# O14 tab=c — Cache en disco del árbol + prebuild nocturno — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que "Por tienda" (tab=c) sin filtro cargue instantáneo sirviendo el árbol determinista desde un cache en disco local (gzip), evitando transferir 46k filas / 5.5MB desde la RDS remota en cada request.

**Architecture:** El árbol tab=c sin filtro es determinista por `cache_key`. Se construye una vez (camino de filas actual, ~26s), se guarda gzip en `cache/o14c_<key>.json.gz` + un `.stamp` con el `creado` del `o14_cache_base`, y se sirve desde disco (ms) mientras el `.stamp` coincida con el `creado` vigente. Un prebuild nocturno lo deja listo por proveedor. El caso filtrado y los tabs b/reco quedan intactos.

**Tech Stack:** PHP 8 + sqlsrv (SQL Server / RDS us-east-1), gzip nativo de PHP, tests como scripts PHP CLI (patrón del repo: guard de DB + assert + exit code), Windows Task Scheduler para el job.

## Global Constraints

- **Paridad byte-a-byte:** el árbol servido desde disco/`o14cBuildPayloadC` debe ser idéntico al camino vivo `?nocache=1` de tab=c sin filtro, para el mismo `cache_key`. (Criterio bloqueante del spec §2.)
- **No tocar tablas SIESA** (constraint del proyecto). Solo se lee `o14_cache_base` (INTEGRACION) y se escribe en disco.
- **Solo el caso lento:** el cache en disco aplica únicamente a `tab==='c' && $cacheMode && sin filtros REF/SKU/BOD`. Filtrado y tabs b/reco: camino actual intacto.
- **Degradar sin romper:** si `cache/` no es escribible o el gzip falla, el endpoint sirve por el camino de filas (lento pero correcto); NUNCA responder 500 por un fallo de cache.
- **Escritura atómica:** todo write a `cache/` va a `<archivo>.tmp.<pid>` + `rename()`.
- **Reusar patrones existentes:** `o14CacheKey`/`ensureO14CacheBase`/`o14CacheFresco`/`O14_CACHE_TTL_MIN` de `api/lib_o14_cache.php`; `buildRefsFromMat` de `api/lib_refs.php`; `login_resolver_proveedor` de `api/lib_login.php`; carpeta `cache/` (ya en `.gitignore`, `cache/*` + `.gitkeep`).
- **Rama:** `feature/o14c-arbol-cache-disco`. Spec: `docs/superpowers/specs/2026-07-09-o14c-arbol-cache-disco-design.md`.

---

## File Structure

- **Crear** `api/lib_o14c_payload.php` — cache en disco + constructor del payload tab=c sin filtro + `ensamblarArbol` (movida acá como fuente única). Responsabilidad: TODA la lógica del árbol sin filtro y su persistencia en disco.
- **Modificar** `api/informe_o14.php` — `require_once` del nuevo lib; borrar la definición inline de `ensamblarArbol`; en el bloque `tab==='c'` añadir el corto-circuito de cache en disco para el caso sin filtro (hit/miss/flock/serveGz). El resto (filtrado, b, reco) intacto.
- **Crear** `sql/prebuild_o14c.php` — CLI: enumera proveedores y deja el `.json.gz` + `.stamp` por proveedor. (Junto al `.bat`, mismo patrón que `sql/refrescar_items_mat.php`.)
- **Crear** `sql/prebuild_o14c.bat` — wrapper para Task Scheduler (patrón `sql/refrescar_items_mat.bat`).
- **Crear** `tests/verificar_o14c_payload.php` — tests: primitivas de disco (roundtrip gzip, atomicidad, cleanup) + paridad `o14cBuildPayloadC` vs vivo + frescura por `.stamp` + smoke prebuild.

---

## Task 1: Primitivas de cache en disco (`lib_o14c_payload.php`)

**Files:**
- Create: `api/lib_o14c_payload.php`
- Test: `tests/verificar_o14c_payload.php`

**Interfaces:**
- Produces:
  - `o14cCacheDir(): string` — ruta absoluta a `cache/` (=`__DIR__.'/../cache'`).
  - `o14cPayloadPath(string $key): string` — `<cache>/o14c_<key>.json.gz`.
  - `o14cStampPath(string $key): string` — `<cache>/o14c_<key>.stamp`.
  - `o14cWritePayload(string $key, string $jsonPlano, string $stamp): bool` — gzip + escritura atómica del `.json.gz` y del `.stamp`. `false` si no puede escribir (degradación).
  - `o14cReadPayload(string $key): ?string` — bytes gzip del `.json.gz`, o `null` si no existe/ilegible.
  - `o14cCleanup(): void` — borra `o14c_*.json.gz` y `.stamp` con `mtime` más viejo que `O14_CACHE_TTL_MIN`.

