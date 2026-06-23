<?php // tests/codificacion_registro_test.php
//   COD_TEST_DB=1 php tests/codificacion_registro_test.php
if (getenv('COD_TEST_DB') !== '1') { echo "SKIP (exporta COD_TEST_DB=1 para probar contra la BD de producción)\nRESULTADO: OK\n"; exit(0); }
require __DIR__ . '/../api/lib_codificacion.php';
require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i=0; $dbConnect===false && $i<4; $i++){ usleep(300000); $dbConnect=sqlsrv_connect($servidor,$infoconn); }
if ($dbConnect===false){ echo "FALLO: sin conexión\nRESULTADO: FALLO\n"; exit(1); }
$fail=0; function chk($c,$m){ global $fail; if(!$c){ echo "FALLO: $m\n"; $fail=1; } }

$marca = '__TEST__'.substr(md5(uniqid('',true)),0,8);

// 1) inserta con nit y devuelve consecutivo > 0
$cons = cod_registrar_envio($dbConnect, $marca, date('Y-m-d'), '999TEST');
chk(is_int($cons) && $cons>0, "debe devolver consecutivo > 0 (got ".var_export($cons,true).")");

// 2) la fila quedó con nit correcto y estado por defecto 'Estudio'
$q = sqlsrv_query($dbConnect, "SELECT nombre_cliente, nit, estado FROM consecutivo_planillas_aka WHERE consecutivo = ?", [$cons]);
$row = $q ? sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC) : null;
chk($row && trim($row['nombre_cliente'])===$marca, "nombre_cliente debe coincidir");
chk($row && trim((string)$row['nit'])==='999TEST', "nit debe ser 999TEST");
chk($row && trim($row['estado'])==='Estudio', "estado debe quedar por defecto 'Estudio'");

// 3) nit null se inserta como NULL
$cons2 = cod_registrar_envio($dbConnect, $marca.'B', date('Y-m-d'), null);
$q2 = sqlsrv_query($dbConnect, "SELECT nit FROM consecutivo_planillas_aka WHERE consecutivo = ?", [$cons2]);
$row2 = $q2 ? sqlsrv_fetch_array($q2, SQLSRV_FETCH_ASSOC) : null;
chk($row2 && $row2['nit']===null, "nit null debe quedar NULL");

// LIMPIEZA: borrar las filas de prueba
sqlsrv_query($dbConnect, "DELETE FROM consecutivo_planillas_aka WHERE consecutivo IN (?, ?)", [$cons, $cons2]);

echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (insert+nit+estado, limpieza hecha)\n"; exit($fail);
