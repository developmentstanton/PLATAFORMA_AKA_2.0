# Filtros y export (Sub-proyecto A) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hacer la barra de filtros colapsable con un aviso de chips, marcar "(filtrado)" en título y pestañas, incluir los filtros aplicados en el encabezado del Excel, y renombrar "Recomendaciones" → "Alertas" en el informe de siembra/stock (o14).

**Architecture:** Un único helper global `window.filtrosUI` en `dashboard.php` (junto a `expFile`/`expDataset`) introspecciona el DOM de la página activa —el contenedor `.g00-filters`, sus `.filter-group` y las instancias de Tom Select (`select.tomselect`)— para conocer los filtros aplicados. Con eso, una sola implementación sirve para los 3 informes con filtros (g00, o14, geo): inyecta la cabecera colapsable + chips, pinta el badge "(filtrado)", y produce las filas de encabezado del Excel. Cada informe empuja su período con `filtrosUI.setPeriodo(...)` dentro de su carga.

**Tech Stack:** PHP server-rendered, JavaScript vanilla (módulos IIFE), Tom Select 2.3, SheetJS (xlsx) 0.20, SweetAlert2. Sin framework de tests JS (verificación manual en navegador, como el resto del módulo).

## Global Constraints

- **No React ni build step:** editar `.php` con `<script>` inline; nada de npm/bundlers.
- **Librerías por CDN ya cargadas** en `dashboard.php` head: no agregar dependencias nuevas.
- **Tom Select:** la instancia de un `<select>` está en `select.tomselect`; valores seleccionados en `select.tomselect.items` (array de strings); texto de una opción en `select.tomselect.options[val].text`. Guardar siempre contra `null` (puede no estar inicializado).
- **Estructura de filtros (los 3 informes):** contenedor `.g00-filters` → filas `.g00-filter-row` → `.filter-group` con `<label>` + `<select multiple>`. Los controles de fecha y segmentados NO son `.filter-group` con Tom Select, así que quedan naturalmente excluidos de `activos()`.
- **Rename alcance:** solo textos visibles + nombres de Excel en `o14.php`. NO tocar ids internos (`reco`, `#o14-reco-*`, `o14ShowTab('reco')`) ni `api/*`.
- **Excel:** los archivos generados no son leídos por ninguna rutina; desplazar filas hacia abajo es seguro.
- **Persistencia colapso:** `localStorage`, clave `filtros_colapsado_<pageId>`; degradar en silencio si no está disponible.

---

### Task 1: Núcleo de lectura `filtrosUI` (activos / período / estaFiltrado)

**Files:**
- Modify: `C:\xampp\htdocs\plataforma_20\dashboard.php` (bloque `<script>` del head, tras `window.expDataset`, ~línea 60)

**Interfaces:**
- Produces:
  - `window.filtrosUI.activos(pageEl) -> Array<{etiqueta:string, valores:string[]}>` — filtros con ≥1 selección.
  - `window.filtrosUI.setPeriodo(pageId:string, desde:string, hasta:string) -> void`
  - `window.filtrosUI.getPeriodo(pageId:string) -> {desde,hasta}|null`
  - `window.filtrosUI.estaFiltrado(pageEl) -> boolean`
  - Helper interno `window.filtrosUI._page(pageEl?) -> HTMLElement|null` (usa `.page.active` si no se pasa `pageEl`).

- [ ] **Step 1: Agregar el objeto `filtrosUI` con la API de lectura**

En `dashboard.php`, justo después de que cierra `window.expDataset = function(...) { ... };` (antes del `</script>` de ese bloque, ~línea 60), insertar:

```javascript
// ===== Estado de filtros compartido por los informes (g00/o14/geo) =====
window.filtrosUI = {
  _periodos: {},
  _page: function (pageEl) {
    if (pageEl && pageEl.nodeType === 1) return pageEl;
    return document.querySelector('.page.active');
  },
  setPeriodo: function (pageId, desde, hasta) {
    if (pageId) this._periodos[pageId] = { desde: desde || '', hasta: hasta || '' };
  },
  getPeriodo: function (pageId) {
    return this._periodos[pageId] || null;
  },
  // Lee los .filter-group del informe activo y devuelve los que tienen selección.
  activos: function (pageEl) {
    const page = this._page(pageEl);
    if (!page) return [];
    const cont = page.querySelector('.g00-filters');
    if (!cont) return [];
    const out = [];
    cont.querySelectorAll('.filter-group').forEach(function (grp) {
      const sel = grp.querySelector('select');
      const lab = grp.querySelector('label');
      if (!sel || !lab || !sel.tomselect) return;
      const items = sel.tomselect.items || [];
      if (!items.length) return;
      const opts = sel.tomselect.options || {};
      const valores = items.map(function (v) {
        return (opts[v] && opts[v].text != null) ? String(opts[v].text) : String(v);
      });
      out.push({ etiqueta: lab.textContent.trim(), valores: valores });
    });
    return out;
  },
  estaFiltrado: function (pageEl) {
    return this.activos(pageEl).length > 0;
  }
};
```

- [ ] **Step 2: Verificar en navegador (consola)**

Iniciar XAMPP, abrir `http://localhost/plataforma_20/dashboard.php`, entrar al informe **G00 (Dashboard de Ventas)**, elegir 2 marcas en el filtro "Marca" y aplicar. En la consola del navegador ejecutar:

```javascript
filtrosUI.activos()
```

Esperado: un array con un objeto `{etiqueta:"Marca", valores:["...", "..."]}` reflejando las 2 marcas elegidas. `filtrosUI.estaFiltrado()` → `true`. Sin filtros → `[]` y `false`.

- [ ] **Step 3: Commit**

```bash
git add dashboard.php
git commit -m "feat(informes): filtrosUI nucleo de lectura de filtros activos"
```

---

### Task 2: Cabecera colapsable + chips

**Files:**
- Modify: `C:\xampp\htdocs\plataforma_20\dashboard.php` (objeto `filtrosUI`: agregar `ensureChrome`, `_toggle`, `_renderChips`, `render`; y CSS en el `<style>`)
- Modify: `C:\xampp\htdocs\plataforma_20\informes\g00.php` (llamar `filtrosUI.setPeriodo` + `filtrosUI.render` en `loadDetal`)
- Modify: `C:\xampp\htdocs\plataforma_20\informes\o14.php` (idem en `o14Load`/carga)
- Modify: `C:\xampp\htdocs\plataforma_20\informes\geo.php` (llamar `filtrosUI.render` en `geoLoad`)

**Interfaces:**
- Consumes: `filtrosUI.activos`, `filtrosUI.getPeriodo`, `filtrosUI._page` (Task 1).
- Produces:
  - `filtrosUI.render(pageEl) -> void` — inyecta cabecera (idempotente) y repinta chips.
  - `filtrosUI.ensureChrome(pageEl) -> void`
  - CSS: `.filtros-head`, `.filtros-toggle`, `.filtros--colapsado`, `.filtros-chips`, `.filtros-chip`.

- [ ] **Step 1: Agregar CSS de cabecera y chips**

En el `<style>` de `dashboard.php` (después de `:root{...}`, en cualquier punto del bloque), agregar:

