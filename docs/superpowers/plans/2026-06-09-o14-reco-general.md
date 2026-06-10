# O14 — Recomendaciones generales por filtros · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** La pestaña Recomendaciones corre el motor de cascada para TODOS los negocios de la vista filtrada y los presenta como 3 matrices negocio×talla (Sobrante / Faltante / Solicitud a proveedor), con un indicador de actividad pendiente en la pestaña.

**Architecture:** Backend: el tab=reco se reescribe para agrupar `#base` por (cía,negocio), correr `recomendar()` y agregar a 3 medidas tidy; los filtros de producto/SKU aplican a reco, los de bodega no. Frontend: `renderReco` pasa a pintar 3 matrices (TOTAL arriba + Excel) y un punto pulsante derivado de los KPIs.

**Tech Stack:** PHP + sqlsrv, JS vanilla + SheetJS, tests CLI + endpoint runner.

**Spec:** `docs/superpowers/specs/2026-06-09-o14-reco-general.md`

---

## Estructura de archivos
- **Create** `tests/o14_reco_general_test.php` — TDD del endpoint reco general.
- **Modify** `api/informe_o14.php` — gating de filtros (REF+SKU incluyen reco; bodega no) + bloque `tab=reco` reescrito.
- **Modify** `informes/o14.php` — `buildParams` (sin ref/color en reco), `loadReco`, `renderReco`→`renderRecoMatrices`+`renderRecoMatriz`+`o14RecoExp`, markup del panel reco, indicador en la pestaña + `setRecoIndicator` en `renderKpis` + CSS.

El motor `api/o14_recomendador.php` NO se toca.

---

## Task 1: Backend — reco general (TDD)

**Files:**
- Create: `tests/o14_reco_general_test.php`
- Modify: `api/informe_o14.php` (gating de filtros + bloque `tab=reco`)

- [ ] **Step 1: Crear el test que falla**

Crear `tests/o14_reco_general_test.php`:

```php
<?php
// Recomendaciones generales (tab=reco): 3 matrices negocio×talla; proveedor<=faltante; producto acota, bodega no.
//   php tests/o14_reco_general_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BELTRANY SAS';
$runner = __DIR__ . '/_endpoint_run_o14.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
function ep($php, $runner, $prov, $qs, $nul) {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    return json_decode(($a !== false && $b !== false) ? substr($raw, $a, $b - $a + 1) : $raw, true);
}
$fail = 0;

$d = ep($php, $runner, $prov, 'tab=reco', $nul);
if (!($d['ok'] ?? false)) { echo "FALLO: tab=reco no ok\n"; $fail = 1; }
$med = $d['medidas'] ?? [];
if ($med !== ['sobrante','faltante','proveedor']) { echo "FALLO: medidas inesperadas: " . json_encode($med) . "\n"; $fail = 1; }
$filas = $d['filas'] ?? [];
echo "reco: negocios=" . count($filas) . " tallas=" . count($d['tallas'] ?? []) . "\n";
if (!count($filas)) { echo "FALLO: sin filas\n"; $fail = 1; }

// invariante proveedor[t] <= faltante[t] por negocio/talla
foreach ($filas as $f) {
    $prv = $f['valores']['proveedor'] ?? []; $fal = $f['valores']['faltante'] ?? [];
    foreach ($prv as $t => $q) {
        if ($q > ($fal[$t] ?? 0)) { echo "FALLO: proveedor>faltante en " . $f['negocio'] . " talla $t: $q > " . ($fal[$t] ?? 0) . "\n"; $fail = 1; break 2; }
    }
}
if (!$fail) echo "invariante proveedor<=faltante OK\n";

// filtro de producto (marca) acota el set de negocios
$cat = ep($php, $runner, $prov, 'tab=filtros', $nul);
$marca = '';
foreach (($cat['combos'] ?? []) as $c) { if (!empty($c['marca'])) { $marca = $c['marca']; break; } }
if ($marca !== '') {
    $dm = ep($php, $runner, $prov, 'tab=reco&marca[]=' . rawurlencode($marca), $nul);
    echo "reco marca[]=$marca: negocios=" . count($dm['filas'] ?? []) . "\n";
    if (count($dm['filas'] ?? []) > count($filas)) { echo "FALLO: filtro marca no acota reco\n"; $fail = 1; }
}

// filtro de bodega NO cambia reco (cascada usa la red completa)
$db = ep($php, $runner, $prov, 'tab=reco&grupo[]=AKA', $nul);
if (count($db['filas'] ?? []) !== count($filas)) { echo "FALLO: filtro bodega cambió reco (" . count($db['filas'] ?? []) . " vs " . count($filas) . ")\n"; $fail = 1; }
else echo "filtro bodega no cambia reco OK\n";

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
```

