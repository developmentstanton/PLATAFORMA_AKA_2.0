# Diseño — G00 (Dashboard de Ventas): filtrado rápido vía cache de dataset granular

- **Fecha:** 2026-07-06
- **Estado:** Aprobado (brainstorming)
- **Informe:** G00 (Dashboard de Ventas — la home)
- **Relacionado:** piloto `2026-07-06-o45-filtrado-instantaneo-design.md` (enfoque A, cliente), `2026-07-03-rendimiento-items-mat-design.md` (`Items_Mat`/`#refs`), [[plataforma-20-prohibido-tocar-siesa]].

## Objetivo

Que aplicar filtros en G00 sea **rápido** (~0,3-2s) en vez de los ~14-23s actuales, sin cambiar los resultados. Segundo de los 3 sub-proyectos de generalización del patrón de filtrado (tras el piloto o45); le siguen o14 y evol.

## Enfoque elegido: B — cache de dataset granular en servidor + re-agregar (transparente en backend)

A diferencia del piloto o45 (enfoque A, filtrado en el navegador), G00 **no encaja** con el cliente:
- **Payload:** el dataset granular de G00 son 2 años de ventas a nivel línea → 0,5 MB (aliado chico) a 8-20 MB (BRAHMA/STANTON). Demasiado para el navegador.
- **Complejidad:** 4 tabs (`detal`/`tiendas`/`productos`/`periodos`) con `GROUPING SETS` multinivel + `COUNT(DISTINCT CASE)` + comparación de 2 años + siembra. Reimplementar en JS = alto riesgo/esfuerzo de paridad.

**Enfoque B:** la 1ª carga materializa el dataset granular por (proveedor+período) en una **tabla persistente en INTEGRACION**; cada request agrega **sobre ese cache** reusando el **SQL de agregación existente** (solo cambia el FROM). Filtros de dimensión = subset del cache (rápido); cambio de fechas/años = rebuild del cache.

### Evidencia (medición read-only, params default de G00)

| Proveedor | Materializar cache (1 vez) | Consolidado sobre cache | (vivo hoy) | Mensual sobre cache | (vivo hoy) |
|---|---|---|---|---|---|
| BH BRANDS SAS | 2,6s (3.690 filas) | **174 ms** | 4,7s | **101 ms** | 3,2s |
| BRAHMA CONCEPT | 2,4s (56.137 filas) | **1,3s** | 5,8s | **441 ms** | 5,1s |

El cuello de los 14-23s es el **scan+join** (re-escanear `Ventas_*_PBI` + joins a `#refs`/`Bodegas`), NO el cómputo del `GROUPING SETS`/`COUNT(DISTINCT CASE)` (que sobre datos ya materializados corre en 0,1-1,3s). Perfilado de fases: consolidado+mensual ≈ 60% del tiempo (cacheable), `countTiendasSiembra` ≈ 30% (siembra/ERP, cache aparte), catálogos ≈ 7% (ya cacheados 12h).

## Arquitectura y flujo

El endpoint se vuelve **cache-first** y transparente:

```
CADA request (cualquier tab, con o sin filtros):
  1. Calcular cache_key = hash(proveedor, anioA, anioB, desde, hasta).
  2. ¿Existe cache fresco para esa key? (tabla INTEGRACION, dentro del TTL)
       NO  -> materializar: DELETE viejo + INSERT granular (ventas ⋈ #refs ⋈ Bodegas, 2 años)
              + materializar siembra por proveedor.  (~2,4-2,7s, una vez por proveedor+período)
       SÍ  -> nada.
  3. Agregar el tab solicitado LEYENDO DEL CACHE, aplicando los filtros en el WHERE
     (mismo SQL GROUPING SETS de hoy, con FROM = tabla cache en vez de cteVentas()⋈#refs).
```

**Consecuencia clave:** la 1ª carga (o cambio de fechas) paga el materialize (~pocos seg); los cambios de **filtro de dimensión** reusan el cache → **~0,3-2s** (round-trip + agregación). El **frontend apenas cambia**: sigue re-fetcheando al filtrar (como hoy), pero el backend ahora es rápido. La aceleración es transparente.

## El cache persistente (punto de diseño delicado)

Los temp tables `#` mueren con la conexión → no sirven entre requests HTTP. El cache vive en **tablas reales en `INTEGRACION`** (permitido; nunca SIESA):

