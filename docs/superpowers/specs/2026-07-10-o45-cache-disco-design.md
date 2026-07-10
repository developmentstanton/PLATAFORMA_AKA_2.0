# O45 (Índice de Ventas) — Cache en disco del dataset

**Fecha:** 2026-07-10
**Sub-proyecto 2 de 3** del frente "rendimiento evol/o45 + prewarm". (1) evol cache en disco ✅ hecho; (2) **o45 cache en disco** (este); (3) capa de calentamiento (prebuild nocturno + login-prewarm) que depende de 1 y 2.
**Rama:** `feature/o45-cache-disco`

---

## 1. Contexto y diagnóstico (medido)

o45 = **Índice de Ventas**. El "filtrado instantáneo" (enfoque A) carga **una vez** el dataset granular vía `tab=dataset` y filtra/re-agrega en el navegador. Medición read-only (BRAHMA CONCEPT, runner real):

| Carga | Tiempo | Payload |
|---|---|---|
| **o45 `tab=dataset`** (lo que carga el navegador) | **~112 s** | **9.0 MB** |
| o45 `tab=data` (agregado, camino viejo) | 16 s | 0.29 MB |

Los ~112s = build pesado (`buildO45Dataset`: escanea `historico_inventarios_PBI` 19.6M filas + joins) **+** transferir 9 MB por el link RDS→PHP lento (~0.21 MB/s, mismo cuello que o14/evol). Es la **peor** carga de los informes y la **carga inicial por defecto** de o45. El filtrado instantáneo trasladó la agregación al cliente pero disparó la carga inicial de ~16s a ~112s.

## 2. Objetivo y criterio de éxito

- **Objetivo:** carga inicial de o45 de ~112s a **~instantánea**, sirviendo el dataset determinista desde disco local (gzip ~1MB) en vez de reconstruir+transferir 9MB cada vez. **Sin re-hacer el enfoque A** (el filtrado en cliente queda igual).
- **Criterio de éxito:**
  1. Con cache en disco fresco, `tab=dataset` responde en < ~2s.
  2. **Paridad byte-a-byte:** el payload servido desde disco == el vivo `?tab=dataset` (mismo proveedor+rango), tolerando la firma de staleness del dataset (stock/hold del corte actual, que deriva intradía aunque el ETL sea nocturno).
  3. Los demás tabs de o45 (`data`, `filtros`) quedan **intactos**.

## 3. Alcance

**Dentro:**
- Cache en disco (gzip) del payload `tab=dataset` con el **`lib_disk_cache.php` compartido** (prefijo `'o45'`).
- **Frescura por stamp de fuente** (o45 NO tiene `cache_base`/`creado`): stamp = marcador que avanza con el ETL nocturno.
- Corto-circuito de disco en `informe_o45.php` tab=dataset + `o45BuildPayload` + `o45CurrentStamp` en un nuevo `api/lib_o45_disk.php`.

**Fuera (otros sub-proyectos / YAGNI):**
- tabs `data`/`filtros` → intactos.
- Prebuild nocturno + login-prewarm → sub-proyecto 3 (aquí o45 queda con cache-en-miss: 1ª carga del día construye ~112s, resto instantáneo).
- Re-arquitectura del enfoque A → NO.

## 4. Arquitectura

### 4.1. Frescura por stamp de fuente (la decisión clave de o45)

Confirmado con Rafael: **los datos de o45 solo cambian con el ETL nocturno** → el dataset es estable ~24h. Como no hay `creado`, el stamp sale de la **fuente**:

- `o45CurrentStamp($conn): ?string` = **compuesto**: `MAX(FECHA)` de `INTEGRACION.dbo.inv_actual_PBI` **+** `MAX(FECHA)` de `INTEGRACION.dbo.Ventas_Detal_PBI` (las dos fuentes de dato "actual" que refresca el ETL), concatenados en un string (p.ej. `"<fechaInv>|<fechaVta>"`). Cuando el ETL carga datos nuevos, alguna avanza → el stamp cambia → el disco se reconstruye. Query barata (dos `MAX(FECHA)`), read-only, NOLOCK. `null` si ambas fallan.
- **Nota:** el stamp es GLOBAL (marcador de dato del sistema), no por-proveedor; el `cache_key` sí es por-proveedor+rango. `diskCacheFresh` compara el stamp guardado al construir contra el global vigente → cuando el ETL avanza, TODOS los proveedores quedan stale y se reconstruyen. Correcto.
- **Además** el `cache_key` incluye `hasta` (default = **ayer**, rota a diario) → rotación diaria automática; el stamp cubre el caso de ETL que corre tarde o recarga intradía.
- Si el ETL NO corre una noche, el stamp no cambia → el disco sigue fresco (correcto: el dato no cambió).

### 4.2. `api/lib_o45_disk.php` (nuevo)

