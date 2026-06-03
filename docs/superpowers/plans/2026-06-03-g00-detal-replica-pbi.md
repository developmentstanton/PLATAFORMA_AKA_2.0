# G00 Detal — Réplica Power BI (Inc 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convertir la pestaña Detal del informe G00 en una réplica del Power BI: 3 KPIs + toggle (solo "Same" activo) + 3 tablas comparativas (Grupo Tiendas / Marca→Tipo / Mensual) con lógica same-store, y quitar los códigos "G00" de botón y título.

**Architecture:** Backend PHP monolítico (`api/informe_g00.php`) con SQL Server (`sqlsrv_*`), una sola pasada con `GROUPING SETS` y `#refs` cacheado. Se extiende `#refs` con `TIPO`, se agrega el grouping set `(MARCA,TIPO)`, se exponen al JSON los campos ya calculados (unidades/margen/tiendas de ambos años) y se aplica un filtro same-store (tiendas operativas en ambos años por el maestro `Bodegas`). El front (`informes/g00.php`) reemplaza KPIs+gráficas ECharts del Detal por KPIs+toggle+3 tablas HTML.

**Tech Stack:** PHP 8 (sqlsrv), SQL Server (INTEGRACION), HTML/CSS/JS vanilla, ECharts (se retira del Detal). Sin framework de tests → verificación con script PHP CLI + chequeo manual en navegador.

**Spec:** `docs/superpowers/specs/2026-06-03-g00-detal-replica-pbi-design.md`

**Notas de contexto (memoria del proyecto):**
- Fan-out `Bodegas`: SIEMPRE `AND b.CIA = 7` en el join (si no, infla SUMs hasta ~16%).
- `Ventas_Detal_PBI` (año actual) y `Ventas_Detal_Acum_PBI` (histórico) sin índices → mantener pushdown de fecha y NOLOCK.
- Gotcha sqlsrv temp tables: `CREATE TABLE #refs` SIN parámetros; INSERT batched (≤200 filas) después.
- `MARGEN` ya viene como % → MB = `AVG(CAST(MARGEN AS float))`, formato `%`.
- Gotcha same-store: hay bodegas abiertas con `FECHA_APERTURA = NULL` → tratar NULL como "abierta desde siempre".

---

### Task 1: Cosmética — quitar códigos "G00" del botón y el título

**Files:**
- Modify: `dashboard.php` (línea ~495 y ~1186)

- [ ] **Step 1: Cambiar el label del botón del menú**

En `dashboard.php`, el nav-item del G00 (línea ~494-495) dice:
```php
                <div class="nav-item" onclick="showPage('informes-g00', this)">
                    <span class="icon"><i class="fa-solid fa-chart-column"></i></span> G00 &mdash; Ventas
```
Cambiar el texto del item a solo `Ventas` (quitar `G00 &mdash; `):
```php
                <div class="nav-item" onclick="showPage('informes-g00', this)">
                    <span class="icon"><i class="fa-solid fa-chart-column"></i></span> Ventas
```

- [ ] **Step 2: Cambiar el título de página**

En `dashboard.php` (línea ~1186), el mapa de títulos:
```php
            'informes-g00':'INFORME G00 — DASHBOARD DE VENTAS',
```
Cambiar a:
```php
            'informes-g00':'DASHBOARD DE VENTAS',
```

- [ ] **Step 3: Verificar que no quedan otros "G00" en botón/título**

Run: `grep -n "G00" dashboard.php`
Expected: las únicas coincidencias restantes son identificadores internos (`'informes-g00'`, comentarios `INFORME G00`, el include), NO texto visible de botón ni el valor del título. El badge en `informes/g00.php` se deja intacto (no se toca en esta tarea).

- [ ] **Step 4: Commit**

```bash
git add dashboard.php
git commit -m "feat(g00): quitar codigo G00 del boton de menu y titulo"
```

---

### Task 2: Backend — agregar `TIPO` a `#refs`

**Files:**
- Modify: `api/informe_g00.php` (`getRefsCached` ~112-123, `buildRefsTemp` ~132-151)

- [ ] **Step 1: Traer `TIPO` en el query de refs cacheadas**

En `getRefsCached`, el SELECT actual:
```php
    $sql = "SELECT REFERENCIA,
                ISNULL(MARCA,    'SIN MARCA')  AS MARCA,
                ISNULL(LINEA,    'SIN LINEA')  AS LINEA,
                ISNULL(SUBLINEA, '')           AS SUBLINEA,
                ISNULL(CATEGORIA,'')           AS CATEGORIA
            FROM INTEGRACION.dbo.ITEMS WITH (NOLOCK)
            WHERE PROVEEDOR = ?";
```
Agregar `TIPO`:
```php
    $sql = "SELECT REFERENCIA,
                ISNULL(MARCA,    'SIN MARCA')  AS MARCA,
                ISNULL(TIPO,     'SIN TIPO')   AS TIPO,
                ISNULL(LINEA,    'SIN LINEA')  AS LINEA,
                ISNULL(SUBLINEA, '')           AS SUBLINEA,
                ISNULL(CATEGORIA,'')           AS CATEGORIA
            FROM INTEGRACION.dbo.ITEMS WITH (NOLOCK)
            WHERE PROVEEDOR = ?";
```

- [ ] **Step 2: Agregar la columna `TIPO` a la temp table `#refs`**

En `buildRefsTemp`, el `CREATE TABLE` y el `INSERT`:
```php
    $ok = sqlsrv_query($conn, "CREATE TABLE #refs (
        REFERENCIA varchar(50) NOT NULL PRIMARY KEY,
        MARCA varchar(40), LINEA varchar(40), SUBLINEA varchar(40), CATEGORIA varchar(40))");
```
Cambiar a (agregar `TIPO`):
```php
    $ok = sqlsrv_query($conn, "CREATE TABLE #refs (
        REFERENCIA varchar(50) NOT NULL PRIMARY KEY,
        MARCA varchar(40), TIPO varchar(40), LINEA varchar(40), SUBLINEA varchar(40), CATEGORIA varchar(40))");
```
Y el bloque de INSERT:
```php
        foreach ($chunk as $r) {
            $vals[] = '(?,?,?,?,?)';
            array_push($params, $r['REFERENCIA'], $r['MARCA'], $r['LINEA'], $r['SUBLINEA'], $r['CATEGORIA']);
        }
        $sql = "INSERT INTO #refs (REFERENCIA,MARCA,LINEA,SUBLINEA,CATEGORIA) VALUES " . implode(',', $vals);
```
Cambiar a (agregar `TIPO`, 6 columnas / 6 placeholders):
```php
        foreach ($chunk as $r) {
            $vals[] = '(?,?,?,?,?,?)';
            array_push($params, $r['REFERENCIA'], $r['MARCA'], $r['TIPO'], $r['LINEA'], $r['SUBLINEA'], $r['CATEGORIA']);
        }
        $sql = "INSERT INTO #refs (REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA) VALUES " . implode(',', $vals);
```

