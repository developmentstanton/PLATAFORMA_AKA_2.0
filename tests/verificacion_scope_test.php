<?php
// Ningun endpoint de verificacion puede usar un nombre de variable que
// conexion/conexion_integracion.php defina.
//
// Ese archivo no es una funcion: define $servidor, $basedatos, $usuario, $password,
// $infoconn y $dbConnect por FILTRADO DE SCOPE al incluirse. Como el require va despues de
// leer la sesion, cualquier variable propia que se llame igual queda pisada en silencio.
//
// Ya paso una vez: verificacion_abrir.php leia $usuario de la sesion y el require lo
// reemplazaba por 'admistanton', el login de SQL Server, de modo que usuario_portal
// guardaba el usuario de la BASE en todas las auditorias -- lo contrario de lo que esa
// columna existe para registrar. Ningun test de persistencia podia verlo: le pasan el
// usuario directo a verif_abrir(). Solo se vio MIRANDO la fila guardada.
//
// La lista de nombres se deduce del propio archivo de conexion, para que siga siendo
// cierta si alguien le agrega una variable.
//
//   php tests/verificacion_scope_test.php
//
// Puro: lee archivos, no toca base ni red.

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

/** Nombres de variable que un archivo asigna en su scope de nivel superior. */
function nombres_que_define(string $ruta): array {
    $src = (string)file_get_contents($ruta);
    preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=[^=]/', $src, $m);
    return array_values(array_unique($m[1]));
}

/** Nombres de variable que un archivo asigna, para cruzarlos con los de arriba. */
function nombres_que_asigna(string $ruta): array {
    return nombres_que_define($ruta);
}

$conexion = __DIR__ . '/../conexion/conexion_integracion.php';
if (!is_file($conexion)) {
    fwrite(STDERR, "No existe conexion/conexion_integracion.php (esta gitignored).\n");
    exit(1);
}

// $dbConnect es la salida deseada del include: es el unico que SI se espera recibir.
$prohibidos = array_diff(nombres_que_define($conexion), ['dbConnect']);

echo "COLISION DE SCOPE CON conexion_integracion.php\n" . str_repeat('=', 70) . "\n";
echo "  Nombres que filtra: \$" . implode(', $', $prohibidos) . "\n\n";

chequear('el archivo de conexion filtra al menos $usuario y $password',
    in_array('usuario', $prohibidos, true) && in_array('password', $prohibidos, true),
    'si esto falla, el propio test dejo de medir lo que cree medir');

$endpoints = glob(__DIR__ . '/../api/verificacion_*.php');
chequear('se encontraron los endpoints', count($endpoints) >= 3, count($endpoints) . ' archivos');

foreach ($endpoints as $ep) {
    $nombre = basename($ep);
    // lib_verificacion.php recibe la conexion por parametro y nunca incluye el archivo,
    // asi que no puede sufrir la colision.
    if (strpos(file_get_contents($ep), 'conexion_integracion.php') === false) continue;

    $choques = array_intersect(nombres_que_asigna($ep), $prohibidos);
    chequear("$nombre no reusa ningun nombre filtrado",
        $choques === [],
        $choques ? 'choca en: $' . implode(', $', $choques) : '');
}

echo "\n" . str_repeat('=', 70) . "\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
