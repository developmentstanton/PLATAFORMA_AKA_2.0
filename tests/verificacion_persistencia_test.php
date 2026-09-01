<?php
// Contrato de la persistencia de auditorías.
//
// El caso que más importa es el 4: re-marcar un informe ACTUALIZA, no duplica. Eso es lo
// que sostiene UQ_verificacion_detalle, y sin ello el PDF mostraría dos veces el mismo
// informe con resultados distintos.
//
//   php tests/verificacion_persistencia_test.php
//
// Escribe en la base, pero SOLO con proveedor '__TEST__' y limpia al terminar.

require_once __DIR__ . '/../conexion/conexion_integracion.php';
require_once __DIR__ . '/../api/lib_verificacion.php';

if ($dbConnect === false) {
    fwrite(STDERR, "No hay conexion a INTEGRACION; el test no puede correr.\n");
    exit(1);
}

// Limpieza a prueba de abortos: queda registrada ANTES de que exista la primera fila.
$creadas = [];
register_shutdown_function(function () use (&$creadas, $dbConnect) {
    foreach ($creadas as $id) {
        sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria_detalle WHERE auditoria_id = ?", [$id]);
        sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria WHERE id = ?", [$id]);
    }
});

// Barrido previo: una corrida abortada pudo dejar una auditoria '__TEST__' en curso, y
// verif_abrir() la recuperaria en vez de crear una nueva. El test fallaria sin que nada
// este roto. Se limpia antes de empezar para que la prueba sea repetible.
sqlsrv_query($dbConnect, "DELETE d FROM verificacion_auditoria_detalle d
    JOIN verificacion_auditoria a ON a.id = d.auditoria_id WHERE a.proveedor = ?", [VERIF_PROVEEDOR_TEST]);
sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria WHERE proveedor = ?", [VERIF_PROVEEDOR_TEST]);

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "PERSISTENCIA DE AUDITORIAS\n" . str_repeat('=', 70) . "\n";

// 1) Abrir crea una auditoria nueva
$a = verif_abrir($dbConnect, VERIF_PROVEEDOR_TEST, 'usr_test', 'Auditor Uno');
$creadas[] = $a['id'];
chequear('abrir crea una auditoria', $a['id'] > 0 && $a['nueva'] === true);

// 2) Abrir de nuevo recupera la misma y CONSERVA el auditor original
$b = verif_abrir($dbConnect, VERIF_PROVEEDOR_TEST, 'usr_test', 'Auditor Dos');
chequear('abrir de nuevo recupera la misma auditoria', $b['id'] === $a['id'] && $b['nueva'] === false);
chequear('el auditor original se conserva', $b['auditor'] === 'Auditor Uno',
    'una auditoria no puede acabar firmada por dos personas');

// 3) Guardar un punto
verif_guardar_punto($dbConnect, $a['id'], 'g00', 'aprobado', null);
$c = verif_cargar($dbConnect, $a['id']);
chequear('el punto guardado se lee', ($c['puntos']['g00']['resultado'] ?? '') === 'aprobado');

// 4) Re-marcar ACTUALIZA, no duplica
verif_guardar_punto($dbConnect, $a['id'], 'g00', 'no_aprobado', 'Marzo no cuadra.');
$c = verif_cargar($dbConnect, $a['id']);
chequear('re-marcar actualiza el resultado', $c['puntos']['g00']['resultado'] === 'no_aprobado');
chequear('re-marcar actualiza la observacion', $c['puntos']['g00']['observacion'] === 'Marzo no cuadra.');
$st = sqlsrv_query($dbConnect,
    "SELECT COUNT(*) AS n FROM verificacion_auditoria_detalle WHERE auditoria_id = ? AND informe = 'g00'",
    [$a['id']]);
$n = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['n'];
chequear('re-marcar NO duplica la fila', $n === 1, "hay $n filas");

// 5) Las tildes sobreviven el viaje (CharacterSet UTF-8 + NVARCHAR)
verif_guardar_punto($dbConnect, $a['id'], 'o45', 'no_aprobado', 'Índice de Ventas: año 2025 sin señal.');
$c = verif_cargar($dbConnect, $a['id']);
chequear('las tildes sobreviven',
    $c['puntos']['o45']['observacion'] === 'Índice de Ventas: año 2025 sin señal.');

// 6) Completitud sobre datos reales: es la composicion exacta que corre el endpoint de
//    cierre (verif_cargar -> array_keys(puntos) -> verif_faltantes). Task 1 prueba
//    verif_faltantes con arreglos a mano; esto prueba que lo que sale de la base encaja.
$c = verif_cargar($dbConnect, $a['id']);
chequear('con dos informes marcados faltan los otros tres',
    verif_faltantes(array_keys($c['puntos'])) === ['o14', 'evol', 'geo']);

foreach (['o14', 'evol', 'geo'] as $clave) {
    verif_guardar_punto($dbConnect, $a['id'], $clave, 'aprobado', null);
}
$c = verif_cargar($dbConnect, $a['id']);
chequear('con los cinco marcados no falta ninguno',
    verif_faltantes(array_keys($c['puntos'])) === [],
    'es la condicion que el endpoint de cierre exige antes de enviar nada');

// 7) Cerrar
chequear('cerrar devuelve true la primera vez',
    verif_cerrar($dbConnect, $a['id'], 'Revisión completa.') === true);
$c = verif_cargar($dbConnect, $a['id']);
chequear('queda en estado cerrada', $c['estado'] === 'cerrada');
chequear('guarda los comentarios', $c['comentarios'] === 'Revisión completa.');
chequear('sella cerrado_en', !empty($c['cerrado_en']));
chequear('cerrar de nuevo devuelve false',
    verif_cerrar($dbConnect, $a['id'], 'otra vez') === false,
    'evita reenviar el correo de una auditoria ya cerrada');

// 8) Tras cerrar, abrir crea una NUEVA (es lo que produce el historial)
$d = verif_abrir($dbConnect, VERIF_PROVEEDOR_TEST, 'usr_test', 'Auditor Tres');
$creadas[] = $d['id'];
chequear('tras cerrar, abrir crea una nueva', $d['id'] !== $a['id'] && $d['nueva'] === true);
chequear('la nueva toma el auditor nuevo', $d['auditor'] === 'Auditor Tres');

// 9) Auditoria inexistente
chequear('cargar una auditoria inexistente devuelve null',
    verif_cargar($dbConnect, 0) === null);

echo "\n" . str_repeat('=', 70) . "\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
