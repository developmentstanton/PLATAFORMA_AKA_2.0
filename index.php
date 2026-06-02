<!DOCTYPE html>
<html lang="es">
<?php
	// Cookies de sesión seguras
	ini_set('session.cookie_httponly', 1);
	ini_set('session.cookie_samesite', 'Strict');
	ini_set('session.use_strict_mode', 1);
	session_start();
	$loginError = '';

	// Protección contra fuerza bruta
	$maxIntentos = 5;
	$bloqueoSegundos = 900; // 15 minutos
	if (!isset($_SESSION['login_intentos'])) {
		$_SESSION['login_intentos'] = 0;
		$_SESSION['login_ultimo_intento'] = 0;
	}

	// Verificar si está bloqueado
	$bloqueado = false;
	if ($_SESSION['login_intentos'] >= $maxIntentos) {
		$tiempoRestante = $bloqueoSegundos - (time() - $_SESSION['login_ultimo_intento']);
		if ($tiempoRestante > 0) {
			$bloqueado = true;
			$minutosRestantes = ceil($tiempoRestante / 60);
			$loginError = "Demasiados intentos fallidos. Intenta de nuevo en $minutosRestantes minuto(s).";
		} else {
			// Tiempo de bloqueo expiró, resetear
			$_SESSION['login_intentos'] = 0;
		}
	}

	// Generar token CSRF si no existe
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}

	if (!$bloqueado && !empty($_POST['username']) && !empty($_POST['pass'])) {
		// Validar token CSRF
		if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
			$loginError = "Solicitud no válida. Recarga la página e intenta de nuevo.";
		} else {

		require('conexion/conexion_integracion.php');

		$sqlLogin = "SELECT nombre_usuario, correo, imagen, link1
					 FROM usuarios_portal_aka
					 WHERE nombre_usuario = ?
					 AND contrasena_usuario COLLATE Latin1_General_BIN = ?";
		$params = array($_POST['username'], $_POST['pass']);
		$stmt = sqlsrv_query($dbConnect, $sqlLogin, $params);

		if ($stmt === false) {
			$loginError = "Error en la consulta";
		} else {
			$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
			if ($user) {
				// Login exitoso: resetear intentos y regenerar sesión
				$_SESSION['login_intentos'] = 0;
				session_regenerate_id(true);

				$_SESSION['usuario'] = $user['nombre_usuario'];
				$_SESSION['correo'] = $user['correo'];
				$_SESSION['imagen'] = $user['imagen'];
				$_SESSION['link1'] = $user['link1'];
				$_SESSION['ultima_actividad'] = time();

				// Resolver nombre del proveedor cruzando con stanton.dbo.t202_mm_proveedores
				// (los nombre_usuario no son idénticos al f202_descripcion_sucursal, por eso LIKE).
				$sqlProv = "SELECT TOP 1 f202_descripcion_sucursal
							FROM stanton.dbo.t202_mm_proveedores
							WHERE f202_id_cia = '7'
							  AND f202_descripcion_sucursal LIKE '%' + REPLACE(?, '_', ' ') + '%'
							ORDER BY LEN(f202_descripcion_sucursal) ASC";
				$stmtProv = sqlsrv_query($dbConnect, $sqlProv, array($user['nombre_usuario']));
				if ($stmtProv !== false) {
					$rowProv = sqlsrv_fetch_array($stmtProv, SQLSRV_FETCH_ASSOC);
					if ($rowProv && !empty($rowProv['f202_descripcion_sucursal'])) {
						$_SESSION['proveedor'] = trim($rowProv['f202_descripcion_sucursal']);
					}
					sqlsrv_free_stmt($stmtProv);
				}

				$consultaLog = "INSERT INTO log_usuarios_portal_aka VALUES (?, GETDATE(), 'INGRESO')";
				$paramsLog = array($user['nombre_usuario']);
				$resultadoLog = sqlsrv_query($dbConnect, $consultaLog, $paramsLog);

				sqlsrv_free_stmt($stmt);
				if ($resultadoLog !== false) {
					sqlsrv_free_stmt($resultadoLog);
				}
				sqlsrv_close($dbConnect);

				header("Location: dashboard.php");
				exit;
			} else {
				// Login fallido: incrementar intentos
				$_SESSION['login_intentos']++;
				$_SESSION['login_ultimo_intento'] = time();
				$intentosRestantes = $maxIntentos - $_SESSION['login_intentos'];
				if ($intentosRestantes > 0) {
					$loginError = "Usuario o contraseña incorrectos. Te quedan $intentosRestantes intento(s).";
				} else {
					$loginError = "Demasiados intentos fallidos. Intenta de nuevo en 15 minutos.";
				}
				sqlsrv_free_stmt($stmt);
				sqlsrv_close($dbConnect);
			}
		}
		} // fin validación CSRF
	}
