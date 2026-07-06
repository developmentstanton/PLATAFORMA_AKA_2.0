<?php
// Ejecuta un tab de o45 con sesión simulada y emite su JSON crudo.
// argv: 1=tab, 2=proveedor, 3..=filtros "clave=valor" (multi-valor: repetir la clave).
error_reporting(0); ini_set('display_errors','0');
@session_start();
$_SESSION['usuario']='cap'; $_SESSION['proveedor']=$argv[2] ?? ''; $_SESSION['nit']='';
$g=['tab'=>$argv[1] ?? 'data','desde'=>'2025-01-01','hasta'=>date('Y-m-d',strtotime('-1 day'))];
foreach (array_slice($argv,3) as $kv){ [$k,$v]=array_pad(explode('=',$kv,2),2,''); $g[$k][]=$v; } // filtros multi-valor
$_GET=$g; $_REQUEST=$g;
include __DIR__ . '/../api/informe_o45.php';
