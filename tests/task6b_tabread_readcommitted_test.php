<?php
/**
 * Task 6b — prueba DETERMINISTA de que las lecturas de PESTAÑA sobre el cache
 * (INTEGRACION.dbo.g00_cache_ventas) ya NO leen sucio (torn read) durante un rebuild
 * en vuelo, tras remover WITH (NOLOCK) de esas lecturas.
 *
 * Fix (2026-07-08): se quitó WITH (NOLOCK) de las 5 lecturas de pestaña que agregan sobre
 * g00_cache_ventas (informe_g00.php: consolidado, tiendas, productos, periodos, mensual) y de
 * countTiendasSiembraCache (lib_g00_cache.php, sobre g00_cache_siembra). Al caer el hint, la
 * lectura vuelve al isolation por defecto de la conexión: READ COMMITTED.
 *
 * ===  CONFIG REAL DE ESTA BASE  =====================================================
 * INTEGRACION tiene READ_COMMITTED_SNAPSHOT = ON (verificado con sys.databases). Por eso
 * READ COMMITTED aquí NO bloquea sobre el escritor: usa row-versioning y lee la ÚLTIMA
 * VERSIÓN COMMITEADA. El efecto sobre el bug es igualmente correcto (y más barato que
 * bloquear):
 *
 *   Escenario: request W reconstruye la key K -> BEGIN TRAN; DELETE K; INSERT (~2.5s) parcial;
 *   aún SIN commit. Simultáneamente Z (que tomó el fast-path del gate porque K seguía fresca)
 *   lee las filas de K para agregar sus pestañas.
 *
 *     - NOLOCK  (READ UNCOMMITTED, el bug): Z ve el estado PARCIAL sin commitear del rebuild
 *               (DELETE aplicado + INSERT a medias) -> ROW-SET PARCIAL -> SUM subestimado.
 *     - sin hint (READ COMMITTED + RCSI, el fix): Z ve la VERSIÓN COMPLETA previamente
 *               commiteada de K (exactamente el set que el gate declaró "fresco") -> SUM
 *               correcto y consistente. Nunca ve el parcial. NO bloquea, NO deadlock.
 *
 * El test monta el estado a mano (sin depender del timing de la carrera) y contrasta ambos
 * hints sobre EXACTAMENTE el mismo estado no-commiteado, desde una conexión B separada.
 *
 * Propiedades probadas:
 *   1) NOLOCK ve el parcial sin commitear           -> SUM=PARCIAL   (== EL BUG: torn read).
 *   2) READ COMMITTED (sin hint) ve el full previo   -> SUM=COMPLETO  (== EL FIX: nunca parcial),
 *      y RETORNA sin bloquear (no golpea el LOCK_TIMEOUT).
 *   3) Tras COMMIT de W, READ COMMITTED ve el nuevo full commiteado -> SUM=COMPLETO (consistente).
 *
 * Uso: php tests/task6b_tabread_readcommitted_test.php
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';   // define $dbConnect (conn A), $servidor, $infoconn
require __DIR__ . '/../api/lib_g00_cache.php';

if ($dbConnect === false) { echo "DBFAIL A\n"; exit(1); }
$connB = sqlsrv_connect($servidor, $infoconn);
if ($connB === false) { echo "DBFAIL B\n"; exit(1); }

$tabla = 'g00_cache_ventas';
$key   = 'TASK6B_' . getmypid() . '_' . random_int(1000, 9999);
$fail  = 0;

$FECHA          = date('Y') . '-03-15';   // fecha dentro del rango act; irrelevante para el lock, presente por fidelidad
$SUM_FULL       = 600.0;                   // set COMPLETO (3 filas: 100+200+300)
$SUM_PARCIAL    = 100.0;                   // set PARCIAL a mitad del rebuild (1 fila: 100)

// Lectura AGREGADA representativa de la pestaña consolidado en su rama cache: SUM(VALOR) de la
// key. Es exactamente la forma cuyo locking cambió al quitar NOLOCK. Con LOCK_TIMEOUT corto para
// DETECTAR bloqueo si lo hubiera (bajo RCSI no lo hay; bajo RC-locking golpearía 1222).
function aggReadSum($conn, $tabla, $key, $fecha, $hint /* ''|'NOLOCK' */): array {
    $hintSql = $hint === '' ? '' : "WITH ($hint) ";
    // fijamos LOCK_TIMEOUT server-side: si la lectura BLOQUEA, SQL Server aborta con error 1222.
    $lt = sqlsrv_query($conn, "SET LOCK_TIMEOUT 3000;");
    if ($lt !== false) sqlsrv_free_stmt($lt);
    $sql = "SELECT ISNULL(SUM(c.VALOR),0) AS s
            FROM INTEGRACION.dbo.$tabla c $hintSql
            WHERE c.cache_key = ? AND c.FECHA BETWEEN ? AND ?";
    $t0 = microtime(true);
    $st = sqlsrv_query($conn, $sql, [$key, $fecha, $fecha]);
    $ms = (microtime(true) - $t0) * 1000;
    if ($st === false) {
        $errs = sqlsrv_errors();
        $blocked = false;
        foreach ($errs as $e) if ((int)$e['code'] === 1222) $blocked = true; // Lock request time out
        return ['ok' => false, 'blocked' => $blocked, 'ms' => $ms, 'errs' => $errs];
    }
    $row = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($st);
    return ['ok' => true, 'blocked' => false, 'ms' => $ms, 'sum' => (float)$row['s']];
}
function cleanup($conn, $tabla, $key) {
    $st = sqlsrv_query($conn, "DELETE FROM INTEGRACION.dbo.$tabla WHERE cache_key=?", [$key]);
    if ($st !== false) sqlsrv_free_stmt($st);
}
function insRow($conn, $tabla, $key, $fecha, $valor) {
    $st = sqlsrv_query($conn,
        "INSERT INTO INTEGRACION.dbo.$tabla (cache_key, FECHA, BODEGA, REFERENCIA, CANTIDAD, VALOR, MARGEN, creado)
         VALUES (?, ?, 'B1', 'REF', 1, ?, 0, SYSDATETIME())",
        [$key, $fecha, $valor]);
    if ($st === false) { echo "INSFAIL " . print_r(sqlsrv_errors(), true) . "\n"; return false; }
    sqlsrv_free_stmt($st);
    return true;
}

