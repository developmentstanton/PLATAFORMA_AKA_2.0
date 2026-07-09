# EVOL — Cache en disco del payload + lib de disco compartido (refactor o14c)

**Fecha:** 2026-07-09
**Sub-proyecto 1 de 3** del frente "rendimiento evol/o45 + prewarm". Sub-proyectos: **(1) evol cache en disco** (este), (2) o45 cache en disco, (3) capa de calentamiento (prebuild nocturno + login-prewarm) que depende de 1 y 2.
**Rama:** `feature/evol-cache-disco`

---

## 1. Contexto y diagnóstico (medido)

El informe **Evolución (evol)**, carga por defecto `tab=data` (matriz negocio×mes), sin filtro. Medición read-only (BRAHMA CONCEPT, via el runner real):

| Carga | Tiempo | Payload |
|---|---|---|
| evol `tab=data` FRÍO (materializa `evol_cache_base`) | ~36 s | 1.27 MB |
| evol `tab=data` CALIENTE (cache-hit BD) | **~7 s** | 1.27 MB |

El cache de servidor `evol_cache_base` (sub-proyecto evol-filtrado, 2026-07-08) ya arregló el **cómputo** (frío→caliente). Pero los ~7s calientes son **casi puro transferir 1.27 MB** por el link RDS→PHP a ~0.21 MB/s — **el mismo cuello que resolvimos en o14** ([[plataforma-20-o14c-cache-disco]]): el cache de BD evita recomputar, no transferir.

## 2. Objetivo y criterio de éxito

- **Objetivo:** carga por defecto de evol de ~7s a **~2s**, sirviendo el payload determinista desde disco local (gzip) en vez de re-transferirlo desde la RDS.
- **Criterio de éxito:**
  1. Con cache en disco caliente, `tab=data` sin filtro responde en < ~2s.
  2. **Paridad byte-a-byte:** el payload servido desde disco == el vivo `?nocache=1` para el mismo `ekey` (tolerando la firma de staleness angosta de evol: solo campos de stock del mes en curso).
  3. El caso **filtrado** de tab=data y cualquier otro tab quedan **intactos**.
  4. **o14c sigue funcionando idéntico** tras el refactor al lib compartido (su suite de tests queda verde).

## 3. Alcance

**Dentro:**
- Nuevo **`api/lib_disk_cache.php`**: primitivas de disco genéricas (write/read/fresh/cleanup/serveGz), parametrizadas por **prefijo** + **stamp que pasa el caller** (sin conocimiento de BD).
- **Refactor de `api/lib_o14c_payload.php`** para usar el lib compartido (conserva sus firmas públicas; la suite o14c queda verde).
- **evol cache en disco:** `evolBuildPayload` + `evolCurrentStamp` + corto-circuito de disco en `informe_evol.php` (tab=data cacheMode, sin filtros).

**Fuera (YAGNI / otros sub-proyectos):**
- evol **filtrado** y otros tabs → camino actual intacto.
- **o45** → sub-proyecto 2.
- **Prebuild nocturno + login-prewarm** → sub-proyecto 3 (aquí evol queda con cache-en-miss: 1ª carga construye, siguientes rápidas, igual que dejamos o14c).

## 4. Arquitectura

### 4.1. `api/lib_disk_cache.php` (compartido, genérico)

Primitivas puras de filesystem + gzip. **No conoce la BD**: la frescura se decide comparando el stamp en disco contra un `$currentStamp` que el caller calcula. Rutas relativas a la carpeta existente `cache/`.

- `diskCacheDir(): string` → `__DIR__.'/../cache'`.
- `diskCachePath(string $prefix, string $key): string` → `<cache>/<prefix>_<key>.json.gz`.
- `diskCacheStampPath(string $prefix, string $key): string` → `<cache>/<prefix>_<key>.stamp`.
- `diskCacheWrite(string $prefix, string $key, string $jsonPlano, string $stamp): bool` — gzip + escritura atómica (tmp+rename) del `.json.gz` y luego el `.stamp` (orden payload→stamp; invertir serviría stale). Degrada a `false` si `cache/` no escribible.
- `diskCacheRead(string $prefix, string $key): ?string` — bytes gzip o `null`.
- `diskCacheFresh(string $prefix, string $key, ?string $currentStamp): bool` — true sí y solo sí existen `.json.gz` y `.stamp` **y** `contenido(.stamp) === $currentStamp` (no-null). **Aquí está la generalización:** el stamp entra como dato, no se consulta.
- `diskCacheCleanup(string $prefix, int $ttlMin): void` — barre `<prefix>_*.json.gz`+`.stamp`+`.tmp.*`+`.lock` con mtime más viejo que `$ttlMin`.
- `diskCacheServeGz(string $gz): void` — sirve gzip al navegador con el guard anti-doble-compresión (`zlib.output_compression`/`ob_gzhandler`) + `Vary: Accept-Encoding` (heredado del hardening de o14c).

### 4.2. Refactor de `api/lib_o14c_payload.php`

