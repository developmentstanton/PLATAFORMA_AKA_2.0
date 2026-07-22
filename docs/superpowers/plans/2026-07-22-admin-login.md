# Módulo administrativo `/admin` — Login y shell — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir la puerta de entrada del módulo administrativo: un login en `/admin` que solo deje pasar a usuarios con `link1 = 'Administrador'`, más un guard reutilizable y un shell vacío listo para colgarle módulos.

**Architecture:** Carpeta `plataforma_20/admin/` autocontenida. Ningún archivo existente del portal se modifica. La lógica de autenticación vive en `admin/lib_admin_auth.php` como funciones puras y testeables que no ejecutan nada al incluirse (mismo patrón que `api/lib_login.php`). Las páginas (`index.php`, `inicio.php`, `logout.php`) solo orquestan.

**Tech Stack:** PHP 8.x procedural, extensión `sqlsrv` contra SQL Server (RDS `INTEGRACION`), HTML/CSS a mano, Font Awesome local (`awesome/`), fuente Space Grotesk. Sin framework, sin composer, sin build.

**Spec:** `docs/superpowers/specs/2026-07-22-admin-login-design.md`

## Global Constraints

- **No modificar ningún archivo fuera de `admin/` y `tests/`.** El portal está en producción.
- Rol de administrador: `usuarios_portal_aka.link1`, comparado con `RTRIM(LTRIM(link1)) = 'Administrador'` (insensible a mayúsculas, por el collation por defecto de la columna).
- Contraseña: comparada con `COLLATE Latin1_General_BIN` (sensible a mayúsculas). Está en texto plano; **no** se hashea en este proyecto.
- Namespace de sesión: **todo** bajo `$_SESSION['admin']`. Nunca tocar `$_SESSION['usuario']` (es del portal).
- Timeout de inactividad: **1800 segundos** (30 min).
- Bloqueo por fuerza bruta: **5 intentos**, **900 segundos** (15 min).
- Eventos de auditoría: `'ADMIN_IN'` y `'ADMIN_OUT'` en `log_usuarios_portal_aka` (columna `evento varchar(10)`).
- Toda salida a HTML pasa por `htmlspecialchars()`.
- Toda consulta usa `sqlsrv_query($conn, $sql, $params)` con parámetros. Nunca concatenar.
- Los mensajes de error jamás muestran `sqlsrv_errors()` al usuario.
- Paleta: `--primary: #4A4782`, `--primary-dark: #3a3768`, `--primary-light: #5c59a0`, `--accent: #ff001e`, `--text: #2d2b4e`, `--text-light: #7b7894`, `--gray-bg: #cacaca`. Fuente `'Space Grotesk'`.
- Los tests son scripts planos ejecutables con `php tests/<archivo>.php`. Sin framework. Salen con código 0 si todo pasa, 1 si algo falla.
- Los archivos PHP del proyecto usan **tabs** para indentar en las páginas (`index.php`, `dashboard.php`) y **4 espacios** en las libs de `api/`. Seguir esa convención: tabs en las páginas de `admin/`, 4 espacios en `admin/lib_admin_auth.php` y en los tests.

---

### Task 1: Autenticación — `admin_autenticar()`

**Files:**
- Create: `admin/lib_admin_auth.php`
- Test: `tests/admin_auth_test.php`

**Interfaces:**
- Consumes: `conexion/conexion_integracion.php` (define `$dbConnect`, un recurso `sqlsrv`).
- Produces:
  - `admin_autenticar($conn, string $usuario, string $clave): ?array` — devuelve `['nombre_usuario' => string, 'correo' => string, 'imagen' => string]` o `null`.
  - Constantes `ADMIN_TIMEOUT_SEG` (1800), `ADMIN_MAX_INTENTOS` (5), `ADMIN_BLOQUEO_SEG` (900).

- [ ] **Step 1: Write the failing test**

Crear `tests/admin_auth_test.php`:

