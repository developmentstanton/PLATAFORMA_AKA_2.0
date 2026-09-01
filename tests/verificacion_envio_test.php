<?php
// Contrato de verif_enviar(). Este test NO envia correo: comprueba que se NIEGA a hacerlo
// cuando no hay a quien. Es la ultima red: si alguien despliega sin configurar
// MAIL_AUDITORIA_TO, el sistema falla ruidosamente en vez de mandar a nadie en silencio.
//
//   php tests/verificacion_envio_test.php

require_once __DIR__ . '/../api/lib_verificacion.php';

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "CONTRATO DE verif_enviar()\n" . str_repeat('=', 70) . "\n";

$paqueteFalso = ['auditoria' => ['id' => 0, 'proveedor' => VERIF_PROVEEDOR_TEST],
                 'asunto' => 'x', 'cuerpo_html' => 'x', 'nombre_pdf' => 'x.pdf', 'filas' => []];

$lanzo = false;
try { verif_enviar($paqueteFalso, [], sys_get_temp_dir() . '/no_existe.pdf'); }
catch (RuntimeException $e) { $lanzo = true; }
chequear('sin destinatarios lanza excepcion en vez de enviar', $lanzo);

$lanzo = false;
try { verif_enviar($paqueteFalso, ['a@b.co'], sys_get_temp_dir() . '/no_existe_' . uniqid() . '.pdf'); }
catch (RuntimeException $e) { $lanzo = true; }
chequear('sin PDF adjunto lanza excepcion en vez de enviar un aviso vacio', $lanzo);

chequear('el proveedor centinela sigue sin resolver destinatarios',
    verif_destinatarios(VERIF_PROVEEDOR_TEST) === []);

// Las dos guardas van ANTES de cualquier require de PHPMailer o de SMTP: si el orden se
// invirtiera, un despliegue sin configurar abriria una conexion SMTP antes de descubrir
// que no tiene a quien escribirle.
chequear('rechazar no llego a cargar PHPMailer',
    !class_exists('PHPMailer\\PHPMailer\\PHPMailer', false),
    'las guardas deben cortar antes de tocar la red');

echo "\n" . str_repeat('=', 70) . "\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
