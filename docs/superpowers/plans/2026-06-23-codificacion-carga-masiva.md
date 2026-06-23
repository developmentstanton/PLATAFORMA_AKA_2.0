# Codificación — Carga Masiva real: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el aliado suba uno o varios Excel en la card "CARGA MASIVA DE CODIFICACIÓN", pulse Enviar, y el sistema registre el envío en `consecutivo_planillas_aka` (con nueva columna `nit`) y mande un correo HTML moderno con los archivos adjuntos.

**Architecture:** Frontend en la SPA `dashboard.php` (drag/drop + `fetch` FormData + SweetAlert) → endpoint `api/codificacion_cargar.php` que orquesta validación, INSERT y envío. La lógica pura (validación, HTML del correo, registro en BD) vive en `api/lib_codificacion.php` para poder probarse aislada. Correo vía PHPMailer (Gmail SMTP), credenciales en `conexion/config_mail.php` (fuera de git).

**Tech Stack:** PHP 8 + `sqlsrv` (SQL Server RDS), PHPMailer (copiado, sin composer), JS vanilla + SweetAlert2 (ya cargado en `dashboard.php`).

## Global Constraints

- **SQL siempre parametrizado** (`sqlsrv_query($conn,$sql,[params])`). Nunca interpolar `$_SESSION`/`$_GET`/`$_POST` en SQL.
- **Tabla destino:** `INTEGRACION.dbo.consecutivo_planillas_aka` — columnas: `consecutivo` int IDENTITY (PK), `nombre_cliente` varchar(60) NOT NULL, `fecha` date NOT NULL, `estado` varchar(20) NOT NULL DEFAULT 'Estudio', **`nit` varchar(20) NULL (se agrega en Task 1)**. INSERT con **lista de columnas explícita**; `consecutivo` y `estado` se autocompletan.
- **NIT:** de `$_SESSION['nit']`; si vacío → insertar `NULL`. **Aliado:** `$_SESSION['usuario']` = `nombre_cliente`.
- **Destinatarios:** TO = `usuarios_portal_aka.correo` del aliado; BCC = `jpmartin@stanton.co`, `masterdata2@stanton.co`, `directorplataformaaka@cauchosol.co`.
- **SMTP:** `smtp.gmail.com:587` STARTTLS, usuario `plataforma@tiendasaka.co`. **La clave NO va al repo.**
- **Límites de archivos:** máx **10 MB** por archivo, máx **10** archivos por envío; solo `.xlsx`/`.xls`. Archivos **solo se adjuntan** (no se guardan en disco).
- **Paleta correo:** morado portal `#4A4782`; logo `http://bi.stanton.com.co:81/portal/img/logo_aka2_corto.png`.
- **Reintento conexión RDS:** 4× con `usleep(300000)` (el RDS rechaza conexiones rápidas intermitentemente).
- `php -l` limpio en cada archivo PHP tocado.

## File Structure

- **Create** `api/lib_codificacion.php` — helpers puros: `cod_validar_archivos`, `cod_correo_html`, `cod_registrar_envio`.
- **Create** `api/codificacion_cargar.php` — endpoint (orquesta lib + conexión + PHPMailer; devuelve JSON).
- **Create** `conexion/config_mail.php` — constantes SMTP reales (gitignored).
- **Create** `conexion/config_mail.example.php` — plantilla sin clave (al repo).
- **Create** `PHPMailer/src/Exception.php`, `PHPMailer/src/PHPMailer.php`, `PHPMailer/src/SMTP.php` — copiados de `C:\xampp\htdocs\nexus_v4\PHPMailer\src\`.
- **Create** `tests/codificacion_validar_test.php`, `tests/codificacion_correo_test.php`, `tests/codificacion_registro_test.php`.
- **Modify** `dashboard.php` — card CARGA MASIVA (HTML) + funciones JS.
- **Modify** `.gitignore` — ignorar `conexion/config_mail.php`.
- **DB (producción, no git):** `ALTER TABLE ... ADD nit`.

---

### Task 1: Agregar columna `nit` a la tabla (cambio de BD)

**Files:**
- Create (temporal): `scratchpad/alter_nit.php` (se borra al final)

**Interfaces:**
- Produces: columna `nit VARCHAR(20) NULL` en `INTEGRACION.dbo.consecutivo_planillas_aka`.

Cambio en producción (RDS), no viaja por git. No hay TDD; es setup verificable.

- [ ] **Step 1: Escribir el script de ALTER + verificación**

```php
<?php // scratchpad/alter_nit.php
require 'C:/xampp/htdocs/plataforma_20/conexion/conexion_integracion.php';
if ($dbConnect === false) { echo "NO CONN\n"; exit(1); }

// ¿Ya existe la columna? (idempotente)
$chk = sqlsrv_query($dbConnect, "SELECT COL_LENGTH('dbo.consecutivo_planillas_aka','nit') AS l");
$exists = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC)['l'];
if ($exists !== null) { echo "Columna 'nit' YA existe (len=$exists). Nada que hacer.\n"; exit(0); }

