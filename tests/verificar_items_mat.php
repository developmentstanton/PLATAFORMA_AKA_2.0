<?php
/**
 * Verificación de paridad + rendimiento de Items_Mat (Sub-proyecto C).
 * Read-only sobre tablas reales; usa temp tables de sesión para comparar.
 * Uso: php tests/verificar_items_mat.php "PROVEEDOR 1" "PROVEEDOR 2" ...
 * Compara, por proveedor: (viejo) getRefsCached+buildRefsTemp  vs  (nuevo) buildRefsFromMat.
 */
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
if ($dbConnect === false) { fwrite(STDERR, "Conexión DB fallida\n"); exit(1); }

$proveedores = array_slice($argv, 1);
if (!$proveedores) { fwrite(STDERR, "Uso: php verificar_items_mat.php <prov1> [prov2 ...]\n"); exit(2); }

// Guardrail: sin Items_Mat, buildRefsFromMat cae al camino viejo y la comparación sería vacua
// (ambos caminos correrían lo mismo → "paridad total" falsa). Exigir que la tabla exista.
$chk = sqlsrv_query($dbConnect, "SELECT OBJECT_ID('INTEGRACION.dbo.Items_Mat') AS oid");
$oidRow = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
if (!$oidRow || $oidRow['oid'] === null) {
    fwrite(STDERR, "ABORT: INTEGRACION.dbo.Items_Mat no existe. Puebla la tabla (EXEC dbo.usp_Refresh_Items_Mat) antes de verificar;\n");
    fwrite(STDERR, "de lo contrario la comparación daría 'paridad total' sin probar realmente el camino nuevo.\n");
    exit(2);
}

function refsViejo($conn, $prov) {  // devuelve mapa REFERENCIA => fila normalizada
    $rows = buildRefsTemp($conn, getRefsCached($conn, $prov)) ? leerRefs($conn) : null;
    sqlsrv_query($conn, "IF OBJECT_ID('tempdb..#refs') IS NOT NULL DROP TABLE #refs");
    return $rows;
}
function refsNuevo($conn, $prov) {
    $rows = buildRefsFromMat($conn, $prov) ? leerRefs($conn) : null;
    sqlsrv_query($conn, "IF OBJECT_ID('tempdb..#refs') IS NOT NULL DROP TABLE #refs");
    return $rows;
}
function leerRefs($conn) {
    $st = sqlsrv_query($conn, "SELECT REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO FROM #refs ORDER BY REFERENCIA");
    if ($st === false) return null;
    $m = [];
    while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) { $m[$r['REFERENCIA']] = $r; }
    sqlsrv_free_stmt($st);
    return $m;
}

$fallo = 0;
foreach ($proveedores as $prov) {
    $t0 = microtime(true); $viejo = refsViejo($dbConnect, $prov); $tViejo = round((microtime(true)-$t0)*1000);
    $t1 = microtime(true); $nuevo = refsNuevo($dbConnect, $prov); $tNuevo = round((microtime(true)-$t1)*1000);
    if ($viejo === null || $nuevo === null) { echo "[$prov] ERROR construyendo refs\n"; $fallo++; continue; }
    $igual = ($viejo == $nuevo); // compara claves + valores de cada fila
    printf("[%s] filas viejo=%d nuevo=%d | paridad=%s | viejo=%dms nuevo=%dms\n",
        $prov, count($viejo), count($nuevo), $igual ? 'OK' : 'DIFERENTE', $tViejo, $tNuevo);
    if (!$igual) {
        $fallo++;
        $soloViejo = array_diff_key($viejo, $nuevo); $soloNuevo = array_diff_key($nuevo, $viejo);
        if ($soloViejo) echo "   refs solo en VIEJO: " . implode(',', array_slice(array_keys($soloViejo),0,10)) . "\n";
        if ($soloNuevo) echo "   refs solo en NUEVO: " . implode(',', array_slice(array_keys($soloNuevo),0,10)) . "\n";
    }
}
echo $fallo ? "\nRESULTADO: $fallo proveedor(es) con diferencias.\n" : "\nRESULTADO: paridad total.\n";
exit($fallo ? 1 : 0);
