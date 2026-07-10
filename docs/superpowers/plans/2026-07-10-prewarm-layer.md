# Capa de calentamiento (prebuild nocturno + login-prewarm) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`).

**Goal:** Que nadie pague la construcción en frío de o14/evol/o45: `warmProveedor()` compartido + prebuild nocturno unificado (todos los proveedores) + login-prewarm (proceso detached en Windows al iniciar sesión, throttle + solo-si-stale).

**Architecture:** `api/lib_prewarm.php` con `warmProveedor($conn,$prov,$onlyIfStale)` que calienta los 3 informes. `sql/prebuild_all.php`+`.bat` lo llama para todos los proveedores (nocturno, tras ETL). `index.php` dispara `sql/prewarm_login.php <prov>` detached al login; ese script hace throttle por-proveedor y llama `warmProveedor(onlyIfStale=true)`.

**Tech Stack:** PHP 8 + sqlsrv (RDS), los libs de cache ya en `main` (lib_disk_cache/o14c/evol_disk/o45_disk), Windows Task Scheduler + `popen('start /B')`.

## Global Constraints

- **Aislamiento por proveedor:** cada proceso recibe el proveedor por argumento, abre su propia conexión, `#refs` propio, caches llaveadas por proveedor → cero cruce entre usuarios.
- **ok-gate en todo write:** escribir a disco SOLO si el payload tiene `ok===true` y el stamp no es null.
- **Login NO se bloquea:** el spawn es fire-and-forget (`@popen('start /B …')`+`pclose`); su fallo NO rompe el login.
- **Rangos por defecto EXACTOS de cada endpoint** (para que la key calentada == la que pide el navegador): o14c `2025-01-01`..hoy; evol `(Y-1)-01`..`Y-m`; o45 `2025-01-01`..ayer.
- **DRY:** `warmProveedor` es la única fuente de "construir+escribir por informe"; nocturno y login-prewarm la comparten. `prebuild_o14c.{php,bat}` se reemplaza por `prebuild_all`.
- **No tocar SIESA**; no cambiar el código/TTL de los informes.
- **Rama:** `feature/prewarm-layer`. Spec: `docs/superpowers/specs/2026-07-10-prewarm-layer-design.md`.

---

## File Structure

- **Crear** `api/lib_prewarm.php` — `warmProveedor()`.
- **Crear** `sql/prebuild_all.php` + `sql/prebuild_all.bat` — prebuild nocturno unificado.
- **Crear** `sql/prewarm_login.php` — login-prewarm (throttle + onlyIfStale).
- **Modificar** `index.php` — spawn detached tras fijar el proveedor.
- **Crear** `tests/verificar_prewarm.php` — smoke de `warmProveedor` + throttle.
- (Obsoleto) `sql/prebuild_o14c.{php,bat}` — se puede borrar o dejar; `prebuild_all` lo cubre.

---

## Task 1: `api/lib_prewarm.php` — `warmProveedor()`

**Files:**
- Create: `api/lib_prewarm.php`
- Test: `tests/verificar_prewarm.php`

**Interfaces:**
- Consumes: `lib_refs` (`buildRefsFromMat`), `lib_o14c_payload` (`o14CacheKey`,`ensureO14CacheBase`,`o14cCurrentStamp`,`o14cBuildPayloadC`,`o14cDiskFresh`,`o14cWritePayload`), `lib_evol_disk`+`lib_evol_cache` (`evolCacheKey`,`ensureEvolCacheBase`,`evolCurrentStamp`,`evolBuildPayload`,`evolDiskFresh`,`evolWritePayload`), `lib_o45_disk` (`o45CacheKey`,`o45CurrentStamp`,`o45BuildPayload`,`o45DiskFresh`,`o45WritePayload`).
- Produces: `warmProveedor($conn, string $proveedor, bool $onlyIfStale=false): array` → `['o14c'=>'warmed|skipped|failed…','evol'=>…,'o45'=>…]`.

- [ ] **Step 1: Write the failing test** — crear `tests/verificar_prewarm.php`:

```php
<?php
/** Smoke de warmProveedor (calienta los 3 informes de un proveedor). php tests/verificar_prewarm.php --run  (requiere DB, LENTO) */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../api/lib_disk_cache.php';
require __DIR__ . '/../api/lib_prewarm.php';
if (($argv[1] ?? '') !== '--run') { echo "usar --run (requiere DB, construye 3 informes)\n"; exit(0); }
require __DIR__ . '/../conexion/conexion_integracion.php';
if ($dbConnect===false){ echo "SKIP sin DB\n"; exit(0); }
$conn=$dbConnect; $fail=0; function ck($c,$m){ global $fail; echo ($c?"OK  ":"FAIL")."  $m\n"; if(!$c)$fail++; }
$prov='BELTRANY SAS';
// keys que warmProveedor debe dejar frescas (mismos defaults que los endpoints):
$k14=o14CacheKey($prov,'2025-01-01',date('Y-m-d'));
$ke =evolCacheKey($prov,(date('Y')-1).'-01',date('Y-m'));
$k45=o45CacheKey($prov,'2025-01-01',date('Y-m-d',strtotime('-1 day')));
// limpiar disco previo
foreach([['o14c',$k14],['evol',$ke],['o45',$k45]] as $x){ @unlink(diskCachePath($x[0],$x[1])); @unlink(diskCacheStampPath($x[0],$x[1])); }

$r = warmProveedor($conn, $prov, false);
echo "  warmProveedor(force) => o14c={$r['o14c']} evol={$r['evol']} o45={$r['o45']}\n";
ck($r['o14c']==='warmed' && $r['evol']==='warmed' && $r['o45']==='warmed', 'los 3 warmed');
ck(o14cDiskFresh($conn,$k14) && evolDiskFresh($conn,$ke) && o45DiskFresh($conn,$k45), 'los 3 frescos en disco tras warm');

$r2 = warmProveedor($conn, $prov, true); // onlyIfStale sobre cache ya fresco
echo "  warmProveedor(onlyIfStale) => o14c={$r2['o14c']} evol={$r2['evol']} o45={$r2['o45']}\n";
ck($r2['o14c']==='skipped' && $r2['evol']==='skipped' && $r2['o45']==='skipped', 'onlyIfStale skipea los 3 frescos');

foreach([['o14c',$k14],['evol',$ke],['o45',$k45]] as $x){ @unlink(diskCachePath($x[0],$x[1])); @unlink(diskCacheStampPath($x[0],$x[1])); }
echo $fail?"\n$fail FALLO(S)\n":"\nPREWARM OK\n"; exit($fail?1:0);
```

Run: `php tests/verificar_prewarm.php --run` → FAIL (`undefined function warmProveedor()`).

- [ ] **Step 2: Implementar `api/lib_prewarm.php`**

```php
<?php
/**
 * Capa de calentamiento: calienta las caches en disco de los 3 informes lentos de UN proveedor.
 * Fuente unica de "construir+escribir por informe", compartida por el prebuild nocturno
 * (sql/prebuild_all.php) y el login-prewarm (sql/prewarm_login.php). Ver spec 2026-07-10-prewarm-layer.
 * Aislamiento: opera sobre el $proveedor pasado, con su #refs en $conn; caches llaveadas por proveedor.
 */
require_once __DIR__ . '/lib_refs.php';
require_once __DIR__ . '/lib_o14c_payload.php';
require_once __DIR__ . '/lib_evol_disk.php';
require_once __DIR__ . '/lib_o45_disk.php';

if (!function_exists('warmProveedor')) {
    function warmProveedor($conn, string $proveedor, bool $onlyIfStale = false): array {
        // #refs una vez (los 3 builders lo comparten en $conn).
        if (!buildRefsFromMat($conn, $proveedor)) return ['o14c'=>'failed-refs','evol'=>'failed-refs','o45'=>'failed-refs'];
        $out = [];

        // --- o14c: 2025-01-01 .. hoy ---
        $d='2025-01-01'; $h=date('Y-m-d'); $k=o14CacheKey($proveedor,$d,$h);
        if ($onlyIfStale && o14cDiskFresh($conn,$k)) $out['o14c']='skipped';
        elseif (!ensureO14CacheBase($conn,$k,$d,$h)) $out['o14c']='failed-ensure';
        else { $st=o14cCurrentStamp($conn,$k); $p=o14cBuildPayloadC($conn,$k,$d,$h);
            $out['o14c']=(($p['ok']??false)===true && $st!==null && o14cWritePayload($k,json_encode($p,JSON_UNESCAPED_UNICODE),$st))?'warmed':'failed'; }

        // --- evol: (Y-1)-01 .. Y-m ---
        $ed=(date('Y')-1).'-01'; $eh=date('Y-m'); $ek=evolCacheKey($proveedor,$ed,$eh);
        if ($onlyIfStale && evolDiskFresh($conn,$ek)) $out['evol']='skipped';
        elseif (!ensureEvolCacheBase($conn,$ek,$ed,$eh)) $out['evol']='failed-ensure';
        else { $st=evolCurrentStamp($conn,$ek); $p=evolBuildPayload($conn,$proveedor,$ek,$ed,$eh);
            $out['evol']=(($p['ok']??false)===true && $st!==null && evolWritePayload($ek,json_encode($p,JSON_UNESCAPED_UNICODE),$st))?'warmed':'failed'; }

        // --- o45: 2025-01-01 .. ayer (o45 no tiene ensure; el build ES la materializacion) ---
        $od='2025-01-01'; $oh=date('Y-m-d',strtotime('-1 day')); $ok=o45CacheKey($proveedor,$od,$oh);
        if ($onlyIfStale && o45DiskFresh($conn,$ok)) $out['o45']='skipped';
        else { $st=o45CurrentStamp($conn); $p=o45BuildPayload($conn,$proveedor,$od,$oh);
            $out['o45']=(($p['ok']??false)===true && $st!==null && o45WritePayload($ok,json_encode($p,JSON_UNESCAPED_UNICODE),$st))?'warmed':'failed'; }

        return $out;
    }
}
```

