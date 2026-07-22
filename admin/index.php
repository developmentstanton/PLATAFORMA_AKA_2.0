<?php
	require_once __DIR__ . '/lib_admin_auth.php';
	admin_iniciar_sesion();

	// Si ya hay sesión admin viva, no tiene sentido mostrar el formulario
	if (admin_estado_sesion($_SESSION, time()) === 'ok') {
		header('Location: inicio.php');
		exit;
	}

	$loginError = '';
	$loginAviso = isset($_GET['expired']) ? 'Tu sesión expiró por inactividad.' : '';

	// Contadores de fuerza bruta PROPIOS del admin: un aliado que falla en el portal
	// no debe bloquear el ingreso administrativo, ni al revés.
	if (!isset($_SESSION['admin_login_intentos'])) {
		$_SESSION['admin_login_intentos'] = 0;
		$_SESSION['admin_login_ultimo'] = 0;
	}

	$bloqueado = false;
	if ($_SESSION['admin_login_intentos'] >= ADMIN_MAX_INTENTOS) {
		$restante = ADMIN_BLOQUEO_SEG - (time() - $_SESSION['admin_login_ultimo']);
		if ($restante > 0) {
			$bloqueado = true;
			$minutos = ceil($restante / 60);
			$loginError = "Demasiados intentos fallidos. Intenta de nuevo en $minutos minuto(s).";
		} else {
			$_SESSION['admin_login_intentos'] = 0;
		}
	}

	// Token CSRF propio del admin: compartir el del portal haría que iniciar sesión
	// en un lado invalidara el formulario abierto del otro.
	if (empty($_SESSION['admin_csrf_token'])) {
		$_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
	}

	if (!$bloqueado && !empty($_POST['username']) && !empty($_POST['pass'])) {
		if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $_POST['csrf_token'])) {
			$loginError = "Solicitud no válida. Recarga la página e intenta de nuevo.";
		} else {
			require __DIR__ . '/../conexion/conexion_integracion.php';

			if ($dbConnect === false) {
				$loginError = "No fue posible validar el ingreso. Intenta más tarde.";
			} else {
				$admin = admin_autenticar($dbConnect, $_POST['username'], $_POST['pass']);

				if ($admin !== null) {
					$_SESSION['admin_login_intentos'] = 0;
					session_regenerate_id(true);

					$_SESSION['admin'] = array(
						'usuario'          => $admin['nombre_usuario'],
						'correo'           => $admin['correo'],
						'imagen'           => $admin['imagen'],
						'ultima_actividad' => time(),
					);

					admin_registrar_evento($dbConnect, $admin['nombre_usuario'], 'ADMIN_IN');
					sqlsrv_close($dbConnect);

					header('Location: inicio.php');
					exit;
				}

				// Mismo mensaje para clave mala, usuario inexistente y usuario sin rol.
				$_SESSION['admin_login_intentos']++;
				$_SESSION['admin_login_ultimo'] = time();
				$quedan = ADMIN_MAX_INTENTOS - $_SESSION['admin_login_intentos'];
				if ($quedan > 0) {
					$loginError = "Usuario o contraseña incorrectos. Te quedan $quedan intento(s).";
				} else {
					$loginError = "Demasiados intentos fallidos. Intenta de nuevo en 15 minutos.";
					$bloqueado = true;
				}
				sqlsrv_close($dbConnect);
			}
		}
	}
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
	<link rel="stylesheet" href="assets/login.css">
</head>
<body>

<div class="login-screen">
	<div class="login-box">
		<div class="login-logo">
			<img src="../img/logo_aka.png" alt="AKA">
			<div class="logo-sub">ADMINISTRACIÓN</div>
		</div>
		<div class="login-form">
			<?php if ($loginAviso && !$loginError) { ?>
				<div class="login-aviso">
					<i class="fa-solid fa-clock" style="margin-right:6px;"></i>
					<?php echo htmlspecialchars($loginAviso); ?>
				</div>
			<?php } ?>
			<?php if ($loginError) { ?>
				<div class="login-alert">
					<i class="fa-solid fa-circle-exclamation" style="margin-right:6px;"></i>
					<?php echo htmlspecialchars($loginError); ?>
				</div>
			<?php } ?>
			<form action="" method="post" id="loginForm">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_csrf_token']); ?>">
				<label>USUARIO</label>
				<input type="text" name="username" id="username" placeholder="Ingrese su usuario" autocomplete="username" required>
				<label>CONTRASEÑA</label>
				<div class="password-wrapper">
					<input type="password" name="pass" id="pass" placeholder="&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;" autocomplete="current-password" required>
					<button type="button" class="password-toggle" id="passwordToggle" aria-label="Mostrar contraseña" tabindex="-1">
						<i class="fa-solid fa-eye" id="eyeIcon"></i>
					</button>
				</div>
				<button type="submit" class="login-btn" id="btn_login" <?php echo $bloqueado ? 'disabled' : ''; ?>>INGRESAR</button>
			</form>
		</div>
	</div>
</div>

<script>
	document.getElementById('passwordToggle').addEventListener('click', function() {
		var input = document.getElementById('pass');
		var icon = document.getElementById('eyeIcon');
		if (input.type === 'password') {
			input.type = 'text';
			icon.className = 'fa-solid fa-eye-slash';
		} else {
			input.type = 'password';
			icon.className = 'fa-solid fa-eye';
		}
	});

	document.getElementById('loginForm').addEventListener('submit', function() {
		var btn = document.getElementById('btn_login');
		btn.textContent = 'VERIFICANDO...';
		btn.disabled = true;
	});

	window.addEventListener('DOMContentLoaded', function() {
		var u = document.getElementById('username');
		if (u) u.focus();
	});
</script>
</body>
</html>
