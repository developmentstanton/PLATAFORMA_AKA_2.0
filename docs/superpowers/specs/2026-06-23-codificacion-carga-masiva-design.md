# Spec — Codificación: Carga Masiva real (subir Excel → guardar en tabla + correo)

**Fecha:** 2026-06-23
**Proyecto:** plataforma_20
**Sección:** GESTIÓN → Codificación → pestaña "Archivos" → card "CARGA MASIVA DE CODIFICACIÓN"

## Objetivo

Dar funcionalidad real a la card "CARGA MASIVA DE CODIFICACIÓN" (hoy es demo estático). El aliado
(proveedor) podrá **arrastrar o seleccionar** uno o varios archivos Excel desde su equipo y pulsar
**Enviar**. Al enviar:

1. Se **registra el envío** en `INTEGRACION.dbo.consecutivo_planillas_aka` (con una **nueva columna `nit`**).
2. Se **envía un correo** de confirmación, con **formato moderno** (paleta del portal + logo AKA), al aliado
   (con copia oculta al equipo interno) y los Excel **adjuntos**.

Se parte de una lógica existente del portal viejo (PHP suelto que hacía `$_FILES` + INSERT + PHPMailer),
adaptándola a la SPA de `dashboard.php`, parametrizando el SQL y modernizando el correo.

## Decisiones (brainstorming 2026-06-23, aprobadas por Rafael)

- **Integración:** endpoint AJAX + SweetAlert (no form-post con redirect a `gracias.php`). Mantiene la SPA.
- **Archivos:** **solo se adjuntan al correo**, NO se guardan en disco del servidor. La tabla solo registra
  metadatos (nombre del aliado, fecha, nit; consecutivo autoincremental).
- **Cantidad:** se permiten **varios** archivos por envío (todos se adjuntan y cuentan como una sola carga / un consecutivo).
- **Destinatarios (sin cambios respecto al código viejo):**
  - TO = correo del aliado (de `usuarios_portal_aka.correo`).
  - BCC = `jpmartin@stanton.co`, `masterdata2@stanton.co`, `directorplataformaaka@cauchosol.co`.
- **NIT:** se toma de `$_SESSION['nit']` (capturado en login; ver [[plataforma-20-an-lisis-de-pagos]]). Si está
  vacío (el usuario no cruzó con un tercero), se inserta `NULL` y el correo igual se envía.
- **Credenciales SMTP:** fuera de git, en `conexion/config_mail.php` (la carpeta `conexion/` ya está en `.gitignore`).

## Estado actual confirmado

### Tabla `INTEGRACION.dbo.consecutivo_planillas_aka` (introspección 2026-06-23)

| # | Columna | Tipo | Null | Identity | Default |
|---|---------|------|------|----------|---------|
| 1 | `consecutivo` | int | NO | **Sí (PK)** | — |
| 2 | `nombre_cliente` | varchar(60) | NO | no | — |
| 3 | `fecha` | date | NO | no | — |
| 4 | `estado` | varchar(20) | NO | no | `'Estudio'` |

304 filas; último `consecutivo` = 304.

**Gotcha confirmado:** el `INSERT INTO consecutivo_planillas_aka VALUES ('$nombre','$fecha')` del código viejo
(2 valores, sin lista de columnas) **hoy falla**, porque tras agregar `estado` NOT NULL (2026-06-19) un INSERT
posicional exige valor para `nombre_cliente`, `fecha` **y** `estado`. Por eso el endpoint nuevo usa **lista de
columnas explícita** y deja que `consecutivo` (IDENTITY) y `estado` (DEFAULT) se autocompleten.

### Card "CARGA MASIVA" hoy (`dashboard.php`, ~líneas 838-881)
- Dropzone visual (`.upload-excel`) **sin** `<input type="file">` real → no sube nada.
- Bloque demo "validado" con tabla de referencias ficticias + botón "ENVIAR 13 SOLICITUDES" → se **elimina**.

### Infra de correo
- `plataforma_20` **no** tiene PHPMailer. Se agrega copiando `PHPMailer/src` (Exception/PHPMailer/SMTP) y
  haciendo `require` de los 3 archivos (método liviano, sin composer; igual que otros proyectos del equipo).