- [ ] **Step 2: Correr y verificar que FALLA**

Run: `php tests/o14_reco_general_test.php`
Expected: FALLO — el endpoint actual, sin `ref`/`color`, responde `{ok:true,tab:'reco',planes:[]}` → no hay `medidas` ni `filas` → "FALLO: medidas inesperadas: []" y "FALLO: sin filas". `RESULTADO: FALLO`.

- [ ] **Step 3: Ajustar el gating de filtros de referencia (incluir reco)**

En `api/informe_o14.php`, reemplazar:
```php
// Filtros de dimensión de referencia: podar #refs → cae en las 4 fuentes (todas la inner-joinan).
if ($tab === 'b' || $tab === 'c') {   // filtros de dimensión solo en vistas B/C/KPIs; reco usa universo completo (CEDI siempre); filtros = catálogo
    foreach ($FILTROS_REF as $key => $col) {
```
por:
```php
// Filtros de dimensión de referencia: podar #refs → cae en las 4 fuentes (todas la inner-joinan).
if ($tab === 'b' || $tab === 'c' || $tab === 'reco') {   // producto/SKU acotan B/C/KPIs y reco; bodega solo B/C
    foreach ($FILTROS_REF as $key => $col) {
```

- [ ] **Step 4: Separar el gating de SKU (incluye reco) del de bodega (solo b/c)**

En `api/informe_o14.php`, reemplazar el bloque:
```php
// Filtros color/talla (columnas de #base) y bodega (vía Bodegas, conservando CEDI). No en catálogo.
if ($tab === 'b' || $tab === 'c') {   // filtros de dimensión solo en vistas B/C/KPIs; reco usa universo completo (CEDI siempre); filtros = catálogo
    foreach ($FILTROS_SKU as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $d = sqlsrv_query($dbConnect, "DELETE FROM #base WHERE $col NOT IN ($ph)", $vals);
        if ($d === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($d);
    }
    foreach ($FILTROS_BOD as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $d = sqlsrv_query($dbConnect, "
            DELETE b FROM #base b
            LEFT JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK)
              ON bo.COD = b.bodega AND RIGHT('000'+rtrim(bo.CIA),3) = b.cia
            WHERE b.bodega <> 'CEDI' AND ISNULL(bo.$col,'') NOT IN ($ph)", $vals);
        if ($d === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($d);
    }
}
```
por:
```php
// Filtros color/talla (columnas de #base): aplican a B/C/KPIs y reco (acotan tallas/colores).
if ($tab === 'b' || $tab === 'c' || $tab === 'reco') {
    foreach ($FILTROS_SKU as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $d = sqlsrv_query($dbConnect, "DELETE FROM #base WHERE $col NOT IN ($ph)", $vals);
        if ($d === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($d);
    }
}
// Filtros de bodega (vía Bodegas, conservando CEDI): SOLO B/C — reco usa la red completa para la cascada.
if ($tab === 'b' || $tab === 'c') {
    foreach ($FILTROS_BOD as $key => $col) {
        $vals = getMulti($key); if (!$vals) continue;
        $ph = implode(',', array_fill(0, count($vals), '?'));
        $d = sqlsrv_query($dbConnect, "
            DELETE b FROM #base b
            LEFT JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK)
              ON bo.COD = b.bodega AND RIGHT('000'+rtrim(bo.CIA),3) = b.cia
            WHERE b.bodega <> 'CEDI' AND ISNULL(bo.$col,'') NOT IN ($ph)", $vals);
        if ($d === false) jsonFail(['error'=>sqlsrv_errors()], $dbConnect); else sqlsrv_free_stmt($d);
    }
}
```

- [ ] **Step 5: Reescribir el bloque `if ($tab === 'reco')`**

