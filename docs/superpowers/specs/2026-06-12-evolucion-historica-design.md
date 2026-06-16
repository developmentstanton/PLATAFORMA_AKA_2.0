# Evolución Histórica — Diseño

**Fecha:** 2026-06-12
**Estado:** Aprobado (brainstorming con Rafael)
**Reemplaza:** placeholder `#page-evolucion-historica` ("Módulo en desarrollo") del menú REPORTES.

## 1. Propósito y contexto de negocio

Informe del portal B2B `plataforma_20`. El proveedor logueado (el "tercero", ej. un proveedor surtido por **Cauchosol** / cía 007) ve, **mes a mes**, el comportamiento histórico de sus negocios en la red Stanton: cómo cerró el **inventario**, cuánto se **vendió**, y cuánto le **compró** la compañía al proveedor.

Filtrado implícito por proveedor vía `ITEMS.PROVEEDOR` → `#refs` (igual que G00/O14/O45; mismo patrón `api/lib_refs.php`).

Es **~70% reuso** de O45/O14: ventas (Detal+Acum), stock por cortes históricos (trabajo de O45 del 2026-06-11), #tiendas e índice de ventas-mes. Lo nuevo de fondo: la fila de **Compras** y el armado como **serie mensual** (matriz negocio×mes).

## 2. Forma del informe

Matriz ancha, una fila por **Negocio** (ref-color, con foto), con **6 medidas apiladas** por negocio. Columnas = cada **mes** del rango + columna **Total**. Réplica del Power BI que muestra Rafael (pantallazo de referencia 2026-06-12).

Columnas izquierdas congeladas: **Negocio** (verde) + **Foto**. Las columnas de mes scrollean horizontal.

### 2.1 Las 6 medidas (filas por negocio)

| # | Fila | Definición | Color | Total (col. derecha) |
|---|------|-----------|-------|------|
| 1 | **Compras Cauchosol** | Σ `CANT_NET` por negocio×mes. Puede ser negativa (devolución de compra). Label fijo "Compras Cauchosol" (solo Cauchosol le compra al proveedor). | — | Σ del rango |
| 2 | **Total Ventas** | Unidades vendidas por negocio×mes. (Se omite la fila "Detal": era idéntica a Total Ventas.) | verde | Σ del rango |
| 3 | **Stock** | Inventario de **cierre del mes**. | azul | — (es foto, no suma) |
| 4 | **Tiendas con Inv** | # distinct cía-bodega con stock>0 en el corte del mes. | — | — |
| 5 | **Meses de Inv** | `round(Stock_mes ÷ TotalVentas_mes)`. 0/blanco si ventas=0. | — | — |
| 6 | **Índice Ventas Detal Mes** | `(Ventas_mes ÷ Tiendas_mes) ÷ (díasMes ÷ 30)`. | naranja | — |

**Fórmulas derivadas y validadas** celda por celda contra el pantallazo (negocio DUNCANA-NEG):
- Meses de Inv: Mar `96÷45=2`, Ago `10÷1=10`, Jun-2026 `134÷3=45`. ✓
- Índice: Mar `(45÷17)÷(31÷30)=2.56`, Jun-2026 `(3÷10)÷(11÷30)=0.82`. ✓ (días reales del mes; parcial para el mes en curso).

### 2.2 Universo de negocios

Negocios con **compras O ventas O stock** en el rango (unión), acotado por:
- filtros de **referencia/SKU** del proveedor (podan `#refs`),
- filtros de **bodega** (grupo/tienda/etc.).

## 3. Eje de meses (columnas)

- Control **Año-Mes Desde / Hasta** en el topbar izquierdo (granularidad **mes**, no día).
- Columnas = cada mes de `[Desde, min(Hasta, mes_actual)]`. **El nº de columnas varía**; nunca pasa del mes actual (año en curso → hasta el mes de hoy; año pasado → todos sus meses).
- **Default:** `Desde = enero del año pasado`, `Hasta = mes actual` (ej. 2025-01 → 2026-06 ≈ 18 columnas), replicando el pantallazo.

