# Rendimiento: materializar dimensión de producto (Sub-proyecto C) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar el cuello de botella de rendimiento (vista `ITEMS` de 17 JOINs + build de `#refs` por lotes, ~218 s en el peor caso) materializando la dimensión de producto en una tabla indexada `dbo.Items_Mat` refrescada de noche, y construyendo `#refs` con una sola sentencia server-side que la lee.

**Architecture:** (1) DDL/proc/job SQL (autorados aquí, **ejecutados por Rafael en SSMS** — Claude no corre DDL en prod). (2) Rewrite PHP centralizado: nueva `buildRefsFromMat($conn,$proveedor)` en `lib_refs.php` que hace `CREATE #refs` + `INSERT ... SELECT FROM Items_Mat WHERE PROVEEDOR=?`, con fallback al camino viejo si la tabla no existe; los 5 endpoints la usan. La estructura de `#refs` no cambia → las queries de agregación quedan intactas. (3) Script de verificación read-only de paridad + tiempos, para correr post-deploy.

**Tech Stack:** SQL Server (T-SQL, SQL Agent), PHP + driver `sqlsrv`, JavaScript vanilla (no build). Sin framework de tests JS/PHP (verificación estática `php -l` + script CLI de paridad).

## Global Constraints

- **Claude NO ejecuta DDL/escrituras contra producción.** Las tablas/proc/job los corre Rafael en SSMS desde scripts versionados en `plataforma_20/sql/`.
- **Paridad exacta:** `Items_Mat` debe reproducir columna a columna el `SELECT` de `getRefsCached` (`lib_refs.php:13-18`), incluidos los mismos `ISNULL(...)`: `ISNULL(MARCA,'SIN MARCA')`, `ISNULL(TIPO,'SIN TIPO')`, `ISNULL(LINEA,'SIN LINEA')`, `ISNULL(SUBLINEA,'')`, `ISNULL(CATEGORIA,'')`, `ISNULL(SUBCATEGORIA,'')`, `ISNULL(GENERO,'')`, `ISNULL(PUBLICO_OBJETIVO,'')`.
- **Estructura de `#refs` INTACTA** (mismas columnas/tipos que hoy): `REFERENCIA varchar(50) PK, MARCA varchar(40), TIPO varchar(40), LINEA varchar(40), SUBLINEA varchar(40), CATEGORIA varchar(40), SUBCATEGORIA varchar(60), GENERO varchar(40), PUBLICO_OBJETIVO varchar(60)`. Las queries de agregación de los informes NO se tocan.
- **Gotcha sqlsrv:** `CREATE TABLE #refs` va **sin parámetros** (scope de sesión); el `INSERT ... SELECT ... WHERE PROVEEDOR = ?` con parámetro va después.
- **NOLOCK** en toda lectura de tablas grandes (patrón del proyecto).
- **Fallback de transición:** `buildRefsFromMat` cae al camino viejo (`getRefsCached`+`buildRefsTemp`) si `OBJECT_ID('INTEGRACION.dbo.Items_Mat')` es NULL.
- **Sin framework de tests:** verificación estática (`php -l`) + script CLI de paridad read-only (corre post-deploy de la BD).
- **Fuera de alcance:** columnstore / agregaciones de hechos (C2 aparte).

---

### Task 1: Scripts SQL (tabla materializada + proc de refresh + job)

**Files:**
- Create: `C:\xampp\htdocs\plataforma_20\sql\002_items_mat.sql`
- Create: `C:\xampp\htdocs\plataforma_20\sql\003_usp_refresh_items_mat.sql`
- Create: `C:\xampp\htdocs\plataforma_20\sql\004_job_items_mat.sql`

**Interfaces:**
- Produces (para Task 2/3): tabla `INTEGRACION.dbo.Items_Mat (PROVEEDOR, REFERENCIA, MARCA, TIPO, LINEA, SUBLINEA, CATEGORIA, SUBCATEGORIA, GENERO, PUBLICO_OBJETIVO)` con índice clustered `(PROVEEDOR, REFERENCIA)`; proc `dbo.usp_Refresh_Items_Mat`.

**Nota:** estos scripts NO se ejecutan como parte de la implementación automatizada (Claude no toca prod). El entregable son los scripts revisados; Rafael los corre en SSMS. La "verificación" es revisión estática de correctitud/paridad/seguridad.

