<?php
// O45: al filtrar por una tienda, Stock CEDI de cada negocio NO cambia (CEDI siempre completo);
// y Stock Tiendas no aumenta respecto al total sin filtro.
//   php tests/o45_filtro_tienda_test.php ["PROVEEDOR"]
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
$idx = function($d){ $m=[]; foreach(($d['filas']??[]) as $f) $m[$f['negocio']]=$f; return $m; };
$fail = 0;

// Tomar una tienda real del catálogo.
$cat = ep($php, $runner, $prov, 'tab=filtros', $nul);
$tienda = null;
foreach (($cat['combos'] ?? []) as $c) if (($c['tienda'] ?? '') !== '') { $tienda = $c['tienda']; break; }
if ($tienda === null) { echo "NOTA: proveedor sin tiendas en catálogo; test trivial OK\n"; echo "RESULTADO: OK\n"; exit(0); }

$base = $idx(ep($php, $runner, $prov, 'tab=data', $nul));
$filt = $idx(ep($php, $runner, $prov, 'tab=data&tienda[]=' . rawurlencode($tienda), $nul));
echo "tienda='$tienda' negocios base=" . count($base) . " filtrado=" . count($filt) . "\n";
foreach ($filt as $neg => $f) {
    if (!isset($base[$neg])) continue;
    if ((int)$f['stock_cedi'] !== (int)$base[$neg]['stock_cedi']) {
        echo "FALLO: stock_cedi cambió con filtro de tienda en $neg (" . $base[$neg]['stock_cedi'] . " -> " . $f['stock_cedi'] . ")\n"; $fail = 1; break;
    }
    if ((int)$f['stock_tiendas'] > (int)$base[$neg]['stock_tiendas']) {
        echo "FALLO: stock_tiendas filtrado > base en $neg\n"; $fail = 1; break;
    }
}
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
