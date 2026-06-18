<?php /* Informe Evolución Histórica (incluido desde dashboard.php) */ ?>
<div class="page" id="page-evolucion-historica">
  <div class="g00-filters evol-filters">
    <div class="g00-filter-row">
      <div class="filter-group"><label>Grupo</label><select id="evol-f-grupo" multiple></select></div>
      <div class="filter-group evol-tienda-group"><label>Tienda</label><select id="evol-f-tienda" multiple></select></div>
      <div class="o14-apply">
        <button class="g00-btn-refresh" onclick="evolLoad()"><i class="fa-solid fa-rotate"></i> Aplicar</button>
      </div>
    </div>
    <div class="g00-filter-row">
      <div class="filter-group"><label>Marca</label><select id="evol-f-marca" multiple></select></div>
      <div class="filter-group"><label>Tipo</label><select id="evol-f-tipo" multiple></select></div>
      <div class="filter-group"><label>Categoría</label><select id="evol-f-categoria" multiple></select></div>
      <div class="filter-group"><label>Subcategoría</label><select id="evol-f-subcategoria" multiple></select></div>
      <div class="filter-group"><label>Género</label><select id="evol-f-genero" multiple></select></div>
      <div class="filter-group"><label>Público</label><select id="evol-f-publico" multiple></select></div>
      <div class="filter-group"><label>Negocio</label><select id="evol-f-negocio" multiple></select></div>
    </div>
    <div class="g00-filter-row" style="flex-wrap:nowrap;">
      <div class="filter-group"><label>Referencia</label><select id="evol-f-referencia" multiple></select></div>
    </div>
  </div>

  <div class="tab-bar">
    <button class="g00-btn-export o14-export-btn" onclick="evolExport()">⤓ Excel</button>
  </div>
  <div id="evol-tabla" class="o14-matriz-wrap"></div>
</div>

<style>
  #page-evolucion-historica .tab-bar { display: flex; justify-content: flex-end; }
  #page-evolucion-historica .evol-tienda-group { min-width: 320px; flex: 2; }
  #page-evolucion-historica .evol-tienda-group .ts-control { min-width: 320px; }
  #page-evolucion-historica table.evol-tabla { border-collapse: collapse; font-size: 11px; }
  #page-evolucion-historica table.evol-tabla th, #page-evolucion-historica table.evol-tabla td {
    border: 1px solid var(--border); padding: 2px 6px; text-align: right; white-space: nowrap; }
  #page-evolucion-historica table.evol-tabla thead th { background: #faf9ff; position: sticky; top: 0; z-index: 2; }
  /* El wrap es el contenedor de scroll (alto limitado) => el thead sticky queda fijo al hacer scroll vertical */
  #page-evolucion-historica .o14-matriz-wrap { max-height: calc(100vh - 320px); min-height: 280px; overflow: auto; }
  /* Inmovilizar las 2 primeras columnas: Negocio + Conceptos */
  #page-evolucion-historica table.evol-tabla td.neg, #page-evolucion-historica table.evol-tabla th.neg {
    text-align: left; position: sticky; left: 0; box-sizing: border-box; width: 132px; min-width: 132px; max-width: 132px;
    white-space: normal; overflow-wrap: anywhere; line-height: 1.2; background: #2e7d6b; color: #fff; font-weight: 600; }
  #page-evolucion-historica table.evol-tabla td.med, #page-evolucion-historica table.evol-tabla th.med {
    text-align: left; position: sticky; left: 132px; box-sizing: border-box; width: 165px; min-width: 165px; max-width: 165px;
    overflow: hidden; text-overflow: ellipsis; background: #f3f1ff; }
  #page-evolucion-historica table.evol-tabla th.med { background: #faf9ff; }
  /* capas: cuerpo fijo por debajo de la cabecera; esquinas por encima de todo */
  #page-evolucion-historica table.evol-tabla tbody td.neg, #page-evolucion-historica table.evol-tabla tbody td.med { z-index: 1; }
  #page-evolucion-historica table.evol-tabla thead th.neg, #page-evolucion-historica table.evol-tabla thead th.med { z-index: 4; }
  #page-evolucion-historica table.evol-tabla td.tot { font-weight: 700; background: #efeefb; }
  #page-evolucion-historica table.evol-tabla tr.m-ventas td.val { background: #c9f7d2; }
  #page-evolucion-historica table.evol-tabla tr.m-stock  td.val { background: #cfe0f5; }
  /* Índice Ventas Detal Mes: toda la fila con el amarillo de los datos */
  #page-evolucion-historica table.evol-tabla tr.m-indice td.val,
  #page-evolucion-historica table.evol-tabla tr.m-indice td.med,
  #page-evolucion-historica table.evol-tabla tr.m-indice td.tot { background: #faecc8; }
  /* Datos negativos en rojo */
  #page-evolucion-historica table.evol-tabla td.neg-val { color: #d4001a; }
  /* Bloque TOTAL general (al final): negrita + etiqueta en verde oscuro */
  #page-evolucion-historica table.evol-tabla tr.evol-tot td { font-weight: 700; }
  #page-evolucion-historica table.evol-tabla td.neg.evol-tot-neg { background: #1f5e50; }
  #evol-img-pop { position: fixed; display: none; z-index: 9999; pointer-events: none; background: #fff;
    border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 6px 20px rgba(45,43,78,.25); padding: 4px; }
  #evol-img-pop img { max-width: 260px; max-height: 320px; display: block; border-radius: 4px; }
