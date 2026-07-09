# EVOL cache en disco + lib de disco compartido (refactor o14c) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que la carga por defecto de Evolución (evol `tab=data` sin filtro) sirva desde un cache en disco gzip (~7s→~2s), generalizando las primitivas de disco a un lib compartido y refactorizando o14c para usarlo.

**Architecture:** Se extrae `api/lib_disk_cache.php` (primitivas genéricas parametrizadas por prefijo + stamp-como-parámetro). `lib_o14c_payload.php` se refactoriza a wrappers finos sobre él (firmas públicas intactas → endpoint/prebuild de o14c sin tocar). evol gana `evolBuildPayload`/`evolCurrentStamp` y un corto-circuito de disco en su endpoint, análogo a o14c. Filtrado/otros tabs intactos.

**Tech Stack:** PHP 8 + sqlsrv (SQL Server/RDS), gzip nativo, tests como scripts PHP CLI (patrón del repo). Windows.

## Global Constraints

- **Paridad byte-a-byte:** el payload evol servido desde disco == vivo `?nocache=1` de `tab=data` sin filtro (tolerando la firma de staleness angosta de evol: solo `stock` del mes en curso deriva). Y **o14c debe quedar idéntico** tras el refactor (sus suites verdes).
- **No tocar tablas SIESA.** Solo se lee `evol_cache_base`/`o14_cache_base` (INTEGRACION) y se escribe en disco.
- **Solo el caso lento:** disco aplica a `tab==='data' && $cacheMode && sin filtros REF/negocio/BOD`. Filtrado y otros tabs: camino actual intacto.
- **Degradar sin romper:** `cache/` no escribible / gzip / lock fallan → camino de filas; NUNCA 500.
- **Escritura atómica** (tmp+rename), **orden payload→stamp**, **frescura por stamp** (timestamp-DB vs timestamp-DB), **concurrencia** flock+double-check sobre el applock heredado.
- Reusar `evolCacheKey`/`ensureEvolCacheBase`/`evolCacheFresco`/`EVOL_CACHE_TTL_MIN` (`api/lib_evol_cache.php`), `buildRefsFromMat` (`api/lib_refs.php`), carpeta `cache/` (gitignored).
- **Rama:** `feature/evol-cache-disco`. Spec: `docs/superpowers/specs/2026-07-09-evol-cache-disco-design.md`.

---

## File Structure

- **Crear** `api/lib_disk_cache.php` — primitivas de disco genéricas (prefijo + stamp-param). Fuente única del cache en disco para o14c/evol/o45.
- **Modificar** `api/lib_o14c_payload.php` — las primitivas de disco pasan a wrappers sobre `lib_disk_cache.php`; firmas públicas intactas; `ensamblarArbol`/`o14cBuildPayloadC`/`o14cCurrentStamp` sin cambios.
- **Crear** `api/lib_evol_disk.php` — `evolCurrentStamp` + `evolBuildPayload` (auto-contenido) + wrappers de disco con prefijo `'evol'`.
- **Modificar** `api/informe_evol.php` — corto-circuito de disco en `tab=data` cacheMode sin filtros + wiring de cleanup.
- **Crear/Modificar** `tests/verificar_disk_cache.php` (nuevo, primitivas del lib compartido) y `tests/verificar_evol_disco.php` (nuevo, paridad/frescura/e2e de evol).

---

## Task 1: `lib_disk_cache.php` — primitivas genéricas

**Files:**
- Create: `api/lib_disk_cache.php`
- Test: `tests/verificar_disk_cache.php`

**Interfaces:**
- Produces: `diskCacheDir()`, `diskCachePath($prefix,$key)`, `diskCacheStampPath($prefix,$key)`, `diskCacheWrite($prefix,$key,$json,$stamp):bool`, `diskCacheRead($prefix,$key):?string`, `diskCacheFresh($prefix,$key,?string $currentStamp):bool`, `diskCacheCleanup($prefix,int $ttlMin):void`, `diskCacheServeGz($gz):void`.

- [ ] **Step 1: Write the failing test** — crear `tests/verificar_disk_cache.php` (DB-independiente):

