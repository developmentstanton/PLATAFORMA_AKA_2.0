<?php // tests/codificacion_validar_test.php
//   php tests/codificacion_validar_test.php
require __DIR__ . '/../api/lib_codificacion.php';
$fail = 0;
function chk($cond,$msg){ global $fail; if(!$cond){ echo "FALLO: $msg\n"; $fail=1; } }

// Helper para construir un $_FILES['adjunto'] con N archivos
function mk($items){ // $items = [['name'=>,'size'=>,'error'=>], ...]
  $o=['name'=>[],'tmp_name'=>[],'error'=>[],'size'=>[]];
  foreach($items as $it){ $o['name'][]=$it['name']; $o['tmp_name'][]='/tmp/'.$it['name'];
    $o['error'][]=$it['error']??UPLOAD_ERR_OK; $o['size'][]=$it['size']??1000; }
  return $o;
}

// 1) sin archivos -> ok=false
$r = cod_validar_archivos(null);            chk($r['ok']===false, "null debe dar ok=false");
$r = cod_validar_archivos(mk([]));          chk($r['ok']===false, "lista vacía debe dar ok=false");

// 2) un xlsx válido -> ok=true, 1 archivo
$r = cod_validar_archivos(mk([['name'=>'Plantilla270.xlsx']]));
chk($r['ok']===true, "xlsx válido debe pasar");
chk(count($r['archivos'])===1, "debe devolver 1 archivo");

// 3) varios válidos
$r = cod_validar_archivos(mk([['name'=>'a.xlsx'],['name'=>'b.xls']]));
chk($r['ok']===true && count($r['archivos'])===2, "2 válidos deben pasar");

// 4) extensión inválida
$r = cod_validar_archivos(mk([['name'=>'virus.exe']]));
chk($r['ok']===false, "exe debe rechazarse");

// 5) demasiado grande (>10MB)
$r = cod_validar_archivos(mk([['name'=>'grande.xlsx','size'=>11*1024*1024]]));
chk($r['ok']===false, ">10MB debe rechazarse");

// 6) demasiados archivos (>10)
$many = []; for($i=0;$i<11;$i++) $many[]=['name'=>"f$i.xlsx"];
$r = cod_validar_archivos(mk($many));
chk($r['ok']===false, ">10 archivos debe rechazarse");

// 7) error de upload en un archivo
$r = cod_validar_archivos(mk([['name'=>'a.xlsx','error'=>UPLOAD_ERR_PARTIAL]]));
chk($r['ok']===false, "error de upload debe rechazarse");

echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK\n"; exit($fail);
