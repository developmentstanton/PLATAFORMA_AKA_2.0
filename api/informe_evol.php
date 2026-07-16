<?php
/**
 * API Informe Evolución Histórica — matriz negocio × mes.
 * 6 medidas: compras (Cauchosol), ventas (Total Ventas), stock (cierre),
 * tiendas (con inventario), mesesInv (stock/ventas), indice (ventas/tiendas / dias·30).
 * tab=data: matriz tidy {meses[], negocios[]}; tab=filtros: catálogo cascada (idéntico a O45).
 * Fuentes verificadas 2026-06-12. Spec: docs/superpowers/specs/2026-06-12-evolucion-historica-design.md
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }

$proveedorSesion = $_SESSION['proveedor'] ?? '';
$proveedor = $proveedorSesion !== '' ? $proveedorSesion : '__SIN_PROVEEDOR__';
// El manejador de sesiones de PHP mantiene un lock EXCLUSIVO sobre el archivo de sesión durante
// toda la petición: el dashboard dispara tab=filtros y tab=data juntas y quedaban en fila (medido
// en WMS-LAB: data 547ms sola -> 42s detrás de filtros). Ya está leído todo lo que se necesita de
// $_SESSION y ningún endpoint de api/ escribe en ella -> soltar el lock aquí. Ver
// tests/evol_session_lock_test.php. NO leer $_SESSION después de esta línea.
session_write_close();
$tab = $_GET['tab'] ?? 'data';

// ===== Cache diario del catálogo de filtros: se consulta AQUÍ, antes de conectar y de construir
// #base (:157-241). Vivía dentro del bloque tab=filtros de abajo, DESPUÉS del build, así que el
// hit costaba lo mismo que el miss (~19s en WMS-LAB) y solo ahorraba el SELECT DISTINCT final.
// Un hit no necesita BD: es leer un .json. La escritura sigue abajo, donde se arma $out. =====
$filtrosCacheFile = __DIR__ . '/../cache/evol_filtros_' . md5($proveedor) . '.json';
if ($tab === 'filtros' && is_file($filtrosCacheFile)
    && date('Y-m-d', filemtime($filtrosCacheFile)) === date('Y-m-d')) {
    $cached = json_decode((string) @file_get_contents($filtrosCacheFile), true);
    if (is_array($cached) && isset($cached['combos'])) { echo json_encode($cached, JSON_UNESCAPED_UNICODE); exit; }
}

// === Eje de meses (Año-Mes). Default: enero del año pasado .. mes actual. Tope = mes actual. ===
$mesActual = date('Y-m');
$desdeMes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['desde'] ?? '')) ? $_GET['desde'] : (date('Y')-1).'-01';
$hastaMes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['hasta'] ?? '')) ? $_GET['hasta'] : $mesActual;
if ($hastaMes > $mesActual) $hastaMes = $mesActual;
if ($desdeMes > $hastaMes) $desdeMes = $hastaMes;
$meses = [];
for ($c = $desdeMes.'-01'; $c <= $hastaMes.'-01'; $c = date('Y-m-01', strtotime($c.' +1 month'))) $meses[] = substr($c,0,7);

// Cortes fin-de-mes para meses pasados (el mes en curso usa snapshot vivo).
$cortes = [];  // ['YYYY-MM' => 'YYYY-MM-DD']
foreach ($meses as $m) if ($m !== $mesActual) $cortes[$m] = date('Y-m-t', strtotime($m.'-01'));
$cortesVals = array_values($cortes);
$incluyeMesActual = in_array($mesActual, $meses, true);

// Ventana de fechas para ventas/compras: del 1er día del primer mes a AYER (corte, igual que O45),
// pero sin pasar del fin del último mes del rango.
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
require_once __DIR__ . '/lib_evol_disk.php';
if ($dbConnect === false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Conexión DB fallida']); exit; }

function run($c,$sql,$p=[]) { $s=sqlsrv_query($c,$sql,$p); if($s===false) return ['error'=>sqlsrv_errors()];
  $r=[]; while($x=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC))$r[]=$x; sqlsrv_free_stmt($s); return $r; }
function jsonFail($rows,$c){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Consulta fallida','detalle'=>$rows['error']??$rows]); sqlsrv_close($c); exit; }

/** Arma el WHERE de filtros (REF/negocio/BOD) sobre las columnas del cache (prefijo `c.`) + sus
 *  params. Espejo en tiempo de lectura de los DELETE de #refs/#base del camino vivo (nocache).
 *  Columnas del cache en minúscula (strtolower del valor de $FILTROS_*): tienda→nombre,
 *  publico→publico_objetivo, etc. BOD conserva CEDI igual que el DELETE del endpoint
 *  (c.bodega='CEDI' pasa siempre). */
