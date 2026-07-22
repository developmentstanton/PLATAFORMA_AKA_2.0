<?php
	// Cookies de sesión seguras (deben fijarse ANTES de session_start)
	ini_set('session.cookie_httponly', 1);
	ini_set('session.cookie_samesite', 'Strict');
	ini_set('session.use_strict_mode', 1);
	session_start();

	require_once __DIR__ . '/lib_admin_auth.php';

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
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		:root {
			--primary: #4A4782;
			--primary-dark: #3a3768;
			--primary-light: #5c59a0;
			--accent: #ff001e;
			--text: #2d2b4e;
			--text-light: #7b7894;
			--gray-bg: #cacaca;
		}
		body { font-family: 'Space Grotesk', system-ui, sans-serif; color: var(--text); margin: 0; }

		.login-screen {
			display: flex; align-items: center; justify-content: center;
			min-height: 100vh;
			background: url('../img/bg.jpeg') no-repeat center center;
			background-size: cover;
			position: relative;
		}
		.login-screen::before {
			content: ''; position: absolute; inset: 0;
			background: rgba(45, 43, 78, 0.5);
		}
		.login-box {
			background: #cacaca; padding: 0; width: 360px;
			box-shadow: 0 0 20px rgba(0,0,0,0.3); overflow: hidden;
			position: relative; z-index: 1;
		}
		.login-logo { background: var(--primary); padding: 32px; text-align: center; }
		.login-logo img { width: 65%; max-width: 200px; height: auto; }
		.login-logo .logo-sub {
			font-size: 11px; color: rgba(255,255,255,0.6); letter-spacing: 3px; margin-top: 4px;
		}
		.login-form { padding: 32px 40px 40px; }
		.login-form label {
			display: block; font-size: 13px; font-weight: 600; color: var(--primary);
			margin-bottom: 4px; letter-spacing: 1px; text-transform: uppercase;
		}
		.login-form input[type="text"],
		.login-form input[type="password"] {
			width: 100%; padding: 10px 0; border: none; border-bottom: 2px solid var(--primary);
			background: transparent; outline: none; font-size: 15px; color: var(--primary);
			font-family: 'Space Grotesk', sans-serif; margin-bottom: 24px;
		}
		.login-form input::placeholder { color: rgba(74,71,130,0.5); }
		.login-form input:focus { border-bottom-color: var(--accent); }

		.password-wrapper { position: relative; margin-bottom: 24px; }
		.password-wrapper input[type="password"],
		.password-wrapper input[type="text"] { margin-bottom: 0 !important; padding-right: 36px; }
		.password-toggle {
			position: absolute; right: 0; top: 50%; transform: translateY(-50%);
			background: none; border: none; color: var(--primary-light);
			cursor: pointer; padding: 4px 6px; font-size: 14px; transition: color 0.2s;
		}
		.password-toggle:hover { color: var(--primary); }

		.login-btn {
			background: var(--accent); color: white; border: none; padding: 12px 32px;
			font-size: 16px; font-weight: 700; cursor: pointer; letter-spacing: 1px;
			font-family: 'Space Grotesk', sans-serif; transition: all 0.2s; width: 100%;
		}
		.login-btn:hover { background: var(--primary); }
		.login-btn:disabled { background: var(--gray-bg); cursor: not-allowed; opacity: 0.8; }

		.login-alert {
			background: rgba(255, 0, 30, 0.1); color: var(--accent);
			padding: 10px 14px; font-size: 13px; font-weight: 600; text-align: center;
			margin-bottom: 16px; border: 1px solid rgba(255, 0, 30, 0.2);
			animation: shake 0.5s ease-in-out;
		}
		.login-aviso {
			background: rgba(74, 71, 130, 0.1); color: var(--primary);
			padding: 10px 14px; font-size: 13px; font-weight: 600; text-align: center;
			margin-bottom: 16px; border: 1px solid rgba(74, 71, 130, 0.2);
		}

		@keyframes shake {
			0%, 100% { transform: translateX(0); }
			25% { transform: translateX(-8px); }
			75% { transform: translateX(8px); }
		}
	</style>
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
