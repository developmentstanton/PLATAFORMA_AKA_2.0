# Análisis de Pagos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir el informe nativo "Análisis de Pagos" (sección PAGOS del portal), vista por proveedor de "¿cuándo me pagan?", replicando el Power BI P78.

**Architecture:** Patrón de informes existente (G00): página PHP en `informes/pagos.php` (UI+JS) incluida en `dashboard.php`, alimentada por `api/informe_pagos.php` (JSON). El endpoint une 3 fuentes (`Doc_Compra_PBI`, `FLUJO_OC_PBI`, `Anticipos_PBI`) filtradas por el NIT de la sesión, calcula fecha de pago/Días/En Pesos en SQL, y devuelve filas crudas + meses; el pivote mensual se arma en JS.

**Tech Stack:** PHP + `sqlsrv` (SQL Server RDS), JS vanilla, SheetJS (XLSX) y SweetAlert (Swal) ya cargados en dashboard, conexión `conexion/conexion_integracion.php` (cross-DB a `stanton`).

## Global Constraints

- El NIT se toma SIEMPRE de `$_SESSION['nit']`, NUNCA de un parámetro del cliente.
- Todas las lecturas SQL usan `WITH (NOLOCK)`.
- Las tablas `*_PBI` viven en `Integracion.dbo`; `t200/t202/t208` en `stanton.dbo`. Una sola conexión (`conexion_integracion.php`, variable `$dbConnect`) puede consultar ambas bases con nombres calificados.
- Columnas de las tablas SIESA traen espacios al final → `RTRIM` al filtrar/cruzar por NIT.
- Endpoint responde JSON UTF-8: `header('Content-Type: application/json; charset=utf-8')` y `json_encode(..., JSON_UNESCAPED_UNICODE)`.
- Forma de error estándar: `{"ok":false,"error":"<mensaje>"}`. Éxito: `{"ok":true, ...}`.
- Prefijo de nombres de la página/JS: `pg` (ej. `pgLoad`, `#page-informes-pagos`, ids `pg-*`).
- Artefactos de origen (SQL/M/DAX/catálogos): `docs/analisis_pagos/`.

---

## File Structure

- **Modify** `index.php` (login) — resolver y guardar `$_SESSION['nit']`.
- **Create** `api/informe_pagos.php` — endpoint JSON.
- **Create** `api/lib_trm.php` — helper de TRM con cache diario + fallback.
- **Create** `tests/_endpoint_run_pagos.php` — harness que setea `$_SESSION['nit']` e incluye el endpoint.
- **Create** `tests/pagos_unif_test.php` — filas unificadas + aislamiento por NIT + En Pesos.
- **Create** `tests/pagos_filtros_test.php` — filtros Causado / Días / Fecha.
- **Create** `tests/pagos_pivote_test.php` — meses presentes + consistencia de sumas.
- **Create** `informes/pagos.php` — página (HTML/CSS/JS).
- **Modify** `dashboard.php` — `nav-item` en sección PAGOS + `include` de la página.
- **Create** `cache/` (si no existe) — para el JSON diario de TRM.

**Payload del endpoint (contrato fijo entre API, tests y front):**
```json
{
  "ok": true,
  "nit": "860009034",
  "razon_social": "CURTIEMBRE RUFINO MELERO S A",
  "trm": {"USD": 4100.5, "EUR": 4500.2, "fecha": "2026-06-22", "fallback": false},
  "filas": [
    {"fecha_venc":"2025-08-22","dias":304,"documento":"OCA-001","causado":"NO",
     "moneda":"COP","valor":5093890,"en_pesos":5093890,
     "fecha_pago":"2026-06-26","anio_pago":2026,"mes_pago":6,"base":"Flujo Proyectado"}
  ],
  "meses": [{"anio":2026,"mes":6}]
}
```
`mes_pago` = entero 1-12 (el front mapea a nombre español). `filas` ordenadas por `fecha_venc` asc.

---

## Task 1: Endpoint base — guard de NIT + filas unificadas por NIT

**Files:**
- Create: `api/informe_pagos.php`
- Create: `tests/_endpoint_run_pagos.php`
- Create: `tests/pagos_unif_test.php`

**Interfaces:**
- Produces: endpoint GET `api/informe_pagos.php` que lee `$_SESSION['nit']` y devuelve `{ok, nit, razon_social, filas:[...]}` (sin filtros ni pivote todavía). Cada fila: `fecha_venc, dias, documento, causado, moneda, valor, en_pesos, fecha_pago, anio_pago, mes_pago, base`.
- Consumes: `conexion/conexion_integracion.php` → `$dbConnect`.

- [ ] **Step 1: Crear el harness de tests**

Crear `tests/_endpoint_run_pagos.php`:
```php
<?php
// Ejecuta el endpoint de Pagos como request real, seteando el NIT en sesión.
//   php tests/_endpoint_run_pagos.php "NIT" "querystring"
error_reporting(E_ALL); ini_set('display_errors', '0');
$nit = $argv[1] ?? '';
$qs  = $argv[2] ?? '';
session_start();
$_SESSION = ['usuario' => 'test', 'nit' => $nit];
parse_str($qs, $_GET);
include __DIR__ . '/../api/informe_pagos.php';
```

- [ ] **Step 2: Escribir el test que falla (filas + aislamiento por NIT)**

