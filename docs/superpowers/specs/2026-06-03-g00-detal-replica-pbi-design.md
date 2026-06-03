# Diseño — G00 Detal: réplica Power BI (Inc 1)

**Fecha:** 2026-06-03
**Autor:** Rafael Lancheros + Claude
**Estado:** Aprobado para plan de implementación
**Proyecto:** plataforma_20 — módulo informes
**Contexto:** ver [[project_plataforma20_informes]] (modelo de datos, gotchas, arquitectura)

## Objetivo

Refinar el informe **G00 (Dashboard de Ventas)** para que su sección **Detal** se vea como el informe equivalente de Power BI. Este es el **Incremento 1** de una refinada mayor del G00; la barra de 13 filtros en cascada/multi-select es el **Incremento 2** (otro spec) y NO entra aquí.

El trabajo del G00 se hará sección por sección. Detal es la primera.

## Alcance de Inc 1

### A. Cosmética global (quitar códigos de los botones)
- Botón del menú lateral (`dashboard.php`): `G00 — Ventas` → **`Ventas`**.
- Título de página (`dashboard.php`, mapa de títulos): `INFORME G00 — DASHBOARD DE VENTAS` → **`DASHBOARD DE VENTAS`**.
- El **badge morado "G00"** del header del informe (`informes/g00.php`) **se mantiene** (no es un botón).

### B. Encabezado de la sección Detal
- Se conserva la cabecera actual (proveedor + período).
- **Franja de 3 KPIs** estilo Power BI, en este orden: `Tiendas 2026`, `$ Prom 2026`, `MB`. (Reemplaza las 5 tarjetas KPI actuales.)
- **Toggle de 3 botones**: `Día a Día`, `Retail`, `Same`.
  - `Día a Día` y `Retail`: visibles pero **inertes** (placeholders sin funcionalidad).
  - `Same`: **activo y por defecto**. Toda la sección Detal se calcula con la lógica Same (ver D).
- Las etiquetas de año (2025/2026) son **dinámicas**: año actual = año de `hasta`; año anterior = actual − 1.

### C. Las 3 tablas comparativas (reemplazan KPIs viejos + gráficas ECharts del Detal)

Convención de columnas: `Q` = unidades (CANTIDAD), `$` = valor (VALOR), `Dif` = actual − anterior, `%` = (actual − anterior)/anterior, `$Prom` = $ / Q (ticket por par), `MB` = margen %.

**Tabla 1 — Resumen Ventas Por Grupo Tiendas** (una fila por GRUPO + fila Total):
`GRUPO · 2025(Q) · 2026(Q) · Dif Q · %Q · $2025 · $2026 · Dif $ · %$ · MB · $Prom 2025 · $Prom 2026 · %Prom · Tiendas 2025 · Tiendas 2026 · ≠Tien 26-25`

**Tabla 2 — Resumen Ventas Por Marca / Tipo** (agrupación de **2 niveles**: fila por MARCA y, anidadas, subfilas por TIPO; + fila Total):
`MARCA/TIPO · 2025(Q) · 2026(Q) · Dif Q · %Q · $2025 · $2026 · Dif $ · %$ · MB · $Prom 2025 · $Prom 2026`
(sin columnas de Tiendas: son de grano tienda, no aplican a marca/tipo).

**Tabla 3 — Resumen Ventas Mensual** (una fila por mes del año, Ene…mes actual + fila Total):
`Mes · 2025(Q) · 2026(Q) · Dif Q · %Q · $2025 · $2026 · Dif $ · %$`

Las 3 tablas mantienen la estética del módulo (clase `.disp-table`, paleta morada/roja, Space Grotesk). Fila Total resaltada (estilo PBI: fondo crema).

### D. Lógica "Same" (mismo rango + same-store)
- **Mismo rango:** el período 2026 es el `desde–hasta` seleccionado; el período 2025 es ese mismo rango desplazado un año (ya implementado hoy: `desdeAnt/hastaAnt`).
- **Same-store:** los números se restringen a las **tiendas operativas en ambos años**, definidas por el **maestro `INTEGRACION.dbo.Bodegas`** (no por las ventas del proveedor): una bodega es comparable si estaba abierta durante la ventana 2025 **y** la ventana 2026, según `FECHA_APERTURA` / `FECHA_CIERRE` (y `ESTADO`). Esto hace que un proveedor nuevo (sin ventas 2025) siga mostrando su 2026 (coincide con el Power BI de referencia), porque las tiendas existían en ambos años aunque el proveedor no vendiera en 2025.
- La definición operativa exacta (comparación de fechas apertura/cierre contra las ventanas) se fija en el plan; criterio: `FECHA_APERTURA <= inicio_ventana_2025` y (`FECHA_CIERRE` nula o `>= fin_ventana_2026`).
- Implementación: derivar el conjunto de bodegas comparables (cia 7) y aplicarlo como filtro/JOIN a las agregaciones del tab Detal (KPIs y las 3 tablas).

