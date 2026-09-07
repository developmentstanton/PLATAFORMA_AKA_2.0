<?php
/**
 * API Informe G00 — Dashboard de Ventas
 *
 * Fase 1 (2026-05-11):
 *  - CTE `ventas` con pushdown de fecha (BETWEEN @gmin AND @gmax) por base table.
 *  - WITH (NOLOCK) en todas las lecturas.
 *  - Detal: 4 queries consolidadas en 1 con GROUPING SETS.
 *  - Catálogos (grupos/marcas) cacheados por proveedor en archivo.
 *
 * Fase 2 (2026-05-22) — #refs cacheado:
 *  - La vista INTEGRACION.dbo.ITEMS (17 LEFT JOIN) cuesta ~11-16s por evaluación.
 *    Se materializan las REFERENCIAs del proveedor (+ MARCA/LINEA/SUBLINEA/CATEGORIA)
 *    en cache de archivo (frescura diaria) y, por request, en una temp table #refs.
 *  - Todas las queries hacen JOIN contra #refs (159 filas típ.) en vez de la vista.
 *    Cada query de fact table baja de ~12-16s a ~2.5s. Resultados idénticos.
 *
 * Params opcionales: ?desde=YYYY-MM-DD &hasta=YYYY-MM-DD &grupo= &marca= &tab=
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/lib_g00_rango.php';

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

$proveedorSesion = $_SESSION['proveedor'] ?? '';
$proveedor = $proveedorSesion !== '' ? $proveedorSesion : '__SIN_PROVEEDOR__';
// Soltar el lock de sesión: si no, este endpoint pone en fila a las demás llamadas del dashboard
// (se disparan juntas). Ver informe_evol.php:15 y tests/evol_session_lock_test.php.
// NO leer $_SESSION después de esta línea.
session_write_close();

$desdeIn = $_GET['desde'] ?? '';
$hastaIn = $_GET['hasta'] ?? '';
$sss     = strtolower(trim($_GET['sss'] ?? 'nosame')); // S.S.S: 'same' aplica same-store; default 'nosame'
$anioBIn = (int)($_GET['anioB'] ?? 0);

if ($desdeIn && $hastaIn) {
    $desdeAct = $desdeIn;
    $hastaAct = $hastaIn;
} else {
    $desdeAct = date('Y-01-01');
    $hastaAct = date('Y-m-d');
}
$cal = strtolower(trim($_GET['cal'] ?? 'diaadia'));
if ($cal !== 'retail') $cal = 'diaadia';

// Cierre diario de ventas: el día en curso no está cerrado. Recorta la fecha final al
// día de cierre (ayer) salvo "año completo" (31-dic). Se aplica ANTES de derivar el año
// menor para que ambos periodos queden espejados. Ver g00_cap_hasta().
$hastaAct = g00_cap_hasta($hastaAct, date('Y-m-d', strtotime('-1 day')));

// Año menor a comparar: si no llega, año del periodo mayor − 1 (comportamiento por defecto).
if ($anioBIn <= 0) $anioBIn = (int)date('Y', strtotime($hastaAct)) - 1;

list($desdeAct, $hastaAct, $desdeAnt, $hastaAnt, $rangoErr) =
    g00_rango_comparacion($desdeAct, $hastaAct, $anioBIn, $cal);
if ($rangoErr !== null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El año a comparar debe ser menor que el año principal.']);
    exit;
}
$yearAct = (int)date('Y', strtotime($hastaAct)); // año mayor, para rótulos y para Mensual

// Rango global para el pushdown de fecha en el CTE.
$gmin = ($desdeAnt < $desdeAct) ? $desdeAnt : $desdeAct;
$gmax = ($hastaAct > $hastaAnt) ? $hastaAct : $hastaAnt;

require __DIR__ . '/../conexion/conexion_integracion.php';
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Conexión DB fallida']);
    exit;
}

/**
 * CTE `ventas` con pushdown de fecha. Cada query inyecta @gmin, @gmax (2 veces, una por tabla).
 * Expone COLOR y TALLA aunque casi ninguna query las re-seleccione: los filtros `v.COLOR`/
 * `v.TALLA` de $FILTROS_MULTI viven en el WHERE que lee del CTE base `ventas v`, así que basta
 * con que estén disponibles aquí (las CTEs internas como ventas_enriq/vm filtran sobre `v`).
 */
function cteVentas() {
    return "
    WITH ventas AS (
        SELECT FECHA, BODEGA, REFERENCIA, CANTIDAD, VALOR, MARGEN, COLOR, TALLA
        FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK)
        WHERE FECHA BETWEEN ? AND ?
        UNION ALL
        SELECT FECHA, BODEGA, REFERENCIA, CANTIDAD, VALOR, MARGEN, COLOR, TALLA
        FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK)
        WHERE FECHA BETWEEN ? AND ?
    )
    ";
}

// Filtros multi-valor (Inc 2A). Cada uno: WHERE <col> IN (?,?,...). Vacío = sin filtro.
// Grano referencia → alias #refs (i). Grano tienda → alias Bodegas (b).
$FILTROS_MULTI = [
    'marca'            => 'i.MARCA',
    'tipo'             => 'i.TIPO',
    'categoria'        => 'i.CATEGORIA',
    'subcategoria'     => 'i.SUBCATEGORIA',
    'genero'           => 'i.GENERO',
    'publico'          => 'i.PUBLICO_OBJETIVO',
    'referencia'       => 'i.REFERENCIA',
    'color'            => 'v.COLOR',
    'talla'            => 'v.TALLA',
    'grupo'            => 'b.GRUPO',
    'tienda'           => 'b.NOMBRE',
    'centro_comercial' => 'b.CENTRO_COMERCIAL',
    'depto'            => 'b.DEPTO',
    'ciudad'           => 'b.CIUDAD',
];
$filtroExtra = '';
$paramsExtra = [];
foreach ($FILTROS_MULTI as $key => $col) {
    $vals = $_GET[$key] ?? [];
    if (!is_array($vals)) $vals = ($vals === '' ? [] : [$vals]);
    $vals = array_values(array_filter(array_map('trim', $vals), fn($v) => $v !== ''));
    if (empty($vals)) continue;
    $ph = implode(',', array_fill(0, count($vals), '?'));
    $filtroExtra .= " AND $col IN ($ph) ";
    foreach ($vals as $v) $paramsExtra[] = $v;
}

