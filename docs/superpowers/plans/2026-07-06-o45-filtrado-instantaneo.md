# o45 Filtrado Instantáneo — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que en o45 los filtros de dimensión se apliquen instantáneamente sin volver al servidor, cargando el dataset granular del proveedor una sola vez y re-agregando en el navegador.

**Architecture:** El backend gana un modo `tab=dataset` que devuelve el dataset granular del proveedor (grano cia,bodega,ref,color,talla + dims + medidas) una vez por rango de fechas. El frontend lo guarda en memoria y un módulo JS puro (`o45_aggregate.js`) filtra + re-agrega en cliente, produciendo la MISMA estructura `{filas,total,rango}` que hoy devuelve `tab=data` (que se conserva como fallback + oráculo). La paridad JS↔backend se blinda con un test golden Node.

**Tech Stack:** PHP 8 + sqlsrv (SQL Server), JS vanilla (navegador), Node para tests golden (`.mjs`, patrón ya usado en el repo).

## Global Constraints

- **PROHIBIDO modificar tablas SIESA** (`t***` en `stanton`/`Siesa_Cloud`). Solo `SELECT`. Toda estructura nueva vive en `INTEGRACION` o en la query.
- **Paridad exacta** con la agregación actual de `tab=data` (redondeo a 2 decimales donde aplica). El test golden es la red de seguridad.
- **`tab=data` se conserva** (fallback + oráculo). No eliminarlo en este piloto. **Decisión (Rafael, 2026-07-06):** se ACEPTA que el build de `#base` quede duplicado entre `tab=data` (inline, intacto) y `buildO45Dataset` durante el piloto; se limpia al jubilar `tab=data`. Un reviewer puede marcarlo como Minor conocido — NO es un defecto a corregir en este piloto.
- **Reusar** `buildRefsFromMat` (`api/lib_refs.php`) y `preciosPorRefs` (`api/lib_precios.php`). No duplicar lógica.
- **Frontera de filtros:** dimensiones (marca/tipo/categoria/subcategoria/genero/publico/referencia, grupo/tienda, negocio) = cliente/instantáneo; rango de fechas = recarga del dataset.
- `php -l` limpio en cada archivo PHP tocado; `WITH (NOLOCK)` en lecturas; `sqlsrv_free_stmt` tras cada statement.

---

## File Structure

- **Create `api/lib_o45_dataset.php`** — `buildO45Dataset($conn,$desde,$hasta)`: construye el dataset granular (reusa el build de `#inv_hist`/`#base` SIN podar por filtros, lo enriquece con dims + atributos de bodega). Devuelve filas planas + meta. Aquí vive la optimización P1.
- **Modify `api/informe_o45.php`** — nuevo `tab=dataset` que llama `buildO45Dataset`, arma `precioMap` (reusa `preciosPorRefs`) y emite JSON compacto (filas como arrays + columnas + mapa precios + meta). `tab=data` intacto.
- **Create `informes/o45_aggregate.js`** — `aggregateO45(dataset, filtros)`: filtra + agrega en JS → `{filas,total,rango}` idéntico a `tab=data`. Puro (sin DOM).
- **Modify `informes/o45.php`** — carga el dataset una vez (`o45Load`), re-agrega local (`o45Render`), cablea cambios de filtro a `o45Render` y cambios de fecha a `o45Load`. Incluye `<script src="informes/o45_aggregate.js">`.
- **Create `tests/verificar_o45_dataset.php`** — doble oráculo PHP: agregar el dataset en PHP == agregación de `tab=data`, por proveedor.
- **Create `tests/o45_aggregate.test.mjs`** — golden Node: `aggregateO45(dataset,filtros) === salida backend`, varios proveedores × combos de filtros, contra fixtures.
- **Create `tests/fixtures/o45/capturar_fixtures.php`** — captura `(dataset, salida tab=data)` por proveedor+filtros → `tests/fixtures/o45/*.json`.

---

## Task 1: `buildO45Dataset` — dataset granular sin filtrar + doble oráculo PHP

**Files:**
- Create: `api/lib_o45_dataset.php`
- Create: `tests/verificar_o45_dataset.php`
- Reference: `api/informe_o45.php:51-170` (build actual de `#inv_hist`/`#base`), `api/lib_refs.php`, `api/lib_precios.php`

**Interfaces:**
- Consumes: `buildRefsFromMat($conn,$proveedor)` (deja `#refs` poblado con TODAS las refs del proveedor), sesión con `#refs` viva.
- Produces:
  - `buildO45Dataset($conn,$desde,$hasta): array` → `['rows'=>[ ['cia'=>..,'bodega'=>..,'grupo'=>..,'tienda'=>..,'es_cedi'=>0|1,'referencia'=>..,'color'=>..,'talla'=>..,'marca'=>..,'tipo'=>..,'categoria'=>..,'subcategoria'=>..,'genero'=>..,'publico'=>..,'disponible'=>int,'hold'=>int,'ventas'=>int,'ventas30'=>int,'inv_hist'=>0|1], ... ], 'meta'=>['desde'=>..,'hasta'=>..,'dias'=>int,'modo_stock'=>'vivo'|'YYYY-MM-DD']]`. Requiere `#refs` ya construido; NO poda `#refs` (los filtros son del cliente). Aplica la exclusión siempre-on de bodegas ADMINISTRATIVAS no-CEDI.
  - `aggO45PHP(array $rows, array $meta): array` (en el mismo test, no en el lib) → `{filas,total}` replicando `tab=data`, usado como puente del doble oráculo.

- [ ] **Step 1: Escribir el test doble-oráculo (falla: lib no existe)**

Create `tests/verificar_o45_dataset.php`:

```php
<?php
/**
 * Doble oráculo: agregar el dataset granular (buildO45Dataset) en PHP debe dar
 * lo MISMO que la agregación de tab=data del backend, por proveedor. Read-only.
 * Uso: php tests/verificar_o45_dataset.php ["PROV A" "PROV B" ...]
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
require __DIR__ . '/../api/lib_o45_dataset.php';
if ($dbConnect === false) { fwrite(STDERR, "Conexión DB fallida\n"); exit(1); }

$desde = '2025-01-01';
$hasta = date('Y-m-d', strtotime('-1 day'));

// Agrega el dataset granular como lo hace tab=data (sin filtros), en PHP.
function aggO45PHP(array $rows, array $meta): array {
    $g = []; // negocio-key => acumulador
    foreach ($rows as $r) {
        $cedi = ($r['bodega'] === 'CEDI');
        $k = $r['cia'] . '|' . $r['referencia'] . '|' . $r['color'];
        if (!isset($g[$k])) $g[$k] = ['cia'=>$r['cia'],'referencia'=>$r['referencia'],'color'=>$r['color'],
            'negocio'=>$r['referencia'].'-'.$r['color'],'marca'=>$r['marca'],
            'ventas'=>0,'ventas30'=>0,'stock_cedi'=>0,'stock_tiendas'=>0,'_tallas'=>[],'_tiendas'=>[]];
        $a = &$g[$k];
        if ($r['marca'] > $a['marca']) $a['marca'] = $r['marca']; // MAX(marca)
        if (!$cedi) { $a['ventas'] += $r['ventas']; $a['ventas30'] += $r['ventas30']; $a['stock_tiendas'] += $r['disponible'] + $r['hold']; }
        else $a['stock_cedi'] += $r['disponible'] + $r['hold'];
        $activo = ($r['disponible'] + $r['hold'] > 0) || $r['inv_hist'] == 1 || $r['ventas'] != 0;
        if ($activo) {
            $a['_tallas'][$r['talla']] = 1;
            if (!in_array($r['grupo'], ['BODEGA','ADMINISTRATIVAS'], true)) $a['_tiendas'][$r['cia'].'-'.$r['bodega']] = 1;
        }
        unset($a);
    }
    $dias = $meta['dias'];
    $filas = []; $tot = ['ventas'=>0,'ventas30'=>0,'stock_cedi'=>0,'stock_tiendas'=>0,'total_stock'=>0]; $totTiendas = [];
    foreach ($g as $a) {
        $total_stock = $a['stock_cedi'] + $a['stock_tiendas'];
        $tiendas = count($a['_tiendas']);
        $filas[] = ['negocio'=>$a['negocio'],'referencia'=>$a['referencia'],'color'=>$a['color'],'marca'=>$a['marca'],
            'ventas'=>$a['ventas'],'tiendas'=>$tiendas,'ventas30'=>$a['ventas30'],
            'stock_cedi'=>$a['stock_cedi'],'stock_tiendas'=>$a['stock_tiendas'],'total_stock'=>$total_stock,
            'ind_inventario'=>$a['ventas30']>0 ? round($total_stock/$a['ventas30'],2) : null,
            'ind_ventas_mes'=>$tiendas>0 ? round(($a['ventas']/$tiendas)/($dias/30),2) : 0.0,
            'tallas'=>count($a['_tallas'])];
        $tot['ventas']+=$a['ventas']; $tot['ventas30']+=$a['ventas30']; $tot['stock_cedi']+=$a['stock_cedi'];
        $tot['stock_tiendas']+=$a['stock_tiendas']; $tot['total_stock']+=$total_stock;
        foreach ($a['_tiendas'] as $t=>$_) $totTiendas[$t]=1;
    }
    $tot['tiendas']=count($totTiendas);
    $tot['ind_inventario']=$tot['ventas30']>0 ? round($tot['total_stock']/$tot['ventas30'],2) : null;
    $tot['ind_ventas_mes']=$tot['tiendas']>0 ? round(($tot['ventas']/$tot['tiendas'])/($dias/30),2) : 0.0;
    usort($filas, fn($x,$y)=>$y['ind_ventas_mes']<=>$x['ind_ventas_mes']);
    return ['filas'=>$filas,'total'=>$tot];
}

// La verdad: llama al endpoint tab=data en-proceso (sesión simulada) y captura su JSON.
function backendTabData($conn, $prov, $desde, $hasta): ?array {
    // Reejecuta la agregación de tab=data vía el endpoint aislado.
    $cmd = sprintf('php %s/o45_tabdata_oraculo.php %s %s %s',
        escapeshellarg(__DIR__), escapeshellarg($prov), $desde, $hasta);
    $out = shell_exec($cmd);
    $j = json_decode((string)$out, true);
    return is_array($j) && isset($j['filas']) ? $j : null;
}

$provs = array_slice($argv, 1);
if (!$provs) $provs = ['BH BRANDS SAS','BRAHMA CONCEPT','CALZADO WALDOS','BELLINO'];

$fallos = 0;
foreach ($provs as $prov) {
    buildRefsFromMat($dbConnect, $prov);
    $ds = buildO45Dataset($dbConnect, $desde, $hasta);
    $nuevo = aggO45PHP($ds['rows'], $ds['meta']);
    sqlsrv_query($dbConnect, "IF OBJECT_ID('tempdb..#refs') IS NOT NULL DROP TABLE #refs");
    $viejo = backendTabData($dbConnect, $prov, $desde, $hasta);
    if ($viejo === null) { echo "[$prov] SKIP (oráculo no disponible)\n"; continue; }
    // Comparar filas por negocio (orden puede variar en empates de ind); normalizar por clave.
    $keyf = fn($f)=>$f['negocio'];
    $mvn = []; foreach ($nuevo['filas'] as $f) $mvn[$keyf($f)] = $f;
    $mvv = []; foreach ($viejo['filas'] as $f) $mvv[$keyf($f)] = $f;
    $dif = 0;
    foreach ($mvv as $k=>$fv) {
        $fn = $mvn[$k] ?? null;
        foreach (['ventas','tiendas','ventas30','stock_cedi','stock_tiendas','total_stock','ind_inventario','ind_ventas_mes','tallas'] as $c)
            if ($fn === null || (string)$fv[$c] !== (string)$fn[$c]) { $dif++; if ($dif<=5) echo "   DIFF [$k].$c viejo=".var_export($fv[$c],true)." nuevo=".var_export($fn[$c]??null,true)."\n"; break; }
    }
    $extra = count(array_diff_key($mvn,$mvv));
    printf("[%s] negocios nuevo=%d viejo=%d | difs=%d extra=%d | total.ventas n=%s v=%s\n",
        $prov, count($mvn), count($mvv), $dif, $extra, $nuevo['total']['ventas'], $viejo['total']['ventas']);
    if ($dif || $extra || (string)$nuevo['total']['ventas']!==(string)$viejo['total']['ventas']) $fallos++;
}
echo $fallos===0 ? "\nRESULTADO: doble-oráculo OK ✔\n" : "\nRESULTADO: $fallos proveedor(es) con diferencias ✗\n";
exit($fallos===0?0:1);
```