- [ ] **Step 3: Invalidar el cache viejo de refs (estructura cambió)**

El cache `g00_refs_*.json` viejo no tiene `TIPO`; al recargarse, `$r['TIPO']` sería undefined. Borrar los archivos para que se regeneren con la nueva estructura:

Run: `rm -f cache/g00_refs_*.json`
Expected: sin salida (archivos eliminados; se regeneran al primer request).

- [ ] **Step 4: Verificar sintaxis**

Run: `C:\xampp\php\php -l api/informe_g00.php`
Expected: `No syntax errors detected in ...api/informe_g00.php` (ignorar warnings de arranque xdebug/dio/openssl).

- [ ] **Step 5: Commit**

```bash
git add api/informe_g00.php
git commit -m "feat(g00): agregar TIPO a #refs (refs cacheadas y temp table)"
```

---

### Task 3: Backend — filtro same-store (tiendas operativas en ambos años)

**Files:**
- Modify: `api/informe_g00.php` (CTE `ventas_enriq` del tab Detal, ~398-438)

- [ ] **Step 1: Agregar la condición same-store al CTE `ventas_enriq`**

El CTE actual filtra por rango:
```php
    , ventas_enriq AS (
        SELECT v.FECHA, v.BODEGA, v.CANTIDAD, v.VALOR, v.MARGEN,
               ISNULL(b.GRUPO, 'SIN GRUPO') AS GRUPO,
               i.MARCA AS MARCA
        FROM ventas v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD        = v.BODEGA AND b.CIA = 7
        WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
          $filtroExtra
    )
```
Reemplazarlo por (agregar `i.TIPO`, y la condición same-store con `EXISTS` contra `Bodegas`, manejando `FECHA_APERTURA` NULL):
```php
    , ventas_enriq AS (
        SELECT v.FECHA, v.BODEGA, v.CANTIDAD, v.VALOR, v.MARGEN,
               ISNULL(b.GRUPO, 'SIN GRUPO') AS GRUPO,
               i.MARCA AS MARCA,
               i.TIPO  AS TIPO
        FROM ventas v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD        = v.BODEGA AND b.CIA = 7
        WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
          $filtroExtra
          AND EXISTS (
                SELECT 1 FROM INTEGRACION.dbo.Bodegas sb WITH (NOLOCK)
                WHERE sb.COD = v.BODEGA AND sb.CIA = 7
                  AND (sb.FECHA_APERTURA IS NULL OR sb.FECHA_APERTURA <= ?)
                  AND (sb.FECHA_CIERRE   IS NULL OR sb.FECHA_CIERRE   >= ?)
          )
    )
```
Criterio: comparable = abierta antes del inicio de la ventana 2025 (`<= $desdeAnt`) y no cerrada antes del fin de la ventana 2026 (`>= $hastaAct`). NULL apertura = "abierta desde siempre"; NULL cierre = "sigue abierta".

- [ ] **Step 2: Inyectar los 2 parámetros nuevos del `EXISTS`**

El arreglo `$paramsConsolidado` actual:
```php
$paramsConsolidado = array_merge(
    [$gmin, $gmax, $gmin, $gmax],   // CTE pushdown PBI + Acum
    [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt],  // ventas_enriq OR-filter exacto
    $paramsExtra,
    [$desdeAct,$hastaAct, $desdeAnt,$hastaAnt,    // val_act / val_ant
     $desdeAct,$hastaAct, $desdeAnt,$hastaAnt,    // ups_act / ups_ant
     $desdeAct,$hastaAct,                          // margen_prom
     $desdeAct,$hastaAct, $desdeAnt,$hastaAnt]    // tiendas_act / tiendas_ant
);
```
Insertar los 2 params del EXISTS (`$desdeAnt`, `$hastaAct`) **justo después** del OR-filter y **antes** de `$paramsExtra` (orden posicional = orden de aparición de los `?` en el SQL: OR-filter → EXISTS → filtroExtra):
```php
$paramsConsolidado = array_merge(
    [$gmin, $gmax, $gmin, $gmax],   // CTE pushdown PBI + Acum
    [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt],  // ventas_enriq OR-filter exacto
    [$desdeAnt, $hastaAct],         // EXISTS same-store (apertura<=inicio2025, cierre>=fin2026)
    $paramsExtra,
    [$desdeAct,$hastaAct, $desdeAnt,$hastaAnt,    // val_act / val_ant
     $desdeAct,$hastaAct, $desdeAnt,$hastaAnt,    // ups_act / ups_ant
     $desdeAct,$hastaAct,                          // margen_prom
     $desdeAct,$hastaAct, $desdeAnt,$hastaAnt]    // tiendas_act / tiendas_ant
);
```
**IMPORTANTE:** `$filtroExtra` usa el alias `i` (marca) y `b` (grupo). El `EXISTS` va **después** del `$filtroExtra` en el WHERE, así que sus 2 params van **después** de `$paramsExtra`... — revisar el orden real: en el SQL el `EXISTS` está escrito DESPUÉS de `$filtroExtra`. Por lo tanto los params del EXISTS deben ir DESPUÉS de `$paramsExtra`. Corrección al array:
```php
$paramsConsolidado = array_merge(
    [$gmin, $gmax, $gmin, $gmax],
    [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt],
    $paramsExtra,
    [$desdeAnt, $hastaAct],         // EXISTS same-store (va tras $filtroExtra en el SQL)
    [$desdeAct,$hastaAct, $desdeAnt,$hastaAnt,
     $desdeAct,$hastaAct, $desdeAnt,$hastaAnt,
     $desdeAct,$hastaAct,
     $desdeAct,$hastaAct, $desdeAnt,$hastaAnt]
);
```
Usar esta última versión (params del EXISTS tras `$paramsExtra`, alineado con la posición del `?` en el SQL).

