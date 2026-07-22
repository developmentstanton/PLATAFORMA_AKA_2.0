# Módulo `/admin` — Bandeja de planillas y cambio de estado

**Fecha:** 2026-07-22
**Estado:** diseño aprobado, pendiente de implementación

## Problema

Cuando un aliado sube una codificación desde el portal, `cod_registrar_envio()`
(`api/lib_codificacion.php:101`) inserta una fila en `consecutivo_planillas_aka` con estado
`'Estudio'`. Alguien decide después si se aprueba o se rechaza, y hoy ese cambio se hace **a
mano contra la base de datos**. El aliado ve el resultado en su historial del portal.

Este módulo es la interfaz de esa decisión: una tabla en `/admin` con las planillas y un
botón para cambiar el estado, registrando el motivo cuando se rechaza.

Es el primer módulo con funcionalidad real del área administrativa, cuyo login y shell se
construyeron en [2026-07-22-admin-login-design.md](2026-07-22-admin-login-design.md).

## Estado actual del sistema (verificado contra la instancia, no supuesto)

`INTEGRACION.dbo.consecutivo_planillas_aka`:

| Columna | Tipo | Nota |
|---|---|---|
| `consecutivo` | int IDENTITY | PK |
| `nombre_cliente` | varchar(60) | NOT NULL |
| `fecha` | date | NOT NULL |
| `estado` | varchar(20) | NOT NULL, `DEFAULT ('Estudio')` |
| `nit` | varchar(20) | NULL, vacío en la práctica |

- **304 filas**, de 2024-05-08 a 2026-06-17.
- **Todas en `'Aprobado'`.** Cero en `'Estudio'`, cero en `'Rechazado'`.
- **No existe columna de motivo.** Se crea en este proyecto.
- El portal del aliado (`dashboard.php:1222`) reconoce exactamente tres estados —
  `Estudio`, `Rechazado`, `Aprobado`— y los pinta con `status-estudio` (morado),
  `status-rechazado` (rojo) y `status-aprobado` (verde). Cualquier otro valor cae a
  `status-pendiente` (ámbar), que es el badge de "desconocido".
- **Otras cuatro apps fuera de este repo escriben en esta tabla**: `aka`, `aka_mejorado`,
  `aka_old` y `etiquetas`, todas bajo `C:\xampp\htdocs`.

### El conflicto que se resolvió antes de diseñar

El pedido original hablaba de filtrar por estado `"En elaboración"`. Ese valor **no existe en
ninguna parte del sistema**: nada lo escribe y el portal no lo sabe pintar. Filtrar por él
habría producido una tabla vacía para siempre.

**Decisión:** el estado pendiente es `'Estudio'`, el valor que ya nace por `DEFAULT` y que el
portal ya reconoce. Cero cambios en producción y cero riesgo con las otras cuatro apps.

**Consecuencia sobre el vocabulario:** el select de cambio de estado ofrece
`Estudio` / `Aprobado` / `Rechazado`, con esas mismas etiquetas visibles. Se descartó rotular
"En elaboración" en el admin porque haría que el administrador y el aliado leyeran palabras
distintas para la misma planilla. Cambiar solo la etiqueta visible, sin tocar el valor
guardado, sigue siendo posible en una línea si se prefiere después.

## Decisiones tomadas

| Decisión | Elegido | Por qué |
|---|---|---|
| Estado pendiente | `'Estudio'` | Es el valor real del sistema; ver arriba. |
| Alcance de la tabla | Todas las planillas, filtro por defecto en `Estudio` | Permite consultar historial y corregir un estado puesto por error. 304 filas no tienen costo. |
| Lista de motivos | Array PHP en código | Rafael entregará la lista definitiva; se reemplaza editando una línea. |
| Auditoría de quién cambió el estado | **No se registra** | Fuera de alcance por decisión explícita. Solo se actualizan `estado` y `motivo`. |
| DataTables | Sí, con jQuery por CDN | Pedido explícito. jQuery queda confinado a la página del admin. |
| SweetAlert | SweetAlert2 v11 por CDN | El proyecto **ya lo usa** por jsdelivr, igual que ECharts, Leaflet y Tom Select. |
| Estructura | Página + endpoints JSON + lib testeable | Sigue el patrón `api/` + `lib_*.php` del proyecto. |

## Cambio de esquema

```sql
ALTER TABLE INTEGRACION.dbo.consecutivo_planillas_aka
ADD motivo VARCHAR(200) NULL;
```

Nullable a propósito: las 304 filas existentes y todo lo aprobado se quedan en `NULL`. Solo
se llena al rechazar.

El script de migración comprueba primero con `COL_LENGTH('dbo.consecutivo_planillas_aka',
'motivo')` si la columna ya existe, de modo que pueda ejecutarse dos veces sin fallar — mismo
patrón que se usó al agregar `nit`.

