# O45 (Índice de Ventas) cache en disco — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que la carga inicial de o45 (`tab=dataset`, ~112s/9MB) sirva desde un cache en disco gzip (~instantánea), reusando el `lib_disk_cache.php` compartido, con frescura por un stamp de fuente que avanza con el ETL nocturno.

**Architecture:** Nuevo `api/lib_o45_disk.php` (`o45CacheKey`, `o45CurrentStamp` = stamp compuesto de fuente, `o45BuildPayload` = envuelve la construcción actual de `tab=dataset`, wrappers de disco `'o45'`). Corto-circuito de disco en `informe_o45.php` tab=dataset (hit/miss/flock/gzip + ok-gate) con bypass `?nocache=1` para oráculo. Filtrado en cliente y demás tabs intactos.

**Tech Stack:** PHP 8 + sqlsrv (RDS), `lib_disk_cache.php` (ya existe, del sub-proyecto evol), tests PHP CLI. Windows.

## Global Constraints

- **Paridad:** payload servido desde disco == vivo `?tab=dataset&nocache=1` (mismo proveedor+rango), tolerando la firma de staleness del corte actual (stock/hold que puede derivar intradía).
- **No tocar tablas SIESA.** Solo se lee (`inv_actual_PBI`, `Ventas_Detal_PBI`, historicos, etc.) y se escribe en disco.
- **`tab=dataset` NO tiene filtros de servidor** (el filtrado es en cliente) → el corto-circuito aplica SIEMPRE para ese tab (sin detección de filtros). Solo lo saltea `?nocache=1`.
- **ok-gate:** el endpoint-miss escribe a disco SOLO si `$payload['ok']===true`; error → 500 sin cachear.
- **Degradar sin romper:** `cache/` no escribible / lock-fail / build error → camino actual (`buildO45Dataset` directo) o 500; NUNCA envenenar el cache.
- **Envelope:** el payload usa **`$proveedorSesion`** en el campo `proveedor` (no el fallback `$proveedor`), igual que hoy `informe_o45.php:53`.
- Reusar `lib_disk_cache.php` (`diskCache*`), `buildO45Dataset` (`lib_o45_dataset.php`), `preciosPorRefs` (`lib_precios.php`), `buildRefsFromMat` (`lib_refs.php`), carpeta `cache/`.
- **Rama:** `feature/o45-cache-disco`. Spec: `docs/superpowers/specs/2026-07-10-o45-cache-disco-design.md`.

---

## File Structure

- **Crear** `api/lib_o45_disk.php` — cacheKey + stamp de fuente + builder + wrappers de disco `'o45'`.
- **Modificar** `api/informe_o45.php` — `require_once` + corto-circuito de disco en `tab=dataset` + bypass `?nocache=1`.
- **Crear** `tests/verificar_o45_disco.php` — smoke del builder/frescura (`--paridad`) + e2e disco-vs-vivo (`--e2e`).

---

## Task 1: `lib_o45_disk.php` — builder + stamp de fuente + wrappers

**Files:**
- Create: `api/lib_o45_disk.php`
- Test: `tests/verificar_o45_disco.php`

**Interfaces:**
- Consumes: `lib_disk_cache.php` (`diskCache*`), `lib_o45_dataset.php` (`buildO45Dataset`), `lib_precios.php` (`preciosPorRefs`), `lib_refs.php` (`buildRefsFromMat`).
- Produces:
  - `o45CacheKey($proveedor,$desde,$hasta): string`
  - `o45CurrentStamp($conn): ?string` — stamp compuesto de fuente (global).
  - `o45DiskFresh($conn,$key): bool`, `o45ReadPayload($key):?string`, `o45WritePayload($key,$json,$stamp):bool`, `o45Cleanup():void`, `o45ServeGz($gz):void`.
  - `o45BuildPayload($conn,$proveedor,$desde,$hasta): array` — payload `tab=dataset` (mismo shape que `informe_o45.php` emite hoy). Asume `#refs` ya construido.

- [ ] **Step 1: Write the failing test** — crear `tests/verificar_o45_disco.php`:

```php
<?php
/**
 * o45 cache en disco. php tests/verificar_o45_disco.php --paridad   (requiere DB)
 * Verifica builder bien formado + consistencia + frescura por stamp de fuente.
 * La paridad byte-a-byte disco-vs-vivo la cubre --e2e (Task 2).
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../api/lib_disk_cache.php';
require __DIR__ . '/../api/lib_o45_disk.php';
if (($argv[1] ?? '') === '--e2e') { require __DIR__ . '/_o45_e2e.php'; exit(o45RunE2E()); }
if (($argv[1] ?? '') !== '--paridad') { echo "usar --paridad o --e2e (requieren DB)\n"; exit(0); }
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_precios.php';
require __DIR__ . '/../api/lib_o45_dataset.php';
if ($dbConnect===false){ echo "SKIP sin DB\n"; exit(0); }
$conn=$dbConnect; $fail=0; function ck($c,$m){ global $fail; echo ($c?"OK  ":"FAIL")."  $m\n"; if(!$c)$fail++; }
$prov='BELTRANY SAS'; $desde='2025-01-01'; $hasta=date('Y-m-d', strtotime('-1 day'));
buildRefsFromMat($conn,$prov);
$key=o45CacheKey($prov,$desde,$hasta);
$p=o45BuildPayload($conn,$prov,$desde,$hasta);
ck(($p['ok']??false)===true && $p['tab']==='dataset' && is_array($p['filas']) && is_array($p['columnas']) && isset($p['precios']) && isset($p['rango']), 'payload bien formado');
ck(count($p['filas'])>0, 'dataset trae filas ('.count($p['filas']).')');
ck($p['proveedor']===$prov, 'envelope usa proveedorSesion');
// frescura por stamp de fuente
$stamp=o45CurrentStamp($conn);
ck($stamp!==null && $stamp!=='', "o45CurrentStamp no-vacio ($stamp)");
o45WritePayload($key, json_encode($p,JSON_UNESCAPED_UNICODE), $stamp);
ck(o45DiskFresh($conn,$key)===true, 'o45DiskFresh true tras escribir con stamp vigente');
file_put_contents(diskCacheStampPath('o45',$key),'STALE-STAMP');
ck(o45DiskFresh($conn,$key)===false, 'o45DiskFresh false con stamp desfasado');
@unlink(diskCachePath('o45',$key)); @unlink(diskCacheStampPath('o45',$key));
echo $fail?"\n$fail FALLO(S)\n":"\nO45 PAYLOAD OK\n"; exit($fail?1:0);
```

Run: `php tests/verificar_o45_disco.php --paridad` → FAIL (`Call to undefined function o45CacheKey()`).

- [ ] **Step 2: Implementar `api/lib_o45_disk.php`**

`o45BuildPayload` **copia verbatim** el armado de `informe_o45.php:44-54` (el `$columnas`/`$filas`/envelope), cambiando solo: recibe params, devuelve el array en vez de `echo`, y en error de `buildO45Dataset` devuelve `['ok'=>false,...]` (para el ok-gate). NO re-derivar el orden de columnas.

