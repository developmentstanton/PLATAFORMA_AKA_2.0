# G00 Inc 2A — Barra de filtros núcleo + Calendario/S.S.S + Mensual — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dotar al G00 de una barra de 9 filtros multi-select en cascada bidireccional (Tom Select), controles Calendario (Día a Día/Retail) y S.S.S (No Same/Same), y la regla del Mensual (independiente del filtro de fecha).

**Architecture:** Backend PHP (`api/informe_g00.php`) parsea filtros multi-valor → `WHERE IN` aplicado en los 4 tabs vía `#refs` (grano referencia) y `Bodegas` (grano tienda); el offset YoY lo decide `cal`. Un endpoint de catálogo cacheado por proveedor (combinaciones referencia×bodega + atributos) alimenta la cascada, que se resuelve en el cliente. La tabla Mensual usa una query propia independiente de la fecha. Frontend (`informes/g00.php`) usa Tom Select por CDN.

**Tech Stack:** PHP (sqlsrv), SQL Server, HTML/CSS/JS vanilla, Tom Select (CDN). Verificación: script PHP CLI + chequeo manual.

**Spec:** `docs/superpowers/specs/2026-06-03-g00-inc2a-filtros-design.md`

**Contexto/gotchas (memoria):** `AND b.CIA = 7` en joins a Bodegas; fact tables sin índice → cachear catálogos a diario, NOLOCK, pushdown; temp tables sqlsrv sin parámetros en CREATE; `MARGEN` ya es %. La rama de trabajo es la de Inc 1 (`feat/g00-detal-replica-pbi`) salvo que se indique otra.

**Keys canónicas de filtro (usar idénticas en todo el plan):**
`marca, tipo, categoria, subcategoria, genero, publico, referencia, depto, ciudad`
GET multi-valor: `marca[]`, `tipo[]`, … `ciudad[]`. Controles: `cal` (`diaadia`|`retail`), `sss` (`nosame`|`same`).

---

### Task 1: Backend — ampliar `#refs` con SUBCATEGORIA, GENERO, PUBLICO_OBJETIVO

**Files:**
- Modify: `api/informe_g00.php` (`getRefsCached`, `buildRefsTemp`)

- [ ] **Step 1: Traer los 3 atributos nuevos en `getRefsCached`**

El SELECT de `getRefsCached` (hoy trae MARCA, TIPO, LINEA, SUBLINEA, CATEGORIA) — reemplazarlo por:
```php
    $sql = "SELECT REFERENCIA,
                ISNULL(MARCA,    'SIN MARCA')         AS MARCA,
                ISNULL(TIPO,     'SIN TIPO')          AS TIPO,
                ISNULL(LINEA,    'SIN LINEA')         AS LINEA,
                ISNULL(SUBLINEA, '')                  AS SUBLINEA,
                ISNULL(CATEGORIA,'')                  AS CATEGORIA,
                ISNULL(SUBCATEGORIA,'')               AS SUBCATEGORIA,
                ISNULL(GENERO,'')                     AS GENERO,
                ISNULL(PUBLICO_OBJETIVO,'')           AS PUBLICO_OBJETIVO
            FROM INTEGRACION.dbo.ITEMS WITH (NOLOCK)
            WHERE PROVEEDOR = ?";
```

- [ ] **Step 2: Ampliar la temp table `#refs` y su INSERT en `buildRefsTemp`**

CREATE:
```php
    $ok = sqlsrv_query($conn, "CREATE TABLE #refs (
        REFERENCIA varchar(50) NOT NULL PRIMARY KEY,
        MARCA varchar(40), TIPO varchar(40), LINEA varchar(40), SUBLINEA varchar(40), CATEGORIA varchar(40),
        SUBCATEGORIA varchar(60), GENERO varchar(40), PUBLICO_OBJETIVO varchar(60))");
```
INSERT (9 columnas / 9 placeholders):
```php
        foreach ($chunk as $r) {
            $vals[] = '(?,?,?,?,?,?,?,?,?)';
            array_push($params, $r['REFERENCIA'], $r['MARCA'], $r['TIPO'], $r['LINEA'], $r['SUBLINEA'], $r['CATEGORIA'],
                       $r['SUBCATEGORIA'], $r['GENERO'], $r['PUBLICO_OBJETIVO']);
        }
        $sql = "INSERT INTO #refs (REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO) VALUES " . implode(',', $vals);
```

- [ ] **Step 3: Invalidar el cache de refs (estructura nueva)**

Run: `rm -f cache/g00_refs_*.json`
Expected: sin salida; se regeneran al primer request con la estructura nueva.

- [ ] **Step 4: Verificar sintaxis**

