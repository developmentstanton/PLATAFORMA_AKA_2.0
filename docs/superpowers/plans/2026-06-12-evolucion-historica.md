# Evolución Histórica — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir el informe "Evolución Histórica" — una matriz por negocio (ref-color) × mes con 6 medidas apiladas (Compras Cauchosol, Total Ventas, Stock, Tiendas con Inv, Meses de Inv, Índice Ventas Detal Mes), reemplazando el placeholder del menú REPORTES.

**Architecture:** Endpoint PHP `api/informe_evol.php` (clon-y-extiende de `api/informe_o45.php`): reusa `lib_refs.php` (`#refs` del proveedor) y el patrón de filtros; arma un `#base` a granularidad (negocio, mes, cía, bodega) alimentado por 3 fuentes (ventas Detal+Acum, compras mov_inv actual+histórico, stock por cortes fin-de-mes + vivo), agrega por (negocio, mes) y devuelve JSON tidy. Frontend `informes/evol.php` pivota a una matriz HTML ancha estilo O14. Wiring en `dashboard.php`.

**Tech Stack:** PHP 8 + `sqlsrv` (SQL Server, `INTEGRACION.dbo`), JS vanilla + Tom Select + SheetJS (CDN, ya cargados en dashboard.php). Sin build.

**Spec:** `docs/superpowers/specs/2026-06-12-evolucion-historica-design.md`

**Golden de referencia (pantallazo 2026-06-12):** proveedor **BH BRANDS SAS**, negocio **DUNCANA-NEG** (ref `DUNCANA`, color `NEG`):

| Mes | Compras | Ventas | Stock | Tiendas | MesesInv | Índice |
|-----|---------|--------|-------|---------|----------|--------|
| 2025-03 | -1 | 45 | 96 | 17 | 2 | 2.56 |
| 2025-08 | -2 | 1 | 10 | 3 | 10 | 0.32 |
| 2026-06 | (vacío) | 3 | 134 | 10 | 45 | 0.82 |

(Índice 2026-06 usa días = día de **ayer** = 11; ventas/compras del mes actual se cortan en ayer, igual que O45.)

---

## File Structure

- **Create** `api/informe_evol.php` — endpoint (`tab=data` matriz tidy, `tab=filtros` catálogo cascada).
- **Create** `informes/evol.php` — página + script (filtros, render matriz, Excel, onEnter).
- **Modify** `dashboard.php` — botón topbar `topbarEvolRefresh`, `include informes/evol.php`, reemplazar placeholder `#page-evolucion-historica` por la página real, entradas en `showPage` (título + reset + dispatch `evolOnEnter`).
- **Create** `tests/_endpoint_run_evol.php` — runner CLI del endpoint.
- **Create** `tests/evol_golden_test.php` — golden BH BRANDS SAS / DUNCANA-NEG + estructura tidy.

---

## Task 1: Test runner + golden test (failing first)

**Files:**
- Create: `tests/_endpoint_run_evol.php`
- Create: `tests/evol_golden_test.php`

- [ ] **Step 1: Crear el runner CLI** (idéntico patrón a `tests/_endpoint_run_o45.php`)

Create `tests/_endpoint_run_evol.php`:

```php
<?php
// Ejecuta el endpoint Evolución como request y vuelca el JSON. Uso:
//   php tests/_endpoint_run_evol.php "PROVEEDOR" "tab=data&desde=2025-01&hasta=2026-06"
error_reporting(E_ALL); ini_set('display_errors', '0');
$prov = $argv[1] ?? 'BH BRANDS SAS';
$qs   = $argv[2] ?? 'tab=data';
session_start();
$_SESSION = ['usuario' => 'test', 'proveedor' => $prov];
parse_str($qs, $_GET);
if (!isset($_GET['tab'])) $_GET['tab'] = 'data';
include __DIR__ . '/../api/informe_evol.php';
```

- [ ] **Step 2: Escribir el golden test** (estructura tidy + valores del pantallazo)

Create `tests/evol_golden_test.php`:

```php
<?php
// Golden de Evolución Histórica contra el pantallazo (BH BRANDS SAS / DUNCANA-NEG).
//   php tests/evol_golden_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BH BRANDS SAS';
$runner = __DIR__ . '/_endpoint_run_evol.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
function ep($php,$runner,$prov,$qs,$nul){
  $cmd = escapeshellarg($php).' -d display_startup_errors=0 -d display_errors=stderr '
       . escapeshellarg($runner).' '.escapeshellarg($prov).' '.escapeshellarg($qs).' 2>'.$nul;
  $raw = (string) shell_exec($cmd);
  $a = strpos($raw,'{'); $b = strrpos($raw,'}');
  return json_decode(($a!==false&&$b!==false)?substr($raw,$a,$b-$a+1):$raw, true);
}
$fail = 0;
$d = ep($php,$runner,$prov,'tab=data&desde=2025-01&hasta=2026-06',$nul);
if (!($d['ok'] ?? false)) { echo "FALLO: tab=data no ok\n"; echo substr(json_encode($d),0,400)."\n"; exit(1); }

// Estructura
foreach (['proveedor','meses','negocios'] as $k)
  if (!array_key_exists($k,$d)) { echo "FALLO: falta '$k'\n"; $fail=1; }
$meses = $d['meses'] ?? [];
if (!in_array('2025-03',$meses,true) || !in_array('2026-06',$meses,true)) { echo "FALLO: eje de meses incompleto\n"; $fail=1; }

// Localizar DUNCANA-NEG
$dun = null; foreach (($d['negocios']??[]) as $n) if (($n['negocio']??'')==='DUNCANA-NEG') { $dun=$n; break; }
if (!$dun) { echo "FALLO: no aparece DUNCANA-NEG\n"; echo "RESULTADO: FALLO\n"; exit(1); }

$g = function($medida,$mes) use ($dun){ return $dun['valores'][$medida][$mes] ?? null; };
$chk = function($label,$got,$exp) use (&$fail){
  if ((string)$got !== (string)$exp) { echo "FALLO golden $label: got=".var_export($got,true)." exp=$exp\n"; $fail=1; }
};
// Pantallazo
$chk('ventas 2025-03',  $g('ventas','2025-03'),   45);
$chk('ventas 2025-08',  $g('ventas','2025-08'),   1);
$chk('ventas 2026-06',  $g('ventas','2026-06'),   3);
$chk('compras 2025-03', $g('compras','2025-03'),  -1);
$chk('stock 2025-03',   $g('stock','2025-03'),    96);
$chk('stock 2026-06',   $g('stock','2026-06'),    134);
$chk('tiendas 2025-03', $g('tiendas','2025-03'),  17);
$chk('mesesInv 2025-03',$g('mesesInv','2025-03'), 2);
$chk('mesesInv 2026-06',$g('mesesInv','2026-06'), 45);
$chk('indice 2025-03',  $g('indice','2025-03'),   2.56);
$chk('indice 2026-06',  $g('indice','2026-06'),   0.82);

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
```

- [ ] **Step 3: Correr el test y verificar que falla** (aún no existe el endpoint)

Run: `php tests/evol_golden_test.php "BH BRANDS SAS"`
Expected: FALLO (el include de `api/informe_evol.php` no existe → JSON inválido → "tab=data no ok").

- [ ] **Step 4: Commit**

```bash
git add tests/_endpoint_run_evol.php tests/evol_golden_test.php
git commit -m "test(evol): runner + golden BH BRANDS SAS/DUNCANA-NEG (rojo)"
```

---

## Task 2: Endpoint `api/informe_evol.php`

**Files:**
- Create: `api/informe_evol.php`
- Referencia de patrones (copiar verbatim donde se indique): `api/informe_o45.php`, `api/lib_refs.php`

- [ ] **Step 1: Crear el endpoint completo**

Create `api/informe_evol.php` con exactamente este contenido:

```php
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
$tab = $_GET['tab'] ?? 'data';

// === Eje de meses (Año-Mes). Default: enero del año pasado .. mes actual. Tope = mes actual. ===
$mesActual = date('Y-m');
$desdeMes = preg_match('/^\d{4}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : (date('Y')-1).'-01';
$hastaMes = preg_match('/^\d{4}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : $mesActual;
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
if ($dbConnect === false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Conexión DB fallida']); exit; }

function run($c,$sql,$p=[]) { $s=sqlsrv_query($c,$sql,$p); if($s===false) return ['error'=>sqlsrv_errors()];
  $r=[]; while($x=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC))$r[]=$x; sqlsrv_free_stmt($s); return $r; }
function jsonFail($rows,$c){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Consulta fallida','detalle'=>$rows['error']??$rows]); sqlsrv_close($c); exit; }

// --- #refs del proveedor (cache compartida g00_refs_*) ---
if (!buildRefsTemp($dbConnect, getRefsCached($dbConnect, $proveedor))) jsonFail(['error'=>sqlsrv_errors()], $dbConnect);

// Filtros de dimensión (podan #refs). En tab=filtros NO se podan (catálogo completo).
if ($tab === 'data') {
    foreach ($FILTROS_REF as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $x = sqlsrv_query($dbConnect, "DELETE FROM #refs WHERE $col NOT IN ($ph)", $vals);
        if ($x === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($x);
    }
}

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

// ===== tab=filtros: catálogo del universo del proveedor (idéntico a O45, cache evol_filtros_*) =====
if ($tab === 'filtros') {
    $cacheDir = __DIR__ . '/../cache'; if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/evol_filtros_' . md5($proveedor) . '.json';
    if (file_exists($cacheFile) && date('Y-m-d', filemtime($cacheFile)) === date('Y-m-d')) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['combos'])) { sqlsrv_close($dbConnect); echo json_encode($cached, JSON_UNESCAPED_UNICODE); exit; }
    }
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
    @file_put_contents($cacheFile, json_encode($out));
    sqlsrv_close($dbConnect); echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
}

// ===== tab=data: agregación por (negocio, mes) y ensamble tidy =====
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
if (isset($agg['error'])) jsonFail($agg, $dbConnect);

// marca por negocio (para columna/Excel y sort estable)
$marcaMap = [];
$rm = run($dbConnect, "SELECT b.negocio, MAX(r.MARCA) marca FROM #base b INNER JOIN #refs r ON r.REFERENCIA=b.referencia GROUP BY b.negocio");
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
    $N['valores']['mesesInv'][$m]= $ventas != 0 ? (int)round($stock / $ventas) : 0;
    $N['valores']['indice'][$m]  = $tiendas > 0 ? round(($ventas / $tiendas) / ($diasMes($m) / 30), 2) : 0.0;
    $N['totales']['compras'] += $compras;
    $N['totales']['ventas']  += $ventas;
    unset($N);
}
// Orden: por total de ventas desc (como O45).
$negocios = array_values($neg);
usort($negocios, fn($a,$b) => $b['totales']['ventas'] <=> $a['totales']['ventas']);

sqlsrv_close($dbConnect);
echo json_encode([
    'ok'=>true, 'proveedor'=>$proveedorSesion,
    'meses'=>$meses, 'mesActual'=>$mesActual,
    'rango'=>['desde'=>$desdeMes,'hasta'=>$hastaMes,'corte_ventas'=>$hastaF],
    'negocios'=>$negocios,
], JSON_UNESCAPED_UNICODE);
exit;
```

- [ ] **Step 2: Lint**

Run: `php -l api/informe_evol.php`
Expected: `No syntax errors detected` (ignorar warnings de xdebug/dio/openssl del entorno).

- [ ] **Step 3: Correr el golden test y verificar que pasa**

Run: `php tests/evol_golden_test.php "BH BRANDS SAS"`
Expected: `RESULTADO: OK`.

Si alguna celda golden falla, depurar con `superpowers:systematic-debugging`:
- **stock/tiendas distinto:** posible inclusión/exclusión de CEDI. El golden manda — si Stock 2025-03 sale > 96, el CEDI está sumando: cambiar el agregado para excluir CEDI del Stock (añadir `AND pb.bodega<>'CEDI'` dentro del `SUM(pb.stock)` vía CASE) y re-verificar. Documentar la decisión.
- **indice distinto:** revisar `diasMes` del mes en curso (debe dar 11 hoy) y el conteo `tiendas`.
- **compras vacío donde debe haber:** revisar el split actual/histórico por año.

- [ ] **Step 4: Commit**

