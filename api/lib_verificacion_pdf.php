<?php
/**
 * Construcción del PDF de verificación sobre FPDF.
 * Separado de lib_verificacion.php porque es presentación pura: cambiar el diseño del
 * documento no debería obligar a releer la lógica de la auditoría.
 */
require_once __DIR__ . '/../fpdf/fpdf.php';

/** Alto de una línea de texto dentro de la tabla, en mm. */
const VERIF_PDF_LINEA = 4.2;
/** Aire entre el texto y el borde de la celda, arriba y abajo. */
const VERIF_PDF_PAD = 2.2;

/**
 * FPDF trabaja en ISO-8859-1 con las fuentes básicas. El texto del portal es UTF-8, así
 * que hay que convertirlo o las tildes salen como basura. Lo que no exista en el destino
 * se translitera en vez de desaparecer.
 */
function verif_pdf_txt(?string $s): string {
    $s = (string)$s;
    $conv = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
    return $conv === false ? $s : $conv;
}

/**
 * Color de fondo de la celda de resultado.
 * El color acompaña, nunca sustituye: la palabra siempre está escrita, porque este PDF se
 * imprime en blanco y negro para archivo físico.
 */
function verif_pdf_color(string $resultado): array {
    switch ($resultado) {
        case 'aprobado':    return [223, 245, 231];
        case 'no_aprobado': return [252, 226, 226];
        case 'no_aplica':   return [238, 238, 238];
        default:            return [255, 255, 255];
    }
}

/**
 * En cuántas líneas parte FPDF un texto para un ancho dado.
 * FPDF no lo expone, así que se mide con GetStringWidth palabra por palabra: es lo que
 * permite calcular el alto de la fila ANTES de dibujarla.
 *
 * Mide con la fuente que esté activa, así que hay que fijarla antes de llamar.
 */
function verif_pdf_partir(FPDF $pdf, string $texto, float $ancho): array {
    $lineas = [];
    foreach (explode("\n", $texto) as $parrafo) {
        $actual = '';
        foreach (explode(' ', $parrafo) as $palabra) {
            $prueba = $actual === '' ? $palabra : $actual . ' ' . $palabra;
            if ($pdf->GetStringWidth($prueba) > $ancho && $actual !== '') {
                $lineas[] = $actual;
                $actual = $palabra;
            } else {
                $actual = $prueba;
            }
        }
        $lineas[] = $actual;
    }
    return $lineas;
}

/** Pinta la fila de encabezado de la tabla. Se repite en cada página nueva. */
function verif_pdf_encabezado(FPDF $pdf, array $anchos): void {
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetFillColor(74, 71, 130);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($anchos['informe'],     8, verif_pdf_txt(' Informe'),     1, 0, 'L', true);
    $pdf->Cell($anchos['resultado'],   8, verif_pdf_txt('Resultado'),    1, 0, 'C', true);
    $pdf->Cell($anchos['observacion'], 8, verif_pdf_txt(' Observación'), 1, 1, 'L', true);
    $pdf->SetTextColor(40, 40, 40);
}

/**
 * Escribe el PDF de una auditoría.
 *
 * @param array  $paquete     Lo que devuelve verif_armar_paquete().
 * @param string $rutaSalida  Dónde escribirlo.
 * @return string La misma ruta.
 */