- [ ] **Step 3: Verificar sintaxis**

Run: `C:\xampp\php\php -l api/informe_g00.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add api/informe_g00.php
git commit -m "feat(g00): filtro same-store (tiendas operativas ambos anios) en Detal"
```

---

### Task 4: Backend — grouping set (MARCA,TIPO) + exponer todos los campos al JSON

**Files:**
- Modify: `api/informe_g00.php` (SELECT consolidado ~409-428, demux ~444-456, mensual ~473-491, mappers ~493-509, `$out` ~511-538)

- [ ] **Step 1: Agregar `TIPO` al SELECT y el grouping set `(MARCA,TIPO)`**

El SELECT/GROUP del query consolidado:
```php
    SELECT
        GROUPING_ID(YEAR(FECHA), MONTH(FECHA), GRUPO, MARCA) AS gid,
        YEAR(FECHA)  AS anio,
        MONTH(FECHA) AS mes,
        GRUPO        AS grupo,
        MARCA        AS marca,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
        ...
    FROM ventas_enriq
    GROUP BY GROUPING SETS (
        (),
        (YEAR(FECHA), MONTH(FECHA)),
        (GRUPO),
        (MARCA)
    )
```
Cambiar a (agregar `TIPO` como 5ª dimensión del `GROUPING_ID`, columna `tipo`, y el set `(MARCA,TIPO)`):
```php
    SELECT
        GROUPING_ID(YEAR(FECHA), MONTH(FECHA), GRUPO, MARCA, TIPO) AS gid,
        YEAR(FECHA)  AS anio,
        MONTH(FECHA) AS mes,
        GRUPO        AS grupo,
        MARCA        AS marca,
        TIPO         AS tipo,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant,
        AVG(CASE WHEN FECHA BETWEEN ? AND ? AND CANTIDAD > 0 THEN CAST(MARGEN AS float) END) AS margen_prom,
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? THEN BODEGA END) AS tiendas_act,
        COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? THEN BODEGA END) AS tiendas_ant
    FROM ventas_enriq
    GROUP BY GROUPING SETS (
        (),
        (YEAR(FECHA), MONTH(FECHA)),
        (GRUPO),
        (MARCA),
        (MARCA, TIPO)
    )
```
(Los `?` de los SUM/AVG/COUNT no cambian en número ni orden; los params de Task 3 siguen válidos.)

- [ ] **Step 2: Recalcular los gid y demultiplexar por los nuevos valores**

Con 5 dimensiones `GROUPING_ID(YEAR,MONTH,GRUPO,MARCA,TIPO)` (bit4=YEAR … bit0=TIPO; bit=1 ⇒ NULL):
- `() total` → `11111` = **31**
- `(YEAR,MONTH)` → `00111` = **7**
- `(GRUPO)` → `11011` = **27**
- `(MARCA)` → `11101` = **29**
- `(MARCA,TIPO)` → `11100` = **28**

Reemplazar el bloque demux:
```php
$kpi = []; $mensualRows = []; $porGrupo = []; $porMarca = [];
foreach ($consolidado as $r) {
    $gid = (int)$r['gid'];
    if ($gid === 15) {
        $kpi = $r;
    } elseif ($gid === 3) {
        $mensualRows[] = $r;
    } elseif ($gid === 13) {
        $porGrupo[] = $r;
    } elseif ($gid === 14) {
        $porMarca[] = $r;
    }
}
```
por:
```php
$kpi = []; $mensualRows = []; $porGrupo = []; $porMarca = []; $porMarcaTipo = [];
foreach ($consolidado as $r) {
    $gid = (int)$r['gid'];
    if      ($gid === 31) { $kpi = $r; }
    elseif  ($gid === 7)  { $mensualRows[]  = $r; }
    elseif  ($gid === 27) { $porGrupo[]     = $r; }
    elseif  ($gid === 29) { $porMarca[]     = $r; }
    elseif  ($gid === 28) { $porMarcaTipo[] = $r; }
}
```

- [ ] **Step 3: Exponer unidades por mes (serie mensual rica)**

El bloque mensual actual solo suma valor:
```php
$mapMes = [];
foreach ($mensualRows as $row) {
    $y = (int)$row['anio']; $m = (int)$row['mes'];
    $valor = (float)$row['val_act'] + (float)$row['val_ant'];
    $mapMes[$y][$m] = $valor;
}
$yearAct = (int)date('Y', strtotime($hastaAct));
$yearAnt = $yearAct - 1;
$labelsMes = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$serieMensual = [];
for ($m = 1; $m <= 12; $m++) {
    $serieMensual[] = [
        'mes'      => $labelsMes[$m-1],
        'actual'   => $mapMes[$yearAct][$m] ?? 0,
        'anterior' => $mapMes[$yearAnt][$m] ?? 0,
    ];
}
```
Reemplazar por (guardar valor Y unidades por año-mes):
```php
$mapMesVal = []; $mapMesUps = [];
foreach ($mensualRows as $row) {
    $y = (int)$row['anio']; $m = (int)$row['mes'];
    // Las dos fact tables no se solapan: una (year,month) trae SOLO act O ant.
    $mapMesVal[$y][$m] = (float)$row['val_act'] + (float)$row['val_ant'];
    $mapMesUps[$y][$m] = (float)$row['ups_act'] + (float)$row['ups_ant'];
}
$yearAct = (int)date('Y', strtotime($hastaAct));
$yearAnt = $yearAct - 1;
$labelsMes = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$serieMensual = [];
for ($m = 1; $m <= 12; $m++) {
    $serieMensual[] = [
        'mes'        => $labelsMes[$m-1],
        'val_act'    => $mapMesVal[$yearAct][$m] ?? 0,
        'val_ant'    => $mapMesVal[$yearAnt][$m] ?? 0,
        'ups_act'    => $mapMesUps[$yearAct][$m] ?? 0,
        'ups_ant'    => $mapMesUps[$yearAnt][$m] ?? 0,
    ];
}
```

- [ ] **Step 4: Mapper que expone todos los campos de fila + armado de marca→tipo**