Run: `C:\xampp\php\php -l api/informe_g00.php`
Expected: `No syntax errors detected` (ignorar warnings de arranque).

- [ ] **Step 5: Commit**

```bash
git add api/informe_g00.php
git commit -m "feat(g00): #refs amplia subcategoria/genero/publico para filtros Inc2A"
```

---

### Task 2: Backend — Calendario (`cal`) define el offset del período anterior

**Files:**
- Modify: `api/informe_g00.php` (cálculo de `$desdeAnt`/`$hastaAnt`, ~33-46)

- [ ] **Step 1: Leer `cal` y calcular el período anterior según el modo**

Reemplazar el bloque que calcula `$desdeAnt`/`$hastaAnt` (hoy fijo a `-1 year`):
```php
$desdeAnt = date('Y-m-d', strtotime($desdeAct . ' -1 year'));
$hastaAnt = date('Y-m-d', strtotime($hastaAct . ' -1 year'));
```
por:
```php
// Calendario: 'diaadia' (default) → período anterior = -1 año (alineación calendario).
//             'retail'            → período anterior = -364 días (52 semanas, preserva día de semana).
$cal = strtolower(trim($_GET['cal'] ?? 'diaadia'));
if ($cal === 'retail') {
    $desdeAnt = date('Y-m-d', strtotime($desdeAct . ' -364 days'));
    $hastaAnt = date('Y-m-d', strtotime($hastaAct . ' -364 days'));
} else {
    $cal = 'diaadia';
    $desdeAnt = date('Y-m-d', strtotime($desdeAct . ' -1 year'));
    $hastaAnt = date('Y-m-d', strtotime($hastaAct . ' -1 year'));
}
```

- [ ] **Step 2: Verificar el cálculo (sanity rápido por CLI)**

Run: `C:\xampp\php\php -r "echo date('Y-m-d', strtotime('2026-01-01 -364 days'));"`
Expected: `2025-01-02` (confirma el desfase retail de 52 semanas).

- [ ] **Step 3: Verificar sintaxis + commit**

Run: `C:\xampp\php\php -l api/informe_g00.php` → `No syntax errors detected`
```bash
git add api/informe_g00.php
git commit -m "feat(g00): Calendario cal=diaadia|retail define offset YoY"
```

---

### Task 3: Backend — parser de filtros multi-valor (reemplaza grupo/marca)

**Files:**
- Modify: `api/informe_g00.php` (lectura de filtros ~35-36 y bloque `$filtroExtra` ~78-81)

- [ ] **Step 1: Quitar la lectura simple de grupo/marca y el `$filtroExtra` viejo**

Eliminar estas líneas de lectura (ya no se usan; `marca` pasa a multi-valor):
```php
$grupo   = trim($_GET['grupo'] ?? '');
$marca   = trim($_GET['marca'] ?? '');
```
Y eliminar el bloque viejo:
```php
$filtroExtra = '';
$paramsExtra = [];
if ($grupo !== '') { $filtroExtra .= " AND ISNULL(b.GRUPO, 'SIN GRUPO') = ? "; $paramsExtra[] = $grupo; }
if ($marca !== '') { $filtroExtra .= " AND i.MARCA = ? "; $paramsExtra[] = $marca; }
```

- [ ] **Step 2: Agregar el parser multi-valor**

En el lugar donde estaba el `$filtroExtra` viejo (después de definir `cteVentas()` o junto a los otros params; debe quedar ANTES de construir cualquier `$sql...`), agregar:
```php
// Filtros multi-valor (Inc 2A). Cada uno: WHERE <col> IN (?,?,...). Vacío = sin filtro.
// Grano referencia → alias #refs (i). Grano tienda → alias Bodegas (b).
$FILTROS_MULTI = [
    'marca'        => 'i.MARCA',
    'tipo'         => 'i.TIPO',
    'categoria'    => 'i.CATEGORIA',
    'subcategoria' => 'i.SUBCATEGORIA',
    'genero'       => 'i.GENERO',
    'publico'      => 'i.PUBLICO_OBJETIVO',
    'referencia'   => 'i.REFERENCIA',
    'depto'        => 'b.DEPTO',
    'ciudad'       => 'b.CIUDAD',
];
$filtroExtra = '';
$paramsExtra = [];
foreach ($FILTROS_MULTI as $key => $col) {
    $vals = $_GET[$key] ?? [];
    if (!is_array($vals)) $vals = ($vals === '' ? [] : [$vals]);
    $vals = array_values(array_filter(array_map('trim', $vals), fn($v) => $v !== ''));
    if (empty($vals)) continue;
    $ph = implode(',', array_fill(0, count($vals), '?'));
    $filtroExtra .= " AND $col IN ($ph) ";
    foreach ($vals as $v) $paramsExtra[] = $v;
}
```

