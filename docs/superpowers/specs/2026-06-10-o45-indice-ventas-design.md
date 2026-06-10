# Spec — Informe O45 «Índice de Ventas»

Fecha: 2026-06-10
Rama: `feat/o14-topbar-g00-style` (o rama nueva a definir en el plan)
Estado: aprobado por Rafael (diseño). Pendiente: plan de implementación.

## Objetivo

Nuevo informe **Índice de Ventas** (código interno **O45**, etiqueta visible `ÍNDICE DE VENTAS`)
en el portal Plataforma 2.0. Es un cuadro plano, **una fila por Negocio** (`referencia-color`),
que cruza ventas del rango filtrado con el stock actual (CEDI / tiendas) y dos índices derivados.

Reemplaza el placeholder `#page-indice-ventas` que hoy existe en el menú (sección REPORTES).

## Alcance

- Página nueva `informes/o45.php` incluida desde `dashboard.php`.
- Endpoint nuevo `api/informe_o45.php` (datos + catálogo de filtros).
- Cableado del menú: el botón **Índice de Ventas** deja de ser placeholder y abre el informe.
- Tests PHP en `tests/`.

Fuera de alcance: no se tocan O14 ni G00 (solo se reusan `lib_refs` y patrones de query).

## Enfoque

Endpoint + página propios (no se mete dentro de O14), reusando:
- el cache de referencias `#refs` (`api/lib_refs.php`),
- el patrón de CTEs stock + ventas probado en `api/informe_o14.php`,
- la plantilla visual de topbar/filtros/Tom Select/Excel de O14/G00.

Diferencia clave con O14: O45 **agrega por negocio** (no matriz por talla) y maneja **dos ventanas
de ventas** (rango filtrado + 30 días móviles hasta `Hasta`).

## Layout

### Página (`informes/o45.php`, `id="page-informes-o45"`)

- **Topbar** (vía `o45OnEnter`, misma plantilla que O14):
  - Izquierda: inputs **Desde / Hasta** (en `#topbarDates`). Default: `Desde=2025-01-01`, `Hasta=hoy`.
  - Centro: título **ÍNDICE DE VENTAS**.
  - Derecha: los 3 botones estándar del topbar — **Actualizar** (`topbarO45Refresh` → `o45Load()`),
    alertas 🔔, logout. (Se agrega un botón refresh propio análogo a `topbarO14Refresh`.)
- **Filtros** (`.g00-filters`):
  - **Fila 1:** Grupo · Tienda · *(botón Aplicar a la derecha → `o45Load()`)*.
    - **Tienda más ancho** (CSS dedicado) y con opciones etiquetadas `COD - NOMBRE`.
  - **Fila 2:** = fila 2 de O14 → Marca · Tipo · Categoría · Subcategoría · Género · Público · Negocio · Referencia.
  - **Sin fila 3** (no se incluyen Color/Talla).
- **Cuerpo:** una `<table>` plana, una fila por negocio, **fila TOTAL** abajo, botón **⤓ Excel** arriba a la derecha.

## Columnas y fórmulas

Orden exacto de columnas:

| # | Columna | Definición |
|---|---|---|
| 1 | **Negocio** | `referencia-color` |
| 2 | **Marca** | `MARCA` del catálogo `#refs` (ITEMS) |
| 3 | **Ventas (und)** | Σ cantidad **neta** vendida en `[Desde, Hasta]` (devolución, cantidad<0, resta), bodegas tienda (no-CEDI) |
| 4 | **#tiendas** | nº de bodegas tienda distintas (no-CEDI) con ventas ≠ 0 en `[Desde, Hasta]` |
| 5 | **Índice de inventario** | `Total Stock ÷ ventas_30d`, con `ventas_30d` = Σ cantidad neta en `[Hasta−29, Hasta]` (tiendas). Si `ventas_30d ≤ 0` → mostrar `—` |
| 6 | **Stock CEDI** | Σ (`disponible` + `hold`) en bodega CEDI |
| 7 | **Stock Tiendas** | Σ (`disponible` + `hold`) en bodegas no-CEDI |
| 8 | **Total Stock** | Stock CEDI + Stock Tiendas |
| 9 | **Índice de Ventas mes** | `(Ventas / #tiendas) / (#días / 30)`, `#días` = `DATEDIFF(día, Desde, Hasta) + 1`. Si `#tiendas = 0` → `0` |
| 10 | **Tallas** | nº de tallas distintas del negocio (tallas con stock o venta) |
| 11 | **Precio de Venta Detal** | `MAX(f126_precio)` de `INTEGRACION.dbo.LISTA_PRECIOS_DETAL` por (`f120_referencia`, `f121_id_ext1_detalle`); vacío si no hay |

