<?php
// Verifica que el bloque Mensual del tab=detal devuelve tiendas por mes + total distinct,
// nombre de mes completo, y la invariante distinct-global <= suma de distinct por mes.
//   php tests/g00_mensual_test.php ["PROVEEDOR"]
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
$d   = call_ep($php, $runner, $prov, 'tab=detal', $nul);
$ok  = $d['ok'] ?? false;
$men = $d['mensual'] ?? [];
$tot = $d['mensual_tdas'] ?? null;
echo "Proveedor: $prov | ok=" . ($ok ? '1' : '0') . " | meses=" . count($men) . "\n";
if (!$ok) { echo "FALLO: " . json_encode($d['detalle'] ?? '(no JSON)') . "\nRESULTADO: FALLO\n"; exit(1); }

$completos = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$sumAct = 0; $nombresOk = true;
foreach ($men as $r) {
    if (!array_key_exists('tiendas_act', $r) || !array_key_exists('tiendas_ant', $r)) {
        echo "FALLO: mes sin tiendas_act/ant\n"; $fail = 1; break;
    }
    $sumAct += (int)$r['tiendas_act'];
    if (!in_array($r['mes'], $completos, true)) $nombresOk = false;
}
if (!$nombresOk) { echo "FALLO: nombre de mes no es completo (" . ($men[0]['mes'] ?? '?') . ")\n"; $fail = 1; }

if (!$tot || !isset($tot['act'], $tot['ant'])) { echo "FALLO: falta mensual_tdas{act,ant}\n"; $fail = 1; }
else {
    echo "Tdas total: act=" . $tot['act'] . " ant=" . $tot['ant'] . " | Sum(tiendas_act/mes)=" . $sumAct . "\n";
    if ((int)$tot['act'] > $sumAct) { echo "FALLO: distinct global > suma por mes (imposible)\n"; $fail = 1; }
}

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK (mensual: tiendas/mes + total distinct + meses completos + invariante)\n";
exit($fail);