```css
.filtros-head { display:flex; align-items:center; gap:10px; margin-bottom:8px; flex-wrap:wrap; }
.filtros-toggle { cursor:pointer; user-select:none; background:none; border:none; font:inherit;
  font-weight:600; color:var(--primary); display:inline-flex; align-items:center; gap:6px; padding:2px 4px; }
.filtros-toggle .chev { transition:transform .15s ease; }
.g00-filters.filtros--colapsado .g00-filter-row { display:none; }
.g00-filters.filtros--colapsado .filtros-toggle .chev { transform:rotate(-90deg); }
.filtros-chips { display:none; gap:6px; flex-wrap:wrap; align-items:center; }
.g00-filters.filtros--colapsado .filtros-chips { display:flex; }
.filtros-chip { background:#eef2f7; border:1px solid var(--border); border-radius:999px;
  padding:2px 10px; font-size:11px; color:var(--text); white-space:nowrap; }
.filtros-chip b { color:var(--primary); font-weight:600; }
.filtros-chip.vacio { color:var(--text-light); background:transparent; }
```

- [ ] **Step 2: Agregar `ensureChrome` + `_toggle` + `_renderChips` + `render` a `filtrosUI`**

Dentro del objeto `window.filtrosUI` (agregar estos métodos junto a los de Task 1):

```javascript
  ensureChrome: function (pageEl) {
    const page = this._page(pageEl);
    if (!page) return;
    const cont = page.querySelector('.g00-filters');
    if (!cont || cont.querySelector('.filtros-head')) return; // idempotente
    const pageId = page.id.replace(/^page-/, '');
    const head = document.createElement('div');
    head.className = 'filtros-head';
    head.innerHTML =
      '<button type="button" class="filtros-toggle"><span class="chev">▾</span> Filtros</button>' +
      '<div class="filtros-chips"></div>';
    cont.insertBefore(head, cont.firstChild);
    const self = this;
    head.querySelector('.filtros-toggle').addEventListener('click', function () {
      self._toggle(page, cont, pageId);
    });
    // Restaurar estado guardado
    let saved = null;
    try { saved = localStorage.getItem('filtros_colapsado_' + pageId); } catch (e) {}
    if (saved === '1') cont.classList.add('filtros--colapsado');
  },
  _toggle: function (page, cont, pageId) {
    cont.classList.toggle('filtros--colapsado');
    const col = cont.classList.contains('filtros--colapsado');
    try { localStorage.setItem('filtros_colapsado_' + pageId, col ? '1' : '0'); } catch (e) {}
    if (col) this._renderChips(page);
  },
  _renderChips: function (page) {
    const box = page.querySelector('.g00-filters .filtros-chips');
    if (!box) return;
    const pageId = page.id.replace(/^page-/, '');
    const chips = [];
    const per = this.getPeriodo(pageId);
    if (per && per.desde && per.hasta) {
      chips.push('<span class="filtros-chip"><b>Período:</b> ' + per.desde + ' a ' + per.hasta + '</span>');
    }
    const act = this.activos(page);
    act.forEach(function (f) {
      const N = 3;
      let vals = f.valores.slice(0, N).join(', ');
      if (f.valores.length > N) vals += ' +' + (f.valores.length - N) + ' más';
      chips.push('<span class="filtros-chip"><b>' + f.etiqueta + ':</b> ' + vals + '</span>');
    });
    if (!act.length) chips.push('<span class="filtros-chip vacio">Sin filtros</span>');
    box.innerHTML = chips.join('');
  },
  render: function (pageEl) {
    const page = this._page(pageEl);
    if (!page) return;
    this.ensureChrome(page);
    this._renderChips(page);
  },
```

Nota: `_renderChips` se llama siempre en `render()` (aunque esté expandido) para que los chips estén listos al colapsar; el CSS los oculta mientras esté expandido.

- [ ] **Step 3: Escapar HTML de los valores (seguridad/robustez)**

Los nombres de filtro provienen de datos (marcas, ciudades). Para evitar romper el markup, agregar un helper de escape y usarlo en `_renderChips`. Dentro de `filtrosUI`:

```javascript
  _esc: function (s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c];
    });
  },
```

Y en `_renderChips`, envolver los textos dinámicos: `this._esc(per.desde)`, `this._esc(per.hasta)`, `this._esc(f.etiqueta)`, y al construir `vals` escapar cada valor: `f.valores.slice(0,N).map(v=>this._esc(v)).join(', ')` (usar `const self=this;` o arrow para conservar `this`). El texto fijo "+N más" no se escapa.

