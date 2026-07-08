<?php
/**
 * Helper de Task 5 (Parte C, concurrencia): fuerza un materialize de g00_cache_ventas para
 * una $key/$desde/$hasta dados, en su PROPIA conexión (proceso PHP CLI independiente). Se
 * lanza dos veces casi-simultáneamente (proc_open, ver tests/_task5_paridad.php) contra la
 * MISMA key para verificar que sp_getapplock serializa correctamente y no duplica filas.
 *
 * Uso: php tests/_task5_concurrent_materialize.php "PROVEEDOR" "cache_key" "desde" "hasta"
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_g00_cache.php';

if ($dbConnect === false) { echo "DBFAIL\n"; exit(1); }

$prov  = $argv[1] ?? '';
$key   = $argv[2] ?? '';
$desde = $argv[3] ?? '';
$hasta = $argv[4] ?? '';

if (!buildRefsFromMat($dbConnect, $prov)) { echo "REFSFAIL\n"; exit(1); }
$ok = ensureG00CacheVentas($dbConnect, $key, $desde, $hasta);
echo $ok ? "OK\n" : "FAIL\n";