- [ ] **Step 1: Write the failing test** (primitivas puras, sin DB)

Añade a `tests/verificar_o14c_payload.php` (crear el archivo con este contenido):

```php
<?php
/**
 * Tests del cache en disco de O14 tab=c. Modo por defecto: primitivas puras (sin DB).
 *   php tests/verificar_o14c_payload.php            # primitivas (Task 1)
 *   php tests/verificar_o14c_payload.php --paridad  # paridad + frescura (Task 2/3, requiere DB)
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../api/lib_o14_cache.php';       // O14_CACHE_TTL_MIN
require __DIR__ . '/../api/lib_o14c_payload.php';    // SUT

$fail = 0;
function check($cond, $msg) { global $fail; echo ($cond ? "OK  " : "FAIL") . "  $msg\n"; if (!$cond) $fail++; }

if (($argv[1] ?? '') === '--paridad') { require __DIR__ . '/_o14c_paridad.php'; exit(o14cRunParidad()); }

// --- roundtrip gzip + atomicidad ---
$key = 'testkey' . getmypid();
$json = json_encode(['ok' => true, 'grupos' => [['grupo' => 'X', 'almacenes' => []]], 'tallas' => ['34','36']]);
check(o14cWritePayload($key, $json, '2026-07-09T03:00:00'), 'write devuelve true');
check(is_file(o14cPayloadPath($key)), 'existe .json.gz');
check(is_file(o14cStampPath($key)), 'existe .stamp');
check(file_get_contents(o14cStampPath($key)) === '2026-07-09T03:00:00', 'stamp persistido');
$gz = o14cReadPayload($key);
check($gz !== null, 'read devuelve bytes');
check(gzdecode($gz) === $json, 'gzdecode == json original');
check(!is_file(o14cPayloadPath($key) . '.tmp.' . getmypid()), 'no queda .tmp');
// lectura de key inexistente
check(o14cReadPayload('noexiste' . getmypid()) === null, 'read de key inexistente = null');
// limpieza
@unlink(o14cPayloadPath($key)); @unlink(o14cStampPath($key));

echo $fail ? "\n$fail FALLO(S)\n" : "\nTODO OK\n";
exit($fail ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/verificar_o14c_payload.php`
Expected: FAIL — `Fatal error: Uncaught Error: Call to undefined function o14cWritePayload()` (el lib aún no existe).

- [ ] **Step 3: Write minimal implementation**

Crea `api/lib_o14c_payload.php`:

