<?php
// Regresión del tab DETAL de G00: el endpoint debe responder ok=true en TODAS las
// permutaciones de Calendario (diaadia/retail), S.S.S (nosame/same) y filtros multi-valor.
//
// Cubre el bug SQL 8120 del bloque "Mensual" (introducido en Inc 2A, corregido 2026-06-04):
// el mismo CASE se repetía en SELECT y en GROUP BY usando marcadores `?` distintos; SQL Server
// no los reconoce como la misma expresión y rechazaba la query ("Column 'vm.FECHA' is invalid...").
// El smoke test del detal quedó pendiente el 2026-06-03 y por eso el bug llegó a navegador.
//
//   php tests/g00_detal_smoke_test.php ["PROVEEDOR"]
//
// Subprocesa el endpoint REAL (vía tests/_endpoint_run.php) → sin duplicar SQL ni credenciales.

$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';

function call_endpoint($php, $runner, $prov, $qs, $nul) {
    // -d ...=0 calla la mayoría de warnings de arranque; aun así, algunos php.ini (XAMPP)
    // los emiten a stdout, así que recortamos del primer '{' al último '}' para aislar el JSON.
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    $json = ($a !== false && $b !== false && $b >= $a) ? substr($raw, $a, $b - $a + 1) : $raw;
    return json_decode($json, true);
}

echo "Proveedor: $prov\n";

// Deriva una marca real del proveedor (del catálogo) para cubrir también la rama de
// filtros multi-valor (params extra). Si no hay, se omite esa permutación.
$cat = call_endpoint($php, $runner, $prov, 'tab=filtros', $nul);
$marca = '';
foreach (($cat['combos'] ?? []) as $c) { if (trim($c['marca'] ?? '') !== '') { $marca = $c['marca']; break; } }

// Deriva valores reales del catálogo para cubrir los filtros nuevos.
$color = $talla = $grupo = $tienda = $cc = '';
foreach (($cat['sku'] ?? []) as $s) {
    if ($color === '' && trim($s['color'] ?? '') !== '') $color = $s['color'];
    if ($talla === '' && trim($s['talla'] ?? '') !== '') $talla = $s['talla'];
    if ($color !== '' && $talla !== '') break;
}
foreach (($cat['combos'] ?? []) as $c) {
    if ($grupo  === '' && trim($c['grupo'] ?? '') !== '')            $grupo  = $c['grupo'];
    if ($tienda === '' && trim($c['tienda'] ?? '') !== '')          $tienda = $c['tienda'];
    if ($cc     === '' && trim($c['centro_comercial'] ?? '') !== '') $cc     = $c['centro_comercial'];
    if ($grupo !== '' && $tienda !== '' && $cc !== '') break;
}

$perms = [
    'tab=detal',
    'tab=detal&cal=retail',
    'tab=detal&sss=same',
    'tab=detal&cal=retail&sss=same',
];
if ($marca !== '') $perms[] = 'tab=detal&cal=retail&sss=same&marca[]=' . rawurlencode($marca);
if ($color !== '') $perms[] = 'tab=detal&color[]=' . rawurlencode($color);
if ($talla !== '') $perms[] = 'tab=detal&talla[]=' . rawurlencode($talla);
if ($grupo !== '') $perms[] = 'tab=detal&grupo[]=' . rawurlencode($grupo);
if ($tienda !== '') $perms[] = 'tab=detal&tienda[]=' . rawurlencode($tienda);
if ($cc !== '') $perms[] = 'tab=detal&centro_comercial[]=' . rawurlencode($cc);

$fail = 0;
foreach ($perms as $qs) {
    $d   = call_endpoint($php, $runner, $prov, $qs, $nul);
    $ok  = $d['ok'] ?? false;
    $kpi = (float)($d['kpis']['ventas_actual'] ?? 0);
    $sum = 0; foreach (($d['mensual'] ?? []) as $m) $sum += $m['val_act'];
    printf("[%-46s] ok=%s | KPI=%s mensual_sum=%s\n",
        $qs, $ok ? '1' : '0', number_format($kpi), number_format($sum));
    if (!$ok) { echo "   FALLO: " . json_encode($d['detalle'] ?? '(respuesta no JSON)') . "\n"; $fail = 1; }
}
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK (detal responde en todas las permutaciones)\n";
exit($fail);
