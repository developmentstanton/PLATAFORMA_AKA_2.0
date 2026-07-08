# EVOL Filtrado Rápido (cache de #base denormalizado) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que filtrar EVOL sea instantáneo y que la carga baje del build lento (~11-40s) a segundos, cacheando el `#base` granular denormalizado (dims #refs+Bodegas) en INTEGRACION por (proveedor, rango-de-meses) y re-agregando la matriz negocio×mes sobre el cache.

**Architecture:** Cache-first (como o14/G00). Cada request: (1) asegura un cache fresco del `#base` para `(proveedor, desdeMes, hastaMes)` en `INTEGRACION.dbo.evol_cache_base` — materializa en DOS PASOS si falta/stale (build `#evolmat` temp + INSERT al cache); (2) agrega/pivota tab=data leyendo del cache con los filtros en el WHERE. Flag `?nocache=1` conserva el camino vivo como oráculo.

**Tech Stack:** PHP 8 + sqlsrv (SQL Server / RDS INTEGRACION), tabla de cache persistente. Reusa `api/lib_o14_cache.php` (concurrencia + patrón dos-pasos).

## Global Constraints

- **PROHIBIDO modificar tablas SIESA** (`stanton.dbo.t***`). Solo SELECT. Estructura nueva en `INTEGRACION`.
- **Paridad EXACTA** con la salida actual de tab=data: la agregación/pivot NO cambia, solo su fuente (cache en vez de `#base`). Test de paridad (cache vs `?nocache=1`) lo blinda.
- **Denormalizar RAW:** dims `grupo/nombre` (Bodegas) y `marca/tipo/categoria/subcategoria/genero/publico_objetivo` (#refs) tal cual; ISNULL se re-aplica en lectura EXACTO como hoy.
- **ADMIN/CEDI:** excluir bodegas `GRUPO='ADMINISTRATIVAS'` (excepto `bodega='CEDI'`), replicando el DELETE de `informe_evol.php:147-153`. CEDI conservado.
- **Materialize en DOS PASOS** (build `#evolmat` temp con las fuentes → INSERT al cache desde el temp + dims). NO un solo `INSERT...SELECT` a la tabla persistente (plan patológico, lección o14).
- **Concurrencia (reusa o14):** `sp_getapplock` Exclusive/Transaction (recurso `'evolcache:'.$key`) + double-checked freshness; freshness gate `READPAST`; reads del cache en READ COMMITTED (sin NOLOCK — RCSI ON).
- **`cache_key` = hash de `(proveedor, desdeMes, hastaMes)`**; los filtros NO entran en la key.
- `php -l` limpio; `sqlsrv_free_stmt` tras cada statement.

## File Structure

- **Create `sql/007_evol_cache.sql`** — DDL de `INTEGRACION.dbo.evol_cache_base` + índice. (Se ejecuta a mano en el RDS.)
- **Create `api/lib_evol_cache.php`** — `evolCacheKey(...)`, `ensureEvolCacheBase($conn,$key,$desdeMes,$hastaMes)`, `evolCacheFresco(...)`, `evolCacheCleanup($conn)`. Materialize dos-pasos + TTL + concurrencia. Espeja `api/lib_o14_cache.php`.
- **Modify `api/informe_evol.php`** — tras `#refs`: computar key + ensure (salvo nocache). tab=data agrega `FROM evol_cache_base WHERE cache_key=? [+filtros]`. Flag `?nocache=1` conserva el camino vivo. Filtros REF/negocio/BOD pasan de DELETE a WHERE.
- **Create `tests/verificar_evol_cache.php`** (+ helpers) — smoke + paridad cache vs nocache + concurrencia.
- **Modify `informes/evol.php`** — auto-aplicar filtros con debounce (como `informes/o14.php`).

## Contrato de columnas del cache

`evol_cache_base`: `cache_key, negocio, mes, cia, bodega, referencia, color, ventas, compras, stock` (de `#base`) + `marca, tipo, categoria, subcategoria, genero, publico_objetivo` (#refs) + `grupo, nombre` (Bodegas) + `creado`.

---

## Task 1: Tabla de cache en INTEGRACION

**Files:**
- Create: `sql/007_evol_cache.sql`
- Test: verificación manual del DDL (SELECT COUNT).

**Interfaces:**
- Produces: tabla `INTEGRACION.dbo.evol_cache_base` + clustered index en `cache_key`.

- [ ] **Step 1: Escribir el DDL**

Create `sql/007_evol_cache.sql`:

```sql
-- Cache del #base granular de EVOL (denormalizado) por proveedor+rango-de-meses.
IF OBJECT_ID('INTEGRACION.dbo.evol_cache_base') IS NOT NULL DROP TABLE INTEGRACION.dbo.evol_cache_base;
CREATE TABLE INTEGRACION.dbo.evol_cache_base (
    cache_key        VARCHAR(64)  NOT NULL,
    negocio          VARCHAR(120) NULL,
    mes              CHAR(7)      NULL,
    cia              VARCHAR(10)  NULL,
    bodega           VARCHAR(20)  NULL,
    referencia       VARCHAR(50)  NULL,
    color            VARCHAR(40)  NULL,
    ventas           INT          NULL,
    compras          INT          NULL,
    stock            INT          NULL,
    marca            VARCHAR(40)  NULL,
    tipo             VARCHAR(40)  NULL,
    categoria        VARCHAR(60)  NULL,
    subcategoria     VARCHAR(60)  NULL,
    genero           VARCHAR(40)  NULL,
    publico_objetivo VARCHAR(60)  NULL,
    grupo            VARCHAR(40)  NULL,
    nombre           VARCHAR(120) NULL,
    creado           DATETIME2    NOT NULL DEFAULT SYSDATETIME()
);
CREATE CLUSTERED INDEX ix_evolcb_key ON INTEGRACION.dbo.evol_cache_base (cache_key);
```

- [ ] **Step 2: Ejecutar el DDL en el RDS y verificar**

Ejecutar `sql/007_evol_cache.sql` contra INTEGRACION vía el conector PHP (`require conexion/conexion_integracion.php`; `sqlsrv_query($dbConnect, $sqlText)` en un solo batch, sin `GO`; ignorar warnings cosméticos de arranque). Verificar:
Run: `php -d display_startup_errors=0 -r 'require "conexion/conexion_integracion.php"; $s=sqlsrv_query($dbConnect,"SELECT COUNT(*) c FROM INTEGRACION.dbo.evol_cache_base"); var_dump(sqlsrv_fetch_array($s));'`
Expected: `int(0)`.

- [ ] **Step 3: Commit**

```bash
git add sql/007_evol_cache.sql
git commit -m "feat(evol): DDL tabla de cache evol_cache_base en INTEGRACION"
```

---

## Task 2: `lib_evol_cache.php` — key + materialize dos-pasos + TTL/concurrencia

**Files:**
- Create: `api/lib_evol_cache.php`
- Reference: `api/informe_evol.php:15-37` (derivación de rango/cortes desde desdeMes/hastaMes), `:65-153` (build de `#base` con todas las fuentes + DELETE ADMIN); `api/lib_o14_cache.php` (patrón de concurrencia + dos-pasos a copiar).
- Test: `tests/verificar_evol_cache.php` (smoke inicial).

**Interfaces:**
- Consumes: `#refs` construido en la misma conexión (`buildRefsFromMat`).
- Produces:
  - `evolCacheKey($proveedor,$desdeMes,$hastaMes): string` (`substr(md5($proveedor.'|'.$desdeMes.'|'.$hastaMes),0,32)`).
  - `ensureEvolCacheBase($conn,$key,$desdeMes,$hastaMes): bool` — dos pasos bajo applock si no hay cache fresco.
  - `evolCacheFresco($conn,$key): bool` — `SELECT TOP 1 1 ... WITH (READPAST) WHERE cache_key=? AND creado > DATEADD(minute,-TTL,SYSDATETIME())`.
  - `evolCacheCleanup($conn): void`.
  - Constante `EVOL_CACHE_TTL_MIN` (ej. 120).

- [ ] **Step 1: Escribir un smoke test (falla: lib no existe)**

Create `tests/verificar_evol_cache.php` (smoke inicial):

```php
<?php
error_reporting(E_ERROR|E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_evol_cache.php';   // <-- SUT (no existe -> RED)
$prov = $argv[1] ?? 'BELTRANY SAS';
$desdeMes = (date('Y')-1).'-01'; $hastaMes = date('Y-m');
buildRefsFromMat($dbConnect, $prov);
$key = evolCacheKey($prov,$desdeMes,$hastaMes);
$ok  = ensureEvolCacheBase($dbConnect, $key, $desdeMes, $hastaMes);
$st  = sqlsrv_query($dbConnect, "SELECT COUNT(*) c FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?", [$key]);
$n   = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['c'];
echo ($ok && $n>0) ? "SMOKE OK filas=$n key=$key\n" : "SMOKE FAIL ok=".var_export($ok,true)." filas=$n\n";
exit(($ok && $n>0)?0:1);
```

Run: `php -d display_errors=1 tests/verificar_evol_cache.php` → Expected: FAIL `Failed opening required '.../lib_evol_cache.php'`.

- [ ] **Step 2: Implementar `api/lib_evol_cache.php`**

Copiar el ESQUELETO de concurrencia + el patrón DOS-PASOS de `api/lib_o14_cache.php` (freshness gate `READPAST`, `sp_getapplock` Exclusive/Transaction con recurso `'evolcache:'.$key`, double-check, todos los paths liberan el lock con commit/rollback, `sqlsrv_free_stmt` en cada statement, DROP/CREATE de la temp con drop-if-exists). El materialize:

- **Derivar rango/cortes** desde `($desdeMes,$hastaMes)` EXACTO como `informe_evol.php:17-37`: `$meses`, `$cortes`/`$cortesVals` (fin-de-mes de meses pasados), `$incluyeMesActual`, `$desdeF`, `$hastaF`, `$mesActual`. **Leé y copiá esa derivación tal cual.**
- **PASO 1 — build `#evolmat` temp** (misma estructura que `#base`: `negocio varchar(120), mes char(7), cia varchar(10), bodega varchar(20), referencia varchar(50), color varchar(40), ventas int, compras int, stock int`). Reproducir los INSERTs de fuentes EXACTO de `informe_evol.php:70-145` (ventas Detal+Acum `:70-84`; compras mov_inv_actual+historico_mov_inv `:86-103`; stock cortes historico_inventarios+historico_hold `:105-126`; stock vivo mes actual inv_actual+_hold_actual `:127-145`), cada uno `INNER JOIN #refs r`. `#refs` llega SIN podar (universo del proveedor). Luego el **DELETE ADMIN** de `:147-153` sobre `#evolmat` (excluye ADMINISTRATIVAS salvo CEDI).
- **PASO 2 — INSERT al cache** desde `#evolmat` + dims:
```sql
INSERT INTO INTEGRACION.dbo.evol_cache_base (cache_key,negocio,mes,cia,bodega,referencia,color,ventas,compras,stock,marca,tipo,categoria,subcategoria,genero,publico_objetivo,grupo,nombre)
SELECT ?, b.negocio,b.mes,b.cia,b.bodega,b.referencia,b.color,b.ventas,b.compras,b.stock,
       r.MARCA,r.TIPO,r.CATEGORIA,r.SUBCATEGORIA,r.GENERO,r.PUBLICO_OBJETIVO, bo.GRUPO,bo.NOMBRE
FROM #evolmat b
 INNER JOIN #refs r ON r.REFERENCIA = b.referencia
 LEFT  JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD = b.bodega AND RIGHT('000'+rtrim(bo.CIA),3) = b.cia
```
(dims RAW, sin ISNULL). Param del PASO 2: `[$key]`. Los params de PASO 1 son los de las fuentes (fechas/cortes) en su orden original.

> Verificá contra `informe_evol.php:65-153` que cada fuente, sus filtros (`TIPO_DOCTO`, `cia<>`, `COLUMNA1 IN`, `FECHA IN cortes`, etc.) y la normalización de cia coincidan EXACTO. Si diverge, la paridad se rompe.

- [ ] **Step 3: Correr el smoke y verlo pasar**

Run: `php -d display_startup_errors=0 tests/verificar_evol_cache.php "BELTRANY SAS"`
Expected: `SMOKE OK filas=<n>` (n>0), exit 0. `php -l api/lib_evol_cache.php` limpio. Idempotente (2 corridas mismo n).

- [ ] **Step 4: Commit**

```bash
git add api/lib_evol_cache.php tests/verificar_evol_cache.php
git commit -m "feat(evol): lib_evol_cache - key + materialize dos-pasos + TTL/concurrencia"
```

---

## Task 3: Refactor de tab=data para leer del cache + wiring + nocache + filtros en lectura

**Files:**
- Modify: `api/informe_evol.php`
- Reference: build actual `:52-173`; agregación/pivot de tab=data `:175-296`; `api/informe_o14.php` (patrón de las dos ramas cache/nocache).

**Interfaces:**
- Consumes: `evolCacheKey`, `ensureEvolCacheBase`, `evolCacheCleanup`.
- Produces: endpoint EVOL cache-first; salida idéntica; flag `?nocache=1`.

- [ ] **Step 1: Flag nocache + wiring del ensure (tras #refs)**

Agregar `$nocache = !empty($_GET['nocache']);`. Tras `buildRefsFromMat` (`:53`), cuando `!$nocache`:
```php
require_once __DIR__ . '/lib_evol_cache.php';
$ekey = evolCacheKey($proveedor,$desdeMes,$hastaMes);
if (!ensureEvolCacheBase($dbConnect,$ekey,$desdeMes,$hastaMes)) jsonFail(['error'=>sqlsrv_errors()], $dbConnect);
evolCacheCleanup($dbConnect);
```
Cuando `$nocache`, se conserva el camino actual (poda #refs + build #base + DELETE ADMIN + filtros por DELETE).

- [ ] **Step 2: Camino nocache = código vivo actual; camino cache = ensure + WHERE**

Envolver en `if (!$nocache) { ... }` toda la poda de `#refs` por REF (`:56-62`), el build de `#base` (`:65-145`), el DELETE ADMIN (`:147-153`) y los filtros por DELETE (`:156-173`). En modo cache NO se construye `#base`. Construir un helper `$whereFiltros`/`$paramsFiltros` (columnas del cache prefijo `c.`) a partir de `$_GET`:
- REF: `AND c.<lower(col)> IN (?,...)` para marca/tipo/categoria/subcategoria/genero/publico/referencia.
- Negocio: `AND c.negocio IN (?,...)`.
- BOD: `AND (c.bodega='CEDI' OR ISNULL(c.<lower(col)>,'') IN (?,...))` para grupo/tienda(→`nombre`), conservando CEDI igual que `:166-170`.

- [ ] **Step 3: Portar tab=data al cache**

En modo cache, la query de agregación/pivot de tab=data (`:175-296`, negocio×mes con ventas/compras/stock) lee `FROM INTEGRACION.dbo.evol_cache_base c WHERE c.cache_key=? $whereFiltros` (READ COMMITTED, sin NOLOCK) con el MISMO GROUP BY/pivot. Params: `[$ekey, ...$paramsFiltros]`. La lógica de ensamblado del pivot en PHP (`$meses`, la matriz) NO cambia. En modo `$nocache`, la fuente sigue siendo `#base`.

- [ ] **Step 4: `php -l` + smoke por modo**

Run: `php -l api/informe_evol.php` (limpio) + un smoke (sesión simulada "BELTRANY SAS") que pega a tab=data cache y `nocache=1` y confirma `ok:true` con filas, y que un headline (una celda negocio-mes, un total) coincide cache vs nocache tras rebuild fresco.
(Ojo sesión: evol hace `session_start()` → el harness arranca la sesión ANTES de setear `$_SESSION`.)

- [ ] **Step 5: Commit**

```bash
git add api/informe_evol.php
git commit -m "refactor(evol): tab=data lee del cache (evol_cache_base) + ensure cache-first + nocache oraculo"
```

---

## Task 4: Test de paridad cache vs nocache + concurrencia + medición

**Files:**
- Modify: `tests/verificar_evol_cache.php` (+ helper de sesión simulada, estilo o14; ojo `session_start()` antes de `$_SESSION`).
- Reference: el harness de paridad de o14 (`tests/_task4_paridad_o14.php`).

- [ ] **Step 1: Paridad por proveedor × filtros**

Para `["BELTRANY SAS","BRAHMA CONCEPT", <un 3º con datos verificados, ej. DISANDINA S.A.>]` × filtros `[{}, {marca:una}, {grupo:uno}, {negocio:uno}]`: capturar tab=data cache (fresh) vs `nocache=1`, comparar los cuadros deep (normalizando orden/redondeo) → idénticos. **Guarda de no-vacuidad** (lección DISANDINA): combo vacío ambos lados → `EMPTY/SKIPPED`, excluido del conteo genuino. Imprimir volumen por combo.
**Staleness:** casi todo evol es histórico inmutable; solo el stock del mes en curso deriva. Comparar contra cache reciente; si un combo difiere SOLO en stock del mes actual, re-materializar (como o14). Diferencia estructural persistente = fallo real → STOP y reportar.

- [ ] **Step 2: Correr paridad**

Run: `php -d display_startup_errors=0 tests/verificar_evol_cache.php --paridad` → Expected: `PARIDAD EVOL OK` (0 diffs en combos no-vacíos), exit 0.

- [ ] **Step 3: Concurrencia + medición**

Test 2-procesos lector-durante-rebuild (como o14). Medición: 1ª carga (materialize) vs filtro (cache-hit) para proveedor chico y grande; confirmar filtro cache-hit rápido (~sub-2s) y 1ª carga en segundos (no ~40s).

- [ ] **Step 4: Commit**

```bash
git add tests/verificar_evol_cache.php
git commit -m "test(evol): paridad cache vs nocache + concurrencia + medicion"
```

---

## Task 5: Frontend auto-aplicar (informes/evol.php)

**Files:**
- Modify: `informes/evol.php`
- Reference: el fix de `informes/o14.php` / `informes/g00.php` (`*AutoAplicar` debounced ~400ms cableado al `onChange` de los filtros).

- [ ] **Step 1: Verificar el wiring real de filtros→carga**

`grep -n "Aplicar\|onChange\|addEventListener\|evolLoad\|evolRender\|TomSelect\|cascadeBusy\|Load()" informes/evol.php`. Entender cómo se dispara la carga hoy (botón Aplicar, onChange, etc.) — NO asumir.

- [ ] **Step 2: Agregar auto-aplicar con debounce**

Espejando `informes/o14.php`: agregar `evolAutoAplicar()` con debounce (~400ms, guard contra cascada si existe) cableado al `onChange` de los filtros de dimensión, para que cambiar un filtro recargue solo (rápido por el cache) sin depender del botón. Conservar el botón como carga inmediata. Cambios de rango de meses = manual. Si el wiring difiere de o14, adaptar y describir; si ya auto-aplica, reportarlo (no forzar cambio redundante).

- [ ] **Step 3: `php -l` + nota de E2E**

Run: `php -l informes/evol.php` (limpio). Marcar que el pase visual en navegador (auto-aplica + filtrado rápido) lo confirma Rafael (E2E manual).

- [ ] **Step 4: Commit**

```bash
git add informes/evol.php
git commit -m "fix(evol): auto-aplicar filtros con debounce (UX instantanea como o14/G00)"
```

---

## Self-review (cobertura del spec)

- Cache denormalizado en INTEGRACION: Task 1 + 2. ✔
- Materialize DOS PASOS (evita plan patológico): Task 2. ✔
- Dims RAW, ADMIN excl/CEDI keep: Task 2. ✔
- tab=data lee del cache + filtros en lectura + CEDI: Task 3. ✔
- Oráculo nocache: Task 3. ✔
- Concurrencia (applock/READPAST/READ COMMITTED): Task 2 + Global Constraints. ✔
- Paridad + no-vacuidad + staleness + concurrencia + medición: Task 4. ✔
- Frontend auto-aplicar: Task 5. ✔
- SIESA intacto: Global Constraints. ✔

## Deploy (tras E2E)
- Ejecutar `sql/007_evol_cache.sql` en el RDS de prod.
- Re-sync `api/{informe_evol,lib_evol_cache}.php` + `informes/evol.php` a `plataforma_20_produccion` + servidor de aliados.