```bash
git add api/informe_evol.php
git commit -m "feat(evol): endpoint matriz negocio x mes (ventas/compras/stock/derivadas) + golden verde"
```

---

## Task 3: Frontend `informes/evol.php`

**Files:**
- Create: `informes/evol.php`
- Referencia: `informes/o45.php` (filtros, Tom Select, hover de foto, Excel)

- [ ] **Step 1: Crear la página con filtros, matriz, Excel y onEnter**

Create `informes/evol.php`:

```php
<?php /* Informe Evolución Histórica (incluido desde dashboard.php) */ ?>
<div class="page" id="page-evolucion-historica">
  <div class="g00-filters evol-filters">
    <div class="g00-filter-row">
      <div class="filter-group"><label>Grupo</label><select id="evol-f-grupo" multiple></select></div>
      <div class="filter-group evol-tienda-group"><label>Tienda</label><select id="evol-f-tienda" multiple></select></div>
      <div class="o14-apply">
        <button class="g00-btn-refresh" onclick="evolLoad()"><i class="fa-solid fa-rotate"></i> Aplicar</button>
      </div>
    </div>
    <div class="g00-filter-row">
      <div class="filter-group"><label>Marca</label><select id="evol-f-marca" multiple></select></div>
      <div class="filter-group"><label>Tipo</label><select id="evol-f-tipo" multiple></select></div>
      <div class="filter-group"><label>Categoría</label><select id="evol-f-categoria" multiple></select></div>
      <div class="filter-group"><label>Subcategoría</label><select id="evol-f-subcategoria" multiple></select></div>
      <div class="filter-group"><label>Género</label><select id="evol-f-genero" multiple></select></div>
      <div class="filter-group"><label>Público</label><select id="evol-f-publico" multiple></select></div>
      <div class="filter-group"><label>Negocio</label><select id="evol-f-negocio" multiple></select></div>
      <div class="filter-group"><label>Referencia</label><select id="evol-f-referencia" multiple></select></div>
    </div>
  </div>

  <div class="tab-bar">
    <button class="g00-btn-export o14-export-btn" onclick="evolExport()">⤓ Excel</button>
  </div>
  <div id="evol-tabla" class="o14-matriz-wrap"></div>
</div>

<style>
  #page-evolucion-historica .tab-bar { display: flex; justify-content: flex-end; }
  #page-evolucion-historica .evol-tienda-group { min-width: 320px; flex: 2; }
  #page-evolucion-historica .evol-tienda-group .ts-control { min-width: 320px; }
  #page-evolucion-historica table.evol-tabla { border-collapse: collapse; font-size: 11px; }
  #page-evolucion-historica table.evol-tabla th, #page-evolucion-historica table.evol-tabla td {
    border: 1px solid var(--border); padding: 2px 6px; text-align: right; white-space: nowrap; }
  #page-evolucion-historica table.evol-tabla thead th { background: #faf9ff; position: sticky; top: 0; z-index: 2; }
  #page-evolucion-historica table.evol-tabla td.neg, #page-evolucion-historica table.evol-tabla th.neg {
    text-align: left; position: sticky; left: 0; background: #2e7d6b; color: #fff; font-weight: 600; z-index: 1; }
  #page-evolucion-historica table.evol-tabla td.med { text-align: left; background: #f3f1ff; }
  #page-evolucion-historica table.evol-tabla td.tot { font-weight: 700; background: #efeefb; }
  #page-evolucion-historica table.evol-tabla tr.m-ventas td.val { background: #c9f7d2; }
  #page-evolucion-historica table.evol-tabla tr.m-stock  td.val { background: #cfe0f5; }
  #page-evolucion-historica table.evol-tabla tr.m-indice td.val { background: #faecc8; }
  #evol-img-pop { position: fixed; display: none; z-index: 9999; pointer-events: none; background: #fff;
    border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 6px 20px rgba(45,43,78,.25); padding: 4px; }
  #evol-img-pop img { max-width: 260px; max-height: 320px; display: block; border-radius: 4px; }
</style>

<script>
(function(){
  'use strict';
  let filtrosInit = false, comboCatalogo = [];
  const DIMS = ['marca','tipo','categoria','subcategoria','genero','publico','negocio','referencia'];
  const tsRef = {};
  const nf  = n => (n==null||n===''?'':Number(n).toLocaleString('es-CO'));
  const nf2 = n => (n==null||n===''?'':Number(n).toLocaleString('es-CO',{minimumFractionDigits:2,maximumFractionDigits:2}));
  const esc = s => (s==null?'':String(s)).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const val = id => (document.getElementById(id)?.value || '');
  function getMultiVals(id){ const el=document.getElementById(id); if(!el) return [];
    return Array.from(el.selectedOptions||[]).map(o=>o.value).filter(Boolean); }

  // Mes Año-Mes por defecto: enero del año pasado .. mes actual.
  function defDesde(){ const d=new Date(); return (d.getFullYear()-1)+'-01'; }
  function defHasta(){ const d=new Date(); return d.toISOString().slice(0,7); }

  function buildParams(){
    const p = new URLSearchParams({ tab:'data', desde: val('evol-vdesde')||defDesde(), hasta: val('evol-vhasta')||defHasta() });
    ['grupo','tienda',...DIMS].forEach(k=>{ getMultiVals('evol-f-'+k).forEach(v=>p.append(k+'[]', v)); });
    return p.toString();
  }

  function poblarSelect(id, valores, labelFn){
    const el=document.getElementById(id); if(!el) return;
    const uniq=[...new Set(valores.filter(v=>v!==''&&v!=null))].sort((a,b)=>String(a).localeCompare(String(b)));
    el.innerHTML = uniq.map(v=>'<option value="'+String(v).replace(/"/g,'&quot;')+'">'+esc(labelFn?labelFn(v):v)+'</option>').join('');
    if (window.TomSelect){ if(tsRef[id]) tsRef[id].destroy(); tsRef[id]=new TomSelect(el,{plugins:['remove_button'],maxOptions:null,placeholder:'Todas'}); }
  }

  function initFiltros(){
    fetch('api/informe_evol.php?tab=filtros',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      comboCatalogo = d.combos||[];
      DIMS.forEach(k=> poblarSelect('evol-f-'+k, comboCatalogo.map(c=>c[k])));
      poblarSelect('evol-f-grupo', comboCatalogo.map(c=>c.grupo));
      const tEl=document.getElementById('evol-f-tienda'); const seen={}; const opts=[];
      comboCatalogo.forEach(c=>{ const nom=c.tienda||''; if(!nom||seen[nom])return; seen[nom]=1; opts.push({nom, cod:c.tienda_cod||''}); });
      opts.sort((a,b)=>a.cod.localeCompare(b.cod));
      tEl.innerHTML = opts.map(o=>'<option value="'+esc(o.nom)+'">'+esc(o.cod)+' - '+esc(o.nom)+'</option>').join('');
      if (window.TomSelect){ if(tsRef['evol-f-tienda']) tsRef['evol-f-tienda'].destroy(); tsRef['evol-f-tienda']=new TomSelect(tEl,{plugins:['remove_button'],maxOptions:null,placeholder:'Todas'}); }
    });
  }

  // Las 6 medidas (orden de la imagen). 'sum' indica si la columna Total acumula.
  const MEDIDAS = [
    {k:'compras',  t:'Compras Cauchosol', f:nf,  cls:'',        sum:true },
    {k:'ventas',   t:'Total Ventas',      f:nf,  cls:'m-ventas', sum:true },
    {k:'stock',    t:'Stock',             f:nf,  cls:'m-stock',  sum:false},
    {k:'tiendas',  t:'Tiendas con Inv',   f:nf,  cls:'',         sum:false},
    {k:'mesesInv', t:'Meses de Inv',      f:nf,  cls:'',         sum:false},
    {k:'indice',   t:'Índice Ventas Detal Mes', f:nf2, cls:'m-indice', sum:false},
  ];
  const fmtMesHdr = m => { const [a,mm]=m.split('-'); const ms=['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']; return a+'-'+ms[(+mm)-1]; };

  function renderMatriz(d){
    const cont=document.getElementById('evol-tabla');
    const meses=d.meses||[], negs=d.negocios||[];
    if(!negs.length){ cont.innerHTML='<p style="padding:16px;color:var(--text-light)">Sin datos.</p>'; return; }
    let h='<table class="evol-tabla" id="evol-tbl"><thead><tr>';
    h+='<th class="neg">Negocio</th><th class="med">Medida</th>';
    meses.forEach(m=> h+='<th>'+fmtMesHdr(m)+'</th>');
    h+='<th>Total</th></tr></thead><tbody>';
    negs.forEach(n=>{
      MEDIDAS.forEach((med,i)=>{
        h+='<tr class="'+med.cls+'"'+(i===0?' data-negimg="'+esc(n.foto)+'"':'')+'>';
        if(i===0) h+='<td class="neg" rowspan="'+MEDIDAS.length+'">'+esc(n.negocio)+'</td>';
        h+='<td class="med">'+med.t+'</td>';
        meses.forEach(m=>{ const v=(n.valores[med.k]||{})[m]; h+='<td class="val">'+med.f(v)+'</td>'; });
        const tot = med.sum ? (n.totales[med.k]) : '';
        h+='<td class="tot">'+(med.sum?med.f(tot):'')+'</td>';
        h+='</tr>';
      });
    });
    h+='</tbody></table>'; cont.innerHTML=h;
  }

  function showLoading(){ if(!window.Swal) return; Swal.fire({title:'Cargando',html:'Obteniendo información…',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()}); }
  function hideLoading(){ if(window.Swal && Swal.isVisible()) Swal.close(); }

  window.evolLoad = function(){
    const cont=document.getElementById('evol-tabla'); showLoading();
    fetch('api/informe_evol.php?'+buildParams(),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(!d.ok){ cont.innerHTML='<p style="padding:16px;color:var(--accent)">Error al cargar.</p>'; return; }
      window.__evollast=d; if(d.proveedor) setTitle(d.proveedor); renderMatriz(d);
    }).catch(()=>{ cont.innerHTML='<p style="padding:16px;color:var(--accent)">Error de red.</p>'; }).finally(hideLoading);
  };

  // Excel: DOM->AOA expandiendo rowspan, enteros es-CO -> número real (mismo criterio que O14/O45).
  window.evolExport = function(){
    const tbl=document.getElementById('evol-tbl');
    if(!tbl || typeof XLSX==='undefined'){ if(window.Swal) Swal.fire('Exportar','Carga el informe primero.','info'); return; }
    const rows=[...tbl.querySelectorAll('tr')]; const aoa=[]; const carry={};
    rows.forEach((tr,ri)=>{
      const out=[]; let ci=0;
      // reinyectar celdas con rowspan pendiente
      Object.keys(carry).forEach(c=>{ if(carry[c].left>0){ out[c]=carry[c].text; carry[c].left--; } });
      [...tr.children].forEach(td=>{
        while(out[ci]!==undefined) ci++;
        const txt=td.textContent.trim();
        const num=Number(txt.replace(/\./g,'').replace(',','.'));
        const v=(txt!=='' && !isNaN(num) && /[0-9]/.test(txt)) ? num : txt;
        out[ci]=v;
        const rs=parseInt(td.getAttribute('rowspan')||'1',10);
        if(rs>1) carry[ci]={text:txt,left:rs-1};
        ci++;
      });
      aoa.push(out.map(x=>x===undefined?'':x));
    });
    const wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(aoa), 'Evolucion');
    XLSX.writeFile(wb,'Evolucion_Historica.xlsx');
  };

  function setTitle(prov){ document.getElementById('pageTitle').textContent = 'EVOLUCIÓN HISTÓRICA' + (prov ? ' - ' + prov : ''); }

  window.evolOnEnter = function(){
    setTitle(window.PROVEEDOR_ACTUAL || '');
    document.getElementById('topbar').classList.add('topbar--o14');
    document.getElementById('pageSubtitle').style.display = 'none';
    const td = document.getElementById('topbarDates'); td.style.display = '';
    if (!document.getElementById('evol-vdesde')) {
      td.innerHTML =
        '<div class="o14-vfilter"><span class="o14-vfilter-lbl">Meses</span>'
        + '<label>Desde<input type="month" id="evol-vdesde" value="'+defDesde()+'"></label>'
        + '<label>Hasta<input type="month" id="evol-vhasta" value="'+defHasta()+'" max="'+defHasta()+'"></label></div>';
    }
    const rb = document.getElementById('topbarEvolRefresh'); if(rb) rb.style.display = '';
    if (!filtrosInit) { initFiltros(); filtrosInit = true; }
    if (!window.__evollast) evolLoad();
  };

  // Foto del zapato al pasar el mouse sobre la columna Negocio (col 0). Igual que O45.
  (function initImgHover(){
    const FOTO_BASE='http://bi.stanton.com.co:81/fotosPBI/';
    const panel=document.getElementById('evol-tabla'); if(!panel) return;
    let pop=null,img=null,triedPng=false,curLabel='';
    function ensurePop(){ if(pop)return; pop=document.createElement('div'); pop.id='evol-img-pop'; img=document.createElement('img'); img.alt='';
      img.onerror=function(){ if(!triedPng){ triedPng=true; img.src=FOTO_BASE+encodeURIComponent(curLabel)+'.png'; } else hide(); };
      pop.appendChild(img); document.body.appendChild(pop); }
    function hide(){ if(pop) pop.style.display='none'; }
    function position(e){ if(!pop)return; const off=16,w=276,h=336; let x=e.clientX+off,y=e.clientY+off;
      if(x+w>window.innerWidth)x=e.clientX-off-w; if(y+h>window.innerHeight)y=e.clientY-off-h;
      pop.style.left=Math.max(4,x)+'px'; pop.style.top=Math.max(4,y)+'px'; }
    panel.addEventListener('mouseover',function(e){ const td=e.target.closest('td.neg'); if(!td)return;
      const tr=td.closest('tr[data-negimg]'); if(!tr)return; curLabel=tr.getAttribute('data-negimg'); triedPng=false; ensurePop();
      img.src=FOTO_BASE+encodeURIComponent(curLabel)+'.jpg'; pop.style.display='block'; position(e); });
    panel.addEventListener('mousemove',function(e){ if(pop&&pop.style.display==='block')position(e); });
    panel.addEventListener('mouseout',function(e){ const td=e.target.closest('td.neg');
      if(td&&(!e.relatedTarget||!td.contains(e.relatedTarget)))hide(); });
  })();
})();
</script>
```