$r = sqlsrv_query($dbConnect, "ALTER TABLE INTEGRACION.dbo.consecutivo_planillas_aka ADD nit VARCHAR(20) NULL");
if ($r === false) { echo "ALTER FALLÓ:\n"; print_r(sqlsrv_errors()); exit(1); }
echo "ALTER OK.\n";

// Verificar
$v = sqlsrv_query($dbConnect, "SELECT COL_LENGTH('dbo.consecutivo_planillas_aka','nit') AS l");
echo "nit len = ".sqlsrv_fetch_array($v, SQLSRV_FETCH_ASSOC)['l']."\n";
```

- [ ] **Step 2: Ejecutar el script**

Run: `php "C:\Users\USUARIO\AppData\Local\Temp\claude\C--xampp-htdocs\cccddd52-3428-486e-8782-3766763c9761\scratchpad\alter_nit.php"`
Expected: `ALTER OK.` y `nit len = 20` (o "YA existe" si se reejecuta).

- [ ] **Step 3: Borrar el script temporal**

```bash
rm -f scratchpad/alter_nit.php
```

No hay commit (cambio de BD, no de código).

---

### Task 2: PHPMailer + config de correo

**Files:**
- Create: `PHPMailer/src/Exception.php`, `PHPMailer/src/PHPMailer.php`, `PHPMailer/src/SMTP.php`
- Create: `conexion/config_mail.php`, `conexion/config_mail.example.php`
- Modify: `.gitignore`

**Interfaces:**
- Produces: clase `PHPMailer\PHPMailer\PHPMailer` disponible vía `require`; constantes `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASS`, `MAIL_FROM_NAME`, `MAIL_BCC` (array).

- [ ] **Step 1: Copiar los 3 archivos de PHPMailer**

```bash
mkdir -p PHPMailer/src
cp /c/xampp/htdocs/nexus_v4/PHPMailer/src/Exception.php PHPMailer/src/Exception.php
cp /c/xampp/htdocs/nexus_v4/PHPMailer/src/PHPMailer.php  PHPMailer/src/PHPMailer.php
cp /c/xampp/htdocs/nexus_v4/PHPMailer/src/SMTP.php       PHPMailer/src/SMTP.php
```

- [ ] **Step 2: Crear `conexion/config_mail.php` (con la clave real)**

```php
<?php
// Credenciales SMTP del portal AKA — NO subir a git (ver .gitignore).
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USER', 'plataforma@tiendasaka.co');
define('MAIL_PASS', 'vpwp rmcq suxc sfpn'); // clave de aplicación de Gmail
define('MAIL_FROM_NAME', 'PLATAFORMA AKA');
define('MAIL_BCC', ['jpmartin@stanton.co', 'masterdata2@stanton.co', 'directorplataformaaka@cauchosol.co']);
```

- [ ] **Step 3: Crear `conexion/config_mail.example.php` (sin la clave, al repo)**

```php
<?php
// Plantilla de credenciales SMTP. Copiar a config_mail.php y rellenar MAIL_PASS.
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USER', 'plataforma@tiendasaka.co');
define('MAIL_PASS', 'REEMPLAZAR_CON_CLAVE_DE_APLICACION');
define('MAIL_FROM_NAME', 'PLATAFORMA AKA');
define('MAIL_BCC', ['jpmartin@stanton.co', 'masterdata2@stanton.co', 'directorplataformaaka@cauchosol.co']);
```

- [ ] **Step 4: Ignorar `config_mail.php` en git**

Añadir bajo el bloque de credenciales de `.gitignore` (tras `conexion/conexion_stanton.php`):

```
conexion/config_mail.php
```

- [ ] **Step 5: Verificar que PHPMailer carga y la clave NO está trackeada**

Run:
```bash
php -r "require 'PHPMailer/src/Exception.php'; require 'PHPMailer/src/PHPMailer.php'; require 'PHPMailer/src/SMTP.php'; require 'conexion/config_mail.php'; echo class_exists('PHPMailer\\PHPMailer\\PHPMailer')?'PHPMailer OK ':'NO'; echo MAIL_USER;"
git status --short conexion/
```
Expected: `PHPMailer OK plataforma@tiendasaka.co`; en `git status` aparece `conexion/config_mail.example.php` pero **NO** `conexion/config_mail.php`.

- [ ] **Step 6: Commit (sin la clave real)**

```bash
git add PHPMailer/src conexion/config_mail.example.php .gitignore
git commit -m "chore(codificacion): añadir PHPMailer + config SMTP (clave fuera de git)"
```

---

### Task 3: `lib_codificacion.php` — validación de archivos (TDD)

**Files:**
- Create: `api/lib_codificacion.php`
- Test: `tests/codificacion_validar_test.php`

**Interfaces:**
- Produces: `cod_validar_archivos($adjunto): array` → `['ok'=>bool, 'error'=>?string, 'archivos'=>[['name'=>string,'tmp_name'=>string,'error'=>int,'size'=>int], ...]]`. Recibe el sub-array `$_FILES['adjunto']` (name/tmp_name/error/size pueden ser escalares o arrays). Constantes `COD_MAX_ARCHIVOS=10`, `COD_MAX_BYTES=10485760`, `COD_EXT_OK=['xlsx','xls']`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php // tests/codificacion_validar_test.php
//   php tests/codificacion_validar_test.php
require __DIR__ . '/../api/lib_codificacion.php';
$fail = 0;
function chk($cond,$msg){ global $fail; if(!$cond){ echo "FALLO: $msg\n"; $fail=1; } }

// Helper para construir un $_FILES['adjunto'] con N archivos
function mk($items){ // $items = [['name'=>,'size'=>,'error'=>], ...]
  $o=['name'=>[],'tmp_name'=>[],'error'=>[],'size'=>[]];
  foreach($items as $it){ $o['name'][]=$it['name']; $o['tmp_name'][]='/tmp/'.$it['name'];
    $o['error'][]=$it['error']??UPLOAD_ERR_OK; $o['size'][]=$it['size']??1000; }
  return $o;
}

// 1) sin archivos -> ok=false
$r = cod_validar_archivos(null);            chk($r['ok']===false, "null debe dar ok=false");
$r = cod_validar_archivos(mk([]));          chk($r['ok']===false, "lista vacía debe dar ok=false");

// 2) un xlsx válido -> ok=true, 1 archivo
$r = cod_validar_archivos(mk([['name'=>'Plantilla270.xlsx']]));
chk($r['ok']===true, "xlsx válido debe pasar");
chk(count($r['archivos'])===1, "debe devolver 1 archivo");

// 3) varios válidos
$r = cod_validar_archivos(mk([['name'=>'a.xlsx'],['name'=>'b.xls']]));
chk($r['ok']===true && count($r['archivos'])===2, "2 válidos deben pasar");

// 4) extensión inválida
$r = cod_validar_archivos(mk([['name'=>'virus.exe']]));
chk($r['ok']===false, "exe debe rechazarse");

// 5) demasiado grande (>10MB)
$r = cod_validar_archivos(mk([['name'=>'grande.xlsx','size'=>11*1024*1024]]));
chk($r['ok']===false, ">10MB debe rechazarse");

// 6) demasiados archivos (>10)
$many = []; for($i=0;$i<11;$i++) $many[]=['name'=>"f$i.xlsx"];
$r = cod_validar_archivos(mk($many));
chk($r['ok']===false, ">10 archivos debe rechazarse");

// 7) error de upload en un archivo
$r = cod_validar_archivos(mk([['name'=>'a.xlsx','error'=>UPLOAD_ERR_PARTIAL]]));
chk($r['ok']===false, "error de upload debe rechazarse");

echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK\n"; exit($fail);
```

