# G00 Inc 2B — Barra en subsecciones + Color/Talla + filtros de bodega — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorganizar la barra de filtros del informe G00 en 4 filas (división solo visual), pasar las fechas a mes/día (año fijo), y agregar 5 filtros nuevos: Color y Talla (vía la vista maestro de SKU) y Grupo, Tienda, Centro comercial (de Bodegas).

**Architecture:** Los 5 filtros nuevos entran por el mecanismo existente `$FILTROS_MULTI`/`$paramsExtra` del backend (sin reordenar parámetros). Color/Talla se filtran sobre las columnas COLOR/TALLA que ya traen las tablas de hechos (se exponen en `cteVentas`), y su catálogo/cascada sale de `INTEGRACION.dbo.Maestro_Ref_Plataforma_AKA`. El front arma la cascada con dos fuentes: `combos` (ref+bodega) y `sku` (ref→color/talla), ligadas por REFERENCIA.

**Tech Stack:** PHP 8 + sqlsrv (SQL Server/RDS), Tom Select 2.3.1 (CDN), JS vanilla. Tests: harness PHP CLI que subprocesa el endpoint real.

**Spec:** `docs/superpowers/specs/2026-06-04-g00-inc2b-filtros-subsecciones.md`

---

## File Structure

- **`api/informe_g00.php`** (modify): `cteVentas()` expone COLOR/TALLA; `$FILTROS_MULTI` suma 5 claves; bloque `?tab=filtros` suma campos de bodega a `combos` y un arreglo `sku`.
- **`informes/g00.php`** (modify): markup de la barra (4 filas, fechas mes/día, 5 selects nuevos), CSS de filas, y JS (clusters de cascada, `sku`, `buildParams`, init).
- **`tests/g00_inc2b_catalog_test.php`** (create): verifica que `?tab=filtros` trae los campos de bodega en `combos` y el arreglo `sku`.
- **`tests/g00_detal_smoke_test.php`** (modify): agrega permutaciones con los 5 filtros nuevos.

---

## Task 1: Backend — catálogo `?tab=filtros` con campos de bodega + arreglo `sku`

**Files:**
- Modify: `api/informe_g00.php` (bloque `if ($tab === 'filtros')`, ~líneas 238-279)
- Test: `tests/g00_inc2b_catalog_test.php` (create)

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/g00_inc2b_catalog_test.php`:

```php
<?php
// Verifica el catálogo Inc 2B: combos con campos de bodega (grupo/tienda/centro_comercial)
// y arreglo sku (referencia,color,talla) desde Maestro_Ref_Plataforma_AKA.
//   php tests/g00_inc2b_catalog_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
$cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
     . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg('tab=filtros') . ' 2>' . $nul;
$raw = (string) shell_exec($cmd);
$a = strpos($raw, '{'); $b = strrpos($raw, '}');
$d = json_decode(($a !== false && $b !== false) ? substr($raw, $a, $b - $a + 1) : $raw, true);

$fail = 0;
$combos = $d['combos'] ?? [];
$sku    = $d['sku'] ?? null;
echo "Proveedor: $prov | combos=" . count($combos) . " | sku=" . (is_array($sku) ? count($sku) : 'AUSENTE') . "\n";

