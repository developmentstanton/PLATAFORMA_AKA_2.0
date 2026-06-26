# Diseño — Estandarización de todas las descargas a "conjunto de datos"

**Fecha:** 2026-06-26
**Estado:** Aprobado (pendiente revisión del spec por Rafael)
**Rama:** `feature/exports-dataset`

## 1. Problema

Los botones de descarga (Excel) de los informes de `plataforma_20` tienen tres problemas:

1. **Algunos no descargan nada y sin avisar.** Los dos botones de Análisis de Pagos
   (`pgExport`, `pgGenExport`) hacen `if (!pgData) return;` / `if (!pgGenData) return;`
   silencioso: si el usuario da clic antes de que carguen los datos, no pasa nada y no hay
   mensaje.
2. **Nombres de archivo con códigos internos** (`G00_PorGrupo_…`, `O14_c_…`,
   `O45_indice_ventas.xlsx`, `Evolucion_Historica.xlsx`) que no significan nada para el aliado.
3. **Formato inconsistente.** El export de G00 "Ventas Por Periodos" mete toda la jerarquía de
   tiempo en **una sola columna "Periodo" con sangría** (`   Trimestre 1`) y los cuadros
   padre/hijo concatenan/indentan los dos niveles en una columna. El usuario quiere un
   **conjunto de datos plano** (una fila por registro, cada dimensión en su propia columna,
   métricas en columnas) listo para tabla dinámica en Excel — como en la imagen de referencia
   (que es justamente el export de Periodos rediseñado).

## 2. Objetivo

Unificar **todas** las descargas de informes para que:

- Generen un **dataset plano**: cada dimensión en su **propia columna**, valor del padre
  repetido hacia abajo, métricas en columnas.