// S.S.S (same-store): SOLO se aplica cuando ?sss=same. Compartido por todos los tabs que
// comparan año actual vs anterior (Detal consolidado, Mensual, Productos). Por defecto
// (No Same) NO filtra → todas las tiendas activas en el periodo. El EXISTS lee `v.BODEGA`,
// así que vive dentro de cualquier CTE que seleccione de `ventas v`.
$sameStoreClause = '';
$sameStoreParams = [];
if ($sss === 'same') {
    $sameStoreClause = "
          AND EXISTS (
                SELECT 1 FROM INTEGRACION.dbo.Bodegas sb WITH (NOLOCK)
                WHERE sb.COD = v.BODEGA AND sb.CIA = 7
                  AND (sb.FECHA_APERTURA IS NULL OR sb.FECHA_APERTURA <= ?)
                  AND (sb.FECHA_CIERRE   IS NULL OR sb.FECHA_CIERRE   >= ?)
          )";
    $sameStoreParams = [$desdeAnt, $hastaAct];
}

function run($conn, $sql, $params = []) {
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) return ['error' => sqlsrv_errors()];
    $rows = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
    sqlsrv_free_stmt($stmt);
    return $rows;
}

function jsonFail($rows, $conn) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Consulta fallida','detalle'=>$rows['error']]);
    sqlsrv_close($conn);
    exit;
}

/**
 * Tiendas con Siembra: # distinto de bodegas con siembra (>0) de cualquier referencia del
 * proveedor, respetando los MISMOS filtros de producto/bodega que el resto de G00
 * ($filtroExtra con aliases i=#refs, v=siembra COLOR/TALLA, b=Bodegas). Sin fecha (la siembra
 * es foto actual) ni S.S.S. Fuente idéntica a O14: stanton.dbo.t400_cm_existencia.
 * Soft-fail → null (el front muestra '—'). Requiere que #refs ya exista.
 */
function countTiendasSiembra($conn, $filtroExtra, $paramsExtra) {
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
    $r = run($conn, $sql, $paramsExtra);
    if (isset($r['error'])) return null;
    return (int)($r[0]['n'] ?? 0);
}

/**
 * Ensambla filas de un GROUPING SETS de 2 niveles en {rows:[{label,...,children:[]}], total}.
 * $gidTotal = gid de la fila grand-total (); $gidPadre = gid de la fila padre (hijo NULL).
 * $padreKey($r)→etiqueta del padre; $hijoLabel($r)→etiqueta del hijo.
 * $childSort: 'talla' (ascendente natural) | 'val' (val_act desc).
 */
function ensamblarArbolProd($rows, $gidTotal, $gidPadre, $padreKey, $hijoLabel, $childSort) {
    $met = function ($r) {
        return [
            'val_act' => (float)$r['val_act'], 'val_ant' => (float)$r['val_ant'],
            'ups_act' => (float)$r['ups_act'], 'ups_ant' => (float)$r['ups_ant'],
            'margen'  => (float)($r['margen_prom'] ?? 0),
            'tiendas_act' => (int)$r['tiendas_act'], 'tiendas_ant' => (int)$r['tiendas_ant'],
        ];
    };
    $total = ['val_act'=>0,'val_ant'=>0,'ups_act'=>0,'ups_ant'=>0,'margen'=>0,'tiendas_act'=>0,'tiendas_ant'=>0];
    $map  = [];   // key → fila padre
    $kids = [];   // key → [hijos]
    foreach ($rows as $r) {
        $gid = (int)$r['gid'];
        if ($gid === $gidTotal) { $total = $met($r); continue; }
        $key = $padreKey($r);
        if ($gid === $gidPadre) {
            $map[$key] = array_merge(['label' => $key], $met($r));
        } else {
            $kids[$key][] = array_merge(['label' => $hijoLabel($r)], $met($r));
        }
    }
    $out = [];
    foreach ($map as $key => $fila) {
        $ch = $kids[$key] ?? [];
        if ($childSort === 'talla') {
            usort($ch, function ($x, $y) {
                $nx = is_numeric($x['label']) ? (float)$x['label'] : null;
                $ny = is_numeric($y['label']) ? (float)$y['label'] : null;
                if ($nx !== null && $ny !== null) return $nx <=> $ny;
                return strcmp((string)$x['label'], (string)$y['label']);
            });
        } else {
            usort($ch, fn($x, $y) => $y['val_act'] <=> $x['val_act']);
        }
        $fila['children'] = $ch;
        $out[] = $fila;
    }
    usort($out, fn($x, $y) => $y['val_act'] <=> $x['val_act']);
    return ['rows' => $out, 'total' => $total];
}

/* getRefsCached() y buildRefsTemp() vivian aqui, duplicadas de lib_refs.php. Como lib_refs se
   protege con function_exists() y este archivo las definia ANTES de requerirlo, en G00 ganaban
   estas y se saltaban los acotes por MARCA (sql/007) y por CATEGORIA (sql/009): filtraban solo
   por PROVEEDOR. Estaba dormido porque solo se usan como respaldo si Items_Mat no existe, pero
   era una fuga de aislamiento entre aliados esperando el dia que ese respaldo entrara. Se
   borran: la unica definicion valida es la de lib_refs.php, que se requiere mas abajo. */

/**
 * Catálogos (grupos y marcas) por proveedor, cacheados en archivo TTL 12h.
 * Las marcas salen directo de #refs; los grupos requieren scan de fact tables
 * (qué grupos vendió el proveedor) joinado contra #refs.
 */
function getCatalogos($conn, $proveedor) {
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/g00_cat_' . md5($proveedor) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 43200) {
        return json_decode(file_get_contents($cacheFile), true);
    }
    $sqlGrupos = "
        SELECT DISTINCT ISNULL(b.GRUPO, 'SIN GRUPO') AS grupo
        FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI v WITH (NOLOCK)
        INNER JOIN #refs i ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.BODEGA AND b.CIA = 7
        UNION
        SELECT DISTINCT ISNULL(b.GRUPO, 'SIN GRUPO')
        FROM INTEGRACION.dbo.Ventas_Detal_PBI v WITH (NOLOCK)
        INNER JOIN #refs i ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.BODEGA AND b.CIA = 7
        ORDER BY grupo
    ";
    $sqlMarcas = "SELECT DISTINCT MARCA AS marca FROM #refs ORDER BY marca";
    $grupos = run($conn, $sqlGrupos);
    $marcas = run($conn, $sqlMarcas);
    if (isset($grupos['error']) || isset($marcas['error'])) {
        return ['grupos' => [], 'marcas' => []];
    }
    $cat = [
        'grupos' => array_values(array_map(fn($r) => $r['grupo'], $grupos)),
        'marcas' => array_values(array_map(fn($r) => $r['marca'], $marcas)),
    ];
    @file_put_contents($cacheFile, json_encode($cat));
    return $cat;
}

// --------------------------------------------------------------------
// Materializar refs del proveedor (cacheadas) en #refs para todos los tabs.
// --------------------------------------------------------------------
require_once __DIR__ . '/lib_refs.php';
if (!buildRefsFromMat($dbConnect, $proveedor)) {
    jsonFail(['error' => sqlsrv_errors()], $dbConnect);
}

