<?php
// Verifica tab=periodos (drill-down): estructura dias[] + invariante Σval_act == ventas_actual de Detal.
//   php tests/g00_periodos_test.php ["PROVEEDOR"]
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
$d = call_ep($php, $runner, $prov, 'tab=periodos', $nul);
$ok = $d['ok'] ?? false;
$dias = $d['dias'] ?? [];
echo "Proveedor: $prov | ok=" . ($ok ? '1' : '0') . " | dias=" . count($dias) . "\n";
if (!$ok) { echo "FALLO: " . json_encode($d['detalle'] ?? '(no JSON)') . "\nRESULTADO: FALLO\n"; exit(1); }
if (!$dias) { echo "FALLO: dias vacío\n"; $fail = 1; }
else {
    $bad = 0; $sva = 0; $sua = 0; $svb = 0;
    foreach ($dias as $x) {
        if (!isset($x['mes'],$x['dia'],$x['val_act'],$x['val_ant'],$x['ups_act'],$x['ups_ant'])) { $bad++; continue; }
        if ($x['mes'] < 1 || $x['mes'] > 12 || $x['dia'] < 1 || $x['dia'] > 31) $bad++;
        $sva += $x['val_act']; $sua += $x['ups_act']; $svb += $x['val_ant'];
    }
    if ($bad) { echo "FALLO: $bad filas con estructura/rango inválido\n"; $fail = 1; }
    echo "Σ val_act=" . number_format($sva) . " | Σ ups_act=" . number_format($sua) . " | Σ val_ant=" . number_format($svb) . "\n";
    if ($sva <= 0) { echo "FALLO: Σ val_act no positivo\n"; $fail = 1; }

    // Invariante: Σ val_act de periodos == ventas_actual del KPI de Detal (mismos filtros, default).
    $det = call_ep($php, $runner, $prov, 'tab=detal', $nul);
    $kpiVa = (float)($det['kpis']['ventas_actual'] ?? -1);
    echo "Detal ventas_actual=" . number_format($kpiVa) . " | dif=" . number_format(abs($kpiVa - $sva)) . "\n";
    if (abs($kpiVa - $sva) > 1) { echo "FALLO: Σ val_act periodos != ventas_actual Detal\n"; $fail = 1; }
}

$d2 = call_ep($php, $runner, $prov, 'tab=periodos&color[]=NEG', $nul);
echo "[tab=periodos&color[]=NEG] ok=" . (($d2['ok'] ?? false) ? '1' : '0') . " dias=" . count($d2['dias'] ?? []) . "\n";
if (!($d2['ok'] ?? false)) { echo "FALLO: filtro color rompe periodos\n"; $fail = 1; }

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK (periodos: dias[] + invariante Σval_act = Detal)\n";
exit($fail);
