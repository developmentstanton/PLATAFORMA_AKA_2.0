# G00 — Exportar a Excel (.xlsx) en las 8 tablas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Botón "⤓ Excel" en cada una de las 8 tablas del G00 que descarga un `.xlsx` nativo con valores numéricos, incluyendo todas las filas (padres + hijos + Total).

**Architecture:** Solo frontend. SheetJS por CDN genera el `.xlsx` en cliente desde los datos crudos del último fetch de cada pestaña (stasheados en `lastDetal/lastTiendas/lastPeriodos/lastProductos`). Builders puros (`comparativaAOA`, `mensualAOA`, `periodosAOA`) arman arrays-de-arrays (AOA) con números; un helper `exportAOA` los vuelca a archivo. Spec: `docs/superpowers/specs/2026-06-05-g00-export-excel.md`.

**Tech Stack:** SheetJS (xlsx) por CDN; JS vanilla + CSS en `informes/g00.php`; `<script>` CDN en `dashboard.php`. Sin backend. Sin test automatizado (DOM/descarga): verificación `php -l` + E2E.

**Rama:** `feat/g00-tiendas-resumen`.

---

## File Structure

- `dashboard.php` — 1 línea: `<script>` CDN de SheetJS en el `<head>`.
- `informes/g00.php` — (1) declarar `lastDetal/lastTiendas/lastPeriodos/lastProductos`; (2) stashearlos en los 4 `load*`; (3) CSS `.g00-btn-export`; (4) botón en los 8 `card-title`; (5) helpers + 3 builders + 8 funciones `g00Exp*`.

No hay test unitario (es DOM + descarga de archivo; no hay arnés JS en el proyecto). Verificación E2E.

---

## Task 1: Fundación — SheetJS + stash + helpers + CSS

**Files:**
- Modify: `dashboard.php` (head, ~línea 33)
- Modify: `informes/g00.php` (estado ~354; los 4 `load*`; CSS ~tras `#g00-img-pop img`; helpers tras el bloque hover ~1073)

- [ ] **Step 1: CDN de SheetJS en `dashboard.php`**

Después de la línea `<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>` (línea 33), añadir:

```html
    <script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
```

- [ ] **Step 2: Declarar las variables de stash**

En `informes/g00.php`, junto a las otras variables de estado del módulo (cerca de `let combos = [];`, ~línea 354), añadir:

```js
    let lastDetal = null, lastTiendas = null, lastPeriodos = null, lastProductos = null;
```

- [ ] **Step 3: Stashear el data en cada `load*`**

En `loadDetal`, tras la línea `if (!data.ok) { hideLoading(); showError(data.error || 'Error cargando datos'); return; }`, añadir:

```js
                lastDetal = data;
```

En `loadTiendas`, tras `if (!data.ok) { hideLoading(); showError(data.error || 'Error cargando tiendas'); return; }`, añadir:

```js
                lastTiendas = data;
```

En `loadPeriodos`, tras `if (!data.ok) { hideLoading(); showError(data.error || 'Error cargando periodos'); return; }`, añadir:

```js
                lastPeriodos = data;
```

En `loadProductos`, tras `if (!data.ok) { hideLoading(); showError(data.error || 'Error cargando productos'); return; }`, añadir:

```js
                lastProductos = data;
```

- [ ] **Step 4: CSS del botón**

En el `<style>`, después de la regla `#g00-img-pop img { ... }`, añadir:

```css
    /* Botón exportar a Excel (encabezado de cada tabla) */
    .g00-btn-export { float: right; font-family: 'Space Grotesk', sans-serif; font-size: 11px;
        border: 1px solid var(--border); background: #fff; color: var(--primary); cursor: pointer;
        border-radius: 6px; padding: 3px 9px; font-weight: 600; line-height: 1.4; }
    .g00-btn-export:hover { background: var(--primary); color: #fff; }
```

- [ ] **Step 5: Helpers de exportación**