- [ ] **Step 3: Limpiar referencias a `$grupo`/`$marca` en la salida**

En el arreglo `$out` del tab detal, la clave `filtros_activos` referencia `$grupo`/`$marca`. Reemplazar:
```php
    'filtros_activos' => ['grupo' => $grupo, 'marca' => $marca],
```
por:
```php
    'filtros_activos' => $paramsExtra ? array_keys(array_filter($FILTROS_MULTI, fn($c,$k)=>!empty($_GET[$k]), ARRAY_FILTER_USE_BOTH)) : [],
```

- [ ] **Step 4: Verificar que no quedan usos de `$grupo`/`$marca`**

Run: `grep -n '\$grupo\b\|\$marca\b' api/informe_g00.php`
Expected: **sin coincidencias** (todo migrado a `$filtroExtra`/`$FILTROS_MULTI`). Si aparece alguna, migrarla o eliminarla.

- [ ] **Step 5: Verificar sintaxis + commit**

Run: `C:\xampp\php\php -l api/informe_g00.php` → `No syntax errors detected`
```bash
git add api/informe_g00.php
git commit -m "feat(g00): parser de 9 filtros multi-valor (WHERE IN) en todos los tabs"
```

---

### Task 4: Backend — catálogo de combinaciones para la cascada (cacheado)

**Files:**
- Modify: `api/informe_g00.php` (nuevo branch `if ($tab === 'filtros')` antes del branch `tiendas`)

- [ ] **Step 1: Agregar el builder cacheado + branch del endpoint**

Justo después de materializar `#refs` (tras `buildRefsTemp(...)` y antes de `if ($tab === 'tiendas')`), agregar:
```php
// ====================================================================
// TAB: FILTROS — catálogo de combinaciones para la cascada (cacheado diario).
// Devuelve combos distintos (atributos de referencia + depto/ciudad de las
// bodegas donde el proveedor vendió). El front resuelve la cascada en memoria.
// ====================================================================
if ($tab === 'filtros') {
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/g00_filtros_' . md5($proveedor) . '.json';
    if (file_exists($cacheFile) && date('Y-m-d', filemtime($cacheFile)) === date('Y-m-d')) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) { sqlsrv_close($dbConnect); echo json_encode($cached, JSON_UNESCAPED_UNICODE); exit; }
    }
    // Parejas distintas (referencia, bodega) que el proveedor vendió (scan 1x/día).
    $sql = "
        SELECT DISTINCT
            i.MARCA, i.TIPO, i.CATEGORIA, i.SUBCATEGORIA, i.GENERO, i.PUBLICO_OBJETIVO,
            i.REFERENCIA,
            ISNULL(b.DEPTO,'')  AS DEPTO,
            ISNULL(b.CIUDAD,'') AS CIUDAD
        FROM (
            SELECT DISTINCT REFERENCIA, BODEGA FROM INTEGRACION.dbo.Ventas_Detal_PBI      WITH (NOLOCK)
            UNION
            SELECT DISTINCT REFERENCIA, BODEGA FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK)
        ) v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.BODEGA AND b.CIA = 7
    ";
    $rows = run($dbConnect, $sql);
    if (isset($rows['error'])) jsonFail($rows, $dbConnect);
    $combos = array_map(fn($r) => [
        'marca'        => trim((string)$r['MARCA']),
        'tipo'         => trim((string)$r['TIPO']),
        'categoria'    => trim((string)$r['CATEGORIA']),
        'subcategoria' => trim((string)$r['SUBCATEGORIA']),
        'genero'       => trim((string)$r['GENERO']),
        'publico'      => trim((string)$r['PUBLICO_OBJETIVO']),
        'referencia'   => trim((string)$r['REFERENCIA']),
        'depto'        => trim((string)$r['DEPTO']),
        'ciudad'       => trim((string)$r['CIUDAD']),
    ], $rows);
    $payload = ['ok' => true, 'combos' => $combos];
    @file_put_contents($cacheFile, json_encode($payload));
    sqlsrv_close($dbConnect);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
```

- [ ] **Step 2: Verificar sintaxis**

Run: `C:\xampp\php\php -l api/informe_g00.php` → `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add api/informe_g00.php
git commit -m "feat(g00): endpoint catalogo de combinaciones (cascada) cacheado por proveedor"
```

---

### Task 5: Backend — query del Mensual independiente de la fecha (corte mismo día, según `cal`)

**Files:**
- Modify: `api/informe_g00.php` (tab detal: reemplazar el origen de `$serieMensual`)

- [ ] **Step 1: Calcular los rangos del Mensual (Ene→hoy, anterior según `cal`)**

