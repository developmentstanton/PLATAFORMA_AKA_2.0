# Georeferenciación — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir el informe "Georeferenciación" — un mapa Leaflet de Colombia con las tiendas donde el proveedor vendió, marcadas por grupo, con hover que muestra ventas en valor y unidades.

**Architecture:** Endpoint PHP `api/informe_geo.php` (clon de `api/informe_evol.php`) que agrega ventas (`VALOR`+`CANTIDAD`) por tienda (cía-bodega) sobre el rango Año-Mes, joinea `Bodegas` (nombre/grupo/ciudad/coordenadas) y devuelve JSON. El parseo de coordenadas vive en `api/lib_geo.php` (testeable en aislamiento). Frontend `informes/geo.php` pinta un mapa Leaflet (satélite Esri) con `circleMarker` por tienda + tooltip al hover, contador y lista. Wiring en `dashboard.php`.

**Tech Stack:** PHP 8 + `sqlsrv` (SQL Server, `INTEGRACION.dbo`), JS vanilla + **Leaflet 1.9.4** (CDN) + Tom Select. Sin build.

**Spec:** `docs/superpowers/specs/2026-06-12-georreferenciacion-design.md`

**Reuso clave:** topbar Año-Mes + filtros en cascada + `#refs` vienen de `api/informe_evol.php` / `informes/evol.php` (mismo patrón). El estilo `.o14-vfilter input[type=month]` ya existe en `dashboard.php`.

---

## File Structure

- **Create** `api/lib_geo.php` — `parseCoord(string): [float|null, float|null]` (parseo robusto de `COORDENADAS`).
- **Create** `api/informe_geo.php` — endpoint (`tab=data` tiendas+ventas; `tab=filtros` catálogo cascada).
- **Create** `informes/geo.php` — página: mapa Leaflet, marcadores, hover, contador, lista, topbar Año-Mes, filtros.
- **Modify** `dashboard.php` — Leaflet CSS/JS en `<head>`; nav-item Georeferenciación; botón `topbarGeoRefresh`; `include informes/geo.php`; `showPage` (título + reset + dispatch `geoOnEnter`).
- **Create** `tests/_endpoint_run_geo.php` — runner CLI.
- **Create** `tests/geo_coord_test.php` — unit test de `parseCoord`.
- **Create** `tests/geo_smoke_test.php` — estructura + invariantes del endpoint.

---

## Task 1: `api/lib_geo.php` + unit test de parseCoord (TDD)

**Files:**
- Create: `api/lib_geo.php`
- Create: `tests/geo_coord_test.php`

- [ ] **Step 1: Escribir el test (rojo)**

Create `tests/geo_coord_test.php`:

```php
<?php
// Unit test de parseCoord (lib_geo). Uso: php tests/geo_coord_test.php
require __DIR__ . '/../api/lib_geo.php';
$fail = 0;
function chk(&$fail,$label,$got,$exp){
  $g = json_encode($got); $e = json_encode($exp);
  if ($g !== $e) { echo "FALLO $label: got=$g exp=$e\n"; $fail=1; }
}
// Formatos reales observados en Bodegas.COORDENADAS
chk($fail,'espacio',      parseCoord('4.609927 -74.083026'),     [4.609927, -74.083026]);
chk($fail,'coma+espacio', parseCoord('4.689665, -74.071421'),    [4.689665, -74.071421]);
chk($fail,'coma',         parseCoord('4.70217,-74.041622'),      [4.70217, -74.041622]);
chk($fail,'coma+zoom',    parseCoord('4.6480302,-74.0912985,17'),[4.6480302, -74.0912985]); // ignora el 3er numero
// Basura / vacíos / fuera de rango -> [null,null]
chk($fail,'vacio',        parseCoord(''),        [null,null]);
chk($fail,'null',         parseCoord(null),      [null,null]);
chk($fail,'un_solo_num',  parseCoord('4.6099'),  [null,null]);
chk($fail,'texto',        parseCoord('N/A'),     [null,null]);
chk($fail,'fuera_rango',  parseCoord('40.7128, -74.0060'), [null,null]); // Nueva York: lat fuera de Colombia
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php tests/geo_coord_test.php`
Expected: error fatal "Call to undefined function parseCoord()" (aún no existe `lib_geo.php`). (Ignorar warnings de xdebug/php_dio/openssl.)

