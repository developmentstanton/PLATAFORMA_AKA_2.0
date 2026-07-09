<?php
/**
 * Tests del cache en disco de O14 tab=c. Modo por defecto: primitivas puras (sin DB).
 *   php tests/verificar_o14c_payload.php            # primitivas (Task 1)
 *   php tests/verificar_o14c_payload.php --paridad  # paridad + frescura (Task 2/3, requiere DB)
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../api/lib_o14_cache.php';       // O14_CACHE_TTL_MIN
require __DIR__ . '/../api/lib_o14c_payload.php';    // SUT

$fail = 0;
function check($cond, $msg) { global $fail; echo ($cond ? "OK  " : "FAIL") . "  $msg\n"; if (!$cond) $fail++; }

if (($argv[1] ?? '') === '--paridad') { require __DIR__ . '/_o14c_paridad.php'; exit(o14cRunParidad()); }

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
$orphan = o14cCacheDir() . '/o14c_orphan' . getmypid() . '.json.gz.tmp.999';
file_put_contents($orphan, 'x');
touch($orphan, time() - (O14_CACHE_TTL_MIN + 5) * 60);
o14cCleanup();
check(!is_file($orphan), 'cleanup borra .tmp huerfano viejo');
@unlink($orphan);

echo $fail ? "\n$fail FALLO(S)\n" : "\nTODO OK\n";
exit($fail ? 1 : 0);
