<?php
// warmProveedor debe dejar escrito el cache diario de filtros de evol (lo que sirve el hit del
// endpoint tras el arreglo 4ef8ec4), para que el 1er aliado del dia NO pague el miss de ~204s.
//   php tests/prewarm_filtros_test.php ["PROVEEDOR_CON_DATOS"] ["PROVEEDOR_VACIO"]
$provDatos = $argv[1] ?? 'BH BRANDS SAS';
$provVacio = $argv[2] ?? 'GUAUTA SHOES';
$root = dirname(__DIR__);
require "$root/conexion/conexion_integracion.php";
require_once "$root/api/lib_prewarm.php";
if ($dbConnect === false) { echo "FALLO: conexión DB\n"; exit(1); }

echo "PREWARM filtros evol\n\n";
$fallos = [];

// 1) Proveedor CON datos: se escribe el cache diario, de hoy, con combos y hit válido.
$daily = "$root/cache/evol_filtros_" . md5($provDatos) . '.json';
@unlink($daily);
$r = warmProveedor($dbConnect, $provDatos, false);
printf("[%s] evol=%s evol_filtros=%s\n", $provDatos, $r['evol'], $r['evol_filtros'] ?? '(ausente)');
if (($r['evol_filtros'] ?? '') !== 'warmed') $fallos[] = "evol_filtros='" . ($r['evol_filtros'] ?? 'ausente') . "' (se esperaba 'warmed')";
elseif (!is_file($daily))                     $fallos[] = "no se escribió $daily";
else {
    if (date('Y-m-d', filemtime($daily)) !== date('Y-m-d')) $fallos[] = "el archivo no es de hoy";
    $j = json_decode((string)file_get_contents($daily), true);
    if (!is_array($j) || ($j['ok'] ?? false) !== true || empty($j['combos'])) $fallos[] = "el archivo no es un hit válido (ok+combos)";
}

// 2) Proveedor VACÍO: no es 'warmed' ni escribe basura; 'vacio' es correcto.
$dailyV = "$root/cache/evol_filtros_" . md5($provVacio) . '.json';
@unlink($dailyV);
$rv = warmProveedor($dbConnect, $provVacio, false);
printf("[%s] evol=%s evol_filtros=%s\n", $provVacio, $rv['evol'], $rv['evol_filtros'] ?? '(ausente)');
if (($rv['evol_filtros'] ?? '') === 'warmed') $fallos[] = "un proveedor sin datos no debería reportar 'warmed'";
if (($rv['evol_filtros'] ?? '') === 'failed') $fallos[] = "un proveedor sin datos no es 'failed' (debe ser 'vacio')";

sqlsrv_close($dbConnect);
if ($fallos) { foreach ($fallos as $f) echo "FALLO: $f\n"; exit(1); }
echo "\nOK: warmProveedor deja el cache diario de filtros listo; vacío no es fallo.\n";
exit(0);