```php
<?php
/**
 * Cache en disco del árbol O14 tab=c SIN filtro (payload determinista por cache_key).
 * Evita transferir 46k filas / ~5.5MB desde la RDS remota en cada request: se construye
 * una vez y se sirve gzip desde disco local. Ver spec 2026-07-09-o14c-arbol-cache-disco.
 * Frescura por .stamp (valor `creado` de o14_cache_base): compara timestamp-de-DB contra
 * timestamp-de-DB, sin cruzar el reloj del server PHP (Colombia) con el de la RDS (UTC).
 */
require_once __DIR__ . '/lib_o14_cache.php'; // O14_CACHE_TTL_MIN, o14CacheKey/Fresco/ensure

if (!function_exists('o14cCacheDir')) {
    function o14cCacheDir(): string { return __DIR__ . '/../cache'; }
    function o14cPayloadPath(string $key): string { return o14cCacheDir() . '/o14c_' . $key . '.json.gz'; }
    function o14cStampPath(string $key): string { return o14cCacheDir() . '/o14c_' . $key . '.stamp'; }

    function o14cWritePayload(string $key, string $jsonPlano, string $stamp): bool {
        $dir = o14cCacheDir();
        if (!is_dir($dir) || !is_writable($dir)) return false;       // degradar sin romper
        $gz = gzencode($jsonPlano, 6);
        if ($gz === false) return false;
        // escritura atómica: tmp + rename (un lector nunca ve archivo a medias)
        $tmp = o14cPayloadPath($key) . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $gz) === false) return false;
        if (!@rename($tmp, o14cPayloadPath($key))) { @unlink($tmp); return false; }
        $tmpS = o14cStampPath($key) . '.tmp.' . getmypid();
        if (file_put_contents($tmpS, $stamp) === false) return false;
        if (!@rename($tmpS, o14cStampPath($key))) { @unlink($tmpS); return false; }
        return true;
    }

    function o14cReadPayload(string $key): ?string {
        $p = o14cPayloadPath($key);
        if (!is_file($p)) return null;
        $b = @file_get_contents($p);
        return $b === false ? null : $b;
    }

    function o14cCleanup(): void {
        $dir = o14cCacheDir();
        if (!is_dir($dir)) return;
        $limite = time() - O14_CACHE_TTL_MIN * 60;
        foreach (glob($dir . '/o14c_*.json.gz') ?: [] as $f) {
            if (@filemtime($f) < $limite) { @unlink($f); @unlink(substr($f, 0, -8) . '.stamp'); }
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/verificar_o14c_payload.php`
Expected: PASS — `TODO OK`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add api/lib_o14c_payload.php tests/verificar_o14c_payload.php
git commit -m "feat(o14c): primitivas de cache en disco (gzip atomico + stamp + cleanup)"
```

---

## Task 2: Constructor del payload + frescura (`o14cBuildPayloadC`, `o14cCurrentStamp`, `o14cDiskFresh`)

**Files:**
- Modify: `api/lib_o14c_payload.php`
- Modify: `api/informe_o14.php:67-91` (mover `ensamblarArbol` al lib)
- Create: `tests/_o14c_paridad.php` (helper de paridad, invocado por `--paridad`)

**Interfaces:**
- Consumes: `o14CacheKey`, `ensureO14CacheBase`, `buildRefsFromMat`.
- Produces:
  - `ensamblarArbol(array $rows): array` — `[grupos, tallas, kpi]` (MOVIDA desde `informe_o14.php`, guardada con `function_exists`; fuente única).
  - `o14cCurrentStamp($conn, string $key): ?string` — `creado` ISO-8601 de la fila de `o14_cache_base` para `$key` (READPAST), o `null` si no hay fila.
  - `o14cDiskFresh($conn, string $key): bool` — true sí y solo sí existen `.json.gz` y `.stamp`, y `contenido(.stamp) === o14cCurrentStamp($conn,$key)` (no-null).
  - `o14cBuildPayloadC($conn, string $key, string $desde, string $hasta): array` — construye el array de respuesta EXACTO de tab=c sin filtro (mismo shape que emite `informe_o14.php` hoy): `['ok'=>true,'tab'=>'c','rango'=>[...],'tallas'=>[...],'medidas'=>[...],'grupos'=>[...],'kpis'=>[...]]`. Asume `#refs` NO necesario (lee de `o14_cache_base` por `cache_key`).

- [ ] **Step 1: Mover `ensamblarArbol` al lib**

En `api/informe_o14.php`, **borra** la función `ensamblarArbol` (líneas ~65-91, el bloque `/** Arma la jerarquía ... */ function ensamblarArbol($rows) { ... }`). Añade, justo después de `require __DIR__ . '/lib_refs.php';` (línea ~40):

```php
require_once __DIR__ . '/lib_o14c_payload.php'; // ensamblarArbol + cache en disco tab=c
```

En `api/lib_o14c_payload.php`, dentro del bloque `if (!function_exists('o14cCacheDir')) { ... }` (o en un `if (!function_exists('ensamblarArbol'))` propio), pega `ensamblarArbol` **verbatim** de como estaba en el endpoint:

```php
if (!function_exists('ensamblarArbol')) {
    /** Arma grupos→almacenes→negocios desde filas planas de o14_cache_base/#base.
     *  KPIs de cantidad se acumulan sobre TODOS los grupos (incluido CEDI). */
    function ensamblarArbol($rows) {
        $tallasSet=[]; $arbol=[];
        $kpi=['siembra'=>0,'disponible'=>0,'hold'=>0,'ventas'=>0,'sobrantes'=>0,'faltante'=>0];
        foreach ($rows as $r) {
            $g=$r['grupo']; $ll=$r['llave']; $neg=$r['negocio']; $talla=(string)$r['talla']; $tallasSet[$talla]=true;
            $si=(int)$r['siembra']; $di=(int)$r['disponible']; $ho=(int)$r['hold']; $ve=(int)$r['ventas'];
            $bal=$si-($di+$ho); $fal=max(0,$bal); $sob=max(0,-$bal);
            if(!isset($arbol[$g])) $arbol[$g]=['grupo'=>$g,'almacenes'=>[]];
            if(!isset($arbol[$g]['almacenes'][$ll])) $arbol[$g]['almacenes'][$ll]=['llave'=>$ll,'bodega'=>$r['bodega'],'nombre'=>$r['nombre'],'negocios'=>[]];
            if(!isset($arbol[$g]['almacenes'][$ll]['negocios'][$neg])) $arbol[$g]['almacenes'][$ll]['negocios'][$neg]=['negocio'=>$neg,'referencia'=>$r['referencia'],'color'=>$r['color'],'valores'=>[]];
            $vals=&$arbol[$g]['almacenes'][$ll]['negocios'][$neg]['valores'];
            foreach(['siembra'=>$si,'disponible'=>$di,'hold'=>$ho,'disphold'=>$di+$ho,'sobrante'=>$sob,'faltante'=>$fal,'ventas'=>$ve] as $m=>$v)
                $vals[$m][$talla]=($vals[$m][$talla]??0)+$v;
            unset($vals);
            $kpi['siembra']+=$si; $kpi['disponible']+=$di; $kpi['hold']+=$ho; $kpi['ventas']+=$ve; $kpi['sobrantes']+=$sob; $kpi['faltante']+=$fal;
        }
        $grupos=[];
        foreach($arbol as $g){
            $g['almacenes']=array_values(array_map(function($a){ $a['negocios']=array_values($a['negocios']); return $a; }, $g['almacenes']));
            $grupos[]=$g;
        }
        $tallas=array_keys($tallasSet);
        usort($tallas, fn($a,$b)=>(is_numeric($a)&&is_numeric($b))?($a<=>$b):strcmp($a,$b));
        return [$grupos, $tallas, $kpi];
    }
}
```