- SMTP del portal AKA: `smtp.gmail.com:587` STARTTLS, usuario `plataforma@tiendasaka.co` (clave de aplicación
  de Gmail). **La clave NO va al repo** (va en `conexion/config_mail.php`).

## Arquitectura

```
[ Card CARGA MASIVA (dashboard.php) ]
   input file (drag/drop + clic, multiple, .xlsx/.xls)
   → lista de archivos seleccionados (JS)
   → botón "Enviar"  --(fetch FormData)-->  [ api/codificacion_cargar.php ]
                                                 ├─ valida sesión + archivos
                                                 ├─ lee $_SESSION['usuario'], ['nit']
                                                 ├─ SELECT correo (parametrizado)
                                                 ├─ INSERT (nombre_cliente, fecha, nit) OUTPUT consecutivo
                                                 ├─ arma correo HTML moderno
                                                 ├─ PHPMailer: TO aliado + BCC equipo + adjuntos
                                                 └─ devuelve JSON {status, consecutivo, message}
   ← SweetAlert "Enviando…" → éxito ("Recibimos tu portafolio, consecutivo #N") / error
```

### Componentes y responsabilidades

1. **Frontend — card CARGA MASIVA (`dashboard.php`)**
   - `<input type="file" id="codFile" accept=".xlsx,.xls" multiple hidden>` + dropzone clickeable que dispara el input.
   - Eventos drag (`dragover`/`dragleave`/`drop`) sobre `.upload-excel` para soltar archivos.
   - Lista de seleccionados con nombre, tamaño legible y botón "quitar" (estado en un array JS).
   - Botón **Enviar** deshabilitado si no hay archivos; al click → `FormData` con `adjunto[]` → `fetch('api/codificacion_cargar.php')`.
   - SweetAlert: loading mientras sube; success con consecutivo; error con mensaje del JSON.
   - Se borra el bloque demo "validado" y su botón.
   - **Sin dependencia de posición**: la lógica de pestañas (`showCodTab`) no se toca.

2. **Endpoint `api/codificacion_cargar.php`** (nuevo)
   - `session_start()`; si no hay `$_SESSION['usuario']` → 401 JSON.
   - Valida `$_FILES['adjunto']` no vacío; cada archivo con extensión `.xlsx/.xls` y `error === UPLOAD_ERR_OK`;
     **límite: máx 10 MB por archivo y máx 10 archivos por envío** (si se excede → 400 con mensaje claro).
   - `require conexion/conexion_integracion.php` + `require conexion/config_mail.php`.
   - `SELECT correo FROM usuarios_portal_aka WHERE nombre_usuario = ?` (**parametrizado**).
   - `INSERT INTO consecutivo_planillas_aka (nombre_cliente, fecha, nit) OUTPUT INSERTED.consecutivo VALUES (?,?,?)`
     con `fecha = date('Y-m-d')`, `nit = $_SESSION['nit'] ?? null`. Fetch del `consecutivo` devuelto por OUTPUT
     (manejar el quirk de sqlsrv si la primera fila es el rows-affected → `sqlsrv_next_result` si aplica).
   - Construye el HTML del correo (helper interno `construirCorreoHtml($datos)`).
   - PHPMailer (config desde `config_mail.php`): `setFrom`, `addAddress($correoAliado)`, 3 `addBCC`, `isHTML(true)`,
     `CharSet='UTF-8'`, `Subject = "RECIBIDO DE PLANTILLAS PLATAFORMA AKA - {consecutivo}"`, `addAttachment(tmp_name, name)` por archivo.
   - Respuesta JSON: éxito `{status:'success', consecutivo, message}`; error `{status:'error', message}` (sin volcar `sqlsrv_errors` crudos al usuario; loguear con `error_log`).

3. **DB — `conexion/config_mail.php`** (nuevo, gitignored)
   - Constantes: `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASS`, `MAIL_FROM_NAME`, y arreglo `MAIL_BCC`.
   - Se crea también `conexion/config_mail.example.php` (sin la clave) para el repo, documentando las claves.