```php
<?php
/** Tests del lib de disco compartido (DB-independiente). php tests/verificar_disk_cache.php */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../api/lib_disk_cache.php';
$fail = 0; function ck($c,$m){ global $fail; echo ($c?"OK  ":"FAIL")."  $m\n"; if(!$c)$fail++; }

$px='tdc'.getmypid(); $key='k1';
$json=json_encode(['a'=>1,'x'=>'áé']);
ck(diskCacheWrite($px,$key,$json,'STAMP-1'), 'write true');
ck(is_file(diskCachePath($px,$key)) && is_file(diskCacheStampPath($px,$key)), 'existen .json.gz + .stamp');
ck(gzdecode(diskCacheRead($px,$key))===$json, 'read->gzdecode == json');
ck(diskCacheRead($px,'nope')===null, 'read inexistente = null');
// frescura por stamp pasado como parametro
ck(diskCacheFresh($px,$key,'STAMP-1')===true, 'fresh true con stamp igual');
ck(diskCacheFresh($px,$key,'STAMP-2')===false, 'fresh false con stamp distinto');
ck(diskCacheFresh($px,$key,null)===false, 'fresh false con stamp null');
ck(diskCacheFresh($px,'nope','STAMP-1')===false, 'fresh false sin archivo');
// cleanup barre .json.gz/.stamp/.tmp/.lock viejos
foreach(['.json.gz','.stamp'] as $s){ touch(diskCacheDir()."/${px}_old${s}", time()-9999999); }
touch(diskCacheDir()."/${px}_x.json.gz.tmp.9", time()-9999999);
touch(diskCacheDir()."/${px}_x.json.gz.lock", time()-9999999);
diskCacheCleanup($px, 120);
ck(!is_file(diskCacheDir()."/${px}_old.json.gz") && !glob(diskCacheDir()."/${px}_x.*"), 'cleanup barre viejos (.json.gz/.stamp/.tmp/.lock)');
// limpieza del caso fresco
@unlink(diskCachePath($px,$key)); @unlink(diskCacheStampPath($px,$key));
echo $fail?"\n$fail FALLO(S)\n":"\nTODO OK\n"; exit($fail?1:0);
```

Run: `php tests/verificar_disk_cache.php` → FAIL (`Call to undefined function diskCacheWrite()`).

- [ ] **Step 2: Implementar `api/lib_disk_cache.php`**

```php
<?php
/**
 * Cache en disco GENÉRICO (gzip) para payloads deterministas, compartido por o14c/evol/o45.
 * Puro filesystem + gzip: NO conoce la BD. La frescura se decide comparando el .stamp en disco
 * contra un $currentStamp que calcula el caller (o14c/evol: `creado`; o45: otra fuente).
 * Rutas relativas a la carpeta existente cache/. Ver spec 2026-07-09-evol-cache-disco.
 */
if (!function_exists('diskCacheDir')) {
    function diskCacheDir(): string { return __DIR__ . '/../cache'; }
    // $key/$prefix se asumen seguros como nombre de archivo (hash md5 / literal corto del caller).
    function diskCachePath(string $prefix, string $key): string { return diskCacheDir() . "/{$prefix}_{$key}.json.gz"; }
    function diskCacheStampPath(string $prefix, string $key): string { return diskCacheDir() . "/{$prefix}_{$key}.stamp"; }

    function diskCacheWrite(string $prefix, string $key, string $jsonPlano, string $stamp): bool {
        $dir = diskCacheDir();
        if (!is_dir($dir) || !is_writable($dir)) return false;   // degradar sin romper
        $gz = gzencode($jsonPlano, 6);
        if ($gz === false) return false;
        // ORDEN payload->stamp: si el stamp falla queda payload-nuevo+stamp-viejo -> fresh FALSE
        // -> rebuild (desperdicio, no incorrección). Invertir serviría árbol STALE. NO invertir.
        $p = diskCachePath($prefix,$key); $tmp = $p . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $gz) === false) { @unlink($tmp); return false; }
        if (!@rename($tmp, $p)) { @unlink($tmp); return false; }
        $ps = diskCacheStampPath($prefix,$key); $tmpS = $ps . '.tmp.' . getmypid();
        if (@file_put_contents($tmpS, $stamp) === false) { @unlink($tmpS); return false; }
        if (!@rename($tmpS, $ps)) { @unlink($tmpS); return false; }
        return true;
    }

    function diskCacheRead(string $prefix, string $key): ?string {
        $p = diskCachePath($prefix,$key);
        if (!is_file($p)) return null;
        $b = @file_get_contents($p);
        return $b === false ? null : $b;
    }

    function diskCacheFresh(string $prefix, string $key, ?string $currentStamp): bool {
        if ($currentStamp === null) return false;
        if (!is_file(diskCachePath($prefix,$key)) || !is_file(diskCacheStampPath($prefix,$key))) return false;
        $s = @file_get_contents(diskCacheStampPath($prefix,$key));
        return $s !== false && $s === $currentStamp;
    }

    function diskCacheCleanup(string $prefix, int $ttlMin): void {
        $dir = diskCacheDir(); if (!is_dir($dir)) return;
        $limite = time() - $ttlMin * 60;
        foreach (glob("$dir/{$prefix}_*.json.gz") ?: [] as $f)
            if (@filemtime($f) < $limite) { @unlink($f); @unlink(substr($f,0,-8).'.stamp'); }
        foreach (glob("$dir/{$prefix}_*.tmp.*") ?: [] as $f) if (@filemtime($f) < $limite) @unlink($f);
        foreach (glob("$dir/{$prefix}_*.lock") ?: [] as $f) if (@filemtime($f) < $limite) @unlink($f);
    }

    function diskCacheServeGz(string $gz): void {
        header('Content-Type: application/json; charset=utf-8');
        header('Vary: Accept-Encoding');
        $ae = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        $z = ini_get('zlib.output_compression');
        $zlibOn = ($z && strtolower((string)$z) !== 'off' && (string)$z !== '0');
        $recomprime = $zlibOn || in_array('ob_gzhandler', ob_list_handlers(), true);
        if (stripos($ae,'gzip') !== false && !$recomprime) { header('Content-Encoding: gzip'); echo $gz; }
        else { echo gzdecode($gz); }
    }
}
```

