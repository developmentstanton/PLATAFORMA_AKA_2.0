<?php
// Contrato de verif_validar_punto() y verif_faltantes().
//
// Corre en el servidor a proposito: los radios del navegador son una comodidad, no una
// barrera. Cualquiera puede mandar un POST a mano con el informe y el resultado que quiera.
//
//   php tests/verificacion_validar_test.php
//
// Puro: no toca base de datos ni red.

require_once __DIR__ . '/../api/lib_verificacion.php';

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "CONTRATO DE verif_validar_punto()\n" . str_repeat('=', 70) . "\n";

chequear('informe y resultado validos pasan',
    verif_validar_punto('g00', 'aprobado', null) === '');

chequear('observacion opcional en aprobado',
    verif_validar_punto('o14', 'aprobado', 'Cuadra con el ERP.') === '');

chequear('informe desconocido se rechaza',
    verif_validar_punto('pagos', 'aprobado', null) !== '');

chequear('resultado fuera del enum se rechaza',
    verif_validar_punto('g00', 'APROBADO', null) !== '',
    'el enum es sensible a mayusculas; la base tiene el mismo CHECK');

chequear('resultado inventado se rechaza',
    verif_validar_punto('g00', 'pendiente', null) !== '');

chequear('no_aprobado exige observacion',
    verif_validar_punto('g00', 'no_aprobado', '') !== '',
    'un hallazgo sin explicacion no sirve de nada a Auditoria');

chequear('no_aprobado con observacion pasa',
    verif_validar_punto('g00', 'no_aprobado', 'Marzo: ERP 45.489.517 vs portal 45.489.000') === '');

chequear('observacion en el limite pasa',
    verif_validar_punto('g00', 'aprobado', str_repeat('a', VERIF_OBS_MAX)) === '');

chequear('observacion pasada del limite se rechaza',
    verif_validar_punto('g00', 'aprobado', str_repeat('a', VERIF_OBS_MAX + 1)) !== '',
    'la columna es NVARCHAR(1000); truncar en silencio perderia el hallazgo');

echo "\nCONTRATO DE verif_faltantes()\n" . str_repeat('=', 70) . "\n";

chequear('sin nada marcado faltan los cinco',
    verif_faltantes([]) === array_keys(VERIF_INFORMES));

chequear('con cuatro marcados falta el quinto',
    verif_faltantes(['g00', 'o14', 'o45', 'evol']) === ['geo']);

chequear('con los cinco marcados no falta ninguno',
    verif_faltantes(['g00', 'o14', 'o45', 'evol', 'geo']) === []);

chequear('el orden de lo marcado no altera el resultado',
    verif_faltantes(['geo', 'g00']) === ['o14', 'o45', 'evol'],
    'la vista y el PDF listan siempre en el orden de VERIF_INFORMES');

echo "\n" . str_repeat('=', 70) . "\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
