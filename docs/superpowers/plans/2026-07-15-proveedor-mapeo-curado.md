# Mapeo curado usuario→proveedor — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que los ~11 aliados con nombre desalineado dejen de ver informes vacíos, resolviendo `$_SESSION['proveedor']` desde un mapeo curado (`usuarios_portal_aka.proveedor_items`) en vez de la razón social de t202 que no coincide con `ITEMS.PROVEEDOR`.

**Architecture:** Columna curada nueva en `usuarios_portal_aka` + un "paso 0" en `login_resolver_proveedor` que la prioriza (arregla login, prebuild y prewarm de un solo lugar). El resto del resolver (t202 → fallback ITEMS) queda como respaldo. DDL y código son no-ops mientras la columna esté en NULL; la curación "enciende" el fix.

**Tech Stack:** PHP 8 (`sqlsrv`), SQL Server (RDS compartida dev/staging/prod). Sin build/npm.

## Global Constraints

- **RDS COMPARTIDA:** el DDL y los `UPDATE` aplican a dev/staging/prod a la vez. Son inofensivos para el código viejo (no lee `proveedor_items`). Aplicar con cuidado, verificar cada paso.
- **Prohibido tocar tablas SIESA** (`t***`) y la vista `ITEMS`/`Items_Mat`. Solo se toca `usuarios_portal_aka` (tabla del portal, no SIESA) y `api/lib_login.php`.
- **No romper el fallback:** un usuario SIN `proveedor_items` debe seguir resolviendo igual que hoy (t202 → ITEMS → null).
- Comandos desde la raíz: `C:\xampp\htdocs\plataforma_20`. Tests de login requieren `LOGIN_TEST_DB=1`.
- Match exacto de `nombre_usuario` (usar los valores exactos de la tabla, tal cual el listado del spec).

## File Structure

| Archivo | Responsabilidad | Cambio |
|---------|-----------------|--------|
| `sql/ddl_proveedor_items.sql` | DDL de la columna (registro) | Crear |
| `sql/curacion_proveedor_items.sql` | UPDATEs de curación (registro) | Crear |
| `api/lib_login.php` | Resolver del proveedor | Paso 0: priorizar `proveedor_items` |
| `tests/login_resolver_test.php` | Test del resolver | Añadir caso curado; ajustar casos ahora curados; probar fallback con usuario no-portal |

---

### Task 1: DDL — columna `proveedor_items`

**Files:**
- Create: `sql/ddl_proveedor_items.sql`
- Runner: usar `php` con `sqlsrv` (RDS compartida)

**Interfaces:**
- Produces: columna `INTEGRACION.dbo.usuarios_portal_aka.proveedor_items VARCHAR(120) NULL`.

- [ ] **Step 1: Escribir el DDL idempotente**

Crear `sql/ddl_proveedor_items.sql`:

```sql
-- Columna curada: el valor EXACTO de ITEMS.PROVEEDOR de cada aliado del portal.
-- Idempotente. RDS compartida (dev/staging/prod). Inofensiva para el código viejo.
IF COL_LENGTH('INTEGRACION.dbo.usuarios_portal_aka','proveedor_items') IS NULL
    ALTER TABLE INTEGRACION.dbo.usuarios_portal_aka ADD proveedor_items VARCHAR(120) NULL;
```

- [ ] **Step 2: Aplicar el DDL contra la RDS**

Ejecutar (desde la raíz):
```bash
php -r "require 'conexion/conexion_integracion.php'; \$sql=file_get_contents('sql/ddl_proveedor_items.sql'); \$r=sqlsrv_query(\$dbConnect,\$sql); echo \$r===false?('ERR: '.json_encode(sqlsrv_errors())):'DDL OK'; echo PHP_EOL;" 2>&1 | grep -v -i "warning\|xdebug\|dio_ts\|openssl"
```
Expected: `DDL OK`.

- [ ] **Step 3: Verificar que la columna existe**

Ejecutar:
```bash
php -r "require 'conexion/conexion_integracion.php'; \$s=sqlsrv_query(\$dbConnect,\"SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH FROM INTEGRACION.INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='usuarios_portal_aka' AND COLUMN_NAME='proveedor_items'\"); \$r=sqlsrv_fetch_array(\$s,SQLSRV_FETCH_ASSOC); echo \$r?('OK: '.json_encode(\$r)):'FALTA'; echo PHP_EOL;" 2>&1 | grep -v -i "warning\|xdebug\|dio_ts\|openssl"
```
Expected: `OK: {"COLUMN_NAME":"proveedor_items","DATA_TYPE":"varchar","CHARACTER_MAXIMUM_LENGTH":120}`.

