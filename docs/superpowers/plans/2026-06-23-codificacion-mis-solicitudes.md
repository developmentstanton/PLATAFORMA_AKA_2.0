# Codificación — "Mis solicitudes" (historial real): Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que la pestaña "Mis solicitudes" muestre el historial real de envíos del aliado logueado (de `consecutivo_planillas_aka`), del más reciente al más antiguo, con # solicitud, fecha, NIT, nombre del tercero y estado coloreado (Estudio=morado, Rechazado=rojo, Aprobado=verde).

**Architecture:** Endpoint JSON (`api/codificacion_solicitudes.php`) que llama a una función testeable en `api/lib_codificacion.php`; el front (`dashboard.php`) hace `fetch` y pinta la tabla al abrir la pestaña. Mismo patrón que los informes del portal.

**Tech Stack:** PHP + `sqlsrv` (SQL Server RDS), JS vanilla en `dashboard.php`.

## Global Constraints

- **SQL parametrizado.** Filtro por `nombre_cliente = ?` con `$_SESSION['usuario']` (NUNCA un parámetro del cliente).
- **Tabla:** `INTEGRACION.dbo.consecutivo_planillas_aka` — `consecutivo` int IDENTITY (PK), `nombre_cliente` varchar(60), `fecha` date, `estado` varchar(20) ('Estudio'/'Rechazado'/'Aprobado'), `nit` varchar(20) NULL.
- **Orden:** `ORDER BY consecutivo DESC` (más reciente primero).
- **Columnas en UI:** `# Solicitud | Fecha | NIT | Nombre del tercero | Estado`. Fecha `dd/mm/yyyy`. NIT null → `—`.
- **Colores de estado:** Estudio → `.status-estudio` (morado, nuevo); Rechazado → `.status-rechazado` (rojo, ya existe); Aprobado → `.status-aprobado` (verde, ya existe).
- **Reintento conexión RDS:** 4× con `usleep(300000)`.
- **Escapar** el contenido dinámico en el render (XSS).
- `php -l` limpio en cada archivo PHP tocado.

## File Structure

- **Modify** `api/lib_codificacion.php` — nueva función `cod_listar_solicitudes`.
- **Create** `api/codificacion_solicitudes.php` — endpoint que devuelve el historial del aliado.
- **Create** `tests/codificacion_solicitudes_test.php` — test de la función (guarded `COD_TEST_DB=1`).
- **Modify** `dashboard.php` — tabla de `#codTab-solicitudes` (línea ~866-894) + CSS `.status-estudio` (tras línea 275) + JS `cargarSolicitudes` + disparo en `showCodTab` (línea ~1172).

---

### Task 1: `cod_listar_solicitudes` en lib (TDD)

**Files:**
- Modify: `api/lib_codificacion.php` (añadir función al final)
- Test: `tests/codificacion_solicitudes_test.php`

**Interfaces:**
- Consumes: `cod_registrar_envio($conn, $nombre, $fecha, $nit): int` (ya existe) — el test lo usa para sembrar filas.
- Produces: `cod_listar_solicitudes($conn, string $nombre): array` → lista de `['consecutivo'=>int, 'fecha'=>string('Y-m-d'|''), 'nit'=>?string, 'nombre'=>string, 'estado'=>string]`, ordenada `consecutivo DESC`, filtrada por `nombre_cliente = $nombre`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php // tests/codificacion_solicitudes_test.php
//   COD_TEST_DB=1 php tests/codificacion_solicitudes_test.php
if (getenv('COD_TEST_DB') !== '1') { echo "SKIP (exporta COD_TEST_DB=1 para probar contra la BD de producción)\nRESULTADO: OK\n"; exit(0); }
require __DIR__ . '/../api/lib_codificacion.php';
require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i=0; $dbConnect===false && $i<4; $i++){ usleep(300000); $dbConnect=sqlsrv_connect($servidor,$infoconn); }
if ($dbConnect===false){ echo "FALLO: sin conexión\nRESULTADO: FALLO\n"; exit(1); }
$fail=0; function chk($c,$m){ global $fail; if(!$c){ echo "FALLO: $m\n"; $fail=1; } }

