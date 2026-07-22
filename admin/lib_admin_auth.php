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
