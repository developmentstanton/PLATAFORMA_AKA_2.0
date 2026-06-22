# Diseño — Análisis de Pagos (informe nativo, vista proveedor)

**Fecha:** 2026-06-22
**Proyecto:** plataforma_20 (Portal de Aliados AKA 2.0)
**Origen:** réplica nativa del Power BI `P78.pbix` (DataModel XPress9, no legible directo;
M/DAX extraídos manualmente — ver `docs/analisis_pagos/`).

## 1. Objetivo y alcance

Nuevo informe **"Análisis de Pagos"** en la sección **PAGOS** del dashboard. Es la vista
personal del aliado: **"¿cuándo me pagan?"**. Cada proveedor ve SOLO sus pagos, filtrado por
su NIT de sesión.

**Fuera de alcance (YAGNI):** la página interna "Detalle Proveedor" del PBI (buscador de
proveedores) — redundante cuando el proveedor ya es quien entró. La matriz de tesorería con
todos los proveedores. Clasificaciones internas (Detalle Compañía / intercompañía) no se
muestran al aliado.

## 2. Arquitectura

Sigue el patrón de los informes existentes (G00/O14):

- **`informes/pagos.php`** — página (HTML + CSS + JS), incluida en `dashboard.php` como
  `<div class="page" id="page-informes-pagos">` con su `nav-item` bajo la sección PAGOS.
- **`api/informe_pagos.php`** — endpoint JSON: valida sesión, resuelve NIT, arma el SQL
  unificado filtrado por NIT, aplica filtros, devuelve filas + meses presentes.
- **`tests/pagos_*_test.php`** — pruebas del endpoint (estilo `tests/o14_*`, `tests/g00_*`).

Conexión: `conexion/conexion_stanton.php` (BD `stanton` en RDS SIESA) y/o
`conexion/conexion_integracion.php` (BD `Integracion`, donde viven las tablas `*_PBI`).

## 3. Identidad del proveedor (sesión → NIT)

**Decisión:** el NIT se toma SIEMPRE de la sesión, nunca de un parámetro del cliente.

- En el login (`index.php`), el mismo lookup que hoy resuelve `$_SESSION['proveedor']`
  (de `t202_mm_proveedores`, cía '7', `LIKE` sobre el usuario) se amplía para traer también
  el NIT (`f200_nit` vía `t200_mm_terceros`) y guardar `$_SESSION['nit']`.
- `api/informe_pagos.php` exige `$_SESSION['nit']`. Si falta → error claro
  (`{ok:false, error:"No se pudo resolver el NIT del proveedor"}`), nunca datos de todos.
- El filtro por NIT usa `RTRIM` (las tablas traen espacios al final).

## 4. Motor de datos (endpoint)

Tres CTEs filtradas por NIT desde el inicio, portando la lógica del PBI (ver
`docs/analisis_pagos/fuentes_powerbi_M.md`), unidas con `UNION ALL`:

| CTE | Fuente | Reglas clave | Base |
|---|---|---|---|
| `docs`  | `Integracion.dbo.Doc_Compra_PBI` | `Estado='Contabilizado'`, `CIA='Stanton S.A.S.'`, `Consignacion='No'`; condición de pago (días vcto) desde `t208/t202/t200`; fecha de pago = corrimiento a viernes | "Documentos Compra" |
| `flujo` | `Integracion.dbo.FLUJO_OC_PBI` | clase proveedor (''/`PROV.%`→SEMANA; QUINCENA/SEMANA/PUNTUAL); fecha corte + fecha pago según clase y día de semana | "Flujo Proyectado" |
| `antic` | `Integracion.dbo.Anticipos_PBI` | clase="PUNTUAL", Causado="SI", Moneda="COP", fecha pago = **próximo viernes** desde hoy | "Anticipos" |

Cada fila del `UNION ALL` expone: `Compañia`, `Fecha_Vencimiento`, `Documento`, `Nit`,
`Razon_Social`, `Clase_Proveedor`, `Tipo_CTA`, `Causado`, `Descripcion`, `Moneda`, `Valor`,
`En_Pesos`, `Fecha_Pago`, `Anio_Pago`, `Mes_Pago`, `Dias`, `Base`.

Derivaciones (en SQL):
- `En_Pesos` = `Moneda='COP' ? Valor : Valor * TRM(Moneda)` (TRM, ver §5).
- `Dias` = `DATEDIFF(day, Fecha_Vencimiento, CONVERT(date, GETDATE()))`.
- `Anio_Pago` = `YEAR(Fecha_Pago)`; `Mes_Pago` = nombre de mes en español.
- `Grupo_Ctas_Pagar` (opcional, vía catálogo GRUPO_CXP por `Tipo_CTA`) — disponible si se
  quiere usar como agrupador; no es columna de fila en la matriz principal.

