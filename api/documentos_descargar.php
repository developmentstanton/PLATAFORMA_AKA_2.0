<?php
session_start();
require __DIR__ . '/lib_documentos.php';

if (!isset($_SESSION['usuario'])) { http_response_code(401); echo 'No autenticado'; exit; }
$nombre = (string)$_SESSION['usuario'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

ob_start();
require __DIR__ . '/../conexion/conexion_integracion.php';
ob_end_clean(); // descarta el <script> de error si la 1a conexion falla (evita corromper el binario)
for ($i=0; $dbConnect===false && $i<4; $i++){ usleep(300000); $dbConnect=sqlsrv_connect($servidor,$infoconn); }
if ($dbConnect===false) { http_response_code(500); echo 'Conexión DB fallida'; exit; }

try { $doc = doc_obtener($dbConnect, $id, $nombre); }
catch (Throwable $e) { error_log('documentos descargar: '.$e->getMessage()); http_response_code(500); echo 'Error'; exit; }
if ($doc === null) { http_response_code(404); echo 'Documento no encontrado'; exit; }

$fn = preg_replace('/[\r\n"]/', '', $doc['nombre_archivo']); // sanea el header
header('Content-Type: '.$doc['mime']);
header('Content-Disposition: attachment; filename="'.$fn.'"');
header('Content-Length: '.strlen($doc['contenido']));
echo $doc['contenido'];