$mio  = '__TESTSOL__'.substr(md5(uniqid('',true)),0,8);
$otro = '__TESTSOL__'.substr(md5(uniqid('',true)),0,8);

// Sembrar: 2 del aliado de prueba + 1 de otro
$c1 = cod_registrar_envio($dbConnect, $mio,  date('Y-m-d'), '111');
$c2 = cod_registrar_envio($dbConnect, $mio,  date('Y-m-d'), '222');
$c3 = cod_registrar_envio($dbConnect, $otro, date('Y-m-d'), '333');

$rows = cod_listar_solicitudes($dbConnect, $mio);
chk(count($rows)===2, "debe devolver solo las 2 del aliado (got ".count($rows).")");
chk(isset($rows[0]) && isset($rows[1]) && $rows[0]['consecutivo'] > $rows[1]['consecutivo'], "orden consecutivo DESC");
chk(isset($rows[0]) && $rows[0]['consecutivo']===$c2 && $rows[1]['consecutivo']===$c1, "la más reciente (c2) va primero");
foreach ($rows as $r) {
  chk($r['nombre']===$mio, "nombre debe ser el del aliado");
  chk($r['estado']==='Estudio', "estado por defecto 'Estudio'");
  chk(is_int($r['consecutivo']), "consecutivo int");
  chk((bool)preg_match('/^\d{4}-\d{2}-\d{2}$/',$r['fecha']), "fecha en formato Y-m-d (got ".var_export($r['fecha'],true).")");
}
$nits = array_column($rows,'nit'); sort($nits);
chk($nits===['111','222'], "nits deben ser 111 y 222 (got ".json_encode($nits).")");
foreach ($rows as $r) chk($r['consecutivo']!==$c3, "no debe incluir la solicitud de otro aliado");

// LIMPIEZA
sqlsrv_query($dbConnect, "DELETE FROM consecutivo_planillas_aka WHERE consecutivo IN (?,?,?)", [$c1,$c2,$c3]);

echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (filtro + orden DESC + campos, limpieza hecha)\n"; exit($fail);
```

- [ ] **Step 2: Ejecutar el test (debe fallar)**

Run: `COD_TEST_DB=1 php tests/codificacion_solicitudes_test.php`
Expected: error fatal "Call to undefined function cod_listar_solicitudes()".

- [ ] **Step 3: Añadir `cod_listar_solicitudes` al final de `api/lib_codificacion.php`**

```php

/**
 * Lista las solicitudes (envíos) de un aliado, de la más reciente a la más antigua.
 * @return array filas ['consecutivo'=>int,'fecha'=>string('Y-m-d'|''),'nit'=>?string,'nombre'=>string,'estado'=>string]
 */
