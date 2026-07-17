# Calentar el catálogo de filtros de evol — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el nocturno/login-prewarm dejen escrito el cache diario de filtros de evol, para que el primer aliado del día nunca pague el miss de ~204 s.

**Architecture:** `warmProveedor` ya materializa `evol_cache_base` para el dataset. Se deriva el catálogo de filtros de esa misma tabla con un `SELECT DISTINCT` (~ms) y se escribe `cache/evol_filtros_<md5>.json`, que el endpoint (tras el arreglo `4ef8ec4`) ya sirve como hit antes de construir `#base`. El endpoint no se toca.

**Tech Stack:** PHP 8 + SQL Server (sqlsrv), sin frameworks. Tests = scripts PHP que subprocesan el endpoint real.

**Spec:** `docs/superpowers/specs/2026-07-17-evol-filtros-prewarm-design.md`

## Global Constraints

- **Alcance: SOLO evol.** o45/o14/geo no se tocan (su miss de filtros es 2–5 s, tolerable).
- **No tocar tablas SIESA** (t*** en stanton/Siesa_Cloud). `evol_cache_base` está en `INTEGRACION` — permitido. Ver [[plataforma-20-siesa-no-tocar]].
- **El endpoint `informe_evol.php` NO se modifica.** C es puramente aditivo en `lib_evol_cache.php` + `lib_prewarm.php`.
- **Shape del cache diario, EXACTO** (lo lee `informe_evol.php:28`): archivo `cache/evol_filtros_<md5($proveedor)>.json`, contenido `['ok'=>true,'tab'=>'filtros','combos'=>[...]]`. Cada combo tiene las 11 claves: `marca,tipo,categoria,subcategoria,genero,publico,referencia,negocio,grupo,tienda,tienda_cod`.
- **Paridad de CONJUNTO, no de orden** (el front consume el catálogo como cascadas; el orden no importa).
- **ok-gate:** no escribir el archivo si la derivación falla o si el proveedor no tiene datos (combos vacío → reportar `'vacio'`, coherente con el fix `29a9ba8`).
- **`.bat`/archivos nuevos con LF está bien** (solo los `.bat` requieren CRLF, y este plan no crea ninguno).
- Commits: terminar con las líneas `Co-Authored-By` y `Claude-Session` que usa el repo.

---

### Task 1: `evolBuildFiltros` — derivar el catálogo de `evol_cache_base`

**Files:**
- Modify: `api/lib_evol_cache.php` (añadir la función `evolBuildFiltros`, junto a `ensureEvolCacheBase`)
- Test: `tests/evol_filtros_paridad_test.php` (nuevo)

**Interfaces:**
- Consumes: `evolCacheKey($proveedor,$desdeMes,$hastaMes)`, `ensureEvolCacheBase($conn,$key,$desdeMes,$hastaMes,$force=false)`, `buildRefsFromMat($conn,$proveedor)` (ya existen).
- Produces: `evolBuildFiltros($conn, string $ekey): array` — devuelve `array` de combos (cada uno con las 11 claves) en éxito, o `['error'=>mixed]` si el `SELECT` falla. El caller distingue error con `isset($r['error'])`.

- [ ] **Step 1: Write the failing test**

Crear `tests/evol_filtros_paridad_test.php`:

