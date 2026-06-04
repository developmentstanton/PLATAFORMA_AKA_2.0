<?php
// Verifica tab=tiendas (Resumen Por Tienda): KPIs + tiendas con children (negocio REF-COLOR)
// + invariante por tienda val_act == Σ children.val_act. Subprocesa el endpoint real.
//   php tests/g00_tiendas_test.php ["PROVEEDOR"]
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
$d = call_ep($php, $runner, $prov, 'tab=tiendas', $nul);
$ok = $d['ok'] ?? false;
$tiendas = $d['tiendas'] ?? [];
$kpis = $d['kpis'] ?? null;
echo "Proveedor: $prov | ok=" . ($ok ? '1' : '0') . " | tiendas=" . count($tiendas) . "\n";
if (!$ok) { echo "FALLO: " . json_encode($d['detalle'] ?? '(no JSON)') . "\nRESULTADO: FALLO\n"; exit(1); }

if (!$kpis || !isset($kpis['tiendas_actual'], $kpis['ticket_prom'], $kpis['margen_prom'])) { echo "FALLO: kpis incompletos\n"; $fail = 1; }
if (!$tiendas) { echo "FALLO: tiendas vacío\n"; $fail = 1; }
else {
    $t = $tiendas[0];
    $kids = $t['children'] ?? [];
    echo "Tienda top: " . ($t['nombre'] ?? '?') . " | children=" . count($kids) . " | negocio[0]=" . ($kids[0]['negocio'] ?? '?') . "\n";
    if (!$kids) { echo "FALLO: tienda sin children\n"; $fail = 1; }
    else {
        if (strpos($kids[0]['negocio'] ?? '', '-') === false) { echo "FALLO: negocio sin formato REF-COLOR\n"; $fail = 1; }
        $sv = 0; $su = 0; foreach ($kids as $k) { $sv += $k['val_act']; $su += $k['ups_act']; }
        $dv = abs($sv - ($t['val_act'] ?? 0)); $du = abs($su - ($t['ups_act'] ?? 0));
        echo "Invariante tienda: val_act=" . number_format($t['val_act'] ?? 0) . " vs Σchildren=" . number_format($sv) . " (dif=" . number_format($dv) . ")\n";
        if ($dv > 1)  { echo "FALLO: val_act tienda != Σ children\n"; $fail = 1; }
        if ($du > 0.5){ echo "FALLO: ups_act tienda != Σ children\n"; $fail = 1; }
    }
    echo "KPIs: tiendas=" . $kpis['tiendas_actual'] . " ticket=" . number_format($kpis['ticket_prom']) . " MB=" . round($kpis['margen_prom'], 2) . "%\n";
}

$d2 = call_ep($php, $runner, $prov, 'tab=tiendas&color[]=NEG', $nul);
echo "[tab=tiendas&color[]=NEG] ok=" . (($d2['ok'] ?? false) ? '1' : '0') . " tiendas=" . count($d2['tiendas'] ?? []) . "\n";
if (!($d2['ok'] ?? false)) { echo "FALLO: filtro color rompe tiendas\n"; $fail = 1; }

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK (tab tiendas: kpis + tiendas con children + invariante)\n";
exit($fail);