- [ ] **Step 2: Ejecutar el test (debe fallar)**

Run: `php tests/codificacion_validar_test.php`
Expected: error fatal "Call to undefined function cod_validar_archivos()" (aún no existe `lib_codificacion.php`).

- [ ] **Step 3: Crear `api/lib_codificacion.php` con la validación**

```php
<?php
/**
 * Helpers de la Carga Masiva de Codificación (lógica pura y testeable).
 * Incluido por api/codificacion_cargar.php y por los tests. NO ejecuta nada al incluirse.
 */

const COD_MAX_ARCHIVOS = 10;
const COD_MAX_BYTES    = 10485760; // 10 MB
const COD_EXT_OK       = ['xlsx', 'xls'];

/**
 * Valida y normaliza el sub-array $_FILES['adjunto'] (escalar o arrays paralelos).
 * @return array ['ok'=>bool,'error'=>?string,'archivos'=>[['name','tmp_name','error','size'], ...]]
 */
function cod_validar_archivos($adjunto): array {
    if (!is_array($adjunto) || !isset($adjunto['name'])) {
        return ['ok'=>false, 'error'=>'No se recibió ningún archivo.', 'archivos'=>[]];
    }
    $names = is_array($adjunto['name'])     ? $adjunto['name']     : [$adjunto['name']];
    $tmps  = is_array($adjunto['tmp_name']) ? $adjunto['tmp_name'] : [$adjunto['tmp_name']];
    $errs  = is_array($adjunto['error'])    ? $adjunto['error']    : [$adjunto['error']];
    $sizes = is_array($adjunto['size'])     ? $adjunto['size']     : [$adjunto['size']];

    $archivos = [];
    for ($i = 0; $i < count($names); $i++) {
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue; // slot vacío
        $archivos[] = ['name'=>$names[$i], 'tmp_name'=>$tmps[$i], 'error'=>$errs[$i], 'size'=>(int)$sizes[$i]];
    }

    if (count($archivos) === 0)               return ['ok'=>false, 'error'=>'Adjunta al menos un archivo .xlsx', 'archivos'=>[]];
    if (count($archivos) > COD_MAX_ARCHIVOS)  return ['ok'=>false, 'error'=>'Máximo '.COD_MAX_ARCHIVOS.' archivos por envío.', 'archivos'=>[]];

    foreach ($archivos as $a) {
        if ($a['error'] !== UPLOAD_ERR_OK)
            return ['ok'=>false, 'error'=>'Error al subir "'.$a['name'].'".', 'archivos'=>[]];
        $ext = strtolower(pathinfo($a['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, COD_EXT_OK, true))
            return ['ok'=>false, 'error'=>'Solo se permiten archivos Excel (.xlsx, .xls). "'.$a['name'].'" no es válido.', 'archivos'=>[]];
        if ($a['size'] > COD_MAX_BYTES)
            return ['ok'=>false, 'error'=>'"'.$a['name'].'" supera el máximo de 10 MB.', 'archivos'=>[]];
    }
    return ['ok'=>true, 'error'=>null, 'archivos'=>$archivos];
}
```

