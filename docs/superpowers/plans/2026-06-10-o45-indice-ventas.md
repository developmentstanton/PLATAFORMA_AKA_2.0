# Informe O45 «Índice de Ventas» — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir el informe «Índice de Ventas» (O45): un cuadro plano, una fila por negocio (ref-color), con ventas del rango, índices de inventario/ventas, stock CEDI/tiendas y precio detal, exportable a Excel.

**Architecture:** Endpoint PHP nuevo `api/informe_o45.php` (`tab=data` y `tab=filtros`) que reusa el cache `#refs` (`lib_refs`) y el patrón de CTEs stock+ventas de `api/informe_o14.php`, agregando por negocio y con dos ventanas de ventas (rango filtrado + 30 días móviles hasta `Hasta`). Página nueva `informes/o45.php` con la plantilla visual de O14 (topbar Desde/Hasta, filtros Tom Select, tabla + Excel), cableada al menú en `dashboard.php`. Stock es snapshot actual; el filtro de fechas solo afecta ventas.

**Tech Stack:** PHP 8 + `sqlsrv` (SQL Server), JS vanilla + Tom Select + SheetJS (CDN), tests PHP de integración vía runner que incluye el endpoint.

**Spec:** `docs/superpowers/specs/2026-06-10-o45-indice-ventas-design.md`

---

## File Structure

- **Create** `api/informe_o45.php` — endpoint de datos y catálogo de filtros.
- **Create** `informes/o45.php` — página (markup + JS) incluida desde dashboard.php.
- **Create** `tests/_endpoint_run_o45.php` — runner de tests (setea sesión + `$_GET`, incluye el endpoint).
- **Create** `tests/o45_smoke_test.php`, `tests/o45_indices_test.php`, `tests/o45_filtro_tienda_test.php` — tests de integración.
- **Modify** `dashboard.php` — incluir `informes/o45.php`, cablear el botón del menú, mapa `titles`, `o45OnEnter` en `showPage`, botón `topbarO45Refresh`, quitar placeholder `#page-indice-ventas`.

Referencia constante: `api/informe_o14.php` (mismo esquelero) e `informes/o14.php` (misma plantilla front).

---

## Task 1: Endpoint `tab=data` — agregación por negocio (sin índices ni precio)

**Files:**
- Create: `api/informe_o45.php`
- Create: `tests/_endpoint_run_o45.php`
- Test: `tests/o45_smoke_test.php`

- [ ] **Step 1: Write the runner**

Create `tests/_endpoint_run_o45.php`:

```php
<?php
// Ejecuta el endpoint O45 real como request y vuelca el JSON. Uso:
//   php tests/_endpoint_run_o45.php "PROVEEDOR" "tab=data&grupo[]=AKA"
error_reporting(E_ALL); ini_set('display_errors', '0');
$prov = $argv[1] ?? 'BELTRANY SAS';
$qs   = $argv[2] ?? 'tab=data';
session_start();
$_SESSION = ['usuario' => 'test', 'proveedor' => $prov];
parse_str($qs, $_GET);
if (!isset($_GET['tab'])) $_GET['tab'] = 'data';
include __DIR__ . '/../api/informe_o45.php';
```

- [ ] **Step 2: Write the failing smoke test**

Create `tests/o45_smoke_test.php`:

```php
<?php
// O45 tab=data: estructura básica + invariante total_stock = stock_cedi + stock_tiendas.
//   php tests/o45_smoke_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run_o45.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
function ep($php, $runner, $prov, $qs, $nul) {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    return json_decode(($a !== false && $b !== false) ? substr($raw, $a, $b - $a + 1) : $raw, true);
}
$fail = 0;
$d = ep($php, $runner, $prov, 'tab=data', $nul);
if (!($d['ok'] ?? false)) { echo "FALLO: tab=data no ok\n"; $fail = 1; }
$filas = $d['filas'] ?? [];
echo "filas=" . count($filas) . "\n";
$keys = ['negocio','marca','ventas','tiendas','ventas30','stock_cedi','stock_tiendas','total_stock','tallas'];
foreach (array_slice($filas, 0, 50) as $f) {
    foreach ($keys as $k) if (!array_key_exists($k, $f)) { echo "FALLO: falta key '$k' en fila\n"; $fail = 1; break 2; }
    if ((int)$f['total_stock'] !== (int)$f['stock_cedi'] + (int)$f['stock_tiendas']) {
        echo "FALLO: total_stock != cedi+tiendas en " . $f['negocio'] . "\n"; $fail = 1; break;
    }
}
if (!isset($d['rango']['dias'])) { echo "FALLO: falta rango.dias\n"; $fail = 1; }
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `C:/xampp/php/php.exe tests/o45_smoke_test.php`
Expected: FAIL (endpoint no existe → JSON null → "tab=data no ok").

- [ ] **Step 4: Create the endpoint with `tab=data`**

Create `api/informe_o45.php`. Copia el esqueleto de `api/informe_o14.php` y ajusta:

```php
<?php
/**
 * API Informe O45 — Índice de Ventas (una fila por negocio).
 * tab=data: cuadro; tab=filtros: catálogo de cascadas.
 * Stock = snapshot actual (inv_actual_PBI + _hold_actual_PBI). Fechas solo afectan ventas.
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
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$w30desde = date('Y-m-d', strtotime($hasta . ' -29 days'));   // ventana de 30 días que termina en hasta
$dias  = (int) floor((strtotime($hasta) - strtotime($desde)) / 86400) + 1;
if ($dias < 1) $dias = 1;

$FILTROS_REF = ['marca'=>'MARCA','tipo'=>'TIPO','categoria'=>'CATEGORIA','subcategoria'=>'SUBCATEGORIA','genero'=>'GENERO','publico'=>'PUBLICO_OBJETIVO','referencia'=>'REFERENCIA'];
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

// --- #base: disponible, hold, ventas (rango), ventas30 (30d hasta hasta) ---
$cre = sqlsrv_query($dbConnect, "CREATE TABLE #base (cia varchar(10), bodega varchar(20), negocio varchar(120),
    referencia varchar(50), color varchar(40), talla varchar(40),
    disponible int, hold int, ventas int, ventas30 int)");
if ($cre===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($cre);

$acumV   = ($desde    <= '2025-12-31') ? "UNION ALL SELECT rtrim(CIA),rtrim(BODEGA),rtrim(REFERENCIA),rtrim(COLOR),rtrim(TALLA),CANTIDAD FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?" : "";
$acumV30 = ($w30desde <= '2025-12-31') ? "UNION ALL SELECT rtrim(CIA),rtrim(BODEGA),rtrim(REFERENCIA),rtrim(COLOR),rtrim(TALLA),CANTIDAD FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?" : "";

$insBase = "
  WITH d AS (
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
  ),
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
  )
  INSERT INTO #base
  SELECT k.cia, k.bodega, k.referencia+'-'+k.color, k.referencia, k.color, k.talla,
         CAST(ISNULL(d.q,0) AS int), CAST(ISNULL(h.q,0) AS int), CAST(ISNULL(v.q,0) AS int), CAST(ISNULL(v30.q,0) AS int)
  FROM llaves k
   LEFT JOIN d   ON d.cia=k.cia AND d.bodega=k.bodega AND d.referencia=k.referencia AND d.color=k.color AND d.talla=k.talla
   LEFT JOIN h   ON h.cia=k.cia AND h.bodega=k.bodega AND h.referencia=k.referencia AND h.color=k.color AND h.talla=k.talla
   LEFT JOIN v   ON v.cia=k.cia AND v.bodega=k.bodega AND v.referencia=k.referencia AND v.color=k.color AND v.talla=k.talla
   LEFT JOIN v30 ON v30.cia=k.cia AND v30.bodega=k.bodega AND v30.referencia=k.referencia AND v30.color=k.color AND v30.talla=k.talla";

// Orden de params: ventas_src(desde,hasta) [+acumV(desde,hasta)] ; ventas30_src(w30desde,hasta) [+acumV30(w30desde,hasta)]
$p = [$desde,$hasta];
if ($acumV   !== '') array_push($p, $desde, $hasta);
array_push($p, $w30desde, $hasta);
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

// ===== tab=data: agregación por negocio =====
if ($tab === 'data') {
    $agg = run($dbConnect, "
        SELECT b.cia, b.negocio, b.referencia, b.color,
               MAX(r.MARCA) marca,
               SUM(CASE WHEN b.bodega<>'CEDI' THEN b.ventas   ELSE 0 END) ventas,
               SUM(CASE WHEN b.bodega<>'CEDI' THEN b.ventas30 ELSE 0 END) ventas30,
               SUM(CASE WHEN b.bodega='CEDI'  THEN b.disponible+b.hold ELSE 0 END) stock_cedi,
               SUM(CASE WHEN b.bodega<>'CEDI' THEN b.disponible+b.hold ELSE 0 END) stock_tiendas,
               COUNT(DISTINCT b.talla) tallas,
               COUNT(DISTINCT CASE WHEN b.bodega<>'CEDI' AND b.ventas<>0 THEN b.bodega END) tiendas
        FROM #base b INNER JOIN #refs r ON r.REFERENCIA = b.referencia
        GROUP BY b.cia, b.negocio, b.referencia, b.color
        ORDER BY ventas DESC");
    if (isset($agg['error'])) jsonFail($agg, $dbConnect);

    $filas = [];
    $tot = ['ventas'=>0,'ventas30'=>0,'stock_cedi'=>0,'stock_tiendas'=>0,'total_stock'=>0];
    foreach ($agg as $r) {
        $ventas = (int)$r['ventas']; $ventas30 = (int)$r['ventas30'];
        $cedi = (int)$r['stock_cedi']; $tiendasStock = (int)$r['stock_tiendas'];
        $total_stock = $cedi + $tiendasStock;
        $filas[] = [
            'negocio'=>$r['negocio'], 'referencia'=>trim((string)$r['referencia']), 'color'=>trim((string)$r['color']),
            'marca'=>trim((string)$r['marca']),
            'ventas'=>$ventas, 'tiendas'=>(int)$r['tiendas'], 'ventas30'=>$ventas30,
            'stock_cedi'=>$cedi, 'stock_tiendas'=>$tiendasStock, 'total_stock'=>$total_stock,
            'tallas'=>(int)$r['tallas'],
        ];
        $tot['ventas']+=$ventas; $tot['ventas30']+=$ventas30; $tot['stock_cedi']+=$cedi;
        $tot['stock_tiendas']+=$tiendasStock; $tot['total_stock']+=$total_stock;
    }
    // #tiendas global (distintas con venta), para la fila TOTAL.
    $ct = run($dbConnect, "SELECT COUNT(DISTINCT cia+'-'+bodega) n FROM #base WHERE bodega<>'CEDI' AND ventas<>0");
    $tot['tiendas'] = (!isset($ct['error']) && $ct) ? (int)$ct[0]['n'] : 0;

    sqlsrv_close($dbConnect);
    echo json_encode(['ok'=>true, 'proveedor'=>$proveedorSesion,
        'rango'=>['desde'=>$desde,'hasta'=>$hasta,'w30desde'=>$w30desde,'dias'=>$dias],
        'filas'=>$filas, 'total'=>$tot], JSON_UNESCAPED_UNICODE);
    exit;
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `C:/xampp/php/php.exe tests/o45_smoke_test.php`
Expected: PASS — `RESULTADO: OK` con `filas=<n>` (n>0). Si sale FALLO de DB, revisar conexión y proveedor de prueba.

- [ ] **Step 6: Lint**

Run: `C:/xampp/php/php.exe -l api/informe_o45.php`
Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add api/informe_o45.php tests/_endpoint_run_o45.php tests/o45_smoke_test.php
git commit -m "feat(o45): endpoint tab=data agregado por negocio (ventas, stock CEDI/tiendas, ventana 30d)"
```

---

## Task 2: Índices (inventario y ventas-mes) en el JSON

**Files:**
- Modify: `api/informe_o45.php` (bloque `tab=data`, armado de `$filas` y `$tot`)
- Test: `tests/o45_indices_test.php`

- [ ] **Step 1: Write the failing test**

Create `tests/o45_indices_test.php`:

```php
<?php
// O45: índices correctos. ind_inventario = total_stock/ventas30 (null si ventas30<=0).
// ind_ventas_mes = (ventas/tiendas)/(dias/30) (0 si tiendas=0). Total recalculado.
//   php tests/o45_indices_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run_o45.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
function ep($php, $runner, $prov, $qs, $nul) {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    return json_decode(($a !== false && $b !== false) ? substr($raw, $a, $b - $a + 1) : $raw, true);
}
$fail = 0;
$d = ep($php, $runner, $prov, 'tab=data', $nul);
$dias = (int)($d['rango']['dias'] ?? 0);
if ($dias < 1) { echo "FALLO: dias inválido\n"; $fail = 1; }
foreach (array_slice($d['filas'] ?? [], 0, 200) as $f) {
    // ind_inventario
    if ((int)$f['ventas30'] <= 0) {
        if ($f['ind_inventario'] !== null) { echo "FALLO: ind_inventario debe ser null si ventas30<=0 (" . $f['negocio'] . ")\n"; $fail = 1; break; }
    } else {
        $exp = round($f['total_stock'] / $f['ventas30'], 2);
        if (abs(($f['ind_inventario'] ?? -1) - $exp) > 0.011) { echo "FALLO: ind_inventario " . $f['negocio'] . " got " . ($f['ind_inventario']??'null') . " exp $exp\n"; $fail = 1; break; }
    }
    // ind_ventas_mes
    if ((int)$f['tiendas'] === 0) {
        if ((float)$f['ind_ventas_mes'] !== 0.0) { echo "FALLO: ind_ventas_mes debe ser 0 si tiendas=0 (" . $f['negocio'] . ")\n"; $fail = 1; break; }
    } else {
        $exp = round(($f['ventas'] / $f['tiendas']) / ($dias / 30), 2);
        if (abs(((float)$f['ind_ventas_mes']) - $exp) > 0.011) { echo "FALLO: ind_ventas_mes " . $f['negocio'] . " got " . $f['ind_ventas_mes'] . " exp $exp\n"; $fail = 1; break; }
    }
}
// Total recalculado a nivel total.
$t = $d['total'] ?? [];
if (isset($t['ventas30']) && (int)$t['ventas30'] > 0) {
    $exp = round($t['total_stock'] / $t['ventas30'], 2);
    if (abs(($t['ind_inventario'] ?? -1) - $exp) > 0.011) { echo "FALLO: total ind_inventario got " . ($t['ind_inventario']??'null') . " exp $exp\n"; $fail = 1; }
}
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `C:/xampp/php/php.exe tests/o45_indices_test.php`
Expected: FAIL (las filas aún no tienen `ind_inventario` / `ind_ventas_mes`).

- [ ] **Step 3: Add the indices in PHP**

En `api/informe_o45.php`, dentro del `foreach ($agg as $r)` (Task 1), añade el cálculo de índices antes de `$filas[] = [...]` y agrégalos a la fila:

```php
        $ind_inv = $ventas30 > 0 ? round($total_stock / $ventas30, 2) : null;
        $ind_vm  = (int)$r['tiendas'] > 0 ? round(($ventas / (int)$r['tiendas']) / ($dias / 30), 2) : 0.0;
```

Agrega a la fila (después de `'total_stock'=>$total_stock,`):

```php
            'ind_inventario'=>$ind_inv, 'ind_ventas_mes'=>$ind_vm,
```

Después de cerrar el `foreach`, calcula los índices de la fila TOTAL (antes del `echo`):

```php
    $tot['ind_inventario'] = $tot['ventas30'] > 0 ? round($tot['total_stock'] / $tot['ventas30'], 2) : null;
    $tot['ind_ventas_mes'] = $tot['tiendas']  > 0 ? round(($tot['ventas'] / $tot['tiendas']) / ($dias / 30), 2) : 0.0;
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `C:/xampp/php/php.exe tests/o45_indices_test.php`
Expected: PASS — `RESULTADO: OK`.

- [ ] **Step 5: Commit**

```bash
git add api/informe_o45.php tests/o45_indices_test.php
git commit -m "feat(o45): indices de inventario y de ventas-mes (con div/0 -> null/0) + fila total"
```

---

## Task 3: Precio de Venta Detal

**Files:**
- Modify: `api/informe_o45.php` (bloque `tab=data`)

- [ ] **Step 1: Add the price lookup**

En `api/informe_o45.php`, después de obtener `$agg` y ANTES del `foreach`, carga el mapa de precios por ref+color:

```php
    $precioMap = [];
    $pr = run($dbConnect, "
        SELECT rtrim(f120_referencia) referencia, rtrim(f121_id_ext1_detalle) color, MAX(f126_precio) precio
        FROM INTEGRACION.dbo.LISTA_PRECIOS_DETAL
        GROUP BY f120_referencia, f121_id_ext1_detalle");
    if (!isset($pr['error'])) foreach ($pr as $x)
        $precioMap[trim((string)$x['referencia']) . '|' . trim((string)$x['color'])] = (float)$x['precio'];
```

Dentro del `foreach`, calcula y añade el precio a la fila:

```php
        $precio = $precioMap[trim((string)$r['referencia']) . '|' . trim((string)$r['color'])] ?? null;
```

Agrega a la fila (al final del array): `'precio'=>$precio,`

- [ ] **Step 2: Verify manually**

Run: `C:/xampp/php/php.exe tests/_endpoint_run_o45.php "BELTRANY SAS" "tab=data" | C:/xampp/php/php.exe -r "$d=json_decode(stream_get_contents(STDIN),true); echo isset($d['filas'][0]['precio'])?'precio key OK: '.var_export($d['filas'][0]['precio'],true).PHP_EOL:'FALTA precio'.PHP_EOL;"`
Expected: `precio key OK: <número o NULL>`.

- [ ] **Step 3: Lint + Commit**

```bash
C:/xampp/php/php.exe -l api/informe_o45.php
git add api/informe_o45.php
git commit -m "feat(o45): columna Precio de Venta Detal (LISTA_PRECIOS_DETAL por ref+color)"
```

---

## Task 4: `tab=filtros` — catálogo de cascadas (Grupo/Tienda con COD-NOMBRE + dimensiones)

**Files:**
- Modify: `api/informe_o45.php` (añadir bloque `tab=filtros` ANTES del bloque `tab=data`, tras los filtros de bodega)
- Test: `tests/o45_smoke_test.php` (extender) — o nuevo assert inline

- [ ] **Step 1: Add the `tab=filtros` block**

En `api/informe_o45.php`, justo después del bloque "Filtro Grupo/Tienda" y antes de `if ($tab === 'data')`, agrega:

```php
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
```

> Nota: el filtro `tienda[]` se aplica por `NOMBRE` (igual que O14). En el front la opción Tienda mostrará `COD - NOMBRE` pero su `value` será `NOMBRE`.

- [ ] **Step 2: Verify**

Run: `C:/xampp/php/php.exe tests/_endpoint_run_o45.php "BELTRANY SAS" "tab=filtros" | C:/xampp/php/php.exe -r "$d=json_decode(stream_get_contents(STDIN),true); echo ($d['ok']??false)&&count($d['combos']??[])>0 ? 'OK combos='.count($d['combos']).PHP_EOL : 'FALLO'.PHP_EOL;"`
Expected: `OK combos=<n>`.

- [ ] **Step 3: Lint + Commit**

```bash
C:/xampp/php/php.exe -l api/informe_o45.php
git add api/informe_o45.php
git commit -m "feat(o45): tab=filtros (catalogo dimensiones + grupo/tienda con COD-NOMBRE)"
```

---

## Task 5: Filtro Grupo/Tienda no afecta Stock CEDI (test)

**Files:**
- Test: `tests/o45_filtro_tienda_test.php`

- [ ] **Step 1: Write the test**

Create `tests/o45_filtro_tienda_test.php`:

```php
<?php
// O45: al filtrar por una tienda, Stock CEDI de cada negocio NO cambia (CEDI siempre completo);
// y Stock Tiendas no aumenta respecto al total sin filtro.
//   php tests/o45_filtro_tienda_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run_o45.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
function ep($php, $runner, $prov, $qs, $nul) {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    return json_decode(($a !== false && $b !== false) ? substr($raw, $a, $b - $a + 1) : $raw, true);
}
$idx = function($d){ $m=[]; foreach(($d['filas']??[]) as $f) $m[$f['negocio']]=$f; return $m; };
$fail = 0;

// Tomar una tienda real del catálogo.
$cat = ep($php, $runner, $prov, 'tab=filtros', $nul);
$tienda = null;
foreach (($cat['combos'] ?? []) as $c) if (($c['tienda'] ?? '') !== '') { $tienda = $c['tienda']; break; }
if ($tienda === null) { echo "NOTA: proveedor sin tiendas en catálogo; test trivial OK\n"; echo "RESULTADO: OK\n"; exit(0); }

$base = $idx(ep($php, $runner, $prov, 'tab=data', $nul));
$filt = $idx(ep($php, $runner, $prov, 'tab=data&tienda[]=' . rawurlencode($tienda), $nul));
echo "tienda='$tienda' negocios base=" . count($base) . " filtrado=" . count($filt) . "\n";
foreach ($filt as $neg => $f) {
    if (!isset($base[$neg])) continue;
    if ((int)$f['stock_cedi'] !== (int)$base[$neg]['stock_cedi']) {
        echo "FALLO: stock_cedi cambió con filtro de tienda en $neg (" . $base[$neg]['stock_cedi'] . " -> " . $f['stock_cedi'] . ")\n"; $fail = 1; break;
    }
    if ((int)$f['stock_tiendas'] > (int)$base[$neg]['stock_tiendas']) {
        echo "FALLO: stock_tiendas filtrado > base en $neg\n"; $fail = 1; break;
    }
}
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
```

- [ ] **Step 2: Run the test**

Run: `C:/xampp/php/php.exe tests/o45_filtro_tienda_test.php`
Expected: PASS — `RESULTADO: OK`.

- [ ] **Step 3: Commit**

```bash
git add tests/o45_filtro_tienda_test.php
git commit -m "test(o45): filtro de tienda no altera Stock CEDI"
```

---

## Task 6: Frontend `informes/o45.php`

**Files:**
- Create: `informes/o45.php`

Referencia de patrones a copiar de `informes/o14.php`: inicialización Tom Select de los filtros en cascada (busca `o14InitFiltros`/`TomSelect` en `informes/o14.php`), `o14OnEnter` (topbar Desde/Hasta), y `tableToAOA` + export SheetJS.

- [ ] **Step 1: Create the page markup + script**

Create `informes/o45.php`:

```php
<?php /* Informe O45 — Índice de Ventas (incluido desde dashboard.php) */ ?>
<div class="page" id="page-informes-o45">
  <!-- Filtros -->
  <div class="g00-filters o45-filters">
    <div class="g00-filter-row">
      <div class="filter-group"><label>Grupo</label><select id="o45-f-grupo" multiple></select></div>
      <div class="filter-group o45-tienda-group"><label>Tienda</label><select id="o45-f-tienda" multiple></select></div>
      <div class="o14-apply">
        <button class="g00-btn-refresh" onclick="o45Load()"><i class="fa-solid fa-rotate"></i> Aplicar</button>
      </div>
    </div>
    <div class="g00-filter-row">
      <div class="filter-group"><label>Marca</label><select id="o45-f-marca" multiple></select></div>
      <div class="filter-group"><label>Tipo</label><select id="o45-f-tipo" multiple></select></div>
      <div class="filter-group"><label>Categoría</label><select id="o45-f-categoria" multiple></select></div>
      <div class="filter-group"><label>Subcategoría</label><select id="o45-f-subcategoria" multiple></select></div>
      <div class="filter-group"><label>Género</label><select id="o45-f-genero" multiple></select></div>
      <div class="filter-group"><label>Público</label><select id="o45-f-publico" multiple></select></div>
      <div class="filter-group"><label>Negocio</label><select id="o45-f-negocio" multiple></select></div>
      <div class="filter-group"><label>Referencia</label><select id="o45-f-referencia" multiple></select></div>
    </div>
  </div>

  <div class="tab-bar">
    <button class="g00-btn-export o14-export-btn" onclick="o45Export()">⤓ Excel</button>
  </div>
  <div id="o45-tabla" class="o14-matriz-wrap"></div>