- [ ] **Step 1: `sql/002_items_mat.sql` — tabla + índice**

```sql
-- Dimensión de producto materializada (snapshot de la vista ITEMS, denormalizada).
-- Paridad exacta con el SELECT de getRefsCached (lib_refs.php). Refrescada de noche por usp_Refresh_Items_Mat.
IF OBJECT_ID('INTEGRACION.dbo.Items_Mat') IS NULL
BEGIN
    CREATE TABLE INTEGRACION.dbo.Items_Mat (
        PROVEEDOR        varchar(100) NULL,
        REFERENCIA       varchar(50)  NOT NULL,
        MARCA            varchar(40)  NULL,
        TIPO             varchar(40)  NULL,
        LINEA            varchar(40)  NULL,
        SUBLINEA         varchar(40)  NULL,
        CATEGORIA        varchar(40)  NULL,
        SUBCATEGORIA     varchar(60)  NULL,
        GENERO           varchar(40)  NULL,
        PUBLICO_OBJETIVO varchar(60)  NULL
    );
    CREATE CLUSTERED INDEX CIX_Items_Mat_prov_ref
        ON INTEGRACION.dbo.Items_Mat (PROVEEDOR, REFERENCIA);
END;
```

(Nota para Rafael: `PROVEEDOR varchar(100)` es una suposición segura; si `ITEMS.PROVEEDOR` tiene otro tamaño/colación, ajústalo para que coincida.)

- [ ] **Step 2: `sql/003_usp_refresh_items_mat.sql` — proc de refresh (staging + swap)**

```sql
CREATE OR ALTER PROCEDURE dbo.usp_Refresh_Items_Mat
AS
BEGIN
    SET NOCOUNT ON;

    -- 1) Staging fresca (la parte lenta: leer la vista ITEMS). NO toca la tabla viva.
    IF OBJECT_ID('INTEGRACION.dbo.Items_Mat_stg') IS NOT NULL
        DROP TABLE INTEGRACION.dbo.Items_Mat_stg;
    CREATE TABLE INTEGRACION.dbo.Items_Mat_stg (
        PROVEEDOR        varchar(100) NULL,
        REFERENCIA       varchar(50)  NOT NULL,
        MARCA            varchar(40)  NULL,
        TIPO             varchar(40)  NULL,
        LINEA            varchar(40)  NULL,
        SUBLINEA         varchar(40)  NULL,
        CATEGORIA        varchar(40)  NULL,
        SUBCATEGORIA     varchar(60)  NULL,
        GENERO           varchar(40)  NULL,
        PUBLICO_OBJETIVO varchar(60)  NULL
    );

    INSERT INTO INTEGRACION.dbo.Items_Mat_stg
        (PROVEEDOR, REFERENCIA, MARCA, TIPO, LINEA, SUBLINEA, CATEGORIA, SUBCATEGORIA, GENERO, PUBLICO_OBJETIVO)
    SELECT PROVEEDOR, REFERENCIA,
        ISNULL(MARCA,'SIN MARCA'), ISNULL(TIPO,'SIN TIPO'), ISNULL(LINEA,'SIN LINEA'), ISNULL(SUBLINEA,''),
        ISNULL(CATEGORIA,''), ISNULL(SUBCATEGORIA,''), ISNULL(GENERO,''), ISNULL(PUBLICO_OBJETIVO,'')
    FROM INTEGRACION.dbo.ITEMS WITH (NOLOCK);

    CREATE CLUSTERED INDEX CIX_Items_Mat_prov_ref
        ON INTEGRACION.dbo.Items_Mat_stg (PROVEEDOR, REFERENCIA);

    -- 2) Swap atómico (rápido). La tabla viva sirve el dataset anterior hasta este instante.
    BEGIN TRAN;
        IF OBJECT_ID('INTEGRACION.dbo.Items_Mat') IS NOT NULL
            DROP TABLE INTEGRACION.dbo.Items_Mat;
        EXEC sp_rename 'INTEGRACION.dbo.Items_Mat_stg', 'Items_Mat';
    COMMIT;
END;
```

