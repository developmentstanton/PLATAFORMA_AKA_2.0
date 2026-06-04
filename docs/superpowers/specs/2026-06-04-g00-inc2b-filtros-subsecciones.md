# G00 Inc 2B — Barra de filtros en subsecciones + Color/Talla + filtros de bodega

**Fecha:** 2026-06-04
**Informe:** G00 (Dashboard de Ventas), `plataforma_20`
**Rama:** continúa sobre `feat/g00-detal-replica-pbi`
**Depende de:** Inc 2A (barra de filtros con Tom Select + Calendario/S.S.S + cascada) y del fix del Mensual (2026-06-04).

## Objetivo

Reorganizar la barra de filtros del G00 en **tres subsecciones visuales** (cada una en su propia fila, **sin subtítulos**, división solo visual), simplificar el selector de fechas a **mes/día** (el año sobra porque el informe siempre compara año actual vs anterior), y **agregar 5 filtros nuevos**: Color y Talla (criterios de referencia) y Grupo, Tienda, Centro comercial (criterios de bodega).

## Alcance

**Dentro:**
1. Layout de la barra en 3 filas (tiempo / criterios de referencia / criterios de bodega).
2. Fechas Desde/Hasta como **mes + día** (año fijo al actual).
3. Filtros de bodega nuevos: **Grupo**, **Tienda**, **Centro comercial**.
4. Filtros de referencia nuevos: **Color**, **Talla** (vía la vista maestro de SKU).
5. Integración de todos en la cascada bidireccional y en el filtrado del backend (5 tabs).
6. Extender el smoke test de regresión con los filtros nuevos.

**Fuera:**
- `status` (PORTAFOLIO.STATUS_N) — queda para un incremento posterior.
- Cruce de cascada Color/Talla ↔ Bodega (ver "Decisiones").
- Cualquier cambio a los KPIs/tablas del Detal (solo cambia el filtrado de entrada).

## Fuentes de datos (confirmadas contra BD, 2026-06-04)

- **Bodega** — `INTEGRACION.dbo.Bodegas` (join existente en todos los tabs, `b.CIA=7`):
  - Grupo → `b.GRUPO`; Tienda → `b.NOMBRE`; Centro comercial → `b.CENTRO_COMERCIAL`; Departamento → `b.DEPTO`; Ciudad → `b.CIUDAD`.
- **Color/Talla — universo (catálogo/cascada):** `INTEGRACION.dbo.Maestro_Ref_Plataforma_AKA` (VIEW), grano **(REFERENCIA, COLOR, TALLA)**. Para BELTRANY (166 refs): 1,285 filas, 48 colores, 19 tallas. Vista completa: 54,682 filas, 587 colores, 158 tallas. Liviano por proveedor.
- **Color/Talla — filtrado de ventas:** las tablas de hechos `Ventas_Detal_PBI`/`Ventas_Detal_Acum_PBI` ya tienen columnas `COLOR` y `TALLA` (grano de línea). El filtro se aplica directamente sobre ellas; el maestro NO se usa para filtrar, solo para enumerar.

## Diseño

### 1. Layout (4 filas, división solo visual, sin subtítulos)

Contenedor `.g00-filters` pasa de una sola fila *wrap* a **cuatro filas** (`flex-direction: column`, cada fila un `div.g00-filter-row` con sus `.filter-group` en *wrap*). Separación visual entre filas con un borde/espaciado sutil (sin texto de título). **Color y Talla van en su propia fila** (Fila 3), separados de Criterios de Referencia — separación **solo visual**; en la cascada Color/Talla siguen perteneciendo al cluster de referencia (ver "Cascada").

```
Fila 1 (tiempo):     [Desde m/d] [Hasta m/d] [Calendario] [S.S.S]              [Aplicar →]
Fila 2 (referencia): [Marca][Tipo][Categoría][Subcategoría][Género][Público][Referencia]
Fila 3 (color/talla):[Color][Talla]
Fila 4 (bodega):     [Grupo][Tienda][Centro comercial][Departamento][Ciudad]
```

El botón **Aplicar** queda alineado a la derecha de la Fila 1.

### 2. Fechas mes/día (sin año)

- Reemplazar los `<input type="date">` de Desde/Hasta por selección de **mes + día** (dos `<select>` cada uno: mes 01–12 y día 01–31, o un control equivalente).
- En `buildParams`, el año se fija al **año actual** (`new Date().getFullYear()`) y se construye `desde`/`hasta` en `YYYY-MM-DD`. El backend no cambia: sigue recibiendo `desde`/`hasta` completos y el comparativo del año anterior lo resuelve Calendario (diaadia/retail).
- Valores por defecto: Desde = 01/01, Hasta = mes/día de hoy (YTD), igual que hoy.

### 3. Filtros de bodega (Grupo, Tienda, Centro comercial)

- **Backend** `api/informe_g00.php`, mapa `$FILTROS_MULTI`: agregar
  `'grupo'=>'b.GRUPO'`, `'tienda'=>'b.NOMBRE'`, `'centro_comercial'=>'b.CENTRO_COMERCIAL'`.
  Se aplican por el mecanismo existente (`$filtroExtra`/`$paramsExtra`), idéntico a depto/ciudad. Sin cambios de orden de params.