- [ ] **Step 4: Ejecutar el test (debe pasar)**

Run: `php tests/codificacion_validar_test.php`
Expected: `RESULTADO: OK`

- [ ] **Step 5: Commit**

```bash
git add api/lib_codificacion.php tests/codificacion_validar_test.php
git commit -m "feat(codificacion): validación de archivos en lib_codificacion (TDD)"
```

---

### Task 4: `lib_codificacion.php` — HTML del correo (TDD)

**Files:**
- Modify: `api/lib_codificacion.php` (añadir función)
- Test: `tests/codificacion_correo_test.php`

**Interfaces:**
- Consumes: nada de tasks previas (función independiente en el mismo lib).
- Produces: `cod_correo_html(array $d): string`. `$d` = `['consecutivo'=>int, 'nombre'=>string, 'nit'=>?string, 'correo'=>string, 'fecha'=>string, 'archivos'=>string[]]`. Devuelve HTML con estilos inline; escapa todo con `htmlspecialchars`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php // tests/codificacion_correo_test.php
//   php tests/codificacion_correo_test.php
require __DIR__ . '/../api/lib_codificacion.php';
$fail = 0;
function chk($c,$m){ global $fail; if(!$c){ echo "FALLO: $m\n"; $fail=1; } }

$html = cod_correo_html([
  'consecutivo'=>305, 'nombre'=>'BELTRANY SAS', 'nit'=>'900123456',
  'correo'=>'aliado@beltrany.co', 'fecha'=>'23/06/2026',
  'archivos'=>['Plantilla270.xlsx','aviso.xlsx']
]);
chk(strpos($html,'#305')!==false, "debe incluir el consecutivo #305");
chk(strpos($html,'BELTRANY SAS')!==false, "debe incluir el nombre del aliado");
chk(strpos($html,'900123456')!==false, "debe incluir el NIT");
chk(strpos($html,'Plantilla270.xlsx')!==false && strpos($html,'aviso.xlsx')!==false, "debe listar los archivos");
chk(strpos($html,'#4A4782')!==false, "debe usar la paleta del portal");
chk(strpos($html,'logo_aka2_corto.png')!==false, "debe incluir el logo AKA");

// Escapado XSS: un nombre con HTML no debe inyectarse crudo
$h2 = cod_correo_html(['consecutivo'=>1,'nombre'=>'<script>x</script>','nit'=>null,'correo'=>'','fecha'=>'','archivos'=>[]]);
chk(strpos($h2,'<script>x</script>')===false, "el nombre debe ir escapado");
chk(strpos($h2,'&lt;script&gt;')!==false, "el nombre escapado debe aparecer");

echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK\n"; exit($fail);
```

- [ ] **Step 2: Ejecutar el test (debe fallar)**

Run: `php tests/codificacion_correo_test.php`
Expected: error fatal "Call to undefined function cod_correo_html()".

- [ ] **Step 3: Añadir `cod_correo_html` al final de `api/lib_codificacion.php`**

```php

/**
 * Construye el HTML del correo de confirmación (estilos inline, paleta del portal).
 */