(Nota para Rafael: el índice de `Items_Mat_stg` queda con su nombre tras el rename; es cosmético. Si prefieres otro patrón de swap — p.ej. `ALTER TABLE ... SWITCH` con particiones, o rename del índice — ajústalo en SSMS. La ventana entre `DROP`/`sp_rename` dentro del `TRAN` es de milisegundos; el fallback del PHP y el manejo de errores existente la cubren.)

- [ ] **Step 3: `sql/004_job_items_mat.sql` — job de SQL Agent (nocturno)**

```sql
-- Job de SQL Server Agent que ejecuta usp_Refresh_Items_Mat cada noche (~03:00).
-- Ejecutar en la BD msdb. Ajusta @owner_login_name y la hora según tu entorno.
USE msdb;
GO
IF EXISTS (SELECT 1 FROM msdb.dbo.sysjobs WHERE name = N'Refresh_Items_Mat')
    EXEC sp_delete_job @job_name = N'Refresh_Items_Mat';
GO
EXEC sp_add_job @job_name = N'Refresh_Items_Mat';
EXEC sp_add_jobstep @job_name = N'Refresh_Items_Mat',
    @step_name = N'Ejecutar usp_Refresh_Items_Mat',
    @subsystem = N'TSQL',
    @database_name = N'INTEGRACION',
    @command = N'EXEC dbo.usp_Refresh_Items_Mat;';
EXEC sp_add_schedule @schedule_name = N'Nightly_0300',
    @freq_type = 4,            -- diario
    @freq_interval = 1,
    @active_start_time = 30000; -- 03:00:00
EXEC sp_attach_schedule @job_name = N'Refresh_Items_Mat', @schedule_name = N'Nightly_0300';
EXEC sp_add_jobserver @job_name = N'Refresh_Items_Mat';
GO
```

- [ ] **Step 4: Revisión estática (no se ejecuta contra prod)**

Releer los 3 scripts y confirmar: (a) columnas y `ISNULL`s de `Items_Mat`/staging **idénticos** al SELECT de `getRefsCached` (`lib_refs.php:13-18`) salvo el `PROVEEDOR` agregado; (b) solo DDL/proc/job — ninguna sentencia destructiva fuera del swap controlado; (c) el índice clustered `(PROVEEDOR, REFERENCIA)` está en la tabla final. No hay `php -l` aquí (es SQL). Dejar constancia en el reporte de que Rafael debe ejecutarlos en SSMS y poblar con `EXEC dbo.usp_Refresh_Items_Mat;` antes de usar el camino nuevo.

- [ ] **Step 5: Commit**

```bash
git add sql/002_items_mat.sql sql/003_usp_refresh_items_mat.sql sql/004_job_items_mat.sql
git commit -m "feat(sql): Items_Mat (dim producto materializada) + proc refresh + job SQL Agent"
```

---

### Task 2: Rewrite PHP — `buildRefsFromMat` + call-sites (con fallback)

**Files:**
- Modify: `C:\xampp\htdocs\plataforma_20\api\lib_refs.php` (agregar `buildRefsFromMat`)
- Modify: `C:\xampp\htdocs\plataforma_20\api\informe_evol.php:53`
- Modify: `C:\xampp\htdocs\plataforma_20\api\informe_o14.php:107`
- Modify: `C:\xampp\htdocs\plataforma_20\api\informe_geo.php:43`
- Modify: `C:\xampp\htdocs\plataforma_20\api\informe_o45.php:39`
- Modify: `C:\xampp\htdocs\plataforma_20\api\informe_g00.php` (call-site 342-344 + `require lib_refs.php`)

**Interfaces:**
- Consumes: `Items_Mat` (Task 1), funciones existentes `getRefsCached`/`buildRefsTemp` (para el fallback).
- Produces: `buildRefsFromMat($conn, $proveedor) -> bool` (true si `#refs` quedó lista).

- [ ] **Step 1: Agregar `buildRefsFromMat` a `lib_refs.php`**

En `api/lib_refs.php`, DESPUÉS del bloque `if (!function_exists('getRefsCached')) { ... }` (tras la línea 48, al final del archivo), agregar — con su **propio** guard `function_exists` para que exista aunque g00 haya definido las otras inline:

```php
if (!function_exists('buildRefsFromMat')) {
    /**
     * Construye #refs leyendo la tabla materializada dbo.Items_Mat (index seek por PROVEEDOR).
     * Reemplaza getRefsCached()+buildRefsTemp(). Si Items_Mat no existe, cae al camino viejo.
     * La estructura de #refs es idéntica a la de buildRefsTemp (queries de agregación intactas).
     */
    function buildRefsFromMat($conn, $proveedor) {
        // Fallback de transición: si la tabla materializada aún no existe, usar el camino viejo.
        $chk = sqlsrv_query($conn, "SELECT OBJECT_ID('INTEGRACION.dbo.Items_Mat') AS oid");
        $existe = false;
        if ($chk !== false) {
            $row = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
            $existe = $row && $row['oid'] !== null;
            sqlsrv_free_stmt($chk);
        }
        if (!$existe) {
            return buildRefsTemp($conn, getRefsCached($conn, $proveedor));
        }
        // Camino nuevo: CREATE #refs (sin params) + INSERT ... SELECT (con param) — respeta gotcha sqlsrv.
        $ok = sqlsrv_query($conn, "CREATE TABLE #refs (
            REFERENCIA varchar(50) NOT NULL PRIMARY KEY,
            MARCA varchar(40), TIPO varchar(40), LINEA varchar(40), SUBLINEA varchar(40),
            CATEGORIA varchar(40), SUBCATEGORIA varchar(60), GENERO varchar(40), PUBLICO_OBJETIVO varchar(60))");
        if ($ok === false) return false;
        sqlsrv_free_stmt($ok);
        $ins = sqlsrv_query($conn,
            "INSERT INTO #refs (REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO)
             SELECT REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO
             FROM INTEGRACION.dbo.Items_Mat WITH (NOLOCK) WHERE PROVEEDOR = ?",
            [$proveedor]);
        if ($ins === false) return false;
        sqlsrv_free_stmt($ins);
        return true;
    }
}
```

- [ ] **Step 2: Actualizar los 4 endpoints que usan `lib_refs.php`**

En cada uno, reemplazar la línea del build de `#refs` por la nueva llamada:

`informe_evol.php:53`, `informe_o14.php:107`, `informe_geo.php:43`, `informe_o45.php:39` — todas hoy dicen:
```php
if (!buildRefsTemp($dbConnect, getRefsCached($dbConnect, $proveedor))) jsonFail(['error'=>sqlsrv_errors()], $dbConnect);
```
Cambiar cada una por:
```php
if (!buildRefsFromMat($dbConnect, $proveedor)) jsonFail(['error'=>sqlsrv_errors()], $dbConnect);
```

- [ ] **Step 3: Actualizar `informe_g00.php` (tiene copias inline; requerir `lib_refs.php` para obtener `buildRefsFromMat`)**

`informe_g00.php` define `getRefsCached` (línea 245) y `buildRefsTemp` (línea 278) inline y NO requiere `lib_refs.php`. Para usar `buildRefsFromMat`:

(a) Agregar el require **después** de las definiciones inline (para que el guard `function_exists` de `lib_refs.php` no intente redefinir `getRefsCached`/`buildRefsTemp` — solo definirá `buildRefsFromMat`). Insertar, justo después de la línea 340 (comentario de separación `// ----`) y antes de la línea 342:
```php
require_once __DIR__ . '/lib_refs.php';
```

(b) Reemplazar el call-site actual (líneas 342-344):
```php
$refsProv = getRefsCached($dbConnect, $proveedor);
if (!buildRefsTemp($dbConnect, $refsProv)) {
    jsonFail(['error' => sqlsrv_errors()], $dbConnect);
}
```
por:
```php
if (!buildRefsFromMat($dbConnect, $proveedor)) {
    jsonFail(['error' => sqlsrv_errors()], $dbConnect);
}
```

Las copias inline `getRefsCached`/`buildRefsTemp` de g00 se **conservan** (sirven de fallback dentro de `buildRefsFromMat`). Dedupe futuro fuera de alcance.

- [ ] **Step 4: Verificación estática**