```php
<?php
// Paridad PERMANENTE del catálogo de filtros de evol: el DERIVADO de evol_cache_base
// (evolBuildFiltros, lo que usa warmProveedor) debe ser el MISMO CONJUNTO que el catálogo del
// endpoint vivo (informe_evol.php?tab=filtros, que construye #base). Si divergen, "derivar"
// cambiaría los filtros del usuario en silencio. Red contra el drift entre las dos fuentes.
//   php tests/evol_filtros_paridad_test.php ["PROVEEDOR"]
$prov = $argv[1] ?? 'BH BRANDS SAS';
$root = dirname(__DIR__);
require "$root/conexion/conexion_integracion.php";
require_once "$root/api/lib_refs.php";
require_once "$root/api/lib_evol_cache.php";
if ($dbConnect === false) { echo "FALLO: conexión DB\n"; exit(1); }

$ed = (date('Y') - 1) . '-01'; $eh = date('Y-m');
$ekey = evolCacheKey($prov, $ed, $eh);
echo "PARIDAD filtros evol — DERIVADO(cache_base) vs VIVO(endpoint)\nproveedor = $prov\n\n";

function keyOf(array $c): string {
    return implode('|', array_map(fn($k) => trim((string)($c[$k] ?? '')),
        ['marca','tipo','categoria','subcategoria','genero','publico',
         'referencia','negocio','grupo','tienda','tienda_cod']));
}

// VIVO: endpoint real tab=filtros, forzando miss (borrar cache diario).
$daily = "$root/cache/evol_filtros_" . md5($prov) . '.json';
@unlink($daily);
$nul = (stripos(PHP_OS,'WIN')===0) ? 'NUL' : '/dev/null';
$cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr '
     . escapeshellarg("$root/tests/_endpoint_run_evol.php") . ' ' . escapeshellarg($prov)
     . ' ' . escapeshellarg('tab=filtros') . ' 2>' . $nul;
$raw = (string) shell_exec($cmd);
$a = strpos($raw, '{'); $b = strrpos($raw, '}');
$vivoJson = ($a === false) ? null : json_decode(substr($raw, $a, $b - $a + 1), true);
if (!is_array($vivoJson) || !isset($vivoJson['combos'])) { echo "FALLO: el endpoint no devolvió combos\n"; exit(1); }
$vivo = [];
foreach ($vivoJson['combos'] as $c) $vivo[keyOf($c)] = true;

// DERIVADO: materializar cache_base (como warmProveedor) y derivar.
if (!buildRefsFromMat($dbConnect, $prov)) { echo "FALLO: buildRefsFromMat\n"; exit(1); }
if (!ensureEvolCacheBase($dbConnect, $ekey, $ed, $eh, true)) { echo "FALLO: ensureEvolCacheBase\n"; exit(1); }
$combos = evolBuildFiltros($dbConnect, $ekey);
if (isset($combos['error'])) { echo "FALLO: evolBuildFiltros error: " . json_encode($combos['error']) . "\n"; exit(1); }
$deriv = [];
foreach ($combos as $c) $deriv[keyOf($c)] = true;

$soloVivo  = array_diff_key($vivo, $deriv);
$soloDeriv = array_diff_key($deriv, $vivo);
echo "combos VIVO(endpoint) : " . count($vivo) . "\n";
echo "combos DERIVADO       : " . count($deriv) . "\n";
echo "solo en VIVO          : " . count($soloVivo) . "\n";
echo "solo en DERIVADO      : " . count($soloDeriv) . "\n";
foreach (array_slice(array_keys($soloVivo), 0, 6) as $k)  echo "  falta en derivado: $k\n";
foreach (array_slice(array_keys($soloDeriv), 0, 6) as $k) echo "  sobra en derivado: $k\n";

sqlsrv_close($dbConnect);
if (count($vivo) === 0)               { echo "\nFALLO: el endpoint no devolvió ningún combo (¿proveedor sin datos? pasa otro).\n"; exit(1); }
if ($soloVivo || $soloDeriv)          { echo "\nFALLO: DIFIEREN — derivar cambiaría los filtros.\n"; exit(1); }
echo "\nOK: catálogo derivado == vivo (conjunto). Sin drift.\n";
exit(0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/evol_filtros_paridad_test.php 2>&1 | grep -v "xdebug\|dio_ts\|openssl\|Failed loading"`
Expected: FAIL — `Fatal error: Uncaught Error: Call to undefined function evolBuildFiltros()`.

- [ ] **Step 3: Write minimal implementation**