</style>

<script>
(function(){
  'use strict';
  let filtrosInit = false, comboCatalogo = [];
  const DIMS = ['marca','tipo','categoria','subcategoria','genero','publico','negocio','referencia'];
  const tsRef = {};
  const nf  = n => (n==null||n===''?'':Number(n).toLocaleString('es-CO'));
  const nf2 = n => (n==null||n===''?'':Number(n).toLocaleString('es-CO',{minimumFractionDigits:2,maximumFractionDigits:2}));
  const esc = s => (s==null?'':String(s)).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const val = id => (document.getElementById(id)?.value || '');
  function getMultiVals(id){ const el=document.getElementById(id); if(!el) return [];
    return Array.from(el.selectedOptions||[]).map(o=>o.value).filter(Boolean); }

  // Mes Año-Mes por defecto: enero del año pasado .. mes actual.
  function defDesde(){ const d=new Date(); return (d.getFullYear()-1)+'-01'; }
  function defHasta(){ const d=new Date(); return d.toISOString().slice(0,7); }

  function buildParams(){
    const p = new URLSearchParams({ tab:'data', desde: val('evol-vdesde')||defDesde(), hasta: val('evol-vhasta')||defHasta() });
    ['grupo','tienda',...DIMS].forEach(k=>{ getMultiVals('evol-f-'+k).forEach(v=>p.append(k+'[]', v)); });
    return p.toString();
  }

  function poblarSelect(id, valores, labelFn){
    const el=document.getElementById(id); if(!el) return;
    const uniq=[...new Set(valores.filter(v=>v!==''&&v!=null))].sort((a,b)=>String(a).localeCompare(String(b)));
    el.innerHTML = uniq.map(v=>'<option value="'+String(v).replace(/"/g,'&quot;')+'">'+esc(labelFn?labelFn(v):v)+'</option>').join('');
    if (window.TomSelect){ if(tsRef[id]) tsRef[id].destroy(); tsRef[id]=new TomSelect(el,{plugins:['remove_button'],maxOptions:null,placeholder:'Todas'}); }
  }

  function initFiltros(){
    fetch('api/informe_evol.php?tab=filtros',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      comboCatalogo = d.combos||[];
      DIMS.forEach(k=> poblarSelect('evol-f-'+k, comboCatalogo.map(c=>c[k])));
      poblarSelect('evol-f-grupo', comboCatalogo.map(c=>c.grupo));
      const tEl=document.getElementById('evol-f-tienda'); const seen={}; const opts=[];
      comboCatalogo.forEach(c=>{ const nom=c.tienda||''; if(!nom||seen[nom])return; seen[nom]=1; opts.push({nom, cod:c.tienda_cod||''}); });
      opts.sort((a,b)=>a.cod.localeCompare(b.cod));
      tEl.innerHTML = opts.map(o=>'<option value="'+esc(o.nom)+'">'+esc(o.cod)+' - '+esc(o.nom)+'</option>').join('');
      if (window.TomSelect){ if(tsRef['evol-f-tienda']) tsRef['evol-f-tienda'].destroy(); tsRef['evol-f-tienda']=new TomSelect(tEl,{plugins:['remove_button'],maxOptions:null,placeholder:'Todas'}); }
    });
  }

  // Las 6 medidas (orden de la imagen). 'sum' indica si la columna Total acumula.
  const MEDIDAS = [
    {k:'compras',  t:'Ingreso',           f:nf,  cls:'',        sum:true },
    {k:'ventas',   t:'Total Ventas',      f:nf,  cls:'m-ventas', sum:true },
    {k:'stock',    t:'Stock',             f:nf,  cls:'m-stock',  sum:false},
    {k:'tiendas',  t:'Tiendas con Inv',   f:nf,  cls:'',         sum:false},
    {k:'mesesInv', t:'Meses de Inv',      f:nf,  cls:'',         sum:false},
    {k:'indice',   t:'Índice Ventas Detal Mes', f:nf2, cls:'m-indice', sum:false},
  ];
  const fmtMesHdr = m => { const [a,mm]=m.split('-'); const ms=['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']; return a+'-'+ms[(+mm)-1]; };

  function renderMatriz(d){
    const cont=document.getElementById('evol-tabla');
    const meses=d.meses||[], negs=d.negocios||[];
    if(!negs.length){ cont.innerHTML='<p style="padding:16px;color:var(--text-light)">Sin datos.</p>'; return; }
    // Un bloque de 6 medidas (negocio o fila TOTAL general). foto=null => sin hover de foto.
    const bloque=(label,valores,totales,foto,esTotal)=>{
      let b='';
      MEDIDAS.forEach((med,i)=>{
        b+='<tr class="'+med.cls+(esTotal?' evol-tot':'')+'"'+(i===0&&foto!=null?' data-negimg="'+esc(foto)+'"':'')+'>';
        if(i===0) b+='<td class="neg'+(esTotal?' evol-tot-neg':'')+'" rowspan="'+MEDIDAS.length+'">'+esc(label)+'</td>';
        b+='<td class="med">'+med.t+'</td>';
        meses.forEach(m=>{ const v=(valores[med.k]||{})[m];
          b+='<td class="val'+(typeof v==='number'&&v<0?' neg-val':'')+'">'+med.f(v)+'</td>'; });
        const tot = med.sum ? (totales[med.k]) : '';
        b+='<td class="tot'+(med.sum&&typeof tot==='number'&&tot<0?' neg-val':'')+'">'+(med.sum?med.f(tot):'')+'</td>';
        b+='</tr>';
      });
      return b;
    };
    let h='<table class="evol-tabla" id="evol-tbl"><thead><tr>';
    h+='<th class="neg">Negocio</th><th class="med">CONCEPTOS</th>';
    meses.forEach(m=> h+='<th>'+fmtMesHdr(m)+'</th>');
    h+='<th>Total</th></tr></thead><tbody>';
    negs.forEach(n=> h+=bloque(n.negocio, n.valores, n.totales, n.foto, false));
    if(d.totalGeneral) h+=bloque('TOTAL', d.totalGeneral.valores, d.totalGeneral.totales, null, true);
    h+='</tbody></table>'; cont.innerHTML=h;
  }

  function showLoading(){ if(!window.Swal) return; const ter=(window.PROVEEDOR_ACTUAL||'');
    Swal.fire({title:'Cargando...',
      html:'<div style="font-size:15px;font-weight:600;color:#4A4782;margin-top:4px">Evolución Histórica</div>'+(ter?'<div style="font-size:13px;color:#6b7280;margin-top:2px">'+esc(ter)+'</div>':''),
      allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()}); }
  function hideLoading(){ if(window.Swal && Swal.isVisible()) Swal.close(); }

  window.evolLoad = function(){
    const cont=document.getElementById('evol-tabla'); showLoading();
    fetch('api/informe_evol.php?'+buildParams(),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(!d.ok){ cont.innerHTML='<p style="padding:16px;color:var(--accent)">Error al cargar.</p>'; return; }
      window.__evollast=d; if(d.proveedor) setTitle(d.proveedor); renderMatriz(d);
    }).catch(()=>{ cont.innerHTML='<p style="padding:16px;color:var(--accent)">Error de red.</p>'; }).finally(hideLoading);
  };

  // Excel: DOM->AOA expandiendo rowspan, enteros es-CO -> número real (mismo criterio que O14/O45).
  window.evolExport = function(){
    const tbl=document.getElementById('evol-tbl');
    if(!tbl || typeof XLSX==='undefined'){ if(window.Swal) Swal.fire('Exportar','Carga el informe primero.','info'); return; }
    const rows=[...tbl.querySelectorAll('tr')]; const aoa=[]; const carry={};
    rows.forEach((tr,ri)=>{
      const out=[]; let ci=0;
      Object.keys(carry).forEach(c=>{ if(carry[c].left>0){ out[c]=carry[c].text; carry[c].left--; } });
      [...tr.children].forEach(td=>{
        while(out[ci]!==undefined) ci++;
        const txt=td.textContent.trim();
        const num=Number(txt.replace(/\./g,'').replace(',','.'));
        const v=(txt!=='' && !isNaN(num) && /[0-9]/.test(txt)) ? num : txt;
        out[ci]=v;
        const rs=parseInt(td.getAttribute('rowspan')||'1',10);
        if(rs>1) carry[ci]={text:txt,left:rs-1};
        ci++;
      });
      aoa.push(out.map(x=>x===undefined?'':x));
    });
    const wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(aoa), 'Evolucion');
    XLSX.writeFile(wb,'Evolucion_Historica.xlsx');
  };

  function setTitle(prov){ document.getElementById('pageTitle').textContent = 'EVOLUCIÓN HISTÓRICA' + (prov ? ' - ' + prov : ''); }

  window.evolOnEnter = function(){
    setTitle(window.PROVEEDOR_ACTUAL || '');
    document.getElementById('topbar').classList.add('topbar--o14');
    document.getElementById('pageSubtitle').style.display = 'none';
    const td = document.getElementById('topbarDates'); td.style.display = '';
    if (!document.getElementById('evol-vdesde')) {
      td.innerHTML =
        '<div class="o14-vfilter"><span class="o14-vfilter-lbl">Meses</span>'
        + '<label>Desde<input type="month" id="evol-vdesde" value="'+defDesde()+'"></label>'
        + '<label>Hasta<input type="month" id="evol-vhasta" value="'+defHasta()+'" max="'+defHasta()+'"></label></div>';
    }
    const rb = document.getElementById('topbarEvolRefresh'); if(rb) rb.style.display = '';
    if (!filtrosInit) { initFiltros(); filtrosInit = true; }
    if (!window.__evollast) evolLoad();
  };

  // Foto del zapato al pasar el mouse sobre la columna Negocio (col 0). Igual que O45.
  (function initImgHover(){
    const FOTO_BASE='http://bi.stanton.com.co:81/fotosPBI/';
    const panel=document.getElementById('evol-tabla'); if(!panel) return;
    let pop=null,img=null,triedPng=false,curLabel='';
    function ensurePop(){ if(pop)return; pop=document.createElement('div'); pop.id='evol-img-pop'; img=document.createElement('img'); img.alt='';
      img.onerror=function(){ if(!triedPng){ triedPng=true; img.src=FOTO_BASE+encodeURIComponent(curLabel)+'.png'; } else hide(); };
      pop.appendChild(img); document.body.appendChild(pop); }
    function hide(){ if(pop) pop.style.display='none'; }
    function position(e){ if(!pop)return; const off=16,w=276,h=336; let x=e.clientX+off,y=e.clientY+off;
      if(x+w>window.innerWidth)x=e.clientX-off-w; if(y+h>window.innerHeight)y=e.clientY-off-h;
      pop.style.left=Math.max(4,x)+'px'; pop.style.top=Math.max(4,y)+'px'; }
    panel.addEventListener('mouseover',function(e){ const td=e.target.closest('td.neg'); if(!td)return;
      const tr=td.closest('tr[data-negimg]'); if(!tr)return; curLabel=tr.getAttribute('data-negimg'); triedPng=false; ensurePop();
      img.src=FOTO_BASE+encodeURIComponent(curLabel)+'.jpg'; pop.style.display='block'; position(e); });
    panel.addEventListener('mousemove',function(e){ if(pop&&pop.style.display==='block')position(e); });
    panel.addEventListener('mouseout',function(e){ const td=e.target.closest('td.neg');
      if(td&&(!e.relatedTarget||!td.contains(e.relatedTarget)))hide(); });
  })();
})();
</script>
