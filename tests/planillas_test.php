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
    $creados = [];          // consecutivos de las filas de prueba que creamos nosotros
    $limpiezaHecha = false;

    // Crea una fila de prueba (nace en 'Estudio' por el DEFAULT) y devuelve SU consecutivo,
    // tomado del OUTPUT INSERTED: es, con certeza, la fila que acabamos de insertar.
    $crearFila = function () use ($dbConnect, $marca, &$creados) {
        $ins = sqlsrv_query($dbConnect,
            "SET NOCOUNT ON;
             INSERT INTO consecutivo_planillas_aka (nombre_cliente, fecha, nit)
             OUTPUT INSERTED.consecutivo AS consecutivo
             VALUES (?, ?, ?)",
            [$marca, date('Y-m-d'), null]);
        $cons = 0;
        if ($ins !== false) {
            do {
                $r = sqlsrv_fetch_array($ins, SQLSRV_FETCH_ASSOC);
                if ($r && isset($r['consecutivo'])) { $cons = (int)$r['consecutivo']; break; }
            } while (sqlsrv_next_result($ins));
            sqlsrv_free_stmt($ins);
        }
        if ($cons > 0) $creados[] = $cons;
        return $cons;
    };

    // Lee (estado, motivo) de una fila. Devuelve ['e'=>..,'m'=>..] o null si no existe.
    $estadoMotivo = function ($cons) use ($dbConnect) {
        $q = sqlsrv_query($dbConnect,
            "SELECT RTRIM(estado) e, motivo m FROM consecutivo_planillas_aka WHERE consecutivo = ?", [$cons]);
        $r = $q ? sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC) : null;
        if ($q) sqlsrv_free_stmt($q);
        return $r ?: null;
    };

    // Limpieza + reseed del IDENTITY.
    //
    // consecutivo_planillas_aka es una tabla VIVA de produccion (la lee el portal de aliados) en
    // la misma RDS de dev/staging/prod, y consecutivo es un IDENTITY que ademas es un numero de
    // NEGOCIO: cada INSERT lo consume PARA SIEMPRE aunque se borre la fila. Sin devolver el
    // IDENTITY, cada corrida "quemaria" numeros (en desarrollo la tabla salto de 304 a 332 asi).
    // Borra TODAS nuestras filas por la marca unica y, si nadie inserto despues de nosotros
    // (IDENT_CURRENT == nuestra ultima fila), reseedea al MAX real para que la proxima carga de
    // un aliado continue sin hueco. Si un aliado inserto en medio, no toca el IDENTITY (reseedar
    // ahi corromperia su numero) y se acepta un hueco. Idempotente.
    $limpiar = function () use ($dbConnect, $marca, &$creados, &$limpiezaHecha) {
        if ($limpiezaHecha) return;
        $del = @sqlsrv_query($dbConnect,
            "DELETE FROM consecutivo_planillas_aka WHERE nombre_cliente = ?", [$marca]);
        if ($del === false) return;
        sqlsrv_free_stmt($del);
        $limpiezaHecha = true;

        $maxCreado = $creados ? max($creados) : 0;
        if ($maxCreado <= 0) return;
        $q = @sqlsrv_query($dbConnect, "SELECT IDENT_CURRENT('consecutivo_planillas_aka') AS cur");
        $cur = $q ? (int)(sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC)['cur'] ?? 0) : 0;
        if ($q) sqlsrv_free_stmt($q);
        if ($cur !== $maxCreado) return;  // alguien inserto despues: no tocar
        $q2 = @sqlsrv_query($dbConnect, "SELECT ISNULL(MAX(consecutivo), 0) AS mx FROM consecutivo_planillas_aka");
        $mx = $q2 ? (int)(sqlsrv_fetch_array($q2, SQLSRV_FETCH_ASSOC)['mx'] ?? 0) : 0;
        if ($q2) sqlsrv_free_stmt($q2);
        if ($mx <= 0) return;
        // $mx es un entero leido de la propia tabla; seguro para interpolar. Ojo: el driver
        // sqlsrv surfacea los mensajes informativos de DBCC como "error" (SQLSTATE 01000) y
        // devuelve false aunque el RESEED SI se ejecute; por eso se ignora el retorno.
        @sqlsrv_query($dbConnect, "DBCC CHECKIDENT('consecutivo_planillas_aka', RESEED, $mx)");
    };
    // Red de seguridad: corre pase lo que pase (excepcion, fatal que ni un try/finally cubre).
    register_shutdown_function(function () use ($limpiar) { $limpiar(); });

    // === Fila A: rechazar guarda el motivo, y despues la decision es DEFINITIVA ===
    $consA = $crearFila();
    chequear('se creo la fila A de prueba', $consA > 0, "consecutivo=$consA");
    if ($consA > 0) {
        chequear('la fila nueva nace en Estudio',
            planillas_estado_actual($dbConnect, $consA) === 'Estudio');

        $ok = planillas_cambiar_estado($dbConnect, $consA, 'Rechazado', PLANILLA_MOTIVOS[1]);
        chequear('rechazar una planilla en Estudio devuelve true', $ok === true);
        $r1 = $estadoMotivo($consA);
        chequear('quedo en Rechazado', $r1 && $r1['e'] === 'Rechazado');
        chequear('el motivo quedo guardado',
            $r1 && trim((string)$r1['m']) === PLANILLA_MOTIVOS[1],
            $r1 ? "motivo=[{$r1['m']}]" : '');

        // BLOQUEO: ya no esta en Estudio -> no se puede volver a cambiar (regla nueva)
        $ok2 = planillas_cambiar_estado($dbConnect, $consA, 'Aprobado', null);
        chequear('cambiar una planilla ya Rechazada devuelve false', $ok2 === false,
            $ok2 === false ? '' : 'DEJO REABRIR UNA DECISION DEFINITIVA');
        $r1b = $estadoMotivo($consA);
        chequear('sigue en Rechazado tras el intento bloqueado', $r1b && $r1b['e'] === 'Rechazado');
        chequear('estado_actual refleja Rechazado',
            planillas_estado_actual($dbConnect, $consA) === 'Rechazado');
    }

    // === Fila B: aprobar descarta el motivo, y despues la decision es DEFINITIVA ===
    $consB = $crearFila();
    chequear('se creo la fila B de prueba', $consB > 0, "consecutivo=$consB");
    if ($consB > 0) {
        // aprobar CON un motivo pasado: debe descartarse a NULL
        $ok = planillas_cambiar_estado($dbConnect, $consB, 'Aprobado', PLANILLA_MOTIVOS[0]);
        chequear('aprobar una planilla en Estudio devuelve true', $ok === true);
        $r2 = $estadoMotivo($consB);
        chequear('quedo en Aprobado', $r2 && $r2['e'] === 'Aprobado');
        chequear('al aprobar, el motivo se descarto a NULL', $r2 && $r2['m'] === null,
            $r2 ? "motivo=[" . var_export($r2['m'], true) . "]" : '');

        // BLOQUEO
        $ok2 = planillas_cambiar_estado($dbConnect, $consB, 'Rechazado', PLANILLA_MOTIVOS[0]);
        chequear('cambiar una planilla ya Aprobada devuelve false', $ok2 === false,
            $ok2 === false ? '' : 'DEJO REABRIR UNA DECISION DEFINITIVA');
        chequear('estado_actual refleja Aprobado',
            planillas_estado_actual($dbConnect, $consB) === 'Aprobado');
    }

    // === "No existe" ===
    chequear('cambiar un consecutivo inexistente devuelve false',
        planillas_cambiar_estado($dbConnect, -999999, 'Aprobado', null) === false);
    chequear('estado_actual de un inexistente devuelve null',
        planillas_estado_actual($dbConnect, -999999) === null);

    // Limpieza explicita (la red de seguridad solo actua si esto no llega a correr).
    $limpiar();
    chequear('se limpiaron las filas de prueba', $limpiezaHecha === true);
}

echo str_repeat('=', 70) . "\n";
if ($fallos) { echo "FALLARON " . count($fallos) . ": " . implode('; ', $fallos) . "\n"; exit(1); }
echo "TODO OK\n";
exit(0);
