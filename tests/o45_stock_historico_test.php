<?php
// O45: corte de stock = max(corte <= hasta). vivo si hasta>=foto viva (ayer); si no, fin de mes historico.
//   php tests/o45_stock_historico_test.php
$runner = __DIR__ . '/_endpoint_run_o45.php';
$php = PHP_BINARY;
$nul = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
function ep($php,$runner,$prov,$qs,$nul){
    $cmd = escapeshellarg($php).' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner).' '.escapeshellarg($prov).' '.escapeshellarg($qs).' 2>'.$nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw,'{'); $b = strrpos($raw,'}');
    return json_decode(($a!==false && $b!==false) ? substr($raw,$a,$b-$a+1) : $raw, true);
}
$fail = 0;
$prov = 'BH BRANDS SAS';

// (se filtra por referencia[] para que A/B/C corran rápido: stock_corte no depende del filtro de ref)
$rf = '&referencia[]=555006144';

// A) hasta futuro => 'vivo'
$d = ep($php,$runner,$prov,'tab=data&desde=2026-01-01&hasta=2026-12-31'.$rf,$nul);
$sc = $d['rango']['stock_corte'] ?? null;
echo "hasta=2026-12-31 stock_corte=".var_export($sc,true)."\n";
if ($sc !== 'vivo') { echo "FALLO: esperaba 'vivo'\n"; $fail = 1; }

// B) hasta en junio (antes de hoy) => corte 2026-05-31
$d = ep($php,$runner,$prov,'tab=data&desde=2026-01-01&hasta=2026-06-06'.$rf,$nul);
$sc = $d['rango']['stock_corte'] ?? null;
echo "hasta=2026-06-06 stock_corte=".var_export($sc,true)."\n";
if ($sc !== '2026-05-31') { echo "FALLO: esperaba '2026-05-31'\n"; $fail = 1; }

// C) hasta en abril => corte 2026-03-31
$d = ep($php,$runner,$prov,'tab=data&desde=2026-01-01&hasta=2026-04-15'.$rf,$nul);
$sc = $d['rango']['stock_corte'] ?? null;
echo "hasta=2026-04-15 stock_corte=".var_export($sc,true)."\n";
if ($sc !== '2026-03-31') { echo "FALLO: esperaba '2026-03-31'\n"; $fail = 1; }

// D) #tiendas historico: negocio 555006144-GBE incluye tiendas con inventario en cortes del rango (I01,R90).
$d = ep($php,$runner,'BH BRANDS SAS','tab=data&desde=2026-01-01&hasta=2026-06-10&referencia[]=555006144',$nul);
$gbe = null;
foreach (($d['filas'] ?? []) as $f) if (($f['negocio'] ?? '') === '555006144-GBE') { $gbe = $f; break; }
if (!$gbe) { echo "FALLO: no aparece negocio 555006144-GBE\n"; $fail = 1; }
else {
    echo "555006144-GBE tiendas=".$gbe['tiendas']." (esperado 7)\n";
    if ((int)$gbe['tiendas'] !== 7) { echo "FALLO: #tiendas historico != 7\n"; $fail = 1; }
}

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