En `api/informe_o14.php`, reemplazar TODO el bloque actual:
```php
if ($tab === 'reco') {
    require __DIR__ . '/o14_recomendador.php';
    if ($refF==='' || $colF==='') { sqlsrv_close($dbConnect); echo json_encode(['ok'=>true,'tab'=>'reco','planes'=>[]]); exit; }
    $rows = run($dbConnect, "
        SELECT cia, bodega, talla, SUM(siembra) siembra, SUM(disponible) disponible, SUM(hold) hold
        FROM #base WHERE referencia=? AND color=?
        GROUP BY cia, bodega, talla", [$refF,$colF]);
    if (isset($rows['error'])) jsonFail($rows, $dbConnect);

    $porCia = [];
    foreach ($rows as $r) {
        $c=$r['cia']; $t=(string)$r['talla'];
        if (rtrim($r['bodega'])==='CEDI') {
            $porCia[$c]['cedi'][$t] = ($porCia[$c]['cedi'][$t] ?? 0) + (int)$r['disponible'];
        } else {
            $porCia[$c]['tiendas'][$r['bodega']]['cod'] = $c.'-'.$r['bodega'];
            $porCia[$c]['tiendas'][$r['bodega']]['tallas'][$t] =
                ['siembra'=>(int)$r['siembra'],'disponible'=>(int)$r['disponible'],'hold'=>(int)$r['hold']];
        }
    }
    $planes = [];
    foreach ($porCia as $c => $g) {
        $plan = recomendar(array_values($g['tiendas'] ?? []), $g['cedi'] ?? []);
        $plan['cia'] = $c; $plan['negocio'] = ['ref'=>$refF,'color'=>$colF];
        $planes[] = $plan;
    }
    sqlsrv_close($dbConnect);
    echo json_encode(['ok'=>true,'tab'=>'reco','negocio'=>['ref'=>$refF,'color'=>$colF],'planes'=>$planes], JSON_UNESCAPED_UNICODE);
    exit;
}
```
por:
```php
if ($tab === 'reco') {
    require __DIR__ . '/o14_recomendador.php';
    // Reco GENERAL: corre el motor por (cia, negocio) sobre #base filtrado (producto/SKU; bodega NO).
    $rows = run($dbConnect, "
        SELECT cia, bodega, negocio, referencia, color, talla,
               SUM(siembra) siembra, SUM(disponible) disponible, SUM(hold) hold
        FROM #base
        GROUP BY cia, bodega, negocio, referencia, color, talla");
    if (isset($rows['error'])) jsonFail($rows, $dbConnect);

    // Agrupar por (cia, negocio): tiendas (no-CEDI) + cedi (disponible).
    $porCiaNeg = [];
    foreach ($rows as $r) {
        $c=$r['cia']; $neg=$r['negocio']; $t=(string)$r['talla']; $bod=rtrim($r['bodega']);
        if (!isset($porCiaNeg[$c][$neg])) $porCiaNeg[$c][$neg]=['ref'=>$r['referencia'],'color'=>$r['color'],'tiendas'=>[],'cedi'=>[]];
        if ($bod==='CEDI') {
            $porCiaNeg[$c][$neg]['cedi'][$t] = ($porCiaNeg[$c][$neg]['cedi'][$t] ?? 0) + (int)$r['disponible'];
        } else {
            $porCiaNeg[$c][$neg]['tiendas'][$bod]['cod'] = $c.'-'.$bod;
            $porCiaNeg[$c][$neg]['tiendas'][$bod]['tallas'][$t] =
                ['siembra'=>(int)$r['siembra'],'disponible'=>(int)$r['disponible'],'hold'=>(int)$r['hold']];
        }
    }

    // Por negocio (agregando cías): sobrante/faltante raw sobre tiendas + proveedor (residual del motor).
    $negs = [];
    foreach ($porCiaNeg as $c => $porNeg) {
        foreach ($porNeg as $neg => $g) {
            if (!isset($negs[$neg])) $negs[$neg]=['referencia'=>$g['ref'],'color'=>$g['color'],'sobrante'=>[],'faltante'=>[],'proveedor'=>[]];
            foreach ($g['tiendas'] as $bod=>$tn) {
                foreach ($tn['tallas'] as $t=>$v) {
                    $bal = $v['siembra'] - ($v['disponible'] + $v['hold']);
                    if ($bal > 0)      $negs[$neg]['faltante'][$t] = ($negs[$neg]['faltante'][$t] ?? 0) + $bal;
                    elseif ($bal < 0)  $negs[$neg]['sobrante'][$t] = ($negs[$neg]['sobrante'][$t] ?? 0) + (-$bal);
                }
            }
            $plan = recomendar(array_values($g['tiendas']), $g['cedi']);
            foreach (($plan['solicitudes_proveedor'] ?? []) as $sp) {
                $t=(string)$sp['talla']; $negs[$neg]['proveedor'][$t] = ($negs[$neg]['proveedor'][$t] ?? 0) + (int)$sp['uds'];
            }
        }
    }
    sqlsrv_close($dbConnect);

    // Tidy: una fila por negocio con algún valor>0; tallas unión ordenada.
    $tallasSet=[]; $filas=[];
    foreach ($negs as $neg=>$v) {
        if ((array_sum($v['sobrante']) + array_sum($v['faltante']) + array_sum($v['proveedor'])) <= 0) continue;
        foreach (['sobrante','faltante','proveedor'] as $m) foreach ($v[$m] as $t=>$q) $tallasSet[(string)$t]=true;
        $filas[] = ['negocio'=>$neg,'referencia'=>$v['referencia'],'color'=>$v['color'],
                    'valores'=>['sobrante'=>$v['sobrante'],'faltante'=>$v['faltante'],'proveedor'=>$v['proveedor']]];
    }
    usort($filas, fn($a,$b)=>strcmp($a['negocio'],$b['negocio']));
    $tallas=array_keys($tallasSet);
    usort($tallas, fn($a,$b)=>(is_numeric($a)&&is_numeric($b))?($a<=>$b):strcmp($a,$b));

    echo json_encode(['ok'=>true,'tab'=>'reco','tallas'=>$tallas,
        'medidas'=>['sobrante','faltante','proveedor'],'filas'=>$filas], JSON_UNESCAPED_UNICODE);
    exit;
}
```

