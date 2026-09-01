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

echo "\nNADIE MAS EN COPIA\n" . str_repeat('=', 70) . "\n";

// Requisito de Rafael (2026-09-01): el aviso de auditoria lo reciben SOLO los correos de
// MAIL_AUDITORIA_TO. Nadie mas, ni en copia oculta.
//
// Esto no es paranoia: config_mail.php define MAIL_BCC con otros tres correos, y
// api/codificacion_cargar.php SI los agrega. Copiar ese patron aqui "por consistencia"
// mandaria cada auditoria a tres personas que no deben verla. Se comprueba sobre el
// codigo fuente porque el envio no se puede ejercitar sin mandar un correo de verdad.
$fuente = (string)file_get_contents(__DIR__ . '/../api/lib_verificacion.php');

chequear('el modulo no menciona BCC en ninguna forma',
    stripos($fuente, 'bcc') === false,
    'config_mail.php define MAIL_BCC; este modulo no debe tocarlo');

// Los unicos destinatarios posibles son el argumento $destinatarios y MAIL_TEST_TO.
preg_match_all('/->add(Address|CC|BCC)\s*\(([^)]*)\)/i', $fuente, $m, PREG_SET_ORDER);
$origenes = array_map(fn($x) => trim($x[1] . '(' . trim($x[2]) . ')'), $m);
chequear('solo hay dos formas de agregar destinatario',
    $origenes === ['Address(MAIL_TEST_TO)', 'Address($d)'],
    implode(' | ', $origenes) ?: 'ninguna');

echo "\n" . str_repeat('=', 70) . "\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
