<?php
/**
 * POST → guarda un punto de control. Re-marcar el mismo informe actualiza, no duplica.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/lib_verificacion.php';

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}
$tokenSesion = (string)($_SESSION['csrf_token'] ?? '');
if ($tokenSesion === '' || !hash_equals($tokenSesion, (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solicitud no válida. Recarga la página.']);
    exit;
}

$auditoriaId = (int)($_POST['auditoria_id'] ?? 0);
$informe     = trim((string)($_POST['informe'] ?? ''));
$resultado   = trim((string)($_POST['resultado'] ?? ''));
$observacion = isset($_POST['observacion']) ? trim((string)$_POST['observacion']) : null;

if ($auditoriaId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Verificación no válida.']);
    exit;
}
$error = verif_validar_punto($informe, $resultado, $observacion);
if ($error !== '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

require __DIR__ . '/../conexion/conexion_integracion.php';
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos.']);
    exit;
}

try {
    // La auditoría tiene que ser del aliado de ESTA sesión y seguir abierta. Sin esta
    // comprobación, un id ajeno en el POST escribiría sobre la auditoría de otro aliado.
    $a = verif_cargar($dbConnect, $auditoriaId);
    if ($a === null || $a['proveedor'] !== trim((string)($_SESSION['proveedor'] ?? ''))) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'La verificación no existe.']);
        exit;
    }
    if ($a['estado'] !== 'en_curso') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Esta verificación ya está cerrada.']);
        exit;
    }
    verif_guardar_punto($dbConnect, $auditoriaId, $informe, $resultado, $observacion);
} catch (Throwable $e) {
    error_log('verificacion guardar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar.']);
    exit;
}

echo json_encode(['ok' => true]);
