<?php
/**
 * API Informe O14 — Siembra & Stock x Tienda x Talla.
 * Devuelve datos tidy; el cliente pivota a la matriz ancha.
 * Tabs: b (por negocio), c (por tienda de un negocio), reco (cascada).
 * Ver docs/superpowers/specs/2026-05-22-o14-design.md y .../plans/2026-05-22-o14-vistas.md
 *
 * Fuentes (cruzan por CIA-normalizada-3díg, BODEGA, REF, COLOR, TALLA):
 *  - Siembra: stanton.dbo.t400_cm_existencia (f400_cant_nivel_min_1)
 *  - Disponible (=Total Stock): inv_actual_PBI (cia<>'001', COLUMNA1 IN INV1430/INV1435/400)
 *  - Hold entrante: _hold_actual_PBI (bodega_ent)
 *  - Ventas: Ventas_Detal_PBI/_Acum (informativa, por rango)
 *  - CEDI: filas con bodega='CEDI' (002=Brahma, 007=Cauchosol): se excluyen de tiendas; fuente de cascada.
 * Fórmula por talla: balance = siembra-(disponible+hold); faltante=max(0,bal), sobrante=max(0,-bal).
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }

$proveedorSesion = $_SESSION['proveedor'] ?? '';
$proveedor = $proveedorSesion !== '' ? $proveedorSesion : '__SIN_PROVEEDOR__';
$tab   = $_GET['tab']   ?? 'b';
$cia   = trim($_GET['cia']   ?? '');
// desde/hasta delimitan SOLO la ventana de Ventas (siembra/disp/hold son foto actual, sin fecha).
// Default: histórico desde 2025-01-01 → cruza Ventas_Detal_Acum_PBI + Ventas_Detal_PBI (ver $inclAcum).
$desde = $_GET['desde'] ?? '2025-01-01';
$hasta = $_GET['hasta'] ?? date('Y-m-d');

// Filtros multi-valor (como G00). REF→poda #refs; color/talla→#base; bodega→Bodegas.
$FILTROS_REF = ['marca'=>'MARCA','tipo'=>'TIPO','categoria'=>'CATEGORIA','subcategoria'=>'SUBCATEGORIA','genero'=>'GENERO','publico'=>'PUBLICO_OBJETIVO','referencia'=>'REFERENCIA'];
$FILTROS_SKU = ['color'=>'color','talla'=>'talla'];
$FILTROS_BOD = ['grupo'=>'GRUPO','tienda'=>'NOMBRE','centro_comercial'=>'CENTRO_COMERCIAL','depto'=>'DEPTO','ciudad'=>'CIUDAD'];
function getMulti($key) { $v = $_GET[$key] ?? []; if (!is_array($v)) $v = ($v === '' ? [] : [$v]);
    return array_values(array_filter(array_map('trim', $v), fn($x) => $x !== '')); }
// El catálogo necesita el universo completo del proveedor: ignora cia y no aplica filtros.
if ($tab === 'filtros') $cia = '';

require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/lib_refs.php';
if ($dbConnect === false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Conexión DB fallida']); exit; }

function run($c,$sql,$p=[]) { $s=sqlsrv_query($c,$sql,$p); if($s===false) return ['error'=>sqlsrv_errors()];
  $r=[]; while($x=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC))$r[]=$x; sqlsrv_free_stmt($s); return $r; }
function jsonFail($rows,$c){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Consulta fallida','detalle'=>$rows['error']]); sqlsrv_close($c); exit; }

/** Pivota filas agg (con ventas ya incluida en cada fila) a tidy. Agrega la medida derivada disphold (disponible+hold). */
function ensamblarTidy($agg, $keyField) {
    $tallasSet=[]; $filas=[];
    $kpi=['siembra'=>0,'disponible'=>0,'hold'=>0,'ventas'=>0,'sobrantes'=>0,'faltante'=>0];
    foreach ($agg as $r) {
        $k=$r[$keyField]; $talla=(string)$r['talla']; $tallasSet[$talla]=true;
        $si=(int)$r['siembra']; $di=(int)$r['disponible']; $ho=(int)$r['hold']; $ve=(int)($r['ventas']??0);
        $bal=$si-($di+$ho); $fal=max(0,$bal); $sob=max(0,-$bal);
        if(!isset($filas[$k])) $filas[$k]=['key'=>$r,'valores'=>[]];
        foreach(['siembra'=>$si,'disponible'=>$di,'hold'=>$ho,'disphold'=>$di+$ho,'sobrante'=>$sob,'faltante'=>$fal,'ventas'=>$ve] as $m=>$val)
            $filas[$k]['valores'][$m][$talla]=($filas[$k]['valores'][$m][$talla]??0)+$val;
        $kpi['siembra']+=$si; $kpi['disponible']+=$di; $kpi['hold']+=$ho; $kpi['ventas']+=$ve; $kpi['sobrantes']+=$sob; $kpi['faltante']+=$fal;
    }
    $tallas=array_keys($tallasSet);
    usort($tallas, fn($a,$b)=>(is_numeric($a)&&is_numeric($b))?($a<=>$b):strcmp($a,$b));
    return [array_values($filas), $tallas, $kpi];
}

