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
  #page-informes-pagos .pg-neg { color: var(--danger,#e3342f); }
  #page-informes-pagos .pg-total-col { background:#fdf3c7; font-weight:700; }
  #page-informes-pagos #pg-tabla th:nth-child(2), #page-informes-pagos #pg-tabla td:nth-child(2) { border-right:2px solid var(--g00-divider,#adabb6); }
  #page-informes-pagos #pg-tabla th:last-child, #page-informes-pagos #pg-tabla td:last-child { border-left:2px solid var(--g00-divider,#adabb6); }
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
    <div class="card-title">Resumen Proyectado de Pagos<button class="g00-btn-export" onclick="pgExport()">&#10515; Excel</button></div>
    <div id="pg-aviso" style="display:none; margin:6px 0; padding:6px 10px; background:#fff3cd; color:#7a5b00; border:1px solid #ffe08a; border-radius:6px; font-size:12px;"></div>
    <div style="overflow-x:auto;"><table id="pg-tabla" class="disp-table"></table></div>
  </div>
  <div class="card">
    <div class="card-title">Resumen de Pagos Generados<button class="g00-btn-export" onclick="pgGenExport()">&#10515; Excel</button></div>
    <div style="overflow-x:auto;"><table id="pg-gen-tabla" class="disp-table"></table></div>
  </div>
</div>
<script>
(function(){
  const MESES_PG = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
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
        const av = document.getElementById('pg-aviso');
        if (d.sin_nit) {
          pgHideLoading();
          av.style.display = '';
          av.textContent = 'Este proveedor no tiene NIT asociado en el maestro de proveedores, por lo que no hay información de pagos disponible.';
          const vacio = '<tbody><tr><td style="text-align:center;color:var(--text-light);padding:20px;">Sin información de pagos</td></tr></tbody>';
          document.getElementById('pg-tabla').innerHTML = vacio;
          document.getElementById('pg-gen-tabla').innerHTML = vacio;
          return;
        }
        if (d.trm && d.trm.fallback) {
          av.style.display = '';
          av.textContent = '⚠ Tasa de cambio no disponible hoy (usando último valor del ' + (d.trm.fecha || '—') + '). Los montos en USD/EU pueden no estar actualizados.';
        } else { av.style.display = 'none'; }
        pgRender(d);
        pgGenLoad();
      })
      .catch(e => {
        pgHideLoading();
        if (window.Swal) Swal.fire('Pagos', 'No se pudo cargar: ' + e.message, 'error');
      });
  }

  function pgBuildGroups(d) {
    const meses = d.meses || [];
    const filas = d.filas || [];
    const anios = [...new Set(meses.map(m => m.anio))];
    const grupos = {};
    filas.forEach(f => {
      if (!grupos[f.fecha_venc]) grupos[f.fecha_venc] = { dias: f.dias, cells: {} };
      const k = f.anio_pago + '-' + f.mes_pago;
      grupos[f.fecha_venc].cells[k] = (grupos[f.fecha_venc].cells[k] || 0) + f.en_pesos;
    });
    return { meses, anios, grupos };
  }

  function pgRender(d) {
    const { meses, anios, grupos } = pgBuildGroups(d);
    const fechas = Object.keys(grupos).sort();

    if (fechas.length === 0) {
      document.getElementById('pg-tabla').innerHTML = '<tbody><tr><td style="text-align:center;color:var(--text-light);padding:20px;">Sin datos</td></tr></tbody>';
      return;
    }

    // Header
    let h = '<thead><tr><th>Fecha vencimiento</th><th>RAZON_SOCIAL</th>';
    anios.forEach(a => {
      meses.filter(m => m.anio === a).forEach(m => {
        h += '<th class="num">' + MESES_PG[m.mes - 1] + ' ' + a + '</th>';
      });
      h += '<th class="num pg-total-col">Total ' + a + '</th>';
    });
    h += '<th class="num pg-total-col">Total</th></tr></thead><tbody>';

    const money = (v) => '<td class="num' + (v < 0 ? ' pg-neg' : '') + '">' + pgMoney(v) + '</td>';
    const gtot = {};

    fechas.forEach(fch => {
      const g = grupos[fch];
      let rowTot = 0;
      h += '<tr><td>' + fch + '</td><td>' + (d.razon_social || '') + '</td>';
      anios.forEach(a => {
        let aTot = 0;
        meses.filter(m => m.anio === a).forEach(m => {
          const k = m.anio + '-' + m.mes;
          const v = g.cells[k] || 0;
          aTot += v;
          gtot[k] = (gtot[k] || 0) + v;
          h += money(v);
        });
        h += '<td class="num pg-total-col">' + pgMoney(aTot) + '</td>';
        rowTot += aTot;
      });
      h += '<td class="num pg-total-col">' + pgMoney(rowTot) + '</td></tr>';
    });

    // Fila total general
    let grand = 0;
    h += '<tr class="g00-total"><td>Total</td><td></td>';
    anios.forEach(a => {
      let aTot = 0;
      meses.filter(m => m.anio === a).forEach(m => {
        const k = m.anio + '-' + m.mes;
        const v = gtot[k] || 0;
        aTot += v;
        h += '<td class="num">' + pgMoney(v) + '</td>';
      });
      h += '<td class="num pg-total-col">' + pgMoney(aTot) + '</td>';
      grand += aTot;
    });
    h += '<td class="num pg-total-col">' + pgMoney(grand) + '</td></tr>';
    h += '</tbody>';
    document.getElementById('pg-tabla').innerHTML = h;
  }

  function pgExport() {
    if (!pgData) { window.expDataset('Resumen Proyectado de Pagos', 'Pagos', [], []); return; }
    const d = pgData;
    const { meses, grupos } = pgBuildGroups(d);
    const header = ['RAZON_SOCIAL', 'Fecha vencimiento', 'Anio', 'Mes', 'En Pesos'];
    const filas = [];
    Object.keys(grupos).sort().forEach(fch => {
      const g = grupos[fch];
      meses.forEach(m => { const v = g.cells[m.anio + '-' + m.mes] || 0;
        if (v) filas.push([d.razon_social || '', fch, m.anio, MESES_PG[m.mes - 1], v]); });
    });
    window.expDataset('Resumen Proyectado de Pagos', 'Pagos', header, filas, d.razon_social);
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
      const caret = tiene ? '<span class="g00-caret">&#9656;</span>' : '';
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
        if (!show) { tr.classList.add('g00-collapsed'); const c = tr.querySelector('.g00-caret'); if (c) c.innerHTML = '&#9656;'; setHijos(rid, false); }
      });
    }
    setHijos(id, !collapsed);
    const caret = el.querySelector('.g00-caret'); if (caret) caret.innerHTML = collapsed ? '&#9656;' : '&#9662;';
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
  window.pgRender = pgRender;
  window.pgExport = pgExport;

  window.pgOnEnter = function () {
    document.getElementById('pageTitle').textContent = 'ANÁLISIS DE PAGOS' + (window.PROVEEDOR_ACTUAL ? ' - ' + window.PROVEEDOR_ACTUAL : '');
    document.getElementById('topbarDates').style.display = 'none';
    if (!pgFiltrosInit) { pgFiltrosInit = true; pgLoad(); }
  };
})();
</script>