```php
<?php
// Contrato de admin_autenticar(): credenciales correctas Y rol de administrador.
//
// El caso que importa es el 3: un aliado con su contraseña BUENA debe ser rechazado igual
// que una contraseña mala. El rol va dentro del WHERE de la consulta justamente para que no
// exista un camino donde la credencial se valide y el rol se revise después: eso filtraría,
// por diferencia de tiempo o de mensaje, que la contraseña era correcta.
//
//   php tests/admin_auth_test.php
//
// Solo lectura. Las contraseñas NO se queman aquí: se leen de la misma base, para que este
// archivo pueda versionarse sin exponer secretos.

require_once __DIR__ . '/../conexion/conexion_integracion.php';
require_once __DIR__ . '/../admin/lib_admin_auth.php';

if ($dbConnect === false) {
    fwrite(STDERR, "No hay conexion a INTEGRACION; el test no puede correr.\n");
    exit(1);
}

// --- Fixtures leidos de la base ---
$sqlAdmin = "SELECT TOP 1 RTRIM(nombre_usuario) AS u, RTRIM(contrasena_usuario) AS p
             FROM usuarios_portal_aka WHERE RTRIM(LTRIM(link1)) = 'Administrador'";
$st = sqlsrv_query($dbConnect, $sqlAdmin);
$admin = $st ? sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC) : null;

$sqlAliado = "SELECT TOP 1 RTRIM(nombre_usuario) AS u, RTRIM(contrasena_usuario) AS p
              FROM usuarios_portal_aka
              WHERE RTRIM(LTRIM(link1)) <> 'Administrador' AND link1 IS NOT NULL";
$st2 = sqlsrv_query($dbConnect, $sqlAliado);
$aliado = $st2 ? sqlsrv_fetch_array($st2, SQLSRV_FETCH_ASSOC) : null;

if (!$admin || !$aliado) {
    fwrite(STDERR, "Faltan fixtures en usuarios_portal_aka (se necesita 1 admin y 1 no-admin).\n");
    exit(1);
}

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "CONTRATO DE admin_autenticar()\n" . str_repeat('=', 70) . "\n";

// 1) Admin con contraseña correcta -> entra
$r = admin_autenticar($dbConnect, $admin['u'], $admin['p']);
chequear('admin + clave correcta devuelve el usuario',
    is_array($r) && $r['nombre_usuario'] === $admin['u'],
    is_array($r) ? 'ok' : 'devolvio null');

// 2) Admin con contraseña incorrecta -> null
$r = admin_autenticar($dbConnect, $admin['u'], $admin['p'] . 'xyz');
chequear('admin + clave incorrecta devuelve null', $r === null);

// 3) EL CASO CENTRAL: aliado real con SU clave correcta -> null (rol insuficiente)
$r = admin_autenticar($dbConnect, $aliado['u'], $aliado['p']);
chequear('aliado + clave CORRECTA devuelve null (no es admin)', $r === null,
    $r === null ? 'ok' : 'DEJO ENTRAR A UN NO-ADMIN');

// 4) Usuario inexistente -> null
$r = admin_autenticar($dbConnect, 'usuario_que_no_existe_9f3a', 'lo_que_sea');
chequear('usuario inexistente devuelve null', $r === null);

// 5) La clave sigue siendo sensible a mayusculas (COLLATE ..._BIN)
$alterada = (strtoupper($admin['p']) !== $admin['p']) ? strtoupper($admin['p']) : strtolower($admin['p']);
if ($alterada === $admin['p']) {
    echo "  SALTA  clave sensible a mayusculas -> la clave del admin no tiene letras\n";
} else {
    $r = admin_autenticar($dbConnect, $admin['u'], $alterada);
    chequear('clave con distinta capitalizacion devuelve null', $r === null);
}

echo str_repeat('=', 70) . "\n";
if ($fallos) { echo "FALLARON " . count($fallos) . ": " . implode('; ', $fallos) . "\n"; exit(1); }
echo "TODO OK\n";
exit(0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/admin_auth_test.php`
Expected: FAIL — `Failed opening required '.../admin/lib_admin_auth.php'` (el archivo aún no existe).

- [ ] **Step 3: Write minimal implementation**

Crear `admin/lib_admin_auth.php` (indentado con 4 espacios):

