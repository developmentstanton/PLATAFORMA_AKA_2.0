# Sub-proyecto B — Fotos de tiendas en georeferenciación

**Fecha:** 2026-07-03
**Módulo:** `plataforma_20` / informes / geo
**Estado:** diseño aprobado, pendiente plan de implementación

## Contexto

El informe de Georeferenciación (`informes/geo.php`) es PHP + JavaScript vanilla (IIFE) con
un mapa **Leaflet**. Cada tienda se dibuja como un `L.circleMarker` con un **tooltip al
hover** (`bindTooltip`, sticky) que muestra `cod - nombre`, Ventas y Unidades. Hay un panel
lateral flotante `#geo-list` ("Descripción Tiendas", recuadro `.geo-box` abajo-derecha,
240px, `max-height:46%`, scroll) que lista `cod - nombre` de todas las tiendas.

Los datos vienen de `api/informe_geo.php` (tab=data) como `tiendas [{cia,cod,nombre,grupo,
ciudad,lat,lng,valor,unidades}]`. El `cod` es el código de Bodegas.

## Decisiones de producto (acordadas con Rafael 2026-07-03)

- **La subida NO va por plataforma_20.** Rafael (u otro admin) sube las fotos
  **directamente a Cloudinary** desde su panel. Plataforma solo **consulta y muestra**. Por
  tanto B **no** incluye formulario de subida, ni auth de subida, ni almacenamiento, ni
  credenciales/SDK de Cloudinary.
- **Resolución foto→tienda por convención de nombre:** cada foto se sube a la carpeta
  `tiendas/` en Cloudinary con **public_id = el `cod` de la tienda** (ej. `tiendas/003`). La
  plataforma arma la URL de entrega directamente desde el `cod`. Sin BD ni mapeo.
- **Sin foto → placeholder genérico** (consistente para todas las tiendas sin foto).
- **`cloud_name` como configuración:** Rafael aún no tiene la cuenta/carpeta; se deja una
  constante a llenar después. Con la constante vacía, todas las tiendas muestran placeholder.
- **Interacción:** el hover sigue mostrando el tooltip de datos (sin cambios); al hacer
  **click** en un pin, la foto grande + datos aparecen en el panel lateral `#geo-list`. Las
  filas del listado también son clicables para el mismo detalle.

## Objetivo

Mostrar la foto del centro comercial/tienda (subida externamente a Cloudinary) en el panel
lateral de georeferenciación al seleccionar una tienda, con placeholder cuando no hay foto.

## Alcance

**Solo `informes/geo.php`** (frontend). Sin cambios en `api/informe_geo.php`, sin BD, sin
nuevas dependencias. Las imágenes públicas de Cloudinary se sirven por URL directa.

## Arquitectura

### 1. Configuración

Al inicio del script de geo, dos constantes:

```javascript
// Nombre de la cuenta Cloudinary (llenar cuando esté disponible). Vacío => solo placeholder.
const CLOUDINARY_CLOUD = '';
const FOTO_FOLDER = 'tiendas';   // convención acordada: tiendas/<cod>
```

Cuando `CLOUDINARY_CLOUD` está vacío, no se construyen URLs ni se hacen intentos de red: se
muestra el placeholder directamente.

### 2. Constructor de URL

```javascript
function fotoTiendaURL(cod) {
  if (!CLOUDINARY_CLOUD || cod == null || cod === '') return null;
  return 'https://res.cloudinary.com/' + CLOUDINARY_CLOUD +
         '/image/upload/f_auto,q_auto,c_fill,w_600,h_400/' +
         FOTO_FOLDER + '/' + encodeURIComponent(cod);
}
```

- `f_auto,q_auto`: Cloudinary elige formato/calidad óptimos — no hay que conocer la extensión
  subida (jpg/png/webp).
- `c_fill,w_600,h_400`: recorte a tamaño consistente para el panel.

### 3. Placeholder genérico

