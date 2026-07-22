# EVOL — Frescura por stamp de fuente (alinear a O45) — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el informe de Evolución Histórica sirva de cache en disco todo el día (incluida la primera carga de la mañana), eliminando la reconstrucción cara que hoy dispara la primera carga.

**Architecture:** Se alinea evol al patrón ya probado de o45: (1) la frescura del disco se decide por un **stamp de fuente** (`MAX(FECHA)` de las 3 tablas vivas) en vez del `creado` de la tabla intermedia con TTL; (2) el endpoint **mira el disco antes** de materializar `evol_cache_base`; (3) la base solo se materializa en un miss real (con `force`) o en el camino filtrado/vivo que la lee. El contenido del payload NO cambia (paridad byte-a-byte).

**Tech Stack:** PHP 8 (driver `sqlsrv`), SQL Server (RDS), cache en disco gzip (`lib_disk_cache.php`). Sin build/npm.

## Global Constraints

- **Paridad byte-a-byte:** el JSON servido por el camino de disco debe ser idéntico al del camino vivo (`?nocache=1`), salvo el staleness intradía ya tolerado (`evolIsStalenessOnly`). Gate: `php tests/verificar_evol_disco.php --e2e`.
- **NO correr dos suites de paridad a la vez** (gotcha conocido: colisión de temp tables/estado en la misma conexión).
- **Prohibido tocar tablas SIESA** (`t***`) ni la materialización byte-a-byte de `evol_cache_base` (solo se añade un parámetro `$force`, sin cambiar el SQL de materialización).
- **`evolBuildPayload` no se modifica** en este plan.
- Comando de test siempre desde la raíz del proyecto: `C:\xampp\htdocs\plataforma_20`.

## File Structure

| Archivo | Responsabilidad | Cambio |
|---------|-----------------|--------|
| `api/lib_evol_disk.php` | Stamp + frescura del disco de evol | `evolCurrentStamp($conn)` pasa a stamp de fuente; `evolDiskFresh` actualiza su llamada |
| `api/lib_evol_cache.php` | Materialización de `evol_cache_base` | Añadir `bool $force=false` a `ensureEvolCacheBase` |
| `api/informe_evol.php` | Endpoint | Reordenar: disco-primero; `ensure(force)` en miss sin filtro; `ensure` normal antes de la agregación cache-mode filtrada |
| `api/lib_prewarm.php` | warmProveedor | Actualizar llamada a `evolCurrentStamp` |
| `tests/verificar_evol_disco.php` | Test unit de frescura/payload | Actualizar llamada a `evolCurrentStamp`; añadir assert de disco-primero |
| `sql/AGENDAR_PREBUILD_WMS-LAB.md` | Entregable operativo | Comando schtasks listo para pegar |

---

### Task 1: Stamp de fuente para evol

**Files:**
- Modify: `api/lib_evol_disk.php:6-12`
- Test: `tests/verificar_evol_disco.php:31` (actualizar llamada) + correr `--paridad`

**Interfaces:**
- Produces: `evolCurrentStamp($conn): ?string` (firma NUEVA, sin `$ekey`) — stamp global de fuente.
- Produces: `evolDiskFresh($conn, string $ekey): bool` (firma igual).

- [ ] **Step 1: Reemplazar `evolCurrentStamp` y `evolDiskFresh`**

En `api/lib_evol_disk.php`, reemplazar las líneas 6-12 (desde `if (!function_exists('evolCurrentStamp')) {` hasta la línea de `evolDiskFresh`) por:

```php
if (!function_exists('evolCurrentStamp')) {
    // Stamp GLOBAL de fuente (mismo enfoque que o45CurrentStamp): avanza cuando el ETL nocturno
    // carga las 3 fuentes VIVAS de evol. ISNULL para que nunca sea NULL por una fuente vacía
    // (si la query falla -> null -> diskCacheFresh=false -> rebuild).
    // NOTA de cobertura: el dataset lee más tablas (Ventas_Detal_Acum, historico_inventarios/
    // hold/mov_inv, _hold_actual), pero el stamp solo mira inv_actual + Ventas_Detal + mov_inv_actual
    // porque: (a) las históricas/Acum son append-only para un [desde,hasta] fijo y su recarga
    // siempre viene en el mismo ETL nocturno; (b) _hold_actual/stock del corte vivo tiene staleness
    // intradía que el diseño ya acepta (evolIsStalenessOnly); (c) esas 3 avanzando de noche son
    // proxy fiable del ETL completo. El prebuild nocturno corre con onlyIfStale=false (fuerza
    // rebuild), respaldo para cualquier recarga histórica.
    function evolCurrentStamp($conn): ?string {
        $sql = "SELECT ISNULL(CONVERT(varchar(19),(SELECT MAX(FECHA) FROM INTEGRACION.dbo.inv_actual_PBI     WITH (NOLOCK)),120),'') + '|'
                     + ISNULL(CONVERT(varchar(19),(SELECT MAX(FECHA) FROM INTEGRACION.dbo.Ventas_Detal_PBI   WITH (NOLOCK)),120),'') + '|'
                     + ISNULL(CONVERT(varchar(19),(SELECT MAX(FECHA) FROM INTEGRACION.dbo.mov_inv_actual_PBI WITH (NOLOCK)),120),'') s";
        $st = sqlsrv_query($conn, $sql);
        if ($st === false) return null;
        $r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($st);
        return $r ? (string)$r['s'] : null;
    }
    function evolDiskFresh($conn, string $ekey): bool { return diskCacheFresh('evol', $ekey, evolCurrentStamp($conn)); }
```

(El resto del bloque — `evolReadPayload`, `evolWritePayload`, `evolCleanup`, `evolServeGz`, `evolFetch`, `evolBuildPayload` — queda intacto.)

- [ ] **Step 2: Actualizar la llamada en el test unit**

En `tests/verificar_evol_disco.php:31`, cambiar:

```php
$stamp=evolCurrentStamp($conn,$ekey);
```
por:
```php
$stamp=evolCurrentStamp($conn);
```

- [ ] **Step 3: Correr el test de paridad/payload (verifica stamp no-null + frescura)**

Run: `php tests/verificar_evol_disco.php --paridad`
Expected: termina con `EVOL PAYLOAD OK` y `0` fallos. En particular estas líneas en OK:
- `OK   evolCurrentStamp no-null (...)` (el stamp ahora es `fecha|fecha|fecha`)
- `OK   evolDiskFresh true tras escribir con stamp vigente`
- `OK   evolDiskFresh false con stamp de disco desfasado`

- [ ] **Step 4: Commit**

```bash
git add api/lib_evol_disk.php tests/verificar_evol_disco.php
git commit -m "fix(evol): stamp de disco por fuente (MAX FECHA) en vez de creado de la base con TTL"
```

---

### Task 2: Parámetro `$force` en `ensureEvolCacheBase`

**Files:**
- Modify: `api/lib_evol_cache.php:68-70`
- Test: `tests/verificar_evol_disco.php` (añadir bloque al final, antes del `echo $fail...`)

**Interfaces:**
- Consumes: nada nuevo.
- Produces: `ensureEvolCacheBase($conn, $key, $desdeMes, $hastaMes, bool $force=false): bool`. Con `$force=true` salta el fast-path `evolCacheFresco` (reconstruye aunque el TTL no haya vencido); conserva applock + double-check.

- [ ] **Step 1: Añadir el parámetro y saltar el fast-path cuando `force`**

En `api/lib_evol_cache.php`, cambiar la firma (línea 68) y el fast-path (línea 70):

De:
```php
    function ensureEvolCacheBase($conn, $key, $desdeMes, $hastaMes): bool {
        // Fast path: sin tocar transacción/lock si ya hay cache fresco.
        if (evolCacheFresco($conn, $key)) return true;
```
A:
```php
    function ensureEvolCacheBase($conn, $key, $desdeMes, $hastaMes, bool $force=false): bool {
        // Fast path: sin tocar transacción/lock si ya hay cache fresco. Con $force (miss de disco
        // por cambio de fuente) se salta el fast-path para que la base coincida con la fuente nueva,
        // aunque su TTL de 120 min no haya vencido.
        if (!$force && evolCacheFresco($conn, $key)) return true;
```

(El `evolCacheFresco` del double-check tras adquirir el lock — línea ~90 — se conserva SIN cambios: si otro request ya materializó mientras esperábamos, no repetimos trabajo aunque venga `force`.)

- [ ] **Step 2: Escribir el test de `force` (falla antes del cambio de Step 1 si se revierte)**

En `tests/verificar_evol_disco.php`, insertar antes de la línea `echo $fail?...` (línea 38):

