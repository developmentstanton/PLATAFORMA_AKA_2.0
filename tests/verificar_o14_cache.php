<?php
/**
 * Smoke test (Task 2) de lib_o14_cache.php: materializa el cache granular denormalizado
 * de O14 (#base ⋈ #refs ⋈ Bodegas, ADMIN excluido) para un proveedor y verifica que quedaron
 * filas. Requiere que INTEGRACION.dbo.o14_cache_base ya exista (Task 1, sql/006_o14_cache.sql).
 *
 * Uso: php tests/verificar_o14_cache.php ["PROVEEDOR"]
 */
error_reporting(E_ERROR|E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_o14_cache.php';   // <-- SUT (no existe -> RED)

$prov  = $argv[1] ?? 'BELTRANY SAS';
$desde = '2025-01-01';
$hasta = date('Y-m-d');

buildRefsFromMat($dbConnect, $prov);

$key = o14CacheKey($prov, $desde, $hasta);
$ok  = ensureO14CacheBase($dbConnect, $key, $desde, $hasta);

$st = sqlsrv_query($dbConnect, "SELECT COUNT(*) c FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=?", [$key]);
$n  = $st !== false ? sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['c'] : -1;
if ($st !== false) sqlsrv_free_stmt($st);

if ($ok && $n > 0) {
    echo "SMOKE OK filas=$n key=$key\n";
    exit(0);
} else {
    echo "SMOKE FAIL ok=" . var_export($ok, true) . " filas=$n\n";
    exit(1);
}