Reemplazar el `$mapDelta` actual:
```php
$mapDelta = function ($rows, $keyDim) {
    $out = [];
    foreach ($rows as $r) {
        $a = (float)$r['val_act'];
        $b = (float)$r['val_ant'];
        $out[] = [
            'label'      => $r[$keyDim] ?? '',
            'actual'     => $a,
            'anterior'   => $b,
            'delta_pct'  => $b > 0 ? (($a - $b) / $b) * 100 : 0,
            'ups_actual' => (float)($r['ups_act'] ?? 0),
        ];
    }
    usort($out, fn($x, $y) => $y['actual'] <=> $x['actual']);
    return $out;
};
```
por un mapper que pasa todos los campos crudos (las métricas derivadas Dif/%/$Prom se calculan en el front):
```php
$rowFields = function ($r, $keyDim) {
    return [
        'label'       => trim((string)($r[$keyDim] ?? '')),
        'val_act'     => (float)($r['val_act']     ?? 0),
        'val_ant'     => (float)($r['val_ant']     ?? 0),
        'ups_act'     => (float)($r['ups_act']     ?? 0),
        'ups_ant'     => (float)($r['ups_ant']     ?? 0),
        'margen'      => (float)($r['margen_prom'] ?? 0),
        'tiendas_act' => (int)  ($r['tiendas_act'] ?? 0),
        'tiendas_ant' => (int)  ($r['tiendas_ant'] ?? 0),
    ];
};

// Por grupo (ordenado por $ actual desc)
$grupoArr = array_map(fn($r) => $rowFields($r, 'grupo'), $porGrupo);
usort($grupoArr, fn($x, $y) => $y['val_act'] <=> $x['val_act']);

// Por marca con tipos anidados
$marcaMap = [];
foreach ($porMarca as $r) {
    $m = trim((string)$r['marca']);
    $marcaMap[$m] = $rowFields($r, 'marca');
    $marcaMap[$m]['children'] = [];
}
foreach ($porMarcaTipo as $r) {
    $m = trim((string)$r['marca']);
    if (!isset($marcaMap[$m])) { // defensa: marca sin fila propia
        $marcaMap[$m] = $rowFields($r, 'marca');
        $marcaMap[$m]['label'] = $m;
        $marcaMap[$m]['children'] = [];
    }
    $marcaMap[$m]['children'][] = $rowFields($r, 'tipo');
}
$marcaArr = array_values($marcaMap);
usort($marcaArr, fn($x, $y) => $y['val_act'] <=> $x['val_act']);
foreach ($marcaArr as &$mref) {
    usort($mref['children'], fn($x, $y) => $y['val_act'] <=> $x['val_act']);
}
unset($mref);
```

- [ ] **Step 5: Actualizar el arreglo `$out` (claves nuevas)**

Reemplazar las claves `mensual`, `por_grupo`, `por_marca` del arreglo `$out`:
```php
    'mensual'   => $serieMensual,
    'por_grupo' => $mapDelta($porGrupo, 'grupo'),
    'por_marca' => $mapDelta($porMarca, 'marca'),
    'catalogos' => $catalogos,
```
por:
```php
    'mensual'   => $serieMensual,
    'por_grupo' => $grupoArr,
    'por_marca' => $marcaArr,
    'catalogos' => $catalogos,
```
(El bloque `kpis` se mantiene igual: ya trae ventas/ups/tiendas/ticket/margen de ambos años, que alimenta los 3 KPIs.)

- [ ] **Step 6: Verificar sintaxis**

Run: `C:\xampp\php\php -l api/informe_g00.php`
Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add api/informe_g00.php
git commit -m "feat(g00): grouping set (MARCA,TIPO) y exponer campos completos al JSON"
```

---

### Task 5: Verificación backend — script PHP CLI

**Files:**
- Create: `tests/g00_detal_test.php`

- [ ] **Step 1: Escribir el script de verificación**

Crea `tests/g00_detal_test.php` con este contenido (replica el armado de #refs + el query consolidado para un proveedor y rango conocidos, y verifica la forma del resultado y la cordura del same-store):

```php
<?php
// Verificación backend del tab Detal del G00 (Inc 1). Correr por CLI:
//   php tests/g00_detal_test.php "NOMBRE PROVEEDOR" 2026-05-01 2026-05-31
// Si no se pasan args, usa un proveedor por defecto detectado.
$srv = "siesa-m1-sqlsw-db15.cbm3ohogeajr.us-east-1.rds.amazonaws.com";
$cn  = sqlsrv_connect($srv, ["Database"=>"INTEGRACION","UID"=>"admistanton","PWD"=>"adminstanton\$12\$%","CharacterSet"=>"UTF-8"]);
if ($cn === false) { fwrite(STDERR, "Sin conexion\n"); exit(1); }

$proveedor = $argv[1] ?? null;
$desdeAct  = $argv[2] ?? date('Y-05-01');
$hastaAct  = $argv[3] ?? date('Y-05-31');
$desdeAnt  = date('Y-m-d', strtotime($desdeAct.' -1 year'));
$hastaAnt  = date('Y-m-d', strtotime($hastaAct.' -1 year'));
$gmin = min($desdeAnt, $desdeAct); $gmax = max($hastaAct, $hastaAnt);

function q($cn,$sql,$p=[]){ $s=sqlsrv_query($cn,$sql,$p); if($s===false){fwrite(STDERR,print_r(sqlsrv_errors(),true));exit(1);} $r=[]; while($x=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC))$r[]=$x; return $r; }

if (!$proveedor) {
    $row = q($cn, "SELECT TOP 1 PROVEEDOR FROM INTEGRACION.dbo.ITEMS WHERE PROVEEDOR IS NOT NULL AND PROVEEDOR <> '' GROUP BY PROVEEDOR ORDER BY COUNT(*) DESC");
    $proveedor = $row[0]['PROVEEDOR'];
}
echo "Proveedor: $proveedor | Rango 2026 $desdeAct..$hastaAct vs 2025 $desdeAnt..$hastaAnt\n";

// #refs con TIPO
sqlsrv_query($cn, "CREATE TABLE #refs (REFERENCIA varchar(50) PRIMARY KEY, MARCA varchar(40), TIPO varchar(40))");
$refs = q($cn, "SELECT REFERENCIA, ISNULL(MARCA,'SIN MARCA') MARCA, ISNULL(TIPO,'SIN TIPO') TIPO FROM INTEGRACION.dbo.ITEMS WITH (NOLOCK) WHERE PROVEEDOR = ?", [$proveedor]);
foreach (array_chunk($refs, 200) as $ch) {
    $vals=[];$p=[]; foreach($ch as $r){$vals[]='(?,?,?)';array_push($p,$r['REFERENCIA'],$r['MARCA'],$r['TIPO']);}
    sqlsrv_query($cn, "INSERT INTO #refs (REFERENCIA,MARCA,TIPO) VALUES ".implode(',',$vals), $p);
}

