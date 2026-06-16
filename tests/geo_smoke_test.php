<?php
// Smoke de Georeferenciación: estructura + invariantes. Uso: php tests/geo_smoke_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run_geo.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
function ep($php,$runner,$prov,$qs,$nul){
  $cmd = escapeshellarg($php).' -d display_startup_errors=0 -d display_errors=stderr '
       . escapeshellarg($runner).' '.escapeshellarg($prov).' '.escapeshellarg($qs).' 2>'.$nul;
  $raw = (string) shell_exec($cmd);
  $a = strpos($raw,'{'); $b = strrpos($raw,'}');
  return json_decode(($a!==false&&$b!==false)?substr($raw,$a,$b-$a+1):$raw, true);
}
$fail = 0;
$d = ep($php,$runner,$prov,'tab=data&desde=2025-01&hasta=2026-06',$nul);
if (!($d['ok'] ?? false)) { echo "FALLO: tab=data no ok\n"; echo substr(json_encode($d),0,400)."\n"; exit(1); }

foreach (['proveedor','rango','tiendas','total_tiendas','sin_coord'] as $k)
  if (!array_key_exists($k,$d)) { echo "FALLO: falta '$k'\n"; $fail=1; }
$tiendas = $d['tiendas'] ?? [];
echo "tiendas=".count($tiendas)."  total_tiendas=".($d['total_tiendas']??'?')."  sin_coord=".($d['sin_coord']??'?')."\n";

$keys = ['cia','cod','nombre','grupo','ciudad','lat','lng','valor','unidades'];
$conCoord = 0; $sinCoord = 0; $sumValor = 0;
foreach ($tiendas as $t) {
  foreach ($keys as $k) if (!array_key_exists($k,$t)) { echo "FALLO: falta key '$k' en tienda\n"; $fail=1; break 2; }
  if ($t['lat'] === null) { $sinCoord++; }
  else {
    $conCoord++;
    if ($t['lat'] < -5 || $t['lat'] > 15 || $t['lng'] < -80 || $t['lng'] > -66) { echo "FALLO: coord fuera de rango en ".$t['cod']."\n"; $fail=1; }
  }
  $sumValor += (float)$t['valor'];
}
if ((int)$d['total_tiendas'] !== count($tiendas)) { echo "FALLO: total_tiendas != count(tiendas)\n"; $fail=1; }
if ((int)$d['sin_coord'] !== $sinCoord) { echo "FALLO: sin_coord != tiendas con lat null\n"; $fail=1; }
if ($conCoord === 0) { echo "FALLO: ninguna tienda con coordenada (esperado: la mayoria)\n"; $fail=1; }
if ($sumValor <= 0) { echo "FALLO: suma de ventas en valor no positiva\n"; $fail=1; }

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
