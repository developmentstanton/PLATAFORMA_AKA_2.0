# Fotos de tiendas en georeferenciación (Sub-proyecto B) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar en el panel lateral de georeferenciación la foto de la tienda seleccionada (subida externamente a Cloudinary, resuelta por `tiendas/<cod>`), con placeholder cuando no hay foto.

**Architecture:** Cambio 100% frontend en `informes/geo.php`. La URL de Cloudinary se construye en el cliente a partir del `cod` de la tienda (sin BD, sin API, sin subida, sin credenciales). Al hacer click en un pin del mapa o en una fila del listado, se rellena una tarjeta `#geo-detalle` en el panel `#geo-list` con la foto (o placeholder) y los datos de la tienda. El hover (tooltip Leaflet) no cambia.

**Tech Stack:** PHP server-rendered, JavaScript vanilla (IIFE), Leaflet 1.9.4. Imágenes públicas de Cloudinary por URL directa de entrega. Sin framework de tests JS (verificación manual en navegador).

## Global Constraints

- **No React ni build step:** editar solo el `<script>`/`<style>`/HTML de `informes/geo.php`.
- **Sin dependencias nuevas** y **sin cambios** en `api/informe_geo.php`, base de datos, o auth.
- **Convención de nombre fija:** `tiendas/<cod>` (carpeta `tiendas`, public_id = el `cod` de Bodegas).
- **URL de Cloudinary exacta:** `https://res.cloudinary.com/<cloud>/image/upload/f_auto,q_auto,c_fill,w_600,h_400/tiendas/<encodeURIComponent(cod)>`.
- **`CLOUDINARY_CLOUD` por defecto `''`** (vacío) → todas las tiendas muestran placeholder, sin intentos de red. Rafael lo llena cuando tenga la cuenta.
- **Placeholder:** SVG inline como data-URI (sin archivos ni CDN externo, seguro para CSP).
- **Escape:** todo texto dinámico con el helper `esc` ya existente en `geo.php`.
- **Sin framework de tests JS:** verificación manual en navegador (XAMPP + sesión de proveedor).

---

### Task 1: Capa de datos — config, constructor de URL y placeholder

**Files:**
- Modify: `C:\xampp\htdocs\plataforma_20\informes\geo.php` (dentro del IIFE del `<script>`, junto a las constantes de módulo ~líneas 51-54)

**Interfaces:**
- Produces:
  - `const CLOUDINARY_CLOUD` (string, default `''`) y `const FOTO_FOLDER = 'tiendas'`.
  - `const FOTO_PLACEHOLDER` (string, data-URI SVG).
  - `function fotoTiendaURL(cod) -> string|null` — URL de Cloudinary, o `null` si no hay cloud o cod vacío.

- [ ] **Step 1: Agregar constantes y `fotoTiendaURL`**

En `informes/geo.php`, dentro del IIFE, inmediatamente después de la línea
`const nf  = n => Number(n||0).toLocaleString('es-CO');` (~línea 54), insertar:

```javascript
  // ===== Fotos de tiendas (Cloudinary) =====
  // Llenar con el cloud_name de tu cuenta Cloudinary. Vacío => solo placeholder (sin red).
  const CLOUDINARY_CLOUD = '';
  const FOTO_FOLDER = 'tiendas';   // convención acordada: tiendas/<cod>
  // Placeholder SVG inline (silueta de tienda + "Sin foto"). No depende de archivos externos.
  const FOTO_PLACEHOLDER = 'data:image/svg+xml;utf8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="280" height="160" viewBox="0 0 280 160">' +
    '<rect width="280" height="160" fill="#eef2f7"/>' +
    '<path d="M96 66h88v46H96z" fill="#c7cfdb"/>' +
    '<path d="M88 66l10-20h84l10 20z" fill="#b3bccd"/>' +
    '<rect x="128" y="86" width="24" height="26" fill="#eef2f7"/>' +
    '<text x="140" y="140" font-family="sans-serif" font-size="13" fill="#8a94a6" text-anchor="middle">Sin foto</text>' +
    '</svg>');
  function fotoTiendaURL(cod) {
    if (!CLOUDINARY_CLOUD || cod == null || cod === '') return null;
    return 'https://res.cloudinary.com/' + CLOUDINARY_CLOUD +
           '/image/upload/f_auto,q_auto,c_fill,w_600,h_400/' +
           FOTO_FOLDER + '/' + encodeURIComponent(cod);
  }
```