- [ ] **Step 2: Write the failing test** (paridad — crea `tests/_o14c_paridad.php`)

```php
<?php
/**
 * Paridad: o14cBuildPayloadC (lo que se cachea en disco) == camino vivo tab=c sin filtro.
 * Compara el JSON normalizado (grupos/tallas/kpis) para varios proveedores. Requiere DB.
 */
function o14cNorm($p) {
    // normaliza: ordena grupos/almacenes/negocios por clave estable para comparar sin depender del orden
    usort($p['grupos'], fn($a,$b)=>strcmp($a['grupo'],$b['grupo']));
    foreach ($p['grupos'] as &$g) {
        usort($g['almacenes'], fn($a,$b)=>strcmp($a['llave'],$b['llave']));
        foreach ($g['almacenes'] as &$a) usort($a['negocios'], fn($x,$y)=>strcmp($x['negocio'],$y['negocio']));
    }
    return json_encode(['tallas'=>$p['tallas'],'kpis'=>$p['kpis'],'grupos'=>$p['grupos']]);
}

function o14cVivoTabC($conn, $desde, $hasta) {
    // camino vivo: replica la query nocache de informe_o14.php tab=c + ensamblarArbol + counts
    // (se construye #base vía el endpoint real seria ideal; aquí usamos el cache recién hecho como
    //  oráculo NO sirve — para vivo real, se compara contra el endpoint por HTTP en Task 3).
    // Este helper valida solo la CONSISTENCIA interna de o14cBuildPayloadC contra ensureO14Cache.
    return null;
}

function o14cRunParidad(): int {
    require __DIR__ . '/../conexion/conexion_integracion.php';
    require __DIR__ . '/../api/lib_refs.php';
    if ($GLOBALS['dbConnect'] === false) { echo "SKIP: sin DB\n"; return 0; }
    $conn = $GLOBALS['dbConnect'];
    $desde='2025-01-01'; $hasta=date('Y-m-d'); $fail=0;
    foreach (['BELTRANY SAS','BRAHMA CONCEPT'] as $prov) {
        buildRefsFromMat($conn, $prov);
        $key = o14CacheKey($prov, $desde, $hasta);
        ensureO14CacheBase($conn, $key, $desde, $hasta);
        $p = o14cBuildPayloadC($conn, $key, $desde, $hasta);
        $ok = isset($p['ok']) && $p['ok'] && $p['tab']==='c' && is_array($p['grupos']);
        echo ($ok?"OK  ":"FAIL")."  $prov: grupos=".count($p['grupos'])." tallas=".count($p['tallas'])." kpis.siembra=".($p['kpis']['siembra']??'?')."\n";
        if (!$ok) $fail++;
        // consistencia: la suma de siembra del arbol == kpis.siembra
        $suma=0; foreach($p['grupos'] as $g) foreach($g['almacenes'] as $a) foreach($a['negocios'] as $n)
            foreach(($n['valores']['siembra']??[]) as $v) $suma+=$v;
        echo ($suma==($p['kpis']['siembra']??-1)?"OK  ":"FAIL")."  $prov: suma arbol siembra ($suma) == kpis.siembra\n";
        if ($suma!=($p['kpis']['siembra']??-1)) $fail++;
    }
    echo $fail?"\n$fail FALLO(S)\n":"\nPARIDAD OK\n";
    return $fail?1:0;
}
```

Run: `php tests/verificar_o14c_payload.php --paridad`
Expected: FAIL — `Call to undefined function o14cBuildPayloadC()`.

- [ ] **Step 3: Implementar `o14cBuildPayloadC` + frescura**

