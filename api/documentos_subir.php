<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/lib_documentos.php';

if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }
$nombre = (string)$_SESSION['usuario'];
$nit = trim((string)($_SESSION['nit'] ?? '')); $nit = $nit === '' ? null : $nit;
$tipo = isset($_POST['tipo']) ? (string)$_POST['tipo'] : null;
$archivo = $_FILES['documento'] ?? null;

$val = doc_validar($tipo, $archivo);
if (!$val['ok']) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$val['error']]); exit; }
if (!is_uploaded_file($archivo['tmp_name'])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Archivo inválido.']); exit; }
$contenido = file_get_contents($archivo['tmp_name']);
if ($contenido === false) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No se pudo leer el archivo.']); exit; }
$ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
$mime = doc_mime_por_ext($ext);

require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i=0; $dbConnect===false && $i<4; $i++){ usleep(300000); $dbConnect=sqlsrv_connect($servidor,$infoconn); }
if ($dbConnect===false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Conexión DB fallida']); exit; }

try {
    $id = doc_guardar($dbConnect, $nombre, $nit, $tipo, (string)$archivo['name'], $mime, $contenido);
} catch (Throwable $e) {
    error_log('documentos subir: '.$e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'No se pudo guardar el documento.']); exit;
}
echo json_encode(['ok'=>true, 'id'=>$id]);
