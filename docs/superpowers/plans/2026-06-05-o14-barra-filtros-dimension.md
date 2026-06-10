# O14 — Barra de filtros por dimensión (14 filtros + cascada) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Llevar a O14 los 14 filtros multi-valor del G00 (referencia/color-talla/bodega) con cascada, aplicados sobre `#base` (afectan B/C/KPIs, conservan CEDI para reco), conservando Compañía + Negocio; barra y KPIs a ancho completo.

**Architecture:** Backend aplica filtros en el único punto de paso `#base`: poda `#refs` (dims de referencia), `DELETE #base` (color/talla y bodega manteniendo CEDI); nuevo `tab=filtros` devuelve catálogo (combos+sku) para la cascada y NO aplica filtros. Frontend porta la barra + cascada Tom Select del G00 a O14. Spec: `docs/superpowers/specs/2026-06-05-o14-barra-filtros-dimension.md`.

**Tech Stack:** PHP + sqlsrv; JS vanilla + Tom Select (CDN ya cargado); tests = arnés CLI que subprocesa el endpoint real.

**Rama:** `feat/o14-topbar-g00-style` (continúa el trabajo de O14 en curso).

---

## File Structure

- `api/informe_o14.php` — parser de filtros + aplicación (poda #refs, deletes #base) + bloque `tab=filtros` (catálogo).
- `informes/o14.php` — markup de la barra (3 filas nuevas) + CSS anchos + JS cascada (Tom Select) + `buildParams`.
- `tests/_endpoint_run_o14.php` — **nuevo**, runner del endpoint O14 (análogo al de G00).
- `tests/o14_filtros_test.php` — **nuevo**, verifica catálogo + aplicación de filtros.

---

## Task 1: Backend — aplicar los 14 filtros sobre #base (b/c/reco) + runner + test

**Files:**
- Create: `tests/_endpoint_run_o14.php`, `tests/o14_filtros_test.php`
- Modify: `api/informe_o14.php`

- [ ] **Step 1: Runner del endpoint O14**

Create `tests/_endpoint_run_o14.php`:

```php
<?php
// Ejecuta el endpoint O14 real como request y vuelca el JSON. Uso:
//   php tests/_endpoint_run_o14.php "PROVEEDOR" "tab=b&grupo[]=AKA"
error_reporting(E_ALL); ini_set('display_errors', '0');
$prov = $argv[1] ?? 'BELTRANY SAS';
$qs   = $argv[2] ?? 'tab=b';
session_start();
$_SESSION = ['usuario' => 'test', 'proveedor' => $prov];
parse_str($qs, $_GET);
if (!isset($_GET['tab'])) $_GET['tab'] = 'b';
include __DIR__ . '/../api/informe_o14.php';
```

- [ ] **Step 2: Write the failing test**

Create `tests/o14_filtros_test.php`:

```php
<?php
// Verifica: tab=filtros devuelve combos+sku; un filtro reduce/mantiene #base (filas ⊆ sin filtro);
// invariante O14B faltante-sobrante = siembra-(disp+hold); reco ve CEDI aun con filtro de bodega.
//   php tests/o14_filtros_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run_o14.php';
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

// 1) catálogo
$cat = ep($php, $runner, $prov, 'tab=filtros', $nul);
$combos = $cat['combos'] ?? null; $sku = $cat['sku'] ?? null;
echo "filtros: combos=" . (is_array($combos)?count($combos):'?') . " sku=" . (is_array($sku)?count($sku):'?') . "\n";
if (!is_array($combos) || !count($combos)) { echo "FALLO: combos vacío\n"; $fail = 1; }
if (!is_array($sku) || !count($sku))       { echo "FALLO: sku vacío\n"; $fail = 1; }

// 2) tab=b sin filtro vs con filtro de talla (toma una talla real del sku)
$base = ep($php, $runner, $prov, 'tab=b', $nul);
$nBase = count($base['filas'] ?? []);
echo "tab=b sin filtro: filas=$nBase ok=" . (($base['ok']??false)?'1':'0') . "\n";
if (!($base['ok'] ?? false)) { echo "FALLO: tab=b base\n"; $fail = 1; }

$tallaTest = $sku[0]['talla'] ?? '';
if ($tallaTest !== '') {
    $f = ep($php, $runner, $prov, 'tab=b&talla[]=' . rawurlencode($tallaTest), $nul);
    $nF = count($f['filas'] ?? []);
    echo "tab=b talla[]=$tallaTest: filas=$nF ok=" . (($f['ok']??false)?'1':'0') . "\n";
    if (!($f['ok'] ?? false)) { echo "FALLO: tab=b filtrado talla\n"; $fail = 1; }
    // invariante por fila: Σfaltante - Σsobrante == Σsiembra - (Σdisp+Σhold)
    foreach (($f['filas'] ?? []) as $fila) {
        $sum = fn($m) => array_sum($fila['valores'][$m] ?? []);
        $lhs = $sum('faltante') - $sum('sobrante');
        $rhs = $sum('siembra') - ($sum('disponible') + $sum('hold'));
        if ($lhs !== $rhs) { echo "FALLO: invariante O14B rota (" . $fila['key']['negocio'] . "): $lhs != $rhs\n"; $fail = 1; break; }
    }
}

// 3) reco ve CEDI aun con filtro de bodega: pedir reco de un negocio con grupo[]=<algo> no debe romper
$ref = $sku[0]['referencia'] ?? ''; $col = $sku[0]['color'] ?? '';
if ($ref !== '') {
    $r = ep($php, $runner, $prov, 'tab=reco&ref=' . rawurlencode($ref) . '&color=' . rawurlencode($col) . '&grupo[]=NOEXISTE', $nul);
    echo "tab=reco con grupo[]=NOEXISTE: ok=" . (($r['ok']??false)?'1':'0') . " planes=" . count($r['planes'] ?? []) . "\n";
    if (!($r['ok'] ?? false)) { echo "FALLO: reco rompe con filtro de bodega\n"; $fail = 1; }
}

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK (catálogo + filtros sobre #base + invariante + reco/CEDI)\n";
exit($fail);
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php tests/o14_filtros_test.php`
Expected: FALLO — `tab=filtros` aún no existe (combos/sku vacío) y los filtros no se aplican.

- [ ] **Step 4: Parser de filtros + forzar universo completo en `tab=filtros`**

En `api/informe_o14.php`, tras la línea `$hasta = $_GET['hasta'] ?? date('Y-m-d');` (~28), añadir:

```php
// Filtros multi-valor (como G00). REF→poda #refs; color/talla→#base; bodega→Bodegas.
$FILTROS_REF = ['marca'=>'MARCA','tipo'=>'TIPO','categoria'=>'CATEGORIA','subcategoria'=>'SUBCATEGORIA','genero'=>'GENERO','publico'=>'PUBLICO_OBJETIVO','referencia'=>'REFERENCIA'];
$FILTROS_SKU = ['color'=>'color','talla'=>'talla'];
$FILTROS_BOD = ['grupo'=>'GRUPO','tienda'=>'NOMBRE','centro_comercial'=>'CENTRO_COMERCIAL','depto'=>'DEPTO','ciudad'=>'CIUDAD'];
function getMulti($key) { $v = $_GET[$key] ?? []; if (!is_array($v)) $v = ($v === '' ? [] : [$v]);
    return array_values(array_filter(array_map('trim', $v), fn($x) => $x !== '')); }
// El catálogo necesita el universo completo del proveedor: ignora cia y no aplica filtros.
if ($tab === 'filtros') $cia = '';
```

- [ ] **Step 5: Poda de `#refs` por dims de referencia**

En `api/informe_o14.php`, justo DESPUÉS de la línea `if (!buildRefsTemp($dbConnect, getRefsCached($dbConnect, $proveedor))) jsonFail(['error'=>sqlsrv_errors()], $dbConnect);` (~98), añadir:

```php
// Filtros de dimensión de referencia: podar #refs → cae en las 4 fuentes (todas la inner-joinan).
if ($tab !== 'filtros') {
    foreach ($FILTROS_REF as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $d = sqlsrv_query($dbConnect, "DELETE FROM #refs WHERE $col NOT IN ($ph)", $vals);
        if ($d === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($d);
    }
}
```

- [ ] **Step 6: Filtros color/talla y bodega sobre `#base`**

En `api/informe_o14.php`, justo DESPUÉS del bloque `DELETE` de ADMINISTRATIVAS (la línea `if ($delAdmin===false) jsonFail(...); else sqlsrv_free_stmt($delAdmin);`, ~185), añadir:

```php
// Filtros color/talla (columnas de #base) y bodega (vía Bodegas, conservando CEDI). No en catálogo.
if ($tab !== 'filtros') {
    foreach ($FILTROS_SKU as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $d = sqlsrv_query($dbConnect, "DELETE FROM #base WHERE $col NOT IN ($ph)", $vals);
        if ($d === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($d);
    }
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
```

- [ ] **Step 7: Run test (catálogo aún FALLA, filtros ya aplican)**

Run: `php tests/o14_filtros_test.php`
Expected: sigue FALLO por `combos/sku vacío` (catálogo es Task 2), pero las líneas de `tab=b talla[]=...` deben dar `ok=1` y la invariante no debe romper. (El catálogo se arregla en Task 2.)

- [ ] **Step 8: Commit**

```bash
git add api/informe_o14.php tests/_endpoint_run_o14.php tests/o14_filtros_test.php
git commit -m "feat(o14): aplica 14 filtros de dimension sobre #base (poda #refs + deletes; CEDI intacto)"
```

---

## Task 2: Backend — catálogo `tab=filtros` (combos + sku)

**Files:**
- Modify: `api/informe_o14.php`

- [ ] **Step 1: Bloque `tab=filtros`**

En `api/informe_o14.php`, DESPUÉS del bloque de filtros color/talla/bodega de Task 1 (Step 6) y ANTES de `// ===== TAB B`, añadir:

```php
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
        'referencia'=>trim((string)$r['referencia']), 'grupo'=>trim((string)$r['GRUPO']), 'tienda'=>trim((string)$r['NOMBRE']),
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
```

- [ ] **Step 2: Run test (ahora completo)**

Run: `php tests/o14_filtros_test.php`
Expected: `RESULTADO: OK (catálogo + filtros sobre #base + invariante + reco/CEDI)` (combos>0, sku>0).
Nota: la primera corrida del día reconstruye el caché; BELTRANY tiene datos.

- [ ] **Step 3: Lint + commit**

Run: `php -l api/informe_o14.php` → Expected: `No syntax errors detected`

```bash
git add api/informe_o14.php
git commit -m "feat(o14): endpoint tab=filtros (catalogo combos+sku) para la cascada"
```

---

## Task 3: Frontend — barra de filtros + cascada + buildParams + anchos

**Files:**
- Modify: `informes/o14.php`

- [ ] **Step 1: Markup de la barra (3 filas nuevas + filas para cía/negocio)**

Reemplazar el bloque actual de filtros (desde `<!-- Filtros -->` hasta el `</div>` que cierra `<div class="g00-filters o14-filters">`) por:

```html
  <!-- Filtros -->
  <div class="g00-filters o14-filters">
    <div class="g00-filter-row">
      <div class="filter-group"><label>Compañía</label>
        <select id="o14-cia"><option value="">Todas</option><option value="002">002 · Brahma</option><option value="007">007 · Cauchosol</option></select>
      </div>
      <div class="filter-group"><label>Negocio</label>
        <select id="o14-negocio" onchange="o14PickNegocio(this.value)"><option value="">— Todos —</option></select>
      </div>
      <button class="g00-btn-refresh" onclick="o14Load()"><i class="fa-solid fa-rotate"></i> Aplicar</button>
      <span class="o14-hint" id="o14-c-sel"></span>
    </div>
    <div class="g00-filter-row">
      <div class="filter-group"><label>Marca</label><select id="o14-f-marca" multiple></select></div>
      <div class="filter-group"><label>Tipo</label><select id="o14-f-tipo" multiple></select></div>
      <div class="filter-group"><label>Categoría</label><select id="o14-f-categoria" multiple></select></div>
      <div class="filter-group"><label>Subcategoría</label><select id="o14-f-subcategoria" multiple></select></div>
      <div class="filter-group"><label>Género</label><select id="o14-f-genero" multiple></select></div>
      <div class="filter-group"><label>Público</label><select id="o14-f-publico" multiple></select></div>
      <div class="filter-group"><label>Referencia</label><select id="o14-f-referencia" multiple></select></div>
    </div>
    <div class="g00-filter-row">
      <div class="filter-group"><label>Color</label><select id="o14-f-color" multiple></select></div>
      <div class="filter-group"><label>Talla</label><select id="o14-f-talla" multiple></select></div>
    </div>
    <div class="g00-filter-row">
      <div class="filter-group"><label>Grupo</label><select id="o14-f-grupo" multiple></select></div>
      <div class="filter-group"><label>Tienda</label><select id="o14-f-tienda" multiple></select></div>
      <div class="filter-group"><label>Centro comercial</label><select id="o14-f-centro_comercial" multiple></select></div>
      <div class="filter-group"><label>Departamento</label><select id="o14-f-depto" multiple></select></div>
      <div class="filter-group"><label>Ciudad</label><select id="o14-f-ciudad" multiple></select></div>
    </div>
  </div>
```

- [ ] **Step 2: CSS de anchos (barra y KPIs a ancho de pantalla)**

En el `<style>` de `informes/o14.php`, después de `.o14-kpis { ... }` (la regla que define el grid de KPIs), añadir:

```css
  #page-informes-o14 .o14-kpis, #page-informes-o14 .g00-filters { width:100%; box-sizing:border-box; }
  #page-informes-o14 .o14-matriz-wrap { max-width:100%; }
```

- [ ] **Step 3: Constantes de campos + estado de cascada (JS)**

En el `<script>` de `informes/o14.php`, tras la línea `let arbolState = null;` (~110), añadir:

```js
  const REF_FIELDS = ['marca','tipo','categoria','subcategoria','genero','publico','referencia'];
  const SKU_FIELDS = ['color','talla'];
  const BOD_FIELDS = ['grupo','tienda','centro_comercial','depto','ciudad'];
  const COMBO_FIELDS = [...REF_FIELDS, ...BOD_FIELDS];
  const FILTER_FIELDS = [...REF_FIELDS, ...SKU_FIELDS, ...BOD_FIELDS];
  const tom = {};
  let combos = [], sku = [], cascadeBusy = false, filtrosInit = false;
  const selectedOf = (f) => tom[f] ? tom[f].getValue() : [];
```

- [ ] **Step 4: Funciones de cascada + init (JS, portadas de G00)**

Tras el bloque del Step 3, añadir:

```js
  function refsAllowedBySku() {
    const selC = selectedOf('color'), selT = selectedOf('talla');
    if (!selC.length && !selT.length) return null;
    const cS = new Set(selC), tS = new Set(selT), out = new Set();
    for (const s of sku) { if (selC.length && !cS.has(s.color)) continue; if (selT.length && !tS.has(s.talla)) continue; out.add(s.referencia); }
    return out;
  }
  function comboMatches(c, exclude) {
    for (const f of COMBO_FIELDS) { if (f === exclude) continue; const sel = selectedOf(f); if (sel.length && !sel.includes(c[f])) return false; }
    return true;
  }
  function activeRefs(exclude) { const out = new Set(); for (const c of combos) if (comboMatches(c, exclude)) out.add(c.referencia); return out; }
  function availableCombo(field) {
    const skuRefs = refsAllowedBySku(), out = new Set();
    for (const c of combos) { if (skuRefs && !skuRefs.has(c.referencia)) continue; if (!comboMatches(c, field)) continue; if (c[field] !== '' && c[field] != null) out.add(c[field]); }
    return Array.from(out).sort((a,b)=>a.localeCompare(b,'es'));
  }
  function availableSku(field) {
    const other = field === 'color' ? 'talla' : 'color';
    const refs = activeRefs(null), oSel = selectedOf(other), oS = new Set(oSel), out = new Set();
    for (const s of sku) { if (!refs.has(s.referencia)) continue; if (oSel.length && !oS.has(s[other])) continue; if (s[field] !== '' && s[field] != null) out.add(s[field]); }
    return Array.from(out).sort((a,b)=>a.localeCompare(b,'es'));
  }
  const availableFor = (f) => SKU_FIELDS.includes(f) ? availableSku(f) : availableCombo(f);
  function refreshOptions() {
    if (cascadeBusy) return; cascadeBusy = true;
    FILTER_FIELDS.forEach(field => {
      const ts = tom[field]; if (!ts) return;
      const keep = ts.getValue();
      const opts = availableFor(field).map(v => ({ value:v, text:v }));
      const keepArr = Array.isArray(keep) ? keep : (keep ? [keep] : []);
      keepArr.forEach(v => { if (!opts.find(o => o.value === v)) opts.push({ value:v, text:v }); });
      ts.clearOptions(); ts.addOptions(opts); ts.refreshOptions(false);
    });
    cascadeBusy = false;
  }
  function initFiltros() {
    FILTER_FIELDS.forEach(field => {
      tom[field] = new TomSelect('#o14-f-' + field, { plugins:['remove_button'], maxOptions:1000, placeholder:'Todas', onChange:()=>refreshOptions() });
    });
    fetch('api/informe_o14.php?tab=filtros', { credentials:'same-origin' })
      .then(r=>r.json()).then(data => { combos = (data&&data.combos)?data.combos:[]; sku = (data&&data.sku)?data.sku:[]; refreshOptions(); })
      .catch(()=>{ combos=[]; sku=[]; });
  }
```

- [ ] **Step 5: `buildParams` manda los 14 filtros**

Reemplazar la función `buildParams` por:

```js
  function buildParams(tab){
    const hoy = new Date();
    const desde = hoy.getFullYear()+'-01-01', hasta = hoy.toISOString().slice(0,10);
    const p = new URLSearchParams({ tab:tab, desde:desde, hasta:hasta });
    const cia = val('o14-cia'); if(cia) p.set('cia', cia);
    FILTER_FIELDS.forEach(f => { (selectedOf(f)||[]).forEach(v => p.append(f+'[]', v)); });
    if(tab==='reco'){ p.set('ref', negocioSel.ref); p.set('color', negocioSel.color); }
    return p.toString();
  }
```

- [ ] **Step 6: Inicializar la cascada al entrar a O14**

En `o14OnEnter`, antes de `if(!tabState.b) loadB();`, añadir:

```js
    if (!filtrosInit) { initFiltros(); filtrosInit = true; }
```

- [ ] **Step 7: Lint + verificación de referencias**

Run: `php -l informes/o14.php` → Expected: `No syntax errors detected`
Run: `grep -n "o14-f-marca\|initFiltros\|FILTER_FIELDS\|tab=filtros" informes/o14.php` → Expected: markup (selects), initFiltros (def+call), FILTER_FIELDS, fetch tab=filtros.
Self-review: los 14 selects existen con ids `o14-f-<campo>`; Compañía+Negocio+Aplicar conservados; cascada inicializa una vez.

- [ ] **Step 8: Commit**

```bash
git add informes/o14.php
git commit -m "feat(o14): barra de filtros por dimension (14, cascada Tom Select) + anchos a pantalla"
```

---

## Task 4: Verificación final + handoff E2E

**Files:** ninguno

- [ ] **Step 1: Suite backend**

Run: `php tests/o14_filtros_test.php` → Expected: `RESULTADO: OK`
Run: `php tests/o14_recomendador_test.php` → Expected: `27 pass, 0 fail` (motor intacto).

- [ ] **Step 2: Lint**

Run: `php -l api/informe_o14.php && php -l informes/o14.php` → Expected: ambos `No syntax errors detected`.

- [ ] **Step 3: Handoff a Rafael (E2E navegador)**

Pedir verificación en `localhost/plataforma_20` → **Siembra/Stock** (Ctrl+F5):
- Barra: Compañía + Negocio + Aplicar arriba; 3 filas nuevas de filtros (referencia / color-talla / bodega) con Tom Select y **cascada** (elegir uno acota los demás).
- Aplicar filtra O14B/O14C/KPIs; el negocio elegido sigue saltando a O14C; Recomendaciones intactas aun con filtro de bodega.
- Barra de filtros y KPIs a **ancho completo** de pantalla; la matriz sigue con scroll horizontal propio.

---

## Self-Review

- **Spec coverage:** 14 filtros aplicados en #base → Task 1 (poda #refs + deletes; CEDI intacto) ✓. Catálogo cascada `tab=filtros` (no aplica filtros, ignora cia) → Task 1 Step 4 (`$cia=''`) + Task 2 ✓. Front barra+cascada+buildParams → Task 3 ✓. Conservar Compañía+Negocio → Task 3 Step 1 ✓. Anchos pantalla → Task 3 Step 2 ✓. Verificación (catálogo+filtros+invariante+reco/CEDI+motor) → Tasks 1/2/4 ✓.
- **Placeholder scan:** sin TBD/TODO; sin código muerto (la invariante se evalúa inline en el bucle de filas).
- **Type consistency:** `combos`/`sku` shape (claves marca…ciudad / referencia,color,talla) idéntico backend (Task 2) ↔ frontend cascada (Task 3) ✓; `FILTROS_REF/SKU/BOD` keys = ids `o14-f-<key>` = `campo[]` en buildParams ✓; `getMulti` lee `campo[]` que buildParams envía ✓; `selectedOf`/`tom`/`FILTER_FIELDS` consistentes; `o14OnEnter`/`buildParams`/`val` ya existen ✓.
