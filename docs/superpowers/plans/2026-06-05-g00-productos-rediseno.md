# G00 — Rediseño pestaña "Ventas Por Productos" — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el contenido de la pestaña "Ventas Por Productos" (treemap + Pareto + Top 20) por 3 tablas árbol colapsables (Negocio→Talla, Categoría→Subcategoría, Género→Público) con las 16 columnas de "Por Grupo".

**Architecture:** Backend reescribe `tab=productos` en `api/informe_g00.php` con 3 queries `GROUPING SETS ((),(padre),(padre,hijo))` sobre `ventas ⋈ #refs`, comparación YoY como la consolidada. Frontend reemplaza el panel por 3 cards y un renderer genérico DRY. Spec: `docs/superpowers/specs/2026-06-05-g00-productos-rediseno.md`.

**Tech Stack:** PHP + sqlsrv (SQL Server), JS vanilla en `informes/g00.php`. Tests = arnés CLI que subprocesa el endpoint real (`tests/_endpoint_run.php`).

**Rama:** `feat/g00-tiendas-resumen` (activa).

---

## File Structure

- `api/informe_g00.php` — (1) mover construcción de same-store a scope compartido (antes del dispatch de tabs); (2) reescribir el bloque `if ($tab === 'productos')`; (3) nueva función `ensamblarArbolProd()`.
- `informes/g00.php` — markup del panel `g00-panel-productos`; CSS `.g00-scroll-neg`; JS: quitar `renderTreemapProductos`/`renderPareto`/`renderTablaProductos` y claves `charts.treemapProd`/`charts.pareto`; reescribir `loadProductos`; añadir `renderTablaArbol`/`rowArbol`/`g00ToggleArbol`.
- `tests/g00_productos_test.php` — **nuevo**.

Helpers JS reutilizados (no se crean): `prom`, `pctCell`, `difCell`, `fmtInt`, `fmtMoneyFull`, `esc`. CSS reutilizado: `.g00-total`, `.g00-tipo`, `.g00-marca-row`, `.g00-collapsed`, `.g00-caret`, `.pos`, `.neg`.

---

## Task 1: Backend — same-store a scope compartido

**Files:**
- Modify: `api/informe_g00.php` (insertar ~tras línea 115; eliminar bloque local en el Detal ~529-542)

El bloque `tab=productos` (línea ~453) necesita `$sameStoreClause`/`$sameStoreParams`, hoy construidos dentro del Detal (~531, después de productos). Se mueven a antes del dispatch para compartirlos. Regresión: el smoke del Detal debe seguir verde.

- [ ] **Step 1: Insertar la construcción compartida tras el loop de `$filtroExtra`**

En `api/informe_g00.php`, justo después de la línea `}` que cierra el `foreach ($FILTROS_MULTI ...)` (la línea 115, antes de `function run(`), insertar:

```php

// S.S.S (same-store): SOLO se aplica cuando ?sss=same. Compartido por todos los tabs que
// comparan año actual vs anterior (Detal consolidado, Mensual, Productos). Por defecto
// (No Same) NO filtra → todas las tiendas activas en el periodo. El EXISTS lee `v.BODEGA`,
// así que vive dentro de cualquier CTE que seleccione de `ventas v`.
$sameStoreClause = '';
$sameStoreParams = [];
if ($sss === 'same') {
    $sameStoreClause = "
          AND EXISTS (
                SELECT 1 FROM INTEGRACION.dbo.Bodegas sb WITH (NOLOCK)
                WHERE sb.COD = v.BODEGA AND sb.CIA = 7
                  AND (sb.FECHA_APERTURA IS NULL OR sb.FECHA_APERTURA <= ?)
                  AND (sb.FECHA_CIERRE   IS NULL OR sb.FECHA_CIERRE   >= ?)
          )";
    $sameStoreParams = [$desdeAnt, $hastaAct];
}
```

- [ ] **Step 2: Eliminar la construcción duplicada del bloque Detal**

