# O14 — Pestaña Recomendaciones general (por filtros)

**Fecha:** 2026-06-09
**Rama:** `feat/o14-topbar-g00-style`
**Archivos:** `api/informe_o14.php` (tab=reco), `informes/o14.php` (renderReco + indicador), `tests/`

## Contexto

Hoy la pestaña "Recomendaciones" exige **elegir un negocio** y muestra, para ese negocio, tablas origen→destino (reubicación / CEDI / proveedor) por cía. Rafael quiere que sean **generales según los filtros activos** (todos los negocios de la vista) y presentadas como **matrices negocio×talla** (estilo O14), no como tablas origen→destino.

El motor de cascada `api/o14_recomendador.php` (`recomendar($tiendas,$cedi,$policy)` → reubicaciones/solicitudes_cedi/solicitudes_proveedor, 27 tests) **no cambia**; se reutiliza para calcular el residual a proveedor.

## Alcance y semántica

- La pestaña corre el motor para **todos los negocios de la vista filtrada**; **ignora el selector de Negocio** (siempre general).
- **Filtros que aplican a reco:** los de **producto/SKU** (marca, tipo, categoría, subcategoría, género, público, referencia, color, talla) → acotan qué negocios/tallas se recomiendan. Los de **bodega** (grupo, tienda, centro comercial, depto, ciudad) **NO** acotan la cascada: el motor usa **toda la red de tiendas del negocio + CEDI** ("todos los sobrantes de las tiendas"). El CEDI siempre presente.
- Reco **no usa ventas** → no escanea `Ventas_Detal_Acum_PBI` (rápida).
- **Tres matrices** negocio×talla, cada una con **fila TOTAL arriba**, columna **Tot** a la derecha, y botón **⤓ Excel**:
  1. **Sobrante (reubicable)** = Σ sobre tiendas (no-CEDI) de `max(0,(disponible+hold)−siembra)` por (negocio,talla).
  2. **Faltante** = Σ sobre tiendas (no-CEDI) de `max(0,siembra−(disponible+hold))` por (negocio,talla).
  3. **Solicitud a proveedor** = `solicitudes_proveedor` del motor por (negocio,talla) = el faltante que queda tras reubicar sobrantes entre tiendas y descontar lo que surte el CEDI ("lo que le hace falta al CEDI").
- **Indicador de actividad pendiente:** punto pulsante en la pestaña "Recomendaciones" cuando en la vista filtrada hay sobrante o faltante (>0). Se deriva de los KPIs ya cargados (`kpi.sobrantes>0 || kpi.faltante>0`), sin fetch adicional.
- **Diferido (sin cambio):** botón "Generar solicitud" (persistencia, sub-proyecto 3) — no aplica aquí.

## Backend — `api/informe_o14.php`

### Gating de filtros (cambio puntual)
- **Filtros de referencia** (poda `#refs`): el gate `if ($tab === 'b' || $tab === 'c')` pasa a `if ($tab === 'b' || $tab === 'c' || $tab === 'reco')`.
- **Filtros color/talla** (DELETE sobre `#base`): incluir `'reco'` en su gate.
- **Filtros de bodega** (DELETE vía Bodegas): **se mantienen SOLO en `b`/`c`** (NO reco). Así la cascada conserva toda la red + CEDI.
- (La vieja colisión `color[]` filtro vs `color=` target ya no aplica: el reco general no recibe `ref`/`color` target.)

### Bloque `if ($tab === 'reco')` reescrito
Ya no exige `ref`/`color`. Consulta `#base` al grano (cia, bodega, negocio, referencia, color, talla, siembra, disponible, hold) y:
1. Agrupa por **(cia, negocio)**: separa `tiendas` (bodega≠'CEDI', con sus tallas {siembra,disponible,hold}) y `cedi` (bodega='CEDI', disponible por talla).
2. Por cada (cia, negocio):
   - `sobrante[talla] += max(0,(disp+hold)−siembra)` y `faltante[talla] += max(0,siembra−(disp+hold))` sobre las tiendas (no-CEDI).
   - Corre `recomendar(array_values($tiendas), $cedi)` y suma `solicitudes_proveedor` → `proveedor[talla]`.
   - Acumula en `$negs[negocio]` (mismo negocio puede venir de varias cías → se suma): `referencia`, `color`, y `valores['sobrante'|'faltante'|'proveedor'][talla]`.