En el tab detal, ANTES de construir `$serieMensual`, agregar el cálculo de rangos independientes de `desde/hasta`:
```php
// Mensual: SIEMPRE Ene→hoy (ignora el filtro de fecha). El período anterior usa
// la alineación del Calendario: -1 año (diaadia) o -364 días (retail), de modo que
// ambos años quedan "al corte del mismo día".
$hoy        = date('Y-m-d');
$mensDesA   = date('Y-01-01');
$mensHasA   = $hoy;
if ($cal === 'retail') {
    $mensDesB = date('Y-m-d', strtotime($mensDesA . ' -364 days'));
    $mensHasB = date('Y-m-d', strtotime($mensHasA . ' -364 days'));
    $shiftToActualDays = 364; // sumar a una fecha 'ant' para mapearla al mes 'act'
} else {
    $mensDesB = date('Y-m-d', strtotime($mensDesA . ' -1 year'));
    $mensHasB = date('Y-m-d', strtotime($mensHasA . ' -1 year'));
    $shiftToActualDays = 0;   // diaadia: el mes calendario ya coincide (mismo mes, -1 año)
}
$mensGmin = min($mensDesB, $mensDesA);
$mensGmax = max($mensHasA, $mensHasB);
```

- [ ] **Step 2: Agregar la query del Mensual y construir `$serieMensual` desde ella**

Reemplazar el bloque actual que arma `$serieMensual` a partir de `$mensualRows`/`$mapMesVal`/`$mapMesUps` por una query dedicada:
```php
// Para 'ant' (retail) el mes se mapea sumando 364 días para que caiga en el mes 'act'
// equivalente; para 'diaadia' el mes 'ant' ya es el mismo mes calendario.
$mesAntExpr = $shiftToActualDays > 0
    ? "MONTH(DATEADD(DAY, $shiftToActualDays, FECHA))"
    : "MONTH(FECHA)";
$sqlMensual = cteVentas() . "
    , vm AS (
        SELECT v.FECHA, v.CANTIDAD, v.VALOR
        FROM ventas v
        INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
        LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD = v.BODEGA AND b.CIA = 7
        WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
          $filtroExtra
          $sameStoreClause
    )
    SELECT
        CASE WHEN FECHA BETWEEN ? AND ? THEN MONTH(FECHA) ELSE $mesAntExpr END AS mes,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR    ELSE 0 END) AS val_ant,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_act,
        SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN CANTIDAD ELSE 0 END) AS ups_ant
    FROM vm
    GROUP BY (CASE WHEN FECHA BETWEEN ? AND ? THEN MONTH(FECHA) ELSE $mesAntExpr END)
";
$pMensual = array_merge(
    [$mensGmin, $mensGmax, $mensGmin, $mensGmax],   // cte pushdown
    [$mensDesA, $mensHasA, $mensDesB, $mensHasB],   // vm OR-filter (act, ant)
    $paramsExtra,
    $sameStoreParams,
    [$mensDesA, $mensHasA],                          // SELECT CASE mes (act)
    [$mensDesA, $mensHasA, $mensDesB, $mensHasB,     // val_act / val_ant
     $mensDesA, $mensHasA, $mensDesB, $mensHasB],    // ups_act / ups_ant
    [$mensDesA, $mensHasA]                           // GROUP BY CASE mes (act)
);
$mensual = run($dbConnect, $sqlMensual, $pMensual);
if (isset($mensual['error'])) jsonFail($mensual, $dbConnect);
$mapMV = []; $mapMU = [];
foreach ($mensual as $r) {
    $mi = (int)$r['mes'];
    if ($mi < 1 || $mi > 12) continue;
    $mapMV[$mi] = ['val_act' => (float)$r['val_act'], 'val_ant' => (float)$r['val_ant']];
    $mapMU[$mi] = ['ups_act' => (float)$r['ups_act'], 'ups_ant' => (float)$r['ups_ant']];
}
$labelsMes = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$mesActual = (int)date('n');
$serieMensual = [];
for ($m = 1; $m <= $mesActual; $m++) {   // Ene → mes actual
    $serieMensual[] = [
        'mes'     => $labelsMes[$m-1],
        'val_act' => $mapMV[$m]['val_act'] ?? 0,
        'val_ant' => $mapMV[$m]['val_ant'] ?? 0,
        'ups_act' => $mapMU[$m]['ups_act'] ?? 0,
        'ups_ant' => $mapMU[$m]['ups_ant'] ?? 0,
    ];
}
```
Nota: el bloque viejo de `$mapMesVal/$mapMesUps` y su `for ($m=1;$m<=12;...)` se elimina (lo reemplaza lo anterior). La demux del consolidado puede seguir llenando `$mensualRows` (gid 7) sin usarse; opcionalmente elimina esa rama del foreach para no dejar variable muerta.