$tab = $_GET['tab'] ?? 'detal';

// ====================================================================
// TAB: FILTROS — catálogo de combinaciones para la cascada (cacheado diario).
// Devuelve combos distintos (atributos de referencia + depto/ciudad de las
// bodegas donde el proveedor vendió). El front resuelve la cascada en memoria.
// ====================================================================
if ($tab === 'filtros') {
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/g00_filtros_' . md5($proveedor) . '.json';
    if (file_exists($cacheFile) && date('Y-m-d', filemtime($cacheFile)) === date('Y-m-d')) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        // Exigir 'sku' invalida de hecho cualquier caché vieja (Inc 2A, sin sku) del mismo día.
        if (is_array($cached) && isset($cached['sku'])) { sqlsrv_close($dbConnect); echo json_encode($cached, JSON_UNESCAPED_UNICODE); exit; }
    }
    // Parejas distintas (referencia, bodega) que el proveedor vendió (scan 1x/día).
    $sql = "
        SELECT DISTINCT
            i.MARCA, i.TIPO, i.CATEGORIA, i.SUBCATEGORIA, i.GENERO, i.PUBLICO_OBJETIVO,
            i.REFERENCIA,
            ISNULL(b.GRUPO,'')            AS GRUPO,
            ISNULL(b.NOMBRE,'')           AS NOMBRE,
            ISNULL(b.CENTRO_COMERCIAL,'') AS CENTRO_COMERCIAL,
            ISNULL(b.DEPTO,'')  AS DEPTO,
            ISNULL(b.CIUDAD,'') AS CIUDAD
        FROM (
            SELECT DISTINCT REFERENCIA, BODEGA FROM INTEGRACION.dbo.Ventas_Detal_PBI      WITH (NOLOCK)
            UNION
            SELECT DISTINCT REFERENCIA, BODEGA FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK)
        ) v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.BODEGA AND b.CIA = 7
    ";
    $rows = run($dbConnect, $sql);
    if (isset($rows['error'])) jsonFail($rows, $dbConnect);
    $combos = array_map(fn($r) => [
        'marca'            => trim((string)$r['MARCA']),
        'tipo'             => trim((string)$r['TIPO']),
        'categoria'        => trim((string)$r['CATEGORIA']),
        'subcategoria'     => trim((string)$r['SUBCATEGORIA']),
        'genero'           => trim((string)$r['GENERO']),
        'publico'          => trim((string)$r['PUBLICO_OBJETIVO']),
        'referencia'       => trim((string)$r['REFERENCIA']),
        'grupo'            => trim((string)$r['GRUPO']),
        'tienda'           => trim((string)$r['NOMBRE']),
        'centro_comercial' => trim((string)$r['CENTRO_COMERCIAL']),
        'depto'            => trim((string)$r['DEPTO']),
        'ciudad'           => trim((string)$r['CIUDAD']),
    ], $rows);
    // SKU del proveedor (referencia → color/talla) desde el maestro. Liviano (no escanea hechos).
    $sqlSku = "
        SELECT DISTINCT m.REFERENCIA, m.COLOR, m.TALLA
        FROM INTEGRACION.dbo.Maestro_Ref_Plataforma_AKA m WITH (NOLOCK)
        INNER JOIN #refs i ON i.REFERENCIA = m.REFERENCIA
    ";
    $rowsSku = run($dbConnect, $sqlSku);
    if (isset($rowsSku['error'])) jsonFail($rowsSku, $dbConnect);
    $sku = array_map(fn($r) => [
        'referencia' => trim((string)$r['REFERENCIA']),
        'color'      => trim((string)$r['COLOR']),
        'talla'      => trim((string)$r['TALLA']),
    ], $rowsSku);
    $payload = ['ok' => true, 'combos' => $combos, 'sku' => $sku];
    @file_put_contents($cacheFile, json_encode($payload));
    sqlsrv_close($dbConnect);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
// CACHE-FIRST (enfoque B): materializa el granular denormalizado
// (ventas ⋈ #refs ⋈ Bodegas) por (proveedor+span) en g00_cache_ventas y
// re-agrega cada tab sobre él, en vez de re-escanear las fact tables por
// request. ?nocache=1 selecciona la ruta VIVA (cteVentas ⋈ #refs ⋈ Bodegas),
// que es el oráculo de paridad y debe seguir siendo byte-idéntica a la de hoy.
// ====================================================================
require_once __DIR__ . '/lib_g00_cache.php';
$nocache = !empty($_GET['nocache']);

// --- Rango de Mensual (hoisted desde el tab Detal a scope compartido) ---
// Mensual escanea Ene→Hasta del año mayor vs su espejo menor. Ese span puede
// EXTENDERSE antes de $gmin cuando ?desde es custom (p.ej. desde=Mar: Mensual
// sigue arrancando en Ene), por lo que el cache debe cubrir la UNIÓN de ambos
// pushdowns. Se computa acá una sola vez y el tab Detal lo reutiliza.
$mensDesA = "$yearAct-01-01";
$mensHasA = $hastaAct;
if ($cal === 'retail') {
    $shiftToActualDays = 364 * ($yearAct - $anioBIn);
    $mensDesB = date('Y-m-d', strtotime($mensDesA . ' -' . $shiftToActualDays . ' days'));
    $mensHasB = $hastaAnt;
} else {
    $shiftToActualDays = 0;   // diaadia: el mes calendario ya coincide
    $mensDesB = g00_set_anio($mensDesA, $anioBIn);
    $mensHasB = $hastaAnt;
}
$mensGmin = min($mensDesB, $mensDesA);
$mensGmax = max($mensHasA, $mensHasB);
// Para 'ant' (retail) el mes se mapea sumando 364 días para que caiga en el mes
// 'act' equivalente; para 'diaadia' el mes 'ant' ya es el mismo mes calendario.
$mesAntExpr = $shiftToActualDays > 0
    ? "MONTH(DATEADD(DAY, $shiftToActualDays, FECHA))"
    : "MONTH(FECHA)";

// Span del cache = unión de TODOS los pushdowns de fecha de los tabs (main
// $gmin..$gmax y el de Mensual $mensGmin..$mensGmax). Al cargar el cache con
// este span, cada tab —filtrando por su propio predicado de fecha sobre el
// cache— ve EXACTO el mismo row-set que su ruta viva → paridad por construcción.
$cacheDesde = min($gmin, $mensGmin);
$cacheHasta = max($gmax, $mensGmax);

// Re-prefijo de los filtros a la tabla denormalizada de cache (i./v./b. → c.).
// El same-store re-prefija SOLO su correlación externa `v.BODEGA`; el alias
// interno `sb` del EXISTS debe quedar intacto (un str_replace de 'b.' lo
// rompería: 'sb.' → 'sc.').
$filtroExtraC     = str_replace(['i.', 'v.', 'b.'], 'c.', $filtroExtra);
$sameStoreClauseC = str_replace('v.', 'c.', $sameStoreClause);