```php
<?php
/**
 * Autenticación y sesión del módulo administrativo.
 * Lógica pura y testeable. Incluida por las páginas de admin/ y por los tests.
 * NO ejecuta nada al incluirse (mismo contrato que api/lib_login.php).
 */

/** Inactividad máxima de una sesión admin, en segundos (30 min, igual que el portal). */
const ADMIN_TIMEOUT_SEG = 1800;
/** Intentos fallidos antes de bloquear el formulario. */
const ADMIN_MAX_INTENTOS = 5;
/** Duración del bloqueo por fuerza bruta, en segundos (15 min). */
const ADMIN_BLOQUEO_SEG = 900;

/**
 * Verifica credenciales Y rol de administrador. No toca $_SESSION.
 *
 * El rol viaja DENTRO del WHERE, no como un if posterior: así un usuario con contraseña
 * correcta pero sin rol es indistinguible de una contraseña equivocada, ni por el mensaje
 * ni por el tiempo de respuesta. No se filtra que la credencial era buena.
 *
 * La contraseña se compara con COLLATE Latin1_General_BIN (sensible a mayúsculas, igual
 * que el portal). link1, en cambio, usa el collation por defecto de la columna, que es
 * insensible a mayúsculas: el rol se escribe a mano en la base y no debe fallar por
 * capitalización ni por espacios sobrantes.
 *
 * @return array{nombre_usuario: string, correo: string, imagen: string}|null
 */
function admin_autenticar($conn, string $usuario, string $clave): ?array {
    if ($conn === false || $usuario === '' || $clave === '') return null;

    $sql = "SELECT TOP 1 RTRIM(nombre_usuario) AS nombre_usuario,
                   RTRIM(ISNULL(correo, '')) AS correo,
                   RTRIM(ISNULL(imagen, '')) AS imagen
            FROM usuarios_portal_aka
            WHERE nombre_usuario = ?
              AND contrasena_usuario COLLATE Latin1_General_BIN = ?
              AND RTRIM(LTRIM(link1)) = 'Administrador'";

    $stmt = sqlsrv_query($conn, $sql, array($usuario, $clave));
    if ($stmt === false) return null;

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if (!$row) return null;

    return array(
        'nombre_usuario' => (string)$row['nombre_usuario'],
        'correo'         => (string)$row['correo'],
        'imagen'         => (string)$row['imagen'],
    );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/admin_auth_test.php`
Expected: PASS — cinco líneas `OK` (o cuatro más un `SALTA` en el caso 5) y `TODO OK`. Código de salida 0.

Nota: si aparecen warnings de PHP sobre `php_xdebug.dll` o `php_dio_ts.dll`, son ruido conocido del `php.ini` de este equipo y no afectan el resultado.

- [ ] **Step 5: Commit**

```bash
git add admin/lib_admin_auth.php tests/admin_auth_test.php
git commit -m "feat(admin): admin_autenticar — credenciales + rol Administrador en el mismo WHERE"
```

---

### Task 2: Guard de sesión

**Files:**
- Modify: `admin/lib_admin_auth.php` (añadir funciones al final)
- Test: `tests/admin_guard_test.php`

**Interfaces:**
- Consumes: `ADMIN_TIMEOUT_SEG` de la Task 1.
- Produces:
  - `admin_estado_sesion(array $sesion, int $ahora): string` — devuelve `'ok'`, `'ausente'` o `'expirada'`.
  - `admin_cerrar_sesion_admin(): void` — borra solo `$_SESSION['admin']`.
  - `admin_exigir_sesion(): array` — orquesta y redirige; devuelve los datos del admin.
  - `admin_registrar_evento($conn, string $usuario, string $evento): void`.

- [ ] **Step 1: Write the failing test**

Crear `tests/admin_guard_test.php`:

```php
<?php
// Contrato del guard de sesión admin. SIN base de datos: se simula $_SESSION.
//
// La decisión (admin_estado_sesion) está separada de la acción (admin_exigir_sesion, que
// redirige y termina) justamente para poder probarla. El caso 4 es el que protege al portal:
// cuando la sesión admin expira NO se puede llamar a session_destroy(), porque el mismo
// navegador puede tener abierta una sesión de aliado y la tumbaría.
//
//   php tests/admin_guard_test.php

require_once __DIR__ . '/../admin/lib_admin_auth.php';

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "CONTRATO DEL GUARD DE SESION ADMIN\n" . str_repeat('=', 70) . "\n";

$AHORA = 1000000;

// 1) Sesion sin namespace admin
chequear('sesion vacia -> ausente',
    admin_estado_sesion(array(), $AHORA) === 'ausente');

// 2) Sesion admin fresca
$fresca = array('admin' => array('usuario' => 'Administrador', 'ultima_actividad' => $AHORA - 60));
chequear('sesion de hace 1 min -> ok',
    admin_estado_sesion($fresca, $AHORA) === 'ok');

// 3) Sesion admin de 31 minutos (limite: ADMIN_TIMEOUT_SEG = 1800)
$vieja = array('admin' => array('usuario' => 'Administrador', 'ultima_actividad' => $AHORA - 1860));
chequear('sesion de 31 min -> expirada',
    admin_estado_sesion($vieja, $AHORA) === 'expirada');

// 3b) Justo en el limite todavia vale
$limite = array('admin' => array('usuario' => 'Administrador', 'ultima_actividad' => $AHORA - 1800));
chequear('sesion de exactamente 30 min -> ok',
    admin_estado_sesion($limite, $AHORA) === 'ok');

// 3c) Namespace admin presente pero sin marca de tiempo -> se trata como expirada
$sinMarca = array('admin' => array('usuario' => 'Administrador'));
chequear('sesion admin sin ultima_actividad -> expirada',
    admin_estado_sesion($sinMarca, $AHORA) === 'expirada');

// 4) EL CASO QUE PROTEGE AL PORTAL: cerrar la sesion admin no toca la del aliado
$_SESSION = array(
    'admin'   => array('usuario' => 'Administrador', 'ultima_actividad' => $AHORA),
    'usuario' => 'Planeta Sport 6',
    'proveedor' => 'PLANETA SPORT',
);
admin_cerrar_sesion_admin();
chequear('cerrar admin borra $_SESSION[admin]', !isset($_SESSION['admin']));
chequear('cerrar admin NO borra $_SESSION[usuario] del portal',
    isset($_SESSION['usuario']) && $_SESSION['usuario'] === 'Planeta Sport 6',
    isset($_SESSION['usuario']) ? 'intacta' : 'SE PERDIO LA SESION DEL PORTAL');
chequear('cerrar admin NO borra $_SESSION[proveedor] del portal',
    isset($_SESSION['proveedor']));

echo str_repeat('=', 70) . "\n";
if ($fallos) { echo "FALLARON " . count($fallos) . ": " . implode('; ', $fallos) . "\n"; exit(1); }
echo "TODO OK\n";
exit(0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/admin_guard_test.php`
Expected: FAIL — `Call to undefined function admin_estado_sesion()`.

- [ ] **Step 3: Write minimal implementation**

Añadir al final de `admin/lib_admin_auth.php`:

```php
/**
 * Decide el estado de una sesión admin. Sin efectos secundarios: recibe el arreglo de
 * sesión y la hora, para poder probarse sin cookies ni redirecciones.
 *
 * @return string 'ok' | 'ausente' | 'expirada'
 */
function admin_estado_sesion(array $sesion, int $ahora): string {
    if (empty($sesion['admin']) || !is_array($sesion['admin'])) return 'ausente';
    $ultima = $sesion['admin']['ultima_actividad'] ?? null;
    // Sin marca de tiempo no hay forma de saber si sigue viva: se asume vencida.
    if (!is_int($ultima)) return 'expirada';
    if (($ahora - $ultima) > ADMIN_TIMEOUT_SEG) return 'expirada';
    return 'ok';
}

/**
 * Borra SOLO el namespace admin de la sesión.
 *
 * Deliberadamente NO usa session_destroy(): el portal de aliados vive en la misma cookie
 * ($_SESSION['usuario'], $_SESSION['proveedor'], ...) y destruir la sesión entera cerraría
 * también la del aliado. Compartida por el guard y por admin/logout.php.
 */
function admin_cerrar_sesion_admin(): void {
    unset($_SESSION['admin']);
}

/**
 * Guard de las páginas del módulo. Primera línea de todo archivo bajo admin/ que exija
 * autenticación. Si no hay sesión válida redirige a admin/index.php y termina.
 *
 * @return array Los datos del admin en sesión.
 */
function admin_exigir_sesion(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    $estado = admin_estado_sesion($_SESSION, time());
    if ($estado !== 'ok') {
        if ($estado === 'expirada') {
            admin_cerrar_sesion_admin();
            header('Location: index.php?expired=1');
        } else {
            header('Location: index.php');
        }
        exit;
    }

    $_SESSION['admin']['ultima_actividad'] = time();
    return $_SESSION['admin'];
}

/**
 * Registra un evento de auditoría ('ADMIN_IN' / 'ADMIN_OUT') en log_usuarios_portal_aka.
 * Su fallo NUNCA rompe el flujo: no poder escribir el log no es motivo para negar el
 * ingreso ni para dejar a alguien sin poder salir.
 */
function admin_registrar_evento($conn, string $usuario, string $evento): void {
    if ($conn === false) return;
    $sql = "INSERT INTO log_usuarios_portal_aka VALUES (?, GETDATE(), ?)";
    $stmt = @sqlsrv_query($conn, $sql, array($usuario, $evento));
    if ($stmt !== false) sqlsrv_free_stmt($stmt);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/admin_guard_test.php`
Expected: PASS — ocho líneas `OK` y `TODO OK`. Código de salida 0.

Verificar además que la Task 1 no se rompió:

Run: `php tests/admin_auth_test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add admin/lib_admin_auth.php tests/admin_guard_test.php
git commit -m "feat(admin): guard de sesion con namespace propio — logout quirurgico que no tumba al portal"
```

---

### Task 3: Página de login

**Files:**
- Create: `admin/index.php`

**Interfaces:**
- Consumes: `admin_autenticar()`, `admin_registrar_evento()`, `admin_estado_sesion()`, `ADMIN_MAX_INTENTOS`, `ADMIN_BLOQUEO_SEG` (Tasks 1 y 2); `conexion/conexion_integracion.php` (`$dbConnect`).
- Produces: al autenticar, deja `$_SESSION['admin'] = ['usuario', 'correo', 'imagen', 'ultima_actividad']` y redirige a `inicio.php`.

Dos advertencias antes de escribir el archivo:

1. `conexion/conexion_integracion.php` **no aborta** cuando falla la conexión: imprime un `<script>` y sigue, dejando `$dbConnect === false`. Hay que comprobarlo explícitamente. Por eso también se incluye solo dentro de la rama del POST, para no soltar ese `<script>` en medio del `<head>`.

2. El `index.php` del portal abre con `<!DOCTYPE html><html lang="es">` **antes** del bloque PHP que llama a `header('Location: ...')`. Funciona solo porque `output_buffering` está activo en esos servidores; si alguien lo apaga, el login rompe con *"headers already sent"*. **No copiar ese orden**: aquí todo el PHP va primero y el HTML después. Es la única desviación deliberada respecto al archivo original.

- [ ] **Step 1: Write the file**

Crear `admin/index.php` (indentado con tabs, igual que `index.php` del portal):

```php
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
```

- [ ] **Step 2: Verify it parses**

Run: `php -l admin/index.php`
Expected: `No syntax errors detected in admin/index.php`

- [ ] **Step 3: Verify in the browser**

Abrir `http://localhost/plataforma_20/admin/`. Comprobar:

1. Se ve la caja de login con el sub-título **ADMINISTRACIÓN** (no "PORTAL DE ALIADOS").
2. Usuario `Administrador` con contraseña **incorrecta** → alerta roja *"Usuario o contraseña incorrectos. Te quedan 4 intento(s)."*
3. Un aliado con su contraseña **correcta** → el **mismo** mensaje. No entra.
4. `Administrador` con contraseña correcta → redirige a `inicio.php` (por ahora dará 404: se crea en la Task 4). Eso confirma que autenticó.

