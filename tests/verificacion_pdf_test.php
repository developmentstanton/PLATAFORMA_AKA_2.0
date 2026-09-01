<?php
// Contrato del PDF de verificacion.
//
// Lo que mas importa aqui es que una observacion larga NO se recorte: un hallazgo de
// auditoria truncado es peor que una tabla fea. Por eso el caso 4 mete un texto largo
// y comprueba que el PDF crece de alto en vez de quedarse igual.
//
//   php tests/verificacion_pdf_test.php
//
// Escribe un PDF temporal y lo borra. No toca la red.
//
// VERIF_PDF_CONSERVAR=1 en el entorno deja los archivos sin borrar, para MIRARLOS.

require_once __DIR__ . '/../conexion/conexion_integracion.php';
require_once __DIR__ . '/../api/lib_verificacion.php';
require_once __DIR__ . '/_verificacion_guardia_escritura.php';
require_once __DIR__ . '/../api/lib_verificacion_pdf.php';

if ($dbConnect === false) {
    fwrite(STDERR, "No hay conexion a INTEGRACION; el test no puede correr.\n");
    exit(1);
}

$conservar = getenv('VERIF_PDF_CONSERVAR') === '1';
$salida = $conservar ? __DIR__ . '/../cache' : sys_get_temp_dir();

$creadas = [];
$temporales = [];
register_shutdown_function(function () use (&$creadas, &$temporales, $dbConnect, $conservar) {
    foreach ($creadas as $id) {
        sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria_detalle WHERE auditoria_id = ?", [$id]);
        sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria WHERE id = ?", [$id]);
    }
    if (!$conservar) {
        foreach ($temporales as $f) if (is_file($f)) @unlink($f);
    }
});

// Esta suite escribe en la tabla viva y gasta numeros de registro IDENTITY.
verif_guardia_escritura($dbConnect);

// Barrido previo: una corrida abortada deja una auditoria '__TEST__' en curso que
// verif_abrir() recuperaria en vez de crear una nueva.
sqlsrv_query($dbConnect, "DELETE d FROM verificacion_auditoria_detalle d
    JOIN verificacion_auditoria a ON a.id = d.auditoria_id WHERE a.proveedor = ?", [VERIF_PROVEEDOR_TEST]);
sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria WHERE proveedor = ?", [VERIF_PROVEEDOR_TEST]);

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

function armar(array $puntos, string $comentarios, $conn, array &$creadas): array {
    $a = verif_abrir($conn, VERIF_PROVEEDOR_TEST, 'usr_test', 'Auditor de Prueba');
    $creadas[] = $a['id'];
    foreach ($puntos as $clave => $v) {
        verif_guardar_punto($conn, $a['id'], $clave, $v[0], $v[1]);
    }
    verif_cerrar($conn, $a['id'], $comentarios);
    return verif_armar_paquete($conn, $a['id']);
}

echo "PDF DE VERIFICACION\n" . str_repeat('=', 70) . "\n";

// 1) Caso corto
$corto = armar([
    'g00'  => ['aprobado', null],
    'o14'  => ['aprobado', null],
    'o45'  => ['no_aplica', 'No lo maneja.'],
    'evol' => ['no_aprobado', 'Marzo no cuadra.'],
    'geo'  => ['aprobado', null],
], 'Sin novedades.', $dbConnect, $creadas);

$f1 = $salida . '/verif_corto.pdf';
$temporales[] = $f1;
verif_pdf($corto, $f1);

chequear('el archivo se crea', is_file($f1));
chequear('es un PDF de verdad', strncmp((string)file_get_contents($f1, false, null, 0, 5), '%PDF-', 5) === 0);
chequear('no esta vacio', filesize($f1) > 1000, filesize($f1) . ' bytes');

// 2) Caso con observaciones largas: el PDF debe CRECER, no recortar
$largo = armar([
    'g00'  => ['no_aprobado', str_repeat('Diferencia detectada contra el ERP. ', 25)],
    'o14'  => ['no_aprobado', str_repeat('Stock por tienda no coincide. ', 25)],
    'o45'  => ['no_aprobado', str_repeat('Indice fuera de rango. ', 25)],
    'evol' => ['no_aprobado', str_repeat('Serie mensual con huecos. ', 25)],
    'geo'  => ['no_aprobado', str_repeat('Tiendas sin coordenadas. ', 25)],
], str_repeat('Comentario general extenso. ', 30), $dbConnect, $creadas);

$f2 = $salida . '/verif_largo.pdf';
$temporales[] = $f2;
verif_pdf($largo, $f2);

chequear('el PDF con texto largo tambien es valido',
    strncmp((string)file_get_contents($f2, false, null, 0, 5), '%PDF-', 5) === 0);
chequear('el PDF largo pesa mas que el corto',
    filesize($f2) > filesize($f1),
    filesize($f1) . ' -> ' . filesize($f2) . ' bytes; si fueran iguales el texto se estaria recortando');

// 3) Las tildes no rompen el PDF (FPDF trabaja en ISO-8859-1; hay que convertir)
$tildes = armar([
    'g00'  => ['aprobado', 'Año 2026: señal correcta. Índice OK.'],
    'o14'  => ['aprobado', null],
    'o45'  => ['aprobado', null],
    'evol' => ['aprobado', null],
    'geo'  => ['aprobado', null],
], 'Revisión con tildes: ñ, á, é, í, ó, ú, ü.', $dbConnect, $creadas);

$f3 = $salida . '/verif_tildes.pdf';
$temporales[] = $f3;
verif_pdf($tildes, $f3);
chequear('las tildes no rompen la generacion',
    is_file($f3) && strncmp((string)file_get_contents($f3, false, null, 0, 5), '%PDF-', 5) === 0);

echo "\n" . str_repeat('=', 70) . "\n";
echo "Para MIRARLOS antes de dar esto por bueno:\n";
echo "  $f1\n  $f2\n  $f3\n";
if (!$conservar) {
    echo "(se borran al terminar; corre con VERIF_PDF_CONSERVAR=1 para conservarlos)\n";
} else {
    echo "(CONSERVADOS: VERIF_PDF_CONSERVAR=1)\n";
}
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
