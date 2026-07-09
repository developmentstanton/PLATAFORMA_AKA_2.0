<?php
/**
 * Cache en disco del árbol O14 tab=c SIN filtro (payload determinista por cache_key).
 * Evita transferir 46k filas / ~5.5MB desde la RDS remota en cada request: se construye
 * una vez y se sirve gzip desde disco local. Ver spec 2026-07-09-o14c-arbol-cache-disco.
 * Frescura por .stamp (valor `creado` de o14_cache_base): compara timestamp-de-DB contra
 * timestamp-de-DB, sin cruzar el reloj del server PHP (Colombia) con el de la RDS (UTC).
 */
require_once __DIR__ . '/lib_o14_cache.php'; // O14_CACHE_TTL_MIN, o14CacheKey/Fresco/ensure

if (!function_exists('o14cCacheDir')) {
    function o14cCacheDir(): string { return __DIR__ . '/../cache'; }
    function o14cPayloadPath(string $key): string { return o14cCacheDir() . '/o14c_' . $key . '.json.gz'; }
    function o14cStampPath(string $key): string { return o14cCacheDir() . '/o14c_' . $key . '.stamp'; }

    function o14cWritePayload(string $key, string $jsonPlano, string $stamp): bool {
        $dir = o14cCacheDir();
        if (!is_dir($dir) || !is_writable($dir)) return false;       // degradar sin romper
        $gz = gzencode($jsonPlano, 6);
        if ($gz === false) return false;
        // escritura atómica: tmp + rename (un lector nunca ve archivo a medias)
        $tmp = o14cPayloadPath($key) . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $gz) === false) return false;
        if (!@rename($tmp, o14cPayloadPath($key))) { @unlink($tmp); return false; }
        $tmpS = o14cStampPath($key) . '.tmp.' . getmypid();
        if (file_put_contents($tmpS, $stamp) === false) return false;
        if (!@rename($tmpS, o14cStampPath($key))) { @unlink($tmpS); return false; }
        return true;
    }

    function o14cReadPayload(string $key): ?string {
        $p = o14cPayloadPath($key);
        if (!is_file($p)) return null;
        $b = @file_get_contents($p);
        return $b === false ? null : $b;
    }

    function o14cCleanup(): void {
        $dir = o14cCacheDir();
        if (!is_dir($dir)) return;
        $limite = time() - O14_CACHE_TTL_MIN * 60;
        foreach (glob($dir . '/o14c_*.json.gz') ?: [] as $f) {
            if (@filemtime($f) < $limite) { @unlink($f); @unlink(substr($f, 0, -8) . '.stamp'); }
        }
    }
}