## 4. Fuentes de datos (verificadas contra BD 2026-06-12)

Todas en `INTEGRACION.dbo`. Llave de negocio = `REFERENCIA-COLOR`; cía normalizada a 3 díg.

### 4.1 Compras (nuevo)
- **Año en curso:** `mov_inv_actual_PBI` (verificado: solo 2026, FECHA 2026-01-05 → hoy).
- **Años ≤ anterior:** `historico_mov_inv_PBI` (verificado: 2019–2025).
- Filtro: `TIPO_DOCTO IN ('DMC','DVC','EAC','EMC') AND cia<>'052'`. Medida = `SUM(CANT_NET)`. Columnas relevantes: `FECHA, CIA, BODEGA, REFERENCIA, COLOR, TALLA, CANT_NET, COSTO_NET, TIPO_DOCTO`.
- Split por año idéntico al de ventas: el histórico solo se une si el rango toca ≤ año anterior.

### 4.2 Ventas (reuso O45/G00)
- `Ventas_Detal_PBI` (2026) + `Ventas_Detal_Acum_PBI` (≤2025, ~2.7M sin índice; unir solo si el rango toca ≤2025).
- `SUM(CANTIDAD)` agrupado por `negocio, YEAR(FECHA), MONTH(FECHA)`.

### 4.3 Stock de cierre por mes (reuso O45, extendido a multi-corte)
- **Tabla de cortes:** `historico_inventarios_PBI` (verificado: snapshots **limpios a fin de mes** — FECHA = último día de cada mes, ~100K filas/mes) + `historico_hold_PBI` (mismo patrón fin-de-mes).
- **Filtros idénticos a O45:** disponible `rtrim(COLUMNA1) IN ('INV1430','INV1435','400') AND CIA<>'001'`, `SUM(CAST(CANTIDAD AS int))`; hold por `BODEGA_SAL`, `CIA<>'001'`. Stock = disponible + hold.
- **Multi-corte (diferencia vs O45):** O45 usa `FECHA = ?` (un corte); aquí `FECHA IN (<fines-de-mes pasados del rango>)` con `GROUP BY ..., FECHA` → **una sola pasada** devuelve todas las columnas-mes.
- **Mes en curso:** no tiene corte de cierre → usar **snapshot vivo** (`inv_actual_PBI` + `_hold_actual_PBI`, mismos filtros que O45 modo "vivo").
- **Tiendas con Inv** sale del mismo escaneo: `COUNT(DISTINCT cía-bodega con stock>0)` por mes.

## 5. Backend — `api/informe_evol.php` (nuevo)

- Reusa `lib_refs.php` (`#refs` del proveedor, esquema 9 cols + cache diaria) y el parser de filtros `getMulti` + mapas `$FILTROS_REF/SKU/BOD` (portados de O45).
- **3 escaneos, cada uno una sola pasada**, materializados en temp tables por (negocio, mes):
  1. `#stock` — cortes históricos (multi-FECHA) + vivo (mes en curso) → `{negocio, mes, stock, tiendas}`.
  2. `#ventas` — Detal+Acum → `{negocio, mes, ventas}`.
  3. `#compras` — actual+histórico → `{negocio, mes, compras}`.
  - Gotcha sqlsrv (ver memoria proyecto): `CREATE TABLE #tmp` sin parámetros, luego `INSERT … ?` (evita el error 208 por `sp_executesql`).
- **Meses de Inv e Índice se calculan en el servidor** (decisión Rafael) para que el Excel salga con los números ya resueltos.
- **Respuesta tidy (JSON):**
  ```
  {
    ok: true,
    proveedor: "<tercero>",
    meses: ["2025-01", ..., "2026-06"],     // orden cronológico
    negocios: [
      { negocio: "DUNCANA-NEG", referencia, color, foto: "<ref-color>",
        valores: {
          compras:   { "2025-03": -1, ... },
          ventas:    { "2025-03": 45, ... },
          stock:     { "2025-03": 96, ... },
          tiendas:   { "2025-03": 17, ... },
          mesesInv:  { "2025-03": 2,  ... },
          indice:    { "2025-03": 2.56, ... }
        },
        totales: { compras: 371, ventas: 237 }   // solo compras y ventas suman
      }, ...
    ]
  }
  ```
