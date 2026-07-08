# O14 Filtrado Rápido (cache de #base denormalizado) — Design

**Fecha:** 2026-07-08
**Sub-proyecto:** 3º de la generalización del patrón de filtrado (tras o45 y G00). Orden: o45 ✅ → G00 ✅ → **o14** → evol.
**Enfoque:** B (cache en servidor), igual que G00 — NO cliente como o45 (el motor de reco no se porta a JS).

## Problema

El informe O14 (Siembra/Stock/Ventas, `api/informe_o14.php`) es lento al cargar y al filtrar. Cada request reconstruye `#base` (el cruce de las 4 fuentes) y re-agrega por tab. Medición (perfilado end-to-end, sesión simulada):

| Proveedor | tab=b | tab=c (árbol) | tab=reco |
|---|---|---|---|
| BELTRANY SAS (chico) | 2.8s | 4.2s | 2.2s |
| BRAHMA CONCEPT (grande) | 4.6s | **29.2s** | 8.4s |

**Diagnóstico por fase (BRAHMA tab=c, el peor caso):**

| Fase | ms |
|---|---|
| build `#refs` | 217 |
| build `#base` (4 fuentes) | 2 387 |
| **tab=c: agregación SQL** | **24 688** ⬅️ el cuello |
| ensamblar árbol (PHP) | 296 |
| json_encode (5.8 MB) | 28 |

El cuello **NO** es el ensamblado ni el payload (324 ms juntos): es la **query de agregación de tab=c (~24.7s)**. Causa raíz: `LEFT JOIN INTEGRACION.dbo.Bodegas bo ON bo.COD=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia` — el `RIGHT('000'+rtrim(...))` **no es sargable**, y `#base` es un heap sin índices → escanea Bodegas por fila + GROUP BY/ORDER BY enorme. (tab=b, que no une Bodegas, agrega en ~2s.)

## Meta

- Que **filtrar en O14 sea instantáneo** (cache-hit: re-agregación sobre el cache, sin rebuild).
- Que **tab=c baje de ~29s a segundos** eliminando el join no-sargable (dims denormalizadas en el cache).
- UX: filtros auto-aplican con debounce (como G00), sin depender del botón.
- **Paridad EXACTA** con la salida actual de cada tab.

## Enfoque: cache de `#base` denormalizado + indexado en INTEGRACION

