# Documentación — subir/listar/descargar documentos: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el aliado suba documentos (Contrato/RUT/Cámara de Comercio) como PDF o imagen, se guarden en una tabla nueva de INTEGRACION (`VARBINARY`), y la página Documentación liste sus documentos con botón Descargar.

**Architecture:** Tabla nueva con el archivo en `VARBINARY(MAX)`. Helpers testeables en `api/lib_documentos.php`; 3 endpoints (subir/listar/descargar) que orquestan sobre el lib; el front (`dashboard.php`) abre un modal para subir y pinta la lista al entrar a la página.

**Tech Stack:** PHP + `sqlsrv` (SQL Server 2019 RDS, binario vía `SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY)` + `SQLSRV_SQLTYPE_VARBINARY('max')`), JS vanilla + SweetAlert2.

## Global Constraints

- **SQL parametrizado** en todo. Filtro por `nombre_cliente = $_SESSION['usuario']` (server-side, NUNCA input del cliente) en listar y descargar.
- **Tabla:** `INTEGRACION.dbo.documentos_aliados_aka` (id IDENTITY, nombre_cliente VARCHAR(60), nit VARCHAR(20) NULL, tipo NVARCHAR(40), nombre_archivo NVARCHAR(255), mime VARCHAR(100), tamano INT, contenido VARBINARY(MAX), fecha DATETIME2 DEFAULT SYSDATETIME()).
- **Tipos válidos (allowlist, validados en PHP):** `['Contrato','RUT','Cámara de Comercio']`. **Formatos:** `pdf, jpg, jpeg, png`. **Máx 10 MB** (10485760).
- **NIT** de `$_SESSION['nit']` (null si vacío). **Aliado** de `$_SESSION['usuario']`.
- **Historial:** cada subida es una fila; lista `ORDER BY id DESC`. Listado SIN el binario.
- **Descarga valida propiedad** (`WHERE id=? AND nombre_cliente=?`); si no → 404 (no distingue inexistente de ajeno). `Content-Disposition: attachment`.
- **Reintento RDS** 4× con `usleep(300000)`. Escapar contenido dinámico (XSS). `error_log` para errores, nunca `sqlsrv_errors` crudos al cliente.
- **Encoding:** `api/lib_documentos.php` y la opción del `<select>` deben quedar en **UTF-8** para que 'Cámara de Comercio' matchee (conexión ya usa `CharacterSet=UTF-8`).
- `php -l` limpio en cada archivo PHP tocado.

## File Structure

- **Create** `api/lib_documentos.php` — constantes + `doc_validar`, `doc_mime_por_ext`, `doc_guardar`, `doc_listar`, `doc_obtener`.
- **Create** `api/documentos_subir.php`, `api/documentos_listar.php`, `api/documentos_descargar.php`.
- **Create** `tests/documentos_validar_test.php` (sin BD), `tests/documentos_db_test.php` (guarded `COD_TEST_DB=1`).
- **Modify** `dashboard.php` — página Documentación (quitar chips, tabla real, modal `#modalDocumento`, JS, wire en `showPage`).
- **DB (producción, no git):** `CREATE TABLE documentos_aliados_aka`.

---

### Task 1: Crear la tabla `documentos_aliados_aka` (cambio de BD)

**Files:** Create (temporal): `scratchpad/crear_tabla_documentos.php` (se borra al final)

**Interfaces:**
- Produces: tabla `INTEGRACION.dbo.documentos_aliados_aka` con las columnas del Global Constraints + índice por `nombre_cliente`.

Cambio en producción (RDS), no viaja por git. Setup verificable (sin TDD).

- [ ] **Step 1: Escribir el script (idempotente)**

