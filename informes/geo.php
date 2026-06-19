<?php /* Informe Georeferenciación (incluido desde dashboard.php) */ ?>
<div class="page" id="page-georreferenciacion">
  <div class="g00-filters geo-filters">
    <div class="g00-filter-row">
      <div class="filter-group geo-tienda-group"><label>Tienda</label><select id="geo-f-tienda" multiple></select></div>
      <div class="o14-apply">
        <button class="g00-btn-refresh" onclick="geoLoad()"><i class="fa-solid fa-rotate"></i> Aplicar</button>
      </div>
    </div>
    <div class="g00-filter-row">
      <div class="filter-group"><label>Marca</label><select id="geo-f-marca" multiple></select></div>
      <div class="filter-group"><label>Tipo</label><select id="geo-f-tipo" multiple></select></div>
      <div class="filter-group"><label>Categoría</label><select id="geo-f-categoria" multiple></select></div>
      <div class="filter-group"><label>Subcategoría</label><select id="geo-f-subcategoria" multiple></select></div>
      <div class="filter-group"><label>Género</label><select id="geo-f-genero" multiple></select></div>
      <div class="filter-group"><label>Público</label><select id="geo-f-publico" multiple></select></div>
      <div class="filter-group"><label>Negocio</label><select id="geo-f-negocio" multiple></select></div>
    </div>
    <div class="g00-filter-row" style="flex-wrap:nowrap;">
      <div class="filter-group"><label>Referencia</label><select id="geo-f-referencia" multiple></select></div>
    </div>
  </div>

  <div class="geo-wrap">
    <div id="geo-map"></div>
    <div id="geo-count" class="geo-box"><span class="n" id="geo-count-n">0</span># Tiendas</div>
    <div id="geo-list" class="geo-box geo-list"><h4>Descripción Tiendas</h4><div id="geo-list-body"></div></div>
  </div>
</div>

