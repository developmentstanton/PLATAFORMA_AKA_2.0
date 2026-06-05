# G00 — Exportar a Excel (.xlsx) en las 8 tablas

**Fecha:** 2026-06-05
**Informe:** G00 — Dashboard de Ventas (todas las pestañas)
**Rama:** `feat/g00-tiendas-resumen`

## Objetivo

Agregar un botón "Exportar Excel" a cada una de las 8 tablas del informe G00, que descargue
un archivo `.xlsx` nativo con los valores **numéricos** (analizables en Excel), incluyendo
**todas** las filas (padres + hijos, aunque estén colapsadas) + la fila Total.

## Contexto

- Las tablas se renderizan en `informes/g00.php` desde el `data` de cada fetch. Hoy ese
  `data` NO se guarda tras renderizar → hay que **stashearlo por pestaña** para exportar
  números crudos (no el texto formateado `$1.234`).
- Patrón de export existente (O14, `informes/o14.php`): DOM→CSV. Aquí NO se reusa: se quiere
  `.xlsx` real con números, así que se usa **SheetJS**.
- No hay librería xlsx cargada. CDNs actuales (ECharts, SweetAlert2, Tom Select) se cargan en
  el `<head>` de `dashboard.php`.

## Las 8 tablas y sus datos

| # | Tabla | Pestaña | Fuente (`data.*`) | Forma |
|---|---|---|---|---|
| 1 | Por Grupo | Detal | `por_grupo[]` (flat) + `kpis` | comparativa full |
| 2 | Por Marca / Tipo | Detal | `por_marca[]` (con `children[]`) + `kpis` | comparativa full |
| 3 | Mensual | Detal | `mensual[]` + `mensual_tdas` | mensual |
| 4 | Resumen Por Tienda | Tiendas | `tiendas[]` (con `children[]`) + `kpis` | comparativa (sin %Prom/Tdas) |
| 5 | Periodos | Periodos | `dias[]` (árbol en cliente) | periodos |
| 6 | Por Negocio | Productos | `negocios.{rows,total}` | comparativa full |
| 7 | Por Categoría | Productos | `categorias.{rows,total}` | comparativa full |
| 8 | Por Género | Productos | `generos.{rows,total}` | comparativa full |

Cada fila comparativa tiene: `label`, `val_act`, `val_ant`, `ups_act`, `ups_ant`, `margen`,
`tiendas_act`, `tiendas_ant` (+ `children[]` en Marca/Tienda/Negocio/Cat/Género). Mensual usa
`mes`, `val_*`, `ups_*`, `tiendas_*`. Periodos usa `dias[]` (`{mes,dia,val_act,val_ant,ups_act,ups_ant}`).

## Columnas exportadas (mismas que en pantalla, pero numéricas)

- **Comparativa full** (Grupo, Marca/Tipo, Negocio, Categoría, Género), 16 cols:
  `<dim>`, `{b}`, `{a}`, `Dif Q`, `%Q`, `$ {b}`, `$ {a}`, `Dif $`, `%$`, `MB`, `$Prom {b}`,
  `$Prom {a}`, `%Prom`, `Tdas {b}`, `Tdas {a}`, `≠Tdas`.
- **Tienda** (12 cols): igual que la comparativa pero **sin** `%Prom`, `Tdas {b}`, `Tdas {a}`,
  `≠Tdas` (réplica de la tabla en pantalla).
- **Mensual** (14 cols): `Mes`, `{b}`, `{a}`, `Dif Q`, `%Q`, `$ {b}`, `$ {a}`, `Dif $`, `%$`,
  `$Prom {b}`, `$Prom {a}`, `Tdas {b}`, `Tdas {a}`, `≠Tdas`.
- **Periodos** (11 cols): `Periodo`, `Cant {b}`, `%{b}`, `Cant {a}`, `%{a}`, `Var% Q`,
  `$ {b}`, `%{b}`, `$ {a}`, `%{a}`, `Var% $`.

