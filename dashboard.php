<?php
	session_start();
	if (!isset($_SESSION['usuario'])) {
		header("Location: index.php");
		exit;
	}

	// Timeout de inactividad: 30 minutos
	$timeout = 1800;
	if (isset($_SESSION['ultima_actividad']) && (time() - $_SESSION['ultima_actividad']) > $timeout) {
		session_unset();
		session_destroy();
		header("Location: index.php?expired=1");
		exit;
	}
	$_SESSION['ultima_actividad'] = time();

	$nombreUsuario = $_SESSION['usuario'];
	$imagenUsuario = isset($_SESSION['imagen']) ? $_SESSION['imagen'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AKA 2.0 — Portal de Aliados (Preview)</title>
    <link rel="shortcut icon" href="img/aka.ico" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="awesome/css/fontawesome.min.css">
    <link rel="stylesheet" href="awesome/css/solid.min.css">
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>window.PROVEEDOR_ACTUAL = <?= json_encode($_SESSION['proveedor'] ?? '') ?>;</script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #4A4782;
            --primary-dark: #3a3768;
            --primary-light: #5c59a0;
            --accent: #ff001e;
            --accent-hover: #d9001a;
            --bg: #f0f0f4;
            --card: #ffffff;
            --text: #2d2b4e;
            --text-light: #7b7894;
            --border: #e0dfe8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --gray-bg: #cacaca;
        }
        body { font-family: 'Space Grotesk', system-ui, sans-serif; background: var(--bg); color: var(--text); }

        /* ============ LOGIN ============ */
        .login-screen {
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 50%, #2d2b4e 100%);
        }
        .login-box {
            background: #cacaca; border-radius: 0; padding: 0; width: 360px;
            box-shadow: 0 0 20px rgba(0,0,0,0.3); overflow: hidden;
        }
        .login-logo {
            background: var(--primary); padding: 32px; text-align: center;
        }
        .login-logo .logo-text {
            font-size: 48px; font-weight: 700; color: white; letter-spacing: 8px;
        }
        .login-logo .logo-sub { font-size: 11px; color: rgba(255,255,255,0.6); letter-spacing: 3px; margin-top: 4px; }
        .login-form { padding: 32px 40px 40px; }
        .login-form label {
            display: block; font-size: 13px; font-weight: 600; color: var(--primary);
            margin-bottom: 4px; letter-spacing: 1px; text-transform: uppercase;
        }
        .login-form input[type="email"], .login-form input[type="password"] {
            width: 100%; padding: 10px 0; border: none; border-bottom: 2px solid var(--primary);
            background: transparent; outline: none; font-size: 15px; color: var(--primary);
            font-family: 'Space Grotesk', sans-serif; margin-bottom: 24px;
        }
        .login-form input::placeholder { color: rgba(74,71,130,0.5); }
        .login-form input:focus { border-bottom-color: var(--accent); }
        .login-btn {
            background: var(--accent); color: white; border: none; padding: 12px 32px;
            font-size: 16px; font-weight: 700; cursor: pointer; letter-spacing: 1px;
            font-family: 'Space Grotesk', sans-serif; transition: background 0.2s;
        }
        .login-btn:hover { background: var(--primary); }

        /* ============ COMMON ============ */
        .btn {
            padding: 10px 20px; border: none; border-radius: 6px; font-size: 14px;
            font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex;
            align-items: center; gap: 8px; font-family: 'Space Grotesk', sans-serif;
        }
        .btn-primary { background: var(--accent); color: white; }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-secondary { background: var(--bg); color: var(--text); border: 1px solid var(--border); }
        .btn-secondary:hover { background: var(--border); }
        .btn-success { background: var(--success); color: white; }
        .btn-sm { padding: 6px 14px; font-size: 12px; }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block; font-size: 11px; font-weight: 600; color: var(--text-light);
            margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 6px;
            font-size: 14px; outline: none; font-family: 'Space Grotesk', sans-serif;
            transition: border-color 0.2s; background: white;
        }
        .form-group input:focus, .form-group select:focus { border-color: var(--primary); }

        /* ============ LAYOUT ============ */
        .app { display: none; }
        .app.active { display: flex; min-height: 100vh; }

        /* SIDEBAR */
        .sidebar {
            width: 256px; background: var(--primary); color: white;
            position: fixed; top: 0; left: 0; bottom: 0; z-index: 100;
            display: flex; flex-direction: column;
        }
        .sidebar-header { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); text-align: center; }
        .sidebar-header .logo-text {
            font-size: 32px; font-weight: 700; color: white; letter-spacing: 6px;
        }
        .sidebar-header .logo-sub {
            font-size: 10px; color: rgba(255,255,255,0.45); letter-spacing: 2px; margin-top: 2px;
        }
        .sidebar-nav { flex: 1; padding: 16px 10px; overflow-y: auto; }
        .nav-section { margin-bottom: 20px; }
        .nav-section-title {
            font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px;
            color: rgba(255,255,255,0.3); padding: 0 12px; margin-bottom: 6px;
        }
        .nav-item {
            display: flex; align-items: center; gap: 10px; padding: 9px 12px;
            border-radius: 6px; cursor: pointer; transition: all 0.15s;
            color: rgba(255,255,255,0.65); font-size: 13px; font-weight: 500; margin-bottom: 1px;
        }
        .nav-item:hover { background: rgba(255,255,255,0.1); color: white; }
        .nav-item.active { background: var(--accent); color: white; }
        .nav-item .icon { width: 18px; text-align: center; font-size: 15px; }
        .nav-item .badge {
            margin-left: auto; background: var(--accent); color: white;
            font-size: 10px; padding: 2px 7px; border-radius: 10px; font-weight: 700;
        }
        .nav-item.active .badge { background: rgba(255,255,255,0.3); }
        .sidebar-footer {
            padding: 14px 16px; border-top: 1px solid rgba(255,255,255,0.1);
            display: flex; align-items: center; gap: 10px;
        }
        .avatar {
            width: 34px; height: 34px; border-radius: 6px; background: var(--accent);
            display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px;
        }
        .user-name { font-size: 12px; font-weight: 600; }
        .user-role { font-size: 10px; color: rgba(255,255,255,0.45); }

        /* MAIN */
        .main { flex: 1; margin-left: 256px; min-width: 0; } /* min-width:0 evita que tablas anchas (O14) desborden el flex item y empujen topbar/KPIs fuera del viewport */
        .topbar {
            background: white; padding: 14px 28px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 50;
        }
        .topbar h2 { font-size: 18px; font-weight: 700; color: var(--primary); }
        .topbar-titles { display: flex; flex-direction: column; gap: 2px; }
        .topbar-subtitle { font-size: 12px; color: var(--text-light); font-weight: 500; }
        .topbar-action {
            background: var(--accent); color: #fff; border: none;
            padding: 8px 14px; border-radius: 8px; cursor: pointer;
            font-family: 'Space Grotesk', sans-serif; font-size: 13px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 7px;
        }
        .topbar-action:hover { filter: brightness(0.92); }
        .topbar-actions { display: flex; align-items: center; gap: 10px; }
        /* Modo G00: 3 secciones (tabla fechas | titulo centrado | botones) */
        .topbar.topbar--g00 { display: grid; grid-template-columns: auto 1fr auto; gap: 16px; }
        .topbar.topbar--g00 .topbar-titles { align-items: center; text-align: center; }
        /* O14: sin tablita de fechas → spacer izquierdo 1fr para centrar el título y empujar acciones a la derecha */
        .topbar.topbar--o14 { display: grid; grid-template-columns: 1fr auto 1fr; gap: 16px; align-items: center; }
        .topbar.topbar--o14 .topbar-titles { align-items: center; text-align: center; }
        .topbar.topbar--o14 .topbar-actions { justify-self: end; }
        .topbar.topbar--o14 .o14-vfilter { display: flex; align-items: center; gap: 10px; }
        .o14-vfilter-lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 700; }
        .o14-vfilter label { display: flex; flex-direction: column; gap: 2px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 600; }
        .o14-vfilter input[type="date"], .o14-vfilter input[type="month"] { font-family: 'Space Grotesk', sans-serif; font-size: 11px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 6px; background: white; color: var(--text); }
        .topbar-dates table { border-collapse: collapse; }
        .topbar-dates th {
            font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px;
            color: var(--text-light); font-weight: 700; padding: 0 10px 2px; text-align: left;
        }
        .topbar-dates td { font-size: 11px; color: var(--text); padding: 1px 10px; white-space: nowrap; }
        .topbar-dates td.ant { color: var(--text-light); }
        .notification-btn {
            position: relative; background: var(--bg); border: none; width: 38px; height: 38px;
            border-radius: 8px; cursor: pointer; font-size: 16px;
            display: flex; align-items: center; justify-content: center;
        }
        .notification-btn .dot {
            position: absolute; top: 7px; right: 7px; width: 7px; height: 7px;
            background: var(--accent); border-radius: 50%;
        }
        .content { padding: 28px; }

        /* PAGES */
        .page { display: none; }
        .page.active { display: block; }

        /* STATS */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }
        .stat-card {
            background: white; border-radius: 10px; padding: 20px;
            border: 1px solid var(--border);
        }
        .stat-card .stat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .stat-card .stat-icon {
            width: 40px; height: 40px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center; font-size: 18px;
        }
        .stat-card .stat-trend { font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px; }
        .stat-card .stat-trend.up { background: #ecfdf5; color: var(--success); }
        .stat-card .stat-trend.down { background: #fef2f2; color: var(--danger); }
        .stat-card .stat-value { font-size: 26px; font-weight: 700; color: var(--primary); margin-bottom: 2px; }
        .stat-card .stat-label { font-size: 12px; color: var(--text-light); }
        .icon-ventas { background: #ede9fe; color: var(--primary); }
        .icon-inventario { background: #dbeafe; color: #3b82f6; }
        .icon-pagos { background: #ecfdf5; color: #10b981; }
        .icon-alertas { background: #fff1f1; color: var(--accent); }

        .grid-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; }
        .card { background: white; border-radius: 10px; padding: 20px; border: 1px solid var(--border); margin-bottom: 16px; }
        .card-title {
            font-size: 14px; font-weight: 700; color: var(--primary); margin-bottom: 14px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .card-title .view-all { font-size: 12px; color: var(--accent); cursor: pointer; font-weight: 600; }

        /* CHARTS */
        .chart-bars { display: flex; align-items: flex-end; gap: 8px; height: 200px; padding: 16px 16px 36px; }
        .chart-bar {
            flex: 1; background: linear-gradient(180deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 4px 4px 0 0; transition: all 0.3s; cursor: pointer; position: relative;
        }
        .chart-bar:hover { background: var(--accent); }
        .chart-bar .bar-label {
            position: absolute; bottom: -24px; left: 50%; transform: translateX(-50%);
            font-size: 10px; color: var(--text-light); white-space: nowrap;
        }

        /* TABLES */
        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: left; padding: 10px 14px; font-size: 10px; text-transform: uppercase;
            letter-spacing: 0.5px; color: var(--text-light); background: var(--bg);
            border-bottom: 1px solid var(--border);
        }
        td { padding: 12px 14px; border-bottom: 1px solid var(--border); font-size: 13px; }
        tr:hover td { background: #f8f7fc; }
        .status {
            display: inline-block; padding: 3px 10px; border-radius: 4px;
            font-size: 11px; font-weight: 600; letter-spacing: 0.3px;
        }
        .status-pendiente { background: #fef3c7; color: #92400e; }
        .status-aprobado { background: #ecfdf5; color: #065f46; }
        .status-rechazado { background: #fef2f2; color: #991b1b; }
        .status-revision { background: #ede9fe; color: var(--primary); }
        .status-cuidaduria { background: #dbeafe; color: #1e40af; }
        .status-estudio { background: #ede9fe; color: var(--primary); }
        .status-vigente { background: #ecfdf5; color: #065f46; }
        .status-vencido { background: #fef2f2; color: #991b1b; }
        .status-pagado { background: #ecfdf5; color: #065f46; }
        .status-parcial { background: #fef3c7; color: #92400e; }

        /* ALERTS */
        .alert-item {
            display: flex; align-items: flex-start; gap: 10px; padding: 12px;
            border-radius: 8px; margin-bottom: 6px; background: #f8f7fc; cursor: pointer;
            transition: all 0.15s; border-left: 3px solid transparent;
        }
        .alert-item:hover { background: var(--border); }
        .alert-item.alta { border-left-color: var(--accent); }
        .alert-item.media { border-left-color: var(--warning); }
        .alert-item.baja { border-left-color: var(--info); }
        .alert-title { font-size: 12px; font-weight: 600; margin-bottom: 2px; }
        .alert-desc { font-size: 11px; color: var(--text-light); }
        .alert-time { font-size: 10px; color: var(--text-light); white-space: nowrap; }

        /* FILTERS */
        .filters { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .filter-chip {
            padding: 6px 14px; border-radius: 4px; font-size: 12px; font-weight: 600;
            border: 1px solid var(--border); cursor: pointer; background: white; transition: all 0.15s;
        }
        .filter-chip.active { background: var(--primary); color: white; border-color: var(--primary); }
        .filter-chip:hover { border-color: var(--primary); }

        .tab-bar { display: flex; gap: 0; border-bottom: 2px solid var(--border); margin-bottom: 20px; }
        .tab {
            padding: 10px 18px; font-size: 13px; font-weight: 600; cursor: pointer;
            color: var(--text-light); border-bottom: 2px solid transparent; margin-bottom: -2px;
        }
        .tab.active { color: var(--accent); border-bottom-color: var(--accent); }
        .tab:hover { color: var(--text); }

        /* CODIFICACION FORM */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-grid .full-width { grid-column: 1 / -1; }
        .criteria-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .step-indicator { display: flex; align-items: center; gap: 0; margin-bottom: 28px; }
        .step {
            display: flex; align-items: center; gap: 6px; padding: 6px 14px;
            border-radius: 6px; font-size: 12px; font-weight: 600; color: var(--text-light);
        }
        .step.active { background: var(--primary); color: white; }
        .step.done { color: var(--success); }
        .step-num {
            width: 22px; height: 22px; border-radius: 50%; display: flex;
            align-items: center; justify-content: center; font-size: 11px;
            border: 2px solid var(--border);
        }
        .step.active .step-num { border-color: transparent; background: rgba(255,255,255,0.3); }
        .step.done .step-num { border-color: var(--success); background: var(--success); color: white; }
        .step-line { width: 32px; height: 2px; background: var(--border); }

        /* MODAL */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(74,71,130,0.6); z-index: 200; align-items: center; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white; border-radius: 12px; padding: 28px; width: 860px;
            max-height: 85vh; overflow-y: auto;
        }
        .modal h3 { font-size: 16px; color: var(--primary); margin-bottom: 18px; }

        /* SECTION HEADER */
        .section-label {
            font-size: 11px; text-transform: uppercase; letter-spacing: 1px;
            color: var(--primary); font-weight: 700; margin: 20px 0 10px;
            padding-bottom: 6px; border-bottom: 2px solid var(--primary);
            display: inline-block;
        }

        /* DISPERSION TABLE */
        .disp-table { font-size: 11px; }
        .disp-table th { font-size: 9px; padding: 6px 8px; text-align: center; }
        .disp-table td { padding: 6px 8px; text-align: center; font-size: 11px; }
        .disp-table td:first-child, .disp-table th:first-child { text-align: left; }
        .disp-total { font-weight: 700; background: #f8f7fc; }

        /* UPLOAD */
        .upload-area {
            border: 2px dashed var(--border); border-radius: 8px; padding: 32px;
            text-align: center; cursor: pointer; transition: all 0.2s;
        }
        .upload-area:hover { border-color: var(--primary); background: #f8f7fc; }
        .upload-area .icon { font-size: 36px; margin-bottom: 8px; }
        .upload-area p { font-size: 13px; color: var(--text-light); }

        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .grid-2 { grid-template-columns: 1fr; }
            .criteria-grid { grid-template-columns: 1fr; }
        }


        /* ============ CARGA MASIVA ============ */
        .upload-excel {
            border: 2px dashed var(--primary); border-radius: 8px; padding: 40px;
            text-align: center; cursor: pointer; transition: all 0.2s;
            background: #f8f7fc;
        }
        .upload-excel:hover { border-color: var(--accent); background: #f0eff5; }
        .upload-excel .icon { font-size: 42px; margin-bottom: 10px; color: var(--primary); }
        .upload-excel p { font-size: 13px; color: var(--text-light); }
        .upload-excel strong { color: var(--primary); }
        .upload-steps {
            display: flex; gap: 20px; margin-top: 20px; justify-content: center;
        }
        .upload-step {
            display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-light);
        }
        .upload-step-num {
            width: 24px; height: 24px; border-radius: 50%; background: var(--primary);
            color: white; display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700;
        }
    </style>
</head>
<body>

<!-- ==================== APP ==================== -->
<div class="app active" id="app">
    <aside class="sidebar">
        <div class="sidebar-header">
            <img src="img/logo_aka.png" alt="AKA" style="width:65%;max-width:140px;height:auto;display:block;margin:0 auto 8px;">
            <div class="logo-sub">PORTAL DE ALIADOS v2.0</div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">REPORTES</div>
                <div class="nav-item active" onclick="showPage('informes-g00', this)">
                    <span class="icon"><i class="fa-solid fa-chart-column"></i></span> Ventas
                </div>
                <div class="nav-item" onclick="showPage('informes-o14', this)">
                    <span class="icon"><i class="fa-solid fa-shoe-prints"></i></span> Siembra/Stock/Ventas
                </div>
                <div class="nav-item" onclick="showPage('informes-o45', this)">
                    <span class="icon"><i class="fa-solid fa-arrow-trend-up"></i></span> &Iacute;ndice de Ventas
                </div>
                <div class="nav-item" onclick="showPage('evolucion-historica', this)">
                    <span class="icon"><i class="fa-solid fa-clock-rotate-left"></i></span> Evoluci&oacute;n Hist&oacute;rica
                </div>
                <div class="nav-item" onclick="showPage('georreferenciacion', this)">
                    <span class="icon"><i class="fa-solid fa-location-dot"></i></span> Georeferenciaci&oacute;n
                </div>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">PAGOS</div>
                <div class="nav-item" onclick="showPage('informes-pagos', this)">
                    <span class="icon"><i class="fa-solid fa-money-bill-wave"></i></span> An&aacute;lisis de Pagos
                </div>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">GESTI&Oacute;N</div>
                <div class="nav-item" onclick="showPage('codificacion', this)">
                    <span class="icon">&#9998;</span> Codificaci&oacute;n
                </div>
                <div class="nav-item" onclick="showPage('documentos', this)">
                    <span class="icon">&#9776;</span> Documentaci&oacute;n
                </div>
            </div>
        </nav>
        <div class="sidebar-footer">
            <?php if ($imagenUsuario): ?>
                <img src="img/<?php echo htmlspecialchars($imagenUsuario); ?>" alt="Avatar" style="width:34px;height:34px;border-radius:6px;object-fit:cover;background:rgba(255,255,255,0.85);padding:2px;">
            <?php else: ?>
                <div class="avatar"><?php echo strtoupper(substr($nombreUsuario, 0, 2)); ?></div>
            <?php endif; ?>
            <div>
                <div class="user-name"><?php echo htmlspecialchars($nombreUsuario); ?></div>
                <div class="user-role">Aliado</div>
            </div>
            <a href="logout.php" title="Cerrar sesión" style="margin-left:auto;color:rgba(255,255,255,0.75);text-decoration:none;font-size:16px;line-height:1;padding:6px;border-radius:6px;transition:background .15s;" onmouseover="this.style.background='rgba(255,255,255,0.12)';this.style.color='#fff';" onmouseout="this.style.background='none';this.style.color='rgba(255,255,255,0.75)';">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </aside>

    <div class="main">
        <div class="topbar" id="topbar">
            <div id="topbarDates" class="topbar-dates" style="display:none;"></div>
            <div class="topbar-titles">
                <h2 id="pageTitle">DASHBOARD</h2>
                <div id="pageSubtitle" class="topbar-subtitle" style="display:none;"></div>
            </div>
            <div class="topbar-actions"></div>
        </div>

        <div class="content">

            <!-- ==================== DASHBOARD ==================== -->
            <div class="page" id="page-dashboard">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon icon-ventas">&#9650;</div>
                            <span class="stat-trend up">+12.5%</span>
                        </div>
                        <div class="stat-value">$284.5M</div>
                        <div class="stat-label">Ventas del mes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon icon-inventario">&#9644;</div>
                            <span class="stat-trend down">-3.2%</span>
                        </div>
                        <div class="stat-value">12,847</div>
                        <div class="stat-label">Unidades en inventario</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon icon-pagos">&#9673;</div>
                            <span class="stat-trend up">+8.1%</span>
                        </div>
                        <div class="stat-value">$45.2M</div>
                        <div class="stat-label">Pagos recibidos</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">Ventas &uacute;ltimos 6 meses <span class="view-all" onclick="showPage('ventas', document.querySelector('[onclick*=ventas]'))">Ver detalle &rarr;</span></div>
                    <div class="chart-bars">
                        <div class="chart-bar" style="height:55%"><span class="bar-label">Oct</span></div>
                        <div class="chart-bar" style="height:70%"><span class="bar-label">Nov</span></div>
                        <div class="chart-bar" style="height:45%"><span class="bar-label">Dic</span></div>
                        <div class="chart-bar" style="height:80%"><span class="bar-label">Ene</span></div>
                        <div class="chart-bar" style="height:65%"><span class="bar-label">Feb</span></div>
                        <div class="chart-bar" style="height:90%"><span class="bar-label">Mar</span></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">&Uacute;ltimas solicitudes de codificaci&oacute;n <span class="view-all" onclick="showPage('codificacion', document.querySelector('[onclick*=codificacion]'))">Ver todas &rarr;</span></div>
                    <table>
                        <thead><tr><th>Referencia</th><th>Descripci&oacute;n</th><th>Marca</th><th>Colores</th><th>Tallas</th><th>Fecha</th><th>Estado</th></tr></thead>
                        <tbody>
                            <tr><td><strong>06-160650-3</strong></td><td>Zapatillas de hombres</td><td>Original Penguin</td><td>Negro/Blanco</td><td>7.5-11</td><td>18/03/2026</td><td><span class="status status-cuidaduria">Cuidadur&iacute;a</span></td></tr>
                            <tr><td><strong>06-160814-1</strong></td><td>Zapatillas de dama</td><td>Original Penguin</td><td>Beige</td><td>5-8.5</td><td>15/03/2026</td><td><span class="status status-revision">Comit&eacute; T&eacute;cnico</span></td></tr>
                            <tr><td><strong>06-690771-2</strong></td><td>Zapatillas de hombres</td><td>Original Penguin</td><td>Blanco/Azul/Rojo</td><td>7-10</td><td>10/03/2026</td><td><span class="status status-aprobado">Aprobado</span></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ==================== VENTAS ==================== -->
            <div class="page" id="page-ventas">
                <div class="filters">
                    <div class="filter-chip active">Todas las marcas</div>
                    <div class="filter-chip">Original Penguin</div>
                    <div class="form-group" style="margin:0;width:180px;">
                        <input type="month" value="2026-03" style="padding:6px 10px;font-size:12px;">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="card">
                        <div class="card-title">Comportamiento de ventas</div>
                        <div class="chart-bars">
                            <div class="chart-bar" style="height:40%"><span class="bar-label">Sem 1</span></div>
                            <div class="chart-bar" style="height:65%"><span class="bar-label">Sem 2</span></div>
                            <div class="chart-bar" style="height:55%"><span class="bar-label">Sem 3</span></div>
                            <div class="chart-bar" style="height:85%"><span class="bar-label">Sem 4</span></div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-title">Top 5 referencias</div>
                        <table>
                            <thead><tr><th>Ref</th><th>Uds</th><th>Valor</th></tr></thead>
                            <tbody>
                                <tr><td>06-160650-3</td><td>342</td><td>$99.2M</td></tr>
                                <tr><td>06-160676-1</td><td>287</td><td>$83.2M</td></tr>
                                <tr><td>06-160814-1</td><td>234</td><td>$58.5M</td></tr>
                                <tr><td>06-690771-2</td><td>198</td><td>$57.4M</td></tr>
                                <tr><td>06-160828-3</td><td>156</td><td>$45.2M</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card">
                    <div class="card-title">Detalle de ventas</div>
                    <table>
                        <thead><tr><th>Fecha</th><th>Documento</th><th>Referencia</th><th>Color</th><th>Talla</th><th>Cant</th><th>Valor</th></tr></thead>
                        <tbody>
                            <tr><td>20/03/2026</td><td>FV-84521</td><td>06-160650-3</td><td>Negro/Blanco</td><td>9</td><td>6</td><td>$1,740,000</td></tr>
                            <tr><td>20/03/2026</td><td>FV-84521</td><td>06-160650-3</td><td>Negro/Blanco</td><td>9.5</td><td>6</td><td>$1,740,000</td></tr>
                            <tr><td>19/03/2026</td><td>FV-84498</td><td>06-160814-1</td><td>Beige</td><td>7</td><td>6</td><td>$1,500,000</td></tr>
                            <tr><td>19/03/2026</td><td>FV-84498</td><td>06-160676-2</td><td>Negro/Blanco</td><td>8.5</td><td>6</td><td>$1,740,000</td></tr>
                            <tr><td>18/03/2026</td><td>FV-84472</td><td>06-690812-1</td><td>Rosado/Beige</td><td>6.5</td><td>6</td><td>$1,500,000</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ==================== INVENTARIOS ==================== -->
            <div class="page" id="page-inventarios">
                <div class="filters">
                    <div class="filter-chip active">Todas las marcas</div>
                    <div class="filter-chip">Original Penguin</div>
                </div>
                <div class="card">
                    <div class="card-title">Encurvamiento de tallas &mdash; 06-160650-3 (Negro/Blanco)</div>
                    <p style="font-size:12px;color:var(--text-light);margin-bottom:14px;">
                        Distribuci&oacute;n inventario vs ventas por talla. Tallas centrales (8.5-9.5) concentran el 50% de la demanda.
                    </p>
                    <div style="display:flex;gap:12px;align-items:flex-end;height:180px;padding:16px 30px 34px;background:#f8f7fc;border-radius:8px;">
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end;">
                            <div style="width:100%;display:flex;gap:2px;height:100%;align-items:flex-end;">
                                <div style="flex:1;height:20%;background:#d5d3e8;border-radius:3px 3px 0 0;" title="Stock: 4"></div>
                                <div style="flex:1;height:15%;background:var(--accent);border-radius:3px 3px 0 0;" title="Ventas: 3"></div>
                            </div>
                            <span style="font-size:11px;color:var(--text-light);font-weight:600;">7.5</span>
                        </div>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end;">
                            <div style="width:100%;display:flex;gap:2px;height:100%;align-items:flex-end;">
                                <div style="flex:1;height:25%;background:#d5d3e8;border-radius:3px 3px 0 0;" title="Stock: 4"></div>
                                <div style="flex:1;height:20%;background:var(--accent);border-radius:3px 3px 0 0;" title="Ventas: 4"></div>
                            </div>
                            <span style="font-size:11px;color:var(--text-light);font-weight:600;">8</span>
                        </div>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end;">
                            <div style="width:100%;display:flex;gap:2px;height:100%;align-items:flex-end;">
                                <div style="flex:1;height:45%;background:#d5d3e8;border-radius:3px 3px 0 0;" title="Stock: 6"></div>
                                <div style="flex:1;height:65%;background:var(--accent);border-radius:3px 3px 0 0;" title="Ventas: 12"></div>
                            </div>
                            <span style="font-size:11px;color:var(--accent);font-weight:700;">8.5 !</span>
                        </div>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end;">
                            <div style="width:100%;display:flex;gap:2px;height:100%;align-items:flex-end;">
                                <div style="flex:1;height:40%;background:#d5d3e8;border-radius:3px 3px 0 0;" title="Stock: 6"></div>
                                <div style="flex:1;height:90%;background:var(--accent);border-radius:3px 3px 0 0;" title="Ventas: 18"></div>
                            </div>
                            <span style="font-size:11px;color:var(--accent);font-weight:700;">9 !</span>
                        </div>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end;">
                            <div style="width:100%;display:flex;gap:2px;height:100%;align-items:flex-end;">
                                <div style="flex:1;height:40%;background:#d5d3e8;border-radius:3px 3px 0 0;" title="Stock: 6"></div>
                                <div style="flex:1;height:75%;background:var(--accent);border-radius:3px 3px 0 0;" title="Ventas: 15"></div>
                            </div>
                            <span style="font-size:11px;color:var(--accent);font-weight:700;">9.5 !</span>
                        </div>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end;">
                            <div style="width:100%;display:flex;gap:2px;height:100%;align-items:flex-end;">
                                <div style="flex:1;height:40%;background:#d5d3e8;border-radius:3px 3px 0 0;" title="Stock: 6"></div>
                                <div style="flex:1;height:35%;background:var(--accent);border-radius:3px 3px 0 0;" title="Ventas: 6"></div>
                            </div>
                            <span style="font-size:11px;color:var(--text-light);font-weight:600;">10</span>
                        </div>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end;">
                            <div style="width:100%;display:flex;gap:2px;height:100%;align-items:flex-end;">
                                <div style="flex:1;height:10%;background:#d5d3e8;border-radius:3px 3px 0 0;" title="Stock: 1"></div>
                                <div style="flex:1;height:5%;background:var(--accent);border-radius:3px 3px 0 0;" title="Ventas: 1"></div>
                            </div>
                            <span style="font-size:11px;color:var(--text-light);font-weight:600;">10.5</span>
                        </div>
                        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end;">
                            <div style="width:100%;display:flex;gap:2px;height:100%;align-items:flex-end;">
                                <div style="flex:1;height:18%;background:#d5d3e8;border-radius:3px 3px 0 0;" title="Stock: 3"></div>
                                <div style="flex:1;height:10%;background:var(--accent);border-radius:3px 3px 0 0;" title="Ventas: 2"></div>
                            </div>
                            <span style="font-size:11px;color:var(--text-light);font-weight:600;">11</span>
                        </div>
                    </div>
                    <div style="display:flex;gap:16px;margin-top:10px;justify-content:center;">
                        <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-light);">
                            <div style="width:10px;height:10px;background:#d5d3e8;border-radius:2px;"></div> Stock
                        </div>
                        <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-light);">
                            <div style="width:10px;height:10px;background:var(--accent);border-radius:2px;"></div> Ventas (30d)
                        </div>
                        <div style="font-size:11px;color:var(--accent);font-weight:600;">! Requiere reposici&oacute;n</div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-title">Saldos de inventario</div>
                    <table>
                        <thead><tr><th>Referencia</th><th>Descripci&oacute;n</th><th>Color</th><th>Total Uds</th><th>Bodega</th><th>Rotaci&oacute;n</th></tr></thead>
                        <tbody>
                            <tr><td><strong>06-160650-3</strong></td><td>Zapatillas hombre</td><td>Negro/Blanco</td><td>36</td><td>CEDI</td><td><span class="status status-aprobado">Alta</span></td></tr>
                            <tr><td><strong>06-160676-1</strong></td><td>Zapatillas hombre</td><td>Blanco/Verde</td><td>36</td><td>CEDI</td><td><span class="status status-pendiente">Media</span></td></tr>
                            <tr><td><strong>06-160814-1</strong></td><td>Zapatillas dama</td><td>Beige</td><td>36</td><td>CEDI</td><td><span class="status status-aprobado">Alta</span></td></tr>
                            <tr><td><strong>06-160676-3</strong></td><td>Zapatillas hombre</td><td>Caf&eacute;</td><td>31</td><td>CEDI</td><td><span class="status status-rechazado">Baja</span></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ==================== PAGOS ==================== -->
            <div class="page" id="page-pagos">
                <div class="stats-grid" style="grid-template-columns: repeat(3,1fr);">
                    <div class="stat-card">
                        <div class="stat-header"><div class="stat-icon icon-pagos">&#9673;</div></div>
                        <div class="stat-value">$45.2M</div>
                        <div class="stat-label">Total pagado (mes)</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header"><div class="stat-icon icon-alertas">&#9203;</div></div>
                        <div class="stat-value">$18.7M</div>
                        <div class="stat-label">Saldo pendiente</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-header"><div class="stat-icon" style="background:#fef2f2;color:var(--danger);">&#9888;</div></div>
                        <div class="stat-value">3</div>
                        <div class="stat-label">Facturas vencidas</div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-title">Estado de facturas</div>
                    <div class="tab-bar">
                        <div class="tab active">Todas</div>
                        <div class="tab">Pendientes</div>
                        <div class="tab">Pagadas</div>
                        <div class="tab">Vencidas</div>
                    </div>
                    <table>
                        <thead><tr><th>Factura</th><th>Fecha</th><th>Vencimiento</th><th>Valor</th><th>Pagado</th><th>Saldo</th><th>Estado</th></tr></thead>
                        <tbody>
                            <tr><td><strong>FV-84521</strong></td><td>20/03/2026</td><td>19/04/2026</td><td>$8,450,000</td><td>$0</td><td>$8,450,000</td><td><span class="status status-pendiente">Pendiente</span></td></tr>
                            <tr><td><strong>FV-84498</strong></td><td>19/03/2026</td><td>18/04/2026</td><td>$5,230,000</td><td>$2,615,000</td><td>$2,615,000</td><td><span class="status status-parcial">Parcial</span></td></tr>
                            <tr><td><strong>FV-84301</strong></td><td>05/03/2026</td><td>04/04/2026</td><td>$12,800,000</td><td>$12,800,000</td><td>$0</td><td><span class="status status-pagado">Pagado</span></td></tr>
                            <tr><td><strong>FV-83950</strong></td><td>15/02/2026</td><td>17/03/2026</td><td>$6,200,000</td><td>$0</td><td>$6,200,000</td><td><span class="status status-rechazado">Vencida</span></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ==================== CODIFICACION ==================== -->
            <div class="page" id="page-codificacion">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                    <div class="tab-bar" style="margin-bottom:0;">
                        <div class="tab active" onclick="showCodTab('masiva')">Archivos</div>
                        <div class="tab" onclick="showCodTab('solicitudes')">Mis solicitudes</div>
                    </div>
                    <!-- Boton "Nueva solicitud" oculto a peticion; se conserva por si se reactiva el formulario individual a futuro.
                    <button class="btn btn-primary" onclick="document.getElementById('modalCodificacion').classList.add('active')">+ NUEVA SOLICITUD</button>
                    -->
                </div>

                <!-- ARCHIVOS: DESCARGA DE PLANTILLAS + CARGA MASIVA -->
                <div id="codTab-masiva">

                <!-- DESCARGA DE PLANTILLAS -->
                <div class="card">
                    <div class="card-title">DESCARGA DE PLANTILLAS</div>
                    <p style="font-size:13px;color:var(--text-light);margin-bottom:20px;">
                        Descarga las plantillas oficiales en Excel para diligenciar tus solicitudes.
                    </p>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;">
                        <div style="border:1px solid #e8e6f0;border-radius:10px;padding:20px;display:flex;flex-direction:column;align-items:flex-start;gap:8px;background:#fff;">
                            <div style="font-size:30px;color:var(--primary);"><i class="fa-solid fa-file-excel"></i></div>
                            <div style="font-size:15px;font-weight:700;color:var(--primary);">Plantilla de Codificaci&oacute;n</div>
                            <div style="font-size:12px;color:var(--text-light);">Formato Excel &bull; .xlsx</div>
                            <a class="btn btn-primary btn-sm" href="Archivos/codificacion.xlsx" download style="margin-top:8px;text-decoration:none;">&#11015; Descargar</a>
                        </div>
                    </div>
                </div>

                <!-- CARGA MASIVA -->
                <div class="card">
                    <div class="card-title">CARGA MASIVA DE CODIFICACI&Oacute;N</div>
                    <p style="font-size:13px;color:var(--text-light);margin-bottom:20px;">
                        Sube un archivo Excel con la Plantilla para codificar m&uacute;ltiples referencias de una sola vez.
                    </p>
                    <div class="upload-excel" id="codDrop">
                        <div class="icon" style="color:var(--primary);"><i class="fa-solid fa-file-excel"></i></div>
                        <p><strong>Arrastra tus archivos Excel aqu&iacute;</strong></p>
                        <p>o haz clic para seleccionar</p>
                        <p style="margin-top:8px;font-size:11px;color:var(--text-light);">.xlsx &mdash; Plantilla (puedes subir varios)</p>
                    </div>
                    <input type="file" id="codFile" accept=".xlsx,.xls" multiple style="display:none;">
                    <div class="upload-steps">
                        <div class="upload-step"><div class="upload-step-num">1</div> Selecciona el/los Excel</div>
                        <div class="upload-step"><div class="upload-step-num">2</div> Revisa la lista</div>
                        <div class="upload-step"><div class="upload-step-num">3</div> Env&iacute;a</div>
                    </div>
                    <div id="codFileList" style="margin-top:16px;"></div>
                    <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                        <button class="btn btn-primary" id="codEnviarBtn" onclick="codEnviar()" disabled>Enviar</button>
                    </div>
                </div>
                </div><!-- /codTab-masiva -->

                <!-- LISTA SOLICITUDES -->
                <div class="card" id="codTab-solicitudes" style="display:none;">
                    <table>
                        <thead><tr><th># Solicitud</th><th>Fecha</th><th>NIT</th><th>Nombre del tercero</th><th>Estado</th></tr></thead>
                        <tbody id="codSolBody">
                            <tr><td colspan="5" style="text-align:center;color:var(--text-light);padding:16px;">Cargando&hellip;</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ==================== DOCUMENTOS ==================== -->
            <div class="page" id="page-documentos">
                <div style="display:flex;justify-content:flex-end;align-items:center;margin-bottom:20px;">
                    <button class="btn btn-primary" onclick="docAbrirModal()">+ SUBIR DOCUMENTO</button>
                </div>
                <div class="card">
                    <table>
                        <thead><tr><th>Tipo</th><th>Documento</th><th>Fecha</th><th>NIT</th><th>Nombre del tercero</th><th></th></tr></thead>
                        <tbody id="docBody">
                            <tr><td colspan="6" style="text-align:center;color:var(--text-light);padding:16px;">Cargando&hellip;</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ==================== INFORME G00 ==================== -->
            <?php include __DIR__ . '/informes/g00.php'; ?>

            <!-- ==================== INFORME O14 ==================== -->
            <?php include __DIR__ . '/informes/o14.php'; ?>

            <!-- ==================== ÍNDICE DE VENTAS (O45) ==================== -->
            <?php include __DIR__ . '/informes/o45.php'; ?>

            <!-- ==================== EVOLUCIÓN HISTÓRICA ==================== -->
            <?php include __DIR__ . '/informes/evol.php'; ?>

            <!-- ==================== GEOREFERENCIACIÓN ==================== -->
            <?php include __DIR__ . '/informes/geo.php'; ?>

            <!-- ==================== ANÁLISIS DE PAGOS ==================== -->
            <?php include __DIR__ . '/informes/pagos.php'; ?>

        </div>
    </div>
</div>

<!-- ==================== MODAL: DOCUMENTOS ==================== -->
<div class="modal-overlay" id="modalDocumento">
    <div class="modal">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3>SUBIR DOCUMENTO</h3>
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('modalDocumento').classList.remove('active')">&#10005; Cerrar</button>
        </div>
        <div class="form-group" style="margin-bottom:16px;">
            <label>Tipo de documento</label>
            <select id="docTipo">
                <option value="">Selecciona&hellip;</option>
                <option value="Contrato">Contrato</option>
                <option value="RUT">RUT</option>
                <option value="Cámara de Comercio">Cámara de Comercio</option>
            </select>
        </div>
        <div class="upload-excel" id="docDrop">
            <div class="icon" style="color:var(--primary);"><i class="fa-solid fa-upload"></i></div>
            <p><strong>Arrastra el archivo aqu&iacute;</strong></p>
            <p>o haz clic para seleccionar</p>
            <p style="margin-top:8px;font-size:11px;color:var(--text-light);">PDF o imagen &mdash; m&aacute;x 10 MB</p>
        </div>
        <input type="file" id="docFile" accept=".pdf,.jpg,.jpeg,.png" style="display:none;">
        <div id="docFileName" style="margin-top:12px;font-size:13px;color:var(--primary);"></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
            <button class="btn btn-secondary" onclick="document.getElementById('modalDocumento').classList.remove('active')">Cancelar</button>
            <button class="btn btn-primary" id="docSubirBtn" onclick="docSubir()" disabled>Subir</button>
        </div>
    </div>
</div>

<!-- ==================== MODAL: NUEVA CODIFICACION ==================== -->
<div class="modal-overlay" id="modalCodificacion">
    <div class="modal">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3>NUEVA SOLICITUD DE CODIFICACI&Oacute;N</h3>
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('modalCodificacion').classList.remove('active')">&#10005; Cerrar</button>
        </div>

        <div class="step-indicator">
            <div class="step active"><span class="step-num">1</span> Referencia</div>
            <div class="step-line"></div>
            <div class="step"><span class="step-num">2</span> Clasificaci&oacute;n</div>
            <div class="step-line"></div>
            <div class="step"><span class="step-num">3</span> Detalle SKU</div>
            <div class="step-line"></div>
            <div class="step"><span class="step-num">4</span> Dispersi&oacute;n</div>
            <div class="step-line"></div>
            <div class="step"><span class="step-num">5</span> Foto</div>
        </div>

        <!-- STEP 1: DATOS REFERENCIA -->
        <div class="section-label">DATOS DE LA REFERENCIA</div>
        <div class="form-grid">
            <div class="form-group">
                <label>Referencia</label>
                <input type="text" placeholder="Ej: 06-160650-3" value="06-160900-1">
            </div>
            <div class="form-group">
                <label>Marca</label>
                <select><option>ORIGINAL PENGUIN</option></select>
            </div>
            <div class="form-group">
                <label>Descripci&oacute;n (m&aacute;x 40 chars)</label>
                <input type="text" placeholder="Descripci&oacute;n del producto" value="ZAPATILLAS DE HOMBRES" maxlength="40">
            </div>
            <div class="form-group">
                <label>Descripci&oacute;n abreviada (m&aacute;x 20)</label>
                <input type="text" placeholder="Desc. corta" value="ZAPATILLAS HOMBRES" maxlength="20">
            </div>
            <div class="form-group">
                <label>Aliado</label>
                <input type="text" value="INTERTENIS S.A.S" disabled style="background:#f0f0f4;">
            </div>
            <div class="form-group">
                <label>NIT</label>
                <input type="text" value="900633781" disabled style="background:#f0f0f4;">
            </div>
            <div class="form-group">
                <label>Unidad de medida</label>
                <select><option>PAR</option><option>UNIDAD</option><option>CAJA</option><option>METRO</option></select>
            </div>
            <div class="form-group">
                <label>Tipo de recepci&oacute;n</label>
                <select><option>PRIMER AVISO</option><option>REPOSICION</option></select>
            </div>
        </div>

        <!-- STEP 2: CLASIFICACION -->
        <div class="section-label">CRITERIOS DE CLASIFICACI&Oacute;N</div>
        <div class="criteria-grid">
            <div class="form-group">
                <label>Origen</label>
                <select><option>IMPORTADO</option><option>NACIONAL</option></select>
            </div>
            <div class="form-group">
                <label>Tipo de producto</label>
                <select><option>CALZADO</option><option>CONFECCI&Oacute;N</option><option>ACCESORIOS</option></select>
            </div>
            <div class="form-group">
                <label>Tipo de fabricaci&oacute;n</label>
                <select><option>ENSAMBLADO</option><option>MONTADO</option><option>INYECTADO AL CORTE</option><option>FULL INYECCI&Oacute;N</option></select>
            </div>
            <div class="form-group">
                <label>Categor&iacute;a</label>
                <select><option>ZAPATOS</option><option>BOTAS</option><option>SANDALIA</option><option>DEPORTIVO</option></select>
            </div>
            <div class="form-group">
                <label>Subcategor&iacute;a</label>
                <select><option>CASUAL</option><option>BOT&Iacute;N</option><option>MEDIA CA&Ntilde;A</option></select>
            </div>
            <div class="form-group">
                <label>G&eacute;nero</label>
                <select><option>MASCULINO</option><option>FEMENINO</option><option>UNISEX</option><option>NO APLICA</option></select>
            </div>
            <div class="form-group">
                <label>P&uacute;blico objetivo</label>
                <select><option>ADULTO</option><option>JUVENIL</option><option>INFANTIL</option><option>PRECAMINADOR</option></select>
            </div>
            <div class="form-group">
                <label>Calidad</label>
                <select><option>PRIMERA</option><option>SEGUNDA</option><option>IMPERFECTA</option></select>
            </div>
            <div class="form-group">
                <label>Rango de talla</label>
                <input type="text" placeholder="Ej: 7 al 11" value="7 al 11">
            </div>
        </div>

        <!-- STEP 3: DETALLE SKU -->
        <div class="section-label">DETALLE POR SKU (COLOR + TALLA)</div>
        <div style="overflow-x:auto;">
            <table class="disp-table" style="min-width:700px;">
                <thead>
                    <tr><th>Referencia</th><th>Color</th><th>Talla</th><th>Cantidad</th><th>Empaque</th><th>EAN</th><th>Costo</th><th>P.V.S.P</th><th>IVA</th></tr>
                </thead>
                <tbody>
                    <tr><td>06-160900-1</td><td>NEGRO/BLANCO</td><td>7.5</td><td><input type="number" value="4" style="width:50px;padding:4px;"></td><td>4</td><td>ASIGNAR</td><td>$219,328</td><td>$290,000</td><td>19%</td></tr>
                    <tr><td>06-160900-1</td><td>NEGRO/BLANCO</td><td>8</td><td><input type="number" value="4" style="width:50px;padding:4px;"></td><td>4</td><td>ASIGNAR</td><td>$219,328</td><td>$290,000</td><td>19%</td></tr>
                    <tr><td>06-160900-1</td><td>NEGRO/BLANCO</td><td>8.5</td><td><input type="number" value="6" style="width:50px;padding:4px;"></td><td>6</td><td>ASIGNAR</td><td>$219,328</td><td>$290,000</td><td>19%</td></tr>
                    <tr><td>06-160900-1</td><td>NEGRO/BLANCO</td><td>9</td><td><input type="number" value="6" style="width:50px;padding:4px;"></td><td>6</td><td>ASIGNAR</td><td>$219,328</td><td>$290,000</td><td>19%</td></tr>
                    <tr><td>06-160900-1</td><td>NEGRO/BLANCO</td><td>9.5</td><td><input type="number" value="6" style="width:50px;padding:4px;"></td><td>6</td><td>ASIGNAR</td><td>$219,328</td><td>$290,000</td><td>19%</td></tr>
                    <tr><td>06-160900-1</td><td>NEGRO/BLANCO</td><td>10</td><td><input type="number" value="6" style="width:50px;padding:4px;"></td><td>6</td><td>ASIGNAR</td><td>$219,328</td><td>$290,000</td><td>19%</td></tr>
                    <tr><td>06-160900-1</td><td>NEGRO/BLANCO</td><td>10.5</td><td><input type="number" value="1" style="width:50px;padding:4px;"></td><td>1</td><td>ASIGNAR</td><td>$219,328</td><td>$290,000</td><td>19%</td></tr>
                    <tr><td>06-160900-1</td><td>NEGRO/BLANCO</td><td>11</td><td><input type="number" value="3" style="width:50px;padding:4px;"></td><td>3</td><td>ASIGNAR</td><td>$219,328</td><td>$290,000</td><td>19%</td></tr>
                    <tr class="disp-total"><td colspan="3">Total</td><td>36</td><td colspan="5"></td></tr>
                </tbody>
            </table>
        </div>

        <!-- STEP 4: DISPERSION -->
        <div class="section-label">DISPERSI&Oacute;N A TIENDAS AKA</div>
        <div style="overflow-x:auto;">
            <table class="disp-table" style="min-width:700px;">
                <thead>
                    <tr><th>Tienda</th><th>7.5</th><th>8</th><th>8.5</th><th>9</th><th>9.5</th><th>10</th><th>10.5</th><th>11</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <tr><td style="text-align:left;font-weight:600;">AKA Outlet Am&eacute;ricas BOG</td><td>0</td><td>1</td><td>1</td><td>1</td><td>1</td><td>1</td><td>1</td><td>0</td><td><strong>6</strong></td></tr>
                    <tr><td style="text-align:left;font-weight:600;">AKA Unicentro C&uacute;cuta</td><td>1</td><td>0</td><td>1</td><td>1</td><td>1</td><td>1</td><td>0</td><td>1</td><td><strong>6</strong></td></tr>
                    <tr><td style="text-align:left;font-weight:600;">AKA Centro Armenia</td><td>1</td><td>1</td><td>1</td><td>1</td><td>1</td><td>1</td><td>0</td><td>0</td><td><strong>6</strong></td></tr>
                    <tr><td style="text-align:left;font-weight:600;">AKA CC Viva Envigado</td><td>0</td><td>0</td><td>1</td><td>1</td><td>1</td><td>1</td><td>0</td><td>1</td><td><strong>5</strong></td></tr>
                    <tr><td style="text-align:left;font-weight:600;">CEDI Cauchosol</td><td>2</td><td>2</td><td>2</td><td>2</td><td>2</td><td>2</td><td>0</td><td>1</td><td><strong>13</strong></td></tr>
                    <tr class="disp-total"><td style="text-align:left;">Total</td><td>4</td><td>4</td><td>6</td><td>6</td><td>6</td><td>6</td><td>1</td><td>3</td><td><strong>36</strong></td></tr>
                </tbody>
            </table>
        </div>

        <!-- STEP 5: FOTO -->
        <div class="section-label">FOTO DEL PRODUCTO (obligatoria)</div>
        <div class="upload-area">
            <div class="icon">&#128247;</div>
            <p><strong>Arrastra la foto del producto aqu&iacute;</strong></p>
            <p>o haz clic para seleccionar archivo</p>
            <p style="margin-top:8px;font-size:11px;">JPG, PNG &mdash; m&aacute;x 5MB</p>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:18px;border-top:1px solid var(--border);">
            <button class="btn btn-secondary" onclick="document.getElementById('modalCodificacion').classList.remove('active')">Cancelar</button>
            <button class="btn" style="background:var(--primary);color:white;">Guardar borrador</button>
            <button class="btn btn-primary">ENVIAR SOLICITUD</button>
        </div>
    </div>
</div>

<!-- Chatbot AKA retirado (2026-06-24): no se usa por ahora. -->

<script>
    function showPage(pageId, navItem) {
        document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
        document.getElementById('page-' + pageId).classList.add('active');
        if (navItem) navItem.classList.add('active');
        const titles = {
            dashboard:'DASHBOARD', ventas:'VENTAS', inventarios:'INVENTARIOS Y ENCURVAMIENTO',
            pagos:'PAGOS Y FACTURAS', codificacion:'CODIFICACI\u00d3N',
            documentos:'DOCUMENTACI\u00d3N', alertas:'ALERTAS',
            'informes-g00':'DASHBOARD DE VENTAS',
            'informes-o14':'SIEMBRA / STOCK / VENTAS',
            'informes-o45':'ÍNDICE DE VENTAS',
            'evolucion-historica':'EVOLUCIÓN HISTÓRICA',
            'georreferenciacion':'GEOREFERENCIACIÓN',
            'informes-pagos':'ANÁLISIS DE PAGOS'
        };
        document.getElementById('pageTitle').textContent = titles[pageId] || pageId;
        // Extras del topbar exclusivos de G00: se ocultan al cambiar de página (g00OnEnter los reactiva).
        document.getElementById('pageSubtitle').style.display = 'none';
        document.getElementById('topbar').classList.remove('topbar--g00');
        document.getElementById('topbar').classList.remove('topbar--o14');
        document.getElementById('topbarDates').style.display = 'none';
        if (pageId === 'informes-g00' && typeof g00OnEnter === 'function') g00OnEnter();
        if (pageId === 'informes-o14' && typeof o14OnEnter === 'function') o14OnEnter();
        if (pageId === 'informes-o45' && typeof o45OnEnter === 'function') o45OnEnter();
        if (pageId === 'evolucion-historica' && typeof evolOnEnter === 'function') evolOnEnter();
        if (pageId === 'georreferenciacion' && typeof geoOnEnter === 'function') geoOnEnter();
        if (pageId === 'informes-pagos' && typeof pgOnEnter === 'function') pgOnEnter();
        if (pageId === 'documentos' && typeof cargarDocumentos === 'function') cargarDocumentos();
    }
    document.getElementById('modalCodificacion').addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('active');
    });

    // Carga masiva tabs
    function showCodTab(tab) {
        document.getElementById('codTab-solicitudes').style.display = tab === 'solicitudes' ? '' : 'none';
        document.getElementById('codTab-masiva').style.display = tab === 'masiva' ? '' : 'none';
        document.querySelectorAll('#page-codificacion .tab').forEach((t,i) => {
            t.classList.toggle('active', (tab === 'masiva' && i === 0) || (tab === 'solicitudes' && i === 1));
        });
        if (tab === 'solicitudes') cargarSolicitudes();
    }

    // ===== Mis solicitudes (historial) =====
    function codSolEsc(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function codSolBadge(estado){
        const map = { 'Estudio':'status-estudio', 'Rechazado':'status-rechazado', 'Aprobado':'status-aprobado' };
        const cls = map[estado] || 'status-pendiente';
        return `<span class="status ${cls}">${codSolEsc(estado)}</span>`;
    }
    function codSolFecha(iso){
        if (!iso) return '—';
        const p = String(iso).split('-');
        return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : codSolEsc(iso);
    }
    function cargarSolicitudes(){
        const body = document.getElementById('codSolBody');
        if (!body) return;
        body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-light);padding:16px;">Cargando…</td></tr>';
        fetch('api/codificacion_solicitudes.php')
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--danger);padding:16px;">No se pudieron cargar las solicitudes.</td></tr>'; return; }
                if (!d.solicitudes.length) { body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-light);padding:16px;">No tienes solicitudes registradas.</td></tr>'; return; }
                body.innerHTML = d.solicitudes.map(s =>
                    `<tr><td><strong>${parseInt(s.consecutivo,10)}</strong></td><td>${codSolFecha(s.fecha)}</td><td>${s.nit ? codSolEsc(s.nit) : '—'}</td><td>${codSolEsc(s.nombre)}</td><td>${codSolBadge(s.estado)}</td></tr>`
                ).join('');
            })
            .catch(() => { body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--danger);padding:16px;">Error de conexión.</td></tr>'; });
    }

    // ===== Carga Masiva de Codificación =====
    let codArchivos = [];
    (function initCodCarga(){
        const drop = document.getElementById('codDrop');
        const input = document.getElementById('codFile');
        if (!drop || !input) return;
        drop.addEventListener('click', () => input.click());
        input.addEventListener('change', () => { codAgregar(input.files); input.value=''; });
        ['dragover','dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor='var(--accent)'; }));
        ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor=''; }));
        drop.addEventListener('drop', e => codAgregar(e.dataTransfer.files));
    })();
    function codAgregar(fileList){
        for (const f of fileList){
            const ext = f.name.split('.').pop().toLowerCase();
            if (ext!=='xlsx' && ext!=='xls'){ Swal.fire('Archivo no válido', f.name+' no es un Excel (.xlsx/.xls)', 'warning'); continue; }
            if (f.size > 10*1024*1024){ Swal.fire('Archivo muy grande', f.name+' supera el máximo de 10 MB.', 'warning'); continue; }
            if (codArchivos.length >= 10){ Swal.fire('Demasiados archivos', 'Máximo 10 archivos por envío.', 'warning'); break; }
            codArchivos.push(f);
        }
        codRender();
    }
    function codQuitar(i){ codArchivos.splice(i,1); codRender(); }
    function codEsc(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function codRender(){
        const cont = document.getElementById('codFileList');
        const btn = document.getElementById('codEnviarBtn');
        if (!cont || !btn) return;
        cont.innerHTML = codArchivos.map((f,i) =>
            `<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#f8f7fc;border-radius:6px;margin-bottom:6px;">
               <span style="font-size:13px;color:var(--primary);"><i class="fa-solid fa-file-excel" style="color:var(--primary);"></i> ${codEsc(f.name)} <span style="color:var(--text-light);font-size:11px;">(${(f.size/1024).toFixed(0)} KB)</span></span>
               <button class="btn btn-secondary btn-sm" onclick="codQuitar(${i})">Quitar</button>
             </div>`).join('');
        btn.disabled = codArchivos.length === 0;
    }
    function codEnviar(){
        if (codArchivos.length === 0) return;
        const fd = new FormData();
        codArchivos.forEach(f => fd.append('adjunto[]', f));
        Swal.fire({ title:'Enviando…', html:'Registrando tu envío y notificando al equipo.', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
        fetch('api/codificacion_cargar.php', { method:'POST', body:fd })
            .then(r => r.json())
            .then(d => {
                if (d.status === 'success'){ Swal.fire('¡Enviado!', d.message, 'success'); codArchivos=[]; codRender(); }
                else if (d.status === 'warning'){ Swal.fire('Registrado', d.message, 'warning'); codArchivos=[]; codRender(); }
                else { Swal.fire('Error', d.message || 'No se pudo enviar.', 'error'); }
            })
            .catch(() => Swal.fire('Error', 'Falló la conexión con el servidor.', 'error'));
    }

    // ===== Documentación =====
    let docArchivo = null;
    (function initDoc(){
        const drop = document.getElementById('docDrop');
        const input = document.getElementById('docFile');
        if (!drop || !input) return;
        drop.addEventListener('click', () => input.click());
        input.addEventListener('change', () => { docSet(input.files[0]); input.value=''; });
        ['dragover','dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor='var(--accent)'; }));
        ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor=''; }));
        drop.addEventListener('drop', e => { if (e.dataTransfer.files[0]) docSet(e.dataTransfer.files[0]); });
        const ov = document.getElementById('modalDocumento');
        if (ov) ov.addEventListener('click', function(e){ if (e.target === this) this.classList.remove('active'); });
        const sel = document.getElementById('docTipo');
        if (sel) sel.addEventListener('change', docToggleBtn);
    })();
    function docEsc(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function docSet(f){
        if (!f) return;
        const ext = f.name.split('.').pop().toLowerCase();
        if (!['pdf','jpg','jpeg','png'].includes(ext)) { Swal.fire('Archivo no válido', 'Solo PDF o imágenes (.pdf, .jpg, .jpeg, .png).', 'warning'); return; }
        if (f.size > 10*1024*1024) { Swal.fire('Archivo muy grande', f.name+' supera el máximo de 10 MB.', 'warning'); return; }
        docArchivo = f;
        document.getElementById('docFileName').innerHTML = '<i class="fa-solid fa-file" style="color:var(--primary);"></i> ' + docEsc(f.name);
        docToggleBtn();
    }
    function docToggleBtn(){
        const tipo = document.getElementById('docTipo').value;
        document.getElementById('docSubirBtn').disabled = !(tipo && docArchivo);
    }
    function docAbrirModal(){
        docArchivo = null;
        document.getElementById('docTipo').value = '';
        document.getElementById('docFileName').innerHTML = '';
        document.getElementById('docSubirBtn').disabled = true;
        document.getElementById('modalDocumento').classList.add('active');
    }
    function docSubir(){
        const tipo = document.getElementById('docTipo').value;
        if (!tipo || !docArchivo) return;
        const fd = new FormData();
        fd.append('tipo', tipo);
        fd.append('documento', docArchivo);
        Swal.fire({ title:'Subiendo…', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
        fetch('api/documentos_subir.php', { method:'POST', body:fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) { document.getElementById('modalDocumento').classList.remove('active'); Swal.fire('¡Subido!', 'El documento se guardó correctamente.', 'success'); cargarDocumentos(); }
                else { Swal.fire('Error', d.error || 'No se pudo subir.', 'error'); }
            })
            .catch(() => Swal.fire('Error', 'Falló la conexión con el servidor.', 'error'));
    }
    function docFecha(s){
        if (!s) return '—';
        const parts = String(s).split(' ');
        const p = parts[0].split('-');
        return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` + (parts[1] ? ` ${parts[1]}` : '') : docEsc(s);
    }
    function cargarDocumentos(){
        const body = document.getElementById('docBody');
        if (!body) return;
        body.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-light);padding:16px;">Cargando…</td></tr>';
        fetch('api/documentos_listar.php')
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { body.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--danger);padding:16px;">No se pudieron cargar los documentos.</td></tr>'; return; }
                if (!d.documentos.length) { body.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-light);padding:16px;">No tienes documentos cargados.</td></tr>'; return; }
                body.innerHTML = d.documentos.map(x =>
                    `<tr><td>${docEsc(x.tipo)}</td><td>${docEsc(x.nombre_archivo)}</td><td>${docFecha(x.fecha)}</td><td>${x.nit ? docEsc(x.nit) : '—'}</td><td>${docEsc(x.nombre)}</td><td><a class="btn btn-secondary btn-sm" href="api/documentos_descargar.php?id=${parseInt(x.id,10)}" style="text-decoration:none;">Descargar</a></td></tr>`
                ).join('');
            })
            .catch(() => { body.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--danger);padding:16px;">Error de conexión.</td></tr>'; });
    }


    // ===== Zebra por filas VISIBLES en tablas disp-table (G00 + pagos) =====
    // nth-child cuenta filas ocultas de los desplegables; por eso se raya con JS sobre las visibles.
    function restripeTable(table) {
        if (!table) return;
        let v = 0;
        table.querySelectorAll('tr').forEach(tr => {
            tr.classList.remove('zebra');
            if (tr.parentElement && tr.parentElement.tagName === 'THEAD') return;
            if (tr.style.display === 'none') return;
            if (tr.classList.contains('g00-total')) return;
            v++;
            if (v % 2 === 0) tr.classList.add('zebra');
        });
    }
    function setupZebra() {
        document.querySelectorAll('table.disp-table').forEach(t => {
            restripeTable(t);
            new MutationObserver(() => restripeTable(t)).observe(t, { childList: true, subtree: true });
            t.addEventListener('click', () => setTimeout(() => restripeTable(t), 0));
        });
    }
    setupZebra();

    // Al iniciar sesión, cargar Ventas (informe G00) por defecto.
    showPage('informes-g00', document.querySelector('.nav-item[onclick*="informes-g00"]'));
</script>
</body>
</html>
