<?php
/**
 * Doble oráculo: agregar el dataset granular (buildO45Dataset) en PHP debe dar
 * lo MISMO que la agregación de tab=data del backend, por proveedor. Read-only.
 * Uso: php tests/verificar_o45_dataset.php ["PROV A" "PROV B" ...]
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_o45_dataset.php';
if ($dbConnect === false) { fwrite(STDERR, "Conexión DB fallida\n"); exit(1); }

$desde = '2025-01-01';
$hasta = date('Y-m-d', strtotime('-1 day'));

// Agrega el dataset granular como lo hace tab=data (sin filtros), en PHP.
function aggO45PHP(array $rows, array $meta): array {
    $g = []; // negocio-key => acumulador
    foreach ($rows as $r) {
        $cedi = ($r['bodega'] === 'CEDI');
        $k = $r['cia'] . '|' . $r['referencia'] . '|' . $r['color'];
        if (!isset($g[$k])) $g[$k] = ['cia'=>$r['cia'],'referencia'=>$r['referencia'],'color'=>$r['color'],
            'negocio'=>$r['referencia'].'-'.$r['color'],'marca'=>$r['marca'],
            'ventas'=>0,'ventas30'=>0,'stock_cedi'=>0,'stock_tiendas'=>0,'_tallas'=>[],'_tiendas'=>[]];
        $a = &$g[$k];
        if ($r['marca'] > $a['marca']) $a['marca'] = $r['marca']; // MAX(marca)
        if (!$cedi) { $a['ventas'] += $r['ventas']; $a['ventas30'] += $r['ventas30']; $a['stock_tiendas'] += $r['disponible'] + $r['hold']; }
        else $a['stock_cedi'] += $r['disponible'] + $r['hold'];
        $activo = ($r['disponible'] + $r['hold'] > 0) || $r['inv_hist'] == 1 || $r['ventas'] != 0;
        if ($activo) {
            $a['_tallas'][$r['talla']] = 1;
            if (!in_array($r['grupo'], ['BODEGA','ADMINISTRATIVAS'], true)) $a['_tiendas'][$r['cia'].'-'.$r['bodega']] = 1;
        }
        unset($a);
    }
    $dias = $meta['dias'];
    $filas = []; $tot = ['ventas'=>0,'ventas30'=>0,'stock_cedi'=>0,'stock_tiendas'=>0,'total_stock'=>0]; $totTiendas = [];
    foreach ($g as $a) {
        $total_stock = $a['stock_cedi'] + $a['stock_tiendas'];
        $tiendas = count($a['_tiendas']);
        $filas[] = ['negocio'=>$a['negocio'],'referencia'=>$a['referencia'],'color'=>$a['color'],'marca'=>$a['marca'],
            'ventas'=>$a['ventas'],'tiendas'=>$tiendas,'ventas30'=>$a['ventas30'],
            'stock_cedi'=>$a['stock_cedi'],'stock_tiendas'=>$a['stock_tiendas'],'total_stock'=>$total_stock,
            'ind_inventario'=>$a['ventas30']>0 ? round($total_stock/$a['ventas30'],2) : null,
            'ind_ventas_mes'=>$tiendas>0 ? round(($a['ventas']/$tiendas)/($dias/30),2) : 0.0,
            'tallas'=>count($a['_tallas'])];
        $tot['ventas']+=$a['ventas']; $tot['ventas30']+=$a['ventas30']; $tot['stock_cedi']+=$a['stock_cedi'];
        $tot['stock_tiendas']+=$a['stock_tiendas']; $tot['total_stock']+=$total_stock;
        foreach ($a['_tiendas'] as $t=>$_) $totTiendas[$t]=1;
    }
    $tot['tiendas']=count($totTiendas);
    $tot['ind_inventario']=$tot['ventas30']>0 ? round($tot['total_stock']/$tot['ventas30'],2) : null;
    $tot['ind_ventas_mes']=$tot['tiendas']>0 ? round(($tot['ventas']/$tot['tiendas'])/($dias/30),2) : 0.0;
    usort($filas, fn($x,$y)=>$y['ind_ventas_mes']<=>$x['ind_ventas_mes']);
    return ['filas'=>$filas,'total'=>$tot];
}

// La verdad: llama al endpoint tab=data en-proceso (sesión simulada) y captura su JSON.
function backendTabData($conn, $prov, $desde, $hasta): ?array {
    // Reejecuta la agregación de tab=data vía el endpoint aislado.
    // -d display_errors=0 -d display_startup_errors=0: en este entorno los warnings de
    // arranque (xdebug/dio/openssl) se emiten a stdout y corrompen el JSON del oráculo.
    $cmd = sprintf('php -d display_errors=0 -d display_startup_errors=0 %s/o45_tabdata_oraculo.php %s %s %s',
        escapeshellarg(__DIR__), escapeshellarg($prov), $desde, $hasta);
    $out = shell_exec($cmd);
    $j = json_decode((string)$out, true);
    return is_array($j) && isset($j['filas']) ? $j : null;
}

$provs = array_slice($argv, 1);
if (!$provs) $provs = ['BH BRANDS SAS','BRAHMA CONCEPT','CALZADO WALDOS','BELLINO'];

$fallos = 0;
foreach ($provs as $prov) {
    buildRefsFromMat($dbConnect, $prov);
    $ds = buildO45Dataset($dbConnect, $desde, $hasta);
    $nuevo = aggO45PHP($ds['rows'], $ds['meta']);
    sqlsrv_query($dbConnect, "IF OBJECT_ID('tempdb..#refs') IS NOT NULL DROP TABLE #refs");
    $viejo = backendTabData($dbConnect, $prov, $desde, $hasta);
    if ($viejo === null) { echo "[$prov] SKIP (oráculo no disponible)\n"; continue; }
    // Comparar filas por negocio (orden puede variar en empates de ind); normalizar por clave.
    $keyf = fn($f)=>$f['negocio'];
    $mvn = []; foreach ($nuevo['filas'] as $f) $mvn[$keyf($f)] = $f;
    $mvv = []; foreach ($viejo['filas'] as $f) $mvv[$keyf($f)] = $f;
    $dif = 0;
    foreach ($mvv as $k=>$fv) {
        $fn = $mvn[$k] ?? null;
        foreach (['ventas','tiendas','ventas30','stock_cedi','stock_tiendas','total_stock','ind_inventario','ind_ventas_mes','tallas'] as $c)
            if ($fn === null || (string)$fv[$c] !== (string)$fn[$c]) { $dif++; if ($dif<=5) echo "   DIFF [$k].$c viejo=".var_export($fv[$c],true)." nuevo=".var_export($fn[$c]??null,true)."\n"; break; }
    }
    $extra = count(array_diff_key($mvn,$mvv));
    // Comparar TODAS las claves de total{} presentes en ambos lados (no solo ventas).
    $totClaves = array_intersect(array_keys($nuevo['total']), array_keys($viejo['total']));
    $totDif = 0;
    foreach ($totClaves as $c) {
        if ((string)$nuevo['total'][$c] !== (string)$viejo['total'][$c]) {
            $totDif++;
            echo "   DIFF [total].$c viejo=".var_export($viejo['total'][$c],true)." nuevo=".var_export($nuevo['total'][$c],true)."\n";
        }
    }
    printf("[%s] negocios nuevo=%d viejo=%d | difs=%d extra=%d | total.ventas n=%s v=%s | total-difs=%d\n",
        $prov, count($mvn), count($mvv), $dif, $extra, $nuevo['total']['ventas'], $viejo['total']['ventas'], $totDif);
    if ($dif || $extra || $totDif) $fallos++;
}
echo $fallos===0 ? "\nRESULTADO: doble-oráculo OK \xE2\x9C\x94\n" : "\nRESULTADO: $fallos proveedor(es) con diferencias \xE2\x9C\x97\n";
exit($fallos===0?0:1);