En `api/lib_evol_cache.php`, dentro del bloque `if (!function_exists(...))` que agrupa las funciones evol (o junto a `ensureEvolCacheBase`), añadir:

```php
    /**
     * Catálogo de filtros de evol DERIVADO de evol_cache_base (ya materializado por
     * ensureEvolCacheBase). Mismo CONJUNTO de combos que el build vivo del endpoint
     * (informe_evol.php tab=filtros, que usa #base) — verificado por
     * tests/evol_filtros_paridad_test.php. Devuelve array de combos, o ['error'=>...] si el
     * SELECT falla (para que el caller aplique ok-gate). Las 11 claves y el filtro CEDI son
     * EXACTAMENTE los del endpoint; no cambiar sin actualizar el test de paridad.
     */
    function evolBuildFiltros($conn, string $ekey): array {
        $sql = "SELECT DISTINCT marca, tipo, categoria, subcategoria, genero, publico_objetivo,
                    referencia, negocio, ISNULL(grupo,'') AS grupo, rtrim(bodega) AS cod,
                    ISNULL(nombre,'') AS nombre
                FROM INTEGRACION.dbo.evol_cache_base WITH (NOLOCK)
                WHERE cache_key = ? AND bodega <> 'CEDI'";
        $st = sqlsrv_query($conn, $sql, [$ekey]);
        if ($st === false) return ['error' => sqlsrv_errors()];
        $combos = [];
        while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) {
            $combos[] = [
                'marca'=>trim((string)$r['marca']), 'tipo'=>trim((string)$r['tipo']),
                'categoria'=>trim((string)$r['categoria']), 'subcategoria'=>trim((string)$r['subcategoria']),
                'genero'=>trim((string)$r['genero']), 'publico'=>trim((string)$r['publico_objetivo']),
                'referencia'=>trim((string)$r['referencia']), 'negocio'=>trim((string)$r['negocio']),
                'grupo'=>trim((string)$r['grupo']), 'tienda'=>trim((string)$r['nombre']),
                'tienda_cod'=>trim((string)$r['cod']),
            ];
        }
        sqlsrv_free_stmt($st);
        return $combos;
    }
```

Nota: si en `lib_evol_cache.php` las funciones están sueltas (sin `function_exists`), colocarla al mismo nivel, después de `ensureEvolCacheBase`. Verificar el patrón del archivo antes de insertar.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/evol_filtros_paridad_test.php 2>&1 | grep -v "xdebug\|dio_ts\|openssl\|Failed loading"`
Expected: PASS — `OK: catálogo derivado == vivo (conjunto). Sin drift.` (BH BRANDS: 1151 combos).

- [ ] **Step 5: Verify parity on a second provider (tamaño distinto)**

Run: `php tests/evol_filtros_paridad_test.php "D&E OLAM SAS" 2>&1 | grep -E "combos|solo en|OK|FALLO"`
Expected: PASS — 37 combos, 0 diffs.

- [ ] **Step 6: Commit**

```bash
git add api/lib_evol_cache.php tests/evol_filtros_paridad_test.php
git commit -m "feat(evol): evolBuildFiltros — catalogo de filtros derivado de cache_base

