<?php
// Golden de Evolución Histórica contra el pantallazo (BH BRANDS SAS / DUNCANA-NEG).
//   php tests/evol_golden_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BH BRANDS SAS';
$runner = __DIR__ . '/_endpoint_run_evol.php';
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

// Estructura
foreach (['proveedor','meses','negocios'] as $k)
  if (!array_key_exists($k,$d)) { echo "FALLO: falta '$k'\n"; $fail=1; }
$meses = $d['meses'] ?? [];
if (!in_array('2025-03',$meses,true) || !in_array('2026-06',$meses,true)) { echo "FALLO: eje de meses incompleto\n"; $fail=1; }

// Localizar DUNCANA-NEG
$dun = null; foreach (($d['negocios']??[]) as $n) if (($n['negocio']??'')==='DUNCANA-NEG') { $dun=$n; break; }
if (!$dun) { echo "FALLO: no aparece DUNCANA-NEG\n"; echo "RESULTADO: FALLO\n"; exit(1); }

$g = function($medida,$mes) use ($dun){ return $dun['valores'][$medida][$mes] ?? null; };
$chk = function($label,$got,$exp) use (&$fail){
  if ((string)$got !== (string)$exp) { echo "FALLO golden $label: got=".var_export($got,true)." exp=$exp\n"; $fail=1; }
};
// Pantallazo
$chk('ventas 2025-03',  $g('ventas','2025-03'),   45);
$chk('ventas 2025-08',  $g('ventas','2025-08'),   1);
$chk('ventas 2026-06',  $g('ventas','2026-06'),   3);
$chk('compras 2025-03', $g('compras','2025-03'),  -1);
$chk('stock 2025-03',   $g('stock','2025-03'),    96);
$chk('stock 2026-06',   $g('stock','2026-06'),    134);
$chk('tiendas 2025-03', $g('tiendas','2025-03'),  17);
$chk('mesesInv 2025-03',$g('mesesInv','2025-03'), 2);
$chk('mesesInv 2026-06',$g('mesesInv','2026-06'), 45);
$chk('indice 2025-03',  $g('indice','2025-03'),   2.56);
$chk('indice 2026-06',  $g('indice','2026-06'),   0.82);

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
