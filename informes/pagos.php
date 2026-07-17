<style>
  /* Extiende el estilo de inputs de .g00-filters (que solo cubre date/select) a number/text */
  #page-informes-pagos .g00-filters input[type="number"], #page-informes-pagos .g00-filters input[type="text"] {
    padding: 4px 10px; border: 1px solid var(--border); border-radius: 6px;
    font-size: 12px; font-family: 'Space Grotesk', sans-serif; outline: none;
    background: white; color: var(--text);
  }
  #page-informes-pagos .g00-filters input[type="number"]:focus, #page-informes-pagos .g00-filters input[type="text"]:focus { border-color: var(--primary); }
  #page-informes-pagos table.disp-table th, #page-informes-pagos table.disp-table td { white-space:nowrap; font-size:11px; }
  #page-informes-pagos table.disp-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
  /* "Resumen de Pagos Generados": paleta verde (diferenciarla de la matriz) + todo centrado */
  #page-informes-pagos #pg-gen-tabla th,
  #page-informes-pagos #pg-gen-tabla td,
  #page-informes-pagos #pg-gen-tabla td.num { text-align: center; }
  #page-informes-pagos #pg-gen-tabla thead th { background: #2e6f4e; color: #fff; border-color: #27603f; }
  #page-informes-pagos #pg-gen-tabla tr.g00-total td { background: #d8ede0; color: #1c4d35; }
  #page-informes-pagos #pg-gen-tabla tr.g00-marca-row:hover td { background: #eef7f1; }
  #page-informes-pagos #pg-gen-tabla tr.zebra td { background: #f4faf6; }
</style>
<div class="page" id="page-informes-pagos">
  <div class="g00-filters">
    <div class="g00-filter-row">
      <div class="filter-group"><label>Desde</label><input type="date" id="pg-fdesde"></div>
      <div class="filter-group"><label>Hasta</label><input type="date" id="pg-fhasta"></div>
      <div style="margin-left:auto; align-self:flex-end;">
        <button class="g00-btn-refresh" onclick="pgLoad()"><i class="fa-solid fa-filter"></i> Aplicar</button>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-title">Resumen de Pagos Generados<button class="g00-btn-export" onclick="pgGenExport()">&#10515; Excel</button></div>
    <div style="overflow-x:auto;"><table id="pg-gen-tabla" class="disp-table"></table></div>
  </div>