- [ ] **Step 3: Run test → PASS**

Run: `php tests/verificar_disk_cache.php` → `TODO OK`, exit 0.
Run: `php -l api/lib_disk_cache.php` → sin errores.

- [ ] **Step 4: Commit**

```bash
git add api/lib_disk_cache.php tests/verificar_disk_cache.php
git commit -m "feat(disk-cache): lib de disco compartido (prefijo + stamp-como-parametro)"
```

---

## Task 2: Refactor `lib_o14c_payload.php` a wrappers sobre el lib compartido

**Files:**
- Modify: `api/lib_o14c_payload.php`

**Interfaces:**
- Consumes: `lib_disk_cache.php`.
- Produces (firmas PÚBLICAS SIN CAMBIO): `o14cPayloadPath`, `o14cStampPath`, `o14cWritePayload`, `o14cReadPayload`, `o14cDiskFresh($conn,$key)`, `o14cCleanup`, `o14cServeGz`. Sin cambio: `ensamblarArbol`, `o14cBuildPayloadC`, `o14cCurrentStamp`.

- [ ] **Step 1: Reescribir las primitivas de disco como wrappers**

En `api/lib_o14c_payload.php`: añadir al inicio (tras el docblock) `require_once __DIR__ . '/lib_disk_cache.php';`. Reemplazar los cuerpos de `o14cPayloadPath/o14cStampPath/o14cWritePayload/o14cReadPayload/o14cCleanup/o14cServeGz` y `o14cDiskFresh` por delegaciones (borrando la implementación inline de write/read/cleanup/servegz que hoy vive ahí). `o14cCurrentStamp`, `o14cBuildPayloadC`, `ensamblarArbol` quedan IGUAL:

```php
    function o14cPayloadPath(string $key): string { return diskCachePath('o14c', $key); }
    function o14cStampPath(string $key): string { return diskCacheStampPath('o14c', $key); }
    function o14cWritePayload(string $key, string $jsonPlano, string $stamp): bool { return diskCacheWrite('o14c', $key, $jsonPlano, $stamp); }
    function o14cReadPayload(string $key): ?string { return diskCacheRead('o14c', $key); }
    function o14cCleanup(): void { diskCacheCleanup('o14c', O14_CACHE_TTL_MIN); }
    function o14cServeGz(string $gz): void { diskCacheServeGz($gz); }
    // o14cDiskFresh delega, calculando el stamp desde creado (o14cCurrentStamp queda igual):
    function o14cDiskFresh($conn, string $key): bool { return diskCacheFresh('o14c', $key, o14cCurrentStamp($conn, $key)); }
```

