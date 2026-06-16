<?php
// S.S.S (Same Store) en tab=tiendas y tab=periodos de G00.
// Caso real: BH BRANDS SAS vendió en AKA FUNZA (COD 621, grupo AKA) en mayo 2025.
// AKA FUNZA cerró el 2026-03-08, así que para mayo 2026 vs 2025 con sss=same debe quedar excluida.
//   php tests/g00_sss_test.php ["PROVEEDOR"]

$prov   = $argv[1] ?? 'BH BRANDS SAS';
$runner = __DIR__ . '/_endpoint_run.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
$PER    = 'desde=2026-05-01&hasta=2026-05-31';

function call_endpoint($php, $runner, $prov, $qs, $nul) {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    $json = ($a !== false && $b !== false && $b >= $a) ? substr($raw, $a, $b - $a + 1) : $raw;
    return json_decode($json, true);
}
function tiene_funza($d) {
    foreach (($d['tiendas'] ?? []) as $t) {
        if (trim((string)($t['cod'] ?? '')) === '621') return true;
    }
    return false;
}

$fail = 0;

// 1) tab=tiendas, No Same -> AKA FUNZA presente (control: vendió en may-2025)
$nos = call_endpoint($php, $runner, $prov, "tab=tiendas&$PER&sss=nosame", $nul);
if (!($nos['ok'] ?? false)) { echo "FALLO: tiendas nosame no ok\n"; exit(1); }
$funzaNoSame = tiene_funza($nos);
echo "tiendas nosame: FUNZA=".($funzaNoSame?'SI':'NO')." (tiendas=".count($nos['tiendas']??[]).")\n";
if (!$funzaNoSame) { echo "FALLO: con nosame AKA FUNZA debería aparecer (precondición del caso)\n"; $fail=1; }

// 2) tab=tiendas, Same -> AKA FUNZA ausente (cerró 2026-03-08)
$sam = call_endpoint($php, $runner, $prov, "tab=tiendas&$PER&sss=same", $nul);
if (!($sam['ok'] ?? false)) { echo "FALLO: tiendas same no ok\n"; exit(1); }
$funzaSame = tiene_funza($sam);
echo "tiendas same:   FUNZA=".($funzaSame?'SI':'NO')." (tiendas=".count($sam['tiendas']??[]).")\n";
if ($funzaSame) { echo "FALLO: con same AKA FUNZA NO debería aparecer (cerrada)\n"; $fail=1; }

// 3) invariante: tiendas(same) ⊆ tiendas(nosame)
$codsNoSame = array_map(fn($t)=>trim((string)$t['cod']), $nos['tiendas']??[]);
foreach (($sam['tiendas']??[]) as $t) {
    if (!in_array(trim((string)$t['cod']), $codsNoSame, true)) {
        echo "FALLO: tienda ".$t['cod']." aparece en same pero no en nosame\n"; $fail=1;
    }
}

// ---- PERIODOS: same-store reduce las ventas del año anterior (excluye tiendas cerradas) ----
function sum_periodos($d, $campo) {
    $s = 0; foreach (($d['dias'] ?? []) as $x) $s += (float)($x[$campo] ?? 0); return $s;
}
$pNos = call_endpoint($php, $runner, $prov, "tab=periodos&$PER&sss=nosame", $nul);
$pSam = call_endpoint($php, $runner, $prov, "tab=periodos&$PER&sss=same", $nul);
if (!($pNos['ok'] ?? false) || !($pSam['ok'] ?? false)) { echo "FALLO: periodos no ok\n"; $fail=1; }
$antNos = sum_periodos($pNos, 'val_ant');
$antSam = sum_periodos($pSam, 'val_ant');
echo "periodos val_ant: nosame=".number_format($antNos)."  same=".number_format($antSam)."\n";
// BH BRANDS vendió en AKA FUNZA (cerrada) en may-2025 -> same debe excluir esas ventas del año anterior
if (!($antSam < $antNos)) { echo "FALLO: periodos same val_ant debería ser MENOR que nosame (excluye tienda cerrada)\n"; $fail=1; }

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
