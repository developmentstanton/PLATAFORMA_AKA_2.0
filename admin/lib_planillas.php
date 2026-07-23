<?php
/**
 * Lógica de la bandeja de planillas del módulo administrativo.
 * Pura y testeable. Incluida por las páginas, los endpoints y los tests.
 * NO ejecuta nada al incluirse (mismo contrato que api/lib_login.php).
 */

/**
 * Los únicos estados admitidos. Coinciden exactamente con los que el portal del aliado
 * sabe pintar (dashboard.php:1222): cualquier otro valor le saldría como badge de
 * "desconocido". 'Estudio' es el estado pendiente, el que asigna el DEFAULT de la columna
 * cuando el aliado sube una codificación.
 */
const PLANILLA_ESTADOS = ['Estudio', 'Aprobado', 'Rechazado'];

/**
 * Motivos de rechazo que ofrece el select.
 *
 * Reemplazar los elementos de este arreglo es todo lo que hace falta para cambiar los
 * motivos — no hay que tocar ni la interfaz ni los endpoints. El texto se compara y se
 * guarda tal cual: cualquier cambio aquí debe respetarse al pie de la letra (mayúsculas,
 * tildes, puntuación). Ninguno puede pasar de 200 caracteres (el largo de la columna).
 */
const PLANILLA_MOTIVOS = [
    'PRODUCTOS SIN FOTO',
    'CAMPOS DEL FORMATO SIN DILIGENCIAR',
    'ERROR EN EL TIPO DE RECEPCION',
    'CANTIDADES A RECIBIR EN CERO',
    'NO CUMPLE LAS CONDICIONES ESTABLECIDAS DE LOS CAMPOS',
    'NO CORRESPONDE LA INFORMACION DE LOS PRODUCTOS YA CODIFICADOS.',
];

/**
 * Valida una transición de estado ANTES de tocar la base.
 *
 * Corre en el servidor a propósito: el select del navegador es una comodidad, no una
 * barrera — cualquiera puede mandar un POST a mano con el estado y el motivo que quiera.
 *
 * @return string Cadena vacía si la transición es válida; el mensaje de error si no.
 */
function planillas_validar(string $estado, ?string $motivo): string {
    if (!in_array($estado, PLANILLA_ESTADOS, true)) {
        return 'Estado no válido.';
    }

    if ($estado === 'Rechazado') {
        $m = trim((string)$motivo);
        if ($m === '') {
            return 'Debes indicar el motivo del rechazo.';
        }
        if (!in_array($m, PLANILLA_MOTIVOS, true)) {
            return 'Motivo de rechazo no válido.';
        }
    }

    // Para los demás estados el motivo sobra, pero no es un error enviarlo:
    // planillas_cambiar_estado() lo descarta a NULL.
    return '';
}

/**
 * Todas las planillas, de la más reciente a la más antigua.
 *
 * Devuelve el conjunto completo: el filtrado por estado lo hace DataTables en el navegador.
 * Con ~300 filas de texto corto el JSON pesa decenas de kilobytes, y filtrar en cliente evita
 * una ida y vuelta a la RDS por cada cambio de filtro — el enlace desde WMS-LAB es lento.
 *
 * @return array filas ['consecutivo'=>int,'nombre_cliente'=>string,'nit'=>?string,
 *                      'fecha'=>string('Y-m-d'),'estado'=>string,'motivo'=>?string]
 */
function planillas_listar($conn): array {
    $sql = "SELECT consecutivo, nombre_cliente, nit, fecha, estado, motivo
            FROM consecutivo_planillas_aka WITH (NOLOCK)
            ORDER BY consecutivo DESC";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        throw new RuntimeException('Listado de planillas falló: ' . print_r(sqlsrv_errors(), true));
    }

    $filas = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $nit    = isset($r['nit']) ? trim((string)$r['nit']) : '';
        $motivo = isset($r['motivo']) ? trim((string)$r['motivo']) : '';
        $filas[] = [
            'consecutivo'    => (int)$r['consecutivo'],
            'nombre_cliente' => trim((string)$r['nombre_cliente']),
            'nit'            => ($nit !== '') ? $nit : null,
            'fecha'          => ($r['fecha'] instanceof DateTime) ? $r['fecha']->format('Y-m-d') : '',
            'estado'         => trim((string)$r['estado']),
            'motivo'         => ($motivo !== '') ? $motivo : null,
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $filas;
}

/**
 * Aplica el cambio de estado sobre una planilla.
 *
 * Normaliza el motivo: solo se guarda cuando el estado es 'Rechazado'. Para cualquier otro
 * estado queda en NULL aunque el llamador haya pasado uno — así una planilla aprobada nunca
 * arrastra el motivo de un rechazo anterior.
 *
 * NO valida: eso es trabajo de planillas_validar(), que el endpoint corre antes.
 *
 * @return bool false si el consecutivo no existe.
 */
function planillas_cambiar_estado($conn, int $consecutivo, string $estado, ?string $motivo): bool {
    $motivoFinal = null;
    if ($estado === 'Rechazado') {
        $m = trim((string)$motivo);
        $motivoFinal = ($m !== '') ? $m : null;
    }

    $sql = "UPDATE consecutivo_planillas_aka
            SET estado = ?, motivo = ?
            WHERE consecutivo = ?";

    $stmt = sqlsrv_query($conn, $sql, [$estado, $motivoFinal, $consecutivo]);
    if ($stmt === false) {
        throw new RuntimeException('Cambio de estado falló: ' . print_r(sqlsrv_errors(), true));
    }

    $afectadas = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    return $afectadas > 0;
}
