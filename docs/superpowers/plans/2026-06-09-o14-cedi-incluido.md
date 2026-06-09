# O14 — Incluir CEDI como grupo normal · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dejar de tratar al CEDI como caso especial en O14: incluirlo en los KPIs y mostrarlo bajo su grupo real `BODEGA` en "Por tienda", con una sola fila TOTAL.

**Architecture:** Quitar las exclusiones `WHERE bodega<>'CEDI'` y el `CASE … 'CEDI'` del backend (`api/informe_o14.php`), y en el frontend (`informes/o14.php`) quitar el skip de CEDI en `kpisFromArbol` y colapsar las dos filas TOTAL a una. El motor de recomendaciones no cambia (identifica el CEDI por `bodega='CEDI'`).

**Tech Stack:** PHP + sqlsrv, JS vanilla, test Node + suites CLI.

**Spec:** `docs/superpowers/specs/2026-06-09-o14-cedi-incluido.md`

---

## Estructura de archivos
- **Modify** `informes/o14.php` — `kpisFromArbol` (quitar skip CEDI) y `renderArbol` (una sola fila TOTAL).
- **Modify** `tests/o14_kpis_arbol_test.mjs` — esperados con CEDI incluido (TDD).
- **Modify** `api/informe_o14.php` — `kpiCounts`, tab b (agg + cnt), tab c (grupo/group by/order), `ensamblarArbol`.

---

## Task 1: Frontend — `kpisFromArbol` incluye CEDI (TDD) + `renderArbol` una sola fila TOTAL

**Files:**
- Modify: `tests/o14_kpis_arbol_test.mjs`
- Modify: `informes/o14.php` (`kpisFromArbol`, `renderArbol`)

- [ ] **Step 1: Actualizar los ESPERADOS del test (sin tocar aún la función copia)**

En `tests/o14_kpis_arbol_test.mjs`, reemplazar el objeto `exp`:
```js
const exp={siembra:5,disponible:2,hold:1,total_stock:3,ventas:4,faltante:2,sobrantes:0,
  negocios:1,negocios_con_siembra:1,tiendas_con_siembra:1,tiendas_con_inv:1,tiendas_con_venta:2};
```
por (ahora el 3er grupo SÍ cuenta):
```js
const exp={siembra:14,disponible:11,hold:1,total_stock:12,ventas:4,faltante:2,sobrantes:0,
  negocios:1,negocios_con_siembra:1,tiendas_con_siembra:2,tiendas_con_inv:2,tiendas_con_venta:2};
```

- [ ] **Step 2: Correr el test y verificar que FALLA**

Run: `node tests/o14_kpis_arbol_test.mjs`
Expected: FALLO — la función del test todavía tiene `if(g.grupo==='CEDI') return;` y el 3er grupo se llama `'CEDI'`, así que lo excluye: `siembra=5` ≠ esperado `14`, etc. `RESULTADO: FALLO`.

- [ ] **Step 3: Quitar el skip de la función copia y renombrar el 3er grupo a 'BODEGA'**

En `tests/o14_kpis_arbol_test.mjs`, en la función `kpisFromArbol`, eliminar la línea:
```js
    if(g.grupo==='CEDI') return;
```
Y en los datos sintéticos, cambiar la etiqueta del 3er grupo de `{grupo:'CEDI',almacenes:[` a `{grupo:'BODEGA',almacenes:[` (refleja que el CEDI real vive en el grupo `BODEGA`).

- [ ] **Step 4: Correr el test y verificar que PASA**

Run: `node tests/o14_kpis_arbol_test.mjs`
Expected: PASS — `RESULTADO: OK`. kpis: siembra 14, disponible 11, hold 1, total_stock 12, ventas 4, faltante 2, sobrantes 0, negocios 1, tiendas_con_siembra 2, tiendas_con_inv 2, tiendas_con_venta 2.

- [ ] **Step 5: Aplicar el cambio a `informes/o14.php` (kpisFromArbol)**