</div>
<script>
(function(){
  const pgMoney = (n) => '$' + Math.round(n || 0).toLocaleString('es-CO');
  let pgData = null;

  function pgParams() {
    const p = new URLSearchParams();
    const fd = document.getElementById('pg-fdesde').value; if (fd) p.append('fdesde', fd);
    const fh = document.getElementById('pg-fhasta').value; if (fh) p.append('fhasta', fh);
    return p.toString();
  }

  function pgShowLoading() {
    if (!window.Swal) return;
    const ter = (window.PROVEEDOR_ACTUAL || '');
    Swal.fire({ title: 'Cargando...',
      html: '<div style="font-size:15px;font-weight:600;color:#4A4782;margin-top:4px">Análisis de Pagos</div>'
          + (ter ? '<div style="font-size:13px;color:#6b7280;margin-top:2px">' + ter + '</div>' : ''),
      allowOutsideClick: false, allowEscapeKey: false, showConfirmButton: false,
      didOpen: () => Swal.showLoading() });
  }
  function pgHideLoading() { if (window.Swal && Swal.isVisible()) Swal.close(); }

  function pgLoad() {
    pgShowLoading();
    fetch('api/informe_pagos.php?' + pgParams(), { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) {
          pgHideLoading();
          (window.Swal ? Swal.fire('Pagos', d.error || 'Error', 'error') : alert(d.error));
          return;
        }
        pgData = d;
        if (d.sin_nit) {
          pgHideLoading();
          document.getElementById('pg-gen-tabla').innerHTML = '<tbody><tr><td style="text-align:center;color:var(--text-light);padding:20px;">Sin información de pagos</td></tr></tbody>';
          return;
        }
        pgGenLoad();
        filtrosUI.setPeriodo('informes-pagos', document.getElementById('pg-fdesde').value, document.getElementById('pg-fhasta').value);
        filtrosUI.render(document.getElementById('page-informes-pagos'));
      })
      .catch(e => {
        pgHideLoading();
        if (window.Swal) Swal.fire('Pagos', 'No se pudo cargar: ' + e.message, 'error');
      });
  }

  let pgFiltrosInit = false;

  let pgGenData = null;
  const MES_PG = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

  function pgGenLoad() {
    fetch('api/informe_pagos_generados.php?' + pgParams(), { credentials:'same-origin' })
      .then(r => r.json())
      .then(d => { pgHideLoading(); if (!d.ok) { if (window.Swal) Swal.fire('Pagos Generados', d.error||'Error', 'error'); return; }
        pgGenData = d; pgGenRender(d); })
      .catch(e => { pgHideLoading(); if (window.Swal) Swal.fire('Pagos Generados', 'No se pudo cargar: ' + e.message, 'error'); });
  }

  function pgGenRender(d) {
    const nodos = d.nodos || [];
    if (!nodos.length) { document.getElementById('pg-gen-tabla').innerHTML = '<tbody><tr><td style="text-align:center;color:var(--text-light);padding:20px;">Sin datos</td></tr></tbody>'; return; }
    const hijos = {}; nodos.forEach(n => { const k = n.pid===null?'root':n.pid; (hijos[k] ??= []).push(n); });
    let h = '<thead><tr><th>FECHA</th><th class="num">VALOR TOTAL</th></tr></thead><tbody>';
    function emit(n) {
      const tiene = !!hijos[n.id];
      const pad = 'padding-left:' + (4 + (n.nivel-1)*18) + 'px;';
      const disp = n.nivel===1 ? '' : 'display:none;';
      const caret = tiene ? '<span class="g00-caret">+</span>' : '';
      const onclk = tiene ? ' onclick="pgGenToggle(' + n.id + ',this)"' : '';
      const cls = tiene ? 'g00-marca-row g00-collapsed' : '';
      h += '<tr class="' + cls + '" data-rid="' + n.id + '" data-pid="' + (n.pid===null?'':n.pid) + '" data-lvl="' + n.nivel + '" style="' + disp + '"' + onclk + '>'
         + '<td style="' + pad + '">' + caret + n.label + '</td>'
         + '<td class="num">' + pgMoney(n.valor) + '</td></tr>';
      (hijos[n.id]||[]).forEach(emit);
    }
    (hijos['root']||[]).forEach(emit);
    h += '<tr class="g00-total"><td>Total</td><td class="num">' + pgMoney(d.total.valor) + '</td></tr>';
    h += '</tbody>';
    document.getElementById('pg-gen-tabla').innerHTML = h;
  }

  window.pgGenToggle = function (id, el) {
    const collapsed = el.classList.toggle('g00-collapsed');
    // mostrar/ocultar descendientes recursivamente: al colapsar, ocultar todo el subárbol
    const tabla = document.getElementById('pg-gen-tabla');
    function setHijos(pid, show) {
      tabla.querySelectorAll('tr[data-pid="' + pid + '"]').forEach(tr => {
        tr.style.display = show ? '' : 'none';
        const rid = tr.getAttribute('data-rid');
        if (!show) { tr.classList.add('g00-collapsed'); const c = tr.querySelector('.g00-caret'); if (c) c.innerHTML = '+'; setHijos(rid, false); }
      });
    }
    setHijos(id, !collapsed);
    const caret = el.querySelector('.g00-caret'); if (caret) caret.innerHTML = collapsed ? '+' : '&#8722;';
  };

  function pgGenExport() {
    if (!pgGenData) { window.expDataset('Pagos Generados', 'Pagos Generados', [], []); return; }
    const header = ['Anio','Mes','Dia','Valor Total','Dias Vencidos'];
    const filas = (pgGenData.nodos||[]).filter(n => n.nivel === 3).map(n => [n.anio, MES_PG[(n.mes||1)-1] || n.mes, n.dia, n.valor, n.dias]);
    const prov = (pgGenData.razon_social || (pgData && pgData.razon_social) || '');
    window.expDataset('Pagos Generados', 'Pagos Generados', header, filas, prov);
  }
  window.pgGenLoad = pgGenLoad; window.pgGenExport = pgGenExport;

  window.pgLoad = pgLoad;

  window.pgOnEnter = function () {
    document.getElementById('pageTitle').textContent = 'ANÁLISIS DE PAGOS' + (window.PROVEEDOR_ACTUAL ? ' - ' + window.PROVEEDOR_ACTUAL : '');
    document.getElementById('topbarDates').style.display = 'none';
    if (!pgFiltrosInit) { pgFiltrosInit = true; pgLoad(); }
    filtrosUI.setPeriodo('informes-pagos', document.getElementById('pg-fdesde').value, document.getElementById('pg-fhasta').value);
    filtrosUI.render(document.getElementById('page-informes-pagos'));
  };
})();
</script>
