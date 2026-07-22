<?php
/**
 * Seguimiento READ-ONLY: ¿el costo de fases 2/3 de G00-detal es el scan+join
 * o el cómputo del GROUPING SETS + COUNT(DISTINCT CASE)?
 * Materializa el granular (mismo FROM/JOINs, sin GROUP BY) en #vcache y re-ejecuta
 * el consolidado y el mensual leyendo de #vcache. Solo SELECT + timing.
 *   php -d display_errors=0 -d display_startup_errors=0 tests/perfilado_g00_vcache.php "BH BRANDS SAS" "BRAHMA CONCEPT"
 */
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_g00_rango.php';

if ($dbConnect === false) { fwrite(STDERR, "Conexion DB fallida\n"); exit(1); }
$proveedores = array_slice($argv, 1);
if (!$proveedores) { fwrite(STDERR, "Uso: php perfilado_g00_vcache.php <prov1> [prov2 ...]\n"); exit(2); }

$OPTS = ['QueryTimeout' => 120];

function exec_timed($conn, $label, $sql, $params, $opts) {
    $t0 = microtime(true);
    $stmt = sqlsrv_query($conn, $sql, $params, $opts);
    if ($stmt === false) {
        $ms = round((microtime(true) - $t0) * 1000);
        echo "  [$label] ERROR ($ms ms): ";
        foreach (sqlsrv_errors() as $e) echo $e['message'] . " | ";
        echo "\n";
        return [null, null];
    }
    $n = 0;
    do { while (sqlsrv_fetch_array($stmt)) $n++; } while (sqlsrv_next_result($stmt));
    sqlsrv_free_stmt($stmt);
    $ms = round((microtime(true) - $t0) * 1000);
    return [$ms, $n];
}