- [ ] **Step 3: Implementar `api/lib_geo.php`**

Create `api/lib_geo.php`:

```php
<?php
/** Parseo robusto de Bodegas.COORDENADAS (texto libre: "lat lng", "lat, lng", "lat,lng,zoom"). */
if (!function_exists('parseCoord')) {
    /**
     * Devuelve [lat, lng] (floats) o [null, null] si no hay 2 coordenadas válidas en rango Colombia.
     * Toma los 2 primeros números decimales del texto; ignora cualquier 3er valor (zoom).
     */
    function parseCoord($s) {
        if (!preg_match_all('/-?\d+\.\d+/', (string)$s, $m) || count($m[0]) < 2) return [null, null];
        $lat = (float)$m[0][0];
        $lng = (float)$m[0][1];
        if ($lat < -5 || $lat > 15 || $lng < -80 || $lng > -66) return [null, null];
        return [$lat, $lng];
    }
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php tests/geo_coord_test.php`
Expected: `RESULTADO: OK`.

- [ ] **Step 5: Commit**

```bash
git add api/lib_geo.php tests/geo_coord_test.php
git commit -m "feat(geo): parseCoord robusto + unit test (3 formatos, basura, fuera de rango)"
```
(Terminar el mensaje con: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`)

---

## Task 2: Endpoint `api/informe_geo.php` + smoke test (TDD)

**Files:**
- Create: `tests/_endpoint_run_geo.php`
- Create: `tests/geo_smoke_test.php`
- Create: `api/informe_geo.php`
- Referencia: `api/informe_evol.php`, `api/lib_refs.php`

- [ ] **Step 1: Crear el runner CLI**

Create `tests/_endpoint_run_geo.php`:

```php
<?php
// Ejecuta el endpoint Georeferenciación como request y vuelca el JSON. Uso:
//   php tests/_endpoint_run_geo.php "PROVEEDOR" "tab=data&desde=2025-01&hasta=2026-06"
error_reporting(E_ALL); ini_set('display_errors', '0');
$prov = $argv[1] ?? 'BELTRANY SAS';
$qs   = $argv[2] ?? 'tab=data';
session_start();
$_SESSION = ['usuario' => 'test', 'proveedor' => $prov];
parse_str($qs, $_GET);
if (!isset($_GET['tab'])) $_GET['tab'] = 'data';
include __DIR__ . '/../api/informe_geo.php';
```

- [ ] **Step 2: Escribir el smoke test (rojo)**

Create `tests/geo_smoke_test.php`:

```php
<?php
// Smoke de Georeferenciación: estructura + invariantes. Uso: php tests/geo_smoke_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run_geo.php';
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

foreach (['proveedor','rango','tiendas','total_tiendas','sin_coord'] as $k)
  if (!array_key_exists($k,$d)) { echo "FALLO: falta '$k'\n"; $fail=1; }
$tiendas = $d['tiendas'] ?? [];
echo "tiendas=".count($tiendas)."  total_tiendas=".($d['total_tiendas']??'?')."  sin_coord=".($d['sin_coord']??'?')."\n";

$keys = ['cia','cod','nombre','grupo','ciudad','lat','lng','valor','unidades'];
$conCoord = 0; $sinCoord = 0; $sumValor = 0;
foreach ($tiendas as $t) {
  foreach ($keys as $k) if (!array_key_exists($k,$t)) { echo "FALLO: falta key '$k' en tienda\n"; $fail=1; break 2; }
  if ($t['lat'] === null) { $sinCoord++; }
  else {
    $conCoord++;
    if ($t['lat'] < -5 || $t['lat'] > 15 || $t['lng'] < -80 || $t['lng'] > -66) { echo "FALLO: coord fuera de rango en ".$t['cod']."\n"; $fail=1; }
  }
  $sumValor += (float)$t['valor'];
}
// Invariantes
if ((int)$d['total_tiendas'] !== count($tiendas)) { echo "FALLO: total_tiendas != count(tiendas)\n"; $fail=1; }
if ((int)$d['sin_coord'] !== $sinCoord) { echo "FALLO: sin_coord != tiendas con lat null\n"; $fail=1; }
if ($conCoord === 0) { echo "FALLO: ninguna tienda con coordenada (esperado: la mayoria)\n"; $fail=1; }
if ($sumValor <= 0) { echo "FALLO: suma de ventas en valor no positiva\n"; $fail=1; }

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
```

- [ ] **Step 3: Correr el smoke y verificar que falla**

Run: `php tests/geo_smoke_test.php "BELTRANY SAS"`
Expected: FALLO ("tab=data no ok") — el endpoint no existe aún.

- [ ] **Step 4: Implementar `api/informe_geo.php`**

Create `api/informe_geo.php`:

```php
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
```

- [ ] **Step 5: Lint + smoke verde**

Run:
```bash
php -l api/informe_geo.php
php tests/geo_smoke_test.php "BELTRANY SAS"
```
Expected: `No syntax errors detected` y `RESULTADO: OK`. (Ignorar warnings de entorno.)
Si el smoke falla por "ninguna tienda con coordenada", probar con otro proveedor (`"BH BRANDS SAS"`); si persiste, depurar con `superpowers:systematic-debugging` (revisar el join a Bodegas por COD+CIA y el parseo).

- [ ] **Step 6: Commit**

```bash
git add tests/_endpoint_run_geo.php tests/geo_smoke_test.php api/informe_geo.php
git commit -m "feat(geo): endpoint ventas por tienda (valor+unidades) + coords + smoke verde"
```
(Terminar con el trailer Co-Authored-By.)

---

## Task 3: Frontend `informes/geo.php`

**Files:**
- Create: `informes/geo.php`
- Referencia: `informes/evol.php` (topbar Año-Mes + filtros cascada)

- [ ] **Step 1: Crear la página (mapa Leaflet + filtros + contador + lista)**

Create `informes/geo.php`:

```php
<?php /* Informe Georeferenciación (incluido desde dashboard.php) */ ?>
<div class="page" id="page-georreferenciacion">
  <div class="g00-filters geo-filters">
    <div class="g00-filter-row">
      <div class="filter-group"><label>Grupo</label><select id="geo-f-grupo" multiple></select></div>
      <div class="filter-group geo-tienda-group"><label>Tienda</label><select id="geo-f-tienda" multiple></select></div>
      <div class="o14-apply">
        <button class="g00-btn-refresh" onclick="geoLoad()"><i class="fa-solid fa-rotate"></i> Aplicar</button>
      </div>
    </div>
    <div class="g00-filter-row">
      <div class="filter-group"><label>Marca</label><select id="geo-f-marca" multiple></select></div>
      <div class="filter-group"><label>Tipo</label><select id="geo-f-tipo" multiple></select></div>
      <div class="filter-group"><label>Categoría</label><select id="geo-f-categoria" multiple></select></div>
      <div class="filter-group"><label>Subcategoría</label><select id="geo-f-subcategoria" multiple></select></div>
      <div class="filter-group"><label>Género</label><select id="geo-f-genero" multiple></select></div>
      <div class="filter-group"><label>Público</label><select id="geo-f-publico" multiple></select></div>
      <div class="filter-group"><label>Negocio</label><select id="geo-f-negocio" multiple></select></div>
      <div class="filter-group"><label>Referencia</label><select id="geo-f-referencia" multiple></select></div>
    </div>
  </div>

  <div class="geo-wrap">
    <div id="geo-map"></div>
    <div id="geo-count" class="geo-box"><span class="n" id="geo-count-n">0</span># Tiendas</div>
    <div id="geo-list" class="geo-box geo-list"><h4>Descripción Tiendas</h4><div id="geo-list-body"></div></div>
  </div>
</div>

<style>
  #page-georreferenciacion .geo-tienda-group { min-width: 320px; flex: 2; }
  #page-georreferenciacion .geo-tienda-group .ts-control { min-width: 320px; }
  #page-georreferenciacion .geo-wrap { position: relative; }
  #page-georreferenciacion #geo-map { width: 100%; height: calc(100vh - 230px); min-height: 460px; border-radius: 8px; }
  #page-georreferenciacion .geo-box { position: absolute; z-index: 1000; background: rgba(255,255,255,.93);
    border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.25); font-size: 12px; color: var(--text); }
  #page-georreferenciacion #geo-count { left: 14px; bottom: 22px; padding: 8px 16px; text-align: center; font-weight: 700; line-height: 1.1; }
  #page-georreferenciacion #geo-count .n { display: block; font-size: 24px; color: var(--accent); }
  #page-georreferenciacion .geo-list { right: 14px; bottom: 22px; width: 240px; max-height: 46%; overflow: auto; padding: 8px 12px; }
  #page-georreferenciacion .geo-list h4 { margin: 0 0 6px; font-size: 12px; text-align: center; color: var(--primary); }
  #page-georreferenciacion .geo-list .row { padding: 1px 0; white-space: nowrap; }
  .geo-tip b { color: #fff; }
  .leaflet-tooltip.geo-tooltip { background: #2d2b4e; color: #fff; border: none; box-shadow: 0 2px 8px rgba(0,0,0,.4); font-size: 12px; }
  .leaflet-tooltip.geo-tooltip::before { border-top-color: #2d2b4e; }
</style>

<script>
(function(){
  'use strict';
  let filtrosInit = false, comboCatalogo = [], map = null, markersLayer = null;
  const DIMS = ['marca','tipo','categoria','subcategoria','genero','publico','negocio','referencia'];
  const tsRef = {};
  const nf  = n => Number(n||0).toLocaleString('es-CO');
  const fmtMoney = v => '$ ' + Number(v||0).toLocaleString('es-CO');
  const esc = s => (s==null?'':String(s)).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const val = id => (document.getElementById(id)?.value || '');
  function getMultiVals(id){ const el=document.getElementById(id); if(!el) return [];
    return Array.from(el.selectedOptions||[]).map(o=>o.value).filter(Boolean); }

  function defDesde(){ const d=new Date(); return (d.getFullYear()-1)+'-01'; }
  function defHasta(){ const d=new Date(); return d.toISOString().slice(0,7); }

  function buildParams(){
    const p = new URLSearchParams({ tab:'data', desde: val('geo-vdesde')||defDesde(), hasta: val('geo-vhasta')||defHasta() });
    ['grupo','tienda',...DIMS].forEach(k=>{ getMultiVals('geo-f-'+k).forEach(v=>p.append(k+'[]', v)); });
    return p.toString();
  }

  function poblarSelect(id, valores, labelFn){
    const el=document.getElementById(id); if(!el) return;
    const uniq=[...new Set(valores.filter(v=>v!==''&&v!=null))].sort((a,b)=>String(a).localeCompare(String(b)));
    el.innerHTML = uniq.map(v=>'<option value="'+String(v).replace(/"/g,'&quot;')+'">'+esc(labelFn?labelFn(v):v)+'</option>').join('');
    if (window.TomSelect){ if(tsRef[id]) tsRef[id].destroy(); tsRef[id]=new TomSelect(el,{plugins:['remove_button'],maxOptions:null,placeholder:'Todas'}); }
  }

  function initFiltros(){
    fetch('api/informe_geo.php?tab=filtros',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      comboCatalogo = d.combos||[];
      DIMS.forEach(k=> poblarSelect('geo-f-'+k, comboCatalogo.map(c=>c[k])));
      poblarSelect('geo-f-grupo', comboCatalogo.map(c=>c.grupo));
      const tEl=document.getElementById('geo-f-tienda'); const seen={}; const opts=[];
      comboCatalogo.forEach(c=>{ const nom=c.tienda||''; if(!nom||seen[nom])return; seen[nom]=1; opts.push({nom, cod:c.tienda_cod||''}); });
      opts.sort((a,b)=>a.cod.localeCompare(b.cod));
      tEl.innerHTML = opts.map(o=>'<option value="'+esc(o.nom)+'">'+esc(o.cod)+' - '+esc(o.nom)+'</option>').join('');
      if (window.TomSelect){ if(tsRef['geo-f-tienda']) tsRef['geo-f-tienda'].destroy(); tsRef['geo-f-tienda']=new TomSelect(tEl,{plugins:['remove_button'],maxOptions:null,placeholder:'Todas'}); }
    });
  }

  // Color por grupo: paleta explícita para los conocidos + fallback determinístico por hash.
  const GRUPO_COLOR = {
    'AKA':'#e6194B','ZEUS':'#4363d8','SPRING STEP':'#f58231','BRAHMA':'#3cb44b',
    'BRAHMA CONCEPT':'#3cb44b','BODEGA':'#911eb4','OUTLET':'#42d4f4','OX':'#bfef45','SOLIMAR':'#f032e6'
  };
  const PAL = ['#e6194B','#3cb44b','#4363d8','#f58231','#911eb4','#42d4f4','#f032e6','#bfef45','#fabed4','#469990','#9A6324','#800000','#808000','#000075'];
  function colorFor(g){ g=(g||'').toUpperCase().trim(); if(GRUPO_COLOR[g]) return GRUPO_COLOR[g];
    let h=0; for(let i=0;i<g.length;i++) h=(h*31+g.charCodeAt(i))>>>0; return PAL[h%PAL.length]; }

  function ensureMap(){
    if (map) return;
    map = L.map('geo-map', { zoomControl:true, attributionControl:true }).setView([4.6,-74.1], 6);
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
      { maxZoom:19, attribution:'Tiles &copy; Esri' }).addTo(map);
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
      { maxZoom:19 }).addTo(map);
    markersLayer = L.layerGroup().addTo(map);
  }

  function renderMapa(d){
    ensureMap();
    markersLayer.clearLayers();
    const tiendas = d.tiendas||[];
    tiendas.forEach(t=>{
      if (t.lat==null || t.lng==null) return;
      const m = L.circleMarker([t.lat,t.lng], { radius:7, fillColor:colorFor(t.grupo), color:'#fff', weight:1, fillOpacity:0.92 });
      const html = '<b>'+esc(t.cod)+' - '+esc(t.nombre)+'</b><br>Ventas: '+fmtMoney(t.valor)+'<br>Unidades: '+nf(t.unidades);
      m.bindTooltip(html, { sticky:true, direction:'top', className:'geo-tooltip', opacity:1 });
      markersLayer.addLayer(m);
    });
    document.getElementById('geo-count-n').textContent = nf(d.total_tiendas);
    const body = (tiendas.slice().sort((a,b)=>String(a.cod).localeCompare(String(b.cod)))
      .map(t=>'<div class="row">'+esc(t.cod)+' - '+esc(t.nombre)+'</div>').join('')) || '<div class="row">Sin tiendas.</div>';
    document.getElementById('geo-list-body').innerHTML = body;
  }

  function showLoading(){ if(!window.Swal) return; Swal.fire({title:'Cargando',html:'Obteniendo información…',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()}); }
  function hideLoading(){ if(window.Swal && Swal.isVisible()) Swal.close(); }

  window.geoLoad = function(){
    showLoading();
    fetch('api/informe_geo.php?'+buildParams(),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(!d.ok){ if(window.Swal) Swal.fire('Error','No se pudo cargar el mapa.','error'); return; }
      window.__geolast=d; if(d.proveedor) setTitle(d.proveedor); renderMapa(d);
    }).catch(()=>{ if(window.Swal) Swal.fire('Error','Error de red.','error'); }).finally(hideLoading);
  };

  function setTitle(prov){ document.getElementById('pageTitle').textContent = 'GEOREFERENCIACIÓN' + (prov ? ' - ' + prov : ''); }

  window.geoOnEnter = function(){
    setTitle(window.PROVEEDOR_ACTUAL || '');
    document.getElementById('topbar').classList.add('topbar--o14');
    document.getElementById('pageSubtitle').style.display = 'none';
    const td = document.getElementById('topbarDates'); td.style.display = '';
    if (!document.getElementById('geo-vdesde')) {
      td.innerHTML =
        '<div class="o14-vfilter"><span class="o14-vfilter-lbl">Meses</span>'
        + '<label>Desde<input type="month" id="geo-vdesde" value="'+defDesde()+'"></label>'
        + '<label>Hasta<input type="month" id="geo-vhasta" value="'+defHasta()+'" max="'+defHasta()+'"></label></div>';
    }
    const rb = document.getElementById('topbarGeoRefresh'); if(rb) rb.style.display = '';
    if (!filtrosInit) { initFiltros(); filtrosInit = true; }
    ensureMap();
    setTimeout(()=>{ if(map) map.invalidateSize(); }, 0);   // el contenedor ya es visible al entrar
    if (!window.__geolast) geoLoad();
  };
})();
</script>
```

- [ ] **Step 2: Lint**

Run: `php -l informes/geo.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add informes/geo.php
git commit -m "feat(geo): pagina frontend (mapa Leaflet satelite, marcadores por grupo, hover ventas, contador+lista)"
```
(Terminar con el trailer Co-Authored-By.)

---

## Task 4: Wiring en `dashboard.php`

**Files:**
- Modify: `dashboard.php` (5 puntos)

- [ ] **Step 1: Leaflet CSS/JS en el `<head>`**

Buscar la línea del CDN de SheetJS (≈ línea 34):
```html
    <script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
```
Inmediatamente DESPUÉS, añadir:
```html
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
```

- [ ] **Step 2: Nuevo ítem de menú en REPORTES**

Buscar el nav-item de Evolución Histórica (≈ líneas 513-515):
```html
                <div class="nav-item" onclick="showPage('evolucion-historica', this)">
                    <span class="icon"><i class="fa-solid fa-clock-rotate-left"></i></span> Evoluci&oacute;n Hist&oacute;rica
                </div>
```
Inmediatamente DESPUÉS de ese `</div>`, añadir:
```html
                <div class="nav-item" onclick="showPage('georreferenciacion', this)">
                    <span class="icon"><i class="fa-solid fa-location-dot"></i></span> Georeferenciaci&oacute;n
                </div>
```

- [ ] **Step 3: Botón de refresh en el topbar**

Buscar el botón `topbarEvolRefresh` (≈ líneas 562-564):
```html
                <button id="topbarEvolRefresh" class="topbar-action" style="display:none;" onclick="evolLoad()">
                    <i class="fa-solid fa-arrows-rotate"></i> Actualizar
                </button>
```
Inmediatamente DESPUÉS, añadir:
```html
                <button id="topbarGeoRefresh" class="topbar-action" style="display:none;" onclick="geoLoad()">
                    <i class="fa-solid fa-arrows-rotate"></i> Actualizar
                </button>
```

- [ ] **Step 4: Include de la página**

Buscar el include de Evolución (≈ línea 968):
```php
            <!-- ==================== EVOLUCIÓN HISTÓRICA ==================== -->
            <?php include __DIR__ . '/informes/evol.php'; ?>
```
Inmediatamente DESPUÉS, añadir:
```php

            <!-- ==================== GEOREFERENCIACIÓN ==================== -->
            <?php include __DIR__ . '/informes/geo.php'; ?>
```

- [ ] **Step 5: showPage — título, reset y dispatch**

(5a) Buscar (≈ línea 1230):
```javascript
            'evolucion-historica':'EVOLUCIÓN HISTÓRICA'
        };