$sqlBase = "
WITH ventas AS (
  SELECT FECHA,BODEGA,REFERENCIA,CANTIDAD,VALOR,MARGEN FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?
  UNION ALL
  SELECT FECHA,BODEGA,REFERENCIA,CANTIDAD,VALOR,MARGEN FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?
), ventas_enriq AS (
  SELECT v.FECHA,v.BODEGA,v.CANTIDAD,v.VALOR,v.MARGEN, ISNULL(b.GRUPO,'SIN GRUPO') GRUPO, i.MARCA, i.TIPO
  FROM ventas v
  INNER JOIN #refs i ON i.REFERENCIA=v.REFERENCIA
  LEFT JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD=v.BODEGA AND b.CIA=7
  WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
  __SAMESTORE__
)
SELECT
  SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR ELSE 0 END) val_act,
  SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR ELSE 0 END) val_ant,
  COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? THEN BODEGA END) tiendas_act
FROM ventas_enriq";

$same = " AND EXISTS (SELECT 1 FROM INTEGRACION.dbo.Bodegas sb WITH (NOLOCK) WHERE sb.COD=v.BODEGA AND sb.CIA=7 AND (sb.FECHA_APERTURA IS NULL OR sb.FECHA_APERTURA<=?) AND (sb.FECHA_CIERRE IS NULL OR sb.FECHA_CIERRE>=?))";

// Sin same-store
$pAll = [$gmin,$gmax,$gmin,$gmax, $desdeAct,$hastaAct,$desdeAnt,$hastaAnt, $desdeAct,$hastaAct,$desdeAnt,$hastaAnt,$desdeAct,$hastaAct];
$all = q($cn, str_replace('__SAMESTORE__','',$sqlBase), $pAll);
// Con same-store (2 params extra del EXISTS, tras el OR-filter)
$pSame = [$gmin,$gmax,$gmin,$gmax, $desdeAct,$hastaAct,$desdeAnt,$hastaAnt, $desdeAnt,$hastaAct, $desdeAct,$hastaAct,$desdeAnt,$hastaAnt,$desdeAct,$hastaAct];
$ss = q($cn, str_replace('__SAMESTORE__',$same,$sqlBase), $pSame);

$va_all=(float)$all[0]['val_act']; $va_ss=(float)$ss[0]['val_act'];
printf("Ventas 2026  TODAS=%s  SAME=%s\n", number_format($va_all), number_format($va_ss));
printf("Tiendas 2026 TODAS=%s  SAME=%s\n", $all[0]['tiendas_act'], $ss[0]['tiendas_act']);

$fail = 0;
if ($va_ss > $va_all + 0.5) { echo "FALLO: same-store > todas (imposible)\n"; $fail=1; }
if ($va_ss <= 0 && $va_all > 0) { echo "AVISO: same-store dio 0 con todas>0 (revisar criterio fechas)\n"; }
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK (same-store <= todas, forma correcta)\n";
exit($fail);
```

- [ ] **Step 2: Correr el script y verificar**

Run: `C:\xampp\php\php tests/g00_detal_test.php 2>/dev/null`
Expected: imprime ventas/tiendas TODAS vs SAME, y `RESULTADO: OK`. El valor SAME debe ser `<=` TODAS (nunca mayor). Si imprime el AVISO de same-store=0, revisar el criterio de fechas del Task 3 contra los datos.

- [ ] **Step 3: Commit**

```bash
git add tests/g00_detal_test.php
git commit -m "test(g00): verificacion backend Detal (forma + cordura same-store)"
```

---

### Task 6: Frontend — panel Detal: KPIs (3) + toggle

**Files:**
- Modify: `informes/g00.php` (panel `#g00-panel-detal` ~177-252, y CSS si hace falta)

- [ ] **Step 1: Reemplazar el contenido del panel Detal por KPIs (3) + toggle + contenedores de tablas**

Reemplazar todo el bloque `<div class="g00-tab-panel active" id="g00-panel-detal"> ... </div>` (desde la apertura en ~177 hasta su cierre en ~252, justo antes de `<!-- ============ TAB DETALLE TIENDAS ============ -->`) por:
```html
    <!-- ============ TAB DETAL ============ -->
    <div class="g00-tab-panel active" id="g00-panel-detal">

        <!-- Franja de 3 KPIs (estilo Power BI) -->
        <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">
            <div class="g00-kpi accent">
                <div class="g00-kpi-head"><span class="g00-kpi-label">Tiendas <span id="g00-kpi-anio-1">—</span></span></div>
                <div class="g00-kpi-value" id="g00-kpi-tiendas"><span class="g00-skeleton" style="width:60px;height:26px;"></span></div>
                <div class="g00-kpi-sub">bodegas con venta</div>
            </div>
            <div class="g00-kpi success">
                <div class="g00-kpi-head"><span class="g00-kpi-label">$ Prom <span id="g00-kpi-anio-2">—</span></span></div>
                <div class="g00-kpi-value" id="g00-kpi-ticket"><span class="g00-skeleton" style="width:100px;height:26px;"></span></div>
                <div class="g00-kpi-sub">$ por par (valor ÷ unidades)</div>
            </div>
            <div class="g00-kpi warning">
                <div class="g00-kpi-head"><span class="g00-kpi-label">MB</span></div>
                <div class="g00-kpi-value" id="g00-kpi-margen"><span class="g00-skeleton" style="width:70px;height:26px;"></span></div>
                <div class="g00-kpi-sub">margen %</div>
            </div>
        </div>

        <!-- Toggle Día a Día / Retail / Same -->
        <div class="tab-bar g00-modo-bar" style="margin-bottom:16px;">
            <div class="tab g00-modo" data-modo="diaadia" onclick="g00ModoInerte()">Día a Día</div>
            <div class="tab g00-modo" data-modo="retail" onclick="g00ModoInerte()">Retail</div>
            <div class="tab g00-modo active" data-modo="same">Same</div>
        </div>

        <!-- Tabla 1: Por Grupo Tiendas -->
        <div class="card">
            <div class="card-title">Resumen Ventas Por Grupo Tiendas</div>
            <div style="overflow-x:auto;">
                <table id="g00-tabla-grupo" class="disp-table"></table>
            </div>
        </div>

        <!-- Tabla 2: Por Marca / Tipo -->
        <div class="card">
            <div class="card-title">Resumen Ventas Por Marca / Tipo</div>
            <div style="overflow-x:auto;">
                <table id="g00-tabla-marca" class="disp-table"></table>
            </div>
        </div>

        <!-- Tabla 3: Mensual -->
        <div class="card">
            <div class="card-title">Resumen Ventas Mensual</div>
            <div style="overflow-x:auto;">
                <table id="g00-tabla-mensual" class="disp-table"></table>
            </div>
        </div>

    </div>
```