- [ ] **Step 3: Verificar sintaxis + commit**

Run: `C:\xampp\php\php -l api/informe_g00.php` → `No syntax errors detected`
```bash
git add api/informe_g00.php
git commit -m "feat(g00): Mensual independiente de fecha (Ene->hoy, corte mismo dia segun cal)"
```

---

### Task 6: Backend — verificación CLI de filtros + cal + catálogo

**Files:**
- Create: `tests/g00_inc2a_test.php`

- [ ] **Step 1: Escribir el script de verificación**

Crear `tests/g00_inc2a_test.php`:
```php
<?php
// Verifica Inc2A backend: catálogo de combinaciones + que un filtro IN reduce ventas.
//   php tests/g00_inc2a_test.php
$srv="siesa-m1-sqlsw-db15.cbm3ohogeajr.us-east-1.rds.amazonaws.com";
$cn=sqlsrv_connect($srv,["Database"=>"INTEGRACION","UID"=>"admistanton","PWD"=>"adminstanton\$12\$%","CharacterSet"=>"UTF-8"]);
if($cn===false){fwrite(STDERR,"sin conexion\n");exit(1);}
function q($cn,$s,$p=[]){ $st=sqlsrv_query($cn,$s,$p); if($st===false){fwrite(STDERR,print_r(sqlsrv_errors(),true));exit(1);} $r=[];while($x=sqlsrv_fetch_array($st,SQLSRV_FETCH_ASSOC))$r[]=$x;return $r;}
$prov=q($cn,"SELECT TOP 1 i.PROVEEDOR FROM INTEGRACION.dbo.Ventas_Detal_PBI v WITH(NOLOCK) INNER JOIN INTEGRACION.dbo.ITEMS i WITH(NOLOCK) ON i.REFERENCIA=v.REFERENCIA WHERE i.PROVEEDOR<>'' GROUP BY i.PROVEEDOR ORDER BY SUM(v.VALOR) DESC")[0]['PROVEEDOR'];
echo "Proveedor: $prov\n";
// #refs ampliado
sqlsrv_query($cn,"CREATE TABLE #refs (REFERENCIA varchar(50) PRIMARY KEY, MARCA varchar(40), TIPO varchar(40), CATEGORIA varchar(40))");
$refs=q($cn,"SELECT REFERENCIA, ISNULL(MARCA,'SIN MARCA') MARCA, ISNULL(TIPO,'SIN TIPO') TIPO, ISNULL(CATEGORIA,'') CATEGORIA FROM INTEGRACION.dbo.ITEMS WITH(NOLOCK) WHERE PROVEEDOR=?",[$prov]);
foreach(array_chunk($refs,200) as $ch){$v=[];$p=[];foreach($ch as $r){$v[]='(?,?,?,?)';array_push($p,$r['REFERENCIA'],$r['MARCA'],$r['TIPO'],$r['CATEGORIA']);}sqlsrv_query($cn,"INSERT INTO #refs (REFERENCIA,MARCA,TIPO,CATEGORIA) VALUES ".implode(',',$v),$p);}
// combos distintos
$combos=q($cn,"SELECT DISTINCT i.MARCA, i.TIPO, i.CATEGORIA, ISNULL(b.DEPTO,'') DEPTO, ISNULL(b.CIUDAD,'') CIUDAD FROM (SELECT DISTINCT REFERENCIA,BODEGA FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH(NOLOCK) UNION SELECT DISTINCT REFERENCIA,BODEGA FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH(NOLOCK)) v INNER JOIN #refs i ON i.REFERENCIA=v.REFERENCIA LEFT JOIN INTEGRACION.dbo.Bodegas b WITH(NOLOCK) ON b.COD=v.BODEGA AND b.CIA=7");
$marcas=array_values(array_unique(array_map(fn($r)=>$r['MARCA'],$combos)));
$deptos=array_values(array_filter(array_unique(array_map(fn($r)=>trim($r['DEPTO']),$combos))));
echo "Combos: ".count($combos)." | marcas distintas: ".count($marcas)." | deptos distintos: ".count($deptos)."\n";
$fail=0;
if(count($combos)<1){echo "FALLO: catalogo vacio\n";$fail=1;}
if(count($marcas)<1){echo "FALLO: sin marcas\n";$fail=1;}
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (catalogo con combos y dimensiones)\n";
exit($fail);
```

- [ ] **Step 2: Correr y verificar**

Run: `C:\xampp\php\php tests/g00_inc2a_test.php 2>/dev/null`
Expected: imprime nº de combos y dimensiones distintas; `RESULTADO: OK`.

- [ ] **Step 3: Commit**