La RDS es la misma para dev, staging y producción: el `ALTER` se aplica una sola vez y sirve
para los tres entornos.

## Arquitectura

Archivos nuevos:

```
admin/planillas.php               página: shell + tabla + JavaScript
admin/lib_planillas.php           lógica pura y testeable
admin/api/planillas_listar.php    GET  → JSON con las filas
admin/api/planillas_estado.php    POST → cambia estado y motivo
sql/ddl_motivo_planillas.php      migración idempotente de la columna
tests/planillas_test.php          pruebas
```

Archivos modificados:

```
admin/inicio.php                  añadir la primera entrada al array $menu
admin/lib_admin_auth.php          añadir admin_exigir_sesion_json()
```

**No se modifica ningún archivo del portal.** `dashboard.php`, `index.php`, `api/` y
`conexion/` están en producción y quedan intactos.

### API de `admin/lib_planillas.php`

```php
/** Motivos de rechazo. Lista provisional: se reemplaza por la definitiva de Rafael. */
const PLANILLA_MOTIVOS = [
    'Codificación incompleta',
    'Información del producto inconsistente',
    'Archivo ilegible o dañado',
    'Referencias duplicadas',
    'No cumple con el formato requerido',
];

/** Los únicos estados admitidos. Coinciden con los que el portal sabe pintar. */
const PLANILLA_ESTADOS = ['Estudio', 'Aprobado', 'Rechazado'];

/**
 * Todas las planillas, de la más reciente a la más antigua.
 * @return array filas ['consecutivo'=>int,'nombre_cliente'=>string,'nit'=>?string,
 *                      'fecha'=>string('Y-m-d'),'estado'=>string,'motivo'=>?string]
 */
function planillas_listar($conn): array

/**
 * Valida la transición ANTES de tocar la base.
 * @return string Cadena vacía si es válida; el mensaje de error si no.
 */
function planillas_validar(string $estado, ?string $motivo): string

/**
 * Aplica el cambio. Normaliza el motivo a NULL cuando el estado no es 'Rechazado'.
 * @return bool false si el consecutivo no existe.
 */
function planillas_cambiar_estado($conn, int $consecutivo, string $estado, ?string $motivo): bool
```

### Reglas de validación

Viven en `planillas_validar()`, del lado del servidor, **no** en el JavaScript: el JavaScript
corre en el cliente y se puede saltar.

1. `$estado` debe estar en `PLANILLA_ESTADOS`. Cualquier otro valor se rechaza.
2. Si `$estado === 'Rechazado'`, el motivo es **obligatorio** y debe coincidir exactamente con
   uno de `PLANILLA_MOTIVOS`. Nadie puede inyectar texto arbitrario esquivando el select.
3. Si `$estado !== 'Rechazado'`, el motivo se guarda como `NULL` aunque el cliente envíe uno.
   Así una planilla aprobada nunca arrastra el motivo de un rechazo anterior.

## Autenticación de los endpoints

`admin_exigir_sesion()` responde con un `Location:` al login. Dentro de un `fetch()` eso no
redirige: el JavaScript recibe el HTML del login y falla al parsear JSON. Por eso se añade a
`admin/lib_admin_auth.php`:

```php
/**
 * Guard para endpoints JSON. Mismo criterio que admin_exigir_sesion(), pero responde
 * 401 con JSON en vez de redirigir — un fetch() necesita un código, no HTML.
 */
function admin_exigir_sesion_json(): array
```

Sin sesión válida responde HTTP **401** con `{"ok":false,"error":"sesion_expirada"}` y
termina. El JavaScript distingue ese caso y muestra un aviso con botón al login.

### Contrato de los endpoints

| | `admin/api/planillas_listar.php` | `admin/api/planillas_estado.php` |
|---|---|---|
| Método | GET | POST |
| Entrada | — | `consecutivo`, `estado`, `motivo`, `csrf_token` |
| Éxito | `{"ok":true,"filas":[...]}` | `{"ok":true}` |
| Error | `{"ok":false,"error":"..."}` | `{"ok":false,"error":"..."}` |

Ambos empiezan con `admin_exigir_sesion_json()`, luego el `require` de la conexión.

**CSRF en el POST.** El cambio de estado es una escritura, así que exige el token: la página
embebe `$_SESSION['admin_csrf_token']` en un `<meta>` y el JavaScript lo envía en el cuerpo.
El endpoint lo compara con `hash_equals()`. Sin token válido → HTTP **403**, sin tocar la
base. El GET de listado no lo necesita porque no muta nada.

### Trampa heredada, aplicable a los dos endpoints

`conexion/conexion_integracion.php` filtra `$servidor`, `$basedatos`, `$usuario`, `$password`
e `$infoconn` al scope de quien lo incluye. Ya provocó un fallo en `admin/logout.php`. Ningún
local de estos archivos puede llamarse así — y como aquí se manejan datos que vienen del
cliente, la colisión sería peor que un log mal escrito. Ver
[2026-07-22-admin-login-design.md](2026-07-22-admin-login-design.md).