3. Arma respuesta tidy: `filas` (una por negocio, ordenadas por negocio), `tallas` (unión ordenada), `medidas=['sobrante','faltante','proveedor']`. Solo incluir negocios con al menos un valor >0 en alguna de las 3 medidas.

Respuesta:
```json
{ "ok": true, "tab": "reco", "tallas": ["36","37",...],
  "medidas": ["sobrante","faltante","proveedor"],
  "filas": [ {"negocio":"434540BLU-AZC","referencia":"434540BLU","color":"AZC",
              "valores": {"sobrante":{"38":7}, "faltante":{"36":2}, "proveedor":{"36":1}}}, ... ] }
```

## Frontend — `informes/o14.php`

- **`loadReco`**: ya no exige `negocioSel.ref`. Hace `fetch(...buildParams('reco'))`, guarda el payload, llama `renderRecoMatrices(d)`. Quita el `showLoading` por-negocio si aplica (mantener un loading general).
- **`buildParams('reco')`**: ya no debe setear `ref`/`color` (quitar la rama `if(tab==='reco'){ p.set('ref',...); p.set('color',...); }`). Los filtros de producto/SKU ya se mandan por `FILTER_FIELDS`.
- **`renderRecoMatrices(data)`** (reemplaza `renderReco`): pinta **3 matrices** negocio×talla con `renderRecoMatriz(containerId, data, medida, label, expFnName)`:
  - Encabezado: `Negocio | <tallas…> | Tot`.
  - **Fila TOTAL arriba** (suma de todas las filas por talla), luego una fila por negocio (solo las que tienen algún valor>0 en esa medida; si ninguna, mostrar "Sin datos").
  - Botón **⤓ Excel** por matriz (reusar `tableToAOA` + SheetJS, como en el export actual).
  - Si no hay `filas`, mostrar "Sin recomendaciones para los filtros actuales."
- **Indicador**: añadir `<span id="o14-reco-dot">` en la pestaña "Recomendaciones". Función `setRecoIndicator(on)` que togglea una clase con animación de pulso (CSS `@keyframes`). Llamarla dentro de `renderKpis` con `(k.sobrantes>0 || k.faltante>0)`.
- El panel `#o14-panel-reco` pasa a contener 3 contenedores de matriz (ej. `#o14-reco-sobrante`, `#o14-reco-faltante`, `#o14-reco-proveedor`), cada uno con su título + botón Excel.

## Tests
- **Motor** `o14_recomendador.php`: 27/27 sin cambios.
- **Nuevo** `tests/o14_reco_general_test.php` (endpoint real, BELTRANY): `tab=reco` responde `ok` con `medidas=['sobrante','faltante','proveedor']` y `filas` no vacío; **invariante por negocio×talla:** `proveedor[t] <= faltante[t]` (el residual nunca excede el faltante); con `marca[]` el set de negocios ⊆ sin filtro; reco NO escanea Acum (corre rápido). Verifica que un filtro de bodega NO cambia el resultado de reco (cascada usa red completa).
- **Regresión:** `o14_filtros`, `o14_total_stock`, `o14_ventas_rango`, `o14_kpis_arbol`, `g00_detal_smoke`, `php -l`.
- **E2E navegador (Rafael):** abrir Recomendaciones sin elegir negocio → 3 matrices (Sobrante/Faltante/Proveedor) con TOTAL arriba; aplicar filtro de producto acota negocios; el punto pulsante aparece cuando hay sobrante/faltante; Excel por matriz baja .xlsx.

## Notas
- Mantener `$wantVentas = ($tab !== 'reco')` (reco sin ventas).
- El motor corre por (cía, negocio); un negocio en varias cías se suma en la matriz (display agregado), pero la cascada es intra-cía (cada cía su CEDI) — sin mezclar.
