# G00 Detal — TDAS + precio promedio en Marca/Tipo y Mensual — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar columnas de tiendas (`Tdas {b}` · `Tdas {a}` · `≠Tdas`) y precio promedio (`$Prom`/`%Prom`) a las tablas "Por Marca / Tipo" y "Mensual" del tab Detal del informe G00, replicando el patrón de la tabla "Por Grupo".

**Architecture:** Marca/Tipo es solo frontend (el endpoint ya devuelve `tiendas_act`/`tiendas_ant` por marca y tipo). Mensual requiere ampliar la query (contar tiendas distinct por mes + total del rango vía GROUPING SETS) y derivar el precio promedio en el cliente. Spec: `docs/superpowers/specs/2026-06-05-g00-detal-tdas-precioprom.md`.

**Tech Stack:** PHP + sqlsrv (SQL Server), JS vanilla en `informes/g00.php`. Tests = arnés CLI que subprocesa el endpoint real (`tests/_endpoint_run.php`).

**Rama:** `feat/g00-tiendas-resumen` (ya activa, working tree limpio).

---

## File Structure

- `api/informe_g00.php` — bloque Mensual (`$sqlMensual`, `$pMensual`, demux, `$labelsMes`, `$serieMensual`, `$out`). Único cambio de backend.
- `informes/g00.php` — `renderTablaMarcaTipo`/`rowMarca`, `renderTablaMensual`/`rowMensual`, y las 2 llamadas en el dispatcher (~líneas 596-597).
- `tests/g00_mensual_test.php` — **nuevo**. Verifica tiendas/mes + total distinct + nombre de mes completo + invariante.

Helpers JS ya existentes y reutilizados (no se crean): `prom(val,ups)`, `pctCell(act,ant)`, `difCell(act,ant,fmt)`, `fmtInt`, `fmtMoneyFull`, `esc`. Clases CSS `pos`/`neg` ya usadas en `rowGrupo`.

---

## Task 1: Backend Mensual — tiendas por mes + total distinct + meses completos

**Files:**
- Test: `tests/g00_mensual_test.php` (create)
- Modify: `api/informe_g00.php` (bloque Mensual, ~líneas 648-700 y `$out` ~765)

- [ ] **Step 1: Write the failing test**

Create `tests/g00_mensual_test.php`:

```php
<?php
// Verifica que el bloque Mensual del tab=detal devuelve tiendas por mes + total distinct,
// nombre de mes completo, y la invariante distinct-global <= suma de distinct por mes.
//   php tests/g00_mensual_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';

function call_ep($php, $runner, $prov, $qs, $nul) {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    return json_decode(($a !== false && $b !== false) ? substr($raw, $a, $b - $a + 1) : $raw, true);
}

$fail = 0;
$d   = call_ep($php, $runner, $prov, 'tab=detal', $nul);
$ok  = $d['ok'] ?? false;
$men = $d['mensual'] ?? [];
$tot = $d['mensual_tdas'] ?? null;
echo "Proveedor: $prov | ok=" . ($ok ? '1' : '0') . " | meses=" . count($men) . "\n";
if (!$ok) { echo "FALLO: " . json_encode($d['detalle'] ?? '(no JSON)') . "\nRESULTADO: FALLO\n"; exit(1); }

$completos = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$sumAct = 0; $nombresOk = true;
foreach ($men as $r) {
    if (!array_key_exists('tiendas_act', $r) || !array_key_exists('tiendas_ant', $r)) {
        echo "FALLO: mes sin tiendas_act/ant\n"; $fail = 1; break;
    }
    $sumAct += (int)$r['tiendas_act'];
    if (!in_array($r['mes'], $completos, true)) $nombresOk = false;
}
if (!$nombresOk) { echo "FALLO: nombre de mes no es completo (" . ($men[0]['mes'] ?? '?') . ")\n"; $fail = 1; }

if (!$tot || !isset($tot['act'], $tot['ant'])) { echo "FALLO: falta mensual_tdas{act,ant}\n"; $fail = 1; }
else {
    echo "Tdas total: act=" . $tot['act'] . " ant=" . $tot['ant'] . " | Sum(tiendas_act/mes)=" . $sumAct . "\n";
    if ((int)$tot['act'] > $sumAct) { echo "FALLO: distinct global > suma por mes (imposible)\n"; $fail = 1; }
}

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK (mensual: tiendas/mes + total distinct + meses completos + invariante)\n";
exit($fail);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/g00_mensual_test.php`
Expected: FALLO — los meses vienen como `Ene`/`Feb` (no completos) y no existe `mensual_tdas` ni `tiendas_act` por mes.

- [ ] **Step 3: Ampliar la query Mensual (`$sqlMensual`)**