Y create `tests/o45_tabdata_oraculo.php` (aísla la salida de `tab=data` para el oráculo):

```php
<?php
// Ejecuta tab=data de o45 con sesión simulada y emite su JSON crudo (oráculo del doble-check).
error_reporting(0); ini_set('display_errors','0');
@session_start();
$_SESSION['usuario']='oraculo'; $_SESSION['proveedor']=$argv[1] ?? ''; $_SESSION['nit']='';
$_GET=['tab'=>'data','desde'=>$argv[2] ?? '2025-01-01','hasta'=>$argv[3] ?? date('Y-m-d',strtotime('-1 day'))];
$_REQUEST=$_GET;
include __DIR__ . '/../api/informe_o45.php';
```

- [ ] **Step 2: Correr el test y verlo fallar**

Run: `php tests/verificar_o45_dataset.php "BH BRANDS SAS"`
Expected: FAIL `Failed opening required '.../api/lib_o45_dataset.php'`.

- [ ] **Step 3: Implementar `api/lib_o45_dataset.php`**

Mueve el build de `#inv_hist`/`#base` (hoy inline en `informe_o45.php:51-170`, modo vivo/corte + Acum) a `buildO45Dataset`, **sin** las podas de filtros (líneas 42-49 y 190-211 del endpoint) pero **con** la exclusión siempre-on de ADMINISTRATIVAS no-CEDI (líneas 182-187), y agrega la query de enriquecido final:

```php
<?php
/**
 * Construye el dataset granular de o45 para filtrado en cliente: grano
 * (cia,bodega,ref,color,talla) + dims (de #refs y Bodegas) + medidas.
 * NO aplica filtros de usuario (marca/negocio/grupo/tienda): eso es del cliente.
 * SÍ aplica la exclusión siempre-on de bodegas ADMINISTRATIVAS (conservando CEDI).
 * Requiere #refs ya poblado (buildRefsFromMat) con TODAS las refs del proveedor.
 * Solo SELECT sobre SIESA/INTEGRACION (no modifica nada). Reusa la lógica de build
 * de informe_o45.php (modo vivo/corte, ventas + Acum, #inv_hist).
 */
if (!function_exists('buildO45Dataset')) {
    function buildO45Dataset($conn, $desde, $hasta): array {
        $w30desde = date('Y-m-d', strtotime($hasta . ' -29 days'));
        $dias = (int) floor((strtotime($hasta) - strtotime($desde)) / 86400) + 1; if ($dias < 1) $dias = 1;

        // Modo stock (idéntico a informe_o45.php:51-61)
        $fv = sqlsrv_query($conn, "SELECT TOP 1 CONVERT(varchar(10),FECHA,120) f FROM INTEGRACION.dbo.inv_actual_PBI WITH (NOLOCK)");
        $row = $fv ? sqlsrv_fetch_array($fv, SQLSRV_FETCH_ASSOC) : null;
        $fechaViva = ($row && !empty($row['f'])) ? $row['f'] : date('Y-m-d', strtotime('-1 day'));
        if ($hasta >= $fechaViva) { $modoStock = 'vivo'; $corteStock = null; }
        else { $modoStock = 'corte'; $finMes = date('Y-m-t', strtotime($hasta));
            $corteStock = ($hasta >= $finMes) ? $finMes : date('Y-m-t', strtotime(date('Y-m-01', strtotime($hasta)) . ' -1 day')); }

        // #base + #inv_hist (COPIAR EXACTO el SQL de informe_o45.php:63-179, modo $modoStock,
        // con la partición Acum de :93-96 y las CTE d/h de :99-131 y el INSERT de :133-179).
        // -- CREATE #base / #inv_hist, INSERT #inv_hist, INSERT #base (params en el mismo orden) --
        // [Reproducir literalmente las sentencias de esas líneas usando sqlsrv_query($conn,...).]

        // Exclusión siempre-on (informe_o45.php:182-187)
        sqlsrv_query($conn, "DELETE b FROM #base AS b
            INNER JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON rtrim(bo.COD)=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia
            WHERE rtrim(bo.GRUPO)='ADMINISTRATIVAS' AND b.bodega<>'CEDI'");

        // Enriquecido: grano + dims (de #refs) + atributos de bodega
        $sql = "SELECT b.cia, b.bodega, ISNULL(bo.GRUPO,'') grupo, ISNULL(bo.NOMBRE,'') tienda,
                   CASE WHEN b.bodega='CEDI' THEN 1 ELSE 0 END es_cedi,
                   b.referencia, b.color, b.talla,
                   r.MARCA marca, r.TIPO tipo, r.CATEGORIA categoria, r.SUBCATEGORIA subcategoria, r.GENERO genero, r.PUBLICO_OBJETIVO publico,
                   b.disponible, b.hold, b.ventas, b.ventas30, b.inv_hist
                FROM #base b
                 INNER JOIN #refs r ON r.REFERENCIA = b.referencia
                 LEFT JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia";
        $st = sqlsrv_query($conn, $sql);
        $rows = [];
        if ($st !== false) {
            while ($x = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) {
                $rows[] = [
                    'cia'=>rtrim((string)$x['cia']), 'bodega'=>rtrim((string)$x['bodega']),
                    'grupo'=>rtrim((string)$x['grupo']), 'tienda'=>rtrim((string)$x['tienda']),
                    'es_cedi'=>(int)$x['es_cedi'], 'referencia'=>rtrim((string)$x['referencia']),
                    'color'=>rtrim((string)$x['color']), 'talla'=>rtrim((string)$x['talla']),
                    'marca'=>rtrim((string)$x['marca']), 'tipo'=>rtrim((string)$x['tipo']),
                    'categoria'=>rtrim((string)$x['categoria']), 'subcategoria'=>rtrim((string)$x['subcategoria']),
                    'genero'=>rtrim((string)$x['genero']), 'publico'=>rtrim((string)$x['publico']),
                    'disponible'=>(int)$x['disponible'], 'hold'=>(int)$x['hold'],
                    'ventas'=>(int)$x['ventas'], 'ventas30'=>(int)$x['ventas30'], 'inv_hist'=>(int)$x['inv_hist'],
                ];
            }
            sqlsrv_free_stmt($st);
        }
        sqlsrv_query($conn, "IF OBJECT_ID('tempdb..#base') IS NOT NULL DROP TABLE #base");
        sqlsrv_query($conn, "IF OBJECT_ID('tempdb..#inv_hist') IS NOT NULL DROP TABLE #inv_hist");
        return ['rows'=>$rows, 'meta'=>['desde'=>$desde,'hasta'=>$hasta,'dias'=>$dias,
            'modo_stock'=>($modoStock==='vivo'?'vivo':$corteStock)]];
    }
}
```