(Conservar los `if (!function_exists(...))` guards existentes según la estructura del archivo. `O14_CACHE_TTL_MIN` viene de `lib_o14_cache.php`, ya requerido.)

- [ ] **Step 2: Verificar que o14c NO cambió de comportamiento**

Run: `php -l api/lib_o14c_payload.php` → sin errores.
Run: `php tests/verificar_o14c_payload.php` → `TODO OK` (primitivas siguen pasando vía los wrappers).
Run: `php tests/verificar_o14c_payload.php --paridad` → `PARIDAD OK` + frescura OK.
Run: `php tests/verificar_o14c_payload.php --e2e` → `PARIDAD E2E OK` (hit/miss + disk-file). Lento (RDS ~1-2min), timeout ≥180000, no matar.
Run: `php tests/verificar_o14_cache.php --paridad` → 36/36, `PARIDAD O14 OK`. Lento (~2-4min), timeout ≥360000, no matar.

Expected: todo verde — prueba que el refactor es transparente. (Ignorar warnings de arranque xdebug/dio_ts/openssl.)

- [ ] **Step 3: Commit**

```bash
git add api/lib_o14c_payload.php
git commit -m "refactor(o14c): primitivas de disco como wrappers sobre lib_disk_cache (sin cambio de comportamiento)"
```

---

## Task 3: evol — `evolCurrentStamp` + `evolBuildPayload`

**Files:**
- Create: `api/lib_evol_disk.php`
- Test: `tests/verificar_evol_disco.php` (+ helper `--paridad`)

**Interfaces:**
- Consumes: `lib_disk_cache.php`, `lib_evol_cache.php` (`evolCacheKey`, `ensureEvolCacheBase`, `EVOL_CACHE_TTL_MIN`), `lib_refs.php` (`buildRefsFromMat`).
- Produces:
  - `evolCurrentStamp($conn, string $ekey): ?string`.
  - `evolBuildPayload($conn, string $proveedor, string $ekey, string $desdeMes, string $hastaMes): array` — payload `tab=data` sin filtro, mismo shape que `informe_evol.php` emite hoy.
  - Wrappers de disco `'evol'`: `evolDiskFresh($conn,$ekey)`, `evolReadPayload($ekey)`, `evolWritePayload($ekey,$json,$stamp)`, `evolCleanup()`, `evolServeGz($gz)`.

- [ ] **Step 1: Write the failing test** — crear `tests/verificar_evol_disco.php`:

```php
<?php
/**
 * evol cache en disco. php tests/verificar_evol_disco.php --paridad  (requiere DB)
 * Verifica que evolBuildPayload devuelve un payload tab=data bien formado y consistente,
 * y la frescura por stamp. La paridad byte-a-byte vs vivo la cubre verificar_evol_cache --paridad
 * (Task 4, que ya rutea tab=data sin filtro por el disco).
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../api/lib_disk_cache.php';
require __DIR__ . '/../api/lib_evol_cache.php';
require __DIR__ . '/../api/lib_evol_disk.php';
if (($argv[1] ?? '') !== '--paridad') { echo "usar --paridad (requiere DB)\n"; exit(0); }
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
if ($dbConnect===false){ echo "SKIP sin DB\n"; exit(0); }
$conn=$dbConnect; $fail=0; function ck($c,$m){ global $fail; echo ($c?"OK  ":"FAIL")."  $m\n"; if(!$c)$fail++; }
$prov='BH BRANDS SAS'; $desde=(date('Y')-1).'-01'; $hasta=date('Y-m');
buildRefsFromMat($conn,$prov);
$ekey=evolCacheKey($prov,$desde,$hasta);
ensureEvolCacheBase($conn,$ekey,$desde,$hasta);
$p=evolBuildPayload($conn,$prov,$ekey,$desde,$hasta);
ck(($p['ok']??false)===true && is_array($p['negocios']) && isset($p['totalGeneral']) && is_array($p['meses']), 'payload bien formado');
// consistencia: total ventas del ultimo mes == suma de negocios en ese mes
$mUlt=end($p['meses']);
$sum=0; foreach($p['negocios'] as $n) $sum += ($n['valores']['ventas'][$mUlt] ?? 0);
ck($sum === ($p['totalGeneral']['valores']['ventas'][$mUlt] ?? -1), "suma ventas negocios ($sum) == totalGeneral mes $mUlt");
// frescura
$stamp=evolCurrentStamp($conn,$ekey);
ck($stamp!==null, "evolCurrentStamp no-null ($stamp)");
evolWritePayload($ekey, json_encode($p,JSON_UNESCAPED_UNICODE), $stamp);
ck(evolDiskFresh($conn,$ekey)===true, 'evolDiskFresh true tras escribir con stamp vigente');
file_put_contents(diskCacheStampPath('evol',$ekey),'STALE');
ck(evolDiskFresh($conn,$ekey)===false, 'evolDiskFresh false con stamp de disco desfasado');
@unlink(diskCachePath('evol',$ekey)); @unlink(diskCacheStampPath('evol',$ekey));
echo $fail?"\n$fail FALLO(S)\n":"\nEVOL PAYLOAD OK\n"; exit($fail?1:0);
```