```php
<?php // scratchpad/crear_tabla_documentos.php
require 'C:/xampp/htdocs/plataforma_20/conexion/conexion_integracion.php';
if ($dbConnect === false) { echo "NO CONN\n"; exit(1); }
$chk = sqlsrv_query($dbConnect, "SELECT OBJECT_ID('dbo.documentos_aliados_aka') AS oid");
$oid = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC)['oid'];
if ($oid !== null) { echo "Tabla YA existe. Nada que hacer.\n"; exit(0); }
$sql = "CREATE TABLE dbo.documentos_aliados_aka (
    id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    nombre_cliente VARCHAR(60) NOT NULL,
    nit VARCHAR(20) NULL,
    tipo NVARCHAR(40) NOT NULL,
    nombre_archivo NVARCHAR(255) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    tamano INT NOT NULL,
    contenido VARBINARY(MAX) NOT NULL,
    fecha DATETIME2 NOT NULL DEFAULT SYSDATETIME()
)";
$r = sqlsrv_query($dbConnect, $sql);
if ($r === false) { echo "CREATE FALLO:\n"; print_r(sqlsrv_errors()); exit(1); }
sqlsrv_query($dbConnect, "CREATE INDEX idx_documentos_aliado ON dbo.documentos_aliados_aka (nombre_cliente)");
echo "CREATE OK.\n";
$v = sqlsrv_query($dbConnect, "SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='documentos_aliados_aka'");
echo "columnas = ".sqlsrv_fetch_array($v, SQLSRV_FETCH_ASSOC)['n']."\n";
```

- [ ] **Step 2: Ejecutar**

Run: `php "C:\Users\USUARIO\AppData\Local\Temp\claude\C--xampp-htdocs\cccddd52-3428-486e-8782-3766763c9761\scratchpad\crear_tabla_documentos.php"`
Expected: `CREATE OK.` y `columnas = 9` (o "YA existe").

- [ ] **Step 3: Borrar el temporal**

```bash
rm -f scratchpad/crear_tabla_documentos.php
```
No hay commit (cambio de BD).

---

### Task 2: `lib_documentos.php` — validación + mime (TDD, sin BD)

**Files:**
- Create: `api/lib_documentos.php`
- Test: `tests/documentos_validar_test.php`

**Interfaces:**
- Produces:
  - Constantes `DOC_TIPOS = ['Contrato','RUT','Cámara de Comercio']`, `DOC_EXT_OK = ['pdf','jpg','jpeg','png']`, `DOC_MAX_BYTES = 10485760`.
  - `doc_validar(?string $tipo, $archivo): array` → `['ok'=>bool,'error'=>?string]`.
  - `doc_mime_por_ext(string $ext): string`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php // tests/documentos_validar_test.php
//   php tests/documentos_validar_test.php
require __DIR__ . '/../api/lib_documentos.php';
$fail=0; function chk($c,$m){ global $fail; if(!$c){ echo "FALLO: $m\n"; $fail=1; } }
function mkf($name,$size=1000,$err=UPLOAD_ERR_OK){ return ['name'=>$name,'tmp_name'=>'/tmp/'.$name,'size'=>$size,'error'=>$err]; }

chk(doc_validar('Otro', mkf('a.pdf'))['ok']===false, "tipo fuera de lista debe fallar");
chk(doc_validar(null, mkf('a.pdf'))['ok']===false, "tipo null debe fallar");
chk(doc_validar('Contrato', mkf('contrato.pdf'))['ok']===true, "Contrato + pdf ok");
chk(doc_validar('RUT', mkf('rut.JPG'))['ok']===true, "RUT + JPG (case-insensitive) ok");
chk(doc_validar('Cámara de Comercio', mkf('cc.png'))['ok']===true, "Cámara + png ok");
chk(doc_validar('Contrato', mkf('virus.exe'))['ok']===false, "exe debe fallar");
chk(doc_validar('Contrato', mkf('big.pdf', 11*1024*1024))['ok']===false, ">10MB debe fallar");
chk(doc_validar('Contrato', mkf('a.pdf',1000,UPLOAD_ERR_NO_FILE))['ok']===false, "sin archivo debe fallar");
chk(doc_mime_por_ext('pdf')==='application/pdf', "mime pdf");
chk(doc_mime_por_ext('PNG')==='image/png', "mime png (case-insensitive)");
chk(doc_mime_por_ext('jpeg')==='image/jpeg', "mime jpeg");
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK\n"; exit($fail);
```

- [ ] **Step 2: Ejecutar (debe fallar)**

Run: `php tests/documentos_validar_test.php`
Expected: error fatal "Call to undefined function doc_validar()".

- [ ] **Step 3: Crear `api/lib_documentos.php` (GUARDAR EN UTF-8)**

```php
<?php
/**
 * Helpers de Documentación (validación pura + acceso a BD). NO ejecuta nada al incluirse.
 * NOTA: archivo en UTF-8 (por el tipo 'Cámara de Comercio').
 */

