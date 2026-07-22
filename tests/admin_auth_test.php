<?php
// Contrato de admin_autenticar(): credenciales correctas Y rol de administrador.
//
// El caso que importa es el 3: un aliado con su contraseña BUENA debe ser rechazado igual
// que una contraseña mala. El rol va dentro del WHERE de la consulta justamente para que no
// exista un camino donde la credencial se valide y el rol se revise después: eso filtraría,
// por diferencia de tiempo o de mensaje, que la contraseña era correcta.
//
//   php tests/admin_auth_test.php
//
// Solo lectura. Las contraseñas NO se queman aquí: se leen de la misma base, para que este
// archivo pueda versionarse sin exponer secretos.

require_once __DIR__ . '/../conexion/conexion_integracion.php';
require_once __DIR__ . '/../admin/lib_admin_auth.php';

if ($dbConnect === false) {
    fwrite(STDERR, "No hay conexion a INTEGRACION; el test no puede correr.\n");
    exit(1);
}

// --- Fixtures leidos de la base ---
$sqlAdmin = "SELECT TOP 1 RTRIM(nombre_usuario) AS u, RTRIM(contrasena_usuario) AS p
             FROM usuarios_portal_aka WHERE RTRIM(LTRIM(link1)) = 'Administrador'";
$st = sqlsrv_query($dbConnect, $sqlAdmin);
$admin = $st ? sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC) : null;

$sqlAliado = "SELECT TOP 1 RTRIM(nombre_usuario) AS u, RTRIM(contrasena_usuario) AS p
              FROM usuarios_portal_aka
              WHERE RTRIM(LTRIM(link1)) <> 'Administrador' AND link1 IS NOT NULL";
$st2 = sqlsrv_query($dbConnect, $sqlAliado);
$aliado = $st2 ? sqlsrv_fetch_array($st2, SQLSRV_FETCH_ASSOC) : null;

if (!$admin || !$aliado) {
    fwrite(STDERR, "Faltan fixtures en usuarios_portal_aka (se necesita 1 admin y 1 no-admin).\n");
    exit(1);
}

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "CONTRATO DE admin_autenticar()\n" . str_repeat('=', 70) . "\n";

// 1) Admin con contraseña correcta -> entra
$r = admin_autenticar($dbConnect, $admin['u'], $admin['p']);
chequear('admin + clave correcta devuelve el usuario',
    is_array($r) && $r['nombre_usuario'] === $admin['u'],
    is_array($r) ? 'ok' : 'devolvio null');

// 2) Admin con contraseña incorrecta -> null
$r = admin_autenticar($dbConnect, $admin['u'], $admin['p'] . 'xyz');
chequear('admin + clave incorrecta devuelve null', $r === null);

// 3) EL CASO CENTRAL: aliado real con SU clave correcta -> null (rol insuficiente)
$r = admin_autenticar($dbConnect, $aliado['u'], $aliado['p']);
chequear('aliado + clave CORRECTA devuelve null (no es admin)', $r === null,
    $r === null ? 'ok' : 'DEJO ENTRAR A UN NO-ADMIN');

// 4) Usuario inexistente -> null
$r = admin_autenticar($dbConnect, 'usuario_que_no_existe_9f3a', 'lo_que_sea');
chequear('usuario inexistente devuelve null', $r === null);

// 5) La clave sigue siendo sensible a mayusculas (COLLATE ..._BIN)
$alterada = (strtoupper($admin['p']) !== $admin['p']) ? strtoupper($admin['p']) : strtolower($admin['p']);
if ($alterada === $admin['p']) {
    echo "  SALTA  clave sensible a mayusculas -> la clave del admin no tiene letras\n";
} else {
    $r = admin_autenticar($dbConnect, $admin['u'], $alterada);
    chequear('clave con distinta capitalizacion devuelve null', $r === null);
}

echo str_repeat('=', 70) . "\n";
if ($fallos) { echo "FALLARON " . count($fallos) . ": " . implode('; ', $fallos) . "\n"; exit(1); }
echo "TODO OK\n";
exit(0);
