<?php
// O45 tab=data: estructura básica + invariante total_stock = stock_cedi + stock_tiendas.
//   php tests/o45_smoke_test.php ["PROVEEDOR"]
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
if (!($d['ok'] ?? false)) { echo "FALLO: tab=data no ok\n"; $fail = 1; }
$filas = $d['filas'] ?? [];
echo "filas=" . count($filas) . "\n";
$keys = ['negocio','marca','ventas','tiendas','ventas30','stock_cedi','stock_tiendas','total_stock','tallas'];
foreach (array_slice($filas, 0, 50) as $f) {
    foreach ($keys as $k) if (!array_key_exists($k, $f)) { echo "FALLO: falta key '$k' en fila\n"; $fail = 1; break 2; }
    if ((int)$f['total_stock'] !== (int)$f['stock_cedi'] + (int)$f['stock_tiendas']) {
        echo "FALLO: total_stock != cedi+tiendas en " . $f['negocio'] . "\n"; $fail = 1; break;
    }
}
if (!isset($d['rango']['dias'])) { echo "FALLO: falta rango.dias\n"; $fail = 1; }
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
