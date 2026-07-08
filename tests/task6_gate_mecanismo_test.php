<?php
/**
 * Task 6 — prueba DETERMINISTA del mecanismo del gate de frescura (g00CacheFresco).
 *
 * No depende de timing de carrera: monta a mano el estado "rebuild en vuelo" (una transacción
 * ABIERTA, sin commitear, con filas frescas para una key) en una conexión A, y consulta el
 * gate desde una conexión B (SEPARADA), comparando el hint viejo (NOLOCK) contra el nuevo
 * (READPAST, el del fix) sobre EXACTAMENTE el mismo estado no-commiteado.
 *
 * Prueba las 3 propiedades que hacen el fix correcto:
 *   1) NOLOCK ve las filas SIN COMMITEAR  -> gate diría "fresco"  (== EL BUG: torn read).
 *   2) READPAST OMITE las filas locked     -> gate dice "NO fresco" (== EL FIX: enruta al applock).
 *   3) Tras COMMIT, READPAST ve las filas   -> gate dice "fresco"   (sin falsos negativos).
 *
 * (2) es justamente lo que hace g00CacheFresco() hoy; se invoca la FUNCIÓN REAL para (2)/(3).
 *
 * Uso: php tests/task6_gate_mecanismo_test.php
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';   // define $dbConnect, $servidor, $infoconn
require __DIR__ . '/../api/lib_g00_cache.php';               // SUT: g00CacheFresco (READPAST)

if ($dbConnect === false) { echo "DBFAIL A\n"; exit(1); }
$connB = sqlsrv_connect($servidor, $infoconn);
if ($connB === false) { echo "DBFAIL B\n"; exit(1); }

$tabla = 'g00_cache_ventas';
$key   = 'TASK6_GATE_' . getmypid() . '_' . random_int(1000, 9999);
$fail  = 0;

// Helpers de lectura cruda (conn B) para contrastar hints sobre el MISMO estado.
function frescoConHint($conn, $tabla, $key, $hint): ?bool {
    $sql = "SELECT TOP 1 1 FROM INTEGRACION.dbo.$tabla WITH ($hint)
            WHERE cache_key=? AND creado > DATEADD(minute, -" . G00_CACHE_TTL_MIN . ", SYSDATETIME())";
    $st = sqlsrv_query($conn, $sql, [$key], ['QueryTimeout' => 8]); // timeout: READPAST no debe bloquear
    if ($st === false) return null;
    $hay = sqlsrv_fetch($st) ? true : false;
    sqlsrv_free_stmt($st);
    return $hay;
}
function cleanup($conn, $tabla, $key) {
    $st = sqlsrv_query($conn, "DELETE FROM INTEGRACION.dbo.$tabla WHERE cache_key=?", [$key]);
    if ($st !== false) sqlsrv_free_stmt($st);
}

cleanup($dbConnect, $tabla, $key); // por si quedó basura de una corrida previa

// --- Montar "rebuild en vuelo": conn A abre transacción e inserta filas frescas SIN commit. ---
if (sqlsrv_begin_transaction($dbConnect) === false) { echo "BEGINFAIL\n"; exit(1); }
$ins = sqlsrv_query($dbConnect,
    "INSERT INTO INTEGRACION.dbo.$tabla (cache_key, REFERENCIA, CANTIDAD, VALOR, creado)
     VALUES (?, 'REF-1', 1, 100.0, SYSDATETIME()),
            (?, 'REF-2', 2, 200.0, SYSDATETIME()),
            (?, 'REF-3', 3, 300.0, SYSDATETIME())",
    [$key, $key, $key]);
if ($ins === false) { sqlsrv_rollback($dbConnect); echo "INSFAIL " . print_r(sqlsrv_errors(), true) . "\n"; exit(1); }
sqlsrv_free_stmt($ins);
// NO commit: las 3 filas están X-locked, frescas, sin commitear (rebuild en vuelo simulado).

// (1) NOLOCK (hint viejo) — desde conn B — VE las filas sin commitear -> "fresco" (el bug).
$nolock = frescoConHint($connB, $tabla, $key, 'NOLOCK');
// (2) READPAST vía la FUNCIÓN REAL g00CacheFresco — desde conn B — OMITE las locked -> "no fresco" (el fix).
$readpastFn = g00CacheFresco($connB, $tabla, $key);

printf("[1] NOLOCK   ve filas sin-commitear -> fresco=%s (esperado: true  = el BUG)\n", var_export($nolock, true));
printf("[2] READPAST (g00CacheFresco real)  -> fresco=%s (esperado: false = el FIX)\n", var_export($readpastFn, true));

if ($nolock !== true)     { echo "  FAIL: NOLOCK no reprodujo la lectura sucia (esperaba true)\n"; $fail = 1; }
if ($readpastFn !== false){ echo "  FAIL: READPAST NO bloqueo la lectura sucia (esperaba false)\n"; $fail = 1; }

// --- Commit A: ahora las filas están commiteadas. ---
sqlsrv_commit($dbConnect);

// (3) READPAST tras commit -> DEBE ver las filas -> "fresco" (sin falsos negativos).
$readpastCommit = g00CacheFresco($connB, $tabla, $key);
printf("[3] READPAST tras COMMIT            -> fresco=%s (esperado: true  = sin falso negativo)\n", var_export($readpastCommit, true));
if ($readpastCommit !== true) { echo "  FAIL: READPAST no vio filas ya commiteadas (esperaba true)\n"; $fail = 1; }

cleanup($dbConnect, $tabla, $key);
sqlsrv_close($connB);

echo $fail ? "TASK6-GATE FAIL\n" : "TASK6-GATE OK (NOLOCK=sucio, READPAST bloquea sucio y ve commiteado)\n";
exit($fail);