Inmediatamente después del cierre del bloque hover `})();` (la IIFE `initNegocioImgHover`, ~línea 1073, justo antes de `// ============ DISPATCHER ============`), insertar:

```js
    // ===== Exportar a Excel (.xlsx) con SheetJS =====
    function expFilename(tabla) {
        const prov = (proveedorActual || window.PROVEEDOR_ACTUAL || '').replace(/[^A-Za-z0-9]+/g, '_').replace(/^_|_$/g, '');
        const fecha = new Date().toISOString().slice(0, 10);
        return 'G00_' + tabla + (prov ? '_' + prov : '') + '_' + fecha + '.xlsx';
    }
    function exportAOA(filename, sheetName, header, aoaRows) {
        if (typeof XLSX === 'undefined') { Swal.fire('Exportar', 'No se pudo cargar la librería de Excel.', 'error'); return; }
        const ws = XLSX.utils.aoa_to_sheet([header, ...aoaRows]);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, sheetName.slice(0, 31));
        XLSX.writeFile(wb, filename);
    }
    const eDif  = (a, b) => (a || 0) - (b || 0);
    const ePct  = (a, b) => b ? ((a - b) / b) * 100 : '';
    const eProm = (v, u) => (u > 0 ? v / u : 0);
    const ePart = (x, d) => (d > 0 ? (x / d) * 100 : '');
```

- [ ] **Step 6: Lint + commit**

Run: `php -l dashboard.php && php -l informes/g00.php` → Expected: ambos `No syntax errors detected`.

```bash
git add dashboard.php informes/g00.php
git commit -m "feat(g00): fundacion export Excel - SheetJS CDN + stash data + helpers"
```

---

## Task 2: Builder comparativo + 6 tablas (Grupo, Marca/Tipo, Tienda, Negocio, Categoría, Género)

**Files:**
- Modify: `informes/g00.php` (helpers de export: añadir `comparativaAOA` + 6 funciones; markup: 6 botones en card-titles)

- [ ] **Step 1: Builder `comparativaAOA`**

Después de los helpers de Task 1 (tras la línea `const ePart = ...;`), añadir:

```js
    // Builder de las 6 tablas comparativas. rows: [{label,val_act,val_ant,ups_act,ups_ant,margen,tiendas_act,tiendas_ant,children?}].
    // opts: {anio, full (default true → 16 cols con %Prom+Tdas; false → 12 cols estilo Tienda), totalTdas:{act,ant}}.
    function comparativaAOA(dim, rows, opts) {
        const a = opts.anio, b = a - 1, full = opts.full !== false;
        const header = full
            ? [dim, b, a, 'Dif Q', '%Q', '$ ' + b, '$ ' + a, 'Dif $', '%$', 'MB', '$Prom ' + b, '$Prom ' + a, '%Prom', 'Tdas ' + b, 'Tdas ' + a, '≠Tdas']
            : [dim, b, a, 'Dif Q', '%Q', '$ ' + b, '$ ' + a, 'Dif $', '%$', 'MB', '$Prom ' + b, '$Prom ' + a];
        const line = (r, indent) => {
            const pa = eProm(r.val_act, r.ups_act), pb = eProm(r.val_ant, r.ups_ant);
            const base = [(indent ? '   ' : '') + (r.label || ''),
                r.ups_ant || 0, r.ups_act || 0, eDif(r.ups_act, r.ups_ant), ePct(r.ups_act, r.ups_ant),
                r.val_ant || 0, r.val_act || 0, eDif(r.val_act, r.val_ant), ePct(r.val_act, r.val_ant),
                r.margen || 0, pb, pa];
            if (full) base.push(ePct(pa, pb), r.tiendas_ant || 0, r.tiendas_act || 0, eDif(r.tiendas_act, r.tiendas_ant));
            return base;
        };
        const body = [];
        let sv = 0, sb = 0, su = 0, sub = 0; const margenes = [];
        (rows || []).forEach(r => {
            sv += r.val_act || 0; sb += r.val_ant || 0; su += r.ups_act || 0; sub += r.ups_ant || 0;
            if (r.margen > 0) margenes.push(r.margen);
            body.push(line(r, false));
            (r.children || []).forEach(c => body.push(line(c, true)));
        });
        const mbTot = margenes.length ? margenes.reduce((x, y) => x + y, 0) / margenes.length : 0;
        const tdas = opts.totalTdas || { act: 0, ant: 0 };
        body.push(line({ label: 'Total', val_act: sv, val_ant: sb, ups_act: su, ups_ant: sub,
            margen: mbTot, tiendas_act: tdas.act, tiendas_ant: tdas.ant }, false));
        return { header, body };
    }
    window.g00ExpGrupo = function () {
        if (!lastDetal) { Swal.fire('Exportar', 'Carga el dashboard primero.', 'info'); return; }
        const r = comparativaAOA('Grupo', lastDetal.por_grupo, { anio: lastDetal.anio, full: true,
            totalTdas: { act: lastDetal.kpis.tiendas_actual, ant: lastDetal.kpis.tiendas_anterior } });
        exportAOA(expFilename('PorGrupo'), 'Por Grupo', r.header, r.body);
    };
    window.g00ExpMarca = function () {
        if (!lastDetal) { Swal.fire('Exportar', 'Carga el dashboard primero.', 'info'); return; }
        const r = comparativaAOA('Marca / Tipo', lastDetal.por_marca, { anio: lastDetal.anio, full: true,
            totalTdas: { act: lastDetal.kpis.tiendas_actual, ant: lastDetal.kpis.tiendas_anterior } });
        exportAOA(expFilename('PorMarcaTipo'), 'Por Marca-Tipo', r.header, r.body);
    };
    window.g00ExpTienda = function () {
        if (!lastTiendas) { Swal.fire('Exportar', 'Carga la pestaña Tiendas primero.', 'info'); return; }
        const rows = (lastTiendas.tiendas || []).map(t => ({
            label: t.nombre || t.cod || '', val_act: t.val_act, val_ant: t.val_ant, ups_act: t.ups_act, ups_ant: t.ups_ant, margen: t.margen,
            children: (t.children || []).map(c => ({ label: c.negocio || '', val_act: c.val_act, val_ant: c.val_ant, ups_act: c.ups_act, ups_ant: c.ups_ant, margen: c.margen }))
        }));
        const r = comparativaAOA('Tienda / Negocio', rows, { anio: lastTiendas.anio, full: false });
        exportAOA(expFilename('PorTienda'), 'Por Tienda', r.header, r.body);
    };
    window.g00ExpNegocio = function () {
        if (!lastProductos) { Swal.fire('Exportar', 'Carga la pestaña Productos primero.', 'info'); return; }
        const n = lastProductos.negocios || { rows: [], total: {} };
        const r = comparativaAOA('Negocio / Talla', n.rows, { anio: lastProductos.anio, full: true,
            totalTdas: { act: n.total.tiendas_act, ant: n.total.tiendas_ant } });
        exportAOA(expFilename('PorNegocio'), 'Por Negocio', r.header, r.body);
    };
    window.g00ExpCategoria = function () {
        if (!lastProductos) { Swal.fire('Exportar', 'Carga la pestaña Productos primero.', 'info'); return; }
        const c = lastProductos.categorias || { rows: [], total: {} };
        const r = comparativaAOA('Categoría / Subcategoría', c.rows, { anio: lastProductos.anio, full: true,
            totalTdas: { act: c.total.tiendas_act, ant: c.total.tiendas_ant } });
        exportAOA(expFilename('PorCategoria'), 'Por Categoria', r.header, r.body);
    };
    window.g00ExpGenero = function () {
        if (!lastProductos) { Swal.fire('Exportar', 'Carga la pestaña Productos primero.', 'info'); return; }
        const g = lastProductos.generos || { rows: [], total: {} };
        const r = comparativaAOA('Género / Público', g.rows, { anio: lastProductos.anio, full: true,
            totalTdas: { act: g.total.tiendas_act, ant: g.total.tiendas_ant } });
        exportAOA(expFilename('PorGenero'), 'Por Genero', r.header, r.body);
    };
```