Run: `php tests/verificar_evol_disco.php --paridad` → FAIL (`Call to undefined function evolBuildPayload()`).

- [ ] **Step 2: Implementar `api/lib_evol_disk.php`**

`evolBuildPayload` **replica el bloque `tab=data` cacheMode SIN filtro** de `informe_evol.php` (queries de líneas 254-268 `$agg` y 328-341 `$aggTot` con `WHERE c.cache_key=?` y **sin** `$whereFiltros`; el map de marca 288-289; el ensamblado PHP 293-372; el payload 375-381), auto-contenido (recomputa `$mesActual/$meses/$hastaF/$diasMes` del período). Copiar los cuerpos SQL y las fórmulas **verbatim** de esas líneas para preservar paridad — cambiar SOLO: quitar `$whereFiltros`/`$paramsFiltros` (params = `[$ekey]`), usar fetch inline (no el `run()` del endpoint), y devolver el array en vez de `echo`.

```php
<?php
/** evol cache en disco: constructor del payload tab=data sin filtro + frescura, sobre lib_disk_cache. */
require_once __DIR__ . '/lib_disk_cache.php';
require_once __DIR__ . '/lib_evol_cache.php'; // evolCacheKey/ensure/EVOL_CACHE_TTL_MIN

if (!function_exists('evolCurrentStamp')) {
    function evolCurrentStamp($conn, string $ekey): ?string {
        $st = sqlsrv_query($conn, "SELECT TOP 1 CONVERT(varchar(30),creado,126) s FROM INTEGRACION.dbo.evol_cache_base WITH (READPAST) WHERE cache_key=? ORDER BY creado DESC", [$ekey]);
        if ($st===false) return null; $r=sqlsrv_fetch_array($st,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($st);
        return $r ? $r['s'] : null;
    }
    function evolDiskFresh($conn, string $ekey): bool { return diskCacheFresh('evol', $ekey, evolCurrentStamp($conn,$ekey)); }
    function evolReadPayload(string $ekey): ?string { return diskCacheRead('evol', $ekey); }
    function evolWritePayload(string $ekey, string $json, string $stamp): bool { return diskCacheWrite('evol', $ekey, $json, $stamp); }
    function evolCleanup(): void { diskCacheCleanup('evol', EVOL_CACHE_TTL_MIN); }
    function evolServeGz(string $gz): void { diskCacheServeGz($gz); }

    function evolFetch($conn,$sql,$p){ $s=sqlsrv_query($conn,$sql,$p); if($s===false) return ['error'=>sqlsrv_errors()];
        $r=[]; while($x=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC))$r[]=$x; sqlsrv_free_stmt($s); return $r; }

    function evolBuildPayload($conn, string $proveedor, string $ekey, string $desdeMes, string $hastaMes): array {
        $mesActual = date('Y-m');
        $meses = [];
        for ($c=$desdeMes.'-01'; $c<=$hastaMes.'-01'; $c=date('Y-m-01', strtotime($c.' +1 month'))) $meses[]=substr($c,0,7);
        $ayer=date('Y-m-d', strtotime('-1 day'));
        $hastaF=date('Y-m-t', strtotime($hastaMes.'-01')); if ($hastaF>$ayer) $hastaF=$ayer;
        $diasMes = function($m) use ($mesActual){ return $m===$mesActual ? max(1,(int)date('j',strtotime('-1 day'))) : (int)date('t',strtotime($m.'-01')); };

        // $agg: COPIAR VERBATIM la query cacheMode de informe_evol.php:255-268 pero SIN $whereFiltros; params=[$ekey].
        $agg = evolFetch($conn, "<<PEGAR VERBATIM 255-268 sin \$whereFiltros>>", [$ekey]);
        // marca por negocio (informe_evol.php:289 sin \$whereFiltros):
        $marcaMap=[]; $rm = evolFetch($conn, "SELECT c.negocio, MAX(c.marca) marca FROM INTEGRACION.dbo.evol_cache_base c WHERE c.cache_key=? GROUP BY c.negocio", [$ekey]);
        if (!isset($rm['error'])) foreach($rm as $x) $marcaMap[$x['negocio']]=trim((string)$x['marca']);

        // ensamblado $neg + sort  (COPIAR VERBATIM informe_evol.php:299-323)
        // ... (verbatim) ... -> $negocios

        // $aggTot: COPIAR VERBATIM informe_evol.php:329-341 sin \$whereFiltros; params=[$ekey].
        $aggTot = evolFetch($conn, "<<PEGAR VERBATIM 329-341 sin \$whereFiltros>>", [$ekey]);
        // $totalGeneral (COPIAR VERBATIM informe_evol.php:357-372)
        // ... (verbatim) ...

        return ['ok'=>true, 'proveedor'=>$proveedor, 'meses'=>$meses, 'mesActual'=>$mesActual,
                'rango'=>['desde'=>$desdeMes,'hasta'=>$hastaMes,'corte_ventas'=>$hastaF],
                'negocios'=>$negocios, 'totalGeneral'=>$totalGeneral];
    }
}
```

