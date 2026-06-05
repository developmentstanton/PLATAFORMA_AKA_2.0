# G00 — Rediseño de la pestaña "Ventas Por Productos"

**Fecha:** 2026-06-05
**Informe:** G00 — Dashboard de Ventas, pestaña **Ventas Por Productos**
**Rama:** `feat/g00-tiendas-resumen` (acumula los rediseños de pestañas de G00)

## Objetivo

Reemplazar el contenido actual de la pestaña "Ventas Por Productos" (tabla Top 20 +
treemap ECharts) por **3 tablas colapsables padre→hijo**, con el mismo juego de columnas
de la tabla "Por Grupo", para analizar las ventas por producto desde tres dimensiones.

## Contexto

- La pestaña Productos hoy (`tab=productos` en `api/informe_g00.php`, `renderTablaProductos`
  + treemap en `informes/g00.php`) devuelve `refs` (TOP 50) y `treemap`. Todo eso se elimina.
- Patrones existentes que se reutilizan:
  - **Tabla "Por Grupo"** (`renderTablaGrupo`/`rowGrupo`): juego de 16 columnas objetivo.
  - **Tabla "Detalle Tiendas"** (`renderTablaTienda`/`rowTienda` + `g00ToggleTienda`):
    patrón de tabla colapsable padre→hijo (filas hijas ocultas con `data-*` + caret ▸/▾).
  - **Query consolidada del Detal**: patrón de comparación YoY (`SUM(CASE WHEN FECHA
    BETWEEN ? AND ? …)`), `COUNT(DISTINCT … BODEGA)` para tiendas, `AVG(MARGEN)` para MB,
    `GROUPING SETS` + demux por `GROUPING_ID`.
  - **Pestaña Tiendas** (`tab=tiendas`): GROUPING SETS de 3 niveles con negocio REF-COLOR.
- `#refs` (temp por request) ya trae `CATEGORIA, SUBCATEGORIA, GENERO, PUBLICO_OBJETIVO`
  (además de MARCA/TIPO/LINEA/SUBLINEA). `cteVentas()` expone `COLOR` y `TALLA` del hecho.
- Las queries respetan los filtros de la barra: `$filtroExtra` (multi-valor `$FILTROS_MULTI`),
  `$sameStoreClause`/`$sameStoreParams` (S.S.S), y el offset YoY de Calendario (`$desdeAnt`/
  `$hastaAnt` ya calculados según `diaadia`/`retail`).

## Columnas (las 3 tablas, idénticas a "Por Grupo")

```
<1ª col> | {b} | {a} | Dif Q | %Q | ${b} | ${a} | Dif $ | %$ | MB | $Prom {b} | $Prom {a} | %Prom | Tdas {b} | Tdas {a} | ≠Tdas
```
16 columnas. `{a}` = año del filtro actual, `{b}` = `{a}-1`. `≠Tdas` = diferencia absoluta
(`Tdas act − Tdas ant`, coloreada `pos`/`neg` con signo). `$Prom` = `$ / unidades`
(helper `prom()`). MB = `AVG(MARGEN)` en % del periodo actual.

Las 3 tablas:

| Tabla | Padre | Hijo | 1ª columna | id tbody |
|---|---|---|---|---|
| Resumen Ventas Por Negocio | Negocio `REFERENCIA-COLOR` | Talla | `Negocio / Talla` | `g00-tabla-negocio` |
| Resumen Ventas Por Categoría | Categoría | Subcategoría | `Categoría / Subcategoría` | `g00-tabla-categoria` |
| Resumen Ventas Por Género | Género | Público objetivo | `Género / Público objetivo` | `g00-tabla-genero` |

## Comportamiento

- **Comparación YoY**: cada tabla compara año actual vs anterior, respetando el filtro de
  fecha, Calendario (Día a Día/Retail) y S.S.S (same-store) — igual que el resto del Detal.