if (!$combos) { echo "FALLO: combos vacío\n"; $fail = 1; }
foreach (['grupo','tienda','centro_comercial'] as $k) {
    if (!array_key_exists($k, $combos[0] ?? [])) { echo "FALLO: combos sin clave '$k'\n"; $fail = 1; }
}
if (!is_array($sku) || !$sku) { echo "FALLO: sku ausente o vacío\n"; $fail = 1; }
else foreach (['referencia','color','talla'] as $k) {
    if (!array_key_exists($k, $sku[0])) { echo "FALLO: sku sin clave '$k'\n"; $fail = 1; }
}
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK (catálogo Inc 2B completo)\n";
exit($fail);
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php tests/g00_inc2b_catalog_test.php`
Expected: FALLO — `combos sin clave 'grupo'` y `sku ausente` (el endpoint aún no los devuelve).

- [ ] **Step 3: Agregar los campos de bodega al SELECT de `combos`**

En `api/informe_g00.php`, dentro de `if ($tab === 'filtros')`, en el `$sql` de combos (~líneas 247-260), cambiar el SELECT para incluir GRUPO/NOMBRE/CENTRO_COMERCIAL:

```php
    $sql = "
        SELECT DISTINCT
            i.MARCA, i.TIPO, i.CATEGORIA, i.SUBCATEGORIA, i.GENERO, i.PUBLICO_OBJETIVO,
            i.REFERENCIA,
            ISNULL(b.GRUPO,'')            AS GRUPO,
            ISNULL(b.NOMBRE,'')           AS NOMBRE,
            ISNULL(b.CENTRO_COMERCIAL,'') AS CENTRO_COMERCIAL,
            ISNULL(b.DEPTO,'')  AS DEPTO,
            ISNULL(b.CIUDAD,'') AS CIUDAD
        FROM (
            SELECT DISTINCT REFERENCIA, BODEGA FROM INTEGRACION.dbo.Ventas_Detal_PBI      WITH (NOLOCK)
            UNION
            SELECT DISTINCT REFERENCIA, BODEGA FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK)
        ) v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.BODEGA AND b.CIA = 7
    ";
```

- [ ] **Step 4: Agregar las claves de bodega a cada combo del mapeo**

En el `array_map` que arma `$combos` (~líneas 263-273), agregar las 3 claves:

```php
    $combos = array_map(fn($r) => [
        'marca'            => trim((string)$r['MARCA']),
        'tipo'             => trim((string)$r['TIPO']),
        'categoria'        => trim((string)$r['CATEGORIA']),
        'subcategoria'     => trim((string)$r['SUBCATEGORIA']),
        'genero'           => trim((string)$r['GENERO']),
        'publico'          => trim((string)$r['PUBLICO_OBJETIVO']),
        'referencia'       => trim((string)$r['REFERENCIA']),
        'grupo'            => trim((string)$r['GRUPO']),
        'tienda'           => trim((string)$r['NOMBRE']),
        'centro_comercial' => trim((string)$r['CENTRO_COMERCIAL']),
        'depto'            => trim((string)$r['DEPTO']),
        'ciudad'           => trim((string)$r['CIUDAD']),
    ], $rows);
```

- [ ] **Step 5: Agregar la query `sku` y meterla al payload**

Justo después de construir `$combos` y antes de `$payload = [...]`, agregar la consulta al maestro y cambiar el payload:

```php
    // SKU del proveedor (referencia → color/talla) desde el maestro. Liviano (no escanea hechos).
    $sqlSku = "
        SELECT DISTINCT m.REFERENCIA, m.COLOR, m.TALLA
        FROM INTEGRACION.dbo.Maestro_Ref_Plataforma_AKA m WITH (NOLOCK)
        INNER JOIN #refs i ON i.REFERENCIA = m.REFERENCIA
    ";
    $rowsSku = run($dbConnect, $sqlSku);
    if (isset($rowsSku['error'])) jsonFail($rowsSku, $dbConnect);
    $sku = array_map(fn($r) => [
        'referencia' => trim((string)$r['REFERENCIA']),
        'color'      => trim((string)$r['COLOR']),
        'talla'      => trim((string)$r['TALLA']),
    ], $rowsSku);

    $payload = ['ok' => true, 'combos' => $combos, 'sku' => $sku];