function cod_correo_html(array $d): string {
    $primary = '#4A4782';
    $nombre = htmlspecialchars((string)($d['nombre'] ?? ''));
    $nit    = htmlspecialchars((string)($d['nit'] ?? '') !== '' ? (string)$d['nit'] : '—');
    $correo = htmlspecialchars((string)($d['correo'] ?? ''));
    $fecha  = htmlspecialchars((string)($d['fecha'] ?? ''));
    $cons   = (int)($d['consecutivo'] ?? 0);
    $logo   = 'http://bi.stanton.com.co:81/portal/img/logo_aka2_corto.png';

    $items = '';
    foreach (($d['archivos'] ?? []) as $a) {
        $items .= "<li style='margin:2px 0;color:#444;'>".htmlspecialchars((string)$a)."</li>";
    }
    if ($items === '') $items = "<li style='color:#999;'>(sin archivos)</li>";

    return <<<HTML
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:20px;font-family:Helvetica,Arial,sans-serif;background:#f4f4f7;'>
  <div style='max-width:600px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #eaeaea;'>
    <div style='background:{$primary};padding:24px;text-align:center;'>
      <img src='{$logo}' alt='AKA' style='max-height:46px;'>
    </div>
    <div style='padding:32px 28px;'>
      <h2 style='color:{$primary};margin:0 0 4px;font-size:20px;'>Estimado aliado, {$nombre}</h2>
      <p style='color:#555;font-size:15px;line-height:1.5;margin:12px 0;'>
        Hemos recibido el cargue de tu portafolio. Será validado por nuestro equipo de
        curaduría y te daremos respuesta próximamente.
      </p>
      <h3 style='color:{$primary};font-size:15px;margin:24px 0 10px;padding-bottom:6px;border-bottom:2px solid #e8e6f0;'>Detalle del envío</h3>
      <table width='100%' cellpadding='0' cellspacing='0' style='font-size:14px;'>
        <tr><td style='padding:5px 0;color:#888;width:130px;'>Consecutivo:</td><td style='padding:5px 0;color:#333;font-weight:bold;'>#{$cons}</td></tr>
        <tr><td style='padding:5px 0;color:#888;'>Aliado:</td><td style='padding:5px 0;color:#333;'>{$nombre}</td></tr>
        <tr><td style='padding:5px 0;color:#888;'>NIT:</td><td style='padding:5px 0;color:#333;'>{$nit}</td></tr>
        <tr><td style='padding:5px 0;color:#888;'>Correo:</td><td style='padding:5px 0;color:#333;'>{$correo}</td></tr>
        <tr><td style='padding:5px 0;color:#888;'>Fecha:</td><td style='padding:5px 0;color:#333;'>{$fecha}</td></tr>
        <tr><td style='padding:5px 0;color:#888;vertical-align:top;'>Archivos:</td><td style='padding:5px 0;color:#333;'><ul style='margin:0;padding-left:18px;'>{$items}</ul></td></tr>
      </table>
      <p style='color:#555;font-size:14px;margin:24px 0 0;'>Cordialmente,<br><strong style='color:{$primary};'>Equipo Tiendas AKA</strong></p>
    </div>
    <div style='background:#f1f0f6;padding:16px;text-align:center;'>
      <p style='margin:0;color:#999;font-size:12px;'>Mensaje automático · por favor no respondas a este correo.</p>
    </div>
  </div>
</body></html>
HTML;
}
```

- [ ] **Step 4: Ejecutar el test (debe pasar)**

Run: `php tests/codificacion_correo_test.php`
Expected: `RESULTADO: OK`

- [ ] **Step 5: Commit**

```bash
git add api/lib_codificacion.php tests/codificacion_correo_test.php
git commit -m "feat(codificacion): HTML moderno del correo en lib_codificacion (TDD)"
```

---

### Task 5: `lib_codificacion.php` — registro en BD (TDD contra BD, con limpieza)

**Files:**
- Modify: `api/lib_codificacion.php` (añadir función)
- Test: `tests/codificacion_registro_test.php`

**Interfaces:**
- Consumes: conexión `$dbConnect` de `conexion/conexion_integracion.php`.
- Produces: `cod_registrar_envio($conn, string $nombre, string $fecha, ?string $nit): int` — inserta una fila y devuelve el `consecutivo` generado. Lanza `RuntimeException` si el INSERT falla.

> El test inserta en la tabla **de producción** y borra la fila al final. Para evitar contaminar prod en cada corrida, **solo corre con `COD_TEST_DB=1`**; sin esa variable hace SKIP.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php // tests/codificacion_registro_test.php
//   COD_TEST_DB=1 php tests/codificacion_registro_test.php
if (getenv('COD_TEST_DB') !== '1') { echo "SKIP (exporta COD_TEST_DB=1 para probar contra la BD de producción)\nRESULTADO: OK\n"; exit(0); }
require __DIR__ . '/../api/lib_codificacion.php';
require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i=0; $dbConnect===false && $i<4; $i++){ usleep(300000); $dbConnect=sqlsrv_connect($servidor,$infoconn); }
if ($dbConnect===false){ echo "FALLO: sin conexión\nRESULTADO: FALLO\n"; exit(1); }
$fail=0; function chk($c,$m){ global $fail; if(!$c){ echo "FALLO: $m\n"; $fail=1; } }

$marca = '__TEST__'.substr(md5(uniqid('',true)),0,8);

// 1) inserta con nit y devuelve consecutivo > 0
$cons = cod_registrar_envio($dbConnect, $marca, date('Y-m-d'), '999TEST');
chk(is_int($cons) && $cons>0, "debe devolver consecutivo > 0 (got ".var_export($cons,true).")");

// 2) la fila quedó con nit correcto y estado por defecto 'Estudio'
$q = sqlsrv_query($dbConnect, "SELECT nombre_cliente, nit, estado FROM consecutivo_planillas_aka WHERE consecutivo = ?", [$cons]);
$row = $q ? sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC) : null;
chk($row && trim($row['nombre_cliente'])===$marca, "nombre_cliente debe coincidir");
chk($row && trim((string)$row['nit'])==='999TEST', "nit debe ser 999TEST");
chk($row && trim($row['estado'])==='Estudio', "estado debe quedar por defecto 'Estudio'");

// 3) nit null se inserta como NULL
$cons2 = cod_registrar_envio($dbConnect, $marca.'B', date('Y-m-d'), null);
$q2 = sqlsrv_query($dbConnect, "SELECT nit FROM consecutivo_planillas_aka WHERE consecutivo = ?", [$cons2]);
$row2 = $q2 ? sqlsrv_fetch_array($q2, SQLSRV_FETCH_ASSOC) : null;
chk($row2 && $row2['nit']===null, "nit null debe quedar NULL");

// LIMPIEZA: borrar las filas de prueba
sqlsrv_query($dbConnect, "DELETE FROM consecutivo_planillas_aka WHERE consecutivo IN (?, ?)", [$cons, $cons2]);

echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (insert+nit+estado, limpieza hecha)\n"; exit($fail);
```