En el bloque consolidado del Detal, eliminar el bloque local (ahora redundante). Reemplazar EXACTAMENTE este texto:

```php
// S.S.S (same-store): SOLO se aplica cuando ?sss=same. Por defecto (No Same) NO se
// filtra → se muestran todas las tiendas activas en el periodo.
$sameStoreClause = '';
$sameStoreParams = [];
if ($sss === 'same') {
    $sameStoreClause = "
          AND EXISTS (
                SELECT 1 FROM INTEGRACION.dbo.Bodegas sb WITH (NOLOCK)
                WHERE sb.COD = v.BODEGA AND sb.CIA = 7
                  AND (sb.FECHA_APERTURA IS NULL OR sb.FECHA_APERTURA <= ?)
                  AND (sb.FECHA_CIERRE   IS NULL OR sb.FECHA_CIERRE   >= ?)
          )";
    $sameStoreParams = [$desdeAnt, $hastaAct];
}
```

por:

```php
// S.S.S (same-store): $sameStoreClause / $sameStoreParams se construyen arriba (scope compartido).
```

- [ ] **Step 3: Lint + regresión del Detal**

Run: `php -l api/informe_g00.php` → Expected: `No syntax errors detected`
Run: `php tests/g00_detal_smoke_test.php` → Expected: `RESULTADO: OK` (10 permutaciones `ok=1`, incl. `sss=same`).

- [ ] **Step 4: Commit**

```bash
git add api/informe_g00.php
git commit -m "refactor(g00): same-store clause a scope compartido (lo usará Productos)"
```

---

## Task 2: Backend — reescribir `tab=productos` (3 árboles) + test

**Files:**
- Test: `tests/g00_productos_test.php` (create)
- Modify: `api/informe_g00.php` (función `ensamblarArbolProd` nueva + bloque `if ($tab === 'productos')` reescrito, ~453-520)

- [ ] **Step 1: Write the failing test**

Create `tests/g00_productos_test.php`:

```php
<?php
// Verifica tab=productos: 3 árboles (negocios/categorias/generos) con rows+total,
// invariante padre = Σ hijos en $/uds, y total.tiendas_act <= Σ padres.tiendas_act.
//   php tests/g00_productos_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';

function call_ep($php, $runner, $prov, $qs, $nul) {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    return json_decode(($a !== false && $b !== false) ? substr($raw, $a, $b - $a + 1) : $raw, true);
}

$fail = 0;
$d = call_ep($php, $runner, $prov, 'tab=productos', $nul);
$ok = $d['ok'] ?? false;
echo "Proveedor: $prov | ok=" . ($ok ? '1' : '0') . "\n";
if (!$ok) { echo "FALLO: " . json_encode($d['detalle'] ?? '(no JSON)') . "\nRESULTADO: FALLO\n"; exit(1); }

foreach (['negocios', 'categorias', 'generos'] as $k) {
    $blk = $d[$k] ?? null;
    if (!$blk || !isset($blk['rows'], $blk['total'])) { echo "FALLO: falta $k.rows/total\n"; $fail = 1; continue; }
    $rows = $blk['rows']; $tot = $blk['total'];
    $sumPadres = 0;
    foreach ($rows as $p) {
        $sv = 0; $su = 0;
        foreach (($p['children'] ?? []) as $c) { $sv += $c['val_act']; $su += $c['ups_act']; }
        if (!empty($p['children'])) {
            if (abs($sv - $p['val_act']) > 1)   { echo "FALLO: $k '" . $p['label'] . "' val_act != Σhijos ($sv vs " . $p['val_act'] . ")\n"; $fail = 1; }
            if (abs($su - $p['ups_act']) > 0.5) { echo "FALLO: $k '" . $p['label'] . "' ups_act != Σhijos\n"; $fail = 1; }
        }
        $sumPadres += (int)$p['tiendas_act'];
    }
    if ($sumPadres > 0 && (int)$tot['tiendas_act'] > $sumPadres) { echo "FALLO: $k total.tiendas_act > Σpadres ($tot[tiendas_act] > $sumPadres)\n"; $fail = 1; }
    echo "$k: padres=" . count($rows) . " | total \$=" . number_format($tot['val_act']) . " Tdas=" . $tot['tiendas_act'] . " (Σpadres=$sumPadres)\n";
}

$first = $d['negocios']['rows'][0]['label'] ?? '';
if ($first !== '' && strpos($first, '-') === false) { echo "FALLO: negocio sin formato REF-COLOR ($first)\n"; $fail = 1; }

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK (productos: 3 arboles, invariante padre=Σhijos, Tdas total<=Σ)\n";
exit($fail);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/g00_productos_test.php`
Expected: FALLO — el endpoint actual devuelve `refs`/`treemap`, no `negocios`/`categorias`/`generos`.