```

(La línea `$payload = ['ok' => true, 'combos' => $combos];` existente se reemplaza por la de arriba. El resto del bloque —cache + echo— no cambia.)

- [ ] **Step 6: Correr el test y verificar que pasa**

Run: `php tests/g00_inc2b_catalog_test.php`
Expected: PASS — `RESULTADO: OK (catálogo Inc 2B completo)`, con `combos>0` y `sku>0` (para BELTRANY ~813 combos y ~1285 sku).

- [ ] **Step 7: Lint + commit**

```bash
php -l api/informe_g00.php
git add api/informe_g00.php tests/g00_inc2b_catalog_test.php
git commit -m "feat(g00): catalogo Inc2B - combos con grupo/tienda/cc + arreglo sku (color/talla)"
```

---

## Task 2: Backend — exponer COLOR/TALLA en `cteVentas` y sumar los 5 filtros a `$FILTROS_MULTI`

**Files:**
- Modify: `api/informe_g00.php` (`cteVentas()` ~líneas 70-82; `$FILTROS_MULTI` ~líneas 86-96)
- Test: `tests/g00_detal_smoke_test.php` (modify)

- [ ] **Step 1: Extender el smoke test (debe fallar al aplicar color/talla)**

En `tests/g00_detal_smoke_test.php`, después de derivar `$marca` del catálogo, derivar también un color, talla, grupo, tienda y centro_comercial reales y agregar permutaciones. Reemplazar el bloque que arma `$perms` por:

```php
// Deriva valores reales del catálogo para cubrir los filtros nuevos.
$color = $talla = $grupo = $tienda = $cc = '';
foreach (($cat['sku'] ?? []) as $s) {
    if ($color === '' && trim($s['color'] ?? '') !== '') $color = $s['color'];
    if ($talla === '' && trim($s['talla'] ?? '') !== '') $talla = $s['talla'];
    if ($color !== '' && $talla !== '') break;
}
foreach (($cat['combos'] ?? []) as $c) {
    if ($grupo  === '' && trim($c['grupo'] ?? '') !== '')            $grupo  = $c['grupo'];
    if ($tienda === '' && trim($c['tienda'] ?? '') !== '')          $tienda = $c['tienda'];
    if ($cc     === '' && trim($c['centro_comercial'] ?? '') !== '') $cc     = $c['centro_comercial'];
    if ($grupo !== '' && $tienda !== '' && $cc !== '') break;
}

