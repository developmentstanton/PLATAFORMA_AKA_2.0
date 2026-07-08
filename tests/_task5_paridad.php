<?php
/**
 * Task 5 — paridad cache vs en-vivo (nocache=1) del endpoint G00 completo, exhaustiva
 * + medición de latencia + intento de verificación de concurrencia del materialize.
 *
 * Incluido por tests/verificar_g00_cache.php cuando se invoca con --paridad.
 * Requiere que el llamador ya haya hecho require de conexion_integracion.php,
 * api/lib_refs.php, api/lib_g00_cache.php y api/lib_g00_rango.php ($dbConnect disponible).
 *
 * Oráculo: NO se duplica lógica de negocio. Se drivea api/informe_g00.php dos veces por
 * combinación (sesión simulada vía tests/_endpoint_run.php, igual patrón que Task 4 y que
 * tests/g00_sss_test.php): una vez cache-first (?tab=X) y otra en vivo (?tab=X&nocache=1).
 * Las respuestas JSON completas deben ser IDÉNTICAS (tras normalizar orden de listas y
 * redondear floats a 4 decimales, por posible ruido de punto flotante en SUM() cuando el
 * orden físico de filas difiere entre el scan en vivo y el scan del cache).
 */

// ------------------------------------------------------------------
// Helpers de invocación del endpoint real (mismo patrón que g00_sss_test.php / Task 4).
// ------------------------------------------------------------------
function g00CallEndpoint(string $prov, string $qs): ?array {
    $runner = __DIR__ . '/_endpoint_run.php';
    $php    = PHP_BINARY;
    $nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    $json = ($a !== false && $b !== false && $b >= $a) ? substr($raw, $a, $b - $a + 1) : $raw;
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

// ------------------------------------------------------------------
// Normalización para comparar payloads sin importar orden de filas (GROUPING SETS no
// garantiza orden sin ORDER BY) ni ruido de punto flotante en SUM() (orden de filas
// físico distinto entre el scan en vivo y el scan del cache).
// ------------------------------------------------------------------
function g00IsList(array $arr): bool {
    return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
}
function g00NormalizeForCompare($val) {
    if (is_float($val)) return round($val, 4);
    if (is_array($val)) {
        $out = [];
        foreach ($val as $k => $v) $out[$k] = g00NormalizeForCompare($v);
        if (g00IsList($out)) {
            usort($out, fn($a, $b) => json_encode($a) <=> json_encode($b));
        }
        return $out;
    }
    return $val;
}
function g00DiffPayloads($a, $b, string $path = ''): array {
    $out = [];
    if (is_array($a) && is_array($b)) {
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        foreach ($keys as $k) {
            $p = $path === '' ? (string)$k : "$path.$k";
            if (!array_key_exists($k, $a)) { $out[] = "$p: FALTA en cache | nocache=" . json_encode($b[$k]); continue; }
            if (!array_key_exists($k, $b)) { $out[] = "$p: FALTA en nocache | cache=" . json_encode($a[$k]); continue; }
            $out = array_merge($out, g00DiffPayloads($a[$k], $b[$k], $p));
        }
    } elseif ($a !== $b) {
        $out[] = "$path: cache=" . json_encode($a) . " | nocache=" . json_encode($b);
    }
    return $out;
}

/**
 * Señal de volumen por tab: cuántas filas de dato real (no metadatos) trae el payload y
 * cuánto valor de ventas suma. Se usa como guarda de no-vacuidad: si AMBOS lados (cache y
 * nocache) de un combo dan volumen 0, un PASS por igualdad (empty === empty) es VACUO —
 * no prueba nada de la lógica de cache — y debe marcarse aparte, no contar como paridad
 * genuina verificada. Estructura de cada tab (ver api/informe_g00.php):
 *  - detal:     kpis.ventas_actual / kpis.ventas_anterior + filas de por_grupo/por_marca.
 *  - tiendas:   payload.tiendas[] (cada fila con val_act/val_ant).
 *  - productos: 3 árboles (negocios/categorias/generos), cada nodo con val_act/val_ant.
 *  - periodos:  payload.dias[] (cada fila con val_act/val_ant).
 */
function g00VolumeSignal(string $tab, array $payload): array {
    switch ($tab) {
        case 'detal':
            $va = (float)($payload['kpis']['ventas_actual']   ?? 0);
            $vb = (float)($payload['kpis']['ventas_anterior'] ?? 0);
            $rows = count($payload['por_grupo'] ?? []) + count($payload['por_marca'] ?? []);
            return ['val' => $va + $vb, 'rows' => $rows];
        case 'tiendas':
            $tiendas = $payload['tiendas'] ?? [];
            $val = 0.0;
            foreach ($tiendas as $t) $val += (float)($t['val_act'] ?? 0) + (float)($t['val_ant'] ?? 0);
            return ['val' => $val, 'rows' => count($tiendas)];
        case 'productos':
            $rows = count($payload['negocios'] ?? []) + count($payload['categorias'] ?? []) + count($payload['generos'] ?? []);
            $val = 0.0;
            foreach (['negocios', 'categorias', 'generos'] as $k) {
                foreach ($payload[$k] ?? [] as $node) $val += (float)($node['val_act'] ?? 0) + (float)($node['val_ant'] ?? 0);
            }
            return ['val' => $val, 'rows' => $rows];
        case 'periodos':
            $dias = $payload['dias'] ?? [];
            $val = 0.0;
            foreach ($dias as $d) $val += (float)($d['val_act'] ?? 0) + (float)($d['val_ant'] ?? 0);
            return ['val' => $val, 'rows' => count($dias)];
        default:
            return ['val' => 0.0, 'rows' => 0];
    }
}

/**
 * Ejecuta una combinación (proveedor, tab, filtros, extra) dos veces — cache y nocache=1 —
 * y compara. Imprime PASS/FAIL/EMPTY con detalle de diffs (máx 15 líneas) en caso de FAIL,
 * y volumen (val_act+val_ant, filas) en caso de PASS/EMPTY para que un humano audite la
 * corrida sin adivinar. `generado` (timestamp de detal) se descarta antes de comparar: varía
 * por llamada aunque todo lo demás sea idéntico.
 *
 * Retorna 'FAIL' | 'PASS' (paridad genuina, con datos) | 'EMPTY' (ambos lados vacíos: cache
 * y nocache coinciden, pero por-vacuidad, NO prueba nada de la lógica de cache — se cuenta
 * aparte, nunca como paridad genuina verificada).
 */
function g00ParidadCombo(string $prov, string $tab, array $filtros, array $extra, string $label, array &$fails): string {
    $qsCache   = http_build_query(array_merge(['tab' => $tab], $filtros, $extra));
    $qsNocache = http_build_query(array_merge(['tab' => $tab], $filtros, $extra, ['nocache' => 1]));

    $rc = g00CallEndpoint($prov, $qsCache);
    $rn = g00CallEndpoint($prov, $qsNocache);

    if ($rc === null || $rn === null) {
        echo "  [FAIL] $label -- respuesta no decodificable (cache=" . ($rc === null ? 'NULL' : 'ok') . " nocache=" . ($rn === null ? 'NULL' : 'ok') . ")\n";
        $fails[] = $label;
        return 'FAIL';
    }
    if (($rc['ok'] ?? null) !== true) {
        echo "  [FAIL] $label -- cache ok:false " . json_encode($rc) . "\n";
        $fails[] = $label;
        return 'FAIL';
    }
    if (($rn['ok'] ?? null) !== true) {
        echo "  [FAIL] $label -- nocache ok:false " . json_encode($rn) . "\n";
        $fails[] = $label;
        return 'FAIL';
    }

    unset($rc['generado'], $rn['generado']);
    $normA = g00NormalizeForCompare($rc);
    $normB = g00NormalizeForCompare($rn);

    if ($normA === $normB) {
        $vol = g00VolumeSignal($tab, $rc);
        if ($vol['val'] <= 0.0 && $vol['rows'] <= 0) {
            echo "  [EMPTY/SKIPPED] $label -- ambos lados vacios (val=0, rows=0); PASS trivial, NO cuenta como paridad verificada\n";
            return 'EMPTY';
        }
        printf("  [PASS] %s (val_act+val_ant=%s, rows=%d)\n", $label, number_format($vol['val'], 2, '.', ''), $vol['rows']);
        return 'PASS';
    }

    echo "  [FAIL] $label -- DIFF DETECTADO:\n";
    $diffs = g00DiffPayloads($normA, $normB);
    foreach (array_slice($diffs, 0, 15) as $d) echo "     $d\n";
    if (count($diffs) > 15) echo "     ... (" . (count($diffs) - 15) . " diffs mas, truncado)\n";
    $fails[] = $label;
    return 'FAIL';
}

// ------------------------------------------------------------------
// Parte B — medición: 1a carga (cache-miss -> materialize) vs filtro (cache-hit).
// ------------------------------------------------------------------
function g00ComputeDefaultCacheKey(string $prov): string {
    $anioA = (int) date('Y');
    $anioBIn = $anioA - 1;
    $desdeAct = date('Y-01-01');
    $hastaAct = g00_cap_hasta(date('Y-m-d'), date('Y-m-d', strtotime('-1 day')));
    list($desdeAct, $hastaAct, $desdeAnt, $hastaAnt,) = g00_rango_comparacion($desdeAct, $hastaAct, $anioBIn, 'diaadia');
    $yearAct = (int) date('Y', strtotime($hastaAct));
    $gmin = ($desdeAnt < $desdeAct) ? $desdeAnt : $desdeAct;
    $gmax = ($hastaAct > $hastaAnt) ? $hastaAct : $hastaAnt;
    // Rango de Mensual (diaadia, sin desde custom) coincide con gmin/gmax -> el span del
    // cache (union) es exactamente gmin/gmax, igual que en api/informe_g00.php.
    return g00CacheKey($prov, $yearAct, $anioBIn, $gmin, $gmax);
}

function g00MedirLatencia($dbConnect, string $prov, string $marcaFiltro): array {
    $ckey = g00ComputeDefaultCacheKey($prov);
    // Forzar cache-miss real: borrar cualquier fila existente para esta key.
    $del = sqlsrv_query($dbConnect, "DELETE FROM INTEGRACION.dbo.g00_cache_ventas WHERE cache_key=?", [$ckey]);
    if ($del !== false) sqlsrv_free_stmt($del);

    $t0 = microtime(true);
    $r1 = g00CallEndpoint($prov, 'tab=detal');
    $t1 = microtime(true);

    $t2 = microtime(true);
    $r2 = g00CallEndpoint($prov, 'tab=detal&marca=' . rawurlencode($marcaFiltro));
    $t3 = microtime(true);

    return [
        'first_load_ms' => round(($t1 - $t0) * 1000),
        'filter_ms'     => round(($t3 - $t2) * 1000),
        'first_ok'      => ($r1['ok'] ?? false) === true,
        'filter_ok'     => ($r2['ok'] ?? false) === true,
    ];
}

// ------------------------------------------------------------------
// Parte C (opcional) — concurrencia: 2 materializes casi-simultáneos de la MISMA key no
// deben duplicar filas (sp_getapplock serializa). Se lanza vía proc_open (no bloqueante al
// spawnear: ambos procesos arrancan antes de leer su salida) contra tests/_task5_concurrent_materialize.php.
// ------------------------------------------------------------------
function g00TestConcurrencia($dbConnect, string $prov): ?array {
    $ckey = g00ComputeDefaultCacheKey($prov);
    $anioA = (int) date('Y');
    $anioBIn = $anioA - 1;
    $desdeAct = date('Y-01-01');
    $hastaAct = g00_cap_hasta(date('Y-m-d'), date('Y-m-d', strtotime('-1 day')));
    list($desdeAct, $hastaAct, $desdeAnt, $hastaAnt,) = g00_rango_comparacion($desdeAct, $hastaAct, $anioBIn, 'diaadia');
    $gmin = ($desdeAnt < $desdeAct) ? $desdeAnt : $desdeAct;
    $gmax = ($hastaAct > $hastaAnt) ? $hastaAct : $hastaAnt;

    $helper = __DIR__ . '/_task5_concurrent_materialize.php';
    if (!file_exists($helper)) return null;

    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($helper) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($ckey) . ' '
         . escapeshellarg($gmin) . ' ' . escapeshellarg($gmax);

    // --- Baseline: 1 sola corrida secuencial, para saber cuántas filas produce un materialize normal.
    $del = sqlsrv_query($dbConnect, "DELETE FROM INTEGRACION.dbo.g00_cache_ventas WHERE cache_key=?", [$ckey]);
    if ($del !== false) sqlsrv_free_stmt($del);
    shell_exec($cmd);
    $st = sqlsrv_query($dbConnect, "SELECT COUNT(*) n FROM INTEGRACION.dbo.g00_cache_ventas WHERE cache_key=?", [$ckey]);
    $baseline = $st !== false ? (int) sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['n'] : -1;
    if ($st !== false) sqlsrv_free_stmt($st);
    if ($baseline <= 0) return ['skip' => true, 'reason' => "baseline invalido ($baseline)"];

    // --- Concurrente: borrar de nuevo y lanzar 2 procesos casi-simultáneos contra la misma key.
    $del = sqlsrv_query($dbConnect, "DELETE FROM INTEGRACION.dbo.g00_cache_ventas WHERE cache_key=?", [$ckey]);
    if ($del !== false) sqlsrv_free_stmt($del);

    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p1 = @proc_open($cmd, $desc, $pipes1);
    $p2 = @proc_open($cmd, $desc, $pipes2);
    if (!is_resource($p1) || !is_resource($p2)) {
        if (is_resource($p1)) proc_close($p1);
        if (is_resource($p2)) proc_close($p2);
        return ['skip' => true, 'reason' => 'proc_open no disponible/fallo en este entorno'];
    }
    $out1 = stream_get_contents($pipes1[1]); fclose($pipes1[1]); fclose($pipes1[2]);
    $out2 = stream_get_contents($pipes2[1]); fclose($pipes2[1]); fclose($pipes2[2]);
    proc_close($p1);
    proc_close($p2);

    $st = sqlsrv_query($dbConnect, "SELECT COUNT(*) n FROM INTEGRACION.dbo.g00_cache_ventas WHERE cache_key=?", [$ckey]);
    $concurrente = $st !== false ? (int) sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['n'] : -1;
    if ($st !== false) sqlsrv_free_stmt($st);

    return [
        'skip' => false,
        'baseline' => $baseline,
        'concurrente' => $concurrente,
        'out1' => trim((string)$out1),
        'out2' => trim((string)$out2),
        'duplicado' => $concurrente > $baseline, // si duplicó filas, concurrente > baseline (idealmente ==)
    ];
}

// ------------------------------------------------------------------
// Orquestador principal.
// ------------------------------------------------------------------
function g00RunParidadFull($dbConnect): int {
    // NOTA (fix revisión critica 2026-07-07): CALZADO WALDOS fue reemplazado por
    // DISANDINA S.A. Motivo: CALZADO WALDOS tiene 0 filas de venta en TODA la ventana
    // 2025-01-01..hoy-1 (verificado por query directa contra
    // INTEGRACION.dbo.Ventas_Detal_PBI/Ventas_Detal_Acum_PBI join #refs) -> los 17 combos
    // que lo usaban (12 de la matriz principal + 5 de los casos mandatorios) comparaban
    // payload_vacio === payload_vacio: PASS trivial que NO prueba nada de la lógica de
    // cache (paridad vacua). DISANDINA S.A. es un proveedor genuinamente chico (92 items,
    // 396 filas de venta en 2025-01-01..hoy-1, vs 5788 de BH BRANDS SAS y 96894 de BRAHMA
    // CONCEPT) pero con ventas reales confirmadas en TODAS las ventanas que ejercita esta
    // matriz, incluida la más angosta (desde-custom = Mar-01 del año actual..ayer):
    //   ventana completa 2025-01-01..hoy-1        : 396 filas, $32.85M+$2.76M
    //   año actual (Ene1-ayer)                     : 40  filas, $2,762,686
    //   desde-custom (Mar1-ayer, año actual)        : 15  filas, $976,890
    //   marca=OAKLEY  en año actual (Ene1-ayer)     : 19  filas, $1,380,419
    //   marca=OAKLEY  en desde-custom               : 2   filas, $135,967
    //   grupo=AKA     en año actual (Ene1-ayer)     : 38  filas, $2,667,055
    //   grupo=AKA     en desde-custom               : 14  filas, $949,243
    // marca=OAKLEY (mayor volumen de filas de venta entre las marcas de DISANDINA) y
    // grupo=AKA (grupo dominante, 394/396 filas) fueron elegidos por ser los filtros
    // no-triviales con más señal, análogo al criterio ya usado para BH BRANDS SAS/BRAHMA
    // CONCEPT.
    $provInfo = [
        'BH BRANDS SAS'  => ['marca' => 'GOODYEAR', 'grupo' => 'AKA'],
        'BRAHMA CONCEPT' => ['marca' => 'BRAHMA',    'grupo' => 'SPRING STEP'],
        'DISANDINA S.A.' => ['marca' => 'OAKLEY',    'grupo' => 'AKA'],
    ];
    $tabs = ['detal', 'tiendas', 'productos', 'periodos'];
    $fails = [];
    $empties = [];
    $total = 0;

    echo "==== PARTE A.1 -- MATRIZ PRINCIPAL: 3 proveedores x 4 tabs x 3 filtros ====\n";
    foreach ($provInfo as $prov => $info) {
        foreach ($tabs as $tab) {
            $filtroSets = [
                'sin filtro'              => [],
                "marca={$info['marca']}"  => ['marca' => $info['marca']],
                "grupo={$info['grupo']}"  => ['grupo' => $info['grupo']],
            ];
            foreach ($filtroSets as $fLabel => $filtros) {
                $label = "$prov | tab=$tab | $fLabel";
                $total++;
                $status = g00ParidadCombo($prov, $tab, $filtros, [], $label, $fails);
                if ($status === 'EMPTY') $empties[] = $label;
            }
        }
    }

    echo "\n==== PARTE A.2 -- CASOS MANDATORIOS (periodos+desde-custom, periodos+retail, detal/tiendas/productos+desde-custom+retail) ====\n";
    $desdeMid = date('Y') . '-03-01';
    $hastaHoy = date('Y-m-d', strtotime('-1 day'));
    foreach ($provInfo as $prov => $info) {
        $extraCases = [
            ['tab' => 'periodos',  'extra' => ['desde' => $desdeMid, 'hasta' => $hastaHoy], 'label' => 'periodos+desde-custom(' . $desdeMid . ')'],
            ['tab' => 'periodos',  'extra' => ['cal' => 'retail'], 'label' => 'periodos+retail'],
            ['tab' => 'detal',     'extra' => ['desde' => $desdeMid, 'hasta' => $hastaHoy, 'cal' => 'retail'], 'label' => 'detal+desde-custom+retail'],
            ['tab' => 'tiendas',   'extra' => ['desde' => $desdeMid, 'hasta' => $hastaHoy, 'cal' => 'retail'], 'label' => 'tiendas+desde-custom+retail'],
            ['tab' => 'productos', 'extra' => ['desde' => $desdeMid, 'hasta' => $hastaHoy, 'cal' => 'retail'], 'label' => 'productos+desde-custom+retail'],
        ];
        foreach ($extraCases as $c) {
            $label = "$prov | {$c['label']}";
            $total++;
            $status = g00ParidadCombo($prov, $c['tab'], [], $c['extra'], $label, $fails);
            if ($status === 'EMPTY') $empties[] = $label;
        }
    }

    $genuinos = $total - count($fails) - count($empties);
    echo "\n==== RESULTADO PARIDAD ====\n";
    printf(
        "Combos ejecutados: %d | Paridad GENUINA verificada (no-vacua, 0 diffs): %d | EMPTY/SKIPPED (vacuos, excluidos del conteo de paridad): %d | Fallos: %d\n",
        $total, $genuinos, count($empties), count($fails)
    );
    if ($empties) {
        echo "COMBOS VACUOS (cache y nocache coinciden pero AMBOS vacios -- no prueban paridad, revisar si el filtro/ventana es correcto):\n";
        foreach ($empties as $e) echo "  - $e\n";
    }
    if ($fails) {
        echo "COMBOS CON DIFERENCIAS (requieren escalar como bug de Task 4, NO normalizar):\n";
        foreach ($fails as $f) echo "  - $f\n";
    }

    echo "\n==== PARTE B -- MEDICION (1a carga = cache-miss vs filtro = cache-hit) ====\n";
    foreach (['BH BRANDS SAS' => $provInfo['BH BRANDS SAS']['marca'], 'BRAHMA CONCEPT' => $provInfo['BRAHMA CONCEPT']['marca']] as $prov => $marca) {
        $m = g00MedirLatencia($dbConnect, $prov, $marca);
        printf("[%s] 1a carga (cache-miss->materialize): %d ms (ok=%s) | filtro (cache-hit): %d ms (ok=%s)\n",
            $prov, $m['first_load_ms'], $m['first_ok'] ? 'si' : 'NO', $m['filter_ms'], $m['filter_ok'] ? 'si' : 'NO');
    }

    echo "\n==== PARTE C (opcional) -- CONCURRENCIA DEL MATERIALIZE ====\n";
    $conc = g00TestConcurrencia($dbConnect, 'BH BRANDS SAS');
    if ($conc === null || ($conc['skip'] ?? false)) {
        echo "CONCURRENCIA SKIP: " . ($conc['reason'] ?? 'helper no disponible') . "\n";
    } else {
        printf("baseline (1 corrida)=%d filas | concurrente (2 procesos casi-simultaneos)=%d filas | duplicado=%s\n",
            $conc['baseline'], $conc['concurrente'], $conc['duplicado'] ? 'SI (FALLO)' : 'NO (OK)');
        if ($conc['duplicado']) { $fails[] = 'concurrencia: filas duplicadas'; }
    }

    echo "\n";
    if ($fails) {
        echo "PARIDAD G00 FAIL\n";
        return 1;
    }
    echo "PARIDAD G00 OK\n";
    return 0;
}