- [ ] **Step 4: Enganchar `render` + `setPeriodo` en G00**

En `informes/g00.php`, dentro de `loadDetal` (tras `hideLoading();` al final del `.then`, ~línea 716), agregar:

```javascript
                filtrosUI.setPeriodo('informes-g00', data.rango && data.rango.desde, data.rango && data.rango.hasta);
                filtrosUI.render(document.getElementById('page-informes-g00'));
```

Si `data.rango` no trae `desde/hasta` con ese nombre, usar los valores de `buildParams` (`dateVal('desde')`, `dateVal('hasta')`): reemplazar la primera línea por
`filtrosUI.setPeriodo('informes-g00', dateVal('desde'), dateVal('hasta'));`
(verificar en el Step de prueba cuál trae la fecha correcta y dejar solo esa).

- [ ] **Step 5: Enganchar `render` + `setPeriodo` en o14**

En `informes/o14.php`, en la función que carga cada tab (al final de `loadCurrentTab` o dentro de `o14Load`), tras pintar, agregar:

```javascript
    filtrosUI.setPeriodo('informes-o14', val('o14-vdesde') || '2025-01-01', val('o14-vhasta') || new Date().toISOString().slice(0,10));
    filtrosUI.render(document.getElementById('page-informes-o14'));
```

Colocarlo dentro de `window.o14Load` (línea ~533) al final, de modo que corra cada vez que se aplica.

- [ ] **Step 6: Enganchar `render` en geo**

En `informes/geo.php`, dentro de `geoLoad` tras cargar los datos del mapa (~línea 130-140, al final del `.then`), agregar (geo no tiene período):

```javascript
    filtrosUI.render(document.getElementById('page-georreferenciacion'));
```

- [ ] **Step 7: Verificar en navegador**

Para cada informe (G00, o14, geo): aplicar 2+ filtros, hacer click en el toggle "▾ Filtros".
Esperado:
- Colapsa: las filas de filtros se ocultan y aparece la tira de chips (`Período:` en g00/o14, luego `Marca: ...`, `Ciudad: ...`, con `+N más` si aplica). Sin filtros → chip "Sin filtros".
- Expandir: vuelven los filtros.
- Navegar a otro informe y volver: el estado colapsado/expandido persiste (localStorage).
- Botón "Aplicar" sigue accesible en ambos estados.

- [ ] **Step 8: Commit**

```bash
git add dashboard.php informes/g00.php informes/o14.php informes/geo.php
git commit -m "feat(informes): filtros colapsables con chips de filtros aplicados"
```

---

### Task 3: Marcador "(filtrado)" en título y pestañas

**Files:**
- Modify: `C:\xampp\htdocs\plataforma_20\dashboard.php` (topbar: badge junto a `#pageTitle` ~línea 491; método `_renderMarcadores` en `filtrosUI`; CSS)

**Interfaces:**
- Consumes: `filtrosUI.estaFiltrado`, `filtrosUI._page` (Task 1); `filtrosUI.render` (Task 2).
- Produces: `filtrosUI._renderMarcadores(page) -> void`, invocado desde `render()`.

- [ ] **Step 1: Agregar el badge al topbar**

En `dashboard.php` línea 491, cambiar:

```html
                <h2 id="pageTitle">DASHBOARD</h2>
```

por:

```html
                <h2 id="pageTitle">DASHBOARD</h2><span id="pageTitleFiltrado" class="filtrado-badge" style="display:none;">(filtrado)</span>
```

- [ ] **Step 2: CSS del badge y del marcador de pestañas**

En el `<style>` de `dashboard.php`, agregar:

```css
.filtrado-badge { font-size:12px; font-weight:600; color:var(--accent, #c0392b);
  margin-left:8px; vertical-align:middle; letter-spacing:.3px; }
.tab .tab-filtrado { font-size:10px; font-weight:600; color:var(--accent, #c0392b); margin-left:6px; }
```

- [ ] **Step 3: Agregar `_renderMarcadores` y llamarlo desde `render`**

En `filtrosUI` agregar:

```javascript
  _renderMarcadores: function (page) {
    const filtrado = this.estaFiltrado(page);
    // Badge del título (solo cuando este informe es el activo)
    const badge = document.getElementById('pageTitleFiltrado');
    if (badge && page.classList.contains('active')) badge.style.display = filtrado ? '' : 'none';
    // Marcador en cada pestaña del informe
    page.querySelectorAll('.tab-bar .tab').forEach(function (tab) {
      let m = tab.querySelector('.tab-filtrado');
      if (filtrado && !m) {
        m = document.createElement('span');
        m.className = 'tab-filtrado';
        m.textContent = '(filtrado)';
        tab.appendChild(m);
      } else if (!filtrado && m) {
        m.remove();
      }
    });
  },
```

Y en `render()`, después de `this._renderChips(page);`, agregar:

```javascript
    this._renderMarcadores(page);
```

- [ ] **Step 4: Ocultar el badge al cambiar de página**

En `dashboard.php`, dentro de `showPage(...)` (junto a las líneas ~1043-1046 que ocultan `pageSubtitle`/topbar), agregar:

```javascript
        var _bf = document.getElementById('pageTitleFiltrado'); if (_bf) _bf.style.display = 'none';
```

Así, al entrar a un informe sin filtros o sin barra, el badge no queda pegado del informe anterior. Cada `filtrosUI.render` del informe activo lo reactiva si corresponde.

- [ ] **Step 5: Verificar en navegador**

En G00: sin filtros → sin "(filtrado)" en título ni pestañas. Aplicar un filtro de marca → aparece "(filtrado)" junto a "DASHBOARD DE VENTAS" y junto a cada pestaña (Detal, Detalle Tiendas, …). Quitar todos los filtros y aplicar → desaparece. Repetir en o14 (pestañas Por tienda/Por negocio/Alertas) y geo. Cambiar de informe → el badge del título no se queda pegado.

- [ ] **Step 6: Commit**

```bash
git add dashboard.php
git commit -m "feat(informes): marcador (filtrado) en titulo y pestanas"
```

---

### Task 4: Filtros aplicados en el encabezado del Excel

**Files:**
- Modify: `C:\xampp\htdocs\plataforma_20\dashboard.php` (`filtrosUI.excelHeaderRows`; inyección en `window.expDataset`)
- Modify: `C:\xampp\htdocs\plataforma_20\informes\o14.php` (inyectar encabezado en `o14Export` reco, ~562-569)

**Interfaces:**
- Consumes: `filtrosUI.activos`, `filtrosUI.getPeriodo`, `filtrosUI._page` (Task 1).
- Produces: `filtrosUI.excelHeaderRows(pageEl) -> Array<Array<string>>`.

- [ ] **Step 1: Agregar `excelHeaderRows` a `filtrosUI`**

```javascript
  excelHeaderRows: function (pageEl) {
    const page = this._page(pageEl);
    const rows = [['Filtros aplicados:']];
    const pageId = page ? page.id.replace(/^page-/, '') : '';
    const per = this.getPeriodo(pageId);
    if (per && per.desde && per.hasta) rows.push(['Período:', per.desde + ' a ' + per.hasta]);
    const act = this.activos(page);
    if (act.length) {
      act.forEach(function (f) { rows.push([f.etiqueta + ':', f.valores.join(', ')]); });
    } else {
      rows.push(['(sin filtros de dimensión)']);
    }
    rows.push([]); // fila en blanco separadora
    return rows;
  },
```

- [ ] **Step 2: Inyectar el encabezado en `window.expDataset`**

