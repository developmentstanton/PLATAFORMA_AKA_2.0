<?php
// Regresión: un proveedor SIN DATOS no debe reportarse como 'failed' en el prebuild.
//
// Bug (2026-07-17): el nocturno de WMS-LAB dio FALLO=1 por GUAUTA SHOES (o14c=failed), y por eso la
// tarea devolvia LastTaskResult=1 -- una alarma que iba a sonar TODAS las madrugadas sin significar
// nada, la otra cara del fallo-en-silencio que nos costo el dia anterior. Investigado: GUAUTA tiene
// 8 refs en Items_Mat pero 0 filas en o14_cache_base, asi que su arbol o14 sale VACIO (grupos=0).
// El payload es ok=true, pero o14cCurrentStamp devuelve NULL (no hay 'creado' sin filas) y
// warmProveedor traducia ese NULL a 'failed'. O sea: "vacio" se reportaba como "fallo". El dia que
// entre un aliado nuevo real -dado de alta pero aun sin movimientos- daria el mismo falso FALLO.
//
// o14cCurrentStamp devuelve NULL en DOS casos: (a) la query fallo, (b) 0 filas. warmProveedor solo
// debe reportar 'vacio' en el caso (b) legitimo -payload ok + arbol sin grupos-; si el stamp es
// NULL por error de query PERO el proveedor tiene datos, sigue siendo 'failed' (protege de
// envenenar el cache). Por eso la condicion es triple.
//
//   php tests/prewarm_vacio_test.php ["PROVEEDOR_VACIO"]
//
// Nota: el camino feliz (proveedor con datos -> 'warmed') lo cubre verificar_prewarm.php --run.

require __DIR__ . '/../conexion/conexion_integracion.php';
require_once __DIR__ . '/../api/lib_prewarm.php';
if ($dbConnect === false) { echo "FALLO: conexión DB\n"; exit(1); }

$provVacio = $argv[1] ?? 'GUAUTA SHOES';
echo "PREWARM — proveedor sin datos no es un fallo\nproveedor de prueba = $provVacio\n\n";

$r = warmProveedor($dbConnect, $provVacio, false);
printf("warmProveedor => o14c=%s evol=%s o45=%s\n\n", $r['o14c'], $r['evol'], $r['o45']);

$fallos = [];

// 1) Un proveedor vacío debe reportar 'vacio', no 'failed'.
if ($r['o14c'] === 'failed')      $fallos[] = "o14c='failed' para un proveedor sin datos: 'vacio' se sigue reportando como fallo";
elseif ($r['o14c'] !== 'vacio')   $fallos[] = "o14c='{$r['o14c']}' (se esperaba 'vacio'). ¿El proveedor de prueba ya tiene datos? Pasa otro vacío como argumento.";

// 2) La regla de conteo de prebuild_all NO debe marcar 'vacio' como fallo (misma lógica del script).
$bad = in_array('failed', $r, true) || in_array('failed-refs', $r, true) || in_array('failed-ensure', $r, true);
printf("clasificación prebuild_all: %s\n", $bad ? 'FALLO' : 'OK');
if ($bad) $fallos[] = "prebuild_all contaría este proveedor como FALLO (haría LastTaskResult=1 cada noche)";

sqlsrv_close($dbConnect);
if ($fallos) { foreach ($fallos as $f) echo "FALLO: $f\n"; exit(1); }
echo "\nOK: el proveedor vacío se reporta 'vacio' y NO cuenta como fallo del nocturno.\n";
exit(0);
