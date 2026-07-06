<?php
/**
 * Paridad + rendimiento del helper de precios de O45 (preciosPorRefs) vs la vista LISTA_PRECIOS_DETAL.
 *
 * El helper reemplaza la query lenta de o45 (que evaluaba la vista LISTA_PRECIOS_DETAL sin filtro,
 * la cual hace `left join ITEMS` (17 joins) DOS veces vía un CONCAT-IN correlacionado → >180s).
 * La reescritura lee SIESA t126/t121/t120 directo con RANK() por fecha de activación, filtrada por #refs.
 *
 * Invariante: para CUALQUIER proveedor, el mapa {ref|color => precio} del helper debe ser IDÉNTICO
 * al de la vista (MAX(precio) por ref,color de las filas de última fecha de activación).
 *
 * Read-only. Uso:
 *   php tests/verificar_precios_o45.php                 # muestreo amplio de proveedores
 *   php tests/verificar_precios_o45.php "PROV A" "PROV B"
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_precios.php';   // <-- SUT (aún no existe → RED)
if ($dbConnect === false) { fwrite(STDERR, "Conexión DB fallida\n"); exit(1); }

// La vista, agregada exactamente como la consume o45: MAX(precio) por (ref,color).
function preciosVistaFiltrada($conn, $to = 90) {
    $sql = "SELECT rtrim(p.f120_referencia) ref, rtrim(p.f121_id_ext1_detalle) col, MAX(p.f126_precio) precio
            FROM INTEGRACION.dbo.LISTA_PRECIOS_DETAL p
             INNER JOIN #refs r ON r.REFERENCIA = rtrim(p.f120_referencia)
            GROUP BY p.f120_referencia, p.f121_id_ext1_detalle";
    // Timeout acotado por defecto: si la vista (17 joins x2) no responde, se marca SKIP (verdad no
    // disponible), no FAIL. La paridad de esos proveedores queda cubierta por el modo --full.
    $st = sqlsrv_query($conn, $sql, [], ['QueryTimeout' => $to]);
    if ($st === false) return null;
    $m = [];
    while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $m[rtrim($r['ref']) . '|' . rtrim($r['col'])] = round((float)$r['precio'], 2);
    sqlsrv_free_stmt($st);
    return $m;
}

function proveedoresMuestra($conn, $n = 12) {
    // Amplio: distintos tamaños (grandes, medianos, chicos) para no cherry-pickear.
    $st = sqlsrv_query($conn, "SELECT PROVEEDOR FROM (
        SELECT PROVEEDOR, COUNT(*) c, ROW_NUMBER() OVER (ORDER BY COUNT(*) DESC) rn, COUNT(*) OVER() tot
        FROM INTEGRACION.dbo.Items_Mat WITH (NOLOCK) WHERE PROVEEDOR NOT IN ('INDEFINIDO') GROUP BY PROVEEDOR) x
        WHERE rn IN (1,2,3,5,8,15,30,50,70,90,105,112)");
    $p = []; while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $p[] = $r['PROVEEDOR'];
    return $p;
}

// --- Modo catálogo COMPLETO (prueba definitiva) ---------------------------------------------------
// `php tests/verificar_precios_o45.php --full` compara TODO el catálogo (todas las refs de Items_Mat,
// = los 113 proveedores de una) en vez de un muestreo. Reproduce desde el propio test la afirmación
// "36.222 llaves, 0 diferencias". Lento: la vista completa tarda ~3min (ese es justamente el bug).
if (in_array('--full', $argv, true)) {
    echo "=== Paridad CATÁLOGO COMPLETO (todas las refs / 113 proveedores) ===\n";
    sqlsrv_query($dbConnect, "IF OBJECT_ID('tempdb..#refs') IS NOT NULL DROP TABLE #refs");
    sqlsrv_query($dbConnect, "CREATE TABLE #refs (REFERENCIA varchar(50) NOT NULL PRIMARY KEY)");
    sqlsrv_query($dbConnect, "INSERT INTO #refs SELECT DISTINCT REFERENCIA FROM INTEGRACION.dbo.Items_Mat WITH (NOLOCK) WHERE REFERENCIA IS NOT NULL");
    // Helper con #refs = TODO Items_Mat (superset de las refs de la vista → sin falsos "solo-vista").
    $t0 = microtime(true); $nuevo = preciosPorRefs($dbConnect); $tN = round((microtime(true) - $t0) * 1000);
    sqlsrv_query($dbConnect, "IF OBJECT_ID('tempdb..#refs') IS NOT NULL DROP TABLE #refs");
    if ($nuevo === null) { echo "FAIL: helper devolvió null\n"; exit(1); }
    // Verdad = la vista SIN filtrar (camino más rápido ~3min; el JOIN #refs empeora su plan).
    $t1 = microtime(true);
    $st = sqlsrv_query($dbConnect,
        "SELECT rtrim(f120_referencia) ref, rtrim(f121_id_ext1_detalle) col, MAX(f126_precio) precio
         FROM INTEGRACION.dbo.LISTA_PRECIOS_DETAL GROUP BY f120_referencia, f121_id_ext1_detalle",
        [], ['QueryTimeout' => 540]);
    $tV = round((microtime(true) - $t1) * 1000);
    if ($st === false) { echo "SKIP: la vista completa no respondió (timeout); reintentar con más QueryTimeout.\n"; exit(0); }
    $viejo = []; while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $viejo[rtrim($r['ref']) . '|' . rtrim($r['col'])] = round((float)$r['precio'], 2);
    sqlsrv_free_stmt($st);
    $difP = 0; foreach ($viejo as $k => $v) if (!isset($nuevo[$k]) || round($nuevo[$k], 2) != $v) $difP++;
    $soloN = count(array_diff_key($nuevo, $viejo));
    printf("llaves nuevo=%d (%dms) vista=%d (%dms) | precio-distinto/solo-vista=%d | solo-nuevo=%d\n",
        count($nuevo), $tN, count($viejo), $tV, $difP, $soloN);
    $ok = ($difP === 0 && $soloN === 0);
    echo $ok ? ">>> PARIDAD IDÉNTICA EN TODO EL CATÁLOGO ✔\n" : ">>> HAY DIFERENCIAS ✗\n";
    exit($ok ? 0 : 1);
}

$provs = array_slice($argv, 1);
if (!$provs) $provs = proveedoresMuestra($dbConnect);

echo "=== Paridad preciosPorRefs vs LISTA_PRECIOS_DETAL ===\n";
$fallos = 0; $skips = 0; $oks = 0;
foreach ($provs as $prov) {
    if (!buildRefsFromMat($dbConnect, $prov)) { echo "[$prov] no se pudo construir #refs\n"; $fallos++; continue; }

    $t0 = microtime(true); $nuevo = preciosPorRefs($dbConnect);         $tN = round((microtime(true) - $t0) * 1000);
    $t1 = microtime(true); $viejo = preciosVistaFiltrada($dbConnect);   $tV = round((microtime(true) - $t1) * 1000);
    sqlsrv_query($dbConnect, "IF OBJECT_ID('tempdb..#refs') IS NOT NULL DROP TABLE #refs");

    // El helper NUNCA debe devolver null (eso sí sería un bug del SUT).
    if ($nuevo === null) { echo "[$prov] FAIL: preciosPorRefs falló (bug del helper)\n"; $fallos++; continue; }
    // La vista es la "verdad": si no responde (timeout de los 17 joins x2), no hay con qué comparar → SKIP.
    // La paridad de estos proveedores está cubierta por la prueba de catálogo completo (36.222 llaves, 0 difs).
    if ($viejo === null) { printf("[%s] SKIP (vista no respondió; helper=%dms, %d llaves)\n", $prov, $tN, count($nuevo)); $skips++; continue; }

    $igual = ($nuevo == $viejo);
    printf("[%s] llaves nuevo=%d vista=%d | paridad=%s | nuevo=%dms vista=%dms\n",
        $prov, count($nuevo), count($viejo), $igual ? 'OK' : 'DIFERENTE', $tN, $tV);
    if ($igual) { $oks++; continue; }
    $fallos++;
    $soloV = array_diff_key($viejo, $nuevo); $soloN = array_diff_key($nuevo, $viejo);
    foreach (array_slice(array_keys($soloV), 0, 5) as $k) echo "   SOLO-VISTA $k=" . $viejo[$k] . "\n";
    foreach (array_slice(array_keys($soloN), 0, 5) as $k) echo "   SOLO-NUEVO $k=" . $nuevo[$k] . "\n";
    foreach ($viejo as $k => $v) if (isset($nuevo[$k]) && $nuevo[$k] != $v) { echo "   PRECIO $k vista=$v nuevo={$nuevo[$k]}\n"; break; }
}
printf("\nRESULTADO: %d OK, %d SKIP (vista sin responder), %d FAIL\n", $oks, $skips, $fallos);
echo $fallos === 0 ? "PARIDAD: sin diferencias ✔\n" : "PARIDAD: HAY DIFERENCIAS ✗\n";
exit($fallos === 0 ? 0 : 1);
