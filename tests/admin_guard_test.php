<?php
// Contrato del guard de sesión admin. SIN base de datos: se simula $_SESSION.
//
// La decisión (admin_estado_sesion) está separada de la acción (admin_exigir_sesion, que
// redirige y termina) justamente para poder probarla. El caso 4 es el que protege al portal:
// cuando la sesión admin expira NO se puede llamar a session_destroy(), porque el mismo
// navegador puede tener abierta una sesión de aliado y la tumbaría.
//
//   php tests/admin_guard_test.php

require_once __DIR__ . '/../admin/lib_admin_auth.php';

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "CONTRATO DEL GUARD DE SESION ADMIN\n" . str_repeat('=', 70) . "\n";

$AHORA = 1000000;

// 1) Sesion sin namespace admin
chequear('sesion vacia -> ausente',
    admin_estado_sesion(array(), $AHORA) === 'ausente');

// 2) Sesion admin fresca
$fresca = array('admin' => array('usuario' => 'Administrador', 'ultima_actividad' => $AHORA - 60));
chequear('sesion de hace 1 min -> ok',
    admin_estado_sesion($fresca, $AHORA) === 'ok');

// 3) Sesion admin de 31 minutos (limite: ADMIN_TIMEOUT_SEG = 1800)
$vieja = array('admin' => array('usuario' => 'Administrador', 'ultima_actividad' => $AHORA - 1860));
chequear('sesion de 31 min -> expirada',
    admin_estado_sesion($vieja, $AHORA) === 'expirada');

// 3b) Justo en el limite todavia vale
$limite = array('admin' => array('usuario' => 'Administrador', 'ultima_actividad' => $AHORA - 1800));
chequear('sesion de exactamente 30 min -> ok',
    admin_estado_sesion($limite, $AHORA) === 'ok');

// 3c) Namespace admin presente pero sin marca de tiempo -> se trata como expirada
$sinMarca = array('admin' => array('usuario' => 'Administrador'));
chequear('sesion admin sin ultima_actividad -> expirada',
    admin_estado_sesion($sinMarca, $AHORA) === 'expirada');

// 4) EL CASO QUE PROTEGE AL PORTAL: cerrar la sesion admin no toca la del aliado
$_SESSION = array(
    'admin'   => array('usuario' => 'Administrador', 'ultima_actividad' => $AHORA),
    'usuario' => 'Planeta Sport 6',
    'proveedor' => 'PLANETA SPORT',
);
admin_cerrar_sesion_admin();
chequear('cerrar admin borra $_SESSION[admin]', !isset($_SESSION['admin']));
chequear('cerrar admin NO borra $_SESSION[usuario] del portal',
    isset($_SESSION['usuario']) && $_SESSION['usuario'] === 'Planeta Sport 6',
    isset($_SESSION['usuario']) ? 'intacta' : 'SE PERDIO LA SESION DEL PORTAL');
chequear('cerrar admin NO borra $_SESSION[proveedor] del portal',
    isset($_SESSION['proveedor']));

echo str_repeat('=', 70) . "\n";
if ($fallos) { echo "FALLARON " . count($fallos) . ": " . implode('; ', $fallos) . "\n"; exit(1); }
echo "TODO OK\n";
exit(0);