> Nota de implementación: copiar textualmente el bloque `#base`/`#inv_hist` de `informe_o45.php:63-179` (CREATE, INSERT `#inv_hist`, CTE d/h vivo/corte, `ventas_src`/`ventas30_src` con `$acumV`/`$acumV30`, INSERT `#base` con el orden de params `[$corte?, $desde,$hasta, ($acumV?$desde,$hasta:), $w30desde,$hasta, ($acumV30?$w30desde,$hasta:)]`). No cambiar el SQL — solo moverlo.

- [ ] **Step 4: Correr el test y verlo pasar**

Run: `php tests/verificar_o45_dataset.php "BH BRANDS SAS" "CALZADO WALDOS" "BELLINO"`
Expected: `RESULTADO: doble-oráculo OK ✔`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add api/lib_o45_dataset.php tests/verificar_o45_dataset.php tests/o45_tabdata_oraculo.php
git commit -m "feat(o45): buildO45Dataset (dataset granular sin filtrar) + doble oraculo PHP"
```

---

## Task 2: Endpoint `tab=dataset`

**Files:**
- Modify: `api/informe_o45.php` (agregar rama `tab=dataset` antes de la rama `tab=data`, tras construir `#refs` en :39)
- Reference: `api/lib_o45_dataset.php`, `api/lib_precios.php`

**Interfaces:**
- Consumes: `buildO45Dataset($conn,$desde,$hasta)`, `preciosPorRefs($conn)`.
- Produces: respuesta JSON `{ ok:true, tab:'dataset', columnas:[...], filas:[[...],...], precios:{'ref|col':num}, rango:{desde,hasta,dias,modo_stock}, proveedor }` donde cada fila es un array en el orden de `columnas`.

- [ ] **Step 1: Escribir un smoke test del endpoint (falla: modo no existe)**

Create `tests/o45_dataset_smoke.php`:

```php
<?php
error_reporting(0); ini_set('display_errors','0');
@session_start();
$_SESSION['usuario']='smoke'; $_SESSION['proveedor']=$argv[1] ?? 'BH BRANDS SAS'; $_SESSION['nit']='';
$_GET=['tab'=>'dataset','desde'=>'2025-01-01','hasta'=>date('Y-m-d',strtotime('-1 day'))]; $_REQUEST=$_GET;
ob_start();
register_shutdown_function(function(){
    $j=json_decode(ob_get_contents(),true); if(ob_get_level())ob_end_clean();
    $ok = is_array($j) && ($j['tab']??'')==='dataset' && isset($j['columnas'],$j['filas'],$j['precios'],$j['rango'])
        && count($j['columnas'])===19 && (count($j['filas'])===0 || count($j['filas'][0])===count($j['columnas']));
    fwrite(STDERR, ($ok?'SMOKE OK':'SMOKE FAIL')." filas=".(is_array($j['filas']??null)?count($j['filas']):'?')."\n");
    exit($ok?0:1);
});
include __DIR__ . '/../api/informe_o45.php';
```

Run: `php tests/o45_dataset_smoke.php` → Expected: `SMOKE FAIL` (aún cae en `tab desconocido`).

- [ ] **Step 2: Implementar la rama `tab=dataset`**

En `api/informe_o45.php`, tras `require __DIR__ . '/lib_refs.php';` agregar `require __DIR__ . '/lib_precios.php';` y `require __DIR__ . '/lib_o45_dataset.php';`. Luego, **inmediatamente después** de construir `#refs` (`buildRefsFromMat`, :39) y ANTES de las podas de filtros (:41), insertar:

```php
if ($tab === 'dataset') {
    $ds = buildO45Dataset($dbConnect, $desde, $hasta);
    $precios = preciosPorRefs($dbConnect);
    sqlsrv_close($dbConnect);
    $columnas = ['cia','bodega','grupo','tienda','es_cedi','referencia','color','talla',
                 'marca','tipo','categoria','subcategoria','genero','publico',
                 'disponible','hold','ventas','ventas30','inv_hist'];
    $filas = array_map(fn($r) => array_map(fn($c) => $r[$c], $columnas), $ds['rows']);
    echo json_encode(['ok'=>true,'tab'=>'dataset','proveedor'=>$proveedorSesion,
        'columnas'=>$columnas,'filas'=>$filas,'precios'=>$precios,'rango'=>$ds['meta']], JSON_UNESCAPED_UNICODE);
    exit;
}
```

- [ ] **Step 3: Correr el smoke y verlo pasar**

Run: `php tests/o45_dataset_smoke.php "BH BRANDS SAS"` → Expected: `SMOKE OK filas=<n>` (n>0), exit 0.
Run: `php -l api/informe_o45.php` → Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add api/informe_o45.php tests/o45_dataset_smoke.php
git commit -m "feat(o45): endpoint tab=dataset (dataset granular + precios + meta, JSON compacto)"
```

---

## Task 3: Módulo JS `aggregateO45` + test golden Node

**Files:**
- Create: `informes/o45_aggregate.js`
- Create: `tests/fixtures/o45/capturar_fixtures.php`
- Create: `tests/o45_aggregate.test.mjs`

**Interfaces:**
- Produces (global browser + export Node): `aggregateO45(dataset, filtros)` donde
  `dataset = {columnas, filas, precios, rango}` (lo que devuelve `tab=dataset`) y
  `filtros = {marca:[],tipo:[],categoria:[],subcategoria:[],genero:[],publico:[],referencia:[],grupo:[],tienda:[],negocio:[]}`.
  Devuelve `{filas:[...], total:{...}, rango}` idéntico a `tab=data`.

- [ ] **Step 1: Capturar fixtures (dataset + salida tab=data)**

Create `tests/fixtures/o45/capturar_fixtures.php`:

```php
<?php
// Captura por proveedor: el dataset (tab=dataset) y la salida oráculo (tab=data), a JSON.
error_reporting(0); ini_set('display_errors','0');
function endpoint($tab,$prov){
    $cmd = sprintf('php %s/../../o45_call.php %s %s', __DIR__, escapeshellarg($tab), escapeshellarg($prov));
    return shell_exec($cmd);
}
$provs = ['BH BRANDS SAS','CALZADO WALDOS','BELLINO','BRAHMA CONCEPT'];
foreach ($provs as $p) {
    $slug = preg_replace('/[^a-z0-9]+/i','_', strtolower($p));
    file_put_contents(__DIR__ . "/dataset_$slug.json", endpoint('dataset',$p));
    file_put_contents(__DIR__ . "/tabdata_$slug.json", endpoint('data',$p));
    echo "capturado $p\n";
}
```

Create `tests/o45_call.php` (acepta filtros extra como pares `clave=valor` en `$argv[3..]`, p.ej. `marca=BRAHMA`):

```php
<?php
error_reporting(0); ini_set('display_errors','0');
@session_start();
$_SESSION['usuario']='cap'; $_SESSION['proveedor']=$argv[2] ?? ''; $_SESSION['nit']='';
$g=['tab'=>$argv[1] ?? 'data','desde'=>'2025-01-01','hasta'=>date('Y-m-d',strtotime('-1 day'))];
foreach (array_slice($argv,3) as $kv){ [$k,$v]=array_pad(explode('=',$kv,2),2,''); $g[$k][]=$v; } // filtros multi-valor
$_GET=$g; $_REQUEST=$g;
include __DIR__ . '/../api/informe_o45.php';
```

**Capturar también oráculos CON filtros** (la parte que importa del piloto). En `capturar_fixtures.php`, además del dataset y el `tabdata` sin filtros, capturar 2-3 salidas de `tab=data` con filtros reales derivados del dataset del proveedor (una marca, un grupo) y guardarlas como `tabdata_<slug>__<filtro>.json` junto con el filtro aplicado (`filtros_<slug>__<filtro>.json`), llamando p.ej. `endpoint('data', $p, ['marca='.$marca])`. Así el golden compara la agregación JS **filtrada** contra el backend **filtrado**, no solo el caso vacío.

Run: `php tests/fixtures/o45/capturar_fixtures.php` → genera `dataset_*.json`, `tabdata_*.json` y los `tabdata_*__*.json` filtrados.

- [ ] **Step 2: Escribir el test golden (falla: módulo no existe)**

Create `tests/o45_aggregate.test.mjs`:

```javascript
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import assert from 'node:assert/strict';
import { aggregateO45 } from '../informes/o45_aggregate.js';

const here = dirname(fileURLToPath(import.meta.url));
const fx = join(here, 'fixtures', 'o45');
const slugs = readdirSync(fx).filter(f => f.startsWith('dataset_')).map(f => f.slice('dataset_'.length, -'.json'.length));

const NUM = ['ventas','tiendas','ventas30','stock_cedi','stock_tiendas','total_stock','ind_inventario','ind_ventas_mes','tallas'];
const norm = v => v == null ? null : (typeof v === 'number' ? Math.round(v*100)/100 : v);

// Combos de filtros a probar (vacío = todo; y un par de subconjuntos derivados del propio dataset).
function combos(dataset) {
    const col = i => dataset.filas.map(r => r[i]);
    const idx = n => dataset.columnas.indexOf(n);
    const uniq = a => [...new Set(a)].filter(x => x !== '' && x != null);
    const marcas = uniq(col(idx('marca')));
    const grupos = uniq(col(idx('grupo')));
    return [
        {},                                              // sin filtros
        marcas.length ? { marca: [marcas[0]] } : {},     // una marca
        grupos.length ? { grupo: [grupos[0]] } : {},     // un grupo
    ];
}

