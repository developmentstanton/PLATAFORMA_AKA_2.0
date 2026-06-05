# G00 — Preview de imagen al hacer hover en "Resumen Ventas Por Negocio"

**Fecha:** 2026-06-05
**Informe:** G00 — Dashboard de Ventas, pestaña **Ventas Por Productos**, tabla **Resumen Ventas Por Negocio**
**Rama:** `feat/g00-tiendas-resumen`

## Objetivo

Al pasar el mouse sobre una fila de **negocio** (`REFERENCIA-COLOR`) en la tabla "Resumen
Ventas Por Negocio", mostrar un preview flotante con la foto del zapato. Solo frontend.

## Contexto

- La tabla la renderiza `renderTablaArbol('g00-tabla-negocio', data.negocios, …, {col1, prefix:'neg'})`
  con `rowArbol(r, opts, kind, meta)` (kinds: `parent`/`child`/`leaf`/`total`) en `informes/g00.php`.
  Las filas de negocio son `kind === 'parent'` (o `'leaf'` si un negocio no tuviera tallas);
  su `r.label` es el `REFERENCIA-COLOR` (p. ej. `434540BLU-AZC`).
- El renderer `renderTablaArbol` es **genérico** (lo usan también Categoría y Género). El
  hover de imagen aplica **solo** a la tabla de Negocio → se activa con una opción del
  renderer, no para las otras dos.
- La página corre en `http` (localhost/XAMPP); el servidor de imágenes también es `http`.

## Servidor de imágenes (dato de Rafael)

- **Base:** `http://bi.stanton.com.co:81/fotosPBI/`
- **Nombre de archivo:** igual al negocio (`REFERENCIA-COLOR`), sin transformación.
- **Extensión:** puede ser `.jpg` **o** `.png` (todas las fotos en esa misma carpeta).
- URL resultante: `http://bi.stanton.com.co:81/fotosPBI/` + `<label>` + (`.jpg` | `.png`).

## Comportamiento

- **Filas con preview:** SOLO filas de negocio (padre/leaf) de la tabla de Negocio. Las
  filas de talla (`child`), la fila Total, y las tablas de Categoría/Género **no** lo tienen.
- **Hover:** al `mouseover` sobre una fila de negocio aparece un preview flotante (tarjeta
  blanca, borde + sombra sutil) posicionado junto al cursor; sigue al cursor con `mousemove`;
  desaparece en `mouseout` al salir de la fila.
- **Carga de extensión:** se arma la URL con `.jpg`. Si la imagen falla (`onerror`), se
  reintenta con `.png`. Si `.png` también falla, se **oculta** el preview (sin ícono de
  imagen rota, sin texto de error).
- **Lazy:** la imagen se descarga solo al hacer hover (no al renderizar la tabla).
- **Tamaño:** ~160px de ancho, alto automático (`height:auto`), con un alto máximo
  razonable (p. ej. 200px) para no desbordar.
- **Nombre/URL:** `encodeURI` sobre la URL completa (o `encodeURIComponent` sobre el label)
  para tolerar caracteres no-ASCII; el `-`, letras y dígitos no se ven afectados.
- **`(Sin dato)`:** un negocio sin ref/color (`label === '(Sin dato)'`) no debe disparar
  una petición de imagen (no hay foto posible) → no se muestra preview.

## Implementación (`informes/g00.php`, solo frontend)

1. **Marcar las filas:** en `rowArbol`, cuando `opts.imgHover` esté activo y la fila sea de
   negocio (`kind === 'parent'` o `'leaf'`) con `label !== '(Sin dato)'`, agregar
   `data-negimg="<label escapado>"` a la fila.
2. **Activar solo en Negocio:** en `loadProductos`, pasar `imgHover:true` en las `opts` de la
   llamada de `g00-tabla-negocio` (las de Categoría/Género NO lo pasan).
3. **Tooltip único:** crear (una sola vez, perezoso) un `<div id="g00-img-pop">` con un `<img>`
   dentro, `position:fixed; display:none; z-index alto; pointer-events:none`.
4. **Handlers delegados** en el contenedor de la tabla de Negocio (`.g00-scroll-neg` o el
   `<table id="g00-tabla-negocio">`):
   - `mouseover`: si el target está dentro de una fila con `data-negimg`, setear
     `img.src = base + encodeURIComponent(label) + '.jpg'`, resetear el flag de fallback,
     mostrar el pop y posicionarlo junto al cursor.
   - `mousemove`: reposicionar el pop junto al cursor (con offset; voltear si se sale del
     viewport por la derecha/abajo).
   - `mouseout`: si se sale de la fila, ocultar el pop.
   - `img.onerror`: primer fallo → cambiar a `.png`; segundo fallo → ocultar el pop.
5. **CSS** `#g00-img-pop`: tarjeta blanca, `border:1px solid var(--border)`, `border-radius`,
   `box-shadow`, `padding:4px`; `img { width:160px; height:auto; max-height:200px; display:block; }`.

No hay cambios de backend ni de payload.

## Verificación

- `php -l informes/g00.php` limpio.
- **E2E navegador (Rafael):** hover sobre un negocio con foto muestra la imagen correcta;
  hover sobre uno cuya foto es `.png` también funciona (fallback); negocio sin foto no
  muestra nada ni deja ícono roto; el preview sigue al cursor y desaparece al salir; las
  tablas de Categoría/Género NO muestran preview; las filas de talla/Total tampoco.

## Fuera de alcance

- No se cachea ni se pre-carga ninguna imagen.
- No se valida la existencia de la foto en backend (se resuelve en cliente con el fallback).
- No aplica a Categoría/Género ni a otras pestañas.
- Servir las fotos por `https` (necesario solo si el portal pasa a `https`) queda anotado
  como follow-up de despliegue, no se implementa aquí.