- [ ] **Step 2: Botones en los 6 card-titles**

Reemplazar cada `card-title` por su versión con botón:

`<div class="card-title">Resumen Ventas Por Grupo Tiendas</div>` →
```html
            <div class="card-title">Resumen Ventas Por Grupo Tiendas<button class="g00-btn-export" onclick="g00ExpGrupo()">⤓ Excel</button></div>
```

`<div class="card-title">Resumen Ventas Por Marca / Tipo</div>` →
```html
            <div class="card-title">Resumen Ventas Por Marca / Tipo<button class="g00-btn-export" onclick="g00ExpMarca()">⤓ Excel</button></div>
```

`<div class="card-title">Resumen Ventas Por Tienda <span style="color:var(--text-light);font-weight:500;">&mdash; clic en la tienda para ver Referencia-Color</span></div>` →
```html
            <div class="card-title">Resumen Ventas Por Tienda <span style="color:var(--text-light);font-weight:500;">&mdash; clic en la tienda para ver Referencia-Color</span><button class="g00-btn-export" onclick="g00ExpTienda()">⤓ Excel</button></div>
```

`<div class="card-title">Resumen Ventas Por Negocio <span style="color:var(--text-light);font-weight:500;">&mdash; clic en el negocio (Ref-Color) para ver tallas</span></div>` →
```html
            <div class="card-title">Resumen Ventas Por Negocio <span style="color:var(--text-light);font-weight:500;">&mdash; clic en el negocio (Ref-Color) para ver tallas</span><button class="g00-btn-export" onclick="g00ExpNegocio()">⤓ Excel</button></div>
```

`<div class="card-title">Resumen Ventas Por Categoría <span style="color:var(--text-light);font-weight:500;">&mdash; clic para ver subcategoría</span></div>` →
```html
            <div class="card-title">Resumen Ventas Por Categoría <span style="color:var(--text-light);font-weight:500;">&mdash; clic para ver subcategoría</span><button class="g00-btn-export" onclick="g00ExpCategoria()">⤓ Excel</button></div>
```

`<div class="card-title">Resumen Ventas Por Género <span style="color:var(--text-light);font-weight:500;">&mdash; clic para ver público objetivo</span></div>` →
```html
            <div class="card-title">Resumen Ventas Por Género <span style="color:var(--text-light);font-weight:500;">&mdash; clic para ver público objetivo</span><button class="g00-btn-export" onclick="g00ExpGenero()">⤓ Excel</button></div>
```

- [ ] **Step 3: Lint + commit**

Run: `php -l informes/g00.php` → Expected: `No syntax errors detected`.

```bash
git add informes/g00.php
git commit -m "feat(g00): export Excel en 6 tablas comparativas (Grupo/Marca/Tienda/Negocio/Categoria/Genero)"
```

---

## Task 3: Builders Mensual y Periodos + sus 2 botones

**Files:**
- Modify: `informes/g00.php` (añadir `mensualAOA`, `periodosAOA`, `g00ExpMensual`, `g00ExpPeriodos`; 2 botones)

- [ ] **Step 1: Builders + funciones**

Después de `window.g00ExpGenero = ...};` (fin de Task 2), añadir:

```js
    function mensualAOA(rows, tdas, anio) {
        const a = anio, b = a - 1;
        const header = ['Mes', b, a, 'Dif Q', '%Q', '$ ' + b, '$ ' + a, 'Dif $', '%$', '$Prom ' + b, '$Prom ' + a, 'Tdas ' + b, 'Tdas ' + a, '≠Tdas'];
        const body = []; let sv = 0, sb = 0, su = 0, sub = 0;
        (rows || []).forEach(r => {
            if (!r.val_act && !r.val_ant && !r.ups_act && !r.ups_ant) return;
            sv += r.val_act || 0; sb += r.val_ant || 0; su += r.ups_act || 0; sub += r.ups_ant || 0;
            const pa = eProm(r.val_act, r.ups_act), pb = eProm(r.val_ant, r.ups_ant);
            body.push([r.mes, r.ups_ant || 0, r.ups_act || 0, eDif(r.ups_act, r.ups_ant), ePct(r.ups_act, r.ups_ant),
                r.val_ant || 0, r.val_act || 0, eDif(r.val_act, r.val_ant), ePct(r.val_act, r.val_ant),
                pb, pa, r.tiendas_ant || 0, r.tiendas_act || 0, eDif(r.tiendas_act, r.tiendas_ant)]);
        });
        const pa = eProm(sv, su), pb = eProm(sb, sub);
        const tA = (tdas && tdas.act) || 0, tB = (tdas && tdas.ant) || 0;
        body.push(['Total', sub, su, eDif(su, sub), ePct(su, sub), sb, sv, eDif(sv, sb), ePct(sv, sb), pb, pa, tB, tA, eDif(tA, tB)]);
        return { header, body };
    }
    function periodosAOA(dias, anio) {
        const a = anio, b = a - 1;
        const header = ['Periodo', 'Cant ' + b, '%' + b, 'Cant ' + a, '%' + a, 'Var% Q', '$ ' + b, '%' + b, '$ ' + a, '%' + a, 'Var% $'];
        const arbol = buildArbolPeriodos(dias);   // {sems, tot}
        const T = arbol.tot;
        const meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        const row = (label, m, ind) => ['   '.repeat(ind) + label,
            m.ub, ePart(m.ub, T.ub), m.ua, ePart(m.ua, T.ua), ePct(m.ua, m.ub),
            m.vb, ePart(m.vb, T.vb), m.va, ePart(m.va, T.va), ePct(m.va, m.vb)];
        const body = [];
        Object.keys(arbol.sems).map(Number).sort((x, y) => x - y).forEach(s => {
            const S = arbol.sems[s];
            body.push(row('Semestre ' + s, S.m, 0));
            Object.keys(S.tris).map(Number).sort((x, y) => x - y).forEach(t => {
                const Tr = S.tris[t];
                body.push(row('Trimestre ' + t, Tr.m, 1));
                Object.keys(Tr.meses).map(Number).sort((x, y) => x - y).forEach(mn => {
                    const M = Tr.meses[mn];
                    body.push(row(meses[mn - 1], M.m, 2));
                    Object.keys(M.dias).map(Number).sort((x, y) => x - y).forEach(d => {
                        body.push(row(meses[mn - 1] + '-' + String(d).padStart(2, '0'), M.dias[d].m, 3));
                    });
                });
            });
        });
        body.push(row('Total', T, 0));
        return { header, body };
    }
    window.g00ExpMensual = function () {
        if (!lastDetal) { Swal.fire('Exportar', 'Carga el dashboard primero.', 'info'); return; }
        const r = mensualAOA(lastDetal.mensual, lastDetal.mensual_tdas, lastDetal.anio);
        exportAOA(expFilename('Mensual'), 'Mensual', r.header, r.body);
    };
    window.g00ExpPeriodos = function () {
        if (!lastPeriodos) { Swal.fire('Exportar', 'Carga la pestaña Periodos primero.', 'info'); return; }
        const r = periodosAOA(lastPeriodos.dias, lastPeriodos.anio);
        exportAOA(expFilename('Periodos'), 'Periodos', r.header, r.body);
    };
```

- [ ] **Step 2: Botones de Mensual y Periodos**

`<div class="card-title">Resumen Ventas Mensual</div>` →
```html
            <div class="card-title">Resumen Ventas Mensual<button class="g00-btn-export" onclick="g00ExpMensual()">⤓ Excel</button></div>
```