- [ ] **Step 6: Correr y verificar que PASA**

Run: `php tests/o14_reco_general_test.php`
Expected: PASS — `medidas` correctas, `filas` no vacío, `invariante proveedor<=faltante OK`, `filtro bodega no cambia reco OK`, `RESULTADO: OK`.

- [ ] **Step 7: `php -l` y commit**

Run: `php -l api/informe_o14.php` → "No syntax errors detected".
```bash
git add api/informe_o14.php tests/o14_reco_general_test.php
git commit -m "feat(o14): reco general por filtros (3 medidas negocio×talla); SKU acota reco, bodega no + test"
```

---

## Task 2: Frontend — 3 matrices de recomendaciones

**Files:**
- Modify: `informes/o14.php` (markup panel reco; `buildParams`; `loadReco`; reemplazar `renderReco`; añadir `renderRecoMatriz`/`renderRecoMatrices`/`o14RecoExp`; declarar `lastReco`; CSS)

- [ ] **Step 1: Markup del panel reco (3 contenedores)**

En `informes/o14.php`, reemplazar:
```html
  <div class="o14-tab-panel" id="o14-panel-reco"><div id="o14-reco"></div></div>
```
por:
```html
  <div class="o14-tab-panel" id="o14-panel-reco">
    <div id="o14-reco-sobrante" class="o14-reco-card"></div>
    <div id="o14-reco-faltante" class="o14-reco-card"></div>
    <div id="o14-reco-proveedor" class="o14-reco-card"></div>
  </div>
```

- [ ] **Step 2: CSS de las cards de reco**

En el `<style>` de `informes/o14.php`, añadir tras la regla `.o14-reco-resumen { ... }` (o al final del bloque de estilos reco):
```css
  .o14-reco-card { margin-bottom:18px; }
  .o14-reco-card .card-title { font-weight:700; color:var(--primary); margin-bottom:8px; font-size:13px; }
```

- [ ] **Step 3: `buildParams` — quitar ref/color del reco**

En `buildParams`, eliminar la línea:
```js
    if(tab==='reco'){ p.set('ref', negocioSel.ref); p.set('color', negocioSel.color); }
```

- [ ] **Step 4: `loadReco` — general (sin exigir negocio)**

Reemplazar la función `loadReco` completa:
```js
  function loadReco(){
    if(!negocioSel.ref){ renderReco({planes:[]}); return; }
    showLoading('Calculando recomendaciones');
    fetch('api/informe_o14.php?'+buildParams('reco'),{credentials:'same-origin'})
      .then(r=>r.json()).then(d=>{ if(!d.ok) throw 0; renderReco(d); tabState.reco=true; })
      .catch(()=>Swal.fire('Error','No se pudo calcular','error')).finally(hideLoading); }
```
por:
```js
  function loadReco(){
    showLoading('Calculando recomendaciones');
    fetch('api/informe_o14.php?'+buildParams('reco'),{credentials:'same-origin'})
      .then(r=>r.json()).then(d=>{ if(!d.ok) throw 0; renderRecoMatrices(d); tabState.reco=true; })
      .catch(()=>Swal.fire('Error','No se pudo calcular','error')).finally(hideLoading); }
```

