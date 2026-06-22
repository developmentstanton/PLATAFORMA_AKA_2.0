<style>
  /* Extiende el estilo de inputs de .g00-filters (que solo cubre date/select) a number/text */
  #page-informes-pagos .g00-filters input[type="number"], #page-informes-pagos .g00-filters input[type="text"] {
    padding: 4px 10px; border: 1px solid var(--border); border-radius: 6px;
    font-size: 12px; font-family: 'Space Grotesk', sans-serif; outline: none;
    background: white; color: var(--text);
  }
  #page-informes-pagos .g00-filters input[type="number"]:focus, #page-informes-pagos .g00-filters input[type="text"]:focus { border-color: var(--primary); }
  #page-informes-pagos #pg-dias-min, #page-informes-pagos #pg-dias-max { width: 90px; }
  #page-informes-pagos table.disp-table th, #page-informes-pagos table.disp-table td { white-space:nowrap; font-size:11px; }
  #page-informes-pagos table.disp-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
  #page-informes-pagos .pg-neg { color: var(--danger,#e3342f); }
  #page-informes-pagos .pg-total-col { background:#fdf3c7; font-weight:700; }
  #page-informes-pagos #pg-tabla th:nth-child(3), #page-informes-pagos #pg-tabla td:nth-child(3) { border-right:2px solid var(--g00-divider,#adabb6); }
  #page-informes-pagos #pg-tabla th:last-child, #page-informes-pagos #pg-tabla td:last-child { border-left:2px solid var(--g00-divider,#adabb6); }
</style>
<div class="page" id="page-informes-pagos">
  <div class="g00-filters">
    <div class="g00-filter-row">
      <div class="filter-group"><label>Causado</label>
        <select id="pg-causado"><option value="">Todas</option><option value="SI">SI</option><option value="NO">NO</option></select></div>
      <div class="filter-group"><label>D&iacute;as Vto (min)</label><input type="number" id="pg-dias-min"></div>
      <div class="filter-group"><label>D&iacute;as Vto (max)</label><input type="number" id="pg-dias-max"></div>
      <div class="filter-group"><label>Desde</label><input type="date" id="pg-fdesde"></div>
      <div class="filter-group"><label>Hasta</label><input type="date" id="pg-fhasta"></div>
      <div style="margin-left:auto; align-self:flex-end;">
        <button class="g00-btn-refresh" onclick="pgLoad()"><i class="fa-solid fa-filter"></i> Aplicar</button>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-title">Resumen Mensual Flujo de Egresos<button class="g00-btn-export" onclick="pgExport()">&#10515; Excel</button></div>
    <div id="pg-aviso" style="display:none; margin:6px 0; padding:6px 10px; background:#fff3cd; color:#7a5b00; border:1px solid #ffe08a; border-radius:6px; font-size:12px;"></div>
    <div style="overflow-x:auto;"><table id="pg-tabla" class="disp-table"></table></div>
  </div>
</div>
<script>
(function(){
  const MESES_PG = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
  const pgMoney = (n) => '$' + Math.round(n || 0).toLocaleString('es-CO');
  let pgData = null;

  function pgParams() {
    const p = new URLSearchParams();
    const c = document.getElementById('pg-causado').value; if (c) p.append('causado', c);
    const dn = document.getElementById('pg-dias-min').value; if (dn !== '') p.append('dias_min', dn);
    const dx = document.getElementById('pg-dias-max').value; if (dx !== '') p.append('dias_max', dx);
    const fd = document.getElementById('pg-fdesde').value; if (fd) p.append('fdesde', fd);
    const fh = document.getElementById('pg-fhasta').value; if (fh) p.append('fhasta', fh);
    return p.toString();
  }

  function pgLoad() {
    fetch('api/informe_pagos.php?' + pgParams(), { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) {
          (window.Swal ? Swal.fire('Pagos', d.error || 'Error', 'error') : alert(d.error));
          return;
        }
        pgData = d;
        const av = document.getElementById('pg-aviso');
        if (d.trm && d.trm.fallback) {
          av.style.display = '';
          av.textContent = '⚠ Tasa de cambio no disponible hoy (usando último valor del ' + (d.trm.fecha || '—') + '). Los montos en USD/EU pueden no estar actualizados.';
        } else { av.style.display = 'none'; }
        pgRender(d);
      })
      .catch(e => {
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
    let h = '<thead><tr><th>Fecha vencimiento</th><th>D&iacute;as</th><th>RAZON_SOCIAL</th>';
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
      h += '<tr><td>' + fch + '</td><td class="num">' + g.dias + '</td><td>' + (d.razon_social || '') + '</td>';
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
    h += '<tr class="g00-total"><td>Total</td><td></td><td></td>';
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
    if (!pgData) { return; }
    if (typeof XLSX === 'undefined') {
      if (window.Swal) Swal.fire('Exportar', 'No se pudo cargar Excel.', 'error');
      return;
    }
    const d = pgData;
    const { meses, anios, grupos } = pgBuildGroups(d);
    const header = ['Fecha vencimiento', 'Días', 'RAZON_SOCIAL'];
    anios.forEach(a => {
      meses.filter(m => m.anio === a).forEach(m => header.push(MESES_PG[m.mes - 1] + ' ' + a));
      header.push('Total ' + a);
    });
    header.push('Total');

    const fechas = Object.keys(grupos).sort();
    const rows = fechas.map(fch => {
      const g = grupos[fch];
      const r = [fch, g.dias, d.razon_social || ''];
      let rt = 0;
      anios.forEach(a => {
        let at = 0;
        meses.filter(m => m.anio === a).forEach(m => {
          const v = g.cells[m.anio + '-' + m.mes] || 0;
          at += v;
          r.push(v);
        });
        r.push(at);
        rt += at;
      });
      r.push(rt);
      return r;
    });

    const ws = XLSX.utils.aoa_to_sheet([header, ...rows]);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Pagos');
    const fecha = new Date().toISOString().slice(0, 10);
    const prov = (d.razon_social || '').replace(/[^A-Za-z0-9]+/g, '_').replace(/^_|_$/g, '');
    XLSX.writeFile(wb, 'Analisis_Pagos' + (prov ? '_' + prov : '') + '_' + fecha + '.xlsx');
  }

  let pgFiltrosInit = false;

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