/** Arma la jerarquía grupos→almacenes→negocios desde filas planas de #base+Bodegas.
 *  KPIs de cantidad se acumulan sobre TODOS los grupos (incluido CEDI). */
function ensamblarArbol($rows) {
    $tallasSet=[]; $arbol=[];
    $kpi=['siembra'=>0,'disponible'=>0,'hold'=>0,'ventas'=>0,'sobrantes'=>0,'faltante'=>0];
    foreach ($rows as $r) {
        $g=$r['grupo']; $ll=$r['llave']; $neg=$r['negocio']; $talla=(string)$r['talla']; $tallasSet[$talla]=true;
        $si=(int)$r['siembra']; $di=(int)$r['disponible']; $ho=(int)$r['hold']; $ve=(int)$r['ventas'];
        $bal=$si-($di+$ho); $fal=max(0,$bal); $sob=max(0,-$bal);
        if(!isset($arbol[$g])) $arbol[$g]=['grupo'=>$g,'almacenes'=>[]];
        if(!isset($arbol[$g]['almacenes'][$ll])) $arbol[$g]['almacenes'][$ll]=['llave'=>$ll,'bodega'=>$r['bodega'],'nombre'=>$r['nombre'],'negocios'=>[]];
        if(!isset($arbol[$g]['almacenes'][$ll]['negocios'][$neg])) $arbol[$g]['almacenes'][$ll]['negocios'][$neg]=['negocio'=>$neg,'referencia'=>$r['referencia'],'color'=>$r['color'],'valores'=>[]];
        $vals=&$arbol[$g]['almacenes'][$ll]['negocios'][$neg]['valores'];
        foreach(['siembra'=>$si,'disponible'=>$di,'hold'=>$ho,'disphold'=>$di+$ho,'sobrante'=>$sob,'faltante'=>$fal,'ventas'=>$ve] as $m=>$v)
            $vals[$m][$talla]=($vals[$m][$talla]??0)+$v;
        unset($vals);
        $kpi['siembra']+=$si; $kpi['disponible']+=$di; $kpi['hold']+=$ho; $kpi['ventas']+=$ve; $kpi['sobrantes']+=$sob; $kpi['faltante']+=$fal;
    }
    $grupos=[];
    foreach($arbol as $g){
        $g['almacenes']=array_values(array_map(function($a){ $a['negocios']=array_values($a['negocios']); return $a; }, $g['almacenes']));
        $grupos[]=$g;
    }
    $tallas=array_keys($tallasSet);
    usort($tallas, fn($a,$b)=>(is_numeric($a)&&is_numeric($b))?($a<=>$b):strcmp($a,$b));
    return [$grupos, $tallas, $kpi];
}

