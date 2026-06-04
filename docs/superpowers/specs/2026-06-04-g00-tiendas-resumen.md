# G00 — Pestaña "Detalle Tiendas": KPIs + Resumen Ventas Por Tienda (colapsable a Ref-Color)

**Fecha:** 2026-06-04 · **Informe:** G00 · **Rama:** `feat/g00-tiendas-resumen`

## Objetivo
Rediseñar la pestaña **Detalle Tiendas** del G00 para que, bajo la barra de filtros (sin cambios), muestre los 3 KPIs (Tiendas/$Prom/MB, como en Detal) y una tabla **Resumen Ventas Por Tienda**: fila por tienda y, colapsadas por defecto, las filas de **negocio = `REFERENCIA-COLOR`** (ej. `KS3280-CAF`) vendidas en esa tienda. Reemplaza el contenido actual (Mapa de Tiendas, Top 10, Detalle Completo).

## Alcance
- **Reemplazar** todo el contenido del panel `#g00-panel-tiendas` (quitar treemap, Top 10, Detalle Completo y su JS).
- 3 KPIs (ids propios) + tabla colapsable.
- Columnas **iguales a la tabla "Por Marca"**: Uds ant/act + Dif Q/%Q, $ ant/act + Dif $/%$, MB, $Prom ant/act.
- Filtros sin cambios (barra compartida; mismos `$FILTROS_MULTI`).

## Backend — `api/informe_g00.php`, `tab=tiendas` reescrito
Una sola query con `GROUPING SETS ((), (BODEGA), (BODEGA, REFERENCIA, COLOR))` sobre el universo filtrado:
- CTE `vt`: ventas ⋈ #refs ⋈ Bodegas (`b.CIA=7`), con `$filtroExtra`, exponiendo BODEGA, NOMBRE, GRUPO, REFERENCIA, COLOR. Filtro de fecha OR (act ∪ ant) como en Detal.
- `GROUPING_ID(BODEGA, REFERENCIA, COLOR)`: **7=()** (KPIs grand total), **3=(BODEGA)** (tienda), **0=(BODEGA,REF,COLOR)** (hijo).
- Métricas por SUM(CASE) act/ant para VALOR y CANTIDAD + `AVG(CASE ... CANTIDAD>0 THEN CAST(MARGEN AS float))` (igual que Detal).
- **Orden de params** idéntico al patrón del consolidado de Detal: `[gmin,gmax,gmin,gmax]` (CTE) + `[desdeAct,hastaAct,desdeAnt,hastaAnt]` (OR-filter vt) + `$paramsExtra` + `[8 fechas de val/ups]` + `[2 fechas de margen]`. (Sin reordenar — los filtros entran por `$paramsExtra`, evitando el bug 8120.)

PHP: demux por gid → `kpis` (del gid=7: ticket_prom=val/ups, margen_prom; tiendas_actual=conteo de tiendas con val_act>0), `tiendas[]` (gid=3) con `children[]` (gid=0, `negocio = REFERENCIA + '-' + COLOR`). Saltar tiendas/hijos con val_act==0 && val_ant==0. $Prom = val/ups por fila. Orden por val_act desc (tiendas y children). Salida `{ok, kpis, tiendas:[{cod,nombre,grupo, val_act,val_ant,ups_act,ups_ant,margen, children:[{negocio, ...}]}]}`.

## Frontend — `informes/g00.php`
- Panel `#g00-panel-tiendas`: 3 KPIs (ids `g00t-kpi-tiendas`/`g00t-kpi-ticket`/`g00t-kpi-margen` + años `g00t-kpi-anio-1/2`) + card "Resumen Ventas Por Tienda" con `<table id="g00-tabla-tienda" class="disp-table">`.
- `loadTiendas` reescrito: fetch → render KPIs (en ids del tab) + `renderTablaTienda(tiendas)`.
- `renderTablaTienda`: encabezado igual que "Por Marca"; por tienda una fila padre (chevron ▸, clic despliega) y sus children (negocio) ocultos por defecto (`display:none`, `data-tparent`), fila Total al final. Reusa el estilo `g00-marca-row`/`g00-caret`/`g00-tipo`. Toggle `g00ToggleTienda(idx, el)`.
- Quitar: markup del treemap/Top10/Detalle Completo; funciones `renderTreemapTiendas` y el render de Top10/Detalle; `charts.treemapTiendas`.

## Testing
- `tests/g00_tiendas_test.php` (nuevo, subprocesa el endpoint real): `tab=tiendas` da `ok=true`; hay `kpis` y `tiendas[]` no vacío; cada tienda tiene `children[]`; `negocio` con formato `REF-COLOR`; **invariante**: por tienda `val_act ≈ Σ children.val_act` (y ups). También una permutación con un filtro (p. ej. `color[]`) que siga dando `ok=true`.
- `php -l`. Verificación en navegador (Rafael): KPIs + tabla colapsable; clic en tienda despliega sus ref-color; Total; otros tabs intactos.

## Decisiones / notas
- KPIs computados por el endpoint de tiendas (reflejan filtros, también al cambiar filtros en este tab).
- `negocio = REFERENCIA + '-' + COLOR` (columna COLOR de los hechos).
- Default colapsado (como la tabla "Por Marca").
- Proveedor grande → (tienda × ref-color) puede traer muchas filas (payload); para proveedores normales es liviano. Follow-up si se vuelve pesado (lazy-load por tienda).
