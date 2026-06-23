# Spec — Documentación: subir/listar/descargar documentos del aliado

**Fecha:** 2026-06-23
**Proyecto:** plataforma_20
**Sección:** GESTIÓN → Documentación (`#page-documentos` en `dashboard.php`)

## Objetivo

Convertir la página **Documentación** (hoy demo estática) en un repositorio real de documentos del aliado
logueado. Tres tipos de documento (**Contrato, RUT, Cámara de Comercio**). El aliado sube un archivo (PDF o
imagen) eligiendo su tipo; el archivo se guarda **en la base de datos** (`VARBINARY(MAX)`); la tabla lista los
documentos del aliado con un botón **Descargar**.

## Decisiones (brainstorming 2026-06-23, aprobadas por Rafael)

- **Almacenamiento:** en BD, columna `VARBINARY(MAX)` en una **tabla nueva** en INTEGRACION (SQL Server 2019 lo soporta).
  No filesystem.
- **Modelo de subida:** **un tipo + un archivo por subida** (modal: elige tipo, arrastra/selecciona 1 archivo, sube).
- **Multiplicidad:** se **guarda historial** — cada subida es una fila nueva; el listado muestra todas (más reciente
  primero). No hay reemplazo.
- **Formatos:** `.pdf, .jpg, .jpeg, .png`. Máx **10 MB** por archivo.
- **Alcance:** SOLO los documentos del aliado logueado. Filtro por `nombre_cliente = $_SESSION['usuario']`
  (clave consistente con el resto del portal; ver [[2026-06-23-codificacion-mis-solicitudes-design]]).
- **Listado:** columnas **Tipo · Nombre del archivo · Fecha · NIT · Nombre del tercero · Descargar**.
- **UI de subida:** modal (`#modalDocumento`), patrón del modal de codificación; dropzone como en la Carga Masiva.

## Estado actual

- `#page-documentos` (`dashboard.php`, ~líneas 877-896): 4 `filter-chip` (Todos/Contratos/Certificados/RUT) + botón
  `+ SUBIR DOCUMENTO` + tabla demo (Título/Tipo/Inicio/Fin/Estado/Descargar, datos ficticios).
- **No existe** ninguna tabla de documentos en INTEGRACION (el `documentacion_aliados` del archivo SQL de diseño nunca
  se creó). SQL Server **2019** (VARBINARY(MAX) OK).
- Sesión: `$_SESSION['usuario']` (= nombre del aliado / `nombre_cliente`) y `$_SESSION['nit']` se setean en login.
- Patrones disponibles: reintento de conexión RDS 4×, SweetAlert, dropzone drag/drop (Carga Masiva).

## Base de datos — tabla nueva `INTEGRACION.dbo.documentos_aliados_aka`

```sql
CREATE TABLE dbo.documentos_aliados_aka (
    id              INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    nombre_cliente  VARCHAR(60)   NOT NULL,   -- = $_SESSION['usuario'] (clave de filtro)
    nit             VARCHAR(20)   NULL,        -- = $_SESSION['nit']
    tipo            NVARCHAR(40)  NOT NULL,    -- 'Contrato' | 'RUT' | 'Cámara de Comercio'
    nombre_archivo  NVARCHAR(255) NOT NULL,
    mime            VARCHAR(100)  NOT NULL,
    tamano          INT           NOT NULL,    -- bytes
    contenido       VARBINARY(MAX) NOT NULL,   -- el archivo
    fecha           DATETIME2     NOT NULL DEFAULT SYSDATETIME()
);
CREATE INDEX idx_documentos_aliado ON dbo.documentos_aliados_aka (nombre_cliente);
```

- El `tipo` se valida en **PHP** contra `['Contrato','RUT','Cámara de Comercio']` (no CHECK con acentos en BD → evita
  gotchas de encoding al crear la tabla vía driver).
- Se crea con un **script PHP temporal** con la conexión del proyecto (como el `ALTER ... ADD nit`); es cambio en
  **producción (RDS)**, no viaja por git. Idempotente (verifica existencia antes de crear).

## Arquitectura

```
[ #page-documentos (dashboard.php) ]
  showPage('documentos')  →  cargarDocumentos()  --(fetch)-->  [ api/documentos_listar.php ]  → tabla (sin binario)
  botón "Subir documento" → #modalDocumento (select tipo + dropzone 1 archivo)
       └─ "Subir" --(FormData)--> [ api/documentos_subir.php ] → valida + INSERT (VARBINARY) → JSON → recarga lista
  botón "Descargar" → enlace a [ api/documentos_descargar.php?id=N ] → stream del binario (valida dueño)
```