Un **SVG inline como data-URI** (silueta de tienda + texto "Sin foto"), guardado en una
constante `FOTO_PLACEHOLDER`. No depende de archivos ni CDN externo (seguro para CSP),
siempre disponible. Se usa cuando `fotoTiendaURL` devuelve `null` o cuando el `<img>`
dispara `onerror` (foto no subida → 404 en Cloudinary).

### 4. Tarjeta de detalle en `#geo-list`

- Se agrega un bloque `#geo-detalle` **arriba** del `#geo-list-body` (dentro de `.geo-list`):
  una `<img class="geo-foto">` (ancho del panel, ~160px alto, `object-fit:cover`) seguida de
  los datos de la tienda seleccionada: **nombre, cod, Ventas, Unidades, ciudad**.
- Estado inicial (sin selección): texto guía "Haz clic en una tienda para ver su foto".
- El panel `.geo-list` se ensancha de `240px` a `~280px` para que la foto se vea decente; el
  listado de tiendas permanece debajo, con scroll.

### 5. Interacción

- **Hover:** el `bindTooltip` existente NO cambia (sigue mostrando `cod-nombre`/Ventas/Unidades).
- **Click en pin:** en `renderMapa`, a cada `circleMarker` se le agrega
  `m.on('click', () => showTiendaDetalle(t))`.
- **Click en fila del listado:** cada fila se renderiza con `data-cod` y un handler que llama
  a `showTiendaDetalle(t)` para la tienda correspondiente (se resuelve `t` desde la lista de
  tiendas del último `renderMapa`, guardada en una variable de módulo).

### 6. `showTiendaDetalle(t)`

Rellena `#geo-detalle`:
- `const url = fotoTiendaURL(t.cod);`
- `<img class="geo-foto" src="url || FOTO_PLACEHOLDER" onerror="this.onerror=null;this.src=FOTO_PLACEHOLDER">`
  (usar el placeholder tanto si `url` es `null` como si la imagen falla al cargar; limpiar
  `onerror` tras el primer fallo para evitar bucle).
- Debajo: `nombre`, `cod`, `Ventas` (money), `Unidades`, `ciudad`, con el mismo formateo que
  el tooltip (`fmtMoney`, `nf`, `esc`).

## Manejo de errores / bordes

- `CLOUDINARY_CLOUD` vacío → `fotoTiendaURL` devuelve `null` → placeholder, sin red.
- Foto aún no subida (404 en Cloudinary) → `onerror` → placeholder. Cada tienda "se enciende"
  al subir su foto, sin tocar código.
- `cod` con caracteres especiales → `encodeURIComponent`.
- Tienda sin `cod` → placeholder.
- Todos los textos dinámicos escapados con el helper `esc` existente.

## Rollout

Se despliega ya, con placeholders en todas las tiendas. Cuando Rafael llene
`CLOUDINARY_CLOUD` y suba fotos con la convención `tiendas/<cod>`, esas tiendas muestran su
foto automáticamente. Requiere un segundo despliegue trivial solo para llenar la constante
(o llenarla directo en prod).

## Pruebas

Verificación manual en navegador (el módulo no tiene framework de tests JS):

1. Con `CLOUDINARY_CLOUD` vacío: entrar a geo, hacer click en un pin y en una fila del
   listado → `#geo-detalle` muestra el placeholder + datos correctos de esa tienda.
2. Con un `CLOUDINARY_CLOUD` real + una foto de prueba subida como `tiendas/<cod>` de una
   tienda concreta: al hacer click en esa tienda se ve la foto; en las demás, placeholder.
3. Confirmar que el hover sigue mostrando el tooltip de datos como antes (sin regresión).

## Fuera de alcance

- Subida de fotos (se hace en Cloudinary, externamente).
- Cualquier cambio en `api/informe_geo.php`, BD, o auth.
- Sub-proyecto C (rendimiento de consultas).