- [ ] **Step 4: Commit**

```bash
git add sql/ddl_proveedor_items.sql
git commit -m "feat(login): DDL columna usuarios_portal_aka.proveedor_items (mapeo curado)"
```

---

### Task 2: Paso 0 en `login_resolver_proveedor` (no-op con columna NULL)

**Files:**
- Modify: `api/lib_login.php` (dentro de `login_resolver_proveedor`, tras `$busqueda = str_replace(...)`)
- Test: `tests/login_resolver_test.php` (SIN cambios en este task; debe seguir pasando)

**Interfaces:**
- Consumes: columna `proveedor_items` (Task 1).
- Produces: `login_resolver_proveedor` devuelve `{proveedor, nit, fuente}` con `fuente='curado'` cuando hay `proveedor_items`; si no, comportamiento actual intacto.

- [ ] **Step 1: Confirmar el baseline (test actual pasa)**

Run: `LOGIN_TEST_DB=1 php tests/login_resolver_test.php`
Expected: termina `RESULTADO: OK (t202 + fallback ITEMS + nulls)`.

- [ ] **Step 2: Añadir el paso 0**

En `api/lib_login.php`, insertar JUSTO DESPUÉS de la línea `$busqueda = str_replace('_', ' ', $usuario);` (antes del comentario `// 1) Maestro de proveedores SIESA`):

```php

    // 0) Nombre canónico CURADO (usuarios_portal_aka.proveedor_items). Prioridad máxima:
    //    los informes filtran ITEMS.PROVEEDOR por match exacto, y la razón social de t202
    //    a veces no coincide (ej. 'BRAHMA CONCEPT S A S' vs 'BRAHMA CONCEPT'). Si el aliado
    //    tiene proveedor_items curado, ese es el valor exacto de ITEMS.PROVEEDOR.
    //    Se usa $usuario (nombre_usuario exacto), NO $busqueda.
    $sqlCurado = "SELECT TOP 1 RTRIM(proveedor_items) AS prov, RTRIM(link2) AS nit
                  FROM usuarios_portal_aka WHERE nombre_usuario = ?";
    $stCur = sqlsrv_query($conn, $sqlCurado, array($usuario));
    if ($stCur !== false) {
        $rowCur = sqlsrv_fetch_array($stCur, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stCur);
        if ($rowCur && trim((string)$rowCur['prov']) !== '') {
            return array(
                'proveedor' => trim((string)$rowCur['prov']),
                'nit'       => (!empty($rowCur['nit'])) ? trim((string)$rowCur['nit']) : null,
                'fuente'    => 'curado',
            );
        }
    }
```

- [ ] **Step 3: Verificar que el test SIGUE pasando (paso 0 es no-op con columna NULL)**

Run: `LOGIN_TEST_DB=1 php tests/login_resolver_test.php`
Expected: `RESULTADO: OK (...)`. Como aún nadie tiene `proveedor_items` (Task 1 solo creó la columna, en NULL), el paso 0 cae siempre → Beltrany sigue `t202`, Intertenis sigue `items`. Esto PRUEBA que el paso 0 no rompe el fallback.

- [ ] **Step 4: Commit**

```bash
git add api/lib_login.php
git commit -m "feat(login): paso 0 en login_resolver_proveedor prioriza proveedor_items curado"
```

---

### Task 3: Curación de datos + actualizar el test

**Files:**
- Create: `sql/curacion_proveedor_items.sql`
- Modify: `tests/login_resolver_test.php`

**Interfaces:**
- Consumes: columna + paso 0 (Tasks 1-2).
- Produces: `proveedor_items` poblado para los 21 usuarios-aliado; resolver devuelve `fuente='curado'` para ellos.

- [ ] **Step 1: Escribir los UPDATE de curación**

Crear `sql/curacion_proveedor_items.sql` (valores EXACTOS del spec; `nombre_usuario` tal cual la tabla):

