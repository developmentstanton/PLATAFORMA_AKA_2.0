<?php /* Informe O14 — Siembra & Stock x Tienda x Talla (incluido desde dashboard.php) */ ?>
<div class="page" id="page-informes-o14">
  <!-- KPIs -->
  <div class="o14-kpis">
    <div class="o14-kpi"><span class="o14-kpi-lbl">Negocios</span><span class="o14-kpi-val" id="o14-kpi-negocios">0</span></div>
    <div class="o14-kpi"><span class="o14-kpi-lbl">Siembra</span><span class="o14-kpi-val" id="o14-kpi-siembra">0</span></div>
    <div class="o14-kpi"><span class="o14-kpi-lbl">Tiendas c/ Siembra</span><span class="o14-kpi-val" id="o14-kpi-tiendas-siembra">0</span></div>
    <div class="o14-kpi"><span class="o14-kpi-lbl">Negocios c/ Siembra</span><span class="o14-kpi-val" id="o14-kpi-negocios-siembra">0</span></div>
    <div class="o14-kpi"><span class="o14-kpi-lbl">Total Stock</span><span class="o14-kpi-val" id="o14-kpi-stock">0</span></div>
    <div class="o14-kpi"><span class="o14-kpi-lbl">Tiendas c/ Inv</span><span class="o14-kpi-val" id="o14-kpi-tiendas-inv">0</span></div>
    <div class="o14-kpi"><span class="o14-kpi-lbl">Ventas</span><span class="o14-kpi-val" id="o14-kpi-ventas">0</span></div>
    <div class="o14-kpi"><span class="o14-kpi-lbl">Tiendas c/ Venta</span><span class="o14-kpi-val" id="o14-kpi-tiendas-venta">0</span></div>
    <div class="o14-kpi o14-kpi-sob"><span class="o14-kpi-lbl">Sobrantes</span><span class="o14-kpi-val" id="o14-kpi-sobrantes">0</span></div>
    <div class="o14-kpi o14-kpi-fal"><span class="o14-kpi-lbl">Faltante</span><span class="o14-kpi-val" id="o14-kpi-faltante">0</span></div>
  </div>

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

  <!-- Tabs -->
  <div class="tab-bar o14-tabs">
    <div class="tab active" onclick="o14ShowTab('c', this)">Por tienda</div>
    <div class="tab" onclick="o14ShowTab('b', this)">Por negocio</div>
    <div class="tab" onclick="o14ShowTab('reco', this)">Recomendaciones</div>
    <button class="g00-btn-export o14-export-btn" onclick="o14Export()">⤓ Excel</button>
  </div>

  <div class="o14-tab-panel" id="o14-panel-b"><div id="o14-matriz-b" class="o14-matriz-wrap"></div></div>
  <div class="o14-tab-panel active" id="o14-panel-c"><div id="o14-matriz-c" class="o14-matriz-wrap"></div></div>
  <div class="o14-tab-panel" id="o14-panel-reco"><div id="o14-reco"></div></div>
</div>

