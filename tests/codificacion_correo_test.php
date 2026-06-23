<?php // tests/codificacion_correo_test.php
//   php tests/codificacion_correo_test.php
require __DIR__ . '/../api/lib_codificacion.php';
$fail = 0;
function chk($c,$m){ global $fail; if(!$c){ echo "FALLO: $m\n"; $fail=1; } }

$html = cod_correo_html([
  'consecutivo'=>305, 'nombre'=>'BELTRANY SAS', 'nit'=>'900123456',
  'correo'=>'aliado@beltrany.co', 'fecha'=>'23/06/2026',
  'archivos'=>['Plantilla270.xlsx','aviso.xlsx']
]);
chk(strpos($html,'#305')!==false, "debe incluir el consecutivo #305");
chk(strpos($html,'BELTRANY SAS')!==false, "debe incluir el nombre del aliado");
chk(strpos($html,'900123456')!==false, "debe incluir el NIT");
chk(strpos($html,'Plantilla270.xlsx')!==false && strpos($html,'aviso.xlsx')!==false, "debe listar los archivos");
chk(strpos($html,'#4A4782')!==false, "debe usar la paleta del portal");
chk(strpos($html,'logo_aka2_corto.png')!==false, "debe incluir el logo AKA");

// Escapado XSS: un nombre con HTML no debe inyectarse crudo
$h2 = cod_correo_html(['consecutivo'=>1,'nombre'=>'<script>x</script>','nit'=>null,'correo'=>'','fecha'=>'','archivos'=>[]]);
chk(strpos($h2,'<script>x</script>')===false, "el nombre debe ir escapado");
chk(strpos($h2,'&lt;script&gt;')!==false, "el nombre escapado debe aparecer");

echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK\n"; exit($fail);