> El implementer DEBE pegar los cuerpos SQL (255-268, 329-341) y los bloques PHP (299-323, 357-372) **verbatim** desde `informe_evol.php`, cambiando solo lo indicado (sin `$whereFiltros`, params `[$ekey]`, `evolFetch` en vez de `run`). No re-derivar las fórmulas (romper paridad). Corregir también el marcador del test (Step 1) usando `diskCacheStampPath('evol',$ekey)`.

- [ ] **Step 3: Run test → PASS**

Run: `php -l api/lib_evol_disk.php` → sin errores.
Run: `php tests/verificar_evol_disco.php --paridad` → `EVOL PAYLOAD OK` (payload bien formado, suma consistente, frescura true/false). Lento (RDS, ~40-90s), timeout ≥180000, no matar.

- [ ] **Step 4: Commit**

```bash
git add api/lib_evol_disk.php tests/verificar_evol_disco.php
git commit -m "feat(evol): evolBuildPayload + frescura por stamp sobre lib_disk_cache"
```

---

## Task 4: Integración en `informe_evol.php` (tab=data sin filtro)

**Files:**
- Modify: `api/informe_evol.php`
- Test: `tests/verificar_evol_disco.php` (modo `--e2e`)

**Interfaces:**
- Consumes: `evolDiskFresh`, `evolReadPayload`, `evolWritePayload`, `evolCurrentStamp`, `evolBuildPayload`, `evolServeGz`, `evolCleanup`, `diskCachePath`.

- [ ] **Step 1: `require_once` + corto-circuito de disco**

En `api/informe_evol.php`, tras `require __DIR__ . '/lib_refs.php';` (~línea 45) añadir `require_once __DIR__ . '/lib_evol_disk.php';`.
Dentro del bloque `if ($cacheMode) { ... }` de setup (~91-98, donde ya se calcula `$ekey` y se llama `ensureEvolCacheBase`), tras `ensureEvolCacheBase` y **antes** de construir el payload (antes de la línea 251 `// ===== tab=data`), insertar el corto-circuito para tab=data sin filtros:

```php
if ($tab === 'data' && $cacheMode) {
    $evolSinFiltros = true;
    foreach (array_merge($FILTROS_REF, $FILTROS_BOD) as $k=>$col) { if (getMulti($k)) { $evolSinFiltros=false; break; } }
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
            $stamp = evolCurrentStamp($dbConnect, $ekey);
            $payload = evolBuildPayload($dbConnect, $proveedorSesion, $ekey, $desdeMes, $hastaMes);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($stamp !== null) evolWritePayload($ekey, $json, $stamp);
            flock($lk,LOCK_UN); fclose($lk); sqlsrv_close($dbConnect);
            evolServeGz(gzencode($json,6)); exit;
        }
        if ($lk) fclose($lk);
        // lock-fail -> cae al camino de filas de abajo (intacto)
    }
}
```