const DOC_TIPOS     = ['Contrato', 'RUT', 'Cámara de Comercio'];
const DOC_EXT_OK    = ['pdf', 'jpg', 'jpeg', 'png'];
const DOC_MAX_BYTES = 10485760; // 10 MB

/** Valida tipo (allowlist) + archivo (subido, extensión, tamaño). @return ['ok'=>bool,'error'=>?string] */
function doc_validar(?string $tipo, $archivo): array {
    if (!in_array($tipo, DOC_TIPOS, true))
        return ['ok'=>false, 'error'=>'Tipo de documento inválido.'];
    if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
        return ['ok'=>false, 'error'=>'Adjunta un archivo.'];
    $ext = strtolower(pathinfo((string)($archivo['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, DOC_EXT_OK, true))
        return ['ok'=>false, 'error'=>'Solo se permiten PDF o imágenes (.pdf, .jpg, .jpeg, .png).'];
    if ((int)($archivo['size'] ?? 0) > DOC_MAX_BYTES)
        return ['ok'=>false, 'error'=>'El archivo supera el máximo de 10 MB.'];
    return ['ok'=>true, 'error'=>null];
}

/** Mime esperado por extensión. */
function doc_mime_por_ext(string $ext): string {
    switch (strtolower($ext)) {
        case 'pdf':  return 'application/pdf';
        case 'png':  return 'image/png';
        case 'jpg':
        case 'jpeg': return 'image/jpeg';
        default:     return 'application/octet-stream';
    }
}
```

- [ ] **Step 4: Ejecutar (debe pasar)**

Run: `php tests/documentos_validar_test.php`
Expected: `RESULTADO: OK`

- [ ] **Step 5: Commit**

```bash
git add api/lib_documentos.php tests/documentos_validar_test.php
git commit -m "feat(documentacion): validacion de tipo/archivo en lib_documentos (TDD)"
```

---

### Task 3: `lib_documentos.php` — guardar/listar/obtener (TDD binario, guarded)

**Files:**
- Modify: `api/lib_documentos.php` (añadir 3 funciones al final)
- Test: `tests/documentos_db_test.php`

**Interfaces:**
- Consumes: nada de tasks previas (mismo lib).
- Produces:
  - `doc_guardar($conn, string $nombre, ?string $nit, string $tipo, string $nombreArchivo, string $mime, string $contenido): int` (devuelve id; lanza `RuntimeException` si falla).
  - `doc_listar($conn, string $nombre): array` → filas `['id'=>int,'tipo'=>string,'nombre_archivo'=>string,'tamano'=>int,'nit'=>?string,'nombre'=>string,'fecha'=>'Y-m-d H:i']` (SIN `contenido`, `ORDER BY id DESC`).
  - `doc_obtener($conn, int $id, string $nombre): ?array` → `['nombre_archivo'=>string,'mime'=>string,'contenido'=>string]` solo si pertenece a `$nombre`; null si no.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php // tests/documentos_db_test.php
//   COD_TEST_DB=1 php tests/documentos_db_test.php
if (getenv('COD_TEST_DB') !== '1') { echo "SKIP (exporta COD_TEST_DB=1 para probar contra la BD de producción)\nRESULTADO: OK\n"; exit(0); }
require __DIR__ . '/../api/lib_documentos.php';
require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i=0; $dbConnect===false && $i<4; $i++){ usleep(300000); $dbConnect=sqlsrv_connect($servidor,$infoconn); }
if ($dbConnect===false){ echo "FALLO: sin conexión\nRESULTADO: FALLO\n"; exit(1); }
$fail=0; function chk($c,$m){ global $fail; if(!$c){ echo "FALLO: $m\n"; $fail=1; } }

$mio  = '__TESTDOC__'.substr(md5(uniqid('',true)),0,8);
$otro = '__TESTDOC__'.substr(md5(uniqid('',true)),0,8);
$contenido = "%PDF-1.4\x00\x01\x02\xFF\xFEbinario de prueba\x00fin"; // bytes "difíciles" (nulos/altos)

$id1 = doc_guardar($dbConnect, $mio, '900', 'Contrato', 'contrato.pdf', 'application/pdf', $contenido);
$id2 = doc_guardar($dbConnect, $mio, '900', 'RUT', 'rut.png', 'image/png', 'imgbytes');
$id3 = doc_guardar($dbConnect, $otro, '901', 'Contrato', 'otro.pdf', 'application/pdf', 'xx');
chk(is_int($id1) && $id1>0, "doc_guardar devuelve id>0");

$rows = doc_listar($dbConnect, $mio);
chk(count($rows)===2, "lista solo los 2 del aliado (got ".count($rows).")");
chk(isset($rows[0]) && $rows[0]['id'] > $rows[1]['id'], "orden id DESC");
chk(isset($rows[0]) && !array_key_exists('contenido', $rows[0]), "la lista NO trae el binario");
$tipos = array_column($rows,'tipo'); sort($tipos);
chk($tipos===['Contrato','RUT'], "tipos correctos (got ".json_encode($tipos).")");
$porId = []; foreach ($rows as $r) $porId[$r['id']] = $r;
chk($porId[$id1]['tamano']===strlen($contenido), "tamaño en bytes correcto");
chk((bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',$rows[0]['fecha']), "fecha Y-m-d H:i (got ".var_export($rows[0]['fecha'],true).")");

$doc = doc_obtener($dbConnect, $id1, $mio);
chk($doc !== null, "doc_obtener encuentra el propio");
chk($doc && $doc['contenido'] === $contenido, "bytes idénticos round-trip");
chk($doc && $doc['mime']==='application/pdf' && $doc['nombre_archivo']==='contrato.pdf', "mime/nombre correctos");
chk(doc_obtener($dbConnect, $id1, $otro) === null, "no se obtiene doc de otro aliado (propiedad)");

sqlsrv_query($dbConnect, "DELETE FROM documentos_aliados_aka WHERE id IN (?,?,?)", [$id1,$id2,$id3]);
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (round-trip binario + filtro + propiedad, limpieza hecha)\n"; exit($fail);
```

- [ ] **Step 2: Ejecutar (debe fallar)**

Run: `COD_TEST_DB=1 php tests/documentos_db_test.php`
Expected: error fatal "Call to undefined function doc_guardar()".

- [ ] **Step 3: Añadir las 3 funciones al final de `api/lib_documentos.php`**

```php

/** Inserta el documento (binario) y devuelve el id. */
function doc_guardar($conn, string $nombre, ?string $nit, string $tipo, string $nombreArchivo, string $mime, string $contenido): int {
    $sql = "SET NOCOUNT ON;
            INSERT INTO documentos_aliados_aka (nombre_cliente, nit, tipo, nombre_archivo, mime, tamano, contenido)
            OUTPUT INSERTED.id AS id
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $params = [
        $nombre, $nit, $tipo, $nombreArchivo, $mime, strlen($contenido),
        [$contenido, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY), SQLSRV_SQLTYPE_VARBINARY('max')],
    ];
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) throw new RuntimeException('INSERT documento falló: '.print_r(sqlsrv_errors(), true));
    $id = 0;
    do { $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC); if ($row && isset($row['id'])) { $id=(int)$row['id']; break; } } while (sqlsrv_next_result($stmt));
    if ($id === 0) throw new RuntimeException('No se recuperó el id del documento.');
    return $id;
}

/** Lista los documentos de un aliado (SIN el binario), del más reciente al más antiguo. */
function doc_listar($conn, string $nombre): array {
    $sql = "SELECT id, tipo, nombre_archivo, tamano, nit, nombre_cliente, fecha
            FROM documentos_aliados_aka WITH (NOLOCK)
            WHERE nombre_cliente = ?
            ORDER BY id DESC";
    $stmt = sqlsrv_query($conn, $sql, [$nombre]);
    if ($stmt === false) throw new RuntimeException('Listado documentos falló: '.print_r(sqlsrv_errors(), true));
    $rows = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $f = $r['fecha'];
        $rows[] = [
            'id'             => (int)$r['id'],
            'tipo'           => trim((string)$r['tipo']),
            'nombre_archivo' => (string)$r['nombre_archivo'],
            'tamano'         => (int)$r['tamano'],
            'nit'            => $r['nit'] !== null ? trim((string)$r['nit']) : null,
            'nombre'         => trim((string)$r['nombre_cliente']),
            'fecha'          => ($f instanceof DateTime) ? $f->format('Y-m-d H:i') : (string)$f,
        ];
    }
    return $rows;
}

/** Devuelve nombre/mime/contenido de un doc SOLO si pertenece al aliado; null si no. */
function doc_obtener($conn, int $id, string $nombre): ?array {
    $sql = "SELECT nombre_archivo, mime, contenido FROM documentos_aliados_aka WITH (NOLOCK)
            WHERE id = ? AND nombre_cliente = ?";
    $stmt = sqlsrv_query($conn, $sql, [$id, $nombre]);
    if ($stmt === false) throw new RuntimeException('Obtener documento falló: '.print_r(sqlsrv_errors(), true));
    if (!sqlsrv_fetch($stmt)) return null;
    return [
        'nombre_archivo' => (string)sqlsrv_get_field($stmt, 0, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR)),
        'mime'           => (string)sqlsrv_get_field($stmt, 1, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR)),
        'contenido'      => (string)sqlsrv_get_field($stmt, 2, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY)),
    ];
}
```

- [ ] **Step 4: Ejecutar (debe pasar)**

Run: `COD_TEST_DB=1 php tests/documentos_db_test.php`
Expected: `RESULTADO: OK (round-trip binario + filtro + propiedad, limpieza hecha)`
Verificar SKIP sin la variable: `php tests/documentos_db_test.php` → `SKIP ... RESULTADO: OK`.

- [ ] **Step 5: Commit**

```bash
git add api/lib_documentos.php tests/documentos_db_test.php
git commit -m "feat(documentacion): guardar/listar/obtener documentos (VARBINARY, TDD)"
```

---

### Task 4: Endpoints subir / listar / descargar

**Files:**
- Create: `api/documentos_subir.php`, `api/documentos_listar.php`, `api/documentos_descargar.php`

**Interfaces:**
- Consumes: `doc_validar`, `doc_mime_por_ext`, `doc_guardar`, `doc_listar`, `doc_obtener` (lib); conexión `$dbConnect/$servidor/$infoconn`.
- Produces: subir → `{ok,id}`; listar → `{ok,documentos:[...]}`; descargar → stream binario (o 404).

No TDD (orquestan upload+BD+stream); cubiertos por los tests del lib + E2E.

- [ ] **Step 1: Crear `api/documentos_subir.php`**

```php
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/lib_documentos.php';

if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }
$nombre = (string)$_SESSION['usuario'];
$nit = trim((string)($_SESSION['nit'] ?? '')); $nit = $nit === '' ? null : $nit;
$tipo = isset($_POST['tipo']) ? (string)$_POST['tipo'] : null;
$archivo = $_FILES['documento'] ?? null;

$val = doc_validar($tipo, $archivo);
if (!$val['ok']) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$val['error']]); exit; }
if (!is_uploaded_file($archivo['tmp_name'])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Archivo inválido.']); exit; }
$contenido = file_get_contents($archivo['tmp_name']);
if ($contenido === false) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No se pudo leer el archivo.']); exit; }
$ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
$mime = doc_mime_por_ext($ext);

require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i=0; $dbConnect===false && $i<4; $i++){ usleep(300000); $dbConnect=sqlsrv_connect($servidor,$infoconn); }
if ($dbConnect===false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Conexión DB fallida']); exit; }

try {
    $id = doc_guardar($dbConnect, $nombre, $nit, $tipo, (string)$archivo['name'], $mime, $contenido);
} catch (Throwable $e) {
    error_log('documentos subir: '.$e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'No se pudo guardar el documento.']); exit;
}
echo json_encode(['ok'=>true, 'id'=>$id]);
```

- [ ] **Step 2: Crear `api/documentos_listar.php`**

```php
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/lib_documentos.php';

if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }
$nombre = (string)$_SESSION['usuario'];

require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i=0; $dbConnect===false && $i<4; $i++){ usleep(300000); $dbConnect=sqlsrv_connect($servidor,$infoconn); }
if ($dbConnect===false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Conexión DB fallida']); exit; }

try { $documentos = doc_listar($dbConnect, $nombre); }
catch (Throwable $e) { error_log('documentos listar: '.$e->getMessage()); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'No se pudieron cargar los documentos']); exit; }
echo json_encode(['ok'=>true, 'documentos'=>$documentos]);
```

- [ ] **Step 3: Crear `api/documentos_descargar.php`**

```php
<?php
session_start();
require __DIR__ . '/lib_documentos.php';

if (!isset($_SESSION['usuario'])) { http_response_code(401); echo 'No autenticado'; exit; }
$nombre = (string)$_SESSION['usuario'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i=0; $dbConnect===false && $i<4; $i++){ usleep(300000); $dbConnect=sqlsrv_connect($servidor,$infoconn); }
if ($dbConnect===false) { http_response_code(500); echo 'Conexión DB fallida'; exit; }

try { $doc = doc_obtener($dbConnect, $id, $nombre); }
catch (Throwable $e) { error_log('documentos descargar: '.$e->getMessage()); http_response_code(500); echo 'Error'; exit; }
if ($doc === null) { http_response_code(404); echo 'Documento no encontrado'; exit; }

$fn = preg_replace('/[\r\n"]/', '', $doc['nombre_archivo']); // sanea el header
header('Content-Type: '.$doc['mime']);
header('Content-Disposition: attachment; filename="'.$fn.'"');
header('Content-Length: '.strlen($doc['contenido']));
echo $doc['contenido'];
```

- [ ] **Step 4: Verificar lint**

Run: `php -l api/documentos_subir.php && php -l api/documentos_listar.php && php -l api/documentos_descargar.php`
Expected: `No syntax errors detected` en los 3.

- [ ] **Step 5: Commit**

```bash
git add api/documentos_subir.php api/documentos_listar.php api/documentos_descargar.php
git commit -m "feat(documentacion): endpoints subir/listar/descargar"
```

---

### Task 5: Frontend — página Documentación en `dashboard.php`

**Files:**
- Modify: `dashboard.php` (página `#page-documentos` ~877-896; modal nuevo cerca de `#modalCodificacion` ~922; JS; wire en `showPage` ~1143)

**Interfaces:**
- Consumes: endpoints `api/documentos_{subir,listar,descargar}.php`; `Swal`.
- Produces: modal `#modalDocumento`, funciones `docAbrirModal/docSubir/cargarDocumentos`.

- [ ] **Step 1: Reemplazar el interior de `#page-documentos` (quitar chips + tabla real)**

Reemplazar el bloque desde `<div style="display:flex;justify-content:space-between;...">` (que contiene los 4 `filter-chip` y el botón, ~878) hasta el `</div>` que cierra la `.card` con la tabla demo (~896) por:

```html
                <div style="display:flex;justify-content:flex-end;align-items:center;margin-bottom:20px;">
                    <button class="btn btn-primary" onclick="docAbrirModal()">+ SUBIR DOCUMENTO</button>
                </div>
                <div class="card">
                    <table>
                        <thead><tr><th>Tipo</th><th>Documento</th><th>Fecha</th><th>NIT</th><th>Nombre del tercero</th><th></th></tr></thead>
                        <tbody id="docBody">
                            <tr><td colspan="6" style="text-align:center;color:var(--text-light);padding:16px;">Cargando&hellip;</td></tr>
                        </tbody>
                    </table>
                </div>
```
(No tocar el `<div class="page" id="page-documentos">` que envuelve ni su `</div>` de cierre.)

- [ ] **Step 2: Verificar lint**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Añadir el modal `#modalDocumento`**

Justo ANTES de la línea `<div class="modal-overlay" id="modalCodificacion">` (~922), insertar:

```html
<div class="modal-overlay" id="modalDocumento">
    <div class="modal">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3>SUBIR DOCUMENTO</h3>
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('modalDocumento').classList.remove('active')">&#10005; Cerrar</button>
        </div>
        <div class="form-group" style="margin-bottom:16px;">
            <label>Tipo de documento</label>
            <select id="docTipo">
                <option value="">Selecciona&hellip;</option>
                <option value="Contrato">Contrato</option>
                <option value="RUT">RUT</option>
                <option value="Cámara de Comercio">Cámara de Comercio</option>
            </select>
        </div>
        <div class="upload-excel" id="docDrop">
            <div class="icon" style="color:var(--primary);"><i class="fa-solid fa-upload"></i></div>
            <p><strong>Arrastra el archivo aqu&iacute;</strong></p>
            <p>o haz clic para seleccionar</p>
            <p style="margin-top:8px;font-size:11px;color:var(--text-light);">PDF o imagen &mdash; m&aacute;x 10 MB</p>
        </div>
        <input type="file" id="docFile" accept=".pdf,.jpg,.jpeg,.png" style="display:none;">
        <div id="docFileName" style="margin-top:12px;font-size:13px;color:var(--primary);"></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
            <button class="btn btn-secondary" onclick="document.getElementById('modalDocumento').classList.remove('active')">Cancelar</button>
            <button class="btn btn-primary" id="docSubirBtn" onclick="docSubir()" disabled>Subir</button>
        </div>
    </div>
</div>
```
(El `<option>` de Cámara debe quedar en UTF-8.)

- [ ] **Step 4: Cablear la carga lazy en `showPage`**

En `showPage`, justo antes de `updateAgentContext(pageId);` (~1143), añadir:

```javascript
        if (pageId === 'documentos' && typeof cargarDocumentos === 'function') cargarDocumentos();
```

- [ ] **Step 5: Añadir las funciones JS**

Dentro del `<script>` principal (cerca del bloque de Mis solicitudes / showCodTab), añadir:

```javascript
    // ===== Documentación =====
    let docArchivo = null;
    (function initDoc(){
        const drop = document.getElementById('docDrop');
        const input = document.getElementById('docFile');
        if (!drop || !input) return;
        drop.addEventListener('click', () => input.click());
        input.addEventListener('change', () => { docSet(input.files[0]); input.value=''; });
        ['dragover','dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor='var(--accent)'; }));
        ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor=''; }));
        drop.addEventListener('drop', e => { if (e.dataTransfer.files[0]) docSet(e.dataTransfer.files[0]); });
        const ov = document.getElementById('modalDocumento');
        if (ov) ov.addEventListener('click', function(e){ if (e.target === this) this.classList.remove('active'); });
        const sel = document.getElementById('docTipo');
        if (sel) sel.addEventListener('change', docToggleBtn);
    })();
    function docEsc(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function docSet(f){
        if (!f) return;
        const ext = f.name.split('.').pop().toLowerCase();
        if (!['pdf','jpg','jpeg','png'].includes(ext)) { Swal.fire('Archivo no válido', 'Solo PDF o imágenes (.pdf, .jpg, .jpeg, .png).', 'warning'); return; }
        if (f.size > 10*1024*1024) { Swal.fire('Archivo muy grande', f.name+' supera el máximo de 10 MB.', 'warning'); return; }
        docArchivo = f;
        document.getElementById('docFileName').innerHTML = '<i class="fa-solid fa-file" style="color:var(--primary);"></i> ' + docEsc(f.name);
        docToggleBtn();
    }
    function docToggleBtn(){
        const tipo = document.getElementById('docTipo').value;
        document.getElementById('docSubirBtn').disabled = !(tipo && docArchivo);
    }
    function docAbrirModal(){
        docArchivo = null;
        document.getElementById('docTipo').value = '';
        document.getElementById('docFileName').innerHTML = '';
        document.getElementById('docSubirBtn').disabled = true;
        document.getElementById('modalDocumento').classList.add('active');
    }
    function docSubir(){
        const tipo = document.getElementById('docTipo').value;
        if (!tipo || !docArchivo) return;
        const fd = new FormData();
        fd.append('tipo', tipo);
        fd.append('documento', docArchivo);
        Swal.fire({ title:'Subiendo…', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
        fetch('api/documentos_subir.php', { method:'POST', body:fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) { document.getElementById('modalDocumento').classList.remove('active'); Swal.fire('¡Subido!', 'El documento se guardó correctamente.', 'success'); cargarDocumentos(); }
                else { Swal.fire('Error', d.error || 'No se pudo subir.', 'error'); }
            })
            .catch(() => Swal.fire('Error', 'Falló la conexión con el servidor.', 'error'));
    }
    function docFecha(s){
        if (!s) return '—';
        const parts = String(s).split(' ');
        const p = parts[0].split('-');
        return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` + (parts[1] ? ` ${parts[1]}` : '') : docEsc(s);
    }
    function cargarDocumentos(){
        const body = document.getElementById('docBody');
        if (!body) return;
        body.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-light);padding:16px;">Cargando…</td></tr>';
        fetch('api/documentos_listar.php')
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { body.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--danger);padding:16px;">No se pudieron cargar los documentos.</td></tr>'; return; }
                if (!d.documentos.length) { body.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-light);padding:16px;">No tienes documentos cargados.</td></tr>'; return; }
                body.innerHTML = d.documentos.map(x =>
                    `<tr><td>${docEsc(x.tipo)}</td><td>${docEsc(x.nombre_archivo)}</td><td>${docFecha(x.fecha)}</td><td>${x.nit ? docEsc(x.nit) : '—'}</td><td>${docEsc(x.nombre)}</td><td><a class="btn btn-secondary btn-sm" href="api/documentos_descargar.php?id=${parseInt(x.id,10)}" style="text-decoration:none;">Descargar</a></td></tr>`
                ).join('');
            })
            .catch(() => { body.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--danger);padding:16px;">Error de conexión.</td></tr>'; });
    }
```

- [ ] **Step 6: Verificar lint y commit**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected`

```bash
git add dashboard.php
git commit -m "feat(documentacion): pagina con modal de subida + listado + descarga"
```

---

### Task 6: Integración + E2E

- [ ] **Step 1: Suite + no-regresión + lint**

Run:
```bash
php tests/documentos_validar_test.php
COD_TEST_DB=1 php tests/documentos_db_test.php
php -l dashboard.php && php -l api/lib_documentos.php && php -l api/documentos_subir.php && php -l api/documentos_listar.php && php -l api/documentos_descargar.php
```
Expected: cada test `RESULTADO: OK`; lints limpios.

- [ ] **Step 2: E2E navegador (Rafael)**

En `localhost/plataforma_20` → Documentación:
1. Confirmar que los 4 chips (Todos/Contratos/Certificados/RUT) ya no están; solo el botón "Subir documento".
2. Clic "Subir documento" → modal → elegir tipo (Contrato) → arrastrar/seleccionar un PDF → "Subir" → éxito → aparece en la tabla (Tipo/Documento/Fecha/NIT/Nombre/Descargar), más reciente arriba.
3. Repetir con una imagen (jpg/png) y otro tipo.
4. Clic "Descargar" → el archivo baja y abre correctamente (bytes intactos).
5. Probar archivo no permitido (.exe) o sin tipo → avisos.
6. Aliado sin documentos → "No tienes documentos cargados."

- [ ] **Step 3: (Si E2E OK) actualizar changelog en memoria**

Registrar la funcionalidad + el cambio de BD (tabla nueva `documentos_aliados_aka`).

## Notas de despliegue (post-merge)

- Re-sync a `plataforma_20_produccion`: copiar `api/lib_documentos.php`, `api/documentos_subir.php`, `api/documentos_listar.php`, `api/documentos_descargar.php`, `dashboard.php`.
- La tabla `documentos_aliados_aka` ya quedó creada en el RDS (Task 1) — no repetir.
