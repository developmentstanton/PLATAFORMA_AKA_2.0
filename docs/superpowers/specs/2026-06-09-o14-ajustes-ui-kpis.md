# O14 — Ajustes de UI + fix de KPIs (lote 2026-06-09)

**Fecha:** 2026-06-09
**Rama:** `feat/o14-topbar-g00-style`
**Archivos:** `informes/o14.php` (mayoría), `api/informe_o14.php` (puntos KPI), `dashboard.php` (CSS si aplica)

## Contexto

Tras verificar el filtro de ventas, Rafael pidió un lote de ajustes a O14. Uno es un bug (KPIs). El rediseño de la pestaña Recomendaciones (traer novedades de toda la vista filtrada con indicador animado) se **difiere a un spec propio** por ser más grande. Este spec cubre 8 ítems de UI/KPIs acotados.

## Ítems

### 1. Total Stock = Disponible + Hold
Hoy el KPI "Total Stock" = `disponible`. Cambiar a `disponible + hold`. La etiqueta del card sigue siendo "Total Stock".
- **Backend `api/informe_o14.php`:** en tab `b` (`$kpi['total_stock'] = $kpi['disponible'];`) y en tab `c` (`$kpi['total_stock'] = $kpi['disponible'];`) cambiar a `$kpi['disponible'] + $kpi['hold']`.
- El fix de KPIs client-side (#8/#9) debe usar la misma fórmula.

### 2. Quitar "Compañía" de la card de filtros
Eliminar el `filter-group` de Compañía (`<select id="o14-cia">`) del markup de `informes/o14.php`. En `buildParams`, eliminar `const cia = val('o14-cia'); if(cia) p.set('cia', cia);`. El backend ya maneja `cia` vacío (sin filtro de cía). No quedan otras referencias a `o14-cia`.

### 3. Mover "Negocio" antes de "Referencia"
El `filter-group` de Negocio (`<select id="o14-negocio" onchange="o14PickNegocio(this.value)">` + su label) hoy está en la primera fila (junto a Compañía). Moverlo a la fila de criterios de referencia, **inmediatamente antes** del `filter-group` de Referencia (`#o14-f-referencia`). Conserva su `onchange` y su lógica (`populateNegocios`, `o14PickNegocio`).

### 4. La última fila de filtros pasa a ser la primera
Reordenar las filas de la barra de filtros para que la fila de bodega (Grupo / Tienda / Centro comercial / Departamento / Ciudad) quede **primera**. Orden final de filas:
1. Grupo, Tienda, Centro comercial, Departamento, Ciudad
2. Marca, Tipo, Categoría, Subcategoría, Género, Público, **Negocio**, Referencia
3. Color, Talla

### 5. Botón "Aplicar" a la derecha
El botón Aplicar (`<button class="g00-btn-refresh" onclick="o14Load()">`) hoy está tras Compañía/Negocio. Reubicarlo **alineado a la derecha** de la card de filtros (p. ej. una fila/zona propia con `margin-left:auto` o `align-self:flex-end`). El hint `#o14-c-sel` se mantiene visible (puede ir junto al botón).

### 6. Orden de pestañas: Por tienda → Por negocio → Recomendaciones
Reordenar las `.tab` en el markup: primero "Por tienda" (`o14ShowTab('c')`), luego "Por negocio" (`o14ShowTab('b')`), luego "Recomendaciones" (`o14ShowTab('reco')`). La primera (Por tienda) lleva la clase `active`.
- **Default:** `currentTab` inicial = `'c'`. `o14OnEnter` debe cargar la pestaña por defecto: cambiar `if(!tabState.b) loadB();` por `if(!tabState[currentTab]) loadCurrentTab();`.
- **Gotcha a corregir:** `o14SelectNegocio` y `o14PickNegocio` ubican el tab C con `querySelector('#page-informes-o14 .o14-tabs .tab:nth-child(2)')`. Al pasar "Por tienda" a ser `nth-child(1)`, **actualizar esos selectores a `:nth-child(1)`** (o, mejor, seleccionar por un atributo/identificador estable en vez de posición).

### 7. (DIFERIDO) Indicador animado de recomendaciones — spec propio
No se implementa en este lote. Se brainstormeará aparte: recomendaciones sobre toda la vista filtrada (todos los negocios, faltantes + sobrantes + proveedor) + indicador de actividad pendiente en la pestaña.

### 8. Totales por grupo en "Por tienda" (O14C / `renderArbol`)
Hoy cada grupo se pinta como: fila-grupo (▼ + nombre + subtotales) arriba, luego almacenes y negocios; y al final una fila `TOTAL (sin CEDI)`.

Cambios (decididos con Rafael):
- **Header de grupo arriba SIN números:** la fila de grupo conserva el toggle ▼/▶ y el nombre, pero **sin** las celdas de medida (solo sirve para colapsar/expandir).
- **Fila "Total &lt;grupo&gt;" al final del grupo:** después de los almacenes/negocios del grupo, una fila con los subtotales de ese grupo (mismas medidas×tallas + Tot). Se **oculta al colapsar** el grupo (igual que los hijos: comparte el patrón `display:none` por grupo).
- **Dos totales generales al final de la tabla:** la fila existente `TOTAL (sin CEDI)` se mantiene, y se **agrega** una fila `TOTAL (con CEDI)` que suma todos los grupos incluyendo el CEDI.
- Estilo: la fila "Total &lt;grupo&gt;" reusa el realce de subtotal de grupo actual (fondo `o14-row-grupo` o similar); las dos filas TOTAL reusan `o14-total`.

### 9. Fix: los KPIs no reflejan el filtro "Negocio" (bug)
**Causa raíz (confirmada por lectura + evidencia de BD):** el backend SÍ aplica todos los filtros a los KPIs (verificado: talla=10 → siembra 891→26; categoría/género/referencia también reducen). El bug está en el frontend: al filtrar por **Negocio** (`o14SelectNegocio` / clic en negocio de O14B / selector "Negocio"), se filtra el árbol en cliente (`filterArbol` + `renderArbol`) **pero no se vuelve a llamar `renderKpis`**, así que la tabla muestra 1 negocio y los KPIs siguen mostrando el total. Los filtros de dimensión + Aplicar sí refrescan KPIs (recargan del server y llaman `renderKpis`).

**Fix (frontend `informes/o14.php`):** los KPIs deben reflejar el subconjunto visible.
- Nueva función `kpisFromArbol(data)` que computa los 10 KPIs desde un árbol `grupos[]` (mismas reglas que el backend): suma `siembra/disponible/hold/ventas` **excluyendo CEDI**; `total_stock = disponible + hold` (consistente con #1); `sobrantes`/`faltante` derivados por talla y sumados; `disphold` no es KPI; conteos: `negocios` = negocios distintos (ref+color) no-CEDI, `negocios_con_siembra` = con siembra>0, `tiendas_con_siembra`/`tiendas_con_inv`/`tiendas_con_venta` = almacenes (llave cia-bodega) distintos no-CEDI con siembra>0 / disponible>0 / ventas≠0.
- En `o14SelectNegocio` (y al filtrar por negocio en general): tras fijar `shownC = filterArbol(...)`, llamar `renderKpis(kpisFromArbol(shownC))`.
- En `o14PickNegocio('')` ("— Todos —"): restaurar KPIs del dataset completo → `renderKpis(kpisFromArbol(lastData.c))` (o reusar `lastData.c.kpis` del server).
- `loadC` puede seguir usando `d.kpis` del server para la carga completa; el requisito es que **cualquier** cambio de lo visible (incluido el filtro de negocio client-side) deje los KPIs coherentes con la tabla.

**Criterio de éxito:** al elegir un negocio, los KPIs muestran los valores de ESE negocio; al volver a "— Todos —", los del total filtrado. La tabla y los KPIs nunca quedan descoordinados.

## Verificación
- **Backend (#1):** test/endpoint — `total_stock == disponible + hold` en tab b y c (BELTRANY: hoy stock=964=disp; con hold=19 debe dar 983).
- **#9 (client-side):** test unitario JS-equivalente no hay arnés DOM; se valida que `kpisFromArbol` sobre un árbol de prueba dé los totales esperados (se puede portar a un mini-check Node con datos sintéticos). E2E por Rafael: elegir negocio → KPIs cambian a ese negocio; "— Todos —" → vuelven al total.
- **Regresión:** `o14_recomendador` 27/27; `o14_filtros` OK; `o14_ventas_rango` OK; `g00_detal_smoke` OK; `php -l` limpio.
- **E2E navegador (Rafael):** Total Stock = disp+hold; sin Compañía; Negocio antes de Referencia; fila de bodega primera; Aplicar a la derecha; pestañas en orden Por tienda/Por negocio/Recomendaciones (default Por tienda); en Por tienda, total de grupo al final + TOTAL con y sin CEDI; KPIs reaccionan al filtro de negocio.

## Fuera de alcance
- #7 (rediseño de recomendaciones + indicador) → spec propio.
- Optimización del escaneo de `Acum` (tradeoff ya aceptado).