- [ ] **Step 2: Agregar CSS para el toggle inerte y filas Total**

Dentro del bloque `<style>` del informe (antes de `</style>`, ~118), agregar:
```css
    /* Toggle de modo (Día a Día / Retail / Same) */
    .g00-modo-bar .g00-modo[data-modo="diaadia"],
    .g00-modo-bar .g00-modo[data-modo="retail"] {
        opacity: 0.45; cursor: not-allowed;
    }
    /* Tablas comparativas tipo Power BI */
    table.disp-table th, table.disp-table td { white-space: nowrap; font-size: 12px; }
    table.disp-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
    table.disp-table tr.g00-total td { background: #fdf6e3; font-weight: 700; }
    table.disp-table tr.g00-tipo td:first-child { padding-left: 26px; color: var(--text-light); font-weight: 500; }
    table.disp-table .pos { color: var(--success); }
    table.disp-table .neg { color: var(--danger); }
```

- [ ] **Step 3: Verificar visual base**

Run: ` ` (sin comando automatizado). Verificación manual: abrir el informe en el navegador; el panel Detal debe mostrar 3 KPIs en skeleton, el toggle con "Same" activo y "Día a Día"/"Retail" atenuados, y 3 tarjetas con tablas vacías. (El llenado viene en Task 7.)
Expected: estructura visible sin errores de consola por HTML (las tablas vacías aún no se llenan).

- [ ] **Step 4: Commit**

```bash
git add informes/g00.php
git commit -m "feat(g00): panel Detal con 3 KPIs y toggle (Same activo)"
```

---

### Task 7: Frontend — renderizar las 3 tablas y reescribir `loadDetal`

**Files:**
- Modify: `informes/g00.php` (script: `renderKpis` ~395-409, `loadDetal` ~551-574, retirar `initChartMensual`/`initChartBarras` del Detal)

- [ ] **Step 1: Agregar helpers de métrica y render de filas**

Dentro del IIFE del script (por ejemplo justo después de `function esc(...)` ~872), agregar:
```javascript
    // Métricas derivadas de una fila cruda {val_act,val_ant,ups_act,ups_ant,margen,tiendas_act,tiendas_ant}
    function difCell(act, ant, fmt) {
        const d = (act || 0) - (ant || 0);
        const cls = d >= 0 ? 'pos' : 'neg';
        return '<td class="num ' + cls + '">' + (d >= 0 ? '+' : '') + fmt(d) + '</td>';
    }
    function pctCell(act, ant) {
        if (!ant) return '<td class="num">—</td>';
        const p = ((act - ant) / ant) * 100;
        const cls = p >= 0 ? 'pos' : 'neg';
        return '<td class="num ' + cls + '">' + (p >= 0 ? '+' : '') + p.toFixed(1) + '%</td>';
    }
    const prom = (val, ups) => (ups > 0 ? val / ups : 0);
```

- [ ] **Step 2: Render de la Tabla 1 (Grupo Tiendas) con fila Total**

Agregar dentro del IIFE:
```javascript
    function renderTablaGrupo(rows, anio) {
        const a = anio, b = anio - 1;
        let h = '<thead><tr>'
            + '<th>Grupo</th>'
            + '<th class="num">'+b+'</th><th class="num">'+a+'</th><th class="num">Dif Q</th><th class="num">%Q</th>'
            + '<th class="num">$'+b+'</th><th class="num">$'+a+'</th><th class="num">Dif $</th><th class="num">%$</th>'
            + '<th class="num">MB</th>'
            + '<th class="num">$Prom '+b+'</th><th class="num">$Prom '+a+'</th><th class="num">%Prom</th>'
            + '<th class="num">Tdas '+b+'</th><th class="num">Tdas '+a+'</th><th class="num">≠Tdas</th>'
            + '</tr></thead><tbody>';
        const tot = {ua:0,ub:0,va:0,vb:0,ta:0,tb:0};
        (rows||[]).forEach(r => {
            tot.ua+=r.ups_act; tot.ub+=r.ups_ant; tot.va+=r.val_act; tot.vb+=r.val_ant;
            h += rowGrupo(r, false);
        });
        // Fila Total (sumas; MB total = promedio simple de filas con dato — aprox. consistente)
        const margenes = (rows||[]).map(r=>r.margen).filter(m=>m>0);
        const mbTot = margenes.length ? margenes.reduce((x,y)=>x+y,0)/margenes.length : 0;
        h += rowGrupo({label:'Total', ups_act:tot.ua,ups_ant:tot.ub, val_act:tot.va,val_ant:tot.vb,
                       margen:mbTot, tiendas_act:0, tiendas_ant:0}, true);
        h += '</tbody>';
        document.getElementById('g00-tabla-grupo').innerHTML = h;
    }
    function rowGrupo(r, isTotal) {
        const pa = prom(r.val_act, r.ups_act), pb = prom(r.val_ant, r.ups_ant);
        const difT = (r.tiendas_act||0) - (r.tiendas_ant||0);
        return '<tr class="'+(isTotal?'g00-total':'')+'">'
            + '<td>'+esc(r.label)+'</td>'
            + '<td class="num">'+fmtInt(r.ups_ant)+'</td><td class="num">'+fmtInt(r.ups_act)+'</td>'
            + difCell(r.ups_act, r.ups_ant, fmtInt) + pctCell(r.ups_act, r.ups_ant)
            + '<td class="num">'+fmtMoneyFull(r.val_ant)+'</td><td class="num">'+fmtMoneyFull(r.val_act)+'</td>'
            + difCell(r.val_act, r.val_ant, fmtMoneyFull) + pctCell(r.val_act, r.val_ant)
            + '<td class="num">'+(r.margen?r.margen.toFixed(2)+'%':'—')+'</td>'
            + '<td class="num">'+fmtMoneyFull(pb)+'</td><td class="num">'+fmtMoneyFull(pa)+'</td>'
            + pctCell(pa, pb)
            + (isTotal
                ? '<td class="num">—</td><td class="num">—</td><td class="num">—</td>'
                : '<td class="num">'+fmtInt(r.tiendas_ant)+'</td><td class="num">'+fmtInt(r.tiendas_act)+'</td>'
                  + '<td class="num '+(difT>=0?'pos':'neg')+'">'+(difT>=0?'+':'')+fmtInt(difT)+'</td>')
            + '</tr>';
    }
```