Añade a `api/lib_o14c_payload.php` (dentro del bloque de funciones):

```php
    function o14cCurrentStamp($conn, string $key): ?string {
        $st = sqlsrv_query($conn,
            "SELECT TOP 1 CONVERT(varchar(30), creado, 126) s FROM INTEGRACION.dbo.o14_cache_base WITH (READPAST) WHERE cache_key=?",
            [$key]);
        if ($st === false) return null;
        $r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($st);
        return $r ? $r['s'] : null;
    }

    function o14cDiskFresh($conn, string $key): bool {
        if (!is_file(o14cPayloadPath($key)) || !is_file(o14cStampPath($key))) return false;
        $stampDisco = @file_get_contents(o14cStampPath($key));
        if ($stampDisco === false) return false;
        $stampDb = o14cCurrentStamp($conn, $key);
        return $stampDb !== null && $stampDisco === $stampDb;
    }

    function o14cBuildPayloadC($conn, string $key, string $desde, string $hasta): array {
        // Query verbatim de informe_o14.php tab=c cacheMode SIN filtro (whereFiltros='', params=[key]).
        $sql = "
            SELECT ISNULL(c.grupo,'SIN GRUPO') grupo, (c.cia + '-' + c.bodega) llave, c.cia, c.bodega,
              ISNULL(c.nombre, c.bodega) nombre, c.negocio, c.referencia, c.color, c.talla,
              SUM(c.siembra) siembra, SUM(c.disponible) disponible, SUM(c.hold) hold, SUM(c.ventas) ventas
            FROM INTEGRACION.dbo.o14_cache_base c
            WHERE c.cache_key=?
            GROUP BY ISNULL(c.grupo,'SIN GRUPO'), c.cia, c.bodega, ISNULL(c.nombre,c.bodega), c.negocio, c.referencia, c.color, c.talla
            ORDER BY ISNULL(c.grupo,'SIN GRUPO'), (c.cia+'-'+c.bodega), c.negocio";
        $st = sqlsrv_query($conn, $sql, [$key]);
        $rows = [];
        if ($st !== false) { while ($x = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $rows[] = $x; sqlsrv_free_stmt($st); }

        [$grupos, $tallas, $kpi] = ensamblarArbol($rows);
        $kpi['total_stock'] = $kpi['disponible'] + $kpi['hold'];

        // Conteos (kpiCounts de informe_o14.php:95-105, unfiltered: WHERE cache_key=?).
        $cnt = sqlsrv_query($conn, "
            SELECT
              (SELECT COUNT(DISTINCT cia+'|'+negocio) FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=?)                  negocios,
              (SELECT COUNT(DISTINCT cia+'|'+negocio) FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=? AND siembra>0)     negocios_con_siembra,
              (SELECT COUNT(DISTINCT cia+'-'+bodega)  FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=? AND siembra>0)     tiendas_con_siembra,
              (SELECT COUNT(DISTINCT cia+'-'+bodega)  FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=? AND disponible>0)  tiendas_con_inv,
              (SELECT COUNT(DISTINCT cia+'-'+bodega)  FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=? AND ventas<>0)     tiendas_con_venta",
            [$key, $key, $key, $key, $key]);
        if ($cnt !== false) { $c = sqlsrv_fetch_array($cnt, SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($cnt);
            if ($c) $kpi = array_merge($kpi, array_map('intval', $c)); }

        return ['ok'=>true, 'tab'=>'c', 'rango'=>['desde'=>$desde,'hasta'=>$hasta],
                'tallas'=>$tallas, 'medidas'=>['siembra','disponible','hold','disphold','sobrante','faltante','ventas'],
                'grupos'=>$grupos, 'kpis'=>$kpi];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/verificar_o14c_payload.php --paridad`
Expected: PASS — `PARIDAD OK` (grupos/tallas poblados; suma del árbol == kpis.siembra para ambos proveedores).

Run también las primitivas (no deben romperse): `php tests/verificar_o14c_payload.php` → `TODO OK`.

- [ ] **Step 5: Verificar que el endpoint sigue sano tras mover `ensamblarArbol`**

Run: `php -l api/informe_o14.php` → `No syntax errors`.
Run: `php tests/verificar_o14_cache.php --paridad` (suite existente del sub-proyecto o14) → debe seguir en verde (usa `ensamblarArbol` ahora desde el lib).

- [ ] **Step 6: Commit**

```bash
git add api/lib_o14c_payload.php api/informe_o14.php tests/verificar_o14c_payload.php tests/_o14c_paridad.php
git commit -m "feat(o14c): o14cBuildPayloadC + frescura por stamp; ensamblarArbol movida a lib compartida"
```

---

