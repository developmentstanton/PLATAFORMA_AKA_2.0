# O45 — Stock por cortes históricos + #tiendas histórico — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el Stock de O45 use el corte de inventario más reciente ≤ `hasta` (foto viva fechada ayer, o corte de fin de mes histórico) y que `#tiendas` cuente las tiendas con inventario en cualquier corte del rango, además de las que vendieron.

**Architecture:** Todo el cambio vive en `api/informe_o45.php`, en el bloque de construcción de `#base`. (1) Se elige el corte de stock en PHP y se intercambia la fuente de las CTE `d`/`h` (vivo = `inv_actual_PBI`/`_hold_actual_PBI`; corte = `historico_inventarios_PBI`/`historico_hold_PBI` con `FECHA=corte`). (2) Se agrega una tabla temporal `#inv_hist` con las llaves (cia,bodega,ref,color,talla) que tuvieron inventario en algún corte del rango; se suma a `llaves` y marca una columna `inv_hist` en `#base`. (3) El agg de `tab=data` cuenta `#tiendas` usando `inv_hist OR stock(corte)>0 OR ventas<>0`, excluyendo grupos `BODEGA`/`ADMINISTRATIVAS`.

**Tech Stack:** PHP 8 + SQL Server (sqlsrv), tablas `INTEGRACION.dbo.*`. Tests CLI vía `tests/_endpoint_run_o45.php` + `shell_exec`.

**Spec:** `docs/superpowers/specs/2026-06-11-o45-stock-historico-cortes-design.md`

**Datos fijos para tests (hoy = 2026-06-11, foto viva fechada 2026-06-10):**
- Proveedor `BH BRANDS SAS` posee la ref `555006144`.
- Negocio `555006144-GBE`, rango `2026-01-01 → 2026-06-10`: `#tiendas` esperado = **7**.
- Cortes de fin de mes disponibles en `historico_inventarios_PBI`: …, 2026-03-31, 2026-04-30, 2026-05-31.

---

### Task 1: Selección del corte de stock (vivo vs fin de mes) + `stock_corte` en la respuesta

**Files:**
- Modify: `api/informe_o45.php` (bloque `#base`, ~líneas 50-116; respuesta de `tab=data`, ~línea 243)
- Test: `tests/o45_stock_historico_test.php` (crear)

- [ ] **Step 1: Escribir el test que falla (selección de corte)**

Crear `tests/o45_stock_historico_test.php`:

```php
<?php
// O45: corte de stock = max(corte <= hasta). vivo si hasta>=foto viva (ayer); si no, fin de mes historico.
//   php tests/o45_stock_historico_test.php
$runner = __DIR__ . '/_endpoint_run_o45.php';
$php = PHP_BINARY;
$nul = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
function ep($php,$runner,$prov,$qs,$nul){
    $cmd = escapeshellarg($php).' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner).' '.escapeshellarg($prov).' '.escapeshellarg($qs).' 2>'.$nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw,'{'); $b = strrpos($raw,'}');
    return json_decode(($a!==false && $b!==false) ? substr($raw,$a,$b-$a+1) : $raw, true);
}
$fail = 0;
$prov = 'BH BRANDS SAS';

// (se filtra por referencia[] para que A/B/C corran rápido: stock_corte no depende del filtro de ref)
$rf = '&referencia[]=555006144';

// A) hasta futuro => 'vivo'
$d = ep($php,$runner,$prov,'tab=data&desde=2026-01-01&hasta=2026-12-31'.$rf,$nul);
$sc = $d['rango']['stock_corte'] ?? null;
echo "hasta=2026-12-31 stock_corte=".var_export($sc,true)."\n";
if ($sc !== 'vivo') { echo "FALLO: esperaba 'vivo'\n"; $fail = 1; }

// B) hasta en junio (antes de hoy) => corte 2026-05-31
$d = ep($php,$runner,$prov,'tab=data&desde=2026-01-01&hasta=2026-06-06'.$rf,$nul);
$sc = $d['rango']['stock_corte'] ?? null;
echo "hasta=2026-06-06 stock_corte=".var_export($sc,true)."\n";
if ($sc !== '2026-05-31') { echo "FALLO: esperaba '2026-05-31'\n"; $fail = 1; }

// C) hasta en abril => corte 2026-03-31
$d = ep($php,$runner,$prov,'tab=data&desde=2026-01-01&hasta=2026-04-15'.$rf,$nul);
$sc = $d['rango']['stock_corte'] ?? null;
echo "hasta=2026-04-15 stock_corte=".var_export($sc,true)."\n";
if ($sc !== '2026-03-31') { echo "FALLO: esperaba '2026-03-31'\n"; $fail = 1; }

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
```