- [ ] **Step 3: Añadir la función `ensamblarArbolProd`**

En `api/informe_g00.php`, después de la función `jsonFail` (~línea 131, antes del bloque que define `getRefsCached`), insertar:

```php
/**
 * Ensambla filas de un GROUPING SETS de 2 niveles en {rows:[{label,...,children:[]}], total}.
 * $gidTotal = gid de la fila grand-total (); $gidPadre = gid de la fila padre (hijo NULL).
 * $padreKey($r)→etiqueta del padre; $hijoLabel($r)→etiqueta del hijo.
 * $childSort: 'talla' (ascendente natural) | 'val' (val_act desc).
 */
function ensamblarArbolProd($rows, $gidTotal, $gidPadre, $padreKey, $hijoLabel, $childSort) {
    $met = function ($r) {
        return [
            'val_act' => (float)$r['val_act'], 'val_ant' => (float)$r['val_ant'],
            'ups_act' => (float)$r['ups_act'], 'ups_ant' => (float)$r['ups_ant'],
            'margen'  => (float)($r['margen_prom'] ?? 0),
            'tiendas_act' => (int)$r['tiendas_act'], 'tiendas_ant' => (int)$r['tiendas_ant'],
        ];
    };
    $total = ['val_act'=>0,'val_ant'=>0,'ups_act'=>0,'ups_ant'=>0,'margen'=>0,'tiendas_act'=>0,'tiendas_ant'=>0];
    $map  = [];   // key → fila padre
    $kids = [];   // key → [hijos]
    foreach ($rows as $r) {
        $gid = (int)$r['gid'];
        if ($gid === $gidTotal) { $total = $met($r); continue; }
        $key = $padreKey($r);
        if ($gid === $gidPadre) {
            $map[$key] = array_merge(['label' => $key], $met($r));
        } else {
            $kids[$key][] = array_merge(['label' => $hijoLabel($r)], $met($r));
        }
    }
    $out = [];
    foreach ($map as $key => $fila) {
        $ch = $kids[$key] ?? [];
        if ($childSort === 'talla') {
            usort($ch, function ($x, $y) {
                $nx = is_numeric($x['label']) ? (float)$x['label'] : null;
                $ny = is_numeric($y['label']) ? (float)$y['label'] : null;
                if ($nx !== null && $ny !== null) return $nx <=> $ny;
                return strcmp((string)$x['label'], (string)$y['label']);
            });
        } else {
            usort($ch, fn($x, $y) => $y['val_act'] <=> $x['val_act']);
        }
        $fila['children'] = $ch;
        $out[] = $fila;
    }
    usort($out, fn($x, $y) => $y['val_act'] <=> $x['val_act']);
    return ['rows' => $out, 'total' => $total];
}
```

- [ ] **Step 4: Reescribir el bloque `if ($tab === 'productos')`**

Reemplazar TODO el bloque actual (desde `if ($tab === 'productos') {` hasta su `}` de cierre, ~líneas 453-520) por:

```php
if ($tab === 'productos') {
    // 3 tablas árbol: Negocio(REF-COLOR)→Talla, Categoría→Subcategoría, Género→Público.
    // Cada una: GROUPING SETS ((),(padre),(padre,hijo)) con comparación YoY (act vs ant).
    $prodMetrics = "
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant,
        AVG(CASE WHEN FECHA BETWEEN ? AND ? AND CANTIDAD > 0 THEN CAST(MARGEN AS float) END) AS margen_prom,
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? THEN BODEGA END) AS tiendas_act,
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? THEN BODEGA END) AS tiendas_ant";

    $prodCte = cteVentas() . "
        , ventas_enriq AS (
            SELECT v.FECHA, v.BODEGA, v.CANTIDAD, v.VALOR, v.MARGEN,
                   v.REFERENCIA, ISNULL(v.COLOR,'') AS COLOR, ISNULL(v.TALLA,'') AS TALLA,
                   ISNULL(i.CATEGORIA,'')        AS CATEGORIA,
                   ISNULL(i.SUBCATEGORIA,'')     AS SUBCATEGORIA,
                   ISNULL(i.GENERO,'')           AS GENERO,
                   ISNULL(i.PUBLICO_OBJETIVO,'') AS PUBLICO
            FROM ventas v
            INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
            LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.BODEGA AND b.CIA = 7
            WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
              $filtroExtra
              $sameStoreClause
        )
    ";

    // Params idénticos para las 3 queries (solo cambian nombres de columna, no marcadores).
    $pProd = array_merge(
        [$gmin, $gmax, $gmin, $gmax],                       // cteVentas pushdown (PBI + Acum)
        [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt],       // ventas_enriq WHERE OR-filter
        $paramsExtra,
        $sameStoreParams,
        [$desdeAct,$hastaAct, $desdeAnt,$hastaAnt,          // val_act / val_ant
         $desdeAct,$hastaAct, $desdeAnt,$hastaAnt,          // ups_act / ups_ant
         $desdeAct,$hastaAct,                                // margen_prom
         $desdeAct,$hastaAct, $desdeAnt,$hastaAnt]          // tiendas_act / tiendas_ant
    );

    $sqlNeg = $prodCte . "
        SELECT GROUPING_ID(REFERENCIA, COLOR, TALLA) AS gid,
               REFERENCIA AS ref, COLOR AS color, TALLA AS talla,
               $prodMetrics
        FROM ventas_enriq
        GROUP BY GROUPING SETS ( (), (REFERENCIA, COLOR), (REFERENCIA, COLOR, TALLA) )
    ";
    $sqlCat = $prodCte . "
        SELECT GROUPING_ID(CATEGORIA, SUBCATEGORIA) AS gid,
               CATEGORIA AS cat, SUBCATEGORIA AS sub,
               $prodMetrics
        FROM ventas_enriq
        GROUP BY GROUPING SETS ( (), (CATEGORIA), (CATEGORIA, SUBCATEGORIA) )
    ";
    $sqlGen = $prodCte . "
        SELECT GROUPING_ID(GENERO, PUBLICO) AS gid,
               GENERO AS gen, PUBLICO AS pub,
               $prodMetrics
        FROM ventas_enriq
        GROUP BY GROUPING SETS ( (), (GENERO), (GENERO, PUBLICO) )
    ";

    $rNeg = run($dbConnect, $sqlNeg, $pProd);
    $rCat = run($dbConnect, $sqlCat, $pProd);
    $rGen = run($dbConnect, $sqlGen, $pProd);
    foreach ([$rNeg, $rCat, $rGen] as $rr) if (isset($rr['error'])) jsonFail($rr, $dbConnect);

    $nz = fn($s) => ($s === '') ? '(Sin dato)' : $s;
    $negKey   = fn($r) => $nz(trim(trim((string)$r['ref']) . '-' . trim((string)$r['color']), '-'));
    $negChild = fn($r) => $nz(trim((string)$r['talla']));
    $catKey   = fn($r) => $nz(trim((string)$r['cat']));
    $catChild = fn($r) => $nz(trim((string)$r['sub']));
    $genKey   = fn($r) => $nz(trim((string)$r['gen']));
    $genChild = fn($r) => $nz(trim((string)$r['pub']));

    // GROUPING_ID: negocio sobre 3 cols (REF,COLOR,TALLA) → ()=7, (REF,COLOR)=1, full=0.
    //              cat/gen sobre 2 cols → ()=3, (padre)=1, full=0.
    $negocios   = ensamblarArbolProd($rNeg, 7, 1, $negKey, $negChild, 'talla');
    $categorias = ensamblarArbolProd($rCat, 3, 1, $catKey, $catChild, 'val');
    $generos    = ensamblarArbolProd($rGen, 3, 1, $genKey, $genChild, 'val');

    sqlsrv_close($dbConnect);
    echo json_encode([
        'ok'   => true,
        'anio' => (int)date('Y', strtotime($hastaAct)),
        'negocios'   => $negocios,
        'categorias' => $categorias,
        'generos'    => $generos,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
```

