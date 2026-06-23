<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/lib_documentos.php';

if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }
$nombre = (string)$_SESSION['usuario'];

ob_start();
require __DIR__ . '/../conexion/conexion_integracion.php';
ob_end_clean(); // descarta el <script> de error si la 1a conexion falla (mantiene el JSON limpio)
for ($i=0; $dbConnect===false && $i<4; $i++){ usleep(300000); $dbConnect=sqlsrv_connect($servidor,$infoconn); }
if ($dbConnect===false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Conexión DB fallida']); exit; }

try { $documentos = doc_listar($dbConnect, $nombre); }
catch (Throwable $e) { error_log('documentos listar: '.$e->getMessage()); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'No se pudieron cargar los documentos']); exit; }
echo json_encode(['ok'=>true, 'documentos'=>$documentos]);