function construirFiltrosCache() {
    global $FILTROS_REF, $FILTROS_BOD;
    $where = ''; $params = [];
    foreach ($FILTROS_REF as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $where .= " AND c." . strtolower($col) . " IN ($ph)";
        $params = array_merge($params, $vals);
    }
    $negVals = getMulti('negocio');
    if ($negVals) {
        $ph = implode(',', array_fill(0, count($negVals), '?'));
        $where .= " AND c.negocio IN ($ph)";
        $params = array_merge($params, $negVals);
    }
    foreach ($FILTROS_BOD as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $where .= " AND (c.bodega = 'CEDI' OR ISNULL(c." . strtolower($col) . ",'') IN ($ph))";
        $params = array_merge($params, $vals);
    }
    return [$where, $params];
}

// --- #refs del proveedor (cache compartida g00_refs_*) ---
if (!buildRefsFromMat($dbConnect, $proveedor)) jsonFail(['error'=>sqlsrv_errors()], $dbConnect);

// Camino cache-first (default) vs camino vivo/oráculo (?nocache=1). Solo tab=data usa cache;
// 'filtros' y tabs desconocidos siguen el camino vivo (build de #base). En modo cache #refs NO se
// poda (el cache guarda el universo del proveedor); los filtros REF/negocio/BOD se aplican como
// WHERE en tiempo de lectura (construirFiltrosCache), no como DELETE.
$nocache   = !empty($_GET['nocache']);
$cacheMode = !$nocache && ($tab === 'data');
$ekey = null; $whereFiltros = ''; $paramsFiltros = [];
if ($cacheMode) {
    require_once __DIR__ . '/lib_evol_cache.php';
    // $desdeMes/$hastaMes YA normalizados arriba (:19-22): la key y el contenido cacheado son 1:1.
    $ekey = evolCacheKey($proveedor, $desdeMes, $hastaMes);
    [$whereFiltros, $paramsFiltros] = construirFiltrosCache();
}

// ===== Corto-circuito de cache en disco: SOLO tab=data cache-mode SIN filtros REF/BOD/negocio. =====
// DISCO-PRIMERO: el hit sirve gz SIN materializar evol_cache_base (patrón o45). Miss -> flock +
// double-check + ensure(force) + build + write (ok-gate) + serve. Lock-fail -> cae al camino de
// filas de abajo (que materializa la base con ensure normal).
if ($tab === 'data' && $cacheMode) {
    $evolSinFiltros = true;
    foreach (array_merge($FILTROS_REF, $FILTROS_BOD) as $k=>$col) { if (getMulti($k)) { $evolSinFiltros=false; break; } }
    if ($evolSinFiltros && getMulti('negocio')) $evolSinFiltros = false;  // negocio es un filtro aparte (construirFiltrosCache)
    if ($evolSinFiltros) {
        if (evolDiskFresh($dbConnect, $ekey)) {
            $gz = evolReadPayload($ekey);
            if ($gz !== null) { sqlsrv_close($dbConnect); evolServeGz($gz); exit; }
        }
        $lockPath = diskCachePath('evol',$ekey) . '.lock';
        $lk = @fopen($lockPath,'c');
        if ($lk && flock($lk, LOCK_EX)) {
            if (evolDiskFresh($dbConnect, $ekey)) { $gz=evolReadPayload($ekey);
                if ($gz!==null){ flock($lk,LOCK_UN); fclose($lk); sqlsrv_close($dbConnect); evolServeGz($gz); exit; } }
            // Miss real: capturar stamp de fuente ANTES de materializar (como o45), luego forzar
            // rebuild de la base (la fuente cambió), construir el payload y cachearlo.
            $stamp = evolCurrentStamp($dbConnect);
            if (!ensureEvolCacheBase($dbConnect, $ekey, $desdeMes, $hastaMes, true)) { flock($lk,LOCK_UN); fclose($lk); jsonFail(['error'=>sqlsrv_errors()], $dbConnect); }
            evolCacheCleanup($dbConnect); evolCleanup();
            $payload = evolBuildPayload($dbConnect, $proveedorSesion, $ekey, $desdeMes, $hastaMes);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $okPayload = (($payload['ok'] ?? false) === true);
            if ($okPayload && $stamp !== null) evolWritePayload($ekey, $json, $stamp);  // NO cachear errores transitorios
            flock($lk,LOCK_UN); fclose($lk); sqlsrv_close($dbConnect);
            if ($okPayload) { evolServeGz(gzencode($json,6)); }
            else { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); echo $json; }
            exit;
        }
        if ($lk) fclose($lk);
        // lock-fail -> cae al camino de filas de abajo (intacto)
    }
}