Crear `tests/pagos_unif_test.php` (usa dos NITs reales; el primero con datos). Reemplazar `NIT_A`/`NIT_B` por NITs verificados en `Anticipos_PBI`/`FLUJO_OC_PBI`:
```php
<?php
//   php tests/pagos_unif_test.php
$php = PHP_BINARY; $runner = __DIR__ . '/_endpoint_run_pagos.php';
$nul = (stripos(PHP_OS,'WIN')===0) ? 'NUL' : '/dev/null';
$NIT_A = getenv('PAGOS_NIT_A') ?: '860009034';
$NIT_B = getenv('PAGOS_NIT_B') ?: '900464619';
function call_ep($php,$runner,$nit,$qs,$nul){
  $cmd = escapeshellarg($php).' '.escapeshellarg($runner).' '.escapeshellarg($nit).' '.escapeshellarg($qs).' 2>'.$nul;
  $raw = (string)shell_exec($cmd);
  $a=strpos($raw,'{'); $b=strrpos($raw,'}');
  return json_decode(($a!==false&&$b!==false)?substr($raw,$a,$b-$a+1):$raw,true);
}
$fail=0;
$d = call_ep($php,$runner,$NIT_A,'',$nul);
if (!($d['ok']??false)) { echo "FALLO: ok=0 ".json_encode($d['error']??'')."\nRESULTADO: FALLO\n"; exit(1); }
$filas = $d['filas']??[];
echo "NIT_A=$NIT_A filas=".count($filas)."\n";
$campos = ['fecha_venc','dias','documento','causado','moneda','valor','en_pesos','fecha_pago','anio_pago','mes_pago','base'];
foreach (array_slice($filas,0,5) as $f) foreach ($campos as $c)
  if (!array_key_exists($c,$f)) { echo "FALLO: falta campo $c\n"; $fail=1; break 2; }
// Aislamiento: ninguna fila debe ser de otro NIT (el endpoint no expone nit por fila,
// pero comparamos que A y B difieran en conteo/razon_social).
$e = call_ep($php,$runner,$NIT_B,'',$nul);
if (($d['razon_social']??'x') === ($e['razon_social']??'y') && $NIT_A!==$NIT_B)
  { echo "FALLO: razon_social igual para NITs distintos\n"; $fail=1; }
// Sin sesión de NIT -> error
$z = call_ep($php,$runner,'','',$nul);
if (($z['ok']??true) !== false) { echo "FALLO: sin NIT debería dar ok=false\n"; $fail=1; }
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (filas con campos + aislamiento + guard NIT)\n";
exit($fail);
```

- [ ] **Step 3: Verificar que falla**

Run: `php tests/pagos_unif_test.php`
Expected: FALL A — el endpoint aún no existe (JSON nulo / ok=0).

- [ ] **Step 4: Implementar el endpoint base**

Crear `api/informe_pagos.php`. Para el SQL verbatim de cada fuente usar los artefactos:
- `docs` (Documentos_Compra): SQL en `docs/analisis_pagos/fuentes_powerbi_M.md` (bloque "Documentos_Compra"). Mantener su `WITH Base/BaseFinal/...`; **añadir** `AND RTRIM(C.nit) = ?` en el `WHERE` de `BaseFinal`.
- `flujo` (Flujo Proyectado): SQL en `docs/analisis_pagos/sql_flujo_proyectado_raw.txt`; **añadir** `WHERE RTRIM(NIT) = ?` en `CalculosIntermedios`.
- `antic` (Anticipos): leer `Anticipos_PBI` directo (inline abajo).