</div>

<style>
  #page-informes-o45 .o45-tienda-group { min-width: 320px; flex: 2; }   /* Tienda más ancho: COD - NOMBRE completo */
  #page-informes-o45 .o45-tienda-group .ts-control { min-width: 320px; }
  #page-informes-o45 table.o45-tabla { width:100%; border-collapse:collapse; font-size:12px; }
  #page-informes-o45 table.o45-tabla th, #page-informes-o45 table.o45-tabla td { border:1px solid var(--border); padding:4px 8px; text-align:right; white-space:nowrap; }
  #page-informes-o45 table.o45-tabla th { background:#faf9ff; position:sticky; top:0; }
  #page-informes-o45 table.o45-tabla td.dim, #page-informes-o45 table.o45-tabla th.dim { text-align:left; }
  #page-informes-o45 table.o45-tabla tr.o45-total td { font-weight:700; background:#f3f1ff; }
</style>

<script>
  (function(){
    let filtrosInit = false, comboCatalogo = [];
    const DIMS = ['marca','tipo','categoria','subcategoria','genero','publico','negocio','referencia'];
    const tsRef = {};
    const nf = n => (n==null?'':Number(n).toLocaleString('es-CO'));
    const nf2 = n => (n==null?'—':Number(n).toLocaleString('es-CO',{minimumFractionDigits:2,maximumFractionDigits:2}));
    const val = id => (document.getElementById(id)?.value || '');
    function getMultiVals(id){ const el=document.getElementById(id); if(!el) return [];
      return Array.from(el.selectedOptions||[]).map(o=>o.value).filter(Boolean); }

    function buildParams(){
      const p = new URLSearchParams({ tab:'data', desde: val('o45-vdesde')||'2025-01-01', hasta: val('o45-vhasta')||'' });
      ['grupo','tienda',...DIMS].forEach(k=>{ getMultiVals('o45-f-'+k).forEach(v=>p.append(k+'[]', v)); });
      return p.toString();
    }

    function poblarSelect(id, valores, labelFn){
      const el=document.getElementById(id); if(!el) return;
      const uniq=[...new Set(valores.filter(v=>v!==''&&v!=null))].sort((a,b)=>String(a).localeCompare(String(b)));
      el.innerHTML = uniq.map(v=>'<option value="'+String(v).replace(/"/g,'&quot;')+'">'+(labelFn?labelFn(v):v)+'</option>').join('');
      if (window.TomSelect){ if(tsRef[id]) tsRef[id].destroy(); tsRef[id]=new TomSelect(el,{plugins:['remove_button'],maxOptions:null}); }
    }

    function initFiltros(){
      fetch('api/informe_o45.php?tab=filtros',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
        comboCatalogo = d.combos||[];
        DIMS.forEach(k=> poblarSelect('o45-f-'+k, comboCatalogo.map(c=>c[k])));
        poblarSelect('o45-f-grupo', comboCatalogo.map(c=>c.grupo));
        // Tienda: value = NOMBRE, label = "COD - NOMBRE"
        const tEl=document.getElementById('o45-f-tienda');
        const seen={}; const opts=[];
        comboCatalogo.forEach(c=>{ const nom=c.tienda||''; if(!nom||seen[nom])return; seen[nom]=1; opts.push({nom, cod:c.tienda_cod||''}); });
        opts.sort((a,b)=>a.cod.localeCompare(b.cod));
        tEl.innerHTML = opts.map(o=>'<option value="'+o.nom.replace(/"/g,'&quot;')+'">'+o.cod+' - '+o.nom+'</option>').join('');
        if (window.TomSelect){ if(tsRef['o45-f-tienda']) tsRef['o45-f-tienda'].destroy(); tsRef['o45-f-tienda']=new TomSelect(tEl,{plugins:['remove_button'],maxOptions:null}); }
      });
    }

    const COLS = [
      {k:'negocio',t:'Negocio',dim:true}, {k:'marca',t:'Marca',dim:true},
      {k:'ventas',t:'Ventas (und)',f:nf}, {k:'tiendas',t:'#tiendas',f:nf},
      {k:'ind_inventario',t:'Índice inventario',f:nf2}, {k:'stock_cedi',t:'Stock CEDI',f:nf},
      {k:'stock_tiendas',t:'Stock Tiendas',f:nf}, {k:'total_stock',t:'Total Stock',f:nf},
      {k:'ind_ventas_mes',t:'Índice Ventas mes',f:nf2}, {k:'tallas',t:'Tallas',f:nf},
      {k:'precio',t:'Precio Detal',f:nf},
    ];

    function renderTabla(d){
      const cont=document.getElementById('o45-tabla');
      const filas=d.filas||[];
      if(!filas.length){ cont.innerHTML='<p style="padding:16px;color:var(--text-light)">Sin datos.</p>'; return; }
      let h='<table class="o45-tabla" id="o45-tbl"><thead><tr>';
      COLS.forEach(c=> h+='<th'+(c.dim?' class="dim"':'')+'>'+c.t+'</th>'); h+='</tr></thead><tbody>';
      filas.forEach(f=>{ h+='<tr>'; COLS.forEach(c=>{ const v=f[c.k]; h+='<td'+(c.dim?' class="dim"':'')+'>'+(c.dim?(v??''):c.f(v))+'</td>'; }); h+='</tr>'; });
      const t=d.total||{};
      h+='<tr class="o45-total"><td class="dim">TOTAL</td><td class="dim"></td>';
      ['ventas','tiendas','ind_inventario','stock_cedi','stock_tiendas','total_stock','ind_ventas_mes'].forEach(k=>{
        const fmt=(k==='ind_inventario'||k==='ind_ventas_mes')?nf2:nf; h+='<td>'+fmt(t[k])+'</td>'; });
      h+='<td></td><td></td></tr>';   // Tallas y Precio en blanco
      h+='</tbody></table>'; cont.innerHTML=h;
    }

    window.o45Load = function(){
      const cont=document.getElementById('o45-tabla'); cont.innerHTML='<p style="padding:16px;color:var(--text-light)">Cargando…</p>';
      fetch('api/informe_o45.php?'+buildParams(),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
        if(!d.ok){ cont.innerHTML='<p style="padding:16px;color:var(--accent)">Error al cargar.</p>'; return; }
        window.__o45last=d; renderTabla(d);
      }).catch(()=>{ cont.innerHTML='<p style="padding:16px;color:var(--accent)">Error de red.</p>'; });
    };

    window.o45Export = function(){
      const tbl=document.getElementById('o45-tbl');
      if(!tbl || typeof XLSX==='undefined'){ if(window.Swal) Swal.fire('Exportar','Carga el informe primero.','info'); return; }
      const aoa=[...tbl.querySelectorAll('tr')].map(tr=>[...tr.children].map(td=>{
        const txt=td.textContent.trim(); const num=Number(txt.replace(/\./g,'').replace(',','.'));
        return (txt!=='' && !isNaN(num) && /[0-9]/.test(txt)) ? num : txt; }));
      const wb=XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(aoa), 'Indice');
      XLSX.writeFile(wb,'O45_indice_ventas.xlsx');
    };

    window.o45OnEnter = function(){
      document.getElementById('pageTitle').textContent = 'ÍNDICE DE VENTAS';
      document.getElementById('topbar').classList.add('topbar--o14');
      document.getElementById('pageSubtitle').style.display = 'none';
      const hoy = new Date().toISOString().slice(0,10);
      const td = document.getElementById('topbarDates'); td.style.display = '';
      if (!document.getElementById('o45-vdesde')) {
        td.innerHTML = '<div class="o14-topbar-dates"><label>Desde<input type="date" id="o45-vdesde" value="2025-01-01"></label>'
          + '<label>Hasta<input type="date" id="o45-vhasta" value="'+hoy+'"></label></div>';
      }
      const rb = document.getElementById('topbarO45Refresh'); if(rb) rb.style.display = '';
      if (!filtrosInit) { initFiltros(); filtrosInit = true; }
      if (!window.__o45last) o45Load();
    };
  })();
</script>
```

- [ ] **Step 2: Lint**

Run: `C:/xampp/php/php.exe -l informes/o45.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add informes/o45.php
git commit -m "feat(o45): pagina frontend (filtros cascada, tabla, Excel, topbar Desde/Hasta)"
```

---

## Task 7: Cablear el menú en `dashboard.php`

**Files:**
- Modify: `dashboard.php`

- [ ] **Step 1: Apuntar el botón del menú al informe**

En `dashboard.php`, reemplaza el onclick del botón Índice de Ventas:

```html
                <div class="nav-item" onclick="showPage('indice-ventas', this)">
```

por:

```html
                <div class="nav-item" onclick="showPage('informes-o45', this)">
```

- [ ] **Step 2: Incluir la página y quitar el placeholder**

Reemplaza el placeholder `#page-indice-ventas` por el include. Busca el bloque:

```html
            <!-- ==================== ÍNDICE DE VENTAS (en desarrollo) ==================== -->
            <div class="page" id="page-indice-ventas">
                <div class="card" style="text-align:center;padding:48px 24px;">
                    <div style="font-size:42px;color:var(--accent);margin-bottom:12px;"><i class="fa-solid fa-arrow-trend-up"></i></div>
                    <div class="card-title" style="justify-content:center;">&Iacute;ndice de Ventas</div>
                    <p style="color:var(--text-light);margin-top:8px;">M&oacute;dulo en desarrollo.</p>
                </div>
            </div>
```

y déjalo así:

```php
            <!-- ==================== ÍNDICE DE VENTAS (O45) ==================== -->
            <?php include __DIR__ . '/informes/o45.php'; ?>
```

- [ ] **Step 3: Botón de refresh en el topbar**

Después del botón `topbarO14Refresh` en el topbar (`<button id="topbarO14Refresh" ...>...</button>`), agrega:

```html
                <button id="topbarO45Refresh" class="topbar-action" style="display:none;" onclick="o45Load()">
                    <i class="fa-solid fa-arrows-rotate"></i> Actualizar
                </button>
```

- [ ] **Step 4: Título y onEnter en `showPage`**

En el mapa `titles` de `showPage`, la entrada `'indice-ventas':'ÍNDICE DE VENTAS'` ya existe; cámbiala a la nueva clave:

```js
            'informes-o45':'ÍNDICE DE VENTAS',
            'evolucion-historica':'EVOLUCIÓN HISTÓRICA'
```

(Elimina la línea anterior `'indice-ventas':'ÍNDICE DE VENTAS',`.)

En `showPage`, junto a las líneas que ocultan los refresh, agrega el de O45:

```js
        document.getElementById('topbarO45Refresh').style.display = 'none';
```

Y junto a los `o14OnEnter`/`g00OnEnter`, agrega:

```js
        if (pageId === 'informes-o45' && typeof o45OnEnter === 'function') o45OnEnter();
```

- [ ] **Step 5: Lint**

Run: `C:/xampp/php/php.exe -l dashboard.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add dashboard.php
git commit -m "feat(o45): cablear Indice de Ventas al menu (reemplaza placeholder) + topbar refresh"
```

---

## Task 8: Verificación E2E en navegador (manual)

**No automatizable.** Abrir `localhost/plataforma_20`, iniciar sesión con un proveedor real y:

- [ ] El menú REPORTES → **Índice de Ventas** abre el informe (título ÍNDICE DE VENTAS, Desde/Hasta en el topbar).
- [ ] El cuadro lista negocios con las 11 columnas y fila TOTAL; orden por Ventas desc.
- [ ] El selector **Tienda** es ancho y muestra `COD - NOMBRE`; al elegir una tienda, Stock CEDI no cambia.
- [ ] Cambiar Desde/Hasta y **Aplicar** recalcula Ventas e Índice de Ventas mes (Stock no cambia).
- [ ] **Excel** descarga el cuadro con números (no texto).
- [ ] Filtros de dimensión (Marca…Referencia, Negocio) acotan las filas.

Si todo pasa, marcar el plan como completo en el changelog (`changelog_plataforma20.md`).

---

## Self-review (cobertura del spec)

- Topbar Desde/Hasta + título + 3 botones → Task 6 (`o45OnEnter`) + Task 7 (botón refresh).
- Filtros fila 1 (Grupo/Tienda, Tienda ancho COD-NOMBRE) y fila 2 (dimensiones) → Task 6 markup + Task 4 catálogo.
- 11 columnas + fila TOTAL + Excel → Task 1/2/3 (datos) + Task 6 (render/export).
- Universo "ventas O stock" → Task 1 (`llaves` = unión de d/h/v/v30).
- Índice inventario (Total Stock ÷ ventas30 hasta Hasta) y div/0 → Task 1 (ventas30) + Task 2.
- Índice ventas mes ((ventas/#tiendas)/(días/30)) y #tiendas=0 → Task 2.
- Stock CEDI/Tiendas/Total y CEDI = `bodega='CEDI'` → Task 1.
- Grupo/Tienda acota tiendas, CEDI intacto → Task 1 (filtro) + Task 5 (test).
- Precio Detal (MAX f126_precio por ref+color) → Task 3.
- Gotchas SQL (CIA=7 / NOLOCK / temp sin params / #refs cache) → respetados en el SQL de Task 1 (CREATE sin params + INSERT; NOLOCK; #refs cacheado). Nota: el join a Bodegas usa `RIGHT('000'+CIA,3)=cia` por COD+cia (evita fan-out sin necesidad de `CIA=7` explícito, igual que O14).
- Tests (universo, ventana 30d, split stock, índices, filtro tienda, smoke) → Tasks 1/2/5. (La ventana 30d se valida indirectamente vía `ventas30` y el índice; un negocio con stock y 0 ventas queda cubierto por el invariante de Task 1.)