### Componentes

1. **`api/lib_documentos.php`** (helpers puros/testeables)
   - `DOC_TIPOS = ['Contrato','RUT','Cámara de Comercio']`, `DOC_EXT_OK = ['pdf','jpg','jpeg','png']`, `DOC_MAX_BYTES = 10485760`.
   - `doc_validar(?string $tipo, $archivo): array` → `['ok'=>bool,'error'=>?string]`. Valida: tipo ∈ DOC_TIPOS; `$archivo`
     presente con `error===UPLOAD_ERR_OK`; extensión ∈ DOC_EXT_OK; `size` ≤ DOC_MAX_BYTES.
   - `doc_mime_por_ext(string $ext): string` → mime esperado (`pdf`→application/pdf, `jpg/jpeg`→image/jpeg, `png`→image/png).
   - `doc_guardar($conn, string $nombre, ?string $nit, string $tipo, string $nombreArchivo, string $mime, string $contenido): int`
     → INSERT (con bind binario) y devuelve el `id`. Lanza `RuntimeException` si falla.
   - `doc_listar($conn, string $nombre): array` → filas `['id'=>int,'tipo'=>string,'nombre_archivo'=>string,'fecha'=>'Y-m-d H:i','nit'=>?string,'nombre'=>string,'tamano'=>int]`, **sin** `contenido`, `ORDER BY id DESC`, filtrado por `nombre_cliente = ?`.
   - `doc_obtener($conn, int $id, string $nombre): ?array` → `['nombre_archivo','mime','contenido']` SOLO si el doc pertenece a `$nombre` (filtro `WHERE id=? AND nombre_cliente=?`); null si no existe/!pertenece.

2. **`api/documentos_subir.php`** (POST)
   - Sesión (401 si no); `$nombre=$_SESSION['usuario']`, `$nit=$_SESSION['nit']??null`.
   - `$tipo = $_POST['tipo']`; `$archivo = $_FILES['documento']`.
   - `doc_validar` → 400 si falla. `is_uploaded_file($archivo['tmp_name'])` → 400 si no.
   - Lee binario: `$contenido = file_get_contents($archivo['tmp_name'])`. `$mime = doc_mime_por_ext(ext)`.
   - Conexión + reintento RDS. `doc_guardar(...)`. Respuesta `{ok:true, id}` (o `{ok:false,error}`).

3. **`api/documentos_listar.php`** (GET)
   - Sesión (401). Conexión + reintento. `doc_listar($conn, $nombre)`. `{ok:true, documentos:[...]}`.

4. **`api/documentos_descargar.php`** (GET `?id=N`)
   - Sesión (si no → 401 texto/redirect, no JSON, porque es descarga directa).
   - `$id = (int)$_GET['id']`. Conexión + reintento. `doc_obtener($conn, $id, $nombre)`.
   - Si null → `http_response_code(404)` + mensaje simple. Si existe: headers
     `Content-Type: {mime}`, `Content-Disposition: attachment; filename="{nombre_archivo}"`,
     `Content-Length: {strlen(contenido)}`, y `echo $contenido`. (No JSON; salida binaria.)

5. **`dashboard.php` — página Documentación + modal + JS**
   - **Quitar** el `<div class="filters">` con los 4 chips (Todos/Contratos/Certificados/RUT). Conservar el botón
     "+ SUBIR DOCUMENTO" (ahora `onclick` abre `#modalDocumento`).
   - **Tabla real**: `<thead>` Tipo · Nombre del archivo · Fecha · NIT · Nombre del tercero · (Descargar);
     `<tbody id="docBody">` cargado por JS.
   - **Modal `#modalDocumento`** (overlay como `#modalCodificacion`): `<select id="docTipo">` con las 3 opciones +
     dropzone (`#docDrop` + `<input type="file" id="docFile" accept=".pdf,.jpg,.jpeg,.png">`, 1 archivo) + nombre del
     archivo seleccionado + botón "Subir" (deshabilitado hasta tener tipo+archivo) + "Cancelar".
   - **JS:** `docAbrirModal()`, manejo del dropzone (1 archivo, valida ext/tamaño en cliente), `docSubir()`
     (`FormData` con `tipo`+`documento` → `documentos_subir.php` → SweetAlert → cierra modal → `cargarDocumentos()`),
     `cargarDocumentos()` (`fetch` `documentos_listar.php` → pinta filas; Descargar = `<a href="api/documentos_descargar.php?id=N">`).
     Escapar contenido dinámico. Fecha `Y-m-d H:i` → `dd/mm/yyyy HH:MM`. NIT null → `—`.
   - **Disparo lazy:** al entrar a la página Documentación (`showPage('documentos')`), llamar `cargarDocumentos()`.

