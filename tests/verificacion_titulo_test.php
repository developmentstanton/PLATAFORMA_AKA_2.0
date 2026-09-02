<?php
// El nombre del aliado auditado en el titulo del PDF.
//
//   php tests/verificacion_titulo_test.php
//
// Esta suite NO toca la base de datos: verif_pdf() recibe un array, asi que el paquete se
// arma a mano. Eso importa por dos razones:
//
//   1. No gasta numeros de registro IDENTITY (ver verificacion_persistencia_test.php).
//   2. Permite probar nombres de aliado que la tabla nunca tendria. El caso que rompe un
//      titulo es el nombre LARGO, y la guardia de escritura obliga al centinela '__TEST__',
//      que tiene ocho caracteres. Con la base de por medio, el caso interesante seria
//      justo el que no se puede montar.
//
// VERIF_PDF_CONSERVAR=1 deja los PDF sin borrar, en cache/, para MIRARLOS. Los asserts de
// aqui miden bytes y anchos; que el titulo se vea bien no lo prueba ninguna maquina.

require_once __DIR__ . '/../api/lib_verificacion.php';
require_once __DIR__ . '/../api/lib_verificacion_pdf.php';

$conservar = getenv('VERIF_PDF_CONSERVAR') === '1';
$salida = $conservar ? __DIR__ . '/../cache' : sys_get_temp_dir();

$temporales = [];
register_shutdown_function(function () use (&$temporales, $conservar) {
    if ($conservar) return;
    foreach ($temporales as $f) if (is_file($f)) @unlink($f);
});

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

/** Un paquete valido con el proveedor que se le pida. No pasa por la base. */
function paquete_de(string $proveedor): array {
    $filas = [];
    foreach (VERIF_INFORMES as $clave => $nombre) {
        $filas[] = ['clave' => $clave, 'nombre' => $nombre, 'resultado' => 'aprobado',
                    'etiqueta' => verif_etiqueta('aprobado'), 'observacion' => null];
    }
    return [
        'auditoria' => ['id' => 0, 'proveedor' => $proveedor, 'auditor' => 'Auditor de Prueba',
                        'usuario_portal' => 'usr_test', 'creado_en' => '2026-09-02 10:00:00',
                        'cerrado_en' => '2026-09-02 10:30:00', 'comentarios' => null],
        'asunto' => 'x', 'cuerpo_html' => 'x', 'nombre_pdf' => 'x.pdf', 'filas' => $filas,
    ];
}

echo "TITULO DEL PDF: NOMBRE DEL ALIADO\n" . str_repeat('=', 70) . "\n";

// --- El ajuste de tamano, como funcion pura ---
//
// El nombre del aliado va en su propia linea justamente para que no compita por el ancho
// con 'VERIFICACION DE PLATAFORMA'. Aun asi se mide: 'FASHION FITNESS COLOMBIA S.A.S.'
// son 31 caracteres y no es el techo, es solo el mas largo que se vio en el repo.

$pdf = new FPDF('P', 'mm', 'LETTER');
$pdf->AddPage();
$ancho = verif_pdf_ancho_titulo($pdf);

chequear('hay ancho util para el titulo', $ancho > 100, round($ancho, 1) . ' mm');

$tamCorto = verif_pdf_tam_titulo($pdf, verif_pdf_txt('BH BRANDS SAS'), $ancho);
chequear('un nombre corto conserva el tamano maximo',
    $tamCorto === VERIF_PDF_TITULO_MAX, $tamCorto . ' pt');

$largo = verif_pdf_txt('COMERCIALIZADORA INTERNACIONAL DE CALZADO Y ACCESORIOS DEPORTIVOS S.A.S.');
$tamLargo = verif_pdf_tam_titulo($pdf, $largo, $ancho);
chequear('un nombre muy largo encoge la letra',
    $tamLargo < VERIF_PDF_TITULO_MAX, $tamLargo . ' pt');

// Encoger sin llegar a caber no sirve de nada: lo que se comprueba es el RESULTADO.
$pdf->SetFont('Helvetica', 'B', $tamLargo);
chequear('y al encoger, CABE de verdad',
    $pdf->GetStringWidth($largo) <= $ancho,
    round($pdf->GetStringWidth($largo), 1) . ' mm de ' . round($ancho, 1) . ' mm');

// El piso existe para que un nombre absurdo no salga en letra ilegible: preferimos que
// se desborde de forma visible a imprimir un titulo que nadie puede leer.
$absurdo = verif_pdf_txt(str_repeat('NOMBRE INTERMINABLE DE ALIADO ', 10));
chequear('nunca baja del tamano minimo legible',
    verif_pdf_tam_titulo($pdf, $absurdo, $ancho) === VERIF_PDF_TITULO_MIN);

chequear('un proveedor vacio no rompe la medida',
    verif_pdf_tam_titulo($pdf, '', $ancho) === VERIF_PDF_TITULO_MAX);

// --- El nombre llega al documento ---
echo "\nRENDER\n" . str_repeat('=', 70) . "\n";

$f1 = $salida . '/verif_titulo_corto.pdf';
$f2 = $salida . '/verif_titulo_largo.pdf';
$temporales[] = $f1;
$temporales[] = $f2;

verif_pdf(paquete_de('BH BRANDS SAS'), $f1);
verif_pdf(paquete_de('COMERCIALIZADORA INTERNACIONAL DE CALZADO Y ACCESORIOS DEPORTIVOS S.A.S.'), $f2);

chequear('el PDF de nombre corto es valido',
    strncmp((string)file_get_contents($f1, false, null, 0, 5), '%PDF-', 5) === 0);
chequear('el PDF de nombre largo es valido',
    strncmp((string)file_get_contents($f2, false, null, 0, 5), '%PDF-', 5) === 0);

// Si el titulo no dependiera del proveedor, dos aliados distintos darian el mismo peso.
// Es un assert debil a proposito: solo dice que el nombre INFLUYE, no que se vea bien.
chequear('el proveedor cambia el documento',
    filesize($f1) !== filesize($f2),
    filesize($f1) . ' vs ' . filesize($f2) . ' bytes');

// Con un nombre de una sola linea, el aliado no debe empujar la tabla a una segunda
// pagina: el PDF de una auditoria sin observaciones cabe en una hoja.
chequear('una auditoria simple sigue cabiendo en una pagina',
    substr_count((string)file_get_contents($f2), '/Type /Page') <= 2,
    'cuenta objetos Page del PDF');

echo "\n" . str_repeat('=', 70) . "\n";
echo "MIRALOS antes de dar esto por bueno (el aliado va bajo el titulo):\n";
echo "  $f1\n  $f2\n";
echo $conservar ? "(CONSERVADOS)\n" : "(se borran; corre con VERIF_PDF_CONSERVAR=1 para conservarlos)\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