- [ ] **Step 5: Reemplazar `renderReco` por `renderRecoMatrices` + `renderRecoMatriz` + `o14RecoExp`**

En `informes/o14.php`, reemplazar la función `renderReco` COMPLETA (desde `function renderReco(data){` hasta su `}` de cierre) por:
```js
  let lastReco=null;
  const RECO_MED=['sobrante','faltante','proveedor'];
  const RECO_LABEL={sobrante:'Reubicación — Sobrante disponible',faltante:'Faltante',proveedor:'Solicitud a proveedor'};
  // Pinta una matriz negocio×talla para una medida, con fila TOTAL arriba y botón Excel.
  function renderRecoMatriz(containerId, data, medida, expIdx){
    const cont=document.getElementById(containerId); if(!cont) return;
    const tallas=data.tallas||[];
    const filas=(data.filas||[]).filter(f=>{ const o=(f.valores||{})[medida]||{}; for(const t in o) if(o[t]) return true; return false; });
    let h='<div class="card-title">'+esc(RECO_LABEL[medida])+'<button class="g00-btn-export" onclick="o14RecoExp('+expIdx+')">⤓ Excel</button></div>';
    if(!filas.length){ cont.innerHTML=h+'<p style="padding:12px;color:var(--text-light)">Sin datos.</p>'; return; }
    h+='<div class="o14-matriz-wrap"><table class="o14-matriz" id="o14-reco-tbl-'+medida+'"><thead><tr><th class="dim">Negocio</th>';
    tallas.forEach(t=> h+='<th>'+esc(t)+'</th>'); h+='<th class="blocktot">Tot</th></tr></thead><tbody>';
    const tot={}; let gtot=0;
    filas.forEach(f=>{ const o=f.valores[medida]||{}; tallas.forEach(t=>{ tot[t]=(tot[t]||0)+(o[t]||0); }); });
    h+='<tr class="o14-total"><td class="dim">TOTAL</td>';
    tallas.forEach(t=>{ const v=tot[t]||0; gtot+=v; h+='<td>'+(v?nf(v):'')+'</td>'; }); h+='<td class="blocktot">'+nf(gtot)+'</td></tr>';
    filas.forEach(f=>{ const o=f.valores[medida]||{}; let rt=0; h+='<tr><td class="dim">'+esc(f.negocio)+'</td>';
      tallas.forEach(t=>{ const v=o[t]||0; rt+=v; h+='<td>'+(v?nf(v):'')+'</td>'; }); h+='<td class="blocktot">'+nf(rt)+'</td></tr>'; });
    h+='</tbody></table></div>'; cont.innerHTML=h;
  }
  function renderRecoMatrices(data){
    lastReco=data;
    renderRecoMatriz('o14-reco-sobrante', data, 'sobrante', 0);
    renderRecoMatriz('o14-reco-faltante', data, 'faltante', 1);
    renderRecoMatriz('o14-reco-proveedor', data, 'proveedor', 2);
  }
  window.o14RecoExp=function(i){
    const med=RECO_MED[i]; const tbl=document.getElementById('o14-reco-tbl-'+med);
    if(!tbl || typeof XLSX==='undefined'){ Swal.fire('Exportar','Nada que exportar.','info'); return; }
    const wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(tableToAOA(tbl)), 'Reco');
    XLSX.writeFile(wb,'O14_reco_'+med+'_'+new Date().toISOString().slice(0,10)+'.xlsx');
  };
```
(Nota: `tableToAOA`, `esc`, `nf` ya existen en el archivo. La función `renderReco` vieja y su markup de tablas origen→destino desaparecen.)

- [ ] **Step 6: Checks estáticos**

Run: `php -l informes/o14.php` → "No syntax errors detected".
Run: `grep -c "renderReco(" informes/o14.php` → `0` (ya no se usa la vieja; ojo: `renderRecoMatriz`/`renderRecoMatrices` NO coinciden con `renderReco(` por el carácter siguiente — si tu grep las cuenta, usar `grep -c "renderReco(data)"` que debe dar `0`).
Run: `grep -c "renderRecoMatrices\|renderRecoMatriz" informes/o14.php` → `≥4`.
Run: `grep -c "negocioSel.ref" informes/o14.php` → `≥1` (sigue usándose en O14C; en loadReco ya NO).

