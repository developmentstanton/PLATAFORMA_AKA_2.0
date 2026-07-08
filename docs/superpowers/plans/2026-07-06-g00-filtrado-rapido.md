# G00 Filtrado Rápido (cache de dataset granular) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que aplicar filtros en G00 (Dashboard de Ventas) baje de ~14-23s a ~0,3-2s, cacheando el dataset granular por proveedor+período en INTEGRACION y re-agregando sobre el cache con el MISMO SQL de hoy (solo cambia el FROM).

**Architecture:** Cache-first transparente. Cada request: (1) asegura un cache fresco del granular `ventas ⋈ #refs ⋈ Bodegas` (2 años) para `(proveedor, anioA, anioB, desde, hasta)` en `INTEGRACION.dbo.g00_cache_ventas` — materializa si falta/stale (paga el scan+join una vez, ~2,5s); (2) agrega el tab pedido leyendo del cache con los filtros en el WHERE. La siembra (`countTiendasSiembra`) se cachea análogamente. Reusa el SQL de agregación existente → cero reimplementación, paridad por construcción.

**Tech Stack:** PHP 8 + sqlsrv (SQL Server / RDS INTEGRACION), tablas de cache persistentes en INTEGRACION.

## Global Constraints

- **PROHIBIDO modificar tablas SIESA** (`t***` en `stanton`). Solo SELECT. El cache y toda estructura nueva viven en `INTEGRACION`.
- **Paridad EXACTA** con la salida actual de G00 por tab (detal/tiendas/productos/periodos): el SQL de agregación NO cambia, solo su FROM (cache en vez de `cteVentas()⋈#refs⋈Bodegas`). El test de paridad (con-cache vs en-vivo) lo blinda.
- **Frontera de filtros:** dimensiones (marca/tipo/categoria/subcategoria/genero/publico/referencia, color/talla, grupo/tienda/centro_comercial/depto/ciudad) → re-agregar cache (rápido). `desde/hasta` o años (`anioA/anioB`) → rebuild del cache (cambian la `cache_key`).
- **`cache_key` = hash de `(proveedor, anioA, anioB, desde, hasta)`**; los filtros NO entran en la key.
- `php -l` limpio; `WITH (NOLOCK)` en lecturas; `sqlsrv_free_stmt` tras cada statement.
- Objetivo de latencia: 1ª carga (materialize) ~2,5-3s; filtro ~0,3-2s.

---

## File Structure

- **Create `sql/005_g00_cache.sql`** — DDL de `INTEGRACION.dbo.g00_cache_ventas` y `g00_cache_siembra` + índices. (Se ejecuta a mano en el RDS, como los scripts previos de `sql/`.)
- **Create `api/lib_g00_cache.php`** — `g00CacheKey(...)`, `ensureG00CacheVentas($conn,$key,$params)`, `ensureG00CacheSiembra($conn,$provKey)`, `g00CacheCleanup($conn)`. Aquí vive el materialize (scan+join una vez) + TTL + limpieza.
- **Modify `api/informe_g00.php`** — al inicio (tras `#refs`): computar key, `ensureG00Cache*`. En cada tab: las queries de agregación leen `FROM INTEGRACION.dbo.g00_cache_ventas c WHERE c.cache_key=?` (en vez de `cteVentas() v ⋈ #refs i ⋈ Bodegas b`); `countTiendasSiembra` lee del cache de siembra. Conservar un modo "en vivo" (flag) como oráculo del test.
- **Create `tests/verificar_g00_cache.php`** — paridad con-cache vs en-vivo por tab × proveedores × filtros.

---

## Global constraint recordatorio de columnas del cache