function compara(got, oracle, ctx) {
    const gMap = new Map(got.filas.map(f => [f.negocio, f]));
    const oMap = new Map(oracle.filas.map(f => [f.negocio, f]));
    assert.equal(gMap.size, oMap.size, `${ctx}: #negocios difiere`);
    for (const [k, of] of oMap) {
        const gf = gMap.get(k); assert.ok(gf, `${ctx}: falta negocio ${k}`);
        for (const c of NUM) assert.equal(norm(gf[c]), norm(of[c]), `${ctx}: ${k}.${c}`);
    }
    for (const c of ['ventas','tiendas','total_stock','ind_ventas_mes'])
        assert.equal(norm(got.total[c]), norm(oracle.total[c]), `${ctx}: total.${c}`);
}

let n = 0;
for (const slug of slugs) {
    const dataset = JSON.parse(readFileSync(join(fx, `dataset_${slug}.json`)));
    // Caso SIN filtros vs oráculo sin filtros.
    compara(aggregateO45(dataset, {}), JSON.parse(readFileSync(join(fx, `tabdata_${slug}.json`))), `${slug}[sinfiltro]`);
    // Casos CON filtros: cada tabdata_<slug>__<f>.json tiene su filtros_<slug>__<f>.json.
    for (const ff of readdirSync(fx).filter(f => f.startsWith(`tabdata_${slug}__`))) {
        const tag = ff.slice(`tabdata_${slug}__`.length, -'.json'.length);
        const filtros = JSON.parse(readFileSync(join(fx, `filtros_${slug}__${tag}.json`)));
        compara(aggregateO45(dataset, filtros), JSON.parse(readFileSync(join(fx, ff))), `${slug}[${tag}]`);
    }
    // Smoke de combos derivados (no rompe).
    for (const f of combos(dataset)) assert.ok(Array.isArray(aggregateO45(dataset, f).filas));
    n++;
    console.log(`OK ${slug}`);
}
console.log(`\nGOLDEN o45_aggregate: ${n} proveedores (sin filtro + filtrados) ✔`);
```

Run: `node tests/o45_aggregate.test.mjs` → Expected: FAIL `Cannot find module '../informes/o45_aggregate.js'`.

- [ ] **Step 3: Implementar `informes/o45_aggregate.js`**

```javascript
// Agregación de o45 en cliente: filtra el dataset granular y reproduce EXACTO tab=data.
// dataset = {columnas, filas (array de arrays), precios {'ref|col':num}, rango {desde,hasta,dias,modo_stock}}
// filtros = {marca:[],tipo:[],categoria:[],subcategoria:[],genero:[],publico:[],referencia:[],grupo:[],tienda:[],negocio:[]}
function aggregateO45(dataset, filtros) {
    const C = {}; dataset.columnas.forEach((n, i) => C[n] = i);
    const f = filtros || {};
    const has = k => Array.isArray(f[k]) && f[k].length > 0;
    const set = k => new Set(f[k]);
    const refDims = ['marca','tipo','categoria','subcategoria','genero','publico','referencia'];
    const activeRef = refDims.filter(has).map(k => [C[k], set(k)]);
    const negSet = has('negocio') ? set('negocio') : null;
    const grpSet = has('grupo') ? set('grupo') : null;
    const tieSet = has('tienda') ? set('tienda') : null;
    const dias = dataset.rango.dias;

    const g = new Map();               // negocio-key -> acumulador
    const totTiendas = new Set();
    for (const row of dataset.filas) {
        const esCedi = row[C.es_cedi] === 1;
        // Filtros ref-dim (nivel referencia, aplican a TODAS las filas incl. CEDI)
        let keep = true;
        for (const [i, s] of activeRef) if (!s.has(row[i])) { keep = false; break; }
        if (!keep) continue;
        const negocio = row[C.referencia] + '-' + row[C.color];
        if (negSet && !negSet.has(negocio)) continue;
        // Filtros de bodega: CEDI siempre se conserva (igual que tab=data)
        if (grpSet && !esCedi && !grpSet.has(row[C.grupo])) continue;
        if (tieSet && !esCedi && !tieSet.has(row[C.tienda])) continue;

        const key = row[C.cia] + '|' + row[C.referencia] + '|' + row[C.color];
        let a = g.get(key);
        if (!a) { a = { cia: row[C.cia], referencia: row[C.referencia], color: row[C.color], negocio,
            marca: row[C.marca], ventas: 0, ventas30: 0, stock_cedi: 0, stock_tiendas: 0,
            tallas: new Set(), tiendas: new Set() }; g.set(key, a); }
        if (row[C.marca] > a.marca) a.marca = row[C.marca];              // MAX(marca)
        const disp = row[C.disponible], hold = row[C.hold], ven = row[C.ventas], v30 = row[C.ventas30];
        if (!esCedi) { a.ventas += ven; a.ventas30 += v30; a.stock_tiendas += disp + hold; }
        else a.stock_cedi += disp + hold;
        const activo = (disp + hold > 0) || row[C.inv_hist] === 1 || ven !== 0;
        if (activo) {
            a.tallas.add(row[C.talla]);
            const grp = row[C.grupo];
            if (grp !== 'BODEGA' && grp !== 'ADMINISTRATIVAS') {
                const t = row[C.cia] + '-' + row[C.bodega]; a.tiendas.add(t); totTiendas.add(t);
            }
        }
    }

    const r2 = x => Math.round(x * 100) / 100;
    const filas = [];
    const tot = { ventas: 0, ventas30: 0, stock_cedi: 0, stock_tiendas: 0, total_stock: 0 };
    for (const a of g.values()) {
        const total_stock = a.stock_cedi + a.stock_tiendas;
        const tiendas = a.tiendas.size;
        filas.push({ negocio: a.negocio, referencia: a.referencia, color: a.color, marca: a.marca,
            ventas: a.ventas, tiendas, ventas30: a.ventas30, stock_cedi: a.stock_cedi,
            stock_tiendas: a.stock_tiendas, total_stock,
            ind_inventario: a.ventas30 > 0 ? r2(total_stock / a.ventas30) : null,
            ind_ventas_mes: tiendas > 0 ? r2((a.ventas / tiendas) / (dias / 30)) : 0,
            tallas: a.tallas.size,
            precio: dataset.precios[a.referencia + '|' + a.color] ?? null });
        tot.ventas += a.ventas; tot.ventas30 += a.ventas30; tot.stock_cedi += a.stock_cedi;
        tot.stock_tiendas += a.stock_tiendas; tot.total_stock += total_stock;
    }
    tot.tiendas = totTiendas.size;
    tot.ind_inventario = tot.ventas30 > 0 ? r2(tot.total_stock / tot.ventas30) : null;
    tot.ind_ventas_mes = tot.tiendas > 0 ? r2((tot.ventas / tot.tiendas) / (dias / 30)) : 0;
    filas.sort((x, y) => y.ind_ventas_mes - x.ind_ventas_mes);
    return { filas, total: tot, rango: dataset.rango };
}