```php
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }
$nit = trim($_SESSION['nit'] ?? '');
if ($nit === '') { echo json_encode(['ok'=>false,'error'=>'No se pudo resolver el NIT del proveedor']); exit; }

require __DIR__ . '/../conexion/conexion_integracion.php';
if ($dbConnect === false) { echo json_encode(['ok'=>false,'error'=>'Conexión DB fallida']); exit; }

// --- SQL unificado: 3 subconsultas filtradas por NIT, normalizadas, UNION ALL ---
// docs: pegar el SELECT de "Documentos_Compra" (docs/analisis_pagos/fuentes_powerbi_M.md)
//       con AND RTRIM(C.nit)=? en BaseFinal, envuelto como sub-CTE que exponga:
//       CIA, FECHA_VENCIMIENTO(date), DOCUMENTO, NIT, PROVEEDOR, CLASE_PROVEEDOR,
//       TIPO_PROVEEDOR, CAUSADO, 'ENTRADA POR CAUSAR' DESCRIPCION, MONEDA, VALOR, FECHA_PAGO(date)
// flujo: pegar sql_flujo_proyectado_raw.txt con WHERE RTRIM(NIT)=? en CalculosIntermedios;
//        exponer FECHA(venc)=FECHA, FECHA_PAGO(date), etc.
// antic: inline.
$sql = "
SET NOCOUNT ON;
;WITH docs AS (
   /* >>> pegar SELECT de Documentos_Compra con AND RTRIM(C.nit)=? ; castear fechas a DATE <<< */
), flujo AS (
   /* >>> pegar SELECT de Flujo Proyectado con WHERE RTRIM(NIT)=? ; castear fechas a DATE <<< */
), antic AS (
   SELECT RTRIM(CIA) CIA, CONVERT(date, FECHA) FECHA_VENCIMIENTO, RTRIM(DOCTO) DOCUMENTO,
          RTRIM(NIT) NIT, RTRIM(RAZON_SOCIAL) PROVEEDOR, 'PUNTUAL' CLASE_PROVEEDOR,
          RTRIM(TIPO_PROVEEDOR) TIPO_PROVEEDOR, 'SI' CAUSADO, 'ANTICIPO' DESCRIPCION,
          'COP' MONEDA, ANTICIPO VALOR,
          /* próximo viernes desde hoy (mismo mapeo Lun=4..Vie=0 de las otras fuentes) */
          DATEADD(day, CASE DATENAME(dw, CONVERT(date,GETDATE()))
              WHEN 'Monday' THEN 4 WHEN 'Tuesday' THEN 3 WHEN 'Wednesday' THEN 2
              WHEN 'Thursday' THEN 1 WHEN 'Friday' THEN 0 WHEN 'Saturday' THEN 6
              WHEN 'Sunday' THEN 5 END, CONVERT(date,GETDATE())) FECHA_PAGO
   FROM Integracion.dbo.Anticipos_PBI WITH (NOLOCK)
   WHERE RTRIM(NIT) = ?
),
u AS (
   SELECT CIA, FECHA_VENCIMIENTO, DOCUMENTO, NIT, PROVEEDOR, CLASE_PROVEEDOR, TIPO_PROVEEDOR,
          CAUSADO, DESCRIPCION, MONEDA, VALOR, FECHA_PAGO, 'Documentos Compra' Base FROM docs
   UNION ALL
   SELECT CIA, FECHA_VENCIMIENTO, DOCUMENTO, NIT, PROVEEDOR, CLASE_PROVEEDOR, TIPO_PROVEEDOR,
          CAUSADO, DESCRIPCION, MONEDA, VALOR, FECHA_PAGO, 'Flujo Proyectado' Base FROM flujo
   UNION ALL
   SELECT CIA, FECHA_VENCIMIENTO, DOCUMENTO, NIT, PROVEEDOR, CLASE_PROVEEDOR, TIPO_PROVEEDOR,
          CAUSADO, DESCRIPCION, MONEDA, VALOR, FECHA_PAGO, 'Anticipos' Base FROM antic
)
SELECT
   CONVERT(varchar(10), FECHA_VENCIMIENTO, 23) fecha_venc,
   DATEDIFF(day, FECHA_VENCIMIENTO, CONVERT(date,GETDATE())) dias,
   DOCUMENTO documento, NIT nit, PROVEEDOR razon_social, CAUSADO causado,
   MONEDA moneda, VALOR valor,
   YEAR(FECHA_PAGO) anio_pago, MONTH(FECHA_PAGO) mes_pago,
   CONVERT(varchar(10), FECHA_PAGO, 23) fecha_pago,
   Base base
FROM u
ORDER BY FECHA_VENCIMIENTO ASC;";

// Parámetros: 1 NIT por cada sub-CTE que filtre (docs, flujo, antic) en orden de aparición.
$params = [$nit, $nit, $nit]; // ajustar si docs/flujo usan más de un '?'
$stmt = sqlsrv_query($dbConnect, $sql, $params);
if ($stmt === false) { echo json_encode(['ok'=>false,'error'=>'Consulta fallida','detalle'=>sqlsrv_errors()]); exit; }

$filas = []; $razon = '';
while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
  $razon = $razon ?: trim((string)$r['razon_social']);
  $filas[] = [
    'fecha_venc'=>$r['fecha_venc'], 'dias'=>(int)$r['dias'], 'documento'=>trim((string)$r['documento']),
    'causado'=>trim((string)$r['causado']), 'moneda'=>trim((string)$r['moneda']),
    'valor'=>(float)$r['valor'], 'en_pesos'=>(float)$r['valor'], // TRM en Task 2
    'fecha_pago'=>$r['fecha_pago'], 'anio_pago'=>(int)$r['anio_pago'], 'mes_pago'=>(int)$r['mes_pago'],
    'base'=>$r['base'],
  ];
}
sqlsrv_close($dbConnect);
echo json_encode(['ok'=>true,'nit'=>$nit,'razon_social'=>$razon,'filas'=>$filas], JSON_UNESCAPED_UNICODE);
```
Completar el "próximo viernes" en `antic` con el patrón: `DATEADD(day, (12 - DATEPART(weekday, GETDATE())) % 7 ... )` o un `CASE DATENAME(dw,...)` como en los otros bloques (mismo mapeo Lun=4..Vie=0). Reusar el mapeo de `sql_flujo_proyectado_raw.txt`.

- [ ] **Step 5: Verificar que pasa**

Run: `php tests/pagos_unif_test.php`
Expected: `RESULTADO: OK`. Si falla por NITs sin datos, exportar `PAGOS_NIT_A`/`PAGOS_NIT_B` con NITs que sí tengan filas (verificar con: `SELECT TOP 5 RTRIM(NIT),COUNT(*) FROM Integracion.dbo.FLUJO_OC_PBI GROUP BY RTRIM(NIT)`).

- [ ] **Step 6: Commit**

```bash
git add api/informe_pagos.php tests/_endpoint_run_pagos.php tests/pagos_unif_test.php
git commit -m "feat(pagos): endpoint base con filas unificadas por NIT"
```

---

## Task 2: TRM (En Pesos para USD/EU) con cache diario + fallback

**Files:**
- Create: `api/lib_trm.php`
- Modify: `api/informe_pagos.php` (usar TRM en `en_pesos` y exponer `trm` en payload)
- Test: `tests/pagos_unif_test.php` (añadir aserción En Pesos)

