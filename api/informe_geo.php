<?php
/**
 * API Informe Georeferenciación — tiendas donde vendió el proveedor, con ventas (valor + unidades).
 * tab=data: lista de tiendas {cia,cod,nombre,grupo,ciudad,lat,lng,valor,unidades} + contadores.
 * tab=filtros: catálogo de cascada (idéntico a Evolución/O45, cache geo_filtros_*).
 * Rango Año-Mes (igual que Evolución). Coordenadas via lib_geo::parseCoord.
 * Spec: docs/superpowers/specs/2026-06-12-georreferenciacion-design.md
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }

$proveedorSesion = $_SESSION['proveedor'] ?? '';
$proveedor = $proveedorSesion !== '' ? $proveedorSesion : '__SIN_PROVEEDOR__';
$tab = $_GET['tab'] ?? 'data';

// === Rango Año-Mes (igual que Evolución): default ene-año-pasado .. mes actual; tope mes actual. ===
$mesActual = date('Y-m');
$desdeMes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['desde'] ?? '')) ? $_GET['desde'] : (date('Y')-1).'-01';
$hastaMes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['hasta'] ?? '')) ? $_GET['hasta'] : $mesActual;
if ($hastaMes > $mesActual) $hastaMes = $mesActual;
if ($desdeMes > $hastaMes) $desdeMes = $hastaMes;
$ayer   = date('Y-m-d', strtotime('-1 day'));
$desdeF = $desdeMes.'-01';
$hastaF = date('Y-m-t', strtotime($hastaMes.'-01'));
if ($hastaF > $ayer) $hastaF = $ayer;

$FILTROS_REF = ['marca'=>'MARCA','tipo'=>'TIPO','categoria'=>'CATEGORIA','subcategoria'=>'SUBCATEGORIA','genero'=>'GENERO','publico'=>'PUBLICO_OBJETIVO','referencia'=>'REFERENCIA'];
$FILTROS_BOD = ['grupo'=>'GRUPO','tienda'=>'NOMBRE'];
function getMulti($key) { $v = $_GET[$key] ?? []; if (!is_array($v)) $v = ($v === '' ? [] : [$v]);
    return array_values(array_filter(array_map('trim', $v), fn($x) => $x !== '')); }

require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/lib_refs.php';
require __DIR__ . '/lib_geo.php';
if ($dbConnect === false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Conexión DB fallida']); exit; }

function run($c,$sql,$p=[]) { $s=sqlsrv_query($c,$sql,$p); if($s===false) return ['error'=>sqlsrv_errors()];
  $r=[]; while($x=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC))$r[]=$x; sqlsrv_free_stmt($s); return $r; }
function jsonFail($rows,$c){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Consulta fallida','detalle'=>$rows['error']??$rows]); sqlsrv_close($c); exit; }

// --- #refs del proveedor (cache compartida g00_refs_*) ---
if (!buildRefsTemp($dbConnect, getRefsCached($dbConnect, $proveedor))) jsonFail(['error'=>sqlsrv_errors()], $dbConnect);

// Filtros de dimensión (podan #refs). En tab=filtros NO se podan.
if ($tab === 'data') {
    foreach ($FILTROS_REF as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $x = sqlsrv_query($dbConnect, "DELETE FROM #refs WHERE $col NOT IN ($ph)", $vals);
        if ($x === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($x);
    }
}

// === #base: ventas por (cía,bodega,negocio,ref) en el rango ===
$cre = sqlsrv_query($dbConnect, "CREATE TABLE #base (cia varchar(10), bodega varchar(20), negocio varchar(120),
    referencia varchar(50), valor float, unidades int)");
if ($cre===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($cre);

$acumV = ($desdeF <= '2025-12-31')
  ? "UNION ALL SELECT RIGHT('000'+rtrim(CIA),3), rtrim(BODEGA), rtrim(REFERENCIA)+'-'+rtrim(COLOR), rtrim(REFERENCIA), CAST(VALOR AS float), CAST(CANTIDAD AS int)
       FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?" : "";
$insV = "INSERT INTO #base (cia,bodega,negocio,referencia,valor,unidades)
  SELECT vs.cia, vs.bodega, vs.negocio, vs.referencia, SUM(vs.valor), SUM(vs.unidades)
  FROM (
    SELECT RIGHT('000'+rtrim(CIA),3) cia, rtrim(BODEGA) bodega, rtrim(REFERENCIA)+'-'+rtrim(COLOR) negocio,
           rtrim(REFERENCIA) referencia, CAST(VALOR AS float) valor, CAST(CANTIDAD AS int) unidades
    FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?
    $acumV
  ) vs INNER JOIN #refs r ON r.REFERENCIA = vs.referencia
  GROUP BY vs.cia, vs.bodega, vs.negocio, vs.referencia";
$pV = [$desdeF,$hastaF]; if ($acumV!=='') array_push($pV,$desdeF,$hastaF);
$rv = run($dbConnect,$insV,$pV); if (isset($rv['error'])) jsonFail($rv,$dbConnect);

// Excluir bodegas ADMINISTRATIVAS (no son tiendas). (CEDI no vende, no aparece; igual se mantiene la regla.)
$delAdmin = sqlsrv_query($dbConnect, "
  DELETE b FROM #base AS b
  INNER JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK)
    ON rtrim(bo.COD)=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia
  WHERE rtrim(bo.GRUPO)='ADMINISTRATIVAS'");
if ($delAdmin===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($delAdmin);

// Filtro Negocio + Grupo/Tienda (vía Bodegas). Solo tab=data.
if ($tab === 'data') {
    $negVals = getMulti('negocio');
    if ($negVals) {
        $ph = implode(',', array_fill(0, count($negVals), '?'));
        $x = sqlsrv_query($dbConnect, "DELETE FROM #base WHERE negocio NOT IN ($ph)", $negVals);
        if ($x===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($x);
    }
    foreach ($FILTROS_BOD as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $x = sqlsrv_query($dbConnect, "
            DELETE b FROM #base b
            LEFT JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK)
              ON bo.COD = b.bodega AND RIGHT('000'+rtrim(bo.CIA),3) = b.cia
            WHERE ISNULL(bo.$col,'') NOT IN ($ph)", $vals);
        if ($x===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($x);
    }
}

// ===== tab=filtros: catálogo del universo del proveedor (cache geo_filtros_*) =====
if ($tab === 'filtros') {
    $cacheDir = __DIR__ . '/../cache'; if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/geo_filtros_' . md5($proveedor) . '.json';
    if (file_exists($cacheFile) && date('Y-m-d', filemtime($cacheFile)) === date('Y-m-d')) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['combos'])) { sqlsrv_close($dbConnect); echo json_encode($cached, JSON_UNESCAPED_UNICODE); exit; }
    }
    $rowsC = run($dbConnect, "
        SELECT DISTINCT r.MARCA, r.TIPO, r.CATEGORIA, r.SUBCATEGORIA, r.GENERO, r.PUBLICO_OBJETIVO, b.referencia,
            b.negocio, ISNULL(bo.GRUPO,'') AS GRUPO, rtrim(b.bodega) AS COD, ISNULL(bo.NOMBRE,'') AS NOMBRE
        FROM #base b
         INNER JOIN #refs r ON r.REFERENCIA = b.referencia
         LEFT  JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD = b.bodega AND RIGHT('000'+rtrim(bo.CIA),3) = b.cia");
    if (isset($rowsC['error'])) jsonFail($rowsC, $dbConnect);
    $combos = array_map(fn($r) => [
        'marca'=>trim((string)$r['MARCA']), 'tipo'=>trim((string)$r['TIPO']), 'categoria'=>trim((string)$r['CATEGORIA']),
        'subcategoria'=>trim((string)$r['SUBCATEGORIA']), 'genero'=>trim((string)$r['GENERO']), 'publico'=>trim((string)$r['PUBLICO_OBJETIVO']),
        'referencia'=>trim((string)$r['referencia']), 'negocio'=>trim((string)$r['negocio']),
        'grupo'=>trim((string)$r['GRUPO']), 'tienda'=>trim((string)$r['NOMBRE']), 'tienda_cod'=>trim((string)$r['COD']),
    ], $rowsC);
    $out = ['ok'=>true, 'tab'=>'filtros', 'combos'=>$combos];
    @file_put_contents($cacheFile, json_encode($out));
    sqlsrv_close($dbConnect); echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
}

// ===== tab=data: agregación por tienda (cía,bodega) =====
$agg = run($dbConnect, "
    SELECT b.cia, b.bodega cod, MAX(bo.NOMBRE) nombre, MAX(bo.GRUPO) grupo, MAX(bo.CIUDAD) ciudad,
           MAX(bo.COORDENADAS) coords, SUM(b.valor) valor, SUM(b.unidades) unidades
    FROM #base b
     LEFT JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia
    GROUP BY b.cia, b.bodega
    ORDER BY valor DESC");
if (isset($agg['error'])) jsonFail($agg, $dbConnect);

$tiendas = []; $sinCoord = 0;
foreach ($agg as $r) {
    [$lat, $lng] = parseCoord($r['coords'] ?? '');
    if ($lat === null) $sinCoord++;
    $tiendas[] = [
        'cia'=>trim((string)$r['cia']), 'cod'=>trim((string)$r['cod']),
        'nombre'=>trim((string)$r['nombre']), 'grupo'=>trim((string)$r['grupo']),
        'ciudad'=>trim((string)$r['ciudad']), 'lat'=>$lat, 'lng'=>$lng,
        'valor'=>round((float)$r['valor'], 2), 'unidades'=>(int)$r['unidades'],
    ];
}

sqlsrv_close($dbConnect);
echo json_encode([
    'ok'=>true, 'proveedor'=>$proveedorSesion,
    'rango'=>['desde'=>$desdeMes,'hasta'=>$hastaMes,'corte_ventas'=>$hastaF],
    'tiendas'=>$tiendas, 'total_tiendas'=>count($tiendas), 'sin_coord'=>$sinCoord,
], JSON_UNESCAPED_UNICODE);
exit;