function verif_pdf(array $paquete, string $rutaSalida): string {
    $a = $paquete['auditoria'];

    $margenInferior = 18;
    $pdf = new FPDF('P', 'mm', 'LETTER');
    $pdf->SetAutoPageBreak(true, $margenInferior);
    $pdf->AddPage();

    // --- Encabezado ---
    $logo = __DIR__ . '/../img/logo_aka.png';
    if (is_file($logo)) $pdf->Image($logo, 15, 12, 28);

    $pdf->SetXY(50, 14);
    $pdf->SetFont('Helvetica', 'B', 15);
    $pdf->SetTextColor(74, 71, 130);            // var(--primary) del portal
    $pdf->Cell(0, 8, verif_pdf_txt('VERIFICACIÓN DE PLATAFORMA'), 0, 1);
    $pdf->SetX(50);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(0, 6, verif_pdf_txt('Portal de Aliados AKA 2.0 — Control de Auditoría Interna'), 0, 1);

    $pdf->Ln(10);
    $pdf->SetTextColor(40, 40, 40);

    // --- Ficha ---
    $ficha = [
        'Tercero auditado'   => $a['proveedor'],
        'Auditor'            => $a['auditor'],
        'Usuario del portal' => $a['usuario_portal'],
        'Fecha de cierre'    => (string)($a['cerrado_en'] ?? $a['creado_en']),
        'Registro N.'        => (string)$a['id'],
    ];
    foreach ($ficha as $etq => $val) {
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(42, 6, verif_pdf_txt($etq), 0, 0);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(0, 6, verif_pdf_txt($val), 0, 1);
    }

    $pdf->Ln(6);

    // --- Tabla ---
    $anchos = ['informe' => 55, 'resultado' => 30, 'observacion' => 101];
    $anchoTextoInf = $anchos['informe']     - 2 * VERIF_PDF_PAD;
    $anchoTextoObs = $anchos['observacion'] - 2 * VERIF_PDF_PAD;
    $limiteY = $pdf->GetPageHeight() - $margenInferior;

    verif_pdf_encabezado($pdf, $anchos);

    foreach ($paquete['filas'] as $f) {
        $nombre = verif_pdf_txt($f['nombre']);
        $obs    = verif_pdf_txt((string)($f['observacion'] ?? ''));

        // El alto de la fila lo manda la columna que más líneas ocupe: se mide con la
        // fuente de cada una ANTES de dibujar. Recortar aquí perdería el hallazgo, que es
        // justo el dato valioso.
        $pdf->SetFont('Helvetica', 'B', 9);
        $lineasInf = count(verif_pdf_partir($pdf, $nombre, $anchoTextoInf));
        $pdf->SetFont('Helvetica', '', 8);
        $lineasObs = $obs === '' ? 1 : count(verif_pdf_partir($pdf, $obs, $anchoTextoObs));

        $alto = max(9, max($lineasInf, $lineasObs) * VERIF_PDF_LINEA + 2 * VERIF_PDF_PAD);

        // Salto de página manual: si la fila no cabe entera, se abre página y se repite
        // el encabezado, para que ninguna fila quede partida entre dos hojas.
        if ($pdf->GetY() + $alto > $limiteY) {
            $pdf->AddPage();
            verif_pdf_encabezado($pdf, $anchos);
        }

        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $xRes = $x + $anchos['informe'];
        $xObs = $xRes + $anchos['resultado'];

        // Los bordes se dibujan como rectángulos de alto $alto y el texto se escribe
        // encima con MultiCell SIN borde. Es deliberado: el segundo parámetro de MultiCell
        // es el alto de CADA LÍNEA, no el de la celda, así que pasarle $alto haría que una
        // observación de tres líneas midiera 3 x $alto y se saliera de su columna.
        $rgb = verif_pdf_color($f['resultado']);
        $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->Rect($xRes, $y, $anchos['resultado'], $alto, 'F');

        $pdf->Rect($x,    $y, $anchos['informe'],     $alto);
        $pdf->Rect($xRes, $y, $anchos['resultado'],   $alto);
        $pdf->Rect($xObs, $y, $anchos['observacion'], $alto);

        $pdf->SetXY($x + VERIF_PDF_PAD, $y + VERIF_PDF_PAD);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->MultiCell($anchoTextoInf, VERIF_PDF_LINEA, $nombre, 0, 'L');

        // La etiqueta va centrada verticalmente: es una sola línea corta y así no queda
        // pegada al borde de arriba en una fila alta.
        $pdf->SetXY($xRes, $y + ($alto - VERIF_PDF_LINEA) / 2);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->MultiCell($anchos['resultado'], VERIF_PDF_LINEA, verif_pdf_txt($f['etiqueta']), 0, 'C');

        $pdf->SetXY($xObs + VERIF_PDF_PAD, $y + VERIF_PDF_PAD);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->MultiCell($anchoTextoObs, VERIF_PDF_LINEA, $obs, 0, 'L');

        $pdf->SetXY($x, $y + $alto);
    }

    // --- Comentarios generales ---
    if (!empty($a['comentarios'])) {
        $pdf->Ln(8);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(0, 6, verif_pdf_txt('Comentarios generales'), 0, 1);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->MultiCell(0, 4.6, verif_pdf_txt($a['comentarios']), 1, 'L');
    }

    // --- Pie ---
    $pdf->Ln(8);
    $pdf->SetFont('Helvetica', 'I', 7);
    $pdf->SetTextColor(130, 130, 130);
    $pdf->Cell(0, 5, verif_pdf_txt('Generado automáticamente por la Plataforma AKA 2.0 el '
        . date('d/m/Y H:i') . '. Registro N. ' . $a['id'] . '.'), 0, 1, 'C');

    $pdf->Output('F', $rutaSalida);
    return $rutaSalida;
}