- [ ] **Step 2: Ejecutar el test (debe fallar)**

Run: `COD_TEST_DB=1 php tests/codificacion_registro_test.php`
Expected: error fatal "Call to undefined function cod_registrar_envio()".

- [ ] **Step 3: Añadir `cod_registrar_envio` al final de `api/lib_codificacion.php`**

```php

/**
 * Inserta el envío y devuelve el consecutivo (IDENTITY) generado.
 * consecutivo y estado ('Estudio') se autocompletan; nit puede ser NULL.
 */
function cod_registrar_envio($conn, string $nombre, string $fecha, ?string $nit): int {
    $sql = "SET NOCOUNT ON;
            INSERT INTO consecutivo_planillas_aka (nombre_cliente, fecha, nit)
            OUTPUT INSERTED.consecutivo AS consecutivo
            VALUES (?, ?, ?)";
    $stmt = sqlsrv_query($conn, $sql, [$nombre, $fecha, $nit]);
    if ($stmt === false) {
        throw new RuntimeException('INSERT falló: '.print_r(sqlsrv_errors(), true));
    }
    $cons = 0;
    do {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && isset($row['consecutivo'])) { $cons = (int)$row['consecutivo']; break; }
    } while (sqlsrv_next_result($stmt));
    return $cons;
}
```

- [ ] **Step 4: Ejecutar el test (debe pasar)**

Run: `COD_TEST_DB=1 php tests/codificacion_registro_test.php`
Expected: `RESULTADO: OK (insert+nit+estado, limpieza hecha)`

Verificar también que sin la variable hace SKIP: `php tests/codificacion_registro_test.php` → `SKIP ... RESULTADO: OK`.

- [ ] **Step 5: Commit**

```bash
git add api/lib_codificacion.php tests/codificacion_registro_test.php
git commit -m "feat(codificacion): registro en BD con OUTPUT consecutivo (TDD)"
```

---

### Task 6: Endpoint `api/codificacion_cargar.php`

**Files:**
- Create: `api/codificacion_cargar.php`

**Interfaces:**
- Consumes: `cod_validar_archivos`, `cod_registrar_envio`, `cod_correo_html` (lib); `MAIL_*` (config); `PHPMailer`; `$dbConnect/$servidor/$infoconn` (conexión).
- Produces: respuesta JSON `{status:'success'|'warning'|'error', consecutivo?:int, message:string}` al recibir `POST` con `$_FILES['adjunto']`.

No es TDD (orquesta efectos: upload real, BD, SMTP). Se valida con `php -l` y E2E manual; las partes lógicas ya están cubiertas por Tasks 3-5.

- [ ] **Step 1: Crear `api/codificacion_cargar.php`**

