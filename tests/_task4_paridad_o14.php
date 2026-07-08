<?php
/**
 * Task 4 — paridad cache vs en-vivo (nocache=1) del endpoint O14 completo (tabs b/c/reco),
 * exhaustiva sobre 3 proveedores x 4 filtros + medición de latencia + concurrencia
 * lector-durante-rebuild del materialize.
 *
 * Incluido por tests/verificar_o14_cache.php cuando se invoca con --paridad. Requiere que el
 * llamador ya haya hecho require de conexion_integracion.php, api/lib_refs.php y
 * api/lib_o14_cache.php ($dbConnect y o14CacheKey() disponibles).
 *
 * Oráculo: NO se duplica lógica de negocio. Se drivea api/informe_o14.php dos veces por
 * combinación (sesión simulada vía tests/_o14_endpoint_run.php, mismo patrón que
 * tests/_endpoint_run.php de G00): una vez cache-first (?tab=X) y otra en vivo
 * (?tab=X&nocache=1). Las respuestas JSON completas deben ser IDÉNTICAS (tras normalizar
 * orden de listas/claves y redondear floats, por si el plan físico ordena empates distinto
 * entre el scan del cache y el scan de #base).
 *
 * STALENESS (ver .superpowers/sdd/task-3-report.md): `disponible`/`hold` (y sus derivados
 * disphold/faltante/sobrante/total_stock/tiendas_con_inv) son SNAPSHOTS que drifean en vivo
 * (inv/hold cambian en el ERP). Por eso, ANTES de correr la matriz de un proveedor, se purga
 * su key de cache (DELETE) para forzar un rematerialize fresco en la PRIMERA combinación de
 * ese proveedor (que dispara ensureO14CacheBase), y las demás combinaciones del mismo
 * proveedor reusan ese cache fresco corriendo inmediatamente después (sin demoras
 * artificiales) para minimizar la ventana de drift. Si una combinación muestra una diferencia
 * que SOLO toca disponible/hold/derivados (firma clásica de staleness) con todo lo demás
 * idéntico, se repurga y reintenta ESA combinación una sola vez antes de declararla fallo real
 * (siembra/ventas/conteos/estructura divergiendo SIEMPRE es fallo real, nunca se reintenta).
 */

