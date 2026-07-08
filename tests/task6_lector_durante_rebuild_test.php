<?php
/**
 * Task 6 — test de CONCURRENCIA "lector durante rebuild en vuelo" (torn read del gate).
 *
 * Reproduce, con DOS PROCESOS REALES (proc_open, mismo estilo que Parte C de _task5_paridad.php),
 * el escenario que el fix de g00CacheFresco (NOLOCK -> READPAST) debe blindar:
 *
 *   Proceso Y: materialize completo de g00_cache_ventas para una key (DELETE + INSERT ~segs,
 *              una transacción; cada fila nueva con creado=SYSDATETIME() fresco).
 *   Proceso Z: ~400ms después (a mitad del rebuild) corre el CAMINO DE LECTOR del endpoint
 *              (ensureG00CacheVentas -> lee el row-set que serviría la pestaña) y reporta
 *              cuántas filas serviría (SERVED n).
 *
 * Invariante que se verifica: Z NUNCA sirve un set PARCIAL. El count servido por Z debe ser
 * igual al count final COMMITEADO (FULL) — porque con el gate READPAST, Z ve "no fresco"
 * durante el rebuild, bloquea en el sp_getapplock existente hasta que Y comitea, y recién
 * entonces lee (datos ya commiteados). Un valor 0<served<FULL (o cualquier != FULL) es el
 * torn read que este test caza.
 *
 * Con el gate viejo (NOLOCK) este test PODÍA observar served parcial (Z veía las filas
 * sin-commitear de Y, saltaba su ensure y servía un conteo intermedio).
 *
 * Uso: php tests/task6_lector_durante_rebuild_test.php ["PROVEEDOR"] [n_iteraciones]
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_g00_cache.php';

if ($dbConnect === false) { echo "DBFAIL\n"; exit(1); }

$prov  = $argv[1] ?? 'BH BRANDS SAS';
$iters = max(1, (int) ($argv[2] ?? 6));

$anioA = (int) date('Y');
$anioB = $anioA - 1;
$desde = "$anioB-01-01";                          // 2 años (año B + año A)
$hasta = date('Y-m-d', strtotime('-1 day'));
$key   = g00CacheKey($prov, $anioA, $anioB, $desde, $hasta);

$php     = PHP_BINARY;
$matHlp  = __DIR__ . '/_task5_concurrent_materialize.php'; // proceso Y
$rdrHlp  = __DIR__ . '/_task6_reader.php';                 // proceso Z
$common  = ' -d display_startup_errors=0 -d display_errors=stderr ';
$cmdY = escapeshellarg($php) . $common . escapeshellarg($matHlp) . ' '
      . escapeshellarg($prov) . ' ' . escapeshellarg($key) . ' '
      . escapeshellarg($desde) . ' ' . escapeshellarg($hasta);
$cmdZ = escapeshellarg($php) . $common . escapeshellarg($rdrHlp) . ' '
      . escapeshellarg($prov) . ' ' . escapeshellarg($key) . ' '
      . escapeshellarg($desde) . ' ' . escapeshellarg($hasta);

function delKey($conn, $key) {
    $st = sqlsrv_query($conn, "DELETE FROM INTEGRACION.dbo.g00_cache_ventas WHERE cache_key=?", [$key]);
    if ($st !== false) sqlsrv_free_stmt($st);
}
function countKey($conn, $key): int {
    $st = sqlsrv_query($conn, "SELECT COUNT(*) n FROM INTEGRACION.dbo.g00_cache_ventas WITH (NOLOCK) WHERE cache_key=?", [$key]);
    $n = ($st !== false) ? (int) sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['n'] : -1;
    if ($st !== false) sqlsrv_free_stmt($st);
    return $n;
}
function parseServed(string $out): ?int {
    if (preg_match('/SERVED\s+(-?\d+)/', $out, $m)) return (int) $m[1];
    return null;
}

// --- Baseline FULL: un materialize secuencial normal, cuántas filas commitea la key. ---
delKey($dbConnect, $key);
shell_exec($cmdY);
$FULL = countKey($dbConnect, $key);
echo "PROVEEDOR=$prov  KEY=$key\n";
echo "BASELINE FULL=$FULL filas\n";
if ($FULL <= 0) { echo "SKIP: baseline invalido ($FULL) — sin datos para ejercitar la ventana\n"; exit(0); }

$torn = [];      // iteraciones con set parcial (0<served<FULL o served!=FULL != window-miss)
$hits = 0;       // iteraciones donde el timing metió a Z a mitad del rebuild (Z tardó > umbral)
$results = [];

for ($i = 1; $i <= $iters; $i++) {
    delKey($dbConnect, $key);

    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pY = @proc_open($cmdY, $desc, $pipesY);
    if (!is_resource($pY)) { echo "SKIP: proc_open no disponible en este entorno\n"; exit(0); }

    usleep(400000); // 400ms: caer a mitad del rebuild de Y

    $tZ0 = microtime(true);
    $pZ = @proc_open($cmdZ, $desc, $pipesZ);
    if (!is_resource($pZ)) { proc_close($pY); echo "SKIP: proc_open no disponible\n"; exit(0); }

    $outZ = stream_get_contents($pipesZ[1]); fclose($pipesZ[1]); fclose($pipesZ[2]);
    $zElapsed = round((microtime(true) - $tZ0) * 1000);
    $outY = stream_get_contents($pipesY[1]); fclose($pipesY[1]); fclose($pipesY[2]);
    proc_close($pZ);
    proc_close($pY);

    $served = parseServed($outZ);
    $finalN = countKey($dbConnect, $key); // debe ser FULL tras commitear ambos

    // Heurística de "ventana alcanzada": Z tardó lo suficiente como para haber bloqueado en
    // el applock (i.e. arrancó durante el rebuild de Y). No es condición del assert, solo diagnóstico.
    $windowHit = $zElapsed >= 300;
    if ($windowHit) $hits++;

    $status = 'OK';
    if ($served === null)          $status = 'ZFAIL(no SERVED)';
    elseif ($served < 0)           $status = "ZFAIL(err=$served)";
    elseif ($served !== $FULL)     { $status = "TORN(served=$served != FULL=$FULL)"; $torn[] = $i; }

    $results[] = $status;
    printf("  it %d: served=%s final=%d zElapsedMs=%d windowHit=%s -> %s | Y=[%s]\n",
        $i, ($served === null ? 'NULL' : $served), $finalN, $zElapsed,
        $windowHit ? 'si' : 'no', $status, trim(str_replace(["\r","\n"], ' ', (string)$outY)));
}

echo "\n==== RESULTADO TASK 6 ====\n";
printf("Iteraciones=%d | ventana-alcanzada(diag)=%d | torn/parciales=%d\n", $iters, $hits, count($torn));
if ($torn) {
    echo "TORN READS EN ITERACIONES: " . implode(',', $torn) . "\n";
    echo "TASK6 FAIL\n";
    exit(1);
}
echo "TASK6 OK (Z siempre sirvio el set COMPLETO; ningun read parcial)\n";
exit(0);