- [ ] **Step 3: Run test → PASS**

Run: `php -l api/lib_prewarm.php` → sin errores.
Run: `php tests/verificar_prewarm.php --run` → `PREWARM OK` (los 3 warmed → frescos; onlyIfStale skipea). Construye 3 informes de BELTRANY contra la RDS — LENTO (~1-3 min), timeout ≥360000, no matar. Ignorar warnings. **Correr SOLO (no en paralelo con otra suite que toque estas keys).**

- [ ] **Step 4: Commit**

```bash
git add api/lib_prewarm.php tests/verificar_prewarm.php
git commit -m "feat(prewarm): warmProveedor() — calienta las 3 caches de un proveedor (ok-gate, onlyIfStale)"
```

---

## Task 2: Prebuild nocturno unificado `sql/prebuild_all.php` + `.bat`

**Files:**
- Create: `sql/prebuild_all.php`, `sql/prebuild_all.bat`
- (Opcional) borrar `sql/prebuild_o14c.{php,bat}`.

**Interfaces:** Consumes `warmProveedor` (`api/lib_prewarm.php`), `login_resolver_proveedor` (`api/lib_login.php`).

- [ ] **Step 1: Escribir `sql/prebuild_all.php`** (basado en `sql/prebuild_o14c.php`, con `--dry-run` y args explícitos)

```php
<?php
/** Prebuild nocturno de las 3 caches en disco (o14c/evol/o45) por proveedor. Correr DESPUES del ETL/Items_Mat.
 *   php sql/prebuild_all.php               (todos los proveedores)
 *   php sql/prebuild_all.php "BELTRANY SAS" (solo esos)
 *   php sql/prebuild_all.php --dry-run     (lista, no construye) */
error_reporting(E_ERROR | E_PARSE);
$t0=microtime(true);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_login.php';
require __DIR__ . '/../api/lib_prewarm.php';
if ($dbConnect===false){ fwrite(STDERR,"[prebuild_all] Conexion DB fallida\n"); exit(1); }
$args=array_slice($argv,1); $dry=in_array('--dry-run',$args,true); $args=array_values(array_filter($args,fn($a)=>$a!=='--dry-run'));

if ($args) { $provs=$args; }
else {
    $provs=[]; $st=sqlsrv_query($dbConnect,"SELECT DISTINCT nombre_usuario FROM usuarios_portal_aka WHERE nombre_usuario IS NOT NULL");
    if ($st!==false){ while($u=sqlsrv_fetch_array($st,SQLSRV_FETCH_ASSOC)){ $r=login_resolver_proveedor($dbConnect,trim((string)$u['nombre_usuario']));
        $p=trim((string)($r['proveedor']??'')); if($p!==''&&$p!=='__SIN_PROVEEDOR__')$provs[$p]=true; } sqlsrv_free_stmt($st); }
    $provs=array_keys($provs);
}
echo "[prebuild_all] ".date('Y-m-d H:i:s')." proveedores=".count($provs).($dry?" (DRY-RUN)":"")."\n";
if ($dry){ foreach($provs as $p) echo "  - $p\n"; sqlsrv_close($dbConnect); exit(0); }

$okN=0;$failN=0;
foreach($provs as $prov){ $tp=microtime(true); $r=warmProveedor($dbConnect,$prov,false);
    $bad=in_array('failed',$r,true)||in_array('failed-refs',$r,true)||in_array('failed-ensure',$r,true);
    printf("  %s %-28s o14c=%s evol=%s o45=%s %.1fs\n",$bad?'FALLO':'OK  ',$prov,$r['o14c'],$r['evol'],$r['o45'],microtime(true)-$tp);
    $bad?$failN++:$okN++; }
printf("[prebuild_all] fin: OK=%d FALLO=%d en %.1fs\n",$okN,$failN,microtime(true)-$t0);
sqlsrv_close($dbConnect); exit($failN?1:0);
```

