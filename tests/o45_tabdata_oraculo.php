<?php
// Ejecuta tab=data de o45 con sesión simulada y emite su JSON crudo (oráculo del doble-check).
error_reporting(0); ini_set('display_errors','0');
@session_start();
$_SESSION['usuario']='oraculo'; $_SESSION['proveedor']=$argv[1] ?? ''; $_SESSION['nit']='';
$_GET=['tab'=>'data','desde'=>$argv[2] ?? '2025-01-01','hasta'=>$argv[3] ?? date('Y-m-d',strtotime('-1 day'))];
$_REQUEST=$_GET;
include __DIR__ . '/../api/informe_o45.php';
