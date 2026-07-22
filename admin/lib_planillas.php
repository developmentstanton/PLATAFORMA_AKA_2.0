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
 * LISTA PROVISIONAL: Rafael entregará la definitiva. Reemplazar los elementos de este
 * arreglo es todo lo que hace falta — no hay que tocar ni la interfaz ni los endpoints.
 * Ninguno puede pasar de 200 caracteres (el largo de la columna).
 */
const PLANILLA_MOTIVOS = [
    'Codificación incompleta',
    'Información del producto inconsistente',
    'Archivo ilegible o dañado',
    'Referencias duplicadas',
    'No cumple con el formato requerido',
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
