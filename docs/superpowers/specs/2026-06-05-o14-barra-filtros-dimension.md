# O14 — Barra de filtros por dimensión (estilo G00) + anchos a pantalla

**Fecha:** 2026-06-05
**Informe:** O14 — Siembra & Stock × Tienda × Talla
**Rama:** `feat/o14-topbar-g00-style` (continúa el trabajo de O14-estilo-G00 ya en curso, sin mergear)

## Objetivo

Llevar a O14 la barra de filtros multi-valor del G00 (filas 2-4: referencia, color/talla,
bodega) con **cascada**, conservando los selectores actuales **Compañía** y **Negocio**.
Los 14 filtros deben afectar **todas** las vistas (O14B, O14C, KPIs) y respetar el CEDI para
Recomendaciones. Además, dejar la **barra de filtros y los KPIs a ancho completo de pantalla**.

## Contexto (cómo está hoy O14)

- `api/informe_o14.php` construye una temp `#base (cia,bodega,negocio,referencia,color,talla,
  siembra,disponible,hold,ventas)` desde 4 fuentes (siembra `t400`, disponible `inv_actual_PBI`,
  hold `_hold_actual_PBI`, ventas `Ventas_Detal_*`). Las 4 hacen `INNER JOIN #refs r ON
  r.REFERENCIA=...`. Luego un `DELETE` quita bodegas `ADMINISTRATIVAS` (join a `Bodegas`).
  Los 3 tabs (`b`/`c`/`reco`) y los KPIs consultan `#base`. → **`#base` es el único punto de paso.**
- Hoy O14 solo filtra por `cia` (`$filtroCia` en el INSERT de `#base`).
- `#refs` (de `lib_refs.php`) ya trae `REFERENCIA, MARCA, TIPO, LINEA, SUBLINEA, CATEGORIA,
  SUBCATEGORIA, GENERO, PUBLICO_OBJETIVO`.
- El **CEDI** (`bodega='CEDI'`) se excluye de B/C/KPIs pero lo usa el motor de reco como fuente.
- La barra de filtros de O14 (`informes/o14.php`) tiene hoy: Compañía + Negocio + Aplicar.
- G00 ya tiene la barra de 4 filas + cascada (Tom Select) + catálogo `?tab=filtros`
  (`$FILTROS_MULTI`, clusters REF/SKU/BODEGA). Es la referencia a replicar (filas 2-4).

## Los 14 filtros y dónde se aplican

| Filtro | Origen | Aplicación |
|---|---|---|
| marca, tipo, categoria, subcategoria, genero, publico, referencia | `#refs` (ITEMS) | **Podar `#refs`** tras construirlo |
| color, talla | `#base` (por línea) | `DELETE FROM #base` lo no coincidente |
| grupo, tienda, centro_comercial, depto, ciudad | `Bodegas` (COD+CIA) | `DELETE FROM #base` por join a `Bodegas`, **conservando CEDI** |

Mapeo de columnas:
`marca→#refs.MARCA, tipo→TIPO, categoria→CATEGORIA, subcategoria→SUBCATEGORIA, genero→GENERO,
publico→PUBLICO_OBJETIVO, referencia→#refs.REFERENCIA; color→#base.color, talla→#base.talla;
grupo→Bodegas.GRUPO, tienda→Bodegas.NOMBRE, centro_comercial→Bodegas.CENTRO_COMERCIAL,
depto→Bodegas.DEPTO, ciudad→Bodegas.CIUDAD`.

### Mecánica (backend `api/informe_o14.php`)

1. **Parser** `$FILTROS_O14` (multi-valor `campo[]` → arreglo de valores; vacío = sin filtro),
   análogo al `$FILTROS_MULTI` de G00.
2. **Poda de `#refs`** (después de `buildRefsTemp`, antes de construir `#base`): por cada
   filtro de referencia activo, `DELETE FROM #refs WHERE <col> NOT IN (?,?,…)`. Al podar
   `#refs`, las 4 fuentes (que lo inner-joinean) quedan filtradas automáticamente.
3. **Filtro color/talla** (después de poblar `#base`): por cada uno activo,
   `DELETE FROM #base WHERE <col> NOT IN (?,…)`.
4. **Filtro bodega** (después de poblar `#base`, junto al DELETE de ADMINISTRATIVAS): por cada
   filtro de bodega activo, `DELETE b FROM #base b LEFT JOIN Bodegas bo ON bo.COD=b.bodega AND
   RIGHT('000'+rtrim(bo.CIA),3)=b.cia WHERE b.bodega<>'CEDI' AND ISNULL(<col>,'') NOT IN (?,…)`.
   Ejecutados en secuencia → semántica AND entre filtros. **El CEDI nunca se borra** (queda como
   fuente de reco).
