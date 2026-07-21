<?php
/**
 * Tests del cache en disco de O14 tab=c. Modo por defecto: primitivas puras (sin DB).
 *   php tests/verificar_o14c_payload.php            # primitivas (Task 1)
 *   php tests/verificar_o14c_payload.php --paridad  # paridad + frescura (Task 2/3, requiere DB)
 *   php tests/verificar_o14c_payload.php --e2e      # wiring del endpoint: disco vs vivo por HTTP (Task 3)
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../api/lib_o14_cache.php';       // O14_CACHE_TTL_MIN
require __DIR__ . '/../api/lib_o14c_payload.php';    // SUT

$fail = 0;
function check($cond, $msg) { global $fail; echo ($cond ? "OK  " : "FAIL") . "  $msg\n"; if (!$cond) $fail++; }

if (($argv[1] ?? '') === '--paridad') { require __DIR__ . '/_o14c_paridad.php'; exit(o14cRunParidad()); }
if (($argv[1] ?? '') === '--e2e') { require __DIR__ . '/_task4_paridad_o14.php'; exit(o14cRunE2E()); }

/**
 * E2E (Task 3): prueba el CABLEADO del corto-circuito de disco en api/informe_o14.php tab=c
 * sin filtro. No re-verifica la lógica de negocio de o14cBuildPayloadC (eso es Task 2,
 * --paridad); verifica que el endpoint REAL, servido dos veces, produce el mismo payload por
 * el camino disco (?tab=c) y por el camino vivo (?tab=c&nocache=1) -- y que el archivo de
 * disco efectivamente se escribió (prueba de que el corto-circuito corrió, no un fall-through
 * silencioso al camino de filas de abajo).
 * Reusa o14CallEndpoint/o14NormalizeForCompare/o14DiffPayloads/o14IsStalenessOnly de
 * tests/_task4_paridad_o14.php (mismo oráculo que --paridad de verificar_o14_cache.php).
 */
function o14cRunE2E(): int {
    require __DIR__ . '/../conexion/conexion_integracion.php';
    require __DIR__ . '/../api/lib_refs.php';
    if ($dbConnect === false) { echo "SKIP: sin DB\n"; return 0; }
    $conn = $dbConnect;
    $fail = 0;
    $chk = function ($cond, $msg) use (&$fail) { echo ($cond ? "OK  " : "FAIL") . "  $msg\n"; if (!$cond) $fail++; };
    $prov  = 'BELTRANY SAS';
    $desde = '2025-01-01'; $hasta = date('Y-m-d');
    $key   = o14CacheKey($prov, $desde, $hasta);

    // --- setup: purgar DB key + borrar cualquier disco existente para forzar un MISS limpio ---
    o14PurgeKey($conn, $prov);
    @unlink(o14cPayloadPath($key)); @unlink(o14cStampPath($key));
    $chk(!is_file(o14cPayloadPath($key)), "setup: sin .json.gz previo para $prov");

    // --- llamada 1: disco (MISS -> materialize -> escribe -> sirve) ---
    $t0 = microtime(true);
    $rDisco = o14CallEndpoint($prov, 'tab=c');
    $tDisco = round((microtime(true) - $t0) * 1000);
    $chk(is_array($rDisco) && ($rDisco['ok'] ?? false) === true, "disco (tab=c): ok:true ({$tDisco}ms)");
    $chk(is_file(o14cPayloadPath($key)), 'disco: o14cPayloadPath(key) existe tras la llamada (corto-circuito ejecuto, no fall-through)');
    $chk(is_file(o14cStampPath($key)), 'disco: .stamp existe tras la llamada');

    // --- llamada 2: vivo (oráculo, nocache=1) ---
    $rVivo = o14CallEndpoint($prov, 'tab=c&nocache=1');
    $chk(is_array($rVivo) && ($rVivo['ok'] ?? false) === true, 'vivo (tab=c&nocache=1): ok:true');

    // --- paridad disco vs vivo (mismo oráculo/normalizador que --paridad) ---
    if (is_array($rDisco) && is_array($rVivo)) {
        $normA = o14NormalizeForCompare($rDisco);
        $normB = o14NormalizeForCompare($rVivo);
        if ($normA === $normB) {
            $chk(true, 'paridad disco vs vivo: payloads normalizados IDENTICOS');
        } else {
            $diffs = o14DiffPayloads($normA, $normB);
            if (o14IsStalenessOnly($diffs)) {
                echo "  [INFO] diffs solo en disponible/hold/derivados (firma de staleness, tolerado): " . count($diffs) . " campos\n";
                $chk(true, 'paridad disco vs vivo: solo staleness disponible/hold (tolerado)');
            } else {
                $chk(false, 'paridad disco vs vivo: DIFF ESTRUCTURAL');
                foreach (array_slice($diffs, 0, 15) as $d) echo "     $d\n";
            }
        }
    }

    // --- llamada 3: disco de nuevo, debe ser un HIT (rapido, sin re-materializar) ---
    $t2 = microtime(true);
    $rDisco2 = o14CallEndpoint($prov, 'tab=c');
    $tDisco2 = round((microtime(true) - $t2) * 1000);
    $chk(is_array($rDisco2) && ($rDisco2['ok'] ?? false) === true, "disco 2a llamada (HIT esperado): ok:true ({$tDisco2}ms)");

    // --- llamada filtrada: el camino filtrado (no-sin-filtros) debe seguir funcionando ---
    $rFiltrado = o14CallEndpoint($prov, 'tab=c&marca=FILA');
    $chk(is_array($rFiltrado) && ($rFiltrado['ok'] ?? false) === true, "filtrado (tab=c&marca=FILA): ok:true");

    // --- cleanup ---
    @unlink(o14cPayloadPath($key)); @unlink(o14cStampPath($key));

    echo $fail ? "\n$fail FALLO(S)\n" : "\nPARIDAD E2E OK\n";
    return $fail ? 1 : 0;
}