En `dashboard.php`, dentro de `window.expDataset`, cambiar la construcción de la hoja. Actualmente (líneas ~55-58):

```javascript
      const ws = XLSX.utils.aoa_to_sheet([header, ...filas]);
      const wb = XLSX.utils.book_new();
```

por:

```javascript
      const hdr = (window.filtrosUI ? window.filtrosUI.excelHeaderRows() : []);
      const ws = XLSX.utils.aoa_to_sheet([...hdr, header, ...filas]);
      const wb = XLSX.utils.book_new();
```

(Como `expDataset` se define antes que `filtrosUI` en el mismo bloque, la guarda `window.filtrosUI ?` evita fallos si el orden cambia; en runtime siempre existirá al momento de exportar.)

- [ ] **Step 3: Inyectar el encabezado en el export propio de o14 (reco)**

En `informes/o14.php`, en `o14Export` (rama reco, líneas ~565-568), anteponer el encabezado a cada hoja. Cambiar:

```javascript
      const wb = XLSX.utils.book_new();
      const HOJA = { sobrante:'Sobrante', faltante:'Faltante', proveedor:'Proveedor' };
      RECO_MED.forEach(med => { const r = recoAOA(dataR, med);
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([r.header, ...r.filas]), HOJA[med]); });
```

por:

```javascript
      const wb = XLSX.utils.book_new();
      const HOJA = { sobrante:'Sobrante', faltante:'Faltante', proveedor:'Proveedor' };
      const hdr = filtrosUI.excelHeaderRows(document.getElementById('page-informes-o14'));
      RECO_MED.forEach(med => { const r = recoAOA(dataR, med);
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([...hdr, r.header, ...r.filas]), HOJA[med]); });
```

- [ ] **Step 4: Verificar en navegador**

En G00 con 2 filtros aplicados, exportar cualquier cuadro a Excel (botón "⤓ Excel"). Abrir el archivo:
Esperado: primeras filas = `Filtros aplicados:`, `Período: <desde> a <hasta>`, una fila por filtro (`Marca: BRAHMA, OX`, `Ciudad: Bogotá`), fila en blanco, y luego el encabezado + datos como antes.
En o14, pestaña Alertas, exportar: cada hoja (Sobrante/Faltante/Proveedor) trae el mismo encabezado arriba.
Sin filtros: encabezado muestra `(sin filtros de dimensión)` y el Período si aplica.

- [ ] **Step 5: Commit**

```bash
git add dashboard.php informes/o14.php
git commit -m "feat(informes): filtros aplicados en el encabezado del Excel exportado"
```

---

### Task 5: Rename "Recomendaciones" → "Alertas" (o14)

**Files:**
- Modify: `C:\xampp\htdocs\plataforma_20\informes\o14.php` (líneas 52, 401, 457, 562, 569)

**Interfaces:**
- Consumes: nada nuevo. Solo cambios de texto/copys.
- Produces: nada nuevo.

- [ ] **Step 1: Renombrar la etiqueta de la pestaña (línea 52)**

Cambiar:

```html
    <div class="tab" onclick="o14ShowTab('reco', this)">Recomendaciones <span id="o14-reco-dot" class="o14-reco-dot"></span></div>
```

por:

```html
    <div class="tab" onclick="o14ShowTab('reco', this)">Alertas <span id="o14-reco-dot" class="o14-reco-dot"></span></div>
```

(NO cambiar `o14ShowTab('reco'`, ni el id `o14-reco-dot`.)

- [ ] **Step 2: Renombrar los nombres de cuadro del Excel (línea 401)**

Cambiar:

```javascript
  const RECO_CUADRO = { sobrante:'Recomendación - Reubicación Sobrante', faltante:'Recomendación - Faltante', proveedor:'Recomendación - Solicitud a Proveedor' };
```

por:

```javascript
  const RECO_CUADRO = { sobrante:'Alerta - Reubicación Sobrante', faltante:'Alerta - Faltante', proveedor:'Alerta - Solicitud a Proveedor' };
```