Notas:
- El stock es snapshot **actual** (`inv_actual_PBI` + `_hold_actual_PBI`); el filtro de fechas **solo** afecta ventas.
- `ventas_30d` es independiente del rango `[Desde, Hasta]`; usa la ventana móvil de 30 días que termina en `Hasta`.
- **CEDI** se identifica por `bodega = 'CEDI'` (igual que O14: `WHERE bodega <> 'CEDI'` separa tiendas de CEDI). Las "tiendas" son las bodegas con `bodega <> 'CEDI'`.

## Universo de filas

Negocios (`referencia-color`) que, dentro del scope filtrado, tengan **ventas en el rango O stock actual**
(disponible/hold > 0). Mismo criterio de universo que O14 (`llaves` = unión de fuentes).

## Reglas de filtros

- **Fila 2 (dimensión de producto):** acota el universo de referencias podando `#refs` (igual que O14).
- **Fila 1 (Grupo / Tienda):** acota las métricas de **tienda** (Ventas, #tiendas, Stock Tiendas, ventas_30d).
  **Stock CEDI siempre completo** (CEDI no es una tienda; no se ve afectado por el filtro Grupo/Tienda).
- Sin filtros seleccionados = todo el proveedor (según sesión).

## Fila TOTAL

- Aditivas sumadas: Ventas, Stock CEDI, Stock Tiendas, Total Stock.
- **#tiendas** = nº de tiendas distintas a nivel global (no la suma de las filas).
- **Índices recalculados a nivel total**:
  - Índice de inventario total = `Total Stock_global ÷ ventas_30d_global`.
  - Índice de Ventas mes total = `(Ventas_global / #tiendas_global) / (#días/30)`.
- **Tallas** y **Precio** en blanco en la fila TOTAL.

## Orden

Por **Ventas (und) descendente** por defecto.

## Backend (`api/informe_o45.php`)

Estructura análoga a `api/informe_o14.php`.

### Parámetros
- `tab` = `data` (cuadro) | `filtros` (catálogo de cascadas).
- `desde`, `hasta` (YYYY-MM-DD). Defaults: `2025-01-01` … hoy.
- Filtros multivaluados: `grupo[]`, `tienda[]`, `marca[]`, `tipo[]`, `categoria[]`, `subcategoria[]`,
  `genero[]`, `publico[]`, `negocio` (ref-color), `referencia[]`.

### `tab=data`
1. Cargar `#refs` (cache `lib_refs`) y podar por los filtros de dimensión (fila 2).
2. Temp `#base` por `(cia, bodega, negocio, ref, color, talla)` con columnas:
   - `disponible` (de `INTEGRACION.dbo.inv_actual_PBI`, mismas reglas que O14: `cia<>'001'`, `COLUMNA1 IN ('INV1430','INV1435','400')`),
   - `hold` (de `INTEGRACION.dbo._hold_actual_PBI`, `cia<>'001'`),
   - `ventas_rango` (Σ `Ventas_Detal_PBI` [+ `Ventas_Detal_Acum_PBI` si `desde<=2025-12-31`], `FECHA BETWEEN desde AND hasta`),
   - `ventas_30d` (misma unión de ventas, `FECHA BETWEEN DATEADD(day,-29,hasta) AND hasta`; incluir Acum si esa ventana toca ≤2025).
   - `negocio = referencia+'-'+color` (igual que O14).
3. Aplicar filtro Grupo/Tienda sobre las bodegas tienda (no sobre CEDI).
4. Agregar por negocio:
   - `ventas = Σ ventas_rango` (bodegas tienda),
   - `tiendas = COUNT(DISTINCT bodega no-CEDI con ventas_rango<>0)`,
   - `ventas_30d_neg = Σ ventas_30d` (tiendas),
   - `stock_cedi = Σ (disponible+hold) en CEDI`,
   - `stock_tiendas = Σ (disponible+hold) en no-CEDI`,
   - `total_stock = stock_cedi + stock_tiendas`,
   - `tallas = COUNT(DISTINCT talla)` del negocio,
   - `marca` (de `#refs`),
   - índices calculados en PHP (para controlar div/0).
5. Join `Precio` desde `LISTA_PRECIOS_DETAL` (`MAX(f126_precio)` por ref+color).
6. Ordenar por `ventas` desc. Calcular fila `total`.
7. `echo json_encode(['ok'=>true, 'rango'=>['desde'=>…,'hasta'=>…,'dias'=>…], 'filas'=>[…], 'total'=>[…]])`.

Cada fila: `{ negocio, referencia, color, marca, ventas, tiendas, ind_inventario, stock_cedi, stock_tiendas, total_stock, ind_ventas_mes, tallas, precio }`.
`ind_inventario` = `null` cuando `ventas_30d_neg <= 0` (frontend pinta `—`).

### `tab=filtros`
Catálogo para las cascadas: combos de dimensión (vía `lib_refs`) + lista Grupo/Tienda desde `INTEGRACION.dbo.Bodegas`
(con `CIA = 7`, etiqueta `COD - NOMBRE`). Reusa el mismo catálogo de dimensiones de O14.

### Gotchas a respetar (de [[project_nexus_sql_gotchas]] / changelog O14)
- Bodegas: filtrar `b.CIA = 7` en cualquier JOIN a `Bodegas` (fan-out).
- Fact tables sin índices: push down `WHERE FECHA BETWEEN ? AND ?` dentro de cada SELECT; `WITH (NOLOCK)`.
- Temp tables + `sqlsrv` + parámetros: `CREATE TABLE #base (...)` sin parámetros y luego `INSERT`.
- `#refs` cacheado (no reconstruir escaneando ITEMS por request).

## Frontend (`informes/o45.php`)

- Markup: filtros (2 filas) y contenedor del cuadro. **Sin franja de KPIs** (a diferencia de O14): el informe es solo el cuadro.
- `o45OnEnter()`: monta topbar (Desde/Hasta + título + botón Actualizar), inicializa filtros (Tom Select cascada) una vez.
- `o45Load()`: `fetch('api/informe_o45.php?tab=data&'+params)` → `renderTabla(d)`.
- `renderTabla(d)`: pinta `<table>` con las 11 columnas + fila TOTAL; formatea índices a 2 decimales, `—` cuando aplica.
- Excel: `o45Export()` con SheetJS (`tableToAOA`), igual que O14.
- CSS: ancho ampliado para la columna/selector Tienda.

## Cableado del menú (`dashboard.php`)

- Botón **Índice de Ventas**: `onclick="showPage('informes-o45', this)"`.
- Eliminar el placeholder `#page-indice-ventas`; incluir `informes/o45.php` junto a g00/o14.
- Mapa `titles` en `showPage`: agregar `'informes-o45':'ÍNDICE DE VENTAS'`.
- `showPage`: `if (pageId === 'informes-o45' && typeof o45OnEnter === 'function') o45OnEnter();`
  y ocultar extras de topbar de o45 al salir (`topbarO45Refresh`).

## Pruebas (`tests/`)

PHP estilo O14 (datasets sintéticos o asserts sobre helpers):
1. **Ventas del rango**: suma neta correcta, devoluciones restan, excluye CEDI.
2. **Ventana 30d**: `[Hasta−29, Hasta]` independiente del rango; incluye Acum si toca ≤2025.
3. **Split de stock**: Stock CEDI vs Tiendas correctos; Total = suma.
4. **Universo**: aparece negocio con stock y 0 ventas; aparece negocio con ventas y 0 stock.
5. **Índices**: `ind_inventario` div/0 → null; `ind_ventas_mes` con #tiendas=0 → 0; #días correcto.
6. **Filtro Grupo/Tienda**: acota tiendas pero NO cambia Stock CEDI.
7. **Smoke** del endpoint (`tab=data` y `tab=filtros`) devuelve JSON `ok`.

## Pendientes / decisiones abiertas

Ninguna bloqueante. (Si en implementación `LISTA_PRECIOS_DETAL` no resuelve algunos ref+color,
el Precio queda vacío — comportamiento aceptado.)
