# O14 Filtrado Rápido (cache de #base denormalizado) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que cargar y filtrar O14 sea rápido — bajar tab=c de ~29s a segundos y hacer el filtrado instantáneo — cacheando el `#base` granular denormalizado (dims de #refs + Bodegas pegadas) e indexado en INTEGRACION, re-agregando el MISMO SQL sobre el cache (solo cambia el FROM; desaparece el `LEFT JOIN Bodegas` no-sargable de tab=c).

**Architecture:** Cache-first transparente, igual que G00. Cada request: (1) asegura un cache fresco del `#base` denormalizado para `(proveedor, desde, hasta)` en `INTEGRACION.dbo.o14_cache_base` — materializa si falta/stale (paga el cruce de las 4 fuentes + joins de dims una vez); (2) agrega el tab pedido (b/c/reco) leyendo del cache con los filtros en el WHERE. Un flag `?nocache=1` conserva el camino vivo actual como oráculo de paridad.

**Tech Stack:** PHP 8 + sqlsrv (SQL Server / RDS INTEGRACION), tabla de cache persistente en INTEGRACION. Reusa el playbook de concurrencia de G00 (`api/lib_g00_cache.php`).

## Global Constraints

- **PROHIBIDO modificar tablas SIESA** (`stanton.dbo.t***`). Solo SELECT. Toda estructura nueva vive en `INTEGRACION`.
- **Paridad EXACTA** con la salida actual de cada tab (b/c/reco): la lógica de agregación NO cambia, solo su fuente (cache en vez de `#base`⋈Bodegas). El test de paridad (cache vs `?nocache=1`) lo blinda.
- **Denormalizar valores RAW:** el cache guarda `grupo/nombre/centro_comercial/depto/ciudad` y `marca/tipo/...` tal cual salen de los joins (sin aplicar `ISNULL(...,'SIN GRUPO')`/`ISNULL(...,'')` en el materialize). Cada consumidor re-aplica su `ISNULL` en lectura EXACTO como hoy (tab=c usa `ISNULL(grupo,'SIN GRUPO')` y `ISNULL(nombre,bodega)`; filtros BOD usan `ISNULL(col,'')`). Esto preserva paridad por construcción (lección G00).
- **ADMIN/CEDI:** el materialize excluye bodegas `GRUPO='ADMINISTRATIVAS'` (excepto `bodega='CEDI'`), replicando el DELETE actual (`informe_o14.php:199-204`). El **CEDI se conserva** (lo usa el motor de reco).
- **Concurrencia (reusa G00):** materialize con `sp_getapplock` Exclusive/Transaction (resource namespaced `o14cache:<key>`) + double-checked freshness; freshness gate con `WITH (READPAST)`; reads del cache en READ COMMITTED (sin NOLOCK — INTEGRACION tiene RCSI ON). Copiar el patrón de `api/lib_g00_cache.php`.
- **`cache_key` = hash de `(proveedor, desde, hasta)`**; los filtros NO entran en la key.
- `php -l` limpio; `sqlsrv_free_stmt` tras cada statement.

## File Structure

- **Create `sql/006_o14_cache.sql`** — DDL de `INTEGRACION.dbo.o14_cache_base` + índices. (Se ejecuta a mano en el RDS, como los scripts previos.)
- **Create `api/lib_o14_cache.php`** — `o14CacheKey(...)`, `ensureO14CacheBase($conn,$key,$desde,$hasta)`, `o14CacheFresco(...)`, `o14CacheCleanup($conn)`. Aquí vive el materialize denormalizado + TTL + concurrencia. Espeja `api/lib_g00_cache.php`.
- **Modify `api/informe_o14.php`** — tras `#refs`: computar key, ensure (salvo `nocache`). Cada tab (b/c/reco) agrega `FROM o14_cache_base WHERE cache_key=? [+filtros]` en vez de `#base`⋈Bodegas. Flag `?nocache=1` conserva el camino vivo. Filtros REF/SKU/BOD pasan de DELETE a WHERE en lectura.
- **Create `tests/verificar_o14_cache.php`** (+ helpers) — smoke + paridad cache vs nocache por tab × proveedores × filtros + concurrencia.
- **Modify `informes/o14.php`** — auto-aplicar filtros con debounce (como `informes/g00.php`).