1. `php -l` en los 6 archivos: `"/c/xampp/php/php.exe" -l api/lib_refs.php api/informe_g00.php api/informe_o14.php api/informe_evol.php api/informe_o45.php api/informe_geo.php` (uno por uno; ignorar warnings preexistentes de xdebug/env). Esperado: "No syntax errors detected" en cada uno.
2. `grep -n "buildRefsFromMat\|buildRefsTemp\|getRefsCached" api/*.php` → confirmar: `buildRefsFromMat` definida en `lib_refs.php` y llamada en los 5 endpoints; el `require_once .../lib_refs.php` presente en g00; ningún endpoint sigue llamando el par viejo directamente en su flujo principal.
3. Confirmar por lectura que el guard `function_exists('buildRefsFromMat')` está separado del guard de `getRefsCached` (para que g00, que define las otras inline, igual obtenga `buildRefsFromMat`).

- [ ] **Step 5: Commit**

```bash
git add api/lib_refs.php api/informe_g00.php api/informe_o14.php api/informe_evol.php api/informe_o45.php api/informe_geo.php
git commit -m "perf(refs): construir #refs desde Items_Mat (buildRefsFromMat) con fallback; 5 endpoints"
```

---

### Task 3: Script de verificación de paridad + tiempos (read-only, post-deploy)

**Files:**
- Create: `C:\xampp\htdocs\plataforma_20\tests\verificar_items_mat.php`

**Interfaces:**
- Consumes: `Items_Mat` (Task 1), `getRefsCached`/`buildRefsTemp`/`buildRefsFromMat` (Task 2).

**Nota:** este script se **ejecuta post-deploy** de la BD (cuando `Items_Mat` ya está poblada). Es read-only salvo por las temp tables de sesión que crea para comparar (no toca tablas reales). El entregable es el script; correrlo es paso manual coordinado con Rafael.

- [ ] **Step 1: Escribir el script de verificación**

```php
<?php
/**
 * Verificación de paridad + rendimiento de Items_Mat (Sub-proyecto C).
 * Read-only sobre tablas reales; usa temp tables de sesión para comparar.
 * Uso: php tests/verificar_items_mat.php "PROVEEDOR 1" "PROVEEDOR 2" ...
 * Compara, por proveedor: (viejo) getRefsCached+buildRefsTemp  vs  (nuevo) buildRefsFromMat.
 */
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
if ($dbConnect === false) { fwrite(STDERR, "Conexión DB fallida\n"); exit(1); }

$proveedores = array_slice($argv, 1);
if (!$proveedores) { fwrite(STDERR, "Uso: php verificar_items_mat.php <prov1> [prov2 ...]\n"); exit(2); }

function refsViejo($conn, $prov) {  // devuelve mapa REFERENCIA => fila normalizada
    $rows = buildRefsTemp($conn, getRefsCached($conn, $prov)) ? leerRefs($conn) : null;
    sqlsrv_query($conn, "IF OBJECT_ID('tempdb..#refs') IS NOT NULL DROP TABLE #refs");
    return $rows;
}
function refsNuevo($conn, $prov) {
    $rows = buildRefsFromMat($conn, $prov) ? leerRefs($conn) : null;
    sqlsrv_query($conn, "IF OBJECT_ID('tempdb..#refs') IS NOT NULL DROP TABLE #refs");
    return $rows;
}
function leerRefs($conn) {
    $st = sqlsrv_query($conn, "SELECT REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO FROM #refs ORDER BY REFERENCIA");
    if ($st === false) return null;
    $m = [];
    while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) { $m[$r['REFERENCIA']] = $r; }
    sqlsrv_free_stmt($st);
    return $m;
}

$fallo = 0;
foreach ($proveedores as $prov) {
    $t0 = microtime(true); $viejo = refsViejo($dbConnect, $prov); $tViejo = round((microtime(true)-$t0)*1000);
    $t1 = microtime(true); $nuevo = refsNuevo($dbConnect, $prov); $tNuevo = round((microtime(true)-$t1)*1000);
    if ($viejo === null || $nuevo === null) { echo "[$prov] ERROR construyendo refs\n"; $fallo++; continue; }
    $igual = ($viejo == $nuevo); // compara claves + valores de cada fila
    printf("[%s] filas viejo=%d nuevo=%d | paridad=%s | viejo=%dms nuevo=%dms\n",
        $prov, count($viejo), count($nuevo), $igual ? 'OK' : 'DIFERENTE', $tViejo, $tNuevo);
    if (!$igual) {
        $fallo++;
        $soloViejo = array_diff_key($viejo, $nuevo); $soloNuevo = array_diff_key($nuevo, $viejo);
        if ($soloViejo) echo "   refs solo en VIEJO: " . implode(',', array_slice(array_keys($soloViejo),0,10)) . "\n";
        if ($soloNuevo) echo "   refs solo en NUEVO: " . implode(',', array_slice(array_keys($soloNuevo),0,10)) . "\n";
    }
}
echo $fallo ? "\nRESULTADO: $fallo proveedor(es) con diferencias.\n" : "\nRESULTADO: paridad total.\n";
exit($fallo ? 1 : 0);
```