### E. Backend (`api/informe_g00.php`, tab Detal)
La mayoría de los agregados **ya se calculan** en el query consolidado con `GROUPING SETS` (val_act, val_ant, ups_act, ups_ant, margen_prom, tiendas_act, tiendas_ant por cada set). Cambios necesarios:
1. **Exponer al JSON** todos esos campos por fila (hoy `$mapDelta` solo pasa actual/anterior/delta/ups_actual). Las tablas necesitan: ups ambos años, $ ambos años, margen, tiendas ambos años → de ahí se derivan Dif/%/$Prom/%Prom/≠Tiendas en el front o en PHP.
2. **Tabla 2 (Marca→Tipo):** agregar `TIPO` a `#refs` (extender `getRefsCached` para traer `TIPO` desde `ITEMS`) y agregar el grouping set `(MARCA, TIPO)` además del `(MARCA)` existente. El front anida tipos bajo marcas.
3. **Mensual:** exponer también las **unidades** por mes y año (hoy solo se expone valor).
4. **Same-store:** incorporar el filtro de bodegas comparables (D) a `ventas_enriq` / a los agregados del tab Detal.
5. **MB:** se usa la columna `MARGEN` de `Ventas_Detal_PBI` (ya viene como %), agregada con `AVG(CAST(MARGEN AS float))` como hoy, mostrada en formato `%`. (Aproximación aceptada; no se trae COSTO en Inc 1.)
6. Mantener las optimizaciones existentes (#refs cacheado, NOLOCK, pushdown de fecha, una sola pasada con GROUPING SETS). El grupo de gotchas de [[project_plataforma20_informes]] aplica (fan-out Bodegas → `AND b.CIA = 7`; temp tables sqlsrv; fact tables sin índices).

### F. Frontend (`informes/g00.php`)
- Reemplazar el panel `#g00-panel-detal` (KPIs + 3 gráficas ECharts) por: franja de 3 KPIs + toggle + 3 tablas HTML.
- `loadDetal()` consume el JSON extendido y renderiza las 3 tablas (con fila Total y formato money/int/pct ya disponible: `fmtMoney`, `fmtInt`, `fmtPct`, `fmtMoneyFull`).
- Las gráficas ECharts del Detal (`initChartMensual`, `initChartBarras` para grupo/marca) y sus contenedores se eliminan. El resto de tabs (Tiendas, Periodos, Productos) **no se tocan**.
- El toggle Same/Día a Día/Retail: solo Same operativo; los otros dos no disparan carga.

## Fuera de alcance (Inc 1)
- Barra de 13 filtros en cascada/multi-select (es Inc 2). En Inc 1 la barra de filtros actual (fechas, grupo, marca) queda **igual**.
- Funcionalidad real de los modos `Día a Día` y `Retail`.
- Export a Excel/PDF (sigue pendiente, `g00Export` queda como está).
- Refinar las otras 3 pestañas (secciones siguientes, specs aparte).
- Traer COSTO para MB ponderado.

## Criterios de éxito
1. El botón del menú dice `Ventas` y el título `DASHBOARD DE VENTAS` (sin "G00"); el badge "G00" sigue en el header.
2. La sección Detal muestra: 3 KPIs (Tiendas 2026, $Prom 2026, MB), el toggle con Same activo (Día a Día/Retail inertes) y las 3 tablas con sus columnas y fila Total.
3. Tabla 2 muestra marcas con sus tipos anidados.
4. Todos los números del Detal corresponden a la lógica Same (mismo rango + solo tiendas operativas en ambos años por maestro Bodegas). Un proveedor con ventas solo en 2026 muestra su 2026 y 2025 en cero (no se anula el 2026).
5. Las otras 3 pestañas siguen funcionando igual que antes.
6. No hay regresión de performance perceptible (se mantiene #refs cacheado + GROUPING SETS de una pasada).