$perms = [
    'tab=detal',
    'tab=detal&cal=retail',
    'tab=detal&sss=same',
    'tab=detal&cal=retail&sss=same',
];
if ($marca !== '') $perms[] = 'tab=detal&cal=retail&sss=same&marca[]=' . rawurlencode($marca);
if ($color !== '') $perms[] = 'tab=detal&color[]=' . rawurlencode($color);
if ($talla !== '') $perms[] = 'tab=detal&talla[]=' . rawurlencode($talla);
if ($grupo !== '') $perms[] = 'tab=detal&grupo[]=' . rawurlencode($grupo);
if ($tienda !== '') $perms[] = 'tab=detal&tienda[]=' . rawurlencode($tienda);
if ($cc !== '') $perms[] = 'tab=detal&centro_comercial[]=' . rawurlencode($cc);
```

- [ ] **Step 2: Correr el smoke test y verificar que falla en los filtros nuevos**

Run: `php tests/g00_detal_smoke_test.php`
Expected: las permutaciones de `color[]`/`talla[]` dan `ok=0` con detalle `Invalid column name 'COLOR'` (el CTE aún no las expone). Las de grupo/tienda/cc podrían dar ok=1 (b.* ya existe) pero sin estar en `$FILTROS_MULTI` el filtro no se aplica → igual hay que agregarlas.

- [ ] **Step 3: Exponer COLOR/TALLA en `cteVentas()`**

Reemplazar `cteVentas()` (~líneas 70-82) por:

```php
function cteVentas() {
    return "
    WITH ventas AS (
        SELECT FECHA, BODEGA, REFERENCIA, CANTIDAD, VALOR, MARGEN, COLOR, TALLA
        FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK)
        WHERE FECHA BETWEEN ? AND ?
        UNION ALL
        SELECT FECHA, BODEGA, REFERENCIA, CANTIDAD, VALOR, MARGEN, COLOR, TALLA
        FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK)
        WHERE FECHA BETWEEN ? AND ?
    )
    ";
}
```

- [ ] **Step 4: Agregar las 5 claves a `$FILTROS_MULTI`**

Reemplazar el arreglo `$FILTROS_MULTI` (~líneas 86-96) por:

```php
$FILTROS_MULTI = [
    'marca'            => 'i.MARCA',
    'tipo'             => 'i.TIPO',
    'categoria'        => 'i.CATEGORIA',
    'subcategoria'     => 'i.SUBCATEGORIA',
    'genero'           => 'i.GENERO',
    'publico'          => 'i.PUBLICO_OBJETIVO',
    'referencia'       => 'i.REFERENCIA',
    'color'            => 'v.COLOR',
    'talla'            => 'v.TALLA',
    'grupo'            => 'b.GRUPO',
    'tienda'           => 'b.NOMBRE',
    'centro_comercial' => 'b.CENTRO_COMERCIAL',
    'depto'            => 'b.DEPTO',
    'ciudad'           => 'b.CIUDAD',
];
```

> Nota: el orden no afecta la alineación de params — el `foreach` recorre el mapa y agrega a `$filtroExtra` y `$paramsExtra` juntos. Los alias `v`/`i`/`b` están disponibles en los 5 tabs donde se inyecta `$filtroExtra`.

- [ ] **Step 5: Correr el smoke test y verificar que pasa**

Run: `php tests/g00_detal_smoke_test.php`
Expected: PASS — todas las permutaciones (incluidas color/talla/grupo/tienda/centro_comercial) dan `ok=1`. `RESULTADO: OK`.

- [ ] **Step 6: Lint + commit**

```bash
php -l api/informe_g00.php
git add api/informe_g00.php tests/g00_detal_smoke_test.php
git commit -m "feat(g00): filtros color/talla (v.COLOR/v.TALLA via cteVentas) + grupo/tienda/cc"
```

---

## Task 3: Frontend — markup de la barra en 4 filas, fechas mes/día y 5 selects nuevos

**Files:**
- Modify: `informes/g00.php` (markup `.g00-filters` ~líneas 159-190; CSS en el `<style>` del archivo)

- [ ] **Step 1: Reemplazar el markup de la barra por 4 filas**

Reemplazar el bloque `<!-- ============ FILTROS ============ -->` … `</div>` (~líneas 158-190) por:

```php
    <!-- ============ FILTROS ============ -->
    <div class="g00-filters">
        <!-- Fila 1: tiempo -->
        <div class="g00-filter-row">
            <div class="filter-group">
                <label>Desde</label>
                <div class="g00-md">
                    <select id="g00-desde-mes"><?php for($m=1;$m<=12;$m++) printf('<option value="%02d"%s>%02d</option>',$m,$m==1?' selected':'',$m); ?></select>
                    <select id="g00-desde-dia"><?php for($d=1;$d<=31;$d++) printf('<option value="%02d"%s>%02d</option>',$d,$d==1?' selected':'',$d); ?></select>
                </div>
            </div>
            <div class="filter-group">
                <label>Hasta</label>
                <div class="g00-md">
                    <select id="g00-hasta-mes"><?php $cm=(int)date('n'); for($m=1;$m<=12;$m++) printf('<option value="%02d"%s>%02d</option>',$m,$m==$cm?' selected':'',$m); ?></select>
                    <select id="g00-hasta-dia"><?php $cd=(int)date('j'); for($d=1;$d<=31;$d++) printf('<option value="%02d"%s>%02d</option>',$d,$d==$cd?' selected':'',$d); ?></select>
                </div>
            </div>
            <div class="filter-group">
                <label>Calendario</label>
                <div class="g00-seg" id="g00-cal">
                    <button type="button" class="g00-seg-btn active" data-val="diaadia">Día a Día</button>
                    <button type="button" class="g00-seg-btn" data-val="retail">Retail</button>
                </div>
            </div>
            <div class="filter-group">
                <label>S.S.S</label>
                <div class="g00-seg" id="g00-sss">
                    <button type="button" class="g00-seg-btn active" data-val="nosame">No Same</button>
                    <button type="button" class="g00-seg-btn" data-val="same">Same</button>
                </div>
            </div>
            <div style="margin-left:auto;align-self:flex-end;">
                <button class="g00-btn-refresh" onclick="g00Load()"><i class="fa-solid fa-filter"></i> Aplicar</button>
            </div>
        </div>
        <!-- Fila 2: criterios de referencia -->
        <div class="g00-filter-row">
            <div class="filter-group"><label>Marca</label><select id="g00-f-marca" multiple></select></div>
            <div class="filter-group"><label>Tipo</label><select id="g00-f-tipo" multiple></select></div>
            <div class="filter-group"><label>Categoría</label><select id="g00-f-categoria" multiple></select></div>
            <div class="filter-group"><label>Subcategoría</label><select id="g00-f-subcategoria" multiple></select></div>
            <div class="filter-group"><label>Género</label><select id="g00-f-genero" multiple></select></div>
            <div class="filter-group"><label>Público</label><select id="g00-f-publico" multiple></select></div>
            <div class="filter-group"><label>Referencia</label><select id="g00-f-referencia" multiple></select></div>
        </div>
        <!-- Fila 3: color / talla -->
        <div class="g00-filter-row">
            <div class="filter-group"><label>Color</label><select id="g00-f-color" multiple></select></div>
            <div class="filter-group"><label>Talla</label><select id="g00-f-talla" multiple></select></div>
        </div>
        <!-- Fila 4: criterios de bodega -->
        <div class="g00-filter-row">
            <div class="filter-group"><label>Grupo</label><select id="g00-f-grupo" multiple></select></div>
            <div class="filter-group"><label>Tienda</label><select id="g00-f-tienda" multiple></select></div>
            <div class="filter-group"><label>Centro comercial</label><select id="g00-f-centro_comercial" multiple></select></div>
            <div class="filter-group"><label>Departamento</label><select id="g00-f-depto" multiple></select></div>
            <div class="filter-group"><label>Ciudad</label><select id="g00-f-ciudad" multiple></select></div>
        </div>
    </div>