Deriva el catalogo de filtros de evol_cache_base (que warmProveedor ya materializa
para el dataset) con un SELECT DISTINCT, en vez de reconstruir #base. Mismo conjunto
de combos que el build vivo del endpoint, verificado por tests/evol_filtros_paridad_test
(1151 combos BH BRANDS, 37 D&E OLAM, 0 diffs). Fuente unica de la derivacion; el test
de paridad es la red contra el drift entre las dos fuentes del catalogo.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_014dZ7CywCpNiipjr4NrnrtA"
```

---

### Task 2: `warmProveedor` escribe el cache diario de filtros

**Files:**
- Modify: `api/lib_prewarm.php` (rama evol, tras `ensureEvolCacheBase`)
- Test: `tests/prewarm_filtros_test.php` (nuevo)

**Interfaces:**
- Consumes: `evolBuildFiltros($conn,$ekey)` (Task 1), `evolCacheKey`, `ensureEvolCacheBase` (existentes).
- Produces: efecto de borde — el archivo `cache/evol_filtros_<md5($proveedor)>.json` con `['ok'=>true,'tab'=>'filtros','combos'=>[...]]`, fechado hoy. `warmProveedor` añade `$out['evol_filtros']` ∈ `{'warmed','vacio','failed','skipped','failed-ensure'}` (espeja el camino que tomó `$out['evol']`: `skipped` si el dataset estaba fresco, `failed-ensure` si no se pudo materializar `cache_base`, y `warmed`/`vacio`/`failed` en el camino que sí deriva). Nunca altera `$out['evol']`. La regla `$bad` de `prebuild_all` solo cuenta `'failed'`/`'failed-refs'`/`'failed-ensure'`, así que `'warmed'`/`'vacio'`/`'skipped'` de `evol_filtros` no marcan FALLO; un `'failed'` de escritura sí (correcto).

- [ ] **Step 1: Write the failing test**

Crear `tests/prewarm_filtros_test.php`:

```php
<?php
// warmProveedor debe dejar escrito el cache diario de filtros de evol (lo que sirve el hit del
// endpoint tras el arreglo 4ef8ec4), para que el 1er aliado del dia NO pague el miss de ~204s.
//   php tests/prewarm_filtros_test.php ["PROVEEDOR_CON_DATOS"] ["PROVEEDOR_VACIO"]
$provDatos = $argv[1] ?? 'BH BRANDS SAS';
$provVacio = $argv[2] ?? 'GUAUTA SHOES';
$root = dirname(__DIR__);
require "$root/conexion/conexion_integracion.php";
require_once "$root/api/lib_prewarm.php";
if ($dbConnect === false) { echo "FALLO: conexión DB\n"; exit(1); }

echo "PREWARM filtros evol\n\n";
$fallos = [];

// 1) Proveedor CON datos: se escribe el cache diario, de hoy, con combos y hit válido.
$daily = "$root/cache/evol_filtros_" . md5($provDatos) . '.json';
@unlink($daily);
$r = warmProveedor($dbConnect, $provDatos, false);
printf("[%s] evol=%s evol_filtros=%s\n", $provDatos, $r['evol'], $r['evol_filtros'] ?? '(ausente)');
if (($r['evol_filtros'] ?? '') !== 'warmed') $fallos[] = "evol_filtros='" . ($r['evol_filtros'] ?? 'ausente') . "' (se esperaba 'warmed')";
elseif (!is_file($daily))                     $fallos[] = "no se escribió $daily";
else {
    if (date('Y-m-d', filemtime($daily)) !== date('Y-m-d')) $fallos[] = "el archivo no es de hoy";
    $j = json_decode((string)file_get_contents($daily), true);
    if (!is_array($j) || ($j['ok'] ?? false) !== true || empty($j['combos'])) $fallos[] = "el archivo no es un hit válido (ok+combos)";
}

// 2) Proveedor VACÍO: no es 'warmed' ni escribe basura; 'vacio' es correcto.
$dailyV = "$root/cache/evol_filtros_" . md5($provVacio) . '.json';
@unlink($dailyV);
$rv = warmProveedor($dbConnect, $provVacio, false);
printf("[%s] evol=%s evol_filtros=%s\n", $provVacio, $rv['evol'], $rv['evol_filtros'] ?? '(ausente)');
if (($rv['evol_filtros'] ?? '') === 'warmed') $fallos[] = "un proveedor sin datos no debería reportar 'warmed'";
if (($rv['evol_filtros'] ?? '') === 'failed') $fallos[] = "un proveedor sin datos no es 'failed' (debe ser 'vacio')";

