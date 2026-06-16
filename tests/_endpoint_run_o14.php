<?php
// Ejecuta el endpoint O14 real como request y vuelca el JSON. Uso:
//   php tests/_endpoint_run_o14.php "PROVEEDOR" "tab=b&grupo[]=AKA"
error_reporting(E_ALL); ini_set('display_errors', '0');
$prov = $argv[1] ?? 'BELTRANY SAS';
$qs   = $argv[2] ?? 'tab=b';
session_start();
$_SESSION = ['usuario' => 'test', 'proveedor' => $prov];
parse_str($qs, $_GET);
if (!isset($_GET['tab'])) $_GET['tab'] = 'b';
include __DIR__ . '/../api/informe_o14.php';
