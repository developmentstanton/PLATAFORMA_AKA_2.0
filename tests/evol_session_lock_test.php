<?php
// Regresión del BLOQUEO DE SESIÓN: una petición rápida NO debe quedar en fila detrás de una lenta
// de la misma sesión.
//
// Bug (2026-07-16, medido con Network en WMS-LAB): el manejador de sesiones de PHP mantiene un
// lock exclusivo sobre el archivo de sesión durante TODA la petición. El dashboard dispara
// tab=filtros y tab=data a la vez; filtros se quedaba con el lock ~42s y tab=data —que responde
// desde el cache en disco en 547ms— esperaba en session_start() (:9) sin poder hacer nada.
// Firma en el Network: data(42.35s) ≈ filtros(41.79s) + 0.56s. La misma URL sola: 547ms.
// Arreglo: session_write_close() apenas se leen 'usuario'/'proveedor' (ningún endpoint escribe).
//
//   php tests/evol_session_lock_test.php ["PROVEEDOR"]
//
// Golpea el endpoint por HTTP REAL (Apache local): el lock solo existe entre procesos, así que
// el harness en proceso de tests/_endpoint_run_evol.php no puede verlo.

$prov  = $argv[1] ?? 'BH BRANDS SAS';
$base  = getenv('EVOL_BASE') ?: 'http://localhost/plataforma_20/api/informe_evol.php';
$UMBRAL_MS = 3000;   // la petición cacheada sola tarda ~0.5s; 3s deja margen y sigue muy lejos de los ~30s del bloqueo

if (!function_exists('curl_multi_init')) { echo "SKIP: se requiere ext/curl.\n"; exit(0); }

// --- Sesión de prueba fabricada a mano (evita depender de credenciales de login) ---
$sid  = 'ptest' . bin2hex(random_bytes(8));
$path = ini_get('session.save_path') ?: sys_get_temp_dir();
$file = rtrim($path, "/\\") . DIRECTORY_SEPARATOR . 'sess_' . $sid;
$data = 'usuario|' . serialize('test') . 'proveedor|' . serialize($prov);
if (@file_put_contents($file, $data) === false) { echo "SKIP: no se pudo escribir la sesión en $path\n"; exit(0); }
register_shutdown_function(fn() => @unlink($file));

function mkreq(string $url, string $sid) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_COOKIE=>'PHPSESSID='.$sid, CURLOPT_TIMEOUT=>180]);
    return $ch;
}

echo "BLOQUEO DE SESIÓN — evol\nproveedor = $prov\nbase      = $base\n\n";

$mh = curl_multi_init();
// LENTA (nocache=1 -> construye #base vivo, ~30s): entra primero y se queda con el lock.
$lenta = mkreq($base . '?tab=data&nocache=1&desde=2025-01&hasta=2026-07', $sid);
curl_multi_add_handle($mh, $lenta);

// Dejar que la lenta arranque y tome el lock ANTES de disparar la rápida. Sin este adelanto, la
// rápida podría ganar la carrera por el lock y el test pasaría en verde con el bug presente.
$t0 = microtime(true); $activas = null;
do { curl_multi_exec($mh, $activas); usleep(20000); } while ($activas && (microtime(true) - $t0) < 1.0);
if (!$activas) { echo "FALLO: la petición lenta terminó en <1s; no sirve como tenedora del lock.\n"; exit(1); }

// RÁPIDA (cache en disco, ~0.5s sola). Su tiempo ES la medición del test.
$rapida = mkreq($base . '?tab=data&desde=2025-01&hasta=2026-07', $sid);
curl_multi_add_handle($mh, $rapida);
$tRapida = microtime(true);
do {
    curl_multi_exec($mh, $activas);
    curl_multi_select($mh, 0.05);
    $info = curl_multi_info_read($mh);
    if ($info && $info['handle'] === $rapida) break;
} while ($activas);
$msRapida = (int) round((microtime(true) - $tRapida) * 1000);

$cuerpo = (string) curl_multi_getcontent($rapida);
$http   = (int) curl_getinfo($rapida, CURLINFO_HTTP_CODE);
curl_multi_remove_handle($mh, $rapida);
// Drenar la lenta para no dejar la petición colgando (y de paso reportar cuánto duró).
do { curl_multi_exec($mh, $activas); curl_multi_select($mh, 0.1); } while ($activas);
$msLenta = (int) round(curl_getinfo($lenta, CURLINFO_TOTAL_TIME) * 1000);
curl_multi_remove_handle($mh, $lenta); curl_multi_close($mh);

echo sprintf("lenta  (nocache=1, tiene el lock) : %6d ms\n", $msLenta);
echo sprintf("rápida (cache en disco)           : %6d ms  http=%d  %s bytes\n\n",
    $msRapida, $http, number_format(strlen($cuerpo)));

$d = json_decode($cuerpo, true);
$fallos = [];
if ($http !== 200)                              $fallos[] = "la rápida devolvió HTTP $http";
if (!is_array($d) || !isset($d['negocios']))    $fallos[] = 'la rápida no devolvió un payload de evol válido';
if ($msRapida > $UMBRAL_MS) $fallos[] = sprintf('la rápida tardó %d ms (umbral %d ms): quedó en fila detrás de la lenta (%d ms) — la sesión sigue bloqueando', $msRapida, $UMBRAL_MS, $msLenta);

if ($fallos) { foreach ($fallos as $f) echo "FALLO: $f\n"; exit(1); }

echo sprintf("OK: la rápida respondió en %d ms mientras la lenta seguía corriendo (%d ms). Sin fila.\n", $msRapida, $msLenta);
exit(0);