```php
// --- Task 2: $force reconstruye aunque la base esté fresca (TTL vigente) ---
ensureEvolCacheBase($conn,$ekey,$desde,$hasta);            // deja base fresca
$c1 = run($conn,"SELECT CONVERT(varchar(30),MAX(creado),126) c FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?",[$ekey]);
$creado1 = $c1[0]['c'] ?? null;
$okFresh = ensureEvolCacheBase($conn,$ekey,$desde,$hasta,true);   // force -> reconstruye
$c2 = run($conn,"SELECT CONVERT(varchar(30),MAX(creado),126) c FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?",[$ekey]);
$creado2 = $c2[0]['c'] ?? null;
ck($okFresh===true && $creado2!==null && $creado2!==$creado1, "ensureEvolCacheBase(force=true) reconstruye base fresca ($creado1 -> $creado2)");
```

Añadir al principio del archivo (tras los `require`, cerca de la línea 18) el helper `run` si no existe:
```php
if (!function_exists('run')) { function run($c,$sql,$p=[]){ $s=sqlsrv_query($c,$sql,$p); if($s===false) return ['error'=>sqlsrv_errors()]; $r=[]; while($x=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC))$r[]=$x; sqlsrv_free_stmt($s); return $r; } }
```

- [ ] **Step 3: Correr el test**

Run: `php tests/verificar_evol_disco.php --paridad`
Expected: incluye `OK   ensureEvolCacheBase(force=true) reconstruye base fresca (...)` y termina `EVOL PAYLOAD OK`, `0` fallos.

- [ ] **Step 4: Commit**

```bash
git add api/lib_evol_cache.php tests/verificar_evol_disco.php
git commit -m "feat(evol): ensureEvolCacheBase acepta \$force para reconstruir base en miss por cambio de fuente"
```

---

### Task 3: Reordenar el endpoint (disco-primero)

**Files:**
- Modify: `api/informe_evol.php:92-133`
- Test: `tests/verificar_evol_disco.php --e2e` (paridad HTTP) + assert nuevo de disco-primero

**Interfaces:**
- Consumes: `evolCurrentStamp($conn)` (Task 1), `ensureEvolCacheBase(...,$force)` (Task 2), `evolDiskFresh/evolReadPayload/evolWritePayload/evolServeGz` (existentes).
- Produces: endpoint que en `tab=data` sin filtros sirve de disco SIN materializar la base cuando el disco está fresco.

- [ ] **Step 1: Reemplazar el bloque de cache-mode + disco (líneas 92-133)**

En `api/informe_evol.php`, reemplazar el bloque actual que va desde `if ($cacheMode) {` (línea 92) hasta el cierre del corto-circuito de disco (línea 133, el `}` que cierra `if ($tab === 'data' && $cacheMode)`) por:

```php
if ($cacheMode) {
    require_once __DIR__ . '/lib_evol_cache.php';
    // $desdeMes/$hastaMes YA normalizados arriba (:19-22): la key y el contenido cacheado son 1:1.
    $ekey = evolCacheKey($proveedor, $desdeMes, $hastaMes);
    [$whereFiltros, $paramsFiltros] = construirFiltrosCache();
}

// ===== Corto-circuito de cache en disco: SOLO tab=data cache-mode SIN filtros REF/BOD/negocio. =====
// DISCO-PRIMERO: el hit sirve gz SIN materializar evol_cache_base (patrón o45). Miss -> flock +
// double-check + ensure(force) + build + write (ok-gate) + serve. Lock-fail -> cae al camino de
// filas de abajo (que materializa la base con ensure normal).
if ($tab === 'data' && $cacheMode) {
    $evolSinFiltros = true;
    foreach (array_merge($FILTROS_REF, $FILTROS_BOD) as $k=>$col) { if (getMulti($k)) { $evolSinFiltros=false; break; } }
    if ($evolSinFiltros && getMulti('negocio')) $evolSinFiltros = false;  // negocio es un filtro aparte (construirFiltrosCache)
    if ($evolSinFiltros) {
        if (evolDiskFresh($dbConnect, $ekey)) {
            $gz = evolReadPayload($ekey);
            if ($gz !== null) { sqlsrv_close($dbConnect); evolServeGz($gz); exit; }
        }
        $lockPath = diskCachePath('evol',$ekey) . '.lock';
        $lk = @fopen($lockPath,'c');
        if ($lk && flock($lk, LOCK_EX)) {
            if (evolDiskFresh($dbConnect, $ekey)) { $gz=evolReadPayload($ekey);
                if ($gz!==null){ flock($lk,LOCK_UN); fclose($lk); sqlsrv_close($dbConnect); evolServeGz($gz); exit; } }
            // Miss real: capturar stamp de fuente ANTES de materializar (como o45), luego forzar
            // rebuild de la base (la fuente cambió), construir el payload y cachearlo.
            $stamp = evolCurrentStamp($dbConnect);
            if (!ensureEvolCacheBase($dbConnect, $ekey, $desdeMes, $hastaMes, true)) { flock($lk,LOCK_UN); fclose($lk); jsonFail(['error'=>sqlsrv_errors()], $dbConnect); }
            evolCacheCleanup($dbConnect); evolCleanup();
            $payload = evolBuildPayload($dbConnect, $proveedorSesion, $ekey, $desdeMes, $hastaMes);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $okPayload = (($payload['ok'] ?? false) === true);
            if ($okPayload && $stamp !== null) evolWritePayload($ekey, $json, $stamp);  // NO cachear errores transitorios
            flock($lk,LOCK_UN); fclose($lk); sqlsrv_close($dbConnect);
            if ($okPayload) { evolServeGz(gzencode($json,6)); }
            else { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); echo $json; }
            exit;
        }
        if ($lk) fclose($lk);
        // lock-fail -> cae al camino de filas de abajo (intacto)
    }
}

// ===== Materializar evol_cache_base para los caminos cache-mode que LEEN de ella (filtrado, o
// no-filtro que cayó por lock-fail). El hit/miss sin filtro de arriba ya hizo exit. nocache NO
// entra aquí (construye #base vivo abajo). =====
if ($cacheMode) {
    if (!ensureEvolCacheBase($dbConnect, $ekey, $desdeMes, $hastaMes)) jsonFail(['error'=>sqlsrv_errors()], $dbConnect);
    evolCacheCleanup($dbConnect); evolCleanup();
}
```

Nota: se ELIMINA la llamada incondicional a `ensureEvolCacheBase` de la antigua línea 96. Ahora la base se materializa solo en (a) el miss sin filtro (con `force`) y (b) el bloque final `if ($cacheMode)` para los reads restantes.

- [ ] **Step 2: Correr la paridad E2E HTTP (regresión del reorder)**

Run: `php tests/verificar_evol_disco.php --e2e`
Expected: todas las líneas `OK`, en particular:
- `OK   disco (tab=data): ok:true (...ms)`
- `OK   disco: diskCachePath('evol',key) existe tras la llamada (corto-circuito ejecuto, no fall-through)`
- `OK   vivo (tab=data&nocache=1): ok:true`
- la comparación de paridad disco-vs-vivo pasa (sin diffs fuera de staleness).
Termina con `0` fallos.

- [ ] **Step 3: Escribir el assert de disco-primero (el corazón del fix)**

Crear `tests/verificar_evol_disco_primero.php`:

```php
<?php
/**
 * Prueba el fix de fondo: con disco fresco, el endpoint tab=data SIN filtro sirve de disco
 * SIN necesitar evol_cache_base. Borramos las filas de la base y, aun así, debe responder ok:true
 * desde disco (si dependiera de la base, fallaría o la reconstruiría lento).
 *   php tests/verificar_evol_disco_primero.php   (requiere DB)
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../api/lib_disk_cache.php';
require __DIR__ . '/../api/lib_evol_cache.php';
require __DIR__ . '/../api/lib_evol_disk.php';
require __DIR__ . '/_task4_paridad_evol.php'; // evolCallEndpoint / evolDefaultDesdeMes / evolDefaultHastaMes / evolPurgeKey
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
if ($dbConnect===false){ echo "SKIP sin DB\n"; exit(0); }
$conn=$dbConnect; $fail=0; function ckp($c,$m){ global $fail; echo ($c?"OK  ":"FAIL")."  $m\n"; if(!$c)$fail++; }
$prov='BH BRANDS SAS';
$ekey=evolCacheKey($prov, evolDefaultDesdeMes(), evolDefaultHastaMes());

// 1) Calentar el disco vía endpoint (miss -> materialize -> write).
$r1 = evolCallEndpoint($prov, 'tab=data');
ckp(is_array($r1) && ($r1['ok']??false)===true, 'calentamiento: endpoint tab=data ok:true');
ckp(evolDiskFresh($conn,$ekey)===true, 'disco fresco tras calentamiento');

// 2) Vaciar evol_cache_base para esta key: si el endpoint dependiera de la base, se notaría.
$d=sqlsrv_query($conn,"DELETE FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?",[$ekey]);
if($d!==false) sqlsrv_free_stmt($d);
$cnt=sqlsrv_query($conn,"SELECT COUNT(*) n FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?",[$ekey]);
$row=sqlsrv_fetch_array($cnt,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($cnt);
ckp((int)$row['n']===0, 'evol_cache_base vaciada para la key');

// 3) Con disco fresco + base vacía, el endpoint DEBE servir de disco (hit), sin reconstruir.
$r2 = evolCallEndpoint($prov, 'tab=data');
ckp(is_array($r2) && ($r2['ok']??false)===true, 'DISCO-PRIMERO: endpoint tab=data ok:true con base vacía (sirvió de disco)');
ckp(json_encode($r2)===json_encode($r1), 'DISCO-PRIMERO: mismo payload que el calentamiento (hit real de disco)');

echo $fail?"\n$fail FALLO(S)\n":"\nEVOL DISCO-PRIMERO OK\n"; exit($fail?1:0);
```

- [ ] **Step 4: Correr el assert de disco-primero**

Run: `php tests/verificar_evol_disco_primero.php`
Expected: todas `OK`, termina `EVOL DISCO-PRIMERO OK`, `0` fallos. (Sin el fix, el paso 3 reconstruiría la base o divergiría → FAIL.)

- [ ] **Step 5: Commit**

```bash
git add api/informe_evol.php tests/verificar_evol_disco_primero.php
git commit -m "fix(evol): disco-primero — servir cache sin materializar evol_cache_base en hit (elimina la carga lenta del dia)"
```

---

### Task 4: Consistencia del prewarm (evol deja de hacer skip erróneo)

**Files:**
- Modify: `api/lib_prewarm.php:30`
- Test: correr el prewarm y verificar `evol=warmed` + hit posterior

**Interfaces:**
- Consumes: `evolCurrentStamp($conn)` (Task 1). `warmProveedor` ya usa `evolDiskFresh($conn,$ek)` (firma intacta) en el gate `onlyIfStale`.

- [ ] **Step 1: Actualizar la llamada a `evolCurrentStamp` en warmProveedor**

En `api/lib_prewarm.php:30`, cambiar:
```php
        else { $st=evolCurrentStamp($conn,$ek); $p=evolBuildPayload($conn,$proveedor,$ek,$ed,$eh);
```
por:
```php
        else { $st=evolCurrentStamp($conn); $p=evolBuildPayload($conn,$proveedor,$ek,$ed,$eh);
```

- [ ] **Step 2: Verificar prewarm en frío -> warmed, y que el gate onlyIfStale ya coincide con el endpoint**

Con el disco de evol purgado, correr el prewarm de un proveedor. Crear/ejecutar un pequeño runner (o usar `sql/prebuild_all.php --dry` primero para ver proveedores). Ejecutar:

Run:
```bash
php -r "require 'C:/xampp/htdocs/plataforma_20/conexion/conexion_integracion.php'; require 'C:/xampp/htdocs/plataforma_20/api/lib_prewarm.php'; \$r=warmProveedor(\$dbConnect,'BH BRANDS SAS',false); echo 'force=false => '.json_encode(\$r).PHP_EOL; \$r2=warmProveedor(\$dbConnect,'BH BRANDS SAS',true); echo 'onlyIfStale=true (ya caliente) => '.json_encode(\$r2).PHP_EOL;" 2>&1 | grep -v -i warning
```
Expected:
- Primera llamada (`force=false`): `evol` = `warmed` (reconstruye + escribe disco).
- Segunda llamada (`onlyIfStale=true`, ya caliente): `evol` = `skipped` **correctamente** (ahora el skip refleja disco realmente fresco vs. la fuente, no el bug). o14c/o45 análogos.

- [ ] **Step 3: Verificar hit de disco en el endpoint tras el prewarm**

Run: `php tests/verificar_evol_disco_primero.php`
Expected: `EVOL DISCO-PRIMERO OK` (confirma que lo que dejó el prewarm sirve de disco).

