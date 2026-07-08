<?php
// Helper de tests: ejecuta el endpoint O14 real como un request HTTP y vuelca en stdout
// EXACTAMENTE el JSON que recibiría el navegador. Generaliza tests/_o14_run.php (Task 3, que
// hardcodeaba "BELTRANY SAS") a cualquier proveedor + querystring, mismo patrón que
// tests/_endpoint_run.php (G00). session_start() ANTES de setear $_SESSION: el
// session_start() del endpoint queda no-op y preserva esta sesión simulada.
// Uso: php tests/_o14_endpoint_run.php "PROVEEDOR" "querystring"
error_reporting(E_ALL); ini_set('display_errors', '0');
$prov = $argv[1] ?? 'BELTRANY SAS';
$qs   = $argv[2] ?? 'tab=b';
session_start();
$_SESSION = ['usuario' => 'test', 'proveedor' => $prov];
parse_str($qs, $_GET);
if (!isset($_GET['tab'])) $_GET['tab'] = 'b';
include __DIR__ . '/../api/informe_o14.php';
