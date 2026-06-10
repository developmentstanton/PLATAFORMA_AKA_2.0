<?php
// Verifica el filtro de fecha que afecta SOLO a ventas en O14.
// Caso real: negocio 555005911A-NEG en tienda 245 (AKA CENTRO TUNJA) tiene venta 2025-07-19 (en Acum).
//  - Con rango por defecto (backend usa desde=2025-01-01) DEBE aparecer en tab=c con ventas>=1.
//  - Con desde=2026-01-01 NO debe aparecer en 245 (control negativo).
//  - Invariante O14: faltante-sobrante == siembra-(disp+hold) por negocio (ventas no lo altera).
//   php tests/o14_ventas_rango_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BH BRANDS SAS';
$runner = __DIR__ . '/_endpoint_run_o14.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
$REF = '555005911A'; $COL = 'NEG'; $BOD = '245';

function ep($php, $runner, $prov, $qs, $nul) {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    return json_decode(($a !== false && $b !== false) ? substr($raw, $a, $b - $a + 1) : $raw, true);
}
// Busca el negocio (ref,color) en una bodega dada dentro del árbol tab=c. Devuelve la fila o null.
function buscarNegEnBodega($resp, $bod, $ref, $col) {
    foreach (($resp['grupos'] ?? []) as $g)
        foreach (($g['almacenes'] ?? []) as $a)
            if (rtrim((string)$a['bodega']) === $bod)
                foreach (($a['negocios'] ?? []) as $n)
                    if (rtrim((string)$n['referencia']) === $ref && rtrim((string)$n['color']) === $col) return $n;
    return null;
}
$sum = fn($n, $m) => array_sum($n['valores'][$m] ?? []);
$fail = 0;

// 1) Rango por defecto (backend usa desde=2025-01-01): 245 debe aparecer con venta histórica.
$def = ep($php, $runner, $prov, 'tab=c', $nul);
if (!($def['ok'] ?? false)) { echo "FALLO: tab=c default no ok\n"; $fail = 1; }
$n245 = buscarNegEnBodega($def, $BOD, $REF, $COL);
if (!$n245) { echo "FALLO: $REF-$COL NO aparece en bodega $BOD con rango por defecto\n"; $fail = 1; }
else {
    $v = $sum($n245, 'ventas');
    echo "OK default: $REF-$COL en $BOD presente, ventas=$v\n";
    if ($v < 1) { echo "FALLO: ventas esperada >=1 en $BOD, hubo $v\n"; $fail = 1; }
    if ($sum($n245,'siembra') !== 0 || $sum($n245,'disponible') !== 0 || $sum($n245,'hold') !== 0)
        echo "NOTA: $BOD trae stock actual además de la venta histórica (no es fila solo-ventas)\n";
}

// 2) Control negativo: solo-2026 -> NO debe aparecer en 245.
$y2026 = ep($php, $runner, $prov, 'tab=c&desde=2026-01-01', $nul);
if (!($y2026['ok'] ?? false)) { echo "FALLO: tab=c 2026 no ok\n"; $fail = 1; }
if (buscarNegEnBodega($y2026, $BOD, $REF, $COL)) { echo "FALLO: con desde=2026-01-01 $REF-$COL NO debería estar en $BOD\n"; $fail = 1; }
else echo "OK control: con solo-2026, $REF-$COL ausente en $BOD\n";

// 3) Invariante por negocio (ventas no altera el balance).
foreach (($def['grupos'] ?? []) as $g) foreach (($g['almacenes'] ?? []) as $a) foreach (($a['negocios'] ?? []) as $n) {
    $lhs = $sum($n,'faltante') - $sum($n,'sobrante');
    $rhs = $sum($n,'siembra') - ($sum($n,'disponible') + $sum($n,'hold'));
    if ($lhs !== $rhs) { echo "FALLO: invariante rota en " . $n['negocio'] . ": $lhs != $rhs\n"; $fail = 1; break 3; }
}
if (!$fail) echo "invariante OK\n";

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