- [ ] **Step 2: Correr el test y verque falla**

Run: `/c/xampp/php/php.exe tests/o45_stock_historico_test.php 2>&1 | grep -E 'stock_corte|RESULTADO'`
Expected: FALLO (la respuesta aún no trae `rango.stock_corte`).

- [ ] **Step 3: Implementar selección de corte + swap de CTE `d`/`h`**

En `api/informe_o45.php`, **después** de los filtros de `#refs` (tras el bloque `if ($tab === 'data') { foreach ($FILTROS_REF ...)}`, ~línea 49) y **antes** del `CREATE TABLE #base`, insertar:

```php
// === Corte de stock: foto viva (fechada ayer) o corte de fin de mes <= hasta ===
$fv = run($dbConnect, "SELECT TOP 1 CONVERT(varchar(10),FECHA,120) f FROM INTEGRACION.dbo.inv_actual_PBI WITH (NOLOCK)");
$fechaViva = (!isset($fv['error']) && $fv && !empty($fv[0]['f'])) ? $fv[0]['f'] : date('Y-m-d', strtotime('-1 day'));
if ($hasta >= $fechaViva) {
    $modoStock = 'vivo'; $corteStock = null;
} else {
    $modoStock = 'corte';
    $finMes = date('Y-m-t', strtotime($hasta));                 // ultimo dia del mes de hasta
    $corteStock = ($hasta >= $finMes) ? $finMes                  // hasta ES fin de mes
                : date('Y-m-t', strtotime(date('Y-m-01', strtotime($hasta)) . ' -1 day')); // fin del mes anterior
}
```

Reemplazar las CTE `d` y `h` dentro de `$insBase` (líneas ~62-77) por variables `$dCte`/`$hCte` construidas según el modo. Insertar este bloque justo antes de `$insBase = "...";`:

```php
if ($modoStock === 'vivo') {
    $dCte = "d AS (
        SELECT RIGHT('000'+rtrim(v.cia),3) cia, rtrim(v.bodega) bodega, rtrim(v.referencia) referencia,
               rtrim(v.color) color, rtrim(v.talla) talla, SUM(CAST(v.cantidad AS int)) q
        FROM INTEGRACION.dbo.inv_actual_PBI v WITH (NOLOCK)
         INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
        WHERE v.cia<>'001' AND v.COLUMNA1 IN ('INV1430','INV1435','400')
        GROUP BY RIGHT('000'+rtrim(v.cia),3),rtrim(v.bodega),rtrim(v.referencia),rtrim(v.color),rtrim(v.talla))";
    $hCte = "h AS (
        SELECT RIGHT('000'+rtrim(v.cia),3) cia, rtrim(v.bodega_sal) bodega, rtrim(v.referencia) referencia,
               rtrim(v.color) color, rtrim(v.talla) talla, SUM(CAST(v.cantidad AS int)) q
        FROM INTEGRACION.dbo._hold_actual_PBI v WITH (NOLOCK)
         INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
        WHERE v.cia<>'001'
        GROUP BY RIGHT('000'+rtrim(v.cia),3),rtrim(v.bodega_sal),rtrim(v.referencia),rtrim(v.color),rtrim(v.talla))";
    $pStock = [];
} else {
    $dCte = "d AS (
        SELECT RIGHT('000'+rtrim(v.CIA),3) cia, rtrim(v.BODEGA) bodega, rtrim(v.REFERENCIA) referencia,
               rtrim(v.COLOR) color, rtrim(v.TALLA) talla, SUM(CAST(v.CANTIDAD AS int)) q
        FROM INTEGRACION.dbo.historico_inventarios_PBI v WITH (NOLOCK)
         INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.REFERENCIA)
        WHERE v.FECHA = ? AND v.CIA<>'001' AND rtrim(v.COLUMNA1) IN ('INV1430','INV1435','400')
        GROUP BY RIGHT('000'+rtrim(v.CIA),3),rtrim(v.BODEGA),rtrim(v.REFERENCIA),rtrim(v.COLOR),rtrim(v.TALLA))";
    $hCte = "h AS (
        SELECT RIGHT('000'+rtrim(v.CIA),3) cia, rtrim(v.BODEGA_SAL) bodega, rtrim(v.REFERENCIA) referencia,
               rtrim(v.COLOR) color, rtrim(v.TALLA) talla, SUM(CAST(v.CANTIDAD AS int)) q
        FROM INTEGRACION.dbo.historico_hold_PBI v WITH (NOLOCK)
         INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.REFERENCIA)
        WHERE v.FECHA = ? AND v.CIA<>'001'
        GROUP BY RIGHT('000'+rtrim(v.CIA),3),rtrim(v.BODEGA_SAL),rtrim(v.REFERENCIA),rtrim(v.COLOR),rtrim(v.TALLA))";
    $pStock = [$corteStock, $corteStock];
}
```

