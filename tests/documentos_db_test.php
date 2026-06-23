<?php // tests/documentos_db_test.php
//   COD_TEST_DB=1 php tests/documentos_db_test.php
if (getenv('COD_TEST_DB') !== '1') { echo "SKIP (exporta COD_TEST_DB=1 para probar contra la BD de producción)\nRESULTADO: OK\n"; exit(0); }
require __DIR__ . '/../api/lib_documentos.php';
require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i=0; $dbConnect===false && $i<4; $i++){ usleep(300000); $dbConnect=sqlsrv_connect($servidor,$infoconn); }
if ($dbConnect===false){ echo "FALLO: sin conexión\nRESULTADO: FALLO\n"; exit(1); }
$fail=0; function chk($c,$m){ global $fail; if(!$c){ echo "FALLO: $m\n"; $fail=1; } }

$mio  = '__TESTDOC__'.substr(md5(uniqid('',true)),0,8);
$otro = '__TESTDOC__'.substr(md5(uniqid('',true)),0,8);
$contenido = "%PDF-1.4\x00\x01\x02\xFF\xFEbinario de prueba\x00fin"; // bytes "difíciles" (nulos/altos)

$id1 = doc_guardar($dbConnect, $mio, '900', 'Contrato', 'contrato.pdf', 'application/pdf', $contenido);
$id2 = doc_guardar($dbConnect, $mio, '900', 'RUT', 'rut.png', 'image/png', 'imgbytes');
$id3 = doc_guardar($dbConnect, $otro, '901', 'Contrato', 'otro.pdf', 'application/pdf', 'xx');
chk(is_int($id1) && $id1>0, "doc_guardar devuelve id>0");

$rows = doc_listar($dbConnect, $mio);
chk(count($rows)===2, "lista solo los 2 del aliado (got ".count($rows).")");
chk(isset($rows[0]) && $rows[0]['id'] > $rows[1]['id'], "orden id DESC");
chk(isset($rows[0]) && !array_key_exists('contenido', $rows[0]), "la lista NO trae el binario");
$tipos = array_column($rows,'tipo'); sort($tipos);
chk($tipos===['Contrato','RUT'], "tipos correctos (got ".json_encode($tipos).")");
$porId = []; foreach ($rows as $r) $porId[$r['id']] = $r;
chk($porId[$id1]['tamano']===strlen($contenido), "tamaño en bytes correcto");
chk((bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',$rows[0]['fecha']), "fecha Y-m-d H:i (got ".var_export($rows[0]['fecha'],true).")");

$doc = doc_obtener($dbConnect, $id1, $mio);
chk($doc !== null, "doc_obtener encuentra el propio");
chk($doc && $doc['contenido'] === $contenido, "bytes idénticos round-trip");
chk($doc && $doc['mime']==='application/pdf' && $doc['nombre_archivo']==='contrato.pdf', "mime/nombre correctos");
chk(doc_obtener($dbConnect, $id1, $otro) === null, "no se obtiene doc de otro aliado (propiedad)");

sqlsrv_query($dbConnect, "DELETE FROM documentos_aliados_aka WHERE id IN (?,?,?)", [$id1,$id2,$id3]);
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (round-trip binario + filtro + propiedad, limpieza hecha)\n"; exit($fail);