`<div class="card-title">Ventas Por Periodos <span style="color:var(--text-light);font-weight:500;">&mdash; clic para desglosar Semestre → Trimestre → Mes → Día</span></div>` →
```html
            <div class="card-title">Ventas Por Periodos <span style="color:var(--text-light);font-weight:500;">&mdash; clic para desglosar Semestre → Trimestre → Mes → Día</span><button class="g00-btn-export" onclick="g00ExpPeriodos()">⤓ Excel</button></div>
```

- [ ] **Step 3: Lint + commit**

Run: `php -l informes/g00.php` → Expected: `No syntax errors detected`.

```bash
git add informes/g00.php
git commit -m "feat(g00): export Excel en tablas Mensual y Periodos"
```

---

## Task 4: Verificación final + handoff E2E

**Files:** ninguno

- [ ] **Step 1: Lint + regresión backend**

Run: `php -l dashboard.php && php -l informes/g00.php` → Expected: ambos `No syntax errors detected`.
Run: `php tests/g00_productos_test.php` → Expected: `RESULTADO: OK` (payload sin cambios).

- [ ] **Step 2: Verificación de referencias**

Run: `grep -n "g00ExpGrupo\|g00ExpMarca\|g00ExpMensual\|g00ExpTienda\|g00ExpPeriodos\|g00ExpNegocio\|g00ExpCategoria\|g00ExpGenero" informes/g00.php`
Expected: cada nombre aparece exactamente 2 veces (definición `window.g00Exp* =` + `onclick` en el card-title). 8 nombres × 2 = 16 líneas.

- [ ] **Step 3: Handoff a Rafael (E2E navegador)**

Pedir verificación en `localhost/plataforma_20` (login → Ventas, Ctrl+F5), en las 4 pestañas:
- Cada tabla tiene el botón "⤓ Excel" a la derecha del título.
- Al hacer clic descarga un `.xlsx` que abre en Excel con: **números reales** (no texto), **todas** las filas (padres + hijos aunque estén colapsados) + fila Total, columnas iguales a la pantalla, nombre `G00_<Tabla>_<Proveedor>_<fecha>.xlsx`.
- En una pestaña no cargada aún, el botón avisa "Carga … primero" (Swal info).
- Mensual: meses completos + Total con Tdas. Periodos: 4 niveles con sangría + Total.

---

## Self-Review

- **Spec coverage:** botón en 8 tablas → Task 2 (6) + Task 3 (2) ✓. .xlsx numérico vía SheetJS → Task 1 (CDN+exportAOA) ✓. Datos crudos (stash) → Task 1 Step 3 ✓. Builders comparativa/mensual/periodos → Tasks 2/3 ✓. Incluir todo (padres+hijos+Total) → `comparativaAOA` itera children + Total; periodos 4 niveles ✓. Tdas Total distinct real (kpis / total / mensual_tdas) → Tasks 2/3 ✓. Tienda 12 cols (full:false) ✓. Nombre archivo + sanitizado proveedor + hoja ≤31 → Task 1/`exportAOA` ✓. No-data → Swal ✓. Verificación php -l + E2E → Task 4 ✓.
- **Placeholder scan:** sin TBD/TODO; todo el código completo.
- **Type consistency:** `lastDetal/lastTiendas/lastPeriodos/lastProductos` declarados (T1) y leídos por las `g00Exp*` (T2/T3) ✓; builders devuelven `{header, body}` consumido como `r.header/r.body` ✓; `comparativaAOA(dim, rows, opts)` firma usada igual en las 6 llamadas ✓; `buildArbolPeriodos` ya existe y devuelve `{sems, tot}` con la estructura que `periodosAOA` recorre ✓; helpers `eDif/ePct/eProm/ePart` definidos en T1 y usados en T2/T3 ✓; campos del payload (`por_grupo`, `por_marca`, `mensual`, `mensual_tdas`, `kpis.tiendas_actual/anterior`, `tiendas`, `dias`, `negocios/categorias/generos.{rows,total}`, `anio`) coinciden con lo que emite el backend ✓.
```