**Interfaces:**
- Produces: `function obtener_trm(): array` → `['USD'=>float,'EUR'=>float,'fecha'=>'YYYY-MM-DD','fallback'=>bool]`.
- Consumes: directorio `cache/`.

- [ ] **Step 1: Añadir aserción de En Pesos al test**

En `tests/pagos_unif_test.php`, antes de la línea de RESULTADO, agregar:
```php
foreach ($filas as $f) {
  if ($f['moneda']==='COP' && abs($f['en_pesos']-$f['valor'])>0.5) { echo "FALLO: COP en_pesos!=valor\n"; $fail=1; break; }
  if ($f['moneda']!=='COP' && $f['valor']!=0 && $f['en_pesos']==$f['valor']) { echo "FALLO: ".$f['moneda']." sin convertir\n"; $fail=1; break; }
}
if (!isset($d['trm']['fecha'])) { echo "FALLO: falta trm.fecha\n"; $fail=1; }
```

- [ ] **Step 2: Verificar que falla**

Run: `php tests/pagos_unif_test.php`
Expected: FALL — falta `trm` y En Pesos no convierte USD/EU.

- [ ] **Step 3: Implementar `api/lib_trm.php`**

```php
<?php
// TRM con cache diario en archivo + fallback al último valor conocido.
function obtener_trm(): array {
  $dir = __DIR__ . '/../cache';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $hoy = date('Y-m-d');
  $file = $dir . '/trm_' . $hoy . '.json';
  if (is_file($file)) { $j = json_decode((string)file_get_contents($file), true); if ($j) return $j; }
  // Fetch (mismo proveedor que el PBI): exchangerate-api
  $usd = null; $eur = null;
  $ctx = stream_context_create(['http'=>['timeout'=>4]]);
  $ru = @file_get_contents('https://api.exchangerate-api.com/v4/latest/USD', false, $ctx);
  $re = @file_get_contents('https://api.exchangerate-api.com/v4/latest/EUR', false, $ctx);
  if ($ru) { $a=json_decode($ru,true); $usd=$a['rates']['COP']??null; }
  if ($re) { $a=json_decode($re,true); $eur=$a['rates']['COP']??null; }
  if ($usd && $eur) {
    $res = ['USD'=>(float)$usd,'EUR'=>(float)$eur,'fecha'=>$hoy,'fallback'=>false];
    @file_put_contents($file, json_encode($res));
    return $res;
  }
  // Fallback: último trm_*.json
  $prev = glob($dir . '/trm_*.json'); rsort($prev);
  if ($prev) { $j = json_decode((string)file_get_contents($prev[0]), true); if ($j) { $j['fallback']=true; return $j; } }
  return ['USD'=>0.0,'EUR'=>0.0,'fecha'=>$hoy,'fallback'=>true];
}
```

- [ ] **Step 4: Usar TRM en el endpoint**

En `api/informe_pagos.php`: tras `require conexion`, añadir `require __DIR__.'/lib_trm.php'; $trm = obtener_trm();`. Cambiar el cálculo de `en_pesos` por:
```php
$m = trim((string)$r['moneda']); $val = (float)$r['valor'];
$tasa = ($m==='COP') ? 1.0 : (($m==='USD') ? ($trm['USD']?:1) : (($m==='EU'||$m==='EUR') ? ($trm['EUR']?:1) : 1));
$enPesos = $val * $tasa;
```
Asignar `'en_pesos'=>$enPesos`. Añadir `'trm'=>$trm` al `json_encode` final.

- [ ] **Step 5: Verificar que pasa**

Run: `php tests/pagos_unif_test.php`
Expected: `RESULTADO: OK`.

- [ ] **Step 6: Commit**

```bash
git add api/lib_trm.php api/informe_pagos.php tests/pagos_unif_test.php
git commit -m "feat(pagos): En Pesos con TRM cacheada diaria y fallback"
```

---

## Task 3: Filtros — Causado, rango Días, rango Fecha

**Files:**
- Modify: `api/informe_pagos.php`
- Create: `tests/pagos_filtros_test.php`

