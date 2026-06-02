<?php
/**
 * Motor de recomendaciones O14 — cascada reubicación → CEDI → proveedor.
 * Función pura (sin BD): testeable con fixtures. Ver tests/o14_recomendador_test.php
 * y docs/superpowers/specs/2026-05-22-o14-design.md.
 */

/**
 * Orden default de tiendas origen: mayor sobrante primero, desempate por cod ascendente.
 * @param array $fuentes  [cod => sobrante]
 * @return array          cods ordenados
 */
function o14_orden_default(array $fuentes): array {
    uksort($fuentes, function ($a, $b) use ($fuentes) {
        if ($fuentes[$a] !== $fuentes[$b]) return $fuentes[$b] <=> $fuentes[$a]; // sobrante desc
        return strcmp((string)$a, (string)$b);                                   // cod asc
    });
    return array_keys($fuentes);
}

/**
 * @param array $tiendas  Tiendas de UN negocio: [['cod'=>..,'tallas'=>['9'=>['siembra','disponible','hold'],..]],..]
 * @param array $cedi     Stock CEDI por talla: ['9'=>5,..]
 * @param callable|null $policy  Orden de selección de tiendas origen (default: mayor sobrante primero)
 * @return array  ['reubicaciones','solicitudes_cedi','solicitudes_proveedor','resumen']
 */
function recomendar(array $tiendas, array $cedi = [], $policy = null): array {
    // 1) Por talla: fuentes (sobrante) y destinos (faltante).
    $tallas = [];   // talla => ['fuentes'=>[cod=>sobrante], 'destinos'=>[cod=>faltante]]
    foreach ($tiendas as $t) {
        $cod = $t['cod'];
        foreach ($t['tallas'] as $talla => $m) {
            $talla   = (string)$talla;
            $balance = $m['siembra'] - ($m['disponible'] + $m['hold']);
            if      ($balance > 0) $tallas[$talla]['destinos'][$cod] =  $balance;
            elseif  ($balance < 0) $tallas[$talla]['fuentes'][$cod]  = -$balance;
        }
    }

    // Stock CEDI restante por talla (copia mutable, claves normalizadas a string).
    $cediRestante = [];
    foreach ($cedi as $talla => $uds) $cediRestante[(string)$talla] = $uds;

    $reubicaciones = [];
    $solicitudesCedi = [];
    $provPorTalla  = [];
    $faltanteTotal = 0; $porReubicacion = 0; $porCedi = 0; $aProveedor = 0;

    foreach ($tallas as $talla => $grp) {
        $destinos = $grp['destinos'] ?? [];
        $fuentes  = $grp['fuentes']  ?? [];
        $orden    = $policy ? $policy($fuentes) : o14_orden_default($fuentes);

        foreach ($destinos as $codDest => $necesita) {
            $faltanteTotal += $necesita;

            // 2) Reubicación desde tiendas con sobrante, según política de origen.
            foreach ($orden as $codFuente) {
                if ($necesita <= 0) break;
                $disp = $fuentes[$codFuente] ?? 0;
                if ($disp <= 0) continue;
                $mover = min($disp, $necesita);
                $reubicaciones[] = ['origen' => (string)$codFuente, 'destino' => (string)$codDest, 'talla' => (string)$talla, 'uds' => $mover];
                $fuentes[$codFuente] -= $mover;
                $necesita            -= $mover;
                $porReubicacion      += $mover;
            }

            // 3) CEDI cubre lo que quede (stock compartido por talla).
            if ($necesita > 0 && ($cediRestante[$talla] ?? 0) > 0) {
                $tomar = min($cediRestante[$talla], $necesita);
                $solicitudesCedi[]    = ['destino' => (string)$codDest, 'talla' => (string)$talla, 'uds' => $tomar];
                $cediRestante[$talla] -= $tomar;
                $necesita             -= $tomar;
                $porCedi              += $tomar;
            }

            // 4) Lo que queda → proveedor (agregado por talla).
            if ($necesita > 0) {
                $provPorTalla[$talla] = ($provPorTalla[$talla] ?? 0) + $necesita;
                $aProveedor          += $necesita;
            }
        }
    }

    $solicitudesProveedor = [];
    foreach ($provPorTalla as $talla => $uds) {
        $solicitudesProveedor[] = ['talla' => (string)$talla, 'uds' => $uds];
    }

    return [
        'reubicaciones'         => $reubicaciones,
        'solicitudes_cedi'      => $solicitudesCedi,
        'solicitudes_proveedor' => $solicitudesProveedor,
        'resumen' => [
            'faltante_total'  => $faltanteTotal,
            'por_reubicacion' => $porReubicacion,
            'por_cedi'        => $porCedi,
            'a_proveedor'     => $aProveedor,
        ],
    ];
}