### Gotchas sqlsrv (binario)

- **INSERT del binario:** bind con tipo binario explícito:
  ```php
  $params = [
      $nombre, $nit, $tipo, $nombreArchivo, $mime, $tamano,
      [$contenido, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY), SQLSRV_SQLTYPE_VARBINARY('max')],
  ];
  ```
  (el `$contenido` puede pasarse como string; el SQLTYPE VARBINARY('max') evita truncados/encoding).
- **SELECT del binario (descarga):** recuperar como binario:
  ```php
  sqlsrv_fetch($stmt);
  $bin = sqlsrv_get_field($stmt, $idxContenido, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY));
  ```
- En `doc_listar` **no** se selecciona `contenido` (evita traer blobs a memoria para la lista).

## Manejo de errores

- Sin sesión → 401 (JSON en subir/listar; texto/redirect en descargar).
- Tipo inválido / sin archivo / extensión / tamaño → 400 con mensaje claro.
- Falla de conexión RDS tras reintentos → 500.
- Descargar id inexistente o de otro aliado → 404 (no se distingue "no existe" de "no es tuyo", evita enumeración).
- Nunca se exponen `sqlsrv_errors` crudos (se loguean con `error_log`).

## Seguridad

- SQL parametrizado en todo.
- Filtro por `$_SESSION['usuario']` server-side (nunca input del cliente) en listar y descargar.
- **Descarga valida propiedad** (`WHERE id=? AND nombre_cliente=?`) → un aliado no puede bajar documentos de otro por id.
- Validación de tipo (allowlist), extensión y tamaño en servidor; `is_uploaded_file`.
- `Content-Disposition: attachment` (no inline) para no ejecutar/rendererizar en el navegador.
- Nombre de archivo escapado en el render del listado (XSS) y saneado en el header de descarga (quitar saltos de línea/comillas).

## Testing

- **`tests/documentos_test.php`** (guarded `COD_TEST_DB=1`):
  - `doc_validar`: tipo fuera de lista → falla; ext inválida (.exe) → falla; >10 MB → falla; pdf/jpg/png válidos → ok.
  - **Round-trip binario:** `doc_guardar` con un contenido binario pequeño conocido (p.ej. bytes `%PDF-1.4...` o 256 bytes
    aleatorios fijos) para un aliado de prueba; `doc_listar` lo devuelve (sin `contenido`, con tipo/nombre/tamaño correctos,
    orden id DESC); `doc_obtener` devuelve los **bytes idénticos** (comparación exacta) y respeta propiedad (con otro
    `$nombre` → null). **Limpieza:** DELETE de las filas insertadas.
  - Sin `COD_TEST_DB` → SKIP.
- **No-regresión:** `php -l` en `dashboard.php` y los 3 endpoints + `lib_documentos.php`; suites previas verdes.
- **E2E navegador (Rafael):** subir un PDF y una imagen de cada tipo; ver el listado (orden, tipo, fecha, nit, nombre);
  descargar y abrir correctamente; confirmar que los 4 chips ya no están.

## Fuera de alcance (YAGNI)

- Filtros por tipo en el listado (se quitaron los chips; el historial es corto por aliado).
- Vigencia / fecha de inicio-fin / estado (Vigente/Por vencer) — el demo los tenía; el diseño nuevo no los pide.
- Borrar/editar documentos desde la UI.
- Previsualización inline (se descarga como attachment).
- Vista admin de todos los aliados.

## Artefactos

- **Nuevo:** `api/lib_documentos.php`, `api/documentos_subir.php`, `api/documentos_listar.php`,
  `api/documentos_descargar.php`, `tests/documentos_test.php`.
- **Modificado:** `dashboard.php` (quitar chips; tabla real; modal `#modalDocumento`; JS; disparo en `showPage`).
- **BD (producción, no git):** `CREATE TABLE documentos_aliados_aka`.