En `$insBase`, cambiar el encabezado `WITH d AS (...), h AS (...),` por `WITH $dCte, $hCte,` (interpolación). Es decir, la línea que abre el `WITH` queda:

```php
$insBase = "
  WITH $dCte,
  $hCte,
  ventas_src AS (
```

(el resto de `ventas_src`, `v`, `ventas30_src`, `v30`, `llaves` e `INSERT` no cambia en esta task).

Ajustar el armado de params para anteponer `$pStock` (líneas ~112-116):

```php
$p = $pStock;                       // <- corte de stock primero (si aplica)
array_push($p, $desde, $hasta);     // ventas_src
if ($acumV   !== '') array_push($p, $desde, $hasta);
array_push($p, $w30desde, $hasta);  // ventas30_src
if ($acumV30 !== '') array_push($p, $w30desde, $hasta);
$ins = run($dbConnect, $insBase, $p);
```

En la respuesta de `tab=data` (~línea 243), agregar `stock_corte` al `rango`:

```php
echo json_encode(['ok'=>true, 'proveedor'=>$proveedorSesion,
    'rango'=>['desde'=>$desde,'hasta'=>$hasta,'w30desde'=>$w30desde,'dias'=>$dias,
              'stock_corte'=>($modoStock==='vivo' ? 'vivo' : $corteStock)],
    'filas'=>$filas, 'total'=>$tot], JSON_UNESCAPED_UNICODE);
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `/c/xampp/php/php.exe tests/o45_stock_historico_test.php 2>&1 | grep -E 'stock_corte|RESULTADO'`
Expected: `RESULTADO: OK` (vivo / 2026-05-31 / 2026-03-31).

- [ ] **Step 5: Verificar `php -l` y suite O45 existente**

Run: `/c/xampp/php/php.exe -l api/informe_o45.php; for t in o45_smoke_test o45_indices_test o45_filtro_tienda_test; do /c/xampp/php/php.exe tests/$t.php 2>&1 | grep RESULTADO; done`
Expected: `No syntax errors` + 3× `RESULTADO: OK`.

- [ ] **Step 6: Commit**

```bash
git add api/informe_o45.php tests/o45_stock_historico_test.php
git commit -m "feat(o45): stock por corte mas reciente <= hasta (vivo o fin de mes historico)"
```

---

### Task 2: `#tiendas` histórico (inventario en cualquier corte del rango)

**Files:**
- Modify: `api/informe_o45.php` (CREATE `#base`; tabla `#inv_hist`; `llaves`; SELECT del INSERT; agg `tab=data`)
- Test: `tests/o45_stock_historico_test.php` (agregar caso)