En `informes/o14.php`, en `kpisFromArbol`, eliminar la línea:
```js
      if(g.grupo==='CEDI') return;
```
Y cambiar el comentario de la función:
```js
  // Computa los 10 KPIs desde un árbol grupos[] (excluye CEDI), con las mismas reglas que el backend.
```
por:
```js
  // Computa los 10 KPIs desde un árbol grupos[] (incluye todos los grupos), con las mismas reglas que el backend.
```

- [ ] **Step 6: `renderArbol` — una sola fila TOTAL**

En `informes/o14.php`, dentro de `renderArbol`:

(a) Reemplazar la inicialización de acumuladores:
```js
    const gtot={}; medidas.forEach(m=>gtot[m]={});       // total sin CEDI
    const gtotC={}; medidas.forEach(m=>gtotC[m]={});     // total con CEDI
```
por:
```js
    const gtot={}; medidas.forEach(m=>gtot[m]={});       // total general (incluye todos los grupos)
```

(b) Reemplazar las dos líneas de acumulación dentro del `grupos.forEach`:
```js
      medidas.forEach(m=>{ for(const t in gv[m]) gtotC[m][t]=(gtotC[m][t]||0)+gv[m][t]; });        // con CEDI: todos
      if(gr.grupo!=='CEDI') medidas.forEach(m=>{ for(const t in gv[m]) gtot[m][t]=(gtot[m][t]||0)+gv[m][t]; }); // sin CEDI
```
por:
```js
      medidas.forEach(m=>{ for(const t in gv[m]) gtot[m][t]=(gtot[m][t]||0)+gv[m][t]; });
```

(c) Reemplazar las dos filas de total final:
```js
    h+='<tr class="o14-total"><td class="dim">TOTAL (sin CEDI)</td>'+rowCells(gtot,tallas,medidas,false)+'</tr>';
    h+='<tr class="o14-total"><td class="dim">TOTAL (con CEDI)</td>'+rowCells(gtotC,tallas,medidas,false)+'</tr>';
```
por:
```js
    h+='<tr class="o14-total"><td class="dim">TOTAL</td>'+rowCells(gtot,tallas,medidas,false)+'</tr>';
```

- [ ] **Step 7: Checks estáticos**

Run: `php -l informes/o14.php` → "No syntax errors detected".
Run: `grep -c "gtotC" informes/o14.php` → `0`.
Run: `grep -c "g.grupo==='CEDI'" informes/o14.php` → `0`.
Run: `grep -c "TOTAL (con CEDI)\|TOTAL (sin CEDI)" informes/o14.php` → `0`.
Run: `node tests/o14_kpis_arbol_test.mjs` → `RESULTADO: OK`.

- [ ] **Step 8: Commit**

```bash
git add informes/o14.php tests/o14_kpis_arbol_test.mjs
git commit -m "feat(o14): KPIs/árbol incluyen CEDI; una sola fila TOTAL (frontend) + test"
```

---

## Task 2: Backend — incluir CEDI en KPIs y bajo su grupo real

**Files:**
- Modify: `api/informe_o14.php` (`ensamblarArbol`, `kpiCounts`, tab b, tab c)

- [ ] **Step 1: `ensamblarArbol` — sumar KPIs de todos los grupos**

En `api/informe_o14.php`, reemplazar el docblock + la línea condicional. Cambiar el comentario:
```php
/** Arma la jerarquía grupos→almacenes→negocios desde filas planas de #base+Bodegas.
 *  KPIs de cantidad se acumulan EXCLUYENDO el grupo CEDI. */
```
por:
```php
/** Arma la jerarquía grupos→almacenes→negocios desde filas planas de #base+Bodegas.
 *  KPIs de cantidad se acumulan sobre TODOS los grupos (incluido CEDI). */
```
Y reemplazar:
```php
        if($g!=='CEDI'){ $kpi['siembra']+=$si; $kpi['disponible']+=$di; $kpi['hold']+=$ho; $kpi['ventas']+=$ve; $kpi['sobrantes']+=$sob; $kpi['faltante']+=$fal; }
```
por:
```php
        $kpi['siembra']+=$si; $kpi['disponible']+=$di; $kpi['hold']+=$ho; $kpi['ventas']+=$ve; $kpi['sobrantes']+=$sob; $kpi['faltante']+=$fal;
```

