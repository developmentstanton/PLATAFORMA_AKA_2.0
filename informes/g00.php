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
        display:flex; flex-direction:column; gap:10px;
        background: white; border: 1px solid var(--border); border-radius: 10px;
        padding: 12px 16px; margin-bottom: 18px;
    }
    .g00-filter-row { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end;
        padding-bottom:10px; border-bottom:1px solid var(--border); }
    .g00-filter-row:last-child { border-bottom:none; padding-bottom:0; }
    .g00-md { display:flex; gap:4px; }
    .g00-md select { min-width:56px; }
    .g00-filters .filter-group { display: flex; align-items: center; gap: 6px; }
    /* Solo la barra nueva (4 filas) apila label sobre control; O14 reusa .g00-filters y conserva su layout. */
    .g00-filters .g00-filter-row .filter-group { flex-direction: column; align-items: flex-start; gap: 4px; }
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

    /* Botones segmentados (Calendario / S.S.S) */
    .g00-seg { display:inline-flex; border:1px solid var(--border); border-radius:6px; overflow:hidden; }
    .g00-seg-btn { border:none; background:white; color:var(--text); font-family:'Space Grotesk',sans-serif;
        font-size:12px; padding:6px 12px; cursor:pointer; }
    .g00-seg-btn.active { background:var(--primary); color:white; font-weight:600; }
    /* Tom Select compacto acorde a la barra */
    .g00-filters .ts-control { min-width:150px; font-size:12px; border-radius:6px; border-color:var(--border); }
    .g00-filters .ts-wrapper { min-width:150px; }
    /* Tablas comparativas tipo Power BI */
    table.disp-table th, table.disp-table td { white-space: nowrap; font-size: 12px; }
    table.disp-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
    table.disp-table tr.g00-total td { background: #fdf6e3; font-weight: 700; }
    table.disp-table tr.g00-tipo td:first-child { padding-left: 26px; color: var(--text-light); font-weight: 500; }
    table.disp-table tr.g00-marca-row { cursor: pointer; }
    table.disp-table tr.g00-marca-row:hover td { background: #faf9fe; }
    .g00-caret { display: inline-block; width: 14px; color: var(--text-light); font-size: 10px; }
    table.disp-table .pos { color: var(--success); }
    table.disp-table .neg { color: var(--danger); }
    /* Tabla de Negocio: scroll interno (~30 filas visibles) con cabecera fija */
    .g00-scroll-neg { max-height: 720px; overflow-y: auto; }
    .g00-scroll-neg table.disp-table thead th { position: sticky; top: 0; background: #fff; z-index: 1; box-shadow: inset 0 -1px 0 var(--border); }
    /* Preview flotante de foto del zapato (hover en filas de Negocio) */
    #g00-img-pop { position: fixed; display: none; z-index: 9999; pointer-events: none;
        background: #fff; border: 1px solid var(--border); border-radius: 8px;
        box-shadow: 0 6px 20px rgba(45,43,78,0.25); padding: 4px; }
    #g00-img-pop img { max-width: 260px; max-height: 320px; width: auto; height: auto; display: block; border-radius: 4px; }

    /* Botón exportar a Excel (encabezado de cada tabla) */
    .g00-btn-export { float: right; font-family: 'Space Grotesk', sans-serif; font-size: 11px;
        border: 1px solid var(--border); background: #fff; color: var(--primary); cursor: pointer;
        border-radius: 6px; padding: 3px 9px; font-weight: 600; line-height: 1.4; }
    .g00-btn-export:hover { background: var(--primary); color: #fff; }

    /* Compactación + fuente reducida del informe G00 (acotado a #page-informes-g00, no afecta O14) */
    #page-informes-g00 { font-size: 13px; }
    #page-informes-g00 .g00-filters { padding: 8px 12px; gap: 6px; margin-bottom: 10px; }
    #page-informes-g00 .g00-filter-row { padding-bottom: 6px; }
    #page-informes-g00 .card { margin-bottom: 10px; }
    #page-informes-g00 .stats-grid { margin-bottom: 14px; }
    #page-informes-g00 .g00-kpi-value { font-size: 22px; }
    #page-informes-g00 .card-title { font-size: 13px; }
    #page-informes-g00 table.disp-table th,
    #page-informes-g00 table.disp-table td { font-size: 11px; }
</style>

<div class="page" id="page-informes-g00">


    <!-- ============ FILTROS ============ -->
    <?php $MESES_FILTRO = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']; ?>
    <div class="g00-filters">
        <!-- Fila 1: tiempo -->
        <div class="g00-filter-row">
            <div class="filter-group">
                <label>Desde</label>
                <div class="g00-md">
                    <select id="g00-desde-mes"><?php for($m=1;$m<=12;$m++) printf('<option value="%02d"%s>%s</option>',$m,$m==1?' selected':'',$MESES_FILTRO[$m-1]); ?></select>
                    <select id="g00-desde-dia"><?php for($d=1;$d<=31;$d++) printf('<option value="%02d"%s>%02d</option>',$d,$d==1?' selected':'',$d); ?></select>
                </div>
            </div>
            <div class="filter-group">
                <label>Hasta</label>
                <div class="g00-md">
                    <select id="g00-hasta-mes"><?php $cm=(int)date('n'); for($m=1;$m<=12;$m++) printf('<option value="%02d"%s>%s</option>',$m,$m==$cm?' selected':'',$MESES_FILTRO[$m-1]); ?></select>
                    <select id="g00-hasta-dia"><?php $cd=(int)date('j'); for($d=1;$d<=31;$d++) printf('<option value="%02d"%s>%02d</option>',$d,$d==$cd?' selected':'',$d); ?></select>
                </div>
            </div>
            <div class="filter-group">
                <label>Calendario</label>
                <div class="g00-seg" id="g00-cal">
                    <button type="button" class="g00-seg-btn active" data-val="diaadia">Día a Día</button>
                    <button type="button" class="g00-seg-btn" data-val="retail">Retail</button>
                </div>
            </div>
            <div class="filter-group">
                <label>S.S.S</label>
                <div class="g00-seg" id="g00-sss">
                    <button type="button" class="g00-seg-btn" data-val="nosame">No Same</button>
                    <button type="button" class="g00-seg-btn" data-val="same">Same</button>
                </div>
            </div>
            <div style="margin-left:auto;align-self:flex-end;">
                <button class="g00-btn-refresh" onclick="g00Load()"><i class="fa-solid fa-filter"></i> Aplicar</button>
            </div>
        </div>
        <!-- Fila 2: criterios de referencia -->
        <div class="g00-filter-row">
            <div class="filter-group"><label>Marca</label><select id="g00-f-marca" multiple></select></div>
            <div class="filter-group"><label>Tipo</label><select id="g00-f-tipo" multiple></select></div>
            <div class="filter-group"><label>Categoría</label><select id="g00-f-categoria" multiple></select></div>
            <div class="filter-group"><label>Subcategoría</label><select id="g00-f-subcategoria" multiple></select></div>
            <div class="filter-group"><label>Género</label><select id="g00-f-genero" multiple></select></div>
            <div class="filter-group"><label>Público</label><select id="g00-f-publico" multiple></select></div>
            <div class="filter-group"><label>Referencia</label><select id="g00-f-referencia" multiple></select></div>
        </div>
        <!-- Fila 3: color / talla -->
        <div class="g00-filter-row">
            <div class="filter-group"><label>Color</label><select id="g00-f-color" multiple></select></div>
            <div class="filter-group"><label>Talla</label><select id="g00-f-talla" multiple></select></div>
        </div>
        <!-- Fila 4: criterios de bodega -->
        <div class="g00-filter-row">
            <div class="filter-group"><label>Grupo</label><select id="g00-f-grupo" multiple></select></div>
            <div class="filter-group"><label>Tienda</label><select id="g00-f-tienda" multiple></select></div>
            <div class="filter-group"><label>Centro comercial</label><select id="g00-f-centro_comercial" multiple></select></div>
            <div class="filter-group"><label>Departamento</label><select id="g00-f-depto" multiple></select></div>
            <div class="filter-group"><label>Ciudad</label><select id="g00-f-ciudad" multiple></select></div>
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

        <!-- Franja de 3 KPIs (estilo Power BI) -->
        <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">
            <div class="g00-kpi accent" title="bodegas con venta">
                <div class="g00-kpi-head"><span class="g00-kpi-label">Tiendas <span id="g00-kpi-anio-1">—</span></span></div>
                <div class="g00-kpi-value" id="g00-kpi-tiendas"><span class="g00-skeleton" style="width:60px;height:26px;"></span></div>
            </div>
            <div class="g00-kpi success" title="$ por par (valor ÷ unidades)">
                <div class="g00-kpi-head"><span class="g00-kpi-label">$ Prom <span id="g00-kpi-anio-2">—</span></span></div>
                <div class="g00-kpi-value" id="g00-kpi-ticket"><span class="g00-skeleton" style="width:100px;height:26px;"></span></div>
            </div>
            <div class="g00-kpi warning" title="margen %">
                <div class="g00-kpi-head"><span class="g00-kpi-label">MB</span></div>
                <div class="g00-kpi-value" id="g00-kpi-margen"><span class="g00-skeleton" style="width:70px;height:26px;"></span></div>
            </div>
        </div>

        <!-- Tabla 1: Por Grupo Tiendas -->
        <div class="card">
            <div class="card-title">Resumen Ventas Por Grupo Tiendas<button class="g00-btn-export" onclick="g00ExpGrupo()">⤓ Excel</button></div>
            <div style="overflow-x:auto;">
                <table id="g00-tabla-grupo" class="disp-table"></table>
            </div>
        </div>

        <!-- Tabla 2: Por Marca / Tipo -->
        <div class="card">
            <div class="card-title">Resumen Ventas Por Marca / Tipo<button class="g00-btn-export" onclick="g00ExpMarca()">⤓ Excel</button></div>
            <div style="overflow-x:auto;">
                <table id="g00-tabla-marca" class="disp-table"></table>
            </div>
        </div>

        <!-- Tabla 3: Mensual -->
        <div class="card">
            <div class="card-title">Resumen Ventas Mensual<button class="g00-btn-export" onclick="g00ExpMensual()">⤓ Excel</button></div>
            <div style="overflow-x:auto;">
                <table id="g00-tabla-mensual" class="disp-table"></table>
            </div>
        </div>

    </div>

    <!-- ============ TAB DETALLE TIENDAS ============ -->
    <div class="g00-tab-panel" id="g00-panel-tiendas">
        <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">
            <div class="g00-kpi accent" title="bodegas con venta">
                <div class="g00-kpi-head"><span class="g00-kpi-label">Tiendas <span id="g00t-kpi-anio-1">—</span></span></div>
                <div class="g00-kpi-value" id="g00t-kpi-tiendas"><span class="g00-skeleton" style="width:60px;height:26px;"></span></div>
            </div>
            <div class="g00-kpi success" title="$ por par (valor ÷ unidades)">
                <div class="g00-kpi-head"><span class="g00-kpi-label">$ Prom <span id="g00t-kpi-anio-2">—</span></span></div>
                <div class="g00-kpi-value" id="g00t-kpi-ticket"><span class="g00-skeleton" style="width:100px;height:26px;"></span></div>
            </div>
            <div class="g00-kpi warning" title="margen %">
                <div class="g00-kpi-head"><span class="g00-kpi-label">MB</span></div>
                <div class="g00-kpi-value" id="g00t-kpi-margen"><span class="g00-skeleton" style="width:70px;height:26px;"></span></div>
            </div>
        </div>
        <div class="card">
            <div class="card-title">Resumen Ventas Por Tienda <span style="color:var(--text-light);font-weight:500;">&mdash; clic en la tienda para ver Referencia-Color</span><button class="g00-btn-export" onclick="g00ExpTienda()">⤓ Excel</button></div>
            <div style="overflow-x:auto;">
                <table id="g00-tabla-tienda" class="disp-table"></table>
            </div>
        </div>
    </div>

    <!-- ============ TAB VENTAS POR PERIODOS ============ -->
    <div class="g00-tab-panel" id="g00-panel-periodos">
        <div class="card">
            <div class="card-title">Ventas Por Periodos <span style="color:var(--text-light);font-weight:500;">&mdash; clic para desglosar Semestre → Trimestre → Mes → Día</span><button class="g00-btn-export" onclick="g00ExpPeriodos()">⤓ Excel</button></div>
            <div style="overflow-x:auto;">
                <table id="g00-tabla-periodos" class="disp-table"></table>
            </div>
        </div>
    </div>

    <!-- ============ TAB VENTAS POR PRODUCTOS ============ -->
    <div class="g00-tab-panel" id="g00-panel-productos">
        <div class="card">
            <div class="card-title">Resumen Ventas Por Negocio <span style="color:var(--text-light);font-weight:500;">&mdash; clic en el negocio (Ref-Color) para ver tallas</span><button class="g00-btn-export" onclick="g00ExpNegocio()">⤓ Excel</button></div>
            <div class="g00-scroll-neg">
                <table id="g00-tabla-negocio" class="disp-table"></table>
            </div>
        </div>
        <div class="card">
            <div class="card-title">Resumen Ventas Por Categoría <span style="color:var(--text-light);font-weight:500;">&mdash; clic para ver subcategoría</span><button class="g00-btn-export" onclick="g00ExpCategoria()">⤓ Excel</button></div>
            <div style="overflow-x:auto;">
                <table id="g00-tabla-categoria" class="disp-table"></table>
            </div>
        </div>
        <div class="card">
            <div class="card-title">Resumen Ventas Por Género <span style="color:var(--text-light);font-weight:500;">&mdash; clic para ver público objetivo</span><button class="g00-btn-export" onclick="g00ExpGenero()">⤓ Excel</button></div>
            <div style="overflow-x:auto;">
                <table id="g00-tabla-genero" class="disp-table"></table>
            </div>
        </div>
    </div>

</div>

<script>
(function () {
    'use strict';

    let currentTab = 'detal';
    let proveedorActual = (window.PROVEEDOR_ACTUAL || '');
    const tabState = { detal: false, tiendas: false, periodos: false, productos: false };

    const REF_FIELDS    = ['marca','tipo','categoria','subcategoria','genero','publico','referencia'];
    const SKU_FIELDS    = ['color','talla'];
    const BODEGA_FIELDS = ['grupo','tienda','centro_comercial','depto','ciudad'];
    const COMBO_FIELDS  = [...REF_FIELDS, ...BODEGA_FIELDS];   // campos presentes en una fila de `combos`
    const FILTER_FIELDS = [...REF_FIELDS, ...SKU_FIELDS, ...BODEGA_FIELDS];
    const tom = {};            // field -> instancia TomSelect
    let lastDetal = null, lastTiendas = null, lastPeriodos = null, lastProductos = null;
    let combos = [];           // catálogo (ref + bodega) del proveedor
    let sku    = [];           // {referencia, color, talla} del maestro
    let cascadeBusy = false;   // evita recursión al actualizar opciones

    function segValue(id) {
        const el = document.querySelector('#' + id + ' .g00-seg-btn.active');
        return el ? el.getAttribute('data-val') : '';
    }
    function initSeg(id) {
        document.querySelectorAll('#' + id + ' .g00-seg-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('#' + id + ' .g00-seg-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                g00Load();
            });
        });
    }
    // Variante toggle para S.S.S: se puede dejar ninguno activo; clic en el activo lo apaga;
    // los botones del grupo son mutuamente excluyentes.
    function initSegToggle(id) {
        document.querySelectorAll('#' + id + ' .g00-seg-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const wasActive = btn.classList.contains('active');
                document.querySelectorAll('#' + id + ' .g00-seg-btn').forEach(b => b.classList.remove('active'));
                if (!wasActive) btn.classList.add('active');
                g00Load();
            });
        });
    }

    function selectedOf(field) { return tom[field] ? tom[field].getValue() : []; }

    // referencias permitidas por las selecciones de color/talla (null = sin restricción)
    function refsAllowedBySku() {
        const selC = selectedOf('color'), selT = selectedOf('talla');
        if (!selC.length && !selT.length) return null;
        const cS = new Set(selC), tS = new Set(selT), out = new Set();
        for (const s of sku) {
            if (selC.length && !cS.has(s.color)) continue;
            if (selT.length && !tS.has(s.talla)) continue;
            out.add(s.referencia);
        }
        return out;
    }

    // ¿la fila combos cumple las selecciones de campos combos (ref+bodega) excepto 'exclude'?
    function comboMatches(c, exclude) {
        for (const f of COMBO_FIELDS) {
            if (f === exclude) continue;
            const sel = selectedOf(f);
            if (sel.length && !sel.includes(c[f])) return false;
        }
        return true;
    }

    // referencias activas según selecciones combos (ref+bodega), ignorando 'exclude'
    function activeRefs(exclude) {
        const out = new Set();
        for (const c of combos) if (comboMatches(c, exclude)) out.add(c.referencia);
        return out;
    }

    // opciones para un campo combos (ref o bodega): bidireccional + acotado por color/talla
    function availableCombo(field) {
        const skuRefs = refsAllowedBySku();
        const out = new Set();
        for (const c of combos) {
            if (skuRefs && !skuRefs.has(c.referencia)) continue;
            if (!comboMatches(c, field)) continue;
            if (c[field] !== '' && c[field] != null) out.add(c[field]);
        }
        return Array.from(out).sort((a, b) => a.localeCompare(b, 'es'));
    }

    // opciones para color/talla: acotado por las refs activas (ref+bodega) y el otro sku-field
    function availableSku(field) {
        const other = field === 'color' ? 'talla' : 'color';
        const refs = activeRefs(null);
        const oSel = selectedOf(other), oS = new Set(oSel);
        const out = new Set();
        for (const s of sku) {
            if (!refs.has(s.referencia)) continue;
            if (oSel.length && !oS.has(s[other])) continue;
            if (s[field] !== '' && s[field] != null) out.add(s[field]);
        }
        return Array.from(out).sort((a, b) => a.localeCompare(b, 'es'));
    }

    function availableFor(field) {
        return SKU_FIELDS.includes(field) ? availableSku(field) : availableCombo(field);
    }

    function refreshOptions() {
        if (cascadeBusy) return;
        cascadeBusy = true;
        FILTER_FIELDS.forEach(field => {
            const ts = tom[field]; if (!ts) return;
            const keep = ts.getValue();
            const opts = availableFor(field).map(v => ({ value: v, text: v }));
            // conservar seleccionados aunque la cascada los excluya (para poder deseleccionar)
            const keepArr = Array.isArray(keep) ? keep : (keep ? [keep] : []);
            keepArr.forEach(v => { if (!opts.find(o => o.value === v)) opts.push({ value: v, text: v }); });
            ts.clearOptions();
            ts.addOptions(opts);
            ts.refreshOptions(false);
        });
        cascadeBusy = false;
    }

    function initFiltros() {
        FILTER_FIELDS.forEach(field => {
            tom[field] = new TomSelect('#g00-f-' + field, {
                plugins: ['remove_button'],
                maxOptions: 1000,
                placeholder: 'Todas',
                onChange: () => { refreshOptions(); }
            });
        });
        initSeg('g00-cal');
        initSegToggle('g00-sss');
        fetch('api/informe_g00.php?tab=filtros', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                combos = (data && data.combos) ? data.combos : [];
                sku    = (data && data.sku)    ? data.sku    : [];
                refreshOptions();
            })
            .catch(() => { combos = []; sku = []; });
    }

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
        treemapTiendas: null,
        calendar: null, dow: null, tendencia: null,
    };

    const DIAS_SEM = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    const MESES_ES = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    function fmtFechaLarga(iso) {
        if (!iso) return '';
        const [y, m, d] = iso.split('-').map(Number);
        const dt = new Date(y, m - 1, d);   // fecha local, sin corrimiento por UTC
        return DIAS_SEM[dt.getDay()] + ' ' + String(d).padStart(2, '0') + ' de ' + MESES_ES[m - 1] + ' de ' + y;
    }
    // Tabla 2x3 del topbar: encabezados Desde/Hasta, fila año actual, fila año anterior.
    function renderTopbarDates(r) {
        const f = (iso) => iso ? fmtFechaLarga(iso) : '…';
        const ra = r || {};
        return '<table>'
            + '<tr><th>Desde</th><th>Hasta</th></tr>'
            + '<tr><td>' + f(ra.desde_actual) + '</td><td>' + f(ra.hasta_actual) + '</td></tr>'
            + '<tr><td class="ant">' + f(ra.desde_anterior) + '</td><td class="ant">' + f(ra.hasta_anterior) + '</td></tr>'
            + '</table>';
    }

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

    function renderKpis(k, anio, pfx) {
        pfx = pfx || 'g00-kpi-';
        document.getElementById(pfx+'anio-1').textContent = anio;
        document.getElementById(pfx+'anio-2').textContent = anio;
        animate(document.getElementById(pfx+'tiendas'), k.tiendas_actual, (n) => Math.round(n).toString());
        animate(document.getElementById(pfx+'ticket'),  k.ticket_prom,    fmtMoneyFull);
        animate(document.getElementById(pfx+'margen'),  k.margen_prom,    (n) => n.toFixed(2) + '%');
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

    function dateVal(prefix) { // prefix = 'desde' | 'hasta'
        const m = document.getElementById('g00-' + prefix + '-mes').value;
        const d = document.getElementById('g00-' + prefix + '-dia').value;
        if (!m || !d) return '';
        return new Date().getFullYear() + '-' + m + '-' + d;
    }

    function buildParams(tab) {
        const p = new URLSearchParams();
        if (tab) p.append('tab', tab);
        const d = dateVal('desde'), h = dateVal('hasta');
        if (d) p.append('desde', d);
        if (h) p.append('hasta', h);
        FILTER_FIELDS.forEach(field => {
            (selectedOf(field) || []).forEach(v => p.append(field + '[]', v));
        });
        p.append('cal', segValue('g00-cal') || 'diaadia');
        p.append('sss', segValue('g00-sss') || 'nosame');
        return p.toString();
    }

    // ============ LOAD: DETAL ============
    function loadDetal() {
        showLoading('Cargando dashboard');
        fetch('api/informe_g00.php?' + buildParams(), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { hideLoading(); showError(data.error || 'Error cargando datos'); return; }
                lastDetal = data;
                const host = document.getElementById('page-informes-g00');
                const banner = host.querySelector('.g00-error'); if (banner) banner.remove();
                proveedorActual = data.proveedor || '';
                document.getElementById('pageTitle').textContent = 'DASHBOARD DE VENTAS - ' + (data.proveedor || '—');
                document.getElementById('topbarDates').innerHTML = renderTopbarDates(data.rango || {});
                renderKpis(data.kpis, data.anio);
                renderTablaGrupo(data.por_grupo, data.anio);
                renderTablaMarcaTipo(data.por_marca, data.anio, data.kpis);
                renderTablaMensual(data.mensual, data.anio, data.mensual_tdas);
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
                lastTiendas = data;
                renderKpis(data.kpis, data.anio, 'g00t-kpi-');
                renderTablaTienda(data.tiendas, data.anio);
                tabState.tiendas = true;
                hideLoading();
            })
            .catch(err => { hideLoading(); showError('No se pudo cargar tiendas: ' + err.message); });
    }

    // Tabla "Resumen Ventas Por Tienda": fila tienda (colapsable) → negocios REF-COLOR. Columnas como "Por Marca".
    function renderTablaTienda(tiendas, anio) {
        const a = anio, b = anio - 1;
        let h = '<thead><tr>'
            + '<th>Tienda / Negocio</th>'
            + '<th class="num">'+b+'</th><th class="num">'+a+'</th><th class="num">Dif Q</th><th class="num">%Q</th>'
            + '<th class="num">$'+b+'</th><th class="num">$'+a+'</th><th class="num">Dif $</th><th class="num">%$</th>'
            + '<th class="num">MB</th><th class="num">$Prom '+b+'</th><th class="num">$Prom '+a+'</th>'
            + '</tr></thead><tbody>';
        if (!tiendas || !tiendas.length) {
            h += '<tr><td colspan="12" style="text-align:center;color:var(--text-light);padding:20px;">Sin datos</td></tr></tbody>';
            document.getElementById('g00-tabla-tienda').innerHTML = h; return;
        }
        const tot = {ua:0,ub:0,va:0,vb:0};
        tiendas.forEach((t, i) => {
            tot.ua+=t.ups_act; tot.ub+=t.ups_ant; tot.va+=t.val_act; tot.vb+=t.val_ant;
            const kids = (t.children||[]);
            h += rowTienda(t, false, false, {idx:i, hasChildren:kids.length});
            kids.forEach(k => { h += rowTienda(k, false, true, {parent:i}); });
        });
        const margenes = tiendas.map(t=>t.margen).filter(x=>x>0);
        const mbTot = margenes.length ? margenes.reduce((x,y)=>x+y,0)/margenes.length : 0;
        h += rowTienda({nombre:'Total',ups_act:tot.ua,ups_ant:tot.ub,val_act:tot.va,val_ant:tot.vb,margen:mbTot}, true, false);
        h += '</tbody>';
        document.getElementById('g00-tabla-tienda').innerHTML = h;
    }
    function rowTienda(r, isTotal, isChild, opts) {
        opts = opts || {};
        const pa = prom(r.val_act, r.ups_act), pb = prom(r.val_ant, r.ups_ant);
        const cls = isTotal ? 'g00-total' : (isChild ? 'g00-tipo' : '');
        const label = isChild ? r.negocio : (r.nombre || r.cod || '');
        let trOpen, labelCell;
        if (opts.parent != null) {                 // negocio (ref-color): oculto por defecto
            trOpen = '<tr class="'+cls+'" data-tparent="'+opts.parent+'" style="display:none">';
            labelCell = '<td>'+esc(label)+'</td>';
        } else if (opts.hasChildren) {             // tienda con negocios: arranca colapsada
            trOpen = '<tr class="'+cls+' g00-marca-row g00-collapsed" data-tienda="'+opts.idx+'" onclick="g00ToggleTienda('+opts.idx+',this)">';
            labelCell = '<td><span class="g00-caret">▸</span>'+esc(label)+'</td>';
        } else {                                   // total o tienda sin negocios
            trOpen = '<tr class="'+cls+'">';
            labelCell = '<td>'+esc(label)+'</td>';
        }
        return trOpen
            + labelCell
            + '<td class="num">'+fmtInt(r.ups_ant)+'</td><td class="num">'+fmtInt(r.ups_act)+'</td>'
            + difCell(r.ups_act, r.ups_ant, fmtInt) + pctCell(r.ups_act, r.ups_ant)
            + '<td class="num">'+fmtMoneyFull(r.val_ant)+'</td><td class="num">'+fmtMoneyFull(r.val_act)+'</td>'
            + difCell(r.val_act, r.val_ant, fmtMoneyFull) + pctCell(r.val_act, r.val_ant)
            + '<td class="num">'+(r.margen?r.margen.toFixed(2)+'%':'—')+'</td>'
            + '<td class="num">'+fmtMoneyFull(pb)+'</td><td class="num">'+fmtMoneyFull(pa)+'</td>'
            + '</tr>';
    }
    window.g00ToggleTienda = function (idx, el) {
        const collapsed = el.classList.toggle('g00-collapsed');
        document.querySelectorAll('#g00-tabla-tienda tr[data-tparent="'+idx+'"]')
            .forEach(r => { r.style.display = collapsed ? 'none' : ''; });
        const caret = el.querySelector('.g00-caret');
        if (caret) caret.textContent = collapsed ? '▸' : '▾';
    };

    // ============ LOAD: PERIODOS ============
    function loadPeriodos() {
        showLoading('Cargando periodos');
        fetch('api/informe_g00.php?' + buildParams('periodos'), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { hideLoading(); showError(data.error || 'Error cargando periodos'); return; }
                lastPeriodos = data;
                renderTablaPeriodos(buildArbolPeriodos(data.dias), data.anio);
                tabState.periodos = true;
                hideLoading();
            })
            .catch(err => { hideLoading(); showError('No se pudo cargar periodos: ' + err.message); });
    }

    // Arma el árbol Semestre→Trimestre→Mes→Día desde dias[] (cada día con act/ant, uds y $).
    function buildArbolPeriodos(dias) {
        const blank = () => ({va:0,vb:0,ua:0,ub:0});
        const add = (o,d) => { o.va+=d.val_act; o.vb+=d.val_ant; o.ua+=d.ups_act; o.ub+=d.ups_ant; };
        const sems = {}; const tot = blank();
        (dias||[]).forEach(d => {
            const sem = d.mes <= 6 ? 1 : 2, tri = Math.ceil(d.mes/3);
            sems[sem] = sems[sem] || {m:blank(), tris:{}};
            add(sems[sem].m, d);
            const S = sems[sem];
            S.tris[tri] = S.tris[tri] || {m:blank(), meses:{}};
            add(S.tris[tri].m, d);
            const T = S.tris[tri];
            T.meses[d.mes] = T.meses[d.mes] || {m:blank(), dias:{}};
            add(T.meses[d.mes].m, d);
            const M = T.meses[d.mes];
            M.dias[d.dia] = M.dias[d.dia] || {m:blank()};
            add(M.dias[d.dia].m, d);
            add(tot, d);
        });
        return {sems, tot};
    }

    const share = (x, d) => d > 0 ? (x / d * 100).toFixed(1) + '%' : '—';
    function rowPeriodo(id, pid, lvl, label, m, tot, hasChildren) {
        const pad = 'padding-left:' + (4 + (lvl-1)*18) + 'px;';
        const disp = lvl === 1 ? '' : 'display:none;';
        const cls = (lvl === 4 ? 'g00-tipo' : '') + (hasChildren ? ' g00-marca-row g00-collapsed' : '');
        const onclk = hasChildren ? ' onclick="g00TogglePeriodo(' + id + ',this)"' : '';
        const caret = hasChildren ? '<span class="g00-caret">▸</span>' : '';
        return '<tr class="' + cls.trim() + '" data-rid="' + id + '" data-pid="' + (pid==null?'':pid) + '" data-lvl="' + lvl + '" style="' + disp + '"' + onclk + '>'
            + '<td style="' + pad + '">' + caret + esc(label) + '</td>'
            + '<td class="num">' + fmtInt(m.ub) + '</td><td class="num">' + share(m.ub, tot.ub) + '</td>'
            + '<td class="num">' + fmtInt(m.ua) + '</td><td class="num">' + share(m.ua, tot.ua) + '</td>'
            + pctCell(m.ua, m.ub)
            + '<td class="num">' + fmtMoneyFull(m.vb) + '</td><td class="num">' + share(m.vb, tot.vb) + '</td>'
            + '<td class="num">' + fmtMoneyFull(m.va) + '</td><td class="num">' + share(m.va, tot.va) + '</td>'
            + pctCell(m.va, m.vb)
            + '</tr>';
    }
    function renderTablaPeriodos(arbol, anio) {
        const a = anio, b = anio - 1;
        let h = '<thead><tr>'
            + '<th>Periodo</th>'
            + '<th class="num">Cant '+b+'</th><th class="num">%'+b+'</th><th class="num">Cant '+a+'</th><th class="num">%'+a+'</th><th class="num">Var%</th>'
            + '<th class="num">$ '+b+'</th><th class="num">%'+b+'</th><th class="num">$ '+a+'</th><th class="num">%'+a+'</th><th class="num">Var%</th>'
            + '</tr></thead><tbody>';
        const T = arbol.tot;
        const semKeys = Object.keys(arbol.sems).map(Number).sort((x,y)=>x-y);
        if (!semKeys.length) {
            h += '<tr><td colspan="11" style="text-align:center;color:var(--text-light);padding:20px;">Sin datos</td></tr></tbody>';
            document.getElementById('g00-tabla-periodos').innerHTML = h; return;
        }
        let id = 0;
        semKeys.forEach(sem => {
            const S = arbol.sems[sem]; const sid = ++id;
            h += rowPeriodo(sid, null, 1, 'Semestre ' + sem, S.m, T, true);
            Object.keys(S.tris).map(Number).sort((x,y)=>x-y).forEach(tri => {
                const Tq = S.tris[tri]; const tid = ++id;
                h += rowPeriodo(tid, sid, 2, 'Trimestre ' + tri, Tq.m, T, true);
                Object.keys(Tq.meses).map(Number).sort((x,y)=>x-y).forEach(mes => {
                    const M = Tq.meses[mes]; const mid = ++id;
                    h += rowPeriodo(mid, tid, 3, MESES_ES[mes-1], M.m, T, true);
                    Object.keys(M.dias).map(Number).sort((x,y)=>x-y).forEach(dia => {
                        h += rowPeriodo(++id, mid, 4, MESES_ES[mes-1] + '-' + String(dia).padStart(2,'0'), M.dias[dia].m, T, false);
                    });
                });
            });
        });
        h += '<tr class="g00-total"><td>Total</td>'
            + '<td class="num">'+fmtInt(T.ub)+'</td><td class="num">100%</td><td class="num">'+fmtInt(T.ua)+'</td><td class="num">100%</td>'+pctCell(T.ua,T.ub)
            + '<td class="num">'+fmtMoneyFull(T.vb)+'</td><td class="num">100%</td><td class="num">'+fmtMoneyFull(T.va)+'</td><td class="num">100%</td>'+pctCell(T.va,T.vb)
            + '</tr></tbody>';
        document.getElementById('g00-tabla-periodos').innerHTML = h;
    }
    window.g00TogglePeriodo = function (id, el) {
        const collapsed = el.classList.toggle('g00-collapsed');
        const caret = el.querySelector('.g00-caret'); if (caret) caret.textContent = collapsed ? '▸' : '▾';
        const tbl = document.getElementById('g00-tabla-periodos');
        if (collapsed) {
            const hideKids = (pid) => {
                tbl.querySelectorAll('tr[data-pid="' + pid + '"]').forEach(r => {
                    r.style.display = 'none'; r.classList.add('g00-collapsed');
                    const c = r.querySelector('.g00-caret'); if (c) c.textContent = '▸';
                    hideKids(r.getAttribute('data-rid'));
                });
            };
            hideKids(id);
        } else {
            tbl.querySelectorAll('tr[data-pid="' + id + '"]').forEach(r => { r.style.display = ''; });
        }
    };

    // ============ LOAD: PRODUCTOS ============
    function loadProductos() {
        showLoading('Cargando productos');
        fetch('api/informe_g00.php?' + buildParams('productos'), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { hideLoading(); showError(data.error || 'Error cargando productos'); return; }
                lastProductos = data;
                renderTablaArbol('g00-tabla-negocio',   data.negocios,   data.anio, {col1:'Negocio / Talla',           prefix:'neg', imgHover:true});
                renderTablaArbol('g00-tabla-categoria', data.categorias, data.anio, {col1:'Categoría / Subcategoría',  prefix:'cat'});
                renderTablaArbol('g00-tabla-genero',    data.generos,    data.anio, {col1:'Género / Público objetivo', prefix:'gen'});
                tabState.productos = true;
                hideLoading();
            })
            .catch(err => { hideLoading(); showError('No se pudo cargar productos: ' + err.message); });
    }

    function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }

    // Métricas derivadas de una fila cruda {val_act,val_ant,ups_act,ups_ant,margen,tiendas_act,tiendas_ant}
    function difCell(act, ant, fmt) {
        const d = (act || 0) - (ant || 0);
        const cls = d >= 0 ? 'pos' : 'neg';
        return '<td class="num ' + cls + '">' + (d >= 0 ? '+' : '') + fmt(d) + '</td>';
    }
    function pctCell(act, ant) {
        if (!ant) return '<td class="num">—</td>';
        const p = ((act - ant) / ant) * 100;
        const cls = p >= 0 ? 'pos' : 'neg';
        return '<td class="num ' + cls + '">' + (p >= 0 ? '+' : '') + p.toFixed(1) + '%</td>';
    }
    const prom = (val, ups) => (ups > 0 ? val / ups : 0);

    function renderTablaGrupo(rows, anio) {
        const a = anio, b = anio - 1;
        let h = '<thead><tr>'
            + '<th>Grupo</th>'
            + '<th class="num">'+b+'</th><th class="num">'+a+'</th><th class="num">Dif Q</th><th class="num">%Q</th>'
            + '<th class="num">$'+b+'</th><th class="num">$'+a+'</th><th class="num">Dif $</th><th class="num">%$</th>'
            + '<th class="num">MB</th>'
            + '<th class="num">$Prom '+b+'</th><th class="num">$Prom '+a+'</th><th class="num">%Prom</th>'
            + '<th class="num">Tdas '+b+'</th><th class="num">Tdas '+a+'</th><th class="num">≠Tdas</th>'
            + '</tr></thead><tbody>';
        const tot = {ua:0,ub:0,va:0,vb:0,ta:0,tb:0};
        (rows||[]).forEach(r => {
            tot.ua+=r.ups_act; tot.ub+=r.ups_ant; tot.va+=r.val_act; tot.vb+=r.val_ant;
            h += rowGrupo(r, false);
        });
        // Fila Total (sumas; MB total = promedio simple de filas con dato — aprox. consistente)
        const margenes = (rows||[]).map(r=>r.margen).filter(m=>m>0);
        const mbTot = margenes.length ? margenes.reduce((x,y)=>x+y,0)/margenes.length : 0;
        h += rowGrupo({label:'Total', ups_act:tot.ua,ups_ant:tot.ub, val_act:tot.va,val_ant:tot.vb,
                       margen:mbTot, tiendas_act:0, tiendas_ant:0}, true);
        h += '</tbody>';
        document.getElementById('g00-tabla-grupo').innerHTML = h;
    }
    function rowGrupo(r, isTotal) {
        const pa = prom(r.val_act, r.ups_act), pb = prom(r.val_ant, r.ups_ant);
        const difT = (r.tiendas_act||0) - (r.tiendas_ant||0);
        return '<tr class="'+(isTotal?'g00-total':'')+'">'
            + '<td>'+esc(r.label)+'</td>'
            + '<td class="num">'+fmtInt(r.ups_ant)+'</td><td class="num">'+fmtInt(r.ups_act)+'</td>'
            + difCell(r.ups_act, r.ups_ant, fmtInt) + pctCell(r.ups_act, r.ups_ant)
            + '<td class="num">'+fmtMoneyFull(r.val_ant)+'</td><td class="num">'+fmtMoneyFull(r.val_act)+'</td>'
            + difCell(r.val_act, r.val_ant, fmtMoneyFull) + pctCell(r.val_act, r.val_ant)
            + '<td class="num">'+(r.margen?r.margen.toFixed(2)+'%':'—')+'</td>'
            + '<td class="num">'+fmtMoneyFull(pb)+'</td><td class="num">'+fmtMoneyFull(pa)+'</td>'
            + pctCell(pa, pb)
            + (isTotal
                ? '<td class="num">—</td><td class="num">—</td><td class="num">—</td>'
                : '<td class="num">'+fmtInt(r.tiendas_ant)+'</td><td class="num">'+fmtInt(r.tiendas_act)+'</td>'
                  + '<td class="num '+(difT>=0?'pos':'neg')+'">'+(difT>=0?'+':'')+fmtInt(difT)+'</td>')
            + '</tr>';
    }

    function renderTablaMarcaTipo(rows, anio, kpis) {
        const a = anio, b = anio - 1;
        let h = '<thead><tr>'
            + '<th>Marca / Tipo</th>'
            + '<th class="num">'+b+'</th><th class="num">'+a+'</th><th class="num">Dif Q</th><th class="num">%Q</th>'
            + '<th class="num">$'+b+'</th><th class="num">$'+a+'</th><th class="num">Dif $</th><th class="num">%$</th>'
            + '<th class="num">MB</th>'
            + '<th class="num">$Prom '+b+'</th><th class="num">$Prom '+a+'</th><th class="num">%Prom</th>'
            + '<th class="num">Tdas '+b+'</th><th class="num">Tdas '+a+'</th><th class="num">≠Tdas</th>'
            + '</tr></thead><tbody>';
        const tot = {ua:0,ub:0,va:0,vb:0};
        (rows||[]).forEach((m, i) => {
            tot.ua+=m.ups_act; tot.ub+=m.ups_ant; tot.va+=m.val_act; tot.vb+=m.val_ant;
            const kids = (m.children||[]);
            h += rowMarca(m, false, false, {idx:i, hasChildren:kids.length});
            kids.forEach(t => { h += rowMarca(t, false, true, {parent:i}); });
        });
        const margenes = (rows||[]).map(r=>r.margen).filter(x=>x>0);
        const mbTot = margenes.length ? margenes.reduce((x,y)=>x+y,0)/margenes.length : 0;
        h += rowMarca({label:'Total',ups_act:tot.ua,ups_ant:tot.ub,val_act:tot.va,val_ant:tot.vb,
                       margen:mbTot, tiendas_act:(kpis?kpis.tiendas_actual:0), tiendas_ant:(kpis?kpis.tiendas_anterior:0)}, true, false);
        h += '</tbody>';
        document.getElementById('g00-tabla-marca').innerHTML = h;
    }
    function rowMarca(r, isTotal, isTipo, opts) {
        opts = opts || {};
        const pa = prom(r.val_act, r.ups_act), pb = prom(r.val_ant, r.ups_ant);
        const difT = (r.tiendas_act||0) - (r.tiendas_ant||0);
        const cls = isTotal ? 'g00-total' : (isTipo ? 'g00-tipo' : '');
        let trOpen, labelCell;
        if (opts.parent != null) {                 // fila hija (tipo): oculta por defecto (colapsada)
            trOpen = '<tr class="'+cls+'" data-parent="'+opts.parent+'" style="display:none">';
            labelCell = '<td>'+esc(r.label)+'</td>';
        } else if (opts.hasChildren) {             // fila marca con hijos: arranca colapsada, clic despliega
            trOpen = '<tr class="'+cls+' g00-marca-row g00-collapsed" data-marca="'+opts.idx+'" onclick="g00ToggleMarca('+opts.idx+',this)">';
            labelCell = '<td><span class="g00-caret">▸</span>'+esc(r.label)+'</td>';
        } else {                                   // total o marca sin hijos
            trOpen = '<tr class="'+cls+'">';
            labelCell = '<td>'+esc(r.label)+'</td>';
        }
        return trOpen
            + labelCell
            + '<td class="num">'+fmtInt(r.ups_ant)+'</td><td class="num">'+fmtInt(r.ups_act)+'</td>'
            + difCell(r.ups_act, r.ups_ant, fmtInt) + pctCell(r.ups_act, r.ups_ant)
            + '<td class="num">'+fmtMoneyFull(r.val_ant)+'</td><td class="num">'+fmtMoneyFull(r.val_act)+'</td>'
            + difCell(r.val_act, r.val_ant, fmtMoneyFull) + pctCell(r.val_act, r.val_ant)
            + '<td class="num">'+(r.margen?r.margen.toFixed(2)+'%':'—')+'</td>'
            + '<td class="num">'+fmtMoneyFull(pb)+'</td><td class="num">'+fmtMoneyFull(pa)+'</td>'
            + pctCell(pa, pb)
            + '<td class="num">'+fmtInt(r.tiendas_ant)+'</td><td class="num">'+fmtInt(r.tiendas_act)+'</td>'
            + '<td class="num '+(difT>=0?'pos':'neg')+'">'+(difT>=0?'+':'')+fmtInt(difT)+'</td>'
            + '</tr>';
    }
    window.g00ToggleMarca = function (idx, el) {
        const collapsed = el.classList.toggle('g00-collapsed');
        document.querySelectorAll('#g00-tabla-marca tr[data-parent="'+idx+'"]')
            .forEach(r => { r.style.display = collapsed ? 'none' : ''; });
        const caret = el.querySelector('.g00-caret');
        if (caret) caret.textContent = collapsed ? '▸' : '▾';
    };

    function renderTablaMensual(rows, anio, tdas) {
        const a = anio, b = anio - 1;
        let h = '<thead><tr>'
            + '<th>Mes</th>'
            + '<th class="num">'+b+'</th><th class="num">'+a+'</th><th class="num">Dif Q</th><th class="num">%Q</th>'
            + '<th class="num">$'+b+'</th><th class="num">$'+a+'</th><th class="num">Dif $</th><th class="num">%$</th>'
            + '<th class="num">$Prom '+b+'</th><th class="num">$Prom '+a+'</th>'
            + '<th class="num">Tdas '+b+'</th><th class="num">Tdas '+a+'</th><th class="num">≠Tdas</th>'
            + '</tr></thead><tbody>';
        const tot = {ua:0,ub:0,va:0,vb:0};
        (rows||[]).forEach(r => {
            // Omitir meses totalmente vacíos (ambos años en 0)
            if (!r.val_act && !r.val_ant && !r.ups_act && !r.ups_ant) return;
            tot.ua+=r.ups_act; tot.ub+=r.ups_ant; tot.va+=r.val_act; tot.vb+=r.val_ant;
            h += rowMensual(r, false);
        });
        h += rowMensual({mes:'Total',ups_act:tot.ua,ups_ant:tot.ub,val_act:tot.va,val_ant:tot.vb,
                         tiendas_act:(tdas?tdas.act:0), tiendas_ant:(tdas?tdas.ant:0)}, true);
        h += '</tbody>';
        document.getElementById('g00-tabla-mensual').innerHTML = h;
    }
    function rowMensual(r, isTotal) {
        const pa = prom(r.val_act, r.ups_act), pb = prom(r.val_ant, r.ups_ant);
        const difT = (r.tiendas_act||0) - (r.tiendas_ant||0);
        return '<tr class="'+(isTotal?'g00-total':'')+'">'
            + '<td>'+esc(r.mes)+'</td>'
            + '<td class="num">'+fmtInt(r.ups_ant)+'</td><td class="num">'+fmtInt(r.ups_act)+'</td>'
            + difCell(r.ups_act, r.ups_ant, fmtInt) + pctCell(r.ups_act, r.ups_ant)
            + '<td class="num">'+fmtMoneyFull(r.val_ant)+'</td><td class="num">'+fmtMoneyFull(r.val_act)+'</td>'
            + difCell(r.val_act, r.val_ant, fmtMoneyFull) + pctCell(r.val_act, r.val_ant)
            + '<td class="num">'+fmtMoneyFull(pb)+'</td><td class="num">'+fmtMoneyFull(pa)+'</td>'
            + '<td class="num">'+fmtInt(r.tiendas_ant)+'</td><td class="num">'+fmtInt(r.tiendas_act)+'</td>'
            + '<td class="num '+(difT>=0?'pos':'neg')+'">'+(difT>=0?'+':'')+fmtInt(difT)+'</td>'
            + '</tr>';
    }
    // ===== Tablas árbol de la pestaña Productos (Negocio/Categoría/Género) =====
    // data = {rows:[{label,...,children:[]}], total:{...}}; opts={col1, prefix}. 16 cols (= Por Grupo).
    function renderTablaArbol(tbodyId, data, anio, opts) {
        const a = anio, b = anio - 1;
        const rows = (data && data.rows) || [];
        const total = (data && data.total) || null;
        let h = '<thead><tr>'
            + '<th>'+esc(opts.col1)+'</th>'
            + '<th class="num">'+b+'</th><th class="num">'+a+'</th><th class="num">Dif Q</th><th class="num">%Q</th>'
            + '<th class="num">$'+b+'</th><th class="num">$'+a+'</th><th class="num">Dif $</th><th class="num">%$</th>'
            + '<th class="num">MB</th>'
            + '<th class="num">$Prom '+b+'</th><th class="num">$Prom '+a+'</th><th class="num">%Prom</th>'
            + '<th class="num">Tdas '+b+'</th><th class="num">Tdas '+a+'</th><th class="num">≠Tdas</th>'
            + '</tr></thead><tbody>';
        if (!rows.length) {
            h += '<tr><td colspan="16" style="text-align:center;color:var(--text-light);padding:20px;">Sin datos</td></tr></tbody>';
            document.getElementById(tbodyId).innerHTML = h; return;
        }
        const tot = {ua:0,ub:0,va:0,vb:0};
        rows.forEach((p, i) => {
            tot.ua+=p.ups_act; tot.ub+=p.ups_ant; tot.va+=p.val_act; tot.vb+=p.val_ant;
            const kids = p.children || [];
            h += rowArbol(p, opts, kids.length ? 'parent' : 'leaf', {idx:i});
            kids.forEach(c => { h += rowArbol(c, opts, 'child', {parent:i}); });
        });
        const totRow = total
            ? {label:'Total', ups_act:total.ups_act, ups_ant:total.ups_ant, val_act:total.val_act, val_ant:total.val_ant, margen:total.margen, tiendas_act:total.tiendas_act, tiendas_ant:total.tiendas_ant}
            : {label:'Total', ups_act:tot.ua, ups_ant:tot.ub, val_act:tot.va, val_ant:tot.vb, margen:0, tiendas_act:0, tiendas_ant:0};
        h += rowArbol(totRow, opts, 'total', {});
        h += '</tbody>';
        document.getElementById(tbodyId).innerHTML = h;
    }
    function rowArbol(r, opts, kind, meta) {
        meta = meta || {};
        const pa = prom(r.val_act, r.ups_act), pb = prom(r.val_ant, r.ups_ant);
        const difT = (r.tiendas_act||0) - (r.tiendas_ant||0);
        const imgAttr = (opts.imgHover && (kind === 'parent' || kind === 'leaf') && r.label !== '(Sin dato)')
            ? ' data-negimg="'+esc(r.label)+'"' : '';
        const cls = kind === 'total' ? 'g00-total' : (kind === 'child' ? 'g00-tipo' : '');
        let trOpen, labelCell;
        if (kind === 'child') {
            trOpen = '<tr class="'+cls+'" data-'+opts.prefix+'parent="'+meta.parent+'" style="display:none">';
            labelCell = '<td>'+esc(r.label)+'</td>';
        } else if (kind === 'parent') {
            trOpen = '<tr class="'+cls+' g00-marca-row g00-collapsed"'+imgAttr+' onclick="g00ToggleArbol(\''+opts.prefix+'\','+meta.idx+',this)">';
            labelCell = '<td><span class="g00-caret">▸</span>'+esc(r.label)+'</td>';
        } else {   // leaf (padre sin hijos) o total
            trOpen = '<tr class="'+cls+'"'+imgAttr+'>';
            labelCell = '<td>'+esc(r.label)+'</td>';
        }
        return trOpen
            + labelCell
            + '<td class="num">'+fmtInt(r.ups_ant)+'</td><td class="num">'+fmtInt(r.ups_act)+'</td>'
            + difCell(r.ups_act, r.ups_ant, fmtInt) + pctCell(r.ups_act, r.ups_ant)
            + '<td class="num">'+fmtMoneyFull(r.val_ant)+'</td><td class="num">'+fmtMoneyFull(r.val_act)+'</td>'
            + difCell(r.val_act, r.val_ant, fmtMoneyFull) + pctCell(r.val_act, r.val_ant)
            + '<td class="num">'+(r.margen?r.margen.toFixed(2)+'%':'—')+'</td>'
            + '<td class="num">'+fmtMoneyFull(pb)+'</td><td class="num">'+fmtMoneyFull(pa)+'</td>'
            + pctCell(pa, pb)
            + '<td class="num">'+fmtInt(r.tiendas_ant)+'</td><td class="num">'+fmtInt(r.tiendas_act)+'</td>'
            + '<td class="num '+(difT>=0?'pos':'neg')+'">'+(difT>=0?'+':'')+fmtInt(difT)+'</td>'
            + '</tr>';
    }
    window.g00ToggleArbol = function (prefix, idx, el) {
        const collapsed = el.classList.toggle('g00-collapsed');
        document.querySelectorAll('tr[data-'+prefix+'parent="'+idx+'"]')
            .forEach(r => { r.style.display = collapsed ? 'none' : ''; });
        const caret = el.querySelector('.g00-caret');
        if (caret) caret.textContent = collapsed ? '▸' : '▾';
    };
    // ===== Preview de imagen del zapato al hover en la tabla "Resumen Ventas Por Negocio" =====
    (function initNegocioImgHover() {
        const FOTO_BASE = 'http://bi.stanton.com.co:81/fotosPBI/';
        const tabla = document.getElementById('g00-tabla-negocio');
        if (!tabla) return;
        let pop = null, img = null, triedPng = false, curLabel = '';
        function ensurePop() {
            if (pop) return;
            pop = document.createElement('div');
            pop.id = 'g00-img-pop';
            img = document.createElement('img');
            img.alt = '';
            img.onerror = function () {
                if (!triedPng) { triedPng = true; img.src = FOTO_BASE + encodeURIComponent(curLabel) + '.png'; }
                else { hide(); }
            };
            pop.appendChild(img);
            document.body.appendChild(pop);
        }
        function hide() { if (pop) pop.style.display = 'none'; }
        function position(e) {
            if (!pop) return;
            const off = 16, w = 276, h = 336;
            let x = e.clientX + off, y = e.clientY + off;
            if (x + w > window.innerWidth)  x = e.clientX - off - w;
            if (y + h > window.innerHeight) y = e.clientY - off - h;
            pop.style.left = Math.max(4, x) + 'px';
            pop.style.top  = Math.max(4, y) + 'px';
        }
        tabla.addEventListener('mouseover', function (e) {
            const td = e.target.closest('td');
            if (!td || td.cellIndex !== 0) return;   // solo la primera columna (Negocio)
            const tr = td.closest('tr[data-negimg]');
            if (!tr) return;
            curLabel = tr.getAttribute('data-negimg');
            triedPng = false;
            ensurePop();
            img.src = FOTO_BASE + encodeURIComponent(curLabel) + '.jpg';
            pop.style.display = 'block';
            position(e);
        });
        tabla.addEventListener('mousemove', function (e) {
            if (pop && pop.style.display === 'block') position(e);
        });
        tabla.addEventListener('mouseout', function (e) {
            const td = e.target.closest('td');
            // ocultar al salir de la primera columna (incluye moverse a otra columna de la fila)
            if (td && td.cellIndex === 0 && (!e.relatedTarget || !td.contains(e.relatedTarget))) hide();
        });
    })();
    // ===== Exportar a Excel (.xlsx) con SheetJS =====
    function expFilename(tabla) {
        const prov = (proveedorActual || window.PROVEEDOR_ACTUAL || '').replace(/[^A-Za-z0-9]+/g, '_').replace(/^_|_$/g, '');
        const fecha = new Date().toISOString().slice(0, 10);
        return 'G00_' + tabla + (prov ? '_' + prov : '') + '_' + fecha + '.xlsx';
    }
    function exportAOA(filename, sheetName, header, aoaRows) {
        if (typeof XLSX === 'undefined') { Swal.fire('Exportar', 'No se pudo cargar la librería de Excel.', 'error'); return; }
        const ws = XLSX.utils.aoa_to_sheet([header, ...aoaRows]);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, sheetName.slice(0, 31));
        XLSX.writeFile(wb, filename);
    }
    const eDif  = (a, b) => (a || 0) - (b || 0);
    const ePct  = (a, b) => b ? ((a - b) / b) * 100 : '';
    const eProm = (v, u) => (u > 0 ? v / u : 0);
    const ePart = (x, d) => (d > 0 ? (x / d) * 100 : '');
    // Builder de las 6 tablas comparativas. rows: [{label,val_act,val_ant,ups_act,ups_ant,margen,tiendas_act,tiendas_ant,children?}].
    // opts: {anio, full (default true → 16 cols con %Prom+Tdas; false → 12 cols estilo Tienda), totalTdas:{act,ant}}.
    function comparativaAOA(dim, rows, opts) {
        const a = opts.anio, b = a - 1, full = opts.full !== false;
        const header = full
            ? [dim, b, a, 'Dif Q', '%Q', '$ ' + b, '$ ' + a, 'Dif $', '%$', 'MB', '$Prom ' + b, '$Prom ' + a, '%Prom', 'Tdas ' + b, 'Tdas ' + a, '≠Tdas']
            : [dim, b, a, 'Dif Q', '%Q', '$ ' + b, '$ ' + a, 'Dif $', '%$', 'MB', '$Prom ' + b, '$Prom ' + a];
        const line = (r, indent) => {
            const pa = eProm(r.val_act, r.ups_act), pb = eProm(r.val_ant, r.ups_ant);
            const base = [(indent ? '   ' : '') + (r.label || ''),
                r.ups_ant || 0, r.ups_act || 0, eDif(r.ups_act, r.ups_ant), ePct(r.ups_act, r.ups_ant),
                r.val_ant || 0, r.val_act || 0, eDif(r.val_act, r.val_ant), ePct(r.val_act, r.val_ant),
                r.margen || 0, pb, pa];
            if (full) base.push(ePct(pa, pb), r.tiendas_ant || 0, r.tiendas_act || 0, eDif(r.tiendas_act, r.tiendas_ant));
            return base;
        };
        const body = [];
        let sv = 0, sb = 0, su = 0, sub = 0; const margenes = [];
        (rows || []).forEach(r => {
            sv += r.val_act || 0; sb += r.val_ant || 0; su += r.ups_act || 0; sub += r.ups_ant || 0;
            if (r.margen > 0) margenes.push(r.margen);
            body.push(line(r, false));
            (r.children || []).forEach(c => body.push(line(c, true)));
        });
        const mbTot = margenes.length ? margenes.reduce((x, y) => x + y, 0) / margenes.length : 0;
        const tdas = opts.totalTdas || { act: 0, ant: 0 };
        body.push(line({ label: 'Total', val_act: sv, val_ant: sb, ups_act: su, ups_ant: sub,
            margen: mbTot, tiendas_act: tdas.act, tiendas_ant: tdas.ant }, false));
        return { header, body };
    }
    window.g00ExpGrupo = function () {
        if (!lastDetal) { Swal.fire('Exportar', 'Carga el dashboard primero.', 'info'); return; }
        const r = comparativaAOA('Grupo', lastDetal.por_grupo, { anio: lastDetal.anio, full: true,
            totalTdas: { act: lastDetal.kpis.tiendas_actual, ant: lastDetal.kpis.tiendas_anterior } });
        exportAOA(expFilename('PorGrupo'), 'Por Grupo', r.header, r.body);
    };
    window.g00ExpMarca = function () {
        if (!lastDetal) { Swal.fire('Exportar', 'Carga el dashboard primero.', 'info'); return; }
        const r = comparativaAOA('Marca / Tipo', lastDetal.por_marca, { anio: lastDetal.anio, full: true,
            totalTdas: { act: lastDetal.kpis.tiendas_actual, ant: lastDetal.kpis.tiendas_anterior } });
        exportAOA(expFilename('PorMarcaTipo'), 'Por Marca-Tipo', r.header, r.body);
    };
    window.g00ExpTienda = function () {
        if (!lastTiendas) { Swal.fire('Exportar', 'Carga la pestaña Tiendas primero.', 'info'); return; }
        const rows = (lastTiendas.tiendas || []).map(t => ({
            label: t.nombre || t.cod || '', val_act: t.val_act, val_ant: t.val_ant, ups_act: t.ups_act, ups_ant: t.ups_ant, margen: t.margen,
            children: (t.children || []).map(c => ({ label: c.negocio || '', val_act: c.val_act, val_ant: c.val_ant, ups_act: c.ups_act, ups_ant: c.ups_ant, margen: c.margen }))
        }));
        const r = comparativaAOA('Tienda / Negocio', rows, { anio: lastTiendas.anio, full: false });
        exportAOA(expFilename('PorTienda'), 'Por Tienda', r.header, r.body);
    };
    window.g00ExpNegocio = function () {
        if (!lastProductos) { Swal.fire('Exportar', 'Carga la pestaña Productos primero.', 'info'); return; }
        const n = lastProductos.negocios || { rows: [], total: {} };
        const r = comparativaAOA('Negocio / Talla', n.rows, { anio: lastProductos.anio, full: true,
            totalTdas: { act: n.total.tiendas_act, ant: n.total.tiendas_ant } });
        exportAOA(expFilename('PorNegocio'), 'Por Negocio', r.header, r.body);
    };
    window.g00ExpCategoria = function () {
        if (!lastProductos) { Swal.fire('Exportar', 'Carga la pestaña Productos primero.', 'info'); return; }
        const c = lastProductos.categorias || { rows: [], total: {} };
        const r = comparativaAOA('Categoría / Subcategoría', c.rows, { anio: lastProductos.anio, full: true,
            totalTdas: { act: c.total.tiendas_act, ant: c.total.tiendas_ant } });
        exportAOA(expFilename('PorCategoria'), 'Por Categoria', r.header, r.body);
    };
    window.g00ExpGenero = function () {
        if (!lastProductos) { Swal.fire('Exportar', 'Carga la pestaña Productos primero.', 'info'); return; }
        const g = lastProductos.generos || { rows: [], total: {} };
        const r = comparativaAOA('Género / Público', g.rows, { anio: lastProductos.anio, full: true,
            totalTdas: { act: g.total.tiendas_act, ant: g.total.tiendas_ant } });
        exportAOA(expFilename('PorGenero'), 'Por Genero', r.header, r.body);
    };
    function mensualAOA(rows, tdas, anio) {
        const a = anio, b = a - 1;
        const header = ['Mes', b, a, 'Dif Q', '%Q', '$ ' + b, '$ ' + a, 'Dif $', '%$', '$Prom ' + b, '$Prom ' + a, 'Tdas ' + b, 'Tdas ' + a, '≠Tdas'];
        const body = []; let sv = 0, sb = 0, su = 0, sub = 0;
        (rows || []).forEach(r => {
            if (!r.val_act && !r.val_ant && !r.ups_act && !r.ups_ant) return;
            sv += r.val_act || 0; sb += r.val_ant || 0; su += r.ups_act || 0; sub += r.ups_ant || 0;
            const pa = eProm(r.val_act, r.ups_act), pb = eProm(r.val_ant, r.ups_ant);
            body.push([r.mes, r.ups_ant || 0, r.ups_act || 0, eDif(r.ups_act, r.ups_ant), ePct(r.ups_act, r.ups_ant),
                r.val_ant || 0, r.val_act || 0, eDif(r.val_act, r.val_ant), ePct(r.val_act, r.val_ant),
                pb, pa, r.tiendas_ant || 0, r.tiendas_act || 0, eDif(r.tiendas_act, r.tiendas_ant)]);
        });
        const pa = eProm(sv, su), pb = eProm(sb, sub);
        const tA = (tdas && tdas.act) || 0, tB = (tdas && tdas.ant) || 0;
        body.push(['Total', sub, su, eDif(su, sub), ePct(su, sub), sb, sv, eDif(sv, sb), ePct(sv, sb), pb, pa, tB, tA, eDif(tA, tB)]);
        return { header, body };
    }
    function periodosAOA(dias, anio) {
        const a = anio, b = a - 1;
        const header = ['Periodo', 'Cant ' + b, '%' + b, 'Cant ' + a, '%' + a, 'Var% Q', '$ ' + b, '%' + b, '$ ' + a, '%' + a, 'Var% $'];
        const arbol = buildArbolPeriodos(dias);   // {sems, tot}
        const T = arbol.tot;
        const row = (label, m, ind) => ['   '.repeat(ind) + label,
            m.ub, ePart(m.ub, T.ub), m.ua, ePart(m.ua, T.ua), ePct(m.ua, m.ub),
            m.vb, ePart(m.vb, T.vb), m.va, ePart(m.va, T.va), ePct(m.va, m.vb)];
        const body = [];
        Object.keys(arbol.sems).map(Number).sort((x, y) => x - y).forEach(s => {
            const S = arbol.sems[s];
            body.push(row('Semestre ' + s, S.m, 0));
            Object.keys(S.tris).map(Number).sort((x, y) => x - y).forEach(t => {
                const Tr = S.tris[t];
                body.push(row('Trimestre ' + t, Tr.m, 1));
                Object.keys(Tr.meses).map(Number).sort((x, y) => x - y).forEach(mn => {
                    const M = Tr.meses[mn];
                    body.push(row(MESES_ES[mn - 1], M.m, 2));
                    Object.keys(M.dias).map(Number).sort((x, y) => x - y).forEach(d => {
                        body.push(row(MESES_ES[mn - 1] + '-' + String(d).padStart(2, '0'), M.dias[d].m, 3));
                    });
                });
            });
        });
        body.push(row('Total', T, 0));
        return { header, body };
    }
    window.g00ExpMensual = function () {
        if (!lastDetal) { Swal.fire('Exportar', 'Carga el dashboard primero.', 'info'); return; }
        const r = mensualAOA(lastDetal.mensual, lastDetal.mensual_tdas, lastDetal.anio);
        exportAOA(expFilename('Mensual'), 'Mensual', r.header, r.body);
    };
    window.g00ExpPeriodos = function () {
        if (!lastPeriodos) { Swal.fire('Exportar', 'Carga la pestaña Periodos primero.', 'info'); return; }
        const r = periodosAOA(lastPeriodos.dias, lastPeriodos.anio);
        exportAOA(expFilename('Periodos'), 'Periodos', r.header, r.body);
    };
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

    let filtrosInit = false;
    window.g00OnEnter = function () {
        // Encabezado del informe vive en el topbar: 3 secciones (tabla fechas | título centrado | botones).
        const prov = proveedorActual || window.PROVEEDOR_ACTUAL || '—';
        document.getElementById('pageTitle').textContent = 'DASHBOARD DE VENTAS - ' + prov;
        document.getElementById('topbar').classList.add('topbar--g00');
        document.getElementById('pageSubtitle').style.display = 'none';
        const dt = document.getElementById('topbarDates');
        // En re-entrada el tab está cacheado y loadDetal() no corre, así que reusamos el rango
        // de la última carga (lastDetal). Sin esto, el topbar se queda en el placeholder «…».
        dt.style.display = ''; dt.innerHTML = renderTopbarDates(lastDetal ? lastDetal.rango : null);
        document.getElementById('topbarG00Refresh').style.display = '';
        if (!filtrosInit) { initFiltros(); filtrosInit = true; }
        if (!tabState.detal) loadDetal();
        else Object.values(charts).forEach(c => c && c.resize());
    };

    window.g00ShowTab = function (name, el) {
        document.querySelectorAll('#page-informes-g00 .tab-bar:not(.g00-modo-bar) .tab').forEach(t => t.classList.remove('active'));
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