```

- [ ] **Step 2: Agregar CSS de filas y del control mes/día**

En el `<style>` de `informes/g00.php`, ajustar `.g00-filters` a columna y agregar las clases nuevas (poner junto a las reglas `.g00-filters`/`.filter-group` existentes):

```css
    .g00-filters { display:flex; flex-direction:column; gap:10px; }
    .g00-filter-row { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end;
        padding-bottom:10px; border-bottom:1px solid var(--border); }
    .g00-filter-row:last-child { border-bottom:none; padding-bottom:0; }
    .g00-md { display:flex; gap:4px; }
    .g00-md select { min-width:56px; }
```

(El borde inferior sutil entre filas es la "división visual"; sin subtítulos.)

- [ ] **Step 3: Verificar lint del PHP**

Run: `php -l informes/g00.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add informes/g00.php
git commit -m "feat(g00): barra de filtros en 4 filas + fechas mes/dia + selects color/talla/grupo/tienda/cc"
```

---

## Task 4: Frontend — cascada con dos clusters, `sku`, `buildParams` mes/día y nuevos campos

**Files:**
- Modify: `informes/g00.php` (JS: `FILTER_FIELDS` y cascada ~líneas 338-405; `buildParams` ~líneas 476-489)

- [ ] **Step 1: Reemplazar la definición de campos y agregar `sku`**

Reemplazar la línea `const FILTER_FIELDS = [...]` (~338) y la declaración `let combos = [];` (~340) por:

```js
    const REF_FIELDS    = ['marca','tipo','categoria','subcategoria','genero','publico','referencia'];
    const SKU_FIELDS    = ['color','talla'];
    const BODEGA_FIELDS = ['grupo','tienda','centro_comercial','depto','ciudad'];
    const FILTER_FIELDS = [...REF_FIELDS, ...SKU_FIELDS, ...BODEGA_FIELDS];
    const tom = {};            // field -> instancia TomSelect
    let combos = [];           // catálogo (ref + bodega) del proveedor
    let sku    = [];           // {referencia, color, talla} del maestro
    let cascadeBusy = false;   // evita recursión al actualizar opciones
