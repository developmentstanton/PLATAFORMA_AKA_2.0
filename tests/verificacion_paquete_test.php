<?php
// Contrato del armado del paquete y de los destinatarios.
//
// Este test existe sobre todo por una razon: demostrar que armar el paquete NO envia nada.
// El correo es irreversible y los tests corren antes que cualquier validacion, asi que la
// unica defensa real es que el dato de prueba sea incapaz de producir un envio: el
// proveedor '__TEST__' no resuelve a ningun destinatario.
//
//   php tests/verificacion_paquete_test.php

require_once __DIR__ . '/../conexion/conexion_integracion.php';
require_once __DIR__ . '/../api/lib_verificacion.php';
require_once __DIR__ . '/_verificacion_guardia_escritura.php';

if ($dbConnect === false) {
    fwrite(STDERR, "No hay conexion a INTEGRACION; el test no puede correr.\n");
    exit(1);
}

$creadas = [];
register_shutdown_function(function () use (&$creadas, $dbConnect) {
    foreach ($creadas as $id) {
        sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria_detalle WHERE auditoria_id = ?", [$id]);
        sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria WHERE id = ?", [$id]);
    }
});

// Esta suite escribe en la tabla viva y gasta numeros de registro IDENTITY.
verif_guardia_escritura($dbConnect);

// Barrido previo (ver verificacion_persistencia_test.php): sin esto, una auditoria
// '__TEST__' en curso de una corrida abortada se recupera en vez de crearse una nueva.
sqlsrv_query($dbConnect, "DELETE d FROM verificacion_auditoria_detalle d
    JOIN verificacion_auditoria a ON a.id = d.auditoria_id WHERE a.proveedor = ?", [VERIF_PROVEEDOR_TEST]);
sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria WHERE proveedor = ?", [VERIF_PROVEEDOR_TEST]);

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "DESTINATARIOS\n" . str_repeat('=', 70) . "\n";

chequear('el proveedor centinela no resuelve a nadie',
    verif_destinatarios(VERIF_PROVEEDOR_TEST) === [],
    'es lo que hace imposible que una fila de prueba produzca un envio');

echo "\nARMADO DEL PAQUETE\n" . str_repeat('=', 70) . "\n";

$a = verif_abrir($dbConnect, VERIF_PROVEEDOR_TEST, 'usr_test', 'Auditor Uno');
$creadas[] = $a['id'];
verif_guardar_punto($dbConnect, $a['id'], 'g00',  'aprobado',    null);
verif_guardar_punto($dbConnect, $a['id'], 'o14',  'aprobado',    null);
verif_guardar_punto($dbConnect, $a['id'], 'o45',  'no_aplica',   'El aliado no maneja este informe.');
verif_guardar_punto($dbConnect, $a['id'], 'evol', 'no_aprobado', 'Marzo: ERP 45.489.517 vs portal 45.489.000');
verif_guardar_punto($dbConnect, $a['id'], 'geo',  'aprobado',    null);
verif_cerrar($dbConnect, $a['id'], 'Sin novedades adicionales.');

$p = verif_armar_paquete($dbConnect, $a['id']);

chequear('el paquete lleva los cinco puntos', count($p['filas']) === 5);
chequear('las filas van en el orden de VERIF_INFORMES',
    array_column($p['filas'], 'clave') === array_keys(VERIF_INFORMES),
    'el PDF y la vista deben coincidir informe por informe');
chequear('el asunto nombra al aliado auditado',
    strpos($p['asunto'], VERIF_PROVEEDOR_TEST) !== false);
chequear('el cuerpo nombra al auditor',
    strpos($p['cuerpo_html'], 'Auditor Uno') !== false);
// Sin regex a proposito: la barra invertida se escapa distinto en la cadena y en el
// patron, y una regex mal escapada hace que preg_match devuelva false por ERROR de
// compilacion — que negado da verde. El assert pasaria sin comprobar nada.
// chr(92) es la barra invertida, y no se escapa en ningun nivel.
chequear('el nombre del PDF no lleva caracteres de ruta',
    strpbrk($p['nombre_pdf'], '/' . chr(92)) === false, $p['nombre_pdf']);

$evol = null;
foreach ($p['filas'] as $f) if ($f['clave'] === 'evol') $evol = $f;
chequear('la etiqueta se traduce a texto legible', $evol['etiqueta'] === 'No aprobado');
chequear('la observacion viaja completa',
    $evol['observacion'] === 'Marzo: ERP 45.489.517 vs portal 45.489.000');

chequear('un informe sin marcar sale como vacio, no revienta',
    verif_etiqueta('') === '—' || verif_etiqueta('') === '-');

echo "\n" . str_repeat('=', 70) . "\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
