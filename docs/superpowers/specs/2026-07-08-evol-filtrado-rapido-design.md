# EVOL Filtrado Rápido (cache de #base denormalizado) — Design

**Fecha:** 2026-07-08
**Sub-proyecto:** 4º y último de la generalización del filtrado. Orden: o45 ✅ → G00 ✅ → o14 ✅ → **evol**.
**Enfoque:** B (cache en servidor), igual que G00 y o14.

## Problema

El informe EVOL (Evolución Histórica, `api/informe_evol.php`) es el más lento de los cuatro (medido 26-41s, con variación cold/warm de buffer). Cada request reconstruye `#base` (cruce de varias fuentes históricas, dominado por el scan de `historico_inventarios_PBI` ~19.6M filas) y agrega la matriz negocio×mes.

**Diagnóstico (perfilado, sesión simulada):**

| Proveedor | tab=data (default 18 meses) | payload |
|---|---|---|
| BELTRANY SAS (chico) | 40.8s cold / ~11s warm | 49 KB |
| BRAHMA CONCEPT (grande) | 25.9s | 1.3 MB |

- El **payload es chico** → el cuello NO es el fetch/pivot (a diferencia de o14 tab=c); es el **build de `#base`**.
- El costo **escala con el rango** (1 mes ~4s, 8 meses ~8s, 18 meses ~11s warm): el scan del histórico es proporcional a los meses.
- **BELTRANY (chico) más lento que BRAHMA (grande)** → inestabilidad de plan en el build (mismo síntoma que el materialize de o14).

## Meta

- Que **filtrar en EVOL sea instantáneo** (cache-hit: re-agregación sobre el cache, sin rebuild).
- Que la carga (1ª vez por rango) baje del build lento (~11-40s) a segundos vía materialize dos-pasos.
- UX: filtros auto-aplican con debounce.
- **Paridad EXACTA** con la salida actual.

## Enfoque: cache de `#base` denormalizado en INTEGRACION

