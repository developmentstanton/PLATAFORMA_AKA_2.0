<?php
// Ejecuta el endpoint Evolución como request y vuelca el JSON. Uso:
//   php tests/_endpoint_run_evol.php "PROVEEDOR" "tab=data&desde=2025-01&hasta=2026-06"
error_reporting(E_ALL); ini_set('display_errors', '0');
$prov = $argv[1] ?? 'BH BRANDS SAS';
$qs   = $argv[2] ?? 'tab=data';
session_start();
$_SESSION = ['usuario' => 'test', 'proveedor' => $prov];
parse_str($qs, $_GET);
if (!isset($_GET['tab'])) $_GET['tab'] = 'data';
include __DIR__ . '/../api/informe_evol.php';
