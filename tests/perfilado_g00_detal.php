<?php
/**
 * Perfilado READ-ONLY del tab `detal` de G00 (api/informe_g00.php), por fase.
 * Solo SELECT + timing. No modifica nada. Uso:
 *   php -d display_errors=0 -d display_startup_errors=0 tests/perfilado_g00_detal.php "BH BRANDS SAS" "BRAHMA CONCEPT"
 */
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_g00_rango.php';

if ($dbConnect === false) { fwrite(STDERR, "Conexion DB fallida\n"); exit(1); }

$proveedores = array_slice($argv, 1);
if (!$proveedores) { fwrite(STDERR, "Uso: php perfilado_g00_detal.php <prov1> [prov2 ...]\n"); exit(2); }

$OPTS = ['QueryTimeout' => 120];

function run_timed($conn, $label, $sql, $params, $opts) {
    $t0 = microtime(true);
    $stmt = sqlsrv_query($conn, $sql, $params, $opts);
    if ($stmt === false) {
        $ms = round((microtime(true) - $t0) * 1000);
        echo "  [$label] ERROR (" . $ms . " ms): ";
        foreach (sqlsrv_errors() as $e) echo $e['message'] . " | ";
        echo "\n";
        return null;
    }
    $n = 0;
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $n++;
    sqlsrv_free_stmt($stmt);
    $ms = round((microtime(true) - $t0) * 1000);
    printf("  [%s] %d ms (%d filas)\n", $label, $ms, $n);
    return $ms;
}

// ---- Params por defecto de G00 (igual que el endpoint sin querystring) ----
$desdeAct = date('Y-01-01');
$hastaAct = date('Y-m-d');
$cal = 'diaadia';
$sss = 'nosame';
$hastaAct = g00_cap_hasta($hastaAct, date('Y-m-d', strtotime('-1 day')));
$anioBIn = (int)date('Y', strtotime($hastaAct)) - 1;
list($desdeAct, $hastaAct, $desdeAnt, $hastaAnt, $rangoErr) =
    g00_rango_comparacion($desdeAct, $hastaAct, $anioBIn, $cal);
$yearAct = (int)date('Y', strtotime($hastaAct));
$gmin = ($desdeAnt < $desdeAct) ? $desdeAnt : $desdeAct;
$gmax = ($hastaAct > $hastaAnt) ? $hastaAct : $hastaAnt;

echo "Rango: desdeAct=$desdeAct hastaAct=$hastaAct desdeAnt=$desdeAnt hastaAnt=$hastaAnt (gmin=$gmin gmax=$gmax)\n";
echo "cal=$cal sss=$sss anioB=$anioBIn yearAct=$yearAct\n\n";

$filtroExtra = '';    // sin filtros de producto/tienda
$paramsExtra = [];
$sameStoreClause = ''; // sss=nosame por defecto
$sameStoreParams = [];

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

foreach ($proveedores as $proveedor) {
    echo "==================== PROVEEDOR: $proveedor ====================\n";

    // ---- Fase 1: buildRefsFromMat ----
    $t0 = microtime(true);
    $ok = buildRefsFromMat($dbConnect, $proveedor);
    $tRefs = round((microtime(true) - $t0) * 1000);
    if (!$ok) { echo "  buildRefsFromMat FALLO\n"; continue; }
    printf("  [1. buildRefsFromMat] %d ms\n", $tRefs);

    // ---- Fase 2: GROUPING SETS consolidado (detal) ----
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
        [$gmin, $gmax, $gmin, $gmax],
        [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt],
        $paramsExtra,
        $sameStoreParams,
        [$desdeAct,$hastaAct, $desdeAnt,$hastaAnt,
         $desdeAct,$hastaAct, $desdeAnt,$hastaAnt,
         $desdeAct,$hastaAct,
         $desdeAct,$hastaAct, $desdeAnt,$hastaAnt]
    );
    run_timed($dbConnect, '2. GROUPING SETS consolidado (detal)', $sqlConsolidado, $paramsConsolidado, $OPTS);

    // ---- Fase 3: Mensual GROUPING SETS ----
    $mensDesA = "$yearAct-01-01";
    $mensHasA = $hastaAct;
    if ($cal === 'retail') {
        $shiftToActualDays = 364 * ($yearAct - $anioBIn);
        $mensDesB = date('Y-m-d', strtotime($mensDesA . ' -' . $shiftToActualDays . ' days'));
        $mensHasB = $hastaAnt;
    } else {
        $shiftToActualDays = 0;
        $mensDesB = g00_set_anio($mensDesA, $anioBIn);
        $mensHasB = $hastaAnt;
    }
    $mensGmin = min($mensDesB, $mensDesA);
    $mensGmax = max($mensHasA, $mensHasB);
    $mesAntExpr = $shiftToActualDays > 0
        ? "MONTH(DATEADD(DAY, $shiftToActualDays, FECHA))"
        : "MONTH(FECHA)";

    $sqlMensual = cteVentas() . "
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
        )
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
        [$mensGmin, $mensGmax, $mensGmin, $mensGmax],
        [$mensDesA, $mensHasA],
        [$mensDesA, $mensHasA, $mensDesB, $mensHasB],
        $paramsExtra,
        $sameStoreParams,
        [$mensDesA, $mensHasA, $mensDesB, $mensHasB,
         $mensDesA, $mensHasA, $mensDesB, $mensHasB,
         $mensDesA, $mensHasA, $mensDesB, $mensHasB]
    );
    run_timed($dbConnect, '3. Mensual GROUPING SETS', $sqlMensual, $pMensual, $OPTS);

    // ---- Fase 4: getCatalogos (query directa, sin cache) ----
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
    $tG0 = microtime(true);
    run_timed($dbConnect, '4a. getCatalogos: grupos (scan Ventas_*_PBI)', $sqlGrupos, [], $OPTS);
    run_timed($dbConnect, '4b. getCatalogos: marcas (#refs)', $sqlMarcas, [], $OPTS);

    // ---- Fase 5: countTiendasSiembra ----
    $sqlSiembra = "
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
    run_timed($dbConnect, '5. countTiendasSiembra (JOIN ERP)', $sqlSiembra, $paramsExtra, $OPTS);

    // limpiar #refs para el siguiente proveedor
    sqlsrv_query($dbConnect, "IF OBJECT_ID('tempdb..#refs') IS NOT NULL DROP TABLE #refs");
    echo "\n";
}

sqlsrv_close($dbConnect);