// ------------------------------------------------------------------
// Helper de invocación del endpoint real.
// ------------------------------------------------------------------
function o14CallEndpoint(string $prov, string $qs): ?array {
    $runner = __DIR__ . '/_o14_endpoint_run.php';
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

function o14PurgeKey($dbConnect, string $prov): void {
    $key = o14CacheKey($prov, '2025-01-01', date('Y-m-d'));
    $st = sqlsrv_query($dbConnect, "DELETE FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=?", [$key]);
    if ($st !== false) sqlsrv_free_stmt($st);
}

// ------------------------------------------------------------------
// Normalización para comparar payloads sin importar orden físico de filas/listas (empates de
// ORDER BY no garantizados entre el scan del cache y el de #base) ni orden de inserción de
// claves en mapas asociativos (p.ej. valores.siembra.<talla>, que se insertan según el orden
// de iteración de filas SQL). Recursiva: floats redondeados a 4 decimales, listas (arrays
// secuenciales 0..n-1) ordenadas por su JSON, arrays asociativos con claves ordenadas (ksort)
// para que la comparación === no dependa de orden de inserción.
// ------------------------------------------------------------------
function o14IsList(array $arr): bool {
    return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
}
function o14NormalizeForCompare($val) {
    if (is_float($val)) return round($val, 4);
    if (is_array($val)) {
        $out = [];
        foreach ($val as $k => $v) $out[$k] = o14NormalizeForCompare($v);
        if (o14IsList($out)) {
            usort($out, fn($a, $b) => json_encode($a) <=> json_encode($b));
        } else {
            ksort($out);
        }
        return $out;
    }
    return $val;
}
function o14DiffPayloads($a, $b, string $path = ''): array {
    $out = [];
    if (is_array($a) && is_array($b)) {
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        foreach ($keys as $k) {
            $p = $path === '' ? (string)$k : "$path.$k";
            if (!array_key_exists($k, $a)) { $out[] = "$p: FALTA en cache | nocache=" . json_encode($b[$k]); continue; }
            if (!array_key_exists($k, $b)) { $out[] = "$p: FALTA en nocache | cache=" . json_encode($a[$k]); continue; }
            $out = array_merge($out, o14DiffPayloads($a[$k], $b[$k], $p));
        }
    } elseif ($a !== $b) {
        $out[] = "$path: cache=" . json_encode($a) . " | nocache=" . json_encode($b);
    }
    return $out;
}

// Firma de staleness: diffs que SOLO tocan disponible/hold y sus derivados (foto viva que
// drifea dentro del TTL). siembra/ventas/conteos/identidad de filas NUNCA deben aparecer acá.
function o14IsStalenessOnly(array $diffs): bool {
    if (!$diffs) return false;
    $allowed = '/(disponible|hold|disphold|faltante|sobrante|total_stock|tiendas_con_inv)/i';
    foreach ($diffs as $d) {
        $path = strstr($d, ':', true);
        if ($path === false) $path = $d;
        if (!preg_match($allowed, $path)) return false;
    }
    return true;
}

/**
 * Señal de volumen por tab: cuántas filas de dato real trae el payload y una magnitud (suma de
 * KPIs/valores) para que un humano audite la corrida. Guarda de no-vacuidad: si AMBOS lados
 * (cache y nocache) de un combo dan rows=0, el PASS por igualdad (vacío===vacío) es VACUO y se
 * cuenta aparte (EMPTY/SKIPPED), no como paridad genuina.
 */
function o14VolumeSignal(string $tab, array $payload): array {
    switch ($tab) {
        case 'b':
            $rows = count($payload['filas'] ?? []);
            $val = array_sum(array_filter($payload['kpis'] ?? [], 'is_numeric'));
            return ['val' => $val, 'rows' => $rows];
        case 'c':
            $rows = 0;
            foreach ($payload['grupos'] ?? [] as $g) foreach ($g['almacenes'] ?? [] as $a) $rows += count($a['negocios'] ?? []);
            $val = array_sum(array_filter($payload['kpis'] ?? [], 'is_numeric'));
            return ['val' => $val, 'rows' => $rows];
        case 'reco':
            $rows = count($payload['filas'] ?? []);
            $val = 0.0;
            foreach ($payload['filas'] ?? [] as $f) {
                foreach (['sobrante', 'faltante', 'proveedor'] as $m) $val += array_sum($f['valores'][$m] ?? []);
            }
            return ['val' => $val, 'rows' => $rows];
        default:
            return ['val' => 0.0, 'rows' => 0];
    }
}

/**
 * Ejecuta una combinación (proveedor, tab, filtros) dos veces — cache y nocache=1 — y compara.
 * Imprime PASS/FAIL/EMPTY con volumen (PASS/EMPTY) o diffs (FAIL, máx 15 líneas). Reintenta UNA
 * vez (repurgando) si el diff es staleness-only (ver o14IsStalenessOnly); cualquier otro diff es
 * fallo real inmediato.
 *
 * Retorna 'FAIL' | 'PASS' (paridad genuina, con datos) | 'EMPTY' (ambos lados vacíos).
 */
function o14ParidadCombo($dbConnect, string $prov, string $tab, array $filtros, string $label, array &$fails, bool $allowRetry = true): string {
    $qsCache   = http_build_query(array_merge(['tab' => $tab], $filtros));
    $qsNocache = http_build_query(array_merge(['tab' => $tab], $filtros, ['nocache' => 1]));

    $rc = o14CallEndpoint($prov, $qsCache);
    $rn = o14CallEndpoint($prov, $qsNocache);

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

    $normA = o14NormalizeForCompare($rc);
    $normB = o14NormalizeForCompare($rn);

    if ($normA === $normB) {
        $vol = o14VolumeSignal($tab, $rc);
        if ($vol['val'] <= 0.0 && $vol['rows'] <= 0) {
            echo "  [EMPTY/SKIPPED] $label -- ambos lados vacios (val=0, rows=0); PASS trivial, NO cuenta como paridad verificada\n";
            return 'EMPTY';
        }
        printf("  [PASS] %s (val=%s, rows=%d)\n", $label, number_format($vol['val'], 2, '.', ''), $vol['rows']);
        return 'PASS';
    }

    $diffs = o14DiffPayloads($normA, $normB);
    if ($allowRetry && o14IsStalenessOnly($diffs)) {
        echo "  [RETRY] $label -- diffs solo en disponible/hold/derivados (firma de staleness: " . count($diffs) . " campos); repurgando y reintentando UNA vez\n";
        o14PurgeKey($dbConnect, $prov);
        return o14ParidadCombo($dbConnect, $prov, $tab, $filtros, $label, $fails, false);
    }

    echo "  [FAIL] $label -- DIFF ESTRUCTURAL DETECTADO:\n";
    foreach (array_slice($diffs, 0, 15) as $d) echo "     $d\n";
    if (count($diffs) > 15) echo "     ... (" . (count($diffs) - 15) . " diffs mas, truncado)\n";
    $fails[] = $label;
    return 'FAIL';
}

// ------------------------------------------------------------------
// Parte B — medición: 1a carga (cache-miss -> materialize) vs filtro (cache-hit), y el camino
// viejo (nocache, oráculo) como referencia "antes". Todo para tab=c (el tab del pain de ~29s).
// ------------------------------------------------------------------
function o14MedirLatencia($dbConnect, string $prov, string $tab, string $filtroKey, string $filtroVal): array {
    $key = o14CacheKey($prov, '2025-01-01', date('Y-m-d'));
    $del = sqlsrv_query($dbConnect, "DELETE FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=?", [$key]);
    if ($del !== false) sqlsrv_free_stmt($del);

    $t0 = microtime(true);
    $r1 = o14CallEndpoint($prov, 'tab=' . $tab);
    $t1 = microtime(true);

    $t2 = microtime(true);
    $r2 = o14CallEndpoint($prov, 'tab=' . $tab . '&' . $filtroKey . '=' . rawurlencode($filtroVal));
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
// simultáneos (proc_open) contra el MISMO endpoint/tab/proveedor. El diseño del sistema
// (ensureO14CacheBase con sp_getapplock + double-checked locking, ver api/lib_o14_cache.php)
// garantiza que CUALQUIER request — sea el que gana el lock y materializa, sea el que espera —
// solo lee o14_cache_base DESPUÉS de que ensureO14CacheBase retorna true (cache fresco
// committeado). No existe código de lectura que se ejecute antes de ese gate. Por lo tanto: si
// el lector "pierde" la carrera por el applock, DEBE bloquear hasta que el materializador
// commitee (nunca debe responder antes ni con un set parcial). Verificamos esto comparando:
// (a) ambas respuestas deben ser IDÉNTICAS (mismo snapshot de o14_cache_base, materializado una
//     sola vez); si uno hubiera leído un set parcial, diferiría del otro.
// (b) ambas no-vacías (rows>0), y el tiempo total del que "pierde" la carrera es del orden del
//     tiempo de materialize completo (no near-zero, lo que delataría un bypass del gate).
// ------------------------------------------------------------------
function o14CountRows(string $tab, array $payload): int {
    return o14VolumeSignal($tab, $payload)['rows'];
}

function o14TestConcurrencia($dbConnect, string $prov, string $tab, int $repeats = 3): array {
    $runner = __DIR__ . '/_o14_endpoint_run.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg('tab=' . $tab);

    $key = o14CacheKey($prov, '2025-01-01', date('Y-m-d'));
    $results = [];
    for ($i = 1; $i <= $repeats; $i++) {
        $del = sqlsrv_query($dbConnect, "DELETE FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=?", [$key]);
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
        $rows1 = $ok1 ? o14CountRows($tab, $j1) : -1;
        $rows2 = $ok2 ? o14CountRows($tab, $j2) : -1;
        $equal = $ok1 && $ok2 && (o14NormalizeForCompare($j1) === o14NormalizeForCompare($j2));

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
function o14RunParidadFull($dbConnect): int {
    // Gating por env (para ejecución fragmentada dentro de límites de timeout de CLI/CI; sin env
    // se corre la matriz completa + concurrencia + medición desde el único comando --paridad):
    //   O14_PROVS="BELTRANY SAS,BRAHMA CONCEPT"  -> restringe la matriz a esos proveedores
    //   O14_SKIP_CONC=1                          -> omite Parte B (concurrencia)
    //   O14_SKIP_MED=1                           -> omite Parte C (medición)
    $onlyProvs = getenv('O14_PROVS');
    $onlyProvs = ($onlyProvs !== false && $onlyProvs !== '') ? array_map('trim', explode(',', $onlyProvs)) : null;
    $skipConc  = getenv('O14_SKIP_CONC') === '1';
    $skipMed   = getenv('O14_SKIP_MED') === '1';
    // 3er proveedor (además de BELTRANY SAS y BRAHMA CONCEPT del brief): DISANDINA S.A.,
    // elegido tras verificar datos reales de O14 (siembra/disponible/hold, NO ventas —
    // O14 es foto de inventario actual, no depende de ventas como G00). Investigación
    // (tests/_o14_endpoint_run.php tab=filtros + spot-check tab=b filtrado):
    //   nrefs=1150, siembra=208 filas, disponible=144 filas, hold=1 fila (todas > 0, NO vacuo,
    //   lección DISANDINA de G00 respetada).
    //   marca: OAKLEY(186) QUIKSILVER(52) ROXY(43) STANCE(40) ALPINESTARS(25) -- diversa.
    //   grupo: AKA(332) BODEGA(14) -- AKA reduce (excluye BODEGA).
    //   talla: UNICA(25) confirmado reduce (n_filas 132->27).
    // BELTRANY SAS y BRAHMA CONCEPT son proveedores MONO-MARCA (100% FILA / 100% BRAHMA
    // respectivamente, verificado): el filtro marca=<su única marca> NO reduce para ellos (es
    // un filtro de "universo completo", documentado abajo con marca_reduce=false) -- sigue
    // siendo una comparación de paridad válida (no vacua), solo no ejercita la poda de REF.
    // grupo='AKA' SÍ reduce para BELTRANY (hold 31->28, Task 3) y BRAHMA (n_filas 1265->269).
    // talla verificada reduce en los 3 (BELTRANY '10': 69->33 filas; BRAHMA 'S': 1265->445;
    // DISANDINA 'UNICA': 132->27).
    $provInfo = [
        'BELTRANY SAS'   => ['marca' => 'FILA',   'grupo' => 'AKA', 'talla' => '10',    'marca_reduce' => false],
        'BRAHMA CONCEPT' => ['marca' => 'BRAHMA', 'grupo' => 'AKA', 'talla' => 'S',     'marca_reduce' => false],
        'DISANDINA S.A.' => ['marca' => 'OAKLEY', 'grupo' => 'AKA', 'talla' => 'UNICA', 'marca_reduce' => true],
    ];
    $tabs = ['b', 'c', 'reco'];
    $fails = [];
    $empties = [];
    $total = 0;

    echo "==== PARTE A -- MATRIZ: 3 proveedores x 4 filtros x 3 tabs (cache purgado+rematerializado fresco por proveedor) ====\n";
    foreach ($provInfo as $prov => $info) {
        if ($onlyProvs !== null && !in_array($prov, $onlyProvs, true)) continue;
        echo "\n-- Proveedor: $prov -- purgando cache_key (forzar rematerialize fresco en la 1a combinacion) --\n";
        o14PurgeKey($dbConnect, $prov);
        $noReduceNote = $info['marca_reduce'] ? '' : ' [no-reduce esperado: proveedor mono-marca]';
        $filtroSets = [
            'sin filtro'                              => [],
            "marca={$info['marca']}{$noReduceNote}"   => ['marca' => $info['marca']],
            "grupo={$info['grupo']}"                  => ['grupo' => $info['grupo']],
            "talla={$info['talla']}"                  => ['talla' => $info['talla']],
        ];
        foreach ($filtroSets as $fLabel => $filtros) {
            foreach ($tabs as $tab) {
                $label = "$prov | tab=$tab | $fLabel";
                $total++;
                $status = o14ParidadCombo($dbConnect, $prov, $tab, $filtros, $label, $fails);
                if ($status === 'EMPTY') $empties[] = $label;
            }
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

    if ($skipConc) { echo "\n==== PARTE B -- CONCURRENCIA: OMITIDA (O14_SKIP_CONC=1) ====\n"; }
    else {
    echo "\n==== PARTE B -- CONCURRENCIA LECTOR-DURANTE-REBUILD (BRAHMA CONCEPT, tab=c, 3 repeticiones) ====\n";
    $conc = o14TestConcurrencia($dbConnect, 'BRAHMA CONCEPT', 'c', 3);
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

    if ($skipMed) { echo "\n==== PARTE C -- MEDICION: OMITIDA (O14_SKIP_MED=1) ====\n"; }
    else {
    echo "\n==== PARTE C -- MEDICION (tab=c; ANTES=nocache/oraculo vs AHORA=cache 1a-carga/filtro) ====\n";
    foreach (['BELTRANY SAS' => $provInfo['BELTRANY SAS']['grupo'], 'BRAHMA CONCEPT' => $provInfo['BRAHMA CONCEPT']['grupo']] as $prov => $grupo) {
        $t0 = microtime(true);
        $rOld = o14CallEndpoint($prov, 'tab=c&nocache=1');
        $tOld = round((microtime(true) - $t0) * 1000);
        $m = o14MedirLatencia($dbConnect, $prov, 'c', 'grupo', $grupo);
        printf(
            "[%s] ANTES (nocache/oraculo, tab=c): %d ms (ok=%s) || AHORA cache 1a-carga (cache-miss->materialize): %d ms (ok=%s) | filtro grupo=%s (cache-hit): %d ms (ok=%s)\n",
            $prov, $tOld, ($rOld['ok'] ?? false) ? 'si' : 'NO',
            $m['first_load_ms'], $m['first_ok'] ? 'si' : 'NO',
            $grupo, $m['filter_ms'], $m['filter_ok'] ? 'si' : 'NO'
        );
    }
    }

    echo "\n";
    if ($fails) {
        echo "PARIDAD O14 FAIL\n";
        return 1;
    }
    echo "PARIDAD O14 OK\n";
    return 0;
}
