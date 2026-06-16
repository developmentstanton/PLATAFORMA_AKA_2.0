# O45 — Stock por cortes históricos + #tiendas histórico

**Fecha:** 2026-06-11
**Informe:** O45 (Índice de Ventas) — **solo este informe**
**Estado:** diseño aprobado, pendiente plan de implementación

## Problema

Hoy O45 mide el inventario como **foto del día de hoy** (`inv_actual_PBI` + `_hold_actual_PBI`); las fechas del filtro solo afectan ventas. Consecuencia: una tienda que tuvo inventario de una referencia **durante** el rango pero hoy está en 0 (y sin ventas en el rango) **no aparece** ni en el Stock ni en el `#tiendas`.

Caso real (ref `555006144-GBE`, rango 2026-01-01 → 2026-06-10): las tiendas **I01** (AKA Unicentro Cúcuta) y **R90** (AKA CC Viva Envigado) tuvieron 8 uds en los cortes de 31-ene y 28-feb, pero hoy 0 y sin ventas → quedaban fuera del `#tiendas`. Deben contar.

## Fuentes de datos (4 tablas)

El Stock pasa a ser la acumulación de una **serie de cortes** sobre 4 tablas:

| Concepto | Histórico (cortes fin de mes) | Vivo (corte "hoy") |
|----------|-------------------------------|--------------------|
| Disponible | `historico_inventarios_PBI` | `inv_actual_PBI` |
| Hold | `historico_hold_PBI` | `_hold_actual_PBI` |

Características verificadas:
- `historico_inventarios_PBI`: cortes **fin de mes**, 2019-01-31 → 2026-05-31 (mensual). `COLUMNA1` mismo esquema que la viva (`'400'`, `'INV1430'`, `'INV1435'`, …). Incluye `CIA='001'` (a excluir) y CEDI.
- `historico_hold_PBI`: cortes fin de mes (2026-01-31 → 2026-05-31). Columnas `BODEGA_SAL, BODEGA_ENT, REFERENCIA, COLOR, TALLA, CANTIDAD, COSTO, FECHA, CIA`.
- Vivo = `inv_actual_PBI` / `_hold_actual_PBI` = corte diario **fechado AYER**. Verificado: ambas traen `FECHA` con valor único = `2026-06-10` (= hoy − 1). Es el corte más reciente de la serie. Por eso el default `Hasta = ayer` apunta justo a la foto viva. La fecha de la foto se lee del dato (`FECHA` de la tabla viva), no se asume.

**Filtros heredados de la lógica viva** (se aplican igual a las tablas históricas):
- Disponible: `cia <> '001'` y `COLUMNA1 IN ('INV1430','INV1435','400')`.
- Hold: `cia <> '001'`, la bodega se toma de **`BODEGA_SAL`** (decisión O45 ya vigente; O14 usa `BODEGA_ENT`).
- Ambos cruzan por `INNER JOIN #refs` (refs del proveedor de la sesión).

## Definiciones

### Serie de cortes
Conjunto de fechas de corte = { fin de mes histórico … 2026-05-31, **foto viva (fechada ayer)** }. La foto viva es el corte más reciente; su fecha es la `FECHA` de las tablas vivas (= ayer).

### Stock mostrado (Stock Tienda, Stock CEDI, Total Stock)
Valor del **corte cuya fecha = `max(corte ≤ hasta)`**, siendo `fecha_viva` = `FECHA` de la foto viva (= ayer):
- Si `hasta ≥ fecha_viva` → foto viva (`inv_actual_PBI` + `_hold_actual_PBI`).
- Si no → el corte de **fin de mes más reciente ≤ hasta** (de las tablas históricas).

Ejemplos (hoy = 2026-06-11, foto viva fechada 2026-06-10):
- `hasta = 2026-06-10` (default ayer) → **foto viva** (el dato más reciente).
- `hasta = 2026-06-06` → corte **2026-05-31** (la foto viva, fechada 06-10, queda fuera del rango).
- `hasta = 2026-05-31` → corte **2026-05-31**.
- `hasta = 2026-04-15` → corte **2026-03-31**.