- [ ] **Step 7: Commit**

```bash
git add informes/o14.php
git commit -m "feat(o14): pestaña Recomendaciones con 3 matrices negocio×talla (Sobrante/Faltante/Proveedor) + Excel"
```

---

## Task 3: Frontend — indicador de actividad pendiente en la pestaña

**Files:**
- Modify: `informes/o14.php` (pestaña reco markup; `setRecoIndicator`; llamada en `renderKpis`; CSS)

- [ ] **Step 1: Markup del punto en la pestaña**

En `informes/o14.php`, reemplazar:
```html
    <div class="tab" onclick="o14ShowTab('reco', this)">Recomendaciones</div>
```
por:
```html
    <div class="tab" onclick="o14ShowTab('reco', this)">Recomendaciones <span id="o14-reco-dot" class="o14-reco-dot"></span></div>
```

- [ ] **Step 2: CSS del punto pulsante**

En el `<style>` de `informes/o14.php`, añadir:
```css
  .o14-reco-dot { display:none; width:8px; height:8px; border-radius:50%; background:var(--accent); margin-left:6px; vertical-align:middle; animation:o14pulse 1.2s ease-in-out infinite; }
  .o14-reco-dot.on { display:inline-block; }
  @keyframes o14pulse { 0%,100%{ opacity:1; transform:scale(1); } 50%{ opacity:.35; transform:scale(1.5); } }
```

- [ ] **Step 3: `setRecoIndicator` + llamada en `renderKpis`**

En `informes/o14.php`, añadir la función justo después de `renderKpis` (tras su `}` de cierre):
```js
  // Punto pulsante en la pestaña Recomendaciones cuando hay actividad pendiente en la vista.
  function setRecoIndicator(on){ const d=document.getElementById('o14-reco-dot'); if(d) d.classList.toggle('on', !!on); }
```
Y dentro de `renderKpis`, como ÚLTIMA línea antes de su `}` de cierre, añadir:
```js
    setRecoIndicator((k.sobrantes||0) > 0 || (k.faltante||0) > 0);
```

- [ ] **Step 4: Checks estáticos**

Run: `php -l informes/o14.php` → "No syntax errors detected".
Run: `grep -c "o14-reco-dot" informes/o14.php` → `3` (markup + 2 reglas CSS).
Run: `grep -c "setRecoIndicator" informes/o14.php` → `2` (definición + llamada en renderKpis).

- [ ] **Step 5: Commit**

```bash
git add informes/o14.php
git commit -m "feat(o14): indicador pulsante de actividad pendiente en la pestaña Recomendaciones"
```

---

## Task 4: Regresión + verificación

**Files:** ninguno.

- [ ] **Step 1: Suites**

Run: `php tests/o14_recomendador_test.php` → `27 pass, 0 fail`.
Run: `php tests/o14_reco_general_test.php` → `RESULTADO: OK`.
Run: `node tests/o14_kpis_arbol_test.mjs` → `RESULTADO: OK`.
Run: `php tests/o14_total_stock_test.php` → `RESULTADO: OK`.
Run: `php tests/o14_filtros_test.php` → `RESULTADO: OK`.
Run: `php tests/o14_ventas_rango_test.php` → `RESULTADO: OK`.
Run: `php tests/g00_detal_smoke_test.php` → `RESULTADO: OK`.

- [ ] **Step 2: Verificación E2E navegador (Rafael)** — checklist:
  - Abrir "Recomendaciones" SIN elegir negocio → 3 matrices (Sobrante / Faltante / Solicitud a proveedor), cada una con fila TOTAL arriba y botón ⤓ Excel.
  - Aplicar un filtro de producto (marca/categoría) → el set de negocios se acota; aplicar un filtro de bodega NO cambia las recomendaciones.
  - El punto pulsante aparece junto a "Recomendaciones" cuando hay sobrante/faltante en la vista; desaparece si no hay.
  - El Excel de cada matriz baja un `.xlsx` con los números correctos.

---

## Notas de ejecución
- `tab=reco` NO escanea `Ventas_Detal_Acum_PBI` (`$wantVentas` ya es false para reco) → es rápido (~pocos segundos). Los tests que usan tab b/c sí escanean Acum (~10-15s).
- El motor `api/o14_recomendador.php` NO se toca.
- El botón "Generar solicitud" (persistencia) sigue diferido — no aplica en este plan.
- Rama: continuar en `feat/o14-topbar-g00-style` (sin merge).