?>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>AKA 2.0 — Ingreso</title>
	<link rel="shortcut icon" href="img/aka.ico" type="image/x-icon">
	<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="awesome/css/fontawesome.min.css">
	<link rel="stylesheet" href="awesome/css/solid.min.css">
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		:root {
			--primary: #4A4782;
			--primary-dark: #3a3768;
			--primary-light: #5c59a0;
			--accent: #ff001e;
			--accent-hover: #d9001a;
			--text: #2d2b4e;
			--text-light: #7b7894;
			--gray-bg: #cacaca;
		}
		body { font-family: 'Space Grotesk', system-ui, sans-serif; color: var(--text); margin: 0; }

		.login-screen {
			display: flex; align-items: center; justify-content: center;
			min-height: 100vh;
			background: url('img/bg.jpeg') no-repeat center center;
			background-size: cover;
			position: relative;
		}
		.login-screen::before {
			content: '';
			position: absolute;
			inset: 0;
			background: rgba(45, 43, 78, 0.5);
		}
		.login-box {
			background: #cacaca; border-radius: 0; padding: 0; width: 360px;
			box-shadow: 0 0 20px rgba(0,0,0,0.3); overflow: hidden;
			position: relative; z-index: 1;
		}
		.login-logo {
			background: var(--primary); padding: 32px; text-align: center;
		}
		.login-logo img {
			width: 65%; max-width: 200px; height: auto;
		}
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

		/* Password wrapper */
		.password-wrapper {
			position: relative;
			margin-bottom: 24px;
		}
		.password-wrapper input[type="password"],
		.password-wrapper input[type="text"] {
			margin-bottom: 0 !important;
			padding-right: 36px;
		}
		.password-toggle {
			position: absolute;
			right: 0;
			top: 50%;
			transform: translateY(-50%);
			background: none;
			border: none;
			color: var(--primary-light);
			cursor: pointer;
			padding: 4px 6px;
			font-size: 14px;
			transition: color 0.2s;
		}
		.password-toggle:hover { color: var(--primary); }

		.login-btn {
			background: var(--accent); color: white; border: none; padding: 12px 32px;
			font-size: 16px; font-weight: 700; cursor: pointer; letter-spacing: 1px;
			font-family: 'Space Grotesk', sans-serif; transition: all 0.2s;
			width: 100%;
		}
		.login-btn:hover { background: var(--primary); }
		.login-btn:disabled {
			background: var(--gray-bg); cursor: not-allowed; opacity: 0.8;
		}

		.login-alert {
			background: rgba(255, 0, 30, 0.1);
			color: var(--accent);
			padding: 10px 14px;
			font-size: 13px;
			font-weight: 600;
			text-align: center;
			margin-bottom: 16px;
			border: 1px solid rgba(255, 0, 30, 0.2);
			animation: shake 0.5s ease-in-out;
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
			<img src="img/logo_aka.png" alt="AKA">
			<div class="logo-sub">PORTAL DE ALIADOS</div>
		</div>
		<div class="login-form">
			<?php if ($loginError) { ?>
				<div class="login-alert">
					<i class="fa-solid fa-circle-exclamation" style="margin-right:6px;"></i>
					<?php echo htmlspecialchars($loginError); ?>
				</div>
			<?php } ?>
			<form action="" method="post" id="loginForm">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
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
