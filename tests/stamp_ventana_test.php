<?php
// Regresión de la VENTANA DEL STAMP (evol + o45): el stamp de frescura debe mirar la MISMA ventana
// de fechas que lee el payload, para no invalidar el cache por filas que nadie lee.
//
// Bug (2026-07-16): evolCurrentStamp/o45CurrentStamp hacían MAX(FECHA) sobre la tabla ENTERA, pero
// los dos informes topan su ventana en AYER (informe_evol.php:37 y $hasta de informe_o45.php).
// mov_inv_actual_PBI recibe movimientos del día en curso (950 filas el 2026-07-16) → el stamp
// saltaba a hoy a media mañana, invalidaba TODO el cache en disco de evol y forzaba un rebuild de
// ~40s cuyo resultado era idéntico (esas filas caen fuera del BETWEEN). De paso anulaba el prebuild
// nocturno: calentaba con el stamp de ayer y a media mañana quedaba obsoleto. o45 tenía el mismo
// stamp global (latente: hoy sus fuentes solo cargan de noche).
//
//   php tests/stamp_ventana_test.php
//
// Oráculo: el stamp DEBE ser igual al MAX(FECHA <= ayer) de sus fuentes (lo que el payload lee), y
// NO al MAX(FECHA) global. El test solo discrimina si hoy existen filas fuera de ventana; si no las
// hay, ambos coinciden y se dice explícitamente (verde no concluyente, no verde falso).

require __DIR__ . '/../conexion/conexion_integracion.php';
require_once __DIR__ . '/../api/lib_evol_disk.php';
require_once __DIR__ . '/../api/lib_o45_disk.php';
if ($dbConnect === false) { echo "FALLO: conexión DB\n"; exit(1); }

$ayer = date('Y-m-d', strtotime('-1 day'));

/** MAX(FECHA) de una tabla, con y sin el tope de la ventana, en el MISMO formato del stamp. */
function maxFecha($conn, string $tabla, ?string $tope): string {
    $w = $tope === null ? '' : ' WHERE FECHA <= ?';
    $st = sqlsrv_query($conn, "SELECT ISNULL(CONVERT(varchar(19),MAX(FECHA),120),'') v
                               FROM INTEGRACION.dbo.$tabla WITH (NOLOCK)$w", $tope === null ? [] : [$tope]);
    if ($st === false) { echo "FALLO: no se pudo consultar $tabla\n"; exit(1); }
    $r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($st);
    return (string)$r['v'];
}

$casos = [
    'evol' => ['fuentes' => ['inv_actual_PBI', 'Ventas_Detal_PBI', 'mov_inv_actual_PBI'],
               'actual'  => evolCurrentStamp($dbConnect)],
    'o45'  => ['fuentes' => ['inv_actual_PBI', 'Ventas_Detal_PBI'],
               'actual'  => o45CurrentStamp($dbConnect)],
];

echo "VENTANA DEL STAMP — el payload de evol/o45 topa en ayer ($ayer)\n\n";
$fallos = []; $discrimina = false;

foreach ($casos as $nom => $c) {
    $enVentana = []; $global = [];
    foreach ($c['fuentes'] as $t) { $enVentana[] = maxFecha($dbConnect, $t, $ayer); $global[] = maxFecha($dbConnect, $t, null); }
    $espEnVentana = implode('|', $enVentana);
    $espGlobal    = implode('|', $global);
    $fuera        = $espEnVentana !== $espGlobal;   // ¿hay filas fuera de ventana AHORA?
    $discrimina   = $discrimina || $fuera;

    echo "[$nom]\n";
    echo "  stamp actual        = " . var_export($c['actual'], true) . "\n";
    echo "  esperado (<= ayer)  = '$espEnVentana'\n";
    echo "  MAX global          = '$espGlobal'" . ($fuera ? "   <-- hay filas FUERA de la ventana del payload\n" : "   (hoy no hay filas fuera de ventana)\n");

    if ($c['actual'] === null)              $fallos[] = "$nom: el stamp es null";
    elseif ($c['actual'] !== $espEnVentana) $fallos[] = "$nom: el stamp mira " . ($c['actual'] === $espGlobal
            ? "el MAX GLOBAL: invalida el cache por filas que el payload no lee"
            : "algo distinto de MAX(FECHA <= ayer)") . " (got='{$c['actual']}' exp='$espEnVentana')";
    echo "\n";
}
sqlsrv_close($dbConnect);

if ($fallos) { foreach ($fallos as $f) echo "FALLO: $f\n"; exit(1); }
echo $discrimina
    ? "OK: los stamps ignoran las filas fuera de ventana (hoy las hay, así que el test discrimina).\n"
    : "OK — pero NO CONCLUYENTE: hoy no hay filas fuera de ventana en ninguna fuente, así que el MAX\n"
    . "global y el acotado coinciden y este test no puede distinguirlos. Volver a correrlo un día con\n"
    . "movimientos del día en curso (mov_inv_actual_PBI suele traerlos a media mañana).\n";
exit(0);