**Interfaces:**
- Produces: el endpoint acepta `?causado=Todas|SI|NO`, `?dias_min=&dias_max=`, `?fdesde=YYYY-MM-DD&fhasta=YYYY-MM-DD` (sobre `fecha_venc`). Sin parámetro = sin restricción.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/pagos_filtros_test.php`:
```php
<?php
$php=PHP_BINARY; $runner=__DIR__.'/_endpoint_run_pagos.php';
$nul=(stripos(PHP_OS,'WIN')===0)?'NUL':'/dev/null';
$NIT=getenv('PAGOS_NIT_A')?:'860009034';
function call_ep($php,$runner,$nit,$qs,$nul){$cmd=escapeshellarg($php).' '.escapeshellarg($runner).' '.escapeshellarg($nit).' '.escapeshellarg($qs).' 2>'.$nul;$raw=(string)shell_exec($cmd);$a=strpos($raw,'{');$b=strrpos($raw,'}');return json_decode(($a!==false&&$b!==false)?substr($raw,$a,$b-$a+1):$raw,true);}
$fail=0;
$base=call_ep($php,$runner,$NIT,'',$nul); $nBase=count($base['filas']??[]);
// Causado=SI -> todas SI
$si=call_ep($php,$runner,$NIT,'causado=SI',$nul);
foreach (($si['filas']??[]) as $f) if ($f['causado']!=='SI'){echo "FALLO: causado!=SI\n";$fail=1;break;}
// Rango Días 0..30
$rd=call_ep($php,$runner,$NIT,'dias_min=0&dias_max=30',$nul);
foreach (($rd['filas']??[]) as $f) if ($f['dias']<0||$f['dias']>30){echo "FALLO: dias fuera de rango\n";$fail=1;break;}
// Rango Fecha
$rf=call_ep($php,$runner,$NIT,'fdesde=2025-01-01&fhasta=2025-12-31',$nul);
foreach (($rf['filas']??[]) as $f) if ($f['fecha_venc']<'2025-01-01'||$f['fecha_venc']>'2025-12-31'){echo "FALLO: fecha fuera de rango\n";$fail=1;break;}
if (count($rd['filas']??[])>$nBase){echo "FALLO: filtro no reduce\n";$fail=1;}
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (causado + rango dias + rango fecha)\n";
exit($fail);
```

- [ ] **Step 2: Verificar que falla**

Run: `php tests/pagos_filtros_test.php`
Expected: FALL — los filtros aún no aplican.

- [ ] **Step 3: Implementar los filtros (post-query, en PHP)**

En `api/informe_pagos.php`, leer params al inicio:
```php
$causado = strtoupper(trim($_GET['causado'] ?? 'TODAS'));
$diasMin = isset($_GET['dias_min']) && $_GET['dias_min']!=='' ? (int)$_GET['dias_min'] : null;
$diasMax = isset($_GET['dias_max']) && $_GET['dias_max']!=='' ? (int)$_GET['dias_max'] : null;
$fdesde  = trim($_GET['fdesde'] ?? '');
$fhasta  = trim($_GET['fhasta'] ?? '');
```
Dentro del `while`, antes de `$filas[]`, aplicar:
```php
$cau = trim((string)$r['causado']);
if ($causado!=='TODAS' && strtoupper($cau)!==$causado) continue;
$di = (int)$r['dias'];
if ($diasMin!==null && $di < $diasMin) continue;
if ($diasMax!==null && $di > $diasMax) continue;
if ($fdesde!=='' && $r['fecha_venc'] < $fdesde) continue;
if ($fhasta!=='' && $r['fecha_venc'] > $fhasta) continue;
```

- [ ] **Step 4: Verificar que pasa**

Run: `php tests/pagos_filtros_test.php`
Expected: `RESULTADO: OK`.

- [ ] **Step 5: Commit**

```bash
git add api/informe_pagos.php tests/pagos_filtros_test.php
git commit -m "feat(pagos): filtros causado, rango dias y rango fecha"
```

---

## Task 4: Lista de meses presentes (para el pivote)

**Files:**
- Modify: `api/informe_pagos.php`
- Create: `tests/pagos_pivote_test.php`

**Interfaces:**
- Produces: el payload incluye `meses: [{anio:int, mes:int}]` ordenado asc, derivado de las filas YA filtradas.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/pagos_pivote_test.php`:
```php
<?php
$php=PHP_BINARY; $runner=__DIR__.'/_endpoint_run_pagos.php';
$nul=(stripos(PHP_OS,'WIN')===0)?'NUL':'/dev/null';
$NIT=getenv('PAGOS_NIT_A')?:'860009034';
function call_ep($php,$runner,$nit,$qs,$nul){$cmd=escapeshellarg($php).' '.escapeshellarg($runner).' '.escapeshellarg($nit).' '.escapeshellarg($qs).' 2>'.$nul;$raw=(string)shell_exec($cmd);$a=strpos($raw,'{');$b=strrpos($raw,'}');return json_decode(($a!==false&&$b!==false)?substr($raw,$a,$b-$a+1):$raw,true);}
$fail=0; $d=call_ep($php,$runner,$NIT,'',$nul);
$meses=$d['meses']??null;
if (!is_array($meses)){echo "FALLO: falta meses[]\n";$fail=1;}
else {
  // cada (anio,mes) de filas debe existir en meses
  $set=[]; foreach($meses as $m)$set[$m['anio'].'-'.$m['mes']]=1;
  foreach(($d['filas']??[]) as $f) if (!isset($set[$f['anio_pago'].'-'.$f['mes_pago']])){echo "FALLO: mes de fila no está en meses[]\n";$fail=1;break;}
  // orden ascendente
  $prev=''; foreach($meses as $m){$k=sprintf('%04d%02d',$m['anio'],$m['mes']); if($k<$prev){echo "FALLO: meses no ordenados\n";$fail=1;break;} $prev=$k;}
}
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (meses presentes + orden + cobertura)\n";
exit($fail);
```

- [ ] **Step 2: Verificar que falla**

Run: `php tests/pagos_pivote_test.php`
Expected: FALL — no existe `meses`.

- [ ] **Step 3: Implementar meses[]**

En `api/informe_pagos.php`, tras llenar `$filas`, antes del `json_encode`:
```php
$mset = [];
foreach ($filas as $f) { $mset[sprintf('%04d-%02d',$f['anio_pago'],$f['mes_pago'])] = [$f['anio_pago'],$f['mes_pago']]; }
ksort($mset);
$meses = array_values(array_map(fn($x)=>['anio'=>$x[0],'mes'=>$x[1]], $mset));
```
Añadir `'meses'=>$meses` al `json_encode`.

- [ ] **Step 4: Verificar que pasa**

Run: `php tests/pagos_pivote_test.php`
Expected: `RESULTADO: OK`.

- [ ] **Step 5: Commit**

```bash
git add api/informe_pagos.php tests/pagos_pivote_test.php
git commit -m "feat(pagos): lista de meses presentes para el pivote"
```