// ---- Params default de G00 ----
$desdeAct = date('Y-01-01'); $hastaAct = date('Y-m-d'); $cal = 'diaadia';
$hastaAct = g00_cap_hasta($hastaAct, date('Y-m-d', strtotime('-1 day')));
$anioBIn = (int)date('Y', strtotime($hastaAct)) - 1;
list($desdeAct, $hastaAct, $desdeAnt, $hastaAnt) = g00_rango_comparacion($desdeAct, $hastaAct, $anioBIn, $cal);
$yearAct = (int)date('Y', strtotime($hastaAct));
$gmin = ($desdeAnt < $desdeAct) ? $desdeAnt : $desdeAct;
$gmax = ($hastaAct > $hastaAnt) ? $hastaAct : $hastaAnt;
echo "Rango: desdeAct=$desdeAct hastaAct=$hastaAct desdeAnt=$desdeAnt hastaAnt=$hastaAnt\n\n";

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
    if (!buildRefsFromMat($dbConnect, $proveedor)) { echo "  buildRefsFromMat FALLO\n"; continue; }

    // ---- (a) Materializar #vcache: mismo FROM/JOINs/WHERE que ventas_enriq, SIN GROUP BY ----
    // CREATE (sin params) + INSERT...SELECT (con params): el SELECT...INTO con `?` se envuelve
    // en sp_executesql y la temp table muere al cerrar el batch (gotcha documentado en el código).
    sqlsrv_query($dbConnect, "IF OBJECT_ID('tempdb..#vcache') IS NOT NULL DROP TABLE #vcache");
    sqlsrv_query($dbConnect, "CREATE TABLE #vcache (
        FECHA date, BODEGA varchar(30), CANTIDAD float, VALOR float, MARGEN float,
        GRUPO varchar(60), MARCA varchar(60), TIPO varchar(60))");
    $sqlMat = cteVentas() . "
        INSERT INTO #vcache (FECHA, BODEGA, CANTIDAD, VALOR, MARGEN, GRUPO, MARCA, TIPO)
        SELECT v.FECHA, v.BODEGA, v.CANTIDAD, v.VALOR, v.MARGEN,
               ISNULL(b.GRUPO, 'SIN GRUPO') AS GRUPO,
               i.MARCA AS MARCA,
               i.TIPO  AS TIPO
        FROM ventas v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.BODEGA AND b.CIA = 7
        WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
    ";
    $pMat = [$gmin, $gmax, $gmin, $gmax, $desdeAct, $hastaAct, $desdeAnt, $hastaAnt];
    list($msMat, ) = exec_timed($dbConnect, 'mat', $sqlMat, $pMat, $OPTS);
    // contar filas materializadas
    $cnt = sqlsrv_query($dbConnect, "SELECT COUNT(*) AS n FROM #vcache");
    $nCache = $cnt ? (int)sqlsrv_fetch_array($cnt, SQLSRV_FETCH_ASSOC)['n'] : -1;
    if ($cnt) sqlsrv_free_stmt($cnt);

    // ---- (b) Consolidado sobre #vcache (mismo GROUPING SETS y COUNT(DISTINCT CASE)) ----
    $sqlConsol = "
        SELECT
            GROUPING_ID(YEAR(FECHA), MONTH(FECHA), GRUPO, MARCA, TIPO) AS gid,
            YEAR(FECHA) AS anio, MONTH(FECHA) AS mes, GRUPO AS grupo, MARCA AS marca, TIPO AS tipo,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant,
            AVG(CASE WHEN FECHA BETWEEN ? AND ? AND CANTIDAD > 0 THEN CAST(MARGEN AS float) END) AS margen_prom,
            COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? AND GRUPO NOT IN ('BODEGA','ADMINISTRATIVAS') THEN BODEGA END) AS tiendas_act,
            COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? AND GRUPO NOT IN ('BODEGA','ADMINISTRATIVAS') THEN BODEGA END) AS tiendas_ant
        FROM #vcache
        GROUP BY GROUPING SETS ( (), (YEAR(FECHA), MONTH(FECHA)), (GRUPO), (MARCA), (MARCA, TIPO) )
    ";
    $pConsol = [$desdeAct,$hastaAct, $desdeAnt,$hastaAnt,
                $desdeAct,$hastaAct, $desdeAnt,$hastaAnt,
                $desdeAct,$hastaAct,
                $desdeAct,$hastaAct, $desdeAnt,$hastaAnt];
    list($msConsol, ) = exec_timed($dbConnect, 'consol', $sqlConsol, $pConsol, $OPTS);

    // ---- (c) Mensual sobre #vcache ----
    $mensDesA = "$yearAct-01-01"; $mensHasA = $hastaAct;
    $mensDesB = g00_set_anio($mensDesA, $anioBIn); $mensHasB = $hastaAnt;
    $sqlMens = "
        WITH vm AS (
            SELECT FECHA, BODEGA, CANTIDAD, VALOR, GRUPO,
                   CASE WHEN FECHA BETWEEN ? AND ? THEN MONTH(FECHA) ELSE MONTH(FECHA) END AS mes
            FROM #vcache
        )
        SELECT
            GROUPING_ID(mes) AS gid, mes,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
            SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant,
            COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? AND GRUPO NOT IN ('BODEGA','ADMINISTRATIVAS') THEN BODEGA END) AS tiendas_act,
            COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? AND GRUPO NOT IN ('BODEGA','ADMINISTRATIVAS') THEN BODEGA END) AS tiendas_ant
        FROM vm
        GROUP BY GROUPING SETS ((mes), ())
    ";
    $pMens = [$mensDesA, $mensHasA,
              $mensDesA, $mensHasA, $mensDesB, $mensHasB,
              $mensDesA, $mensHasA, $mensDesB, $mensHasB,
              $mensDesA, $mensHasA, $mensDesB, $mensHasB];
    list($msMens, ) = exec_timed($dbConnect, 'mens', $sqlMens, $pMens, $OPTS);

    printf("  (a) materializar #vcache : %5s ms   (%d filas)\n", $msMat, $nCache);
    printf("  (b) consolidado/#vcache  : %5s ms   (live ~4737-5799 ms)\n", $msConsol);
    printf("  (c) mensual/#vcache      : %5s ms   (live ~3172-5087 ms)\n", $msMens);

    sqlsrv_query($dbConnect, "IF OBJECT_ID('tempdb..#vcache') IS NOT NULL DROP TABLE #vcache");
    sqlsrv_query($dbConnect, "IF OBJECT_ID('tempdb..#refs') IS NOT NULL DROP TABLE #refs");
    echo "\n";
}
sqlsrv_close($dbConnect);
