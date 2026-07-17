<?php
// Paridad PERMANENTE del catálogo de filtros de evol: el DERIVADO de evol_cache_base
// (evolBuildFiltros, lo que usa warmProveedor) debe ser el MISMO CONJUNTO que el catálogo del
// endpoint vivo (informe_evol.php?tab=filtros, que construye #base). Si divergen, "derivar"
// cambiaría los filtros del usuario en silencio. Red contra el drift entre las dos fuentes.
//   php tests/evol_filtros_paridad_test.php ["PROVEEDOR"]
$prov = $argv[1] ?? 'BH BRANDS SAS';
$root = dirname(__DIR__);
require "$root/conexion/conexion_integracion.php";
require_once "$root/api/lib_refs.php";
require_once "$root/api/lib_evol_cache.php";
if ($dbConnect === false) { echo "FALLO: conexión DB\n"; exit(1); }

$ed = (date('Y') - 1) . '-01'; $eh = date('Y-m');
$ekey = evolCacheKey($prov, $ed, $eh);
echo "PARIDAD filtros evol — DERIVADO(cache_base) vs VIVO(endpoint)\nproveedor = $prov\n\n";

function keyOf(array $c): string {
    return implode('|', array_map(fn($k) => trim((string)($c[$k] ?? '')),
        ['marca','tipo','categoria','subcategoria','genero','publico',
         'referencia','negocio','grupo','tienda','tienda_cod']));
}

// VIVO: endpoint real tab=filtros, forzando miss (borrar cache diario).
$daily = "$root/cache/evol_filtros_" . md5($prov) . '.json';
@unlink($daily);
$nul = (stripos(PHP_OS,'WIN')===0) ? 'NUL' : '/dev/null';
$cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr '
     . escapeshellarg("$root/tests/_endpoint_run_evol.php") . ' ' . escapeshellarg($prov)
     . ' ' . escapeshellarg('tab=filtros') . ' 2>' . $nul;
$raw = (string) shell_exec($cmd);
$a = strpos($raw, '{'); $b = strrpos($raw, '}');
$vivoJson = ($a === false) ? null : json_decode(substr($raw, $a, $b - $a + 1), true);
if (!is_array($vivoJson) || !isset($vivoJson['combos'])) { echo "FALLO: el endpoint no devolvió combos\n"; exit(1); }
$vivo = [];
foreach ($vivoJson['combos'] as $c) $vivo[keyOf($c)] = true;

// DERIVADO: materializar cache_base (como warmProveedor) y derivar.
if (!buildRefsFromMat($dbConnect, $prov)) { echo "FALLO: buildRefsFromMat\n"; exit(1); }
if (!ensureEvolCacheBase($dbConnect, $ekey, $ed, $eh, true)) { echo "FALLO: ensureEvolCacheBase\n"; exit(1); }
$combos = evolBuildFiltros($dbConnect, $ekey);
if (isset($combos['error'])) { echo "FALLO: evolBuildFiltros error: " . json_encode($combos['error']) . "\n"; exit(1); }
$deriv = [];
foreach ($combos as $c) $deriv[keyOf($c)] = true;

$soloVivo  = array_diff_key($vivo, $deriv);
$soloDeriv = array_diff_key($deriv, $vivo);
echo "combos VIVO(endpoint) : " . count($vivo) . "\n";
echo "combos DERIVADO       : " . count($deriv) . "\n";
echo "solo en VIVO          : " . count($soloVivo) . "\n";
echo "solo en DERIVADO      : " . count($soloDeriv) . "\n";
foreach (array_slice(array_keys($soloVivo), 0, 6) as $k)  echo "  falta en derivado: $k\n";
foreach (array_slice(array_keys($soloDeriv), 0, 6) as $k) echo "  sobra en derivado: $k\n";

sqlsrv_close($dbConnect);
if (count($vivo) === 0)               { echo "\nFALLO: el endpoint no devolvió ningún combo (¿proveedor sin datos? pasa otro).\n"; exit(1); }
if ($soloVivo || $soloDeriv)          { echo "\nFALLO: DIFIEREN — derivar cambiaría los filtros.\n"; exit(1); }
echo "\nOK: catálogo derivado == vivo (conjunto). Sin drift.\n";
exit(0);