if (typeof module !== 'undefined' && module.exports) module.exports = { aggregateO45 };
export { aggregateO45 };
```

> Nota: el `export`/`module.exports` dual permite `import` en el test `.mjs` y `<script>` en el navegador (en navegador, `export` en un script clásico se ignora si se carga como módulo; cargar con `<script type="module">` o exponer `window.aggregateO45`). Para el navegador, además agregar al final: `if (typeof window !== 'undefined') window.aggregateO45 = aggregateO45;` y cargar el archivo con `<script>` normal quitando la línea `export` si diera error de sintaxis en script clásico — ver Task 4.

- [ ] **Step 4: Correr el golden y verlo pasar**

Run: `node tests/o45_aggregate.test.mjs`
Expected: `OK <slug>` por proveedor + `GOLDEN o45_aggregate: N proveedores ✔`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add informes/o45_aggregate.js tests/o45_aggregate.test.mjs tests/o45_call.php tests/fixtures/o45/
git commit -m "feat(o45): modulo JS aggregateO45 + test golden Node (paridad vs tab=data)"
```

---

## Task 4: Cablear el frontend a carga-única + filtrado local

**Files:**
- Modify: `informes/o45.php` (`o45Load`, nuevo `o45Render`, `currentFilters`, wiring de filtros/fechas, `<script>` del módulo)
- Reference: `informes/o45.php:139-149` (`o45Load`), `:126-131` (`renderKpis`), `:172-190` (`o45OnEnter`, `initFiltros`)

**Interfaces:**
- Consumes: `aggregateO45(dataset, filtros)` global; `renderTabla(d)`, `renderKpis(d)` existentes (consumen `{filas,total,rango}`).
- Produces: `window.__o45dataset` (dataset crudo en memoria), `o45Render()` (re-agrega local), `o45Load()` (fetch dataset).

- [ ] **Step 1: Cargar el módulo JS**

En `informes/o45.php`, cerca del `<script>` principal, agregar antes: `<script src="informes/o45_aggregate.js"></script>` y en `o45_aggregate.js` asegurar `if (typeof window !== 'undefined') window.aggregateO45 = aggregateO45;` (ya incluido). Si `export {...}` rompe el `<script>` clásico, envolver esa línea: `try{ /*module*/ }catch(e){}` no aplica a `export`; en su lugar servir el archivo tal cual y cargarlo con `<script type="module">` **no** expone globals — por eso se usa `window.aggregateO45`. Cargar con `<script src>` clásico y **eliminar** la línea `export { aggregateO45 };` del archivo servido no es viable (rompe el test). Solución: mantener el `export` y cargar en el navegador con `<script type="module">import {aggregateO45} from './o45_aggregate.js'; window.aggregateO45=aggregateO45;</script>`.

- [ ] **Step 2: Reemplazar `o45Load` por fetch de dataset + render local, y agregar `currentFilters`/`o45Render`**

Reemplazar `window.o45Load` (`:139-149`) por:

```javascript
    function currentFilters(){
        const g = id => { const el=document.getElementById(id); if(!el) return [];
            return Array.from(el.selectedOptions||[]).map(o=>o.value).filter(v=>v!==''); };
        // ids de los <select> de filtro de o45 (ver initFiltros); ajustar a los reales.
        return { marca:g('o45-f-marca'), tipo:g('o45-f-tipo'), categoria:g('o45-f-categoria'),
            subcategoria:g('o45-f-subcategoria'), genero:g('o45-f-genero'), publico:g('o45-f-publico'),
            referencia:g('o45-f-referencia'), grupo:g('o45-f-grupo'), tienda:g('o45-f-tienda'),
            negocio:g('o45-f-negocio') };
    }
    window.o45Render = function(){
        const cont=document.getElementById('o45-tabla');
        if(!window.__o45dataset){ o45Load(); return; }
        const d = window.aggregateO45(window.__o45dataset, currentFilters());
        d.proveedor = window.__o45dataset.proveedor;
        window.__o45last = d;
        renderTabla(d); renderKpis(d);
        const ayer = new Date(Date.now()-86400000).toISOString().slice(0,10);
        filtrosUI.setPeriodo('informes-o45', val('o45-vdesde')||'2025-01-01', val('o45-vhasta')||ayer);
        filtrosUI.render(document.getElementById('page-informes-o45'));
    };
    window.o45Load = function(){   // trae el dataset (1ª carga o cambio de fechas) y re-agrega
        showLoading();
        const p = new URLSearchParams({tab:'dataset', desde: val('o45-vdesde')||'2025-01-01', hasta: val('o45-vhasta')|| new Date(Date.now()-86400000).toISOString().slice(0,10)});
        fetch('api/informe_o45.php?'+p.toString(),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
            if(!d.ok){ document.getElementById('o45-tabla').innerHTML='<p style="padding:16px;color:var(--accent)">Error al cargar.</p>'; renderKpis(); return; }
            window.__o45dataset = d; if(d.proveedor) setTitle(d.proveedor);
            o45Render();
        }).catch(()=>{ document.getElementById('o45-tabla').innerHTML='<p style="padding:16px;color:var(--accent)">Error de red.</p>'; renderKpis(); }).finally(hideLoading);
    };
```