---

## Task 5: Resolver NIT en el login

**Files:**
- Modify: `index.php:70-82` (el bloque que resuelve `$_SESSION['proveedor']`)

**Interfaces:**
- Produces: `$_SESSION['nit']` poblado al iniciar sesión.

- [ ] **Step 1: Modificar el lookup del proveedor para traer también el NIT**

En `index.php`, reemplazar la consulta `$sqlProv` (líneas ~70-74) para incluir el NIT vía `t200_mm_terceros`:
```php
$sqlProv = "SELECT TOP 1 RTRIM(p.f202_descripcion_sucursal) AS razon, RTRIM(t.f200_nit) AS nit
            FROM stanton.dbo.t202_mm_proveedores p
            JOIN stanton.dbo.t200_mm_terceros t ON t.f200_rowid = p.f202_rowid_tercero
            WHERE p.f202_id_cia = '7'
              AND p.f202_descripcion_sucursal LIKE '%' + REPLACE(?, '_', ' ') + '%'
            ORDER BY LEN(p.f202_descripcion_sucursal) ASC";
```
Y en el bloque que procesa `$rowProv`:
```php
if ($rowProv) {
    if (!empty($rowProv['razon'])) $_SESSION['proveedor'] = trim($rowProv['razon']);
    if (!empty($rowProv['nit']))   $_SESSION['nit'] = trim($rowProv['nit']);
}
```

- [ ] **Step 2: Verificar resolución de NIT (probe manual)**

Crear y correr un probe temporal (luego borrarlo):
```php
<?php // probe_nit.php
require('conexion/conexion_integracion.php');
$u = $argv[1] ?? 'CURTIEMBRE_RUFINO';
$sql = "SELECT TOP 1 RTRIM(p.f202_descripcion_sucursal) razon, RTRIM(t.f200_nit) nit
        FROM stanton.dbo.t202_mm_proveedores p JOIN stanton.dbo.t200_mm_terceros t ON t.f200_rowid=p.f202_rowid_tercero
        WHERE p.f202_id_cia='7' AND p.f202_descripcion_sucursal LIKE '%'+REPLACE(?, '_',' ')+'%' ORDER BY LEN(p.f202_descripcion_sucursal) ASC";
$st = sqlsrv_query($dbConnect, $sql, [$u]);
var_dump(sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC));
```
Run: `php probe_nit.php "CURTIEMBRE_RUFINO"`
Expected: imprime `razon` y `nit` no vacíos. Luego: `rm probe_nit.php`.

- [ ] **Step 3: Commit**

```bash
git add index.php
git commit -m "feat(login): resolver y guardar NIT del proveedor en sesión"
```

---

## Task 6: Página y registro en el dashboard

**Files:**
- Create: `informes/pagos.php`
- Modify: `dashboard.php` (nav-item en sección PAGOS ~línea 524; include ~línea 990; pageTitles ~línea 1247)

**Interfaces:**
- Produces: `showPage('informes-pagos', ...)` muestra `#page-informes-pagos`; al activarse llama `pgLoad()`.

- [ ] **Step 1: Crear el esqueleto de la página**

Crear `informes/pagos.php` con: `<style>` (reusar variables y `disp-table`, definir `--g00-divider` si no heredado), `<div class="page" id="page-informes-pagos">`, barra de filtros (Causado select; Días min/max inputs; Fecha desde/hasta inputs; etiqueta Proveedor de solo lectura), botón "Aplicar" → `pgLoad()`, y `<table id="pg-tabla" class="disp-table"></table>` dentro de una `.card` con título "Resumen Mensual Flujo de Egresos" y botón "⤓ Excel" → `pgExport()`. Mantener helpers `fmtMoneyFull`/`fmtInt` (es-CO) como en G00.

```php
<style>
  #page-informes-pagos .pg-filters { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; padding:8px 12px; margin-bottom:10px; }
  #page-informes-pagos table.disp-table th, #page-informes-pagos table.disp-table td { white-space:nowrap; font-size:11px; }
  #page-informes-pagos table.disp-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
  #page-informes-pagos .pg-neg { color: var(--danger,#e3342f); }
  #page-informes-pagos .pg-total-col { background:#fdf3c7; font-weight:700; }
</style>
<div class="page" id="page-informes-pagos">
  <div class="pg-filters">
    <div class="filter-group"><label>Causado</label>
      <select id="pg-causado"><option value="Todas">Todas</option><option value="SI">SI</option><option value="NO">NO</option></select></div>
    <div class="filter-group"><label>Días Vto (min)</label><input type="number" id="pg-dias-min"></div>
    <div class="filter-group"><label>Días Vto (max)</label><input type="number" id="pg-dias-max"></div>
    <div class="filter-group"><label>Desde</label><input type="date" id="pg-fdesde"></div>
    <div class="filter-group"><label>Hasta</label><input type="date" id="pg-fhasta"></div>
    <div class="filter-group"><label>Proveedor</label><input type="text" id="pg-prov" readonly></div>
    <button class="g00-btn-refresh" onclick="pgLoad()"><i class="fa-solid fa-filter"></i> Aplicar</button>
  </div>
  <div class="card">
    <div class="card-title">Resumen Mensual Flujo de Egresos<button class="g00-btn-export" onclick="pgExport()">⤓ Excel</button></div>
    <div style="overflow-x:auto;"><table id="pg-tabla" class="disp-table"></table></div>
  </div>
</div>
<script>
  const MESES_PG=['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
  const pgMoney=(n)=>'$'+Math.round(n||0).toLocaleString('es-CO');
  let pgData=null;
  function pgParams(){const p=new URLSearchParams();
    const c=document.getElementById('pg-causado').value; if(c) p.append('causado',c);
    const dn=document.getElementById('pg-dias-min').value; if(dn!=='') p.append('dias_min',dn);
    const dx=document.getElementById('pg-dias-max').value; if(dx!=='') p.append('dias_max',dx);
    const fd=document.getElementById('pg-fdesde').value; if(fd) p.append('fdesde',fd);
    const fh=document.getElementById('pg-fhasta').value; if(fh) p.append('fhasta',fh);
    return p.toString();}
  function pgLoad(){
    fetch('api/informe_pagos.php?'+pgParams(),{credentials:'same-origin'})
      .then(r=>r.json()).then(d=>{ if(!d.ok){ (window.Swal?Swal.fire('Pagos',d.error||'Error','error'):alert(d.error)); return;}
        pgData=d; document.getElementById('pg-prov').value=d.razon_social||''; pgRender(d); })
      .catch(e=>{ if(window.Swal)Swal.fire('Pagos','No se pudo cargar: '+e.message,'error'); });
  }
  window.pgLoad=pgLoad;
</script>
```

