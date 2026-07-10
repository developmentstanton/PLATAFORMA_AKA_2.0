<?php
/**
 * Cache en disco GENÉRICO (gzip) para payloads deterministas, compartido por o14c/evol/o45.
 * Puro filesystem + gzip: NO conoce la BD. La frescura se decide comparando el .stamp en disco
 * contra un $currentStamp que calcula el caller (o14c/evol: `creado`; o45: otra fuente).
 * Rutas relativas a la carpeta existente cache/. Ver spec 2026-07-09-evol-cache-disco.
 */
if (!function_exists('diskCacheDir')) {
    function diskCacheDir(): string { return __DIR__ . '/../cache'; }
    // $key/$prefix se asumen seguros como nombre de archivo (hash md5 / literal corto del caller).
    function diskCachePath(string $prefix, string $key): string { return diskCacheDir() . "/{$prefix}_{$key}.json.gz"; }
    function diskCacheStampPath(string $prefix, string $key): string { return diskCacheDir() . "/{$prefix}_{$key}.stamp"; }

    function diskCacheWrite(string $prefix, string $key, string $jsonPlano, string $stamp): bool {
        $dir = diskCacheDir();
        if (!is_dir($dir) || !is_writable($dir)) return false;   // degradar sin romper
        $gz = gzencode($jsonPlano, 6);
        if ($gz === false) return false;
        // ORDEN payload->stamp: si el stamp falla queda payload-nuevo+stamp-viejo -> fresh FALSE
        // -> rebuild (desperdicio, no incorrección). Invertir serviría árbol STALE. NO invertir.
        $p = diskCachePath($prefix,$key); $tmp = $p . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $gz) === false) { @unlink($tmp); return false; }
        if (!@rename($tmp, $p)) { @unlink($tmp); return false; }
        $ps = diskCacheStampPath($prefix,$key); $tmpS = $ps . '.tmp.' . getmypid();
        if (@file_put_contents($tmpS, $stamp) === false) { @unlink($tmpS); return false; }
        if (!@rename($tmpS, $ps)) { @unlink($tmpS); return false; }
        return true;
    }

    function diskCacheRead(string $prefix, string $key): ?string {
        $p = diskCachePath($prefix,$key);
        if (!is_file($p)) return null;
        $b = @file_get_contents($p);
        return $b === false ? null : $b;
    }

    function diskCacheFresh(string $prefix, string $key, ?string $currentStamp): bool {
        if ($currentStamp === null) return false;
        if (!is_file(diskCachePath($prefix,$key)) || !is_file(diskCacheStampPath($prefix,$key))) return false;
        $s = @file_get_contents(diskCacheStampPath($prefix,$key));
        return $s !== false && $s === $currentStamp;
    }

    function diskCacheCleanup(string $prefix, int $ttlMin): void {
        $dir = diskCacheDir(); if (!is_dir($dir)) return;
        $limite = time() - $ttlMin * 60;
        foreach (glob("$dir/{$prefix}_*.json.gz") ?: [] as $f)
            if (@filemtime($f) < $limite) { @unlink($f); @unlink(substr($f,0,-8).'.stamp'); }
        foreach (glob("$dir/{$prefix}_*.tmp.*") ?: [] as $f) if (@filemtime($f) < $limite) @unlink($f);
        foreach (glob("$dir/{$prefix}_*.lock") ?: [] as $f) if (@filemtime($f) < $limite) @unlink($f);
    }

    function diskCacheServeGz(string $gz): void {
        header('Content-Type: application/json; charset=utf-8');
        header('Vary: Accept-Encoding');
        $ae = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        $z = ini_get('zlib.output_compression');
        $zlibOn = ($z && strtolower((string)$z) !== 'off' && (string)$z !== '0');
        $recomprime = $zlibOn || in_array('ob_gzhandler', ob_list_handlers(), true);
        if (stripos($ae,'gzip') !== false && !$recomprime) { header('Content-Encoding: gzip'); echo $gz; }
        else { echo gzdecode($gz); }
    }
}