5. Tabs `b`/`c`/`reco` y KPIs no cambian: leen `#base` ya filtrada. (Reco sigue viendo el CEDI.)
6. **`tab=filtros` NO aplica** los pasos 2-4 (poda/deletes): el catálogo necesita el universo
   completo del proveedor para poblar la cascada. Los filtros solo se aplican para `b`/`c`/`reco`.

**Gotcha sqlsrv:** los `DELETE ... NOT IN (?,…)` llevan params; `#refs`/`#base` ya existen como
temp de sesión (no se crean con params), así que los DELETE parametrizados son seguros (el
patrón problemático era `SELECT INTO #tmp ... ?`). Mantener cada DELETE con su lista de params.

## Catálogo + cascada (`?tab=filtros` nuevo en O14)

Devuelve, para poblar y encascar los selectores, desde el universo de O14 (sin filtros):
- `combos`: distinct de las dims de referencia + bodega — `#base` (no-CEDI) ⋈ `#refs` (atributos
  de referencia) ⋈ `Bodegas` (grupo, tienda=NOMBRE, centro_comercial, depto, ciudad).
- `sku`: distinct `(referencia, color, talla)` de `#base`.
Construye `#refs` + `#base` (sin los filtros de dimensión) y consulta esos distinct. Igual que
G00, el front lo cachea tras la primera carga del informe.

## Frontend (`informes/o14.php`)

- **Barra de filtros**: bajo Compañía + Negocio (que se conservan), agregar las 3 filas del G00
  (estructura `.g00-filter-row` + `.filter-group` con `<select multiple>`): fila referencia
  (marca, tipo, categoría, subcategoría, género, público, referencia), fila color/talla, fila
  bodega (grupo, tienda, centro comercial, depto, ciudad). Ids `o14-f-<campo>`. Botón **Aplicar**
  al final.
- **Tom Select + cascada**: inicializar los multi-select con Tom Select (CDN ya cargado para G00);
  poblar desde `?tab=filtros`; cascada de 2 clusters (combos ligado por las dims; sku por
  referencia) replicando la lógica de G00 (`comboMatches`/`activeRefs`/`availableCombo`/
  `availableSku`). Inicializar una sola vez (flag), al entrar a O14.
- **`buildParams`**: agregar los 14 `campo[]` (multi-valor) además de `cia` (y `ref`/`color`
  para `reco`). El rango sigue fijo YTD (no hay filtro de fechas).
- **Anchos**: la barra de filtros (`.g00-filters`) y los KPIs (`.o14-kpis`) a **ancho completo**
  del contenedor de la página (no condicionados al ancho de la matriz; la matriz ya vive en
  `.o14-matriz-wrap` con `overflow-x:auto`). Asegurar `width:100%` en ambos contenedores; KPIs en
  grid responsivo que llene el ancho.

No se duplican helpers de G00 entre archivos (cada informe tiene su copia; dedupe = follow-up).

## Decisiones confirmadas (Rafael)

- 14 filtros, con cascada, conservando Compañía + Negocio.
- Filtros de **bodega** afectan B/C/KPIs; **el CEDI se conserva siempre** para Recomendaciones.

## Verificación

- **Test backend** (`tests/o14_filtros_test.php`, subprocesa el endpoint real, BELTRANY SAS):
  `?tab=filtros` devuelve `combos` y `sku` no vacíos; aplicar un filtro (p. ej. `talla[]=` un valor,
  o `grupo[]=AKA`, o `marca[]=`) reduce/mantiene `#base` coherentemente: `tab=b` con filtro ⇒
  `ok=1` y filas ⊆ sin filtro; invariante O14B `faltante−sobrante = siembra−(disp+hold)` se
  mantiene; reco de un negocio sigue viendo CEDI aun con filtro de bodega.
- **Motor** `o14_recomendador_test.php` 27/27 (no se toca).
- `php -l` limpio.
- **E2E navegador (Rafael):** barra con Compañía + Negocio + 3 filas nuevas (cascada), Aplicar
  filtra B/C/KPIs; barra y KPIs a ancho de pantalla; reco intacto.

## Fuera de alcance

- No se extrae código compartido de filtros entre G00 y O14 (dedupe = follow-up).
- No se agregan filtros que O14 no tenga datos para soportar (solo los 14 de G00 filas 2-4).
- Persistencia "Generar solicitud" (sub-proyecto 3) sigue aparte.
- No se reintroduce el filtro de fechas (rango fijo YTD, decisión previa).