Nota: como la Task 4 aún no existe, tras el paso 4 la sesión queda abierta. Para repetir las pruebas, borrar las cookies del sitio.

- [ ] **Step 4: Commit**

```bash
git add admin/index.php
git commit -m "feat(admin): pagina de login con CSRF, bloqueo por intentos y contadores propios"
```

---

### Task 4: Shell administrativo y salida

**Files:**
- Create: `admin/inicio.php`
- Create: `admin/assets/admin.css`
- Create: `admin/logout.php`

**Interfaces:**
- Consumes: `admin_exigir_sesion()`, `admin_cerrar_sesion_admin()`, `admin_registrar_evento()` (Tasks 1 y 2).
- Produces: el array `$menu` en `inicio.php` como punto de extensión para los módulos futuros.

- [ ] **Step 1: Write the stylesheet**

Crear `admin/assets/admin.css`:

```css
/* Shell del módulo administrativo. Misma paleta y tipografía que el portal. */
* { margin: 0; padding: 0; box-sizing: border-box; }

:root {
	--primary: #4A4782;
	--primary-dark: #3a3768;
	--primary-light: #5c59a0;
	--accent: #ff001e;
	--text: #2d2b4e;
	--text-light: #7b7894;
	--gray-bg: #cacaca;
	--barra-alto: 64px;
	--menu-ancho: 220px;
}

body {
	font-family: 'Space Grotesk', system-ui, sans-serif;
	color: var(--text);
	background: #f4f4f7;
	min-height: 100vh;
}

/* --- Barra superior --- */
.admin-barra {
	position: fixed; top: 0; left: 0; right: 0; height: var(--barra-alto);
	background: var(--primary); color: #fff;
	display: flex; align-items: center; gap: 16px; padding: 0 20px;
	z-index: 10;
}
.admin-barra img { height: 28px; width: auto; }
.admin-barra .titulo {
	font-size: 12px; letter-spacing: 3px; color: rgba(255,255,255,0.65);
	text-transform: uppercase;
}
.admin-barra .espaciador { flex: 1; }
.admin-barra .usuario { font-size: 14px; font-weight: 600; }
.admin-barra .salir {
	color: rgba(255,255,255,0.75); text-decoration: none;
	font-size: 16px; padding: 6px 8px; transition: color 0.2s;
}
.admin-barra .salir:hover { color: var(--accent); }

/* --- Menú lateral --- */
.admin-menu {
	position: fixed; top: var(--barra-alto); left: 0; bottom: 0; width: var(--menu-ancho);
	background: #fff; border-right: 1px solid #e3e3ea; padding: 20px 0;
	overflow-y: auto;
}
.admin-menu .encabezado {
	font-size: 11px; letter-spacing: 2px; color: var(--text-light);
	text-transform: uppercase; padding: 0 20px 12px;
}
.admin-menu a {
	display: flex; align-items: center; gap: 10px;
	padding: 10px 20px; color: var(--text); text-decoration: none;
	font-size: 14px; font-weight: 500; border-left: 3px solid transparent;
	transition: background 0.15s, border-color 0.15s;
}
.admin-menu a:hover { background: #f4f4f7; border-left-color: var(--accent); }
.admin-menu a i { width: 18px; color: var(--primary-light); }
.admin-menu .vacio {
	padding: 10px 20px; font-size: 13px; color: var(--text-light); font-style: italic;
}

/* --- Contenido --- */
.admin-contenido {
	margin-left: var(--menu-ancho); padding: calc(var(--barra-alto) + 32px) 32px 32px;
}
.admin-contenido h1 { font-size: 26px; font-weight: 600; color: var(--primary); }
.admin-contenido .subtitulo { color: var(--text-light); margin-top: 8px; font-size: 15px; }
.admin-tarjeta {
	background: #fff; border: 1px solid #e3e3ea; padding: 28px; margin-top: 24px;
}

@media (max-width: 720px) {
	.admin-menu { display: none; }
	.admin-contenido { margin-left: 0; padding: calc(var(--barra-alto) + 24px) 20px 24px; }
}
```