En `api/informe_g00.php`, reemplazar el bloque `$sqlMensual = cteVentas() . "..."` (el CTE `vm` + SELECT) por esta versión (agrega `v.BODEGA` al `vm`, `GROUPING_ID(mes)`, 2 columnas `COUNT(DISTINCT...)`, y `GROUP BY GROUPING SETS ((mes),())`):

```php
$sqlMensual = cteVentas() . "
    , vm AS (
        SELECT v.FECHA, v.BODEGA, v.CANTIDAD, v.VALOR,
               CASE WHEN v.FECHA BETWEEN ? AND ? THEN MONTH(v.FECHA) ELSE $mesAntExpr END AS mes
        FROM ventas v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.BODEGA AND b.CIA = 7
        WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
          $filtroExtra
          $sameStoreClause
    )
    SELECT
        GROUPING_ID(mes) AS gid,
        mes,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant,
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? THEN BODEGA END) AS tiendas_act,
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? THEN BODEGA END) AS tiendas_ant
    FROM vm
    GROUP BY GROUPING SETS ((mes), ())
";
```

- [ ] **Step 4: Ampliar los params (`$pMensual`)**

Reemplazar el bloque final de `$pMensual` para agregar los 2 pares de params de tiendas, **después** de ups (mismo orden act,ant):

```php
$pMensual = array_merge(
    [$mensGmin, $mensGmax, $mensGmin, $mensGmax],   // cte pushdown
    [$mensDesA, $mensHasA],                          // vm: CASE mes (rango act)
    [$mensDesA, $mensHasA, $mensDesB, $mensHasB],   // vm WHERE OR-filter (act, ant)
    $paramsExtra,
    $sameStoreParams,
    [$mensDesA, $mensHasA, $mensDesB, $mensHasB,     // val_act / val_ant
     $mensDesA, $mensHasA, $mensDesB, $mensHasB,     // ups_act / ups_ant
     $mensDesA, $mensHasA, $mensDesB, $mensHasB]     // tiendas_act / tiendas_ant
);
```

- [ ] **Step 5: Demux con la fila total (gid=1) + mapa de tiendas**

Reemplazar el bloque del `foreach ($mensual as $r)` (el que llena `$mapMV`/`$mapMU`) por:

```php
$mapMV = []; $mapMU = []; $mapMT = [];
$tdasTotAct = 0; $tdasTotAnt = 0;
foreach ($mensual as $r) {
    if ((int)$r['gid'] === 1) {           // grand total (mes NULL) → distinct del rango
        $tdasTotAct = (int)$r['tiendas_act'];
        $tdasTotAnt = (int)$r['tiendas_ant'];
        continue;
    }
    $mi = (int)$r['mes'];
    if ($mi < 1 || $mi > 12) continue;
    $mapMV[$mi] = ['val_act' => (float)$r['val_act'], 'val_ant' => (float)$r['val_ant']];
    $mapMU[$mi] = ['ups_act' => (float)$r['ups_act'], 'ups_ant' => (float)$r['ups_ant']];
    $mapMT[$mi] = ['act' => (int)$r['tiendas_act'], 'ant' => (int)$r['tiendas_ant']];
}
```

- [ ] **Step 6: Meses completos en `$labelsMes`**

Reemplazar la línea de `$labelsMes` (abreviados) por nombres completos:

```php
$labelsMes = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
```

- [ ] **Step 7: Agregar tiendas a `$serieMensual`**

Reemplazar el cuerpo del `for ($m = 1; $m <= $mesActual; $m++)` por:

```php
    $serieMensual[] = [
        'mes'         => $labelsMes[$m-1],
        'val_act'     => $mapMV[$m]['val_act'] ?? 0,
        'val_ant'     => $mapMV[$m]['val_ant'] ?? 0,
        'ups_act'     => $mapMU[$m]['ups_act'] ?? 0,
        'ups_ant'     => $mapMU[$m]['ups_ant'] ?? 0,
        'tiendas_act' => $mapMT[$m]['act'] ?? 0,
        'tiendas_ant' => $mapMT[$m]['ant'] ?? 0,
    ];
```

- [ ] **Step 8: Exponer el total distinct en el payload**

En el arreglo `$out`, justo después de la línea `'mensual'   => $serieMensual,`, agregar:

```php
    'mensual_tdas' => ['act' => $tdasTotAct, 'ant' => $tdasTotAnt],
```

- [ ] **Step 9: Lint + correr el test**

Run: `php -l api/informe_g00.php` → Expected: `No syntax errors detected`
Run: `php tests/g00_mensual_test.php` → Expected: `RESULTADO: OK (...)`
(BELTRANY no tiene 2025 → `ant`/`Tdas {b}` en 0, esperado.)

