<?php
// Verifica tab=productos: 3 árboles (negocios/categorias/generos) con rows+total,
// invariante padre = Σ hijos en $/uds, y total.tiendas_act <= Σ padres.tiendas_act.
//   php tests/g00_productos_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';

function call_ep($php, $runner, $prov, $qs, $nul) {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    return json_decode(($a !== false && $b !== false) ? substr($raw, $a, $b - $a + 1) : $raw, true);
}

$fail = 0;
$d = call_ep($php, $runner, $prov, 'tab=productos', $nul);
$ok = $d['ok'] ?? false;
echo "Proveedor: $prov | ok=" . ($ok ? '1' : '0') . "\n";
if (!$ok) { echo "FALLO: " . json_encode($d['detalle'] ?? '(no JSON)') . "\nRESULTADO: FALLO\n"; exit(1); }

foreach (['negocios', 'categorias', 'generos'] as $k) {
    $blk = $d[$k] ?? null;
    if (!$blk || !isset($blk['rows'], $blk['total'])) { echo "FALLO: falta $k.rows/total\n"; $fail = 1; continue; }
    $rows = $blk['rows']; $tot = $blk['total'];
    $sumPadres = 0;
    foreach ($rows as $p) {
        $sv = 0; $su = 0;
        foreach (($p['children'] ?? []) as $c) { $sv += $c['val_act']; $su += $c['ups_act']; }
        if (!empty($p['children'])) {
            if (abs($sv - $p['val_act']) > 1)   { echo "FALLO: $k '" . $p['label'] . "' val_act != Σhijos ($sv vs " . $p['val_act'] . ")\n"; $fail = 1; }
            if (abs($su - $p['ups_act']) > 0.5) { echo "FALLO: $k '" . $p['label'] . "' ups_act != Σhijos\n"; $fail = 1; }
        }
        $sumPadres += (int)$p['tiendas_act'];
    }
    if ($sumPadres > 0 && (int)$tot['tiendas_act'] > $sumPadres) { echo "FALLO: $k total.tiendas_act > Σpadres ($tot[tiendas_act] > $sumPadres)\n"; $fail = 1; }
    echo "$k: padres=" . count($rows) . " | total \$=" . number_format($tot['val_act']) . " Tdas=" . $tot['tiendas_act'] . " (Σpadres=$sumPadres)\n";
}

$first = $d['negocios']['rows'][0]['label'] ?? '';
if ($first !== '' && $first !== '(Sin dato)' && strpos($first, '-') === false) { echo "FALLO: negocio sin formato REF-COLOR ($first)\n"; $fail = 1; }

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK (productos: 3 arboles, invariante padre=Σhijos, Tdas total<=Σ)\n";
exit($fail);
