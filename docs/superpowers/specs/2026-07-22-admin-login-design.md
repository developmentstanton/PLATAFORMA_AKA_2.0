# Módulo administrativo `/admin` — Login y shell

**Fecha:** 2026-07-22
**Estado:** diseño aprobado, pendiente de implementación

## Problema

El portal AKA 2.0 no tiene área administrativa. Toda la gestión (usuarios, mapeo curado
de proveedores, revisión de caches) se hace hoy a mano contra la base de datos. El primer
paso es la puerta de entrada: un login propio en `/admin` que solo deje pasar a quien
tenga rol de administrador, más un shell vacío listo para colgarle módulos.

Esta entrega **no** incluye ninguna funcionalidad administrativa. Solo autenticación,
guard reutilizable y layout base.

## Decisiones tomadas

| Decisión | Elegido | Por qué |
|---|---|---|
| Marca de rol | `usuarios_portal_aka.link1 = 'Administrador'` | Ya existe un usuario así. Cero cambios de esquema en una RDS compartida con producción. |
| Sesión | Independiente del portal | Entrar al portal no concede admin y viceversa. No acopla el módulo al login existente. |
| Alcance | Login + shell vacío | No hay páginas administrativas todavía; el shell define el marco donde irán. |
| Estética | Igual al portal, sub-título `ADMINISTRACIÓN` | Se reconoce al instante dónde estás. |
| Auditoría | Eventos `ADMIN_IN` / `ADMIN_OUT` | Se distinguen de los ingresos de aliados; no ensucian las estadísticas de uso. |
| Estructura | Carpeta `/admin` autocontenida | No toca ni un archivo del portal, que está en producción. |

### Contexto de `link1` (importante)

`link1 varchar(250)` es un campo **legacy de la v1.0**: en 25 de los 26 usuarios guarda la
URL del reporte de Power BI embebido. La v1.0 (`E:\wms\www\plataforma`) **sigue viva en
producción** y la consume.

En la v2.0 `link1` se copia a `$_SESSION['link1']` en `index.php:65` pero **no se usa en
ninguna parte**. Por eso es seguro leerlo como marca de rol.

Consecuencia operativa: **marcar a alguien como administrador le borra su URL de Power BI
y lo rompe en la v1.0.** El usuario `Administrador` (correo `jpmartin@stanton.co`) es una
cuenta dedicada que no usa la v1.0, así que hoy no hay conflicto. Si mañana se quiere dar
rol admin a un aliado que sí usa la v1.0, primero hay que migrar a una columna `rol`
dedicada.

## Arquitectura

Archivos nuevos, todos bajo `plataforma_20/admin/`. **Ningún archivo existente se modifica.**

```
admin/
  lib_admin_auth.php   lógica pura, sin efectos al incluirse
  index.php            formulario de login + manejo del POST
  inicio.php           shell admin (destino tras autenticarse)
  logout.php           cierra la sesión admin
  assets/admin.css     estilos del shell
```

Se reutiliza `conexion/conexion_integracion.php` (credenciales, fuera de git).

`lib_admin_auth.php` sigue el patrón ya establecido en `api/lib_login.php`: lógica pura y
testeable que **no ejecuta nada al incluirse**.

### API de `lib_admin_auth.php`

```php
/**
 * Verifica credenciales Y rol de administrador. No toca $_SESSION.
 * @return array|null  El usuario (nombre_usuario, correo, imagen) o null si no procede.
 */
function admin_autenticar($conn, string $usuario, string $clave): ?array

/**
 * Guard de las páginas del módulo. Si no hay sesión admin válida o expiró,
 * redirige a admin/index.php y termina la ejecución.
 * @return array  Los datos del admin en sesión.
 */
function admin_exigir_sesion(): array
```

## Flujo de autenticación

`admin/index.php` al recibir **GET**: si ya existe una sesión admin válida y no expirada,
redirige directo a `inicio.php`. No tiene sentido mostrarle el formulario a quien ya entró.

`admin/index.php` al recibir **POST**:

1. **Validar CSRF.** Token inválido o ausente → error genérico, no se consulta la BD.
2. **Verificar bloqueo.** 5 intentos fallidos → 15 minutos, con contador propio del admin.
3. **`admin_autenticar()`** ejecuta una sola consulta:

   ```sql
   SELECT nombre_usuario, correo, imagen
   FROM usuarios_portal_aka
   WHERE nombre_usuario = ?
     AND contrasena_usuario COLLATE Latin1_General_BIN = ?
     AND RTRIM(LTRIM(link1)) = 'Administrador'
   ```

   El rol va **dentro del `WHERE`**, no como un `if` posterior. Así un usuario válido sin
   rol recibe exactamente el mismo error y el mismo tiempo de respuesta que una contraseña
   equivocada: no se filtra que la credencial era buena.

   El `COLLATE Latin1_General_BIN` replica el comportamiento del portal (`index.php:49`):
   la contraseña distingue mayúsculas de minúsculas.

   La comparación de `link1`, en cambio, usa el collation por defecto de la columna, que es
   **insensible a mayúsculas**: `'administrador'` y `'ADMINISTRADOR'` también valen. Es
   deliberado — el rol se escribe a mano en la base y no debe fallar por capitalización.
   El `RTRIM(LTRIM(...))` cubre los espacios sobrantes por la misma razón.

4. **Éxito:** resetear contador → `session_regenerate_id(true)` → poblar `$_SESSION['admin']`
   → `INSERT INTO log_usuarios_portal_aka VALUES (?, GETDATE(), 'ADMIN_IN')` →
   redirigir a `inicio.php`.
5. **Fallo:** incrementar contador, registrar la hora, mostrar mensaje genérico.

**No se resuelve proveedor ni se dispara el login-prewarm.** El admin no es un aliado; ese
trabajo (~700 s de construcción de caches) no tiene sentido aquí.

## Sesión y guard

### Forma de la sesión

```php
$_SESSION['admin'] = [
    'usuario'          => 'Administrador',
    'correo'           => 'jpmartin@stanton.co',
    'imagen'           => '',
    'ultima_actividad' => time(),
];
```

Una sola cookie PHP para todo el sitio, pero los dos contextos no se pisan: el portal lee
`$_SESSION['usuario']`, el admin lee `$_SESSION['admin']`.

### `admin_exigir_sesion()`

Primera línea de toda página del módulo:

1. `session_start()` si la sesión no está iniciada.
2. Sin `$_SESSION['admin']` → redirigir a `admin/index.php` y `exit`.
3. Inactividad mayor a **30 minutos** (mismo valor que el portal, `dashboard.php:9`) →
   `unset($_SESSION['admin'])` y redirigir a `admin/index.php?expired=1`.
4. Refrescar `ultima_actividad` y devolver los datos del admin.

### Logout quirúrgico

`logout.php` del portal hace `session_destroy()` a secas. `admin/logout.php` **no puede**:
si el mismo navegador tiene sesión de aliado abierta, la tumbaría. Hace
`unset($_SESSION['admin'])` + `session_regenerate_id(true)`, registra `ADMIN_OUT` y
redirige a `admin/index.php`.

El caso inverso ya existe y se acepta: cerrar sesión en el portal mata también la del
admin, porque `logout.php` destruye la sesión entera. No se modifica.

### Limitación conocida

El guard **no** revalida contra la BD en cada request. Si a alguien le quitan el
`Administrador` de `link1`, su sesión abierta sigue viva hasta que expire (máximo 30 min).
Con un solo administrador es irrelevante; si en el futuro hay varios, conviene revalidar
el rol cada N minutos.

## Shell administrativo

`admin/inicio.php`, tres zonas, con la paleta del portal (Space Grotesk, `#4A4782`
morado, `#ff001e` rojo):

```
┌─────────────────────────────────────────────┐
│ [logo AKA]  ADMINISTRACIÓN    Administrador ⏻│  barra superior
├──────────────┬──────────────────────────────┤
│              │                              │
│  MENÚ        │   Bienvenido, Administrador  │
│  (vacío)     │                              │
│              │   Módulo en construcción.    │
│              │                              │
└──────────────┴──────────────────────────────┘
```

El menú lateral se alimenta de un array PHP al inicio del archivo:

```php
$menu = [];  // ['etiqueta' => 'Usuarios', 'icono' => 'fa-users', 'url' => 'usuarios.php']
```

Vacío muestra el texto "Próximamente". Agregar una sección futura es una línea en ese
array, sin tocar el HTML.

Estilos en `admin/assets/admin.css`, reutilizando las variables CSS que ya define
`index.php`. El login sí lleva sus estilos embebidos, igual que el del portal.

## Seguridad

| Defensa | Implementación |
|---|---|
| CSRF | `$_SESSION['admin_csrf_token']` propio. Compartir el del portal haría que un login en un lado invalidara el formulario del otro. |
| Fuerza bruta | `$_SESSION['admin_login_intentos']` propio: 5 intentos → 15 min. Un aliado torpe no bloquea al admin. |
| Cookies | `httponly` + `samesite=Strict` + `use_strict_mode`, fijadas **antes** de `session_start()`. |
| Fijación de sesión | `session_regenerate_id(true)` al autenticar. |
| Inyección SQL | `sqlsrv_query` con parámetros. |
| XSS | `htmlspecialchars()` en toda salida: mensajes de error y nombre del usuario en el shell. |
| Enumeración de usuarios | Mensaje y tiempo de respuesta idénticos para credencial mala, usuario inexistente y usuario sin rol. |

### Manejo de errores

- Credencial incorrecta, usuario inexistente **o usuario válido sin rol de admin** →
  *"Usuario o contraseña incorrectos. Te quedan N intento(s)."*
- Conexión caída o `sqlsrv_query` devuelve `false` → *"No fue posible validar el ingreso.
  Intenta más tarde."* Nunca se imprime `sqlsrv_errors()` en pantalla.
- Sesión expirada (`?expired=1`) → *"Tu sesión expiró por inactividad."*, en tono neutro.

### Deuda heredada que NO se toca

Las contraseñas están en **texto plano** en `contrasena_usuario varchar(25)`. Hashearlas
rompería el login de la v1.0 y el del portal v2.0, que comparten la misma tabla. Es un
proyecto aparte con su propia migración. Aquí solo se hereda el esquema existente.

## Pruebas

`tests/admin_auth_test.php` — contra la BD real, solo lectura. Ejecutable con
`php tests/admin_auth_test.php`:

1. `Administrador` + contraseña correcta → devuelve el usuario.
2. `Administrador` + contraseña incorrecta → `null`.
3. **Un aliado real con su contraseña correcta → `null`.** Es la prueba central:
   credencial válida, rol insuficiente.
4. Usuario inexistente → `null`.
5. Contraseña con distinta capitalización → `null` (confirma que el `COLLATE ..._BIN`
   sigue mordiendo).

`tests/admin_guard_test.php` — sin BD, simulando `$_SESSION`:

1. Sesión ausente → rechaza.
2. Sesión fresca → acepta y refresca `ultima_actividad`.
3. Sesión de 31 minutos → rechaza por expiración.
4. Al expirar, **no borra `$_SESSION['usuario']`** del portal.

Verificación manual en navegador: `http://localhost/plataforma_20/admin/`.

## Fuera de alcance

- Cualquier funcionalidad administrativa (CRUD de usuarios, curación de `proveedor_items`,
  gestión de caches). Cada una será su propio spec.
- Hasheo de contraseñas.
- Columna `rol` dedicada.
- Múltiples niveles de permiso.
- Despliegue a WMS-LAB (se hace cuando haya funcionalidad real que desplegar).

## Datos de referencia

`usuarios_portal_aka`: `id_usuario`, `nombre_usuario varchar(60)`,
`contrasena_usuario varchar(25)`, `correo varchar(100)`, `imagen varchar(25)`,
`link1..link8 varchar(250)`, `proveedor_items varchar(120)`. 26 filas.

`log_usuarios_portal_aka`: `nombre_usuario varchar(60)`, `fecha_evento datetime`,
`evento varchar(10)`. Los eventos `ADMIN_IN` (8) y `ADMIN_OUT` (9) caben.

Administrador actual: `nombre_usuario = 'Administrador'`, `correo = 'jpmartin@stanton.co'`,
`link1 = 'Administrador'`, sin imagen ni `proveedor_items`.
