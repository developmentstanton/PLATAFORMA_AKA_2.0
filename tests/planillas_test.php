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

// ---------------------------------------------------------------------------
// Contra la BASE DE DATOS.
//
// CUIDADO: consecutivo_planillas_aka es una tabla VIVA de producción. Este bloque inserta
// su PROPIA fila, opera solo sobre el consecutivo que él generó, y la borra al terminar.
// Nunca toca una fila que no haya creado. Mismo patrón que se usó al probar
// cod_registrar_envio().
// ---------------------------------------------------------------------------
echo "\nCONTRA LA BASE (fila propia, se borra al final)\n" . str_repeat('=', 70) . "\n";

require_once __DIR__ . '/../conexion/conexion_integracion.php';

if ($dbConnect === false) {
    echo "  SALTA  no hay conexion a INTEGRACION\n";
} else {
    $marca = 'ZZ_TEST_PLANILLAS';
    $consTest = 0;
    $limpiezaHecha = false;

    // Crear la fila de prueba y quedarnos con SU consecutivo
    $ins = sqlsrv_query($dbConnect,
        "SET NOCOUNT ON;
         INSERT INTO consecutivo_planillas_aka (nombre_cliente, fecha, nit)
         OUTPUT INSERTED.consecutivo AS consecutivo
         VALUES (?, ?, ?)",
        [$marca, date('Y-m-d'), null]);
    if ($ins !== false) {
        do {
            $r = sqlsrv_fetch_array($ins, SQLSRV_FETCH_ASSOC);
            if ($r && isset($r['consecutivo'])) { $consTest = (int)$r['consecutivo']; break; }
        } while (sqlsrv_next_result($ins));
        sqlsrv_free_stmt($ins);
    }

    if ($consTest > 0) {
        // Red de seguridad: consecutivo_planillas_aka es una tabla VIVA de produccion (304
        // filas reales que lee el portal de aliados) en la misma RDS de dev/staging/prod. Si
        // algo aborta el script entre este INSERT y el DELETE explicito de mas abajo (una
        // excepcion no atrapada, un error fatal de PHP como agotamiento de memoria -que ni
        // siquiera un try/finally cubre-, un corte de red contra la RDS), la fila de prueba
        // quedaria huerfana y visible en produccion. Ya paso una vez en desarrollo (consecutivo
        // 323, hubo que borrarlo a mano). register_shutdown_function corre pase lo que pase.
        // Conserva el mismo doble guard (consecutivo + nombre_cliente) y es idempotente: si la
        // limpieza normal de mas abajo ya corrio, no hace nada.
        register_shutdown_function(function () use ($dbConnect, $consTest, $marca, &$limpiezaHecha) {
            if ($limpiezaHecha) return;
            $del = @sqlsrv_query($dbConnect,
                "DELETE FROM consecutivo_planillas_aka WHERE consecutivo = ? AND nombre_cliente = ?",
                [$consTest, $marca]);
            if ($del !== false) {
                sqlsrv_free_stmt($del);
                echo "  [shutdown] red de seguridad: limpiada fila huerfana consecutivo=$consTest\n";
            }
        });
    }

    chequear('se creo la fila de prueba', $consTest > 0, "consecutivo=$consTest");

    if ($consTest > 0) {
        // Guard: solo seguimos si la fila es realmente la nuestra
        $chk = sqlsrv_query($dbConnect,
            "SELECT nombre_cliente FROM consecutivo_planillas_aka WHERE consecutivo = ?", [$consTest]);
        $rowChk = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
        if ($chk) sqlsrv_free_stmt($chk);
        $esNuestra = $rowChk && trim((string)$rowChk['nombre_cliente']) === $marca;
        chequear('la fila de prueba es la nuestra', $esNuestra);

        if ($esNuestra) {
            // a) Nace en 'Estudio' por el DEFAULT de la columna
            $filas = planillas_listar($dbConnect);
            $mia = null;
            foreach ($filas as $f) { if ($f['consecutivo'] === $consTest) { $mia = $f; break; } }
            chequear('planillas_listar devuelve la fila nueva', $mia !== null);
            chequear('la fila nace en estado Estudio',
                $mia !== null && $mia['estado'] === 'Estudio',
                $mia !== null ? "estado={$mia['estado']}" : '');
            chequear('la fecha viene como Y-m-d',
                $mia !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$mia['fecha']) === 1);
            chequear('el motivo nace en NULL', $mia !== null && $mia['motivo'] === null);

            // b) Rechazar guarda el motivo
            $ok = planillas_cambiar_estado($dbConnect, $consTest, 'Rechazado', PLANILLA_MOTIVOS[1]);
            chequear('cambiar a Rechazado devuelve true', $ok === true);
            $q = sqlsrv_query($dbConnect,
                "SELECT RTRIM(estado) e, motivo m FROM consecutivo_planillas_aka WHERE consecutivo = ?",
                [$consTest]);
            $r1 = $q ? sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC) : null;
            if ($q) sqlsrv_free_stmt($q);
            chequear('quedo en Rechazado', $r1 && $r1['e'] === 'Rechazado');
            chequear('el motivo quedo guardado',
                $r1 && trim((string)$r1['m']) === PLANILLA_MOTIVOS[1],
                $r1 ? "motivo=[{$r1['m']}]" : '');

            // c) EL CASO QUE IMPORTA: aprobar limpia el motivo del rechazo anterior
            $ok2 = planillas_cambiar_estado($dbConnect, $consTest, 'Aprobado', PLANILLA_MOTIVOS[0]);
            chequear('cambiar a Aprobado devuelve true', $ok2 === true);
            $q2 = sqlsrv_query($dbConnect,
                "SELECT RTRIM(estado) e, motivo m FROM consecutivo_planillas_aka WHERE consecutivo = ?",
                [$consTest]);
            $r2 = $q2 ? sqlsrv_fetch_array($q2, SQLSRV_FETCH_ASSOC) : null;
            if ($q2) sqlsrv_free_stmt($q2);
            chequear('quedo en Aprobado', $r2 && $r2['e'] === 'Aprobado');
            chequear('al aprobar, el motivo se limpio a NULL', $r2 && $r2['m'] === null,
                $r2 ? "motivo=[" . var_export($r2['m'], true) . "]" : '');

            // d) Un consecutivo inexistente devuelve false
            chequear('consecutivo inexistente devuelve false',
                planillas_cambiar_estado($dbConnect, -999999, 'Aprobado', null) === false);
        }

        // Limpieza: SIEMPRE, y solo nuestra fila (doble guard por marca).
        // La red de seguridad registrada arriba solo actua si esto no llega a correr.
        $del = sqlsrv_query($dbConnect,
            "DELETE FROM consecutivo_planillas_aka WHERE consecutivo = ? AND nombre_cliente = ?",
            [$consTest, $marca]);
        chequear('se borro la fila de prueba', $del !== false);
        if ($del !== false) sqlsrv_free_stmt($del);
        $limpiezaHecha = ($del !== false);
    }
}

echo str_repeat('=', 70) . "\n";
if ($fallos) { echo "FALLARON " . count($fallos) . ": " . implode('; ', $fallos) . "\n"; exit(1); }
echo "TODO OK\n";
exit(0);