## Task 3: Integración en el endpoint (`informe_o14.php` tab=c sin filtro)

**Files:**
- Modify: `api/informe_o14.php` (bloque `if ($tab === 'c')`, ~línea 377; y wiring de cleanup ~línea 144)
- Test: `tests/verificar_o14c_payload.php` (añadir modo `--e2e` que compara disco vs `?nocache=1` por HTTP)

**Interfaces:**
- Consumes: `o14cDiskFresh`, `o14cReadPayload`, `o14cWritePayload`, `o14cCurrentStamp`, `o14cBuildPayloadC`, `o14cCleanup`.

- [ ] **Step 1: Añadir helper `sinFiltros()` y el corto-circuito de disco**

En `api/informe_o14.php`, dentro del bloque `if ($tab === 'c') {` (línea ~377), **antes** del `if ($cacheMode) { ... } else { ... }` existente, inserta:

```php
    // Cache en disco: SOLO tab=c cache-mode SIN filtros (el árbol determinista de 46k filas).
    $sinFiltros = true;
    foreach (array_merge($FILTROS_REF, $FILTROS_SKU, $FILTROS_BOD) as $k => $col) { if (getMulti($k)) { $sinFiltros = false; break; } }
    if ($cacheMode && $sinFiltros) {
        if (o14cDiskFresh($dbConnect, $okey)) {                       // HIT
            $gz = o14cReadPayload($okey);
            if ($gz !== null) { sqlsrv_close($dbConnect); o14cServeGz($gz); exit; }
        }
        // MISS: flock + double-check para que 2 requests no paguen los 26s a la vez
        $lockPath = o14cPayloadPath($okey) . '.lock';
        $lk = @fopen($lockPath, 'c');
        if ($lk && flock($lk, LOCK_EX)) {
            if (o14cDiskFresh($dbConnect, $okey)) {                   // otro request ya construyó
                $gz = o14cReadPayload($okey);
                if ($gz !== null) { flock($lk, LOCK_UN); fclose($lk); sqlsrv_close($dbConnect); o14cServeGz($gz); exit; }
            }
            $stamp = o14cCurrentStamp($dbConnect, $okey);
            $payload = o14cBuildPayloadC($dbConnect, $okey, $desde, $hasta);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($stamp !== null) o14cWritePayload($okey, $json, $stamp);   // degrada si no puede escribir
            flock($lk, LOCK_UN); fclose($lk);
            sqlsrv_close($dbConnect);
            o14cServeGz(gzencode($json, 6));
            exit;
        }
        if ($lk) fclose($lk);
        // si no se pudo lockear, cae al camino de filas de abajo (correcto, lento)
    }
```

- [ ] **Step 2: Añadir `o14cServeGz` al lib**

En `api/lib_o14c_payload.php`:

```php
    function o14cServeGz(string $gz): void {
        header('Content-Type: application/json; charset=utf-8');
        $ae = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        if (stripos($ae, 'gzip') !== false) { header('Content-Encoding: gzip'); echo $gz; }
        else { echo gzdecode($gz); }
    }
```

- [ ] **Step 3: Cablear `o14cCleanup` junto al cleanup de DB**

En `api/informe_o14.php`, en la línea donde se llama `o14CacheCleanup($dbConnect);` (~línea 144), añade justo después:

```php
    o14cCleanup();
```

- [ ] **Step 4: Verificar sintaxis y E2E de paridad por HTTP**

Run: `php -l api/informe_o14.php` y `php -l api/lib_o14c_payload.php` → sin errores.

Añade al final de `o14cRunParidad` (o como modo `--e2e`) una comparación real contra el endpoint vivo: llama por HTTP (curl con la sesión, patrón de `perf_probe`/tests existentes) `?tab=c` (disco) vs `?tab=c&nocache=1` (vivo) para BELTRANY y BRAHMA, normaliza con `o14cNorm` y exige **0 diffs**.

Run: `php tests/verificar_o14c_payload.php --e2e`
Expected: `PARIDAD E2E OK — 0 diffs` para ambos proveedores; y la 2ª llamada (disco) responde en < 2s vs la vívida ~26s.

> **Nota de implementación (E2E):** requiere una sesión autenticada. Reusar el arnés de simulación de sesión de `tests/` (los otros verificadores ya lo hacen) o correrlo tras login manual. Si el arnés HTTP no está disponible en el entorno del agente, dejar `--e2e` documentado para que Rafael lo corra en el navegador (ver Task 6).

- [ ] **Step 5: Commit**

```bash
git add api/informe_o14.php api/lib_o14c_payload.php tests/verificar_o14c_payload.php tests/_o14c_paridad.php
git commit -m "feat(o14c): endpoint tab=c sin filtro sirve desde cache en disco (hit/miss/flock/gzip)"
```

