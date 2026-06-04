<?php
// Helper de tests: ejecuta el endpoint G00 real como un request HTTP y vuelca en stdout
// EXACTAMENTE el JSON que recibiría el navegador. Usa la conexión real (no hay credenciales
// en este archivo). Uso interno:  php tests/_endpoint_run.php "PROVEEDOR" "querystring"
error_reporting(E_ALL); ini_set('display_errors', '0');
$prov = $argv[1] ?? 'BELTRANY SAS';
$qs   = $argv[2] ?? 'tab=detal';
session_start();
$_SESSION = ['usuario' => 'test', 'proveedor' => $prov];
parse_str($qs, $_GET);
if (!isset($_GET['tab'])) $_GET['tab'] = 'detal';
include __DIR__ . '/../api/informe_g00.php';