<style>
  #page-georreferenciacion .geo-tienda-group { min-width: 480px; flex: 2; }
  #page-georreferenciacion .geo-tienda-group .ts-control { min-width: 480px; }
  #page-georreferenciacion .geo-wrap { position: relative; }
  #page-georreferenciacion #geo-map { width: 100%; height: calc(100vh - 230px); min-height: 460px; border-radius: 8px; }
  #page-georreferenciacion .geo-box { position: absolute; z-index: 1000; background: rgba(255,255,255,.93);
    border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.25); font-size: 12px; color: var(--text); }
  #page-georreferenciacion #geo-count { left: 14px; bottom: 22px; padding: 8px 16px; text-align: center; font-weight: 700; line-height: 1.1; }
  #page-georreferenciacion #geo-count .n { display: block; font-size: 24px; color: var(--accent); }
  #page-georreferenciacion .geo-list { right: 14px; bottom: 22px; width: 240px; max-height: 46%; overflow: auto; padding: 8px 12px; }
  #page-georreferenciacion .geo-list h4 { margin: 0 0 6px; font-size: 12px; text-align: center; color: var(--primary); }
  #page-georreferenciacion .geo-list .row { padding: 1px 0; white-space: nowrap; }
  .leaflet-tooltip.geo-tooltip { background: #fff; color: #2d2b4e; border: 1px solid #2d2b4e; border-radius: 6px; box-shadow: 0 2px 10px rgba(0,0,0,.25); font-size: 12px; }
  .leaflet-tooltip.geo-tooltip b { color: #2d2b4e; }
  .leaflet-tooltip.geo-tooltip::before { border-top-color: #2d2b4e; }
</style>

<script>
(function(){
  'use strict';
  let filtrosInit = false, comboCatalogo = [], map = null, markersLayer = null;
  const DIMS = ['marca','tipo','categoria','subcategoria','genero','publico','negocio','referencia'];
  const tsRef = {};
  const nf  = n => Number(n||0).toLocaleString('es-CO');
  const fmtMoney = v => '$ ' + Number(v||0).toLocaleString('es-CO');
  const esc = s => (s==null?'':String(s)).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const val = id => (document.getElementById(id)?.value || '');
  function getMultiVals(id){ const el=document.getElementById(id); if(!el) return [];
    return Array.from(el.selectedOptions||[]).map(o=>o.value).filter(Boolean); }

  function defDesde(){ const d=new Date(); return (d.getFullYear()-1)+'-01'; }
  function defHasta(){ const d=new Date(); return d.toISOString().slice(0,7); }

  function buildParams(){
    const p = new URLSearchParams({ tab:'data', desde: val('geo-vdesde')||defDesde(), hasta: val('geo-vhasta')||defHasta() });
    ['tienda',...DIMS].forEach(k=>{ getMultiVals('geo-f-'+k).forEach(v=>p.append(k+'[]', v)); });
    return p.toString();
  }

  function poblarSelect(id, valores, labelFn){
    const el=document.getElementById(id); if(!el) return;
    const uniq=[...new Set(valores.filter(v=>v!==''&&v!=null))].sort((a,b)=>String(a).localeCompare(String(b)));
    el.innerHTML = uniq.map(v=>'<option value="'+String(v).replace(/"/g,'&quot;')+'">'+esc(labelFn?labelFn(v):v)+'</option>').join('');
    if (window.TomSelect){ if(tsRef[id]) tsRef[id].destroy(); tsRef[id]=new TomSelect(el,{plugins:['remove_button'],maxOptions:null,placeholder:'Todas'}); }
  }

  function initFiltros(){
    fetch('api/informe_geo.php?tab=filtros',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      comboCatalogo = d.combos||[];
      DIMS.forEach(k=> poblarSelect('geo-f-'+k, comboCatalogo.map(c=>c[k])));
      const tEl=document.getElementById('geo-f-tienda'); const seen={}; const opts=[];
      comboCatalogo.forEach(c=>{ const nom=c.tienda||''; if(!nom||seen[nom])return; seen[nom]=1; opts.push({nom, cod:c.tienda_cod||''}); });
      opts.sort((a,b)=>a.cod.localeCompare(b.cod));
      tEl.innerHTML = opts.map(o=>'<option value="'+esc(o.nom)+'">'+esc(o.cod)+' - '+esc(o.nom)+'</option>').join('');
      if (window.TomSelect){ if(tsRef['geo-f-tienda']) tsRef['geo-f-tienda'].destroy(); tsRef['geo-f-tienda']=new TomSelect(tEl,{plugins:['remove_button'],maxOptions:null,placeholder:'Todas'}); }
    });
  }

  // Color por grupo: paleta explícita para los conocidos + fallback determinístico por hash.
  const GRUPO_COLOR = {
    'AKA':'#e6194B','ZEUS':'#4363d8','SPRING STEP':'#f58231','BRAHMA':'#3cb44b',
    'BRAHMA CONCEPT':'#3cb44b','BODEGA':'#911eb4','OUTLET':'#42d4f4','OX':'#bfef45','SOLIMAR':'#f032e6'
  };
  const PAL = ['#e6194B','#3cb44b','#4363d8','#f58231','#911eb4','#42d4f4','#f032e6','#bfef45','#fabed4','#469990','#9A6324','#800000','#808000','#000075'];
  function colorFor(g){ g=(g||'').toUpperCase().trim(); if(GRUPO_COLOR[g]) return GRUPO_COLOR[g];
    let h=0; for(let i=0;i<g.length;i++) h=(h*31+g.charCodeAt(i))>>>0; return PAL[h%PAL.length]; }

  function ensureMap(){
    if (map) return;
    map = L.map('geo-map', { zoomControl:true, attributionControl:true }).setView([4.6,-74.1], 6);
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
      { maxZoom:19, attribution:'Tiles &copy; Esri' }).addTo(map);
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
      { maxZoom:19 }).addTo(map);
    markersLayer = L.layerGroup().addTo(map);
  }

  function renderMapa(d){
    ensureMap();
    markersLayer.clearLayers();
    const tiendas = d.tiendas||[];
    tiendas.forEach(t=>{
      if (t.lat==null || t.lng==null) return;
      const m = L.circleMarker([t.lat,t.lng], { radius:7, fillColor:colorFor(t.grupo), color:'#fff', weight:1, fillOpacity:0.92 });
      const html = '<b>'+esc(t.cod)+' - '+esc(t.nombre)+'</b><br>Ventas: '+fmtMoney(t.valor)+'<br>Unidades: '+nf(t.unidades);
      m.bindTooltip(html, { sticky:true, direction:'top', className:'geo-tooltip', opacity:1 });
      markersLayer.addLayer(m);
    });
    document.getElementById('geo-count-n').textContent = nf(d.total_tiendas);
    const body = (tiendas.slice().sort((a,b)=>String(a.cod).localeCompare(String(b.cod)))
      .map(t=>'<div class="row">'+esc(t.cod)+' - '+esc(t.nombre)+'</div>').join('')) || '<div class="row">Sin tiendas.</div>';
    document.getElementById('geo-list-body').innerHTML = body;
  }

  function showLoading(){ if(!window.Swal) return; const ter=(window.PROVEEDOR_ACTUAL||'');
    Swal.fire({title:'Cargando...',
      html:'<div style="font-size:15px;font-weight:600;color:#4A4782;margin-top:4px">Georreferenciación</div>'+(ter?'<div style="font-size:13px;color:#6b7280;margin-top:2px">'+esc(ter)+'</div>':''),
      allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()}); }
  function hideLoading(){ if(window.Swal && Swal.isVisible()) Swal.close(); }

  window.geoLoad = function(){
    showLoading();
    fetch('api/informe_geo.php?'+buildParams(),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(!d.ok){ if(window.Swal) Swal.fire('Error','No se pudo cargar el mapa.','error'); return; }
      window.__geolast=d; if(d.proveedor) setTitle(d.proveedor); renderMapa(d);
    }).catch(()=>{ if(window.Swal) Swal.fire('Error','Error de red.','error'); }).finally(hideLoading);
  };

  function setTitle(prov){ document.getElementById('pageTitle').textContent = 'GEOREFERENCIACIÓN' + (prov ? ' - ' + prov : ''); }

  window.geoOnEnter = function(){
    setTitle(window.PROVEEDOR_ACTUAL || '');
    document.getElementById('topbar').classList.add('topbar--o14');
    document.getElementById('pageSubtitle').style.display = 'none';
    const td = document.getElementById('topbarDates'); td.style.display = '';
    if (!document.getElementById('geo-vdesde')) {
      td.innerHTML =
        '<div class="o14-vfilter"><span class="o14-vfilter-lbl">Meses</span>'
        + '<label>Desde<input type="month" id="geo-vdesde" value="'+defDesde()+'"></label>'
        + '<label>Hasta<input type="month" id="geo-vhasta" value="'+defHasta()+'" max="'+defHasta()+'"></label></div>';
    }
    const rb = document.getElementById('topbarGeoRefresh'); if(rb) rb.style.display = '';
    if (!filtrosInit) { initFiltros(); filtrosInit = true; }
    ensureMap();
    setTimeout(()=>{ if(map) map.invalidateSize(); }, 0);   // el contenedor ya es visible al entrar
    if (!window.__geolast) geoLoad();
  };
})();
</script>