```

(Eliminar la antigua `const tom = {};` y `let cascadeBusy = false;` duplicadas que venían tras `FILTER_FIELDS` — quedan incluidas arriba.)

- [ ] **Step 2: Reemplazar la lógica de cascada (`availableFor`) por la de dos clusters**

Reemplazar la función `availableFor` (~líneas 361-371) por estas funciones:

```js
    // referencias permitidas por las selecciones de color/talla (null = sin restricción)
    function refsAllowedBySku() {
        const selC = selectedOf('color'), selT = selectedOf('talla');
        if (!selC.length && !selT.length) return null;
        const cS = new Set(selC), tS = new Set(selT), out = new Set();
        for (const s of sku) {
            if (selC.length && !cS.has(s.color)) continue;
            if (selT.length && !tS.has(s.talla)) continue;
            out.add(s.referencia);
        }
        return out;
    }

    // ¿la fila combos cumple las selecciones de campos combos (ref+bodega) excepto 'exclude'?
    function comboMatches(c, exclude) {
        for (const f of [...REF_FIELDS, ...BODEGA_FIELDS]) {
            if (f === exclude) continue;
            const sel = selectedOf(f);
            if (sel.length && !sel.includes(c[f])) return false;
        }
        return true;
    }

    // referencias activas según selecciones combos (ref+bodega), ignorando 'exclude'
    function activeRefs(exclude) {
        const out = new Set();
        for (const c of combos) if (comboMatches(c, exclude)) out.add(c.referencia);
        return out;
    }

    // opciones para un campo combos (ref o bodega): bidireccional + acotado por color/talla
    function availableCombo(field) {
        const skuRefs = refsAllowedBySku();
        const out = new Set();
        for (const c of combos) {
            if (skuRefs && !skuRefs.has(c.referencia)) continue;
            if (!comboMatches(c, field)) continue;
            if (c[field] !== '' && c[field] != null) out.add(c[field]);
        }
        return Array.from(out).sort((a, b) => a.localeCompare(b, 'es'));
    }

    // opciones para color/talla: acotado por las refs activas (ref+bodega) y el otro sku-field
    function availableSku(field) {
        const other = field === 'color' ? 'talla' : 'color';
        const refs = activeRefs(null);
        const oSel = selectedOf(other), oS = new Set(oSel);
        const out = new Set();
        for (const s of sku) {
            if (!refs.has(s.referencia)) continue;
            if (oSel.length && !oS.has(s[other])) continue;
            if (s[field] !== '' && s[field] != null) out.add(s[field]);
        }
        return Array.from(out).sort((a, b) => a.localeCompare(b, 'es'));
    }

    function availableFor(field) {
        return SKU_FIELDS.includes(field) ? availableSku(field) : availableCombo(field);
    }
```

(`refreshOptions` no cambia: ya itera `FILTER_FIELDS` y llama `availableFor`.)

- [ ] **Step 3: Cargar `sku` en `initFiltros`**

En `initFiltros`, reemplazar el `fetch(...).then(...)` (~líneas 401-404) por:

```js
        fetch('api/informe_g00.php?tab=filtros', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                combos = (data && data.combos) ? data.combos : [];
                sku    = (data && data.sku)    ? data.sku    : [];
                refreshOptions();
            })
            .catch(() => { combos = []; sku = []; });
