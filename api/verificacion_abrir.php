<?php
/**
 * POST → abre una auditoría para el aliado de la sesión, o recupera la que esté en curso.
 *
 * El orden de las comprobaciones importa: sesión, método y CSRF ocurren ANTES de abrir la
 * conexión, para no gastar una conexión a la RDS en una petición ya rechazada.
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

$proveedor = trim((string)($_SESSION['proveedor'] ?? ''));
$usuario   = trim((string)$_SESSION['usuario']);
$auditor   = trim((string)($_POST['auditor'] ?? ''));

if ($proveedor === '') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'La sesión no tiene aliado asociado; no hay a quién auditar.']);
    exit;
}
if ($auditor === '' || mb_strlen($auditor) > 120) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Escribe tu nombre (máximo 120 caracteres).']);
    exit;
}
// El proveedor centinela es de los tests: no puede entrar por el camino real.
if ($proveedor === VERIF_PROVEEDOR_TEST) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Aliado no auditable.']);
    exit;
}

require __DIR__ . '/../conexion/conexion_integracion.php';
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos.']);
    exit;
}

try {
    $a = verif_abrir($dbConnect, $proveedor, $usuario, $auditor);
    $completa = verif_cargar($dbConnect, $a['id']);
} catch (Throwable $e) {
    error_log('verificacion abrir: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo abrir la verificación.']);
    exit;
}

echo json_encode([
    'ok'        => true,
    'id'        => $a['id'],
    'auditor'   => $a['auditor'],
    'nueva'     => $a['nueva'],
    'estado'    => $completa['estado'],
    'puntos'    => $completa['puntos'],
    'proveedor' => $proveedor,
]);