## Contrato de columnas del cache

`o14_cache_base` debe tener TODO lo que las agregaciones y filtros usan (hoy vía `#base` + join a Bodegas + `#refs`):
- De `#base`: `cia, bodega, negocio(ref-color), referencia, color, talla, siembra, disponible, hold, ventas`.
- Dims de `#refs` (filtros REF): `marca, tipo, categoria, subcategoria, genero, publico_objetivo`.
- Dims de `Bodegas` (tab=c + filtros BOD + exclusión ADMIN): `grupo, nombre, centro_comercial, depto, ciudad` (RAW).
- `cache_key`, `creado`.

---

## Task 1: Tabla de cache en INTEGRACION

**Files:**
- Create: `sql/006_o14_cache.sql`
- Test: verificación manual del DDL (SELECT COUNT sobre la tabla creada).

**Interfaces:**
- Produces: tabla `INTEGRACION.dbo.o14_cache_base` con las columnas del contrato + clustered index en `cache_key`.

- [ ] **Step 1: Escribir el DDL**

Create `sql/006_o14_cache.sql`:

```sql
-- Cache del #base granular de O14 (denormalizado) por proveedor+rango-de-ventas.
IF OBJECT_ID('INTEGRACION.dbo.o14_cache_base') IS NOT NULL DROP TABLE INTEGRACION.dbo.o14_cache_base;
CREATE TABLE INTEGRACION.dbo.o14_cache_base (
    cache_key        VARCHAR(64)  NOT NULL,
    cia              VARCHAR(10)  NULL,
    bodega           VARCHAR(20)  NULL,
    negocio          VARCHAR(120) NULL,
    referencia       VARCHAR(50)  NULL,
    color            VARCHAR(40)  NULL,
    talla            VARCHAR(40)  NULL,
    siembra          INT          NULL,
    disponible       INT          NULL,
    hold             INT          NULL,
    ventas           INT          NULL,
    marca            VARCHAR(40)  NULL,
    tipo             VARCHAR(40)  NULL,
    categoria        VARCHAR(60)  NULL,
    subcategoria     VARCHAR(60)  NULL,
    genero           VARCHAR(40)  NULL,
    publico_objetivo VARCHAR(60)  NULL,
    grupo            VARCHAR(40)  NULL,
    nombre           VARCHAR(120) NULL,
    centro_comercial VARCHAR(120) NULL,
    depto            VARCHAR(60)  NULL,
    ciudad           VARCHAR(60)  NULL,
    creado           DATETIME2    NOT NULL DEFAULT SYSDATETIME()
);
CREATE CLUSTERED INDEX ix_o14cb_key ON INTEGRACION.dbo.o14_cache_base (cache_key);
```

- [ ] **Step 2: Ejecutar el DDL en el RDS y verificar**

Ejecutar `sql/006_o14_cache.sql` contra INTEGRACION vía el conector PHP (leer el archivo y correrlo con `sqlsrv_query($dbConnect, $sqlText)` en un solo batch — no hay `GO`; ignorar warnings cosméticos de arranque de PHP). Verificar:
Run: `php -d display_startup_errors=0 -r 'require "conexion/conexion_integracion.php"; $s=sqlsrv_query($dbConnect,"SELECT COUNT(*) c FROM INTEGRACION.dbo.o14_cache_base"); var_dump(sqlsrv_fetch_array($s));'`
Expected: `int(0)` (tabla existe, vacía).

- [ ] **Step 3: Commit**

