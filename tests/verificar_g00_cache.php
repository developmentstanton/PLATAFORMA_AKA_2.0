<?php
/**
 * Smoke test inicial de lib_g00_cache.php (Task 2).
 * RED esperado antes de crear api/lib_g00_cache.php: fatal "Failed opening required".
 * GREEN esperado tras implementarlo: "SMOKE OK filas=<n>" con n>0.
 *
 * Uso: php tests/verificar_g00_cache.php ["PROVEEDOR"]
 */
error_reporting(E_ERROR|E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_g00_cache.php';   // <-- SUT (no existe -> RED)

$prov = $argv[1] ?? 'BH BRANDS SAS';
$anioA = (int)date('Y'); $anioB = $anioA - 1;
$desde = "$anioA-01-01"; $hasta = date('Y-m-d', strtotime('-1 day'));

buildRefsFromMat($dbConnect, $prov);

$key = g00CacheKey($prov, $anioA, $anioB, $desde, $hasta);
$ok  = ensureG00CacheVentas($dbConnect, $key, $anioB . '-01-01', $hasta);

$st = sqlsrv_query($dbConnect, "SELECT COUNT(*) c FROM INTEGRACION.dbo.g00_cache_ventas WHERE cache_key=?", [$key]);
$n  = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['c'];
sqlsrv_free_stmt($st);

echo ($ok && $n > 0) ? "SMOKE OK filas=$n key=$key\n" : "SMOKE FAIL ok=" . var_export($ok, true) . " filas=$n\n";
exit(($ok && $n > 0) ? 0 : 1);