> Nota: los `RELATED('Tipo Detalle Proveedor')` y `RELATED('Valor DIVISAS')` del modelo DAX
> se reproducen como JOINs (por NIT y por Moneda) dentro de cada CTE antes del UNION. El
> "Detalle Compañía" intercompañía NO se expone al aliado.

**Frescura de datos:** las tres tablas `*_PBI` se recrean a diario (las dos grandes ~01:09 AM
por un job nocturno; `Anticipos_PBI` ~06:39 AM, hoy dependiente del refresh del PBI). El
endpoint solo LEE (con `WITH (NOLOCK)`). ⚠️ Riesgo registrado: si se apaga el Power BI,
`Anticipos_PBI` deja de refrescarse → crear un job/endpoint propio (fuera de alcance de esta
primera entrega; ver §9).

## 5. TRM (divisas)

- **Cache diario en archivo** (patrón de cache de catálogos de G00): la primera carga del día
  consulta el API de divisas una vez, guarda `{USD, EUR, fecha}` en un JSON local; el resto
  del día lee del cache.
- **Fallback:** si el API falla, usa el último valor cacheado y marca la fecha de ese valor.
  El informe nunca se cae por un API externo caído.
- TRM solo aplica a documentos USD/EU; la mayoría del aliado serán COP.

## 6. Pivote y armado de la matriz

- El endpoint devuelve **filas crudas + lista de meses presentes** (no pivotea en SQL).
- El **pivote mensual (Año → Mes) se arma en JS** en el front, porque las columnas de mes son
  dinámicas (dependen de las fechas de pago del proveedor). Evita SQL dinámico.

## 7. UI (`informes/pagos.php`, estilo G00)

**Filtros (barra superior):**
1. `Causado` — dropdown (Todas [default] / SI / NO).
2. `Días Vto Flujo` — rango numérico (min / max) sobre `Dias`.
3. `Fecha` — rango de fechas (desde / hasta) sobre `Fecha_Vencimiento`.
4. `Proveedor` — fijo: muestra la razón social de la sesión, no editable.

**Tarjetas (resumen):** Total por pagar, Total vencido (Dias>0), Próximo pago (monto del
mes en curso). [Confirmar set final en implementación.]

**Matriz "Resumen Mensual Flujo de Egresos":**
- Filas: jerarquía `Fecha vencimiento` → (expandible a) `Documento`; columnas adjuntas
  `Días` y `Razón Social`. Orden por Fecha vencimiento ascendente.
- **Expandir** una fecha de vencimiento muestra los **documentos individuales** de esa fecha.
- Columnas: `Año Pago` (año) → `Mes` (nombre de mes) + subtotal `Total` por año.
- Última columna **Total general** (resaltada amarillo).
- Valores: SUMA de `En Pesos`; negativos en rojo (notas crédito/devoluciones).
- Botón "⤓ Excel" como en G00.
- Reutiliza el CSS de bordes divisores de 2px (`--g00-divider`) para separar secciones.

## 8. Manejo de errores

- Sesión sin `nit` → `{ok:false, error:"No se pudo resolver el NIT del proveedor"}`.
- Proveedor sin documentos → matriz vacía con mensaje "Sin datos".
- API TRM caído → fallback a último cache (informe sigue funcionando).
- Errores SQL → `{ok:false, error:...}` sin filtrar datos sensibles.

## 9. Pruebas (TDD, estilo o14/g00)

- Pivote mensual: suma por (Año, Mes) correcta vs filas crudas.
- Fila/columna Total = suma de la fila/columna.
- Filtro `Causado` (Todas/SI/NO).
- Filtro rango `Días` (min/max inclusive).
- Filtro rango `Fecha` sobre vencimiento.
- **Aislamiento por NIT:** un NIT no ve filas de otro (prueba de seguridad).
- `En_Pesos`: COP = Valor; USD/EU = Valor × TRM.
- Anticipos: fecha de pago = próximo viernes; Causado="SI".

## 10. Pendientes menores / futuro

- Confirmar columna `Mes Pago` del PBI (prob. `FORMAT([Fecha Pago],"MMMM")`) y medidas
  `Titulo 1`/`Titulo 2` (cosméticas) — no bloquean.
- Job propio para refrescar `Anticipos_PBI` si se decomisiona el Power BI.
- Posible evolución a vista SQL (`vw_flujo_egresos`) si la lógica se reutiliza en otros informes.

## 11. Referencias

- `docs/analisis_pagos/fuentes_powerbi_M.md` — consultas M/SQL y DAX del UNION.
- `docs/analisis_pagos/lookups_estaticos.json` — catálogos GRUPO_CXP y Tipo Detalle Proveedor.
- `docs/analisis_pagos/requisitos_ui.md` — tabla y filtros (capturas del PBI).
- Memoria: `project_plataforma20_analisis_pagos.md`.