cleanup($dbConnect, $tabla, $key);

// --- (0) Sanidad: confirmar RCSI ON (de esto depende el mecanismo del fix). ---
$stR = sqlsrv_query($connB, "SELECT is_read_committed_snapshot_on r FROM sys.databases WHERE name='INTEGRACION'");
$rcsi = $stR !== false ? (int)sqlsrv_fetch_array($stR, SQLSRV_FETCH_ASSOC)['r'] : -1;
if ($stR !== false) sqlsrv_free_stmt($stR);
printf("[0] READ_COMMITTED_SNAPSHOT(INTEGRACION) = %d  (1 = el fix lee versión commiteada, no bloquea)\n", $rcsi);

// --- Estado inicial COMMITEADO: set COMPLETO de K (3 filas, SUM=600). Esto es la materialización
//     previa que el gate de Z consideró "fresca". (autocommit en conn A) ---
if (!insRow($dbConnect, $tabla, $key, $FECHA, 100) ||
    !insRow($dbConnect, $tabla, $key, $FECHA, 200) ||
    !insRow($dbConnect, $tabla, $key, $FECHA, 300)) { cleanup($dbConnect,$tabla,$key); exit(1); }

// --- Montar "rebuild en vuelo" (request W): conn A abre TRAN, DELETE K, INSERT PARCIAL (1 fila),
//     y NO commitea: las filas nuevas quedan X-locked / sin commitear; el set previo, versionado. ---
if (sqlsrv_begin_transaction($dbConnect) === false) { echo "BEGINFAIL\n"; cleanup($dbConnect,$tabla,$key); exit(1); }
$del = sqlsrv_query($dbConnect, "DELETE FROM INTEGRACION.dbo.$tabla WHERE cache_key=?", [$key]);
if ($del === false) { sqlsrv_rollback($dbConnect); echo "DELFAIL\n"; cleanup($dbConnect,$tabla,$key); exit(1); }
sqlsrv_free_stmt($del);
if (!insRow($dbConnect, $tabla, $key, $FECHA, 100)) { sqlsrv_rollback($dbConnect); cleanup($dbConnect,$tabla,$key); exit(1); }
// (rebuild a medias: falta INSERT de 200 y 300; W todavía NO commitea)