- [ ] **Step 3: Render de la Tabla 2 (Marca → Tipo anidado) con fila Total**

Agregar:
```javascript
    function renderTablaMarcaTipo(rows, anio) {
        const a = anio, b = anio - 1;
        let h = '<thead><tr>'
            + '<th>Marca / Tipo</th>'
            + '<th class="num">'+b+'</th><th class="num">'+a+'</th><th class="num">Dif Q</th><th class="num">%Q</th>'
            + '<th class="num">$'+b+'</th><th class="num">$'+a+'</th><th class="num">Dif $</th><th class="num">%$</th>'
            + '<th class="num">MB</th><th class="num">$Prom '+b+'</th><th class="num">$Prom '+a+'</th>'
            + '</tr></thead><tbody>';
        const tot = {ua:0,ub:0,va:0,vb:0};
        (rows||[]).forEach(m => {
            tot.ua+=m.ups_act; tot.ub+=m.ups_ant; tot.va+=m.val_act; tot.vb+=m.val_ant;
            h += rowMarca(m, false, false);
            (m.children||[]).forEach(t => { h += rowMarca(t, false, true); });
        });
        const margenes = (rows||[]).map(r=>r.margen).filter(x=>x>0);
        const mbTot = margenes.length ? margenes.reduce((x,y)=>x+y,0)/margenes.length : 0;
        h += rowMarca({label:'Total',ups_act:tot.ua,ups_ant:tot.ub,val_act:tot.va,val_ant:tot.vb,margen:mbTot}, true, false);
        h += '</tbody>';
        document.getElementById('g00-tabla-marca').innerHTML = h;
    }
    function rowMarca(r, isTotal, isTipo) {
        const pa = prom(r.val_act, r.ups_act), pb = prom(r.val_ant, r.ups_ant);
        const cls = isTotal ? 'g00-total' : (isTipo ? 'g00-tipo' : '');
        return '<tr class="'+cls+'">'
            + '<td>'+esc(r.label)+'</td>'
            + '<td class="num">'+fmtInt(r.ups_ant)+'</td><td class="num">'+fmtInt(r.ups_act)+'</td>'
            + difCell(r.ups_act, r.ups_ant, fmtInt) + pctCell(r.ups_act, r.ups_ant)
            + '<td class="num">'+fmtMoneyFull(r.val_ant)+'</td><td class="num">'+fmtMoneyFull(r.val_act)+'</td>'
            + difCell(r.val_act, r.val_ant, fmtMoneyFull) + pctCell(r.val_act, r.val_ant)
            + '<td class="num">'+(r.margen?r.margen.toFixed(2)+'%':'—')+'</td>'
            + '<td class="num">'+fmtMoneyFull(pb)+'</td><td class="num">'+fmtMoneyFull(pa)+'</td>'
            + '</tr>';
    }
```

- [ ] **Step 4: Render de la Tabla 3 (Mensual) con fila Total**

Agregar:
```javascript
    function renderTablaMensual(rows, anio) {
        const a = anio, b = anio - 1;
        let h = '<thead><tr>'
            + '<th>Mes</th>'
            + '<th class="num">'+b+'</th><th class="num">'+a+'</th><th class="num">Dif Q</th><th class="num">%Q</th>'
            + '<th class="num">$'+b+'</th><th class="num">$'+a+'</th><th class="num">Dif $</th><th class="num">%$</th>'
            + '</tr></thead><tbody>';
        const tot = {ua:0,ub:0,va:0,vb:0};
        (rows||[]).forEach(r => {
            // Omitir meses totalmente vacíos (ambos años en 0)
            if (!r.val_act && !r.val_ant && !r.ups_act && !r.ups_ant) return;
            tot.ua+=r.ups_act; tot.ub+=r.ups_ant; tot.va+=r.val_act; tot.vb+=r.val_ant;
            h += rowMensual(r, false);
        });
        h += rowMensual({mes:'Total',ups_act:tot.ua,ups_ant:tot.ub,val_act:tot.va,val_ant:tot.vb}, true);
        h += '</tbody>';
        document.getElementById('g00-tabla-mensual').innerHTML = h;
    }
    function rowMensual(r, isTotal) {
        return '<tr class="'+(isTotal?'g00-total':'')+'">'
            + '<td>'+esc(r.mes)+'</td>'
            + '<td class="num">'+fmtInt(r.ups_ant)+'</td><td class="num">'+fmtInt(r.ups_act)+'</td>'
            + difCell(r.ups_act, r.ups_ant, fmtInt) + pctCell(r.ups_act, r.ups_ant)
            + '<td class="num">'+fmtMoneyFull(r.val_ant)+'</td><td class="num">'+fmtMoneyFull(r.val_act)+'</td>'
            + difCell(r.val_act, r.val_ant, fmtMoneyFull) + pctCell(r.val_act, r.val_ant)
            + '</tr>';
    }
    window.g00ModoInerte = function () {
        Swal.fire({icon:'info', title:'Modo no disponible', text:'Por ahora solo está activo el modo "Same".', confirmButtonColor:'#4A4782'});
    };
```

- [ ] **Step 5: Reescribir `renderKpis` (3 KPIs) y `loadDetal`**

