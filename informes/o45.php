<?php /* Informe O45 — Índice de Ventas (incluido desde dashboard.php) */ ?>
<div class="page" id="page-informes-o45">
  <!-- Filtros -->
  <div class="g00-filters o45-filters">
    <div class="g00-filter-row">
      <div class="filter-group"><label>Grupo</label><select id="o45-f-grupo" multiple></select></div>
      <div class="filter-group o45-tienda-group"><label>Tienda</label><select id="o45-f-tienda" multiple></select></div>
      <div class="o14-apply">
        <button class="g00-btn-refresh" onclick="o45Load()"><i class="fa-solid fa-rotate"></i> Aplicar</button>
      </div>
    </div>
    <div class="g00-filter-row">
      <div class="filter-group"><label>Marca</label><select id="o45-f-marca" multiple></select></div>
      <div class="filter-group"><label>Tipo</label><select id="o45-f-tipo" multiple></select></div>
      <div class="filter-group"><label>Categoría</label><select id="o45-f-categoria" multiple></select></div>
      <div class="filter-group"><label>Subcategoría</label><select id="o45-f-subcategoria" multiple></select></div>
      <div class="filter-group"><label>Género</label><select id="o45-f-genero" multiple></select></div>
      <div class="filter-group"><label>Público</label><select id="o45-f-publico" multiple></select></div>
      <div class="filter-group"><label>Negocio</label><select id="o45-f-negocio" multiple></select></div>
      <div class="filter-group"><label>Referencia</label><select id="o45-f-referencia" multiple></select></div>
    </div>
  </div>

  <div class="tab-bar">
    <button class="g00-btn-export o14-export-btn" onclick="o45Export()">⤓ Excel</button>
  </div>
  <div id="o45-tabla" class="o14-matriz-wrap"></div>
</div>

<style>
  #page-informes-o45 .o45-tienda-group { min-width: 320px; flex: 2; }   /* Tienda más ancho: COD - NOMBRE completo */
  #page-informes-o45 .o45-tienda-group .ts-control { min-width: 320px; }
  #page-informes-o45 table.o45-tabla { width:100%; border-collapse:collapse; font-size:12px; }
  #page-informes-o45 table.o45-tabla th, #page-informes-o45 table.o45-tabla td { border:1px solid var(--border); padding:4px 8px; text-align:right; white-space:nowrap; }
  #page-informes-o45 table.o45-tabla th { background:#faf9ff; position:sticky; top:0; }
  #page-informes-o45 table.o45-tabla td.dim, #page-informes-o45 table.o45-tabla th.dim { text-align:left; }
  #page-informes-o45 table.o45-tabla tr.o45-total td { font-weight:700; background:#f3f1ff; }
</style>

