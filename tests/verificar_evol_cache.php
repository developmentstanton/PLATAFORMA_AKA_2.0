<?php
/**
 * Smoke test (Task 2) de lib_evol_cache.php: materializa el cache granular denormalizado de
 * EVOL (#evolmat ⋈ #refs ⋈ Bodegas, ADMIN excluido) para un proveedor y verifica que quedaron
 * filas. Requiere que INTEGRACION.dbo.evol_cache_base ya exista.
 *
 * Task 4 (--paridad): paridad EXTREMO A EXTREMO del endpoint completo (api/informe_evol.php,
 * tab=data) — cache-first vs vivo (?nocache=1) — para 3 proveedores x 4 filtros (ver
 * tests/_task4_paridad_evol.php), más medición de latencia y concurrencia lector-durante-rebuild
 * del materialize.
 *
 * Uso:
 *   php tests/verificar_evol_cache.php ["PROVEEDOR"]   — smoke (Task 2)
 *   php tests/verificar_evol_cache.php --paridad       — matriz completa (Task 4)
 */
error_reporting(E_ERROR|E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_evol_cache.php';   // <-- SUT (no existe -> RED)

if (($argv[1] ?? '') === '--paridad') {
    require __DIR__ . '/_task4_paridad_evol.php';
    exit(evolRunParidadFull($dbConnect));
}

$prov = $argv[1] ?? 'BELTRANY SAS';
$desdeMes = (date('Y')-1).'-01'; $hastaMes = date('Y-m');
buildRefsFromMat($dbConnect, $prov);
$key = evolCacheKey($prov,$desdeMes,$hastaMes);
$ok  = ensureEvolCacheBase($dbConnect, $key, $desdeMes, $hastaMes);
$st  = sqlsrv_query($dbConnect, "SELECT COUNT(*) c FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?", [$key]);
$n   = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['c'];
echo ($ok && $n>0) ? "SMOKE OK filas=$n key=$key\n" : "SMOKE FAIL ok=".var_export($ok,true)." filas=$n\n";
exit(($ok && $n>0)?0:1);