- [ ] **Step 2: Write the shell page**

Crear `admin/inicio.php` (tabs):

```php
<?php
	ini_set('session.cookie_httponly', 1);
	ini_set('session.cookie_samesite', 'Strict');
	ini_set('session.use_strict_mode', 1);

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
```

- [ ] **Step 3: Write the logout**

Crear `admin/logout.php` (tabs):

```php
<?php
	session_start();

	require_once __DIR__ . '/lib_admin_auth.php';

	$usuario = $_SESSION['admin']['usuario'] ?? '';
	if ($usuario !== '') {
		require __DIR__ . '/../conexion/conexion_integracion.php';
		if ($dbConnect !== false) {
			admin_registrar_evento($dbConnect, $usuario, 'ADMIN_OUT');
			sqlsrv_close($dbConnect);
		}
	}

	// Solo el namespace admin: el portal de aliados puede estar abierto en la misma cookie.
	admin_cerrar_sesion_admin();
	session_regenerate_id(true);

	header('Location: index.php');
	exit;
?>
```

- [ ] **Step 4: Verify all three parse**

Run: `php -l admin/inicio.php && php -l admin/logout.php`
Expected: `No syntax errors detected` para ambos.

- [ ] **Step 5: Verify the full flow in the browser**

1. Borrar cookies del sitio. Abrir `http://localhost/plataforma_20/admin/inicio.php` **sin sesión** → redirige a `admin/index.php`.
2. Entrar con `Administrador` y su contraseña → carga el shell: barra morada con el logo, "ADMINISTRACIÓN", el nombre a la derecha, menú con "Próximamente" y la tarjeta de "en construcción".
3. Volver a abrir `http://localhost/plataforma_20/admin/` con la sesión ya activa → redirige a `inicio.php` sin pedir credenciales.
4. Clic en el ícono de apagado → vuelve al login.
5. **Prueba de convivencia:** en la misma ventana, entrar al portal (`http://localhost/plataforma_20/`) con un aliado, luego entrar a `/admin/` con `Administrador`, luego hacer logout del admin → recargar `dashboard.php` y confirmar que **la sesión del aliado sigue abierta**.
6. Verificar la auditoría:

```bash
php -r "require 'conexion/conexion_integracion.php'; \$s=sqlsrv_query(\$dbConnect,\"SELECT TOP 5 nombre_usuario, fecha_evento, evento FROM log_usuarios_portal_aka WHERE evento LIKE 'ADMIN%' ORDER BY fecha_evento DESC\"); while(\$r=sqlsrv_fetch_array(\$s,SQLSRV_FETCH_ASSOC)) echo \$r['nombre_usuario'].' | '.\$r['fecha_evento']->format('Y-m-d H:i:s').' | '.\$r['evento'].PHP_EOL;"
```

Expected: filas `Administrador | ... | ADMIN_IN` y `Administrador | ... | ADMIN_OUT`.

- [ ] **Step 6: Run the full test suite**

Run: `php tests/admin_auth_test.php && php tests/admin_guard_test.php`
Expected: ambos `TODO OK`.

- [ ] **Step 7: Commit**

```bash
git add admin/inicio.php admin/assets/admin.css admin/logout.php
git commit -m "feat(admin): shell administrativo con menu extensible y salida quirurgica"
```

---

## Verificación final

Antes de dar el trabajo por terminado:

- [ ] `php tests/admin_auth_test.php` → `TODO OK`
- [ ] `php tests/admin_guard_test.php` → `TODO OK`
- [ ] `php -l` limpio en los cuatro archivos PHP de `admin/`
- [ ] `git status` no muestra cambios en archivos fuera de `admin/`, `tests/` y `docs/`
- [ ] El flujo completo del navegador (Task 4, Step 5) pasa, incluida la prueba de convivencia

**No se despliega a WMS-LAB.** El módulo no tiene todavía funcionalidad administrativa; el despliegue se hará cuando haya algo que administrar.