---

## Task 4: Prebuild nocturno (`sql/prebuild_o14c.php` + `.bat`)

**Files:**
- Create: `sql/prebuild_o14c.php`
- Create: `sql/prebuild_o14c.bat`
- Test: modo `--smoke` en `tests/verificar_o14c_payload.php` o ejecución directa del CLI

**Interfaces:**
- Consumes: `login_resolver_proveedor` (`api/lib_login.php`), `buildRefsFromMat`, `o14CacheKey`, `ensureO14CacheBase`, `o14cCurrentStamp`, `o14cBuildPayloadC`, `o14cWritePayload`.

- [ ] **Step 1: Escribir `sql/prebuild_o14c.php`**

```php
<?php
/**
 * Prebuild nocturno del cache en disco de O14 tab=c (árbol sin filtro) por proveedor.
 * Deja cache/o14c_<key>.json.gz + .stamp listos para que nadie pague los ~26s en vivo.
 * Correr DESPUÉS del refresh de Items_Mat y del ETL (usa hasta=hoy).
 *   php sql/prebuild_o14c.php
 * Programar vía sql/prebuild_o14c.bat en el Programador de tareas.
 */
error_reporting(E_ERROR | E_PARSE);
$t0 = microtime(true);
require __DIR__ . '/../conexion/conexion_integracion.php';   // $dbConnect
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_login.php';
require __DIR__ . '/../api/lib_o14c_payload.php';
if ($dbConnect === false) { fwrite(STDERR, "[prebuild_o14c] Conexion DB fallida\n"); exit(1); }

$desde = '2025-01-01'; $hasta = date('Y-m-d');

// Enumerar proveedores distintos de los usuarios del portal (resueltos como en el login).
$provs = [];
$st = sqlsrv_query($dbConnect, "SELECT DISTINCT nombre_usuario FROM usuarios_portal_aka WHERE nombre_usuario IS NOT NULL");
if ($st !== false) {
    while ($u = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) {
        $r = login_resolver_proveedor($dbConnect, trim((string)$u['nombre_usuario']));
        $p = trim((string)($r['proveedor'] ?? ''));
        if ($p !== '' && $p !== '__SIN_PROVEEDOR__') $provs[$p] = true;
    }
    sqlsrv_free_stmt($st);
}
$provs = array_keys($provs);
echo "[prebuild_o14c] " . date('Y-m-d H:i:s') . " proveedores=" . count($provs) . " hasta=$hasta\n";

$okN = 0; $failN = 0;
foreach ($provs as $prov) {
    $tp = microtime(true);
    if (!buildRefsFromMat($dbConnect, $prov)) { echo "  FALLO refs: $prov\n"; $failN++; continue; }
    $key = o14CacheKey($prov, $desde, $hasta);
    if (!ensureO14CacheBase($dbConnect, $key, $desde, $hasta)) { echo "  FALLO cache DB: $prov\n"; $failN++; continue; }
    $stamp = o14cCurrentStamp($dbConnect, $key);
    $payload = o14cBuildPayloadC($dbConnect, $key, $desde, $hasta);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ok = $stamp !== null && o14cWritePayload($key, $json, $stamp);
    printf("  %s %-28s grupos=%d filas_json=%.1fKB %.1fs\n", $ok?'OK  ':'FALLO', $prov,
        count($payload['grupos']), strlen($json)/1024, microtime(true)-$tp);
    $ok ? $okN++ : $failN++;
}
o14cCleanup();
printf("[prebuild_o14c] fin: OK=%d FALLO=%d en %.1fs\n", $okN, $failN, microtime(true)-$t0);
sqlsrv_close($dbConnect);
exit($failN ? 1 : 0);
```

- [ ] **Step 2: Escribir `sql/prebuild_o14c.bat`**

```bat
@echo off
REM ============================================================================
REM Prebuild nocturno del cache en disco de O14 tab=c (arbol sin filtro).
REM Reutiliza conexion_integracion.php de la app (auth SQL contra RDS). Sin sqlcmd.
REM Programar en el Programador de tareas de Windows:
REM   - Disparador: diario, DESPUES del refresh de Items_Mat (p.ej. 03:30)
REM   - Accion: Iniciar programa -> este .bat
REM   - "Ejecutar con privilegios mas altos" + "aunque el usuario no haya iniciado sesion"
REM AJUSTA PHP_EXE si php.exe esta en otra ruta.
REM ============================================================================
setlocal
set PHP_EXE=C:\xampp\php\php.exe
"%PHP_EXE%" "%~dp0prebuild_o14c.php" >> "%~dp0prebuild_o14c.log" 2>&1
endlocal
```