sqlsrv_close($dbConnect);
if ($fallos) { foreach ($fallos as $f) echo "FALLO: $f\n"; exit(1); }
echo "\nOK: warmProveedor deja el cache diario de filtros listo; vacío no es fallo.\n";
exit(0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/prewarm_filtros_test.php 2>&1 | grep -v "xdebug\|dio_ts\|openssl\|Failed loading"`
Expected: FAIL — `evol_filtros='ausente'` (warmProveedor todavía no lo produce).

- [ ] **Step 3: Write minimal implementation**

En `api/lib_prewarm.php`, reemplazar la rama evol actual (líneas ~33-38):

```php
        // --- evol: (Y-1)-01 .. Y-m ---
        $ed=(date('Y')-1).'-01'; $eh=date('Y-m'); $ek=evolCacheKey($proveedor,$ed,$eh);
        if ($onlyIfStale && evolDiskFresh($conn,$ek)) $out['evol']='skipped';
        elseif (!ensureEvolCacheBase($conn,$ek,$ed,$eh)) $out['evol']='failed-ensure';
        else { $st=evolCurrentStamp($conn); $p=evolBuildPayload($conn,$proveedor,$ek,$ed,$eh);
            $out['evol']=(($p['ok']??false)===true && $st!==null && evolWritePayload($ek,json_encode($p,JSON_UNESCAPED_UNICODE),$st))?'warmed':'failed'; }
```

por:

```php
        // --- evol: (Y-1)-01 .. Y-m ---
        $ed=(date('Y')-1).'-01'; $eh=date('Y-m'); $ek=evolCacheKey($proveedor,$ed,$eh);
        if ($onlyIfStale && evolDiskFresh($conn,$ek)) { $out['evol']='skipped'; $out['evol_filtros']='skipped'; }
        elseif (!ensureEvolCacheBase($conn,$ek,$ed,$eh)) { $out['evol']='failed-ensure'; $out['evol_filtros']='failed-ensure'; }
        else { $st=evolCurrentStamp($conn); $p=evolBuildPayload($conn,$proveedor,$ek,$ed,$eh);
            $out['evol']=(($p['ok']??false)===true && $st!==null && evolWritePayload($ek,json_encode($p,JSON_UNESCAPED_UNICODE),$st))?'warmed':'failed';
            // --- evol tab=filtros: derivar de cache_base (ya materializado arriba) y escribir el
            // cache diario que sirve el endpoint (informe_evol.php:28). Evita el miss de ~204s del
            // 1er aliado. Combos vacio -> proveedor sin datos -> 'vacio' (no fallo, no escribe). ---
            $cf = evolBuildFiltros($conn, $ek);
            if (isset($cf['error'])) $out['evol_filtros']='failed';
            elseif (empty($cf))      $out['evol_filtros']='vacio';
            else {
                $ffile = __DIR__ . '/../cache/evol_filtros_' . md5($proveedor) . '.json';
                $okW = @file_put_contents($ffile, json_encode(['ok'=>true,'tab'=>'filtros','combos'=>$cf], JSON_UNESCAPED_UNICODE)) !== false;
                $out['evol_filtros'] = $okW ? 'warmed' : 'failed';
            }
        }
```

Verificar que `require_once __DIR__ . '/lib_evol_disk.php';` (que a su vez requiere `lib_evol_cache.php`) ya está en `lib_prewarm.php` — sí lo está (línea 10). `evolBuildFiltros` queda disponible.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/prewarm_filtros_test.php 2>&1 | grep -v "xdebug\|dio_ts\|openssl\|Failed loading"`
Expected: PASS — `[BH BRANDS SAS] evol=warmed evol_filtros=warmed`, `[GUAUTA SHOES] ... evol_filtros=vacio`, `OK: warmProveedor deja el cache diario de filtros listo`.

- [ ] **Step 5: Regresión — nada roto**

Run: `php tests/verificar_prewarm.php --run 2>&1 | grep -v "xdebug\|dio_ts\|openssl\|Failed loading" | tail -6`
Expected: `PREWARM OK` (los 3 warmed / los 3 skipped siguen igual; `evol_filtros` es aditivo).

Run: `php tests/prewarm_vacio_test.php 2>&1 | grep -E "OK:|FALLO:"`
Expected: `OK: el proveedor vacío se reporta 'vacio' y NO cuenta como fallo del nocturno.` (verifica que `evol_filtros='vacio'` no rompe la clasificación de `prebuild_all`; nota: la regla `$bad` de prebuild_all solo mira 'failed'/'failed-refs'/'failed-ensure', así que 'vacio' y 'warmed' de evol_filtros no cuentan como fallo — confirmar).

Run: `php tests/filtros_cache_test.php evol 2>&1 | grep -E "OK:|FALLO:|evol"`
Expected: `OK` — el endpoint sigue sirviendo su hit de filtros.

- [ ] **Step 6: Verify end-to-end — el aliado ya no paga el miss**

Simular el flujo real: warmProveedor (nocturno) escribe el diario; el endpoint debe servir un HIT instantáneo, no reconstruir #base.

```bash
php -r '$p="BH BRANDS SAS"; @unlink("cache/evol_filtros_".md5($p).".json");' 2>/dev/null
# 1) el nocturno calienta:
php -r 'require "conexion/conexion_integracion.php"; require_once "api/lib_prewarm.php"; $r=warmProveedor($dbConnect,"BH BRANDS SAS",false); echo "evol_filtros=".$r["evol_filtros"]."\n";' 2>/dev/null | grep evol_filtros
# 2) el aliado abre filtros -> debe ser HIT (<3s), no ~204s:
START=$(date +%s%N); php tests/_endpoint_run_evol.php "BH BRANDS SAS" "tab=filtros" > /dev/null 2>&1; END=$(date +%s%N); echo "hit tras prewarm: $(( (END-START)/1000000 )) ms"
```
Expected: `evol_filtros=warmed`, y el hit `< 3000 ms` (vs ~204000 ms sin C).

- [ ] **Step 7: Commit**

```bash
git add api/lib_prewarm.php tests/prewarm_filtros_test.php
git commit -m "feat(prewarm): calentar el catalogo de filtros de evol (sub-proyecto C)

warmProveedor, tras materializar evol_cache_base para el dataset, deriva el catalogo
de filtros (evolBuildFiltros) y escribe el cache diario cache/evol_filtros_<md5>.json
que sirve el endpoint. Asi el 1er aliado del dia encuentra un hit instantaneo en vez
de pagar el miss de ~204s que reconstruye #base. Costo incremental ~un SELECT DISTINCT
(cache_base ya esta materializado). Proveedor sin datos -> 'vacio', no escribe (coherente
con 29a9ba8). Solo evol; el endpoint no se toca (fallback intacto si el nocturno no corrio).

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_014dZ7CywCpNiipjr4NrnrtA"
```

---

## Notas de integración (no son tareas)

- **Camino `skipped`:** cuando `onlyIfStale=true` y el dataset ya está fresco, no se reescribe el cache diario de filtros (se marca `evol_filtros='skipped'`). El nocturno (`onlyIfStale=false`) siempre lo escribe, así que a las ~04:00 queda listo para todos. El hueco teórico (dataset fresco pero filtros del día ausente) lo cubre el fallback del endpoint (miss → `#base`, como hoy).
- **Deploy:** solo `api/lib_evol_cache.php` + `api/lib_prewarm.php`. Sin DDL. Re-sync a staging desde git y copiar a WMS-LAB **excluyendo `cache\`** (ver [[plataforma-20-deploy-gotchas]]). El efecto se ve tras el próximo nocturno (o corriendo la tarea a mano).
- **Verificación en prod:** tras el nocturno, `cache/evol_filtros_*.json` debe existir para los proveedores con datos, y el `prebuild_all.log` mostrará la línea de cada proveedor sin FALLO nuevo.
