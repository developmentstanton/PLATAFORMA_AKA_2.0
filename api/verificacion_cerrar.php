<?php
/**
 * POST → valida completitud, cierra la auditoría y (Task 7) envía el correo.
 *
 * El cierre y el envío están separados a propósito: si el SMTP falla, la auditoría YA
 * quedó guardada. El registro es el dato valioso; el correo es la notificación.
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
$comentarios = isset($_POST['comentarios']) ? trim((string)$_POST['comentarios']) : null;
if ($auditoriaId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Verificación no válida.']);
    exit;
}

require __DIR__ . '/../conexion/conexion_integracion.php';
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos.']);
    exit;
}

try {
    $a = verif_cargar($dbConnect, $auditoriaId);
    if ($a === null || $a['proveedor'] !== trim((string)($_SESSION['proveedor'] ?? ''))) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'La verificación no existe.']);
        exit;
    }

    // La completitud se exige en el SERVIDOR, no solo en el navegador: es la condición
    // que le da sentido al registro.
    $faltan = verif_faltantes(array_keys($a['puntos']));
    if ($faltan) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'faltantes' => $faltan,
            'error' => 'Faltan informes por marcar: '
                . implode(', ', array_map(fn($k) => VERIF_INFORMES[$k], $faltan))]);
        exit;
    }

    if (!verif_cerrar($dbConnect, $auditoriaId, $comentarios)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Esta verificación ya estaba cerrada.']);
        exit;
    }
} catch (Throwable $e) {
    error_log('verificacion cerrar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo cerrar la verificación.']);
    exit;
}

// La auditoría YA quedó cerrada. Lo que sigue es la notificación: si falla, se informa,
// pero NO se revierte el cierre — el registro es el dato valioso.
require_once __DIR__ . '/lib_verificacion_pdf.php';

$rutaPdf = '';
try {
    $paquete = verif_armar_paquete($dbConnect, $auditoriaId);
    $rutaPdf = sys_get_temp_dir() . '/' . $paquete['nombre_pdf'];
    verif_pdf($paquete, $rutaPdf);
    verif_enviar($paquete, verif_destinatarios($a['proveedor']), $rutaPdf,
                 verif_copias($a['proveedor']));
    verif_marcar_correo_enviado($dbConnect, $auditoriaId);
    echo json_encode(['ok' => true, 'correo_enviado' => true]);
} catch (Throwable $e) {
    error_log('verificacion envio: ' . $e->getMessage());
    echo json_encode(['ok' => true, 'correo_enviado' => false,
        'aviso' => 'La verificación quedó guardada, pero el correo no se pudo enviar. '
                 . 'Avisa a sistemas; el registro N.° ' . $auditoriaId . ' ya está en la base.']);
} finally {
    if ($rutaPdf !== '' && is_file($rutaPdf)) @unlink($rutaPdf);
}
