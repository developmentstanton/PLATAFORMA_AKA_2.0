<?php
// Verifica: tab=filtros devuelve combos+sku; un filtro reduce/mantiene #base (filas ⊆ sin filtro);
// invariante O14B faltante-sobrante = siembra-(disp+hold); reco ve CEDI aun con filtro de bodega.
//   php tests/o14_filtros_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run_o14.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';

function ep($php, $runner, $prov, $qs, $nul) {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    return json_decode(($a !== false && $b !== false) ? substr($raw, $a, $b - $a + 1) : $raw, true);
}

$fail = 0;

// 1) catálogo
$cat = ep($php, $runner, $prov, 'tab=filtros', $nul);
$combos = $cat['combos'] ?? null; $sku = $cat['sku'] ?? null;
echo "filtros: combos=" . (is_array($combos)?count($combos):'?') . " sku=" . (is_array($sku)?count($sku):'?') . "\n";
if (!is_array($combos) || !count($combos)) { echo "FALLO: combos vacío\n"; $fail = 1; }
if (!is_array($sku) || !count($sku))       { echo "FALLO: sku vacío\n"; $fail = 1; }

// 2) tab=b sin filtro vs con filtro de talla (toma una talla real del sku)
$base = ep($php, $runner, $prov, 'tab=b', $nul);
$nBase = count($base['filas'] ?? []);
echo "tab=b sin filtro: filas=$nBase ok=" . (($base['ok']??false)?'1':'0') . "\n";
if (!($base['ok'] ?? false)) { echo "FALLO: tab=b base\n"; $fail = 1; }

$tallaTest = $sku[0]['talla'] ?? '';
if ($tallaTest !== '') {
    $f = ep($php, $runner, $prov, 'tab=b&talla[]=' . rawurlencode($tallaTest), $nul);
    $nF = count($f['filas'] ?? []);
    echo "tab=b talla[]=$tallaTest: filas=$nF ok=" . (($f['ok']??false)?'1':'0') . "\n";
    if (!($f['ok'] ?? false)) { echo "FALLO: tab=b filtrado talla\n"; $fail = 1; }
    foreach (($f['filas'] ?? []) as $fila) {
        $sum = fn($m) => array_sum($fila['valores'][$m] ?? []);
        $lhs = $sum('faltante') - $sum('sobrante');
        $rhs = $sum('siembra') - ($sum('disponible') + $sum('hold'));
        if ($lhs !== $rhs) { echo "FALLO: invariante O14B rota (" . $fila['key']['negocio'] . "): $lhs != $rhs\n"; $fail = 1; break; }
    }
}

// 2b) filtro de dimensión de referencia (marca): debe aplicar (filas ⊆ base) y mantener invariante
$marcaTest = '';
foreach (($combos ?? []) as $c) { if (!empty($c['marca'])) { $marcaTest = $c['marca']; break; } }
if ($marcaTest !== '') {
    $fm = ep($php, $runner, $prov, 'tab=b&marca[]=' . rawurlencode($marcaTest), $nul);
    $nFm = count($fm['filas'] ?? []);
    echo "tab=b marca[]=$marcaTest: filas=$nFm ok=" . (($fm['ok']??false)?'1':'0') . "\n";
    if (!($fm['ok'] ?? false)) { echo "FALLO: tab=b filtrado marca\n"; $fail = 1; }
    if ($nFm > $nBase) { echo "FALLO: filtro marca no reduce ($nFm > $nBase)\n"; $fail = 1; }
}

// 3) reco IGNORA los filtros de dimensión (CEDI/tiendas siempre completos para el negocio):
//    aun pasando grupo[]/talla[] el endpoint responde ok (los filtros solo aplican a B/C).
$ref = $sku[0]['referencia'] ?? ''; $col = $sku[0]['color'] ?? '';
if ($ref !== '') {
    $r = ep($php, $runner, $prov, 'tab=reco&ref=' . rawurlencode($ref) . '&color=' . rawurlencode($col) . '&grupo[]=NOEXISTE&talla[]=99', $nul);
    echo "tab=reco con grupo[]/talla[] inexistentes: ok=" . (($r['ok']??false)?'1':'0') . " planes=" . count($r['planes'] ?? []) . "\n";
    if (!($r['ok'] ?? false)) { echo "FALLO: reco rompe / aplica filtros de dimension\n"; $fail = 1; }
}

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK (catálogo + filtros sobre #base + invariante + reco/CEDI)\n";
exit($fail);
