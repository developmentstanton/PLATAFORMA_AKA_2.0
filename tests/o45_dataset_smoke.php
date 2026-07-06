<?php
error_reporting(0); ini_set('display_errors','0');
@session_start();
$_SESSION['usuario']='smoke'; $_SESSION['proveedor']=$argv[1] ?? 'BH BRANDS SAS'; $_SESSION['nit']='';
$_GET=['tab'=>'dataset','desde'=>'2025-01-01','hasta'=>date('Y-m-d',strtotime('-1 day'))]; $_REQUEST=$_GET;
ob_start();
register_shutdown_function(function(){
    $j=json_decode(ob_get_contents(),true); if(ob_get_level())ob_end_clean();
    $ok = is_array($j) && ($j['tab']??'')==='dataset' && isset($j['columnas'],$j['filas'],$j['precios'],$j['rango'])
        && count($j['columnas'])===19 && (count($j['filas'])===0 || count($j['filas'][0])===count($j['columnas']));
    fwrite(STDERR, ($ok?'SMOKE OK':'SMOKE FAIL')." filas=".(is_array($j['filas']??null)?count($j['filas']):'?')."\n");
    exit($ok?0:1);
});
include __DIR__ . '/../api/informe_o45.php';
