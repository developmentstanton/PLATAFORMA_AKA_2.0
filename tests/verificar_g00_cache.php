<?php
/**
 * Test de paridad de lib_g00_cache.php / api/informe_g00.php.
 *  - Task 2: smoke del cache de ventas (ensureG00CacheVentas).
 *  - Task 3: paridad de siembra — countTiendasSiembraCache (cache, SUT real de
 *    lib_g00_cache.php) vs countTiendasSiembraVivoTest (oráculo en vivo), sin filtro y con
 *    1 filtro de marca, MISMO proveedor.
 *  - Task 5 (--paridad): paridad EXTREMO A EXTREMO del endpoint completo
 *    (api/informe_g00.php) — cache-first (?tab=X) vs vivo (?tab=X&nocache=1) — para la
 *    matriz 3 proveedores x 4 tabs x 3 filtros + los casos mandatorios de periodos/retail/
 *    desde-custom (ver g00RunParidadFull() más abajo). Además mide latencia (cache-miss vs
 *    cache-hit) y, opcionalmente, concurrencia del materialize.
 *
 * countTiendasSiembraCache vive en api/lib_g00_cache.php (requireable) y se llama acá
 * DIRECTAMENTE — es el SUT real, no una copia. countTiendasSiembra (el oráculo en vivo)
 * sigue viviendo en api/informe_g00.php, que NO se puede `require` desde CLI: ese script
 * tiene efectos de lado (session_start, chequeo de auth con `exit`) que abortan cualquier
 * script que lo incluya (mismo problema documentado en la cabecera de lib_g00_cache.php
 * para cteVentas()/g00CteVentasCache()). Por eso el oráculo SÍ se duplica acá a propósito
 * (countTiendasSiembraVivoTest): es un cálculo independiente en vivo, legítimo como oráculo;
 * si countTiendasSiembra cambia en informe_g00.php, replicar el cambio en esta copia.
 * Para --paridad el "oráculo" es distinto: en vez de duplicar código, se DRIVEA el endpoint
 * real (tests/_endpoint_run.php, sesión simulada) dos veces por combinación — con y sin
 * `nocache=1` — y se comparan las respuestas JSON completas. Ningún código de negocio se
 * duplica; se compara el sistema real contra sí mismo en sus dos rutas.
 *
 * Uso:
 *   php tests/verificar_g00_cache.php ["PROVEEDOR"]   — smoke (Task 2) + siembra (Task 3)
 *   php tests/verificar_g00_cache.php --paridad       — matriz completa (Task 5)
 */
error_reporting(E_ERROR|E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_g00_cache.php';   // <-- SUT
require __DIR__ . '/../api/lib_g00_rango.php';

if (($argv[1] ?? '') === '--paridad') {
    require __DIR__ . '/_task5_paridad.php';
    exit(g00RunParidadFull($dbConnect));
}

$prov = $argv[1] ?? 'BH BRANDS SAS';
$anioA = (int)date('Y'); $anioB = $anioA - 1;
$desde = "$anioA-01-01"; $hasta = date('Y-m-d', strtotime('-1 day'));

$exitCode = 0;

buildRefsFromMat($dbConnect, $prov);

// ---- Task 2: smoke del cache de ventas ----
$key = g00CacheKey($prov, $anioA, $anioB, $desde, $hasta);
$ok  = ensureG00CacheVentas($dbConnect, $key, $anioB . '-01-01', $hasta);

$st = sqlsrv_query($dbConnect, "SELECT COUNT(*) c FROM INTEGRACION.dbo.g00_cache_ventas WHERE cache_key=?", [$key]);
$n  = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['c'];
sqlsrv_free_stmt($st);

if ($ok && $n > 0) {
    echo "SMOKE OK filas=$n key=$key\n";
} else {
    echo "SMOKE FAIL ok=" . var_export($ok, true) . " filas=$n\n";
    $exitCode = 1;
}

// ---- Task 3: paridad de siembra (cache vs vivo) ----

function testRunLocal($conn, $sql, $params = []) {
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) return ['error' => sqlsrv_errors()];
    $rows = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
    sqlsrv_free_stmt($stmt);
    return $rows;
}

