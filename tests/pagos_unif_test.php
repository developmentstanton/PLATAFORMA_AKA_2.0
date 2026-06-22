<?php
//   php tests/pagos_unif_test.php
$php = PHP_BINARY; $runner = __DIR__ . '/_endpoint_run_pagos.php';
$nul = (stripos(PHP_OS,'WIN')===0) ? 'NUL' : '/dev/null';
// NIT_A: STANTON S.A.S. (860009034) — 15260 filas en FLUJO_OC_PBI
// NIT_B: CAUCHOSOL S.A.S. (860029964) — 13622 filas en FLUJO_OC_PBI (diferente razon_social)
$NIT_A = getenv('PAGOS_NIT_A') ?: '860009034';
$NIT_B = getenv('PAGOS_NIT_B') ?: '860029964';
function call_ep($php,$runner,$nit,$qs,$nul){
  $cmd = escapeshellarg($php).' '.escapeshellarg($runner).' '.escapeshellarg($nit).' '.escapeshellarg($qs).' 2>'.$nul;
  $r = null;
  for ($i=0; $i<4; $i++) {
    $raw = (string)shell_exec($cmd);
    $a=strpos($raw,'{'); $b=strrpos($raw,'}');
    $r = json_decode(($a!==false&&$b!==false)?substr($raw,$a,$b-$a+1):$raw,true);
    // Reintenta solo ante fallo de conexión intermitente al RDS (no enmascara fallos reales).
    if (is_array($r) && !(isset($r['ok']) && $r['ok']===false && stripos($r['error']??'','onexi')!==false)) return $r;
    usleep(400000);
  }
  return $r;
}
$fail=0;
$d = call_ep($php,$runner,$NIT_A,'',$nul);
if (!($d['ok']??false)) { echo "FALLO: ok=0 ".json_encode($d['error']??'')."\nRESULTADO: FALLO\n"; exit(1); }
$filas = $d['filas']??[];
echo "NIT_A=$NIT_A filas=".count($filas)."\n";
if (count($filas)===0){ echo "FALLO: NIT_A devolvió 0 filas\n"; $fail=1; }
$campos = ['fecha_venc','dias','documento','causado','moneda','valor','en_pesos','fecha_pago','anio_pago','mes_pago','base'];
foreach (array_slice($filas,0,5) as $f) foreach ($campos as $c)
  if (!array_key_exists($c,$f)) { echo "FALLO: falta campo $c\n"; $fail=1; break 2; }
// Aislamiento: ninguna fila debe ser de otro NIT (el endpoint no expone nit por fila,
// pero comparamos que A y B difieran en conteo/razon_social).
$e = call_ep($php,$runner,$NIT_B,'',$nul);
if (($d['razon_social']??'x') === ($e['razon_social']??'y') && $NIT_A!==$NIT_B)
  { echo "FALLO: razon_social igual para NITs distintos\n"; $fail=1; }
// Aislamiento por NIT: el campo nit de la respuesta debe corresponder al NIT solicitado.
if (($d['nit']??null) !== $NIT_A)
  { echo "FALLO: respuesta NIT_A devolvió nit=".json_encode($d['nit']??null)."\n"; $fail=1; }
if (($e['nit']??null) !== $NIT_B)
  { echo "FALLO: respuesta NIT_B devolvió nit=".json_encode($e['nit']??null)."\n"; $fail=1; }
// Aislamiento por volumen: conteos distintos cuando los proveedores tienen volúmenes distintos.
$cnt_a = count($d['filas']??[]); $cnt_b = count($e['filas']??[]);
if ($cnt_a === $cnt_b && $NIT_A !== $NIT_B)
  { echo "FALLO: conteo igual para NITs distintos ($cnt_a)\n"; $fail=1; }
// Sin sesión de NIT -> error
$z = call_ep($php,$runner,'','',$nul);
if (($z['ok']??true) !== false) { echo "FALLO: sin NIT debería dar ok=false\n"; $fail=1; }
// Task 2: TRM — En Pesos debe convertir divisas; COP debe quedar igual a valor
foreach ($filas as $f) {
  if ($f['moneda']==='COP' && abs($f['en_pesos']-$f['valor'])>0.5) { echo "FALLO: COP en_pesos!=valor\n"; $fail=1; break; }
  if ($f['moneda']!=='COP' && $f['valor']!=0 && $f['en_pesos']==$f['valor']) { echo "FALLO: ".$f['moneda']." sin convertir\n"; $fail=1; break; }
}
if (!isset($d['trm']['fecha'])) { echo "FALLO: falta trm.fecha\n"; $fail=1; }
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (filas con campos + aislamiento + guard NIT)\n";
exit($fail);