- **`INTEGRACION.dbo.g00_cache_ventas`**: `cache_key VARCHAR(64)`, columnas del grano (anio, fecha, grupo, marca, tipo, categoria, subcategoria, genero, publico, bodega, referencia, color, talla) + medidas (cantidad, valor, margen, …), `creado DATETIME2`. Índice clustered/nonclustered por `cache_key` (+ columnas de filtro frecuentes).
- **`INTEGRACION.dbo.g00_cache_siembra`**: análogo, para el granular de `countTiendasSiembra` (por proveedor; snapshot, sin fechas).
- **`cache_key`** = hash de `(proveedor, anioA, anioB, desde, hasta)`. Los filtros de dimensión NO entran en la key (subsetean en el WHERE). El siembra_key omite fechas (es snapshot).
- **Lifecycle:** en un request de "carga" (cache ausente o stale o cambió el rango): `DELETE WHERE cache_key=?` + `INSERT ... SELECT` (materialize), dentro de una transacción. En filtros: solo `SELECT`-agregar `WHERE cache_key=? AND <filtros>`.
- **TTL / frescura:** los hechos cambian con el cierre diario; TTL de pocas horas (o "hasta el próximo día"). El request de carga rebuild-ea si `creado` está fuera del TTL. Limpieza oportunista: en cada carga, `DELETE WHERE creado < now - TTL_limpieza` (barre entradas viejas de todos los proveedores).
- **Concurrencia:** dos requests materializando la misma key a la vez → doble build; se acepta (idempotente: DELETE+INSERT por key; el último gana). Mitigación simple con transacción; sin locks elaborados en el piloto.

## Frontera de filtros
- **Rápido (re-agregar cache):** todas las dimensiones (grupo, marca, tipo, categoria, subcategoria, genero, publico, tienda/bodega, ciudad, etc.).
- **Rebuild del cache:** cambio de `desde/hasta` o de los años comparados (`anioA`/`anioB`) — redefinen el granular.

## Alcance: los 4 tabs comparten el cache
`detal`, `tiendas`, `productos`, `periodos` leen todos el mismo `ventas ⋈ #refs ⋈ Bodegas` → **un solo cache de ventas** los alimenta a los 4 (cada tab corre su propio GROUPING SETS sobre el cache). `countTiendasSiembra` (usado en detal y tiendas) usa el cache de siembra. La comparación de 2 años está contenida en el cache (guarda ambos años).

## Frontend
Cambios mínimos en `informes/g00.php`: ninguno estructural si ya re-fetchea al aplicar filtros. Solo se valida que el flujo de "Aplicar/cambio de filtro" siga pegando al endpoint (que ahora es rápido). Si G00 hoy re-consulta en cada filtro, la mejora es transparente.

## Paridad y testing
- **Oráculo:** el resultado actual de G00 (agregación en vivo) por tab. Se conserva un camino "sin cache" (o el path viejo) como oráculo durante la transición.
- **Test de paridad:** para varios proveedores y varios tabs (detal/tiendas/productos/periodos) × combinaciones de filtros, comparar la salida **con cache** vs **en vivo** → deben ser idénticas (el SQL de agregación es el mismo; solo cambia el FROM). Guarded por DB, patrón `tests/verificar_*`.
- **Medición antes/después:** 1ª carga (materialize) y latencia de filtro (objetivo ~0,3-2s).
- **Verificación de que el cache no se ensucia entre proveedores** (aislamiento por `cache_key`).

## Rollout
1. Tablas de cache en INTEGRACION (`g00_cache_ventas`, `g00_cache_siembra`) + índices.
2. Lib de cache: `ensureG00Cache($conn, $key, $params)` (materialize si falta/stale) + limpieza por TTL.
3. Refactor del endpoint: las queries de los 4 tabs leen del cache (FROM = tabla cache) en vez de `cteVentas()⋈#refs`; el materialize corre el scan+join una vez.
4. Siembra: cache de `countTiendasSiembra`.
5. Test de paridad (con-cache vs en-vivo) + medición.
6. E2E navegador (Rafael): filtros rápidos + cifras idénticas + rebuild al cambiar fechas.
7. Deploy: crear tablas en RDS + re-sync código a prod + servidor aliados.

## Fuera de alcance
- Filtrado **instantáneo** puro (client-side): descartado para G00 (payload/complejidad). "Rápido" (~0,3-2s) es el objetivo.
- Optimizar la materialización inicial por debajo de ~2,5s (es el scan+join irreducible; aceptable como costo de 1ª carga/cambio de fechas).
- Job nocturno de pre-materialización (posible mejora futura: pre-cachear proveedores activos de noche para 1ª carga instantánea).

## Riesgos
- **Gestión del cache** (TTL/limpieza/concurrencia/staleness vs cierre diario): el punto más delicado; se mitiga con key por proveedor+período, rebuild en carga, TTL de horas y limpieza oportunista.
- **Crecimiento de las tablas de cache** en INTEGRACION: acotado por la limpieza por TTL; monitorear tamaño.
- **Paridad**: bajo riesgo porque se reusa el SQL de agregación (solo cambia el FROM); el test de paridad con-cache vs en-vivo lo blinda.