```
Reemplazar por (añade coma + entrada geo):
```javascript
            'evolucion-historica':'EVOLUCIÓN HISTÓRICA',
            'georreferenciacion':'GEOREFERENCIACIÓN'
        };
```

(5b) Buscar la línea de reset de evol (≈ línea 1238):
```javascript
        document.getElementById('topbarEvolRefresh').style.display = 'none';
```
Inmediatamente DESPUÉS, añadir:
```javascript
        document.getElementById('topbarGeoRefresh').style.display = 'none';
```

(5c) Buscar el dispatch de evol (≈ línea 1245):
```javascript
        if (pageId === 'evolucion-historica' && typeof evolOnEnter === 'function') evolOnEnter();
```
Inmediatamente DESPUÉS, añadir:
```javascript
        if (pageId === 'georreferenciacion' && typeof geoOnEnter === 'function') geoOnEnter();
```

- [ ] **Step 6: Verificar + lint**

Run:
```bash
grep -n "leaflet@1.9.4\|georreferenciacion\|topbarGeoRefresh\|informes/geo.php\|geoOnEnter" dashboard.php
php -l dashboard.php
```
Expected: el grep muestra el CSS+JS de Leaflet, el nav-item, el botón (def + reset), el include, el título y el dispatch; lint `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add dashboard.php
git commit -m "feat(geo): cablear Georeferenciacion (Leaflet CDN, menu, boton, include, showPage)"
```
(Terminar con el trailer Co-Authored-By.)

---

## Task 5: Integración, suite y handoff E2E

**Files:** (sin cambios de código salvo correcciones que surjan)

- [ ] **Step 1: Suite del informe + lint final**

Run:
```bash
php tests/geo_coord_test.php
php tests/geo_smoke_test.php "BELTRANY SAS"
php -l api/lib_geo.php && php -l api/informe_geo.php && php -l informes/geo.php && php -l dashboard.php
```
Expected: `RESULTADO: OK` (×2) y `No syntax errors detected` (×4).

- [ ] **Step 2: No-regresión** (compartimos `lib_refs.php` y cache `g00_refs_*`)

Run:
```bash
php tests/evol_golden_test.php "BH BRANDS SAS"
php tests/o45_smoke_test.php "BELTRANY SAS"
php tests/g00_detal_smoke_test.php "BELTRANY SAS"
```
Expected: todos `OK`.

- [ ] **Step 3: Commit (si hubo correcciones)**

```bash
git add api/informe_geo.php informes/geo.php   # solo los archivos del informe que se hayan tocado
git commit -m "test(geo): suite verde + no-regresion"
```
(NO usar `git add -A` — hay un cambio sin relación en `informes/g00.php` que no se debe commitear. Terminar con el trailer Co-Authored-By.)

- [ ] **Step 4: Handoff E2E a Rafael** (manual, navegador). NO ejecutar navegador aquí.

Checklist (REPORTES → Georeferenciación):
1. Topbar centrado "GEOREFERENCIACIÓN - {Tercero}", control Meses Desde/Hasta (Año-Mes) a la izquierda, botón Actualizar.
2. Mapa satélite de Colombia con etiquetas; marcadores de tamaño fijo coloreados por grupo; al hacer hover sale el cuadro con `COD - NOMBRE` + Ventas $ + Unidades.
3. Contador "# Tiendas" abajo-izq = total con venta; lista "Descripción Tiendas" abajo-der.
4. Cambiar rango Año-Mes y/o filtros (cascada) → recarga marcadores/contador/lista.
5. El mapa dimensiona bien al entrar y al volver desde otro informe (sin "medio gris").

> Tras el OK de Rafael: decidir push + PR apilado de `feat/georreferenciacion` (base `feat/evolucion-historica`).

---

## Self-Review (autor del plan)

**1. Cobertura del spec:**
- §2 modelo (ventas $+unidades por tienda; color=grupo en front; universo con venta) → Task 2 (#base + agg) + Task 3 (`colorFor`). ✓
- §2.1 parseo de coordenadas (3 formatos, ~72%, rango Colombia) → Task 1 (`parseCoord` + test). ✓
- §3 backend (rango Año-Mes, `tab=data`/`tab=filtros`, respuesta con `total_tiendas`/`sin_coord`) → Task 2. ✓
- §4 frontend (topbar, filtros, mapa Leaflet satélite+labels, marcadores hover, contador, lista, gotcha invalidateSize) → Task 3. ✓
- §5 wiring (Leaflet CDN, menú, botón, include, showPage) → Task 4. ✓
- §7 testing (estructura, suma, parseCoord, universo sin-coord, php -l) → Task 1/2/5. ✓

**2. Placeholders:** ninguno; todo el código es literal.

**3. Consistencia de tipos/nombres:** claves del JSON `tiendas[].{cia,cod,nombre,grupo,ciudad,lat,lng,valor,unidades}` + `total_tiendas`/`sin_coord` son idénticas en backend (Task 2), smoke test (Task 2) y frontend (`renderMapa`, Task 3). `geoLoad`/`geoOnEnter`/`topbarGeoRefresh`/`#page-georreferenciacion`/`geo-vdesde`/`geo-vhasta` consistentes entre `informes/geo.php` y el wiring de `dashboard.php` (Task 4). `parseCoord` definido en Task 1 y usado en Task 2. ✓