function cod_listar_solicitudes($conn, string $nombre): array {
    $sql = "SELECT consecutivo, fecha, nit, nombre_cliente, estado
            FROM consecutivo_planillas_aka WITH (NOLOCK)
            WHERE nombre_cliente = ?
            ORDER BY consecutivo DESC";
    $stmt = sqlsrv_query($conn, $sql, [$nombre]);
    if ($stmt === false) {
        throw new RuntimeException('Listado de solicitudes falló: '.print_r(sqlsrv_errors(), true));
    }
    $rows = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $fecha = $r['fecha'];
        $rows[] = [
            'consecutivo' => (int)$r['consecutivo'],
            'fecha'       => ($fecha instanceof DateTime) ? $fecha->format('Y-m-d') : (string)$fecha,
            'nit'         => $r['nit'] !== null ? trim((string)$r['nit']) : null,
            'nombre'      => trim((string)$r['nombre_cliente']),
            'estado'      => trim((string)$r['estado']),
        ];
    }
    return $rows;
}
```

- [ ] **Step 4: Ejecutar el test (debe pasar)**

Run: `COD_TEST_DB=1 php tests/codificacion_solicitudes_test.php`
Expected: `RESULTADO: OK (filtro + orden DESC + campos, limpieza hecha)`
Verificar también SKIP sin la variable: `php tests/codificacion_solicitudes_test.php` → `SKIP ... RESULTADO: OK`.

- [ ] **Step 5: Commit**

```bash
git add api/lib_codificacion.php tests/codificacion_solicitudes_test.php
git commit -m "feat(codificacion): cod_listar_solicitudes (historial por aliado, TDD)"
```

---

### Task 2: Endpoint `api/codificacion_solicitudes.php`

**Files:**
- Create: `api/codificacion_solicitudes.php`

**Interfaces:**
- Consumes: `cod_listar_solicitudes($conn, string $nombre): array` (Task 1); `$dbConnect/$servidor/$infoconn` (conexión).
- Produces: respuesta JSON `{ok:true, solicitudes:[...]}` (o `{ok:false, error}` con 401/500).

No es TDD (orquesta sesión + BD); la lógica está cubierta por el test de Task 1. Se valida con `php -l` y E2E.

- [ ] **Step 1: Crear `api/codificacion_solicitudes.php`**

```php
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/lib_codificacion.php';

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false, 'error'=>'No autenticado']); exit;
}
$nombre = (string)$_SESSION['usuario'];

require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i = 0; $dbConnect === false && $i < 4; $i++) { usleep(300000); $dbConnect = sqlsrv_connect($servidor, $infoconn); }
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Conexión DB fallida']); exit;
}

