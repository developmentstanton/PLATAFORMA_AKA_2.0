<?php // tests/documentos_validar_test.php
//   php tests/documentos_validar_test.php
require __DIR__ . '/../api/lib_documentos.php';
$fail=0; function chk($c,$m){ global $fail; if(!$c){ echo "FALLO: $m\n"; $fail=1; } }
function mkf($name,$size=1000,$err=UPLOAD_ERR_OK){ return ['name'=>$name,'tmp_name'=>'/tmp/'.$name,'size'=>$size,'error'=>$err]; }

chk(doc_validar('Otro', mkf('a.pdf'))['ok']===false, "tipo fuera de lista debe fallar");
chk(doc_validar(null, mkf('a.pdf'))['ok']===false, "tipo null debe fallar");
chk(doc_validar('Contrato', mkf('contrato.pdf'))['ok']===true, "Contrato + pdf ok");
chk(doc_validar('RUT', mkf('rut.JPG'))['ok']===true, "RUT + JPG (case-insensitive) ok");
chk(doc_validar('Cámara de Comercio', mkf('cc.png'))['ok']===true, "Cámara + png ok");
chk(doc_validar('Contrato', mkf('virus.exe'))['ok']===false, "exe debe fallar");
chk(doc_validar('Contrato', mkf('big.pdf', 11*1024*1024))['ok']===false, ">10MB debe fallar");
chk(doc_validar('Contrato', mkf('a.pdf',1000,UPLOAD_ERR_NO_FILE))['ok']===false, "sin archivo debe fallar");
chk(doc_mime_por_ext('pdf')==='application/pdf', "mime pdf");
chk(doc_mime_por_ext('PNG')==='image/png', "mime png (case-insensitive)");
chk(doc_mime_por_ext('jpeg')==='image/jpeg', "mime jpeg");
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK\n"; exit($fail);