- [ ] **Step 2: Lint**

Run: `php -l informes/evol.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add informes/evol.php
git commit -m "feat(evol): pagina frontend (filtros cascada, matriz negocio x mes, Excel, topbar Ano-Mes)"
```

---

## Task 4: Wiring en `dashboard.php`

**Files:**
- Modify: `dashboard.php` (4 puntos)

- [ ] **Step 1: Añadir el botón de refresh en el topbar**

En `dashboard.php`, tras el botón `topbarO45Refresh` (≈ línea 559-561), añadir:

```html
                <button id="topbarEvolRefresh" class="topbar-action" style="display:none;" onclick="evolLoad()">
                    <i class="fa-solid fa-arrows-rotate"></i> Actualizar
                </button>
```

- [ ] **Step 2: Reemplazar el placeholder por el include de la página**

Buscar el bloque placeholder `<div class="page" id="page-evolucion-historica"> … Módulo en desarrollo … </div>` (≈ líneas 965-971) y reemplazarlo COMPLETO por:

```php
            <?php include __DIR__ . '/informes/evol.php'; ?>
```

(El `include` ya define su propio `<div class="page" id="page-evolucion-historica">`.)

- [ ] **Step 3: Reset del botón al cambiar de página**

En `showPage` (≈ línea 1240), tras la línea que oculta `topbarO45Refresh`, añadir:

```javascript
        document.getElementById('topbarEvolRefresh').style.display = 'none';
```

- [ ] **Step 4: Dispatch de `evolOnEnter`**

En `showPage` (≈ línea 1246), tras `if (pageId === 'informes-o45' …)`, añadir:

```javascript
        if (pageId === 'evolucion-historica' && typeof evolOnEnter === 'function') evolOnEnter();
```

(El título `'evolucion-historica':'EVOLUCIÓN HISTÓRICA'` ya existe en el mapa `titles`; `evolOnEnter` lo sobreescribe con el proveedor.)

- [ ] **Step 5: Lint**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add dashboard.php
git commit -m "feat(evol): cablear Evolucion Historica en el menu (boton, include, showPage)"
```

---

## Task 5: Integración, suite y handoff a E2E

**Files:** (sin cambios de código salvo correcciones que surjan)

- [ ] **Step 1: Golden + lint final**

Run:
```bash
php tests/evol_golden_test.php "BH BRANDS SAS"
php -l api/informe_evol.php && php -l informes/evol.php && php -l dashboard.php
```
Expected: `RESULTADO: OK` y `No syntax errors detected` en los 3.

- [ ] **Step 2: No-regresión de O45/O14/G00** (compartimos `lib_refs.php` y cache `g00_refs_*`)

Run:
```bash
php tests/o45_smoke_test.php "BELTRANY SAS"
php tests/o14_filtros_test.php
php tests/g00_detal_smoke_test.php "BELTRANY SAS"
```
Expected: todos `OK` (Evolución no tocó esos archivos; esto confirma que la cache compartida sigue sana).

- [ ] **Step 3: Commit (si hubo correcciones del Step 1/2)**

```bash
git add -A
git commit -m "test(evol): suite verde + no-regresion O45/O14/G00"
```

- [ ] **Step 4: Handoff E2E a Rafael** (manual, navegador)

Checklist a verificar en `localhost/plataforma_20/` → REPORTES → Evolución Histórica:
1. Topbar: título centrado **"EVOLUCIÓN HISTÓRICA - {Tercero}"**, control **Meses Desde/Hasta** (Año-Mes) a la izquierda, botón Actualizar a la derecha.
2. Matriz: por negocio, 6 filas (Compras Cauchosol, Total Ventas verde, Stock azul, Tiendas con Inv, Meses de Inv, Índice naranja); columnas Año-Mes del rango + Total; columna Negocio congelada a la izquierda con foto al hover.
3. Default ene-año-pasado → mes actual; el mes en curso aparece con stock vivo; no hay columnas futuras.
4. Filtros (dimensión/grupo/tienda/negocio) acotan; Excel descarga `.xlsx` con números.
5. Cuadre contra el Power BI viejo para BH BRANDS SAS / DUNCANA-NEG (valores del pantallazo).

> Tras el OK de Rafael: decidir merge/PR de `feat/evolucion-historica` (rama stack sobre `feat/o45-indice-ventas`).

---

## Self-Review (autor del plan)

**1. Cobertura del spec:**
- §2.1 las 6 medidas → Task 2 (engine) + Task 3 (`MEDIDAS`). ✓
- §2.2 universo compras∪ventas∪stock → `#base` con 3 inserts + agregado por negocio (Task 2). ✓
- §3 eje Año-Mes, tope mes actual, default ene-año-pasado → `$meses`/`defDesde`/`defHasta`. ✓
- §4 fuentes (compras actual/hist, ventas Detal/Acum, stock cortes+vivo) → Task 2. ✓
- §5 backend tidy + tab=filtros → Task 2. ✓
- §6 frontend matriz + topbar + filtros + Excel + wiring → Tasks 3-4. ✓
- §8 testing (compras, stock=O45, fórmulas, solo-compras, php -l) → golden Task 1/2 + Task 5. ✓
- §10 control Año-Mes → resuelto: `<input type="month">` (más simple). ✓

**2. Placeholders:** ninguno; todo el código es literal.

**3. Consistencia de tipos/nombres:** las claves de medida (`compras,ventas,stock,tiendas,mesesInv,indice`) son idénticas en backend (`$N['valores'][...]`), golden test (`$g('mesesInv',...)`) y frontend (`MEDIDAS[].k`). El JSON expone `negocios[].valores[medida][mes]`, `negocios[].totales.{compras,ventas}`, `meses[]`, `proveedor`, `mesActual` — consumidos igual en test y front. ✓

**Riesgo conocido (resuelto por golden):** inclusión de CEDI en Stock/Tiendas. El golden DUNCANA-NEG (Stock 96 / Tiendas 17) fija la decisión empíricamente; Task 2 Step 3 documenta el ajuste si hay mismatch.
