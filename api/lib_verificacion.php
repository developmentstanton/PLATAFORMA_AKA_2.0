<?php
/**
 * Lógica del módulo de Verificación (auditoría de aliados).
 * Pura y testeable. Incluida por la vista, los endpoints y los tests.
 * NO ejecuta nada al incluirse (mismo contrato que api/lib_login.php).
 */

/**
 * Los cinco puntos de control, en el orden en que se muestran y se imprimen.
 * Las claves coinciden EXACTAMENTE con el CHECK de verificacion_auditoria_detalle.informe;
 * agregar un informe es agregar una entrada aquí y ampliar el CHECK, nada más.
 */
const VERIF_INFORMES = [
    'g00'  => 'Ventas',
    'o14'  => 'Siembra / Stock / Ventas',
    'o45'  => 'Índice de Ventas',
    'evol' => 'Evolución Histórica',
    'geo'  => 'Georreferenciación',
];

/** Coinciden con el CHECK de la columna resultado. Sensible a mayúsculas, como el CHECK. */
const VERIF_RESULTADOS = ['aprobado', 'no_aprobado', 'no_aplica'];

/** Largo de verificacion_auditoria_detalle.observacion. Se valida aquí para no truncar en la base. */
const VERIF_OBS_MAX = 1000;

/**
 * Proveedor centinela de los tests. verif_destinatarios() lo rechaza, así que una fila de
 * prueba es incapaz de producir un envío aunque alguien la empuje por el camino real.
 */
const VERIF_PROVEEDOR_TEST = '__TEST__';

/**
 * Valida un punto de control ANTES de tocar la base.
 *
 * @return string Cadena vacía si es válido; el mensaje de error si no.
 */
function verif_validar_punto(string $informe, string $resultado, ?string $observacion): string {
    if (!array_key_exists($informe, VERIF_INFORMES)) {
        return 'Informe no válido.';
    }
    if (!in_array($resultado, VERIF_RESULTADOS, true)) {
        return 'Resultado no válido.';
    }

    $obs = trim((string)$observacion);

    // Un "no aprobado" es un hallazgo de auditoría: sin explicación no le sirve a nadie
    // que lea el PDF tres meses después.
    if ($resultado === 'no_aprobado' && $obs === '') {
        return 'Debes explicar por qué no se aprueba.';
    }

    // Se mide en caracteres, no en bytes: la columna es NVARCHAR y las tildes cuentan
    // como uno. strlen() rechazaría observaciones válidas por llevar acentos.
    if (mb_strlen($obs) > VERIF_OBS_MAX) {
        return 'La observación no puede pasar de ' . VERIF_OBS_MAX . ' caracteres.';
    }

    return '';
}

/**
 * Qué informes quedan sin marcar.
 *
 * @param array $marcados Claves de informe ya registradas.
 * @return array Claves que faltan, en el orden de VERIF_INFORMES.
 */
function verif_faltantes(array $marcados): array {
    return array_values(array_diff(array_keys(VERIF_INFORMES), $marcados));
}