try {
    $solicitudes = cod_listar_solicitudes($dbConnect, $nombre);
} catch (Throwable $e) {
    error_log('codificacion solicitudes: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'No se pudieron cargar las solicitudes']); exit;
}

echo json_encode(['ok'=>true, 'solicitudes'=>$solicitudes]);
```

- [ ] **Step 2: Verificar lint**

Run: `php -l api/codificacion_solicitudes.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add api/codificacion_solicitudes.php
git commit -m "feat(codificacion): endpoint del historial de solicitudes"
```

---

### Task 3: Frontend — tabla "Mis solicitudes" en `dashboard.php`

**Files:**
- Modify: `dashboard.php` (tabla de `#codTab-solicitudes` ~866-894; CSS `.status-estudio` tras ~275; JS `cargarSolicitudes`; `showCodTab` ~1172)

**Interfaces:**
- Consumes: endpoint `api/codificacion_solicitudes.php` → `{ok, solicitudes:[{consecutivo, fecha, nit, nombre, estado}]}`.
- Produces: tabla real renderizada al abrir la pestaña; función global `cargarSolicitudes()`.

- [ ] **Step 1: Reemplazar la tabla demo de `#codTab-solicitudes`**

Reemplazar el bloque `<table>...</table>` DENTRO de `<div class="card" id="codTab-solicitudes" ...>` (desde `<table>` en la línea ~866 hasta su `</table>` en ~894, las filas demo `06-160650-3` etc.) por EXACTAMENTE:

```html
                    <table>
                        <thead><tr><th># Solicitud</th><th>Fecha</th><th>NIT</th><th>Nombre del tercero</th><th>Estado</th></tr></thead>
                        <tbody id="codSolBody">
                            <tr><td colspan="5" style="text-align:center;color:var(--text-light);padding:16px;">Cargando&hellip;</td></tr>
                        </tbody>
                    </table>
```

(No tocar el `<div class="card" id="codTab-solicitudes" ...>` que lo envuelve ni su `</div>`.)

- [ ] **Step 2: Verificar lint**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Añadir la clase CSS `.status-estudio`**

Tras la línea `.status-cuidaduria { background: #dbeafe; color: #1e40af; }` (~275), añadir:

```css
        .status-estudio { background: #ede9fe; color: var(--primary); }
```

- [ ] **Step 4: Disparar la carga al abrir la pestaña (`showCodTab`)**

En la función `showCodTab(tab)` (~1172), tras las líneas que togglean display/active, añadir antes del cierre `}`:

```javascript
        if (tab === 'solicitudes') cargarSolicitudes();
```

- [ ] **Step 5: Añadir las funciones JS de "Mis solicitudes"**

Justo después del cierre de la función `showCodTab` (la línea `}` en ~1178), añadir:

```javascript
    // ===== Mis solicitudes (historial) =====
    function codSolEsc(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function codSolBadge(estado){
        const map = { 'Estudio':'status-estudio', 'Rechazado':'status-rechazado', 'Aprobado':'status-aprobado' };
        const cls = map[estado] || 'status-pendiente';
        return `<span class="status ${cls}">${codSolEsc(estado)}</span>`;
    }
    function codSolFecha(iso){
        if (!iso) return '—';
        const p = String(iso).split('-');
        return p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : codSolEsc(iso);
    }
    function cargarSolicitudes(){
        const body = document.getElementById('codSolBody');
        if (!body) return;
        body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-light);padding:16px;">Cargando…</td></tr>';
        fetch('api/codificacion_solicitudes.php')
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--danger);padding:16px;">No se pudieron cargar las solicitudes.</td></tr>'; return; }
                if (!d.solicitudes.length) { body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-light);padding:16px;">No tienes solicitudes registradas.</td></tr>'; return; }
                body.innerHTML = d.solicitudes.map(s =>
                    `<tr><td><strong>${parseInt(s.consecutivo,10)}</strong></td><td>${codSolFecha(s.fecha)}</td><td>${s.nit ? codSolEsc(s.nit) : '—'}</td><td>${codSolEsc(s.nombre)}</td><td>${codSolBadge(s.estado)}</td></tr>`
                ).join('');
            })
            .catch(() => { body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--danger);padding:16px;">Error de conexión.</td></tr>'; });
    }
```

- [ ] **Step 6: Verificar lint y commit**

Run: `php -l dashboard.php`
Expected: `No syntax errors detected`

```bash
git add dashboard.php
git commit -m "feat(codificacion): pestana Mis solicitudes con historial real + estado coloreado"
```

---

### Task 4: Integración + E2E

- [ ] **Step 1: Suite + no-regresión + lint**

Run:
```bash
COD_TEST_DB=1 php tests/codificacion_solicitudes_test.php
php tests/codificacion_validar_test.php
php tests/codificacion_correo_test.php
php -l dashboard.php && php -l api/codificacion_solicitudes.php && php -l api/lib_codificacion.php
```
Expected: cada test `RESULTADO: OK`; lints limpios.

- [ ] **Step 2: E2E navegador (Rafael)**

En `localhost/plataforma_20` → Codificación → pestaña "Mis solicitudes":
1. Ver el historial propio, ordenado del más reciente al más antiguo, con # solicitud, fecha (dd/mm/yyyy), NIT, nombre y estado coloreado (morado/rojo/verde).
2. Hacer un envío en la pestaña "Archivos" → volver a "Mis solicitudes" → la nueva solicitud aparece arriba con estado "Estudio" (morado).
3. Si el aliado no tiene solicitudes → "No tienes solicitudes registradas."

- [ ] **Step 3: (Si E2E OK) actualizar changelog en memoria**

Registrar la funcionalidad en el changelog de plataforma_20.

## Notas de despliegue (post-merge)

- Re-sync a `plataforma_20_produccion`: copiar `api/lib_codificacion.php`, `api/codificacion_solicitudes.php`, `dashboard.php`.
- No hay cambios de BD ni de credenciales en esta feature.