- [ ] **Step 3: Cablear filtros → `o45Render` (local) y fechas → `o45Load` (fetch)**

En `initFiltros` (donde hoy los `change` de los `<select>` de filtro llaman a `o45Load`), cambiar el handler de los filtros de dimensión a `o45Render`. Los inputs de fecha `#o45-vdesde`/`#o45-vhasta` y el botón refresh siguen llamando `o45Load` (recarga el dataset). Verificar que `o45OnEnter` (`:187`) siga: `if(!window.__o45dataset) o45Load();` (renombrar la guarda de `__o45last` a `__o45dataset`).

- [ ] **Step 4: Verificación E2E (manual, Rafael)**

En `localhost/plataforma_20`, entrar a Índice de Ventas con un aliado:
1. Carga inicial muestra datos (pocos segundos).
2. Cambiar filtro de marca/tienda/negocio → la tabla se actualiza **instantáneo** (sin spinner de red).
3. Los números coinciden con los de hoy (comparar contra `tab=data` en otra pestaña o antes del deploy).
4. Cambiar rango de fechas → recarga (spinner) y vuelve a estar instantáneo al filtrar.
Expected: filtros instantáneos, cifras idénticas.

- [ ] **Step 5: Commit**

```bash
git add informes/o45.php informes/o45_aggregate.js
git commit -m "feat(o45): frontend carga dataset 1 vez + filtrado/reagregacion instantanea en cliente"
```

---

## Task 5: P1 — optimizar el build de la 1ª carga (medición → palanca INTEGRACION)

**Files:**
- Modify: `api/lib_o45_dataset.php` (según la palanca elegida)
- Possibly create: `sql/00X_*.sql` (índice o materialización, si aplica), `tests/verificar_o45_build.php` (medición antes/después)

**Interfaces:** sin cambio de contrato — `buildO45Dataset` mantiene su firma y salida; solo cambia su implementación interna / estructuras de apoyo en INTEGRACION.

- [ ] **Step 1: Medir el build por fases (baseline)**

Escribir `tests/verificar_o45_build.php` que, por proveedor, cronometre: (a) `#inv_hist` INSERT, (b) `#base` INSERT, (c) el enriquecido, y reporte ms + filas. Correr para BH BRANDS / BRAHMA / STANTON. Registrar baseline.
Run: `php tests/verificar_o45_build.php` → registrar tiempos.

- [ ] **Step 2: Verificar si el ETL recrea las tablas `_PBI`/históricas**

Consultar metadatos y confirmar con Rafael cómo se refrescan `historico_inventarios_PBI`, `historico_hold_PBI`, `inv_actual_PBI`, `_hold_actual_PBI`, `Ventas_Detal_PBI` (¿`TRUNCATE`+`INSERT` conserva índices, o `DROP`/`SELECT INTO` los pierde?). Decide la palanca:
- Si conserva índices → **índice de apoyo** en `INTEGRACION` sobre las claves de join (ej. `historico_inventarios_PBI(REFERENCIA, FECHA, CIA)` incluyendo COLUMNA1/CANTIDAD) para habilitar *seek*.
- Si los recrea → **normalizar claves** en una **tabla materializada** propia (patrón `Items_Mat`, refrescada de noche) con las claves ya limpias, y join por seek contra ella.

- [ ] **Step 3: Aplicar la palanca elegida + medir**

Implementar el índice o la materialización (SQL en `sql/`), ajustar `buildO45Dataset` si usa la nueva estructura, y re-correr `tests/verificar_o45_build.php`. **Objetivo: build ~2-4s.** Re-correr `tests/verificar_o45_dataset.php` (doble oráculo) para confirmar que la optimización NO cambió resultados.
Expected: build más rápido + doble-oráculo sigue OK.

- [ ] **Step 4: Commit**

```bash
git add api/lib_o45_dataset.php sql/ tests/verificar_o45_build.php
git commit -m "perf(o45): acelerar build del dataset (palanca INTEGRACION) - build NNs -> Ms"
```

> Si tras la medición ninguna palanca segura dentro de INTEGRACION alcanza y la materialización nocturna no se aprueba, dejar el build en ~4-7s (ya cumple "unos segundos") y documentar el follow-up. P1 no bloquea P2.

---

## Deploy (tras E2E aprobado)

- Re-sync runtime a `plataforma_20_produccion`: `api/{informe_o45,lib_o45_dataset,lib_precios}.php`, `informes/{o45.php,o45_aggregate.js}` (los `tests/` NO se despliegan).
- Copia de `plataforma_20_produccion` al servidor de aliados (manual, Rafael).
- Merge de `feature/o45-filtrado-instantaneo` a `main` (`--no-ff`) + push.

## Self-review (cobertura del spec)

- Enfoque A (dataset + filtrado cliente): Tasks 2-4. ✔
- Contrato del dataset: Task 1-2. ✔
- Agregación cliente idéntica: Task 3 (golden). ✔
- Frontera de filtros (dims cliente / fecha recarga): Task 4 step 3. ✔
- 2º cuello / P1: Task 5. ✔
- Paridad (doble oráculo PHP + golden Node): Tasks 1, 3. ✔
- `tab=data` conservado: no se toca; se usa de oráculo. ✔
- SIESA intacto: solo SELECT; estructuras en INTEGRACION (Task 5). ✔
- Rollout piloto o45: todo el plan; generalización = fuera de alcance (post-piloto). ✔