(Este bloque va DESPUÉS de que `$ekey`, `$whereFiltros`, `$paramsFiltros`, `$desdeMes`, `$hastaMes`, `$proveedorSesion` estén definidos y `ensureEvolCacheBase` haya corrido. El `require_once` de `lib_evol_disk.php` sube `lib_disk_cache.php`.)

- [ ] **Step 2: Wiring de cleanup**

Junto al cleanup de BD de evol (buscar `evolCacheCleanup(` en `informe_evol.php`) añadir `evolCleanup();` inmediatamente después.

- [ ] **Step 3: E2E `--e2e`** — añadir a `tests/verificar_evol_disco.php` un modo `--e2e` (reusar `o14CallEndpoint`/normalizador de `tests/_task4_paridad_o14.php` NO aplica a evol; usar el runner `tests/_endpoint_run_evol.php` vía `shell_exec` y el comparador de `tests/verificar_evol_cache.php`/su helper de paridad). Para BH BRANDS SAS: purgar la key + borrar `cache/evol_<ekey>.*`, llamar `tab=data` (disco) y `tab=data&nocache=1` (vivo), normalizar y exigir paridad (tolerando staleness de stock del mes actual), y `is_file(diskCachePath('evol',$ekey))` true tras la llamada de disco. Filtrado `tab=data&marca=X` → `ok:true`.

Run: `php -l api/informe_evol.php` → sin errores.
Run: `php tests/verificar_evol_cache.php --paridad` → verde (ahora `tab=data` sin filtro rutea por disco → prueba paridad disco↔vivo). Lento (~2-4min), timeout ≥360000, no matar.
Run: `php tests/verificar_evol_disco.php --e2e` → asserts OK.

- [ ] **Step 4: Commit**

```bash
git add api/informe_evol.php tests/verificar_evol_disco.php
git commit -m "feat(evol): endpoint tab=data sin filtro sirve desde cache en disco (hit/miss/flock/gzip)"
```

---

## Task 5: Revisión final + deploy

- [ ] **Step 1: `php -l`** en `api/{lib_disk_cache,lib_o14c_payload,lib_evol_disk,informe_evol}.php` → limpio.
- [ ] **Step 2: Suites** — `verificar_disk_cache.php` (TODO OK); `verificar_o14c_payload.php` (todos los modos) + `verificar_o14_cache.php --paridad` (o14c intacto tras refactor); `verificar_evol_disco.php --paridad` + `--e2e`; `verificar_evol_cache.php --paridad`. Todo verde.
- [ ] **Step 3: `requesting-code-review`** de la rama (opus): refactor o14c transparente, paridad evol, degradación, concurrencia, y que filtrado/otros tabs no cambiaron.
- [ ] **Step 4: Checklist de deploy** (Rafael): sin DDL nuevo. Re-sync a `plataforma_20_produccion` + aliados: `api/lib_disk_cache.php` (nuevo), `api/lib_o14c_payload.php` (refactor), `api/lib_evol_disk.php` (nuevo), `api/informe_evol.php` (mod). `cache/` ya escribible. E2E navegador: Evolución sin filtro rápido + bien (gzip).

---

## Self-Review (hecho)

- **Cobertura del spec:** §4.1 lib compartido → Task 1; §4.2 refactor o14c → Task 2; §4.3 evol (build/stamp) → Task 3; endpoint → Task 4; §6 pruebas → tests por task + Task 5; §7 deploy → Task 5 Step 4.
- **Placeholders:** los `<<PEGAR VERBATIM>>` de `evolBuildPayload` son deliberados — instruyen copiar exactamente los cuerpos SQL/PHP de `informe_evol.php` (líneas citadas) cambiando solo lo indicado; re-transcribir el SQL a mano rompería la paridad, así que copiar-verbatim es lo correcto. No hay otros placeholders.
- **Consistencia de firmas:** `diskCache*`(prefijo,key,...) usadas igual en Tasks 1-4; wrappers o14c/evol delegan con prefijo fijo; `evolBuildPayload($conn,$proveedor,$ekey,$desdeMes,$hastaMes)` estable.
