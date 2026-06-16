# O14 — Ajustes de UI + fix de KPIs · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aplicar 8 ajustes acotados a O14: Total Stock=disp+hold, quitar Compañía, reordenar filtros/pestañas, Aplicar a la derecha, totales por grupo en "Por tienda", y arreglar que los KPIs no reflejaban el filtro de Negocio.

**Architecture:** Cambios casi todos en `informes/o14.php` (markup + JS de la IIFE); 2 líneas en `api/informe_o14.php` (Total Stock). El fix de KPIs añade `kpisFromArbol()` que computa los 10 KPIs desde el árbol visible (mismas reglas que el backend) y se invoca en los caminos de filtrado client-side por Negocio.

**Tech Stack:** PHP + sqlsrv, JS vanilla, tests CLI propios vía `tests/_endpoint_run_o14.php` + checks Node para lógica JS.

**Spec:** `docs/superpowers/specs/2026-06-09-o14-ajustes-ui-kpis.md`

---

## Estructura de archivos
- **Modify** `api/informe_o14.php` — `total_stock` en tab b y tab c (#1).
- **Create** `tests/o14_total_stock_test.php` — TDD de #1.
- **Modify** `informes/o14.php` — markup de filtros (#2-#5) y pestañas (#6); `renderArbol` (#8); `buildParams`, `loadC`, `o14SelectNegocio`, `o14PickNegocio`, `currentTab`, `o14OnEnter` y nuevo `kpisFromArbol` (#6, #9).
- **Create** `tests/o14_kpis_arbol_test.mjs` — check Node de `kpisFromArbol` (#9).

---

## Task 1: Backend — Total Stock = Disponible + Hold (#1)

**Files:**
- Test (create): `tests/o14_total_stock_test.php`
- Modify: `api/informe_o14.php` (tab b ~línea 286 y tab c ~línea 326)

- [ ] **Step 1: Crear el test que falla**

Crear `tests/o14_total_stock_test.php`:

```php
<?php
// total_stock debe ser disponible+hold en tab b y c (antes era solo disponible).
//   php tests/o14_total_stock_test.php ["PROVEEDOR"]
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
foreach (['b', 'c'] as $tab) {
    $d = ep($php, $runner, $prov, 'tab=' . $tab, $nul);
    $k = $d['kpis'] ?? [];
    $exp = (int)($k['disponible'] ?? 0) + (int)($k['hold'] ?? 0);
    $got = (int)($k['total_stock'] ?? -1);
    echo "tab=$tab disp=" . ($k['disponible'] ?? '?') . " hold=" . ($k['hold'] ?? '?') . " total_stock=$got (esperado $exp)\n";
    if ($got !== $exp) { echo "FALLO: total_stock != disponible+hold en tab=$tab\n"; $fail = 1; }
}
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
```

- [ ] **Step 2: Correr y verificar que FALLA**

Run: `php tests/o14_total_stock_test.php`
Expected: FALLO — hoy `total_stock == disponible` (BELTRANY: disp=964, hold=19, total_stock=964 ≠ 983). Cada llamada escanea Acum (~10-15s); el test tarda ~30-60s.

- [ ] **Step 3: Cambiar el cálculo en tab b**

En `api/informe_o14.php`, dentro del bloque `if ($tab === 'b')`, reemplazar:
```php
    $kpi['total_stock'] = $kpi['disponible'];
```
por:
```php
    $kpi['total_stock'] = $kpi['disponible'] + $kpi['hold'];
```

- [ ] **Step 4: Cambiar el cálculo en tab c**

En `api/informe_o14.php`, dentro del bloque `if ($tab === 'c')`, reemplazar:
```php
    $kpi['total_stock'] = $kpi['disponible'];
```
por:
```php
    $kpi['total_stock'] = $kpi['disponible'] + $kpi['hold'];
```

- [ ] **Step 5: Correr y verificar que PASA**

Run: `php tests/o14_total_stock_test.php`
Expected: PASS — `RESULTADO: OK` (BELTRANY: total_stock=983 en tab b y c).

- [ ] **Step 6: `php -l` y commit**

Run: `php -l api/informe_o14.php` → "No syntax errors detected".
```bash
git add api/informe_o14.php tests/o14_total_stock_test.php
git commit -m "feat(o14): Total Stock = disponible + hold (KPI) + test"
```

---

## Task 2: Reestructurar la barra de filtros (#2 quitar Compañía, #3 Negocio antes de Referencia, #4 fila bodega primera, #5 Aplicar a la derecha)

**Files:**
- Modify: `informes/o14.php` (markup `<div class="g00-filters o14-filters">`, líneas ~18-49; y `buildParams`)

- [ ] **Step 1: Reemplazar el markup de la barra de filtros**

En `informes/o14.php`, reemplazar TODO el bloque `<div class="g00-filters o14-filters"> ... </div>` (desde `<!-- Filtros -->` hasta el `</div>` que cierra `g00-filters`) por:

```html
  <!-- Filtros -->
  <div class="g00-filters o14-filters">
    <div class="g00-filter-row">
      <div class="filter-group"><label>Grupo</label><select id="o14-f-grupo" multiple></select></div>
      <div class="filter-group"><label>Tienda</label><select id="o14-f-tienda" multiple></select></div>
      <div class="filter-group"><label>Centro comercial</label><select id="o14-f-centro_comercial" multiple></select></div>
      <div class="filter-group"><label>Departamento</label><select id="o14-f-depto" multiple></select></div>
      <div class="filter-group"><label>Ciudad</label><select id="o14-f-ciudad" multiple></select></div>
      <div class="o14-apply">
        <span class="o14-hint" id="o14-c-sel"></span>
        <button class="g00-btn-refresh" onclick="o14Load()"><i class="fa-solid fa-rotate"></i> Aplicar</button>
      </div>
    </div>
    <div class="g00-filter-row">
      <div class="filter-group"><label>Marca</label><select id="o14-f-marca" multiple></select></div>
      <div class="filter-group"><label>Tipo</label><select id="o14-f-tipo" multiple></select></div>
      <div class="filter-group"><label>Categoría</label><select id="o14-f-categoria" multiple></select></div>
      <div class="filter-group"><label>Subcategoría</label><select id="o14-f-subcategoria" multiple></select></div>
      <div class="filter-group"><label>Género</label><select id="o14-f-genero" multiple></select></div>
      <div class="filter-group"><label>Público</label><select id="o14-f-publico" multiple></select></div>
      <div class="filter-group"><label>Negocio</label>
        <select id="o14-negocio" onchange="o14PickNegocio(this.value)"><option value="">— Todos —</option></select>
      </div>
      <div class="filter-group"><label>Referencia</label><select id="o14-f-referencia" multiple></select></div>
    </div>
    <div class="g00-filter-row">
      <div class="filter-group"><label>Color</label><select id="o14-f-color" multiple></select></div>
      <div class="filter-group"><label>Talla</label><select id="o14-f-talla" multiple></select></div>
    </div>
  </div>
```

Cambios respecto al actual: se elimina el `filter-group` de **Compañía** (`#o14-cia`); el **Negocio** (`#o14-negocio`, conserva `onchange="o14PickNegocio(this.value)"`) se mueve a la 2ª fila justo antes de Referencia; la fila de bodega queda 1ª; el hint `#o14-c-sel` y el botón **Aplicar** van en `.o14-apply` (se alinea a la derecha vía CSS del Step 2).

- [ ] **Step 2: CSS para alinear Aplicar a la derecha**

En el bloque `<style>` de `informes/o14.php`, añadir tras la regla `.o14-tabs .o14-export-btn { ... }`:

```css
  .o14-filters .o14-apply { margin-left:auto; display:flex; align-items:center; gap:10px; }
```

- [ ] **Step 3: Quitar la lectura de cia en `buildParams`**

En `buildParams`, eliminar la línea:
```js
    const cia = val('o14-cia'); if(cia) p.set('cia', cia);
```
(El backend trata `cia` vacío como "sin filtro de cía". No quedan otras referencias a `o14-cia`.)

- [ ] **Step 4: Checks estáticos**

Run: `php -l informes/o14.php` → "No syntax errors detected".
Run: `grep -c "o14-cia" informes/o14.php` → `0` (ninguna referencia).
Run: `grep -c "o14-negocio" informes/o14.php` → `≥2` (el select + usos en `populateNegocios`/`o14SelectNegocio`).

- [ ] **Step 5: Commit**

```bash
git add informes/o14.php
git commit -m "feat(o14): barra de filtros sin Compañía, Negocio antes de Referencia, bodega primera, Aplicar a la derecha"
```

---

## Task 3: Reordenar pestañas a Por tienda → Por negocio → Recomendaciones (#6)

**Files:**
- Modify: `informes/o14.php` (markup de `.o14-tabs` y paneles; `currentTab`; `o14OnEnter`; `o14SelectNegocio`; `o14PickNegocio`)

- [ ] **Step 1: Reordenar las pestañas y el panel activo**

En `informes/o14.php`, reemplazar el bloque de pestañas:
```html
  <div class="tab-bar o14-tabs">
    <div class="tab active" onclick="o14ShowTab('b', this)">Por negocio</div>
    <div class="tab" onclick="o14ShowTab('c', this)">Por tienda</div>
    <div class="tab" onclick="o14ShowTab('reco', this)">Recomendaciones</div>
    <button class="g00-btn-export o14-export-btn" onclick="o14Export()">⤓ Excel</button>
  </div>
```
por:
```html
  <div class="tab-bar o14-tabs">
    <div class="tab active" onclick="o14ShowTab('c', this)">Por tienda</div>
    <div class="tab" onclick="o14ShowTab('b', this)">Por negocio</div>
    <div class="tab" onclick="o14ShowTab('reco', this)">Recomendaciones</div>
    <button class="g00-btn-export o14-export-btn" onclick="o14Export()">⤓ Excel</button>
  </div>
```
Y cambiar la clase `active` de panel: reemplazar
```html
  <div class="o14-tab-panel active" id="o14-panel-b"><div id="o14-matriz-b" class="o14-matriz-wrap"></div></div>
  <div class="o14-tab-panel" id="o14-panel-c"><div id="o14-matriz-c" class="o14-matriz-wrap"></div></div>
```
por:
```html
  <div class="o14-tab-panel" id="o14-panel-b"><div id="o14-matriz-b" class="o14-matriz-wrap"></div></div>
  <div class="o14-tab-panel active" id="o14-panel-c"><div id="o14-matriz-c" class="o14-matriz-wrap"></div></div>
```

- [ ] **Step 2: `currentTab` por defecto = 'c'**

En la IIFE, cambiar:
```js
  let currentTab = 'b';
```
por:
```js
  let currentTab = 'c';
```

- [ ] **Step 3: `o14OnEnter` carga la pestaña por defecto**

En `o14OnEnter`, reemplazar la última línea:
```js
    if(!tabState.b) loadB();
```
por:
```js
    if(!tabState[currentTab]) loadCurrentTab();
```

- [ ] **Step 4: Corregir el selector del tab C (nth-child) en dos funciones**

El tab "Por tienda" pasa a ser el 1er `.tab`. En `o14SelectNegocio` reemplazar:
```js
    const cTab = document.querySelector('#page-informes-o14 .o14-tabs .tab:nth-child(2)');
```
por:
```js
    const cTab = document.querySelector('#page-informes-o14 .o14-tabs .tab:nth-child(1)');
```
Y en `o14PickNegocio` reemplazar la misma línea:
```js
    const cTab = document.querySelector('#page-informes-o14 .o14-tabs .tab:nth-child(2)');
```
por:
```js
    const cTab = document.querySelector('#page-informes-o14 .o14-tabs .tab:nth-child(1)');
```

- [ ] **Step 5: Checks estáticos**

Run: `php -l informes/o14.php` → "No syntax errors detected".
Run: `grep -c "nth-child(2)" informes/o14.php` → `0`.
Run: `grep -c "nth-child(1)" informes/o14.php` → `2`.

- [ ] **Step 6: Commit**

```bash
git add informes/o14.php
git commit -m "feat(o14): pestañas Por tienda/Por negocio/Recomendaciones (default Por tienda) + fix selector tab C"
```

---

## Task 4: Totales por grupo en "Por tienda" — total al final + TOTAL con/sin CEDI (#8)

**Files:**
- Modify: `informes/o14.php` (función `renderArbol`, líneas ~250-280; y un estilo)

- [ ] **Step 1: Reescribir el cuerpo de `renderArbol`**

En `informes/o14.php`, reemplazar desde `const gtot={}; medidas.forEach(m=>gtot[m]={});` hasta `h+='</tbody></table>'; cont.innerHTML=h;` (el bloque que arma los grupos y el TOTAL) por:

```js
    const gtot={}; medidas.forEach(m=>gtot[m]={});       // total sin CEDI
    const gtotC={}; medidas.forEach(m=>gtotC[m]={});     // total con CEDI
    const nCols = medidas.length*(tallas.length+1);
    grupos.forEach((gr,gi)=>{
      const allNeg=[]; gr.almacenes.forEach(a=>a.negocios.forEach(n=>allNeg.push(n)));
      const gv=sumValores(allNeg, medidas);
      const gexp=arbolState.g[gi].exp;
      medidas.forEach(m=>{ for(const t in gv[m]) gtotC[m][t]=(gtotC[m][t]||0)+gv[m][t]; });        // con CEDI: todos
      if(gr.grupo!=='CEDI') medidas.forEach(m=>{ for(const t in gv[m]) gtot[m][t]=(gtot[m][t]||0)+gv[m][t]; }); // sin CEDI
      // Header de grupo: toggle + nombre, SIN números (solo para colapsar).
      h+='<tr class="o14-row-grupo" data-g="'+gi+'"><td class="dim" onclick="o14ToggleGrupo('+gi+')"><span class="o14-tw">'+(gexp?'▼':'▶')+'</span> '+esc(gr.grupo)+'</td><td colspan="'+nCols+'"></td></tr>';
      gr.almacenes.forEach((a,ai)=>{
        const av=sumValores(a.negocios, medidas);
        const aexp=arbolState.g[gi].a[ai];
        const aHide=gexp?'':' style="display:none"';
        h+='<tr class="o14-row-alm" data-g="'+gi+'" data-a="'+ai+'"'+aHide+'><td class="dim" style="padding-left:22px" onclick="o14ToggleAlm('+gi+','+ai+')"><span class="o14-tw">'+(aexp?'▼':'▶')+'</span> '+esc(a.bodega)+' · '+esc(a.nombre)+'</td>'+rowCells(av,tallas,medidas,false)+'</tr>';
        a.negocios.forEach(n=>{
          const nHide=(gexp&&aexp)?'':' style="display:none"';
          h+='<tr class="o14-row-neg" data-g="'+gi+'" data-a="'+ai+'"'+nHide+'><td class="dim" style="padding-left:42px">'+esc(n.negocio)+'</td>'+rowCells(n.valores,tallas,medidas,true)+'</tr>';
        });
      });
      // Total del grupo al final (oculto si el grupo está colapsado).
      const gHide=gexp?'':' style="display:none"';
      h+='<tr class="o14-row-grupo o14-grupo-total" data-g="'+gi+'"'+gHide+'><td class="dim" style="padding-left:22px">Total '+esc(gr.grupo)+'</td>'+rowCells(gv,tallas,medidas,false)+'</tr>';
    });
    h+='<tr class="o14-total"><td class="dim">TOTAL (sin CEDI)</td>'+rowCells(gtot,tallas,medidas,false)+'</tr>';
    h+='<tr class="o14-total"><td class="dim">TOTAL (con CEDI)</td>'+rowCells(gtotC,tallas,medidas,false)+'</tr>';
    h+='</tbody></table>'; cont.innerHTML=h;
```

(Diferencias vs el actual: el header de grupo ya NO pinta `rowCells(gv,...)` sino una celda `colspan` vacía; se agrega la fila `o14-grupo-total` al final de cada grupo con `rowCells(gv,...)`, oculta si colapsado; y se añade el segundo total `TOTAL (con CEDI)` usando `gtotC` que acumula todos los grupos.)

- [ ] **Step 2: Estilo de la fila "Total &lt;grupo&gt;"**

En el `<style>` de `informes/o14.php`, añadir tras la regla `.o14-matriz tr.o14-row-grupo td.dim { cursor:pointer; }`:

```css
  .o14-matriz tr.o14-grupo-total td { border-top:1px solid var(--primary); }
  .o14-matriz tr.o14-grupo-total td.dim { cursor:default; }
```

- [ ] **Step 3: Checks estáticos**

Run: `php -l informes/o14.php` → "No syntax errors detected".
Run: `grep -c "TOTAL (con CEDI)" informes/o14.php` → `1`.
Run: `grep -c "o14-grupo-total" informes/o14.php` → `≥2` (markup + CSS).

- [ ] **Step 4: Commit**

```bash
git add informes/o14.php
git commit -m "feat(o14): total por grupo al final (header sin números) + TOTAL con/sin CEDI en Por tienda"
```

---

## Task 5: Fix — KPIs reflejan el filtro de Negocio (#9)

**Files:**
- Modify: `informes/o14.php` (nuevo `kpisFromArbol`; `loadC`; `o14SelectNegocio`; `o14PickNegocio`)
- Test (create): `tests/o14_kpis_arbol_test.mjs`

- [ ] **Step 1: Crear el check Node de `kpisFromArbol` (falla: función no existe aún en el harness)**

Crear `tests/o14_kpis_arbol_test.mjs` con la función + datos sintéticos + asserts:

```js
// Verifica kpisFromArbol (copia idéntica a la de informes/o14.php) sobre un árbol sintético.
//   node tests/o14_kpis_arbol_test.mjs
function kpisFromArbol(data){
  const k={siembra:0,disponible:0,hold:0,ventas:0,sobrantes:0,faltante:0};
  const negSet={}, negSiem={}, tdaSiem=new Set(), tdaInv=new Set(), tdaVta=new Set();
  (data.grupos||[]).forEach(g=>{
    if(g.grupo==='CEDI') return;
    (g.almacenes||[]).forEach(a=>{
      const cia=String(a.llave).slice(0, String(a.llave).length - String(a.bodega).length - 1);
      let aSi=0, aDi=0, aVeAny=false;
      (a.negocios||[]).forEach(n=>{
        const negKey=cia+'|'+n.negocio, v=n.valores||{};
        const sumM=(m)=>{ let s=0; const o=v[m]||{}; for(const t in o) s+=o[t]; return s; };
        const si=sumM('siembra'), di=sumM('disponible'), ho=sumM('hold'), ve=sumM('ventas');
        k.siembra+=si; k.disponible+=di; k.hold+=ho; k.ventas+=ve;
        const tallas=new Set([...Object.keys(v.siembra||{}),...Object.keys(v.disponible||{}),...Object.keys(v.hold||{})]);
        tallas.forEach(t=>{ const bal=((v.siembra||{})[t]||0)-(((v.disponible||{})[t]||0)+((v.hold||{})[t]||0));
          if(bal>0) k.faltante+=bal; else if(bal<0) k.sobrantes+=-bal; });
        negSet[negKey]=1; if(si>0) negSiem[negKey]=1;
        aSi+=si; aDi+=di;
        const vo=v.ventas||{}; for(const t in vo) if(vo[t]!==0) aVeAny=true;
      });
      if(aSi>0) tdaSiem.add(a.llave);
      if(aDi>0) tdaInv.add(a.llave);
      if(aVeAny) tdaVta.add(a.llave);
    });
  });
  k.total_stock=k.disponible+k.hold;
  k.negocios=Object.keys(negSet).length;
  k.negocios_con_siembra=Object.keys(negSiem).length;
  k.tiendas_con_siembra=tdaSiem.size;
  k.tiendas_con_inv=tdaInv.size;
  k.tiendas_con_venta=tdaVta.size;
  return k;
}

const data={grupos:[
  {grupo:'AKA',almacenes:[
    {llave:'007-245',bodega:'245',nombre:'TUNJA',negocios:[
      {negocio:'A-NEG',referencia:'A',color:'NEG',valores:{siembra:{'10':5},disponible:{'10':2},hold:{'10':1},ventas:{'10':3}}},
    ]},
    {llave:'007-547',bodega:'547',nombre:'X',negocios:[
      {negocio:'A-NEG',referencia:'A',color:'NEG',valores:{siembra:{'10':0},disponible:{'10':0},hold:{'10':0},ventas:{'10':1}}},
    ]},
  ]},
  {grupo:'CEDI',almacenes:[
    {llave:'007-CEDI',bodega:'CEDI',nombre:'CEDI',negocios:[
      {negocio:'A-NEG',referencia:'A',color:'NEG',valores:{siembra:{'10':9},disponible:{'10':9},hold:{'10':0},ventas:{'10':0}}},
    ]},
  ]},
]};

const k=kpisFromArbol(data);
const exp={siembra:5,disponible:2,hold:1,total_stock:3,ventas:4,faltante:2,sobrantes:0,
  negocios:1,negocios_con_siembra:1,tiendas_con_siembra:1,tiendas_con_inv:1,tiendas_con_venta:2};
let fail=0;
for(const key in exp){ if(k[key]!==exp[key]){ console.error(`FALLO: ${key}=${k[key]} esperado ${exp[key]}`); fail=1; } }
console.log('kpis:',JSON.stringify(k));
console.log(fail?'RESULTADO: FALLO':'RESULTADO: OK (CEDI excluido, balance y conteos)');
process.exit(fail);
```

- [ ] **Step 2: Correr el check y verificar el resultado esperado**

Run: `node tests/o14_kpis_arbol_test.mjs`
Expected: `RESULTADO: OK` — valida la lógica con la que se implementará la función real en el siguiente paso (es la misma copia). KPIs: siembra5, disp2, hold1, stock3, ventas4, faltante2, sobrantes0, negocios1, tiendas_con_venta2, etc.

- [ ] **Step 3: Añadir `kpisFromArbol` a `informes/o14.php`**

En `informes/o14.php`, inmediatamente DESPUÉS de la función `renderKpis` (después de su `}` de cierre, ~línea 198), insertar la MISMA función:

```js
  // Computa los 10 KPIs desde un árbol grupos[] (excluye CEDI), con las mismas reglas que el backend.
  // total_stock = disponible+hold; sobrante/faltante por (almacén,negocio,talla); conteos sobre llave (cia-bodega) y (cia|negocio).
  function kpisFromArbol(data){
    const k={siembra:0,disponible:0,hold:0,ventas:0,sobrantes:0,faltante:0};
    const negSet={}, negSiem={}, tdaSiem=new Set(), tdaInv=new Set(), tdaVta=new Set();
    (data.grupos||[]).forEach(g=>{
      if(g.grupo==='CEDI') return;
      (g.almacenes||[]).forEach(a=>{
        const cia=String(a.llave).slice(0, String(a.llave).length - String(a.bodega).length - 1);
        let aSi=0, aDi=0, aVeAny=false;
        (a.negocios||[]).forEach(n=>{
          const negKey=cia+'|'+n.negocio, v=n.valores||{};
          const sumM=(m)=>{ let s=0; const o=v[m]||{}; for(const t in o) s+=o[t]; return s; };
          const si=sumM('siembra'), di=sumM('disponible'), ho=sumM('hold'), ve=sumM('ventas');
          k.siembra+=si; k.disponible+=di; k.hold+=ho; k.ventas+=ve;
          const tallas=new Set([...Object.keys(v.siembra||{}),...Object.keys(v.disponible||{}),...Object.keys(v.hold||{})]);
          tallas.forEach(t=>{ const bal=((v.siembra||{})[t]||0)-(((v.disponible||{})[t]||0)+((v.hold||{})[t]||0));
            if(bal>0) k.faltante+=bal; else if(bal<0) k.sobrantes+=-bal; });
          negSet[negKey]=1; if(si>0) negSiem[negKey]=1;
          aSi+=si; aDi+=di;
          const vo=v.ventas||{}; for(const t in vo) if(vo[t]!==0) aVeAny=true;
        });
        if(aSi>0) tdaSiem.add(a.llave);
        if(aDi>0) tdaInv.add(a.llave);
        if(aVeAny) tdaVta.add(a.llave);
      });
    });
    k.total_stock=k.disponible+k.hold;
    k.negocios=Object.keys(negSet).length;
    k.negocios_con_siembra=Object.keys(negSiem).length;
    k.tiendas_con_siembra=tdaSiem.size;
    k.tiendas_con_inv=tdaInv.size;
    k.tiendas_con_venta=tdaVta.size;
    return k;
  }
```

- [ ] **Step 4: Usar `kpisFromArbol` en los caminos de filtrado por negocio**

En `loadC`, reemplazar el `.then(...)` actual:
```js
      .then(r=>r.json()).then(d=>{ if(!d.ok) throw 0; renderKpis(d.kpis||{});
        lastData.c=d; arbolState=null;
        if(negocioSel.ref){ shownC=filterArbol(d, negocioSel.ref, negocioSel.color); renderArbol('o14-matriz-c', shownC, true); }
        else { shownC=d; renderArbol('o14-matriz-c', d, false); }
        tabState.c=true; })
```
por:
```js
      .then(r=>r.json()).then(d=>{ if(!d.ok) throw 0;
        lastData.c=d; arbolState=null;
        if(negocioSel.ref){ shownC=filterArbol(d, negocioSel.ref, negocioSel.color); renderArbol('o14-matriz-c', shownC, true); renderKpis(kpisFromArbol(shownC)); }
        else { shownC=d; renderArbol('o14-matriz-c', d, false); renderKpis(d.kpis||{}); }
        tabState.c=true; })
```

En `o14SelectNegocio`, reemplazar la última línea del `if`:
```js
    if(tabState.c && lastData.c){ shownC=filterArbol(lastData.c, ref, color); renderArbol('o14-matriz-c', shownC, true); }
```
por:
```js
    if(tabState.c && lastData.c){ shownC=filterArbol(lastData.c, ref, color); renderArbol('o14-matriz-c', shownC, true); renderKpis(kpisFromArbol(shownC)); }
```

En `o14PickNegocio`, en la rama de "— Todos —", reemplazar:
```js
      if(tabState.c && lastData.c){ shownC=lastData.c; arbolState=null; renderArbol('o14-matriz-c', lastData.c, false); }
```
por:
```js
      if(tabState.c && lastData.c){ shownC=lastData.c; arbolState=null; renderArbol('o14-matriz-c', lastData.c, false); renderKpis(lastData.c.kpis||{}); }
```

- [ ] **Step 5: Checks estáticos**

Run: `php -l informes/o14.php` → "No syntax errors detected".
Run: `grep -c "kpisFromArbol" informes/o14.php` → `3` (definición + uso en `loadC` + uso en `o14SelectNegocio`; `o14PickNegocio` usa la versión server `lastData.c.kpis`, no `kpisFromArbol`).

- [ ] **Step 6: Commit**

```bash
git add informes/o14.php tests/o14_kpis_arbol_test.mjs
git commit -m "fix(o14): KPIs reflejan el filtro de Negocio (kpisFromArbol desde la vista visible) + test"
```

---

## Task 6: Regresión + verificación

**Files:** ninguno (corre suites).

- [ ] **Step 1: Suites O14**

Run: `php tests/o14_recomendador_test.php` → `27 pass, 0 fail`.
Run: `node tests/o14_kpis_arbol_test.mjs` → `RESULTADO: OK`.
Run: `php tests/o14_total_stock_test.php` → `RESULTADO: OK`.
Run: `php tests/o14_filtros_test.php` → `RESULTADO: OK`.
Run: `php tests/o14_ventas_rango_test.php` → `RESULTADO: OK`.

- [ ] **Step 2: Regresión G00**

Run: `php tests/g00_detal_smoke_test.php` → `RESULTADO: OK`.

- [ ] **Step 3: Verificación E2E navegador (Rafael)** — checklist:
  - "Total Stock" ahora = disponible+hold.
  - Card de filtros SIN Compañía; Negocio aparece antes de Referencia; fila de bodega (Grupo/Tienda/CC/Depto/Ciudad) es la primera; botón Aplicar a la derecha.
  - Pestañas en orden Por tienda / Por negocio / Recomendaciones; abre en "Por tienda" por defecto.
  - En "Por tienda": cada grupo tiene su header arriba (▼ + nombre) y su fila "Total &lt;grupo&gt;" al final; al pie, dos filas TOTAL (sin CEDI) y (con CEDI).
  - Al elegir un Negocio (selector o clic en una fila), los **KPIs cambian** a los de ese negocio; al volver a "— Todos —", regresan al total filtrado.

---

## Notas de ejecución
- Cada test que pega al endpoint escanea `Ventas_Detal_Acum_PBI` (~10-15s) por el default de ventas desde 2025. Esperar ~30-60s por test.
- Rama: continuar en `feat/o14-topbar-g00-style` (sin merge).
- #7 (indicador/recomendaciones sobre vista filtrada) NO va aquí — spec propio.
