<?php
/**
 * Aliado acotado ademas a una CATEGORIA (caso Ibiza -> ZAPATOS).
 *
 * Ibiza ya estaba acotado a MARCA = 'IBIZA' (sql/007). Encima de eso se le acota la CATEGORIA:
 * usuarios_portal_aka.categoria_items = 'ZAPATOS' (sql/009).
 *
 * OJO, esto NO es cosmetica y el numero manda: de las 560 referencias de la marca IBIZA solo
 * 7 tienen CATEGORIA = 'ZAPATOS' (552 son SANDALIA y 1 BOTAS). El acote se pidio a sabiendas
 * de eso, asi que la prueba fija el numero pequeno a proposito: si algun dia #refs vuelve a
 * traer las 552 sandalias, es que el acote se cayo.
 *
 * Comprueba:
 *   1. la categoria se lee del curado y se reconoce
 *   2. #refs para Ibiza trae SOLO CATEGORIA='ZAPATOS' y solo MARCA='IBIZA' (los dos acotes,
 *      no uno pisando al otro)
 *   3. un aliado sin categoria_items no se entera de nada
 *   4. el universo NO se arma por una ruta que se salte el acote (g00 tenia copias propias
 *      de getRefsCached/buildRefsTemp que sombreaban a las de lib_refs)
 *
 * Si la migracion sql/009_categoria_items.sql aun no esta aplicada, el caso Ibiza se SALTA y
 * solo se comprueba la no-regresion: sirve para las dos fases del despliegue.
 *
 *   php tests/ibiza_categoria_refs_test.php
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

$hayColumna = unaCol($dbConnect, "SELECT COL_LENGTH('dbo.usuarios_portal_aka','categoria_items')") !== null;
echo "migracion sql/009 aplicada: " . ($hayColumna ? "SI" : "NO (el caso Ibiza se salta)") . "\n\n";

// ---------- No regresion: un aliado sin categoria_items no cambia ----------
echo "== Aliado normal (BELTRANY SAS): nada debe cambiar ==\n";
chk(refs_categoria_acotada($dbConnect, 'BELTRANY SAS') === '', "no tiene categoria acotada");
chk(buildRefsFromMat($dbConnect, 'BELTRANY SAS') !== false, "se le arma #refs sin error");
$nB = (int) unaCol($dbConnect, "SELECT COUNT(*) FROM #refs");
$catsB = (int) unaCol($dbConnect, "SELECT COUNT(DISTINCT CATEGORIA) FROM #refs");
echo "  #refs = $nB referencias, $catsB categorias distintas\n";
chk($nB > 0, "su universo no queda vacio");
chk($catsB > 1, "sigue viendo mas de una categoria (no se le colo el acote)");

// ---------- Nadie puede armar el universo saltandose lib_refs ----------
echo "\n== El acote no se puede esquivar ==\n";
$g00 = file_get_contents(__DIR__ . '/../api/informe_g00.php');
$defineLocales = preg_match('/^\s*function\s+(getRefsCached|buildRefsTemp)\s*\(/m', $g00);
chk(!$defineLocales,
    "informe_g00.php ya no define su propia getRefsCached/buildRefsTemp sombreando a lib_refs");

// ---------- El caso Ibiza ----------
if (!$hayColumna) {
    echo "\n(Se salta el caso Ibiza: falta aplicar sql/009_categoria_items.sql)\n";
    echo $fail ? "\nRESULTADO: FALLO\n" : "\nRESULTADO: OK (solo no-regresion)\n";
    exit($fail);
}

echo "\n== Ibiza: ademas de la marca, solo la categoria ZAPATOS ==\n";
chk(refs_categoria_acotada($dbConnect, 'IBIZA') === 'ZAPATOS',
    "'IBIZA' se reconoce acotado a ZAPATOS (dio " . var_export(refs_categoria_acotada($dbConnect, 'IBIZA'), true) . ")");

chk(buildRefsFromMat($dbConnect, 'IBIZA') !== false, "se le arma #refs sin error");
$nI      = (int) unaCol($dbConnect, "SELECT COUNT(*) FROM #refs");
$otraCat = (int) unaCol($dbConnect, "SELECT COUNT(*) FROM #refs WHERE RTRIM(CATEGORIA) <> 'ZAPATOS'");
$otraMar = (int) unaCol($dbConnect, "SELECT COUNT(*) FROM #refs WHERE RTRIM(MARCA) <> 'IBIZA'");
$esperado = (int) unaCol($dbConnect,
    "SELECT COUNT(*) FROM INTEGRACION.dbo.Items_Mat WITH (NOLOCK)
      WHERE RTRIM(MARCA) = 'IBIZA' AND RTRIM(CATEGORIA) = 'ZAPATOS'");
$soloMarca = (int) unaCol($dbConnect,
    "SELECT COUNT(*) FROM INTEGRACION.dbo.Items_Mat WITH (NOLOCK) WHERE RTRIM(MARCA) = 'IBIZA'");

echo "  #refs = $nI referencias (se esperaban $esperado); fuera de ZAPATOS: $otraCat; de otra marca: $otraMar\n";
chk($nI === $esperado, "trae exactamente MARCA='IBIZA' AND CATEGORIA='ZAPATOS'");
chk($otraCat === 0, "NO se le cuela ni una referencia de otra categoria");
chk($otraMar === 0, "el acote de marca sigue en pie (sql/007 no se piso)");
chk($nI > 0, "el universo no queda del todo vacio");
echo "  (sin el acote de categoria veria $soloMarca referencias de la marca IBIZA)\n";
chk($nI < $soloMarca, "el acote de categoria reduce de verdad");

// La caja del disco tiene que distinguir 'IBIZA acotada' de 'IBIZA sin acotar', o el dia
// del despliegue se sirve el universo viejo desde cache/g00_refs_*.json.
echo "\n== La clave de cache incorpora el acote ==\n";
$claveSin  = refs_cache_clave('IBIZA', '');
$claveCon  = refs_cache_clave('IBIZA', 'ZAPATOS');
chk($claveSin !== $claveCon, "la clave cambia cuando cambia la categoria acotada");

echo $fail ? "\nRESULTADO: FALLO\n" : "\nRESULTADO: OK\n";
exit($fail);
