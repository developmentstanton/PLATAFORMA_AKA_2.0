<?php
// Ejecuta el endpoint de Pagos como request real, seteando el NIT en sesión.
//   php tests/_endpoint_run_pagos.php "NIT" "querystring"
error_reporting(E_ALL); ini_set('display_errors', '0');
$nit = $argv[1] ?? '';
$qs  = $argv[2] ?? '';
session_start();
$_SESSION = ['usuario' => 'test', 'nit' => $nit];
parse_str($qs, $_GET);
include __DIR__ . '/../api/informe_pagos.php';
