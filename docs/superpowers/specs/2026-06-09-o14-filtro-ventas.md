# O14 — Filtro de fechas que afecta únicamente a Ventas

**Fecha:** 2026-06-09
**Rama destino:** `feat/o14-topbar-g00-style` (continúa el trabajo de O14 estilo G00)
**Informe:** O14 — Siembra / Stock (`api/informe_o14.php`, `informes/o14.php`, topbar en `dashboard.php`)

## Problema / motivación

O14 es una foto del **estado actual** (siembra, disponible, hold) más **ventas** como medida informativa. Tras el rediseño del 2026-06-05 se quitó el filtro Desde/Hasta y el rango de ventas quedó fijo en **YTD del año en curso** (`desde = año-01-01`).

Caso que lo destapó (Rafael, 2026-06-09): el negocio **`555005911A-NEG`** (proveedor BH BRANDS SAS) en la tienda **`245 — AKA CENTRO TUNJA`** no aparece en O14C, pese a tener un movimiento de venta. Diagnóstico contra BD: la única venta en 245 es del **2025-07-19** (está en `Ventas_Detal_Acum_PBI`); en 2026 esa ref solo vendió en las bodegas 547 y 602. Como O14 usa YTD y solo cruza `Ventas_Detal_Acum_PBI` cuando el rango toca 2025, la venta de 2025 queda fuera → la fila no aparece.

**Decisión de negocio:** O14 sigue siendo escenario del año actual para siembra/stock, **pero ventas debe acumular histórico** cruzando las dos tablas (`Ventas_Detal_PBI` año actual + `Ventas_Detal_Acum_PBI` histórico), **considerando por defecto desde el 1 de enero de 2025**. Debe existir un **filtro de fecha que afecte únicamente a ventas**.

## Alcance

- Desacoplar la ventana temporal de **Ventas** del resto de medidas.
- Cambiar el **default** del rango de ventas a `2025-01-01 → hoy`.
- Exponer un control **Desde / Hasta** en la UI, dedicado a ventas, etiquetado claramente.

**Fuera de alcance:** cambiar la semántica de siembra/disponible/hold (siguen siendo foto actual, sin fecha); persistencia (sub-proyecto 3); optimización del escaneo de `Acum` (ver Tradeoff).

## Comportamiento

### Semántica de fechas
- **Siembra / Disponible / Hold:** foto actual, SIN filtro de fecha (ya es así hoy: los CTE `s`/`d`/`h` de `#base` no filtran `FECHA`). **No cambian.**
- **Ventas:** se calcula sobre el rango `[desde, hasta]`, que **solo afecta a ventas** (ya es así en O14: el rango solo entra al CTE `v`). El cambio es el **default**: `desde = 2025-01-01`, `hasta = hoy`.
- Con `desde ≤ 2025-12-31`, la lógica existente `$inclAcum` une `Ventas_Detal_Acum_PBI` además de `Ventas_Detal_PBI`. Cada tabla filtra su propio `FECHA BETWEEN ? AND ?`; no hay solape (Acum ≤2025-12-31, Detal ≥2026) → **sin doble conteo**. Si el usuario acota el rango a solo 2026, `$inclAcum` queda en false y Acum no se escanea (la optimización sigue vigente para rangos angostos).

### Universo de filas
Ventas alimenta el universo `llaves` (UNION con `v`), así que una tienda/negocio con **solo venta histórica** (sin siembra/disp/hold actual) **aparece como fila**, con esas medidas en 0 y su número de Ventas. → 245 aparecerá bajo grupo `AKA` con Ventas=1 cuando el rango incluya 2025-07-19. (Aprobado por Rafael; efecto colateral consciente: la matriz crece con tiendas/negocios que vendieron en 2025 aunque hoy no tengan inventario.)

### Medidas derivadas y KPIs
- **Faltante / Sobrante / Disp+Hold:** se derivan de siembra/disp/hold (stock actual vs siembra), **NO de ventas** → **no se alteran** al mover el rango. Una fila que aparece solo por venta histórica tiene faltante=0 y sobrante=0.
- **KPIs afectados por el rango:** `ventas`, `tiendas_con_venta`, y los conteos de `negocios` / `negocios_con_siembra` cambian porque el universo crece. Los KPIs de siembra/stock no cambian de valor por el rango (pero sus conteos `negocios` totales sí reflejan el universo ampliado).
- **Reco:** no usa ventas (`$wantVentas = ($tab !== 'reco')`); el rango no lo afecta. Sin cambios.

