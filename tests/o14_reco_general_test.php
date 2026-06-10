<?php
// Recomendaciones generales (tab=reco): 3 matrices negocio×talla; proveedor<=faltante; producto acota, bodega no.
//   php tests/o14_reco_general_test.php ["PROVEEDOR"]
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

$d = ep($php, $runner, $prov, 'tab=reco', $nul);
if (!($d['ok'] ?? false)) { echo "FALLO: tab=reco no ok\n"; $fail = 1; }
$med = $d['medidas'] ?? [];
if ($med !== ['sobrante','faltante','proveedor']) { echo "FALLO: medidas inesperadas: " . json_encode($med) . "\n"; $fail = 1; }
$filas = $d['filas'] ?? [];
echo "reco: negocios=" . count($filas) . " tallas=" . count($d['tallas'] ?? []) . "\n";
if (!count($filas)) { echo "FALLO: sin filas\n"; $fail = 1; }

foreach ($filas as $f) {
    $prv = $f['valores']['proveedor'] ?? []; $fal = $f['valores']['faltante'] ?? [];
    foreach ($prv as $t => $q) {
        if ($q > ($fal[$t] ?? 0)) { echo "FALLO: proveedor>faltante en " . $f['negocio'] . " talla $t: $q > " . ($fal[$t] ?? 0) . "\n"; $fail = 1; break 2; }
    }
}
if (!$fail) echo "invariante proveedor<=faltante OK\n";

$cat = ep($php, $runner, $prov, 'tab=filtros', $nul);
$marca = '';
foreach (($cat['combos'] ?? []) as $c) { if (!empty($c['marca'])) { $marca = $c['marca']; break; } }
if ($marca !== '') {
    $dm = ep($php, $runner, $prov, 'tab=reco&marca[]=' . rawurlencode($marca), $nul);
    echo "reco marca[]=$marca: negocios=" . count($dm['filas'] ?? []) . "\n";
    if (count($dm['filas'] ?? []) > count($filas)) { echo "FALLO: filtro marca no acota reco\n"; $fail = 1; }
}

$db = ep($php, $runner, $prov, 'tab=reco&grupo[]=AKA', $nul);
if (count($db['filas'] ?? []) !== count($filas)) { echo "FALLO: filtro bodega cambió reco (" . count($db['filas'] ?? []) . " vs " . count($filas) . ")\n"; $fail = 1; }
else echo "filtro bodega no cambia reco OK\n";

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
