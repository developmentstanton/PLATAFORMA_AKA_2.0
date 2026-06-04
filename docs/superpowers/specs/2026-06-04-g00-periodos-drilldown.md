# G00 — Pestaña "Ventas por Periodos": drill-down Semestre→Trimestre→Mes→Día

**Fecha:** 2026-06-04 · **Informe:** G00 · **Rama:** `feat/g00-tiendas-resumen`

## Objetivo
Reemplazar las 3 gráficas de la pestaña **Ventas por Periodos** (heatmap calendario, barras por día de semana, tendencia diaria) por una tabla **drill-down colapsable de 4 niveles**: Semestre → Trimestre → Mes → Día (`Enero-01` = mes-día), con columnas de cantidad y dinero comparando año actual (2026) vs anterior (2025).

## Columnas
Por cada fila (en cualquier nivel): **Cant 2025, %Part 2025, Cant 2026, %Part 2026, Var% (uds)**, **$ 2025, %Part$ 2025, $ 2026, %Part$ 2026, Var% ($)**.
- **Participación = nodo / TOTAL DEL AÑO** (mismo denominador en todos los niveles; la fila Total = 100%). Por separado para 2025 y 2026, en cantidad y dinero.
- **Var% = (act − ant) / ant** por fila (uds y $).
- Comparación **alineada por calendario**: 2025 = mismo mes-día del año anterior, independiente del toggle Calendario/Retail.

## Jerarquía
4 niveles colapsables, **colapsado por defecto** (solo semestres visibles); clic baja un nivel:
- **Semestre** (1 = meses 1–6, 2 = meses 7–12). Solo los presentes en el rango (YTD: S1, y S2 si ya pasó junio).
- **Trimestre** (T1=ene-mar, T2=abr-jun, T3=jul-sep, T4=oct-dic) dentro del semestre.
- **Mes** (Enero…) dentro del trimestre.
- **Día** etiquetado `Mes-DD` (ej. `Enero-01`) dentro del mes.
- Fila **Total** al final.

## Backend — `api/informe_g00.php`, `tab=periodos` reescrito
Una query agrupando por `(MONTH, DAY)` sobre el universo filtrado (#refs, `$filtroExtra`):
```sql
SELECT MONTH(FECHA) AS mes, DAY(FECHA) AS dia,
  SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
  SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
  SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
  SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant
FROM ventas v INNER JOIN #refs i ... LEFT JOIN Bodegas b ... (b.CIA=7)
WHERE 1=1 $filtroExtra
GROUP BY MONTH(FECHA), DAY(FECHA)
```
- `act` = `$desdeAct..$hastaAct`; `ant` = `$desdeAnt..$hastaAnt` (mismo periodo −1 año; se usa la alineación calendario, **no** retail, para esta tabla → calcular `antDesde/antHasta` = act −1 año explícitamente).
- Orden de params: `[gmin,gmax,gmin,gmax]` (CTE) + `[8 fechas: act,ant × val + act,ant × ups]` + `$paramsExtra`. (Sin reordenar; filtros por `$paramsExtra`.)
- PHP: saltar `(mes,dia)` con val_act==0 && val_ant==0 && ups ambos 0. Salida `{ok, anio, dias:[{mes,dia,val_act,val_ant,ups_act,ups_ant}]}`. Se eliminan `diario`/`por_dow`.

## Frontend — `informes/g00.php`
- Panel `#g00-panel-periodos`: quitar las 3 cards de gráficas; una card "Ventas Por Periodos" con `<table id="g00-tabla-periodos" class="disp-table">`.
- `loadPeriodos` reescrito: fetch → `buildArbolPeriodos(dias)` → `renderTablaPeriodos(arbol, anio)`.
- Árbol cliente: por cada día agrega a Mes→Trimestre→Semestre y al gran total (act y ant, uds y $). Etiquetas: `Semestre N`, `Trimestre N`, mes (`MESES_ES`), día (`MES-DD`).
- Tabla: filas con `data-pid` (id del padre) y `data-lvl`; colapsado por defecto (todo lo no-semestre `display:none`); toggle `g00TogglePeriodo(id, el)` que muestra hijos directos al expandir y oculta TODO el subárbol al colapsar (recursivo). Indentación por nivel + chevron en nodos con hijos. Participación = valor/gran total; Var% = (act−ant)/ant. Quitar `renderHeatmapCalendar`/`renderDow`/`renderTendencia` y sus charts (`calendar`, `dow`, `tendencia`).

## Testing
- `tests/g00_periodos_test.php` (nuevo, subprocesa endpoint real): `tab=periodos` da `ok=true`; `dias[]` no vacío; cada fila tiene mes∈1..12, dia∈1..31 y las 4 métricas; **invariante**: Σ val_act de `dias` > 0 y coincide (≈) con el KPI ventas_actual de Detal para los mismos filtros (sanity de totales). Permutación con filtro (`marca[]`/`color[]`) sigue `ok=true`.
- `php -l`. Navegador (Rafael): tabla drill-down 4 niveles, colapso/expansión, etiquetas `Enero-01`, participaciones y variación; gráficas ya no aparecen; otros tabs intactos.

## Decisiones / notas
- Participación contra el total del año (no contra el padre).
- Alineación 2025↔2026 por calendario (mes-día), no retail.
- Default colapsado; Total al final.
- Datos a grano (mes,dia) ≤ 366 filas → liviano.