```bash
git add sql/006_o14_cache.sql
git commit -m "feat(o14): DDL tabla de cache o14_cache_base en INTEGRACION"
```

---

## Task 2: `lib_o14_cache.php` — key + materialize denormalizado + TTL/concurrencia

**Files:**
- Create: `api/lib_o14_cache.php`
- Reference: `api/informe_o14.php:106-204` (build de `#refs` + `#base` con CTEs `s/d/h/v` + INSERT + DELETE ADMIN); `api/lib_g00_cache.php` (patrón de concurrencia a copiar).
- Test: `tests/verificar_o14_cache.php` (versión inicial — smoke).

**Interfaces:**
- Consumes: `#refs` ya construido en la misma conexión (`buildRefsFromMat`); el SQL del build de `#base`.
- Produces:
  - `o14CacheKey($proveedor,$desde,$hasta): string` (`substr(md5($proveedor.'|'.$desde.'|'.$hasta),0,32)`).
  - `ensureO14CacheBase($conn,$key,$desde,$hasta): bool` — si NO hay cache fresco (dentro del TTL) para `$key`, materializa: `sp_getapplock` + double-check + `DELETE WHERE cache_key=?` + `INSERT ... SELECT` del `#base` denormalizado. Devuelve true si ok.
  - `o14CacheFresco($conn,$key): bool` — `SELECT TOP 1 1 FROM o14_cache_base WITH (READPAST) WHERE cache_key=? AND creado > DATEADD(minute,-TTL,SYSDATETIME())`.
  - `o14CacheCleanup($conn): void` — `DELETE WHERE creado < DATEADD(minute,-TTL,SYSDATETIME())`.
  - Constante `O14_CACHE_TTL_MIN` (ej. 120).

- [ ] **Step 1: Escribir un smoke test del materialize (falla: lib no existe)**

Create `tests/verificar_o14_cache.php` (versión inicial — smoke):

```php
<?php
error_reporting(E_ERROR|E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_o14_cache.php';   // <-- SUT (no existe -> RED)
$prov  = $argv[1] ?? 'BELTRANY SAS';
$desde = '2025-01-01'; $hasta = date('Y-m-d');
buildRefsFromMat($dbConnect, $prov);
$key = o14CacheKey($prov,$desde,$hasta);
$ok  = ensureO14CacheBase($dbConnect, $key, $desde, $hasta);
$st  = sqlsrv_query($dbConnect, "SELECT COUNT(*) c FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=?", [$key]);
$n   = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['c'];
echo ($ok && $n>0) ? "SMOKE OK filas=$n key=$key\n" : "SMOKE FAIL ok=".var_export($ok,true)." filas=$n\n";
exit(($ok && $n>0)?0:1);
```

Run: `php -d display_errors=1 tests/verificar_o14_cache.php` → Expected: FAIL `Failed opening required '.../lib_o14_cache.php'`.

- [ ] **Step 2: Implementar `api/lib_o14_cache.php`**

Copiar el ESQUELETO de concurrencia de `api/lib_g00_cache.php` (mismas funciones: freshness gate con `READPAST`, `sp_getapplock` Exclusive/Transaction con resource `'o14cache:'.$key`, double-checked freshness tras el lock, todos los paths liberan el lock con commit/rollback, `sqlsrv_free_stmt` en cada statement). El materialize replica el build de `#base` (`informe_o14.php:122-193`) — **leé esas líneas y copiá las CTEs `s/d/h/v` + `llaves` + los LEFT JOIN EXACTOS** — más:
- `INNER JOIN #refs r ON r.REFERENCIA = k.referencia` para las dims de producto (RAW: `r.MARCA, r.TIPO, r.CATEGORIA, r.SUBCATEGORIA, r.GENERO, r.PUBLICO_OBJETIVO`).
- `LEFT JOIN INTEGRACION.dbo.Bodegas bo ON bo.COD = k.bodega AND RIGHT('000'+rtrim(bo.CIA),3) = k.cia` para las dims de bodega (RAW: `bo.GRUPO, bo.NOMBRE, bo.CENTRO_COMERCIAL, bo.DEPTO, bo.CIUDAD`). **NO aplicar `ISNULL` aquí** — guardar RAW.
- **Exclusión ADMIN** en el WHERE del SELECT del materialize (replica el DELETE de `:199-204`): `WHERE (ISNULL(bo.GRUPO,'') <> 'ADMINISTRATIVAS' OR k.bodega = 'CEDI')`.
- El INSERT lleva `cache_key` como primer valor (literal-bind del `$key`) y las 22 columnas del contrato en orden.
- Params del materialize: los del build de ventas (`$desde,$hasta` [y Acum si `$desde<='2025-12-31'`]) tal como los arma el endpoint, seguidos del `$key`. **Verificá el orden posicional de los `?` contra el SQL final** (los del WITH van primero).