- [ ] **Step 3: Smoke del prebuild (1 corrida real)**

Run: `php sql/prebuild_o14c.php`
Expected: imprime `proveedores=N`, líneas `OK <proveedor> grupos=...`, y `fin: OK=... FALLO=0`. Verifica que quedaron archivos:
Run: `ls cache/o14c_*.json.gz | head` → al menos uno.
Verifica un hit posterior: correr `php tests/verificar_o14c_payload.php --paridad` de nuevo debe encontrar el cache fresco (o probar `o14cDiskFresh` en un mini-script para un proveedor prebuildeado → true).

- [ ] **Step 4: Añadir `.log` al `.gitignore` (si no cae ya en `*.log`)**

`sql/prebuild_o14c.log` ya cae en el patrón `*.log` del `.gitignore` — verifícalo:
Run: `git status --short sql/` → no debe listar el `.log`.

- [ ] **Step 5: Commit**

```bash
git add sql/prebuild_o14c.php sql/prebuild_o14c.bat
git commit -m "feat(o14c): prebuild nocturno del cache en disco por proveedor (CLI + bat Task Scheduler)"
```

---

## Task 5: Revisión final de rama + preparación de deploy

**Files:** ninguno nuevo (revisión + notas de deploy).

- [ ] **Step 1: `php -l` en todos los archivos tocados**

Run: `for f in api/informe_o14.php api/lib_o14c_payload.php sql/prebuild_o14c.php; do php -l "$f"; done`
Expected: `No syntax errors` en los 3.

- [ ] **Step 2: Correr toda la suite relevante**

Run: `php tests/verificar_o14c_payload.php` (primitivas) → `TODO OK`.
Run: `php tests/verificar_o14c_payload.php --paridad` → `PARIDAD OK`.
Run: `php tests/verificar_o14_cache.php --paridad` (suite existente, no debe romperse por mover `ensamblarArbol`) → verde.

- [ ] **Step 3: Solicitar `requesting-code-review` de la rama** (opus, correctitud + calidad), enfocado en: paridad disco↔vivo, degradación cuando `cache/` no escribible, concurrencia flock+double-check, y que el caso filtrado / tabs b/reco no cambiaron.

- [ ] **Step 4: Redactar el checklist de deploy** (queda para Rafael, ver spec §7):
  1. Verificar `sql/006_o14_cache.sql` ejecutado en RDS prod (pendiente del sub-proyecto o14-filtrado; sin la tabla `o14_cache_base` el cache en disco tampoco funciona).
  2. `cache/` escribible por el usuario de Apache/PHP en prod y aliados.
  3. Re-sync a `plataforma_20_produccion` + servidor aliados: `api/informe_o14.php`, `api/lib_o14c_payload.php`, `sql/prebuild_o14c.php`, `sql/prebuild_o14c.bat`.
  4. Verificar en prod que `mod_deflate` no haga doble-compresión con `Content-Encoding: gzip` (si lo hace, ajustar `o14cServeGz` o desactivar deflate para esa respuesta).
  5. Montar la tarea programada nocturna con `sql/prebuild_o14c.bat` (después de Items_Mat) en cada servidor PHP.
  6. Borrar `medir_link_db.php` del servidor de prod si sigue.

- [ ] **Step 5: Commit de cierre (si hubo ajustes del review)** y `finishing-a-development-branch`.

---

## Self-Review (hecho)

- **Cobertura del spec:** §1 diagnóstico → contexto del plan; §4.1 lib disco → Task 1+2; §4.2 endpoint → Task 3; §4.3 prebuild → Task 4; §5 concurrencia → Task 3 (flock) + heredado DB; §6 pruebas → tests en cada task + Task 5; §7 deploy → Task 5 Step 4. Sin secciones huérfanas.
- **Placeholders:** ninguno — todo el código de las funciones y tests está completo. (El único punto marcado como "según entorno" es el arnés HTTP del `--e2e`, con fallback explícito a verificación en navegador.)
- **Consistencia de tipos/nombres:** `o14cBuildPayloadC`, `o14cCurrentStamp`, `o14cDiskFresh`, `o14cReadPayload`, `o14cWritePayload`, `o14cServeGz`, `o14cCleanup`, `o14cPayloadPath`, `o14cStampPath` usados con las mismas firmas en Tasks 1-4. Shape de respuesta idéntico al de `informe_o14.php` tab=c (verificado contra líneas 412-414).
- **Refinamiento sobre el spec:** frescura por `.stamp` (comparación timestamp-DB↔timestamp-DB) en vez de `filemtime ≥ creado`, para evitar el cruce de relojes Colombia↔UTC. Mismo intent, más robusto.