// El cache queda identificado por (proveedor + span). El contenido depende SOLO
// de esos dos (los predicados act/ant y S.S.S se aplican en lectura), así que la
// key no necesita $cal ni $sss: dos requests con el mismo span comparten cache.
$ckey = g00CacheKey($proveedor, $yearAct, $anioBIn, $cacheDesde, $cacheHasta);
$skey = g00SiembraKey($proveedor);
if (!$nocache) {
    if (!ensureG00CacheVentas($dbConnect, $ckey, $cacheDesde, $cacheHasta)) jsonFail(['error' => sqlsrv_errors()], $dbConnect);
    ensureG00CacheSiembra($dbConnect, $skey);
    g00CacheCleanup($dbConnect);   // poda keys viejas (>TTL) para no crecer sin límite
}

// ====================================================================
// TAB: TIENDAS
// ====================================================================
if ($tab === 'tiendas') {
    // Resumen por tienda + negocio (REFERENCIA-COLOR), 1 query con GROUPING SETS.
    // GROUPING_ID(BODEGA, REFERENCIA, COLOR): 7=() KPIs grand total, 3=(BODEGA) tienda, 0=(BODEGA,REF,COLOR) negocio.
    // Fuente de filas: viva (cteVentas ⋈ #refs ⋈ Bodegas) o cache (g00_cache_ventas),
    // según $nocache. La agregación (SELECT/GROUP BY) es idéntica: solo cambia el origen.
    if ($nocache) {
        $vtSrc = cteVentas() . "
        , vt AS (
            SELECT v.FECHA, v.BODEGA, v.CANTIDAD, v.VALOR, v.MARGEN,
                   ISNULL(b.NOMBRE, v.BODEGA)   AS NOMBRE,
                   ISNULL(b.GRUPO, 'SIN GRUPO') AS GRUPO,
                   v.REFERENCIA,
                   ISNULL(v.COLOR, '')          AS COLOR
            FROM ventas v
            INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
            LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.BODEGA AND b.CIA = 7
            WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
              $filtroExtra
              $sameStoreClause
        )";
        $vtParams = array_merge(
            [$gmin, $gmax, $gmin, $gmax],                 // CTE pushdown
            [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt], // vt OR-filter (act, ant)
            $paramsExtra,
            $sameStoreParams
        );
    } else {
        $vtSrc = "
        WITH vt AS (
            SELECT c.FECHA, c.BODEGA, c.CANTIDAD, c.VALOR, c.MARGEN,
                   ISNULL(c.NOMBRE, c.BODEGA)   AS NOMBRE,
                   ISNULL(c.GRUPO, 'SIN GRUPO') AS GRUPO,
                   c.REFERENCIA,
                   ISNULL(c.COLOR, '')          AS COLOR
            FROM INTEGRACION.dbo.g00_cache_ventas c
            WHERE c.cache_key = ? AND (c.FECHA BETWEEN ? AND ? OR c.FECHA BETWEEN ? AND ?)
              $filtroExtraC
              $sameStoreClauseC
        )";
        $vtParams = array_merge(
            [$ckey],                                      // cache_key (reemplaza pushdown)
            [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt], // vt OR-filter (act, ant)
            $paramsExtra,
            $sameStoreParams
        );
    }
    $sql = $vtSrc . "
        SELECT
            GROUPING_ID(BODEGA, REFERENCIA, COLOR) AS gid,
            BODEGA      AS cod,
            MAX(NOMBRE) AS nombre,
            MAX(GRUPO)  AS grupo,
            REFERENCIA  AS referencia,
            COLOR       AS color,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant,
            AVG(CASE WHEN FECHA BETWEEN ? AND ? AND CANTIDAD > 0 THEN CAST(MARGEN AS float) END) AS margen_prom
        FROM vt
        GROUP BY GROUPING SETS ( (), (BODEGA), (BODEGA, REFERENCIA, COLOR) )
    ";
    $params = array_merge(
        $vtParams,                                    // fuente (pushdown+OR-filter+extra+ss | ckey+OR-filter+extra+ss)
        [$desdeAct,$hastaAct, $desdeAnt,$hastaAnt,    // val_act / val_ant
         $desdeAct,$hastaAct, $desdeAnt,$hastaAnt,    // ups_act / ups_ant
         $desdeAct,$hastaAct]                          // margen_prom
    );
    $rows = run($dbConnect, $sql, $params);
    if (isset($rows['error'])) jsonFail($rows, $dbConnect);

    $rowFieldsT = function ($r) {
        $va=(float)$r['val_act']; $vb=(float)$r['val_ant'];
        $ua=(float)$r['ups_act']; $ub=(float)$r['ups_ant'];
        return [
            'val_act'=>$va, 'val_ant'=>$vb, 'ups_act'=>$ua, 'ups_ant'=>$ub,
            'margen'=>(float)$r['margen_prom'],
            'prom_act'=>$ua>0?$va/$ua:0, 'prom_ant'=>$ub>0?$vb/$ub:0,
        ];
    };
    $kpi = ['val_act'=>0,'ups_act'=>0,'margen'=>0];
    $tiendaMap = [];   // cod => fila tienda
    $hijos = [];       // cod => [children negocio]
    foreach ($rows as $r) {
        $gid = (int)$r['gid'];
        if ($gid === 7) {                       // grand total → KPIs
            $kpi = $rowFieldsT($r);
        } elseif ($gid === 3) {                 // tienda
            $f = $rowFieldsT($r);
            if ($f['val_act']==0 && $f['val_ant']==0) continue;
            $cod = trim((string)$r['cod']);
            $f['cod']=$cod; $f['nombre']=trim((string)$r['nombre']); $f['grupo']=trim((string)$r['grupo']);
            $f['children']=[];
            $tiendaMap[$cod]=$f;
        } elseif ($gid === 0) {                 // negocio (referencia-color)
            $f = $rowFieldsT($r);
            if ($f['val_act']==0 && $f['val_ant']==0) continue;
            $f['negocio']=trim((string)$r['referencia']).'-'.trim((string)$r['color']);
            $hijos[trim((string)$r['cod'])][] = $f;
        }
    }
    foreach ($hijos as $cod=>$hs) {
        if (!isset($tiendaMap[$cod])) continue;
        usort($hs, fn($x,$y)=>$y['val_act']<=>$x['val_act']);
        $tiendaMap[$cod]['children']=$hs;
    }
    $tiendas = array_values($tiendaMap);
    usort($tiendas, fn($x,$y)=>$y['val_act']<=>$x['val_act']);

    // Solo cuentan como tienda los grupos tipo TIENDAS (excluye BODEGA/ADMINISTRATIVAS); sus ventas SÍ aparecen en la lista.
    $tiendasAct = 0; foreach ($tiendas as $t) if ($t['val_act']>0 && !in_array(strtoupper(trim((string)$t['grupo'])), ['BODEGA','ADMINISTRATIVAS'], true)) $tiendasAct++;
    $kpis = [
        'tiendas_siembra' => $nocache
            ? countTiendasSiembra($dbConnect, $filtroExtra, $paramsExtra)
            : countTiendasSiembraCache($dbConnect, $skey, $filtroExtra, $paramsExtra),
        'tiendas_actual'  => $tiendasAct,
        'ticket_prom'     => $kpi['ups_act']>0 ? $kpi['val_act']/$kpi['ups_act'] : 0,
        'margen_prom'     => $kpi['margen'],
    ];
    sqlsrv_close($dbConnect);
    echo json_encode(['ok'=>true,'kpis'=>$kpis,'anio_a'=>$yearAct, 'anio_b'=>$anioBIn,'tiendas'=>$tiendas], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
// TAB: PERIODOS — datos a grano (mes, día) para el drill-down Semestre→Trimestre→Mes→Día.
// ====================================================================
if ($tab === 'periodos') {
    $pAntDesde = $desdeAnt;
    $pAntHasta = $hastaAnt;
    $pGmin = $gmin;
    $pGmax = $gmax;
    // act/ant derivados del bloque global (anioB, cal: diaadia o retail). $pAntDesde = $desdeAnt.
    // S.S.S en Periodos: misma cláusula EXISTS. Vacío salvo sss=same.
    $sameStorePeriodos = ''; $ssParamsPeriodos = [];
    if ($sss === 'same') { $sameStorePeriodos = $sameStoreClause; $ssParamsPeriodos = [$pAntDesde, $hastaAct]; }
    // Fuente de filas viva/cache según $nocache. Esta query lee la fuente DIRECTAMENTE
    // (sin CTE intermedia): en cache el `c.cache_key = ?` vive en el WHERE, textualmente
    // DESPUÉS del SELECT, así que su param va tras los del SELECT (no al frente).
    if ($nocache) {
        $periPrefix = cteVentas();
        $periFromWhere = "
        FROM ventas v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD        = v.BODEGA AND b.CIA = 7
        WHERE 1=1
          $filtroExtra
          $sameStorePeriodos";
        $periLeadParams = [$pGmin, $pGmax, $pGmin, $pGmax];         // CTE pushdown (antes del SELECT)
        $periTailParams = array_merge($paramsExtra, $ssParamsPeriodos);
    } else {
        $sameStorePeriodosC = str_replace('v.', 'c.', $sameStorePeriodos);
        $periPrefix = '';
        $periFromWhere = "
        FROM INTEGRACION.dbo.g00_cache_ventas c
        WHERE c.cache_key = ?
          $filtroExtraC
          $sameStorePeriodosC";
        $periLeadParams = [];
        $periTailParams = array_merge([$ckey], $paramsExtra, $ssParamsPeriodos);
    }
    $sql = $periPrefix . "
        SELECT
            MONTH(FECHA) AS mes,
            DAY(FECHA)   AS dia,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant" . $periFromWhere . "
        GROUP BY MONTH(FECHA), DAY(FECHA)
    ";
    $params = array_merge(
        $periLeadParams,                             // CTE pushdown (nocache) | vacío (cache)
        [$desdeAct,$hastaAct, $pAntDesde,$pAntHasta, // val_act / val_ant
         $desdeAct,$hastaAct, $pAntDesde,$pAntHasta],// ups_act / ups_ant
        $periTailParams                              // cache_key(cache) + extra + S.S.S — WHERE posterior al SELECT
    );
    $rows = run($dbConnect, $sql, $params);
    if (isset($rows['error'])) jsonFail($rows, $dbConnect);
    $dias = [];
    foreach ($rows as $r) {
        $va=(float)$r['val_act']; $vb=(float)$r['val_ant'];
        $ua=(float)$r['ups_act']; $ub=(float)$r['ups_ant'];
        if ($va==0 && $vb==0 && $ua==0 && $ub==0) continue;
        $dias[] = [
            'mes'=>(int)$r['mes'], 'dia'=>(int)$r['dia'],
            'val_act'=>$va, 'val_ant'=>$vb, 'ups_act'=>$ua, 'ups_ant'=>$ub,
        ];
    }
    sqlsrv_close($dbConnect);
    echo json_encode(['ok'=>true,'anio_a'=>$yearAct, 'anio_b'=>$anioBIn,'dias'=>$dias], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
// TAB: PRODUCTOS
// ====================================================================
if ($tab === 'productos') {
    // 3 tablas árbol: Negocio(REF-COLOR)→Talla, Categoría→Subcategoría, Género→Público.
    // Cada una: GROUPING SETS ((),(padre),(padre,hijo)) con comparación YoY (act vs ant).
    $prodMetrics = "
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant,
        AVG(CASE WHEN FECHA BETWEEN ? AND ? AND CANTIDAD > 0 THEN CAST(MARGEN AS float) END) AS margen_prom,
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? AND GRUPO NOT IN ('BODEGA','ADMINISTRATIVAS') THEN BODEGA END) AS tiendas_act,
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? AND GRUPO NOT IN ('BODEGA','ADMINISTRATIVAS') THEN BODEGA END) AS tiendas_ant";

    // Fuente ventas_enriq: viva o cache según $nocache. Agregación idéntica en las 3 queries.
    if ($nocache) {
        $prodCte = cteVentas() . "
        , ventas_enriq AS (
            SELECT v.FECHA, v.BODEGA, v.CANTIDAD, v.VALOR, v.MARGEN,
                   v.REFERENCIA, ISNULL(v.COLOR,'') AS COLOR, ISNULL(v.TALLA,'') AS TALLA,
                   ISNULL(b.GRUPO,'SIN GRUPO')   AS GRUPO,
                   ISNULL(i.CATEGORIA,'')        AS CATEGORIA,
                   ISNULL(i.SUBCATEGORIA,'')     AS SUBCATEGORIA,
                   ISNULL(i.GENERO,'')           AS GENERO,
                   ISNULL(i.PUBLICO_OBJETIVO,'') AS PUBLICO
            FROM ventas v
            INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
            LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.BODEGA AND b.CIA = 7
            WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
              $filtroExtra
              $sameStoreClause
        )
    ";
        $prodLeadParams = array_merge(
            [$gmin, $gmax, $gmin, $gmax],                   // cteVentas pushdown (PBI + Acum)
            [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt],   // ventas_enriq WHERE OR-filter
            $paramsExtra,
            $sameStoreParams
        );
    } else {
        $prodCte = "
        WITH ventas_enriq AS (
            SELECT c.FECHA, c.BODEGA, c.CANTIDAD, c.VALOR, c.MARGEN,
                   c.REFERENCIA, ISNULL(c.COLOR,'') AS COLOR, ISNULL(c.TALLA,'') AS TALLA,
                   ISNULL(c.GRUPO,'SIN GRUPO')   AS GRUPO,
                   ISNULL(c.CATEGORIA,'')        AS CATEGORIA,
                   ISNULL(c.SUBCATEGORIA,'')     AS SUBCATEGORIA,
                   ISNULL(c.GENERO,'')           AS GENERO,
                   ISNULL(c.PUBLICO_OBJETIVO,'') AS PUBLICO
            FROM INTEGRACION.dbo.g00_cache_ventas c
            WHERE c.cache_key = ? AND (c.FECHA BETWEEN ? AND ? OR c.FECHA BETWEEN ? AND ?)
              $filtroExtraC
              $sameStoreClauseC
        )
    ";
        $prodLeadParams = array_merge(
            [$ckey],                                        // cache_key (reemplaza pushdown)
            [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt],   // ventas_enriq WHERE OR-filter
            $paramsExtra,
            $sameStoreParams
        );
    }

    // Params idénticos para las 3 queries (solo cambian nombres de columna, no marcadores).
    $pProd = array_merge(
        $prodLeadParams,                                    // fuente (pushdown|ckey) + OR-filter + extra + ss
        [$desdeAct,$hastaAct, $desdeAnt,$hastaAnt,          // val_act / val_ant
         $desdeAct,$hastaAct, $desdeAnt,$hastaAnt,          // ups_act / ups_ant
         $desdeAct,$hastaAct,                                // margen_prom
         $desdeAct,$hastaAct, $desdeAnt,$hastaAnt]          // tiendas_act / tiendas_ant
    );

    $sqlNeg = $prodCte . "
        SELECT GROUPING_ID(REFERENCIA, COLOR, TALLA) AS gid,
               REFERENCIA AS ref, COLOR AS color, TALLA AS talla,
               $prodMetrics
        FROM ventas_enriq
        GROUP BY GROUPING SETS ( (), (REFERENCIA, COLOR), (REFERENCIA, COLOR, TALLA) )
    ";
    $sqlCat = $prodCte . "
        SELECT GROUPING_ID(CATEGORIA, SUBCATEGORIA) AS gid,
               CATEGORIA AS cat, SUBCATEGORIA AS sub,
               $prodMetrics
        FROM ventas_enriq
        GROUP BY GROUPING SETS ( (), (CATEGORIA), (CATEGORIA, SUBCATEGORIA) )
    ";
    $sqlGen = $prodCte . "
        SELECT GROUPING_ID(GENERO, PUBLICO) AS gid,
               GENERO AS gen, PUBLICO AS pub,
               $prodMetrics
        FROM ventas_enriq
        GROUP BY GROUPING SETS ( (), (GENERO), (GENERO, PUBLICO) )
    ";

    $rNeg = run($dbConnect, $sqlNeg, $pProd);
    $rCat = run($dbConnect, $sqlCat, $pProd);
    $rGen = run($dbConnect, $sqlGen, $pProd);
    foreach ([$rNeg, $rCat, $rGen] as $rr) if (isset($rr['error'])) jsonFail($rr, $dbConnect);

    $nz = fn($s) => ($s === '') ? '(Sin dato)' : $s;
    $negKey   = fn($r) => $nz(trim(trim((string)$r['ref']) . '-' . trim((string)$r['color']), '-'));
    $negChild = fn($r) => $nz(trim((string)$r['talla']));
    $catKey   = fn($r) => $nz(trim((string)$r['cat']));
    $catChild = fn($r) => $nz(trim((string)$r['sub']));
    $genKey   = fn($r) => $nz(trim((string)$r['gen']));
    $genChild = fn($r) => $nz(trim((string)$r['pub']));

    // GROUPING_ID: negocio sobre 3 cols (REF,COLOR,TALLA) → ()=7, (REF,COLOR)=1, full=0.
    //              cat/gen sobre 2 cols → ()=3, (padre)=1, full=0.
    $negocios   = ensamblarArbolProd($rNeg, 7, 1, $negKey, $negChild, 'talla');
    $categorias = ensamblarArbolProd($rCat, 3, 1, $catKey, $catChild, 'val');
    $generos    = ensamblarArbolProd($rGen, 3, 1, $genKey, $genChild, 'val');

    sqlsrv_close($dbConnect);
    echo json_encode([
        'ok'   => true,
        'anio_a' => $yearAct, 'anio_b' => $anioBIn,
        'negocios'   => $negocios,
        'categorias' => $categorias,
        'generos'    => $generos,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
// TAB: DETAL (default) — 4 queries consolidadas en 1 con GROUPING SETS
// ====================================================================
//
// GROUPING_ID(YEAR, MONTH, GRUPO, MARCA, TIPO) por bit (1 = NULL en ese nivel):
//   gid 31 → KPIs totales | 7 → mensual | 27 → grupo | 29 → marca | 28 → marca+tipo
//
// S.S.S (same-store): $sameStoreClause / $sameStoreParams se construyen arriba (scope compartido).
if ($nocache) {
    $consSrc = cteVentas() . "
    , ventas_enriq AS (
        SELECT v.FECHA, v.BODEGA, v.CANTIDAD, v.VALOR, v.MARGEN,
               ISNULL(b.GRUPO, 'SIN GRUPO') AS GRUPO,
               i.MARCA AS MARCA,
               i.TIPO  AS TIPO
        FROM ventas v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD        = v.BODEGA AND b.CIA = 7
        WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
          $filtroExtra
          $sameStoreClause
    )";
    $consLeadParams = array_merge(
        [$gmin, $gmax, $gmin, $gmax],                  // CTE pushdown PBI + Acum
        [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt],  // ventas_enriq OR-filter exacto
        $paramsExtra,
        $sameStoreParams
    );
} else {
    $consSrc = "
    WITH ventas_enriq AS (
        SELECT c.FECHA, c.BODEGA, c.CANTIDAD, c.VALOR, c.MARGEN,
               ISNULL(c.GRUPO, 'SIN GRUPO') AS GRUPO,
               c.MARCA AS MARCA,
               c.TIPO  AS TIPO
        FROM INTEGRACION.dbo.g00_cache_ventas c
        WHERE c.cache_key = ? AND (c.FECHA BETWEEN ? AND ? OR c.FECHA BETWEEN ? AND ?)
          $filtroExtraC
          $sameStoreClauseC
    )";
    $consLeadParams = array_merge(
        [$ckey],                                       // cache_key (reemplaza pushdown)
        [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt],  // ventas_enriq OR-filter exacto
        $paramsExtra,
        $sameStoreParams
    );
}
$sqlConsolidado = $consSrc . "
    SELECT
        GROUPING_ID(YEAR(FECHA), MONTH(FECHA), GRUPO, MARCA, TIPO) AS gid,
        YEAR(FECHA)  AS anio,
        MONTH(FECHA) AS mes,
        GRUPO        AS grupo,
        MARCA        AS marca,
        TIPO         AS tipo,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant,
        AVG(CASE WHEN FECHA BETWEEN ? AND ? AND CANTIDAD > 0 THEN CAST(MARGEN AS float) END) AS margen_prom,
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? AND GRUPO NOT IN ('BODEGA','ADMINISTRATIVAS') THEN BODEGA END) AS tiendas_act,
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? AND GRUPO NOT IN ('BODEGA','ADMINISTRATIVAS') THEN BODEGA END) AS tiendas_ant
    FROM ventas_enriq
    GROUP BY GROUPING SETS (
        (),
        (YEAR(FECHA), MONTH(FECHA)),
        (GRUPO),
        (MARCA),
        (MARCA, TIPO)
    )
";
$paramsConsolidado = array_merge(
    $consLeadParams,                // fuente: (pushdown+OR+extra+ss) viva | (ckey+OR+extra+ss) cache
    [$desdeAct,$hastaAct, $desdeAnt,$hastaAnt,    // val_act / val_ant
     $desdeAct,$hastaAct, $desdeAnt,$hastaAnt,    // ups_act / ups_ant
     $desdeAct,$hastaAct,                          // margen_prom
     $desdeAct,$hastaAct, $desdeAnt,$hastaAnt]    // tiendas_act / tiendas_ant
);

$consolidado = run($dbConnect, $sqlConsolidado, $paramsConsolidado);
if (isset($consolidado['error'])) jsonFail($consolidado, $dbConnect);

// Demultiplex por gid.
// Con 5 dims GROUPING_ID(YEAR,MONTH,GRUPO,MARCA,TIPO) (bit4=YEAR…bit0=TIPO; bit=1⇒NULL):
//   total=31 (11111), mensual=7 (00111), grupo=27 (11011), marca=29 (11101), marca+tipo=28 (11100)
$kpi = []; $mensualRows = []; $porGrupo = []; $porMarca = []; $porMarcaTipo = [];
foreach ($consolidado as $r) {
    $gid = (int)$r['gid'];
    if      ($gid === 31) { $kpi = $r; }
    elseif  ($gid === 7)  { $mensualRows[]  = $r; }
    elseif  ($gid === 27) { $porGrupo[]     = $r; }
    elseif  ($gid === 29) { $porMarca[]     = $r; }
    elseif  ($gid === 28) { $porMarcaTipo[] = $r; }
}

$catalogos = getCatalogos($dbConnect, $proveedor);

$ventasAct = (float)($kpi['val_act']      ?? 0);
$ventasAnt = (float)($kpi['val_ant']      ?? 0);
$upsAct    = (float)($kpi['ups_act']      ?? 0);
$upsAnt    = (float)($kpi['ups_ant']      ?? 0);
$margenPr  = (float)($kpi['margen_prom']  ?? 0);
$tiendasAc = (int)  ($kpi['tiendas_act']  ?? 0);
$tiendasAn = (int)  ($kpi['tiendas_ant']  ?? 0);

$deltaVentas  = $ventasAnt > 0 ? (($ventasAct - $ventasAnt) / $ventasAnt) * 100 : 0;
$deltaUps     = $upsAnt    > 0 ? (($upsAct    - $upsAnt)    / $upsAnt)    * 100 : 0;
$deltaTiendas = $tiendasAn > 0 ? (($tiendasAc - $tiendasAn) / $tiendasAn) * 100 : 0;
$ticketProm   = $upsAct    > 0 ?  $ventasAct / $upsAct                          : 0;

// Mensual: rango ($mensDesA/$mensHasA/$mensDesB/$mensHasB, $mensGmin/$mensGmax,
// $mesAntExpr, $shiftToActualDays) ya computado arriba (bloque cache-first hoisted),
// porque el span de Mensual entra en el cálculo del span del cache.
// El mes se calcula UNA sola vez dentro del CTE (alias `mes`) y se agrupa por ese alias.
// Antes el mismo CASE se repetía en SELECT y en GROUP BY; como cada uno usa marcadores `?`
// distintos, SQL Server no los reconoce como la misma expresión y lanza el error 8120
// ('FECHA' inválida fuera de agregado/GROUP BY). Con el alias, el GROUP BY no lleva params.
if ($nocache) {
    $vmSrc = cteVentas() . "
    , vm AS (
        SELECT v.FECHA, v.BODEGA, v.CANTIDAD, v.VALOR,
               ISNULL(b.GRUPO, 'SIN GRUPO') AS GRUPO,
               CASE WHEN v.FECHA BETWEEN ? AND ? THEN MONTH(v.FECHA) ELSE $mesAntExpr END AS mes
        FROM ventas v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.BODEGA AND b.CIA = 7
        WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
          $filtroExtra
          $sameStoreClause
    )";
    $vmLeadParams = array_merge(
        [$mensGmin, $mensGmax, $mensGmin, $mensGmax],   // cte pushdown
        [$mensDesA, $mensHasA],                          // vm: CASE mes (rango act)
        [$mensDesA, $mensHasA, $mensDesB, $mensHasB],   // vm WHERE OR-filter (act, ant)
        $paramsExtra,
        $sameStoreParams
    );
} else {
    // $mesAntExpr usa FECHA bare → resuelve a c.FECHA (único origen). cache_key va en el
    // WHERE (tras el CASE-mes del SELECT), así que su param va tras los del CASE-mes.
    $vmSrc = "
    WITH vm AS (
        SELECT c.FECHA, c.BODEGA, c.CANTIDAD, c.VALOR,
               ISNULL(c.GRUPO, 'SIN GRUPO') AS GRUPO,
               CASE WHEN c.FECHA BETWEEN ? AND ? THEN MONTH(c.FECHA) ELSE $mesAntExpr END AS mes
        FROM INTEGRACION.dbo.g00_cache_ventas c
        WHERE c.cache_key = ? AND (c.FECHA BETWEEN ? AND ? OR c.FECHA BETWEEN ? AND ?)
          $filtroExtraC
          $sameStoreClauseC
    )";
    $vmLeadParams = array_merge(
        [$mensDesA, $mensHasA],                          // vm: CASE mes (rango act)
        [$ckey],                                          // cache_key (WHERE, tras el CASE)
        [$mensDesA, $mensHasA, $mensDesB, $mensHasB],   // vm WHERE OR-filter (act, ant)
        $paramsExtra,
        $sameStoreParams
    );
}
$sqlMensual = $vmSrc . "
    SELECT
        GROUPING_ID(mes) AS gid,
        mes,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant,
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? AND GRUPO NOT IN ('BODEGA','ADMINISTRATIVAS') THEN BODEGA END) AS tiendas_act,
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? AND GRUPO NOT IN ('BODEGA','ADMINISTRATIVAS') THEN BODEGA END) AS tiendas_ant
    FROM vm
    GROUP BY GROUPING SETS ((mes), ())
";
$pMensual = array_merge(
    $vmLeadParams,                                   // fuente (pushdown|ckey en su posición) + CASE-mes + OR-filter + extra + ss
    [$mensDesA, $mensHasA, $mensDesB, $mensHasB,     // val_act / val_ant
     $mensDesA, $mensHasA, $mensDesB, $mensHasB,     // ups_act / ups_ant
     $mensDesA, $mensHasA, $mensDesB, $mensHasB]     // tiendas_act / tiendas_ant
);
$mensual = run($dbConnect, $sqlMensual, $pMensual);
if (isset($mensual['error'])) jsonFail($mensual, $dbConnect);
$mapMV = []; $mapMU = []; $mapMT = [];
$tdasTotAct = 0; $tdasTotAnt = 0;
foreach ($mensual as $r) {
    if ((int)$r['gid'] === 1) {           // grand total (mes NULL) → distinct del rango
        $tdasTotAct = (int)$r['tiendas_act'];
        $tdasTotAnt = (int)$r['tiendas_ant'];
        continue;
    }
    $mi = (int)$r['mes'];
    if ($mi < 1 || $mi > 12) continue;
    $mapMV[$mi] = ['val_act' => (float)$r['val_act'], 'val_ant' => (float)$r['val_ant']];
    $mapMU[$mi] = ['ups_act' => (float)$r['ups_act'], 'ups_ant' => (float)$r['ups_ant']];
    $mapMT[$mi] = ['act' => (int)$r['tiendas_act'], 'ant' => (int)$r['tiendas_ant']];
}
$labelsMes = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mesActual = (int)date('n', strtotime($hastaAct));   // Ene → mes del Hasta del año mayor
// $yearAct definido arriba (derivación de rangos) junto con $anioBIn.
$serieMensual = [];
for ($m = 1; $m <= $mesActual; $m++) {   // Ene → mes actual
    $serieMensual[] = [
        'mes'         => $labelsMes[$m-1],
        'val_act'     => $mapMV[$m]['val_act'] ?? 0,
        'val_ant'     => $mapMV[$m]['val_ant'] ?? 0,
        'ups_act'     => $mapMU[$m]['ups_act'] ?? 0,
        'ups_ant'     => $mapMU[$m]['ups_ant'] ?? 0,
        'tiendas_act' => $mapMT[$m]['act'] ?? 0,
        'tiendas_ant' => $mapMT[$m]['ant'] ?? 0,
    ];
}

$rowFields = function ($r, $keyDim) {
    return [
        'label'       => trim((string)($r[$keyDim] ?? '')),
        'val_act'     => (float)($r['val_act']     ?? 0),
        'val_ant'     => (float)($r['val_ant']     ?? 0),
        'ups_act'     => (float)($r['ups_act']     ?? 0),
        'ups_ant'     => (float)($r['ups_ant']     ?? 0),
        'margen'      => (float)($r['margen_prom'] ?? 0),
        'tiendas_act' => (int)  ($r['tiendas_act'] ?? 0),
        'tiendas_ant' => (int)  ($r['tiendas_ant'] ?? 0),
    ];
};

// Por grupo (ordenado por $ actual desc)
$grupoArr = array_map(fn($r) => $rowFields($r, 'grupo'), $porGrupo);
usort($grupoArr, fn($x, $y) => $y['val_act'] <=> $x['val_act']);

// Por marca con tipos anidados
$marcaMap = [];
foreach ($porMarca as $r) {
    $m = trim((string)$r['marca']);
    $marcaMap[$m] = $rowFields($r, 'marca');
    $marcaMap[$m]['children'] = [];
}
foreach ($porMarcaTipo as $r) {
    $m = trim((string)$r['marca']);
    if (!isset($marcaMap[$m])) { // defensa: marca sin fila propia
        $marcaMap[$m] = $rowFields($r, 'marca');
        $marcaMap[$m]['label'] = $m;
        $marcaMap[$m]['children'] = [];
    }
    $marcaMap[$m]['children'][] = $rowFields($r, 'tipo');
}
$marcaArr = array_values($marcaMap);
usort($marcaArr, fn($x, $y) => $y['val_act'] <=> $x['val_act']);
foreach ($marcaArr as &$mref) {
    usort($mref['children'], fn($x, $y) => $y['val_act'] <=> $x['val_act']);
}
unset($mref);

// Tiendas con Siembra (cualquier marca del proveedor, según filtros de producto/bodega; sin fecha ni S.S.S).
$tiendasSiembra = $nocache
    ? countTiendasSiembra($dbConnect, $filtroExtra, $paramsExtra)
    : countTiendasSiembraCache($dbConnect, $skey, $filtroExtra, $paramsExtra);

$out = [
    'ok'        => true,
    'proveedor' => $proveedorSesion,
    'generado'  => date('c'),
    'anio_a'    => $yearAct,
    'anio_b'    => $anioBIn,
    'rango' => [
        'desde_actual'   => $desdeAct, 'hasta_actual'   => $hastaAct,
        'desde_anterior' => $desdeAnt, 'hasta_anterior' => $hastaAnt,
    ],
    'filtros_activos' => $paramsExtra ? array_keys(array_filter($FILTROS_MULTI, fn($c,$k)=>!empty($_GET[$k]), ARRAY_FILTER_USE_BOTH)) : [],
    'kpis' => [
        'ventas_actual'    => $ventasAct,
        'ventas_anterior'  => $ventasAnt,
        'delta_ventas'     => $deltaVentas,
        'ups_actual'       => $upsAct,
        'ups_anterior'     => $upsAnt,
        'delta_ups'        => $deltaUps,
        'tiendas_siembra'  => $tiendasSiembra,
        'tiendas_actual'   => $tiendasAc,
        'tiendas_anterior' => $tiendasAn,
        'delta_tiendas'    => $deltaTiendas,
        'ticket_prom'      => $ticketProm,
        'margen_prom'      => $margenPr,
    ],
    'mensual'      => $serieMensual,
    'mensual_tdas' => ['act' => $tdasTotAct, 'ant' => $tdasTotAnt],
    'por_grupo' => $grupoArr,
    'por_marca' => $marcaArr,
    'catalogos' => $catalogos,
];

sqlsrv_close($dbConnect);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
