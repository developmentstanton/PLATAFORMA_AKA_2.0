<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/lib_codificacion.php';

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false, 'error'=>'No autenticado']); exit;
}
$nombre = (string)$_SESSION['usuario'];

require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i = 0; $dbConnect === false && $i < 4; $i++) { usleep(300000); $dbConnect = sqlsrv_connect($servidor, $infoconn); }
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Conexión DB fallida']); exit;
}

try {
    $solicitudes = cod_listar_solicitudes($dbConnect, $nombre);
} catch (Throwable $e) {
    error_log('codificacion solicitudes: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'No se pudieron cargar las solicitudes']); exit;
}

echo json_encode(['ok'=>true, 'solicitudes'=>$solicitudes]);