Stock CEDI / Stock Tienda / Total Stock se calculan **sobre ese único corte** (misma partición CEDI vs no-CEDI que hoy: CEDI = `bodega='CEDI'`; Tiendas = resto no-CEDI; disponible+hold).

### #tiendas
`DISTINCT cia-bodega` que cumpla **(A o B)** y no esté excluida por grupo:
- **A — inventario:** tuvo `disponible+hold > 0` en **algún corte dentro de [desde, hasta]** (cualquier fin de mes del rango; y la foto viva si `hoy ∈ [desde,hasta]`).
- **B — ventas:** `ventas <> 0` en el rango `[desde, hasta]`.
- **Exclusión:** `ISNULL(bo.GRUPO,'') NOT IN ('BODEGA','ADMINISTRATIVAS')` (todo el grupo BODEGA, incl. CEDI, y ADMINISTRATIVAS).

Aplica al `#tiendas` **por fila** (acotado al negocio de la fila) y al **TOTAL** (distinct global del informe).

### Índices (sin cambio de fórmula, cambia el insumo de stock)
- `ind_inventario = Total Stock (del corte elegido) ÷ ventas30`.
- `ventas30` = ventana `[hasta-29, hasta]` (igual que hoy).
- `ind_ventas_mes` = `(ventas / #tiendas) / (dias/30)` (igual que hoy; `#tiendas` ya con la nueva definición).

## Selección del corte en código

El corte se determina a partir de `hasta` y de la fecha de la foto viva:
- `fecha_viva` = `MAX(FECHA)` de `inv_actual_PBI` (= ayer; lectura barata, una vez por request). Alternativa: `date('Y-m-d', strtotime('-1 day'))` como proxy, pero se prefiere el dato real.
- Si `hasta >= fecha_viva` → modo **vivo**.
- Si no → `corte = fin de mes más reciente ≤ hasta` (último día del mes de `hasta` si `hasta` es fin de mes; si no, último día del mes anterior), calculado en PHP (evita el `MAX(FECHA)` ~1.7s sobre la tabla histórica).
- Borde ETL: si el fin de mes calculado aún no fue cargado en el histórico, el corte queda sin filas (Stock 0 ese día). Se asume que el ETL mantiene los cortes al día; no se añade lógica de fallback en esta versión (documentado como limitación conocida).

## Rendimiento (decisión: aceptar)

Medición (BELTRANY SAS, rango ene–jun):
- Stock de un corte (16 bodegas): ~0.9s.
- `#tiendas` en cualquier corte del rango: **~4.8s** (escaneo de ~15M filas).
- Carga total estimada del informe: **~6–8s**.

**Decisión (Rafael):** se **acepta** la latencia con el spinner existente ("Cargando"). No se agrega índice ni tabla pre-agregada en esta versión. `historico_inventarios_PBI` es tabla compartida de PBI y no tiene índice por REFERENCIA/FECHA; optimizar queda como mejora futura si la espera molesta.

## Alcance

- **Solo O45** (`api/informe_o45.php` y, si aplica, `informes/o45.php`).
- No se toca O14 ni otros informes.
- La partición CEDI/Tiendas y los grupos excluidos no cambian respecto a lo ya implementado.

## Pruebas

- Caso I01/R90 (ref `555006144-GBE`, rango ene→jun): ambas deben aparecer en `#tiendas`. (Verificado en investigación: tienen inventario>0 en cortes ene/feb.)
- Stock mostrado para `hasta=2026-06-06` y `hasta=2026-06-10` = corte 2026-05-31; para `hasta=hoy` = foto viva.
- Invariante: `#tiendas` ≥ (#tiendas solo-ventas) — la versión histórica nunca quita tiendas que ya contaban por ventas.
- Suite O45 existente (smoke, indices, filtro_tienda) sigue verde.
- Test nuevo: `#tiendas` incluye tienda con inventario en un corte del rango pero sin stock hoy ni ventas.
