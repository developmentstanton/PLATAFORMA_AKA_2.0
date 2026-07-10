<?php
/** Smoke de warmProveedor (calienta los 3 informes de un proveedor). php tests/verificar_prewarm.php --run  (requiere DB, LENTO) */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../api/lib_disk_cache.php';
require __DIR__ . '/../api/lib_prewarm.php';
if (($argv[1] ?? '') !== '--run') { echo "usar --run (requiere DB, construye 3 informes)\n"; exit(0); }
require __DIR__ . '/../conexion/conexion_integracion.php';
if ($dbConnect===false){ echo "SKIP sin DB\n"; exit(0); }
$conn=$dbConnect; $fail=0; function ck($c,$m){ global $fail; echo ($c?"OK  ":"FAIL")."  $m\n"; if(!$c)$fail++; }
$prov='BELTRANY SAS';
// keys que warmProveedor debe dejar frescas (mismos defaults que los endpoints):
$k14=o14CacheKey($prov,'2025-01-01',date('Y-m-d'));
$ke =evolCacheKey($prov,(date('Y')-1).'-01',date('Y-m'));
$k45=o45CacheKey($prov,'2025-01-01',date('Y-m-d',strtotime('-1 day')));
// limpiar disco previo
foreach([['o14c',$k14],['evol',$ke],['o45',$k45]] as $x){ @unlink(diskCachePath($x[0],$x[1])); @unlink(diskCacheStampPath($x[0],$x[1])); }

$r = warmProveedor($conn, $prov, false);
echo "  warmProveedor(force) => o14c={$r['o14c']} evol={$r['evol']} o45={$r['o45']}\n";
ck($r['o14c']==='warmed' && $r['evol']==='warmed' && $r['o45']==='warmed', 'los 3 warmed');
ck(o14cDiskFresh($conn,$k14) && evolDiskFresh($conn,$ke) && o45DiskFresh($conn,$k45), 'los 3 frescos en disco tras warm');

$r2 = warmProveedor($conn, $prov, true); // onlyIfStale sobre cache ya fresco
echo "  warmProveedor(onlyIfStale) => o14c={$r2['o14c']} evol={$r2['evol']} o45={$r2['o45']}\n";
ck($r2['o14c']==='skipped' && $r2['evol']==='skipped' && $r2['o45']==='skipped', 'onlyIfStale skipea los 3 frescos');

foreach([['o14c',$k14],['evol',$ke],['o45',$k45]] as $x){ @unlink(diskCachePath($x[0],$x[1])); @unlink(diskCacheStampPath($x[0],$x[1])); }
echo $fail?"\n$fail FALLO(S)\n":"\nPREWARM OK\n"; exit($fail?1:0);