- [ ] **Step 10: Commit**

```bash
git add api/informe_g00.php tests/g00_mensual_test.php
git commit -m "feat(g00): Mensual cuenta tiendas/mes + total distinct + nombre de mes completo"
```

---

## Task 2: Frontend — columnas %Prom + Tdas en "Por Marca / Tipo"

**Files:**
- Modify: `informes/g00.php` (`renderTablaMarcaTipo` ~941, `rowMarca` ~962, llamada en dispatcher ~596)

- [ ] **Step 1: Pasar `data.kpis` a `renderTablaMarcaTipo`**

En el dispatcher (donde dice `renderTablaMarcaTipo(data.por_marca, data.anio);`), cambiar a:

```js
                renderTablaMarcaTipo(data.por_marca, data.anio, data.kpis);
```

- [ ] **Step 2: Cabecera idéntica a Por Grupo + recibir kpis**

Reemplazar la firma y la cabecera de `renderTablaMarcaTipo`. Cambiar `function renderTablaMarcaTipo(rows, anio) {` por `function renderTablaMarcaTipo(rows, anio, kpis) {` y la construcción de `h` (thead) por:

```js
        let h = '<thead><tr>'
            + '<th>Marca / Tipo</th>'
            + '<th class="num">'+b+'</th><th class="num">'+a+'</th><th class="num">Dif Q</th><th class="num">%Q</th>'
            + '<th class="num">$'+b+'</th><th class="num">$'+a+'</th><th class="num">Dif $</th><th class="num">%$</th>'
            + '<th class="num">MB</th>'
            + '<th class="num">$Prom '+b+'</th><th class="num">$Prom '+a+'</th><th class="num">%Prom</th>'
            + '<th class="num">Tdas '+b+'</th><th class="num">Tdas '+a+'</th><th class="num">≠Tdas</th>'
            + '</tr></thead><tbody>';
```

- [ ] **Step 3: Fila Total con tiendas distintas reales (desde kpis)**

Reemplazar la línea que arma la fila Total:

```js
        h += rowMarca({label:'Total',ups_act:tot.ua,ups_ant:tot.ub,val_act:tot.va,val_ant:tot.vb,
                       margen:mbTot, tiendas_act:(kpis?kpis.tiendas_actual:0), tiendas_ant:(kpis?kpis.tiendas_anterior:0)}, true, false);
```

- [ ] **Step 4: Agregar %Prom + Tdas al render de fila (`rowMarca`)**

En `rowMarca`, añadir `const difT = (r.tiendas_act||0) - (r.tiendas_ant||0);` junto al `const pa = ...`. Luego reemplazar el cierre de la fila (el bloque que hoy termina con los 2 `$Prom` y `'</tr>'`) por:

```js
            + '<td class="num">'+fmtMoneyFull(pb)+'</td><td class="num">'+fmtMoneyFull(pa)+'</td>'
            + pctCell(pa, pb)
            + '<td class="num">'+fmtInt(r.tiendas_ant)+'</td><td class="num">'+fmtInt(r.tiendas_act)+'</td>'
            + '<td class="num '+(difT>=0?'pos':'neg')+'">'+(difT>=0?'+':'')+fmtInt(difT)+'</td>'
            + '</tr>';
```

(Marca y Tipo muestran su propio distinct; el Total usa el de kpis — todas las filas renderizan número, sin caso especial.)

- [ ] **Step 5: Lint**

Run: `php -l informes/g00.php` → Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add informes/g00.php
git commit -m "feat(g00): tabla Por Marca/Tipo con %Prom + Tdas (igual a Por Grupo)"
```

---

## Task 3: Frontend — columnas $Prom + Tdas en "Mensual"

**Files:**
- Modify: `informes/g00.php` (`renderTablaMensual` ~995, `rowMensual` ~1013, llamada en dispatcher ~597)

- [ ] **Step 1: Pasar `data.mensual_tdas` a `renderTablaMensual`**

En el dispatcher (donde dice `renderTablaMensual(data.mensual, data.anio);`), cambiar a:

```js
                renderTablaMensual(data.mensual, data.anio, data.mensual_tdas);
