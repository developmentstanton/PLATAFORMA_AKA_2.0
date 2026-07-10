<?php
/**
 * Task 4 — paridad cache vs en-vivo (nocache=1) del endpoint EVOL (tab=data), exhaustiva sobre
 * 3 proveedores x 4 filtros + medición de latencia + concurrencia lector-durante-rebuild del
 * materialize. Mismo patrón que tests/_task4_paridad_o14.php (Task 4 de O14).
 *
 * Incluido por tests/verificar_evol_cache.php cuando se invoca con --paridad. Requiere que el
 * llamador ya haya hecho require de conexion_integracion.php, api/lib_refs.php y
 * api/lib_evol_cache.php ($dbConnect y evolCacheKey() disponibles).
 *
 * Oráculo: NO se duplica lógica de negocio. Se drivea api/informe_evol.php dos veces por
 * combinación (sesión simulada vía tests/_evol_endpoint_run.php): una vez cache-first
 * (?tab=data) y otra en vivo (?tab=data&nocache=1). Las respuestas JSON completas deben ser
 * IDÉNTICAS (tras normalizar orden de listas/claves y redondear floats).
 *
 * STALENESS (evol-específico, ver .superpowers/sdd/task-4-brief.md): EVOL es MAYORMENTE
 * histórico inmutable (ventas/compras por rango de fechas cerradas, stock por CORTES fin-de-mes
 * para meses pasados). Lo ÚNICO que puede driftear entre el snapshot cacheado y una lectura vivo
 * inmediatamente posterior es el STOCK DEL MES EN CURSO (inv_actual_PBI/_hold_actual_PBI son
 * fotos vivas del ERP — ver informe_evol.php:127-145 y task-3-report.md). Ese drift solo puede
 * tocar, para el mes actual ($mesActual), los campos derivados de `stock`: `valores.stock`,
 * `valores.mesesInv` (stock/ventas), `valores.tiendas` (COUNT DISTINCT ... WHEN stock>0 ...) y
 * `valores.indice` (depende de `tiendas`) — tanto por negocio como en `totalGeneral`. Cualquier
 * diff en `ventas`/`compras`/`totales`, en cualquier OTRO mes, o estructural (negocio
 * faltante/sobrante, meses distintos, etc.) es un fallo REAL, nunca staleness.
 *
 * Por eso, ANTES de la matriz de un proveedor se purga su key de cache (DELETE) para forzar un
 * rematerialize fresco en la 1ª combinación (que dispara ensureEvolCacheBase), y las demás
 * combinaciones del mismo proveedor corren INMEDIATAMENTE después (cache recién materializado,
 * sin demoras artificiales) para minimizar la ventana de drift. Si una combinación muestra un
 * diff que SOLO toca stock/mesesInv/tiendas/indice del mes actual, se repurga y reintenta ESA
 * combinación una sola vez antes de declararla fallo real.
 */

// ------------------------------------------------------------------
// Rango default del endpoint (informe_evol.php:19-20, sin desde/hasta en el querystring):
// desdeMes = (año actual - 1) + '-01', hastaMes = mes actual. La key de purge debe coincidir
// EXACTO con la que computa el endpoint para que purguemos la key correcta.
// ------------------------------------------------------------------
function evolDefaultDesdeMes(): string { return (date('Y') - 1) . '-01'; }
function evolDefaultHastaMes(): string { return date('Y-m'); }

