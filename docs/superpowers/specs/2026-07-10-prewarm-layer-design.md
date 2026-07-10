# Capa de calentamiento — prebuild nocturno (evol/o45) + login-prewarm

**Fecha:** 2026-07-10
**Sub-proyecto 3 de 3** (último) del frente "rendimiento evol/o45 + prewarm". (1) evol cache en disco ✅; (2) o45 cache en disco ✅; (3) **capa de calentamiento** (este) — depende de 1 y 2, ya en `main`.
**Rama:** `feature/prewarm-layer`

---

## 1. Contexto

Los 3 informes lentos (o14 tab=c, evol tab=data, o45 tab=dataset) ya sirven desde cache en disco, pero **en frío** (cache-en-miss) la 1ª carga aún construye+transfiere (o14 ~26-36s, evol ~36s, o45 ~112s según proveedor). Objetivo: que **nadie pague la construcción en frío**, calentando las caches en 2 momentos:

- **Prebuild nocturno:** o14c ya lo tiene (`prebuild_o14c.bat`); falta **extenderlo a evol y o45**, y correr tras el ETL. Deja todo caliente en la mañana.
- **Login-prewarm:** al iniciar sesión, un proceso en segundo plano recalienta lo que se puso **stale** durante el día (sobre todo o14c/evol, cuya frescura sigue el TTL de BD de ~120min; o45 con stamp nocturno queda fresco todo el día).

## 2. Objetivo y criterio de éxito

- **Objetivo:** que la carga de o14/evol/o45 sea instantánea en operación normal, sin que ningún usuario espere una construcción en frío.
- **Criterio de éxito:**
  1. Tras el prebuild nocturno, los 3 informes de cada proveedor activo están frescos en disco.
  2. Al iniciar sesión, un proceso **detached** (no bloquea el login) recalienta en segundo plano lo stale del proveedor que entra, con **throttle** (no apila en re-logins) y **solo-si-stale** (no golpea la RDS de más).
  3. El login responde igual de rápido que hoy (el spawn no bloquea).
  4. **Sin cruce entre usuarios:** cada proceso opera sobre el proveedor pasado por argumento, su propia conexión, caches llaveadas por proveedor.
  5. Rafael puede montar la tarea nocturna con instrucciones/comandos exactos.

## 3. Alcance

**Dentro:**
- Helper compartido `warmProveedor()` (calienta los 3 informes de un proveedor; opción `onlyIfStale`).
- Prebuild nocturno **unificado** (`sql/prebuild_all.php` + `.bat`): todos los proveedores × 3 informes.
- Login-prewarm: spawn detached en `index.php` + `sql/prewarm_login.php` (throttle + onlyIfStale).
- **Entregable de ops:** instrucciones/comando `schtasks` para montar la tarea nocturna en WMS-LAB (+ la de Items_Mat pendiente).

**Fuera (YAGNI):**
- Cambiar el código de los informes o sus TTL.
- Prewarm en tiempo real síncrono (el spawn es fire-and-forget).
- `prebuild_o14c.php` existente se mantiene (o se pliega dentro del unificado — decisión de implementación DRY).

## 4. Arquitectura

### 4.1. `api/lib_prewarm.php` (nuevo) — helper compartido

`warmProveedor($conn, string $proveedor, bool $onlyIfStale = false): array`
- `buildRefsFromMat($conn, $proveedor)` **una vez** (los 3 builders comparten `#refs` en la misma conexión).
- Para cada informe, con los **mismos defaults de rango que el endpoint** (para que la key calentada == la que pedirá el navegador):
  - **o14c:** `desde=2025-01-01`, `hasta=date('Y-m-d')`. `key=o14CacheKey(...)`. Si `onlyIfStale && o14cDiskFresh` → skip. Si no: `ensureO14CacheBase` → `o14cBuildPayloadC` → `o14cWritePayload(key, json, o14cCurrentStamp)`.
  - **evol:** `desde=(Y-1)-01`, `hasta=Y-m` (mes actual). `key=evolCacheKey(...)`. Idem con `ensureEvolCacheBase`/`evolBuildPayload`/`evolCurrentStamp`.
  - **o45:** `desde=2025-01-01`, `hasta=ayer`. `key=o45CacheKey(...)`. `o45BuildPayload`/`o45CurrentStamp` (o45 no tiene ensure; el build ES la materialización).
  - **ok-gate en todos:** escribe a disco SOLO si el payload construido tiene `ok===true` y el stamp no es null.
- Devuelve un resumen `['o14c'=>'warmed|skipped|failed', 'evol'=>..., 'o45'=>...]` para logging.
- Requiere los libs: `lib_refs`, `lib_o14c_payload`, `lib_evol_cache`+`lib_evol_disk`, `lib_o45_disk`, `lib_disk_cache`.

### 4.2. Prebuild nocturno unificado — `sql/prebuild_all.php` + `.bat`