```sql
-- Curación proveedor_items = valor EXACTO de ITEMS.PROVEEDOR por aliado. RDS compartida.
UPDATE usuarios_portal_aka SET proveedor_items='BRAHMA CONCEPT'                  WHERE nombre_usuario='Brahma Concept';
UPDATE usuarios_portal_aka SET proveedor_items='C.I HERMECO'                     WHERE nombre_usuario='C.I Hermeco';
UPDATE usuarios_portal_aka SET proveedor_items='COLOMBIANA DE TENIS SA'          WHERE nombre_usuario='Colombiana de Tenis';
UPDATE usuarios_portal_aka SET proveedor_items='CUEROS VELEZ'                     WHERE nombre_usuario='Cueros Velez';
UPDATE usuarios_portal_aka SET proveedor_items='DISANDINA S.A.'                   WHERE nombre_usuario='Disandina';
UPDATE usuarios_portal_aka SET proveedor_items='DYNAMO DISTRIBUTION S.A'          WHERE nombre_usuario='Dynamo_new_era';
UPDATE usuarios_portal_aka SET proveedor_items='DYNAMO DISTRIBUTION S.A'          WHERE nombre_usuario='Dynamo_vans';
UPDATE usuarios_portal_aka SET proveedor_items='ESTUDIO DE MODA'                  WHERE nombre_usuario='Estudio de Moda';
UPDATE usuarios_portal_aka SET proveedor_items='FASHION FITNESS COLOMBIA S.A.S.' WHERE nombre_usuario='Fashion Fitness Colombia sas';
UPDATE usuarios_portal_aka SET proveedor_items='GUAUTA SHOES'                     WHERE nombre_usuario='Guauta shoes';
UPDATE usuarios_portal_aka SET proveedor_items='KRONOTIME'                        WHERE nombre_usuario='Krono Time Sas';
UPDATE usuarios_portal_aka SET proveedor_items='MC CAWLEYS S.A.S.'               WHERE nombre_usuario='Mc Cawleys';
UPDATE usuarios_portal_aka SET proveedor_items='ULTRA SPORT L&A S.A.S.'          WHERE nombre_usuario='Ultra Sport';
UPDATE usuarios_portal_aka SET proveedor_items='MYL DE COLOMBIA'                  WHERE nombre_usuario='M&L de Colombia';
UPDATE usuarios_portal_aka SET proveedor_items='SHOEMASTERS S.A.S'               WHERE nombre_usuario='Shoes Master';
UPDATE usuarios_portal_aka SET proveedor_items='BELTRANY SAS'                     WHERE nombre_usuario='Beltrany sas';
UPDATE usuarios_portal_aka SET proveedor_items='BH BRANDS SAS'                    WHERE nombre_usuario='BH Brands';
UPDATE usuarios_portal_aka SET proveedor_items='D&E OLAM SAS'                     WHERE nombre_usuario='D&E Olam Sas';
UPDATE usuarios_portal_aka SET proveedor_items='INTERTENIS S.A.S'                WHERE nombre_usuario='Intertenis';
UPDATE usuarios_portal_aka SET proveedor_items='PARANA DISTRIBUCIONES SAS'       WHERE nombre_usuario='Parana';
UPDATE usuarios_portal_aka SET proveedor_items='PLANETA SPORT 6 SAS'             WHERE nombre_usuario='Planeta Sport 6';
```

- [ ] **Step 2: Aplicar la curación y verificar filas afectadas**

Ejecutar:
```bash
php -r "require 'conexion/conexion_integracion.php'; \$sql=file_get_contents('sql/curacion_proveedor_items.sql'); foreach(array_filter(array_map('trim',explode(';',\$sql))) as \$u){ if(stripos(\$u,'UPDATE')!==0) continue; \$r=sqlsrv_query(\$dbConnect,\$u); if(\$r===false){echo 'ERR: '.json_encode(sqlsrv_errors()).PHP_EOL;} else {echo 'ok '.sqlsrv_rows_affected(\$r).' <- '.substr(\$u,strpos(\$u,'WHERE')).PHP_EOL;} }" 2>&1 | grep -v -i "warning\|xdebug\|dio_ts\|openssl"
```
Expected: cada línea `ok 1 <- WHERE nombre_usuario='...'` (1 fila afectada por UPDATE; 21 en total). Si alguno da `ok 0`, el `nombre_usuario` no coincide exacto → revisar el valor contra la tabla.

- [ ] **Step 3: Verificación a nivel dato — un aliado antes roto ahora tiene #refs > 0**

Ejecutar (reproduce el camino real del informe para BRAHMA vía el resolver):
```bash
php -r "require 'conexion/conexion_integracion.php'; require 'api/lib_login.php'; require 'api/lib_refs.php'; \$p=login_resolver_proveedor(\$dbConnect,'Brahma Concept'); echo 'resuelto='.json_encode(\$p).PHP_EOL; buildRefsFromMat(\$dbConnect,\$p['proveedor']); \$s=sqlsrv_query(\$dbConnect,'SELECT COUNT(*) n FROM #refs'); \$r=sqlsrv_fetch_array(\$s); echo '#refs='.\$r['n'].PHP_EOL;" 2>&1 | grep -v -i "warning\|xdebug\|dio_ts\|openssl"
```
Expected: `resuelto={"proveedor":"BRAHMA CONCEPT","nit":...,"fuente":"curado"}` y `#refs=` un número **> 0** (antes daba 0). Esto prueba el fix end-to-end a nivel de datos.