- [ ] **Step 2: Verificación estática + consola**

1. PHP lint: `"/c/xampp/php/php.exe" -l informes/geo.php` → esperado "No syntax errors detected"
   (ignorar warnings preexistentes de xdebug/env). Si `php.exe` no está en esa ruta, probar `php -l`.
2. En el navegador (geo cargado), en la consola —los nombres son locales al IIFE, así que
   probar temporalmente exponiéndolos NO es necesario; en su lugar validar por lectura del
   código— confirmar:
   - Con `CLOUDINARY_CLOUD = ''`: `fotoTiendaURL('003')` debe devolver `null` (rama del guard).
   - Si se cambiara a `'demo'`: devolvería
     `https://res.cloudinary.com/demo/image/upload/f_auto,q_auto,c_fill,w_600,h_400/tiendas/003`.
   Dejar `CLOUDINARY_CLOUD = ''` en el commit.

- [ ] **Step 3: Commit**

```bash
git add informes/geo.php
git commit -m "feat(geo): capa de datos de fotos de tienda (config, URL Cloudinary, placeholder)"
```

---

### Task 2: UI y interacción — tarjeta de detalle en el panel + click en pin/fila

**Files:**
- Modify: `C:\xampp\htdocs\plataforma_20\informes\geo.php`:
  - HTML del panel `#geo-list` (~línea 27)
  - CSS (`<style>`, ~líneas 40-46)
  - `renderMapa` (~líneas 108-123) y nuevas funciones en el `<script>`

**Interfaces:**
- Consumes: `fotoTiendaURL`, `FOTO_PLACEHOLDER` (Task 1); helpers existentes `esc`, `fmtMoney`, `nf`.
- Produces:
  - `function showTiendaDetalle(t) -> void` — pinta `#geo-detalle` con la foto/placeholder + datos.
  - Variable de módulo `let tiendasActuales = []` — última lista de tiendas (para resolver clicks de fila).

- [ ] **Step 1: Agregar el contenedor `#geo-detalle` al panel**

En `informes/geo.php`, línea ~27, cambiar:

```html
  <div id="geo-list" class="geo-box geo-list"><h4>Descripción Tiendas</h4><div id="geo-list-body"></div></div>
```

por:

```html
  <div id="geo-list" class="geo-box geo-list"><h4>Descripción Tiendas</h4><div id="geo-detalle" class="geo-detalle"><div class="geo-detalle-hint">Haz clic en una tienda para ver su foto.</div></div><div id="geo-list-body"></div></div>
```

- [ ] **Step 2: CSS — ensanchar el panel y estilar la tarjeta**

En el `<style>` de `geo.php`, cambiar la regla de `.geo-list` (línea ~40) de `width: 240px;`
a `width: 280px;`:

```css
  #page-georreferenciacion .geo-list { right: 14px; bottom: 22px; width: 280px; max-height: 60%; overflow: auto; padding: 8px 12px; }
```

(También se sube `max-height` de `46%` a `60%` para que la foto + listado quepan.)

Y agregar, dentro del mismo `<style>` (antes de `</style>`):

```css
  #page-georreferenciacion .geo-detalle { margin: 0 0 8px; }
  #page-georreferenciacion .geo-detalle-hint { font-size: 11px; color: var(--text-light); text-align: center; padding: 10px 4px; }
  #page-georreferenciacion .geo-foto { width: 100%; height: 150px; object-fit: cover; border-radius: 6px; display: block; background: #eef2f7; }
  #page-georreferenciacion .geo-detalle-info { margin-top: 6px; font-size: 12px; line-height: 1.35; }
  #page-georreferenciacion .geo-detalle-info .nom { font-weight: 700; color: var(--primary); }
  #page-georreferenciacion .geo-list .row { padding: 1px 0; white-space: nowrap; cursor: pointer; }
  #page-georreferenciacion .geo-list .row:hover { color: var(--accent); }
```