// Copia EXACTA de countTiendasSiembra (api/informe_g00.php:167-190) — oráculo en vivo.
function countTiendasSiembraVivoTest($conn, $filtroExtra, $paramsExtra) {
    $sql = "
      SELECT COUNT(DISTINCT v.bodega) AS n
      FROM (
        SELECT rtrim(f150_id) bodega, rtrim(f120_referencia) REFERENCIA,
               rtrim(f121_id_ext1_detalle) COLOR, rtrim(f121_id_ext2_detalle) TALLA,
               SUM(CAST(f400_cant_nivel_min_1 AS int)) q
        FROM stanton.dbo.t400_cm_existencia
         INNER JOIN stanton.dbo.t150_mc_bodegas           ON f150_rowid = f400_rowid_bodega
         INNER JOIN stanton.dbo.t121_mc_items_extensiones ON f121_rowid = f400_rowid_item_ext
         INNER JOIN stanton.dbo.t120_mc_items             ON f120_rowid = f121_rowid_item
        WHERE (f400_cant_nivel_min_1>0 OR f400_cant_nivel_pedido>0) AND f120_referencia<>'GIFTCARD'
        GROUP BY rtrim(f150_id), rtrim(f120_referencia), rtrim(f121_id_ext1_detalle), rtrim(f121_id_ext2_detalle)
      ) v
      INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
      LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.bodega AND b.CIA = 7
      WHERE v.q > 0
        AND ISNULL(b.GRUPO,'') NOT IN ('BODEGA','ADMINISTRATIVAS')
      $filtroExtra
    ";
    $r = testRunLocal($conn, $sql, $paramsExtra);
    if (isset($r['error'])) return null;
    return (int)($r[0]['n'] ?? 0);
}

$skey = g00SiembraKey($prov);
$okS  = ensureG00CacheSiembra($dbConnect, $skey);
if (!$okS) {
    echo "SIEMBRA FAIL ensureG00CacheSiembra=false\n";
    exit(1);
}

// (a) sin filtro
$viaCacheA = countTiendasSiembraCache($dbConnect, $skey, '', []);
$enVivoA   = countTiendasSiembraVivoTest($dbConnect, '', []);
if ($viaCacheA !== null && $viaCacheA === $enVivoA) {
    echo "SIEMBRA OK ($viaCacheA)\n";
} else {
    echo "SIEMBRA DIFF cache=" . var_export($viaCacheA, true) . " vivo=" . var_export($enVivoA, true) . "\n";
    $exitCode = 1;
}

// (b) con 1 filtro de marca (tomamos la marca con más referencias de #refs para este
// proveedor, para que el filtro sea no-trivial en vez de una marca casi vacía)
$stM = sqlsrv_query($dbConnect, "SELECT TOP 1 MARCA FROM #refs WHERE MARCA IS NOT NULL AND MARCA <> '' GROUP BY MARCA ORDER BY COUNT(*) DESC");
$marcaRow = $stM !== false ? sqlsrv_fetch_array($stM, SQLSRV_FETCH_ASSOC) : null;
if ($stM !== false) sqlsrv_free_stmt($stM);
$marca = $marcaRow['MARCA'] ?? null;

if ($marca === null) {
    echo "SIEMBRA SKIP (sin marca disponible en #refs para filtrar)\n";
} else {
    $filtroExtra = "AND i.MARCA IN (?)";
    $paramsExtra = [$marca];
    $viaCacheB = countTiendasSiembraCache($dbConnect, $skey, $filtroExtra, $paramsExtra);
    $enVivoB   = countTiendasSiembraVivoTest($dbConnect, $filtroExtra, $paramsExtra);
    if ($viaCacheB !== null && $viaCacheB === $enVivoB) {
        echo "SIEMBRA OK marca=$marca ($viaCacheB)\n";
    } else {
        echo "SIEMBRA DIFF marca=$marca cache=" . var_export($viaCacheB, true) . " vivo=" . var_export($enVivoB, true) . "\n";
        $exitCode = 1;
    }
}

exit($exitCode);