- **Conserven las filas de subtotal y la fila "Total"** (decisión de Rafael: "no quites los
  totales, estándar"). En las filas agregadas, las columnas de nivel más profundo van vacías.
- Tengan **nombre legible**: `Cuadro - Proveedor - Fecha.xlsx`, sin códigos.
- **Nunca fallen en silencio**: sin datos → aviso claro; sin XLSX → error; con datos → siempre
  descargan.

**Fuera de alcance:** las tablas en pantalla NO cambian (solo cambia lo que se exporta). Las
descargas de archivo (`Archivos/codificacion.xlsx` estático y `api/documentos_descargar.php`
binario) no son exports de informe y quedan igual.

## 3. Regla del formato "dataset"

| Hoy | Dataset |
|---|---|
| Jerarquía en 1 columna con sangría (`   Trimestre 1`) | Una columna por nivel; el valor del padre se repite en cada fila |
| Padre/Hijo indentado en una columna | "Padre / Hijo" → 2 columnas (ej. `Tienda` \| `Negocio`) |
| Filas de subtotal y "Total" | **Se conservan** (Rafael); columnas profundas vacías en filas agregadas |

Importante: **conservar los totales** significa *no quitar* los que ya existen. NO implica
*agregar* totales a los exports que hoy son largos y no los tienen (O14 b/c, Evol, Pagos);
esos mantienen su forma.

## 4. Mapeo por cuadro

### G00 — `informes/g00.php` (el grueso del cambio de forma)

Builders `comparativaAOA` (6 cuadros), `mensualAOA`, `periodosAOA` se ajustan para emitir
columnas separadas. Métricas se conservan tal cual.

- **Periodos** (`periodosAOA`): la columna única `Periodo` se explota en
  `Semestre · Trimestre · Bimestre · Mes · Día`. **Bimestre = `Math.ceil(mes/2)`** calculado al
  vuelo (no toca backend; el árbol actual es Semestre→Trimestre→Mes→Día y Bimestre es un
  atributo derivado del mes en cada fila hoja). Etiquetas como en la imagen:
  `Semestre 1`, `Trim-1`, `Bim 1`, `Enero`, `Ene-02`. Cabeceras de métrica como en la imagen:
  `2025 · Part Q 2025 · 2026 · Part Q 2026 · % Q · $ 2025 · Part $ 2025 · $ 2026 · Part $ 2026 · % $`
  (años dinámicos). Se conservan los subtotales (Semestre/Trimestre/Mes) y el Total; en las
  filas agregadas las columnas más profundas quedan en blanco.
- **Grupo / Marca / Tienda / Negocio / Categoría / Género** (`comparativaAOA`): el `dim`
  "Padre / Hijo" se parte en 2 columnas. Fila padre: `[padre, '', métricas…]`; filas hijo:
  `[padre, hijo, métricas…]`; fila Total: `['Total', '', métricas…]`. Cuadros sin hijos
  (p.ej. Grupo) usan solo la 1ª columna y la 2ª va vacía.
- **Mensual** (`mensualAOA`): una sola dimensión (`Mes`). Sin cambio de forma; conserva Total.

### O14 — `informes/o14.php`

- **Tabs b/c** (`o14Export`): ya son dataset largo (`Negocio·Medida·Talla·Valor` /
  `Grupo·Almacén·Negocio·Medida·Talla·Valor`). Sin cambio de forma. Solo nombre de archivo +
  guard.
- **Recomendaciones** (`o14RecoExp`, 3 botones): hoy vuelcan el DOM. Pasan a construirse desde
  `lastReco` (datos `filas[].valores[medida][talla]` + `tallas`) como **matriz negocio×talla con
  fila TOTAL y columna Tot** (misma forma que en pantalla, pero desde el dato → robusta e
  independiente del estado colapsado). Conserva el TOTAL. Nombre + guard.

### O45 — `informes/o45.php`

- `o45Export`: pasa de volcar el DOM a construirse desde `__o45last`. Dataset plano: una fila
  por negocio con las 11 columnas de `COLS` (Negocio, Marca, Ventas (und), #tiendas, Índice de
  inventario, Stock CEDI, Stock Tiendas, Total Stock, Índice de Ventas mes, Tallas, Precio de
  Venta Detal) usando valores numéricos (no strings formateados). Conserva la fila TOTAL
  (`__o45last.total`). Nombre + guard.

### Evol — `informes/evol.php`

- `evolExport`: ya es dataset largo (`Negocio·Concepto·Mes·Valor`). Solo nombre + guard.

### Pagos — `informes/pagos.php`

- `pgExport` / `pgGenExport`: ya son dataset largo. Se **elimina el `return` silencioso** (→
  aviso claro vía el guard común) y se renombra el archivo. Forma intacta.

## 5. Nombres de archivo

Esquema: **`<Cuadro> - <Proveedor> - <Fecha>.xlsx`**.

- Proveedor: `proveedorActual` / `window.PROVEEDOR_ACTUAL` (o `razon_social` en Pagos); se
  conserva el nombre real (ej. `BELTRANY SAS`). Si está vacío, se omite ese segmento.
- Fecha: `YYYY-MM-DD` (hoy).
- Saneo: solo se reemplazan los caracteres **ilegales en nombres de archivo**
  (`/ \ : * ? " < > |`) por un espacio; se conservan espacios, acentos y mayúsculas.

Nombres legibles por cuadro:

| Cuadro | Nombre |
|---|---|
| G00 Grupo | `Ventas por Grupo de Tiendas` |
| G00 Marca/Tipo | `Ventas por Marca y Tipo` |
| G00 Mensual | `Ventas Mensuales` |
| G00 Tienda | `Ventas por Tienda` |
| G00 Periodos | `Ventas por Periodos` |
| G00 Negocio | `Ventas por Negocio` |
| G00 Categoría | `Ventas por Categoría` |
| G00 Género | `Ventas por Género` |
| O14 (b/c) | `Siembra Stock Ventas` |
| O14 Reco Sobrante | `Recomendación - Reubicación Sobrante` |
| O14 Reco Faltante | `Recomendación - Faltante` |
| O14 Reco Proveedor | `Recomendación - Solicitud a Proveedor` |
| O45 | `Índice de Ventas` |
| Evol | `Evolución Histórica` |
| Pagos flujo | `Resumen Proyectado de Pagos` |
| Pagos generados | `Pagos Generados` |

## 6. Robustez — guard común (sin fallos mudos)

Todo export pasa por dos helpers compartidos. Como cada `informes/*.php` corre su `<script>`
en el scope global dentro de `dashboard.php` (donde ya se carga XLSX en el `<head>`), los
helpers se definen una sola vez en `dashboard.php`:

```js
// Arma el nombre de archivo legible y saneado.
window.expFile = function (cuadro, proveedor) {
  const prov = (proveedor || window.PROVEEDOR_ACTUAL || '').trim();
  const fecha = new Date().toISOString().slice(0, 10);
  const limpio = s => String(s).replace(/[\/\\:*?"<>|]+/g, ' ').replace(/\s+/g, ' ').trim();
  return limpio(cuadro) + (prov ? ' - ' + limpio(prov) : '') + ' - ' + fecha + '.xlsx';
};

// Escribe el .xlsx. Devuelve false (con aviso) si no hay datos o no hay XLSX.
window.expDataset = function (cuadro, hoja, header, filas, proveedor) {
  if (typeof XLSX === 'undefined') {
    if (window.Swal) Swal.fire('Exportar', 'No se pudo cargar la librería de Excel.', 'error');
    return false;
  }
  if (!filas || !filas.length) {
    if (window.Swal) Swal.fire('Exportar', 'Aún no hay datos cargados. Carga el informe primero.', 'info');
    return false;
  }
  const ws = XLSX.utils.aoa_to_sheet([header, ...filas]);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, String(hoja).slice(0, 31));
  XLSX.writeFile(wb, window.expFile(cuadro, proveedor));
  return true;
};
```

Cada export de informe: arma su AOA plano (header + filas) y llama `window.expDataset(...)`.
Si el dato aún no existe (objeto `lastX`/`pgData`/`__o45last` nulo), llama igual con `filas`
vacío para que el guard muestre el aviso (en vez de `return` mudo). Se elimina el helper local
`exportAOA`/`expFilename` de g00.php (reemplazados por los globales).

## 7. Arquitectura

- **Nuevo:** bloque `<script>` con `window.expFile` + `window.expDataset` en `dashboard.php`,
  junto a la carga de XLSX.
- **Builders puros:** las transformaciones (explotar periodos, partir padre/hijo, aplanar O45,
  matriz reco) se escriben como funciones puras `(datos) -> {header, filas}` para poder
  probarlas aisladas.
- Sin cambios de backend ni de SQL. Sin cambios en el render de las tablas en pantalla.

## 8. Pruebas

El repo no tiene runner de JS (los exports anteriores se validaron por navegador). Estrategia:

- **Builders puras** extraídas para poder ejecutarlas con un mini-harness Node opcional
  (`node` sobre las funciones de transformación de periodos y padre/hijo, que son las de mayor
  riesgo). Verifican: columnas correctas, padre repetido, subtotales/Total presentes con
  columnas profundas vacías, Bimestre correcto.
- **E2E navegador** por Rafael en `localhost/plataforma_20`: descargar cada cuadro y verificar
  nombre de archivo, columnas separadas, totales presentes, y que ningún botón quede mudo.

## 9. Despliegue

Tras E2E: re-sync de los archivos de runtime tocados (`dashboard.php`, `informes/{g00,o14,o45,
evol,pagos}.php`) a `plataforma_20_produccion` (idénticos por hash + `php -l`) + commit/push.
Rafael copia luego la carpeta de producción al servidor (paso manual habitual).

## 10. Riesgos / notas

- **Saneo de nombres con acentos/espacios:** los navegadores aceptan espacios y acentos en
  `Content-Disposition`/`writeFile`; solo se filtran los caracteres ilegales de Windows.
- **Periodos sin Bimestre como nivel de agregación:** Bimestre y Trimestre no anidan, por eso
  Bimestre es un atributo derivado por fila hoja (no un subtotal). Las filas de subtotal de
  Semestre/Trimestre/Mes dejan Bimestre vacío. Consistente con la imagen.
- **O14 reco como matriz (no largo):** se mantiene la forma matriz negocio×talla porque
  conserva el TOTAL que pidió Rafael y es la vista establecida; el cambio es solo la fuente
  (dato en vez de DOM) + nombre + guard.