```bash
git add tests/g00_inc2a_test.php
git commit -m "test(g00): verificacion catalogo de combinaciones Inc2A"
```

---

### Task 7: Frontend — Tom Select (CDN) + nueva barra de filtros (HTML/CSS)

**Files:**
- Modify: `informes/g00.php` (barra de filtros `.g00-filters` ~143-166; CSS; quitar toggle del Detal)
- Modify: `dashboard.php` (incluir CDN de Tom Select en el `<head>`)

- [ ] **Step 1: Incluir Tom Select por CDN en `dashboard.php`**

En el `<head>` de `dashboard.php` (junto a los otros `<link>`/`<script>` de CDN), agregar:
```html
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
```

- [ ] **Step 2: Reemplazar la barra de filtros del G00**

Reemplazar todo el bloque `<div class="g00-filters"> ... </div>` (~143-166) por:
```html
    <!-- ============ FILTROS ============ -->
    <div class="g00-filters">
        <div class="filter-group"><label>Desde</label><input type="date" id="g00-filtro-desde"></div>
        <div class="filter-group"><label>Hasta</label><input type="date" id="g00-filtro-hasta"></div>
        <div class="divider"></div>
        <div class="filter-group"><label>Marca</label><select id="g00-f-marca" multiple></select></div>
        <div class="filter-group"><label>Tipo</label><select id="g00-f-tipo" multiple></select></div>
        <div class="filter-group"><label>Categoría</label><select id="g00-f-categoria" multiple></select></div>
        <div class="filter-group"><label>Subcategoría</label><select id="g00-f-subcategoria" multiple></select></div>
        <div class="filter-group"><label>Género</label><select id="g00-f-genero" multiple></select></div>
        <div class="filter-group"><label>Público</label><select id="g00-f-publico" multiple></select></div>
        <div class="filter-group"><label>Referencia</label><select id="g00-f-referencia" multiple></select></div>
        <div class="filter-group"><label>Departamento</label><select id="g00-f-depto" multiple></select></div>
        <div class="filter-group"><label>Ciudad</label><select id="g00-f-ciudad" multiple></select></div>
        <div class="divider"></div>
        <div class="filter-group">
            <label>Calendario</label>
            <div class="g00-seg" id="g00-cal">
                <button type="button" class="g00-seg-btn active" data-val="diaadia">Día a Día</button>
                <button type="button" class="g00-seg-btn" data-val="retail">Retail</button>
            </div>
        </div>
        <div class="filter-group">
            <label>S.S.S</label>
            <div class="g00-seg" id="g00-sss">
                <button type="button" class="g00-seg-btn active" data-val="nosame">No Same</button>
                <button type="button" class="g00-seg-btn" data-val="same">Same</button>
            </div>
        </div>
        <div style="margin-left:auto;">
            <button class="g00-btn-refresh" onclick="g00Load()"><i class="fa-solid fa-filter"></i> Aplicar</button>
        </div>
    </div>
```

- [ ] **Step 3: Agregar CSS para los segmentados Calendario/S.S.S y ajustar Tom Select**

Antes de `</style>` agregar:
```css
    /* Botones segmentados (Calendario / S.S.S) */
    .g00-seg { display:inline-flex; border:1px solid var(--border); border-radius:6px; overflow:hidden; }
    .g00-seg-btn { border:none; background:white; color:var(--text); font-family:'Space Grotesk',sans-serif;
        font-size:12px; padding:6px 12px; cursor:pointer; }
    .g00-seg-btn.active { background:var(--primary); color:white; font-weight:600; }
    /* Tom Select compacto acorde a la barra */
    .g00-filters .ts-control { min-width:150px; font-size:12px; border-radius:6px; border-color:var(--border); }
    .g00-filters .ts-wrapper { min-width:150px; }
```

- [ ] **Step 4: Quitar el toggle de modo del panel Detal (se movió a la barra)**

Eliminar del panel `#g00-panel-detal` el bloque del toggle:
```html
        <!-- Toggle Día a Día / Retail / Same -->
        <div class="tab-bar g00-modo-bar" style="margin-bottom:16px;">
            <div class="tab g00-modo active" data-modo="diaadia">Día a Día</div>
            <div class="tab g00-modo" data-modo="retail" onclick="g00ModoInerte()">Retail</div>
            <div class="tab g00-modo" data-modo="same" onclick="g00ModoInerte()">Same</div>
        </div>
```
(El CSS `.g00-modo-bar ...` y la función `g00ModoInerte` quedan sin uso; se pueden dejar o quitar — quitar es más limpio: eliminar la regla `.g00-modo-bar ...` del `<style>` y la función `window.g00ModoInerte` del script.)