`{a}` = `data.anio`, `{b}` = `anio-1`. Valores derivados como **número**:
- `Dif Q` = `ups_act - ups_ant`; `Dif $` = `val_act - val_ant`; `≠Tdas` = `tiendas_act - tiendas_ant`.
- `%Q`/`%$`/`%Prom`/`Var%` = `(act-ant)/ant*100` (número; vacío si `ant==0`).
- `$Prom` = `val/ups` (0 si `ups==0`). `MB` = `margen` (número en %).
- `%Part` (Periodos) = `val/total*100`.

Las celdas de **texto** (la primera columna `label`/`Mes`/`Periodo`) van como string; el resto
como número. Celdas sin valor calculable (p. ej. `%` con `ant==0`) van vacías (`null`/`''`).

## Filas incluidas

- **Siempre todas**: cada padre seguido de **todos sus hijos** (label del hijo con sangría
  `'   '` por prefijo para distinguir nivel), aunque en pantalla estén colapsados.
- **Fila Total** al final (igual que en pantalla). `Tdas` del Total = **distinct real**
  (Grupo/Marca → `kpis.tiendas_actual/anterior`; Negocio/Cat/Género → `<x>.total`;
  Mensual → `mensual_tdas`). Para Grupo, aunque en pantalla el Total muestra `—` en Tdas, en
  el Excel se exporta el distinct real (más útil). `MB` del Total = promedio simple de los
  `margen>0` de los padres. `$Prom` del Total = `Σval/Σups`.
- Periodos: se incluyen los 4 niveles (Semestre, Trimestre, Mes, Día) aplanados con sangría
  por nivel, + fila TOTAL.

## Implementación (`informes/g00.php` + `dashboard.php`)

1. **SheetJS CDN** (`dashboard.php`, en el `<head>` con los otros CDNs):
   `<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>`.
2. **Stash del último data** (`informes/g00.php`): variables de módulo `lastDetal`,
   `lastTiendas`, `lastPeriodos`, `lastProductos`, asignadas al inicio del `.then(data=>...)`
   de `loadDetal`/`loadTiendas`/`loadPeriodos`/`loadProductos` (antes o junto al render).
3. **Botón por tabla**: en cada uno de los 8 `card-title`, un
   `<button class="g00-btn-export" onclick="g00Exp<Tabla>()">⤓ Excel</button>`. CSS
   `.g00-btn-export` discreto (acorde al informe). El botón vive en el markup estático del
   card (no dentro del `<table>` que se re-renderiza).
4. **Helpers JS**:
   - `exportAOA(filename, sheetName, header, aoaRows)`: `XLSX.utils.aoa_to_sheet([header,
     ...aoaRows])` → `book_new` → `book_append_sheet` → `XLSX.writeFile`.
   - `comparativaAOA(rows, {full, total, anio})`: arma `[header, ...filas]` para las 6 tablas
     comparativas (padre → hijos con sangría → Total). `full=false` para Tienda (12 cols).
   - `mensualAOA(rows, tdas, anio)` y `periodosAOA(dias, anio)`: builders específicos.
   - 8 funciones `g00Exp<Tabla>()` que: validan que el `last*` exista (si no, Swal info
     "Nada que exportar"), construyen el AOA con el builder correcto y llaman `exportAOA`.
   - Nombre de archivo: `'G00_'+<tabla>+'_'+(proveedorActual||'')+'_'+new Date().toISOString().slice(0,10)+'.xlsx'`
     (sanitizar el proveedor: reemplazar caracteres no `[A-Za-z0-9]` por `_`).
   - Nombre de hoja ≤ 31 chars (límite de Excel).

No hay cambios de backend ni de payload.

## Verificación

- `php -l informes/g00.php` y `php -l dashboard.php` limpios.
- Regresión backend sin cambios (`g00_productos_test.php` verde).
- **E2E navegador (Rafael):** en cada una de las 8 tablas, el botón descarga un `.xlsx` que
  abre en Excel con: números reales (no texto), padres + hijos (todos), fila Total, columnas
  iguales a la pantalla; el nombre del archivo correcto; tab no cargado → aviso "Nada que
  exportar".

## Fuera de alcance

- Sin formato condicional/estilos de celda en el Excel (solo valores).
- Sin export agregado por pestaña (es uno por tabla).
- Sin cambios a O14 (su export CSV sigue igual).
- Mixed-content/caveats de despliegue: no aplica (SheetJS por CDN https, generación local).