// ------------------------------------------------------------------
// Helper de invocación del endpoint real.
// ------------------------------------------------------------------
function evolCallEndpoint(string $prov, string $qs): ?array {
    $runner = __DIR__ . '/_evol_endpoint_run.php';
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

function evolPurgeKey($dbConnect, string $prov): void {
    $key = evolCacheKey($prov, evolDefaultDesdeMes(), evolDefaultHastaMes());
    $st = sqlsrv_query($dbConnect, "DELETE FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?", [$key]);
    if ($st !== false) sqlsrv_free_stmt($st);
}

// ------------------------------------------------------------------
// Normalización para comparar payloads sin importar orden físico de filas (empates de ORDER BY
// no garantizados entre el scan del cache y el de #base) ni orden de inserción de claves
// asociativas (p.ej. valores.<medida>.<mes>). Recursiva: floats redondeados a 4 decimales,
// arrays asociativos con claves ordenadas (ksort). Caso especial: la lista `negocios` (records
// con campo 'negocio') se reindexa por IDENTIDAD (negocio => fila) en vez de ordenarse por su
// JSON completo — evita que un drift de stock del mes actual en UN negocio cambie su clave de
// sort y desplace su posición (lo que arrastraría falsos diffs "negocio distinto en este
// índice" contra vecinos que no cambiaron). Otras listas (p.ej. `meses`) se ordenan por JSON
// como fallback genérico (son deterministas en ambos caminos, el sort es un no-op seguro).
// ------------------------------------------------------------------
function evolIsList(array $arr): bool {
    return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
}
function evolNormalizeForCompare($val) {
    if (is_float($val)) return round($val, 4);
    if (is_array($val)) {
        $out = [];
        foreach ($val as $k => $v) $out[$k] = evolNormalizeForCompare($v);
        if (evolIsList($out)) {
            if ($out !== []) {
                $porNegocio = true;
                foreach ($out as $item) {
                    if (!is_array($item) || !array_key_exists('negocio', $item)) { $porNegocio = false; break; }
                }
                if ($porNegocio) {
                    $tmp = [];
                    foreach ($out as $item) $tmp[(string) $item['negocio']] = $item;
                    ksort($tmp);
                    return $tmp;
                }
            }
            usort($out, fn($a, $b) => json_encode($a) <=> json_encode($b));
        } else {
            ksort($out);
        }
        return $out;
    }
    return $val;
}
function evolDiffPayloads($a, $b, string $path = ''): array {
    $out = [];
    if (is_array($a) && is_array($b)) {
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        foreach ($keys as $k) {
            $p = $path === '' ? (string)$k : "$path.$k";
            if (!array_key_exists($k, $a)) { $out[] = "$p: FALTA en cache | nocache=" . json_encode($b[$k]); continue; }
            if (!array_key_exists($k, $b)) { $out[] = "$p: FALTA en nocache | cache=" . json_encode($a[$k]); continue; }
            $out = array_merge($out, evolDiffPayloads($a[$k], $b[$k], $p));
        }
    } elseif ($a !== $b) {
        $out[] = "$path: cache=" . json_encode($a) . " | nocache=" . json_encode($b);
    }
    return $out;
}

// Firma de staleness: diffs que SOLO tocan stock/mesesInv/tiendas/indice DEL MES ACTUAL (foto
// viva de inv_actual/_hold_actual que drifea entre el materialize del cache y la lectura vivo
// inmediatamente posterior). ventas/compras/totales/estructura/otros meses NUNCA deben aparecer.
function evolIsStalenessOnly(array $diffs, string $mesActual): bool {
    if (!$diffs) return false;
    $mesQ = preg_quote($mesActual, '/');
    $allowed = '/\.(stock|mesesInv|tiendas|indice)\.' . $mesQ . '$/';
    foreach ($diffs as $d) {
        $path = strstr($d, ':', true);
        if ($path === false) $path = $d;
        if (!preg_match($allowed, $path)) return false;
    }
    return true;
}

/**
 * Señal de volumen: cantidad de negocios en el payload y magnitud (ventas+compras totales) para
 * que un humano audite la corrida. Guarda de no-vacuidad: si AMBOS lados dan rows=0, el PASS por
 * igualdad (vacío===vacío) es VACUO y se cuenta aparte (EMPTY/SKIPPED).
 */
function evolVolumeSignal(array $payload): array {
    $rows = count($payload['negocios'] ?? []);
    $tot = $payload['totalGeneral']['totales'] ?? [];
    $val = (float)($tot['ventas'] ?? 0) + (float)($tot['compras'] ?? 0);
    return ['val' => $val, 'rows' => $rows];
}
function evolCountRows(array $payload): int { return evolVolumeSignal($payload)['rows']; }

/**
 * Ejecuta una combinación (proveedor, filtros) dos veces — cache y nocache=1 — y compara.
 * Imprime PASS/FAIL/EMPTY con volumen (PASS/EMPTY) o diffs (FAIL, máx 15 líneas). Reintenta UNA
 * vez (repurgando) si el diff es staleness-only (ver evolIsStalenessOnly); cualquier otro diff es
 * fallo real inmediato.
 *
 * Retorna 'FAIL' | 'PASS' (paridad genuina, con datos) | 'EMPTY' (ambos lados vacíos).
 */
function evolParidadCombo($dbConnect, string $prov, array $filtros, string $label, array &$fails, bool $allowRetry = true): string {
    $qsCache   = http_build_query(array_merge(['tab' => 'data'], $filtros));
    $qsNocache = http_build_query(array_merge(['tab' => 'data'], $filtros, ['nocache' => 1]));

    $rc = evolCallEndpoint($prov, $qsCache);
    $rn = evolCallEndpoint($prov, $qsNocache);

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

    $mesActual = $rc['mesActual'] ?? date('Y-m');

    $normA = evolNormalizeForCompare($rc);
    $normB = evolNormalizeForCompare($rn);

    if ($normA === $normB) {
        $vol = evolVolumeSignal($rc);
        if ($vol['val'] <= 0.0 && $vol['rows'] <= 0) {
            echo "  [EMPTY/SKIPPED] $label -- ambos lados vacios (val=0, rows=0); PASS trivial, NO cuenta como paridad verificada\n";
            return 'EMPTY';
        }
        printf("  [PASS] %s (val=%s, rows=%d)\n", $label, number_format($vol['val'], 2, '.', ''), $vol['rows']);
        return 'PASS';
    }

    $diffs = evolDiffPayloads($normA, $normB);
    if ($allowRetry && evolIsStalenessOnly($diffs, $mesActual)) {
        echo "  [RETRY] $label -- diffs solo en stock/mesesInv/tiendas/indice del mes actual ($mesActual) (firma de staleness: " . count($diffs) . " campos); repurgando y reintentando UNA vez\n";
        evolPurgeKey($dbConnect, $prov);
        return evolParidadCombo($dbConnect, $prov, $filtros, $label, $fails, false);
    }

    echo "  [FAIL] $label -- DIFF ESTRUCTURAL DETECTADO:\n";
    foreach (array_slice($diffs, 0, 15) as $d) echo "     $d\n";
    if (count($diffs) > 15) echo "     ... (" . (count($diffs) - 15) . " diffs mas, truncado)\n";
    $fails[] = $label;
    return 'FAIL';
}

// ------------------------------------------------------------------
// Parte B — medición: 1a carga (cache-miss -> materialize) vs filtro (cache-hit), y el camino
// viejo (nocache, oráculo) como referencia "antes". tab=data (el único tab cacheado de EVOL).
// ------------------------------------------------------------------
function evolMedirLatencia($dbConnect, string $prov, string $filtroKey, string $filtroVal): array {
    $key = evolCacheKey($prov, evolDefaultDesdeMes(), evolDefaultHastaMes());
    $del = sqlsrv_query($dbConnect, "DELETE FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?", [$key]);
    if ($del !== false) sqlsrv_free_stmt($del);

    $t0 = microtime(true);
    $r1 = evolCallEndpoint($prov, 'tab=data');
    $t1 = microtime(true);

    $t2 = microtime(true);
    $r2 = evolCallEndpoint($prov, 'tab=data&' . $filtroKey . '=' . rawurlencode($filtroVal));
    $t3 = microtime(true);

    return [
        'first_load_ms' => round(($t1 - $t0) * 1000),
        'filter_ms'     => round(($t3 - $t2) * 1000),
        'first_ok'      => ($r1['ok'] ?? false) === true,
        'filter_ok'     => ($r2['ok'] ?? false) === true,
    ];
}

// ------------------------------------------------------------------
// Parte C — concurrencia lector-durante-rebuild: purga la key, lanza 2 procesos casi-
// simultáneos (proc_open) contra el MISMO endpoint (tab=data)/proveedor. El diseño del sistema
// (ensureEvolCacheBase con sp_getapplock + double-checked locking, ver api/lib_evol_cache.php)
// garantiza que CUALQUIER request — sea el que gana el lock y materializa, sea el que espera —
// solo lee evol_cache_base DESPUÉS de que ensureEvolCacheBase retorna true (cache fresco
// committeado). Verificamos: (a) ambas respuestas IDÉNTICAS (mismo snapshot materializado una
// sola vez), (b) ambas no-vacías (rows>0).
// ------------------------------------------------------------------
function evolTestConcurrencia($dbConnect, string $prov, int $repeats = 3): array {
    $runner = __DIR__ . '/_evol_endpoint_run.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg('tab=data');

    $key = evolCacheKey($prov, evolDefaultDesdeMes(), evolDefaultHastaMes());
    $results = [];
    for ($i = 1; $i <= $repeats; $i++) {
        $del = sqlsrv_query($dbConnect, "DELETE FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?", [$key]);
        if ($del !== false) sqlsrv_free_stmt($del);

        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $t0 = microtime(true);
        $p1 = @proc_open($cmd, $desc, $pipes1);
        $p2 = @proc_open($cmd, $desc, $pipes2);
        if (!is_resource($p1) || !is_resource($p2)) {
            if (is_resource($p1)) proc_close($p1);
            if (is_resource($p2)) proc_close($p2);
            return ['skip' => true, 'reason' => 'proc_open no disponible/fallo en este entorno'];
        }
        $out1 = stream_get_contents($pipes1[1]); fclose($pipes1[1]); fclose($pipes1[2]);
        $t1 = microtime(true);
        $out2 = stream_get_contents($pipes2[1]); fclose($pipes2[1]); fclose($pipes2[2]);
        $t2 = microtime(true);
        proc_close($p1);
        proc_close($p2);

        $a1 = strpos($out1, '{'); $j1 = $a1 !== false ? json_decode(substr($out1, $a1), true) : null;
        $a2 = strpos($out2, '{'); $j2 = $a2 !== false ? json_decode(substr($out2, $a2), true) : null;
        $ok1 = is_array($j1) && ($j1['ok'] ?? false) === true;
        $ok2 = is_array($j2) && ($j2['ok'] ?? false) === true;
        $rows1 = $ok1 ? evolCountRows($j1) : -1;
        $rows2 = $ok2 ? evolCountRows($j2) : -1;
        $equal = $ok1 && $ok2 && (evolNormalizeForCompare($j1) === evolNormalizeForCompare($j2));

        $results[] = [
            'iter' => $i, 'ok1' => $ok1, 'ok2' => $ok2, 'rows1' => $rows1, 'rows2' => $rows2,
            'equal' => $equal,
            'elapsed_p1_ms' => round(($t1 - $t0) * 1000),
            'elapsed_total_ms' => round(($t2 - $t0) * 1000),
        ];
    }
    return ['skip' => false, 'results' => $results];
}

// ------------------------------------------------------------------
// Orquestador principal.
// ------------------------------------------------------------------
function evolRunParidadFull($dbConnect): int {
    // Gating por env (para ejecución fragmentada dentro de límites de timeout de CLI/CI; sin env
    // se corre la matriz completa + concurrencia + medición desde el único comando --paridad):
    //   EVOL_PROVS="BELTRANY SAS,BRAHMA CONCEPT"  -> restringe la matriz a esos proveedores
    //   EVOL_SKIP_CONC=1                          -> omite Parte B (concurrencia)
    //   EVOL_SKIP_MED=1                           -> omite Parte C (medición)
    $onlyProvs = getenv('EVOL_PROVS');
    $onlyProvs = ($onlyProvs !== false && $onlyProvs !== '') ? array_map('trim', explode(',', $onlyProvs)) : null;
    $skipConc  = getenv('EVOL_SKIP_CONC') === '1';
    $skipMed   = getenv('EVOL_SKIP_MED') === '1';

    // 3er proveedor (además de BELTRANY SAS y BRAHMA CONCEPT del brief): DISANDINA S.A., mismo
    // elegido que en O14/G00 (datos reales verificados: filas=4710, negocios=142, sum_ventas=360,
    // NO vacuo — lección DISANDINA respetada). Investigación (tests/_zz_investigar_evol.php,
    // throwaway, no commiteado):
    //   BELTRANY SAS   : filas=3577 negocios=69  marca=FILA(100%, mono-marca) grupo=AKA(2978)/BODEGA(598)/ON LINE(1)
    //   BRAHMA CONCEPT : filas=267221 negocios=1106 marca=BRAHMA(100%, mono-marca) grupo=BRAHMA CONCEPT(233254)/AKA(22288)/BODEGA(9623)/ON LINE(2056)
    //   DISANDINA S.A. : filas=4710 negocios=142 marca=OAKLEY(2189)/STANCE(1056)/ALPINESTARS(505)/ROXY(501)/QUIKSILVER(459) grupo=AKA(3989)/BODEGA(721)
    // BELTRANY SAS y BRAHMA CONCEPT son proveedores MONO-MARCA (100% FILA / 100% BRAHMA
    // respectivamente): el filtro marca=<su única marca> NO reduce (universo completo,
    // marca_reduce=false, mismo patrón documentado en O14/G00) — sigue siendo comparación válida
    // (no vacua), solo no ejercita la poda REF. grupo=AKA SÍ reduce en los 3 (confirma la rama
    // BOD con preservación de CEDI). negocio=<top negocio por volumen> SÍ reduce en los 3
    // (siempre acota de 69/1106/142 negocios a 1).
    //
    // ACTIVIDAD EN BODEGAS ADMINISTRATIVAS (excluidas por el materialize/live, ver
    // informe_evol.php:148-153 y lib_evol_cache.php DELETE ADMIN): verificado por consulta directa
    // contra las fuentes vivas (inv_actual_PBI + historico_inventarios_PBI) JOIN Bodegas
    // GRUPO='ADMINISTRATIVAS' (excluyendo CEDI) para cada proveedor:
    //   BELTRANY SAS   : inv_actual filas=20 qty=24   | historico(>=2025-01) filas=150 qty=192
    //   BRAHMA CONCEPT : inv_actual filas=2344 qty=7250 | historico(>=2025-01) filas=38022 qty=99782
    //   DISANDINA S.A. : inv_actual filas=22 qty=35   | historico(>=2025-01) filas=124 qty=175
    // Los 3 proveedores de la matriz tienen actividad REAL en bodegas ADMINISTRATIVAS (no
    // hipotético) — BRAHMA CONCEPT es el caso más significativo (99782+7250 unidades excluidas).
    // La paridad cache/nocache de estos 3 confirma que la exclusión ADMIN del materialize
    // (lib_evol_cache.php) coincide EXACTO con el DELETE ADMIN del camino vivo (informe_evol.php)
    // para los 3, incluyendo el de mayor volumen.
    $provInfo = [
        'BELTRANY SAS'   => ['marca' => 'FILA',   'grupo' => 'AKA', 'negocio' => '434530PRP-MOR',    'marca_reduce' => false],
        'BRAHMA CONCEPT' => ['marca' => 'BRAHMA', 'grupo' => 'AKA', 'negocio' => 'PRE0063-NEG',      'marca_reduce' => false],
        'DISANDINA S.A.' => ['marca' => 'OAKLEY', 'grupo' => 'AKA', 'negocio' => 'FOS90090622YU-GRI','marca_reduce' => true],
    ];
    $fails = [];
    $empties = [];
    $total = 0;

    echo "==== PARTE A -- MATRIZ: 3 proveedores x 4 filtros (tab=data; cache purgado+rematerializado fresco por proveedor) ====\n";
    foreach ($provInfo as $prov => $info) {
        if ($onlyProvs !== null && !in_array($prov, $onlyProvs, true)) continue;
        echo "\n-- Proveedor: $prov -- purgando cache_key (forzar rematerialize fresco en la 1a combinacion) --\n";
        evolPurgeKey($dbConnect, $prov);
        $noReduceNote = $info['marca_reduce'] ? '' : ' [no-reduce esperado: proveedor mono-marca]';
        $filtroSets = [
            'sin filtro'                            => [],
            "marca={$info['marca']}{$noReduceNote}" => ['marca' => $info['marca']],
            "grupo={$info['grupo']}"                => ['grupo' => $info['grupo']],
            "negocio={$info['negocio']}"            => ['negocio' => $info['negocio']],
        ];
        foreach ($filtroSets as $fLabel => $filtros) {
            $label = "$prov | $fLabel";
            $total++;
            $status = evolParidadCombo($dbConnect, $prov, $filtros, $label, $fails);
            if ($status === 'EMPTY') $empties[] = $label;
        }
    }

    $genuinos = $total - count($fails) - count($empties);
    echo "\n==== RESULTADO PARIDAD (PARTE A) ====\n";
    printf(
        "Combos ejecutados: %d | Paridad GENUINA verificada (no-vacua, 0 diffs): %d | EMPTY/SKIPPED (vacuos, excluidos): %d | Fallos: %d\n",
        $total, $genuinos, count($empties), count($fails)
    );
    if ($empties) {
        echo "COMBOS VACUOS:\n";
        foreach ($empties as $e) echo "  - $e\n";
    }
    if ($fails) {
        echo "COMBOS CON DIFERENCIAS ESTRUCTURALES (fallo real):\n";
        foreach ($fails as $f) echo "  - $f\n";
    }

    if ($skipConc) { echo "\n==== PARTE B -- CONCURRENCIA: OMITIDA (EVOL_SKIP_CONC=1) ====\n"; }
    else {
    echo "\n==== PARTE B -- CONCURRENCIA LECTOR-DURANTE-REBUILD (BRAHMA CONCEPT, tab=data, 3 repeticiones) ====\n";
    $conc = evolTestConcurrencia($dbConnect, 'BRAHMA CONCEPT', 3);
    if ($conc['skip'] ?? false) {
        echo "CONCURRENCIA SKIP: " . ($conc['reason'] ?? '??') . "\n";
    } else {
        foreach ($conc['results'] as $r) {
            printf(
                "  iter %d: ok1=%s ok2=%s rows1=%d rows2=%d equal=%s | p1 termino en %dms, p2 (o el segundo en leerse) termino en %dms\n",
                $r['iter'], $r['ok1'] ? 'si' : 'NO', $r['ok2'] ? 'si' : 'NO', $r['rows1'], $r['rows2'],
                $r['equal'] ? 'SI' : 'NO', $r['elapsed_p1_ms'], $r['elapsed_total_ms']
            );
            $vacuo = $r['rows1'] <= 0 || $r['rows2'] <= 0;
            if (!$r['ok1'] || !$r['ok2'] || !$r['equal'] || $vacuo) {
                $fails[] = "concurrencia iter {$r['iter']}: posible lectura parcial/inconsistente (ok1={$r['ok1']} ok2={$r['ok2']} equal={$r['equal']} rows1={$r['rows1']} rows2={$r['rows2']})";
            }
        }
    }
    }

    if ($skipMed) { echo "\n==== PARTE C -- MEDICION: OMITIDA (EVOL_SKIP_MED=1) ====\n"; }
    else {
    echo "\n==== PARTE C -- MEDICION (tab=data; ANTES=nocache/oraculo vs AHORA=cache 1a-carga/filtro) ====\n";
    foreach (['BELTRANY SAS' => $provInfo['BELTRANY SAS']['grupo'], 'BRAHMA CONCEPT' => $provInfo['BRAHMA CONCEPT']['grupo']] as $prov => $grupo) {
        $t0 = microtime(true);
        $rOld = evolCallEndpoint($prov, 'tab=data&nocache=1');
        $tOld = round((microtime(true) - $t0) * 1000);
        $m = evolMedirLatencia($dbConnect, $prov, 'grupo', $grupo);
        printf(
            "[%s] ANTES (nocache/oraculo, tab=data): %d ms (ok=%s) || AHORA cache 1a-carga (cache-miss->materialize): %d ms (ok=%s) | filtro grupo=%s (cache-hit): %d ms (ok=%s)\n",
            $prov, $tOld, ($rOld['ok'] ?? false) ? 'si' : 'NO',
            $m['first_load_ms'], $m['first_ok'] ? 'si' : 'NO',
            $grupo, $m['filter_ms'], $m['filter_ok'] ? 'si' : 'NO'
        );
    }
    }

    echo "\n";
    if ($fails) {
        echo "PARIDAD EVOL FAIL\n";
        return 1;
    }
    echo "PARIDAD EVOL OK\n";
    return 0;
}

// ------------------------------------------------------------------
// E2E (Task 4): prueba el CABLEADO del corto-circuito de disco en api/informe_evol.php tab=data
// sin filtro. No re-verifica la lógica de negocio de evolBuildPayload (eso ya lo hace
// verificar_evol_disco.php --paridad / Task 3); verifica que el endpoint REAL, servido dos
// veces, produce el mismo payload por el camino disco (?tab=data) y por el camino vivo
// (?tab=data&nocache=1) -- y que el archivo de disco efectivamente se escribió (prueba de que
// el corto-circuito corrió, no un fall-through silencioso al camino de filas de abajo). Reusa
// evolCallEndpoint/evolNormalizeForCompare/evolDiffPayloads/evolIsStalenessOnly/evolPurgeKey/
// evolDefaultDesdeMes/evolDefaultHastaMes de este mismo archivo (mismo oráculo que --paridad).
// Llamado desde tests/verificar_evol_disco.php --e2e (requiere lib_disk_cache.php/
// lib_evol_cache.php/lib_evol_disk.php ya requeridos por el llamador).
// ------------------------------------------------------------------
function evolRunE2E(): int {
    require __DIR__ . '/../conexion/conexion_integracion.php';
    require __DIR__ . '/../api/lib_refs.php';
    if ($dbConnect === false) { echo "SKIP: sin DB\n"; return 0; }
    $conn = $dbConnect;
    $fail = 0;
    $chk = function ($cond, $msg) use (&$fail) { echo ($cond ? "OK  " : "FAIL") . "  $msg\n"; if (!$cond) $fail++; };
    $prov = 'BH BRANDS SAS';
    $key  = evolCacheKey($prov, evolDefaultDesdeMes(), evolDefaultHastaMes());

    // --- setup: purgar DB key + borrar cualquier disco existente para forzar un MISS limpio ---
    evolPurgeKey($conn, $prov);
    @unlink(diskCachePath('evol', $key)); @unlink(diskCacheStampPath('evol', $key));
    $chk(!is_file(diskCachePath('evol', $key)), "setup: sin .json.gz previo para $prov");

    // --- llamada 1: disco (MISS -> materialize -> escribe -> sirve) ---
    $t0 = microtime(true);
    $rDisco = evolCallEndpoint($prov, 'tab=data');
    $tDisco = round((microtime(true) - $t0) * 1000);
    $chk(is_array($rDisco) && ($rDisco['ok'] ?? false) === true, "disco (tab=data): ok:true ({$tDisco}ms)");
    $chk(is_file(diskCachePath('evol', $key)), "disco: diskCachePath('evol',key) existe tras la llamada (corto-circuito ejecuto, no fall-through)");
    $chk(is_file(diskCacheStampPath('evol', $key)), 'disco: .stamp existe tras la llamada');

    // --- llamada 2: vivo (oráculo, nocache=1) ---
    $rVivo = evolCallEndpoint($prov, 'tab=data&nocache=1');
    $chk(is_array($rVivo) && ($rVivo['ok'] ?? false) === true, 'vivo (tab=data&nocache=1): ok:true');

    // --- paridad disco vs vivo (mismo oráculo/normalizador que --paridad), tolerando staleness
    //     de stock/mesesInv/tiendas/indice del mes actual (ver evolIsStalenessOnly arriba) ---
    if (is_array($rDisco) && is_array($rVivo)) {
        $mesActual = $rDisco['mesActual'] ?? date('Y-m');
        $normA = evolNormalizeForCompare($rDisco);
        $normB = evolNormalizeForCompare($rVivo);
        if ($normA === $normB) {
            $chk(true, 'paridad disco vs vivo: payloads normalizados IDENTICOS');
        } else {
            $diffs = evolDiffPayloads($normA, $normB);
            if (evolIsStalenessOnly($diffs, $mesActual)) {
                echo "  [INFO] diffs solo en stock/mesesInv/tiendas/indice del mes actual (firma de staleness, tolerado): " . count($diffs) . " campos\n";
                $chk(true, 'paridad disco vs vivo: solo staleness de stock del mes actual (tolerado)');
            } else {
                $chk(false, 'paridad disco vs vivo: DIFF ESTRUCTURAL');
                foreach (array_slice($diffs, 0, 15) as $d) echo "     $d\n";
            }
        }
    }

    // --- llamada 3: disco de nuevo, debe ser un HIT (rápido, sin re-materializar) ---
    $t2 = microtime(true);
    $rDisco2 = evolCallEndpoint($prov, 'tab=data');
    $tDisco2 = round((microtime(true) - $t2) * 1000);
    $chk(is_array($rDisco2) && ($rDisco2['ok'] ?? false) === true, "disco 2a llamada (HIT esperado): ok:true ({$tDisco2}ms)");

    // --- llamada filtrada: el camino filtrado (no-sin-filtros) debe seguir funcionando (NO pasa
    //     por el corto-circuito de disco -- va por el camino de filas WHERE sobre evol_cache_base) ---
    $rFiltrado = evolCallEndpoint($prov, 'tab=data&grupo=AKA');
    $chk(is_array($rFiltrado) && ($rFiltrado['ok'] ?? false) === true, "filtrado (tab=data&grupo=AKA): ok:true");

    // --- cleanup ---
    @unlink(diskCachePath('evol', $key)); @unlink(diskCacheStampPath('evol', $key));

    echo $fail ? "\n$fail FALLO(S)\n" : "\nPARIDAD E2E OK\n";
    return $fail ? 1 : 0;
}