> Verificá contra `informe_o14.php:122-204` que las CTEs, los ON de los LEFT JOIN de `s/d/h/v` y la normalización de cia (`RIGHT('000'+rtrim(...),3)`) coincidan EXACTO. Si diverge, la paridad se rompe.

- [ ] **Step 3: Correr el smoke y verlo pasar**

Run: `php -d display_startup_errors=0 tests/verificar_o14_cache.php "BELTRANY SAS"`
Expected: `SMOKE OK filas=<n>` (n>0), exit 0. `php -l api/lib_o14_cache.php` limpio.

- [ ] **Step 4: Commit**

```bash
git add api/lib_o14_cache.php tests/verificar_o14_cache.php
git commit -m "feat(o14): lib_o14_cache - key + materialize #base denormalizado + TTL/concurrencia"
```

---

## Task 3: Refactor de los tabs (b/c/reco) para leer del cache + wiring + nocache + filtros en lectura

**Files:**
- Modify: `api/informe_o14.php`
- Reference: build actual `:106-228`; tab=b `:277-308`; tab=c `:310-335`; tab=reco `:337-399`; `kpiCounts` `:94-104`.

**Interfaces:**
- Consumes: `o14CacheKey`, `ensureO14CacheBase`, `o14CacheCleanup`.
- Produces: endpoint O14 cache-first; salida idéntica por tab; flag `?nocache=1` = camino vivo.