- `o45CacheKey($proveedor, $desde, $hasta): string` = `substr(md5($proveedor.'|'.$desde.'|'.$hasta),0,32)` (mismo patrón que o14/evol; o45 no tenía uno).
- `o45CurrentStamp($conn): ?string` — el stamp compuesto (§4.1).
- `o45DiskFresh($conn, $key): bool` = `diskCacheFresh('o45', $key, o45CurrentStamp($conn))`.
- Wrappers `'o45'`: `o45ReadPayload`, `o45WritePayload`, `o45Cleanup` (`diskCacheCleanup('o45', O45_DISK_TTL_MIN)` con TTL largo ~1500min/25h para que el archivo del día sobreviva hasta el próximo ETL), `o45ServeGz`.
- `o45BuildPayload($conn, string $proveedor, string $desde, string $hasta): array` — **envuelve la construcción actual del endpoint** (`informe_o45.php:44-54`): asume `#refs` ya construido (`buildRefsFromMat`); corre `buildO45Dataset` → si `error` devuelve `['ok'=>false,'error'=>...]` (para el **ok-gate**); `preciosPorRefs`; arma `$columnas`/`$filas` **verbatim** de las líneas 49-52; devuelve el array `['ok'=>true,'tab'=>'dataset','proveedor'=>$proveedor,'columnas'=>...,'filas'=>...,'precios'=>...,'rango'=>$ds['meta']]`. Reusado por endpoint-miss y por el prebuild (sub-proyecto 3).

### 4.3. `api/informe_o45.php` (mod)

`require_once __DIR__ . '/lib_o45_disk.php';`. En el bloque `if ($tab === 'dataset')` (línea 44), **antes** de `buildO45Dataset`, insertar el corto-circuito de disco (tab=dataset NO tiene filtros de servidor — el filtrado es en cliente — así que **siempre** aplica cuando `$tab==='dataset'`):
- HIT (`o45DiskFresh`) → `o45ServeGz(o45ReadPayload($key))` + exit.
- MISS → `flock` + double-check → `o45BuildPayload` → **ok-gate** (escribe a disco solo si `$payload['ok']===true`; error → 500 sin cachear) → serve gz + exit. Lock-fail → cae al camino actual (buildO45Dataset directo, intacto).
- `o45Cleanup()` junto a donde haga sentido (una vez por request de dataset).
- `#refs` ya está construido (línea 41, antes del dispatch de tab) → el builder lo tiene.

## 5. Concurrencia / degradación

- `flock(LOCK_EX)` + double-check `o45DiskFresh`; escritura atómica (heredado del lib compartido). En frío (post-ETL) evita que N requests paguen los 112s a la vez.
- `cache/` no escribible / build error → degrada al camino actual / 500 sin cachear; nunca envenena el cache (ok-gate).

## 6. Pruebas / paridad

- **Paridad (bloqueante):** test que compara, para 2-3 proveedores, `o45BuildPayload` (o el endpoint `?tab=dataset` servido desde disco) vs el vivo `?tab=dataset` (bypass de disco), normalizado, **0 diffs** salvo la firma de staleness (stock/hold del corte actual). Reusar el runner `tests/_endpoint_run_o45.php`. **Ojo (lección de evol):** NO correr dos suites de paridad a la vez.
- Smoke de frescura: `o45CurrentStamp` no-null; `o45DiskFresh` true tras escribir con el stamp vigente, false con stamp desfasado.
- Tests del lib compartido ya existen (`verificar_disk_cache.php`).
- `php -l` limpio en los archivos nuevos/modificados.

## 7. Despliegue (manual, Rafael — post E2E)

- Sin DDL. Re-sync a `plataforma_20_produccion` + servidor **WMS-LAB** (prod): `api/lib_o45_disk.php` (nuevo), `api/informe_o45.php` (mod). (El `lib_disk_cache.php` compartido ya se desplegó con evol.) `cache/` ya escribible.
- E2E navegador: abrir Índice de Ventas → carga rápida (tras 1ª construcción del día) + filtrado instantáneo sigue funcionando + datos correctos.

## 8. Riesgos y follow-ups

- **Staleness intradía del corte actual:** el stamp es nocturno; si el stock/hold del corte se mueve intradía, el disco lo refleja solo tras el próximo cambio de stamp. Aceptado (Rafael confirmó cadence nocturno; consistente con o14/evol que ya toleran ~120min).
- **`o45BuildPayload` requiere `#refs`** (como el endpoint): el prebuild (sub-proyecto 3) debe `buildRefsFromMat` antes, y clampear `hasta=ayer` como el endpoint.
- **Prepara el sub-proyecto 3:** el prebuild nocturno de o45 debe correr **después** del ETL (para que el stamp/dato sean los nuevos) — orden: ETL → Items_Mat → prebuild o14c/evol/o45.
- La query de precios (`preciosPorRefs`) también entra al payload; ya está optimizada (fix de 2026-07-06) y es parte del dataset cacheado.
