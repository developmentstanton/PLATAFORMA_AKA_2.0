<?php
/**
 * evol cache en disco.
 *   php tests/verificar_evol_disco.php --paridad  (requiere DB) -- payload builder + frescura (Task 3)
 *   php tests/verificar_evol_disco.php --e2e      (requiere DB) -- wiring del corto-circuito de
 *     disco en api/informe_evol.php tab=data sin filtro (Task 4): disco vs vivo por HTTP real.
 * La paridad byte-a-byte vs vivo EXHAUSTIVA (3 proveedores x 4 filtros) la cubre
 * verificar_evol_cache --paridad (Task 4, que ya rutea tab=data sin filtro por el disco).
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../api/lib_disk_cache.php';
require __DIR__ . '/../api/lib_evol_cache.php';
require __DIR__ . '/../api/lib_evol_disk.php';
if (($argv[1] ?? '') === '--e2e') { require __DIR__ . '/_task4_paridad_evol.php'; exit(evolRunE2E()); }
if (($argv[1] ?? '') !== '--paridad') { echo "usar --paridad o --e2e (requiere DB)\n"; exit(0); }
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
if ($dbConnect===false){ echo "SKIP sin DB\n"; exit(0); }
$conn=$dbConnect; $fail=0; function ck($c,$m){ global $fail; echo ($c?"OK  ":"FAIL")."  $m\n"; if(!$c)$fail++; }
$prov='BH BRANDS SAS'; $desde=(date('Y')-1).'-01'; $hasta=date('Y-m');
buildRefsFromMat($conn,$prov);
$ekey=evolCacheKey($prov,$desde,$hasta);
ensureEvolCacheBase($conn,$ekey,$desde,$hasta);
$p=evolBuildPayload($conn,$prov,$ekey,$desde,$hasta);
ck(($p['ok']??false)===true && is_array($p['negocios']) && isset($p['totalGeneral']) && is_array($p['meses']), 'payload bien formado');
// consistencia: total ventas del ultimo mes == suma de negocios en ese mes
$mUlt=end($p['meses']);
$sum=0; foreach($p['negocios'] as $n) $sum += ($n['valores']['ventas'][$mUlt] ?? 0);
ck($sum === ($p['totalGeneral']['valores']['ventas'][$mUlt] ?? -1), "suma ventas negocios ($sum) == totalGeneral mes $mUlt");
// frescura
$stamp=evolCurrentStamp($conn);
ck($stamp!==null, "evolCurrentStamp no-null ($stamp)");
evolWritePayload($ekey, json_encode($p,JSON_UNESCAPED_UNICODE), $stamp);
ck(evolDiskFresh($conn,$ekey)===true, 'evolDiskFresh true tras escribir con stamp vigente');
file_put_contents(diskCacheStampPath('evol',$ekey),'STALE');
ck(evolDiskFresh($conn,$ekey)===false, 'evolDiskFresh false con stamp de disco desfasado');
@unlink(diskCachePath('evol',$ekey)); @unlink(diskCacheStampPath('evol',$ekey));
echo $fail?"\n$fail FALLO(S)\n":"\nEVOL PAYLOAD OK\n"; exit($fail?1:0);
