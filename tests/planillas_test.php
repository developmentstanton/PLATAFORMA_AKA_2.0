<?php
// Contrato de planillas_validar(). SIN base de datos.
//
// Esta es la barrera real: el select del navegador se puede saltar (basta un POST a mano),
// así que la regla "si rechazas, el motivo es obligatorio y tiene que ser uno de la lista"
// vive aquí, en el servidor. Los casos 2, 3 y 5 son los que protegen ese requisito.
//
// El caso 6 documenta una decisión deliberada: aprobar con motivo NO es un error, pero el
// motivo se descarta. Así una planilla aprobada nunca arrastra el motivo de un rechazo previo.
//
//   php tests/planillas_test.php

require_once __DIR__ . '/../admin/lib_planillas.php';

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "CONTRATO DE planillas_validar()\n" . str_repeat('=', 70) . "\n";

$motivoValido = PLANILLA_MOTIVOS[0];

// 1) Aprobado sin motivo: el camino normal
chequear('Aprobado sin motivo es valido',
    planillas_validar('Aprobado', null) === '');

// 2) Rechazado sin motivo: NO se permite
$e = planillas_validar('Rechazado', null);
chequear('Rechazado sin motivo es invalido', $e !== '', $e === '' ? 'LO DEJO PASAR' : $e);

// 2b) Rechazado con motivo en blanco tampoco
chequear('Rechazado con motivo vacio es invalido',
    planillas_validar('Rechazado', '   ') !== '');

// 3) Rechazado con un motivo inventado: NO se permite
$e = planillas_validar('Rechazado', 'porque si');
chequear('Rechazado con motivo fuera de la lista es invalido', $e !== '',
    $e === '' ? 'ACEPTO TEXTO ARBITRARIO' : $e);

// 4) Rechazado con motivo de la lista: valido
chequear('Rechazado con motivo de la lista es valido',
    planillas_validar('Rechazado', $motivoValido) === '',
    planillas_validar('Rechazado', $motivoValido));

// 5) Estado inventado: NO se permite
chequear('Estado inventado es invalido',
    planillas_validar('Borrado', null) !== '');

// 5b) Estudio es un estado valido (se puede devolver una planilla a pendiente)
chequear('Estudio es valido', planillas_validar('Estudio', null) === '');

// 6) Aprobado CON motivo: valido (el motivo se descarta al guardar, ver Task 3)
chequear('Aprobado con motivo es valido',
    planillas_validar('Aprobado', $motivoValido) === '');

// 7) La lista de motivos no puede estar vacia ni tener duplicados
chequear('PLANILLA_MOTIVOS no esta vacia', count(PLANILLA_MOTIVOS) > 0);
chequear('PLANILLA_MOTIVOS no tiene duplicados',
    count(PLANILLA_MOTIVOS) === count(array_unique(PLANILLA_MOTIVOS)));

// 8) Ningun motivo puede pasarse de VARCHAR(200)
$largos = array_filter(PLANILLA_MOTIVOS, fn($m) => strlen($m) > 200);
chequear('Ningun motivo excede 200 caracteres', count($largos) === 0,
    count($largos) ? 'se pasan: ' . implode('; ', $largos) : '');

echo str_repeat('=', 70) . "\n";
if ($fallos) { echo "FALLARON " . count($fallos) . ": " . implode('; ', $fallos) . "\n"; exit(1); }
echo "TODO OK\n";
exit(0);
