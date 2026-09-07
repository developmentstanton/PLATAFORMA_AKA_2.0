<?php
/**
 * Aliado acotado a una MARCA (caso Ibiza).
 *
 * 'Ibiza' tiene proveedor_items = 'STANTON', asi que antes de este cambio los informes le
 * armaban el universo con ITEMS.PROVEEDOR = 'STANTON' y veia el catalogo COMPLETO de Stanton
 * (24.000+ referencias de 21 marcas) en vez de las 560 de MARCA = 'IBIZA'.
 *
 * Comprueba las dos mitades:
 *   1. el login resuelve 'Ibiza' -> 'IBIZA' (de ahi salen los titulos y las claves de cache)
 *   2. #refs se arma por MARCA y NO trae ninguna referencia de otra marca
 * y ademas que un aliado normal siga filtrando por PROVEEDOR, sin enterarse.
 *
 * Si la migracion sql/007_marca_items.sql aun no esta aplicada, el caso Ibiza se SALTA y solo
 * se comprueba la no-regresion: asi esta prueba sirve para las dos fases del despliegue.
 *
 *   php tests/ibiza_marca_refs_test.php
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_login.php';
require __DIR__ . '/../api/lib_refs.php';

$fail = 0;
function chk($cond, $msg) {
    global $fail;
    echo ($cond ? "  OK    " : "  FALLO ") . $msg . "\n";
    if (!$cond) $fail = 1;
}
function unaCol($conn, $sql, $p = []) {
    $st = sqlsrv_query($conn, $sql, $p);
    if ($st === false) return null;
    $r = sqlsrv_fetch_array($st, SQLSRV_FETCH_NUMERIC);
    sqlsrv_free_stmt($st);
    return $r ? $r[0] : null;
}

$hayColumna = unaCol($dbConnect, "SELECT COL_LENGTH('dbo.usuarios_portal_aka','marca_items')") !== null;
echo "migracion sql/007 aplicada: " . ($hayColumna ? "SI" : "NO (el caso Ibiza se salta)") . "\n\n";

// ---------- No regresion: un aliado normal sigue filtrando por PROVEEDOR ----------
echo "== Aliado normal (BELTRANY SAS): nada debe cambiar ==\n";
$r = login_resolver_proveedor($dbConnect, 'Beltrany sas');
chk($r['proveedor'] === 'BELTRANY SAS', "el login sigue resolviendo 'BELTRANY SAS' (dio " . var_export($r['proveedor'], true) . ")");
chk($r['fuente'] === 'curado', "sigue resolviendo por 'curado' (dio " . var_export($r['fuente'], true) . ")");
chk(refs_marca_curada($dbConnect, 'BELTRANY SAS') === false, "no se le toma por marca");

chk(buildRefsFromMat($dbConnect, 'BELTRANY SAS') !== false, "se le arma #refs sin error");
$n = (int) unaCol($dbConnect, "SELECT COUNT(*) FROM #refs");
$marcas = (int) unaCol($dbConnect, "SELECT COUNT(DISTINCT MARCA) FROM #refs");
echo "  #refs = $n referencias, $marcas marcas\n";
chk($n > 0, "su universo no queda vacio");

// ---------- El caso Ibiza ----------
if (!$hayColumna) {
    echo "\n(Se salta el caso Ibiza: falta aplicar sql/007_marca_items.sql)\n";
    echo $fail ? "\nRESULTADO: FALLO\n" : "\nRESULTADO: OK (solo no-regresion)\n";
    exit($fail);
}

echo "\n== Ibiza: su universo es la MARCA, no el proveedor ==\n";
$i = login_resolver_proveedor($dbConnect, 'Ibiza');
chk($i['proveedor'] === 'IBIZA', "el login resuelve 'Ibiza' -> 'IBIZA' (dio " . var_export($i['proveedor'], true) . ")");
chk($i['fuente'] === 'curado-marca', "por la via 'curado-marca' (dio " . var_export($i['fuente'], true) . ")");
chk(refs_marca_curada($dbConnect, 'IBIZA') === true, "'IBIZA' se reconoce como marca curada");

chk(buildRefsFromMat($dbConnect, 'IBIZA') !== false, "se le arma #refs sin error");
$nI = (int) unaCol($dbConnect, "SELECT COUNT(*) FROM #refs");
$otras = (int) unaCol($dbConnect, "SELECT COUNT(*) FROM #refs WHERE RTRIM(MARCA) <> 'IBIZA'");

// Desde sql/009 el aliado puede llevar ENCIMA un acote de CATEGORIA. Esta prueba es la del
// acote de MARCA, asi que lo que comprueba es "solo su marca", no un total fijo: el total lo
// fija ibiza_categoria_refs_test.php. Sin esto, aplicar sql/009 rompia esta prueba (Ibiza pasa
// de 560 a 7) y el fallo habria parecido una regresion del acote de marca, que no lo es.
$catAcote = function_exists('refs_categoria_acotada') ? refs_categoria_acotada($dbConnect, 'IBIZA') : '';
$sqlEsperado = "SELECT COUNT(*) FROM INTEGRACION.dbo.Items_Mat WITH (NOLOCK) WHERE RTRIM(MARCA) = 'IBIZA'";
$parEsperado = [];
if ($catAcote !== '') {
    $sqlEsperado .= " AND RTRIM(ISNULL(CATEGORIA,'')) = ?";
    $parEsperado[] = $catAcote;
    echo "  (ademas esta acotado a CATEGORIA='$catAcote' por sql/009)\n";
}
$esperado = (int) unaCol($dbConnect, $sqlEsperado, $parEsperado);
echo "  #refs = $nI referencias (se esperaban $esperado); de otras marcas: $otras\n";
chk($nI === $esperado, "trae exactamente las referencias de MARCA='IBIZA'" . ($catAcote !== '' ? " acotadas a '$catAcote'" : ""));
chk($otras === 0, "NO se le cuela ni una referencia de otra marca");

// Lo que se estaba viendo antes, para dejar constancia del tamano del problema.
$antes = (int) unaCol($dbConnect, "SELECT COUNT(*) FROM INTEGRACION.dbo.Items_Mat WITH (NOLOCK) WHERE RTRIM(PROVEEDOR) = 'STANTON'");
echo "  (antes de este arreglo veia $antes referencias, todo el catalogo de STANTON)\n";
chk($nI < $antes, "el universo se reduce de verdad");

echo $fail ? "\nRESULTADO: FALLO\n" : "\nRESULTADO: OK\n";
exit($fail);
