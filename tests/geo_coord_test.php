<?php
// Unit test de parseCoord (lib_geo). Uso: php tests/geo_coord_test.php
require __DIR__ . '/../api/lib_geo.php';
$fail = 0;
function chk(&$fail,$label,$got,$exp){
  $g = json_encode($got); $e = json_encode($exp);
  if ($g !== $e) { echo "FALLO $label: got=$g exp=$e\n"; $fail=1; }
}
// Formatos reales observados en Bodegas.COORDENADAS
chk($fail,'espacio',      parseCoord('4.609927 -74.083026'),     [4.609927, -74.083026]);
chk($fail,'coma+espacio', parseCoord('4.689665, -74.071421'),    [4.689665, -74.071421]);
chk($fail,'coma',         parseCoord('4.70217,-74.041622'),      [4.70217, -74.041622]);
chk($fail,'coma+zoom',    parseCoord('4.6480302,-74.0912985,17'),[4.6480302, -74.0912985]); // ignora el 3er numero
// Basura / vacíos / fuera de rango -> [null,null]
chk($fail,'vacio',        parseCoord(''),        [null,null]);
chk($fail,'null',         parseCoord(null),      [null,null]);
chk($fail,'un_solo_num',  parseCoord('4.6099'),  [null,null]);
chk($fail,'texto',        parseCoord('N/A'),     [null,null]);
chk($fail,'fuera_rango',  parseCoord('40.7128, -74.0060'), [null,null]); // Nueva York: lat fuera de Colombia
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
