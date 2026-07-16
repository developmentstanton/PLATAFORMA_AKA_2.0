<?php
// Regresión del TTL de limpieza del cache en disco (evol + o45): el barrido NO debe borrar archivos
// que siguen FRESCOS por stamp, pero SÍ debe barrer los abandonados.
//
// Bug (2026-07-16): evolCleanup() usaba EVOL_CACHE_TTL_MIN (120 min), que es la vida de
// evol_cache_base en la BD — otra cosa. Al pasar evol a frescura por stamp de fuente (que dura
// todo el día, como o45), ese barrido de 2h quedó inconsistente con su propio modelo: el miss de
// CUALQUIER proveedor corría evolCleanup() y borraba los .json.gz de los demás con >2h, aunque
// evolDiskFresh() los diera por válidos → rebuild de ~40s para reconstruir un payload idéntico.
// Demostrado: archivo fresco (evolDiskFresh=SI) + mtime -3h + evolCleanup() => BORRADO.
// o45 ya tenía O45_DISK_TTL_MIN=1500 (~25h) justo para que el archivo del día sobreviva.
//
//   php tests/disk_ttl_test.php
//
// Sin BD: escribe entradas sintéticas con un stamp inventado y comprueba el contrato del barrido.

require_once __DIR__ . '/../api/lib_evol_disk.php';
require_once __DIR__ . '/../api/lib_o45_disk.php';

$HORA = 3600;
$casos = [   // prefijo => [fn de limpieza, constante del TTL de DISCO]
    'evol' => ['evolCleanup', defined('EVOL_DISK_TTL_MIN') ? EVOL_DISK_TTL_MIN : EVOL_CACHE_TTL_MIN],
    'o45'  => ['o45Cleanup',  O45_DISK_TTL_MIN],
];

$fallos = []; $limpiar = [];
echo "TTL DE LIMPIEZA DEL CACHE EN DISCO\n" . str_repeat('=', 70) . "\n";

foreach ($casos as $pref => [$cleanupFn, $ttlMin]) {
    $stamp = 'stamp-de-prueba-' . $pref;
    // (a) FRESCO pero de hace 3h: el usuario del día que aún debe servirse desde disco.
    $kFresco = 'ttltest_fresco_' . $pref;
    // (b) ABANDONADO: mucho más viejo que el TTL. El barrido SÍ debe llevárselo.
    $kViejo  = 'ttltest_viejo_' . $pref;

    foreach ([$kFresco, $kViejo] as $k) {
        diskCacheWrite($pref, $k, json_encode(['ok' => true, 'test' => $k]), $stamp);
        $limpiar[] = [$pref, $k];
    }
    touch(diskCachePath($pref, $kFresco), time() - 3 * $HORA);
    touch(diskCacheStampPath($pref, $kFresco), time() - 3 * $HORA);
    touch(diskCachePath($pref, $kViejo), time() - (int)($ttlMin * 60) - 2 * $HORA);
    touch(diskCacheStampPath($pref, $kViejo), time() - (int)($ttlMin * 60) - 2 * $HORA);
    clearstatcache();   // PHP cachea filemtime; sin esto el barrido lee mtimes viejos

    $frescoAntes = diskCacheFresh($pref, $kFresco, $stamp);
    $cleanupFn();
    clearstatcache();
    $sobrevive = is_file(diskCachePath($pref, $kFresco));
    $barrido   = !is_file(diskCachePath($pref, $kViejo));

    printf("[%-4s] TTL de disco = %d min (%.1f h)\n", $pref, $ttlMin, $ttlMin / 60);
    printf("       fresco por stamp (mtime -3h)   : %s\n", $frescoAntes ? 'SI' : 'NO');
    printf("       sobrevive al barrido           : %s\n", $sobrevive ? 'SI' : 'NO  <-- borra datos VÁLIDOS');
    printf("       abandonado (mtime -%dh) barrido : %s\n\n", (int)($ttlMin / 60) + 2, $barrido ? 'SI' : 'NO');

    if (!$frescoAntes) $fallos[] = "$pref: el caso de prueba no quedó fresco (test mal montado)";
    elseif (!$sobrevive) $fallos[] = "$pref: el barrido borró un archivo FRESCO de 3h — el TTL de disco ($ttlMin min) contradice la frescura por stamp, que dura todo el día";
    if (!$barrido) $fallos[] = "$pref: el barrido NO se llevó un archivo abandonado (el cleanup dejó de limpiar)";
}

foreach ($limpiar as [$p, $k]) { @unlink(diskCachePath($p, $k)); @unlink(diskCacheStampPath($p, $k)); }

echo str_repeat('=', 70) . "\n";
if ($fallos) { foreach ($fallos as $f) echo "FALLO: $f\n"; exit(1); }
echo "OK: el barrido respeta lo fresco y se lleva lo abandonado, en evol y o45.\n";
exit(0);
