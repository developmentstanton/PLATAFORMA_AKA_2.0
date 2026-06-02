<?php /* Página del Informe G00 — Dashboard de Ventas (incluido desde dashboard.php) */ ?>
<style>
    /* ===== Estilos específicos del módulo Informes (prefijo .informe- y .g00-) ===== */
    .informe-header {
        display: flex; align-items: center; justify-content: space-between;
        background: linear-gradient(135deg, #fff 0%, #f8f7fc 100%);
        border: 1px solid var(--border); border-radius: 14px;
        padding: 18px 22px; margin-bottom: 18px;
        box-shadow: 0 1px 3px rgba(45,43,78,0.04);
    }
    .informe-meta { display: flex; align-items: center; gap: 16px; }
    .informe-code {
        background: var(--primary); color: white;
        width: 54px; height: 54px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 18px; letter-spacing: 1px;
        box-shadow: 0 4px 14px rgba(74,71,130,0.35);
    }
    .informe-meta h3 { font-size: 18px; color: var(--primary); margin: 0 0 3px; }
    .informe-meta p { font-size: 12px; color: var(--text-light); margin: 0; }
    .informe-meta p strong { color: var(--accent); font-weight: 700; letter-spacing: 0.5px; }
    .informe-actions { display: flex; gap: 8px; }

    /* Barra de filtros del informe */
    .g00-filters {
        display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        background: white; border: 1px solid var(--border); border-radius: 10px;
        padding: 12px 16px; margin-bottom: 18px;
    }
    .g00-filters .filter-group { display: flex; align-items: center; gap: 6px; }
    .g00-filters label {
        font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px;
        color: var(--text-light); font-weight: 600;
    }
    .g00-filters input[type="date"], .g00-filters select {
        padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px;
        font-size: 12px; font-family: 'Space Grotesk', sans-serif; outline: none;
        background: white; color: var(--text); cursor: pointer;
    }
    .g00-filters input[type="date"]:focus, .g00-filters select:focus { border-color: var(--primary); }
    .g00-filters .divider { width: 1px; height: 22px; background: var(--border); }
    .g00-btn-refresh {
        background: var(--primary); color: white; border: none;
        padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 600;
        cursor: pointer; font-family: 'Space Grotesk', sans-serif;
        display: inline-flex; align-items: center; gap: 6px;
        transition: background 0.2s;
    }
    .g00-btn-refresh:hover { background: var(--primary-dark); }

    /* Tabs del informe */
    .g00-tab-panel { display: none; }
    .g00-tab-panel.active { display: block; animation: g00FadeIn 0.35s ease-out; }
    @keyframes g00FadeIn {
        from { opacity: 0; transform: translateY(6px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* KPIs con gradiente sutil y animación */
    .g00-kpi {
        position: relative; overflow: hidden;
        background: white; border: 1px solid var(--border);
        border-radius: 12px; padding: 18px 20px;
        transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
    }
    .g00-kpi:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(74,71,130,0.10);
        border-color: var(--primary-light);
    }
    .g00-kpi::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
        background: linear-gradient(90deg, var(--primary), var(--primary-light));
    }
    .g00-kpi.accent::before { background: linear-gradient(90deg, var(--accent), #ff6b80); }
    .g00-kpi.success::before { background: linear-gradient(90deg, var(--success), #34d399); }
    .g00-kpi.warning::before { background: linear-gradient(90deg, var(--warning), #fbbf24); }
    .g00-kpi.info::before { background: linear-gradient(90deg, var(--info), #60a5fa); }
    .g00-kpi-head {
        display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;
    }
    .g00-kpi-label {
        font-size: 10px; text-transform: uppercase; letter-spacing: 0.8px;
        color: var(--text-light); font-weight: 700;
    }
    .g00-kpi-delta {
        font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px;
    }
    .g00-kpi-delta.up   { background: #ecfdf5; color: var(--success); }
    .g00-kpi-delta.down { background: #fef2f2; color: var(--danger); }
    .g00-kpi-value {
        font-size: 26px; font-weight: 700; color: var(--primary);
        letter-spacing: -0.5px; line-height: 1.15;
    }
    .g00-kpi-sub { font-size: 11px; color: var(--text-light); margin-top: 3px; }

    /* Skeleton shimmer mientras carga */
    .g00-skeleton {
        background: linear-gradient(90deg, #eee 0%, #f5f5f5 50%, #eee 100%);
        background-size: 200% 100%;
        animation: g00Shimmer 1.4s ease-in-out infinite;
        border-radius: 6px; display: inline-block;
    }
    @keyframes g00Shimmer {
        0%   { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }

    /* Placeholder para pestañas aún no implementadas */
    .g00-coming-soon {
        background: white; border: 2px dashed var(--border); border-radius: 14px;
        padding: 60px 24px; text-align: center; color: var(--text-light);
    }
    .g00-coming-soon .cs-icon {
        font-size: 42px; color: var(--primary-light); margin-bottom: 10px;
    }
    .g00-coming-soon h4 { color: var(--primary); font-size: 16px; margin-bottom: 6px; }
    .g00-coming-soon p { font-size: 12px; max-width: 420px; margin: 0 auto; line-height: 1.5; }
</style>

<div class="page" id="page-informes-g00">

    <!-- ============ HEADER ============ -->
    <div class="informe-header">
        <div class="informe-meta">
            <div class="informe-code">G00</div>
            <div>
                <h3>Dashboard de Ventas</h3>
                <p>Proveedor: <strong id="g00-proveedor">—</strong> &middot; <span id="g00-periodo">Cargando período…</span></p>
            </div>
        </div>
        <div class="informe-actions">
            <button class="btn btn-secondary btn-sm" onclick="g00Export()">
                <i class="fa-solid fa-file-excel"></i> Exportar
            </button>
            <button class="btn btn-primary btn-sm" onclick="g00Load()">
                <i class="fa-solid fa-arrows-rotate"></i> Actualizar
            </button>
        </div>
    </div>

    <!-- ============ FILTROS ============ -->
    <div class="g00-filters">
        <div class="filter-group">
            <label>Desde</label>
            <input type="date" id="g00-filtro-desde">
        </div>
        <div class="filter-group">
            <label>Hasta</label>
            <input type="date" id="g00-filtro-hasta">
        </div>
        <div class="divider"></div>
        <div class="filter-group">
            <label>Grupo</label>
            <select id="g00-filtro-grupo"><option value="">Todos</option></select>
        </div>
        <div class="filter-group">
            <label>Marca</label>
            <select id="g00-filtro-marca"><option value="">Todas</option></select>
        </div>
        <div style="margin-left:auto;">
            <button class="g00-btn-refresh" onclick="g00Load()">
                <i class="fa-solid fa-filter"></i> Aplicar
            </button>
        </div>
    </div>

    <!-- ============ TABS ============ -->
    <div class="tab-bar">
        <div class="tab active" onclick="g00ShowTab('detal', this)">Detal</div>
        <div class="tab" onclick="g00ShowTab('tiendas', this)">Detalle Tiendas</div>
        <div class="tab" onclick="g00ShowTab('periodos', this)">Ventas Por Periodos</div>
        <div class="tab" onclick="g00ShowTab('productos', this)">Ventas Por Productos</div>
    </div>

    <!-- ============ TAB DETAL ============ -->
    <div class="g00-tab-panel active" id="g00-panel-detal">

        <!-- KPIs -->
        <div class="stats-grid" style="grid-template-columns: repeat(5, 1fr);">
            <div class="g00-kpi">
                <div class="g00-kpi-head">
                    <span class="g00-kpi-label">Ventas YTD</span>
                    <span class="g00-kpi-delta up" id="g00-kpi-ventas-delta">—</span>
                </div>
                <div class="g00-kpi-value" id="g00-kpi-ventas">
                    <span class="g00-skeleton" style="width:120px;height:26px;"></span>
                </div>
                <div class="g00-kpi-sub" id="g00-kpi-ventas-sub">vs. año anterior</div>
            </div>
            <div class="g00-kpi info">
                <div class="g00-kpi-head">
                    <span class="g00-kpi-label">Unidades</span>
                    <span class="g00-kpi-delta up" id="g00-kpi-ups-delta">—</span>
                </div>
                <div class="g00-kpi-value" id="g00-kpi-ups">
                    <span class="g00-skeleton" style="width:90px;height:26px;"></span>
                </div>
                <div class="g00-kpi-sub">pares vendidos YTD</div>
            </div>
            <div class="g00-kpi success" title="Valor total vendido ÷ unidades vendidas">
                <div class="g00-kpi-head">
                    <span class="g00-kpi-label">Ticket Promedio</span>
                    <i class="fa-solid fa-circle-info" style="color:var(--text-light);font-size:11px;"></i>
                </div>
                <div class="g00-kpi-value" id="g00-kpi-ticket">
                    <span class="g00-skeleton" style="width:100px;height:26px;"></span>
                </div>
                <div class="g00-kpi-sub">$ ingreso promedio por par</div>
            </div>
            <div class="g00-kpi warning">
                <div class="g00-kpi-head">
                    <span class="g00-kpi-label">Margen Promedio</span>
                </div>
                <div class="g00-kpi-value" id="g00-kpi-margen">
                    <span class="g00-skeleton" style="width:70px;height:26px;"></span>
                </div>
                <div class="g00-kpi-sub">% sobre PVP</div>
            </div>
            <div class="g00-kpi accent">
                <div class="g00-kpi-head">
                    <span class="g00-kpi-label">Tiendas con Ventas</span>
                    <span class="g00-kpi-delta up" id="g00-kpi-tiendas-delta">—</span>
                </div>
                <div class="g00-kpi-value" id="g00-kpi-tiendas">
                    <span class="g00-skeleton" style="width:60px;height:26px;"></span>
                </div>
                <div class="g00-kpi-sub" id="g00-kpi-tiendas-sub">bodegas distintas con venta</div>
            </div>
        </div>

        <!-- GRÁFICAS: mensual + grupo -->
        <div class="grid-2" style="grid-template-columns: 1.6fr 1fr;">
            <div class="card">
                <div class="card-title">
                    Ventas Mensuales <span style="color:var(--text-light);font-weight:500;">&mdash; <span id="g00-anio-label">—</span> vs año anterior</span>
                </div>
                <div id="g00-chart-mensual" style="width:100%;height:340px;"></div>
            </div>
            <div class="card">
                <div class="card-title">Ventas por Grupo Tienda</div>
                <div id="g00-chart-grupo" style="width:100%;height:340px;"></div>
            </div>
        </div>

        <!-- GRÁFICA: por marca -->
        <div class="card">
            <div class="card-title">Ventas por Marca <span style="color:var(--text-light);font-weight:500;">&mdash; comparativo YoY</span></div>
            <div id="g00-chart-marca" style="width:100%;height:320px;"></div>
        </div>

    </div>

    <!-- ============ TAB DETALLE TIENDAS ============ -->
    <div class="g00-tab-panel" id="g00-panel-tiendas">
        <div class="grid-2" style="grid-template-columns: 1.4fr 1fr;">
            <div class="card">
                <div class="card-title">Mapa de Tiendas <span style="color:var(--text-light);font-weight:500;">&mdash; tamaño = ventas, color = Δ%</span></div>
                <div id="g00-chart-treemap-tiendas" style="width:100%;height:440px;"></div>
            </div>
            <div class="card">
                <div class="card-title">Top 10 Tiendas</div>
                <div style="max-height:440px;overflow-y:auto;">
                    <table id="g00-tabla-top-tiendas">
                        <thead><tr><th>Tienda</th><th>Ciudad</th><th style="text-align:right;">Ventas</th><th style="text-align:right;">Δ%</th></tr></thead>
                        <tbody><tr><td colspan="4" style="text-align:center;color:var(--text-light);padding:20px;">Cargando…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-title">Detalle Completo de Tiendas</div>
            <div style="overflow-x:auto;">
                <table id="g00-tabla-tiendas" class="disp-table">
                    <thead><tr>
                        <th>Grupo</th><th>Código</th><th>Tienda</th><th>Ciudad</th><th>Depto</th><th>Estado</th>
                        <th style="text-align:right;">Ventas</th>
                        <th style="text-align:right;">Año Ant.</th>
                        <th style="text-align:right;">Δ%</th>
                        <th style="text-align:right;">UPS</th>
                    </tr></thead>
                    <tbody><tr><td colspan="10" style="text-align:center;color:var(--text-light);padding:20px;">Cargando…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============ TAB VENTAS POR PERIODOS ============ -->
    <div class="g00-tab-panel" id="g00-panel-periodos">
        <div class="card">
            <div class="card-title">Heatmap Calendario <span style="color:var(--text-light);font-weight:500;">&mdash; intensidad de ventas por día</span></div>
            <div id="g00-chart-calendar" style="width:100%;height:260px;"></div>
        </div>
        <div class="grid-2" style="grid-template-columns: 1fr 1fr;">
            <div class="card">
                <div class="card-title">Ventas por Día de la Semana</div>
                <div id="g00-chart-dow" style="width:100%;height:300px;"></div>
            </div>
            <div class="card">
                <div class="card-title">Tendencia Diaria <span style="color:var(--text-light);font-weight:500;">&mdash; evolución del rango</span></div>
                <div id="g00-chart-tendencia" style="width:100%;height:300px;"></div>
            </div>
        </div>
    </div>

    <!-- ============ TAB VENTAS POR PRODUCTOS ============ -->
    <div class="g00-tab-panel" id="g00-panel-productos">
        <div class="card">
            <div class="card-title">Jerarquía Marca &rarr; Línea <span style="color:var(--text-light);font-weight:500;">&mdash; click para profundizar</span></div>
            <div id="g00-chart-treemap-productos" style="width:100%;height:420px;"></div>
        </div>
        <div class="grid-2" style="grid-template-columns: 1.3fr 1fr;">
            <div class="card">
                <div class="card-title">Pareto 80/20 de Referencias <span style="color:var(--text-light);font-weight:500;">&mdash; top 30</span></div>
                <div id="g00-chart-pareto" style="width:100%;height:340px;"></div>
            </div>
            <div class="card">
                <div class="card-title">Top 20 Productos</div>
                <div style="max-height:340px;overflow-y:auto;">
                    <table id="g00-tabla-productos" class="disp-table">
                        <thead><tr>
                            <th>Referencia</th><th>Marca</th>
                            <th style="text-align:right;">UPS</th>
                            <th style="text-align:right;">Ventas</th>
                            <th style="text-align:right;">Margen</th>
                        </tr></thead>
                        <tbody><tr><td colspan="5" style="text-align:center;color:var(--text-light);padding:20px;">Cargando…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
(function () {
    'use strict';

    let selectsPopulated = false;
    let currentTab = 'detal';
    let proveedorActual = (window.PROVEEDOR_ACTUAL || '');
    const tabState = { detal: false, tiendas: false, periodos: false, productos: false };

    function showLoading(accion) {
        const prov = proveedorActual ? ' del proveedor <strong>' + esc(proveedorActual) + '</strong>' : '';
        Swal.fire({
            title: accion || 'Cargando datos',
            html: 'Obteniendo información' + prov + '…',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });
    }
    function hideLoading() {
        if (window.Swal && Swal.isVisible()) Swal.close();
    }
    const charts = {
        mensual: null, grupo: null, marca: null,
        treemapTiendas: null,
        calendar: null, dow: null, tendencia: null,
        treemapProd: null, pareto: null,
    };

    const fmtMoney = (n) => {
        if (!n) return '$0';
        const abs = Math.abs(n);
        if (abs >= 1e9) return '$' + (n / 1e9).toFixed(2) + 'MM';
        if (abs >= 1e6) return '$' + (n / 1e6).toFixed(1) + 'M';
        if (abs >= 1e3) return '$' + (n / 1e3).toFixed(0) + 'K';
        return '$' + Math.round(n).toLocaleString('es-CO');
    };
    const fmtMoneyFull = (n) => '$' + Math.round(n || 0).toLocaleString('es-CO');
    const fmtInt = (n) => Math.round(n || 0).toLocaleString('es-CO');
    const fmtPct = (n) => (n >= 0 ? '+' : '') + (n || 0).toFixed(1) + '%';

    function animate(el, to, fmt, duration) {
        duration = duration || 900;
        const t0 = performance.now();
        (function tick(t) {
            const k = Math.min(1, (t - t0) / duration);
            const eased = 1 - Math.pow(1 - k, 3);
            el.textContent = fmt(to * eased);
            if (k < 1) requestAnimationFrame(tick);
        })(t0);
    }

    function setDelta(el, pct) {
        el.classList.remove('up', 'down');
        el.classList.add(pct >= 0 ? 'up' : 'down');
        el.textContent = fmtPct(pct);
    }

    function renderKpis(k, anio) {
        document.getElementById('g00-anio-label').textContent = anio;
        animate(document.getElementById('g00-kpi-ventas'),  k.ventas_actual,  fmtMoney);
        animate(document.getElementById('g00-kpi-ups'),     k.ups_actual,     fmtInt);
        animate(document.getElementById('g00-kpi-ticket'),  k.ticket_prom,    fmtMoneyFull);
        animate(document.getElementById('g00-kpi-margen'),  k.margen_prom,    (n) => n.toFixed(2) + '%');
        animate(document.getElementById('g00-kpi-tiendas'), k.tiendas_actual, (n) => Math.round(n).toString());
        setDelta(document.getElementById('g00-kpi-ventas-delta'),  k.delta_ventas);
        setDelta(document.getElementById('g00-kpi-ups-delta'),     k.delta_ups);
        setDelta(document.getElementById('g00-kpi-tiendas-delta'), k.delta_tiendas);
        document.getElementById('g00-kpi-ventas-sub').textContent =
            'vs ' + fmtMoney(k.ventas_anterior) + ' año anterior';
        document.getElementById('g00-kpi-tiendas-sub').textContent =
            'vs ' + (k.tiendas_anterior || 0) + ' año anterior';
    }

    function populateSelects(catalogos) {
        if (selectsPopulated || !catalogos) return;
        const gSel = document.getElementById('g00-filtro-grupo');
        const mSel = document.getElementById('g00-filtro-marca');
        (catalogos.grupos || []).forEach(g => {
            if (!g) return;
            const o = document.createElement('option'); o.value = g; o.textContent = g;
            gSel.appendChild(o);
        });
        (catalogos.marcas || []).forEach(m => {
            if (!m) return;
            const o = document.createElement('option'); o.value = m; o.textContent = m;
            mSel.appendChild(o);
        });
        selectsPopulated = true;
    }

    function initChartMensual(serie, anio) {
        const el = document.getElementById('g00-chart-mensual');
        if (!charts.mensual) charts.mensual = echarts.init(el);
        const labels = serie.map(r => r.mes);
        const actual = serie.map(r => r.actual);
        const anterior = serie.map(r => r.anterior);
        charts.mensual.setOption({
            color: ['#4A4782', '#ff001e'],
            tooltip: {
                trigger: 'axis',
                backgroundColor: 'rgba(45,43,78,0.95)',
                borderWidth: 0,
                textStyle: { color: '#fff', fontFamily: 'Space Grotesk' },
                formatter: (params) => {
                    let h = '<b>' + params[0].axisValue + '</b><br/>';
                    params.forEach(p => { h += p.marker + ' ' + p.seriesName + ': <b>' + fmtMoneyFull(p.value) + '</b><br/>'; });
                    return h;
                }
            },
            legend: { data: [String(anio), String(anio - 1)], right: 10, top: 0, textStyle: { fontFamily: 'Space Grotesk', fontSize: 11 } },
            grid: { left: 55, right: 18, top: 32, bottom: 28 },
            xAxis: {
                type: 'category', data: labels,
                axisLine: { lineStyle: { color: '#e0dfe8' } },
                axisLabel: { color: '#7b7894', fontFamily: 'Space Grotesk', fontSize: 11 }
            },
            yAxis: {
                type: 'value',
                axisLine: { show: false }, splitLine: { lineStyle: { color: '#f0eff5' } },
                axisLabel: { color: '#7b7894', fontFamily: 'Space Grotesk', fontSize: 10, formatter: fmtMoney }
            },
            series: [
                {
                    name: String(anio), type: 'line', data: actual, smooth: true, symbol: 'circle', symbolSize: 7,
                    lineStyle: { width: 3 },
                    areaStyle: { color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                        { offset: 0, color: 'rgba(74,71,130,0.35)' }, { offset: 1, color: 'rgba(74,71,130,0.02)' }
                    ]) },
                    emphasis: { focus: 'series' }
                },
                {
                    name: String(anio - 1), type: 'line', data: anterior, smooth: true, symbol: 'circle', symbolSize: 5,
                    lineStyle: { width: 2, type: 'dashed' },
                    emphasis: { focus: 'series' }
                }
            ],
            animationDuration: 1000,
            animationEasing: 'cubicOut'
        });
    }

    function initChartBarras(id, key, data, anio) {
        const el = document.getElementById(id);
        if (!charts[key]) charts[key] = echarts.init(el);
        const top = data.slice(0, 8).reverse();
        const labels = top.map(r => r.label);
        charts[key].setOption({
            color: ['#4A4782', '#c9c7dd'],
            tooltip: {
                trigger: 'axis', axisPointer: { type: 'shadow' },
                backgroundColor: 'rgba(45,43,78,0.95)', borderWidth: 0,
                textStyle: { color: '#fff', fontFamily: 'Space Grotesk' },
                formatter: (params) => {
                    const idx = params[0].dataIndex;
                    const row = top[idx];
                    const delta = row.delta_pct;
                    const deltaTxt = (delta >= 0 ? '▲ ' : '▼ ') + Math.abs(delta).toFixed(1) + '%';
                    let h = '<b>' + row.label + '</b><br/>';
                    h += params[0].marker + ' ' + anio + ': <b>' + fmtMoneyFull(row.actual) + '</b><br/>';
                    h += params[1].marker + ' ' + (anio - 1) + ': <b>' + fmtMoneyFull(row.anterior) + '</b><br/>';
                    h += '<span style="color:' + (delta >= 0 ? '#34d399' : '#ff6b80') + ';font-weight:700;">' + deltaTxt + '</span>';
                    return h;
                }
            },
            legend: { data: [String(anio), String(anio - 1)], right: 10, top: 0, textStyle: { fontFamily: 'Space Grotesk', fontSize: 11 } },
            grid: { left: 110, right: 20, top: 32, bottom: 18 },
            xAxis: {
                type: 'value',
                axisLine: { show: false }, splitLine: { lineStyle: { color: '#f0eff5' } },
                axisLabel: { color: '#7b7894', fontFamily: 'Space Grotesk', fontSize: 10, formatter: fmtMoney }
            },
            yAxis: {
                type: 'category', data: labels,
                axisLine: { lineStyle: { color: '#e0dfe8' } },
                axisLabel: { color: '#2d2b4e', fontFamily: 'Space Grotesk', fontSize: 11, fontWeight: 600 }
            },
            series: [
                { name: String(anio),     type: 'bar', data: top.map(r => r.actual),   barWidth: 14, itemStyle: { borderRadius: [0, 4, 4, 0] } },
                { name: String(anio - 1), type: 'bar', data: top.map(r => r.anterior), barWidth: 14, itemStyle: { borderRadius: [0, 4, 4, 0] } }
            ],
            animationDuration: 1100,
            animationDelay: (i) => i * 60,
            animationEasing: 'cubicOut'
        });
    }

    function showError(msg) {
        const host = document.getElementById('page-informes-g00');
        let banner = host.querySelector('.g00-error');
        if (!banner) {
            banner = document.createElement('div');
            banner.className = 'g00-error';
            banner.style.cssText = 'background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:14px;font-size:13px;';
            host.insertBefore(banner, host.firstChild);
        }
        banner.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + msg;
    }

    function buildParams(tab) {
        const p = new URLSearchParams();
        if (tab) p.append('tab', tab);
        const d = document.getElementById('g00-filtro-desde').value;
        const h = document.getElementById('g00-filtro-hasta').value;
        const g = document.getElementById('g00-filtro-grupo').value;
        const m = document.getElementById('g00-filtro-marca').value;
        if (d) p.append('desde', d);
        if (h) p.append('hasta', h);
        if (g) p.append('grupo', g);
        if (m) p.append('marca', m);
        return p.toString();
    }

    // ============ LOAD: DETAL ============
    function loadDetal() {
        showLoading('Cargando dashboard');
        fetch('api/informe_g00.php?' + buildParams(), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { hideLoading(); showError(data.error || 'Error cargando datos'); return; }
                const host = document.getElementById('page-informes-g00');
                const banner = host.querySelector('.g00-error'); if (banner) banner.remove();
                populateSelects(data.catalogos);
                proveedorActual = data.proveedor || '';
                document.getElementById('g00-proveedor').textContent = data.proveedor || '—';
                const r0 = data.rango || {};
                document.getElementById('g00-periodo').textContent = r0.desde_actual
                    ? 'Periodo: ' + r0.desde_actual + ' → ' + r0.hasta_actual + ' (vs ' + r0.desde_anterior + ' → ' + r0.hasta_anterior + ')'
                    : 'Datos al ' + new Date(data.generado).toLocaleDateString('es-CO');
                renderKpis(data.kpis, data.anio);
                initChartMensual(data.mensual, data.anio);
                initChartBarras('g00-chart-grupo', 'grupo', data.por_grupo, data.anio);
                initChartBarras('g00-chart-marca', 'marca', data.por_marca, data.anio);
                tabState.detal = true;
                hideLoading();
            })
            .catch(err => { hideLoading(); showError('No se pudo cargar el informe: ' + err.message); });
    }

    // ============ LOAD: TIENDAS ============
    function loadTiendas() {
        showLoading('Cargando tiendas');
        fetch('api/informe_g00.php?' + buildParams('tiendas'), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { hideLoading(); showError(data.error || 'Error cargando tiendas'); return; }
                renderTreemapTiendas(data.tiendas);
                renderTopTiendas(data.tiendas);
                renderTablaTiendas(data.tiendas);
                tabState.tiendas = true;
                hideLoading();
            })
            .catch(err => { hideLoading(); showError('No se pudo cargar tiendas: ' + err.message); });
    }

    function renderTreemapTiendas(tiendas) {
        const el = document.getElementById('g00-chart-treemap-tiendas');
        if (!charts.treemapTiendas) charts.treemapTiendas = echarts.init(el);
        if (!tiendas || tiendas.length === 0) {
            charts.treemapTiendas.setOption({ title: { text: 'Sin datos en el período', left: 'center', top: 'middle', textStyle: { color: '#7b7894', fontSize: 13 } } }, true);
            return;
        }
        const data = tiendas.filter(t => t.actual > 0).map(t => ({
            name: t.nombre || t.cod,
            value: t.actual,
            delta: t.delta_pct,
            grupo: t.grupo,
            ciudad: t.ciudad,
            itemStyle: {
                color: t.delta_pct >= 15 ? '#10b981'
                     : t.delta_pct >= 0  ? '#4A4782'
                     : t.delta_pct >= -15 ? '#f59e0b'
                     : '#ef4444'
            }
        }));
        charts.treemapTiendas.setOption({
            tooltip: {
                backgroundColor: 'rgba(45,43,78,0.95)', borderWidth: 0,
                textStyle: { color: '#fff', fontFamily: 'Space Grotesk' },
                formatter: (p) => {
                    const d = p.data;
                    return '<b>' + d.name + '</b><br/>'
                        + (d.grupo ? 'Grupo: ' + d.grupo + '<br/>' : '')
                        + (d.ciudad ? d.ciudad + '<br/>' : '')
                        + 'Ventas: <b>' + fmtMoneyFull(d.value) + '</b><br/>'
                        + 'Δ%: <b style="color:' + (d.delta >= 0 ? '#34d399' : '#ff6b80') + ';">' + fmtPct(d.delta) + '</b>';
                }
            },
            series: [{
                type: 'treemap', data: data, roam: false, nodeClick: false,
                breadcrumb: { show: false },
                label: { show: true, fontFamily: 'Space Grotesk', fontSize: 11, color: '#fff', formatter: (p) => p.name },
                itemStyle: { borderColor: '#fff', borderWidth: 1, gapWidth: 2 },
                animationDuration: 900
            }]
        }, true);
    }

    function renderTopTiendas(tiendas) {
        const tbody = document.querySelector('#g00-tabla-top-tiendas tbody');
        const top = (tiendas || []).filter(t => t.actual > 0).slice(0, 10);
        if (top.length === 0) { tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--text-light);padding:20px;">Sin datos</td></tr>'; return; }
        tbody.innerHTML = top.map(t => {
            const cls = t.delta_pct >= 0 ? 'up' : 'down';
            return '<tr>'
                + '<td><strong>' + esc(t.nombre || t.cod) + '</strong><br/><span style="font-size:10px;color:var(--text-light);">' + esc(t.grupo) + '</span></td>'
                + '<td style="font-size:12px;">' + esc(t.ciudad) + '</td>'
                + '<td style="text-align:right;font-weight:600;">' + fmtMoney(t.actual) + '</td>'
                + '<td style="text-align:right;"><span class="g00-kpi-delta ' + cls + '" style="font-size:10px;">' + fmtPct(t.delta_pct) + '</span></td>'
                + '</tr>';
        }).join('');
    }

    function renderTablaTiendas(tiendas) {
        const tbody = document.querySelector('#g00-tabla-tiendas tbody');
        if (!tiendas || tiendas.length === 0) { tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:var(--text-light);padding:20px;">Sin datos</td></tr>'; return; }
        tbody.innerHTML = tiendas.map(t => {
            const cls = t.delta_pct >= 0 ? 'up' : 'down';
            const estBadge = t.estado === 'ABIERTA'
                ? '<span class="status status-vigente">ABIERTA</span>'
                : (t.estado === 'CERRADA' ? '<span class="status status-rechazado">CERRADA</span>' : '<span style="color:var(--text-light);">—</span>');
            return '<tr>'
                + '<td style="font-weight:600;">' + esc(t.grupo) + '</td>'
                + '<td>' + esc(t.cod) + '</td>'
                + '<td>' + esc(t.nombre) + '</td>'
                + '<td>' + esc(t.ciudad) + '</td>'
                + '<td>' + esc(t.depto) + '</td>'
                + '<td>' + estBadge + '</td>'
                + '<td style="text-align:right;font-weight:600;">' + fmtMoneyFull(t.actual) + '</td>'
                + '<td style="text-align:right;color:var(--text-light);">' + fmtMoneyFull(t.anterior) + '</td>'
                + '<td style="text-align:right;"><span class="g00-kpi-delta ' + cls + '" style="font-size:10px;">' + fmtPct(t.delta_pct) + '</span></td>'
                + '<td style="text-align:right;">' + fmtInt(t.ups_actual) + '</td>'
                + '</tr>';
        }).join('');
    }

    // ============ LOAD: PERIODOS ============
    function loadPeriodos() {
        showLoading('Cargando periodos');
        fetch('api/informe_g00.php?' + buildParams('periodos'), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { hideLoading(); showError(data.error || 'Error cargando periodos'); return; }
                renderCalendar(data.diario, data.rango);
                renderDow(data.por_dow);
                renderTendencia(data.diario);
                tabState.periodos = true;
                hideLoading();
            })
            .catch(err => { hideLoading(); showError('No se pudo cargar periodos: ' + err.message); });
    }

    function renderCalendar(diario, rango) {
        const el = document.getElementById('g00-chart-calendar');
        if (!charts.calendar) charts.calendar = echarts.init(el);
        const data = (diario || []).map(d => [d.dia, d.valor]);
        const values = data.map(d => d[1]);
        const max = values.length ? Math.max(...values) : 0;
        const rangeStart = rango.desde;
        const rangeEnd = rango.hasta;
        charts.calendar.setOption({
            tooltip: {
                backgroundColor: 'rgba(45,43,78,0.95)', borderWidth: 0,
                textStyle: { color: '#fff', fontFamily: 'Space Grotesk' },
                formatter: (p) => '<b>' + p.value[0] + '</b><br/>Ventas: <b>' + fmtMoneyFull(p.value[1]) + '</b>'
            },
            visualMap: {
                min: 0, max: max || 1,
                calculable: false, orient: 'horizontal', left: 'center', bottom: 0,
                inRange: { color: ['#f0eff5', '#c9c7dd', '#4A4782', '#ff001e'] },
                textStyle: { color: '#7b7894', fontFamily: 'Space Grotesk', fontSize: 10 }
            },
            calendar: {
                top: 30, left: 40, right: 20, bottom: 40,
                range: [rangeStart, rangeEnd],
                cellSize: ['auto', 14],
                itemStyle: { borderColor: '#fff', borderWidth: 1 },
                splitLine: { show: false },
                dayLabel: { color: '#7b7894', fontFamily: 'Space Grotesk', fontSize: 10, nameMap: ['D','L','M','M','J','V','S'] },
                monthLabel: { color: '#2d2b4e', fontFamily: 'Space Grotesk', fontSize: 11, fontWeight: 600, nameMap: ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'] },
                yearLabel: { show: false }
            },
            series: [{ type: 'heatmap', coordinateSystem: 'calendar', data: data, animationDuration: 900 }]
        }, true);
    }

    function renderDow(porDow) {
        const el = document.getElementById('g00-chart-dow');
        if (!charts.dow) charts.dow = echarts.init(el);
        const labels = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
        const vals = [0,0,0,0,0,0,0];
        (porDow || []).forEach(r => { if (r.dow >= 1 && r.dow <= 7) vals[r.dow - 1] = r.valor; });
        charts.dow.setOption({
            color: ['#4A4782'],
            tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' },
                backgroundColor: 'rgba(45,43,78,0.95)', borderWidth: 0,
                textStyle: { color: '#fff', fontFamily: 'Space Grotesk' },
                formatter: (p) => '<b>' + p[0].axisValue + '</b><br/>' + fmtMoneyFull(p[0].value) },
            grid: { left: 55, right: 18, top: 22, bottom: 24 },
            xAxis: { type: 'category', data: labels, axisLine: { lineStyle: { color: '#e0dfe8' } }, axisLabel: { color: '#7b7894', fontFamily: 'Space Grotesk', fontSize: 11 } },
            yAxis: { type: 'value', axisLine: { show: false }, splitLine: { lineStyle: { color: '#f0eff5' } }, axisLabel: { color: '#7b7894', fontFamily: 'Space Grotesk', fontSize: 10, formatter: fmtMoney } },
            series: [{
                type: 'bar', data: vals, barWidth: 28,
                itemStyle: { borderRadius: [6,6,0,0],
                    color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                        { offset: 0, color: '#5c59a0' }, { offset: 1, color: '#4A4782' }
                    ])
                },
                animationDuration: 900, animationDelay: (i) => i * 80
            }]
        }, true);
    }

    function renderTendencia(diario) {
        const el = document.getElementById('g00-chart-tendencia');
        if (!charts.tendencia) charts.tendencia = echarts.init(el);
        const labels = (diario || []).map(d => d.dia);
        const vals = (diario || []).map(d => d.valor);
        charts.tendencia.setOption({
            color: ['#ff001e'],
            tooltip: { trigger: 'axis',
                backgroundColor: 'rgba(45,43,78,0.95)', borderWidth: 0,
                textStyle: { color: '#fff', fontFamily: 'Space Grotesk' },
                formatter: (p) => '<b>' + p[0].axisValue + '</b><br/>' + fmtMoneyFull(p[0].value) },
            grid: { left: 55, right: 18, top: 22, bottom: 28 },
            xAxis: { type: 'category', data: labels, axisLine: { lineStyle: { color: '#e0dfe8' } }, axisLabel: { color: '#7b7894', fontFamily: 'Space Grotesk', fontSize: 10, showMaxLabel: true } },
            yAxis: { type: 'value', axisLine: { show: false }, splitLine: { lineStyle: { color: '#f0eff5' } }, axisLabel: { color: '#7b7894', fontFamily: 'Space Grotesk', fontSize: 10, formatter: fmtMoney } },
            series: [{
                type: 'line', data: vals, smooth: true, symbol: 'none',
                lineStyle: { width: 2 },
                areaStyle: { color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                    { offset: 0, color: 'rgba(255,0,30,0.28)' }, { offset: 1, color: 'rgba(255,0,30,0.02)' }
                ]) },
                animationDuration: 1000
            }]
        }, true);
    }

    // ============ LOAD: PRODUCTOS ============
    function loadProductos() {
        showLoading('Cargando productos');
        fetch('api/informe_g00.php?' + buildParams('productos'), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { hideLoading(); showError(data.error || 'Error cargando productos'); return; }
                renderTreemapProductos(data.treemap);
                renderPareto(data.refs);
                renderTablaProductos(data.refs);
                tabState.productos = true;
                hideLoading();
            })
            .catch(err => { hideLoading(); showError('No se pudo cargar productos: ' + err.message); });
    }

    function renderTreemapProductos(tree) {
        const el = document.getElementById('g00-chart-treemap-productos');
        if (!charts.treemapProd) charts.treemapProd = echarts.init(el);
        charts.treemapProd.setOption({
            tooltip: {
                backgroundColor: 'rgba(45,43,78,0.95)', borderWidth: 0,
                textStyle: { color: '#fff', fontFamily: 'Space Grotesk' },
                formatter: (p) => '<b>' + p.name + '</b><br/>' + fmtMoneyFull(p.value)
            },
            series: [{
                type: 'treemap', data: tree || [],
                roam: false,
                breadcrumb: { show: true, itemStyle: { color: '#4A4782', textStyle: { color: '#fff', fontFamily: 'Space Grotesk' } } },
                levels: [
                    { itemStyle: { borderColor: '#fff', borderWidth: 2, gapWidth: 2 }, upperLabel: { show: false } },
                    { itemStyle: { borderColor: '#fff', borderWidth: 1, gapWidth: 1 },
                      colorSaturation: [0.35, 0.65],
                      label: { show: true, fontFamily: 'Space Grotesk', fontSize: 11, color: '#fff', formatter: (p) => p.name } }
                ],
                animationDuration: 900
            }]
        }, true);
    }

    function renderPareto(refs) {
        const el = document.getElementById('g00-chart-pareto');
        if (!charts.pareto) charts.pareto = echarts.init(el);
        const top = (refs || []).slice(0, 30);
        const labels = top.map(r => r.ref);
        const vals = top.map(r => r.valor);
        const total = vals.reduce((a, b) => a + b, 0) || 1;
        let acc = 0;
        const cum = vals.map(v => { acc += v; return (acc / total) * 100; });
        charts.pareto.setOption({
            color: ['#4A4782', '#ff001e'],
            tooltip: { trigger: 'axis',
                backgroundColor: 'rgba(45,43,78,0.95)', borderWidth: 0,
                textStyle: { color: '#fff', fontFamily: 'Space Grotesk' },
                formatter: (ps) => {
                    let h = '<b>' + ps[0].axisValue + '</b><br/>';
                    h += ps[0].marker + ' Ventas: <b>' + fmtMoneyFull(ps[0].value) + '</b><br/>';
                    if (ps[1]) h += ps[1].marker + ' Acumulado: <b>' + ps[1].value.toFixed(1) + '%</b>';
                    return h;
                }
            },
            legend: { data: ['Ventas', '% Acumulado'], top: 0, right: 10, textStyle: { fontFamily: 'Space Grotesk', fontSize: 11 } },
            grid: { left: 60, right: 55, top: 32, bottom: 40 },
            xAxis: { type: 'category', data: labels, axisLine: { lineStyle: { color: '#e0dfe8' } },
                axisLabel: { color: '#7b7894', fontFamily: 'Space Grotesk', fontSize: 9, rotate: 50 } },
            yAxis: [
                { type: 'value', axisLine: { show: false }, splitLine: { lineStyle: { color: '#f0eff5' } },
                  axisLabel: { color: '#7b7894', fontFamily: 'Space Grotesk', fontSize: 10, formatter: fmtMoney } },
                { type: 'value', max: 100, axisLine: { show: false }, splitLine: { show: false },
                  axisLabel: { color: '#7b7894', fontFamily: 'Space Grotesk', fontSize: 10, formatter: '{value}%' } }
            ],
            series: [
                { name: 'Ventas', type: 'bar', data: vals, barWidth: 12, itemStyle: { borderRadius: [4,4,0,0] },
                  animationDuration: 900, animationDelay: (i) => i * 25 },
                { name: '% Acumulado', type: 'line', yAxisIndex: 1, data: cum, smooth: true,
                  symbol: 'circle', symbolSize: 6, lineStyle: { width: 2 },
                  markLine: { silent: true, symbol: 'none', data: [{ yAxis: 80, lineStyle: { color: '#ff001e', type: 'dashed' }, label: { formatter: '80%', color: '#ff001e' } }] },
                  animationDuration: 1200 }
            ]
        }, true);
    }

    function renderTablaProductos(refs) {
        const tbody = document.querySelector('#g00-tabla-productos tbody');
        const top = (refs || []).slice(0, 20);
        if (top.length === 0) { tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-light);padding:20px;">Sin datos</td></tr>'; return; }
        tbody.innerHTML = top.map(r => {
            return '<tr>'
                + '<td><strong>' + esc(r.ref) + '</strong><br/><span style="font-size:10px;color:var(--text-light);">' + esc(r.linea) + '</span></td>'
                + '<td>' + esc(r.marca) + '</td>'
                + '<td style="text-align:right;">' + fmtInt(r.ups) + '</td>'
                + '<td style="text-align:right;font-weight:600;">' + fmtMoney(r.valor) + '</td>'
                + '<td style="text-align:right;">' + (r.margen ? r.margen.toFixed(2) + '%' : '—') + '</td>'
                + '</tr>';
        }).join('');
    }

    function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }

    // ============ DISPATCHER ============
    function loadCurrentTab() {
        if (currentTab === 'tiendas')        loadTiendas();
        else if (currentTab === 'periodos')  loadPeriodos();
        else if (currentTab === 'productos') loadProductos();
        else                                 loadDetal();
    }

    window.g00Load = function () {
        // Filtros cambiaron: invalida todos, recarga el actual, los demás se recargarán al visitarse
        Object.keys(tabState).forEach(k => tabState[k] = false);
        loadCurrentTab();
    };

    window.g00OnEnter = function () {
        if (!tabState.detal) loadDetal();
        else Object.values(charts).forEach(c => c && c.resize());
    };

    window.g00ShowTab = function (name, el) {
        document.querySelectorAll('#page-informes-g00 .tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('#page-informes-g00 .g00-tab-panel').forEach(p => p.classList.remove('active'));
        if (el) el.classList.add('active');
        document.getElementById('g00-panel-' + name).classList.add('active');
        currentTab = name;
        if (!tabState[name]) {
            loadCurrentTab();
        } else {
            setTimeout(() => Object.values(charts).forEach(c => c && c.resize()), 50);
        }
    };

    window.g00Export = function () { alert('Export a Excel/PDF: pendiente.'); };

    window.addEventListener('resize', () => Object.values(charts).forEach(c => c && c.resize()));
})();
</script>