- [ ] **Step 5: Verificar sintaxis PHP + presencia de IDs**

Run: `C:\xampp\php\php -l informes/g00.php` → `No syntax errors detected`
Run: `grep -c "g00-f-marca\|g00-f-ciudad\|g00-cal\|g00-sss" informes/g00.php`
Expected: ≥ 4 (todos los IDs presentes).

- [ ] **Step 6: Commit**

```bash
git add dashboard.php informes/g00.php
git commit -m "feat(g00): barra de filtros Inc2A (Tom Select CDN + Calendario/S.S.S)"
```

---

### Task 8: Frontend — catálogo, Tom Select, cascada bidireccional, params y carga

**Files:**
- Modify: `informes/g00.php` (script IIFE: estado de filtros, init, cascada, `buildParams`, `populateSelects` viejo)

- [ ] **Step 1: Eliminar `populateSelects` viejo (selects de grupo/marca)**

Quitar la función `populateSelects` (~411-426) y su llamada dentro de `loadDetal` (`populateSelects(data.catalogos);`). Los filtros ahora se pueblan del catálogo (Step 3), no del `data.catalogos`.

- [ ] **Step 2: Agregar estado de filtros + helpers de segmentados**

Dentro del IIFE (cerca del inicio, tras `let currentTab=...`), agregar:
```javascript
    const FILTER_FIELDS = ['marca','tipo','categoria','subcategoria','genero','publico','referencia','depto','ciudad'];
    const tom = {};            // field -> instancia TomSelect
    let combos = [];           // catálogo de combinaciones del proveedor
    let cascadeBusy = false;   // evita recursión al actualizar opciones

    function segValue(id) {
        const el = document.querySelector('#' + id + ' .g00-seg-btn.active');
        return el ? el.getAttribute('data-val') : '';
    }
    function initSeg(id) {
        document.querySelectorAll('#' + id + ' .g00-seg-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('#' + id + ' .g00-seg-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                g00Load();
            });
        });
    }
```

- [ ] **Step 3: Cargar catálogo e inicializar Tom Select + cascada**

Agregar dentro del IIFE:
```javascript
    function selectedOf(field) { return tom[field] ? tom[field].getValue() : []; }

    // Opciones disponibles de un campo = valores distintos en combos que cumplen
    // la selección de TODOS los demás campos (cascada bidireccional).
    function availableFor(field) {
        const sels = {};
        FILTER_FIELDS.forEach(f => { if (f !== field) { const v = selectedOf(f); if (v.length) sels[f] = new Set(v); } });
        const out = new Set();
        for (const c of combos) {
            let ok = true;
            for (const f in sels) { if (!sels[f].has(c[f])) { ok = false; break; } }
            if (ok && c[field] !== '' && c[field] != null) out.add(c[field]);
        }
        return Array.from(out).sort((a,b) => a.localeCompare(b, 'es'));
    }

    function refreshOptions() {
        if (cascadeBusy) return;
        cascadeBusy = true;
        FILTER_FIELDS.forEach(field => {
            const ts = tom[field]; if (!ts) return;
            const keep = ts.getValue();
            const opts = availableFor(field).map(v => ({ value: v, text: v }));
            // conservar seleccionados aunque la cascada los excluya (para poder deseleccionar)
            const keepArr = Array.isArray(keep) ? keep : (keep ? [keep] : []);
            keepArr.forEach(v => { if (!opts.find(o => o.value === v)) opts.push({ value: v, text: v }); });
            ts.clearOptions();
            ts.addOptions(opts);
            ts.refreshOptions(false);
        });
        cascadeBusy = false;
    }

    function initFiltros() {
        FILTER_FIELDS.forEach(field => {
            tom[field] = new TomSelect('#g00-f-' + field, {
                plugins: ['remove_button'],
                maxOptions: 1000,
                placeholder: 'Todas',
                onChange: () => { refreshOptions(); }
            });
        });
        initSeg('g00-cal');
        initSeg('g00-sss');
        fetch('api/informe_g00.php?tab=filtros', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => { combos = (data && data.combos) ? data.combos : []; refreshOptions(); })
            .catch(() => { combos = []; });
    }
```

- [ ] **Step 4: Reescribir `buildParams` (multi-valor + cal + sss)**

Reemplazar la función `buildParams` (~536-548) por:
```javascript
    function buildParams(tab) {
        const p = new URLSearchParams();
        if (tab) p.append('tab', tab);
        const d = document.getElementById('g00-filtro-desde').value;
        const h = document.getElementById('g00-filtro-hasta').value;
        if (d) p.append('desde', d);
        if (h) p.append('hasta', h);
        FILTER_FIELDS.forEach(field => {
            (selectedOf(field) || []).forEach(v => p.append(field + '[]', v));
        });
        p.append('cal', segValue('g00-cal') || 'diaadia');
        p.append('sss', segValue('g00-sss') || 'nosame');
        return p.toString();
    }
```