El granular denormalizado debe tener TODAS las columnas que las agregaciones y los filtros usan (hoy vía aliases `i`=#refs, `v`=ventas, `b`=Bodegas):
- De ventas (`v`): `FECHA`, `BODEGA`, `REFERENCIA`, `COLOR`, `TALLA`, `CANTIDAD`, `VALOR`, `MARGEN`.
- De #refs (`i`): `MARCA`, `TIPO`, `CATEGORIA`, `SUBCATEGORIA`, `GENERO`, `PUBLICO_OBJETIVO`.
- De Bodegas (`b`): `GRUPO`, `NOMBRE`, `CENTRO_COMERCIAL`, `DEPTO`, `CIUDAD`.
- Derivadas útiles para GROUP BY: `anio` (YEAR(FECHA)), `mes` (MONTH), `dia` (DAY).

Al portar los filtros/GROUP BY, los prefijos `i.`/`v.`/`b.` pasan a ser el alias único del cache (`c.`).

---

## Task 1: Tablas de cache en INTEGRACION

**Files:**
- Create: `sql/005_g00_cache.sql`
- Test: verificación manual del DDL (SELECT sobre las tablas creadas)

**Interfaces:**
- Produces: tablas `INTEGRACION.dbo.g00_cache_ventas` y `INTEGRACION.dbo.g00_cache_siembra` con las columnas del contrato.

- [ ] **Step 1: Escribir el DDL**

Create `sql/005_g00_cache.sql`:

```sql
-- Cache del dataset granular de G00 (ventas denormalizado) por proveedor+periodo.
IF OBJECT_ID('INTEGRACION.dbo.g00_cache_ventas') IS NOT NULL DROP TABLE INTEGRACION.dbo.g00_cache_ventas;
CREATE TABLE INTEGRACION.dbo.g00_cache_ventas (
    cache_key       VARCHAR(64)  NOT NULL,
    FECHA           DATETIME     NULL,
    anio            INT          NULL,
    mes             INT          NULL,
    dia             INT          NULL,
    BODEGA          VARCHAR(20)  NULL,
    REFERENCIA      VARCHAR(50)  NULL,
    COLOR           VARCHAR(40)  NULL,
    TALLA           VARCHAR(40)  NULL,
    MARCA           VARCHAR(40)  NULL,
    TIPO            VARCHAR(40)  NULL,
    CATEGORIA       VARCHAR(60)  NULL,
    SUBCATEGORIA    VARCHAR(60)  NULL,
    GENERO          VARCHAR(40)  NULL,
    PUBLICO_OBJETIVO VARCHAR(60) NULL,
    GRUPO           VARCHAR(40)  NULL,
    NOMBRE          VARCHAR(120) NULL,
    CENTRO_COMERCIAL VARCHAR(120) NULL,
    DEPTO           VARCHAR(60)  NULL,
    CIUDAD          VARCHAR(60)  NULL,
    CANTIDAD        INT          NULL,
    VALOR           FLOAT        NULL,
    MARGEN          FLOAT        NULL,
    creado          DATETIME2    NOT NULL DEFAULT SYSDATETIME()
);
CREATE CLUSTERED INDEX ix_g00cv_key ON INTEGRACION.dbo.g00_cache_ventas (cache_key);

-- Cache de siembra (snapshot por proveedor), granular por (bodega,ref,color,talla)+dims de bodega.
IF OBJECT_ID('INTEGRACION.dbo.g00_cache_siembra') IS NOT NULL DROP TABLE INTEGRACION.dbo.g00_cache_siembra;
CREATE TABLE INTEGRACION.dbo.g00_cache_siembra (
    cache_key   VARCHAR(64) NOT NULL,   -- hash de proveedor (siembra = snapshot, sin fechas)
    BODEGA      VARCHAR(20) NULL,
    REFERENCIA  VARCHAR(50) NULL,
    COLOR       VARCHAR(40) NULL,
    TALLA       VARCHAR(40) NULL,
    MARCA VARCHAR(40) NULL, TIPO VARCHAR(40) NULL, CATEGORIA VARCHAR(60) NULL,
    SUBCATEGORIA VARCHAR(60) NULL, GENERO VARCHAR(40) NULL, PUBLICO_OBJETIVO VARCHAR(60) NULL,
    GRUPO VARCHAR(40) NULL, NOMBRE VARCHAR(120) NULL, CENTRO_COMERCIAL VARCHAR(120) NULL,
    DEPTO VARCHAR(60) NULL, CIUDAD VARCHAR(60) NULL,
    q           INT NULL,
    creado      DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);
CREATE CLUSTERED INDEX ix_g00cs_key ON INTEGRACION.dbo.g00_cache_siembra (cache_key);
```

- [ ] **Step 2: Ejecutar el DDL en el RDS y verificar**

Ejecutar `sql/005_g00_cache.sql` contra `INTEGRACION` (vía sqlcmd o el conector). Verificar:
Run: `php -d display_errors=0 -d display_startup_errors=0 -r 'require "conexion/conexion_integracion.php"; $s=sqlsrv_query($dbConnect,"SELECT COUNT(*) c FROM INTEGRACION.dbo.g00_cache_ventas"); var_dump(sqlsrv_fetch_array($s));'`
Expected: `int(0)` (tabla existe, vacía).

- [ ] **Step 3: Commit**

```bash
git add sql/005_g00_cache.sql
git commit -m "feat(g00): DDL tablas de cache g00_cache_ventas/siembra en INTEGRACION"
```

---

## Task 2: `lib_g00_cache.php` — key + materialize ventas + TTL/cleanup

**Files:**
- Create: `api/lib_g00_cache.php`
- Reference: `api/informe_g00.php:83-95` (`cteVentas`), `:665-770` (query consolidado: joins exactos `ventas v ⋈ #refs i ⋈ Bodegas b`)
- Test: `tests/verificar_g00_cache.php` (Task 5 lo extiende; en esta task, un smoke)

**Interfaces:**
- Consumes: `#refs` ya construido (buildRefsFromMat); `cteVentas()` (o su SQL) para el materialize.
- Produces:
  - `g00CacheKey($proveedor,$anioA,$anioB,$desde,$hasta): string` (hash de 64 chars, ej. `substr(sha1(...),0,32)` — o hash md5).
  - `ensureG00CacheVentas($conn,$key,$desde,$hasta): bool` — si NO hay filas frescas para `$key` (dentro del TTL), materializa: `DELETE WHERE cache_key=?` + `INSERT ... SELECT` del granular denormalizado (ventas 2 años ⋈ #refs ⋈ Bodegas). Devuelve true si ok.
  - `g00CacheCleanup($conn): void` — `DELETE FROM g00_cache_ventas WHERE creado < DATEADD(hour,-6,SYSDATETIME())` (+ ídem siembra).
  - Constante `G00_CACHE_TTL_MIN` (ej. 120 min): frescura del cache.

- [ ] **Step 1: Escribir un smoke test del materialize (falla: lib no existe)**

Create `tests/verificar_g00_cache.php` (versión inicial — smoke):

```php
<?php
error_reporting(E_ERROR|E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_g00_cache.php';   // <-- SUT (no existe -> RED)
$prov = $argv[1] ?? 'BH BRANDS SAS';
$anioA = (int)date('Y'); $anioB = $anioA-1;
$desde = "$anioA-01-01"; $hasta = date('Y-m-d', strtotime('-1 day'));
buildRefsFromMat($dbConnect, $prov);
$key = g00CacheKey($prov,$anioA,$anioB,$desde,$hasta);
$ok = ensureG00CacheVentas($dbConnect, $key, $anioB.'-01-01', $hasta);   // desde-anioB para cubrir 2 años
$st = sqlsrv_query($dbConnect, "SELECT COUNT(*) c FROM INTEGRACION.dbo.g00_cache_ventas WHERE cache_key=?", [$key]);
$n = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['c'];
echo ($ok && $n>0) ? "SMOKE OK filas=$n key=$key\n" : "SMOKE FAIL ok=".var_export($ok,true)." filas=$n\n";
exit(($ok && $n>0)?0:1);
```

Run: `php -d display_errors=1 tests/verificar_g00_cache.php` → Expected: FAIL `Failed opening required '.../lib_g00_cache.php'`.

- [ ] **Step 2: Implementar `api/lib_g00_cache.php`**

El materialize replica los **joins exactos** del consolidado (`informe_g00.php:665-770`: `FROM (cteVentas) v INNER JOIN #refs i ON i.REFERENCIA=... LEFT JOIN Bodegas b ON b.COD=... AND b.CIA=7`) — **leé esas líneas y copiá las condiciones ON tal cual** para no divergir. Denormaliza a las columnas del contrato:

```php
<?php
/**
 * Cache del dataset granular de G00 (enfoque B). Materializa ventas denormalizadas
 * (2 años ⋈ #refs ⋈ Bodegas) por (proveedor+período) en INTEGRACION.dbo.g00_cache_ventas,
 * para re-agregar filtros sin re-escanear las fact tables. Solo INTEGRACION (no SIESA).
 */
if (!defined('G00_CACHE_TTL_MIN')) define('G00_CACHE_TTL_MIN', 120);

if (!function_exists('g00CacheKey')) {
    function g00CacheKey($proveedor,$anioA,$anioB,$desde,$hasta): string {
        return substr(md5($proveedor.'|'.$anioA.'|'.$anioB.'|'.$desde.'|'.$hasta), 0, 32);
    }

    // ¿Hay cache fresco (dentro del TTL) para esta key?
    function g00CacheFresco($conn,$tabla,$key): bool {
        $sql = "SELECT TOP 1 1 FROM INTEGRACION.dbo.$tabla WITH (NOLOCK)
                WHERE cache_key=? AND creado > DATEADD(minute, -".G00_CACHE_TTL_MIN.", SYSDATETIME())";
        $st = sqlsrv_query($conn,$sql,[$key]); if ($st===false) return false;
        $hay = sqlsrv_fetch($st) ? true : false; sqlsrv_free_stmt($st); return $hay;
    }

    // Materializa el granular denormalizado si falta/stale. $desde2/$hasta cubren los 2 años.
    function ensureG00CacheVentas($conn,$key,$desde2,$hasta): bool {
        if (g00CacheFresco($conn,'g00_cache_ventas',$key)) return true;
        g00CacheCleanup($conn);
        $del = sqlsrv_query($conn,"DELETE FROM INTEGRACION.dbo.g00_cache_ventas WHERE cache_key=?",[$key]);
        if ($del===false) return false; sqlsrv_free_stmt($del);
        // NOTA: copiar los ON exactos de informe_g00.php:665-770. Aquí la forma:
        $sql = "INSERT INTO INTEGRACION.dbo.g00_cache_ventas
                  (cache_key,FECHA,anio,mes,dia,BODEGA,REFERENCIA,COLOR,TALLA,
                   MARCA,TIPO,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO,
                   GRUPO,NOMBRE,CENTRO_COMERCIAL,DEPTO,CIUDAD,CANTIDAD,VALOR,MARGEN)
                WITH ventas AS (
                   SELECT FECHA,BODEGA,REFERENCIA,CANTIDAD,VALOR,MARGEN,COLOR,TALLA
                     FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?
                   UNION ALL
                   SELECT FECHA,BODEGA,REFERENCIA,CANTIDAD,VALOR,MARGEN,COLOR,TALLA
                     FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?
                )
                SELECT ?, v.FECHA, YEAR(v.FECHA), MONTH(v.FECHA), DAY(v.FECHA),
                       rtrim(v.BODEGA), rtrim(v.REFERENCIA), rtrim(v.COLOR), rtrim(v.TALLA),
                       i.MARCA,i.TIPO,i.CATEGORIA,i.SUBCATEGORIA,i.GENERO,i.PUBLICO_OBJETIVO,
                       b.GRUPO,b.NOMBRE,b.CENTRO_COMERCIAL,b.DEPTO,b.CIUDAD,
                       v.CANTIDAD,v.VALOR,v.MARGEN
                FROM ventas v
                 INNER JOIN #refs i ON i.REFERENCIA = rtrim(v.REFERENCIA)
                 LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = rtrim(v.BODEGA) AND b.CIA = 7";
        // Params: ventas PBI (desde2,hasta), Acum (desde2,hasta), luego el cache_key del SELECT.
        // OJO orden: los ? del WITH van primero (4), luego el ? del SELECT (cache_key).
        $st = sqlsrv_query($conn,$sql,[$desde2,$hasta,$desde2,$hasta,$key]);
        if ($st===false) return false; sqlsrv_free_stmt($st); return true;
    }

    function g00CacheCleanup($conn): void {
        foreach (['g00_cache_ventas','g00_cache_siembra'] as $t) {
            $st = sqlsrv_query($conn,"DELETE FROM INTEGRACION.dbo.$t WHERE creado < DATEADD(minute, -".G00_CACHE_TTL_MIN.", SYSDATETIME())");
            if ($st!==false) sqlsrv_free_stmt($st);
        }
    }
}
```

> Verificá contra `informe_g00.php:665-770` que las condiciones `ON` de #refs y Bodegas (y el `rtrim`/`b.CIA=7`) coincidan EXACTO con el consolidado. Si el consolidado usa otra normalización de llave, replicala.

- [ ] **Step 3: Correr el smoke y verlo pasar**

Run: `php -d display_errors=0 -d display_startup_errors=0 tests/verificar_g00_cache.php "BH BRANDS SAS"`
Expected: `SMOKE OK filas=<n>` (n>0), exit 0. `php -l api/lib_g00_cache.php` limpio.

- [ ] **Step 4: Commit**

```bash
git add api/lib_g00_cache.php tests/verificar_g00_cache.php
git commit -m "feat(g00): lib_g00_cache - key + materialize granular ventas + TTL/cleanup"
```

---

## Task 3: Cache de siembra + `countTiendasSiembra` lee del cache

**Files:**
- Modify: `api/lib_g00_cache.php` (agregar `ensureG00CacheSiembra` + `siembraKey`)
- Modify: `api/informe_g00.php` (`countTiendasSiembra` lee del cache)
- Reference: `api/informe_g00.php:167-190` (countTiendasSiembra actual, JOIN al ERP)

**Interfaces:**
- Produces: `ensureG00CacheSiembra($conn,$provKey): bool` — materializa el granular de siembra (el subquery de `t400...` de :170-180, denormalizado con #refs + Bodegas) en `g00_cache_siembra` para `$provKey` (hash del proveedor). `countTiendasSiembra` re-implementado para agregar sobre el cache con `$filtroExtra`.

- [ ] **Step 1: Escribir test de paridad de siembra (falla)**

Extender `tests/verificar_g00_cache.php`: comparar `countTiendasSiembra` **vía cache** vs el original **en vivo**, sin filtros y con 1 filtro (una marca), para 2 proveedores → deben dar el MISMO entero.

```php
// (agregar al test) compara siembra cache vs vivo
$provKey = g00SiembraKey($prov);
ensureG00CacheSiembra($dbConnect, $provKey);
$viaCache = countTiendasSiembraCache($dbConnect, $provKey, '', []);
$enVivo   = countTiendasSiembra($dbConnect, '', []);   // función original
echo ($viaCache === $enVivo) ? "SIEMBRA OK ($viaCache)\n" : "SIEMBRA DIFF cache=$viaCache vivo=$enVivo\n";
```

Run: → FAIL (funciones nuevas no existen).

- [ ] **Step 2: Implementar el cache de siembra + `countTiendasSiembraCache`**

En `api/lib_g00_cache.php` agregar `g00SiembraKey($prov)` y `ensureG00CacheSiembra` que materializa el granular del subquery de `t400` (informe_g00.php:170-180) INNER JOIN #refs + LEFT JOIN Bodegas, denormalizando dims, en `g00_cache_siembra`. En `informe_g00.php` agregar `countTiendasSiembraCache($conn,$key,$filtroExtra,$paramsExtra)` que hace `SELECT COUNT(DISTINCT BODEGA) FROM g00_cache_siembra WHERE cache_key=? AND q>0 AND ISNULL(GRUPO,'') NOT IN ('BODEGA','ADMINISTRATIVAS') $filtroExtra` — con `$filtroExtra` re-prefijado a columnas del cache (sin `i.`/`v.`/`b.`). Copiá el subquery `t400` EXACTO de :170-180.

- [ ] **Step 3: Correr paridad de siembra**

Run: `php ... tests/verificar_g00_cache.php "BH BRANDS SAS"` → Expected: `SIEMBRA OK (<n>)` (cache == vivo).

- [ ] **Step 4: Commit**

```bash
git add api/lib_g00_cache.php api/informe_g00.php tests/verificar_g00_cache.php
git commit -m "feat(g00): cache de siembra + countTiendasSiembra sobre cache (paridad)"
```

---

## Task 4: Refactor de los 4 tabs para leer del cache + wiring del ensure

**Files:**
- Modify: `api/informe_g00.php` — (a) tras `#refs`, computar keys + `ensureG00CacheVentas`/`ensureG00CacheSiembra` + `g00CacheCleanup`; (b) en cada query de agregación (detal consolidado ~665, mensual ~796, tiendas ~421-505, productos ~572-631, periodos ~541), cambiar el FROM de `cteVentas() v ⋈ #refs i ⋈ Bodegas b` a `FROM INTEGRACION.dbo.g00_cache_ventas c WHERE c.cache_key = ?` y re-prefijar columnas `i.`/`v.`/`b.` → `c.`; quitar los params de fecha de esas queries (ya viven en el cache) y agregar el param `cache_key`; conservar el `$filtroExtra`/`$sameStoreClause` re-prefijados.
- Reference: todas las queries de tabs en `informe_g00.php`.

**Interfaces:**
- Consumes: `ensureG00CacheVentas`, `ensureG00CacheSiembra`, `g00CacheKey`, `g00SiembraKey`, `countTiendasSiembraCache`.
- Produces: endpoint G00 cache-first; salida idéntica por tab.

- [ ] **Step 1: Wiring del ensure (tras #refs)**

Tras construir `#refs`, antes de rutear el tab, agregar:
```php
require_once __DIR__ . '/lib_g00_cache.php';
$ckey = g00CacheKey($proveedor,$anioA,$anioB,$desdeAct,$hastaAct);
if (!ensureG00CacheVentas($dbConnect,$ckey,$desdeAnt,$hastaAct)) jsonFail(['error'=>sqlsrv_errors()],$dbConnect);
$skey = g00SiembraKey($proveedor);
ensureG00CacheSiembra($dbConnect,$skey);
```
(`$desdeAnt`..`$hastaAct` cubre los 2 años; ajustar a las variables reales de rango del endpoint.)

- [ ] **Step 2: Portar el consolidado (detal) al cache**

En la query consolidado (~665-770): reemplazar `FROM (cteVentas) v INNER JOIN #refs i ... LEFT JOIN Bodegas b ...` por `FROM INTEGRACION.dbo.g00_cache_ventas c WITH (NOLOCK) WHERE c.cache_key = ?`; cambiar los `i.`/`v.`/`b.`/(YEAR(FECHA) etc.) a `c.MARCA`/`c.COLOR`/`c.GRUPO`/`c.anio`/`c.mes`; el `$filtroExtra` re-prefijado a `c.`; `$sameStoreClause` (usa `v.BODEGA`) → `c.BODEGA` (y su EXISTS a Bodegas queda igual); quitar los 4 params de fecha, primer param = `$ckey`. Verificar por paridad (Task 5) antes de seguir.

- [ ] **Step 3: Portar mensual, tiendas, productos, periodos**

Aplicar la MISMA transformación (FROM cache + re-prefijo `c.` + `cache_key` param, sin fechas) a: mensual (~796), tiendas (~421-505), productos (~572-631), periodos (~541). Cada una: mismo GROUP BY GROUPING SETS y SELECT; solo cambia la fuente. `countTiendasSiembra` → `countTiendasSiembraCache($dbConnect,$skey,$filtroExtra,$paramsExtra)`.

- [ ] **Step 4: `php -l` + smoke por tab**

Run: `php -l api/informe_g00.php` (limpio) + un smoke que pega a cada tab con sesión simulada y confirma `ok:true` con filas.

- [ ] **Step 5: Commit**

```bash
git add api/informe_g00.php
git commit -m "refactor(g00): 4 tabs leen del cache (FROM g00_cache_ventas) + ensure cache-first"
```

---

## Task 5: Test de paridad con-cache vs en-vivo + medición

**Files:**
- Modify: `tests/verificar_g00_cache.php` — paridad completa por tab × proveedores × filtros.
- Reference: para el "en vivo" (oráculo), capturar la salida del endpoint ANTES del refactor (fixtures) o conservar un flag `?nocache=1` que fuerce el camino viejo.

**Interfaces:** consume el endpoint (con y sin cache) por tab.

- [ ] **Step 1: Definir el oráculo**

Opción elegida: agregar un flag `?nocache=1` a `informe_g00.php` que salta el cache y corre las queries en vivo (camino viejo, conservado). El test compara `?tab=X` (cache) vs `?tab=X&nocache=1` (vivo).

- [ ] **Step 2: Escribir la paridad (falla si difiere)**

Para `["BH BRANDS SAS","BRAHMA CONCEPT","CALZADO WALDOS"]` × tabs `[detal,tiendas,productos,periodos]` × filtros `[{}, {marca:una}, {grupo:uno}]`: capturar ambas salidas (cache vs nocache) vía sesión simulada y comparar los cuadros (normalizando orden/redondeo). Deben ser idénticas.

- [ ] **Step 3: Correr paridad**

Run: `php ... tests/verificar_g00_cache.php --paridad` → Expected: `PARIDAD G00 OK` (0 difs), exit 0.

- [ ] **Step 4: Medición antes/después**

Cronometrar (con sesión simulada): 1ª carga (cache miss → materialize) y un filtro (cache hit) por proveedor. Confirmar filtro ~0,3-2s.

- [ ] **Step 5: Commit**

```bash
git add tests/verificar_g00_cache.php api/informe_g00.php
git commit -m "test(g00): paridad cache vs en-vivo (nocache flag) + medicion antes/despues"
```

---

## Self-review (cobertura del spec)

- Cache en INTEGRACION (tablas): Task 1. ✔
- Materialize (scan+join 1 vez) + key + TTL/cleanup: Task 2. ✔
- Siembra cacheada: Task 3. ✔
- 4 tabs leen del cache (reusa SQL): Task 4. ✔
- Frontera filtros (cache) vs fechas (rebuild vía cache_key): Tasks 2+4. ✔
- Paridad con-cache vs en-vivo + medición: Task 5. ✔
- Frontend sin cambios: no hay task de frontend (G00 ya re-fetchea). Verificar en E2E. ✔
- SIESA intacto (solo SELECT; cache en INTEGRACION): Global constraints + Tasks. ✔

## Deploy (tras E2E)
- Ejecutar `sql/005_g00_cache.sql` en el RDS de prod.
- Re-sync `api/{informe_g00,lib_g00_cache}.php` a `plataforma_20_produccion` + copia al servidor de aliados.
- Montar (opcional) limpieza por TTL como job, o confiar en la limpieza oportunista de `g00CacheCleanup`.
