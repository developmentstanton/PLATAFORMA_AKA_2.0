<?php
// Verifica el catálogo Inc 2B: combos con campos de bodega (grupo/tienda/centro_comercial)
// y arreglo sku (referencia,color,talla) desde Maestro_Ref_Plataforma_AKA.
//   php tests/g00_inc2b_catalog_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
$cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
     . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg('tab=filtros') . ' 2>' . $nul;
$raw = (string) shell_exec($cmd);
$a = strpos($raw, '{'); $b = strrpos($raw, '}');
$d = json_decode(($a !== false && $b !== false) ? substr($raw, $a, $b - $a + 1) : $raw, true);

$fail = 0;
$combos = $d['combos'] ?? [];
$sku    = $d['sku'] ?? null;
echo "Proveedor: $prov | combos=" . count($combos) . " | sku=" . (is_array($sku) ? count($sku) : 'AUSENTE') . "\n";

if (!$combos) { echo "FALLO: combos vacío\n"; $fail = 1; }
foreach (['grupo','tienda','centro_comercial'] as $k) {
    if (!array_key_exists($k, $combos[0] ?? [])) { echo "FALLO: combos sin clave '$k'\n"; $fail = 1; }
}
if (!is_array($sku) || !$sku) { echo "FALLO: sku ausente o vacío\n"; $fail = 1; }
else foreach (['referencia','color','talla'] as $k) {
    if (!array_key_exists($k, $sku[0])) { echo "FALLO: sku sin clave '$k'\n"; $fail = 1; }
}
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK (catálogo Inc 2B completo)\n";
exit($fail);
