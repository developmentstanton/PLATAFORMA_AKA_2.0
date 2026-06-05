# G00 — Preview de imagen al hover en "Resumen Ventas Por Negocio" — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar un preview flotante con la foto del zapato al pasar el mouse sobre cada fila de negocio (`REF-COLOR`) de la tabla "Resumen Ventas Por Negocio".

**Architecture:** Solo frontend en `informes/g00.php`. Se marcan las filas de negocio con `data-negimg="<label>"`; un handler delegado en `#g00-tabla-negocio` muestra/posiciona un `<div>` tooltip único con un `<img>` cuya URL es `http://bi.stanton.com.co:81/fotosPBI/<label>.jpg` con fallback a `.png` y, si falla, se oculta. Spec: `docs/superpowers/specs/2026-06-05-g00-negocio-hover-imagen.md`.

**Tech Stack:** JS vanilla + CSS embebidos en `informes/g00.php`. Sin backend. Sin test automatizado (UI/red): verificación por `php -l` + E2E navegador.

**Rama:** `feat/g00-tiendas-resumen`.

---

## File Structure

- `informes/g00.php` — único archivo. (1) `rowArbol`: añadir `data-negimg` a filas de negocio cuando `opts.imgHover`; (2) `loadProductos`: pasar `imgHover:true` solo a la tabla de Negocio; (3) CSS `#g00-img-pop`; (4) bloque JS de inicialización del hover.

No hay test unitario: es comportamiento de DOM + red. Verificación E2E.

---

## Task 1: Preview de imagen al hover (todo en `informes/g00.php`)

**Files:**
- Modify: `informes/g00.php` (CSS ~147, `loadProductos` ~789, `rowArbol` ~984-1012, tras `g00ToggleArbol` ~1019)

- [ ] **Step 1: CSS del tooltip**

Tras la línea `.g00-scroll-neg table.disp-table thead th { position: sticky; top: 0; background: #fff; z-index: 1; box-shadow: inset 0 -1px 0 var(--border); }` (~147), añadir:

```css
    /* Preview flotante de foto del zapato (hover en filas de Negocio) */
    #g00-img-pop { position: fixed; display: none; z-index: 9999; pointer-events: none;
        background: #fff; border: 1px solid var(--border); border-radius: 8px;
        box-shadow: 0 6px 20px rgba(45,43,78,0.25); padding: 4px; }
    #g00-img-pop img { width: 160px; height: auto; max-height: 200px; display: block; border-radius: 4px; }
```

- [ ] **Step 2: Activar el hover solo en la tabla de Negocio (`loadProductos`)**

Reemplazar la línea:

```js
                renderTablaArbol('g00-tabla-negocio',   data.negocios,   data.anio, {col1:'Negocio / Talla',           prefix:'neg'});
```

por:

```js
                renderTablaArbol('g00-tabla-negocio',   data.negocios,   data.anio, {col1:'Negocio / Talla',           prefix:'neg', imgHover:true});
```

(Las llamadas de `g00-tabla-categoria` y `g00-tabla-genero` quedan IGUAL, sin `imgHover`.)

- [ ] **Step 3: Marcar las filas de negocio con `data-negimg` en `rowArbol`**

En `rowArbol`, tras la línea `const difT = (r.tiendas_act||0) - (r.tiendas_ant||0);` (~987), añadir:

```js
        const imgAttr = (opts.imgHover && (kind === 'parent' || kind === 'leaf') && r.label !== '(Sin dato)')
            ? ' data-negimg="'+esc(r.label)+'"' : '';
```

Luego, en el mismo `rowArbol`, reemplazar la línea del `trOpen` de la rama **parent**:

```js
            trOpen = '<tr class="'+cls+' g00-marca-row g00-collapsed" onclick="g00ToggleArbol(\''+opts.prefix+'\','+meta.idx+',this)">';
```

por:

```js
            trOpen = '<tr class="'+cls+' g00-marca-row g00-collapsed"'+imgAttr+' onclick="g00ToggleArbol(\''+opts.prefix+'\','+meta.idx+',this)">';
```

Y reemplazar la línea del `trOpen` de la rama **else** (leaf/total):

```js
            trOpen = '<tr class="'+cls+'">';
```

por:

```js
            trOpen = '<tr class="'+cls+'"'+imgAttr+'>';
```

(La rama `child` —tallas— NO lleva `imgAttr`. La fila Total tiene `kind === 'total'`, así que `imgAttr` queda vacío para ella. Solo `parent` y `leaf` no-`(Sin dato)` reciben el atributo.)

- [ ] **Step 4: Bloque JS de inicialización del hover**

Inmediatamente DESPUÉS de la definición de `window.g00ToggleArbol = function (...) { ... };` (termina con `};` en ~línea 1019), insertar:

```js
    // ===== Preview de imagen del zapato al hover en la tabla "Resumen Ventas Por Negocio" =====
    (function initNegocioImgHover() {
        const FOTO_BASE = 'http://bi.stanton.com.co:81/fotosPBI/';
        const tabla = document.getElementById('g00-tabla-negocio');
        if (!tabla) return;
        let pop = null, img = null, triedPng = false, curLabel = '';
        function ensurePop() {
            if (pop) return;
            pop = document.createElement('div');
            pop.id = 'g00-img-pop';
            img = document.createElement('img');
            img.alt = '';
            img.onerror = function () {
                if (!triedPng) { triedPng = true; img.src = FOTO_BASE + encodeURIComponent(curLabel) + '.png'; }
                else { hide(); }
            };
            pop.appendChild(img);
            document.body.appendChild(pop);
        }
        function hide() { if (pop) pop.style.display = 'none'; }
        function position(e) {
            if (!pop) return;
            const off = 16, w = 176, h = 216;
            let x = e.clientX + off, y = e.clientY + off;
            if (x + w > window.innerWidth)  x = e.clientX - off - w;
            if (y + h > window.innerHeight) y = e.clientY - off - h;
            pop.style.left = Math.max(4, x) + 'px';
            pop.style.top  = Math.max(4, y) + 'px';
        }
        tabla.addEventListener('mouseover', function (e) {
            const tr = e.target.closest('tr[data-negimg]');
            if (!tr) return;
            curLabel = tr.getAttribute('data-negimg');
            triedPng = false;
            ensurePop();
            img.src = FOTO_BASE + encodeURIComponent(curLabel) + '.jpg';
            pop.style.display = 'block';
            position(e);
        });
        tabla.addEventListener('mousemove', function (e) {
            if (pop && pop.style.display === 'block') position(e);
        });
        tabla.addEventListener('mouseout', function (e) {
            const tr = e.target.closest('tr[data-negimg]');
            if (tr && (!e.relatedTarget || !tr.contains(e.relatedTarget))) hide();
        });
    })();
```

Notas de diseño:
- El `<table id="g00-tabla-negocio">` existe en el markup estático (el panel está en la página desde el inicio, solo oculto), así que los listeners se enganchan una vez al cargar el script y sobreviven a los re-render de `renderTablaArbol` (que solo cambia el `innerHTML` del table, no el elemento).
- `position` usa `clientX/clientY` con `position:fixed` (relativo al viewport) y voltea el preview si se sale por la derecha/abajo.
- `onerror`: 1er fallo (`.jpg`) → reintenta `.png`; 2º fallo (`.png`) → oculta. `triedPng`/`curLabel` se resetean en cada `mouseover`.

- [ ] **Step 5: Lint + verificación de referencias**

Run: `php -l informes/g00.php` → Expected: `No syntax errors detected`
Run: `grep -n "data-negimg\|g00-img-pop\|fotosPBI\|imgHover" informes/g00.php`
Expected: aparecen — `imgHover` en la llamada de negocio (1) + `rowArbol` (1); `data-negimg` en `rowArbol` (1) + 3 listeners; `g00-img-pop` en CSS + JS; `fotosPBI` en el JS (FOTO_BASE).
Self-review: la llamada de Negocio tiene `imgHover:true`; las de Categoría/Género NO; las ramas `child` y `total` no emiten `data-negimg`.

- [ ] **Step 6: Commit**

```bash
git add informes/g00.php
git commit -m "feat(g00): preview de foto del zapato al hover en tabla Resumen Ventas Por Negocio"
```

---

## Task 2: Verificación final + handoff E2E

**Files:** ninguno

- [ ] **Step 1: Lint + regresión backend (no debería verse afectada)**

Run: `php -l informes/g00.php` → Expected: `No syntax errors detected`
Run: `php tests/g00_productos_test.php` → Expected: `RESULTADO: OK` (el payload no cambió; solo confirma que la pestaña sigue sirviendo bien).

- [ ] **Step 2: Handoff a Rafael (E2E navegador)**

Pedir verificación en `localhost/plataforma_20` (login → Ventas → tab Ventas Por Productos → tabla "Resumen Ventas Por Negocio", Ctrl+F5):
- Hover sobre un negocio con foto `.jpg` → aparece el preview con la imagen correcta, que sigue al cursor y desaparece al salir de la fila.
- Hover sobre un negocio cuya foto es `.png` → también aparece (fallback `.jpg`→`.png`).
- Hover sobre un negocio sin foto → no aparece nada, sin ícono de imagen rota.
- Las filas de **talla** (hijas) y la fila **Total** NO disparan preview.
- Las tablas de **Categoría** y **Género** NO muestran preview.
- (Recordatorio mixed-content: funciona en `http` local; si el portal pasa a `https`, las fotos `http` se bloquearían — follow-up de despliegue.)

---

## Self-Review

- **Spec coverage:** preview flotante en filas de negocio → Task 1 Steps 3-4 ✓. URL base + fallback `.jpg`→`.png`→ocultar → Step 4 (`onerror`) ✓. Solo tabla Negocio → Step 2 (`imgHover` solo ahí) ✓. Excluir talla/Total/`(Sin dato)` → Step 3 (`imgAttr` condicionado a `parent`/`leaf` y `label !== '(Sin dato)'`) ✓. Lazy (carga al hover) → Step 4 (`img.src` se setea en `mouseover`) ✓. Tamaño ~160px + tarjeta → Step 1 CSS ✓. `encodeURIComponent` → Step 4 ✓. Sigue al cursor + voltea en bordes → `position()` ✓. Verificación php -l + E2E → Tasks 1/2 ✓.
- **Placeholder scan:** sin TBD/TODO; todo el código completo.
- **Type consistency:** `opts.imgHover` (seteado en `loadProductos` Step 2) leído en `rowArbol` (Step 3) ✓; `data-negimg` escrito en `rowArbol` y leído por los listeners (Step 4) con el mismo nombre ✓; `kind` valores `parent/child/leaf/total` coinciden con los emitidos por `renderTablaArbol` ✓; helper `esc` ya existe ✓.