## UI

- Control **Desde / Hasta** ubicado en la **columna izquierda del topbar** de O14 (`#topbarDates`, hoy un spacer vacío en el grid `topbar--o14` de `1fr auto 1fr`), reaprovechando el mismo espacio donde G00 muestra su tablita de fechas.
- Rotulado claro de que solo afecta ventas: una etiqueta **"Ventas"** encima/junto a los dos campos.
- Default visible: `2025-01-01` / hoy.
- Se aplica con el botón **Actualizar** existente del topbar (`#topbarO14Refresh` → `o14Load()`), igual que G00. No hace falta auto-recargar al cambiar la fecha.
- La selección persiste mientras se navega entre pestañas de O14 (los inputs viven en `#topbarDates`, que solo toca `o14OnEnter`); al salir y volver a O14 puede resetear a default (aceptable).

## Implementación (resumen; el detalle va en el plan)

### Backend `api/informe_o14.php`
- Cambiar el default: `$desde = $_GET['desde'] ?? '2025-01-01';` (antes `date('Y-01-01')`). `$hasta` se mantiene (`?? date('Y-m-d')`).
- Se mantienen los nombres de parámetro `desde`/`hasta` (en O14 ya son exclusivos de ventas). El resto de la maquinaria (`$inclAcum`, CTE `v`, `llaves`, `#base.ventas`) **no cambia**.
- Comentario aclaratorio de que `desde`/`hasta` son la ventana de **ventas** únicamente.

### Frontend `informes/o14.php`
- `o14OnEnter`: en vez de `td.innerHTML = ''`, **inyectar** en `#topbarDates` los inputs Desde/Hasta (`#o14-vdesde`, `#o14-vhasta`) con defaults `2025-01-01` / hoy y una etiqueta "Ventas". Guardar para no pisar la selección si ya existen.
- `buildParams`: leer `#o14-vdesde` / `#o14-vhasta` (con fallback a `2025-01-01` / hoy) y enviarlos como los parámetros `desde` / `hasta` (los nombres que el backend ya espera), en vez del YTD fijo.

### `dashboard.php`
- Estilos para los inputs de fecha de ventas dentro del topbar (reusar/extender `.topbar-dates`). `showPage` ya oculta `#topbarDates` al salir y `o14OnEnter` lo reactiva.

## Verificación

- **Backend (endpoint real, proveedor BH BRANDS SAS):**
  - Con rango por defecto (`2025-01-01..hoy`), tab `c`: el negocio `555005911A-NEG` **aparece** bajo grupo `AKA`, tienda `245`, con `ventas ≥ 1`, y `siembra=disponible=hold=0` en esa fila.
  - Con rango `2026-01-01..hoy`: el negocio **no aparece** en 245 (control negativo).
  - **Invariante:** `faltante − sobrante == siembra − (disponible + hold)` por talla, idéntico con ambos rangos (ventas no altera el balance).
- **Regresión:** motor `tests/o14_recomendador_test.php` 27/27; `tests/o14_filtros_test.php` verde; smoke G00 sin cambios.
- `php -l` limpio en los archivos tocados.
- **E2E navegador (Rafael):** control Desde/Hasta a la izquierda del título, default 2025-01-01/hoy, etiquetado como ventas; al Actualizar, 245 aparece con su venta 2025; siembra/stock/faltante intactos; acotar a 2026 lo oculta de 245.

## Tradeoff aceptado

Con el default llegando a 2025, **cada carga de O14 escanea `Ventas_Detal_Acum_PBI` (~2.7M filas, sin índice, ≈10-15s)**. Antes, en YTD, se evitaba. Es inherente al requerimiento de histórico por defecto. Mitigaciones futuras (no en este alcance): índice/columnstore en la fact, cache de ventas por proveedor-día, o materializar el agregado de ventas. El usuario puede acotar el rango a 2026 para cargas rápidas.

## Puntos menores / decisiones

- Default `2025-01-01` es **fijo** (no relativo al año anterior); si en el futuro se quiere "desde el año pasado" dinámico, se ajusta. La tabla `Acum` cubre desde 2019, así que el usuario puede pedir más atrás vía el filtro.