- [ ] **Step 1: Flag nocache + wiring del ensure (tras #refs)**

Agregar `$nocache = !empty($_GET['nocache']);`. Tras construir `#refs` (`:107`) y ANTES de decidir la fuente, cuando `!$nocache`:
```php
require_once __DIR__ . '/lib_o14_cache.php';
$okey = o14CacheKey($proveedor,$desde,$hasta);
if (!ensureO14CacheBase($dbConnect,$okey,$desde,$hasta)) jsonFail(['error'=>sqlsrv_errors()], $dbConnect);
o14CacheCleanup($dbConnect);
```
Cuando `$nocache`, se conserva el camino actual (build `#base` + DELETE ADMIN + filtros por DELETE).

- [ ] **Step 2: Parametrizar la fuente + filtros en lectura (cache mode)**

En modo cache, cada tab lee `FROM INTEGRACION.dbo.o14_cache_base c WHERE c.cache_key = ?` (READ COMMITTED, sin NOLOCK), con los filtros como WHERE sobre columnas del cache (en vez de los DELETE de `:109-228`):
- REF: `AND c.<col> IN (?,...)` para marca/tipo/categoria/subcategoria/genero/publico/referencia.
- SKU: `AND c.color IN (...)` / `AND c.talla IN (...)`.
- BOD: `AND (c.bodega = 'CEDI' OR ISNULL(c.<col>,'') IN (...))` para grupo/nombre(tienda)/centro_comercial/depto/ciudad — **conservando el CEDI** como hoy (`:221-225`).
Construir un helper que arme el `$whereFiltros` + `$paramsFiltros` a partir de `$_GET`, reprefijados a `c.`. En modo `$nocache`, la fuente sigue siendo `#base` (ya filtrado por los DELETE) sin cambios.

- [ ] **Step 3: Portar tab=b al cache**

En modo cache, la query de tab=b (`:278-283`) lee `FROM o14_cache_base c WHERE c.cache_key=? $whereFiltros` con `GROUP BY c.cia,c.negocio,c.referencia,c.color,c.talla`. `ensamblarTidy` intacto. Los KPIs de conteo (`:290-295`) también leen del cache (`FROM o14_cache_base WHERE cache_key=? $whereFiltros`). Params: `[$okey, ...$paramsFiltros]` (repetidos por subquery donde aplique).

- [ ] **Step 4: Portar tab=c al cache (el caso crítico)**

La query de tab=c (`:314-324`) en modo cache: **sin el `LEFT JOIN Bodegas`** — `grupo`/`nombre` son columnas. Aplicar `ISNULL(c.grupo,'SIN GRUPO') grupo`, `ISNULL(c.nombre,c.bodega) nombre` (EXACTO como hoy). `FROM o14_cache_base c WHERE c.cache_key=? $whereFiltros GROUP BY ISNULL(c.grupo,'SIN GRUPO'), c.cia,c.bodega, ISNULL(c.nombre,c.bodega), c.negocio,c.referencia,c.color,c.talla ORDER BY ISNULL(c.grupo,'SIN GRUPO'), (c.cia+'-'+c.bodega), c.negocio`. `ensamblarArbol` + `kpiCounts` intactos (kpiCounts lee del cache en modo cache). Verificar por paridad (Task 4) antes de seguir.

- [ ] **Step 5: Portar tab=reco al cache**

La query de reco (`:343-347`) en modo cache lee siembra/disponible/hold `FROM o14_cache_base c WHERE c.cache_key=? $whereFiltros` con el mismo GROUP BY. El resto (agrupar por cia/negocio, CEDI, motor `recomendar()`) **sin cambios** — el motor `o14_recomendador.php` no se toca. CEDI conservado (los filtros BOD ya lo conservan).

- [ ] **Step 6: `php -l` + smoke por tab**

Run: `php -l api/informe_o14.php` (limpio) + un smoke que pega a b/c/reco (sesión simulada, "BELTRANY SAS") y confirma `ok:true` con filas, cache y `nocache=1`.

- [ ] **Step 7: Commit**

```bash
git add api/informe_o14.php
git commit -m "refactor(o14): tabs b/c/reco leen del cache (o14_cache_base) + ensure cache-first + nocache oraculo"
```

---

## Task 4: Test de paridad cache vs nocache + concurrencia + medición

**Files:**
- Modify: `tests/verificar_o14_cache.php` (+ helpers de sesión simulada, estilo `tests/_task5_paridad.php` de G00).
- Reference: el harness de paridad de G00 y `tests/_endpoint_run.php` (ojo: o14 hace `session_start()` → arrancar la sesión ANTES de setear `$_SESSION`, o el endpoint la resetea).

**Interfaces:** consume el endpoint (cache y `?nocache=1`) por tab.

- [ ] **Step 1: Paridad por tab × proveedores × filtros**

Para `["BELTRANY SAS","BRAHMA CONCEPT", <un 3º con datos verificados>]` × tabs `[b,c,reco]` × filtros `[{}, {marca:una}, {grupo:uno}, {color:uno}]`: capturar la salida de `?tab=X` (cache) vs `?tab=X&nocache=1` (vivo) vía sesión simulada y comparar los cuadros (normalizando orden/redondeo). Deben ser idénticas. **Guarda de no-vacuidad**: si ambos lados son vacíos, marcar el combo `EMPTY/SKIPPED` (no contar como paridad genuina) — verificar que cada proveedor/filtro tenga datos reales (lección DISANDINA de G00). Imprimir volumen por combo.

- [ ] **Step 2: Correr paridad**

Run: `php -d display_startup_errors=0 tests/verificar_o14_cache.php --paridad` → Expected: `PARIDAD O14 OK` (0 diffs en combos no-vacíos), exit 0.

- [ ] **Step 3: Concurrencia lector-durante-rebuild (como G00)**

Test 2-procesos (`proc_open`): forzar key K frío, un proceso materializa (lento), otro lee mid-rebuild → el lector nunca sirve un set parcial (bloquea/espera o lee la versión completa previa vía RCSI). Repetir varias veces.

- [ ] **Step 4: Medición antes/después**

Cronometrar (sesión simulada): 1ª carga (cache miss → materialize) y un filtro (cache hit) por tab, proveedor chico y grande. Confirmar filtro cache-hit ~sub-2s y **tab=c grande de ~29s a segundos**.

- [ ] **Step 5: Commit**

```bash
git add tests/verificar_o14_cache.php
git commit -m "test(o14): paridad cache vs nocache (b/c/reco x prov x filtros) + concurrencia + medicion"
```

---

## Task 5: Frontend auto-aplicar (informes/o14.php)

**Files:**
- Modify: `informes/o14.php`
- Reference: el fix de `informes/g00.php` (`g00AutoAplicar` debounced ~400ms cableado al `onChange` de los filtros TomSelect).

**Interfaces:** UI; sin cambios de backend.

- [ ] **Step 1: Verificar el wiring real de filtros→carga de o14**

`grep -n "Aplicar\|onChange\|addEventListener\|o14Load\|o14Render\|TomSelect" informes/o14.php`. Entender cómo se dispara la carga hoy (botón Aplicar, onChange, etc.) — NO asumir que auto-aplica.

- [ ] **Step 2: Agregar auto-aplicar con debounce**

Espejando `informes/g00.php`: agregar `o14AutoAplicar()` con debounce (~400ms, guard contra cascada si aplica) y cablearlo al `onChange` de los filtros de dimensión, para que cambiar un filtro recargue solo (rápido por el cache) sin depender del botón. Conservar el botón como carga inmediata. Fechas siguen manuales (rebuild).

- [ ] **Step 3: `php -l` + nota de E2E**

Run: `php -l informes/o14.php` (limpio). Marcar que el pase visual en navegador (auto-aplica al cambiar filtro, tab=c rápido) lo confirma Rafael (E2E manual, como en G00).

- [ ] **Step 4: Commit**

```bash
git add informes/o14.php
git commit -m "fix(o14): auto-aplicar filtros con debounce (UX instantanea como G00)"
```

---

## Self-review (cobertura del spec)

- Cache denormalizado+indexado en INTEGRACION: Task 1 + 2. ✔
- Materialize (dims pegadas, ADMIN excl, CEDI keep, RAW): Task 2. ✔
- tab=c sin join no-sargable (el cuello): Task 3 Step 4. ✔
- Filtros en lectura (WHERE), CEDI preservado: Task 3 Step 2. ✔
- Oráculo nocache: Task 3 Step 1-2. ✔
- Concurrencia (applock/READPAST/READ COMMITTED): Task 2 + Global Constraints. ✔
- Paridad + no-vacuidad + concurrencia + medición: Task 4. ✔
- Frontend auto-aplicar: Task 5. ✔
- SIESA intacto (solo SELECT; cache en INTEGRACION): Global Constraints. ✔

## Deploy (tras E2E)
- Ejecutar `sql/006_o14_cache.sql` en el RDS de prod.
- Re-sync `api/{informe_o14,lib_o14_cache}.php` + `informes/o14.php` a `plataforma_20_produccion` + copia al servidor de aliados.
- Confiar en la limpieza oportunista de `o14CacheCleanup` (o job por TTL).
