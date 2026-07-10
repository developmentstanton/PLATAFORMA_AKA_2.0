<?php
/**
 * o45 cache en disco. php tests/verificar_o45_disco.php --paridad   (requiere DB)
 * Verifica builder bien formado + consistencia + frescura por stamp de fuente.
 * La paridad byte-a-byte disco-vs-vivo la cubre --e2e (Task 2).
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../api/lib_disk_cache.php';
require __DIR__ . '/../api/lib_o45_disk.php';
if (($argv[1] ?? '') === '--e2e') { require __DIR__ . '/_o45_e2e.php'; exit(o45RunE2E()); }
if (($argv[1] ?? '') !== '--paridad') { echo "usar --paridad o --e2e (requieren DB)\n"; exit(0); }
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_precios.php';
require __DIR__ . '/../api/lib_o45_dataset.php';
if ($dbConnect===false){ echo "SKIP sin DB\n"; exit(0); }
$conn=$dbConnect; $fail=0; function ck($c,$m){ global $fail; echo ($c?"OK  ":"FAIL")."  $m\n"; if(!$c)$fail++; }
$prov='BELTRANY SAS'; $desde='2025-01-01'; $hasta=date('Y-m-d', strtotime('-1 day'));
buildRefsFromMat($conn,$prov);
$key=o45CacheKey($prov,$desde,$hasta);
$p=o45BuildPayload($conn,$prov,$desde,$hasta);
ck(($p['ok']??false)===true && $p['tab']==='dataset' && is_array($p['filas']) && is_array($p['columnas']) && isset($p['precios']) && isset($p['rango']), 'payload bien formado');
ck(count($p['filas'])>0, 'dataset trae filas ('.count($p['filas']).')');
ck($p['proveedor']===$prov, 'envelope usa proveedorSesion');
// frescura por stamp de fuente
$stamp=o45CurrentStamp($conn);
ck($stamp!==null && $stamp!=='', "o45CurrentStamp no-vacio ($stamp)");
o45WritePayload($key, json_encode($p,JSON_UNESCAPED_UNICODE), $stamp);
ck(o45DiskFresh($conn,$key)===true, 'o45DiskFresh true tras escribir con stamp vigente');
file_put_contents(diskCacheStampPath('o45',$key),'STALE-STAMP');
ck(o45DiskFresh($conn,$key)===false, 'o45DiskFresh false con stamp desfasado');
@unlink(diskCachePath('o45',$key)); @unlink(diskCacheStampPath('o45',$key));
echo $fail?"\n$fail FALLO(S)\n":"\nO45 PAYLOAD OK\n"; exit($fail?1:0);
