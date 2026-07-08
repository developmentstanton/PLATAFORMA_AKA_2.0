<?php
error_reporting(E_ERROR|E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_evol_cache.php';   // <-- SUT (no existe -> RED)
$prov = $argv[1] ?? 'BELTRANY SAS';
$desdeMes = (date('Y')-1).'-01'; $hastaMes = date('Y-m');
buildRefsFromMat($dbConnect, $prov);
$key = evolCacheKey($prov,$desdeMes,$hastaMes);
$ok  = ensureEvolCacheBase($dbConnect, $key, $desdeMes, $hastaMes);
$st  = sqlsrv_query($dbConnect, "SELECT COUNT(*) c FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?", [$key]);
$n   = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['c'];
echo ($ok && $n>0) ? "SMOKE OK filas=$n key=$key\n" : "SMOKE FAIL ok=".var_export($ok,true)." filas=$n\n";
exit(($ok && $n>0)?0:1);
