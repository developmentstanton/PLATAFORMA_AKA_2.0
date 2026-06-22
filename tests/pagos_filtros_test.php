<?php
$php=PHP_BINARY; $runner=__DIR__.'/_endpoint_run_pagos.php';
$nul=(stripos(PHP_OS,'WIN')===0)?'NUL':'/dev/null';
$NIT=getenv('PAGOS_NIT_A')?:'860009034';
function call_ep($php,$runner,$nit,$qs,$nul){$cmd=escapeshellarg($php).' '.escapeshellarg($runner).' '.escapeshellarg($nit).' '.escapeshellarg($qs).' 2>'.$nul;$r=null;for($i=0;$i<4;$i++){$raw=(string)shell_exec($cmd);$a=strpos($raw,'{');$b=strrpos($raw,'}');$r=json_decode(($a!==false&&$b!==false)?substr($raw,$a,$b-$a+1):$raw,true);if(is_array($r)&&!(isset($r['ok'])&&$r['ok']===false&&stripos($r['error']??'','onexi')!==false))return $r;usleep(400000);}return $r;}
$fail=0;
$base=call_ep($php,$runner,$NIT,'',$nul); $nBase=count($base['filas']??[]);
// Causado=SI -> todas SI
$si=call_ep($php,$runner,$NIT,'causado=SI',$nul);
foreach (($si['filas']??[]) as $f) if ($f['causado']!=='SI'){echo "FALLO: causado!=SI\n";$fail=1;break;}
if (empty($si['filas'] ?? [])) { echo "FALLO: causado=SI devolvió 0 filas (test no verificable)\n"; $fail=1; }
// Causado=Todas ~= baseline (ambos sin filtro de causado). Tolerancia por WITH(NOLOCK):
// dos lecturas separadas de ~15k filas pueden variar levemente entre sí (lecturas sucias).
$todas = call_ep($php,$runner,$NIT,'causado=Todas',$nul);
$nTodas = count($todas['filas'] ?? []);
$tol = max(5, (int)ceil($nBase * 0.02));
if (abs($nTodas - $nBase) > $tol) { echo "FALLO: causado=Todas ($nTodas) difiere de baseline ($nBase) más allá de tolerancia ($tol)\n"; $fail=1; }
// Rango Días 0..30
$rd=call_ep($php,$runner,$NIT,'dias_min=0&dias_max=30',$nul);
foreach (($rd['filas']??[]) as $f) if ($f['dias']<0||$f['dias']>30){echo "FALLO: dias fuera de rango\n";$fail=1;break;}
// Rango Fecha
$rf=call_ep($php,$runner,$NIT,'fdesde=2025-01-01&fhasta=2025-12-31',$nul);
foreach (($rf['filas']??[]) as $f) if ($f['fecha_venc']<'2025-01-01'||$f['fecha_venc']>'2025-12-31'){echo "FALLO: fecha fuera de rango\n";$fail=1;break;}
if (empty($rf['filas'] ?? [])) { echo "AVISO: rango fecha 2025 devolvió 0 filas (no se ejerció el borde)\n"; }
if (count($rd['filas']??[])>$nBase){echo "FALLO: filtro no reduce\n";$fail=1;}
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (causado + rango dias + rango fecha)\n";
exit($fail);