Cachear el `#base` granular **con las dimensiones ya pegadas** (de #refs y Bodegas) en una tabla persistente indexada de INTEGRACION, por `(proveedor, rango-de-ventas)`. Las tabs b/c/reco agregan sobre el cache reusando el MISMO SQL de agregación (solo cambia el FROM y desaparece el join a Bodegas). Filtros → WHERE sobre las columnas del cache = re-agregación rápida.

Es el mismo patrón que G00 (ver `2026-07-06-g00-filtrado-rapido-design.md`), reusando el playbook de concurrencia probado ahí.

## Global Constraints

- **PROHIBIDO modificar tablas SIESA** (`stanton.dbo.t***`) — solo SELECT. Toda estructura nueva vive en `INTEGRACION`.
- **Paridad EXACTA** por tab (b/c/reco): el SQL de agregación no cambia su lógica, solo su fuente (cache en vez de `#base`⋈Bodegas). Un flag `?nocache=1` conserva el camino vivo como oráculo; el test de paridad lo blinda.
- **CEDI se conserva** en el cache (grupo `BODEGA`) — el motor de reco lo necesita. **ADMINISTRATIVAS se excluye** (o14 las excluye de todo).
- `php -l` limpio; `WITH (NOLOCK)` solo donde corresponde (ver Concurrencia); `sqlsrv_free_stmt` tras cada statement.

## Estructura de la tabla de cache

`CREATE TABLE INTEGRACION.dbo.o14_cache_base` — una fila por `(cache_key, cia, bodega, ref, color, talla)`:

- **Llave/medidas:** `cache_key VARCHAR(64)`, `cia`, `bodega`, `negocio` (ref-color), `referencia`, `color`, `talla`, `siembra INT`, `disponible INT`, `hold INT`, `ventas INT`.
- **Dims de #refs** (filtros REF): `marca`, `tipo`, `categoria`, `subcategoria`, `genero`, `publico_objetivo`.
- **Dims de Bodegas** (tab=c + filtros BOD + exclusión ADMIN): `grupo`, `nombre`, `centro_comercial`, `depto`, `ciudad`.
- `creado DATETIME2 DEFAULT SYSDATETIME()`.
- Índice **clustered en `cache_key`**. Índice secundario para las agregaciones de tab=c se afina con medición durante implementación.

## Cache key + TTL

- `o14CacheKey($proveedor, $desde, $hasta)` = hash de esos 3 (solo ventas depende del rango; siembra/disp/hold son foto actual y se re-materializan con la misma key).
- `O14_CACHE_TTL_MIN` (config, p.ej. 120). Frescura = `creado > DATEADD(minute, -TTL, SYSDATETIME())`. Limpieza oportunista (`o14CacheCleanup`).

## Materialize

`ensureO14CacheBase($conn, $key, $desde, $hasta)`: si no hay cache fresco, materializa. Reproduce el build actual del `#base` (`api/informe_o14.php`, CTEs `s/d/h/v` + `llaves` + INSERT) **más los joins a #refs (dims) y a Bodegas (dims)**, denormalizando a las columnas del contrato, **excluyendo ADMINISTRATIVAS** (como hoy) y **conservando CEDI**. Requiere `#refs` construido en la misma conexión (`buildRefsFromMat`). Copiar las condiciones ON y filtros EXACTOS del endpoint actual para no divergir.

## Lectura por tab (sobre el cache)

Cada tab agrega `FROM INTEGRACION.dbo.o14_cache_base c WHERE c.cache_key=? [+ filtros]`, reusando el SQL de hoy:

- **tab=b:** `GROUP BY cia,negocio,ref,color,talla` → `ensamblarTidy` (sin cambios).
- **tab=c:** `GROUP BY grupo,cia,bodega,nombre,negocio,ref,color,talla ORDER BY grupo,llave,negocio` → **sin `LEFT JOIN Bodegas`** (grupo/nombre son columnas) → `ensamblarArbol` (sin cambios). Aquí muere el cuello.
- **tab=reco:** agrega siembra/disponible/hold desde el cache; el motor `api/o14_recomendador.php` corre **sin cambios**. CEDI conservado.
- **tab=filtros:** se conserva como está (ya tiene cache diario en archivo).

## Filtros en lectura (el filtrado instantáneo)

Los filtros REF/SKU/BOD que hoy **podan** `#refs`/`#base` (DELETE antes de agregar) pasan a **WHERE sobre las columnas del cache**:
- REF: `marca/tipo/categoria/subcategoria/genero/publico/referencia` → `c.<col> IN (?,...)`.
- SKU: `color/talla` → `c.<col> IN (?,...)`.
- BOD: `grupo/tienda(nombre)/centro_comercial/depto/ciudad` → `c.<col> IN (?,...)`, **conservando el CEDI** (`c.bodega <> 'CEDI' AND ...`) igual que hoy para la cascada.

Cambiar un filtro = re-agregar sobre el cache (cache-hit) = instantáneo. Cambiar el rango de ventas (desde/hasta) = nueva `cache_key` → rebuild.

## Oráculo `?nocache=1`

Flag que corre el camino vivo actual (build `#base` + agregación de hoy con el join a Bodegas). Es el oráculo del test de paridad. Se implementa parametrizando la **fuente** de cada agregación (cache vs vivo), sin duplicar la lógica de agregación.

## Concurrencia (reusa el playbook de G00)

Nuevo `api/lib_o14_cache.php` que espeja `api/lib_g00_cache.php`:
- Materialize con `sp_getapplock` Exclusive/Transaction (resource namespaced `o14cache:<key>`) + double-checked freshness → no duplica filas.
- Freshness gate con `READPAST` (no lee filas uncommitted de un rebuild en vuelo).
- Reads del cache en `READ COMMITTED` (INTEGRACION tiene RCSI ON → lee versión completa previa, no parcial, sin bloquear).
- (Futuro, fuera de alcance: factorizar un helper de cache compartido entre g00 y o14.)

## Frontend (auto-aplicar)

En `informes/o14.php`: aplicar auto-aplicar con debounce (~400ms) en los filtros de dimensión, igual que el fix de `informes/g00.php` (`g00AutoAplicar`). Verificar el wiring real de filtros→carga de o14 antes (no asumir). Los cambios de tab b/c/reco ya serán rápidos por el cache.

## Testing / paridad

- Golden `tab=X` (cache) vs `tab=X&nocache=1` (vivo) por **tab (b/c/reco)** × proveedores (chico/grande, con datos verificados — guarda de **no-vacuidad**, lección DISANDINA) × filtros `[{}, {marca}, {grupo}, {color}]`. Comparación deep, normalizando orden/redondeo. 0 diffs.
- Test 2-procesos lector-durante-rebuild (como G00) para la concurrencia.
- Medición antes/después por tab (1ª carga vs filtro cache-hit). Objetivo: filtro ~sub-2s; tab=c grande de ~29s a segundos.
- `php -l` limpio.

## Puntos a confirmar con medición (durante implementación)

1. Índice secundario óptimo de `o14_cache_base` para las agregaciones de tab=c (que la agregación indexada baje a segundos).
2. Tamaño real del cache para BRAHMA (nº de filas granular) y su impacto en materialize/lectura.
3. Que la denormalización de Bodegas reproduce EXACTO el `grupo/nombre` que hoy calcula el join (incluida la normalización de cia a 3 díg).

## Fuera de alcance

- Portar el motor de reco a JS (enfoque C descartado).
- Sub-proyecto 3 de o14 (persistencia "Generar solicitud").
- Auto-aplicar en cambios de fecha (rebuild pesado) — se deja manual, como en G00.
- Optimización de columnstore/pre-agregados si la latencia grande persiste (follow-up, como en G00).

## Referencias

- Patrón base: `docs/superpowers/specs/2026-07-06-g00-filtrado-rapido-design.md`.
- Endpoint actual: `api/informe_o14.php`; motor: `api/o14_recomendador.php`; refs: `api/lib_refs.php`.
- Constraint SIESA: memoria `plataforma-20-prohibido-tocar-siesa`.
