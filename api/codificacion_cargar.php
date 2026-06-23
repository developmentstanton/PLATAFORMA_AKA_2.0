<?php
use PHPMailer\PHPMailer\PHPMailer;

session_start();
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/lib_codificacion.php';

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['status'=>'error', 'message'=>'No autenticado.']); exit;
}
$nombre = (string)$_SESSION['usuario'];
$nit    = trim((string)($_SESSION['nit'] ?? ''));
$nit    = $nit === '' ? null : $nit;

// 1) Validar archivos
$val = cod_validar_archivos($_FILES['adjunto'] ?? null);
if (!$val['ok']) {
    http_response_code(400);
    echo json_encode(['status'=>'error', 'message'=>$val['error']]); exit;
}
foreach ($val['archivos'] as $a) {
    if (!is_uploaded_file($a['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['status'=>'error', 'message'=>'Archivo inválido.']); exit;
    }
}

// 2) Conexión (con reintento RDS)
require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i = 0; $dbConnect === false && $i < 4; $i++) { usleep(300000); $dbConnect = sqlsrv_connect($servidor, $infoconn); }
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['status'=>'error', 'message'=>'Conexión DB fallida. Intenta de nuevo.']); exit;
}

// 3) Correo del aliado (parametrizado)
$correo = '';
$st = sqlsrv_query($dbConnect, "SELECT correo FROM usuarios_portal_aka WHERE nombre_usuario = ?", [$nombre]);
if ($st !== false && ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC))) $correo = trim((string)($r['correo'] ?? ''));

// 4) Registrar envío
try {
    $consecutivo = cod_registrar_envio($dbConnect, $nombre, date('Y-m-d'), $nit);
} catch (Throwable $e) {
    error_log('codificacion INSERT: '.$e->getMessage());
    echo json_encode(['status'=>'error', 'message'=>'No se pudo registrar el envío.']); exit;
}

// 5) Enviar correo (el registro ya quedó; si falla el correo es 'warning', no se revierte)
require __DIR__ . '/../conexion/config_mail.php';
require __DIR__ . '/../PHPMailer/src/Exception.php';
require __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer/src/SMTP.php';

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USER;
    $mail->Password   = MAIL_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;

    $mail->setFrom(MAIL_USER, MAIL_FROM_NAME);
    if (defined('MAIL_TEST_TO') && MAIL_TEST_TO !== '') {
        // Modo prueba (definido en config_mail.php, gitignored): el correo va SOLO a ese
        // destinatario; no se usa el correo del aliado ni los BCC. No-op en producción.
        $mail->addAddress(MAIL_TEST_TO);
    } else {
        if ($correo !== '') $mail->addAddress($correo);
        foreach (MAIL_BCC as $bcc) $mail->addBCC($bcc);
    }

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'RECIBIDO DE PLANTILLAS PLATAFORMA AKA - ' . $consecutivo;
    $mail->Body = cod_correo_html([
        'consecutivo' => $consecutivo,
        'nombre'      => $nombre,
        'nit'         => $nit,
        'correo'      => $correo,
        'fecha'       => date('d/m/Y'),
        'archivos'    => array_column($val['archivos'], 'name'),
    ]);
    foreach ($val['archivos'] as $a) $mail->addAttachment($a['tmp_name'], $a['name']);

    $mail->send();
    echo json_encode(['status'=>'success', 'consecutivo'=>$consecutivo,
        'message'=>'Recibimos tu portafolio. Consecutivo #'.$consecutivo.'. Te llegará un correo de confirmación.']);
} catch (Throwable $e) {
    error_log('codificacion mail: '.$mail->ErrorInfo);
    echo json_encode(['status'=>'warning', 'consecutivo'=>$consecutivo,
        'message'=>'Tu envío quedó registrado (#'.$consecutivo.') pero el correo no pudo enviarse.']);
}
