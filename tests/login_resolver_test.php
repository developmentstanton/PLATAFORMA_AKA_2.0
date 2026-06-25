<?php // tests/login_resolver_test.php
//   LOGIN_TEST_DB=1 php tests/login_resolver_test.php
// Verifica login_resolver_proveedor(): maestro t202 + fallback a ITEMS.PROVEEDOR.
if (getenv('LOGIN_TEST_DB') !== '1') { echo "SKIP (exporta LOGIN_TEST_DB=1 para probar contra la BD)\nRESULTADO: OK\n"; exit(0); }
require __DIR__ . '/../api/lib_login.php';
require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i=0; $dbConnect===false && $i<4; $i++){ usleep(300000); $dbConnect=sqlsrv_connect($servidor,$infoconn); }
if ($dbConnect===false){ echo "FALLO: sin conexión\nRESULTADO: FALLO\n"; exit(1); }
$fail=0; function chk($c,$m){ global $fail; if(!$c){ echo "FALLO: $m\n"; $fail=1; } }

if (!function_exists('login_resolver_proveedor')) {
    echo "FALLO: login_resolver_proveedor() no existe\nRESULTADO: FALLO\n"; exit(1);
}

// Caso 1 — EL BUG: usuario en ventas (ITEMS) pero NO en el maestro t202 → debe caer al fallback.
$r = login_resolver_proveedor($dbConnect, 'Intertenis');
chk($r['proveedor'] === 'INTERTENIS S.A.S', "Intertenis debe resolver a 'INTERTENIS S.A.S' (got ".var_export($r['proveedor'],true).")");
chk($r['fuente'] === 'items', "Intertenis debe resolverse por fallback ITEMS (got ".var_export($r['fuente'],true).")");
chk($r['nit'] === null, "Intertenis sin NIT (no está en maestro) (got ".var_export($r['nit'],true).")");

// Caso 2 — CONTROL: proveedor que SÍ está en el maestro t202 cía 7 → razón + NIT, fuente t202.
$b = login_resolver_proveedor($dbConnect, 'Beltrany');
chk($b['proveedor'] === 'BELTRANY SAS', "Beltrany debe resolver a 'BELTRANY SAS' por t202 (got ".var_export($b['proveedor'],true).")");
chk($b['fuente'] === 't202', "Beltrany debe resolverse por t202 (got ".var_export($b['fuente'],true).")");
chk($b['nit'] === '901038888', "Beltrany NIT 901038888 (got ".var_export($b['nit'],true).")");

// Caso 3 — guion bajo: el usuario usa '_' donde el nombre lleva espacio (REPLACE _→espacio).
$u = login_resolver_proveedor($dbConnect, 'Intertenis');
chk(is_array($u) && array_key_exists('proveedor',$u) && array_key_exists('nit',$u) && array_key_exists('fuente',$u),
    "la función devuelve siempre las 3 claves proveedor/nit/fuente");

// Caso 4 — usuario sin match en ningún lado → todo null.
$x = login_resolver_proveedor($dbConnect, 'zzzz_no_existe_proveedor_xyz');
chk($x['proveedor'] === null && $x['nit'] === null && $x['fuente'] === null,
    "usuario inexistente debe devolver proveedor/nit/fuente = null (got ".json_encode($x).")");

echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (t202 + fallback ITEMS + nulls)\n"; exit($fail);