<style>
  .o14-kpis { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:16px; }
  .o14-kpi { background:#fff; border:1px solid var(--border); border-radius:10px; padding:10px 14px; display:flex; flex-direction:column; gap:4px; }
  .o14-kpi-lbl { font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-light); font-weight:600; }
  .o14-kpi-val { font-size:20px; font-weight:700; color:var(--primary); }
  .o14-kpi-sob .o14-kpi-val { color:#9a6b00; }
  .o14-kpi-fal .o14-kpi-val { color:#c0001a; }
  #page-informes-o14 .o14-kpis, #page-informes-o14 .g00-filters { width:100%; box-sizing:border-box; }
  #page-informes-o14 .o14-matriz-wrap { max-width:100%; }
  .o14-filters .o14-hint { font-size:11px; color:var(--text-light); }
  .o14-tabs .o14-export-btn { margin-left:auto; float:none; align-self:center; }
  .o14-filters .o14-apply { margin-left:auto; display:flex; align-items:center; gap:10px; }
  .o14-tab-panel { display:none; }
  .o14-tab-panel.active { display:block; animation:o14fade .3s ease-out; }
  @keyframes o14fade { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }
  .o14-matriz-wrap { overflow-x:auto; border:1px solid var(--border); border-radius:10px; background:#fff; }
  .o14-matriz { border-collapse:collapse; font-size:10px; white-space:nowrap; min-width:100%; }
  .o14-matriz th, .o14-matriz td { border:1px solid var(--border); padding:2px 6px; text-align:center; }
  .o14-matriz thead th { background:#f3f2fa; color:var(--primary); position:sticky; top:0; }
  .o14-matriz th.dim, .o14-matriz td.dim { text-align:center; position:sticky; left:0; background:#fff; z-index:1; }
  .o14-matriz thead th.dim { background:#f3f2fa; z-index:2; }
  .o14-matriz tr:hover td { background:#faf9ff; }
  .o14-clickable { cursor:pointer; color:var(--primary); font-weight:600; }
  .o14-clickable:hover { text-decoration:underline; }
  .o14-cell-fal { background:#ffe3e3; color:#c0001a; font-weight:600; }
  .o14-cell-sob { background:#fff4d6; color:#9a6b00; font-weight:600; }
  .o14-matriz td.blocktot, .o14-matriz th.blocktot { background:#efecfa; font-weight:700; border-left:2px solid #cfc8ee; }
  .o14-matriz tr.o14-total td { font-weight:800; background:#e0dbf2; color:var(--primary); border-top:2px solid var(--primary); border-bottom:2px solid var(--primary); }
  .o14-matriz tr.o14-total td.blocktot { background:#cfc7ec; border-left:2px solid var(--primary); }
  .o14-arbol .o14-tw { display:inline-block; width:12px; color:var(--primary); font-size:9px; }
  .o14-matriz tr.o14-row-grupo td { background:#e3def3; font-weight:800; color:var(--primary); }
  .o14-matriz tr.o14-row-grupo td.dim { cursor:pointer; }
  .o14-matriz tr.o14-grupo-total td { border-top:1px solid var(--primary); }
  .o14-matriz tr.o14-grupo-total td.dim { cursor:default; }
  .o14-matriz tr.o14-row-alm td { background:#f1eefb; font-weight:600; }
  .o14-matriz tr.o14-row-alm td.dim { cursor:pointer; }
  .o14-matriz tr.o14-row-neg td.dim { color:var(--text); font-weight:400; }
  .o14-reco-cia { background:#fff; border:1px solid var(--border); border-radius:10px; padding:14px 18px; margin-bottom:14px; }
  .o14-reco-cia h4 { margin:0 0 10px; color:var(--primary); }
  .o14-reco-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
  .o14-reco-grid h5 { margin:0 0 6px; font-size:12px; text-transform:uppercase; color:var(--text-light); }
  .o14-reco-grid table { width:100%; border-collapse:collapse; font-size:11px; }
  .o14-reco-grid th, .o14-reco-grid td { border:1px solid var(--border); padding:3px 8px; text-align:center; }
  .o14-reco-resumen { margin:12px 0 8px; font-size:12px; color:var(--text); }
</style>

<script>
(function () {
  'use strict';
  let currentTab = 'c';
  let proveedorActual = (window.PROVEEDOR_ACTUAL || '');
  let negocioSel = { ref:'', color:'' };
  const tabState = { b:false, c:false, reco:false };
  const lastData = { b:null, c:null };
  let shownC = null;     // datos actualmente renderizados en O14C (completo o filtrado)
  let arbolState = null; // { n:<#grupos>, g:[{exp:bool, a:[bool,...]}] }

  const REF_FIELDS = ['marca','tipo','categoria','subcategoria','genero','publico','referencia'];
  const SKU_FIELDS = ['color','talla'];
  const BOD_FIELDS = ['grupo','tienda','centro_comercial','depto','ciudad'];
  const COMBO_FIELDS = [...REF_FIELDS, ...BOD_FIELDS];
  const FILTER_FIELDS = [...REF_FIELDS, ...SKU_FIELDS, ...BOD_FIELDS];
  const tom = {};
  let combos = [], sku = [], cascadeBusy = false, filtrosInit = false;
  const selectedOf = (f) => tom[f] ? tom[f].getValue() : [];

  function refsAllowedBySku() {
    const selC = selectedOf('color'), selT = selectedOf('talla');
    if (!selC.length && !selT.length) return null;
    const cS = new Set(selC), tS = new Set(selT), out = new Set();
    for (const s of sku) { if (selC.length && !cS.has(s.color)) continue; if (selT.length && !tS.has(s.talla)) continue; out.add(s.referencia); }
    return out;
  }
  function comboMatches(c, exclude) {
    for (const f of COMBO_FIELDS) { if (f === exclude) continue; const sel = selectedOf(f); if (sel.length && !sel.includes(c[f])) return false; }
    return true;
  }
  function activeRefs(exclude) { const out = new Set(); for (const c of combos) if (comboMatches(c, exclude)) out.add(c.referencia); return out; }
  function availableCombo(field) {
    const skuRefs = refsAllowedBySku(), out = new Set();
    for (const c of combos) { if (skuRefs && !skuRefs.has(c.referencia)) continue; if (!comboMatches(c, field)) continue; if (c[field] !== '' && c[field] != null) out.add(c[field]); }
    return Array.from(out).sort((a,b)=>a.localeCompare(b,'es'));
  }
  function availableSku(field) {
    const other = field === 'color' ? 'talla' : 'color';
    const refs = activeRefs(null), oSel = selectedOf(other), oS = new Set(oSel), out = new Set();
    for (const s of sku) { if (!refs.has(s.referencia)) continue; if (oSel.length && !oS.has(s[other])) continue; if (s[field] !== '' && s[field] != null) out.add(s[field]); }
    return Array.from(out).sort((a,b)=>a.localeCompare(b,'es'));
  }
  const availableFor = (f) => SKU_FIELDS.includes(f) ? availableSku(f) : availableCombo(f);
  function refreshOptions() {
    if (cascadeBusy) return; cascadeBusy = true;
    FILTER_FIELDS.forEach(field => {
      const ts = tom[field]; if (!ts) return;
      const keep = ts.getValue();
      const opts = availableFor(field).map(v => ({ value:v, text:v }));
      const keepArr = Array.isArray(keep) ? keep : (keep ? [keep] : []);
      keepArr.forEach(v => { if (!opts.find(o => o.value === v)) opts.push({ value:v, text:v }); });
      ts.clearOptions(); ts.addOptions(opts); ts.refreshOptions(false);
    });
    cascadeBusy = false;
  }
  function initFiltros() {
    FILTER_FIELDS.forEach(field => {
      tom[field] = new TomSelect('#o14-f-' + field, { plugins:['remove_button'], maxOptions:1000, placeholder:'Todas', onChange:()=>refreshOptions() });
    });
    fetch('api/informe_o14.php?tab=filtros', { credentials:'same-origin' })
      .then(r=>r.json()).then(data => { combos = (data&&data.combos)?data.combos:[]; sku = (data&&data.sku)?data.sku:[]; refreshOptions(); })
      .catch(()=>{ combos=[]; sku=[]; });
  }

  function showLoading(accion){ const p=proveedorActual?(' del proveedor <strong>'+esc(proveedorActual)+'</strong>'):'';
    Swal.fire({title:accion||'Cargando',html:'Obteniendo información'+p+'…',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()}); }
  function hideLoading(){ if(window.Swal && Swal.isVisible()) Swal.close(); }
  const nf = (n)=> (Math.round(n)||0).toLocaleString('es-CO');
  function esc(s){ return (s==null?'':String(s)).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }
  const val = (id)=>{ const e=document.getElementById(id); return e? e.value.trim():''; };

  function buildParams(tab){
    const hoy = new Date().toISOString().slice(0,10);
    const desde = val('o14-vdesde') || '2025-01-01';   // rango SOLO de ventas
    const hasta = val('o14-vhasta') || hoy;
    const p = new URLSearchParams({ tab:tab, desde:desde, hasta:hasta });
    FILTER_FIELDS.forEach(f => { (selectedOf(f)||[]).forEach(v => p.append(f+'[]', v)); });
    if(tab==='reco'){ p.set('ref', negocioSel.ref); p.set('color', negocioSel.color); }
    return p.toString();
  }

  function renderKpis(k){
    k = k || {};
    const set=(id,v)=>{ const e=document.getElementById(id); if(e) e.textContent=nf(v||0); };
    set('o14-kpi-negocios',k.negocios); set('o14-kpi-siembra',k.siembra);
    set('o14-kpi-tiendas-siembra',k.tiendas_con_siembra); set('o14-kpi-negocios-siembra',k.negocios_con_siembra);
    set('o14-kpi-stock',k.total_stock); set('o14-kpi-tiendas-inv',k.tiendas_con_inv);
    set('o14-kpi-ventas',k.ventas); set('o14-kpi-tiendas-venta',k.tiendas_con_venta);
    set('o14-kpi-sobrantes',k.sobrantes); set('o14-kpi-faltante',k.faltante);
  }

  // Suma valores[medida][talla] sobre una lista de negocios (para subtotales de grupo/almacén).
  function sumValores(negocios, medidas){
    const out={}; medidas.forEach(m=>out[m]={});
    negocios.forEach(n=>medidas.forEach(m=>{ const v=n.valores[m]||{}; for(const t in v) out[m][t]=(out[m][t]||0)+v[t]; }));
    return out;
  }
  // Celdas de medidas×tallas+Tot para una fila. colorize=true solo en hojas (negocio).
  function rowCells(valores, tallas, medidas, colorize){
    let h='';
    medidas.forEach(m=>{ let tot=0; tallas.forEach(t=>{ const v=(valores[m]||{})[t]||0; tot+=v;
      const cls=colorize?((m==='faltante'&&v>0)?' class="o14-cell-fal"':((m==='sobrante'&&v>0)?' class="o14-cell-sob"':'')):'';
      h+='<td'+cls+'>'+(v?nf(v):'')+'</td>'; }); h+='<td class="blocktot">'+nf(tot)+'</td>'; });
    return h;
  }

  function renderMatriz(containerId, data, dimLabel, clickable){
    const cont = document.getElementById(containerId);
    if(!data || !data.filas || !data.filas.length){ cont.innerHTML = '<p style="padding:16px;color:var(--text-light)">Sin datos.</p>'; return; }
    const tallas = data.tallas, medidas = data.medidas;
    const MED_LABEL = {siembra:'Siembra',disponible:'Disponible',hold:'Hold',disphold:'Disp + Hold',sobrante:'Sobrante',faltante:'Faltante',ventas:'Ventas'};
    const totGen = {}; medidas.forEach(m=> totGen[m] = {});
    let h = '<table class="o14-matriz"><thead><tr><th class="dim" rowspan="2">'+esc(dimLabel)+'</th>';
    medidas.forEach(m=> h += '<th colspan="'+(tallas.length+1)+'">'+esc(MED_LABEL[m]||m.toUpperCase())+'</th>');
    h += '</tr><tr>';
    medidas.forEach(()=>{ tallas.forEach(t=> h+='<th>'+esc(t)+'</th>'); h+='<th class="blocktot">Tot</th>'; });
    h += '</tr></thead><tbody>';
    data.filas.forEach(fila=>{
      const key = fila.key;
      let label, attr='';
      if(clickable){ label = esc(key.negocio)+' <span style="color:var(--text-light);font-size:10px">('+esc(key.cia)+')</span>';
        attr = ' class="dim o14-clickable" onclick="o14SelectNegocio(\''+esc(key.referencia)+'\',\''+esc(key.color)+'\')"'; }
      else { label = '<strong>'+esc(key.bodega)+'</strong> '+esc(key.nombre)+' <span style="color:var(--text-light);font-size:10px">('+esc(key.grupo)+')</span>'; attr=' class="dim"'; }
      h += '<tr><td'+attr+'>'+label+'</td>';
      medidas.forEach(m=>{
        let tot=0;
        tallas.forEach(t=>{ const v=(fila.valores[m]||{})[t]||0; tot+=v; totGen[m][t]=(totGen[m][t]||0)+v;
          const cls = (m==='faltante'&&v>0)?' class="o14-cell-fal"':((m==='sobrante'&&v>0)?' class="o14-cell-sob"':'');
          h += '<td'+cls+'>'+(v?nf(v):'')+'</td>'; });
        h += '<td class="blocktot">'+nf(tot)+'</td>';
      });
      h += '</tr>';
    });
    // fila total general
    h += '<tr class="o14-total"><td class="dim">TOTAL</td>';
    medidas.forEach(m=>{ let tot=0; tallas.forEach(t=>{ const v=totGen[m][t]||0; tot+=v; h+='<td>'+(v?nf(v):'')+'</td>'; }); h+='<td class="blocktot">'+nf(tot)+'</td>'; });
    h += '</tr></tbody></table>';
    cont.innerHTML = h;
  }

  // Pinta el árbol Grupo→Almacén→Negocio. expandAll fuerza todo abierto (modo filtrado).
  function renderArbol(containerId, data, expandAll){
    const cont=document.getElementById(containerId);
    const grupos=data.grupos||[], tallas=data.tallas||[], medidas=data.medidas||[];
    if(!grupos.length){ cont.innerHTML='<p style="padding:16px;color:var(--text-light)">Sin datos.</p>'; return; }
    const MED_LABEL={siembra:'Siembra',disponible:'Disponible',hold:'Hold',disphold:'Disp + Hold',sobrante:'Sobrante',faltante:'Faltante',ventas:'Ventas'};
    if(expandAll) arbolState={ n:grupos.length, g:grupos.map(gr=>({exp:true, a:gr.almacenes.map(()=>true)})) };
    else if(!arbolState || arbolState.n!==grupos.length) arbolState={ n:grupos.length, g:grupos.map(gr=>({exp:true, a:gr.almacenes.map(()=>false)})) };
    let h='<table class="o14-matriz o14-arbol"><thead><tr><th class="dim" rowspan="2">Grupo / Almacén / Negocio</th>';
    medidas.forEach(m=> h+='<th colspan="'+(tallas.length+1)+'">'+esc(MED_LABEL[m]||m)+'</th>');
    h+='</tr><tr>'; medidas.forEach(()=>{ tallas.forEach(t=> h+='<th>'+esc(t)+'</th>'); h+='<th class="blocktot">Tot</th>'; }); h+='</tr></thead><tbody>';
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
  }

  window.o14ToggleGrupo=function(gi){ if(!arbolState)return; arbolState.g[gi].exp=!arbolState.g[gi].exp; renderArbol('o14-matriz-c', shownC, false); };
  window.o14ToggleAlm=function(gi,ai){ if(!arbolState)return; arbolState.g[gi].a[ai]=!arbolState.g[gi].a[ai]; renderArbol('o14-matriz-c', shownC, false); };

  function renderReco(data){
    const c=document.getElementById('o14-reco');
    if(!negocioSel.ref){ c.innerHTML='<p style="padding:16px;color:var(--text-light)">Elige un negocio en O14B (clic en una fila) para ver sus recomendaciones.</p>'; return; }
    if(!data.planes || !data.planes.length){ c.innerHTML='<p style="padding:16px;color:var(--text-light)">Sin faltantes para este negocio.</p>'; return; }
    c.innerHTML = '<p style="margin:0 0 12px;color:var(--text-light)">Negocio <strong>'+esc(negocioSel.ref)+'-'+esc(negocioSel.color)+'</strong></p>' +
      data.planes.map(p=>{
        const reub=(p.reubicaciones||[]).map(x=>'<tr><td>'+esc(x.origen)+'</td><td>'+esc(x.destino)+'</td><td>'+esc(x.talla)+'</td><td>'+nf(x.uds)+'</td></tr>').join('')||'<tr><td colspan="4" style="color:var(--text-light)">—</td></tr>';
        const cedi=(p.solicitudes_cedi||[]).map(x=>'<tr><td>'+esc(x.destino)+'</td><td>'+esc(x.talla)+'</td><td>'+nf(x.uds)+'</td></tr>').join('')||'<tr><td colspan="3" style="color:var(--text-light)">—</td></tr>';
        const prov=(p.solicitudes_proveedor||[]).map(x=>'<tr><td>'+esc(x.talla)+'</td><td>'+nf(x.uds)+'</td></tr>').join('')||'<tr><td colspan="2" style="color:var(--text-light)">—</td></tr>';
        const r=p.resumen||{};
        return '<div class="o14-reco-cia"><h4>Compañía '+esc(p.cia)+'</h4><div class="o14-reco-grid">'
          +'<div><h5>Reubicación entre tiendas</h5><table><tr><th>Origen</th><th>Destino</th><th>Talla</th><th>Uds</th></tr>'+reub+'</table></div>'
          +'<div><h5>Solicitar a CEDI</h5><table><tr><th>Destino</th><th>Talla</th><th>Uds</th></tr>'+cedi+'</table></div>'
          +'<div><h5>Solicitar a Proveedor</h5><table><tr><th>Talla</th><th>Uds</th></tr>'+prov+'</table></div></div>'
          +'<p class="o14-reco-resumen">Faltante <strong>'+nf(r.faltante_total)+'</strong> = reubicación '+nf(r.por_reubicacion)+' + CEDI '+nf(r.por_cedi)+' + proveedor '+nf(r.a_proveedor)+'</p>'
          +'<button class="g00-btn-refresh" disabled title="Próximamente">Generar solicitud</button></div>';
      }).join('');
  }

  function loadB(){ showLoading('Cargando O14B');
    fetch('api/informe_o14.php?'+buildParams('b'),{credentials:'same-origin'})
      .then(r=>r.json()).then(d=>{ if(!d.ok) throw 0; proveedorActual=d.proveedor||proveedorActual;
        renderKpis(d.kpis); renderMatriz('o14-matriz-b', d, 'Negocio', true); populateNegocios(d); lastData.b=d; tabState.b=true; })
      .catch(()=>Swal.fire('Error','No se pudo cargar O14B','error')).finally(hideLoading); }

  function loadC(){
    showLoading('Cargando O14C');
    fetch('api/informe_o14.php?'+buildParams('c'),{credentials:'same-origin'})
      .then(r=>r.json()).then(d=>{ if(!d.ok) throw 0; renderKpis(d.kpis||{});
        lastData.c=d; arbolState=null;
        if(negocioSel.ref){ shownC=filterArbol(d, negocioSel.ref, negocioSel.color); renderArbol('o14-matriz-c', shownC, true); }
        else { shownC=d; renderArbol('o14-matriz-c', d, false); }
        tabState.c=true; })
      .catch(()=>Swal.fire('Error','No se pudo cargar O14C','error')).finally(hideLoading); }

  function loadReco(){
    if(!negocioSel.ref){ renderReco({planes:[]}); return; }
    showLoading('Calculando recomendaciones');
    fetch('api/informe_o14.php?'+buildParams('reco'),{credentials:'same-origin'})
      .then(r=>r.json()).then(d=>{ if(!d.ok) throw 0; renderReco(d); tabState.reco=true; })
      .catch(()=>Swal.fire('Error','No se pudo calcular','error')).finally(hideLoading); }

  function loadCurrentTab(){ if(currentTab==='c') loadC(); else if(currentTab==='reco') loadReco(); else loadB(); }

  // Llena el selector "Negocio" de la barra de filtros con los negocios de O14B (distinct ref+color).
  function populateNegocios(d){
    const sel = document.getElementById('o14-negocio'); if(!sel) return;
    const prev = sel.value, seen = {}, opts = [];
    (d.filas||[]).forEach(f=>{ const k=f.key, key=k.referencia+'|'+k.color;
      if(seen[key]) return; seen[key]=1; opts.push({v:key, t:(k.negocio||(k.referencia+'-'+k.color))}); });
    opts.sort((a,b)=>a.t.localeCompare(b.t));
    sel.innerHTML = '<option value="">— Todos —</option>' + opts.map(o=>'<option value="'+esc(o.v)+'">'+esc(o.t)+'</option>').join('');
    if(prev && seen[prev]) sel.value = prev;
    else if(negocioSel.ref && seen[negocioSel.ref+'|'+negocioSel.color]) sel.value = negocioSel.ref+'|'+negocioSel.color;
  }

  // Poda el árbol a un solo negocio (ref+color), descartando almacenes/grupos vacíos.
  function filterArbol(data, ref, color){
    const grupos=[];
    (data.grupos||[]).forEach(g=>{
      const alm=[];
      g.almacenes.forEach(a=>{ const negs=a.negocios.filter(n=>n.referencia===ref && n.color===color);
        if(negs.length) alm.push(Object.assign({}, a, {negocios:negs})); });
      if(alm.length) grupos.push(Object.assign({}, g, {almacenes:alm}));
    });
    return Object.assign({}, data, {grupos});
  }

  window.o14SelectNegocio = function(ref,color){
    negocioSel = { ref:ref, color:color };
    document.getElementById('o14-c-sel').textContent = 'Negocio: '+ref+'-'+color;
    const selN = document.getElementById('o14-negocio'); if(selN) selN.value = ref+'|'+color;
    tabState.reco=false; // reco depende del negocio elegido
    const cTab = document.querySelector('#page-informes-o14 .o14-tabs .tab:nth-child(1)');
    o14ShowTab('c', cTab);
    if(tabState.c && lastData.c){ shownC=filterArbol(lastData.c, ref, color); renderArbol('o14-matriz-c', shownC, true); }
  };

  // Selector de la barra: elegir un negocio filtra el árbol de O14C; "— Todos —" muestra todo.
  window.o14PickNegocio = function(value){
    const cTab = document.querySelector('#page-informes-o14 .o14-tabs .tab:nth-child(1)');
    if(!value){ negocioSel = { ref:'', color:'' }; document.getElementById('o14-c-sel').textContent='';
      o14ShowTab('c', cTab);
      if(tabState.c && lastData.c){ shownC=lastData.c; arbolState=null; renderArbol('o14-matriz-c', lastData.c, false); }
      return; }
    const i = value.indexOf('|'); o14SelectNegocio(value.slice(0,i), value.slice(i+1));
  };

  window.o14ShowTab = function(name, el){
    document.querySelectorAll('#page-informes-o14 .o14-tabs .tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('#page-informes-o14 .o14-tab-panel').forEach(p=>p.classList.remove('active'));
    if(el) el.classList.add('active');
    document.getElementById('o14-panel-'+name).classList.add('active');
    currentTab = name;
    if(!tabState[name]) loadCurrentTab();
  };

  window.o14Load = function(){ tabState.b=tabState.c=tabState.reco=false; loadCurrentTab(); };

  window.o14OnEnter = function(){
    // Topbar estilo G00: título centrado "SIEMBRA / STOCK", botón Actualizar.
    document.getElementById('pageTitle').textContent = 'SIEMBRA / STOCK';
    document.getElementById('topbar').classList.add('topbar--o14');
    document.getElementById('pageSubtitle').style.display = 'none';
    // Columna izquierda del topbar: control de fecha que afecta SOLO a ventas (default histórico desde 2025-01-01).
    const td = document.getElementById('topbarDates'); td.style.display = '';
    if (!document.getElementById('o14-vdesde')) {
      const hoy = new Date().toISOString().slice(0,10);
      td.innerHTML =
        '<div class="o14-vfilter"><span class="o14-vfilter-lbl">Ventas</span>'
        + '<label>Desde<input type="date" id="o14-vdesde" value="2025-01-01"></label>'
        + '<label>Hasta<input type="date" id="o14-vhasta" value="'+hoy+'"></label></div>';
    }
    const rb = document.getElementById('topbarO14Refresh'); if(rb) rb.style.display = '';
    if (!filtrosInit) { initFiltros(); filtrosInit = true; }
    if(!tabState[currentTab]) loadCurrentTab();
  };

  // DOM→AOA expandiendo colspan/rowspan; convierte enteros es-CO ("1.234"→1234) a números reales.
  function tableToAOA(tbl){
    const aoa = [], carry = {}; // carry[col] = {text, rem} para rowspans pendientes
    [...tbl.rows].forEach(tr => {
      const row = []; let c = 0;
      const placeCarry = () => { while(carry[c] && carry[c].rem > 0){ row[c] = carry[c].text; carry[c].rem--; c++; } };
      [...tr.cells].forEach(cell => {
        placeCarry();
        const cs = cell.colSpan||1, rs = cell.rowSpan||1, txt = cell.innerText.trim();
        const v = /^-?\d{1,3}(\.\d{3})*$/.test(txt) ? parseInt(txt.replace(/\./g,''),10) : txt;
        for(let k=0;k<cs;k++){ const cv = k===0 ? v : ''; row[c] = cv; if(rs>1) carry[c] = {text:cv, rem:rs-1}; c++; }
      });
      placeCarry();
      aoa.push(row);
    });
    return aoa;
  }
  // Exporta a .xlsx real la tabla de la pestaña actual (incluye todas las filas, incluso grupos colapsados).
  window.o14Export = function(){
    const tbl = document.querySelector('#o14-panel-'+currentTab+' table');
    if(!tbl){ Swal.fire('Exportar','Nada que exportar en esta pestaña.','info'); return; }
    if(typeof XLSX === 'undefined'){ Swal.fire('Exportar','No se cargó el componente de Excel.','error'); return; }
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(tableToAOA(tbl)), 'O14');
    XLSX.writeFile(wb, 'O14_'+currentTab+'_'+new Date().toISOString().slice(0,10)+'.xlsx');
  };
})();
</script>
