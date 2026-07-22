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