- Enumera proveedores distintos de `usuarios_portal_aka` (reusando `login_resolver_proveedor`, dedup; mismo patrón que `prebuild_o14c.php`, con `--dry-run` y args explícitos).
- Por proveedor: `warmProveedor($conn, $prov, onlyIfStale=false)` (fuerza rebuild de los 3). Loguea el resumen por proveedor.
- `sql/prebuild_all.bat` (patrón `refrescar_items_mat.bat`): corre **después** del ETL/Items_Mat. `prebuild_o14c.{php,bat}` queda obsoleto → se reemplaza por este (o `prebuild_all` llama la misma lógica).
- **Orden nocturno:** ETL → `usp_Refresh_Items_Mat` → `prebuild_all` (para que refs/stamps/datos sean los nuevos).

### 4.3. Login-prewarm

- **`index.php`** (tras `$_SESSION['proveedor']`, línea 73; antes del redirect línea 92): si `$_SESSION['proveedor']` no vacío, disparar un **proceso detached** que NO bloquee el login:
  - Windows: `popen()` de un `start /B` que lanza `php sql/prewarm_login.php <proveedor>` redirigido a un log, y `pclose()` inmediato. Fire-and-forget: el login sigue al `header('Location')` sin esperar.
  - **Guarda:** solo si el binario php + el script existen (config de ruta php; fallback: no romper el login si el spawn falla — `@popen`).
- **`sql/prewarm_login.php <proveedor>`:**
  - **Throttle:** `flock(LOCK_EX|LOCK_NB)` sobre `cache/prewarm_<md5(proveedor)>.lock`; si no lo adquiere → otro prewarm del mismo proveedor corre → **exit 0** (no apila en re-logins).
  - `warmProveedor($conn, $proveedor, onlyIfStale=true)` → solo reconstruye lo stale.
  - Loguea a `sql/prewarm_login.log`.
- **Auto-limitado:** o45 casi siempre fresco (stamp nocturno) → skip; o14c/evol se recalientan solo si pasaron ~120min (~12-32s). No hay builds de 112s en cada login.

## 5. Aislamiento / concurrencia (resuelve la pregunta del cruce)

- Cada proceso (nocturno o login) lleva el **proveedor por argumento**, abre su **propia conexión**, y construye `#refs` propio. Las caches (BD y disco) se llavean por proveedor. → **cero estado compartido mutable entre logins/usuarios; imposible cruzar datos.**
- Throttle por-proveedor (lock de disco) evita builds duplicados del mismo proveedor.
- Reusa el flock/applock/double-check ya existente en cada `ensure...`/write.

## 6. Configuración de la tarea nocturna (entregable, Rafael ejecuta)

Instrucciones + comando exacto para WMS-LAB (y local si se quiere):
- **`refrescar_items_mat.bat`** (pendiente desde jul-3): diario ~03:00.
- **`prebuild_all.bat`**: diario ~03:30 (después de Items_Mat).
- Vía `schtasks /create ...` (comando dado) o el Programador gráfico, con **"ejecutar aunque el usuario no haya iniciado sesión"** + privilegios altos. Ajustar `PHP_EXE` si php.exe está en otra ruta en WMS-LAB.

## 7. Pruebas

- `warmProveedor`: smoke que calienta 1 proveedor (los 3 informes) → verifica que quedan los 3 `.json.gz` en disco y `DiskFresh` true; con `onlyIfStale=true` sobre cache ya fresco → skip (no reconstruye).
- Prebuild `--dry-run` (enumera) + build de 1 proveedor.
- Login-prewarm: verificar que el spawn **no bloquea** (medir que el login retorna rápido) y que `prewarm_login.php` corre en background y calienta; que el throttle omite un 2º lanzamiento concurrente.
- `php -l` limpio en `index.php` + los nuevos.
- **Gotcha heredado:** NO correr en paralelo con otra suite que toque las mismas keys.

## 8. Despliegue (manual, Rafael)

- Sin DDL. Re-sync a `plataforma_20_produccion` + WMS-LAB: `index.php` (mod), `api/lib_prewarm.php` (new), `sql/prebuild_all.{php,bat}` (new), `sql/prewarm_login.php` (new). `cache/` ya escribible.
- Montar/actualizar las 2 tareas nocturnas (§6). Verificar en `sql/prewarm_login.log` que el login-prewarm dispara y calienta.
- Ajustar `PHP_EXE` en los `.bat` + la ruta php del spawn en `index.php` si en WMS-LAB difiere.

## 9. Riesgos y follow-ups

- **Spawn detached en Windows (riesgo clave):** `popen('start /B …')` debe (a) no bloquear el login y (b) sobrevivir al fin del request. A verificar empíricamente en la implementación (el login debe retornar inmediato; el proceso debe seguir vivo). Fallback: si el spawn no funciona en WMS-LAB, el prebuild nocturno + cache-en-miss siguen cubriendo; el login-prewarm es aditivo, su fallo no rompe nada (`@popen`).
- **Carga en la RDS:** el login-prewarm solo-si-stale + throttle acota; o45 (112s) casi nunca se reconstruye en login (stamp nocturno). Vigilar `prewarm_login.log`.
- **Ruta de php:** el spawn necesita la ruta a `php.exe`; configurable/const, con fallback seguro.
- **DRY:** `warmProveedor` es la única fuente de "construir+escribir por informe"; el nocturno y el login-prewarm la comparten. `prebuild_o14c` existente se pliega en `prebuild_all`.