- [ ] **Step 2: `kpiCounts` — quitar exclusión de CEDI**

Reemplazar el comentario y el cuerpo de `kpiCounts`. Cambiar:
```php
/** KPIs de conteo (red, EXCLUYENDO CEDI) desde #base. */
function kpiCounts($c) {
    $r = run($c, "
        SELECT
          (SELECT COUNT(DISTINCT cia+'|'+negocio) FROM #base WHERE bodega<>'CEDI')              negocios,
          (SELECT COUNT(DISTINCT cia+'|'+negocio) FROM #base WHERE bodega<>'CEDI' AND siembra>0) negocios_con_siembra,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE bodega<>'CEDI' AND siembra>0) tiendas_con_siembra,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE bodega<>'CEDI' AND disponible>0) tiendas_con_inv,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE bodega<>'CEDI' AND ventas<>0) tiendas_con_venta");
```
por:
```php
/** KPIs de conteo (red, incluido CEDI) desde #base. */
function kpiCounts($c) {
    $r = run($c, "
        SELECT
          (SELECT COUNT(DISTINCT cia+'|'+negocio) FROM #base)              negocios,
          (SELECT COUNT(DISTINCT cia+'|'+negocio) FROM #base WHERE siembra>0) negocios_con_siembra,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE siembra>0) tiendas_con_siembra,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE disponible>0) tiendas_con_inv,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE ventas<>0) tiendas_con_venta");
```

- [ ] **Step 3: Tab b — quitar exclusión de CEDI del agg y los conteos**

En el bloque `if ($tab === 'b')`, reemplazar:
```php
        FROM #base WHERE bodega <> 'CEDI'
        GROUP BY cia, negocio, referencia, color, talla
```
por:
```php
        FROM #base
        GROUP BY cia, negocio, referencia, color, talla
```
Y reemplazar el bloque `$cnt`:
```php
    $cnt = run($dbConnect, "
        SELECT
          (SELECT COUNT(DISTINCT cia+'|'+negocio) FROM #base WHERE bodega<>'CEDI' AND siembra>0)    negocios_con_siembra,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE bodega<>'CEDI' AND siembra>0)    tiendas_con_siembra,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE bodega<>'CEDI' AND disponible>0) tiendas_con_inv,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE bodega<>'CEDI' AND ventas<>0)    tiendas_con_venta");
```
por:
```php
    $cnt = run($dbConnect, "
        SELECT
          (SELECT COUNT(DISTINCT cia+'|'+negocio) FROM #base WHERE siembra>0)    negocios_con_siembra,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE siembra>0)    tiendas_con_siembra,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE disponible>0) tiendas_con_inv,
          (SELECT COUNT(DISTINCT cia+'-'+bodega)   FROM #base WHERE ventas<>0)    tiendas_con_venta");
```

- [ ] **Step 4: Tab c — usar el grupo real (sin CASE de CEDI)**

