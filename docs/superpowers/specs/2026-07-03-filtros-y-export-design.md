# Sub-proyecto A — Filtros y export

**Fecha:** 2026-07-03
**Módulo:** `plataforma_20` / informes
**Estado:** diseño aprobado, pendiente plan de implementación

## Contexto

El módulo de informes es **PHP server-rendered + JavaScript vanilla** (módulos IIFE con
`fetch()` a endpoints PHP JSON), SQL Server por detrás, y el Excel se genera **en el
cliente con SheetJS**. Librerías relevantes: Tom Select (filtros multi-select), ECharts,
Leaflet, SweetAlert2, SheetJS.

Tres informes tienen barra de filtros y comparten exactamente la misma estructura de
marcado:

- `informes/g00.php` — Dashboard de Ventas (principal).
- `informes/o14.php` — Siembra / Stock / Ventas (contiene la pestaña "Recomendaciones").
- `informes/geo.php` — Georeferenciación.

Estructura común de filtros: contenedor `.g00-filters` → filas `.g00-filter-row` → cada
filtro es un `.filter-group` con un `<label>` (nombre humano) + un `<select multiple>`
gobernado por Tom Select. Cada informe tiene su propio botón "Aplicar" y su `buildParams()`.

Este sub-proyecto agrupa 4 peticiones cohesivas de UI/export. (Los otros dos
sub-proyectos — B: fotos de tiendas en geo; C: rendimiento de consultas — van en specs
aparte.)

## Objetivos

1. **Filtros colapsables** con aviso de chips de los filtros aplicados cuando está colapsado.
2. **"(filtrado)"** junto al título del informe y junto a las pestañas cuando hay filtros activos.
3. **Filtros aplicados** en el encabezado del Excel descargado.
4. **Renombrar** "Recomendaciones" → "Alertas" en siembra/stock (etiqueta + nombre de Excel).

## Arquitectura

### Pieza central: helper compartido `filtrosUI`

Se agrega en `dashboard.php`, junto a los helpers `expFile`/`expDataset` existentes
(head, ~líneas 36-61). Es un objeto global `window.filtrosUI` que **introspecciona el DOM**
de la página activa, evitando configuración por informe:

- Localiza el `.page.active` y, dentro, su `.g00-filters`.
- Por cada `.filter-group`: lee el texto del `<label>` (nombre humano) y los valores
  seleccionados de su `<select>` vía la instancia de Tom Select (`select.tomselect.items`),
  resolviendo cada valor a su texto de opción (`select.tomselect.options[val].text`).
- Ignora los `.filter-group` sin selección.

API expuesta:

- `filtrosUI.activos(pageEl)` → `[{etiqueta, valores: [texto,...]}]` — filtros de
  dimensión/bodega con selección.
- `filtrosUI.periodo(pageEl)` → `{desde, hasta}` cuando el informe tiene rango de fechas
  (g00); `null` si no aplica.
- `filtrosUI.estaFiltrado(pageEl)` → `bool` — verdadero si `activos().length > 0`.
- `filtrosUI.render(pageEl)` → repinta chips + marcadores "(filtrado)" del informe.
- `filtrosUI.excelHeaderRows(pageEl)` → `Array<Array<string>>` — filas para prepender al Excel.

**Enganche:** cada informe ya llama a su `xxxLoad()` al aplicar filtros. Se añade una
llamada a `filtrosUI.render(pageEl)` al final de cada carga (y al entrar al informe vía
`xxxOnEnter`), para mantener chips y marcadores sincronizados con el estado real.

**Nota sobre el Período como "filtro":** el rango de fechas (Desde–Hasta de g00) **no**
dispara el estado "(filtrado)" — siempre está puesto, es el período base del informe. Sí
se incluye como contexto en los chips y en el encabezado del Excel.

### 1. Filtros colapsables

- A la barra `.g00-filters` se le antepone una **cabecera** con un botón chevron (▸/▾) y la
  palabra "Filtros". Al hacer click alterna una clase (`.filtros--colapsado`) en el
  contenedor.
- Colapsado: las `.g00-filter-row` se ocultan (CSS) y se muestra la **tira de chips**.
- Expandido: se ven los filtros completos; los chips se ocultan.
- El estado se persiste por informe en `localStorage` (clave `filtros_colapsado_<pageId>`),
  para que no se reinicie al navegar entre informes.
- El botón "Aplicar" de cada informe permanece accesible en ambos estados.

### 2. Aviso colapsado = chips

- Contenedor `.filtros-chips` (visible solo colapsado). Se puebla desde
  `filtrosUI.activos()`.
- Cada chip: `Campo: valor1, valor2`. Si un filtro tiene más de N valores (N≈3), muestra los
  primeros y `+K más`.
