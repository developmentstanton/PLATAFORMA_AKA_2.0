<?php
// api/lib_g00_rango.php — derivación pura de los rangos de comparación de dos años para G00.
// Sin acceso a BD. Testeable con: php tests/g00_rango_comparacion_test.php

/** Cambia el año de una fecha YYYY-MM-DD a $anio; 29-feb→28-feb si $anio no es bisiesto. */
function g00_set_anio(string $fecha, int $anio): string {
    $anio = (int) $anio;
    $mmdd = substr($fecha, 5); // 'MM-DD'
    if ($mmdd === '02-29' && !date('L', mktime(0, 0, 0, 1, 1, $anio))) {
        $mmdd = '02-28';
    }
    return $anio . '-' . $mmdd;
}

/**
 * Deriva [desdeAct, hastaAct, desdeAnt, hastaAnt, error] para comparar dos años.
 * Periodo MAYOR = [$desde,$hasta] (año tomado de $hasta). Periodo MENOR = año $anioB.
 * $cal: 'retail' → anterior = -364*(añoMayor-añoMenor) días; otro → mismo mes/día en $anioB.
 * error='rango_anios_invalido' si $anioB<=0 o $anioB>=añoMayor (devuelve el mayor en ambos lados).
 */
function g00_rango_comparacion(string $desde, string $hasta, int $anioB, string $cal): array {
    $anioA = (int) substr($hasta, 0, 4);
    $anioB = (int) $anioB;
    if ($anioB <= 0 || $anioB >= $anioA) {
        return [$desde, $hasta, $desde, $hasta, 'rango_anios_invalido'];
    }
    if ($cal === 'retail') {
        $dias = ' -' . (364 * ($anioA - $anioB)) . ' days';
        $desdeAnt = date('Y-m-d', strtotime($desde . $dias));
        $hastaAnt = date('Y-m-d', strtotime($hasta . $dias));
    } else {
        $desdeAnt = g00_set_anio($desde, $anioB);
        $hastaAnt = g00_set_anio($hasta, $anioB);
    }
    return [$desde, $hasta, $desdeAnt, $hastaAnt, null];
}
