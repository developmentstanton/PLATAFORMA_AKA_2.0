# G00 — Control S.S.S como toggle (default sin selección, excluyente)

**Fecha:** 2026-06-16
**Informe:** G00 (Ventas)
**Archivo:** `informes/g00.php` (solo frontend; sin backend)

## Problema

Hoy el control **S.S.S** (botones "No Same" / "Same") es un segmentado de selección única: siempre hay exactamente uno activo, y arranca con **No Same** resaltado (vía `initSeg('g00-sss')`). No se puede dejar ninguno activo.

Rafael quiere que arranque **sin selección** y que cada botón se pueda **prender/apagar** con clic, siendo **mutuamente excluyentes** (nunca los dos a la vez).

## Objetivo

- El control S.S.S arranca con **ninguno** activo.
- Clic en un botón inactivo → lo activa y **desactiva el otro**.
- Clic en el botón activo → lo **desactiva** (queda ninguno).
- Cada clic recarga el informe (igual que hoy).
- **Ninguno activo = todas las tiendas** (universo completo = same + no-same). En datos equivale a "No Same".
- **Calendario** (Día a Día / Retail) NO cambia: sigue siendo selección única.

## Diseño (solo `informes/g00.php`)

1. **HTML:** quitar la clase `active` del botón "No Same" (~línea 204) → el grupo arranca sin selección.
2. **JS:** manejador *toggle* dedicado para `#g00-sss`. Lógica por clic:
   - Si el botón ya tiene `active` → quitarle `active` (queda ninguno).
   - Si no → quitar `active` a todos los del grupo y ponérselo solo a él.
   - Luego `g00Load()`.
   `initSeg` (radio, siempre uno activo) se conserva tal cual para `#g00-cal`.
3. **`buildParams`:** ya envía `segValue('g00-sss') || 'nosame'`. Con ninguno activo, `segValue` devuelve `''` → manda `nosame` = todas las tiendas. **Sin cambios.**
4. **Backend:** sin cambios. `nosame` (o ausencia) = todas; `same` = filtro same-store (ya implementado).

## Estados y datos

| Estado del control | `sss` enviado | Datos |
|---|---|---|
| Ninguno (default) | `nosame` | Todas las tiendas |
| No Same activo | `nosame` | Todas las tiendas |
| Same activo | `same` | Solo same-store |

(No Same y "ninguno" producen los mismos datos; difieren solo en el resaltado visual.)

## Testing

Comportamiento puro de DOM (toggle visual). El proyecto no tiene arnés de pruebas JS, así que se verifica por **E2E en navegador**. El backend no cambia → la suite G00 (`g00_detal_smoke`, `g00_mensual`, `g00_productos`) y `g00_sss_test.php` siguen cubriendo que `sss=same`/`nosame` responden bien.

## Fuera de alcance

- Calendario (se mantiene selección única).
- Backend / definición de same-store.
- Otros informes.