- [ ] **Step 2: Registrar en el dashboard**

En `dashboard.php`:
- Sección PAGOS (línea ~524), dentro del `<div class="nav-section">`, agregar:
```html
<div class="nav-item" onclick="showPage('informes-pagos', this)">
    <span class="icon"><i class="fa-solid fa-money-bill-wave"></i></span> Análisis de Pagos
</div>
```
- Junto a los `include` de informes (línea ~990), agregar:
```php
<?php include __DIR__ . '/informes/pagos.php'; ?>
```
- En el mapa `pageTitles` (línea ~1247), agregar la clave `'informes-pagos':'ANÁLISIS DE PAGOS'`.
- En `showPage`, si hay un dispatcher de carga por página, añadir que `informes-pagos` llame `pgLoad()` la primera vez (seguir el patrón de cómo G00 dispara `g00Load`/`showPage` al final del archivo, línea ~1348).

- [ ] **Step 3: Verificar carga en navegador**

Abrir `http://localhost/plataforma_20/dashboard.php`, login con un usuario proveedor, clic en "Análisis de Pagos". Esperado: la página aparece, el campo Proveedor se llena, y la tabla recibe datos (aunque el render se completa en Task 7).

- [ ] **Step 4: Commit**

```bash
git add informes/pagos.php dashboard.php
git commit -m "feat(pagos): página Análisis de Pagos + registro en dashboard"
```

---

## Task 7: Render del pivote mensual (matriz)

**Files:**
- Modify: `informes/pagos.php` (función `pgRender`)

**Interfaces:**
- Consumes: payload `{filas, meses, razon_social}`.
- Produces: `pgRender(d)` dibuja la matriz: filas por (fecha_venc → documento), columnas por mes (agrupadas por año) + Total año + Total general.

- [ ] **Step 1: Implementar `pgRender`**

Agregar al `<script>` de `informes/pagos.php`:
```js
function pgRender(d){
  const meses=d.meses||[], filas=d.filas||[];
  // índice de columnas: clave anio-mes -> posición
  const colKey=m=>m.anio+'-'+m.mes;
  const cols=meses.map(colKey);
  const anios=[...new Set(meses.map(m=>m.anio))];
  // agrupar filas por fecha_venc
  const grupos={};
  filas.forEach(f=>{ (grupos[f.fecha_venc] ??= {dias:f.dias, docs:{}, cells:{}}); 
    const g=grupos[f.fecha_venc]; const k=f.anio_pago+'-'+f.mes_pago;
    g.cells[k]=(g.cells[k]||0)+f.en_pesos;
    (g.docs[f.documento] ??= {dias:f.dias, cells:{}}); g.docs[f.documento].cells[k]=(g.docs[f.documento].cells[k]||0)+f.en_pesos;
  });
  const fechas=Object.keys(grupos).sort();
  // cabecera: Fecha venc | Dias | Razon Social | [por año: meses... | Total año] | Total general
  let h='<thead><tr><th>Fecha vencimiento</th><th>Días</th><th>RAZON_SOCIAL</th>';
  anios.forEach(a=>{ meses.filter(m=>m.anio===a).forEach(m=>{ h+='<th class="num">'+MESES_PG[m.mes-1]+' '+a+'</th>'; }); h+='<th class="num pg-total-col">Total '+a+'</th>'; });
  h+='<th class="num pg-total-col">Total</th></tr></thead><tbody>';
  const money=(v)=>'<td class="num'+(v<0?' pg-neg':'')+'">'+(v?pgMoney(v):'')+'</td>';
  const gtot={};
  fechas.forEach(fch=>{ const g=grupos[fch]; let rowTot=0;
    h+='<tr><td>'+fch+'</td><td class="num">'+g.dias+'</td><td>'+(d.razon_social||'')+'</td>';
    anios.forEach(a=>{ let aTot=0; meses.filter(m=>m.anio===a).forEach(m=>{ const k=m.anio+'-'+m.mes; const v=g.cells[k]||0; aTot+=v; gtot[k]=(gtot[k]||0)+v; h+=money(v); }); h+='<td class="num pg-total-col">'+(aTot?pgMoney(aTot):'')+'</td>'; rowTot+=aTot; });
    h+='<td class="num pg-total-col">'+(rowTot?pgMoney(rowTot):'')+'</td></tr>';
  });
  // fila Total general
  let grand=0; h+='<tr class="g00-total"><td>Total</td><td></td><td></td>';
  anios.forEach(a=>{ let aTot=0; meses.filter(m=>m.anio===a).forEach(m=>{ const k=m.anio+'-'+m.mes; const v=gtot[k]||0; aTot+=v; h+='<td class="num">'+(v?pgMoney(v):'')+'</td>'; }); h+='<td class="num pg-total-col">'+(aTot?pgMoney(aTot):'')+'</td>'; grand+=aTot; });
  h+='<td class="num pg-total-col">'+(grand?pgMoney(grand):'')+'</td></tr>';
  h+='</tbody>';
  document.getElementById('pg-tabla').innerHTML=h;
}
window.pgRender=pgRender;
```