(La última regla `.row` reemplaza/complementa la existente de línea ~42 agregando `cursor:pointer`
y hover — si prefieres, edita la regla existente en vez de duplicarla; el resultado debe ser una
sola definición con `cursor:pointer;` y el `:hover`.)

- [ ] **Step 3: `showTiendaDetalle(t)`**

En el `<script>`, agregar esta función (por ejemplo justo antes de `renderMapa`, ~línea 108):

```javascript
  function showTiendaDetalle(t){
    const cont = document.getElementById('geo-detalle');
    if(!cont || !t) return;
    const url = fotoTiendaURL(t.cod);
    const src = url || FOTO_PLACEHOLDER;
    cont.innerHTML =
      '<img class="geo-foto" alt="Foto tienda" src="'+esc(src)+'" '+
        'onerror="this.onerror=null;this.src=\''+FOTO_PLACEHOLDER+'\'">'+
      '<div class="geo-detalle-info">'+
        '<div class="nom">'+esc(t.nombre||'')+'</div>'+
        '<div>Cod: '+esc(t.cod)+(t.ciudad?' · '+esc(t.ciudad):'')+'</div>'+
        '<div>Ventas: '+fmtMoney(t.valor)+'</div>'+
        '<div>Unidades: '+nf(t.unidades)+'</div>'+
      '</div>';
  }
```

Nota: `FOTO_PLACEHOLDER` es un data-URI SVG que NO contiene comillas dobles sin escapar en su
parte codificada (viene de `encodeURIComponent`), por lo que insertarlo dentro del atributo
`onerror='...this.src="..."'` con comillas simples es seguro. `src` sí se pasa por `esc`.

- [ ] **Step 4: Guardar la lista de tiendas y cablear click en pin + fila**

En `renderMapa` (~líneas 108-123), hacer tres cambios:

(a) Al inicio de la función, tras `const tiendas = d.tiendas||[];`, guardar la lista en la
variable de módulo. Primero declarar la variable de módulo: junto a `let filtrosInit = false, ...`
(~línea 51) agregar `tiendasActuales`:

```javascript
  let filtrosInit = false, comboCatalogo = [], map = null, markersLayer = null, tiendasActuales = [];
```

y dentro de `renderMapa`, tras `const tiendas = d.tiendas||[];` agregar:

```javascript
    tiendasActuales = tiendas;
```

(b) Al crear cada marcador, agregar el handler de click (dentro del `tiendas.forEach(t=>{ ... })`,
después de `m.bindTooltip(...)`):

```javascript
      m.on('click', () => showTiendaDetalle(t));
```

(c) Renderizar las filas del listado con `data-cod` (para resolver el click). Cambiar:

```javascript
    const body = (tiendas.slice().sort((a,b)=>String(a.cod).localeCompare(String(b.cod)))
      .map(t=>'<div class="row">'+esc(t.cod)+' - '+esc(t.nombre)+'</div>').join('')) || '<div class="row">Sin tiendas.</div>';
    document.getElementById('geo-list-body').innerHTML = body;
```

por:

```javascript
    const body = (tiendas.slice().sort((a,b)=>String(a.cod).localeCompare(String(b.cod)))
      .map(t=>'<div class="row" data-cod="'+esc(t.cod)+'">'+esc(t.cod)+' - '+esc(t.nombre)+'</div>').join('')) || '<div class="row">Sin tiendas.</div>';
    document.getElementById('geo-list-body').innerHTML = body;
```

- [ ] **Step 5: Delegación de click en el listado (una sola vez)**

Las filas se regeneran con `innerHTML` en cada `renderMapa`, así que se usa delegación en el
contenedor padre `#geo-list-body` (que no se reemplaza), atada una sola vez. Agregar una
variable de módulo `let detalleWired = false;` junto a las otras (~línea 51), y al final de
`renderMapa` (después de asignar `geo-list-body`) agregar:

```javascript
    if(!detalleWired){
      const lb = document.getElementById('geo-list-body');
      if(lb){ lb.addEventListener('click', (ev)=>{
        const row = ev.target.closest('.row[data-cod]'); if(!row) return;
        const cod = row.getAttribute('data-cod');
        const t = tiendasActuales.find(x => String(x.cod) === String(cod));
        if(t) showTiendaDetalle(t);
      }); detalleWired = true; }
    }
```

- [ ] **Step 6: Verificación estática + navegador**

1. PHP lint: `"/c/xampp/php/php.exe" -l informes/geo.php` → "No syntax errors detected"
   (ignorar warnings preexistentes). Si no está, `php -l`.
2. `grep -n "showTiendaDetalle\|geo-detalle\|tiendasActuales\|data-cod" informes/geo.php` → confirmar
   que la markup, la función, la variable y los cablearon están presentes.
3. Navegador (geo cargado, `CLOUDINARY_CLOUD` vacío): al hacer **click en un pin** → `#geo-detalle`
   muestra el **placeholder** + nombre/cod/ciudad/Ventas/Unidades correctos de esa tienda. Al hacer
   **click en una fila** del listado → mismo detalle. El **hover** sobre el pin sigue mostrando el
   tooltip como antes (sin regresión).
4. (Opcional, prueba real de foto) Cambiar temporalmente `CLOUDINARY_CLOUD` a un cloud con una foto
   `tiendas/<cod>` subida → esa tienda muestra la foto; las demás, placeholder. Revertir a `''`.

- [ ] **Step 7: Commit**

```bash
git add informes/geo.php
git commit -m "feat(geo): tarjeta de foto/detalle de tienda en panel lateral (click pin y fila)"
```

---

## Self-Review

**Spec coverage:**
- Config `CLOUDINARY_CLOUD` + convención `tiendas/<cod>` → Task 1. ✓
- Constructor de URL con `f_auto,q_auto,c_fill,w_600,h_400` → Task 1. ✓
- Placeholder SVG inline data-URI → Task 1. ✓
- Tarjeta `#geo-detalle` en `#geo-list` con foto + datos, panel ensanchado → Task 2 Steps 1-2. ✓
- `showTiendaDetalle` con foto/placeholder y `onerror` → Task 2 Step 3. ✓
- Click en pin (hover intacto) → Task 2 Step 4b. ✓
- Filas del listado clicables (delegación) → Task 2 Steps 4c + 5. ✓
- Placeholder cuando cloud vacío o 404 → `fotoTiendaURL` null + `onerror` (Tasks 1 y 2 Step 3). ✓
- Escape de texto dinámico con `esc` → Task 2 Step 3/4. ✓
- Sin cambios en API/BD/auth/subida → ninguna task los toca. ✓

**Placeholder scan:** sin TBD/TODO; todo el código está completo. `CLOUDINARY_CLOUD=''` es un valor
de configuración intencional (documentado), no un placeholder de plan.

**Type consistency:** `fotoTiendaURL(cod)->string|null` y `FOTO_PLACEHOLDER` (string) definidos en
Task 1 y consumidos con esas firmas en Task 2 (`showTiendaDetalle`). `tiendasActuales`,
`detalleWired`, `showTiendaDetalle`, `#geo-detalle`, `#geo-list-body`, clase `.row`/`data-cod`
son coherentes entre Task 2 Steps 1-5. Helpers `esc`/`fmtMoney`/`nf` existen en geo.php.

## Notas de ejecución

- Trabajar en una rama nueva (ej. `feature/fotos-tiendas-geo`); merge + push al final con visto bueno de Rafael, luego copia al servidor.
- No hay framework de tests JS: verificación manual en navegador (XAMPP + sesión de proveedor).
- El feature se despliega con placeholders; se "encienden" las fotos cuando Rafael llene `CLOUDINARY_CLOUD` y suba `tiendas/<cod>`.
