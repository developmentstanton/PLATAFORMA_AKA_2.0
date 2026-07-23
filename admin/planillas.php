<?php
	require_once __DIR__ . '/lib_admin_auth.php';
	require_once __DIR__ . '/lib_planillas.php';
	$admin = admin_exigir_sesion();
?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['admin_csrf_token'] ?? ''); ?>">
	<title>Planillas — Administración AKA 2.0</title>
	<link rel="shortcut icon" href="../img/aka.ico" type="image/x-icon">
	<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="../awesome/css/fontawesome.min.css">
	<link rel="stylesheet" href="../awesome/css/solid.min.css">
	<link rel="stylesheet" href="assets/admin.css">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-dt@2.1.8/css/dataTables.dataTables.min.css">
	<style>
		.planillas-barra {
			display: flex; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;
		}
		.planillas-barra label {
			font-size: 12px; letter-spacing: 1px; text-transform: uppercase; color: var(--text-light);
		}
		.planillas-barra select {
			font-family: 'Space Grotesk', sans-serif; font-size: 14px;
			padding: 6px 10px; border: 1px solid #d5d5de; background: #fff; color: var(--text);
		}
		.status {
			display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600;
			border-radius: 12px; white-space: nowrap;
		}
		.status-estudio   { background: #ede9fe; color: #4A4782; }
		.status-aprobado  { background: #ecfdf5; color: #065f46; }
		.status-rechazado { background: #fef2f2; color: #991b1b; }
		.status-otro      { background: #fef3c7; color: #92400e; }
		.btn-estado {
			font-family: 'Space Grotesk', sans-serif; font-size: 12px; font-weight: 600;
			background: var(--primary); color: #fff; border: none; padding: 6px 12px;
			cursor: pointer; transition: background 0.15s; white-space: nowrap;
		}
		.btn-estado:hover { background: var(--accent); }
		table.dataTable { font-size: 14px; }
		.dt-empty { color: var(--text-light); font-style: italic; }
		/* Sin menú lateral: el contenido ocupa todo el ancho (admin.css deja hueco para el menú). */
		.admin-contenido { margin-left: 0; }
	</style>
</head>
<body>

<div class="admin-barra">
	<img src="../img/logo_aka.png" alt="AKA">
	<span class="titulo">Administración</span>
	<span class="espaciador"></span>
	<span class="usuario"><?php echo htmlspecialchars($admin['usuario']); ?></span>
	<a href="logout.php" class="salir" title="Cerrar sesión"><i class="fa-solid fa-power-off"></i></a>
</div>

<main class="admin-contenido">
	<h1>Planillas</h1>
	<p class="subtitulo">Codificaciones enviadas por los aliados.</p>

	<div class="admin-tarjeta">
		<div class="planillas-barra">
			<label for="filtroEstado">Estado</label>
			<select id="filtroEstado">
				<option value="Estudio">Estudio</option>
				<option value="Aprobado">Aprobado</option>
				<option value="Rechazado">Rechazado</option>
				<option value="">Todos</option>
			</select>
		</div>

		<table id="tablaPlanillas" class="display" style="width:100%">
			<thead>
				<tr>
					<th>Consecutivo</th>
					<th>Cliente</th>
					<th>NIT</th>
					<th>Fecha</th>
					<th>Estado</th>
					<th>Motivo</th>
					<th>Acción</th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net@2.1.8/js/dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const CSRF     = document.querySelector('meta[name="csrf-token"]').content;
const ESTADOS  = <?php echo json_encode(PLANILLA_ESTADOS, JSON_UNESCAPED_UNICODE); ?>;
const MOTIVOS  = <?php echo json_encode(PLANILLA_MOTIVOS, JSON_UNESCAPED_UNICODE); ?>;

function esc(s) {
	return String(s ?? '').replace(/[&<>"']/g, c => ({
		'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
	}[c]));
}

function badge(estado) {
	const map = { 'Estudio':'status-estudio', 'Aprobado':'status-aprobado', 'Rechazado':'status-rechazado' };
	const cls = map[estado] || 'status-otro';
	return '<span class="status ' + cls + '">' + esc(estado) + '</span>';
}

function fechaCorta(iso) {
	if (!iso) return '—';
	const p = String(iso).split('-');
	return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : esc(iso);
}

// Si la sesión murió, avisar en vez de dejar un error de parseo.
function sesionExpirada() {
	Swal.fire({
		icon: 'warning',
		title: 'Tu sesión expiró',
		text: 'Vuelve a iniciar sesión para continuar.',
		confirmButtonText: 'Ir al login',
		confirmButtonColor: '#4A4782',
		allowOutsideClick: false
	}).then(() => { window.location.href = 'index.php?expired=1'; });
}

let tabla = null;

$(function () {
	tabla = new DataTable('#tablaPlanillas', {
		order: [[0, 'desc']],
		pageLength: 25,
		columns: [
			{ data: 'consecutivo' },
			{ data: 'nombre_cliente', render: (d) => esc(d) },
			{ data: 'nit',    render: (d) => d ? esc(d) : '—' },
			{ data: 'fecha',  render: (d, tipo) => tipo === 'display' ? fechaCorta(d) : (d || '') },
			{ data: 'estado', render: (d, tipo) => tipo === 'display' ? badge(d) : (d || '') },
			{ data: 'motivo', render: (d) => d ? esc(d) : '—' },
			{
				data: null, orderable: false, searchable: false,
				render: (fila) => '<button class="btn-estado" data-cons="' + fila.consecutivo + '">Cambiar estado</button>'
			}
		],
		language: {
			emptyTable: '<span class="dt-empty">No hay planillas pendientes de revisar.</span>',
			zeroRecords: '<span class="dt-empty">Ninguna planilla coincide con el filtro.</span>',
			info: 'Mostrando _START_ a _END_ de _TOTAL_ planillas',
			infoEmpty: 'Sin planillas',
			infoFiltered: '(filtrado de _MAX_ en total)',
			lengthMenu: 'Mostrar _MENU_',
			search: 'Buscar:',
			paginate: { first: 'Primera', last: 'Última', next: 'Siguiente', previous: 'Anterior' }
		}
	});

	// Arranca filtrada en las pendientes. Regex anclado para que 'Estudio' no
	// coincida con nada más.
	tabla.column(4).search('^Estudio$', true, false).draw();

	$('#filtroEstado').on('change', function () {
		const v = this.value;
		tabla.column(4).search(v ? '^' + v + '$' : '', true, false).draw();
	});

	cargar();

	$('#tablaPlanillas tbody').on('click', '.btn-estado', function () {
		const cons = parseInt(this.dataset.cons, 10);
		const fila = tabla.rows().data().toArray().find(f => f.consecutivo === cons);
		if (fila) abrirDialogo(fila);
	});
});

function cargar() {
	fetch('api/planillas_listar.php', { credentials: 'same-origin' })
		.then(r => {
			if (r.status === 401) { sesionExpirada(); return null; }
			return r.json();
		})
		.then(j => {
			if (!j) return;
			if (!j.ok) { Swal.fire({ icon:'error', title:'Error', text: j.error || 'No se pudieron cargar las planillas.', confirmButtonColor:'#4A4782' }); return; }
			tabla.clear();
			tabla.rows.add(j.filas);
			tabla.draw();
		})
		.catch(() => {
			Swal.fire({ icon:'error', title:'Sin conexión', text:'No se pudieron cargar las planillas.', confirmButtonColor:'#4A4782' });
		});
}

function abrirDialogo(fila) {
	const opcEstados = ESTADOS
		.map(e => '<option value="' + esc(e) + '"' + (e === fila.estado ? ' selected' : '') + '>' + esc(e) + '</option>')
		.join('');
	const opcMotivos = ['<option value="">— Selecciona un motivo —</option>']
		.concat(MOTIVOS.map(m => '<option value="' + esc(m) + '"' + (m === fila.motivo ? ' selected' : '') + '>' + esc(m) + '</option>'))
		.join('');

	Swal.fire({
		title: 'Planilla ' + fila.consecutivo,
		html:
			'<div style="text-align:left;font-size:14px;">' +
				'<p style="margin:0 0 14px;color:#7b7894;">' + esc(fila.nombre_cliente) + '</p>' +
				'<label style="display:block;font-size:12px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:#4A4782;margin-bottom:4px;">Estado</label>' +
				'<select id="swEstado" class="swal2-select" style="width:100%;margin:0 0 14px;">' + opcEstados + '</select>' +
				'<div id="swMotivoBox" style="display:none;">' +
					'<label style="display:block;font-size:12px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:#4A4782;margin-bottom:4px;">Motivo del rechazo</label>' +
					'<select id="swMotivo" class="swal2-select" style="width:100%;margin:0;">' + opcMotivos + '</select>' +
				'</div>' +
			'</div>',
		showCancelButton: true,
		confirmButtonText: 'Guardar',
		cancelButtonText: 'Cancelar',
		confirmButtonColor: '#4A4782',
		cancelButtonColor: '#7b7894',
		didOpen: () => {
			const sel = document.getElementById('swEstado');
			const box = document.getElementById('swMotivoBox');
			const mot = document.getElementById('swMotivo');
			const sincronizar = () => {
				const esRechazo = sel.value === 'Rechazado';
				box.style.display = esRechazo ? 'block' : 'none';
				mot.disabled = !esRechazo;
				if (!esRechazo) mot.value = '';   // no arrastrar el motivo si ya no se rechaza
			};
			sel.addEventListener('change', sincronizar);
			sincronizar();
		},
		preConfirm: () => {
			const estado = document.getElementById('swEstado').value;
			const motivo = document.getElementById('swMotivo').value;
			// Comodidad para el usuario. La barrera real está en el servidor.
			if (estado === 'Rechazado' && !motivo) {
				Swal.showValidationMessage('Debes indicar el motivo del rechazo.');
				return false;
			}
			return { estado, motivo };
		}
	}).then(res => {
		if (res.isConfirmed) guardar(fila.consecutivo, res.value.estado, res.value.motivo);
	});
}

function guardar(consecutivo, estado, motivo) {
	const cuerpo = new URLSearchParams();
	cuerpo.set('consecutivo', consecutivo);
	cuerpo.set('estado', estado);
	cuerpo.set('motivo', motivo || '');
	cuerpo.set('csrf_token', CSRF);

	fetch('api/planillas_estado.php', {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
		body: cuerpo.toString()
	})
	.then(r => {
		if (r.status === 401) { sesionExpirada(); return null; }
		return r.json();
	})
	.then(j => {
		if (!j) return;
		if (!j.ok) {
			Swal.fire({ icon:'error', title:'No se guardó', text: j.error || 'No se pudo guardar el cambio.', confirmButtonColor:'#4A4782' });
			return;   // la tabla NO se toca: seguiría mintiendo respecto a la base
		}
		Swal.fire({
			icon: 'success', title: 'Estado actualizado',
			toast: true, position: 'top-end', showConfirmButton: false, timer: 2200, timerProgressBar: true
		});
		cargar();   // releer de la base, no adivinar
	})
	.catch(() => {
		Swal.fire({ icon:'error', title:'Sin conexión', text:'No se pudo guardar el cambio.', confirmButtonColor:'#4A4782' });
	});
}
</script>
</body>
</html>