Cachear el `#base` granular **con las dims pegadas** (de #refs y Bodegas) en una tabla persistente de INTEGRACION, por `(proveedor, rango-de-meses)`. tab=data agrega/pivota sobre el cache reusando el MISMO SQL. Filtros → WHERE sobre las columnas del cache. Mismo patrón que o14 (ver `2026-07-08-o14-filtrado-rapido-design.md`), reusando su playbook de concurrencia y el fix de materialize dos-pasos. **evol es el caso más limpio: payload chico, sin problema de árbol.**

## Global Constraints

- **PROHIBIDO modificar tablas SIESA** (`stanton.dbo.t***`) — solo SELECT. Estructura nueva en `INTEGRACION`.
- **Paridad EXACTA** con la salida actual de tab=data: la lógica de agregación/pivot NO cambia, solo su fuente (cache en vez de `#base`). Flag `?nocache=1` conserva el camino vivo como oráculo; el test de paridad lo blinda.
- **Denormalizar valores RAW:** dims (`grupo/nombre` de Bodegas, `marca/...` de #refs) tal cual salen del join, sin ISNULL en el materialize; cada consumidor re-aplica su ISNULL en lectura EXACTO como hoy.
- **ADMIN/CEDI:** el materialize excluye bodegas `GRUPO='ADMINISTRATIVAS'` (excepto `bodega='CEDI'`), replicando el DELETE actual (`informe_evol.php:147-153`). CEDI conservado.
- **Materialize en DOS PASOS** (build `#base` temp → INSERT al cache desde el temp + dims), para evitar el plan patológico del `INSERT...SELECT` único a tabla persistente (lección de o14).
- **Concurrencia (reusa g00/o14):** `sp_getapplock` Exclusive/Transaction (recurso `evolcache:<key>`) + double-check; freshness gate `READPAST`; reads del cache en READ COMMITTED (RCSI ON). Copiar `api/lib_o14_cache.php`.
- **`cache_key` = hash de `(proveedor, desdeMes, hastaMes)`**; los filtros NO entran en la key.
- `php -l` limpio; `sqlsrv_free_stmt` tras cada statement.

## Estructura de la tabla de cache

`CREATE TABLE INTEGRACION.dbo.evol_cache_base` — fila por `(cache_key, negocio, mes, cia, bodega, referencia, color)`:

- **Llave/medidas:** `cache_key VARCHAR(64)`, `negocio VARCHAR(120)`, `mes CHAR(7)`, `cia VARCHAR(10)`, `bodega VARCHAR(20)`, `referencia VARCHAR(50)`, `color VARCHAR(40)`, `ventas INT`, `compras INT`, `stock INT`.
- **Dims de #refs** (filtros REF): `marca, tipo, categoria, subcategoria, genero, publico_objetivo`.
- **Dims de Bodegas** (filtros BOD + exclusión ADMIN): `grupo, nombre`.
- `creado DATETIME2 DEFAULT SYSDATETIME()`. Índice **clustered en `cache_key`** (índice secundario se afina con medición).

## Cache key + TTL

- `evolCacheKey($proveedor, $desdeMes, $hastaMes)` = hash de los 3.
- `EVOL_CACHE_TTL_MIN` (config, p.ej. 120). **Ventaja de evol:** la mayoría del `#base` es histórico INMUTABLE (meses pasados: ventas/compras/stock-corte son snapshots fijos); **solo el stock del mes en curso** (inv_actual/_hold_actual) deriva. El TTL solo importa para la porción del mes actual. Frescura = `creado > DATEADD(minute,-TTL,SYSDATETIME())`. Limpieza oportunista.

## Materialize (dos pasos)

`ensureEvolCacheBase($conn, $key, $desdeMes, $hastaMes)`: si no hay cache fresco, con `sp_getapplock` + double-check:
- **PASO 1:** build `#evolmat` (tabla temp) reproduciendo TODOS los INSERTs de fuentes del endpoint (`informe_evol.php:65-145`): ventas (Detal+Acum), compras (mov_inv_actual + historico_mov_inv), stock cortes (historico_inventarios + historico_hold en `$cortesVals`), stock vivo mes actual (inv_actual + _hold_actual si `$incluyeMesActual`) — copiar EXACTO el rango/cortes derivados de `(desdeMes,hastaMes)`. `#refs` completo (sin podar). Excluir ADMIN (salvo CEDI).
- **PASO 2:** `INSERT INTO evol_cache_base SELECT <key>, b.*, r.<dims>, bo.<dims> FROM #evolmat b INNER JOIN #refs r ... LEFT JOIN Bodegas bo ...` (dims RAW, exclusión ADMIN ya aplicada en paso 1 o aquí — decidir en implementación, EXACTO al endpoint). Reutiliza la lógica de derivación de `$cortesVals`/`$incluyeMesActual`/`$desdeF`/`$hastaF` desde `(desdeMes,hastaMes)`.

## Lectura (tab=data, sobre el cache)

En modo cache, la query de tab=data agrega/pivota `FROM INTEGRACION.dbo.evol_cache_base c WHERE c.cache_key=? [+ filtros]` (READ COMMITTED, sin NOLOCK), reusando el mismo GROUP BY / pivot negocio×mes de hoy. `tab=filtros` se conserva (cache diario en archivo).

## Filtros en lectura

Los filtros REF/negocio/BOD que hoy **podan** `#refs`/`#base` (DELETE) pasan a **WHERE sobre columnas del cache**:
- REF: `c.marca IN (...)`, `c.tipo IN (...)`, ... `c.referencia IN (...)`.
- Negocio: `c.negocio IN (...)`.
- BOD: `(c.bodega='CEDI' OR ISNULL(c.<col>,'') IN (...))` para grupo/nombre(el filtro 'tienda' mapea a `nombre`), **conservando CEDI** igual que hoy (`:166-170`).

## Oráculo `?nocache=1`

Flag que corre el camino vivo actual (build `#base` + agregación de hoy). Oráculo del test de paridad. Fuente parametrizada (cache vs vivo), sin duplicar la lógica de agregación.

## Frontend (auto-aplicar)

En `informes/evol.php`: auto-aplicar con debounce (~400ms) en los filtros de dimensión, igual que g00/o14. Verificar el wiring real de filtros→carga antes. Cambios de rango de meses = manual (rebuild).

## Testing / paridad

- Golden `tab=data` (cache) vs `tab=data&nocache=1` (vivo) × proveedores (chico/grande + un 3º con datos, guarda de **no-vacuidad**) × filtros `[{}, {marca}, {grupo}, {negocio}]`. Comparación deep, 0 diffs.
- **Staleness:** comparar contra cache reciente para la porción del mes actual (histórico es inmutable); si un combo muestra drift solo en stock del mes en curso, re-materializar (como o14).
- Test concurrencia 2-procesos (como g00/o14). Medición antes/después.

## Puntos a confirmar con medición (implementación)

1. Índice secundario óptimo de `evol_cache_base` para el pivot negocio×mes.
2. Que el materialize dos-pasos evita la inestabilidad de plan (chico ≥ rápido que hoy).
3. Que la denormalización reproduce EXACTO las dims (grupo/nombre, cia 3-díg).

## Fuera de alcance

- Auto-aplicar en cambios de rango de meses (rebuild) — manual, como g00/o14.
- Optimización adicional si el cold-load grande persiste (follow-up).

## Referencias

- Patrón base: `docs/superpowers/specs/2026-07-08-o14-filtrado-rapido-design.md` (+ su fix de materialize dos-pasos).
- Endpoint actual: `api/informe_evol.php`; refs: `api/lib_refs.php`; concurrencia: `api/lib_o14_cache.php` / `api/lib_g00_cache.php`.
- Constraint SIESA: memoria `plataforma-20-prohibido-tocar-siesa`.