- [ ] **Step 5: Verificar el mapeo de `GROUPING_ID` con una query de prueba**

Antes de confiar en los gid (7/1/0 y 3/1/0), confirmar contra la BD. Run:

```bash
php tests/g00_productos_test.php
```

Si el test pasa, el mapeo es correcto (las invariantes padre=Σhijos y Tdas total≤Σ solo cuadran si los gid se rutean bien). Si FALLA con sumas en cero o totales raros, los gid están invertidos: añadir temporalmente `echo` de los `gid` distintos que devuelve cada query (`SELECT DISTINCT gid ...`) y ajustar los argumentos `$gidTotal`/`$gidPadre`.

Expected (BELTRANY SAS): `RESULTADO: OK`, con `negocios`/`categorias`/`generos` mostrando padres>0 y `Tdas` ≤ Σpadres.

- [ ] **Step 6: Lint + test**

Run: `php -l api/informe_g00.php` → Expected: `No syntax errors detected`
Run: `php tests/g00_productos_test.php` → Expected: `RESULTADO: OK (...)`

- [ ] **Step 7: Commit**

```bash
git add api/informe_g00.php tests/g00_productos_test.php
git commit -m "feat(g00): tab=productos reescrito - 3 arboles (negocio/categoria/genero) con YoY"
```

---

## Task 3: Frontend — panel Productos (3 tablas árbol)

**Files:**
- Modify: `informes/g00.php` — markup panel (~311-336), CSS (~144), charts obj (~486-490), `loadProductos` (~787-800), quitar `renderTreemapProductos`/`renderPareto`/`renderTablaProductos` (~802-881), añadir renderers.

- [ ] **Step 1: Reemplazar el markup del panel Productos**

Reemplazar TODO el bloque del panel (desde `<!-- ============ TAB VENTAS POR PRODUCTOS ============ -->` hasta el `</div>` que cierra `id="g00-panel-productos"`, ~líneas 310-336) por:

```html
    <!-- ============ TAB VENTAS POR PRODUCTOS ============ -->
    <div class="g00-tab-panel" id="g00-panel-productos">
        <div class="card">
            <div class="card-title">Resumen Ventas Por Negocio <span style="color:var(--text-light);font-weight:500;">&mdash; clic en el negocio (Ref-Color) para ver tallas</span></div>
            <div class="g00-scroll-neg">
                <table id="g00-tabla-negocio" class="disp-table"></table>
            </div>
        </div>
        <div class="card">
            <div class="card-title">Resumen Ventas Por Categoría <span style="color:var(--text-light);font-weight:500;">&mdash; clic para ver subcategoría</span></div>
            <div style="overflow-x:auto;">
                <table id="g00-tabla-categoria" class="disp-table"></table>
            </div>
        </div>
        <div class="card">
            <div class="card-title">Resumen Ventas Por Género <span style="color:var(--text-light);font-weight:500;">&mdash; clic para ver público objetivo</span></div>
            <div style="overflow-x:auto;">
                <table id="g00-tabla-genero" class="disp-table"></table>
            </div>
        </div>
    </div>
```