- [ ] **Step 4: Actualizar el test del resolver (ahora Beltrany/Intertenis son 'curado')**

En `tests/login_resolver_test.php`, reemplazar los Casos 1 y 2 (líneas 15-25) por:

```php
// Caso 1 — CURADO: aliado con proveedor_items → fuente 'curado', nombre exacto de ITEMS.
$r = login_resolver_proveedor($dbConnect, 'Brahma Concept');
chk($r['proveedor'] === 'BRAHMA CONCEPT', "Brahma Concept curado → 'BRAHMA CONCEPT' (got ".var_export($r['proveedor'],true).")");
chk($r['fuente'] === 'curado', "Brahma Concept debe resolverse por curado (got ".var_export($r['fuente'],true).")");

// Caso 2 — CURADO con NIT: Beltrany tiene proveedor_items + link2(NIT).
$b = login_resolver_proveedor($dbConnect, 'Beltrany sas');
chk($b['proveedor'] === 'BELTRANY SAS', "Beltrany curado → 'BELTRANY SAS' (got ".var_export($b['proveedor'],true).")");
chk($b['fuente'] === 'curado', "Beltrany debe resolverse por curado (got ".var_export($b['fuente'],true).")");
chk($b['nit'] === '901038888', "Beltrany NIT 901038888 desde link2 (got ".var_export($b['nit'],true).")");

// Caso 2b — FALLBACK intacto: un nombre que NO es usuario del portal pero SÍ está en el
// maestro t202 cía 7 → debe resolver por t202 (prueba que el paso 0 no rompe el fallback).
$f = login_resolver_proveedor($dbConnect, 'BELTRANY');
chk($f['fuente'] === 't202', "'BELTRANY' (no-usuario-portal) debe caer a t202 (got ".var_export($f['fuente'],true).")");
```

(Casos 3 y 4 —claves siempre presentes; usuario inexistente → null— quedan igual.)

- [ ] **Step 5: Correr el test actualizado**

Run: `LOGIN_TEST_DB=1 php tests/login_resolver_test.php`
Expected: `RESULTADO: OK (...)`, sin `FALLO`.

- [ ] **Step 6: Commit**

```bash
git add sql/curacion_proveedor_items.sql tests/login_resolver_test.php
git commit -m "feat(login): curar proveedor_items de 21 aliados + test del paso curado y fallback"
```

---

### Task 4: Despliegue de código + verificación en prod

**Files:** ninguno (ops). El DDL + curación YA aplicaron a la RDS compartida en Tasks 1/3.

**Interfaces:** ninguno.

- [ ] **Step 1: Llevar el código a staging y prod**

`api/lib_login.php` y `tests/login_resolver_test.php` a `main` (ya commiteados) → re-sync a `plataforma_20_produccion` → copiar a `E:\WMS\www\plataforma_20` (mismo flujo que evol). Verificar por hash `lib_login.php` main vs staging vs prod.

- [ ] **Step 2: Re-correr el prebuild en WMS-LAB y confirmar que los aliados curados calientan**

En WMS-LAB (RDP): `schtasks /Run /TN "Plataforma20 Prebuild Caches"`, esperar, luego revisar el log:
```powershell
Get-Content "E:\WMS\www\plataforma_20\sql\prebuild_all.log" -Tail 20
```
Expected: los aliados antes en `o14c=failed` (BRAHMA, HERMECO, VELEZ, DISANDINA, ESTUDIO DE MODA, FASHION FITNESS, GUAUTA, KRONO, MC CAWLEYS, ULTRA SPORT, COLOMBIANA DE TENIS) ahora en `o14c=warmed`. Conteo `o14c_*.json.gz` sube hacia ~17.

- [ ] **Step 3: Verificación en navegador (Rafael)**

Entrar al portal como un aliado antes roto (ej. Brahma) → los informes muestran datos (no vacíos).

## Self-Review (cobertura del spec)

- Spec §1 (DDL) → Task 1. ✓
- Spec §2 (código paso 0) → Task 2. ✓
- Spec §3 (curación, 21 filas) → Task 3 Steps 1-2. ✓
- Spec §Pruebas (reproducción/después/regresión/e2e) → Task 3 Steps 3-5 (#refs>0, test curado + fallback) + Task 4 Step 2. ✓
- Spec §Despliegue → Task 4. ✓
- Spec §Fuera de alcance (Everlast/Maison/internos sin curar; NIT aparte) → respetado (no se curan). ✓