- [ ] **Step 4: Commit**

```bash
git add api/lib_prewarm.php
git commit -m "fix(evol): warmProveedor usa evolCurrentStamp() de fuente — prewarm y endpoint con criterio de frescura consistente"
```

---

### Task 5: Entregable operativo — agendar el prebuild nocturno en WMS-LAB

**Files:**
- Create: `sql/AGENDAR_PREBUILD_WMS-LAB.md`

**Interfaces:** ninguno (documento + comando para Rafael).

- [ ] **Step 1: Confirmar el .bat de prebuild y la ruta en WMS-LAB**

Run: `cat sql/prebuild_all.bat`
Expected: ver la ruta a `php` y a `sql/prebuild_all.php` que usa el prebuild. Anotar la ruta real del proyecto en WMS-LAB (en local es `C:\xampp\htdocs\plataforma_20`; en prod es la carpeta de despliegue de WMS-LAB — Rafael confirma).

- [ ] **Step 2: Escribir la guía con el comando schtasks listo para pegar**

Crear `sql/AGENDAR_PREBUILD_WMS-LAB.md`:

```markdown
# Agendar el prebuild nocturno de caches (o14c/evol/o45) en WMS-LAB

Deja las 3 caches en disco calientes tras el ETL nocturno, para que el primer usuario del día
obtenga hit de disco (no la reconstrucción lenta). Correr DESPUÉS de que termine el ETL/Items_Mat.

## Comando (PowerShell/CMD como administrador, en WMS-LAB)

Ajustar la hora (`/st`) para que quede DESPUÉS del ETL. Ajustar la ruta del .bat a la carpeta
real del proyecto en WMS-LAB si no es `C:\xampp\htdocs\plataforma_20`.

    schtasks /Create /TN "Plataforma20 Prebuild Caches" /TR "C:\xampp\htdocs\plataforma_20\sql\prebuild_all.bat" /SC DAILY /ST 05:30 /RU SYSTEM /RL HIGHEST /F

## Verificar

    schtasks /Query /TN "Plataforma20 Prebuild Caches" /V /FO LIST

## Probar a mano (sin esperar a la noche)

    schtasks /Run /TN "Plataforma20 Prebuild Caches"

Luego revisar que se escribieron los .gz recientes:

    dir C:\xampp\htdocs\plataforma_20\cache\evol_*.json.gz

## Notas
- `/RU SYSTEM` evita depender de una sesión de usuario logueada.
- La tarea corre `prebuild_all.php` para TODOS los proveedores (warmProveedor con onlyIfStale=false).
- El login-prewarm queda como red de seguridad si un proveedor no se alcanzó a precalentar.
```

- [ ] **Step 3: Commit**

```bash
git add sql/AGENDAR_PREBUILD_WMS-LAB.md
git commit -m "docs(evol): guia schtasks para agendar el prebuild nocturno en WMS-LAB"
```

---

## Verificación final (todo junto)

- [ ] **Correr las tres suites por separado** (NO en paralelo — gotcha de paridad):

```bash
php tests/verificar_evol_disco.php --paridad
php tests/verificar_evol_disco.php --e2e
php tests/verificar_evol_disco_primero.php
```
Expected: cada una termina en OK con `0` fallos.

- [ ] **Sanity manual del síntoma original:** purgar disco de evol (`del cache\evol_*.json.gz cache\evol_*.stamp`), abrir el informe en el navegador (primera carga = miss, reconstruye una vez), recargar (segunda = hit de disco, instantáneo). Confirmar que una tercera carga tras vaciar `evol_cache_base` sigue siendo hit (disco-primero).

## Self-Review (cobertura del spec)

- Spec §Diseño 1 (stamp de fuente) → Task 1. ✓
- Spec §Diseño 2 (reordenar endpoint disco-primero) → Task 3. ✓
- Spec §Diseño 3 (`$force`) → Task 2. ✓
- Spec §Diseño 4 (agendar prebuild) → Task 5. ✓
- Spec §Consistencia prewarm (fin del `evol=skipped` erróneo) → Task 4. ✓
- Spec §Paridad y pruebas → Tasks 1-4 (suites `--paridad`, `--e2e`, disco-primero). ✓
- Spec §Riesgo "camino filtrado depende de la base" → Task 3 Step 1 (bloque final `if ($cacheMode) ensure`). ✓