- [ ] **Step 1: Agregar el test que falla (#tiendas histórico = 7)**

Agregar al final de `tests/o45_stock_historico_test.php`, **antes** de la línea `echo $fail ? ...`:

```php
// D) #tiendas historico: negocio 555006144-GBE incluye tiendas con inventario en cortes del rango (I01,R90).
$d = ep($php,$runner,'BH BRANDS SAS','tab=data&desde=2026-01-01&hasta=2026-06-10&referencia[]=555006144',$nul);
$gbe = null;
foreach (($d['filas'] ?? []) as $f) if (($f['negocio'] ?? '') === '555006144-GBE') { $gbe = $f; break; }
if (!$gbe) { echo "FALLO: no aparece negocio 555006144-GBE\n"; $fail = 1; }
else {
    echo "555006144-GBE tiendas=".$gbe['tiendas']." (esperado 7)\n";
    if ((int)$gbe['tiendas'] !== 7) { echo "FALLO: #tiendas historico != 7\n"; $fail = 1; }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `/c/xampp/php/php.exe tests/o45_stock_historico_test.php 2>&1 | grep -E '555006144|RESULTADO'`
Expected: FALLO — hoy da `tiendas=5` (sin contar I01/R90, que tuvieron inventario histórico pero no stock hoy ni ventas).

- [ ] **Step 3: Construir `#inv_hist` y marcar `inv_hist` en `#base`**

(3a) En el `CREATE TABLE #base`, agregar la columna `inv_hist int`:

```php
$cre = sqlsrv_query($dbConnect, "CREATE TABLE #base (cia varchar(10), bodega varchar(20), negocio varchar(120),
    referencia varchar(50), color varchar(40), talla varchar(40),
    disponible int, hold int, ventas int, ventas30 int, inv_hist int)");
```

(3b) Inmediatamente **después** de ese `CREATE TABLE #base` (y antes de armar `$insBase`), crear y poblar `#inv_hist`:

```php
// #inv_hist: llaves (cia,bodega,ref,color,talla) con inventario>0 en ALGUN corte de fin de mes dentro del rango.
$creH = sqlsrv_query($dbConnect, "CREATE TABLE #inv_hist (cia varchar(10), bodega varchar(20),
    referencia varchar(50), color varchar(40), talla varchar(40))");
if ($creH===false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($creH);
$insHist = "INSERT INTO #inv_hist
  SELECT DISTINCT cia,bodega,referencia,color,talla FROM (
    SELECT RIGHT('000'+rtrim(hi.CIA),3) cia, rtrim(hi.BODEGA) bodega, rtrim(hi.REFERENCIA) referencia,
           rtrim(hi.COLOR) color, rtrim(hi.TALLA) talla
      FROM INTEGRACION.dbo.historico_inventarios_PBI hi WITH (NOLOCK)
       INNER JOIN #refs r ON r.REFERENCIA = rtrim(hi.REFERENCIA)
      WHERE hi.FECHA BETWEEN ? AND ? AND hi.CIA<>'001'
            AND rtrim(hi.COLUMNA1) IN ('INV1430','INV1435','400') AND CAST(hi.CANTIDAD AS int) > 0
    UNION
    SELECT RIGHT('000'+rtrim(hh.CIA),3), rtrim(hh.BODEGA_SAL), rtrim(hh.REFERENCIA), rtrim(hh.COLOR), rtrim(hh.TALLA)
      FROM INTEGRACION.dbo.historico_hold_PBI hh WITH (NOLOCK)
       INNER JOIN #refs r ON r.REFERENCIA = rtrim(hh.REFERENCIA)
      WHERE hh.FECHA BETWEEN ? AND ? AND hh.CIA<>'001' AND CAST(hh.CANTIDAD AS int) > 0
  ) z";
$rh = run($dbConnect, $insHist, [$desde,$hasta,$desde,$hasta]);
if (isset($rh['error'])) jsonFail($rh, $dbConnect);
```

(3c) En `llaves` (dentro de `$insBase`), agregar la unión con `#inv_hist`:

```php
  llaves AS (
    SELECT cia,bodega,referencia,color,talla FROM d
    UNION SELECT cia,bodega,referencia,color,talla FROM h
    UNION SELECT cia,bodega,referencia,color,talla FROM v
    UNION SELECT cia,bodega,referencia,color,talla FROM v30
    UNION SELECT cia,bodega,referencia,color,talla FROM #inv_hist
  )
```

(3d) En el `INSERT INTO #base ... SELECT`, agregar la columna `inv_hist` y el LEFT JOIN:

```php
  INSERT INTO #base
  SELECT k.cia, k.bodega, k.referencia+'-'+k.color, k.referencia, k.color, k.talla,
         CAST(ISNULL(d.q,0) AS int), CAST(ISNULL(h.q,0) AS int), CAST(ISNULL(v.q,0) AS int), CAST(ISNULL(v30.q,0) AS int),
         CASE WHEN ih.cia IS NOT NULL THEN 1 ELSE 0 END
  FROM llaves k
   LEFT JOIN d   ON d.cia=k.cia AND d.bodega=k.bodega AND d.referencia=k.referencia AND d.color=k.color AND d.talla=k.talla
   LEFT JOIN h   ON h.cia=k.cia AND h.bodega=k.bodega AND h.referencia=k.referencia AND h.color=k.color AND h.talla=k.talla
   LEFT JOIN v   ON v.cia=k.cia AND v.bodega=k.bodega AND v.referencia=k.referencia AND v.color=k.color AND v.talla=k.talla
   LEFT JOIN v30 ON v30.cia=k.cia AND v30.bodega=k.bodega AND v30.referencia=k.referencia AND v30.color=k.color AND v30.talla=k.talla
   LEFT JOIN #inv_hist ih ON ih.cia=k.cia AND ih.bodega=k.bodega AND ih.referencia=k.referencia AND ih.color=k.color AND ih.talla=k.talla";
```

- [ ] **Step 4: Ajustar el agg de `tab=data` (#tiendas y Tallas) y el TOTAL**

(4a) En el `SELECT` del agg (~líneas 188-198), cambiar `tallas` y `tiendas`:

```php
        SELECT b.cia, b.negocio, b.referencia, b.color,
               MAX(r.MARCA) marca,
               SUM(CASE WHEN b.bodega<>'CEDI' THEN b.ventas   ELSE 0 END) ventas,
               SUM(CASE WHEN b.bodega<>'CEDI' THEN b.ventas30 ELSE 0 END) ventas30,
               SUM(CASE WHEN b.bodega='CEDI'  THEN b.disponible+b.hold ELSE 0 END) stock_cedi,
               SUM(CASE WHEN b.bodega<>'CEDI' THEN b.disponible+b.hold ELSE 0 END) stock_tiendas,
               COUNT(DISTINCT CASE WHEN b.disponible+b.hold>0 OR b.inv_hist=1 OR b.ventas<>0 THEN b.talla END) tallas,
               -- #tiendas: stock del corte O inventario en algun corte del rango O ventas; excl. grupos BODEGA/ADMINISTRATIVAS.
               COUNT(DISTINCT CASE WHEN ISNULL(bo.GRUPO,'') NOT IN ('BODEGA','ADMINISTRATIVAS')
                                    AND (b.disponible+b.hold>0 OR b.inv_hist=1 OR b.ventas<>0) THEN b.cia+'-'+b.bodega END) tiendas
        FROM #base b
         INNER JOIN #refs r ON r.REFERENCIA = b.referencia
         LEFT  JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia
        GROUP BY b.cia, b.negocio, b.referencia, b.color
        ORDER BY ventas DESC
```

(4b) En la query del TOTAL `#tiendas` (~líneas 232-237), agregar `OR b.inv_hist=1`:

```php
    $ct = run($dbConnect, "
        SELECT COUNT(DISTINCT b.cia+'-'+b.bodega) n
        FROM #base b
         LEFT JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia
        WHERE ISNULL(bo.GRUPO,'') NOT IN ('BODEGA','ADMINISTRATIVAS')
              AND (b.disponible+b.hold>0 OR b.inv_hist=1 OR b.ventas<>0)");
    $tot['tiendas'] = (!isset($ct['error']) && $ct) ? (int)$ct[0]['n'] : 0;
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `/c/xampp/php/php.exe tests/o45_stock_historico_test.php 2>&1 | grep -E '555006144|stock_corte|RESULTADO'`
Expected: `555006144-GBE tiendas=7` y `RESULTADO: OK`.

- [ ] **Step 6: Verificar `php -l`, suite O45 e invariante total_stock**

Run: `/c/xampp/php/php.exe -l api/informe_o45.php; for t in o45_smoke_test o45_indices_test o45_filtro_tienda_test; do /c/xampp/php/php.exe tests/$t.php 2>&1 | grep RESULTADO; done`
Expected: `No syntax errors` + 3× `RESULTADO: OK` (el smoke valida `total_stock = stock_cedi + stock_tiendas`).

- [ ] **Step 7: Commit**

```bash
git add api/informe_o45.php tests/o45_stock_historico_test.php
git commit -m "feat(o45): #tiendas cuenta inventario en cualquier corte del rango (#inv_hist)"
```

---

### Task 3: Verificación funcional en endpoint real + actualización de changelog

**Files:**
- Modify: (memoria) `changelog_plataforma20.md`

- [ ] **Step 1: Probar el endpoint real con varios rangos (humo manual)**

Run:
```bash
/c/xampp/php/php.exe tests/_endpoint_run_o45.php "BH BRANDS SAS" "tab=data&desde=2026-01-01&hasta=2026-06-10&referencia[]=555006144" 2>&1 \
 | grep -v -E 'xdebug|dio_ts|openssl|Failed loading|^Warning|session_start|Notice' \
 | python -c "import sys,json; d=json.load(sys.stdin); f=[x for x in d['filas'] if x['negocio']=='555006144-GBE'][0]; print('stock_corte=',d['rango']['stock_corte'],'| GBE tiendas=',f['tiendas'],'| stock_tiendas=',f['stock_tiendas'],'| stock_cedi=',f['stock_cedi'])"
```
Expected: `stock_corte= vivo | GBE tiendas= 7 | …` (hasta=2026-06-10 = foto viva).

- [ ] **Step 2: Confirmar corte histórico para un rango pasado**

Run:
```bash
/c/xampp/php/php.exe tests/_endpoint_run_o45.php "BH BRANDS SAS" "tab=data&desde=2026-01-01&hasta=2026-06-06&referencia[]=555006144" 2>&1 \
 | grep -v -E 'xdebug|dio_ts|openssl|Failed loading|^Warning|session_start|Notice' \
 | python -c "import sys,json; d=json.load(sys.stdin); print('stock_corte=',d['rango']['stock_corte'])"
```
Expected: `stock_corte= 2026-05-31`.

- [ ] **Step 3: Actualizar changelog**

Agregar entrada `## 2026-06-11 (6) — O45: stock por cortes históricos + #tiendas histórico` en `C:\Users\USUARIO\.claude\projects\C--xampp-htdocs\memory\changelog_plataforma20.md` resumiendo: 4 tablas, regla de corte (vivo/fin de mes), `#inv_hist`, `stock_corte` en respuesta, golden test (BH BRANDS, GBE=7), rendimiento aceptado.

- [ ] **Step 4: Verificación en navegador (Rafael)**

Abrir O45 con proveedor BH BRANDS SAS, filtrar ref 555006144; confirmar que el negocio GBE muestra 7 tiendas, y que al mover Hasta a una fecha de un mes anterior el Stock cambia al corte de fin de mes correspondiente.

---

## Notas de diseño / límites conocidos
- **Rendimiento**: la construcción de `#inv_hist` escanea `historico_inventarios_PBI` (~15M filas) por el rango → ~5s; carga total ~6–8s. Aceptado (spinner). Optimización futura: índice por `REFERENCIA, FECHA` o tabla pre-agregada.
- **Borde ETL**: si el fin de mes calculado aún no fue cargado en el histórico, ese corte queda en 0 (sin fallback en esta versión).
- **Golden test (#tiendas=7)**: depende de datos vivos; si BH BRANDS registra ventas/inventario nuevos en esas tiendas el valor puede cambiar y habrá que actualizar el test.
- **Columna Tallas**: ahora cuenta tallas con stock(corte)>0 **o** inv_hist **o** ventas (antes solo stock/ventas de la foto viva). Cambio intencional para mantener coherencia con `#tiendas`.