- **Catálogo** `?tab=filtros`: agregar `b.GRUPO`, `b.NOMBRE`, `b.CENTRO_COMERCIAL` al SELECT de `combos` (y a la estructura de cada combo).
- **Front:** 3 `<select multiple>` nuevos en la Fila 3; entran a `FILTER_FIELDS` (cluster bodega) y a la cascada.

### 4. Color/Talla (vía Maestro_Ref_Plataforma_AKA)

**Enfoque elegido (mínimo impacto en orden de params):**

- **Exponer COLOR/TALLA en `cteVentas()`**: agregar `COLOR, TALLA` al SELECT de las dos tablas base del UNION. (2 columnas extra en el CTE; costo de I/O despreciable frente al scan.)
- **`$FILTROS_MULTI`**: agregar `'color'=>'v.COLOR'`, `'talla'=>'v.TALLA'`. Como `v` (ventas), `i` (#refs) y `b` (Bodegas) están disponibles en TODOS los tabs donde se inyecta `$filtroExtra`, los predicados `v.COLOR IN(...)` / `v.TALLA IN(...)` funcionan en los 5 tabs **sin cambiar el orden de parámetros** (entran por `$paramsExtra`, ya posicionado correctamente en cada query).
- **Catálogo `?tab=filtros`**: agregar un arreglo nuevo `sku` al payload (junto a `combos`), cacheado en el mismo archivo `cache/g00_filtros_{md5}.json`:
  ```sql
  SELECT DISTINCT m.REFERENCIA, m.COLOR, m.TALLA
  FROM INTEGRACION.dbo.Maestro_Ref_Plataforma_AKA m WITH (NOLOCK)
  INNER JOIN #refs i ON i.REFERENCIA = m.REFERENCIA
  ```
- **Front:** 2 `<select multiple>` nuevos (Color, Talla) en la Fila 2; cascadean por **referencia** usando el arreglo `sku`.

### Cascada (comportamiento)

Dos clusters:
- **Referencia:** marca, tipo, categoría, subcategoría, género, público, referencia, **color, talla**.
- **Bodega:** grupo, tienda, centro comercial, departamento, ciudad.

Reglas:
- Dentro de cada cluster, las opciones se acotan entre sí (bidireccional), como hoy.
- **Color/Talla** se ligan al cluster de referencia: las opciones de Color/Talla reflejan las referencias válidas según las selecciones de criterios de referencia; y seleccionar Color/Talla acota las referencias (y por ende las demás dims de ref). Se implementa cruzando `combos` (atributos de ref) con `sku` (color/talla por ref) por `REFERENCIA`.
- **No** se cruza Color/Talla ↔ Bodega (evita el producto cartesiano ref×bodega×color×talla). Las opciones de bodega no dependen del color/talla elegido y viceversa.

## Decisiones

- **Color/Talla sí se incluyen ahora** (no se difieren) gracias a la vista maestro, que vuelve barato el catálogo.
- **Cascada Color/Talla solo por referencia, no por bodega** — pragmático; mantiene los payloads chicos (~1.3K filas sku + ~800 combos para un proveedor típico).
- **Año fuera del filtro de fechas** — el informe es siempre comparativo actual vs anterior.
- **Sin subtítulos** en las subsecciones — la separación es solo visual (filas + espaciado/borde sutil).

## Cambios por archivo

- `api/informe_g00.php`: `cteVentas()` (+COLOR,TALLA); `$FILTROS_MULTI` (+5 claves); `getCatalogos`/bloque `?tab=filtros` (combos +grupo/tienda/cc, +arreglo `sku`).
- `informes/g00.php`: markup de la barra en 3 filas; controles mes/día; 5 selects nuevos; `FILTER_FIELDS` dividido en 2 clusters; lógica de cascada con `sku`; `buildParams` (año fijo + nuevos campos); CSS de filas/separación.
- `tests/g00_detal_smoke_test.php`: agregar permutaciones con `color[]`, `talla[]`, `grupo[]`, `tienda[]`, `centro_comercial[]` → siguen dando `ok=1`.

## Testing

- **Backend:** smoke test extendido (subprocesa el endpoint real) exige `ok=true` en permutaciones que incluyen los 5 filtros nuevos y combinaciones con Calendario/S.S.S. Verificar que un filtro real de color/talla **reduce** los totales y que el parser multi-valor mantiene el orden de params.
- **Catálogo:** `?tab=filtros` devuelve `combos` (con grupo/tienda/cc) y `sku` no vacíos para un proveedor real; tamaños acordes al dimensionamiento.
- **Navegador (Rafael):** las 3 filas, sin subtítulos; fechas mes/día; cascada en ambos clusters; aplicar color/talla filtra; export sigue ok.

## Riesgos / consideraciones

- **Orden de parámetros:** el enfoque por `$FILTROS_MULTI`/`$paramsExtra` evita reordenar params, pero CUALQUIER filtro nuevo debe entrar por ese mecanismo (no inline) para no repetir el bug 8120/desalineación. El smoke test es la red de seguridad.
- **`cteVentas` con COLOR/TALLA siempre presentes:** costo de I/O marginal; aceptable.
- **Cache de catálogo:** al cambiar la estructura de `g00_filtros_*` (nuevo `sku` + campos de bodega), la primera carga del día reconstruye (se invalida por fecha del archivo).
- **Escala STANTON (latente, no de este Inc):** el INSERT de `#refs` de ~24K refs (~100s) sigue rompiendo la conexión para proveedores enormes. No lo aborda este spec; queda como follow-up aparte (ver changelog 2026-06-04).