- [ ] **Step 2: Escribir `sql/prebuild_all.bat`** (patrón `sql/refrescar_items_mat.bat`)

```bat
@echo off
REM Prebuild nocturno de las 3 caches en disco (o14c/evol/o45). Correr DESPUES del refresh de Items_Mat.
REM Programar diario (p.ej. 03:30) con "privilegios mas altos" + "aunque el usuario no haya iniciado sesion".
setlocal
set PHP_EXE=C:\xampp\php\php.exe
"%PHP_EXE%" "%~dp0prebuild_all.php" >> "%~dp0prebuild_all.log" 2>&1
endlocal
```

- [ ] **Step 3: Smoke**

Run: `php -l sql/prebuild_all.php` → limpio.
Run: `php sql/prebuild_all.php --dry-run` → `proveedores=N` (N≥1) + lista, sin construir, rápido.
Run: `php sql/prebuild_all.php "BELTRANY SAS"` → una línea `OK  BELTRANY SAS o14c=warmed evol=warmed o45=warmed …`, `OK=1 FALLO=0`. LENTO (~1-3min), timeout ≥360000, no matar. **Correr SOLO.**

- [ ] **Step 4: Commit**

```bash
git add sql/prebuild_all.php sql/prebuild_all.bat
git commit -m "feat(prewarm): prebuild nocturno unificado (o14c/evol/o45 x proveedor) + bat Task Scheduler"
```

---

## Task 3: Login-prewarm — `sql/prewarm_login.php` + spawn en `index.php`

**Files:**
- Create: `sql/prewarm_login.php`
- Modify: `index.php` (tras `$_SESSION['proveedor']`, antes del redirect)

**Interfaces:** Consumes `warmProveedor` (onlyIfStale=true).

- [ ] **Step 1: Escribir `sql/prewarm_login.php`**

```php
<?php
/** Login-prewarm: calienta en segundo plano SOLO lo stale del proveedor pasado, con throttle por-proveedor.
 * Lanzado detached por index.php al iniciar sesion. NO bloquea el login.
 *   php sql/prewarm_login.php "PROVEEDOR" */
error_reporting(E_ERROR | E_PARSE);
$t0=microtime(true);
$prov=$argv[1] ?? '';
if ($prov==='') exit(0);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_prewarm.php';
if ($dbConnect===false){ fwrite(STDERR,"[prewarm_login] ".date('Y-m-d H:i:s')." $prov: sin DB\n"); exit(1); }
// throttle: un prewarm por proveedor a la vez (no apilar en re-logins).
$lockPath=__DIR__.'/../cache/prewarm_'.substr(md5($prov),0,32).'.lock';
$lk=@fopen($lockPath,'c');
if (!$lk || !flock($lk,LOCK_EX|LOCK_NB)){ echo date('Y-m-d H:i:s')." $prov: throttled\n"; exit(0); }
$r=warmProveedor($dbConnect,$prov,true);
flock($lk,LOCK_UN); fclose($lk);
printf("%s %s: o14c=%s evol=%s o45=%s (%.1fs)\n",date('Y-m-d H:i:s'),$prov,$r['o14c'],$r['evol'],$r['o45'],microtime(true)-$t0);
sqlsrv_close($dbConnect); exit(0);
```

- [ ] **Step 2: Spawn detached en `index.php`**

En `index.php`, entre `sqlsrv_close($dbConnect);` (línea 90) y `header("Location: dashboard.php");` (línea 92), insertar:

```php
					// Login-prewarm: dispara en 2o plano el calentamiento de caches del proveedor
					// (fire-and-forget; NO bloquea el login; su fallo no rompe nada).
					$provPre = $_SESSION['proveedor'] ?? '';
					if ($provPre !== '') {
						$phpExe = 'C:\\xampp\\php\\php.exe';        // AJUSTAR en WMS-LAB si difiere
						$script = __DIR__ . '\\sql\\prewarm_login.php';
						$log    = __DIR__ . '\\sql\\prewarm_login.log';
						if (@is_file($phpExe) && @is_file($script)) {
							$cmd = 'start "" /B ' . escapeshellarg($phpExe) . ' ' . escapeshellarg($script)
							     . ' ' . escapeshellarg($provPre) . ' >> ' . escapeshellarg($log) . ' 2>&1';
							$hp = @popen($cmd, 'r'); if ($hp !== false) pclose($hp);
						}
					}
```

