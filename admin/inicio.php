<?php
	require_once __DIR__ . '/lib_admin_auth.php';
	$admin = admin_exigir_sesion();

	// Punto de extensión: cada módulo futuro se agrega como una entrada aquí.
	// Vacío muestra "Próximamente" en el menú.
	$menu = array(
		// array('etiqueta' => 'Usuarios', 'icono' => 'fa-users', 'url' => 'usuarios.php'),
	);
?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>AKA 2.0 — Administración</title>
	<link rel="shortcut icon" href="../img/aka.ico" type="image/x-icon">
	<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="../awesome/css/fontawesome.min.css">
	<link rel="stylesheet" href="../awesome/css/solid.min.css">
	<link rel="stylesheet" href="assets/admin.css">
</head>
<body>

<div class="admin-barra">
	<img src="../img/logo_aka.png" alt="AKA">
	<span class="titulo">Administración</span>
	<span class="espaciador"></span>
	<span class="usuario"><?php echo htmlspecialchars($admin['usuario']); ?></span>
	<a href="logout.php" class="salir" title="Cerrar sesión"><i class="fa-solid fa-power-off"></i></a>
</div>

<nav class="admin-menu">
	<div class="encabezado">Menú</div>
	<?php if (empty($menu)) { ?>
		<div class="vacio">Próximamente</div>
	<?php } else { ?>
		<?php foreach ($menu as $item) { ?>
			<a href="<?php echo htmlspecialchars($item['url']); ?>">
				<i class="fa-solid <?php echo htmlspecialchars($item['icono']); ?>"></i>
				<?php echo htmlspecialchars($item['etiqueta']); ?>
			</a>
		<?php } ?>
	<?php } ?>
</nav>

<main class="admin-contenido">
	<h1>Bienvenido, <?php echo htmlspecialchars($admin['usuario']); ?></h1>
	<p class="subtitulo">Módulo administrativo de AKA 2.0.</p>
	<div class="admin-tarjeta">
		<i class="fa-solid fa-screwdriver-wrench" style="color:var(--primary-light);margin-right:8px;"></i>
		Este módulo está en construcción. Las secciones aparecerán en el menú de la izquierda
		a medida que se vayan habilitando.
	</div>
</main>

</body>
</html>