```php
<?php
/** o45 cache en disco: builder del payload tab=dataset + frescura por stamp de fuente. */
require_once __DIR__ . '/lib_disk_cache.php';
require_once __DIR__ . '/lib_o45_dataset.php';
require_once __DIR__ . '/lib_precios.php';

if (!defined('O45_DISK_TTL_MIN')) define('O45_DISK_TTL_MIN', 1500); // ~25h: el archivo del dia sobrevive al proximo ETL

if (!function_exists('o45CacheKey')) {
    function o45CacheKey($proveedor, $desde, $hasta): string { return substr(md5($proveedor.'|'.$desde.'|'.$hasta),0,32); }

    // Stamp GLOBAL de fuente: avanza cuando el ETL nocturno carga inv_actual/Ventas_Detal.
    // ISNULL para que nunca sea NULL por una fuente vacia (si la query falla -> null -> rebuild).
    function o45CurrentStamp($conn): ?string {
        $sql = "SELECT ISNULL(CONVERT(varchar(19),(SELECT MAX(FECHA) FROM INTEGRACION.dbo.inv_actual_PBI  WITH (NOLOCK)),120),'') + '|'
                     + ISNULL(CONVERT(varchar(19),(SELECT MAX(FECHA) FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK)),120),'') s";
        $st = sqlsrv_query($conn, $sql);
        if ($st === false) return null;
        $r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($st);
        return $r ? (string)$r['s'] : null;
    }

    function o45DiskFresh($conn, string $key): bool { return diskCacheFresh('o45', $key, o45CurrentStamp($conn)); }
    function o45ReadPayload(string $key): ?string { return diskCacheRead('o45', $key); }
    function o45WritePayload(string $key, string $json, string $stamp): bool { return diskCacheWrite('o45', $key, $json, $stamp); }
    function o45Cleanup(): void { diskCacheCleanup('o45', O45_DISK_TTL_MIN); }
    function o45ServeGz(string $gz): void { diskCacheServeGz($gz); }

    function o45BuildPayload($conn, string $proveedor, string $desde, string $hasta): array {
        // asume #refs ya construido (el endpoint lo hace en linea 41; el prebuild debe hacerlo antes).
        $ds = buildO45Dataset($conn, $desde, $hasta);
        if (!empty($ds['error'])) return ['ok'=>false, 'error'=>'Consulta fallida', 'detalle'=>$ds['error']];
        $precios = preciosPorRefs($conn);
        // VERBATIM de informe_o45.php:49-52:
        $columnas = ['cia','bodega','grupo','tienda','es_cedi','referencia','color','talla',
                     'marca','tipo','categoria','subcategoria','genero','publico',
                     'disponible','hold','ventas','ventas30','inv_hist'];
        $filas = array_map(fn($r) => array_map(fn($c) => $r[$c], $columnas), $ds['rows']);
        return ['ok'=>true, 'tab'=>'dataset', 'proveedor'=>$proveedor,
                'columnas'=>$columnas, 'filas'=>$filas, 'precios'=>$precios, 'rango'=>$ds['meta']];
    }
}
```

- [ ] **Step 3: Run test → PASS**

Run: `php -l api/lib_o45_disk.php` → sin errores.
Run: `php tests/verificar_o45_disco.php --paridad` → `O45 PAYLOAD OK` (payload bien formado, filas>0, envelope proveedorSesion, frescura true/false). Lento (RDS + build ~40-112s), timeout ≥180000, no matar. Ignorar warnings de arranque.

- [ ] **Step 4: Commit**

```bash
git add api/lib_o45_disk.php tests/verificar_o45_disco.php
git commit -m "feat(o45): o45BuildPayload + stamp de fuente (MAX FECHA inv_actual+ventas) sobre lib_disk_cache"
```

---

## Task 2: Integración en `informe_o45.php` (tab=dataset)

**Files:**
- Modify: `api/informe_o45.php`
- Test: `tests/verificar_o45_disco.php` (modo `--e2e`) + `tests/_o45_e2e.php`

**Interfaces:**
- Consumes: `o45DiskFresh`, `o45ReadPayload`, `o45WritePayload`, `o45CurrentStamp`, `o45BuildPayload`, `o45ServeGz`, `o45Cleanup`, `o45CacheKey`, `diskCachePath`.

- [ ] **Step 1: `require_once` + bypass nocache + corto-circuito**

En `api/informe_o45.php`, tras `require __DIR__ . '/lib_o45_dataset.php';` (línea 33) añadir `require_once __DIR__ . '/lib_o45_disk.php';`. Añadir cerca del setup: `$nocache = !empty($_GET['nocache']);`.

Reemplazar el inicio del bloque `if ($tab === 'dataset') {` (línea 44). El corto-circuito va PRIMERO; el código actual (`$ds = buildO45Dataset...` líneas 45-55) queda como fall-through:

```php
if ($tab === 'dataset') {
    $o45key = o45CacheKey($proveedor, $desde, $hasta);
    if (!$nocache) {
        if (o45DiskFresh($dbConnect, $o45key)) {
            $gz = o45ReadPayload($o45key);
            if ($gz !== null) { sqlsrv_close($dbConnect); o45ServeGz($gz); exit; }
        }
        $lk = @fopen(diskCachePath('o45',$o45key).'.lock', 'c');
        if ($lk && flock($lk, LOCK_EX)) {
            if (o45DiskFresh($dbConnect, $o45key)) { $gz=o45ReadPayload($o45key);
                if ($gz!==null){ flock($lk,LOCK_UN); fclose($lk); sqlsrv_close($dbConnect); o45ServeGz($gz); exit; } }
            $stamp = o45CurrentStamp($dbConnect);
            $payload = o45BuildPayload($dbConnect, $proveedorSesion, $desde, $hasta);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $okP = (($payload['ok'] ?? false) === true);
            if ($okP && $stamp !== null) o45WritePayload($o45key, $json, $stamp);   // NO cachear errores
            flock($lk,LOCK_UN); fclose($lk); sqlsrv_close($dbConnect); o45Cleanup();
            if ($okP) { o45ServeGz(gzencode($json,6)); }
            else { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); echo $json; }
            exit;
        }
        if ($lk) fclose($lk);
        // lock-fail -> cae al camino actual de abajo (intacto)
    }
    // ---- camino actual (nocache=1 o lock-fail): buildO45Dataset directo (INTACTO) ----
    $ds = buildO45Dataset($dbConnect, $desde, $hasta);
    if ($ds['error']) jsonFail(['error'=>$ds['error']], $dbConnect);
    $precios = preciosPorRefs($dbConnect);
    sqlsrv_close($dbConnect);
    $columnas = ['cia','bodega','grupo','tienda','es_cedi','referencia','color','talla',
                 'marca','tipo','categoria','subcategoria','genero','publico',
                 'disponible','hold','ventas','ventas30','inv_hist'];
    $filas = array_map(fn($r) => array_map(fn($c) => $r[$c], $columnas), $ds['rows']);
    echo json_encode(['ok'=>true,'tab'=>'dataset','proveedor'=>$proveedorSesion,
        'columnas'=>$columnas,'filas'=>$filas,'precios'=>$precios,'rango'=>$ds['meta']], JSON_UNESCAPED_UNICODE);
    exit;
}
```

(El bloque "camino actual" es el código EXISTENTE de las líneas 45-55 sin cambios — solo queda debajo del corto-circuito. `o45Cleanup()` se llama una vez en el miss.)

- [ ] **Step 2: Crear el helper e2e `tests/_o45_e2e.php`**

```php
<?php
/** e2e o45: disco (?tab=dataset) vs vivo (?tab=dataset&nocache=1) via el runner real. */
function o45CallEndpoint(string $prov, string $qs): ?array {
    $runner = __DIR__ . '/_endpoint_run_o45.php'; $php = PHP_BINARY;
    $nul = (stripos(PHP_OS,'WIN')===0)?'NUL':'/dev/null';
    $cmd = escapeshellarg($php).' -d display_errors=0 '.escapeshellarg($runner).' '.escapeshellarg($prov).' '.escapeshellarg($qs).' 2>'.$nul;
    $raw=(string)shell_exec($cmd); $a=strpos($raw,'{'); $b=strrpos($raw,'}');
    $j=($a!==false&&$b!==false)?substr($raw,$a,$b-$a+1):$raw; $d=json_decode($j,true);
    return is_array($d)?$d:null;
}
// normaliza: ordena 'filas' (arrays) por su JSON para comparar sin depender del orden fisico.
function o45Norm($p){ $f=$p['filas']??[]; usort($f, fn($x,$y)=>strcmp(json_encode($x),json_encode($y)));
    return ['columnas'=>$p['columnas']??[], 'nfilas'=>count($f), 'filas'=>$f, 'rango'=>$p['rango']??[]]; }

function o45RunE2E(): int {
    require __DIR__ . '/../conexion/conexion_integracion.php';
    require __DIR__ . '/../api/lib_refs.php'; require __DIR__ . '/../api/lib_o45_disk.php';
    if ($GLOBALS['dbConnect']===false){ echo "SKIP sin DB\n"; return 0; }
    $conn=$GLOBALS['dbConnect']; $fail=0; function e($c,$m){ global $fail; echo ($c?"OK  ":"FAIL")."  $m\n"; if(!$c)$fail++; }
    $prov='BELTRANY SAS'; $desde='2025-01-01'; $hasta=date('Y-m-d', strtotime('-1 day'));
    $key=o45CacheKey($prov,$desde,$hasta);
    @unlink(diskCachePath('o45',$key)); @unlink(diskCacheStampPath('o45',$key));
    $qs="tab=dataset&desde=$desde&hasta=$hasta";
    $rD=o45CallEndpoint($prov,$qs);                 // disco (miss->build->write->serve)
    e(is_array($rD)&&($rD['ok']??false)===true, 'disco tab=dataset ok:true');
    e(is_file(diskCachePath('o45',$key)), 'archivo o45_<key>.json.gz escrito (corto-circuito corrio)');
    $rV=o45CallEndpoint($prov,"$qs&nocache=1");      // vivo (oraculo)
    e(is_array($rV)&&($rV['ok']??false)===true, 'vivo tab=dataset&nocache=1 ok:true');
    if (is_array($rD)&&is_array($rV)) {
        $a=o45Norm($rD); $b=o45Norm($rV);
        if (json_encode($a)===json_encode($b)) e(true, 'paridad disco vs vivo: IDENTICO');
        else { // tolerar staleness del corte actual: comparar sin las columnas de stock/hold
            e($a['nfilas']===$b['nfilas'] && $a['columnas']===$b['columnas'], 'paridad: mismas filas/columnas (diffs solo en stock/hold del corte actual = staleness tolerado)');
            echo "  [INFO] nfilas D=".$a['nfilas']." V=".$b['nfilas']."\n";
        }
    }
    @unlink(diskCachePath('o45',$key)); @unlink(diskCacheStampPath('o45',$key));
    echo $fail?"\n$fail FALLO(S)\n":"\nO45 E2E OK\n"; return $fail?1:0;
}
```