// --- roundtrip gzip + atomicidad ---
$key = 'testkey' . getmypid();
$json = json_encode(['ok' => true, 'grupos' => [['grupo' => 'X', 'almacenes' => []]], 'tallas' => ['34','36']]);
check(o14cWritePayload($key, $json, '2026-07-09T03:00:00'), 'write devuelve true');
check(is_file(o14cPayloadPath($key)), 'existe .json.gz');
check(is_file(o14cStampPath($key)), 'existe .stamp');
check(file_get_contents(o14cStampPath($key)) === '2026-07-09T03:00:00', 'stamp persistido');
$gz = o14cReadPayload($key);
check($gz !== null, 'read devuelve bytes');
check(gzdecode($gz) === $json, 'gzdecode == json original');
check(!is_file(o14cPayloadPath($key) . '.tmp.' . getmypid()), 'no queda .tmp');
// lectura de key inexistente
check(o14cReadPayload('noexiste' . getmypid()) === null, 'read de key inexistente = null');
// limpieza
@unlink(o14cPayloadPath($key)); @unlink(o14cStampPath($key));

// --- cleanup barre .tmp.* huérfanos viejos (fuga de proceso muerto entre write y rename) ---
// Se envejecen contra O14C_DISK_TTL_MIN (el TTL que gobierna el barrido de DISCO), NO contra
// O14_CACHE_TTL_MIN, que es la vida de o14_cache_base en la BD — confundirlas fue el bug de 2026-07-21.
$orphan = o14cCacheDir() . '/o14c_orphan' . getmypid() . '.json.gz.tmp.999';
file_put_contents($orphan, 'x');
touch($orphan, time() - (O14C_DISK_TTL_MIN + 5) * 60);
// --- cleanup barre .lock viejos (uno por cache_key/dia, se acumulan) ---
$lock = o14cCacheDir() . '/o14c_orphan' . getmypid() . '.json.gz.lock';
file_put_contents($lock, '');
touch($lock, time() - (O14C_DISK_TTL_MIN + 5) * 60);
o14cCleanup();
check(!is_file($orphan), 'cleanup borra .tmp huerfano viejo');
check(!is_file($lock), 'cleanup borra .lock viejo');
@unlink($orphan); @unlink($lock);

echo $fail ? "\n$fail FALLO(S)\n" : "\nTODO OK\n";
exit($fail ? 1 : 0);
