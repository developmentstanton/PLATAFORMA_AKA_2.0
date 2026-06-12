<?php
/**
 * API Informe O45 — Índice de Ventas (una fila por negocio).
 * tab=data: cuadro; tab=filtros: catálogo de cascadas.
 * Stock = snapshot actual (inv_actual_PBI + _hold_actual_PBI). Fechas solo afectan ventas.
 * Hold: bodega = BODEGA_SAL (salida) — específico de O45 (O14 usa BODEGA_ENT/entrada).
 * Dos ventanas de ventas: [desde,hasta] (rango) y [hasta-29,hasta] (ventas30).
 * Spec: docs/superpowers/specs/2026-06-10-o45-indice-ventas-design.md
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }

$proveedorSesion = $_SESSION['proveedor'] ?? '';
$proveedor = $proveedorSesion !== '' ? $proveedorSesion : '__SIN_PROVEEDOR__';
$tab   = $_GET['tab'] ?? 'data';
$desde = $_GET['desde'] ?? '2025-01-01';
$hasta = $_GET['hasta'] ?? date('Y-m-d', strtotime('-1 day'));   // O45: tope = ayer (hoy - 1 día)
$w30desde = date('Y-m-d', strtotime($hasta . ' -29 days'));   // ventana de 30 días que termina en hasta
$dias  = (int) floor((strtotime($hasta) - strtotime($desde)) / 86400) + 1;
if ($dias < 1) $dias = 1;

$FILTROS_REF = ['marca'=>'MARCA','tipo'=>'TIPO','categoria'=>'CATEGORIA','subcategoria'=>'SUBCATEGORIA','genero'=>'GENERO','publico'=>'PUBLICO_OBJETIVO','referencia'=>'REFERENCIA'];
// El filtro `tienda[]` va por NOMBRE. `tienda_cod` (COD) que expone tab=filtros es solo para
// mostrar "COD - NOMBRE" y desduplicar en el front; NO se usa como valor de filtro.
$FILTROS_BOD = ['grupo'=>'GRUPO','tienda'=>'NOMBRE'];
function getMulti($key) { $v = $_GET[$key] ?? []; if (!is_array($v)) $v = ($v === '' ? [] : [$v]);
    return array_values(array_filter(array_map('trim', $v), fn($x) => $x !== '')); }

require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/lib_refs.php';
if ($dbConnect === false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Conexión DB fallida']); exit; }

function run($c,$sql,$p=[]) { $s=sqlsrv_query($c,$sql,$p); if($s===false) return ['error'=>sqlsrv_errors()];
  $r=[]; while($x=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC))$r[]=$x; sqlsrv_free_stmt($s); return $r; }
function jsonFail($rows,$c){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Consulta fallida','detalle'=>$rows['error']]); sqlsrv_close($c); exit; }

// --- #refs del proveedor ---
if (!buildRefsTemp($dbConnect, getRefsCached($dbConnect, $proveedor))) jsonFail(['error'=>sqlsrv_errors()], $dbConnect);

// Filtros de dimensión (fila 2): podan #refs. (En tab=filtros NO se podan: catálogo completo.)
if ($tab === 'data') {
    foreach ($FILTROS_REF as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $x = sqlsrv_query($dbConnect, "DELETE FROM #refs WHERE $col NOT IN ($ph)", $vals);
        if ($x === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($x);
    }
}

// === Corte de stock: foto viva (fechada ayer) o corte de fin de mes <= hasta ===
$fv = run($dbConnect, "SELECT TOP 1 CONVERT(varchar(10),FECHA,120) f FROM INTEGRACION.dbo.inv_actual_PBI WITH (NOLOCK)");
$fechaViva = (!isset($fv['error']) && $fv && !empty($fv[0]['f'])) ? $fv[0]['f'] : date('Y-m-d', strtotime('-1 day'));
if ($hasta >= $fechaViva) {
    $modoStock = 'vivo'; $corteStock = null;
} else {
    $modoStock = 'corte';
    $finMes = date('Y-m-t', strtotime($hasta));                 // ultimo dia del mes de hasta
    $corteStock = ($hasta >= $finMes) ? $finMes                  // hasta ES fin de mes
                : date('Y-m-t', strtotime(date('Y-m-01', strtotime($hasta)) . ' -1 day')); // fin del mes anterior
}

// --- #base: disponible, hold, ventas (rango), ventas30 (30d hasta hasta) ---
$cre = sqlsrv_query($dbConnect, "CREATE TABLE #base (cia varchar(10), bodega varchar(20), negocio varchar(120),
    referencia varchar(50), color varchar(40), talla varchar(40),
    disponible int, hold int, ventas int, ventas30 int, inv_hist int)");
if ($cre===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($cre);

// #inv_hist: llaves (cia,bodega,ref,color,talla) con inventario>0 en ALGUN corte de fin de mes dentro del rango.
$creH = sqlsrv_query($dbConnect, "CREATE TABLE #inv_hist (cia varchar(10), bodega varchar(20),
    referencia varchar(50), color varchar(40), talla varchar(40))");
if ($creH===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($creH);
// Solo se puebla en tab=data (su escaneo histórico no debe afectar la carga del catálogo de filtros).
if ($tab === 'data') {
    $insHist = "INSERT INTO #inv_hist
      SELECT DISTINCT cia,bodega,referencia,color,talla FROM (
        SELECT RIGHT('000'+rtrim(hi.CIA),3) cia, rtrim(hi.BODEGA) bodega, rtrim(hi.REFERENCIA) referencia,
               rtrim(hi.COLOR) color, rtrim(hi.TALLA) talla
          FROM INTEGRACION.dbo.historico_inventarios_PBI hi WITH (NOLOCK)
           INNER JOIN #refs r ON r.REFERENCIA = rtrim(hi.REFERENCIA)
          WHERE hi.FECHA BETWEEN ? AND ? AND hi.CIA<>'001'
                AND rtrim(hi.COLUMNA1) IN ('INV1430','INV1435','400') AND CAST(hi.CANTIDAD AS int) > 0
        UNION
        SELECT RIGHT('000'+rtrim(hh.CIA),3), rtrim(hh.BODEGA_SAL), rtrim(hh.REFERENCIA), rtrim(hh.COLOR), rtrim(hh.TALLA)
          FROM INTEGRACION.dbo.historico_hold_PBI hh WITH (NOLOCK)
           INNER JOIN #refs r ON r.REFERENCIA = rtrim(hh.REFERENCIA)
          WHERE hh.FECHA BETWEEN ? AND ? AND hh.CIA<>'001' AND CAST(hh.CANTIDAD AS int) > 0
      ) z";
    $rh = run($dbConnect, $insHist, [$desde,$hasta,$desde,$hasta]);
    if (isset($rh['error'])) jsonFail($rh, $dbConnect);
}

// Partición de ventas: Ventas_Detal_PBI cubre 2026+ y Ventas_Detal_Acum_PBI ≤2025 (sin solape).
// Solo se une Acum si la ventana toca ≤2025 (evita escanear 2.7M filas en vano y NO duplica años).
$acumV   = ($desde    <= '2025-12-31') ? "UNION ALL SELECT rtrim(CIA),rtrim(BODEGA),rtrim(REFERENCIA),rtrim(COLOR),rtrim(TALLA),CANTIDAD FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?" : "";
$acumV30 = ($w30desde <= '2025-12-31') ? "UNION ALL SELECT rtrim(CIA),rtrim(BODEGA),rtrim(REFERENCIA),rtrim(COLOR),rtrim(TALLA),CANTIDAD FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?" : "";

// CTE de stock (d=disponible, h=hold) según el modo: vivo (foto) o corte (fin de mes histórico).
if ($modoStock === 'vivo') {
    $dCte = "d AS (
        SELECT RIGHT('000'+rtrim(v.cia),3) cia, rtrim(v.bodega) bodega, rtrim(v.referencia) referencia,
               rtrim(v.color) color, rtrim(v.talla) talla, SUM(CAST(v.cantidad AS int)) q
        FROM INTEGRACION.dbo.inv_actual_PBI v WITH (NOLOCK)
         INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
        WHERE v.cia<>'001' AND v.COLUMNA1 IN ('INV1430','INV1435','400')
        GROUP BY RIGHT('000'+rtrim(v.cia),3),rtrim(v.bodega),rtrim(v.referencia),rtrim(v.color),rtrim(v.talla))";
    $hCte = "h AS (
        SELECT RIGHT('000'+rtrim(v.cia),3) cia, rtrim(v.bodega_sal) bodega, rtrim(v.referencia) referencia,
               rtrim(v.color) color, rtrim(v.talla) talla, SUM(CAST(v.cantidad AS int)) q
        FROM INTEGRACION.dbo._hold_actual_PBI v WITH (NOLOCK)
         INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
        WHERE v.cia<>'001'
        GROUP BY RIGHT('000'+rtrim(v.cia),3),rtrim(v.bodega_sal),rtrim(v.referencia),rtrim(v.color),rtrim(v.talla))";
    $pStock = [];
} else {
    $dCte = "d AS (
        SELECT RIGHT('000'+rtrim(v.CIA),3) cia, rtrim(v.BODEGA) bodega, rtrim(v.REFERENCIA) referencia,
               rtrim(v.COLOR) color, rtrim(v.TALLA) talla, SUM(CAST(v.CANTIDAD AS int)) q
        FROM INTEGRACION.dbo.historico_inventarios_PBI v WITH (NOLOCK)
         INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.REFERENCIA)
        WHERE v.FECHA = ? AND v.CIA<>'001' AND rtrim(v.COLUMNA1) IN ('INV1430','INV1435','400')
        GROUP BY RIGHT('000'+rtrim(v.CIA),3),rtrim(v.BODEGA),rtrim(v.REFERENCIA),rtrim(v.COLOR),rtrim(v.TALLA))";
    $hCte = "h AS (
        SELECT RIGHT('000'+rtrim(v.CIA),3) cia, rtrim(v.BODEGA_SAL) bodega, rtrim(v.REFERENCIA) referencia,
               rtrim(v.COLOR) color, rtrim(v.TALLA) talla, SUM(CAST(v.CANTIDAD AS int)) q
        FROM INTEGRACION.dbo.historico_hold_PBI v WITH (NOLOCK)
         INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.REFERENCIA)
        WHERE v.FECHA = ? AND v.CIA<>'001'
        GROUP BY RIGHT('000'+rtrim(v.CIA),3),rtrim(v.BODEGA_SAL),rtrim(v.REFERENCIA),rtrim(v.COLOR),rtrim(v.TALLA))";
    $pStock = [$corteStock, $corteStock];
}

$insBase = "
  WITH $dCte,
  $hCte,
  ventas_src AS (
    SELECT RIGHT('000'+rtrim(CIA),3) cia, rtrim(BODEGA) bodega, rtrim(REFERENCIA) referencia, rtrim(COLOR) color, rtrim(TALLA) talla, CANTIDAD
    FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ? $acumV
  ),
  v AS (
    SELECT vv.cia, vv.bodega, vv.referencia, vv.color, vv.talla, SUM(CAST(vv.CANTIDAD AS int)) q
    FROM ventas_src vv INNER JOIN #refs r ON r.REFERENCIA = vv.referencia
    GROUP BY vv.cia, vv.bodega, vv.referencia, vv.color, vv.talla
  ),
  ventas30_src AS (
    SELECT RIGHT('000'+rtrim(CIA),3) cia, rtrim(BODEGA) bodega, rtrim(REFERENCIA) referencia, rtrim(COLOR) color, rtrim(TALLA) talla, CANTIDAD
    FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ? $acumV30
  ),
  v30 AS (
    SELECT vv.cia, vv.bodega, vv.referencia, vv.color, vv.talla, SUM(CAST(vv.CANTIDAD AS int)) q
    FROM ventas30_src vv INNER JOIN #refs r ON r.REFERENCIA = vv.referencia
    GROUP BY vv.cia, vv.bodega, vv.referencia, vv.color, vv.talla
  ),
  llaves AS (
    SELECT cia,bodega,referencia,color,talla FROM d
    UNION SELECT cia,bodega,referencia,color,talla FROM h
    UNION SELECT cia,bodega,referencia,color,talla FROM v
    UNION SELECT cia,bodega,referencia,color,talla FROM v30
    UNION SELECT cia,bodega,referencia,color,talla FROM #inv_hist
  )
  INSERT INTO #base
  SELECT k.cia, k.bodega, k.referencia+'-'+k.color, k.referencia, k.color, k.talla,
         CAST(ISNULL(d.q,0) AS int), CAST(ISNULL(h.q,0) AS int), CAST(ISNULL(v.q,0) AS int), CAST(ISNULL(v30.q,0) AS int),
         CASE WHEN ih.cia IS NOT NULL THEN 1 ELSE 0 END
  FROM llaves k
   LEFT JOIN d   ON d.cia=k.cia AND d.bodega=k.bodega AND d.referencia=k.referencia AND d.color=k.color AND d.talla=k.talla
   LEFT JOIN h   ON h.cia=k.cia AND h.bodega=k.bodega AND h.referencia=k.referencia AND h.color=k.color AND h.talla=k.talla
   LEFT JOIN v   ON v.cia=k.cia AND v.bodega=k.bodega AND v.referencia=k.referencia AND v.color=k.color AND v.talla=k.talla
   LEFT JOIN v30 ON v30.cia=k.cia AND v30.bodega=k.bodega AND v30.referencia=k.referencia AND v30.color=k.color AND v30.talla=k.talla
   LEFT JOIN #inv_hist ih ON ih.cia=k.cia AND ih.bodega=k.bodega AND ih.referencia=k.referencia AND ih.color=k.color AND ih.talla=k.talla";

// Orden de params: stock(corte) ; ventas_src(desde,hasta) [+acumV(desde,hasta)] ; ventas30_src(w30desde,hasta) [+acumV30(w30desde,hasta)]
$p = $pStock;                       // <- corte de stock primero (si aplica)
array_push($p, $desde, $hasta);     // ventas_src
if ($acumV   !== '') array_push($p, $desde, $hasta);
array_push($p, $w30desde, $hasta);  // ventas30_src
if ($acumV30 !== '') array_push($p, $w30desde, $hasta);
$ins = run($dbConnect, $insBase, $p);
if (isset($ins['error'])) jsonFail($ins, $dbConnect);

// Excluir bodegas ADMINISTRATIVAS (no son tiendas), conservando CEDI.
$delAdmin = sqlsrv_query($dbConnect, "
  DELETE b FROM #base AS b
  INNER JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK)
    ON rtrim(bo.COD)=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia
  WHERE rtrim(bo.GRUPO)='ADMINISTRATIVAS' AND b.bodega<>'CEDI'");
if ($delAdmin===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($delAdmin);

// Filtro Negocio (ref-color) sobre #base. Solo tab=data.
if ($tab === 'data') {
    $negVals = getMulti('negocio');
    if ($negVals) {
        $ph = implode(',', array_fill(0, count($negVals), '?'));
        $x = sqlsrv_query($dbConnect, "DELETE FROM #base WHERE negocio NOT IN ($ph)", $negVals);
        if ($x === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($x);
    }
}

// Filtro Grupo/Tienda (vía Bodegas), conservando CEDI. Solo tab=data.
if ($tab === 'data') {
    foreach ($FILTROS_BOD as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $x = sqlsrv_query($dbConnect, "
            DELETE b FROM #base b
            LEFT JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK)
              ON bo.COD = b.bodega AND RIGHT('000'+rtrim(bo.CIA),3) = b.cia
            WHERE b.bodega <> 'CEDI' AND ISNULL(bo.$col,'') NOT IN ($ph)", $vals);
        if ($x === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($x);
    }
}

// ===== tab=filtros: catálogo del universo del proveedor (sin filtros aplicados) =====
if ($tab === 'filtros') {
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/o45_filtros_' . md5($proveedor) . '.json';
    if (file_exists($cacheFile) && date('Y-m-d', filemtime($cacheFile)) === date('Y-m-d')) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['combos'])) { sqlsrv_close($dbConnect); echo json_encode($cached, JSON_UNESCAPED_UNICODE); exit; }
    }
    $rowsC = run($dbConnect, "
        SELECT DISTINCT
            r.MARCA, r.TIPO, r.CATEGORIA, r.SUBCATEGORIA, r.GENERO, r.PUBLICO_OBJETIVO, b.referencia,
            b.referencia+'-'+b.color AS negocio,
            ISNULL(bo.GRUPO,'')  AS GRUPO,
            rtrim(b.bodega)      AS COD,
            ISNULL(bo.NOMBRE,'') AS NOMBRE
        FROM #base b
         INNER JOIN #refs r ON r.REFERENCIA = b.referencia
         LEFT  JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD = b.bodega AND RIGHT('000'+rtrim(bo.CIA),3) = b.cia
        WHERE b.bodega <> 'CEDI'");
    if (isset($rowsC['error'])) jsonFail($rowsC, $dbConnect);
    $combos = array_map(fn($r) => [
        'marca'=>trim((string)$r['MARCA']), 'tipo'=>trim((string)$r['TIPO']), 'categoria'=>trim((string)$r['CATEGORIA']),
        'subcategoria'=>trim((string)$r['SUBCATEGORIA']), 'genero'=>trim((string)$r['GENERO']), 'publico'=>trim((string)$r['PUBLICO_OBJETIVO']),
        'referencia'=>trim((string)$r['referencia']), 'negocio'=>trim((string)$r['negocio']),
        'grupo'=>trim((string)$r['GRUPO']), 'tienda'=>trim((string)$r['NOMBRE']), 'tienda_cod'=>trim((string)$r['COD']),
    ], $rowsC);
    $out = ['ok'=>true, 'tab'=>'filtros', 'combos'=>$combos];
    @file_put_contents($cacheFile, json_encode($out));
    sqlsrv_close($dbConnect);
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== tab=data: agregación por negocio =====
if ($tab === 'data') {
    $agg = run($dbConnect, "
        SELECT b.cia, b.negocio, b.referencia, b.color,
               MAX(r.MARCA) marca,
               SUM(CASE WHEN b.bodega<>'CEDI' THEN b.ventas   ELSE 0 END) ventas,
               SUM(CASE WHEN b.bodega<>'CEDI' THEN b.ventas30 ELSE 0 END) ventas30,
               SUM(CASE WHEN b.bodega='CEDI'  THEN b.disponible+b.hold ELSE 0 END) stock_cedi,
               SUM(CASE WHEN b.bodega<>'CEDI' THEN b.disponible+b.hold ELSE 0 END) stock_tiendas,
               COUNT(DISTINCT CASE WHEN b.disponible+b.hold>0 OR b.inv_hist=1 OR b.ventas<>0 THEN b.talla END) tallas,
               -- #tiendas: stock del corte O inventario en algun corte del rango O ventas; excl. grupos BODEGA/ADMINISTRATIVAS.
               COUNT(DISTINCT CASE WHEN ISNULL(bo.GRUPO,'') NOT IN ('BODEGA','ADMINISTRATIVAS')
                                    AND (b.disponible+b.hold>0 OR b.inv_hist=1 OR b.ventas<>0) THEN b.cia+'-'+b.bodega END) tiendas
        FROM #base b
         INNER JOIN #refs r ON r.REFERENCIA = b.referencia
         LEFT  JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia
        GROUP BY b.cia, b.negocio, b.referencia, b.color
        ORDER BY ventas DESC");
    if (isset($agg['error'])) jsonFail($agg, $dbConnect);

    $precioMap = [];
    $pr = run($dbConnect, "
        SELECT rtrim(f120_referencia) referencia, rtrim(f121_id_ext1_detalle) color, MAX(f126_precio) precio
        FROM INTEGRACION.dbo.LISTA_PRECIOS_DETAL
        GROUP BY f120_referencia, f121_id_ext1_detalle");
    if (!isset($pr['error'])) foreach ($pr as $x)
        $precioMap[trim((string)$x['referencia']) . '|' . trim((string)$x['color'])] = (float)$x['precio'];

    $filas = [];
    $tot = ['ventas'=>0,'ventas30'=>0,'stock_cedi'=>0,'stock_tiendas'=>0,'total_stock'=>0];
    foreach ($agg as $r) {
        $ventas = (int)$r['ventas']; $ventas30 = (int)$r['ventas30'];
        $cedi = (int)$r['stock_cedi']; $tiendasStock = (int)$r['stock_tiendas'];
        $total_stock = $cedi + $tiendasStock;
        $ind_inv = $ventas30 > 0 ? round($total_stock / $ventas30, 2) : null;
        $ind_vm  = (int)$r['tiendas'] > 0 ? round(($ventas / (int)$r['tiendas']) / ($dias / 30), 2) : 0.0;
        $precio = $precioMap[trim((string)$r['referencia']) . '|' . trim((string)$r['color'])] ?? null;
        $filas[] = [
            'negocio'=>$r['negocio'], 'referencia'=>trim((string)$r['referencia']), 'color'=>trim((string)$r['color']),
            'marca'=>trim((string)$r['marca']),
            'ventas'=>$ventas, 'tiendas'=>(int)$r['tiendas'], 'ventas30'=>$ventas30,
            'stock_cedi'=>$cedi, 'stock_tiendas'=>$tiendasStock, 'total_stock'=>$total_stock,
            'ind_inventario'=>$ind_inv, 'ind_ventas_mes'=>$ind_vm,
            'tallas'=>(int)$r['tallas'],
            'precio'=>$precio,
        ];
        $tot['ventas']+=$ventas; $tot['ventas30']+=$ventas30; $tot['stock_cedi']+=$cedi;
        $tot['stock_tiendas']+=$tiendasStock; $tot['total_stock']+=$total_stock;
    }
    // #tiendas global (distintas con stock disp+hold>0 O ventas<>0; excl. grupos BODEGA y ADMINISTRATIVAS), para la fila TOTAL.
    $ct = run($dbConnect, "
        SELECT COUNT(DISTINCT b.cia+'-'+b.bodega) n
        FROM #base b
         LEFT JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia
        WHERE ISNULL(bo.GRUPO,'') NOT IN ('BODEGA','ADMINISTRATIVAS') AND (b.disponible+b.hold>0 OR b.inv_hist=1 OR b.ventas<>0)");
    $tot['tiendas'] = (!isset($ct['error']) && $ct) ? (int)$ct[0]['n'] : 0;

    $tot['ind_inventario'] = $tot['ventas30'] > 0 ? round($tot['total_stock'] / $tot['ventas30'], 2) : null;
    $tot['ind_ventas_mes'] = $tot['tiendas']  > 0 ? round(($tot['ventas'] / $tot['tiendas']) / ($dias / 30), 2) : 0.0;

    // Orden final: por Índice de Ventas mes, de mayor a menor.
    usort($filas, fn($a, $b) => $b['ind_ventas_mes'] <=> $a['ind_ventas_mes']);

    sqlsrv_close($dbConnect);
    echo json_encode(['ok'=>true, 'proveedor'=>$proveedorSesion,
        'rango'=>['desde'=>$desde,'hasta'=>$hasta,'w30desde'=>$w30desde,'dias'=>$dias,
                  'stock_corte'=>($modoStock==='vivo' ? 'vivo' : $corteStock)],
        'filas'=>$filas, 'total'=>$tot], JSON_UNESCAPED_UNICODE);
    exit;
}

sqlsrv_close($dbConnect);
echo json_encode(['ok'=>false,'error'=>'tab desconocido']);
