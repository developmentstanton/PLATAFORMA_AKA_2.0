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

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

$proveedorSesion = $_SESSION['proveedor'] ?? '';
$proveedor = $proveedorSesion !== '' ? $proveedorSesion : '__SIN_PROVEEDOR__';

$desdeIn = $_GET['desde'] ?? '';
$hastaIn = $_GET['hasta'] ?? '';
$sss     = strtolower(trim($_GET['sss'] ?? 'nosame')); // S.S.S: 'same' aplica same-store; default 'nosame' = todas

if ($desdeIn && $hastaIn) {
    $desdeAct = $desdeIn;
    $hastaAct = $hastaIn;
} else {
    $desdeAct = date('Y-01-01');
    $hastaAct = date('Y-m-d');
}
// Calendario: 'diaadia' (default) → período anterior = -1 año (alineación calendario).
//             'retail'            → período anterior = -364 días (52 semanas, preserva día de semana).
$cal = strtolower(trim($_GET['cal'] ?? 'diaadia'));
if ($cal === 'retail') {
    $desdeAnt = date('Y-m-d', strtotime($desdeAct . ' -364 days'));
    $hastaAnt = date('Y-m-d', strtotime($hastaAct . ' -364 days'));
} else {
    $cal = 'diaadia';
    $desdeAnt = date('Y-m-d', strtotime($desdeAct . ' -1 year'));
    $hastaAnt = date('Y-m-d', strtotime($hastaAct . ' -1 year'));
}

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
 */
function cteVentas() {
    return "
    WITH ventas AS (
        SELECT FECHA, BODEGA, REFERENCIA, CANTIDAD, VALOR, MARGEN
        FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK)
        WHERE FECHA BETWEEN ? AND ?
        UNION ALL
        SELECT FECHA, BODEGA, REFERENCIA, CANTIDAD, VALOR, MARGEN
        FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK)
        WHERE FECHA BETWEEN ? AND ?
    )
    ";
}

// Filtros multi-valor (Inc 2A). Cada uno: WHERE <col> IN (?,?,...). Vacío = sin filtro.
// Grano referencia → alias #refs (i). Grano tienda → alias Bodegas (b).
$FILTROS_MULTI = [
    'marca'        => 'i.MARCA',
    'tipo'         => 'i.TIPO',
    'categoria'    => 'i.CATEGORIA',
    'subcategoria' => 'i.SUBCATEGORIA',
    'genero'       => 'i.GENERO',
    'publico'      => 'i.PUBLICO_OBJETIVO',
    'referencia'   => 'i.REFERENCIA',
    'depto'        => 'b.DEPTO',
    'ciudad'       => 'b.CIUDAD',
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
 * Refs del proveedor (REFERENCIA + atributos), cacheadas en archivo con frescura
 * diaria (válido mientras el archivo se generó hoy → expira de hecho a medianoche,
 * alineado con la actualización del ERP). Evita evaluar la vista ITEMS por request.
 */
function getRefsCached($conn, $proveedor) {
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/g00_refs_' . md5($proveedor) . '.json';
    if (file_exists($cacheFile) && date('Y-m-d', filemtime($cacheFile)) === date('Y-m-d')) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (is_array($data)) return $data;
    }
    $sql = "SELECT REFERENCIA,
                ISNULL(MARCA,    'SIN MARCA')         AS MARCA,
                ISNULL(TIPO,     'SIN TIPO')          AS TIPO,
                ISNULL(LINEA,    'SIN LINEA')         AS LINEA,
                ISNULL(SUBLINEA, '')                  AS SUBLINEA,
                ISNULL(CATEGORIA,'')                  AS CATEGORIA,
                ISNULL(SUBCATEGORIA,'')               AS SUBCATEGORIA,
                ISNULL(GENERO,'')                     AS GENERO,
                ISNULL(PUBLICO_OBJETIVO,'')           AS PUBLICO_OBJETIVO
            FROM INTEGRACION.dbo.ITEMS WITH (NOLOCK)
            WHERE PROVEEDOR = ?";
    $rows = run($conn, $sql, [$proveedor]);
    if (isset($rows['error'])) return [];   // fallo suave → #refs vacío → resultados vacíos
    @file_put_contents($cacheFile, json_encode($rows));
    return $rows;
}