```

- [ ] **Step 2: Cabecera con $Prom + Tdas + recibir tdas**

Cambiar `function renderTablaMensual(rows, anio) {` por `function renderTablaMensual(rows, anio, tdas) {` y reemplazar la construcción de `h` (thead) por:

```js
        let h = '<thead><tr>'
            + '<th>Mes</th>'
            + '<th class="num">'+b+'</th><th class="num">'+a+'</th><th class="num">Dif Q</th><th class="num">%Q</th>'
            + '<th class="num">$'+b+'</th><th class="num">$'+a+'</th><th class="num">Dif $</th><th class="num">%$</th>'
            + '<th class="num">$Prom '+b+'</th><th class="num">$Prom '+a+'</th>'
            + '<th class="num">Tdas '+b+'</th><th class="num">Tdas '+a+'</th><th class="num">≠Tdas</th>'
            + '</tr></thead><tbody>';
```

- [ ] **Step 3: Fila Total con tiendas distintas del rango**

Reemplazar la línea que arma la fila Total:

```js
        h += rowMensual({mes:'Total',ups_act:tot.ua,ups_ant:tot.ub,val_act:tot.va,val_ant:tot.vb,
                         tiendas_act:(tdas?tdas.act:0), tiendas_ant:(tdas?tdas.ant:0)}, true);
```

- [ ] **Step 4: Agregar $Prom + Tdas al render de fila (`rowMensual`)**

Reemplazar `rowMensual` completa por:

```js
    function rowMensual(r, isTotal) {
        const pa = prom(r.val_act, r.ups_act), pb = prom(r.val_ant, r.ups_ant);
        const difT = (r.tiendas_act||0) - (r.tiendas_ant||0);
        return '<tr class="'+(isTotal?'g00-total':'')+'">'
            + '<td>'+esc(r.mes)+'</td>'
            + '<td class="num">'+fmtInt(r.ups_ant)+'</td><td class="num">'+fmtInt(r.ups_act)+'</td>'
            + difCell(r.ups_act, r.ups_ant, fmtInt) + pctCell(r.ups_act, r.ups_ant)
            + '<td class="num">'+fmtMoneyFull(r.val_ant)+'</td><td class="num">'+fmtMoneyFull(r.val_act)+'</td>'
            + difCell(r.val_act, r.val_ant, fmtMoneyFull) + pctCell(r.val_act, r.val_ant)
            + '<td class="num">'+fmtMoneyFull(pb)+'</td><td class="num">'+fmtMoneyFull(pa)+'</td>'
            + '<td class="num">'+fmtInt(r.tiendas_ant)+'</td><td class="num">'+fmtInt(r.tiendas_act)+'</td>'
            + '<td class="num '+(difT>=0?'pos':'neg')+'">'+(difT>=0?'+':'')+fmtInt(difT)+'</td>'
            + '</tr>';
    }
```

- [ ] **Step 5: Lint**

Run: `php -l informes/g00.php` → Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add informes/g00.php
git commit -m "feat(g00): tabla Mensual con $Prom 2 anios + Tdas + diferencia"
```

---

## Task 4: Verificación final + handoff E2E

**Files:** ninguno (solo verificación)

- [ ] **Step 1: Suite backend verde**

Run: `php tests/g00_mensual_test.php` → Expected: `RESULTADO: OK`
Run: `php tests/g00_detal_smoke_test.php` → Expected: todas las permutaciones `ok=1` (regresión: el cambio de Mensual no rompe el Detal).

- [ ] **Step 2: Lint global**

Run: `php -l api/informe_g00.php && php -l informes/g00.php` → Expected: ambos `No syntax errors detected`.

- [ ] **Step 3: Handoff a Rafael (E2E navegador)**

Pedir verificación en `localhost/plataforma_20` (login proveedor → Ventas → tab Detal, Ctrl+F5):
- "Por Marca / Tipo": columnas `%Prom`, `Tdas {b}`, `Tdas {a}`, `≠Tdas` (en marca, tipo y Total). Total = tiendas distintas reales (= KPI de cabecera).
- "Mensual": `$Prom {b}`, `$Prom {a}`, `Tdas {b}`, `Tdas {a}`, `≠Tdas`; nombres de mes completos (Enero, Febrero…); Total con tiendas distintas del rango.
- `≠Tdas` coloreado verde/rojo con signo.

---

## Self-Review

- **Spec coverage:** Marca/Tipo %Prom+Tdas → Task 2 ✓. Mensual $Prom+Tdas+mes completo → Tasks 1+3 ✓. Total distinct real → Task 1 (payload) + Tasks 2/3 (render) ✓. Test backend + invariante → Task 1 ✓. E2E → Task 4 ✓.
- **Placeholder scan:** sin TBD/TODO; todo el código mostrado completo.
- **Type consistency:** `mensual_tdas => {act,ant}` (backend Task 1) consumido como `tdas.act`/`tdas.ant` (Task 3) ✓; `tiendas_act`/`tiendas_ant` por fila consistente backend↔frontend ✓; `kpis.tiendas_actual`/`tiendas_anterior` ya existen en el payload (KPIs del Detal) ✓; helper `prom`/`pctCell`/`difCell`/`fmtInt`/`fmtMoneyFull` ya definidos ✓.
