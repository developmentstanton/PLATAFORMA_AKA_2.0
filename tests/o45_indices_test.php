<?php
// O45: índices correctos. ind_inventario = total_stock/ventas30 (null si ventas30<=0).
// ind_ventas_mes = (ventas/tiendas)/(dias/30) (0 si tiendas=0). Total recalculado.
//   php tests/o45_indices_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run_o45.php';
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
$d = ep($php, $runner, $prov, 'tab=data', $nul);
$dias = (int)($d['rango']['dias'] ?? 0);
if ($dias < 1) { echo "FALLO: dias inválido\n"; $fail = 1; }
foreach (array_slice($d['filas'] ?? [], 0, 200) as $f) {
    // ind_inventario
    if ((int)$f['ventas30'] <= 0) {
        if ($f['ind_inventario'] !== null) { echo "FALLO: ind_inventario debe ser null si ventas30<=0 (" . $f['negocio'] . ")\n"; $fail = 1; break; }
    } else {
        $exp = round($f['total_stock'] / $f['ventas30'], 2);
        if (abs(($f['ind_inventario'] ?? -1) - $exp) > 0.011) { echo "FALLO: ind_inventario " . $f['negocio'] . " got " . ($f['ind_inventario']??'null') . " exp $exp\n"; $fail = 1; break; }
    }
    // ind_ventas_mes
    if ((int)$f['tiendas'] === 0) {
        if ((float)$f['ind_ventas_mes'] !== 0.0) { echo "FALLO: ind_ventas_mes debe ser 0 si tiendas=0 (" . $f['negocio'] . ")\n"; $fail = 1; break; }
    } else {
        $exp = round(($f['ventas'] / $f['tiendas']) / ($dias / 30), 2);
        if (abs(((float)$f['ind_ventas_mes']) - $exp) > 0.011) { echo "FALLO: ind_ventas_mes " . $f['negocio'] . " got " . $f['ind_ventas_mes'] . " exp $exp\n"; $fail = 1; break; }
    }
}
// Total recalculado a nivel total.
$t = $d['total'] ?? [];
if (isset($t['ventas30']) && (int)$t['ventas30'] > 0) {
    $exp = round($t['total_stock'] / $t['ventas30'], 2);
    if (abs(($t['ind_inventario'] ?? -1) - $exp) > 0.011) { echo "FALLO: total ind_inventario got " . ($t['ind_inventario']??'null') . " exp $exp\n"; $fail = 1; }
}
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