- Primer chip siempre el **Período** (`Desde–Hasta`) como contexto, cuando el informe lo
  tenga (g00).
- Si no hay filtros de dimensión/bodega activos: muestra "Sin filtros".

### 3. "(filtrado)" en título y pestañas

- **Título:** un `<span class="filtrado-badge">(filtrado)</span>` aparte, junto a
  `#pageTitle`. Se mantiene como elemento separado para no pelear con los textos que g00/o14
  escriben directamente en `#pageTitle` (ej. g00 pone "DASHBOARD DE VENTAS - proveedor"). El
  badge se muestra/oculta según `estaFiltrado()`.
- **Pestañas:** marcador junto a cada `.tab` del informe activo (span o pseudo-elemento).
  Como los filtros aplican a todo el informe (las pestañas comparten `buildParams`), aparece
  en todas las pestañas del informe. Se muestra/oculta según `estaFiltrado()`.
- **Disparador:** `estaFiltrado()` — solo cuando hay ≥1 filtro de dimensión/bodega con
  selección. El Período no cuenta.

### 4. Filtros en el Excel (encabezado)

- `filtrosUI.excelHeaderRows(pageEl)` devuelve filas AOA a prepender, p. ej.:
  ```
  ["Filtros aplicados:"]
  ["Período:", "2026-01-01 a 2026-07-03"]
  ["Marca:", "BRAHMA, OX"]
  ["Ciudad:", "Bogotá"]
  []            // fila en blanco separadora
  ```
  (Cuando no hay filtros: `["Filtros aplicados:", "Ninguno"]` + Período si aplica.)
- **Inyección en `expDataset`** (`dashboard.php`): antes de `aoa_to_sheet([header, ...filas])`
  se anteponen las filas de encabezado detectando la página activa. Los call-sites de
  `expDataset` **no cambian**.
- **Inyección en el export propio de o14** (`o14.php`, ~562-569): o14 arma su propio libro
  con `XLSX.writeFile`. Se le anteponen las mismas filas llamando a
  `filtrosUI.excelHeaderRows()` sobre la hoja construida.
- Los archivos generados no son leídos por ninguna rutina (no hay lectores con posiciones
  fijas de celda), así que desplazar los datos hacia abajo es seguro.

### 5. Rename "Recomendaciones" → "Alertas"

Alcance: **etiqueta visible + nombre del Excel**; ids internos intactos.

- `informes/o14.php:52` — etiqueta de la pestaña "Recomendaciones" → "Alertas". El punto
  pulsante `#o14-reco-dot` se conserva (queda junto a "Alertas").
- Encabezados/textos visibles dentro del panel reco que digan "Recomendaciones" (revisar
  líneas ~219, 374, 417, 457, 509) → "Alertas" donde sea texto de cara al usuario.
- Export de o14 (~562-569): `expFile('Recomendaciones')` → `expFile('Alertas')`.
- **Sin cambios** en: `o14ShowTab('reco', ...)`, ids `#o14-reco-*`, `#o14-panel-reco`, ni en
  `api/o14_recomendador.php` / `api/informe_o14.php` (lógica interna).

## Alcance y consistencia

- Colapsable, chips y "(filtrado)" se aplican de forma **uniforme** a g00, o14 y geo.
- Informes sin barra de filtros no se tocan.
- El rename aplica solo a o14.

## Manejo de errores / bordes

- Si Tom Select aún no inicializó un select al momento de `render()`, ese filtro se omite sin
  romper (guardas sobre `select.tomselect`).
- `excelHeaderRows` degrada a "Ninguno" cuando no hay filtros; el export sigue funcionando.
- El toggle de colapso y `localStorage` degradan silenciosamente si `localStorage` no está
  disponible.

## Pruebas

Verificación manual en el navegador real, por cada informe (g00, o14, geo):

1. Aplicar uno o más filtros → confirmar badge "(filtrado)" en título y pestañas.
2. Colapsar la barra → confirmar chips correctos (campo: valores, `+N más`, Período).
3. Expandir → los filtros completos vuelven; el estado persiste al navegar y regresar.
4. Exportar a Excel → confirmar filas de encabezado con Período + filtros arriba de los datos.
5. o14: confirmar pestaña "Alertas" y nombre de archivo del export `Alertas - ... .xlsx`.

No se agregan tests automatizados (es JS de navegador). Los tests PHP existentes de o14
(`tests/o14_recomendador_test.php`) no se ven afectados porque la lógica interna no cambia.

## Fuera de alcance (otros sub-proyectos)

- **B:** fotos de tiendas en georeferenciación (Cloudinary + panel lateral).
- **C:** rendimiento de consultas (medición + índices + tabla/vista de resumen).
