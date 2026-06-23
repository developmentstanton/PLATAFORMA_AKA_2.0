<?php // tests/codificacion_solicitudes_test.php
//   COD_TEST_DB=1 php tests/codificacion_solicitudes_test.php
if (getenv('COD_TEST_DB') !== '1') { echo "SKIP (exporta COD_TEST_DB=1 para probar contra la BD de producción)\nRESULTADO: OK\n"; exit(0); }
require __DIR__ . '/../api/lib_codificacion.php';
require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i=0; $dbConnect===false && $i<4; $i++){ usleep(300000); $dbConnect=sqlsrv_connect($servidor,$infoconn); }
if ($dbConnect===false){ echo "FALLO: sin conexión\nRESULTADO: FALLO\n"; exit(1); }
$fail=0; function chk($c,$m){ global $fail; if(!$c){ echo "FALLO: $m\n"; $fail=1; } }

$mio  = '__TESTSOL__'.substr(md5(uniqid('',true)),0,8);
$otro = '__TESTSOL__'.substr(md5(uniqid('',true)),0,8);

// Sembrar: 2 del aliado de prueba + 1 de otro
$c1 = cod_registrar_envio($dbConnect, $mio,  date('Y-m-d'), '111');
$c2 = cod_registrar_envio($dbConnect, $mio,  date('Y-m-d'), '222');
$c3 = cod_registrar_envio($dbConnect, $otro, date('Y-m-d'), '333');

$rows = cod_listar_solicitudes($dbConnect, $mio);
chk(count($rows)===2, "debe devolver solo las 2 del aliado (got ".count($rows).")");
chk(isset($rows[0]) && isset($rows[1]) && $rows[0]['consecutivo'] > $rows[1]['consecutivo'], "orden consecutivo DESC");
chk(isset($rows[0]) && $rows[0]['consecutivo']===$c2 && $rows[1]['consecutivo']===$c1, "la más reciente (c2) va primero");
foreach ($rows as $r) {
  chk($r['nombre']===$mio, "nombre debe ser el del aliado");
  chk($r['estado']==='Estudio', "estado por defecto 'Estudio'");
  chk(is_int($r['consecutivo']), "consecutivo int");
  chk((bool)preg_match('/^\d{4}-\d{2}-\d{2}$/',$r['fecha']), "fecha en formato Y-m-d (got ".var_export($r['fecha'],true).")");
}
$nits = array_column($rows,'nit'); sort($nits);
chk($nits===['111','222'], "nits deben ser 111 y 222 (got ".json_encode($nits).")");
foreach ($rows as $r) chk($r['consecutivo']!==$c3, "no debe incluir la solicitud de otro aliado");

// LIMPIEZA
sqlsrv_query($dbConnect, "DELETE FROM consecutivo_planillas_aka WHERE consecutivo IN (?,?,?)", [$c1,$c2,$c3]);

echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (filtro + orden DESC + campos, limpieza hecha)\n"; exit($fail);
