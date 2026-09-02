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

echo "\nQUIEN RECIBE, Y NADIE MAS\n" . str_repeat('=', 70) . "\n";

// Requisito de Rafael (2026-09-01, ampliado el 2026-09-02): el aviso lo reciben los
// correos de MAIL_AUDITORIA_TO y, en COPIA VISIBLE, los de MAIL_AUDITORIA_CC. Nadie mas,
// y en particular nadie en copia OCULTA.
//
// Esto no es paranoia: config_mail.php define MAIL_BCC con otros tres correos, y
// api/codificacion_cargar.php SI los agrega. Copiar ese patron aqui "por consistencia"
// mandaria cada auditoria a tres personas que no deben verla. Se comprueba sobre el
// codigo fuente porque el envio no se puede ejercitar sin mandar un correo de verdad.
$fuente = (string)file_get_contents(__DIR__ . '/../api/lib_verificacion.php');

chequear('el modulo no menciona BCC en ninguna forma',
    stripos($fuente, 'bcc') === false,
    'config_mail.php define MAIL_BCC; este modulo no debe tocarlo');

// Las unicas formas de agregar a alguien son las dos listas que devuelve
// verif_envio_destinos(). Ninguna direccion entra directamente en verif_enviar().
preg_match_all('/->add(Address|CC|BCC)\s*\(([^)]*)\)/i', $fuente, $m, PREG_SET_ORDER);
$origenes = array_map(fn($x) => trim($x[1] . '(' . trim($x[2]) . ')'), $m);
chequear('solo hay dos formas de agregar destinatario',
    $origenes === ['Address($d)', 'CC($c)'],
    implode(' | ', $origenes) ?: 'ninguna');

echo "\nLA COPIA A COORDINACION\n" . str_repeat('=', 70) . "\n";

// El TO vacio es un error (envio a nadie). El CC vacio NO lo es: un despliegue sin
// MAIL_AUDITORIA_CC debe seguir enviando el aviso a los destinatarios de siempre.
chequear('el proveedor centinela tampoco resuelve copias',
    verif_copias(VERIF_PROVEEDOR_TEST) === []);

// Esta llamada hace el require de config_mail.php, asi que de aqui en adelante se esta
// mirando la configuracion REAL de este entorno.
$copiasReales = verif_copias('ALIADO CUALQUIERA');

// MAIL_AUDITORIA_CC vive en conexion/config_mail.php, que NO se versiona: al copiar el
// modulo a otro servidor hay que escribirla a mano alla. Si se olvida, el aviso sale sin
// copia y nadie se entera nunca. Este assert convierte ese olvido mudo en un fallo
// ruidoso, que es la unica forma de que se note.
chequear('este entorno tiene configurada la copia a Coordinacion',
    in_array('coordinventarios@stanton.co', $copiasReales, true),
    implode(', ', $copiasReales) ?: 'lista vacia: falta MAIL_AUDITORIA_CC en conexion/config_mail.php');

$sinCC = verif_envio_destinos(['a@b.co'], []);
chequear('sin copias configuradas el envio sigue en pie',
    $sinCC['to'] === ['a@b.co'] && $sinCC['cc'] === []);

$conCC = verif_envio_destinos(['a@b.co'], ['c@d.co']);
chequear('la copia viaja aparte de los destinatarios',
    $conCC['to'] === ['a@b.co'] && $conCC['cc'] === ['c@d.co']);

// Ojo al orden: MAIL_TEST_TO se define ABAJO a proposito. Si config_mail.php lo trajera
// ya definido, este assert falla — y eso es lo que se quiere: seria un despliegue en
// modo prueba, que no manda los avisos a quien debe.
chequear('el entorno no esta en modo prueba',
    !defined('MAIL_TEST_TO') || MAIL_TEST_TO === '',
    defined('MAIL_TEST_TO')
        ? 'MAIL_TEST_TO=' . MAIL_TEST_TO . ': los avisos NO llegan a los destinatarios reales'
        : 'MAIL_TEST_TO sin definir');

// A partir de aqui, modo prueba. Es irreversible dentro de este proceso (las constantes
// no se pueden redefinir), asi que va al final.
define('MAIL_TEST_TO', 'ensayo@stanton.co');

$prueba = verif_envio_destinos(['a@b.co'], ['c@d.co']);
chequear('en modo prueba el correo va SOLO al buzon de ensayo',
    $prueba['to'] === ['ensayo@stanton.co']);
chequear('y en modo prueba NO se manda copia a nadie real',
    $prueba['cc'] === [],
    'ensayar no puede escribirle a Coordinacion de Inventarios');

echo "\n" . str_repeat('=', 70) . "\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
