<?php
/** Parseo robusto de Bodegas.COORDENADAS (texto libre: "lat lng", "lat, lng", "lat,lng,zoom"). */
if (!function_exists('parseCoord')) {
    /**
     * Devuelve [lat, lng] (floats) o [null, null] si no hay 2 coordenadas válidas en rango Colombia.
     * Toma los 2 primeros números decimales del texto; ignora cualquier 3er valor (zoom).
     */
    function parseCoord($s) {
        if (!preg_match_all('/-?\d+\.\d+/', (string)$s, $m) || count($m[0]) < 2) return [null, null];
        $lat = (float)$m[0][0];
        $lng = (float)$m[0][1];
        if ($lat < -5 || $lat > 15 || $lng < -80 || $lng > -66) return [null, null];
        return [$lat, $lng];
    }
}