- **Orden**:
  - Padres: por `$` actual (`val_act`) desc.
  - Hijos de Negocio (tallas): por **talla ascendente** (curva de tallas) — comparador
    numérico (`parseFloat`) con fallback a comparación de texto (`localeCompare`) cuando la
    talla no es numérica.
  - Hijos de Categoría (subcategorías) y de Género (públicos): por `$` actual desc.
- **Colapso**: padres arrancan **colapsados** (caret ▸); al hacer clic despliegan sus hijos
  (caret ▾). Mismo mecanismo `data-*parent`/`display:none` que `rowTienda`.
- **Fila Total** por tabla: sumas de `$`/uds/`$Prom`; **Tdas del Total = conteo distinct
  real** (no sumable), tomado de la fila `()` (grand total) de cada query. MB total = AVG
  del grand total.
- **Etiquetas vacías**: padre/hijo sin valor → `'(Sin dato)'` (p. ej. talla vacía).
- **Scroll**: la tabla de **Negocio** va dentro de un contenedor con alto fijo que muestra
  ~30 filas de negocio iniciales y `overflow-y:auto` para el resto, con el `<thead>` fijo
  (sticky). Categoría y Género son cortas → sin scroll.

## Backend (`api/informe_g00.php`, bloque `tab=productos` reescrito)

Tres queries independientes, cada una un `GROUPING SETS ((), (padre), (padre, hijo))` sobre
`ventas ⋈ #refs` (+ `LEFT JOIN Bodegas … AND b.CIA = 7` solo si hace falta para same-store;
las dims no usan Bodegas salvo el filtro). Estructura por query (ejemplo Negocio):

```sql
SELECT
    GROUPING_ID(<padre>, <hijo>) AS gid,
    <padre> AS p, <hijo> AS h,
    SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
    SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
    SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
    SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant,
    AVG(CASE WHEN FECHA BETWEEN ? AND ? AND CANTIDAD > 0 THEN CAST(MARGEN AS float) END) AS margen_prom,
    COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? THEN BODEGA END) AS tiendas_act,
    COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? THEN BODEGA END) AS tiendas_ant
FROM ventas_enriq
GROUP BY GROUPING SETS ( (), (<padre>), (<padre>, <hijo>) )
```

- `ventas_enriq` = CTE `cteVentas()` + JOIN `#refs` + WHERE `(FECHA BETWEEN act OR FECHA
  BETWEEN ant)` + `$filtroExtra` + `$sameStoreClause`. (Mismo molde que la consolidada.)
- Dims por tabla:
  - Negocio: `<padre>` = `(REFERENCIA, COLOR)` → label = `REFERENCIA + '-' + COLOR`; `<hijo>` = `TALLA`.
  - Categoría: `<padre>` = `CATEGORIA`; `<hijo>` = `SUBCATEGORIA`.
  - Género: `<padre>` = `GENERO`; `<hijo>` = `PUBLICO_OBJETIVO`.
- **Demux por `GROUPING_ID`** (recordar: bit alto = primera columna; bit=1 ⇒ columna
  agrupada/NULL). Para 2 dims (padre,hijo): `gid=3`→grand total `()`, `gid=1`→fila padre
  (hijo NULL), `gid=0`→fila padre+hijo. Para Negocio con 3 columnas reales (REF,COLOR,TALLA)
  el GROUPING_ID se calcula sobre `(REFERENCIA, COLOR, TALLA)`: `()`=7, `(REF,COLOR)`=1,
  `(REF,COLOR,TALLA)`=0. **Verificar el mapeo con una query de prueba antes de confiar**
  (lección de la Fase 1: el cálculo mental de GROUPING_ID se equivoca fácil).
- Ensamblado PHP: por tabla, mapa `padre → {fila padre + children[]}`; ordenar padres por
  `val_act` desc; ordenar children (tallas asc / resto $ desc); separar la fila `()` como
  `total`.