4. **DB — cambio de esquema**
   ```sql
   ALTER TABLE INTEGRACION.dbo.consecutivo_planillas_aka ADD nit VARCHAR(20) NULL;
   ```
   Ejecutado vía script PHP temporal con la conexión del proyecto (como el cambio de `estado` del 2026-06-19).
   Es cambio en **producción (RDS)**, no viaja por git.

5. **Librería — `PHPMailer/src/`** (Exception.php, PHPMailer.php, SMTP.php) agregada al repo.

### Diseño del correo (moderno)

HTML con estilos **inline** (compatibilidad email), paleta del portal `--primary #4A4782`, logo AKA
(`http://bi.stanton.com.co:81/portal/img/logo_aka2_corto.png`). Secciones:
- Banda superior morada con el logo.
- Saludo "Estimado aliado, {NOMBRE}" + mensaje "Hemos recibido el cargue de tu portafolio. Será validado por
  nuestro equipo de curaduría y te daremos respuesta próximamente."
- Tarjeta "Detalle del envío": Consecutivo `#N`, Aliado, NIT, Correo, Fecha, lista de Archivos adjuntos.
- Cierre "Cordialmente, Equipo Tiendas AKA".
- Footer gris: "Mensaje automático · no responder".

## Manejo de errores

- Sin sesión → 401 JSON, el front redirige/avisa.
- Sin archivos o tipo inválido → 400 JSON "Adjunta al menos un archivo .xlsx".
- Falla de conexión a BD (intermitente en RDS; ver gotcha de [[plataforma-20-an-lisis-de-pagos]]) → reintento corto (4×, 0.3s) y, si persiste, error claro.
- Falla de INSERT → error JSON, no se intenta enviar correo.
- Falla de envío de correo **después** del INSERT → el registro ya quedó; se responde con advertencia
  ("Registrado pero el correo no pudo enviarse") y se loguea `ErrorInfo`. (El consecutivo no se revierte.)
- Nunca se exponen credenciales ni `sqlsrv_errors` crudos al cliente.

## Seguridad

- **Todo el SQL parametrizado** (la versión vieja era inyectable vía `$nombre`).
- Credenciales SMTP fuera de git.
- Validación de extensión y tamaño de archivos en el servidor (no confiar en el cliente).
- Nota: la clave de aplicación de Gmail quedó expuesta en el código viejo (y en el chat de diseño);
  recomendado rotarla en Google tras el despliegue (acción manual de Rafael).

## Testing

- **Unit/endpoint (PHP harness, como `tests/pagos_*`):**
  - Validación: sin archivos → 400; extensión inválida → 400; sin sesión → 401.
  - INSERT: inserta fila con `nit` correcto y `estado` por defecto 'Estudio'; devuelve consecutivo > 0; con
    `$_SESSION['nit']` vacío inserta `NULL` sin error.
  - Helper `construirCorreoHtml`: contiene consecutivo, nombre, nit, lista de archivos; escapado de HTML.
  - (Envío SMTP real no se prueba en CI; se valida manualmente con un envío de prueba.)
- **E2E navegador (Rafael):** arrastrar 1 y varios .xlsx, Enviar, ver SweetAlert de éxito con consecutivo, y
  confirmar llegada del correo (TO + BCC) con adjuntos y formato.
- **No-regresión:** `php -l` en `dashboard.php` y el endpoint; la pestaña "Archivos" y "Mis solicitudes" siguen alternando.

## Fuera de alcance (YAGNI)

- Persistir archivos en disco / histórico físico.
- Parseo/validación del contenido del Excel (Plantilla 270) — hoy es solo recepción.
- Flujo de aprobación que lea/escriba `estado` desde la UI (existe la columna pero sin UI; queda para otra iteración).
- Reescribir "Mis solicitudes" (sigue con datos demo; no se toca).

## Artefactos a crear/modificar

- **Nuevo:** `api/codificacion_cargar.php`, `conexion/config_mail.php` (gitignored) + `conexion/config_mail.example.php`,
  `PHPMailer/src/*`, `tests/codificacion_cargar_test.php`.
- **Modificado:** `dashboard.php` (card CARGA MASIVA), `.gitignore` (asegurar `conexion/config_mail.php`).
- **BD (producción, no git):** `ALTER TABLE ... ADD nit`.
