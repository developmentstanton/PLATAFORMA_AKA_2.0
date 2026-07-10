<?php
/** Tests del lib de disco compartido (DB-independiente). php tests/verificar_disk_cache.php */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../api/lib_disk_cache.php';
$fail = 0; function ck($c,$m){ global $fail; echo ($c?"OK  ":"FAIL")."  $m\n"; if(!$c)$fail++; }

$px='tdc'.getmypid(); $key='k1';
$json=json_encode(['a'=>1,'x'=>'áé']);
ck(diskCacheWrite($px,$key,$json,'STAMP-1'), 'write true');
ck(is_file(diskCachePath($px,$key)) && is_file(diskCacheStampPath($px,$key)), 'existen .json.gz + .stamp');
ck(gzdecode(diskCacheRead($px,$key))===$json, 'read->gzdecode == json');
ck(diskCacheRead($px,'nope')===null, 'read inexistente = null');
// frescura por stamp pasado como parametro
ck(diskCacheFresh($px,$key,'STAMP-1')===true, 'fresh true con stamp igual');
ck(diskCacheFresh($px,$key,'STAMP-2')===false, 'fresh false con stamp distinto');
ck(diskCacheFresh($px,$key,null)===false, 'fresh false con stamp null');
ck(diskCacheFresh($px,'nope','STAMP-1')===false, 'fresh false sin archivo');
// cleanup barre .json.gz/.stamp/.tmp/.lock viejos
foreach(['.json.gz','.stamp'] as $s){ touch(diskCacheDir()."/${px}_old${s}", time()-9999999); }
touch(diskCacheDir()."/${px}_x.json.gz.tmp.9", time()-9999999);
touch(diskCacheDir()."/${px}_x.json.gz.lock", time()-9999999);
diskCacheCleanup($px, 120);
ck(!is_file(diskCacheDir()."/${px}_old.json.gz") && !glob(diskCacheDir()."/${px}_x.*"), 'cleanup barre viejos (.json.gz/.stamp/.tmp/.lock)');
// limpieza del caso fresco
@unlink(diskCachePath($px,$key)); @unlink(diskCacheStampPath($px,$key));
echo $fail?"\n$fail FALLO(S)\n":"\nTODO OK\n"; exit($fail?1:0);