- **Payload nuevo** (reemplaza `refs`/`treemap`):
  ```json
  {
    "ok": true, "anio": <int>,
    "negocios":   { "rows": [ {label,val_act,val_ant,ups_act,ups_ant,margen,tiendas_act,tiendas_ant,children:[…]} ], "total": {…} },
    "categorias": { "rows": [...], "total": {…} },
    "generos":    { "rows": [...], "total": {…} }
  }
  ```
  Cada `children[]` item = mismo shape sin `children`. `total` = `{val_act,val_ant,ups_act,
  ups_ant,margen,tiendas_act,tiendas_ant}` (de la fila `()`).
- `anio` se calcula como en el Detal (`(int)date('Y', strtotime($hastaAct))`).
- **Costo**: 3 escaneos del hecho (cada query joina `#refs`, ~2.5s c/u en warm, en línea con
  el costo actual de la pestaña que ya hace 2 queries). No se introduce caché nuevo.

## Frontend (`informes/g00.php`)

- **Markup pestaña Productos**: reemplazar el bloque actual (treemap + tabla Top 20) por
  3 `<div class="card">` con título y `<table>` (ids `g00-tabla-negocio`, `g00-tabla-categoria`,
  `g00-tabla-genero`). La de Negocio envuelta en `<div class="g00-scroll-neg">` (CSS: alto
  fijo ~30 filas, `overflow-y:auto`, `thead` sticky).
- **JS**:
  - Eliminar `renderTablaProductos`, el init del treemap (`initChart*`/ECharts de productos)
    y referencias a `data.refs`/`data.treemap` en `loadProductos`.
  - `loadProductos` pasa a renderizar las 3 tablas desde `data.negocios/categorias/generos`.
  - **Renderer genérico** `renderTablaArbol(cont, data, anio, opts)` parametrizado por:
    `opts.col1` (encabezado 1ª col), `opts.tbodyId`, `opts.childSort` (`'talla'` | `'val'`),
    `opts.toggleName`. Reutiliza `prom/difCell/pctCell/fmtInt/fmtMoneyFull/esc`. Construye
    el `<thead>` de 16 cols (idéntico a `rowGrupo`) y filas padre/hijo/Total con caret y
    `data-<x>parent`. Total usa `data.<col>.total` (Tdas distinct real).
  - `g00ToggleArbol(prefix, idx, el)` genérico para colapsar/expandir (un solo handler).
  - Comparador de talla: `(x,y) => { const nx=parseFloat(x.label), ny=parseFloat(y.label);
    return (!isNaN(nx)&&!isNaN(ny)) ? nx-ny : String(x.label).localeCompare(String(y.label)); }`.
- **CSS** (`.g00-scroll-neg`): `max-height` para ~30 filas, `overflow-y:auto`; `thead th`
  con `position:sticky; top:0` dentro del contenedor.

## Verificación

- **Test backend** `tests/g00_productos_test.php` (subprocesa el endpoint real, BELTRANY SAS,
  `tab=productos`): `ok=1`; existen `negocios/categorias/generos` con `rows[]` y `total`;
  invariante por padre **`Σ children.val_act == padre.val_act`** (dif≈0) y lo mismo en uds;
  invariante **`total.tiendas_act ≤ Σ padres.tiendas_act`** (distinct ≤ suma) en cada tabla;
  el label de negocio contiene `-` (REF-COLOR).
- **Regresión** `tests/g00_detal_smoke_test.php` → sigue verde (no se tocan otros tabs).
- **`php -l`** limpio en ambos archivos.
- **E2E navegador (Rafael)**: pestaña Productos con 3 tablas, colapso/expansión, scroll en
  Negocio (~30 visibles), tallas en orden ascendente, columnas Tdas/%Prom/MB correctas,
  reacciona a la barra de filtros.

## Fuera de alcance

- No se tocan los otros tabs (Detal, Tiendas, Periodos) ni la query consolidada.
- No se agrega caché de respuesta (el costo es similar al actual).
- No se agrega export CSV de estas tablas (no pedido).