// ===== Materializar evol_cache_base para los caminos cache-mode que LEEN de ella (filtrado, o
// no-filtro que cayó por lock-fail). El hit/miss sin filtro de arriba ya hizo exit. nocache NO
// entra aquí (construye #base vivo abajo). =====
if ($cacheMode) {
    if (!ensureEvolCacheBase($dbConnect, $ekey, $desdeMes, $hastaMes)) jsonFail(['error'=>sqlsrv_errors()], $dbConnect);
    evolCacheCleanup($dbConnect); evolCleanup();
}

// Filtros de dimensión (podan #refs). En tab=filtros/cache NO se podan (catálogo/universo completo).
if (!$cacheMode && $tab === 'data') {
    foreach ($FILTROS_REF as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $x = sqlsrv_query($dbConnect, "DELETE FROM #refs WHERE $col NOT IN ($ph)", $vals);
        if ($x === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($x);
    }
}

// En modo cache NO se construye #base ni se aplican los DELETE de filtros: tab=data lee directo del
// cache con filtros como WHERE. Este bloque es el camino vivo (nocache) y el de 'filtros'.
if (!$cacheMode) {
// === #base a granularidad (negocio, mes, cia, bodega): cada fila aporta UNA medida; se agrega al final. ===
$cre = sqlsrv_query($dbConnect, "CREATE TABLE #base (negocio varchar(120), mes char(7), cia varchar(10),
    bodega varchar(20), referencia varchar(50), color varchar(40), ventas int, compras int, stock int)");
if ($cre===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($cre);

// --- Ventas (Detal 2026 + Acum <=2025), por negocio×mes×cía×bodega ---
$acumV = ($desdeF <= '2025-12-31')
  ? "UNION ALL SELECT rtrim(REFERENCIA)+'-'+rtrim(COLOR), CONVERT(varchar(7),FECHA,120), RIGHT('000'+rtrim(CIA),3), rtrim(BODEGA), rtrim(REFERENCIA), rtrim(COLOR), CAST(CANTIDAD AS int)
       FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?" : "";
$insV = "INSERT INTO #base (negocio,mes,cia,bodega,referencia,color,ventas,compras,stock)
  SELECT vs.negocio, vs.mes, vs.cia, vs.bodega, vs.referencia, vs.color, SUM(vs.q), 0, 0
  FROM (
    SELECT rtrim(REFERENCIA)+'-'+rtrim(COLOR) negocio, CONVERT(varchar(7),FECHA,120) mes,
           RIGHT('000'+rtrim(CIA),3) cia, rtrim(BODEGA) bodega, rtrim(REFERENCIA) referencia, rtrim(COLOR) color, CAST(CANTIDAD AS int) q
    FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?
    $acumV
  ) vs INNER JOIN #refs r ON r.REFERENCIA = vs.referencia
  GROUP BY vs.negocio, vs.mes, vs.cia, vs.bodega, vs.referencia, vs.color";
$pV = [$desdeF,$hastaF]; if ($acumV!=='') array_push($pV,$desdeF,$hastaF);
$rv = run($dbConnect,$insV,$pV); if (isset($rv['error'])) jsonFail($rv,$dbConnect);

// --- Compras (mov_inv_actual año actual + historico_mov_inv <=2025), TIPO_DOCTO de compra, cia<>052 ---
$compFilt = "TIPO_DOCTO IN ('DMC','DVC','EAC','EMC') AND cia<>'052' AND FECHA BETWEEN ? AND ?";
$srcActual = ($hastaF >= date('Y').'-01-01')
  ? "SELECT rtrim(REFERENCIA)+'-'+rtrim(COLOR) negocio, CONVERT(varchar(7),FECHA,120) mes, RIGHT('000'+rtrim(CIA),3) cia, rtrim(BODEGA) bodega, rtrim(REFERENCIA) referencia, rtrim(COLOR) color, CAST(CANT_NET AS int) q
       FROM INTEGRACION.dbo.mov_inv_actual_PBI WITH (NOLOCK) WHERE $compFilt" : "";
$srcHist = ($desdeF <= (date('Y')-1).'-12-31')
  ? "SELECT rtrim(REFERENCIA)+'-'+rtrim(COLOR), CONVERT(varchar(7),FECHA,120), RIGHT('000'+rtrim(CIA),3), rtrim(BODEGA), rtrim(REFERENCIA), rtrim(COLOR), CAST(CANT_NET AS int)
       FROM INTEGRACION.dbo.historico_mov_inv_PBI WITH (NOLOCK) WHERE $compFilt" : "";
$compSrc = trim($srcActual . (($srcActual && $srcHist) ? "\n    UNION ALL " : "") . $srcHist);
if ($compSrc !== '') {
    $insC = "INSERT INTO #base (negocio,mes,cia,bodega,referencia,color,ventas,compras,stock)
      SELECT cs.negocio, cs.mes, cs.cia, cs.bodega, cs.referencia, cs.color, 0, SUM(cs.q), 0
      FROM ( $compSrc ) cs INNER JOIN #refs r ON r.REFERENCIA = cs.referencia
      GROUP BY cs.negocio, cs.mes, cs.cia, cs.bodega, cs.referencia, cs.color";
    // Orden de params $pC: srcActual(desde,hasta) [si aplica] ; srcHist(desde,hasta) [si aplica].
    $pC = []; if ($srcActual) array_push($pC,$desdeF,$hastaF); if ($srcHist) array_push($pC,$desdeF,$hastaF);
    $rc = run($dbConnect,$insC,$pC); if (isset($rc['error'])) jsonFail($rc,$dbConnect);
}

// --- Stock por cortes fin-de-mes (disp + hold), filtros idénticos a O45 ---
if ($cortesVals) {
    $ph = implode(',', array_fill(0, count($cortesVals), '?'));
    // disponible
    $rsd = run($dbConnect, "INSERT INTO #base (negocio,mes,cia,bodega,referencia,color,ventas,compras,stock)
      SELECT rtrim(hi.REFERENCIA)+'-'+rtrim(hi.COLOR), CONVERT(varchar(7),hi.FECHA,120), RIGHT('000'+rtrim(hi.CIA),3),
             rtrim(hi.BODEGA), rtrim(hi.REFERENCIA), rtrim(hi.COLOR), 0, 0, SUM(CAST(hi.CANTIDAD AS int))
      FROM INTEGRACION.dbo.historico_inventarios_PBI hi WITH (NOLOCK)
       INNER JOIN #refs r ON r.REFERENCIA = rtrim(hi.REFERENCIA)
      WHERE hi.FECHA IN ($ph) AND hi.CIA<>'001' AND rtrim(hi.COLUMNA1) IN ('INV1430','INV1435','400')
      GROUP BY rtrim(hi.REFERENCIA)+'-'+rtrim(hi.COLOR), CONVERT(varchar(7),hi.FECHA,120), RIGHT('000'+rtrim(hi.CIA),3), rtrim(hi.BODEGA), rtrim(hi.REFERENCIA), rtrim(hi.COLOR)", $cortesVals);
    if (isset($rsd['error'])) jsonFail($rsd,$dbConnect);
    // hold (bodega = BODEGA_SAL, igual que O45)
    $rsh = run($dbConnect, "INSERT INTO #base (negocio,mes,cia,bodega,referencia,color,ventas,compras,stock)
      SELECT rtrim(hh.REFERENCIA)+'-'+rtrim(hh.COLOR), CONVERT(varchar(7),hh.FECHA,120), RIGHT('000'+rtrim(hh.CIA),3),
             rtrim(hh.BODEGA_SAL), rtrim(hh.REFERENCIA), rtrim(hh.COLOR), 0, 0, SUM(CAST(hh.CANTIDAD AS int))
      FROM INTEGRACION.dbo.historico_hold_PBI hh WITH (NOLOCK)
       INNER JOIN #refs r ON r.REFERENCIA = rtrim(hh.REFERENCIA)
      WHERE hh.FECHA IN ($ph) AND hh.CIA<>'001'
      GROUP BY rtrim(hh.REFERENCIA)+'-'+rtrim(hh.COLOR), CONVERT(varchar(7),hh.FECHA,120), RIGHT('000'+rtrim(hh.CIA),3), rtrim(hh.BODEGA_SAL), rtrim(hh.REFERENCIA), rtrim(hh.COLOR)", $cortesVals);
    if (isset($rsh['error'])) jsonFail($rsh,$dbConnect);
}
// --- Stock vivo para el mes en curso (si está en el rango) ---
if ($incluyeMesActual) {
    $rvd = run($dbConnect, "INSERT INTO #base (negocio,mes,cia,bodega,referencia,color,ventas,compras,stock)
      SELECT rtrim(v.referencia)+'-'+rtrim(v.color), '$mesActual', RIGHT('000'+rtrim(v.cia),3),
             rtrim(v.bodega), rtrim(v.referencia), rtrim(v.color), 0, 0, SUM(CAST(v.cantidad AS int))
      FROM INTEGRACION.dbo.inv_actual_PBI v WITH (NOLOCK)
       INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
      WHERE v.cia<>'001' AND v.COLUMNA1 IN ('INV1430','INV1435','400')
      GROUP BY rtrim(v.referencia)+'-'+rtrim(v.color), RIGHT('000'+rtrim(v.cia),3), rtrim(v.bodega), rtrim(v.referencia), rtrim(v.color)");
    if (isset($rvd['error'])) jsonFail($rvd,$dbConnect);
    $rvh = run($dbConnect, "INSERT INTO #base (negocio,mes,cia,bodega,referencia,color,ventas,compras,stock)
      SELECT rtrim(v.referencia)+'-'+rtrim(v.color), '$mesActual', RIGHT('000'+rtrim(v.cia),3),
             rtrim(v.bodega_sal), rtrim(v.referencia), rtrim(v.color), 0, 0, SUM(CAST(v.cantidad AS int))
      FROM INTEGRACION.dbo._hold_actual_PBI v WITH (NOLOCK)
       INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
      WHERE v.cia<>'001'
      GROUP BY rtrim(v.referencia)+'-'+rtrim(v.color), RIGHT('000'+rtrim(v.cia),3), rtrim(v.bodega_sal), rtrim(v.referencia), rtrim(v.color)");
    if (isset($rvh['error'])) jsonFail($rvh,$dbConnect);
}

// Excluir bodegas ADMINISTRATIVAS (no son tiendas), conservando CEDI. (Igual que O45.)
$delAdmin = sqlsrv_query($dbConnect, "
  DELETE b FROM #base AS b
  INNER JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK)
    ON rtrim(bo.COD)=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia
  WHERE rtrim(bo.GRUPO)='ADMINISTRATIVAS' AND b.bodega<>'CEDI'");
if ($delAdmin===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($delAdmin);

// Filtro Negocio + Grupo/Tienda (vía Bodegas), conservando CEDI. Solo tab=data.
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
            WHERE b.bodega <> 'CEDI' AND ISNULL(bo.$col,'') NOT IN ($ph)", $vals);
        if ($x===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($x);
    }
}
} // fin del bloque !$cacheMode (build #base + DELETE ADMIN + filtros negocio/BOD)

// ===== tab=filtros: catálogo del universo del proveedor (idéntico a O45, cache evol_filtros_*) =====
if ($tab === 'filtros') {
    // El hit ya salió arriba (:16-24). Aquí solo se llega en miss -> construir y cachear.
    $cacheDir = __DIR__ . '/../cache'; if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $rowsC = run($dbConnect, "
        SELECT DISTINCT r.MARCA, r.TIPO, r.CATEGORIA, r.SUBCATEGORIA, r.GENERO, r.PUBLICO_OBJETIVO, b.referencia,
            b.negocio, ISNULL(bo.GRUPO,'') AS GRUPO, rtrim(b.bodega) AS COD, ISNULL(bo.NOMBRE,'') AS NOMBRE
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
    @file_put_contents($filtrosCacheFile, json_encode($out));
    sqlsrv_close($dbConnect); echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
}

// ===== tab=data: agregación por (negocio, mes) y ensamble tidy =====
// En modo cache la fuente es el cache denormalizado (grupo es columna → sin LEFT JOIN Bodegas);
// en nocache es #base + LEFT JOIN Bodegas. Mismo GROUP BY/pivot en ambos.
if ($cacheMode) {
    $agg = run($dbConnect, "
      WITH perbod AS (
        SELECT c.negocio, c.mes, c.cia, c.bodega, MAX(c.referencia) referencia, MAX(c.color) color,
               SUM(c.ventas) ventas, SUM(c.compras) compras, SUM(c.stock) stock, MAX(ISNULL(c.grupo,'')) grupo
        FROM INTEGRACION.dbo.evol_cache_base c
        WHERE c.cache_key=? $whereFiltros
        GROUP BY c.negocio, c.mes, c.cia, c.bodega )
      SELECT pb.negocio, pb.mes, MAX(pb.referencia) referencia, MAX(pb.color) color,
             SUM(pb.ventas) ventas, SUM(pb.compras) compras, SUM(pb.stock) stock,
             COUNT(DISTINCT CASE WHEN pb.stock>0 AND pb.grupo NOT IN ('BODEGA','ADMINISTRATIVAS')
                                  THEN pb.cia+'-'+pb.bodega END) tiendas
      FROM perbod pb
      GROUP BY pb.negocio, pb.mes
      ORDER BY pb.negocio, pb.mes", array_merge([$ekey], $paramsFiltros));
} else {
    $agg = run($dbConnect, "
      WITH perbod AS (
        SELECT b.negocio, b.mes, b.cia, b.bodega, MAX(b.referencia) referencia, MAX(b.color) color,
               SUM(b.ventas) ventas, SUM(b.compras) compras, SUM(b.stock) stock
        FROM #base b GROUP BY b.negocio, b.mes, b.cia, b.bodega )
      SELECT pb.negocio, pb.mes, MAX(pb.referencia) referencia, MAX(pb.color) color,
             SUM(pb.ventas) ventas, SUM(pb.compras) compras, SUM(pb.stock) stock,
             COUNT(DISTINCT CASE WHEN pb.stock>0 AND ISNULL(bo.GRUPO,'') NOT IN ('BODEGA','ADMINISTRATIVAS')
                                  THEN pb.cia+'-'+pb.bodega END) tiendas
      FROM perbod pb
       LEFT JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD=pb.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=pb.cia
      GROUP BY pb.negocio, pb.mes
      ORDER BY pb.negocio, pb.mes");
}
if (isset($agg['error'])) jsonFail($agg, $dbConnect);

// marca por negocio (para columna/Excel y sort estable)
$marcaMap = [];
$rm = $cacheMode
  ? run($dbConnect, "SELECT c.negocio, MAX(c.marca) marca FROM INTEGRACION.dbo.evol_cache_base c WHERE c.cache_key=? $whereFiltros GROUP BY c.negocio", array_merge([$ekey], $paramsFiltros))
  : run($dbConnect, "SELECT b.negocio, MAX(r.MARCA) marca FROM #base b INNER JOIN #refs r ON r.REFERENCIA=b.referencia GROUP BY b.negocio");
if (!isset($rm['error'])) foreach ($rm as $x) $marcaMap[$x['negocio']] = trim((string)$x['marca']);

// días por mes: mes pasado = días del mes; mes en curso = día de AYER (corte O45).
$diasMes = function($m) use ($mesActual) {
    if ($m === $mesActual) return max(1, (int)date('j', strtotime('-1 day')));
    return (int)date('t', strtotime($m.'-01'));
};

$neg = [];   // negocio => fila tidy
foreach ($agg as $r) {
    $key = $r['negocio'];
    if (!isset($neg[$key])) $neg[$key] = [
        'negocio'=>$key, 'referencia'=>trim((string)$r['referencia']), 'color'=>trim((string)$r['color']),
        'marca'=>$marcaMap[$key] ?? '', 'foto'=>$key,
        'valores'=>['compras'=>[], 'ventas'=>[], 'stock'=>[], 'tiendas'=>[], 'mesesInv'=>[], 'indice'=>[]],
        'totales'=>['compras'=>0, 'ventas'=>0],
    ];
    $m = $r['mes'];
    $ventas = (int)$r['ventas']; $compras = (int)$r['compras']; $stock = (int)$r['stock']; $tiendas = (int)$r['tiendas'];
    $N =& $neg[$key];
    $N['valores']['ventas'][$m]  = $ventas;
    $N['valores']['compras'][$m] = $compras;
    $N['valores']['stock'][$m]   = $stock;
    $N['valores']['tiendas'][$m] = $tiendas;
    $N['valores']['mesesInv'][$m]= $ventas > 0 ? (int)round($stock / $ventas) : 0;
    $N['valores']['indice'][$m]  = $tiendas > 0 ? round(($ventas / $tiendas) / ($diasMes($m) / 30), 2) : 0.0;
    $N['totales']['compras'] += $compras;
    $N['totales']['ventas']  += $ventas;
    unset($N);
}
// Orden: por total de ventas desc (como O45).
$negocios = array_values($neg);
usort($negocios, fn($a,$b) => $b['totales']['ventas'] <=> $a['totales']['ventas']);

// === TOTAL general por mes (todos los negocios). Ingreso/Ventas/Stock = suma;
//     Tiendas = conteo distinto de tiendas con inventario (NO suma, evita doble conteo);
//     Meses de Inv e Índice se RECALCULAN sobre los totales (mismas fórmulas por-negocio). ===
if ($cacheMode) {
    $aggTot = run($dbConnect, "
      WITH perbod AS (
        SELECT c.mes, c.cia, c.bodega, SUM(c.ventas) ventas, SUM(c.compras) compras, SUM(c.stock) stock,
               MAX(ISNULL(c.grupo,'')) grupo
        FROM INTEGRACION.dbo.evol_cache_base c
        WHERE c.cache_key=? $whereFiltros
        GROUP BY c.mes, c.cia, c.bodega )
      SELECT pb.mes,
             SUM(pb.ventas) ventas, SUM(pb.compras) compras, SUM(pb.stock) stock,
             COUNT(DISTINCT CASE WHEN pb.stock>0 AND pb.grupo NOT IN ('BODEGA','ADMINISTRATIVAS')
                                  THEN pb.cia+'-'+pb.bodega END) tiendas
      FROM perbod pb
      GROUP BY pb.mes", array_merge([$ekey], $paramsFiltros));
} else {
    $aggTot = run($dbConnect, "
      WITH perbod AS (
        SELECT b.mes, b.cia, b.bodega, SUM(b.ventas) ventas, SUM(b.compras) compras, SUM(b.stock) stock
        FROM #base b GROUP BY b.mes, b.cia, b.bodega )
      SELECT pb.mes,
             SUM(pb.ventas) ventas, SUM(pb.compras) compras, SUM(pb.stock) stock,
             COUNT(DISTINCT CASE WHEN pb.stock>0 AND ISNULL(bo.GRUPO,'') NOT IN ('BODEGA','ADMINISTRATIVAS')
                                  THEN pb.cia+'-'+pb.bodega END) tiendas
      FROM perbod pb
       LEFT JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD=pb.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=pb.cia
      GROUP BY pb.mes");
}
if (isset($aggTot['error'])) jsonFail($aggTot, $dbConnect);

$totalGeneral = [
    'valores'=>['compras'=>[], 'ventas'=>[], 'stock'=>[], 'tiendas'=>[], 'mesesInv'=>[], 'indice'=>[]],
    'totales'=>['compras'=>0, 'ventas'=>0],
];
foreach ($aggTot as $r) {
    $m = $r['mes'];
    $ventas=(int)$r['ventas']; $compras=(int)$r['compras']; $stock=(int)$r['stock']; $tiendas=(int)$r['tiendas'];
    $totalGeneral['valores']['ventas'][$m]   = $ventas;
    $totalGeneral['valores']['compras'][$m]  = $compras;
    $totalGeneral['valores']['stock'][$m]    = $stock;
    $totalGeneral['valores']['tiendas'][$m]  = $tiendas;
    $totalGeneral['valores']['mesesInv'][$m] = $ventas > 0 ? (int)round($stock / $ventas) : 0;
    $totalGeneral['valores']['indice'][$m]   = $tiendas > 0 ? round(($ventas / $tiendas) / ($diasMes($m) / 30), 2) : 0.0;
    $totalGeneral['totales']['compras'] += $compras;
    $totalGeneral['totales']['ventas']  += $ventas;
}

sqlsrv_close($dbConnect);
echo json_encode([
    'ok'=>true, 'proveedor'=>$proveedorSesion,
    'meses'=>$meses, 'mesActual'=>$mesActual,
    'rango'=>['desde'=>$desdeMes,'hasta'=>$hastaMes,'corte_ventas'=>$hastaF],
    'negocios'=>$negocios,
    'totalGeneral'=>$totalGeneral,
], JSON_UNESCAPED_UNICODE);
exit;
