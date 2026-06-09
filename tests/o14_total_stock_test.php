<?php
// total_stock debe ser disponible+hold en tab b y c (antes era solo disponible).
//   php tests/o14_total_stock_test.php ["PROVEEDOR"]
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
foreach (['b', 'c'] as $tab) {
    $d = ep($php, $runner, $prov, 'tab=' . $tab, $nul);
    $k = $d['kpis'] ?? [];
    $exp = (int)($k['disponible'] ?? 0) + (int)($k['hold'] ?? 0);
    $got = (int)($k['total_stock'] ?? -1);
    echo "tab=$tab disp=" . ($k['disponible'] ?? '?') . " hold=" . ($k['hold'] ?? '?') . " total_stock=$got (esperado $exp)\n";
    if ($got !== $exp) { echo "FALLO: total_stock != disponible+hold en tab=$tab\n"; $fail = 1; }
}
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