Reemplazar la función `renderKpis` (~395-409) por una versión de 3 KPIs:
```javascript
    function renderKpis(k, anio) {
        document.getElementById('g00-kpi-anio-1').textContent = anio;
        document.getElementById('g00-kpi-anio-2').textContent = anio;
        animate(document.getElementById('g00-kpi-tiendas'), k.tiendas_actual, (n) => Math.round(n).toString());
        animate(document.getElementById('g00-kpi-ticket'),  k.ticket_prom,    fmtMoneyFull);
        animate(document.getElementById('g00-kpi-margen'),  k.margen_prom,    (n) => n.toFixed(2) + '%');
    }
```
Reemplazar la función `loadDetal` (~551-574) por:
```javascript
    function loadDetal() {
        showLoading('Cargando dashboard');
        fetch('api/informe_g00.php?' + buildParams(), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { hideLoading(); showError(data.error || 'Error cargando datos'); return; }
                const host = document.getElementById('page-informes-g00');
                const banner = host.querySelector('.g00-error'); if (banner) banner.remove();
                populateSelects(data.catalogos);
                proveedorActual = data.proveedor || '';
                document.getElementById('g00-proveedor').textContent = data.proveedor || '—';
                const r0 = data.rango || {};
                document.getElementById('g00-periodo').textContent = r0.desde_actual
                    ? 'Periodo: ' + r0.desde_actual + ' → ' + r0.hasta_actual + ' (vs ' + r0.desde_anterior + ' → ' + r0.hasta_anterior + ')'
                    : 'Datos al ' + new Date(data.generado).toLocaleDateString('es-CO');
                renderKpis(data.kpis, data.anio);
                renderTablaGrupo(data.por_grupo, data.anio);
                renderTablaMarcaTipo(data.por_marca, data.anio);
                renderTablaMensual(data.mensual, data.anio);
                tabState.detal = true;
                hideLoading();
            })
            .catch(err => { hideLoading(); showError('No se pudo cargar el informe: ' + err.message); });
    }
```

- [ ] **Step 6: Retirar las gráficas ECharts del Detal**

En el objeto `charts` (~359-364), quitar las entradas `mensual`, `grupo`, `marca` (ya no se usan en el Detal):
```javascript
    const charts = {
        treemapTiendas: null,
        calendar: null, dow: null, tendencia: null,
        treemapProd: null, pareto: null,
    };
```
Eliminar las funciones `initChartMensual` (~428-477) y `initChartBarras` (~479-522) **completas** (ya no las llama nadie tras reescribir `loadDetal`). Dejar intactas las funciones de los otros tabs (treemap tiendas, calendar, dow, tendencia, treemap productos, pareto).

- [ ] **Step 7: Verificar que no quedan referencias colgantes**

Run: `grep -n "initChartMensual\|initChartBarras\|charts.mensual\|charts.grupo\|charts.marca" informes/g00.php`
Expected: **sin coincidencias** (todas removidas). Si aparece alguna, eliminarla.

- [ ] **Step 8: Commit**

```bash
git add informes/g00.php
git commit -m "feat(g00): render 3 tablas comparativas y KPIs en Detal; retirar charts ECharts"
```

---

### Task 8: Verificación E2E manual + cierre

**Files:** (ninguno — verificación)

- [ ] **Step 1: Verificación funcional en el navegador (Rafael o quien tenga login)**

Con Apache corriendo, entrar a la plataforma, loguearse con un usuario de proveedor, abrir el informe (botón "Ventas") y validar:
- El botón del menú dice **Ventas** y el título **DASHBOARD DE VENTAS** (sin "G00"); el badge "G00" sigue en el header.
- Franja de **3 KPIs** (Tiendas, $Prom, MB con el año correcto) con valores reales.
- Toggle con **Same** activo; clic en Día a Día/Retail muestra el aviso "Modo no disponible".
- **Tabla Grupo Tiendas:** filas por grupo + Total, todas las columnas (Q/$/Dif/%/MB/$Prom/Tiendas/≠).
- **Tabla Marca/Tipo:** marcas con sus tipos anidados (sangría) + Total.
- **Tabla Mensual:** meses con datos + Total.
- Cambiar el rango de fechas y "Aplicar" recalcula las 3 tablas.
- Las pestañas Detalle Tiendas / Periodos / Productos siguen funcionando igual.

Expected: todo lo anterior se cumple; sin errores en consola del navegador.

- [ ] **Step 2: Sanity de números (Same)**

En la tabla, verificar que la fila **Total** de "Por Grupo Tiendas" y la de "Mensual" cuadran entre sí en $2026 (mismo universo Same). Para un proveedor sin ventas 2025, las columnas 2025 quedan en 0 pero 2026 muestra datos (no se anula).
Expected: coherencia entre tablas; 2026 no se anula por falta de 2025.

- [ ] **Step 3: Borrar el script de verificación temporal del cache (si aplica) y confirmar limpieza**

Run: `git status -s`
Expected: árbol limpio (todo commiteado); `tests/g00_detal_test.php` queda versionado como verificación reutilizable. No quedan archivos `_diag_*.php`.

---

## Self-Review

- **Spec coverage:**
  - A. Cosmética → Task 1. ✅
  - B. KPIs (3) + toggle → Task 6 (estructura) + Task 7 step 5 (KPIs). ✅
  - C. 3 tablas (Grupo / Marca→Tipo / Mensual con sus columnas + Total) → Task 7 steps 2-4. ✅
  - D. Same-store (operativas ambos años, NULL apertura) → Task 3 + verificación Task 5. ✅
  - E. Backend (TIPO en #refs, grouping (MARCA,TIPO), exponer campos, unidades mensuales, MB %) → Tasks 2 y 4. ✅
  - F. Filtros sin tocar → no hay tarea que los modifique. ✅
- **Placeholder scan:** sin TBD/TODO; todo el código (SQL, PHP, JS, HTML, CSS) está completo. La única decisión diferida en el spec (criterio exacto de fechas same-store) quedó fijada en Task 3 Step 1.
- **Type consistency:** claves JSON consistentes entre backend y front: `por_grupo`/`por_marca`(con `children`)/`mensual`; campos de fila `val_act,val_ant,ups_act,ups_ant,margen,tiendas_act,tiendas_ant`; mensual `val_act/val_ant/ups_act/ups_ant`; `kpis.tiendas_actual/ticket_prom/margen_prom`. Gid recalculados (31/7/27/29/28) coinciden entre Step 1 y Step 2 de Task 4. Funciones front (`renderTablaGrupo/MarcaTipo/Mensual`, `rowGrupo/rowMarca/rowMensual`, `difCell/pctCell/prom`) referenciadas en `loadDetal` están todas definidas en Task 7.