/**
 * Crea la temp table #refs y la puebla desde el arreglo cacheado.
 * El CREATE TABLE va SIN parámetros (vive en el scope de la sesión); si se usara
 * SELECT...INTO con un `?`, el driver lo envuelve en sp_executesql y la temp table
 * se destruye al cerrar ese batch. El INSERT batched (200 filas / 1000 placeholders)
 * a una tabla preexistente sí persiste y respeta el límite de 2100 params.
 */
function buildRefsTemp($conn, $refs) {
    $ok = sqlsrv_query($conn, "CREATE TABLE #refs (
        REFERENCIA varchar(50) NOT NULL PRIMARY KEY,
        MARCA varchar(40), TIPO varchar(40), LINEA varchar(40), SUBLINEA varchar(40), CATEGORIA varchar(40),
        SUBCATEGORIA varchar(60), GENERO varchar(40), PUBLICO_OBJETIVO varchar(60))");
    if ($ok === false) return false;
    sqlsrv_free_stmt($ok);
    if (empty($refs)) return true;
    foreach (array_chunk($refs, 200) as $chunk) {
        $vals = []; $params = [];
        foreach ($chunk as $r) {
            $vals[] = '(?,?,?,?,?,?,?,?,?)';
            array_push($params, $r['REFERENCIA'], $r['MARCA'], $r['TIPO'], $r['LINEA'], $r['SUBLINEA'], $r['CATEGORIA'],
                       $r['SUBCATEGORIA'], $r['GENERO'], $r['PUBLICO_OBJETIVO']);
        }
        $sql = "INSERT INTO #refs (REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO) VALUES " . implode(',', $vals);
        $ins = sqlsrv_query($conn, $sql, $params);
        if ($ins === false) return false;
        sqlsrv_free_stmt($ins);
    }
    return true;
}

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
$refsProv = getRefsCached($dbConnect, $proveedor);
if (!buildRefsTemp($dbConnect, $refsProv)) {
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
        if (is_array($cached)) { sqlsrv_close($dbConnect); echo json_encode($cached, JSON_UNESCAPED_UNICODE); exit; }
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
// TAB: TIENDAS
// ====================================================================
if ($tab === 'tiendas') {
    $sql = cteVentas() . "
        SELECT
            v.BODEGA                              AS cod,
            ISNULL(b.NOMBRE,  v.BODEGA)           AS nombre,
            ISNULL(b.GRUPO,   'SIN GRUPO')        AS grupo,
            ISNULL(b.CIUDAD,  '')                 AS ciudad,
            ISNULL(b.DEPTO,   '')                 AS depto,
            ISNULL(b.COORDENADAS, '')             AS coord,
            ISNULL(b.ESTADO,  '')                 AS estado,
            SUM(CASE WHEN v.FECHA BETWEEN ? AND ? THEN v.VALOR    ELSE 0 END) AS actual,
            SUM(CASE WHEN v.FECHA BETWEEN ? AND ? THEN v.VALOR    ELSE 0 END) AS anterior,
            SUM(CASE WHEN v.FECHA BETWEEN ? AND ? THEN v.CANTIDAD ELSE 0 END) AS ups_actual
        FROM ventas v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD        = v.BODEGA AND b.CIA = 7
        WHERE 1=1
          $filtroExtra
        GROUP BY v.BODEGA, b.NOMBRE, b.GRUPO, b.CIUDAD, b.DEPTO, b.COORDENADAS, b.ESTADO
        HAVING SUM(CASE WHEN v.FECHA BETWEEN ? AND ? THEN v.VALOR ELSE 0 END) > 0
            OR SUM(CASE WHEN v.FECHA BETWEEN ? AND ? THEN v.VALOR ELSE 0 END) > 0
        ORDER BY actual DESC
    ";
    $params = array_merge(
        [$gmin, $gmax, $gmin, $gmax],                              // CTE pushdown
        [$desdeAct,$hastaAct, $desdeAnt,$hastaAnt, $desdeAct,$hastaAct],  // SUM CASE
        $paramsExtra,
        [$desdeAct,$hastaAct, $desdeAnt,$hastaAnt]                 // HAVING
    );
    $rows = run($dbConnect, $sql, $params);
    if (isset($rows['error'])) jsonFail($rows, $dbConnect);
    $tiendas = [];
    foreach ($rows as $r) {
        $a = (float)$r['actual']; $b = (float)$r['anterior'];
        $tiendas[] = [
            'cod'        => trim($r['cod']),
            'nombre'     => trim($r['nombre']),
            'grupo'      => trim($r['grupo']),
            'ciudad'     => trim($r['ciudad']),
            'depto'      => trim($r['depto']),
            'coord'      => trim($r['coord']),
            'estado'     => trim($r['estado']),
            'actual'     => $a,
            'anterior'   => $b,
            'delta_pct'  => $b > 0 ? (($a - $b) / $b) * 100 : 0,
            'ups_actual' => (float)$r['ups_actual'],
        ];
    }
    sqlsrv_close($dbConnect);
    echo json_encode(['ok'=>true,'tiendas'=>$tiendas], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
// TAB: PERIODOS — consolidado en 1 query con GROUPING SETS (diario + dow)
// ====================================================================
if ($tab === 'periodos') {
    // GROUPING_ID(dia, dow): dia es bit alto, dow es bit bajo. Bit=1 → columna NULL.
    //   gid = 1 (01) → row DIARIA (dia agrupado bit=0, dow NULL bit=1)
    //   gid = 2 (10) → row DOW    (dia NULL bit=1, dow agrupado bit=0)
    $sql = cteVentas() . "
        SELECT
            GROUPING_ID(CONVERT(varchar(10), v.FECHA, 120), DATEPART(WEEKDAY, v.FECHA)) AS gid,
            CONVERT(varchar(10), v.FECHA, 120) AS dia,
            DATEPART(WEEKDAY, v.FECHA)         AS dow,
            SUM(v.VALOR)    AS valor,
            SUM(v.CANTIDAD) AS ups
        FROM ventas v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD        = v.BODEGA AND b.CIA = 7
        WHERE v.FECHA BETWEEN ? AND ?
          $filtroExtra
        GROUP BY GROUPING SETS (
            (CONVERT(varchar(10), v.FECHA, 120)),
            (DATEPART(WEEKDAY, v.FECHA))
        )
    ";
    $params = array_merge([$desdeAct, $hastaAct, $desdeAct, $hastaAct, $desdeAct, $hastaAct], $paramsExtra);

    $rows = run($dbConnect, $sql, $params);
    if (isset($rows['error'])) jsonFail($rows, $dbConnect);

    $diarioArr = []; $dowArr = [];
    foreach ($rows as $r) {
        $gid = (int)$r['gid'];
        if ($gid === 1) {
            $diarioArr[] = [
                'dia'   => $r['dia'],
                'valor' => (float)$r['valor'],
                'ups'   => (float)$r['ups'],
            ];
        } elseif ($gid === 2) {
            $dowArr[] = [
                'dow'   => (int)$r['dow'],
                'valor' => (float)$r['valor'],
                'ups'   => (float)$r['ups'],
            ];
        }
    }
    usort($diarioArr, fn($a, $b) => strcmp($a['dia'], $b['dia']));
    usort($dowArr, fn($a, $b) => $a['dow'] - $b['dow']);

    sqlsrv_close($dbConnect);
    echo json_encode([
        'ok' => true,
        'rango' => ['desde' => $desdeAct, 'hasta' => $hastaAct],
        'diario' => $diarioArr,
        'por_dow' => $dowArr,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
// TAB: PRODUCTOS
// ====================================================================
if ($tab === 'productos') {
    $sqlRef = cteVentas() . "
        SELECT TOP 50
            v.REFERENCIA                       AS ref,
            i.MARCA                            AS marca,
            i.LINEA                            AS linea,
            i.SUBLINEA                         AS sublinea,
            i.CATEGORIA                        AS categoria,
            SUM(v.VALOR)     AS valor,
            SUM(v.CANTIDAD)  AS ups,
            AVG(CAST(v.MARGEN AS float)) AS margen
        FROM ventas v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD        = v.BODEGA AND b.CIA = 7
        WHERE v.FECHA BETWEEN ? AND ?
          $filtroExtra
        GROUP BY v.REFERENCIA, i.MARCA, i.LINEA, i.SUBLINEA, i.CATEGORIA
        ORDER BY valor DESC
    ";
    $paramsRef = array_merge([$desdeAct, $hastaAct, $desdeAct, $hastaAct, $desdeAct, $hastaAct], $paramsExtra);

    $sqlTreemap = cteVentas() . "
        SELECT
            i.MARCA      AS marca,
            i.LINEA      AS linea,
            SUM(v.VALOR) AS valor
        FROM ventas v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD        = v.BODEGA AND b.CIA = 7
        WHERE v.FECHA BETWEEN ? AND ?
          $filtroExtra
        GROUP BY i.MARCA, i.LINEA
        HAVING SUM(v.VALOR) > 0
        ORDER BY valor DESC
    ";
    $paramsTree = array_merge([$desdeAct, $hastaAct, $desdeAct, $hastaAct, $desdeAct, $hastaAct], $paramsExtra);

    $refs = run($dbConnect, $sqlRef,     $paramsRef);
    $tree = run($dbConnect, $sqlTreemap, $paramsTree);
    if (isset($refs['error'])) jsonFail($refs, $dbConnect);
    if (isset($tree['error'])) jsonFail($tree, $dbConnect);

    $refsArr = array_map(fn($r) => [
        'ref'       => trim($r['ref']),
        'marca'     => trim($r['marca']),
        'linea'     => trim($r['linea']),
        'sublinea'  => trim($r['sublinea']),
        'categoria' => trim($r['categoria']),
        'valor'     => (float)$r['valor'],
        'ups'       => (float)$r['ups'],
        'margen'    => (float)$r['margen'],
    ], $refs);
    $jerarquia = [];
    foreach ($tree as $r) {
        $m = trim($r['marca']); $l = trim($r['linea']);
        if (!isset($jerarquia[$m])) $jerarquia[$m] = ['name' => $m, 'value' => 0, 'children' => []];
        $jerarquia[$m]['value']      += (float)$r['valor'];
        $jerarquia[$m]['children'][] = ['name' => $l, 'value' => (float)$r['valor']];
    }
    sqlsrv_close($dbConnect);
    echo json_encode([
        'ok' => true,
        'rango' => ['desde' => $desdeAct, 'hasta' => $hastaAct],
        'refs' => $refsArr,
        'treemap' => array_values($jerarquia),
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
// S.S.S (same-store): SOLO se aplica cuando ?sss=same. Por defecto (No Same) NO se
// filtra → se muestran todas las tiendas activas en el periodo.
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
$sqlConsolidado = cteVentas() . "
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
    )
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
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? THEN BODEGA END) AS tiendas_act,
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? THEN BODEGA END) AS tiendas_ant
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
    [$gmin, $gmax, $gmin, $gmax],   // CTE pushdown PBI + Acum
    [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt],  // ventas_enriq OR-filter exacto
    $paramsExtra,
    $sameStoreParams,               // EXISTS same-store SOLO si sss=same (vacío por defecto)
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

// Mensual: SIEMPRE Ene→hoy (ignora el filtro de fecha). El período anterior usa
// la alineación del Calendario: -1 año (diaadia) o -364 días (retail), de modo que
// ambos años quedan "al corte del mismo día".
$hoy        = date('Y-m-d');
$mensDesA   = date('Y-01-01');
$mensHasA   = $hoy;
if ($cal === 'retail') {
    $mensDesB = date('Y-m-d', strtotime($mensDesA . ' -364 days'));
    $mensHasB = date('Y-m-d', strtotime($mensHasA . ' -364 days'));
    $shiftToActualDays = 364; // sumar a una fecha 'ant' para mapearla al mes 'act'
} else {
    $mensDesB = date('Y-m-d', strtotime($mensDesA . ' -1 year'));
    $mensHasB = date('Y-m-d', strtotime($mensHasA . ' -1 year'));
    $shiftToActualDays = 0;   // diaadia: el mes calendario ya coincide (mismo mes, -1 año)
}
$mensGmin = min($mensDesB, $mensDesA);
$mensGmax = max($mensHasA, $mensHasB);

// Para 'ant' (retail) el mes se mapea sumando 364 días para que caiga en el mes 'act'
// equivalente; para 'diaadia' el mes 'ant' ya es el mismo mes calendario.
$mesAntExpr = $shiftToActualDays > 0
    ? "MONTH(DATEADD(DAY, $shiftToActualDays, FECHA))"
    : "MONTH(FECHA)";
// El mes se calcula UNA sola vez dentro del CTE (alias `mes`) y se agrupa por ese alias.
// Antes el mismo CASE se repetía en SELECT y en GROUP BY; como cada uno usa marcadores `?`
// distintos, SQL Server no los reconoce como la misma expresión y lanza el error 8120
// ('FECHA' inválida fuera de agregado/GROUP BY). Con el alias, el GROUP BY no lleva params.
$sqlMensual = cteVentas() . "
    , vm AS (
        SELECT v.FECHA, v.CANTIDAD, v.VALOR,
               CASE WHEN v.FECHA BETWEEN ? AND ? THEN MONTH(v.FECHA) ELSE $mesAntExpr END AS mes
        FROM ventas v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.BODEGA AND b.CIA = 7
        WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
          $filtroExtra
          $sameStoreClause
    )
    SELECT
        mes,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant
    FROM vm
    GROUP BY mes
";
$pMensual = array_merge(
    [$mensGmin, $mensGmax, $mensGmin, $mensGmax],   // cte pushdown
    [$mensDesA, $mensHasA],                          // vm: CASE mes (rango act)
    [$mensDesA, $mensHasA, $mensDesB, $mensHasB],   // vm WHERE OR-filter (act, ant)
    $paramsExtra,
    $sameStoreParams,
    [$mensDesA, $mensHasA, $mensDesB, $mensHasB,     // val_act / val_ant
     $mensDesA, $mensHasA, $mensDesB, $mensHasB]     // ups_act / ups_ant
);
$mensual = run($dbConnect, $sqlMensual, $pMensual);
if (isset($mensual['error'])) jsonFail($mensual, $dbConnect);
$mapMV = []; $mapMU = [];
foreach ($mensual as $r) {
    $mi = (int)$r['mes'];
    if ($mi < 1 || $mi > 12) continue;
    $mapMV[$mi] = ['val_act' => (float)$r['val_act'], 'val_ant' => (float)$r['val_ant']];
    $mapMU[$mi] = ['ups_act' => (float)$r['ups_act'], 'ups_ant' => (float)$r['ups_ant']];
}
$labelsMes = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$mesActual = (int)date('n');
// Año para los rótulos de las tablas (Grupo/Marca/KPI respetan el filtro de fecha).
// En uso por defecto (filtro = año en curso) coincide con el año actual.
$yearAct = (int)date('Y', strtotime($hastaAct));
$serieMensual = [];
for ($m = 1; $m <= $mesActual; $m++) {   // Ene → mes actual
    $serieMensual[] = [
        'mes'     => $labelsMes[$m-1],
        'val_act' => $mapMV[$m]['val_act'] ?? 0,
        'val_ant' => $mapMV[$m]['val_ant'] ?? 0,
        'ups_act' => $mapMU[$m]['ups_act'] ?? 0,
        'ups_ant' => $mapMU[$m]['ups_ant'] ?? 0,
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

$out = [
    'ok'        => true,
    'proveedor' => $proveedorSesion,
    'generado'  => date('c'),
    'anio'      => $yearAct,
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
        'tiendas_actual'   => $tiendasAc,
        'tiendas_anterior' => $tiendasAn,
        'delta_tiendas'    => $deltaTiendas,
        'ticket_prom'      => $ticketProm,
        'margen_prom'      => $margenPr,
    ],
    'mensual'   => $serieMensual,
    'por_grupo' => $grupoArr,
    'por_marca' => $marcaArr,
    'catalogos' => $catalogos,
];

sqlsrv_close($dbConnect);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
