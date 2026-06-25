<?php
// php tests/pagos_generados_test.php
$php=PHP_BINARY; $runner=__DIR__.'/_endpoint_run_generados.php';
$nul=(stripos(PHP_OS,'WIN')===0)?'NUL':'/dev/null';
$NIT=getenv('PAGOS_NIT_A')?:'914480000';
function call_ep($php,$runner,$nit,$qs,$nul){$cmd=escapeshellarg($php).' '.escapeshellarg($runner).' '.escapeshellarg($nit).' '.escapeshellarg($qs).' 2>'.$nul;$r=null;for($i=0;$i<4;$i++){$raw=(string)shell_exec($cmd);$a=strpos($raw,'{');$b=strrpos($raw,'}');$r=json_decode(($a!==false&&$b!==false)?substr($raw,$a,$b-$a+1):$raw,true);if(is_array($r)&&!(isset($r['ok'])&&$r['ok']===false&&stripos($r['error']??'','onexi')!==false))return $r;usleep(400000);}return $r;}
$fail=0;
$d=call_ep($php,$runner,$NIT,'',$nul);
if(!($d['ok']??false)){echo "FALLO: ok=0 ".json_encode($d['error']??'')."\nRESULTADO: FALLO\n";exit(1);}
$nodos=$d['nodos']??null;
if(!is_array($nodos)||!count($nodos)){echo "FALLO: nodos vacío\n";$fail=1;}
$by=[]; foreach(($nodos??[]) as $n)$by[$n['id']]=$n;
// niveles y jerarquía coherentes
foreach(($nodos??[]) as $n){
  if(!in_array($n['nivel'],[1,2,3],true)){echo "FALLO: nivel inválido\n";$fail=1;break;}
  if($n['nivel']===1 && $n['pid']!==null){echo "FALLO: año con pid\n";$fail=1;break;}
  if($n['nivel']>1 && !isset($by[$n['pid']])){echo "FALLO: pid inexistente\n";$fail=1;break;}
  if($n['nivel']>1 && $by[$n['pid']]['nivel']!==$n['nivel']-1){echo "FALLO: pid de nivel incorrecto\n";$fail=1;break;}
}
// roll-up: valor de cada año = suma de sus meses = suma de sus días
function hijos($nodos,$pid){return array_values(array_filter($nodos,fn($x)=>$x['pid']===$pid));}
foreach(array_filter($nodos,fn($x)=>$x['nivel']===1) as $anio){
  $meses=hijos($nodos,$anio['id']); $sm=0; foreach($meses as $m)$sm+=$m['valor'];
  if(abs($sm-$anio['valor'])>1){echo "FALLO: valor año != suma meses (".$anio['label'].")\n";$fail=1;break;}
  foreach($meses as $m){$dias=hijos($nodos,$m['id']); $sd=0; foreach($dias as $x)$sd+=$x['valor'];
    if(abs($sd-$m['valor'])>1){echo "FALLO: valor mes != suma días\n";$fail=1;break 2;}}
}
// total = suma de años
$sa=0; foreach(array_filter($nodos,fn($x)=>$x['nivel']===1) as $a)$sa+=$a['valor'];
if(abs($sa-($d['total']['valor']??0))>1){echo "FALLO: total != suma años\n";$fail=1;}
// aislamiento: NIT inexistente -> sin nodos; sin NIT -> estado vacío con sin_nit (NO error)
$z=call_ep($php,$runner,'',' ',$nul);
if(($z['ok']??false)!==true){echo "FALLO: sin NIT debería ser ok:true (estado vacío)\n";$fail=1;}
if(($z['sin_nit']??false)!==true){echo "FALLO: sin NIT debería marcar sin_nit=true\n";$fail=1;}
if(count($z['nodos']??[null])!==0){echo "FALLO: sin NIT debería devolver nodos vacíos\n";$fail=1;}
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (árbol coherente + roll-up + total + guard NIT)\n";
exit($fail);