// --- (1) NOLOCK (hint viejo) desde conn B: VE el parcial sin commitear -> SUM=100 (el bug). ---
$r1 = aggReadSum($connB, $tabla, $key, $FECHA, 'NOLOCK');
// --- (2) READ COMMITTED (sin hint, el fix) desde conn B: VE el full previo commiteado -> SUM=600,
//         sin bloquear (RCSI). ---
$r2 = aggReadSum($connB, $tabla, $key, $FECHA, '');

printf("[1] NOLOCK          -> ok=%s blocked=%s sum=%s ms=%.0f (esperado sum=%.0f = PARCIAL = EL BUG)\n",
    var_export($r1['ok'],true), var_export($r1['blocked'],true), isset($r1['sum'])?$r1['sum']:'-', $r1['ms'], $SUM_PARCIAL);
printf("[2] READ COMMITTED  -> ok=%s blocked=%s sum=%s ms=%.0f (esperado sum=%.0f = COMPLETO = EL FIX, sin bloquear)\n",
    var_export($r2['ok'],true), var_export($r2['blocked'],true), isset($r2['sum'])?$r2['sum']:'-', $r2['ms'], $SUM_FULL);

if (!($r1['ok'] && $r1['sum'] === $SUM_PARCIAL)) {
    echo "  FAIL: NOLOCK no reprodujo el torn read parcial (esperaba SUM=$SUM_PARCIAL)\n"; $fail = 1;
}
if (!$r2['ok']) {
    echo "  FAIL: READ COMMITTED devolvió error/bloqueo (blocked=" . var_export($r2['blocked'],true) . ") en vez del full commiteado\n"; $fail = 1;
} elseif ($r2['sum'] === $SUM_PARCIAL) {
    echo "  FAIL: READ COMMITTED LEYÓ el parcial (torn read NO cerrado)\n"; $fail = 1;
} elseif ($r2['sum'] !== $SUM_FULL) {
    echo "  FAIL: READ COMMITTED devolvió un SUM inesperado (esperaba full=$SUM_FULL)\n"; $fail = 1;
}

// --- W termina el rebuild: INSERT del resto (200,300) y COMMIT -> nuevo full commiteado (600). ---
insRow($dbConnect, $tabla, $key, $FECHA, 200);
insRow($dbConnect, $tabla, $key, $FECHA, 300);
sqlsrv_commit($dbConnect);

// --- (3) READ COMMITTED tras COMMIT: ve el nuevo full commiteado -> SUM=600 (consistente). ---
$r3 = aggReadSum($connB, $tabla, $key, $FECHA, '');
printf("[3] READ COMMITTED (post-commit) -> ok=%s sum=%s (esperado sum=%.0f = nuevo full commiteado)\n",
    var_export($r3['ok'],true), isset($r3['sum'])?$r3['sum']:'-', $SUM_FULL);
if (!($r3['ok'] && $r3['sum'] === $SUM_FULL)) { echo "  FAIL: tras commit READ COMMITTED no vio el full ($SUM_FULL)\n"; $fail = 1; }

cleanup($dbConnect, $tabla, $key);
sqlsrv_close($connB);

echo $fail
    ? "TASK6B FAIL\n"
    : "TASK6B OK (NOLOCK=parcial/torn=$SUM_PARCIAL; READ COMMITTED=full consistente=$SUM_FULL sin bloqueo; RCSI ON)\n";
exit($fail);