/** KPIs de conteo (red, incluido CEDI) desde #base. */
function kpiCounts($c) {
    $r = run($c, "
        SELECT
          (SELECT COUNT(DISTINCT cia+'|'+negocio) FROM #base)              negocios,
          (SELECT COUNT(DISTINCT cia+'|'+negocio) FROM #base WHERE siembra>0) negocios_con_siembra,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE siembra>0) tiendas_con_siembra,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE disponible>0) tiendas_con_inv,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE ventas<>0) tiendas_con_venta");
    if (isset($r['error']) || !$r) return [];
    return array_map('intval', $r[0]);
}

// --- #refs del proveedor ---
if (!buildRefsTemp($dbConnect, getRefsCached($dbConnect, $proveedor))) jsonFail(['error'=>sqlsrv_errors()], $dbConnect);

// Filtros de dimensión de referencia: podar #refs → cae en las 4 fuentes (todas la inner-joinan).
if ($tab === 'b' || $tab === 'c' || $tab === 'reco') {   // producto/SKU acotan B/C/KPIs y reco; bodega solo B/C
    foreach ($FILTROS_REF as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $d = sqlsrv_query($dbConnect, "DELETE FROM #refs WHERE $col NOT IN ($ph)", $vals);
        if ($d === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($d);
    }
}

// --- #base unificada (CREATE separado + WITH...INSERT; cia normalizada a 3 díg) ---
// Ventas se incluye en el UNIVERSO de filas (no solo siembra/disp/hold) para no perder tiendas/negocios
// con inventario cero hoy pero con ventas en el período. Se omite en 'reco' (no la usa) por rendimiento.
$cre = sqlsrv_query($dbConnect, "CREATE TABLE #base (cia varchar(10), bodega varchar(20), negocio varchar(120),
    referencia varchar(50), color varchar(40), talla varchar(40), siembra int, disponible int, hold int, ventas int)");
if ($cre===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($cre);

$filtroCia = $cia !== '' ? " WHERE k.cia = ? " : "";
$pCia = $cia !== '' ? [$cia] : [];

// Detal cubre 2026+, Acum ≤2025 → solo unir Acum si el rango llega a 2025 o antes (evita escanear 2.7M filas en vano).
$wantVentas = ($tab !== 'reco');
$inclAcum   = ($desde <= '2025-12-31');
$vCte=''; $vUnion=''; $vJoin=''; $vSel='0'; $pVentas=[];
if ($wantVentas) {
    $acum = $inclAcum ? "
      UNION ALL
      SELECT rtrim(CIA), rtrim(BODEGA), rtrim(REFERENCIA), rtrim(COLOR), rtrim(TALLA), CANTIDAD
      FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?" : "";
    $vCte = ",
  v AS (
    SELECT vv.cia, vv.bodega, vv.referencia, vv.color, vv.talla, SUM(CAST(vv.cantidad AS int)) q
    FROM (
      SELECT RIGHT('000'+rtrim(CIA),3) cia, rtrim(BODEGA) bodega, rtrim(REFERENCIA) referencia, rtrim(COLOR) color, rtrim(TALLA) talla, CANTIDAD cantidad
      FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?$acum
    ) vv INNER JOIN #refs r ON r.REFERENCIA = vv.referencia
    GROUP BY vv.cia, vv.bodega, vv.referencia, vv.color, vv.talla
  )";
    $vUnion = "    UNION SELECT cia,bodega,referencia,color,talla FROM v\n";
    $vJoin  = "   LEFT JOIN v ON v.cia=k.cia AND v.bodega=k.bodega AND v.referencia=k.referencia AND v.color=k.color AND v.talla=k.talla\n";
    $vSel   = "CAST(ISNULL(v.q,0) AS int)";
    $pVentas = $inclAcum ? [$desde,$hasta,$desde,$hasta] : [$desde,$hasta];
}

$insBase = "
  WITH s AS (
    SELECT RIGHT('000'+rtrim(f400_id_cia),3) cia, rtrim(f150_id) bodega, rtrim(f120_referencia) referencia,
           rtrim(f121_id_ext1_detalle) color, rtrim(f121_id_ext2_detalle) talla, SUM(CAST(f400_cant_nivel_min_1 AS int)) q
    FROM stanton.dbo.t400_cm_existencia
     INNER JOIN stanton.dbo.t150_mc_bodegas ON f150_rowid=f400_rowid_bodega
     INNER JOIN stanton.dbo.t121_mc_items_extensiones ON f121_rowid=f400_rowid_item_ext
     INNER JOIN stanton.dbo.t120_mc_items ON f120_rowid=f121_rowid_item
     INNER JOIN #refs r ON r.REFERENCIA = rtrim(f120_referencia)
    WHERE (f400_cant_nivel_min_1>0 OR f400_cant_nivel_pedido>0) AND f120_referencia<>'GIFTCARD'
    GROUP BY RIGHT('000'+rtrim(f400_id_cia),3),rtrim(f150_id),rtrim(f120_referencia),rtrim(f121_id_ext1_detalle),rtrim(f121_id_ext2_detalle)
  ),
  d AS (
    SELECT RIGHT('000'+rtrim(v.cia),3) cia, rtrim(v.bodega) bodega, rtrim(v.referencia) referencia,
           rtrim(v.color) color, rtrim(v.talla) talla, SUM(CAST(v.cantidad AS int)) q
    FROM INTEGRACION.dbo.inv_actual_PBI v WITH (NOLOCK)
     INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
    WHERE v.cia<>'001' AND v.COLUMNA1 IN ('INV1430','INV1435','400')
    GROUP BY RIGHT('000'+rtrim(v.cia),3),rtrim(v.bodega),rtrim(v.referencia),rtrim(v.color),rtrim(v.talla)
  ),
  h AS (
    SELECT RIGHT('000'+rtrim(v.cia),3) cia, rtrim(v.bodega_ent) bodega, rtrim(v.referencia) referencia,
           rtrim(v.color) color, rtrim(v.talla) talla, SUM(CAST(v.cantidad AS int)) q
    FROM INTEGRACION.dbo._hold_actual_PBI v WITH (NOLOCK)
     INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
    WHERE v.cia<>'001'
    GROUP BY RIGHT('000'+rtrim(v.cia),3),rtrim(v.bodega_ent),rtrim(v.referencia),rtrim(v.color),rtrim(v.talla)
  )$vCte,
  llaves AS (
    SELECT cia,bodega,referencia,color,talla FROM s
    UNION SELECT cia,bodega,referencia,color,talla FROM d
    UNION SELECT cia,bodega,referencia,color,talla FROM h
$vUnion  )
  INSERT INTO #base
  SELECT k.cia, k.bodega, k.referencia+'-'+k.color, k.referencia, k.color, k.talla,
         CAST(ISNULL(s.q,0) AS int), CAST(ISNULL(d.q,0) AS int), CAST(ISNULL(h.q,0) AS int), $vSel
  FROM llaves k
   LEFT JOIN s ON s.cia=k.cia AND s.bodega=k.bodega AND s.referencia=k.referencia AND s.color=k.color AND s.talla=k.talla
   LEFT JOIN d ON d.cia=k.cia AND d.bodega=k.bodega AND d.referencia=k.referencia AND d.color=k.color AND d.talla=k.talla
   LEFT JOIN h ON h.cia=k.cia AND h.bodega=k.bodega AND h.referencia=k.referencia AND h.color=k.color AND h.talla=k.talla
$vJoin  $filtroCia";
$ins = run($dbConnect, $insBase, array_merge($pVentas, $pCia));
if (isset($ins['error'])) jsonFail($ins, $dbConnect);

// Excluir bodegas ADMINISTRATIVAS (no son tiendas: garantías, muestras, inservibles, etc.) de TODO el informe.
// El CEDI no se ve afectado: su GRUPO es 'BODEGA' (y además se trata como grupo propio).
$delAdmin = sqlsrv_query($dbConnect, "
  DELETE b FROM #base AS b
  INNER JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK)
    ON rtrim(bo.COD)=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia
  WHERE rtrim(bo.GRUPO)='ADMINISTRATIVAS' AND b.bodega<>'CEDI'");
if ($delAdmin===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($delAdmin);

// Filtros color/talla (columnas de #base): aplican a B/C/KPIs y reco (acotan tallas/colores).
if ($tab === 'b' || $tab === 'c' || $tab === 'reco') {
    foreach ($FILTROS_SKU as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $d = sqlsrv_query($dbConnect, "DELETE FROM #base WHERE $col NOT IN ($ph)", $vals);
        if ($d === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($d);
    }
}
// Filtros de bodega (vía Bodegas, conservando CEDI): SOLO B/C — reco usa la red completa para la cascada.
if ($tab === 'b' || $tab === 'c') {
    foreach ($FILTROS_BOD as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $d = sqlsrv_query($dbConnect, "
            DELETE b FROM #base b
            LEFT JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK)
              ON bo.COD = b.bodega AND RIGHT('000'+rtrim(bo.CIA),3) = b.cia
            WHERE b.bodega <> 'CEDI' AND ISNULL(bo.$col,'') NOT IN ($ph)", $vals);
        if ($d === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($d);
    }
}

// ====================================================================
// TAB FILTROS — catálogo (combos + sku) del universo del proveedor para la cascada.
// No aplica filtros (se construyó #base sin ellos y sin cia). Cache diaria.
// ====================================================================
if ($tab === 'filtros') {
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/o14_filtros_' . md5($proveedor) . '.json';
    if (file_exists($cacheFile) && date('Y-m-d', filemtime($cacheFile)) === date('Y-m-d')) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['sku'])) { sqlsrv_close($dbConnect); echo json_encode($cached, JSON_UNESCAPED_UNICODE); exit; }
    }
    $rowsC = run($dbConnect, "
        SELECT DISTINCT
            r.MARCA, r.TIPO, r.CATEGORIA, r.SUBCATEGORIA, r.GENERO, r.PUBLICO_OBJETIVO, b.referencia,
            ISNULL(bo.GRUPO,'')            AS GRUPO,
            ISNULL(bo.NOMBRE,'')           AS NOMBRE,
            ISNULL(bo.CENTRO_COMERCIAL,'') AS CENTRO_COMERCIAL,
            ISNULL(bo.DEPTO,'')            AS DEPTO,
            ISNULL(bo.CIUDAD,'')           AS CIUDAD
        FROM #base b
         INNER JOIN #refs r ON r.REFERENCIA = b.referencia
         LEFT  JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD = b.bodega AND RIGHT('000'+rtrim(bo.CIA),3) = b.cia
        WHERE b.bodega <> 'CEDI'");
    if (isset($rowsC['error'])) jsonFail($rowsC, $dbConnect);
    $combos = array_map(fn($r) => [
        'marca'=>trim((string)$r['MARCA']), 'tipo'=>trim((string)$r['TIPO']), 'categoria'=>trim((string)$r['CATEGORIA']),
        'subcategoria'=>trim((string)$r['SUBCATEGORIA']), 'genero'=>trim((string)$r['GENERO']), 'publico'=>trim((string)$r['PUBLICO_OBJETIVO']),
        'referencia'=>trim((string)$r['referencia']),
        'grupo'=>trim((string)$r['GRUPO']), 'tienda'=>trim((string)$r['NOMBRE']),
        'centro_comercial'=>trim((string)$r['CENTRO_COMERCIAL']), 'depto'=>trim((string)$r['DEPTO']), 'ciudad'=>trim((string)$r['CIUDAD']),
    ], $rowsC);
    $rowsS = run($dbConnect, "SELECT DISTINCT referencia, color, talla FROM #base WHERE bodega <> 'CEDI'");
    if (isset($rowsS['error'])) jsonFail($rowsS, $dbConnect);
    $sku = array_map(fn($r) => [
        'referencia'=>trim((string)$r['referencia']), 'color'=>trim((string)$r['color']), 'talla'=>trim((string)$r['talla']),
    ], $rowsS);
    $out = ['ok'=>true, 'tab'=>'filtros', 'combos'=>$combos, 'sku'=>$sku];
    @file_put_contents($cacheFile, json_encode($out));
    sqlsrv_close($dbConnect);
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
// TAB B — por negocio (cia, ref-color); excluye CEDI
// ====================================================================
if ($tab === 'b') {
    $agg = run($dbConnect, "
        SELECT cia + '|' + negocio kb, cia, negocio, referencia, color, talla,
               SUM(siembra) siembra, SUM(disponible) disponible, SUM(hold) hold, SUM(ventas) ventas
        FROM #base
        GROUP BY cia, negocio, referencia, color, talla
        ORDER BY negocio");
    if (isset($agg['error'])) jsonFail($agg, $dbConnect);

    [$filas, $tallas, $kpi] = ensamblarTidy($agg, 'kb');
    $kpi['negocios']    = count($filas);
    $kpi['total_stock'] = $kpi['disponible'] + $kpi['hold'];

    $cnt = run($dbConnect, "
        SELECT
          (SELECT COUNT(DISTINCT cia+'|'+negocio) FROM #base WHERE siembra>0)    negocios_con_siembra,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE siembra>0)    tiendas_con_siembra,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE disponible>0) tiendas_con_inv,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE ventas<>0)    tiendas_con_venta");
    if (!isset($cnt['error']) && $cnt) {
        $kpi['negocios_con_siembra']=(int)$cnt[0]['negocios_con_siembra'];
        $kpi['tiendas_con_siembra']=(int)$cnt[0]['tiendas_con_siembra'];
        $kpi['tiendas_con_inv']=(int)$cnt[0]['tiendas_con_inv'];
        $kpi['tiendas_con_venta']=(int)$cnt[0]['tiendas_con_venta'];
    }

    sqlsrv_close($dbConnect);
    echo json_encode(['ok'=>true,'proveedor'=>$proveedorSesion,'tab'=>'b','rango'=>['desde'=>$desde,'hasta'=>$hasta],
        'tallas'=>$tallas,'medidas'=>['siembra','disponible','hold','disphold','sobrante','faltante','ventas'],
        'filas'=>$filas,'kpis'=>$kpi], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
// TAB C — árbol Grupo → Almacén → Negocio (todos los negocios); CEDI dentro de su grupo real (BODEGA)
// ====================================================================
if ($tab === 'c') {
    $rows = run($dbConnect, "
        SELECT
          ISNULL(bo.GRUPO,'SIN GRUPO') grupo,
          (b.cia + '-' + b.bodega) llave, b.cia, b.bodega,
          ISNULL(bo.NOMBRE, b.bodega) nombre, b.negocio, b.referencia, b.color, b.talla,
          SUM(b.siembra) siembra, SUM(b.disponible) disponible, SUM(b.hold) hold, SUM(b.ventas) ventas
        FROM #base b
         LEFT JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia
        GROUP BY ISNULL(bo.GRUPO,'SIN GRUPO'),
                 b.cia, b.bodega, bo.NOMBRE, b.negocio, b.referencia, b.color, b.talla
        ORDER BY ISNULL(bo.GRUPO,'SIN GRUPO'), llave, b.negocio");
    if (isset($rows['error'])) jsonFail($rows, $dbConnect);

    [$grupos, $tallas, $kpi] = ensamblarArbol($rows);
    $kpi['total_stock'] = $kpi['disponible'] + $kpi['hold'];
    $kpi = array_merge($kpi, kpiCounts($dbConnect));
    sqlsrv_close($dbConnect);
    echo json_encode(['ok'=>true,'tab'=>'c','rango'=>['desde'=>$desde,'hasta'=>$hasta],
        'tallas'=>$tallas,'medidas'=>['siembra','disponible','hold','disphold','sobrante','faltante','ventas'],
        'grupos'=>$grupos,'kpis'=>$kpi], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================================================================
// TAB RECO — cascada por (cia, negocio) reusando el motor
// ====================================================================
if ($tab === 'reco') {
    require __DIR__ . '/o14_recomendador.php';
    // Reco GENERAL: corre el motor por (cia, negocio) sobre #base filtrado (producto/SKU; bodega NO).
    $rows = run($dbConnect, "
        SELECT cia, bodega, negocio, referencia, color, talla,
               SUM(siembra) siembra, SUM(disponible) disponible, SUM(hold) hold
        FROM #base
        GROUP BY cia, bodega, negocio, referencia, color, talla");
    if (isset($rows['error'])) jsonFail($rows, $dbConnect);

    // Agrupar por (cia, negocio): tiendas (no-CEDI) + cedi (disponible).
    $porCiaNeg = [];
    foreach ($rows as $r) {
        $c=$r['cia']; $neg=$r['negocio']; $t=(string)$r['talla']; $bod=rtrim($r['bodega']);
        if (!isset($porCiaNeg[$c][$neg])) $porCiaNeg[$c][$neg]=['ref'=>$r['referencia'],'color'=>$r['color'],'tiendas'=>[],'cedi'=>[]];
        if ($bod==='CEDI') {
            $porCiaNeg[$c][$neg]['cedi'][$t] = ($porCiaNeg[$c][$neg]['cedi'][$t] ?? 0) + (int)$r['disponible'];
        } else {
            $porCiaNeg[$c][$neg]['tiendas'][$bod]['cod'] = $c.'-'.$bod;
            $porCiaNeg[$c][$neg]['tiendas'][$bod]['tallas'][$t] =
                ['siembra'=>(int)$r['siembra'],'disponible'=>(int)$r['disponible'],'hold'=>(int)$r['hold']];
        }
    }

    // Por negocio (agregando cías): sobrante/faltante raw sobre tiendas + proveedor (residual del motor).
    $negs = [];
    foreach ($porCiaNeg as $c => $porNeg) {
        foreach ($porNeg as $neg => $g) {
            if (!isset($negs[$neg])) $negs[$neg]=['referencia'=>$g['ref'],'color'=>$g['color'],'sobrante'=>[],'faltante'=>[],'proveedor'=>[]];
            foreach ($g['tiendas'] as $bod=>$tn) {
                foreach ($tn['tallas'] as $t=>$v) {
                    $bal = $v['siembra'] - ($v['disponible'] + $v['hold']);
                    if ($bal > 0)      $negs[$neg]['faltante'][$t] = ($negs[$neg]['faltante'][$t] ?? 0) + $bal;
                    elseif ($bal < 0)  $negs[$neg]['sobrante'][$t] = ($negs[$neg]['sobrante'][$t] ?? 0) + (-$bal);
                }
            }
            $plan = recomendar(array_values($g['tiendas']), $g['cedi']);
            foreach (($plan['solicitudes_proveedor'] ?? []) as $sp) {
                $t=(string)$sp['talla']; $negs[$neg]['proveedor'][$t] = ($negs[$neg]['proveedor'][$t] ?? 0) + (int)$sp['uds'];
            }
        }
    }
    sqlsrv_close($dbConnect);

    // Tidy: una fila por negocio con algún valor>0; tallas unión ordenada.
    $tallasSet=[]; $filas=[];
    foreach ($negs as $neg=>$v) {
        if ((array_sum($v['sobrante']) + array_sum($v['faltante']) + array_sum($v['proveedor'])) <= 0) continue;
        foreach (['sobrante','faltante','proveedor'] as $m) foreach ($v[$m] as $t=>$q) $tallasSet[(string)$t]=true;
        $filas[] = ['negocio'=>$neg,'referencia'=>$v['referencia'],'color'=>$v['color'],
                    'valores'=>['sobrante'=>$v['sobrante'],'faltante'=>$v['faltante'],'proveedor'=>$v['proveedor']]];
    }
    usort($filas, fn($a,$b)=>strcmp($a['negocio'],$b['negocio']));
    $tallas=array_keys($tallasSet);
    usort($tallas, fn($a,$b)=>(is_numeric($a)&&is_numeric($b))?($a<=>$b):strcmp($a,$b));

    echo json_encode(['ok'=>true,'tab'=>'reco','tallas'=>$tallas,
        'medidas'=>['sobrante','faltante','proveedor'],'filas'=>$filas], JSON_UNESCAPED_UNICODE);
    exit;
}

sqlsrv_close($dbConnect);
echo json_encode(['ok'=>false,'error'=>'tab desconocido']);
