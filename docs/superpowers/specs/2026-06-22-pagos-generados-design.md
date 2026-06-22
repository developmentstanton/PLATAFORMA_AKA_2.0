# Diseño — "Resumen de Pagos Generados" (segunda tabla de Análisis de Pagos)

**Fecha:** 2026-06-22
**Proyecto:** plataforma_20 (Portal de Aliados AKA 2.0)
**Contexto:** segunda tabla en la página `informes/pagos.php` (informe Análisis de Pagos),
debajo de la matriz "Resumen Mensual Flujo de Egresos" ya existente.

## 1. Objetivo y alcance

Tabla **"Resumen de Pagos Generados"**: árbol desplegable **Año → Mes → Día** de los pagos
generados al proveedor, con VALOR TOTAL y DÍAS VENCIDOS por periodo. Vista por proveedor
(filtra por `$_SESSION['nit']`). Replica/unifica las dos tablas del PBI (Resumen de Pagos
Generados + Resumen Mensual de Pagos) en una sola jerárquica.

**Fuera de alcance (YAGNI):** nivel de detalle por documento bajo el día (Rafael pidió hasta
día); filtros propios (comparte el rango Fecha de la barra superior).

## 2. Datos

Fuentes (BD `Integracion`, estructura idéntica; ambas con `NIT`):
- `PAGOS_FECHA_VCTO_PBI` (~22k filas, pagos recientes)
- `HIST_PAGOS_FECHA_VCTO_PBI` (~121k filas, históricos)

Columnas usadas: `FECHA` (fecha del pago generado), `FECHA_VCTO` (vencimiento), `VALOR_NETO`, `NIT`.

`UNION ALL` de ambas, filtrado por `RTRIM(NIT) = ?`. Agregación por día en SQL (`WITH (NOLOCK)`):
```sql
SELECT CONVERT(date, FECHA) dia,
       SUM(VALOR_NETO) valor,
       COUNT(*) n,
       SUM(DATEDIFF(day, FECHA_VCTO, FECHA)) sumdias
FROM (
    SELECT FECHA, FECHA_VCTO, VALOR_NETO, NIT FROM Integracion.dbo.PAGOS_FECHA_VCTO_PBI WITH (NOLOCK)
    UNION ALL
    SELECT FECHA, FECHA_VCTO, VALOR_NETO, NIT FROM Integracion.dbo.HIST_PAGOS_FECHA_VCTO_PBI WITH (NOLOCK)
) u
WHERE RTRIM(NIT) = ?
  [AND CONVERT(date,FECHA) BETWEEN ? AND ?]   -- si viene rango Fecha
GROUP BY CONVERT(date, FECHA)
ORDER BY dia;
```

**Métricas por nivel** (roll-up en PHP desde los agregados por día):
- `VALOR TOTAL` = suma de `valor`.
- `DÍAS VENCIDOS` = `SUM(sumdias) / SUM(n)` del nivel (promedio simple de días de mora por
  documento). El roll-up mantiene el promedio correcto sumando `sumdias` y `n`, no promediando
  promedios. **A confirmar contra PBI:** si allí es promedio ponderado por valor, ajustar.

## 3. Backend

Endpoint dedicado **`api/informe_pagos_generados.php`** (responsabilidad única):
- Valida `$_SESSION['usuario']` y `$_SESSION['nit']` (igual que `informe_pagos.php`); sin NIT → `{ok:false}`.
- Reusa el patrón de conexión con reintento de `informe_pagos.php` (RDS rechaza conexiones rápidas).
- Params: `?fdesde=YYYY-MM-DD&fhasta=YYYY-MM-DD` (opcionales, sobre `FECHA`).
- Devuelve `{ok, nit, nodos:[...], total:{valor, dias}}` donde `nodos` es una lista plana de filas
  del árbol con `{id, pid, nivel, label, anio, mes, dia, valor, dias}` (nivel 1=Año, 2=Mes, 3=Día),
  ordenada para render directo (patrón del informe de Periodos del G00 / `rowPeriodo`).
  - `dias` por nodo = promedio (sumdias/n) del subconjunto del nodo.
  - El árbol se arma en PHP a partir de los agregados por día.

## 4. UI (`informes/pagos.php`, nueva tarjeta)

Debajo de la matriz de flujo, una `.card` con título "Resumen de Pagos Generados" + botón ⤓ Excel,
y `<table id="pg-gen-tabla" class="disp-table">`.

- Árbol desplegable Año→Mes→Día reutilizando el patrón de `g00TogglePeriodo`/`rowPeriodo`
  (filas con `data-rid`/`data-pid`/`data-lvl`, indentación por nivel, caret ▸/▾, hijos ocultos por defecto).
- Columnas: **FECHA** (etiqueta: año / nombre de mes / fecha completa) · **VALOR TOTAL** ($) · **DÍAS VENCIDOS** (2 decimales).
- Fila **Total** (amarilla) con valor total y días promedio global.
- Años colapsados al inicio.
- Carga junto con la matriz en `pgLoad()` (segunda llamada `fetch` al endpoint de generados),
  pasando el rango Fecha actual; función `pgGenRender(d)` dibuja el árbol y `pgGenExport()` el Excel.
- "Sin datos" si el proveedor no tiene pagos generados.

## 5. Manejo de errores
- Sin NIT → `{ok:false,...}`. API falla → mensaje con `Swal`, sin tumbar la otra tabla.
- Errores SQL → `error_log` + `'Consulta fallida'` genérico (no filtrar SQL al cliente).

## 6. Pruebas (TDD)
- Estructura del árbol: cada Año tiene Meses, cada Mes tiene Días; `pid`/`nivel` coherentes.
- Roll-up: `VALOR TOTAL` de un Año = suma de sus Meses = suma de sus Días.
- `DÍAS VENCIDOS` de un nivel = SUM(sumdias)/SUM(n) (no promedio de promedios).
- Total general = suma de años.
- Aislamiento por NIT (un NIT no ve otro).
- Filtro rango Fecha acota los nodos.

## 7. Referencias
- Patrón de árbol: `informes/g00.php` (`renderTablaPeriodos`, `rowPeriodo`, `g00TogglePeriodo`).
- Endpoint base y conexión con reintento: `api/informe_pagos.php`.
- Imagen de referencia: `Desktop/p78/WhatsApp Image 2026-06-22 at 4.02.02 PM.jpeg`.