```

- [ ] **Step 4: Actualizar `buildParams` (fechas mes/día con año fijo + nuevos campos)**

Reemplazar `buildParams` (~líneas 476-489) por:

```js
    function dateVal(prefix) { // prefix = 'desde' | 'hasta'
        const m = document.getElementById('g00-' + prefix + '-mes').value;
        const d = document.getElementById('g00-' + prefix + '-dia').value;
        if (!m || !d) return '';
        return new Date().getFullYear() + '-' + m + '-' + d;
    }

    function buildParams(tab) {
        const p = new URLSearchParams();
        if (tab) p.append('tab', tab);
        const d = dateVal('desde'), h = dateVal('hasta');
        if (d) p.append('desde', d);
        if (h) p.append('hasta', h);
        FILTER_FIELDS.forEach(field => {
            (selectedOf(field) || []).forEach(v => p.append(field + '[]', v));
        });
        p.append('cal', segValue('g00-cal') || 'diaadia');
        p.append('sss', segValue('g00-sss') || 'nosame');
        return p.toString();
    }
```

- [ ] **Step 5: Verificar lint + smoke test backend siguen OK**

Run: `php -l informes/g00.php && php tests/g00_detal_smoke_test.php`
Expected: sin errores de sintaxis y `RESULTADO: OK` (el backend no cambió en esta tarea; es una verificación de no-regresión).

- [ ] **Step 6: Commit**

```bash
git add informes/g00.php
git commit -m "feat(g00): cascada en 2 clusters (combos+sku), color/talla por referencia, buildParams mes/dia"
```

---

## Task 5: Verificación integral + handoff a navegador

**Files:** ninguno (solo verificación)

- [ ] **Step 1: Correr ambos tests backend**

Run: `php tests/g00_inc2b_catalog_test.php && php tests/g00_detal_smoke_test.php`
Expected: ambos `RESULTADO: OK`.

- [ ] **Step 2: Verificación en navegador (Rafael)**

Checklist en `localhost/plataforma_20` (login proveedor, informe Ventas, **Ctrl+F5**):
- La barra muestra **4 filas** separadas por un borde sutil, **sin subtítulos**: (1) Desde m/d · Hasta m/d · Calendario · S.S.S · Aplicar; (2) Marca…Referencia; (3) Color · Talla; (4) Grupo · Tienda · Centro comercial · Departamento · Ciudad.
- Las fechas son **dos selects (mes, día)**; el año no aparece. Default Desde 01/01, Hasta hoy.
- La cascada funciona en ambos clusters; elegir Marca acota Color/Talla y Grupo/Tienda; elegir Color acota Marca/Referencia.
- Aplicar con filtros de Color/Talla/Grupo/Tienda/Centro comercial reduce los datos y NO sale "Consulta Fallida".
- Export CSV sigue funcionando.

- [ ] **Step 3: Decisión de merge/PR de `feat/g00-detal-replica-pbi`** (Inc 1 + 2A + fix + 2B).

---

## Self-Review (hecho)

- **Cobertura del spec:** layout 4 filas (Task 3) ✓; fechas mes/día (Task 3 markup + Task 4 buildParams) ✓; bodega grupo/tienda/cc (Task 1 catálogo + Task 2 filtro) ✓; color/talla (Task 1 sku + Task 2 cteVentas/$FILTROS_MULTI + Task 4 cascada) ✓; cascada 2 clusters (Task 4) ✓; tests (Task 1, 2) ✓.
- **Sin placeholders:** todos los pasos traen código/ comando concretos.
- **Consistencia de nombres:** claves `centro_comercial` (mapa, id `g00-f-centro_comercial`, `BODEGA_FIELDS`, `buildParams`) coinciden; `sku`/`combos` coinciden front↔back; `color`/`talla` → `v.COLOR`/`v.TALLA` con `cteVentas` exponiéndolas.