- [ ] **Step 2: Añadir CSS `.g00-scroll-neg` (scroll + thead sticky)**

Tras la línea `table.disp-table .neg { color: var(--danger); }` (~144), añadir:

```css
    /* Tabla de Negocio: scroll interno (~30 filas visibles) con cabecera fija */
    .g00-scroll-neg { max-height: 720px; overflow-y: auto; }
    .g00-scroll-neg table.disp-table thead th { position: sticky; top: 0; background: #fff; z-index: 1; box-shadow: inset 0 -1px 0 var(--border); }
```

- [ ] **Step 3: Quitar las claves de charts de productos**

Reemplazar:

```js
    const charts = {
        treemapTiendas: null,
        calendar: null, dow: null, tendencia: null,
        treemapProd: null, pareto: null,
    };
```

por:

```js
    const charts = {
        treemapTiendas: null,
        calendar: null, dow: null, tendencia: null,
    };
```

- [ ] **Step 4: Reescribir `loadProductos`**

Reemplazar la función `loadProductos` completa (~787-800) por:

```js
    function loadProductos() {
        showLoading('Cargando productos');
        fetch('api/informe_g00.php?' + buildParams('productos'), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { hideLoading(); showError(data.error || 'Error cargando productos'); return; }
                renderTablaArbol('g00-tabla-negocio',   data.negocios,   data.anio, {col1:'Negocio / Talla',           prefix:'neg'});
                renderTablaArbol('g00-tabla-categoria', data.categorias, data.anio, {col1:'Categoría / Subcategoría',  prefix:'cat'});
                renderTablaArbol('g00-tabla-genero',    data.generos,    data.anio, {col1:'Género / Público objetivo', prefix:'gen'});
                tabState.productos = true;
                hideLoading();
            })
            .catch(err => { hideLoading(); showError('No se pudo cargar productos: ' + err.message); });
    }
```

- [ ] **Step 5: Eliminar `renderTreemapProductos`, `renderPareto`, `renderTablaProductos`**

Borrar las tres funciones completas (`renderTreemapProductos` ~802-824, `renderPareto` ~826-866, `renderTablaProductos` ~868-881). NO borrar `esc` (viene justo después, línea ~883) ni los helpers `difCell`/`pctCell`/`prom`. Tras borrar, confirmar que no quedan referencias:

Run: `grep -n "renderTreemapProductos\|renderPareto\|renderTablaProductos\|treemapProd\|charts.pareto\|g00-chart-pareto\|g00-chart-treemap" informes/g00.php`
Expected: sin resultados.

- [ ] **Step 6: Añadir el renderer genérico + toggle**

Inmediatamente después de la función `rowMensual` (~línea 1021, fin del bloque `}`), añadir:

```js
    // ===== Tablas árbol de la pestaña Productos (Negocio/Categoría/Género) =====
    // data = {rows:[{label,...,children:[]}], total:{...}}; opts={col1, prefix}. 16 cols (= Por Grupo).
    function renderTablaArbol(tbodyId, data, anio, opts) {
        const a = anio, b = anio - 1;
        const rows = (data && data.rows) || [];
        const total = (data && data.total) || null;
        let h = '<thead><tr>'
            + '<th>'+esc(opts.col1)+'</th>'
            + '<th class="num">'+b+'</th><th class="num">'+a+'</th><th class="num">Dif Q</th><th class="num">%Q</th>'
            + '<th class="num">$'+b+'</th><th class="num">$'+a+'</th><th class="num">Dif $</th><th class="num">%$</th>'
            + '<th class="num">MB</th>'
            + '<th class="num">$Prom '+b+'</th><th class="num">$Prom '+a+'</th><th class="num">%Prom</th>'
            + '<th class="num">Tdas '+b+'</th><th class="num">Tdas '+a+'</th><th class="num">≠Tdas</th>'
            + '</tr></thead><tbody>';
        if (!rows.length) {
            h += '<tr><td colspan="16" style="text-align:center;color:var(--text-light);padding:20px;">Sin datos</td></tr></tbody>';
            document.getElementById(tbodyId).innerHTML = h; return;
        }
        const tot = {ua:0,ub:0,va:0,vb:0};
        rows.forEach((p, i) => {
            tot.ua+=p.ups_act; tot.ub+=p.ups_ant; tot.va+=p.val_act; tot.vb+=p.val_ant;
            const kids = p.children || [];
            h += rowArbol(p, opts, kids.length ? 'parent' : 'leaf', {idx:i});
            kids.forEach(c => { h += rowArbol(c, opts, 'child', {parent:i}); });
        });
        const totRow = total
            ? {label:'Total', ups_act:total.ups_act, ups_ant:total.ups_ant, val_act:total.val_act, val_ant:total.val_ant, margen:total.margen, tiendas_act:total.tiendas_act, tiendas_ant:total.tiendas_ant}
            : {label:'Total', ups_act:tot.ua, ups_ant:tot.ub, val_act:tot.va, val_ant:tot.vb, margen:0, tiendas_act:0, tiendas_ant:0};
        h += rowArbol(totRow, opts, 'total', {});
        h += '</tbody>';
        document.getElementById(tbodyId).innerHTML = h;
    }
    function rowArbol(r, opts, kind, meta) {
        meta = meta || {};
        const pa = prom(r.val_act, r.ups_act), pb = prom(r.val_ant, r.ups_ant);
        const difT = (r.tiendas_act||0) - (r.tiendas_ant||0);
        const cls = kind === 'total' ? 'g00-total' : (kind === 'child' ? 'g00-tipo' : '');
        let trOpen, labelCell;
        if (kind === 'child') {
            trOpen = '<tr class="'+cls+'" data-'+opts.prefix+'parent="'+meta.parent+'" style="display:none">';
            labelCell = '<td>'+esc(r.label)+'</td>';
        } else if (kind === 'parent') {
            trOpen = '<tr class="'+cls+' g00-marca-row g00-collapsed" onclick="g00ToggleArbol(\''+opts.prefix+'\','+meta.idx+',this)">';
            labelCell = '<td><span class="g00-caret">▸</span>'+esc(r.label)+'</td>';
        } else {   // leaf (padre sin hijos) o total
            trOpen = '<tr class="'+cls+'">';
            labelCell = '<td>'+esc(r.label)+'</td>';
        }
        return trOpen
            + labelCell
            + '<td class="num">'+fmtInt(r.ups_ant)+'</td><td class="num">'+fmtInt(r.ups_act)+'</td>'
            + difCell(r.ups_act, r.ups_ant, fmtInt) + pctCell(r.ups_act, r.ups_ant)
            + '<td class="num">'+fmtMoneyFull(r.val_ant)+'</td><td class="num">'+fmtMoneyFull(r.val_act)+'</td>'
            + difCell(r.val_act, r.val_ant, fmtMoneyFull) + pctCell(r.val_act, r.val_ant)
            + '<td class="num">'+(r.margen?r.margen.toFixed(2)+'%':'—')+'</td>'
            + '<td class="num">'+fmtMoneyFull(pb)+'</td><td class="num">'+fmtMoneyFull(pa)+'</td>'
            + pctCell(pa, pb)
            + '<td class="num">'+fmtInt(r.tiendas_ant)+'</td><td class="num">'+fmtInt(r.tiendas_act)+'</td>'
            + '<td class="num '+(difT>=0?'pos':'neg')+'">'+(difT>=0?'+':'')+fmtInt(difT)+'</td>'
            + '</tr>';
    }
    window.g00ToggleArbol = function (prefix, idx, el) {
        const collapsed = el.classList.toggle('g00-collapsed');
        document.querySelectorAll('tr[data-'+prefix+'parent="'+idx+'"]')
            .forEach(r => { r.style.display = collapsed ? 'none' : ''; });
        const caret = el.querySelector('.g00-caret');
        if (caret) caret.textContent = collapsed ? '▸' : '▾';
    };
```

