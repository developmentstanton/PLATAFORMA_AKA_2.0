<?php
/**
 * Guardia común de las suites que ESCRIBEN en las tablas de verificación.
 *
 * Esas suites insertan y borran auditorías en la tabla VIVA. `id` es IDENTITY: cada INSERT
 * gasta un número para siempre, aunque la fila se borre después. Correrlas con auditorías
 * reales adentro no borra nada — pero abre HUECOS en la numeración, y "Registro N." sale
 * impreso en el PDF y en el correo. Una serie de registros de auditoría con saltos es justo
 * lo que un auditor cuestiona al revisar el archivo.
 *
 * De ahí esta guardia: si la tabla ya tiene auditorías que no son del proveedor centinela,
 * las suites de escritura se niegan a correr. En desarrollo (tabla sin auditorías reales)
 * no estorban.
 *
 * VERIF_PERMITIR_ESCRITURA=1 en el entorno la salta, para quien sepa lo que hace.
 */

function verif_guardia_escritura($conn): void {
    if (getenv('VERIF_PERMITIR_ESCRITURA') === '1') {
        fwrite(STDERR, "AVISO: guardia de escritura saltada (VERIF_PERMITIR_ESCRITURA=1).\n"
                     . "       Esta corrida va a gastar numeros de registro.\n\n");
        return;
    }

    $stmt = sqlsrv_query($conn,
        "SELECT COUNT(*) AS n FROM verificacion_auditoria WHERE proveedor <> ?",
        [VERIF_PROVEEDOR_TEST]);

    // Si la consulta falla (p. ej. la tabla aún no existe), no es asunto de la guardia:
    // que sea el test el que reporte el problema real.
    if ($stmt === false) return;

    $n = (int)sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['n'];
    sqlsrv_free_stmt($stmt);

    if ($n > 0) {
        fwrite(STDERR,
            "\n" . str_repeat('=', 70) . "\n"
          . "SUITE DE ESCRITURA BLOQUEADA\n"
          . str_repeat('=', 70) . "\n"
          . "La tabla verificacion_auditoria ya tiene $n auditoria(s) REAL(es).\n\n"
          . "Esta suite inserta y borra filas de prueba. No tocaria las reales, pero el id\n"
          . "es IDENTITY: cada insercion gasta un numero de registro para siempre. Correrla\n"
          . "aqui dejaria huecos en la numeracion que sale impresa en el PDF y en el correo.\n\n"
          . "Corre en su lugar las suites que NO escriben:\n"
          . "  php tests/verificacion_validar_test.php\n"
          . "  php tests/verificacion_scope_test.php\n"
          . "  php tests/verificacion_envio_test.php\n"
          . "  php tests/verificacion_guard_test.php\n\n"
          . "Si aun asi hace falta, VERIF_PERMITIR_ESCRITURA=1 la salta.\n"
          . str_repeat('=', 70) . "\n");
        exit(1);
    }
}