- [ ] **Step 2: Verificar visualmente**

Recargar dashboard, abrir "Análisis de Pagos", aplicar. Esperado: matriz con meses, subtotal por año, Total general amarillo, negativos en rojo, ordenada por fecha de vencimiento. Comparar 2-3 totales contra el Power BI (`P78.pbix`, misma fecha y proveedor).

- [ ] **Step 3: Commit**

```bash
git add informes/pagos.php
git commit -m "feat(pagos): render del pivote mensual con totales por año y general"
```

---

## Task 8: Exportar a Excel + bordes divisores

**Files:**
- Modify: `informes/pagos.php`

**Interfaces:**
- Consumes: `pgData` (último payload), `XLSX` (SheetJS, ya cargado en dashboard).
- Produces: `pgExport()` descarga `.xlsx` de la matriz.

- [ ] **Step 1: Implementar `pgExport` (AOA con SheetJS)**

```js
function pgExport(){
  if(!pgData){return;}
  if(typeof XLSX==='undefined'){ if(window.Swal)Swal.fire('Exportar','No se pudo cargar Excel.','error'); return; }
  const d=pgData, meses=d.meses||[], anios=[...new Set(meses.map(m=>m.anio))];
  const header=['Fecha vencimiento','Días','RAZON_SOCIAL'];
  anios.forEach(a=>{ meses.filter(m=>m.anio===a).forEach(m=>header.push(MESES_PG[m.mes-1]+' '+a)); header.push('Total '+a); });
  header.push('Total');
  const grupos={}; (d.filas||[]).forEach(f=>{ (grupos[f.fecha_venc] ??= {dias:f.dias,cells:{}}); grupos[f.fecha_venc].cells[f.anio_pago+'-'+f.mes_pago]=(grupos[f.fecha_venc].cells[f.anio_pago+'-'+f.mes_pago]||0)+f.en_pesos; });
  const rows=Object.keys(grupos).sort().map(fch=>{ const g=grupos[fch]; const r=[fch,g.dias,d.razon_social||'']; let rt=0;
    anios.forEach(a=>{ let at=0; meses.filter(m=>m.anio===a).forEach(m=>{ const v=g.cells[m.anio+'-'+m.mes]||0; at+=v; r.push(v); }); r.push(at); rt+=at; }); r.push(rt); return r; });
  const ws=XLSX.utils.aoa_to_sheet([header,...rows]); const wb=XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb,ws,'Pagos');
  const fecha=new Date().toISOString().slice(0,10);
  const prov=(d.razon_social||'').replace(/[^A-Za-z0-9]+/g,'_').replace(/^_|_$/g,'');
  XLSX.writeFile(wb,'Analisis_Pagos'+(prov?'_'+prov:'')+'_'+fecha+'.xlsx');
}
window.pgExport=pgExport;
```

- [ ] **Step 2: Añadir bordes divisores de sección**

En el `<style>` de `informes/pagos.php`, separar visualmente Razón Social del bloque de meses y el Total general:
```css
#page-informes-pagos #pg-tabla th:nth-child(3), #page-informes-pagos #pg-tabla td:nth-child(3) { border-right:2px solid var(--g00-divider,#adabb6); }
#page-informes-pagos #pg-tabla th:last-child, #page-informes-pagos #pg-tabla td:last-child { border-left:2px solid var(--g00-divider,#adabb6); }
```

- [ ] **Step 3: Verificar export + divisores**

Recargar, abrir "Análisis de Pagos", "⤓ Excel" → descarga `.xlsx` con los mismos números de la matriz. Verificar bordes de 2px.

- [ ] **Step 4: Correr toda la suite de tests del informe**

Run:
```bash
php tests/pagos_unif_test.php && php tests/pagos_filtros_test.php && php tests/pagos_pivote_test.php
```
Expected: las tres → `RESULTADO: OK`.

- [ ] **Step 5: Commit**

```bash
git add informes/pagos.php
git commit -m "feat(pagos): exportar a Excel y bordes divisores de sección"
```

---

## Validación final (vs Power BI)

- Elegir un proveedor con datos (ej. CURTIEMBRE RUFINO MELERO), mismo día, y comparar:
  - Total general de la matriz nativa vs `P78.pbix`.
  - 2-3 celdas mes×fila.
- Si hay diferencias en proveedores **QUINCENA**, restaurar la columna `DIA_CORTE2` literal del pbix en la CTE `flujo` (ver nota en `sql_flujo_proyectado_raw.txt`).
- Registrar el resultado en `changelog_plataforma20.md`.