- Filtros (dimensión/bodega) aplican al universo de negocios; ventas/compras/stock comparten el mismo `#refs`/conjunto de bodegas. Endpoint `tab=filtros` para el catálogo de la cascada (reuso O45, cache diaria).
- Sin inyección: filtros parametrizados; meses-corte se pasan como parámetros.

## 6. Frontend — `informes/evol.php` (nuevo) + wiring en `dashboard.php`

- **Render:** matriz ancha estilo O14 (**tidy-data + pivote en cliente**, tabla HTML vanilla, sin deps). Negocio+Foto congeladas; meses scrollean (apoyado en `.main { min-width:0 }`, ya existente por O14).
- **Colores:** verde Total Ventas, azul Stock, naranja Índice (como el pantallazo).
- **Foto** por negocio: `http://bi.stanton.com.co:81/fotosPBI/{ref-color}.jpg` con fallback `.png` (igual que G00/O14/O45).
- **Topbar** estilo O45/G00 (`topbar--o14`): título centrado **"EVOLUCIÓN HISTÓRICA - {Tercero}"**, botón Actualizar + 🔔 + 🚪 a la derecha; control **Año-Mes Desde/Hasta** a la izquierda (inputs `month` o dos selects Año/Mes).
- **Filtros:** sección clonada de O45 (Tom Select + cascada combos/sku, grupo/tienda).
- **Excel:** botón ⤓ vía SheetJS reusando `tableToAOA` (expande colspan/rowspan, convierte enteros es-CO a número real — ya existe en O14/O45).
- **`dashboard.php`:** placeholder `#page-evolucion-historica` → página real; `showPage` con `evolOnEnter`/`topbarEvolRefresh`; entrada en `titles`; `evolOnEnter` reactiva topbar+filtros (idempotente).

## 7. Performance

`historico_inventarios_PBI` (~15M filas, sin índice) se escanea una vez por request para todos los cortes del rango (una pasada agrupando por fin-de-mes). Costo esperado en el orden de O45 (~6–10s con varias columnas); spinner existente. **Plan B documentado, no implementado:** materializar cortes mensuales por (negocio, bodega, mes) en tabla/caché si el tiempo molesta. Igual que O45, `WITH (NOLOCK)` y push-down de `FECHA`/`#refs` en cada CTE.

## 8. Testing

Tests PHP contra un proveedor real (estilo O45, runner `_endpoint_run_*`):
1. **Compras** cuadran por negocio×mes contra query directo a `mov_inv_actual_PBI`/`historico_mov_inv_PBI`.
2. **Stock** de un mes pasado = el valor que da O45 para ese corte (consistencia con la máquina reusada).
3. **Meses de Inv** e **Índice** = fórmula (incl. mes parcial con días reales; div/0 → 0/blanco).
4. Negocio **solo-compras** (sin ventas ni stock) aparece en el universo.
5. `php -l` limpio en `api/informe_evol.php`, `informes/evol.php`, `dashboard.php`.

**E2E navegador (Rafael):** matriz con 6 filas/negocio + Total; columnas Año-Mes según filtro (tope mes actual); colores; foto; filtros acotan; Excel con números; default ene-año-pasado → mes actual.

## 9. Fuera de alcance (YAGNI)

- Materialización de cortes (plan B §7).
- Otras compañías compradoras (solo Cauchosol compra al proveedor).
- Fila "Detal" (omitida, == Total Ventas).
- Valor en $ de compras/ventas (la vista es en unidades; `COSTO_NET`/`VALOR` disponibles si se pide después).

## 10. Decisiones abiertas (cerrar en el plan)

- Control Año-Mes: `<input type="month">` nativo vs dos `<select>` Año/Mes (definir en el plan según soporte/estética; el nativo es más simple).
- Si el universo combina compras+ventas+stock con negocios sin foto, mostrar placeholder de imagen (reuso fallback existente).
