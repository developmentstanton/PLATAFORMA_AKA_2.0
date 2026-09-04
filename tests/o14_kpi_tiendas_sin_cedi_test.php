<?php
/**
 * "Tiendas con Siembra" tiene que decir lo MISMO en Ventas (g00) y en Siembra (o14).
 * Antes no: o14 contaba el CEDI como tienda y daba uno de mas (15 contra 14 con BH BRANDS SAS).
 *
 * El CEDI no es una tienda. La pestana B ya lo excluye de la matriz y la C lo muestra a proposito
 * dentro de su grupo real (lo necesita la cascada del recomendador), pero en ninguna de las dos
 * debe contar como tienda. Lo mismo vale para "Tiendas c/ Inv" y "Tiendas c/ Venta".
 *
 * Los esperados de o14 NO se calculan aqui: se derivan del arbol de la pestana C, que es la matriz
 * que ve el usuario. Asi la prueba compara el KPI contra lo que la pantalla muestra.
 *
 *   php tests/o14_kpi_tiendas_sin_cedi_test.php ["PROVEEDOR"]
 */
$prov = $argv[1] ?? 'BH BRANDS SAS';
$php  = PHP_BINARY;
$nul  = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';

function ep($php, $runner, $prov, $qs, $nul) {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg(__DIR__ . '/' . $runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    return json_decode(($a !== false && $b !== false) ? substr($raw, $a, $b - $a + 1) : $raw, true);
}

$fail = 0;
function chk($cond, $msg) {
    global $fail;
    echo ($cond ? "  OK    " : "  FALLO ") . $msg . "\n";
    if (!$cond) $fail = 1;
}

// Calentamiento: la pestana C sin filtros se sirve de un payload en disco, y construirlo en
// frio es lento. Si el cache esta vacio (recien desplegado, o tras borrar los o14c_*), la
// primera llamada puede tardar lo bastante como para que alguna de las siguientes se quede
// corta y la prueba falle sin que nada este mal. Esta llamada paga ese coste una vez y se
// tira; a partir de aqui todas leen del mismo payload ya construido.
ep($php, '_endpoint_run_o14.php', $prov, 'tab=c', $nul);

// ---- Esperados derivados del arbol de la pestana C (la matriz), descartando el CEDI ----
$c = ep($php, '_endpoint_run_o14.php', $prov, 'tab=c', $nul);
if (!($c['ok'] ?? false)) { echo "No se pudo cargar o14 tab=c\n"; exit(1); }

$tdaSiembra = $tdaInv = $tdaVenta = [];
foreach ($c['grupos'] ?? [] as $g) {
    foreach ($g['almacenes'] ?? [] as $a) {
        if (trim((string)($a['bodega'] ?? '')) === 'CEDI') continue;   // el CEDI no es tienda
        $llave = ($g['grupo'] ?? '') . '|' . ($a['bodega'] ?? '');
        $si = $di = 0; $veAny = false;
        foreach ($a['negocios'] ?? [] as $n) {
            foreach (($n['valores']['siembra']    ?? []) as $v) $si += (int)$v;
            foreach (($n['valores']['disponible'] ?? []) as $v) $di += (int)$v;
            foreach (($n['valores']['ventas']     ?? []) as $v) if ((int)$v !== 0) $veAny = true;
        }
        if ($si > 0)  $tdaSiembra[$llave] = 1;
        if ($di > 0)  $tdaInv[$llave]     = 1;
        if ($veAny)   $tdaVenta[$llave]   = 1;
    }
}
$espSiembra = count($tdaSiembra);
$espInv     = count($tdaInv);
$espVenta   = count($tdaVenta);
echo "Esperado desde el arbol de C (sin CEDI): siembra=$espSiembra inv=$espInv venta=$espVenta\n\n";

// ---- o14: los tres KPIs, en las dos pestanas y por los dos caminos (cache y vivo) ----
foreach (['b', 'c'] as $tab) {
    foreach (['cache' => '', 'vivo' => '&nocache=1'] as $via => $extra) {
        $d = ep($php, '_endpoint_run_o14.php', $prov, "tab=$tab$extra", $nul);
        $k = $d['kpis'] ?? [];
        echo "o14 tab=$tab ($via): siembra=" . ($k['tiendas_con_siembra'] ?? '?')
           . " inv=" . ($k['tiendas_con_inv'] ?? '?') . " venta=" . ($k['tiendas_con_venta'] ?? '?') . "\n";
        chk((int)($k['tiendas_con_siembra'] ?? -1) === $espSiembra, "tab=$tab ($via) Tiendas c/ Siembra no cuenta el CEDI");
        chk((int)($k['tiendas_con_inv']     ?? -1) === $espInv,     "tab=$tab ($via) Tiendas c/ Inv no cuenta el CEDI");
        chk((int)($k['tiendas_con_venta']   ?? -1) === $espVenta,   "tab=$tab ($via) Tiendas c/ Venta no cuenta el CEDI");
    }
}

// ---- Ventas (g00) tiene que decir el mismo numero de tiendas con siembra ----
$g = ep($php, '_endpoint_run.php', $prov, 'tab=detal', $nul);
$gs = $g['kpis']['tiendas_siembra'] ?? null;
echo "\nVentas (g00): tiendas_siembra=" . var_export($gs, true) . "\n";
chk($gs !== null, 'Ventas devuelve el KPI de tiendas con siembra');
chk((int)$gs === $espSiembra, "Ventas y Siembra dicen lo mismo ($gs contra $espSiembra)");

echo $fail ? "\nRESULTADO: FALLO\n" : "\nRESULTADO: OK\n";
exit($fail);
