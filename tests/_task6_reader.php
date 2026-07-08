<?php
/**
 * Helper de Task 6 (fix torn-read del gate de frescura). Corre en su PROPIO proceso PHP CLI
 * (su propia conexión $dbConnect) el CAMINO DE LECTOR del endpoint G00, para el escenario
 * "lector durante rebuild en vuelo":
 *
 *   ensureG00CacheVentas($conn,$key,...)   // el gate g00CacheFresco vive DENTRO de ensure
 *   luego lee el ROW-SET que serviría la pestaña (COUNT(*) por cache_key, NOLOCK — igual
 *   que las lecturas de pestaña de api/informe_g00.php).
 *
 * Imprime exactamente:  SERVED <n>   (n = filas que el lector serviría para esta key)
 *
 * Se lanza con proc_open ~400ms DESPUÉS de arrancar un materialize completo (proceso Y, ver
 * tests/_task5_concurrent_materialize.php) contra la MISMA key, para caer a mitad del rebuild.
 * Con el gate arreglado (READPAST) el ensure de Z ve "no fresco", bloquea en el applock hasta
 * que Y comitea, re-chequea (ya commiteado), y su lectura NOLOCK posterior pega el set COMPLETO.
 * Con el gate viejo (NOLOCK) Z veía filas sin-commitear -> saltaba ensure -> servía un set PARCIAL.
 *
 * Uso: php tests/_task6_reader.php "PROVEEDOR" "cache_key" "desde" "hasta"
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_g00_cache.php';

if ($dbConnect === false) { echo "SERVED -1\n"; exit(1); }

$prov  = $argv[1] ?? '';
$key   = $argv[2] ?? '';
$desde = $argv[3] ?? '';
$hasta = $argv[4] ?? '';

// #refs por si Z gana el applock y debe reconstruir (mismo pre-requisito que el endpoint).
if (!buildRefsFromMat($dbConnect, $prov)) { echo "SERVED -2\n"; exit(1); }

// Camino del endpoint: SIEMPRE llama ensure (el gate de frescura está dentro). Con el fix,
// esto bloquea en el applock si hay rebuild en vuelo y retorna recién con datos commiteados.
$ok = ensureG00CacheVentas($dbConnect, $key, $desde, $hasta);
if (!$ok) { echo "SERVED -3\n"; exit(1); }

// Lectura de pestaña (NOLOCK, idéntico a api/informe_g00.php): el row-set que serviría Z.
$st = sqlsrv_query(
    $dbConnect,
    "SELECT COUNT(*) n FROM INTEGRACION.dbo.g00_cache_ventas WITH (NOLOCK) WHERE cache_key=?",
    [$key]
);
$n = ($st !== false) ? (int) sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['n'] : -4;
if ($st !== false) sqlsrv_free_stmt($st);

echo "SERVED $n\n";