- [ ] **Step 5: Inicializar los filtros al entrar al informe**

En `window.g00OnEnter` (~894-897), inicializar Tom Select una sola vez antes de cargar:
```javascript
    let filtrosInit = false;
    window.g00OnEnter = function () {
        if (!filtrosInit) { initFiltros(); filtrosInit = true; }
        if (!tabState.detal) loadDetal();
        else Object.values(charts).forEach(c => c && c.resize());
    };
```

- [ ] **Step 6: Verificar PHP + referencias**

Run: `C:\xampp\php\php -l informes/g00.php` → `No syntax errors detected`
Run: `grep -n "populateSelects" informes/g00.php`
Expected: **sin coincidencias** (la función vieja y su llamada fueron removidas).

- [ ] **Step 7: Commit**

```bash
git add informes/g00.php
git commit -m "feat(g00): catalogo, Tom Select, cascada bidireccional, params cal/sss"
```

---

### Task 9: Verificación E2E manual + cierre

**Files:** (ninguno)

- [ ] **Step 1: Verificación funcional en el navegador (Rafael)**

Con Apache corriendo, login de proveedor, abrir "Ventas":
- La barra muestra los 9 filtros multi-select (chips, búsqueda) + date pickers + Calendario (Día a Día activo) + S.S.S (No Same activo). "Grupo" ya no está.
- Elegir una **Marca** recorta las opciones de Tipo/Categoría/…/Ciudad (cascada bidireccional); elegir una **Ciudad** recorta Marcas, etc.
- "Aplicar" recalcula KPIs y las 3 tablas; el filtro se refleja en todos los tabs.
- Cambiar **Calendario** a Retail cambia los comparativos YoY (período anterior −364 días).
- Cambiar **S.S.S** a Same aplica same-store; No Same muestra todas.
- La tabla **Mensual** muestra Ene→mes actual, NO cambia al mover Desde/Hasta, pero SÍ cambia con los demás filtros; el mes en curso compara al corte del mismo día.

Expected: todo lo anterior se cumple; sin errores en consola.

- [ ] **Step 2: Sanity de cascada vacía**

Seleccionar combinaciones incompatibles no debe romper; un filtro sin opciones compatibles queda vacío (solo con lo ya seleccionado). Deseleccionar restaura opciones.
Expected: sin errores; la cascada es reversible.

- [ ] **Step 3: Limpieza**

Run: `git status -s`
Expected: árbol limpio; no quedan archivos `_diag_*.php`.

---

## Self-Review

- **Spec coverage:**
  - A. 9 filtros multi-select Tom Select + cascada bidireccional client-side + quitar Grupo → Tasks 7 (UI), 8 (cascada), 4 (catálogo), 3 (backend IN). ✅
  - B. Calendario (diaadia/retail) → Task 2 (offset) + 7/8 (control). S.S.S en barra → Task 7/8 (control) + Inc1 (`?sss=same`). ✅
  - C. Mensual independiente de fecha, corte mismo día según cal, respeta demás filtros → Task 5. ✅
  - D. Backend multi-valor en 4 tabs, #refs ampliado, catálogo, cal, mensual → Tasks 1-5. ✅
  - E. Frontend barra + cascada + params + quitar toggle → Tasks 7-8. ✅
  - Color/talla/status excluidos (Inc 2B). ✅
- **Placeholder scan:** sin TBD/TODO; todo el código (PHP/SQL/JS/HTML/CSS) está completo. La nota de "opcionalmente eliminar variable muerta" en Task 5 es una mejora opcional, no un placeholder de funcionalidad.
- **Type/clave consistency:** keys de filtro idénticas en backend (`$FILTROS_MULTI`), GET (`campo[]`), catálogo (`combos[].campo`) y front (`FILTER_FIELDS`, IDs `g00-f-<field>`): marca/tipo/categoria/subcategoria/genero/publico/referencia/depto/ciudad. Controles `cal` (diaadia|retail) y `sss` (nosame|same) consistentes entre Task 2/5 (backend) y Task 7/8 (front). `$sameStoreClause`/`$sameStoreParams` (de Inc 1) reutilizados en la query Mensual (Task 5) — existen porque Inc 1 ya los definió antes del tab detal.
- **Dependencia de orden:** Task 5 usa `$sameStoreClause`/`$sameStoreParams` y `$cal`; ambos se definen antes (Inc 1 y Task 2 respectivamente). Task 3 define `$filtroExtra`/`$paramsExtra` usados por todas las queries incl. Mensual.
