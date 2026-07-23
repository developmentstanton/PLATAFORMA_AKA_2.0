<?php
/**
 * POST → cambia el estado (y el motivo) de una planilla.
 *
 * Es una escritura, así que exige CSRF. El orden de las comprobaciones importa: sesión,
 * método, CSRF y validación de negocio ocurren ANTES de abrir la conexión, para no gastar
 * una conexión a la RDS en una petición que ya sabemos que va a ser rechazada.
 */
require_once __DIR__ . '/../lib_admin_auth.php';
require_once __DIR__ . '/../lib_planillas.php';

header('Content-Type: application/json; charset=utf-8');
admin_exigir_sesion_json();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$tokenEnviado = (string)($_POST['csrf_token'] ?? '');
$tokenSesion  = (string)($_SESSION['admin_csrf_token'] ?? '');
if ($tokenSesion === '' || !hash_equals($tokenSesion, $tokenEnviado)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solicitud no válida. Recarga la página.']);
    exit;
}

$consecutivo = (int)($_POST['consecutivo'] ?? 0);
$estado      = trim((string)($_POST['estado'] ?? ''));
$motivo      = isset($_POST['motivo']) ? trim((string)$_POST['motivo']) : null;

if ($consecutivo <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Consecutivo no válido.']);
    exit;
}

$error = planillas_validar($estado, $motivo);
if ($error !== '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

require __DIR__ . '/../../conexion/conexion_integracion.php';
for ($i = 0; $dbConnect === false && $i < 4; $i++) {
    usleep(300000);
    $dbConnect = sqlsrv_connect($servidor, $infoconn);
}
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos.']);
    exit;
}

try {
    // Solo se pueden cambiar las planillas en 'Estudio': una decisión (Aprobado/Rechazado)
    // es definitiva. Se consulta el estado actual para dar un mensaje claro; el UPDATE de
    // planillas_cambiar_estado repite la condición en su WHERE como barrera atómica.
    $actual = planillas_estado_actual($dbConnect, $consecutivo);
    if ($actual === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'La planilla no existe.']);
        exit;
    }
    if ($actual !== 'Estudio') {
        http_response_code(409);
        echo json_encode(['ok' => false,
            'error' => 'Esta planilla ya está en «' . $actual . '»; solo se pueden cambiar las que están en Estudio.']);
        exit;
    }

    $ok = planillas_cambiar_estado($dbConnect, $consecutivo, $estado, $motivo);
} catch (Throwable $e) {
    error_log('admin planillas estado: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el cambio.']);
    exit;
}

if (!$ok) {
    // Llegar aquí con estado 'Estudio' confirmado arriba significa que otra petición la
    // decidió en el intervalo (carrera). El cambio no se aplicó.
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'La planilla acaba de cambiar de estado. Recarga la página.']);
    exit;
}

echo json_encode(['ok' => true]);
