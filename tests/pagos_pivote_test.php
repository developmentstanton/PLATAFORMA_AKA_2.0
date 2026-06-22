<?php
$php=PHP_BINARY; $runner=__DIR__.'/_endpoint_run_pagos.php';
$nul=(stripos(PHP_OS,'WIN')===0)?'NUL':'/dev/null';
$NIT=getenv('PAGOS_NIT_A')?:'860009034';
function call_ep($php,$runner,$nit,$qs,$nul){$cmd=escapeshellarg($php).' '.escapeshellarg($runner).' '.escapeshellarg($nit).' '.escapeshellarg($qs).' 2>'.$nul;$r=null;for($i=0;$i<4;$i++){$raw=(string)shell_exec($cmd);$a=strpos($raw,'{');$b=strrpos($raw,'}');$r=json_decode(($a!==false&&$b!==false)?substr($raw,$a,$b-$a+1):$raw,true);if(is_array($r)&&!(isset($r['ok'])&&$r['ok']===false&&stripos($r['error']??'','onexi')!==false))return $r;usleep(400000);}return $r;}
$fail=0; $d=call_ep($php,$runner,$NIT,'',$nul);
$meses=$d['meses']??null;
if (!is_array($meses)){echo "FALLO: falta meses[]\n";$fail=1;}
else {
  // cada (anio,mes) de filas debe existir en meses
  $set=[]; foreach($meses as $m)$set[$m['anio'].'-'.$m['mes']]=1;
  foreach(($d['filas']??[]) as $f) if (!isset($set[$f['anio_pago'].'-'.$f['mes_pago']])){echo "FALLO: mes de fila no está en meses[]\n";$fail=1;break;}
  // orden ascendente
  $prev=''; foreach($meses as $m){$k=sprintf('%04d%02d',$m['anio'],$m['mes']); if($k<$prev){echo "FALLO: meses no ordenados\n";$fail=1;break;} $prev=$k;}
}
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (meses presentes + orden + cobertura)\n";
exit($fail);