Cambiar el comentario de cabecera del bloque:
```php
// TAB C — árbol Grupo → Almacén → Negocio (todos los negocios); CEDI como grupo aparte
```
por:
```php
// TAB C — árbol Grupo → Almacén → Negocio (todos los negocios); CEDI dentro de su grupo real (BODEGA)
```
En la consulta del bloque `if ($tab === 'c')`, reemplazar la línea del SELECT:
```php
          CASE WHEN b.bodega='CEDI' THEN 'CEDI' ELSE ISNULL(bo.GRUPO,'SIN GRUPO') END grupo,
```
por:
```php
          ISNULL(bo.GRUPO,'SIN GRUPO') grupo,
```
Reemplazar el `GROUP BY`:
```php
        GROUP BY (CASE WHEN b.bodega='CEDI' THEN 'CEDI' ELSE ISNULL(bo.GRUPO,'SIN GRUPO') END),
                 b.cia, b.bodega, bo.NOMBRE, b.negocio, b.referencia, b.color, b.talla
```
por:
```php
        GROUP BY ISNULL(bo.GRUPO,'SIN GRUPO'),
                 b.cia, b.bodega, bo.NOMBRE, b.negocio, b.referencia, b.color, b.talla
```
Reemplazar el `ORDER BY`:
```php
        ORDER BY CASE WHEN b.bodega='CEDI' THEN 1 ELSE 0 END,
                 (CASE WHEN b.bodega='CEDI' THEN 'CEDI' ELSE ISNULL(bo.GRUPO,'SIN GRUPO') END),
                 llave, b.negocio");
```
por:
```php
        ORDER BY ISNULL(bo.GRUPO,'SIN GRUPO'), llave, b.negocio");
```

- [ ] **Step 5: Checks estáticos + endpoint**

Run: `php -l api/informe_o14.php` → "No syntax errors detected".
Run: `grep -c "bodega<>'CEDI'\|bodega <> 'CEDI'\|THEN 'CEDI'" api/informe_o14.php` → `0` (ya no queda special-casing de CEDI en las CONSULTAS SQL. El bloque `reco` identifica el CEDI en PHP con `rtrim($r['bodega'])==='CEDI'` — no coincide con ninguno de esos 3 patrones, así que no debe tocarse y no afecta este grep).
Run (negativo, tab c ya no tiene grupo 'CEDI'):
`php tests/_endpoint_run_o14.php "BELTRANY SAS" "tab=c" 2>NUL | grep -c '"grupo":"CEDI"'` → `0`.

- [ ] **Step 6: Commit**

```bash
git add api/informe_o14.php
git commit -m "feat(o14): backend incluye CEDI en KPIs y bajo su grupo real BODEGA (sin special-casing)"
```

---

## Task 3: Regresión + verificación

**Files:** ninguno (corre suites).

- [ ] **Step 1: Suites**

Run: `php tests/o14_recomendador_test.php` → `27 pass, 0 fail`.
Run: `node tests/o14_kpis_arbol_test.mjs` → `RESULTADO: OK`.
Run: `php tests/o14_total_stock_test.php` → `RESULTADO: OK` (total_stock=disp+hold sigue cuadrando, ahora con CEDI incluido).
Run: `php tests/o14_filtros_test.php` → `RESULTADO: OK` (el invariante por negocio se mantiene; los conteos pueden subir).
Run: `php tests/o14_ventas_rango_test.php` → `RESULTADO: OK` (caso 245, no-CEDI; invariante intacto).
Run: `php tests/g00_detal_smoke_test.php` → `RESULTADO: OK`.

- [ ] **Step 2: Verificación E2E navegador (Rafael)** — checklist:
  - Los KPIs **incluyen el CEDI** (números mayores que antes; cuadran con el Power BI viejo).
  - En "Por tienda", el CEDI aparece **bajo el grupo `BODEGA`** (no como grupo `CEDI` separado al final).
  - Hay **una sola fila TOTAL** (ya no "sin CEDI"/"con CEDI").
  - Recomendaciones siguen funcionando igual (sin cambios en este spec).

---

## Notas de ejecución
- Cada test de endpoint escanea `Ventas_Detal_Acum_PBI` (~10-15s) por el default de ventas desde 2025; esperar ~30-60s por test.
- **NO tocar** el bloque `if ($tab === 'reco')`: el motor sigue identificando el CEDI por `bodega='CEDI'` para la cascada de abastecimiento.
- Rama: continuar en `feat/o14-topbar-g00-style` (sin merge).
- Siguiente spec (aparte): recomendaciones generales por filtros (#7).