```php
<?php
use PHPMailer\PHPMailer\PHPMailer;

session_start();
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/lib_codificacion.php';

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['status'=>'error', 'message'=>'No autenticado.']); exit;
}
$nombre = (string)$_SESSION['usuario'];
$nit    = trim((string)($_SESSION['nit'] ?? ''));
$nit    = $nit === '' ? null : $nit;

// 1) Validar archivos
$val = cod_validar_archivos($_FILES['adjunto'] ?? null);
if (!$val['ok']) {
    http_response_code(400);
    echo json_encode(['status'=>'error', 'message'=>$val['error']]); exit;
}
foreach ($val['archivos'] as $a) {
    if (!is_uploaded_file($a['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['status'=>'error', 'message'=>'Archivo inválido.']); exit;
    }
}

// 2) Conexión (con reintento RDS)
require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i = 0; $dbConnect === false && $i < 4; $i++) { usleep(300000); $dbConnect = sqlsrv_connect($servidor, $infoconn); }
if ($dbConnect === false) {
    echo json_encode(['status'=>'error', 'message'=>'Conexión DB fallida. Intenta de nuevo.']); exit;
}

// 3) Correo del aliado (parametrizado)
$correo = '';
$st = sqlsrv_query($dbConnect, "SELECT correo FROM usuarios_portal_aka WHERE nombre_usuario = ?", [$nombre]);
if ($st !== false && ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC))) $correo = trim((string)($r['correo'] ?? ''));

// 4) Registrar envío
try {
    $consecutivo = cod_registrar_envio($dbConnect, $nombre, date('Y-m-d'), $nit);
} catch (Throwable $e) {
    error_log('codificacion INSERT: '.$e->getMessage());
    echo json_encode(['status'=>'error', 'message'=>'No se pudo registrar el envío.']); exit;
}

// 5) Enviar correo (el registro ya quedó; si falla el correo es 'warning', no se revierte)
require __DIR__ . '/../conexion/config_mail.php';
require __DIR__ . '/../PHPMailer/src/Exception.php';
require __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer/src/SMTP.php';

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USER;
    $mail->Password   = MAIL_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;

    $mail->setFrom(MAIL_USER, MAIL_FROM_NAME);
    if ($correo !== '') $mail->addAddress($correo);
    foreach (MAIL_BCC as $bcc) $mail->addBCC($bcc);

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'RECIBIDO DE PLANTILLAS PLATAFORMA AKA - ' . $consecutivo;
    $mail->Body = cod_correo_html([
        'consecutivo' => $consecutivo,
        'nombre'      => $nombre,
        'nit'         => $nit,
        'correo'      => $correo,
        'fecha'       => date('d/m/Y'),
        'archivos'    => array_column($val['archivos'], 'name'),
    ]);
    foreach ($val['archivos'] as $a) $mail->addAttachment($a['tmp_name'], $a['name']);

    $mail->send();
    echo json_encode(['status'=>'success', 'consecutivo'=>$consecutivo,
        'message'=>'Recibimos tu portafolio. Consecutivo #'.$consecutivo.'. Te llegará un correo de confirmación.']);
} catch (Throwable $e) {
    error_log('codificacion mail: '.$mail->ErrorInfo);
    echo json_encode(['status'=>'warning', 'consecutivo'=>$consecutivo,
        'message'=>'Tu envío quedó registrado (#'.$consecutivo.') pero el correo no pudo enviarse.']);
}
```

- [ ] **Step 2: Verificar lint**

Run: `php -l api/codificacion_cargar.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add api/codificacion_cargar.php
git commit -m "feat(codificacion): endpoint de carga (valida + registra + correo)"
```

---

### Task 7: Frontend — card CARGA MASIVA en `dashboard.php`

**Files:**
- Modify: `dashboard.php` (HTML de la card ~837-880 + funciones JS en el `<script>`)

**Interfaces:**
- Consumes: endpoint `api/codificacion_cargar.php`; `Swal` (SweetAlert2, ya cargado).
- Produces: UI funcional de carga (drag/drop + selección + lista + botón Enviar).

- [ ] **Step 1: Reemplazar el cuerpo de la card (dropzone real + lista + botón; quitar demo)**

Reemplazar el bloque desde `<div class="upload-excel">` (línea ~843) hasta el `</div>` que cierra el bloque demo "validado" (línea ~879, el `</div>` justo antes de `</div><!-- /codTab-masiva -->`), por:

```html
                    <div class="upload-excel" id="codDrop">
                        <div class="icon" style="color:var(--primary);"><i class="fa-solid fa-file-excel"></i></div>
                        <p><strong>Arrastra tus archivos Excel aqu&iacute;</strong></p>
                        <p>o haz clic para seleccionar</p>
                        <p style="margin-top:8px;font-size:11px;color:var(--text-light);">.xlsx &mdash; Plantilla 270 (puedes subir varios)</p>
                    </div>
                    <input type="file" id="codFile" accept=".xlsx,.xls" multiple style="display:none;">
                    <div class="upload-steps">
                        <div class="upload-step"><div class="upload-step-num">1</div> Selecciona el/los Excel</div>
                        <div class="upload-step"><div class="upload-step-num">2</div> Revisa la lista</div>
                        <div class="upload-step"><div class="upload-step-num">3</div> Env&iacute;a</div>
                    </div>
                    <div id="codFileList" style="margin-top:16px;"></div>
                    <div style="display:flex;justify-content:flex-end;margin-top:16px;">
                        <button class="btn btn-primary" id="codEnviarBtn" onclick="codEnviar()" disabled>Enviar</button>
                    </div>
```

- [ ] **Step 2: Verificar lint tras el cambio de HTML**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Añadir las funciones JS**

Dentro del `<script>` de `dashboard.php` (cerca de `showCodTab`, antes de su cierre `</script>` final), añadir:

```javascript
    // ===== Carga Masiva de Codificación =====
    let codArchivos = [];
    (function initCodCarga(){
        const drop = document.getElementById('codDrop');
        const input = document.getElementById('codFile');
        if (!drop || !input) return;
        drop.addEventListener('click', () => input.click());
        input.addEventListener('change', () => { codAgregar(input.files); input.value=''; });
        ['dragover','dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor='var(--accent)'; }));
        ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor=''; }));
        drop.addEventListener('drop', e => codAgregar(e.dataTransfer.files));
    })();
    function codAgregar(fileList){
        for (const f of fileList){
            const ext = f.name.split('.').pop().toLowerCase();
            if (ext!=='xlsx' && ext!=='xls'){ Swal.fire('Archivo no válido', f.name+' no es un Excel (.xlsx/.xls)', 'warning'); continue; }
            codArchivos.push(f);
        }
        codRender();
    }
    function codQuitar(i){ codArchivos.splice(i,1); codRender(); }
    function codRender(){
        const cont = document.getElementById('codFileList');
        const btn = document.getElementById('codEnviarBtn');
        if (!cont || !btn) return;
        cont.innerHTML = codArchivos.map((f,i) =>
            `<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#f8f7fc;border-radius:6px;margin-bottom:6px;">
               <span style="font-size:13px;color:var(--primary);"><i class="fa-solid fa-file-excel" style="color:var(--primary);"></i> ${f.name} <span style="color:var(--text-light);font-size:11px;">(${(f.size/1024).toFixed(0)} KB)</span></span>
               <button class="btn btn-secondary btn-sm" onclick="codQuitar(${i})">Quitar</button>
             </div>`).join('');
        btn.disabled = codArchivos.length === 0;
    }
    function codEnviar(){
        if (codArchivos.length === 0) return;
        const fd = new FormData();
        codArchivos.forEach(f => fd.append('adjunto[]', f));
        Swal.fire({ title:'Enviando…', html:'Registrando tu envío y notificando al equipo.', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
        fetch('api/codificacion_cargar.php', { method:'POST', body:fd })
            .then(r => r.json())
            .then(d => {
                if (d.status === 'success'){ Swal.fire('¡Enviado!', d.message, 'success'); codArchivos=[]; codRender(); }
                else if (d.status === 'warning'){ Swal.fire('Registrado', d.message, 'warning'); codArchivos=[]; codRender(); }
                else { Swal.fire('Error', d.message || 'No se pudo enviar.', 'error'); }
            })
            .catch(() => Swal.fire('Error', 'Falló la conexión con el servidor.', 'error'));
    }
```

- [ ] **Step 4: Verificar lint**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add dashboard.php
git commit -m "feat(codificacion): card CARGA MASIVA con carga real + envío (UI)"
```

---

### Task 8: Integración, no-regresión y E2E

**Files:** (ninguno nuevo; verificación)

- [ ] **Step 1: Correr toda la suite de codificación (sin BD) + no-regresión**

Run:
```bash
php tests/codificacion_validar_test.php
php tests/codificacion_correo_test.php
php tests/codificacion_registro_test.php   # SKIP sin COD_TEST_DB
php tests/pagos_unif_test.php               # no-regresión (endpoint intacto)
php -l dashboard.php && php -l api/codificacion_cargar.php && php -l api/lib_codificacion.php
```
Expected: cada test `RESULTADO: OK`; lints limpios.

- [ ] **Step 2: Prueba real de BD + correo (manual, con cuidado)**

Run: `COD_TEST_DB=1 php tests/codificacion_registro_test.php`
Expected: `RESULTADO: OK (... limpieza hecha)`.

- [ ] **Step 3: E2E navegador (Rafael)**

En `localhost/plataforma_20` → Codificación → Archivos:
1. Arrastrar 1 `.xlsx` → aparece en la lista → Enviar → SweetAlert "¡Enviado! Consecutivo #N".
2. Repetir con **varios** archivos a la vez.
3. Confirmar que llega el correo al aliado (TO) y a los BCC, con los Excel adjuntos y el formato morado.
4. Probar arrastrar un no-Excel → aviso "Archivo no válido".

Expected: todo OK. Anotar el consecutivo generado.

- [ ] **Step 4: (Si E2E OK) actualizar memoria/changelog**

Registrar en el changelog de plataforma_20 (memoria) la funcionalidad y el cambio de BD (columna `nit`), como se hizo con el `estado` del 2026-06-19.

---

## Notas de despliegue (post-merge)

- Re-sync a `plataforma_20_produccion`: copiar `api/lib_codificacion.php`, `api/codificacion_cargar.php`, `dashboard.php`, `PHPMailer/`, y crear `conexion/config_mail.php` **a mano** en producción (no viaja por git, igual que `conexion_*.php`).
- La columna `nit` ya quedó en la BD de producción (Task 1, RDS compartido) — no hay que repetirla.
- Recomendado a Rafael: **rotar la clave de aplicación de Gmail** (quedó expuesta en código viejo).