## Flujo de un rechazo

1. El admin abre `admin/planillas.php`; el guard normal `admin_exigir_sesion()` protege la
   página.
2. El JavaScript pide `planillas_listar.php` y DataTables pinta las filas, arrancando filtrada
   en `Estudio`.
3. Clic en **Cambiar estado** → SweetAlert con el select de estados. Elegir `Rechazado`
   habilita el segundo select con los motivos; elegir otro estado lo deshabilita y lo limpia.
4. Confirmar → `POST` a `planillas_estado.php`.
5. El servidor valida sesión, CSRF y `planillas_validar()`. Solo si todo pasa ejecuta
   `UPDATE ... WHERE consecutivo = ?`.
6. Respuesta `ok` → la fila se actualiza en la tabla sin recargar la página, más un toast de
   confirmación.

## Interfaz

`admin/planillas.php` reutiliza el layout del shell (barra superior + menú lateral +
contenido) y `admin/assets/admin.css`.

`admin/inicio.php` estrena su menú, que hasta ahora mostraba "Próximamente":

```php
$menu = array(
    array('etiqueta' => 'Planillas', 'icono' => 'fa-file-lines', 'url' => 'planillas.php'),
);
```

### La tabla

| Consecutivo | Cliente | NIT | Fecha | Estado | Motivo | Acción |
|---|---|---|---|---|---|---|
| 305 | BH Brands | — | 17/06/2026 | `Estudio` | — | **Cambiar estado** |

Los badges reusan los colores del portal (`dashboard.php:447-452`): morado `Estudio`, verde
`Aprobado`, rojo `Rechazado`. Una planilla se ve igual del lado del admin y del lado del
aliado.

Dos notas sobre los datos reales: el `NIT` está vacío en la práctica, así que esa columna
mostrará `—` casi siempre; y `Motivo` solo tendrá contenido en las rechazadas.

DataTables en español, ordenada por consecutivo descendente, con el filtro de estado
arrancando en `Estudio`.

**El filtrado es del lado del cliente.** `planillas_listar.php` devuelve las filas completas
en una sola llamada y DataTables filtra en memoria. Con 304 filas y cinco columnas de texto
corto, el JSON pesa unas decenas de kilobytes: no justifica paginación en servidor, y filtrar
o buscar queda instantáneo sin ida y vuelta a la RDS — que es lenta desde WMS-LAB. Si la
tabla creciera un orden de magnitud, habría que revisarlo.

## Manejo de errores

- **Tabla vacía** — hoy no hay ninguna planilla en `Estudio`. En vez del "No data available"
  genérico, un mensaje propio: *"No hay planillas pendientes de revisar."* Que esté vacía es
  normal, no un fallo.
- **Endpoint caído o conexión perdida** — SweetAlert de error, y la fila **no** cambia en
  pantalla. Nada de actualizarla optimistamente y que quede mintiendo respecto a la base.
- **Sesión expirada a mitad de trabajo** (401) — aviso propio con botón al login.
- **CSRF inválido** (403) — mensaje de recargar la página.
- El detalle de `sqlsrv_errors()` nunca llega al navegador.

## Pruebas

`tests/planillas_test.php`, ejecutable con `php tests/planillas_test.php`. Script plano, sin
framework, exit 0 si pasa y 1 si falla — igual que el resto de la suite.

**`planillas_validar()`, sin base de datos:**

1. `Aprobado` sin motivo → válido.
2. `Rechazado` sin motivo → **inválido**. Es la regla que protege el requisito.
3. `Rechazado` con un motivo fuera de `PLANILLA_MOTIVOS` → **inválido**.
4. `Rechazado` con motivo de la lista → válido.
5. Estado inventado (`"Borrado"`) → **inválido**.
6. `Aprobado` con motivo → válido, pero el motivo se descarta a `NULL`.

**`planillas_cambiar_estado()`, contra la base, con precaución explícita:**
`consecutivo_planillas_aka` es una **tabla viva de producción**. El test inserta su propia
fila, opera solo sobre el consecutivo que él mismo generó, y la borra al terminar. Nunca toca
una fila que no haya creado. Es el patrón que ya usó el proyecto al probar
`cod_registrar_envio()`.

**Verificación manual con `curl`**, como en las tareas del login: listar, rechazar con motivo,
confirmar que el `UPDATE` llegó a la base, y comprobar que el endpoint rechaza un POST sin
CSRF y otro sin sesión.

## Fuera de alcance

- Registrar quién cambió el estado y cuándo (decisión explícita).
- Notificar al aliado por correo cuando su planilla cambia de estado.
- Editar la lista de motivos desde la interfaz.
- Cualquier otro módulo administrativo.
- Despliegue a WMS-LAB.
