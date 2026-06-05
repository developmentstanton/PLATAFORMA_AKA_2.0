# G00 Detal — Columnas TDAS y precio promedio en Marca/Tipo y Mensual

**Fecha:** 2026-06-05
**Informe:** G00 — Dashboard de Ventas, pestaña **Detal**
**Rama:** `feat/g00-tiendas-resumen` (acumula los rediseños de pestañas de G00)

## Objetivo

Enriquecer dos tablas de la pestaña Detal con columnas de **tiendas (TDAS)** y **precio
promedio** por año, replicando el patrón que ya existe en las tablas "Por Grupo" y
"Detalle Tiendas".

## Contexto

- La tabla **Por Grupo** (`renderTablaGrupo`/`rowGrupo`) ya muestra el bloque
  `$Prom {b}` · `$Prom {a}` · `%Prom` · `Tdas {b}` · `Tdas {a}` · `≠Tdas`. Es la
  referencia de estilo. `≠Tdas` = diferencia **absoluta** (`Tdas act − Tdas ant`),
  coloreada verde/rojo con signo.
- El endpoint `api/informe_g00.php` (tab Detal) construye `por_marca[]` (con
  `children[]` = tipos) vía GROUPING SETS. Cada fila **ya incluye** `tiendas_act` y
  `tiendas_ant` (`COUNT(DISTINCT BODEGA)` por grouping set). → para Marca/Tipo NO se
  requiere cambio de backend.
- El bloque **Mensual** es una query aparte (`$sqlMensual`, CTE `vm`) que agrupa por
  mes e **independiente del filtro de fecha** (siempre Ene→hoy, año actual vs anterior
  según Calendario diaadia/retail). Hoy NO cuenta tiendas por mes ni manda el nombre
  de mes completo (`$labelsMes` = `Ene`, `Feb`…).

## Cambios

### 1. Tabla "Resumen Ventas Por Marca / Tipo" — solo frontend (`informes/g00.php`)

`renderTablaMarcaTipo` / `rowMarca`: agregar al final, después de `$Prom {a}`, el bloque
que la deja **idéntica a Por Grupo**:

- `%Prom` — variación del precio promedio (`pctCell(promAct, promAnt)`).
- `Tdas {b}` · `Tdas {a}` · `≠Tdas` — conteo de tiendas por año + diferencia absoluta.

Reglas:
- Las 3 columnas TDAS se muestran tanto en filas **marca** como en filas hijas **tipo**
  (cada una trae su propio `tiendas_act`/`tiendas_ant` distinct, válido por sí mismo).
- **Fila Total:** el conteo de tiendas distintas NO es sumable. La fila Total toma el
  total real de tiendas distintas desde los KPIs de cabecera
  (`data.kpis.tiendas_anterior` / `tiendas_actual`) y calcula `≠Tdas` con esos. `%Prom`
  del total usa el precio promedio agregado del total (val_total/ups_total).
- Para acceder a los KPIs, `renderTablaMarcaTipo` recibe un argumento extra con los
  totales de tiendas (se pasa desde el dispatcher que ya tiene `data.kpis`).

Cabecera final de la tabla:
```
Marca / Tipo | {b} | {a} | Dif Q | %Q | ${b} | ${a} | Dif$ | %$ | MB | $Prom{b} | $Prom{a} | %Prom | Tdas{b} | Tdas{a} | ≠Tdas
```

### 2. Tabla "Resumen Ventas Mensual" — backend + frontend

**Backend (`api/informe_g00.php`, bloque Mensual):**
- CTE `vm`: incluir `v.BODEGA`.
- SELECT exterior: agregar
  `COUNT(DISTINCT CASE WHEN FECHA BETWEEN <act> THEN BODEGA END) AS tiendas_act` y el
  equivalente `tiendas_ant`. Los 2 nuevos pares de params van **después** de
  `ups_act/ups_ant` en `$pMensual` (respetar el orden; no reintroducir el bug 8120).
- `GROUP BY GROUPING SETS ((mes),())` para obtener, en la misma query, el **total
  distinct** del rango Ene→hoy (fila con `mes` NULL). El `GROUP BY` sigue sin params
  (usa el alias `mes` del CTE). val/ups del total se ignoran (el front los suma); solo
  se usa el total de tiendas distintas.
- `$labelsMes`: nombres completos (`Enero`, `Febrero`, … `Diciembre`).
- `serieMensual[]`: agregar `tiendas_act` y `tiendas_ant` por mes. Exponer el total
  distinct del rango en una clave nueva del payload, p.ej.
  `mensual_tdas => ['act'=>N, 'ant'=>N]`.

**Frontend (`informes/g00.php`, `renderTablaMensual`/`rowMensual`):**
- Agregar, después del bloque `$ … Dif$ %$`:
  - `$Prom {b}` · `$Prom {a}` — precio promedio = `prom(val, ups)` por mes.
  - `Tdas {b}` · `Tdas {a}` · `≠Tdas`.
- **Fila Total:** `$Prom` desde val/ups totales sumados; `Tdas` desde `mensual_tdas`
  (total distinct del rango); `≠Tdas` con esos dos.

Cabecera final:
```
Mes | {b} | {a} | Dif Q | %Q | ${b} | ${a} | Dif$ | %$ | $Prom{b} | $Prom{a} | Tdas{b} | Tdas{a} | ≠Tdas
```
(La Mensual no lleva `MB` ni `%Prom` — Rafael pidió solo precio promedio 2 años + tiendas.)

## Verificación

- **Test backend** (`tests/g00_detal_test.php` o nuevo): contra el endpoint real
  (BELTRANY SAS), confirmar que el Mensual devuelve `tiendas_act`/`tiendas_ant` por mes
  y `mensual_tdas`, y la invariante **`mensual_tdas.act ≤ Σ tiendas_act por mes`**
  (distinct global ≤ suma de distinct por mes). Nota: BELTRANY no tiene 2025 → columnas
  `{b}` en 0/—, esperado.
- **`php -l`** limpio en ambos archivos.
- **E2E navegador (Rafael):** ambas tablas con las columnas nuevas, totales correctos,
  nombres de mes completos, diferencias coloreadas.

## Fuera de alcance

- No se tocan las tablas Por Grupo ni Detalle Tiendas (ya tienen el patrón).
- No se toca la fila Total de Por Grupo (sigue mostrando `—` en TDAS; no fue pedido).
- No se toca el caché ni la estructura de `#refs`.
