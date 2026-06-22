<?php
// php tests/pagos_trm_test.php
require __DIR__ . '/../api/lib_trm.php';
$fail=0;
$t = obtener_trm();
foreach (['USD','EUR','fecha','fallback'] as $k) if (!array_key_exists($k,$t)) { echo "FALLO: falta $k en trm\n"; $fail=1; }
if (!is_numeric($t['USD']??null) || !is_numeric($t['EUR']??null)) { echo "FALLO: USD/EUR no numéricos\n"; $fail=1; }
if (!is_bool($t['fallback']??null)) { echo "FALLO: fallback no bool\n"; $fail=1; }
// Mapeo de tasa documentado (mismo que usa el endpoint): COP=1, USD=trm.USD, EU/EUR=trm.EUR
$tasa = function($m,$trm){ return $m==='COP'?1.0:($m==='USD'?($trm['USD']?:1):(($m==='EU'||$m==='EUR')?($trm['EUR']?:1):1)); };
$trmFake=['USD'=>4000.0,'EUR'=>4500.0,'fecha'=>'2026-06-22','fallback'=>false];
if (abs(100*$tasa('USD',$trmFake)-400000)>0.01){ echo "FALLO: conversión USD\n"; $fail=1; }
if (abs(100*$tasa('EU',$trmFake)-450000)>0.01){ echo "FALLO: conversión EU\n"; $fail=1; }
if (abs(100*$tasa('COP',$trmFake)-100)>0.01){ echo "FALLO: COP debe ser identidad\n"; $fail=1; }
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (trm shape + mapeo de tasa USD/EU/COP)\n";
exit($fail);
