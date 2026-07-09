<?php
/**
 * Prebuild nocturno del cache en disco de O14 tab=c (árbol sin filtro) por proveedor.
 * Deja cache/o14c_<key>.json.gz + .stamp listos para que nadie pague los ~26s en vivo.
 * Correr DESPUÉS del refresh de Items_Mat y del ETL (usa hasta=hoy).
 *   php sql/prebuild_o14c.php                          # nocturno: TODOS los proveedores
 *   php sql/prebuild_o14c.php "BELTRANY SAS" "OTRO"     # solo los proveedores nombrados
 *   php sql/prebuild_o14c.php --dry-run                 # enumera y lista, no construye nada
 *   php sql/prebuild_o14c.php --dry-run "BELTRANY SAS"  # solo eco de los nombrados
 * Programar vía sql/prebuild_o14c.bat en el Programador de tareas.
 */
error_reporting(E_ERROR | E_PARSE);
$t0 = microtime(true);
require __DIR__ . '/../conexion/conexion_integracion.php';   // $dbConnect
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_login.php';
require __DIR__ . '/../api/lib_o14c_payload.php';
if ($dbConnect === false) { fwrite(STDERR, "[prebuild_o14c] Conexion DB fallida\n"); exit(1); }

$desde = '2025-01-01'; $hasta = date('Y-m-d');

// Args: --dry-run (en cualquier posición) + lista opcional de proveedores explícitos.
$dryRun = false; $explicitos = [];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') { $dryRun = true; continue; }
    $explicitos[] = $a;
}

if ($explicitos) {
    // Solo los proveedores nombrados (dedupe), sin enumerar.
    $provs = array_values(array_unique($explicitos));
} else {
    // Enumerar proveedores distintos de los usuarios del portal (resueltos como en el login).
    $provs = [];
    $st = sqlsrv_query($dbConnect, "SELECT DISTINCT nombre_usuario FROM usuarios_portal_aka WHERE nombre_usuario IS NOT NULL");
    if ($st !== false) {
        while ($u = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) {
            $r = login_resolver_proveedor($dbConnect, trim((string)$u['nombre_usuario']));
            $p = trim((string)($r['proveedor'] ?? ''));
            if ($p !== '' && $p !== '__SIN_PROVEEDOR__') $provs[$p] = true;
        }
        sqlsrv_free_stmt($st);
    }
    $provs = array_keys($provs);
}
echo "[prebuild_o14c] " . date('Y-m-d H:i:s') . " proveedores=" . count($provs) . " hasta=$hasta\n";
foreach ($provs as $p) echo "  - $p\n";

if ($dryRun) {
    echo "[prebuild_o14c] --dry-run: no se construyó nada.\n";
    sqlsrv_close($dbConnect);
    exit(0);
}

$okN = 0; $failN = 0;
foreach ($provs as $prov) {
    $tp = microtime(true);
    if (!buildRefsFromMat($dbConnect, $prov)) { echo "  FALLO refs: $prov\n"; $failN++; continue; }
    $key = o14CacheKey($prov, $desde, $hasta);
    if (!ensureO14CacheBase($dbConnect, $key, $desde, $hasta)) { echo "  FALLO cache DB: $prov\n"; $failN++; continue; }
    $stamp = o14cCurrentStamp($dbConnect, $key);
    $payload = o14cBuildPayloadC($dbConnect, $key, $desde, $hasta);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ok = $stamp !== null && o14cWritePayload($key, $json, $stamp);
    printf("  %s %-28s grupos=%d filas_json=%.1fKB %.1fs\n", $ok?'OK  ':'FALLO', $prov,
        count($payload['grupos']), strlen($json)/1024, microtime(true)-$tp);
    $ok ? $okN++ : $failN++;
}
o14cCleanup();
printf("[prebuild_o14c] fin: OK=%d FALLO=%d en %.1fs\n", $okN, $failN, microtime(true)-$t0);
sqlsrv_close($dbConnect);
exit($failN ? 1 : 0);