Conserva la lógica específica de o14c (`o14cBuildPayloadC`, `ensamblarArbol`, `o14cCurrentStamp` que consulta `o14_cache_base.creado`). Las primitivas de disco pasan a ser **thin wrappers** sobre el lib compartido, **manteniendo las firmas públicas** para no tocar `informe_o14.php` ni `sql/prebuild_o14c.php`:
- `o14cReadPayload($k)`→`diskCacheRead('o14c',$k)`; `o14cWritePayload($k,$j,$s)`→`diskCacheWrite('o14c',$k,$j,$s)`; `o14cCleanup()`→`diskCacheCleanup('o14c',O14_CACHE_TTL_MIN)`; `o14cServeGz($g)`→`diskCacheServeGz($g)`; `o14cDiskFresh($conn,$k)`→`diskCacheFresh('o14c',$k,o14cCurrentStamp($conn,$k))`; `o14cPayloadPath`/`o14cStampPath`→`diskCachePath`/`diskCacheStampPath('o14c',…)`.
- **Verificación del refactor:** `tests/verificar_o14c_payload.php` (primitivas + `--paridad` + `--e2e`) y `tests/verificar_o14_cache.php --paridad` (36/36) quedan **verdes sin cambios de comportamiento**.

### 4.3. evol cache en disco

- Nuevo `api/lib_evol_disk.php` (o añadido a `lib_evol_cache.php`):
  - `evolCurrentStamp($conn, string $ekey): ?string` — `CONVERT(varchar(30),creado,126)` de `evol_cache_base` para `$ekey`, `WITH (READPAST)`, `ORDER BY creado DESC` (determinista, mismo patrón que o14c).
  - `evolBuildPayload($conn, string $ekey, $desdeMes, $hastaMes): array` — **refactor** del ensamblado del payload `tab=data` cacheMode **sin filtros** (bloque actual de `informe_evol.php`, ~líneas 254-375), devolviendo el mismo array que hoy se hace `echo json_encode`. Reusado por el endpoint-miss (y por el prewarm del sub-proyecto 3).
- `api/informe_evol.php`, bloque `tab=data` cacheMode, cuando **no hay filtros** (REF/negocio/BOD vacíos): corto-circuito de disco con prefijo `'evol'`:
  - HIT (`diskCacheFresh('evol',$ekey,evolCurrentStamp(...))`) → `diskCacheServeGz(diskCacheRead('evol',$ekey))` + exit.
  - MISS → `flock` + double-check → `evolBuildPayload` → `diskCacheWrite('evol',...)` → serve gz + exit. Lock-fail → cae al camino de filas actual.
  - `diskCacheCleanup('evol', EVOL_CACHE_TTL_MIN)` junto al cleanup de BD.
- El request **filtrado** ni entra al bloque de disco (camino de filas actual, intacto).

## 5. Frescura / concurrencia

- **Frescura:** `.stamp` = `creado` de `evol_cache_base` (timestamp-DB vs timestamp-DB, sin cruzar relojes). Un rebuild de BD avanza `creado` → disco stale → rebuild. Cubre el staleness angosto de evol (solo stock del mes en curso deriva).
- **Concurrencia:** `flock(LOCK_EX)` + double-check (disco) sobre el `sp_getapplock` + double-check (BD, heredado de `ensureEvolCacheBase`). Escritura atómica tmp+rename.

## 6. Pruebas / paridad

- **Refactor o14c (bloqueante):** correr `tests/verificar_o14c_payload.php` (todos los modos) + `tests/verificar_o14_cache.php --paridad` → deben quedar **verdes** (prueba que el refactor no cambió comportamiento).
- **Paridad evol (bloqueante):** la suite existente `tests/verificar_evol_cache.php --paridad` drivea el endpoint real y compara `tab=data` vs `nocache=1`. Como el `tab=data` sin filtro ahora rutea por el disco, su verde (tolerando la firma de staleness de evol) **ES la prueba de paridad disco↔vivo**. Añadir un `--e2e`/smoke que confirme que el archivo `cache/evol_<ekey>.json.gz` se escribe (que el corto-circuito corrió, no un fall-through) y un smoke de frescura (`diskCacheFresh` true tras escribir, false con stamp desfasado).
- Tests del lib compartido: roundtrip gzip + atomicidad + cleanup (barre .tmp/.lock) + frescura por stamp (DB-independiente).
- `php -l` limpio en todos.

## 7. Despliegue (manual, Rafael — post E2E)

- Sin DDL nuevo (reusa `evol_cache_base`, ya en la RDS).
- Re-sync a `plataforma_20_produccion` + servidor aliados: `api/lib_disk_cache.php` (nuevo), `api/lib_o14c_payload.php` (refactor), `api/lib_evol_disk.php` (nuevo) + `api/lib_evol_cache.php` si se toca, `api/informe_evol.php` (mod). `cache/` ya escribible.
- E2E navegador: abrir Evolución sin filtro → rápido y bien (confirma gzip).

## 8. Riesgos y follow-ups

- **Refactor de o14c ya mergeado:** riesgo mitigado por su suite completa (primitivas + paridad 36/36 + e2e). Mantener firmas públicas evita tocar el endpoint/prebuild de o14c.
- **Payload filtrado de evol NO se cachea** (igual que o14c): los filtros van por el camino de filas (ya aceptable). Si un filtro específico resultara lento, se evalúa aparte.
- **Prepara o45 y el prewarm:** `diskCacheFresh` con stamp-como-parámetro deja listo el sub-proyecto 2 (o45, cuyo stamp saldrá de otra fuente, no de un `creado`).