<script>
  (function(){
    'use strict';
    let filtrosInit = false, comboCatalogo = [];
    const DIMS = ['marca','tipo','categoria','subcategoria','genero','publico','negocio','referencia'];
    const tsRef = {};
    const nf = n => (n==null?'':Number(n).toLocaleString('es-CO'));
    const nf2 = n => (n==null?'—':Number(n).toLocaleString('es-CO',{minimumFractionDigits:2,maximumFractionDigits:2}));
    const esc = s => (s==null?'':String(s)).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const val = id => (document.getElementById(id)?.value || '');
    function getMultiVals(id){ const el=document.getElementById(id); if(!el) return [];
      return Array.from(el.selectedOptions||[]).map(o=>o.value).filter(Boolean); }

    function buildParams(){
      const p = new URLSearchParams({ tab:'data', desde: val('o45-vdesde')||'2025-01-01', hasta: val('o45-vhasta')||new Date().toISOString().slice(0,10) });
      ['grupo','tienda',...DIMS].forEach(k=>{ getMultiVals('o45-f-'+k).forEach(v=>p.append(k+'[]', v)); });
      return p.toString();
    }

    function poblarSelect(id, valores, labelFn){
      const el=document.getElementById(id); if(!el) return;
      const uniq=[...new Set(valores.filter(v=>v!==''&&v!=null))].sort((a,b)=>String(a).localeCompare(String(b)));
      el.innerHTML = uniq.map(v=>'<option value="'+String(v).replace(/"/g,'&quot;')+'">'+(labelFn?labelFn(v):v)+'</option>').join('');
      if (window.TomSelect){ if(tsRef[id]) tsRef[id].destroy(); tsRef[id]=new TomSelect(el,{plugins:['remove_button'],maxOptions:null}); }
    }

    function initFiltros(){
      fetch('api/informe_o45.php?tab=filtros',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
        comboCatalogo = d.combos||[];
        DIMS.forEach(k=> poblarSelect('o45-f-'+k, comboCatalogo.map(c=>c[k])));
        poblarSelect('o45-f-grupo', comboCatalogo.map(c=>c.grupo));
        // Tienda: value = NOMBRE, label = "COD - NOMBRE"
        const tEl=document.getElementById('o45-f-tienda');
        const seen={}; const opts=[];
        comboCatalogo.forEach(c=>{ const nom=c.tienda||''; if(!nom||seen[nom])return; seen[nom]=1; opts.push({nom, cod:c.tienda_cod||''}); });
        opts.sort((a,b)=>a.cod.localeCompare(b.cod));
        tEl.innerHTML = opts.map(o=>'<option value="'+o.nom.replace(/"/g,'&quot;')+'">'+o.cod+' - '+o.nom+'</option>').join('');
        if (window.TomSelect){ if(tsRef['o45-f-tienda']) tsRef['o45-f-tienda'].destroy(); tsRef['o45-f-tienda']=new TomSelect(tEl,{plugins:['remove_button'],maxOptions:null}); }
      });
    }

    const COLS = [
      {k:'negocio',t:'Negocio',dim:true}, {k:'marca',t:'Marca',dim:true},
      {k:'ventas',t:'Ventas (und)',f:nf}, {k:'tiendas',t:'#tiendas',f:nf},
      {k:'ind_inventario',t:'Índice de inventario',f:nf2}, {k:'stock_cedi',t:'Stock CEDI',f:nf},
      {k:'stock_tiendas',t:'Stock Tiendas',f:nf}, {k:'total_stock',t:'Total Stock',f:nf},
      {k:'ind_ventas_mes',t:'Índice de Ventas mes',f:nf2}, {k:'tallas',t:'Tallas',f:nf},
      {k:'precio',t:'Precio de Venta Detal',f:nf},
    ];

    function renderTabla(d){
      const cont=document.getElementById('o45-tabla');
      const filas=d.filas||[];
      if(!filas.length){ cont.innerHTML='<p style="padding:16px;color:var(--text-light)">Sin datos.</p>'; return; }
      let h='<table class="o45-tabla" id="o45-tbl"><thead><tr>';
      COLS.forEach(c=> h+='<th'+(c.dim?' class="dim"':'')+'>'+c.t+'</th>'); h+='</tr></thead><tbody>';
      filas.forEach(f=>{ h+='<tr>'; COLS.forEach(c=>{ const v=f[c.k]; h+='<td'+(c.dim?' class="dim"':'')+'>'+(c.dim?esc(v):c.f(v))+'</td>'; }); h+='</tr>'; });
      const t=d.total||{};
      h+='<tr class="o45-total"><td class="dim">TOTAL</td><td class="dim"></td>';
      ['ventas','tiendas','ind_inventario','stock_cedi','stock_tiendas','total_stock','ind_ventas_mes'].forEach(k=>{
        const fmt=(k==='ind_inventario'||k==='ind_ventas_mes')?nf2:nf; h+='<td>'+fmt(t[k])+'</td>'; });
      h+='<td></td><td></td></tr>';   // Tallas y Precio en blanco
      h+='</tbody></table>'; cont.innerHTML=h;
    }

    window.o45Load = function(){
      const cont=document.getElementById('o45-tabla'); cont.innerHTML='<p style="padding:16px;color:var(--text-light)">Cargando…</p>';
      fetch('api/informe_o45.php?'+buildParams(),{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
        if(!d.ok){ cont.innerHTML='<p style="padding:16px;color:var(--accent)">Error al cargar.</p>'; return; }
        window.__o45last=d; renderTabla(d);
      }).catch(()=>{ cont.innerHTML='<p style="padding:16px;color:var(--accent)">Error de red.</p>'; });
    };

    window.o45Export = function(){
      const tbl=document.getElementById('o45-tbl');
      if(!tbl || typeof XLSX==='undefined'){ if(window.Swal) Swal.fire('Exportar','Carga el informe primero.','info'); return; }
      const aoa=[...tbl.querySelectorAll('tr')].map(tr=>[...tr.children].map(td=>{
        const txt=td.textContent.trim(); const num=Number(txt.replace(/\./g,'').replace(',','.'));
        return (txt!=='' && !isNaN(num) && /[0-9]/.test(txt)) ? num : txt; }));
      const wb=XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(aoa), 'Indice');
      XLSX.writeFile(wb,'O45_indice_ventas.xlsx');
    };

    window.o45OnEnter = function(){
      document.getElementById('pageTitle').textContent = 'ÍNDICE DE VENTAS';
      document.getElementById('topbar').classList.add('topbar--o14');
      document.getElementById('pageSubtitle').style.display = 'none';
      const hoy = new Date().toISOString().slice(0,10);
      const td = document.getElementById('topbarDates'); td.style.display = '';
      if (!document.getElementById('o45-vdesde')) {
        td.innerHTML = '<div class="o14-topbar-dates"><label>Desde<input type="date" id="o45-vdesde" value="2025-01-01"></label>'
          + '<label>Hasta<input type="date" id="o45-vhasta" value="'+hoy+'"></label></div>';
      }
      const rb = document.getElementById('topbarO45Refresh'); if(rb) rb.style.display = '';
      if (!filtrosInit) { initFiltros(); filtrosInit = true; }
      if (!window.__o45last) o45Load();
    };
  })();
</script>