> Nota: `_endpoint_run_o45.php` ya existe y drivea el endpoint real con sesión simulada. El runner CLI no manda `Accept-Encoding: gzip`, así que `o45ServeGz` sirve JSON plano (gzdecode) → `json_decode` funciona.

- [ ] **Step 3: Verificar**

Run: `php -l api/informe_o45.php` y `php -l tests/_o45_e2e.php` → sin errores.
Run: `php tests/verificar_o45_disco.php --e2e` → `O45 E2E OK` (disco ok, archivo escrito, vivo ok, paridad disco==vivo o solo-staleness). Lento (2× build ~112s BRAHMA; usar BELTRANY que es chico), timeout ≥360000, no matar.

- [ ] **Step 4: Commit**

```bash
git add api/informe_o45.php tests/verificar_o45_disco.php tests/_o45_e2e.php
git commit -m "feat(o45): tab=dataset sirve desde cache en disco (hit/miss/flock/gzip + ok-gate + bypass nocache)"
```

---

## Task 3: Revisión final + deploy

- [ ] **Step 1: `php -l`** en `api/{lib_o45_disk,informe_o45}.php` + `tests/_o45_e2e.php` → limpio.
- [ ] **Step 2: Suites** — `verificar_o45_disco.php --paridad` (`O45 PAYLOAD OK`) + `--e2e` (`O45 E2E OK`). Verde. (Recordatorio: NO correr en paralelo con otra suite que toque las mismas keys.)
- [ ] **Step 3: `requesting-code-review`** de la rama (opus): paridad disco↔vivo, el stamp de fuente (¿avanza con el ETL? ¿NULL-safe?), ok-gate, degradación, concurrencia, y que los tabs data/filtros no cambiaron.
- [ ] **Step 4: Checklist de deploy** (Rafael): sin DDL. Re-sync a `plataforma_20_produccion` + WMS-LAB: `api/lib_o45_disk.php` (nuevo), `api/informe_o45.php` (mod). (`lib_disk_cache.php` ya está en prod desde evol.) `cache/` ya escribible. E2E navegador: Índice de Ventas carga rápido (tras 1ª construcción) + filtrado instantáneo sigue.

---

## Self-Review (hecho)

- **Cobertura del spec:** §4.1 stamp de fuente → Task 1 (`o45CurrentStamp`); §4.2 lib → Task 1; §4.3 endpoint → Task 2; §6 pruebas → Tasks 1/2 + Task 3; §7 deploy → Task 3 Step 4.
- **Placeholders:** ninguno — el `$columnas`/`$filas` es copia verbatim explícita de `informe_o45.php:49-52` (documentado; re-derivar rompería paridad). El `--e2e` reusa `_endpoint_run_o45.php` existente.
- **Consistencia de firmas:** `o45CacheKey/CurrentStamp/DiskFresh/ReadPayload/WritePayload/Cleanup/ServeGz/BuildPayload` usadas igual en Tasks 1-2; envelope con `$proveedorSesion`; ok-gate idéntico al de evol.
- **Reuso:** `diskCache*` del lib compartido (ya en prod); ok-gate + patrón flock heredados de evol.