- [ ] **Step 2: Verificación estática**

`"/c/xampp/php/php.exe" -l tests/verificar_items_mat.php` → "No syntax errors detected". (No se ejecuta contra prod en este paso; corre post-deploy.)

- [ ] **Step 3: Commit**

```bash
git add tests/verificar_items_mat.php
git commit -m "test(perf): script de verificacion de paridad + tiempos de Items_Mat (read-only)"
```

---

## Despliegue coordinado (post-merge, manual de Rafael)

1. Correr `sql/002` y `sql/003` en SSMS (crear tabla + proc).
2. `EXEC dbo.usp_Refresh_Items_Mat;` para poblar; verificar `SELECT COUNT(*) FROM dbo.Items_Mat` y `SELECT TOP 5 PROVEEDOR, COUNT(*) FROM dbo.Items_Mat GROUP BY PROVEEDOR ORDER BY 2 DESC`.
3. Crear el job (`sql/004`).
4. `php tests/verificar_items_mat.php "<prov grande>" "<prov chico>"` → confirmar **paridad total** y tiempos (build nuevo debe ser sub-segundo / pocos segundos vs cientos de segundos del viejo).
5. Verificación funcional en navegador de los 5 informes (cargan y filtran OK).

## Self-Review

**Spec coverage:**
- Tabla `Items_Mat` + índice clustered (PROVEEDOR,REFERENCIA) → Task 1 Step 1. ✓
- Refresh proc staging+swap + job SQL Agent → Task 1 Steps 2-3. ✓
- `buildRefsFromMat` (CREATE #refs paramless + INSERT...SELECT con param) → Task 2 Step 1. ✓
- Estructura de #refs idéntica → columnas verbatim en Step 1; queries de agregación no tocadas. ✓
- Fallback si Items_Mat no existe → Task 2 Step 1 (OBJECT_ID check). ✓
- Los 5 endpoints usan buildRefsFromMat (incl. g00 con require + inline preservado) → Task 2 Steps 2-3. ✓
- Eliminar cache JSON: el camino nuevo no lo usa; el fallback (viejo) aún lo usa hasta retirar — aceptable en transición (la eliminación total del cache es limpieza posterior, fuera de este alcance mínimo). ✓ (nota: no se borra `getRefsCached`/cache para preservar el fallback.)
- Paridad + tiempos → Task 3 (script) + Despliegue paso 4. ✓
- Claude no corre DDL en prod → Task 1 nota + Despliegue manual. ✓

**Placeholder scan:** sin TBD/TODO; SQL y PHP completos. Las "notas para Rafael" son decisiones de operación de BD explícitas, no placeholders.

**Type consistency:** `buildRefsFromMat($conn,$proveedor)->bool` definida en Task 2 y consumida en Tasks 2/3 con esa firma. Columnas de `Items_Mat`/`#refs`/`getRefsCached` idénticas en Tasks 1-3. Nombres de objetos (`Items_Mat`, `Items_Mat_stg`, `usp_Refresh_Items_Mat`, `CIX_Items_Mat_prov_ref`) consistentes entre scripts.

## Notas de ejecución

- Rama nueva (ej. `feature/rendimiento-items-mat`); merge + push con visto bueno de Rafael. El despliegue de BD (SSMS) y la verificación de paridad/tiempos son pasos manuales de Rafael, coordinados post-merge.
- El cambio PHP es seguro de mergear aun antes del deploy de BD gracias al fallback (`OBJECT_ID` NULL → camino viejo).
- Verificación funcional final: en navegador, los 5 informes; y el script de paridad tras poblar `Items_Mat`.
