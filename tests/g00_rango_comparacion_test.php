<?php // tests/g00_rango_comparacion_test.php
//   php tests/g00_rango_comparacion_test.php
// Verifica g00_rango_comparacion() y g00_set_anio() (lógica pura, sin BD).
require __DIR__ . '/../api/lib_g00_rango.php';
$fail=0; function chk($c,$m){ global $fail; if(!$c){ echo "FALLO: $m\n"; $fail=1; } }

// 1) diaadia adyacente (2026 vs 2025) — comportamiento por defecto histórico.
$r = g00_rango_comparacion('2026-01-01','2026-06-26', 2025, 'diaadia');
chk($r === ['2026-01-01','2026-06-26','2025-01-01','2025-06-26', null], "diaadia adyacente: ".json_encode($r));

// 2) diaadia no adyacente (2026 vs 2023).
$r = g00_rango_comparacion('2026-01-01','2026-06-26', 2023, 'diaadia');
chk($r === ['2026-01-01','2026-06-26','2023-01-01','2023-06-26', null], "diaadia no adyacente: ".json_encode($r));

// 3) retail brecha 1 año = -364 días.
$r = g00_rango_comparacion('2026-06-26','2026-06-26', 2025, 'retail');
chk($r[2] === date('Y-m-d', strtotime('2026-06-26 -364 days')) && $r[4] === null, "retail gap1: ".json_encode($r));

// 4) retail brecha 3 años = -1092 días (364*3).
$r = g00_rango_comparacion('2026-06-26','2026-06-26', 2023, 'retail');
chk($r[2] === date('Y-m-d', strtotime('2026-06-26 -1092 days')), "retail gap3: ".json_encode($r));

// 5) 29-feb en año menor NO bisiesto (2023) → 28-feb.
$r = g00_rango_comparacion('2024-02-29','2024-02-29', 2023, 'diaadia');
chk($r[2] === '2023-02-28' && $r[3] === '2023-02-28', "29feb→28feb: ".json_encode($r));

// 6) 29-feb en año menor bisiesto (2020) se conserva.
chk(g00_set_anio('2024-02-29', 2020) === '2020-02-29', "29feb año bisiesto se conserva");

// 7) anioB >= anioA → error.
$r = g00_rango_comparacion('2026-01-01','2026-06-26', 2026, 'diaadia');
chk($r[4] === 'rango_anios_invalido', "anioB=anioA inválido: ".json_encode($r));
$r = g00_rango_comparacion('2026-01-01','2026-06-26', 2027, 'diaadia');
chk($r[4] === 'rango_anios_invalido', "anioB>anioA inválido: ".json_encode($r));

// 8) anioB <= 0 → error.
$r = g00_rango_comparacion('2026-01-01','2026-06-26', 0, 'diaadia');
chk($r[4] === 'rango_anios_invalido', "anioB=0 inválido: ".json_encode($r));

// 9) g00_cap_hasta — recorta la fecha final al día de cierre (ayer) salvo 31-dic / fechas ya cerradas.
$ayer = '2026-06-30';
chk(g00_cap_hasta('2026-07-01', $ayer) === '2026-06-30', "cap: hoy (año en curso) se recorta a ayer");
chk(g00_cap_hasta('2026-06-30', $ayer) === '2026-06-30', "cap: exactamente ayer se conserva");
chk(g00_cap_hasta('2026-06-15', $ayer) === '2026-06-15', "cap: fecha pasada ya cerrada se conserva");
chk(g00_cap_hasta('2026-12-31', $ayer) === '2026-12-31', "cap: 31-dic (año completo) exento aunque sea futuro");
chk(g00_cap_hasta('2025-12-31', $ayer) === '2025-12-31', "cap: año pasado se conserva");
chk(g00_cap_hasta('2025-07-01', $ayer) === '2025-07-01', "cap: año pasado no se recorta aunque mm-dd > ayer");

echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (diaadia/retail/29feb/validación/cap)\n"; exit($fail);