- [ ] **Step 7: Lint + verificación de referencias**

Run: `php -l informes/g00.php` → Expected: `No syntax errors detected`
Run: `grep -n "renderTreemapProductos\|renderPareto\|renderTablaProductos\|treemapProd\|charts.pareto" informes/g00.php` → Expected: sin resultados.
Self-review: `<th>` = 16 y `rowArbol` emite 16 `<td>` (label + 4 Q + 4 $ + MB + 2 $Prom + %Prom + 2 Tdas + ≠Tdas).

- [ ] **Step 8: Commit**

```bash
git add informes/g00.php
git commit -m "feat(g00): pestana Productos - 3 tablas arbol (Negocio/Categoria/Genero) + scroll"
```

---

## Task 4: Verificación final + handoff E2E

**Files:** ninguno

- [ ] **Step 1: Suite backend verde**

Run: `php tests/g00_productos_test.php` → Expected: `RESULTADO: OK`
Run: `php tests/g00_detal_smoke_test.php` → Expected: `RESULTADO: OK` (regresión Detal tras mover same-store).
Run: `php tests/g00_mensual_test.php` → Expected: `RESULTADO: OK` (sin afectar).

- [ ] **Step 2: Lint global**

Run: `php -l api/informe_g00.php && php -l informes/g00.php` → Expected: ambos `No syntax errors detected`.

- [ ] **Step 3: Handoff a Rafael (E2E navegador)**

Pedir verificación en `localhost/plataforma_20` (login proveedor → Ventas → tab **Ventas Por Productos**, Ctrl+F5):
- 3 tablas: **Por Negocio** (Ref-Color → Talla, con scroll ~30 visibles + cabecera fija), **Por Categoría** (→ Subcategoría), **Por Género** (→ Público).
- Colapso/expansión por clic; tallas en **orden ascendente**.
- 16 columnas como Por Grupo (Tdas, %Prom, MB) y fila Total con Tdas distinct real.
- Reacciona a la barra de filtros (marca/fecha/calendario/S.S.S, etc.).
- Ya NO aparece el treemap, el Pareto ni el Top 20.

---

## Self-Review

- **Spec coverage:** 3 tablas árbol con 16 cols → Tasks 2 (backend) + 3 (frontend) ✓. Negocio→Talla (asc), Categoría→Subcat, Género→Público → Task 2 (`ensamblarArbolProd` + dims) ✓. YoY + filtros + S.S.S → Task 1 (same-store compartido) + Task 2 (params YoY) ✓. Total Tdas distinct real → Task 2 (fila `()`/`total`) + Task 3 (totRow usa `data.total`) ✓. Scroll Negocio ~30 + thead sticky → Task 3 Step 2 ✓. Reemplazar treemap/pareto/top20 → Task 3 Steps 1,3,5 ✓. Test invariantes → Task 2 ✓. Regresión + E2E → Task 4 ✓.
- **Placeholder scan:** sin TBD/TODO; todo el código mostrado completo.
- **Type consistency:** payload `{negocios,categorias,generos}` cada uno `{rows:[{label,val_act,val_ant,ups_act,ups_ant,margen,tiendas_act,tiendas_ant,children}],total:{...}}` (Task 2) consumido idéntico por `renderTablaArbol`/`rowArbol` (Task 3) ✓; `margen_prom` (SQL) → `margen` (ensamblador) → `r.margen` (render) ✓; `data-<prefix>parent` escrito por `rowArbol` y leído por `g00ToggleArbol` con el mismo `prefix` ✓; gids 7/1/0 (negocio) y 3/1/0 (cat/gen) coherentes con `GROUPING_ID` de 3 vs 2 columnas ✓; helpers `prom/difCell/pctCell/fmtInt/fmtMoneyFull/esc` ya existen ✓.