- [ ] **Step 3: Verificar `prewarm_login.php` standalone + throttle**

Run: `php -l index.php` y `php -l sql/prewarm_login.php` → limpio.
Run (cache de BELTRANY ya caliente del Task 1/2, o córrelo tras un prebuild): `php sql/prewarm_login.php "BELTRANY SAS"` → línea con `o14c=skipped evol=skipped o45=skipped` (si estaba fresco) o `warmed` (si stale). Exit 0.
Throttle: lanzar dos en paralelo (`php sql/prewarm_login.php "BELTRANY SAS" & php sql/prewarm_login.php "BELTRANY SAS"`) → uno debe imprimir `throttled`. (En Windows/Git-Bash usar dos terminales o `start`.)

- [ ] **Step 4: Verificación del spawn (parcial en CLI; E2E la cierra Rafael)**

El spawn de `index.php` requiere un POST de login real → su prueba definitiva es **E2E navegador de Rafael** (el login retorna instantáneo + `sql/prewarm_login.log` muestra actividad). En CLI, verificar que el `$cmd` está bien formado (imprimirlo) y que `popen('start "" /B php -v', 'r')` retorna sin bloquear. Documentar para el E2E de Rafael: (1) el login se siente igual de rápido; (2) `prewarm_login.log` registra el prewarm tras loguear.

- [ ] **Step 5: Commit**

```bash
git add index.php sql/prewarm_login.php
git commit -m "feat(prewarm): login-prewarm — spawn detached en index.php + prewarm_login.php (throttle + onlyIfStale)"
```

---

## Task 4: Revisión final + config Task Scheduler + deploy

**Files:** `docs/` (guía de la tarea nocturna) — sin código nuevo.

- [ ] **Step 1: `php -l`** en `index.php` + `api/lib_prewarm.php` + `sql/{prebuild_all,prewarm_login}.php` → limpio.
- [ ] **Step 2: Suites** — `verificar_prewarm.php --run` (`PREWARM OK`) + `prebuild_all.php --dry-run`. Verde. (SOLO, sin concurrencia.)
- [ ] **Step 3: `requesting-code-review`** de la rama (opus): DRY de `warmProveedor`, ok-gate, aislamiento por proveedor, throttle, que el spawn no bloquee ni rompa el login, y rangos por defecto == endpoints.
- [ ] **Step 4: Redactar la guía de Task Scheduler** (`docs/deploy-tareas-nocturnas.md`): comandos `schtasks /create` exactos para WMS-LAB para (a) `refrescar_items_mat.bat` ~03:00 y (b) `prebuild_all.bat` ~03:30, con `/RU SYSTEM` (o cuenta con "ejecutar aunque no haya sesión") + `/RL HIGHEST`; nota de ajustar `PHP_EXE` y la ruta php del spawn de `index.php`.
- [ ] **Step 5: Checklist de deploy** (Rafael): sin DDL. Re-sync a `plataforma_20_produccion` + WMS-LAB: `index.php`, `api/lib_prewarm.php`, `sql/{prebuild_all.php,prebuild_all.bat,prewarm_login.php}`. Montar las 2 tareas nocturnas. E2E navegador: login instantáneo + `prewarm_login.log` activo + los 3 informes cargan al instante.

---

## Self-Review (hecho)

- **Cobertura del spec:** §4.1 `warmProveedor` → Task 1; §4.2 nocturno → Task 2; §4.3 login-prewarm → Task 3; §6 Task Scheduler → Task 4 Step 4; §7 pruebas → tasks + Task 4; §8 deploy → Task 4 Step 5.
- **Placeholders:** ninguno; el spawn de `index.php` trae la ruta php como const AJUSTABLE (documentado), no un placeholder. La verificación del spawn se reparte CLI (Task 3 Step 4) + E2E navegador (Rafael), explícito.
- **Consistencia de firmas:** `warmProveedor($conn,$prov,$onlyIfStale)` usada igual en Tasks 2-3; rangos por defecto (o14c/evol/o45) idénticos a los endpoints; ok-gate en cada write.
- **Riesgo asumido:** el spawn detached en Windows es el punto frágil (Task 3); si falla en WMS-LAB, es aditivo (nocturno + cache-en-miss cubren) y `@popen` no rompe el login.
