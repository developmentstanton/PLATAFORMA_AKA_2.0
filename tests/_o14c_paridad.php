<?php
/**
 * Paridad (Task 2): o14cBuildPayloadC produce un payload tab=c bien formado y CONSISTENTE
 * consigo mismo (suma del árbol de siembra == kpis.siembra) para varios proveedores.
 * La paridad byte-a-byte contra el camino vivo HTTP (?nocache=1) es Task 3 (--e2e).
 * Requiere DB (RDS remota): puede tardar ~40-60s para BRAHMA CONCEPT (~46k filas).
 */
function o14cNorm($p) {
    // normaliza: ordena grupos/almacenes/negocios por clave estable para comparar sin depender del orden
    usort($p['grupos'], fn($a,$b)=>strcmp($a['grupo'],$b['grupo']));
    foreach ($p['grupos'] as &$g) {
        usort($g['almacenes'], fn($a,$b)=>strcmp($a['llave'],$b['llave']));
        foreach ($g['almacenes'] as &$a) usort($a['negocios'], fn($x,$y)=>strcmp($x['negocio'],$y['negocio']));
    }
    return json_encode(['tallas'=>$p['tallas'],'kpis'=>$p['kpis'],'grupos'=>$p['grupos']]);
}

function o14cRunParidad(): int {
    require __DIR__ . '/../conexion/conexion_integracion.php';
    require __DIR__ . '/../api/lib_refs.php';
    // NOTA: conexion_integracion.php se require() DENTRO de esta función, así que $dbConnect
    // queda en el scope LOCAL de o14cRunParidad (no en $GLOBALS) — se usa la variable local
    // directamente (el brief original leía $GLOBALS['dbConnect'], que aquí es null/undefined
    // y rompía buildRefsFromMat() con un TypeError en vez del RED esperado).
    if ($dbConnect === false) { echo "SKIP: sin DB\n"; return 0; }
    $conn = $dbConnect;
    $desde='2025-01-01'; $hasta=date('Y-m-d'); $fail=0;
    foreach (['BELTRANY SAS','BRAHMA CONCEPT'] as $prov) {
        buildRefsFromMat($conn, $prov);
        $key = o14CacheKey($prov, $desde, $hasta);
        ensureO14CacheBase($conn, $key, $desde, $hasta);
        $p = o14cBuildPayloadC($conn, $key, $desde, $hasta);
        $ok = isset($p['ok']) && $p['ok'] && $p['tab']==='c' && is_array($p['grupos']);
        echo ($ok?"OK  ":"FAIL")."  $prov: grupos=".count($p['grupos'])." tallas=".count($p['tallas'])." kpis.siembra=".($p['kpis']['siembra']??'?')."\n";
        if (!$ok) $fail++;
        // consistencia: la suma de siembra del arbol == kpis.siembra
        $suma=0; foreach($p['grupos'] as $g) foreach($g['almacenes'] as $a) foreach($a['negocios'] as $n)
            foreach(($n['valores']['siembra']??[]) as $v) $suma+=$v;
        echo ($suma==($p['kpis']['siembra']??-1)?"OK  ":"FAIL")."  $prov: suma arbol siembra ($suma) == kpis.siembra\n";
        if ($suma!=($p['kpis']['siembra']??-1)) $fail++;
    }
    // --- Frescura: o14cCurrentStamp + o14cDiskFresh contra datos reales ---
    $provF = 'BELTRANY SAS';
    buildRefsFromMat($conn, $provF);
    $keyF = o14CacheKey($provF, $desde, $hasta);
    ensureO14CacheBase($conn, $keyF, $desde, $hasta);
    $stampF = o14cCurrentStamp($conn, $keyF);
    echo (($stampF !== null) ? "OK  " : "FAIL") . "  frescura: o14cCurrentStamp devuelve stamp no-null ($stampF)\n";
    if ($stampF === null) $fail++;
    $pF = o14cBuildPayloadC($conn, $keyF, $desde, $hasta);
    o14cWritePayload($keyF, json_encode($pF, JSON_UNESCAPED_UNICODE), $stampF);
    $fresco = o14cDiskFresh($conn, $keyF);
    echo ($fresco ? "OK  " : "FAIL") . "  frescura: o14cDiskFresh TRUE tras escribir con el stamp vigente\n";
    if (!$fresco) $fail++;
    // stamp falso en disco -> debe dar FALSE
    file_put_contents(o14cStampPath($keyF), 'STAMP-FALSO');
    $stale = o14cDiskFresh($conn, $keyF);
    echo (!$stale ? "OK  " : "FAIL") . "  frescura: o14cDiskFresh FALSE con stamp de disco desfasado\n";
    if ($stale) $fail++;
    @unlink(o14cPayloadPath($keyF)); @unlink(o14cStampPath($keyF));

    echo $fail?"\n$fail FALLO(S)\n":"\nPARIDAD OK\n";
    return $fail?1:0;
}