- [ ] **Step 3: Renombrar el texto de carga (línea 457)**

Cambiar:

```javascript
    showLoading('Calculando recomendaciones');
```

por:

```javascript
    showLoading('Calculando alertas');
```

- [ ] **Step 4: Renombrar el nombre de archivo del export (líneas 562 y 569)**

Cambiar en la línea 562:

```javascript
      if(!lastReco){ window.expDataset('Recomendaciones', 'Reco', [], []); return; }
```

por:

```javascript
      if(!lastReco){ window.expDataset('Alertas', 'Reco', [], []); return; }
```

Y en la línea 569:

```javascript
      XLSX.writeFile(wb, window.expFile('Recomendaciones'));
```

por:

```javascript
      XLSX.writeFile(wb, window.expFile('Alertas'));
```

- [ ] **Step 5: Verificar que no queden textos visibles "Recomendaci" en o14**

```bash
grep -n "Recomendaci" informes/o14.php
```

Esperado: solo comentarios (líneas ~219, 374, 408, 417, 509) y ninguna cadena de UI/export. Los comentarios se pueden dejar (no son de cara al usuario). `RECO_LABEL` (línea 348: "Reubicación — Sobrante disponible", "Faltante", "Solicitud a proveedor") no contiene "Recomendaci", no se toca.

- [ ] **Step 6: Verificar en navegador**

Entrar a o14 (Siembra/Stock/Ventas): la 3ª pestaña dice "Alertas" (con su punto pulsante). Cargarla → loader dice "Calculando alertas". Exportar → archivo `Alertas - <proveedor> - <fecha>.xlsx`.

- [ ] **Step 7: Commit**

```bash
git add informes/o14.php
git commit -m "feat(o14): renombrar Recomendaciones a Alertas (UI + Excel)"
```

---

## Self-Review

**Spec coverage:**
- Filtros colapsables + chips → Task 2. ✓
- "(filtrado)" en título y pestañas → Task 3. ✓
- Filtros en encabezado del Excel (expDataset + export propio de o14) → Task 4. ✓
- Rename Recomendaciones→Alertas (etiqueta + Excel) → Task 5. ✓
- Helper compartido `filtrosUI` (introspección DOM + Tom Select) → Task 1 (núcleo), extendido en 2/3/4. ✓
- Aplicación uniforme a g00/o14/geo → Tasks 2/3 enganchan los 3 informes. ✓
- Período como contexto (no dispara "(filtrado)") → `activos()` excluye fechas; `estaFiltrado()` solo cuenta selects; Período va en chips (Task 2) y Excel (Task 4). ✓
- Persistencia de colapso en localStorage → Task 2. ✓
- Degradación ante Tom Select no inicializado / localStorage ausente → guardas en Tasks 1 y 2. ✓

**Placeholder scan:** sin TBD/TODO; todo el código está completo. El único punto condicional (Task 2 Step 4: `data.rango` vs `dateVal`) se resuelve por observación en la prueba y deja una sola línea. ✓

**Type consistency:** `activos()` devuelve `{etiqueta, valores}` y así se consume en `_renderChips` (Task 2) y `excelHeaderRows` (Task 4). `_page`, `getPeriodo`, `estaFiltrado`, `render` se usan con las mismas firmas en todas las tasks. Los ids `page-informes-g00`, `page-informes-o14`, `page-georreferenciacion` coinciden con el markup real. ✓

## Notas de ejecución

- Trabajar en una rama nueva (ej. `feature/filtros-y-export`); el proyecto usa ramas de feature y luego merge+push+copia al servidor.
- No hay framework de tests JS: la verificación es manual en navegador (XAMPP local), consistente con la sección "Pruebas" del spec.
- Requiere XAMPP corriendo y sesión iniciada con un proveedor para que los informes carguen datos.
