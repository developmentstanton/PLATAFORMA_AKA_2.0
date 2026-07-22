# Bandeja de planillas en `/admin` — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el administrador vea las planillas de `consecutivo_planillas_aka` en una tabla DataTables y pueda cambiarles el estado desde un SweetAlert, registrando el motivo cuando rechaza.

**Architecture:** Una página (`admin/planillas.php`) que reutiliza el shell administrativo, dos endpoints JSON bajo `admin/api/`, y toda la lógica de negocio en `admin/lib_planillas.php` como funciones puras y testeables que no ejecutan nada al incluirse. La validación vive en el servidor, no en el JavaScript.

**Tech Stack:** PHP 8.x procedural, extensión `sqlsrv` contra SQL Server (RDS `INTEGRACION`), jQuery 3.7 + DataTables 2.x + SweetAlert2 v11 por CDN de jsdelivr. Sin framework, sin composer, sin build.

**Spec:** `docs/superpowers/specs/2026-07-22-admin-planillas-design.md`

## Global Constraints

- **No modificar ningún archivo del portal.** `index.php` (raíz), `dashboard.php`, `api/` y `conexion/` están en producción. Esta rama solo toca `admin/`, `sql/`, `tests/` y `docs/`.
- Estados admitidos, exactamente estos tres: `'Estudio'`, `'Aprobado'`, `'Rechazado'`. El estado pendiente es **`'Estudio'`** — `"En elaboración"` no existe en el sistema y no debe aparecer en ningún lado.
- Columna nueva: `motivo VARCHAR(200) NULL` en `INTEGRACION.dbo.consecutivo_planillas_aka`.
- El motivo es **obligatorio** cuando el estado es `'Rechazado'`, debe ser uno de `PLANILLA_MOTIVOS` exactamente, y se guarda como `NULL` para cualquier otro estado aunque el cliente envíe uno.
- La validación corre en el **servidor**. El JavaScript puede duplicarla por comodidad de la interfaz, pero nunca es la única barrera.
- Toda consulta usa `sqlsrv_query($conn, $sql, $params)` con parámetros. Nunca concatenar SQL.
- Los endpoints JSON responden `{"ok":true,...}` o `{"ok":false,"error":"..."}`. El detalle de `sqlsrv_errors()` **nunca** llega al navegador: va a `error_log()`.
- El POST de cambio de estado exige CSRF (`$_SESSION['admin_csrf_token']`, comparado con `hash_equals()`).
- Toda salida a HTML pasa por `htmlspecialchars()`; todo texto insertado desde JavaScript se escapa antes de concatenarse.
- **Trampa del proyecto:** `conexion/conexion_integracion.php` declara en scope global `$servidor`, `$basedatos`, `$usuario`, `$password`, `$infoconn` y `$dbConnect`. Ningún local puede llamarse así. (`$servidor` e `$infoconn` **sí** se usan a propósito en el retry de conexión, ver Task 4.)
- **`consecutivo_planillas_aka` es una tabla viva de producción.** Cualquier test que escriba debe insertar su propia fila, operar solo sobre el consecutivo que él generó, y borrarla al terminar.
- Indentación: **4 espacios** en `admin/lib_planillas.php`, en los endpoints y en los tests; **tabs** en `admin/planillas.php` (es una página).
- Paleta: `--primary: #4A4782`, `--accent: #ff001e`, `--text: #2d2b4e`, `--text-light: #7b7894`. Badges de estado con los colores del portal: `Estudio` morado (`#ede9fe`/`#4A4782`), `Aprobado` verde (`#ecfdf5`/`#065f46`), `Rechazado` rojo (`#fef2f2`/`#991b1b`).
- Los tests son scripts planos ejecutables con `php tests/<archivo>.php`, sin framework, exit 0 si pasan y 1 si fallan.
- Al correr PHP en este equipo salen warnings sobre `php_xdebug.dll` y `php_dio_ts.dll`. Es ruido conocido del `php.ini` local: ignorarlo mientras el exit code sea 0.

---

### Task 1: Columna `motivo` en la base

**Files:**
- Create: `sql/ddl_motivo_planillas.php`

**Interfaces:**
- Consumes: `conexion/conexion_integracion.php` (define `$dbConnect`).
- Produces: la columna `motivo VARCHAR(200) NULL` en `INTEGRACION.dbo.consecutivo_planillas_aka`, de la que dependen las Tasks 3, 4 y 5.

Esta tarea no lleva test automatizado: su verificación es consultar el esquema después de correrla, y correrla dos veces para comprobar que es idempotente.

- [ ] **Step 1: Write the migration script**

Crear `sql/ddl_motivo_planillas.php` (4 espacios):

```php
<?php
/**
 * Agrega consecutivo_planillas_aka.motivo — el motivo por el cual se rechaza una planilla.
 *
 * IDEMPOTENTE: comprueba con COL_LENGTH antes de tocar nada, así que se puede correr dos
 * veces sin fallar. La RDS es la MISMA para dev, staging y producción, de modo que aplicarlo
 * una vez sirve para los tres entornos.
 *
 *   php sql/ddl_motivo_planillas.php
 */
require __DIR__ . '/../conexion/conexion_integracion.php';

if ($dbConnect === false) {
    fwrite(STDERR, "No hay conexion a INTEGRACION.\n");
    exit(1);
}

$chk = sqlsrv_query($dbConnect, "SELECT COL_LENGTH('dbo.consecutivo_planillas_aka','motivo') AS l");
if ($chk === false) {
    fwrite(STDERR, "No se pudo consultar el esquema.\n");
    exit(1);
}
$row = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($chk);

if ($row !== null && $row['l'] !== null) {
    echo "OK: la columna 'motivo' ya existe (VARCHAR({$row['l']})). Nada que hacer.\n";
    exit(0);
}

$alter = sqlsrv_query(
    $dbConnect,
    "ALTER TABLE INTEGRACION.dbo.consecutivo_planillas_aka ADD motivo VARCHAR(200) NULL"
);
if ($alter === false) {
    fwrite(STDERR, "ALTER TABLE fallo.\n");
    exit(1);
}
sqlsrv_free_stmt($alter);

$ver = sqlsrv_query($dbConnect, "SELECT COL_LENGTH('dbo.consecutivo_planillas_aka','motivo') AS l");
$rowVer = $ver ? sqlsrv_fetch_array($ver, SQLSRV_FETCH_ASSOC) : null;
if ($rowVer === null || $rowVer['l'] === null) {
    fwrite(STDERR, "El ALTER no fallo pero la columna no aparece.\n");
    exit(1);
}

echo "OK: columna 'motivo' creada, VARCHAR({$rowVer['l']}) NULL.\n";
exit(0);
```

- [ ] **Step 2: Run it**

Run: `php sql/ddl_motivo_planillas.php`
Expected: `OK: columna 'motivo' creada, VARCHAR(200) NULL.` y código de salida 0.

- [ ] **Step 3: Run it again to prove it is idempotent**

Run: `php sql/ddl_motivo_planillas.php`
Expected: `OK: la columna 'motivo' ya existe (VARCHAR(200)). Nada que hacer.` y código de salida 0.

- [ ] **Step 4: Confirm the schema and that no existing row was touched**

Run:

```bash
php -r "require 'conexion/conexion_integracion.php'; \$s=sqlsrv_query(\$dbConnect,\"SELECT COUNT(*) t, COUNT(motivo) conmotivo FROM consecutivo_planillas_aka\"); \$r=sqlsrv_fetch_array(\$s,SQLSRV_FETCH_ASSOC); echo \"filas={\$r['t']} con_motivo={\$r['conmotivo']}\n\";"
```

Expected: `filas=304 con_motivo=0` (o el total que haya en ese momento, con `con_motivo=0`). Ninguna fila existente debe haber quedado con motivo.

- [ ] **Step 5: Commit**

```bash
git add sql/ddl_motivo_planillas.php
git commit -m "feat(admin): columna motivo en consecutivo_planillas_aka (DDL idempotente)"
```

---

### Task 2: Validación de la transición

**Files:**
- Create: `admin/lib_planillas.php`
- Test: `tests/planillas_test.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `const PLANILLA_ESTADOS = ['Estudio', 'Aprobado', 'Rechazado'];`
  - `const PLANILLA_MOTIVOS = [...]` (cinco cadenas, ver el código).
  - `planillas_validar(string $estado, ?string $motivo): string` — devuelve `''` si la transición es válida, o el mensaje de error si no.

- [ ] **Step 1: Write the failing test**

Crear `tests/planillas_test.php`:

```php
<?php
// Contrato de planillas_validar(). SIN base de datos.
//
// Esta es la barrera real: el select del navegador se puede saltar (basta un POST a mano),
// así que la regla "si rechazas, el motivo es obligatorio y tiene que ser uno de la lista"
// vive aquí, en el servidor. Los casos 2, 3 y 5 son los que protegen ese requisito.
//
// El caso 6 documenta una decisión deliberada: aprobar con motivo NO es un error, pero el
// motivo se descarta. Así una planilla aprobada nunca arrastra el motivo de un rechazo previo.
//
//   php tests/planillas_test.php

require_once __DIR__ . '/../admin/lib_planillas.php';

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "CONTRATO DE planillas_validar()\n" . str_repeat('=', 70) . "\n";

$motivoValido = PLANILLA_MOTIVOS[0];

// 1) Aprobado sin motivo: el camino normal
chequear('Aprobado sin motivo es valido',
    planillas_validar('Aprobado', null) === '');

// 2) Rechazado sin motivo: NO se permite
$e = planillas_validar('Rechazado', null);
chequear('Rechazado sin motivo es invalido', $e !== '', $e === '' ? 'LO DEJO PASAR' : $e);

// 2b) Rechazado con motivo en blanco tampoco
chequear('Rechazado con motivo vacio es invalido',
    planillas_validar('Rechazado', '   ') !== '');

// 3) Rechazado con un motivo inventado: NO se permite
$e = planillas_validar('Rechazado', 'porque si');
chequear('Rechazado con motivo fuera de la lista es invalido', $e !== '',
    $e === '' ? 'ACEPTO TEXTO ARBITRARIO' : $e);

// 4) Rechazado con motivo de la lista: valido
chequear('Rechazado con motivo de la lista es valido',
    planillas_validar('Rechazado', $motivoValido) === '',
    planillas_validar('Rechazado', $motivoValido));

// 5) Estado inventado: NO se permite
chequear('Estado inventado es invalido',
    planillas_validar('Borrado', null) !== '');

// 5b) Estudio es un estado valido (se puede devolver una planilla a pendiente)
chequear('Estudio es valido', planillas_validar('Estudio', null) === '');

// 6) Aprobado CON motivo: valido (el motivo se descarta al guardar, ver Task 3)
chequear('Aprobado con motivo es valido',
    planillas_validar('Aprobado', $motivoValido) === '');

// 7) La lista de motivos no puede estar vacia ni tener duplicados
chequear('PLANILLA_MOTIVOS no esta vacia', count(PLANILLA_MOTIVOS) > 0);
chequear('PLANILLA_MOTIVOS no tiene duplicados',
    count(PLANILLA_MOTIVOS) === count(array_unique(PLANILLA_MOTIVOS)));

// 8) Ningun motivo puede pasarse de VARCHAR(200)
$largos = array_filter(PLANILLA_MOTIVOS, fn($m) => strlen($m) > 200);
chequear('Ningun motivo excede 200 caracteres', count($largos) === 0,
    count($largos) ? 'se pasan: ' . implode('; ', $largos) : '');

echo str_repeat('=', 70) . "\n";
if ($fallos) { echo "FALLARON " . count($fallos) . ": " . implode('; ', $fallos) . "\n"; exit(1); }
echo "TODO OK\n";
exit(0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/planillas_test.php`
Expected: FAIL — `Failed opening required '.../admin/lib_planillas.php'`.

- [ ] **Step 3: Write minimal implementation**

Crear `admin/lib_planillas.php` (4 espacios):

```php
<?php
/**
 * Lógica de la bandeja de planillas del módulo administrativo.
 * Pura y testeable. Incluida por las páginas, los endpoints y los tests.
 * NO ejecuta nada al incluirse (mismo contrato que api/lib_login.php).
 */

/**
 * Los únicos estados admitidos. Coinciden exactamente con los que el portal del aliado
 * sabe pintar (dashboard.php:1222): cualquier otro valor le saldría como badge de
 * "desconocido". 'Estudio' es el estado pendiente, el que asigna el DEFAULT de la columna
 * cuando el aliado sube una codificación.
 */
const PLANILLA_ESTADOS = ['Estudio', 'Aprobado', 'Rechazado'];

/**
 * Motivos de rechazo que ofrece el select.
 *
 * LISTA PROVISIONAL: Rafael entregará la definitiva. Reemplazar los elementos de este
 * arreglo es todo lo que hace falta — no hay que tocar ni la interfaz ni los endpoints.
 * Ninguno puede pasar de 200 caracteres (el largo de la columna).
 */
const PLANILLA_MOTIVOS = [
    'Codificación incompleta',
    'Información del producto inconsistente',
    'Archivo ilegible o dañado',
    'Referencias duplicadas',
    'No cumple con el formato requerido',
];

/**
 * Valida una transición de estado ANTES de tocar la base.
 *
 * Corre en el servidor a propósito: el select del navegador es una comodidad, no una
 * barrera — cualquiera puede mandar un POST a mano con el estado y el motivo que quiera.
 *
 * @return string Cadena vacía si la transición es válida; el mensaje de error si no.
 */
function planillas_validar(string $estado, ?string $motivo): string {
    if (!in_array($estado, PLANILLA_ESTADOS, true)) {
        return 'Estado no válido.';
    }

    if ($estado === 'Rechazado') {
        $m = trim((string)$motivo);
        if ($m === '') {
            return 'Debes indicar el motivo del rechazo.';
        }
        if (!in_array($m, PLANILLA_MOTIVOS, true)) {
            return 'Motivo de rechazo no válido.';
        }
    }

    // Para los demás estados el motivo sobra, pero no es un error enviarlo:
    // planillas_cambiar_estado() lo descarta a NULL.
    return '';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/planillas_test.php`
Expected: PASS — once líneas `OK` y `TODO OK`. Código de salida 0.

- [ ] **Step 5: Commit**

```bash
git add admin/lib_planillas.php tests/planillas_test.php
git commit -m "feat(admin): planillas_validar — el motivo obligatorio al rechazar se exige en el servidor"
```

---

### Task 3: Listar y cambiar estado contra la base

**Files:**
- Modify: `admin/lib_planillas.php` (añadir dos funciones al final)
- Modify: `tests/planillas_test.php` (añadir un bloque contra la base antes del resumen final)

**Interfaces:**
- Consumes: `PLANILLA_ESTADOS`, `PLANILLA_MOTIVOS`, `planillas_validar()` (Task 2); la columna `motivo` (Task 1).
- Produces:
  - `planillas_listar($conn): array` — filas `['consecutivo'=>int, 'nombre_cliente'=>string, 'nit'=>?string, 'fecha'=>string, 'estado'=>string, 'motivo'=>?string]`, con `fecha` en formato `Y-m-d`.
  - `planillas_cambiar_estado($conn, int $consecutivo, string $estado, ?string $motivo): bool` — `false` si el consecutivo no existe.

- [ ] **Step 1: Write the failing test**

En `tests/planillas_test.php`, **antes** del bloque final que imprime el resumen (las tres últimas líneas: `echo str_repeat...`, el `if ($fallos)` y el `echo "TODO OK"`), insertar:

```php
// ---------------------------------------------------------------------------
// Contra la BASE DE DATOS.
//
// CUIDADO: consecutivo_planillas_aka es una tabla VIVA de producción. Este bloque inserta
// su PROPIA fila, opera solo sobre el consecutivo que él generó, y la borra al terminar.
// Nunca toca una fila que no haya creado. Mismo patrón que se usó al probar
// cod_registrar_envio().
// ---------------------------------------------------------------------------
echo "\nCONTRA LA BASE (fila propia, se borra al final)\n" . str_repeat('=', 70) . "\n";

require_once __DIR__ . '/../conexion/conexion_integracion.php';

if ($dbConnect === false) {
    echo "  SALTA  no hay conexion a INTEGRACION\n";
} else {
    $marca = 'ZZ_TEST_PLANILLAS';
    $consTest = 0;

    // Crear la fila de prueba y quedarnos con SU consecutivo
    $ins = sqlsrv_query($dbConnect,
        "SET NOCOUNT ON;
         INSERT INTO consecutivo_planillas_aka (nombre_cliente, fecha, nit)
         OUTPUT INSERTED.consecutivo AS consecutivo
         VALUES (?, ?, ?)",
        [$marca, date('Y-m-d'), null]);
    if ($ins !== false) {
        do {
            $r = sqlsrv_fetch_array($ins, SQLSRV_FETCH_ASSOC);
            if ($r && isset($r['consecutivo'])) { $consTest = (int)$r['consecutivo']; break; }
        } while (sqlsrv_next_result($ins));
        sqlsrv_free_stmt($ins);
    }

    chequear('se creo la fila de prueba', $consTest > 0, "consecutivo=$consTest");

    if ($consTest > 0) {
        // Guard: solo seguimos si la fila es realmente la nuestra
        $chk = sqlsrv_query($dbConnect,
            "SELECT nombre_cliente FROM consecutivo_planillas_aka WHERE consecutivo = ?", [$consTest]);
        $rowChk = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
        if ($chk) sqlsrv_free_stmt($chk);
        $esNuestra = $rowChk && trim((string)$rowChk['nombre_cliente']) === $marca;
        chequear('la fila de prueba es la nuestra', $esNuestra);

        if ($esNuestra) {
            // a) Nace en 'Estudio' por el DEFAULT de la columna
            $filas = planillas_listar($dbConnect);
            $mia = null;
            foreach ($filas as $f) { if ($f['consecutivo'] === $consTest) { $mia = $f; break; } }
            chequear('planillas_listar devuelve la fila nueva', $mia !== null);
            chequear('la fila nace en estado Estudio',
                $mia !== null && $mia['estado'] === 'Estudio',
                $mia !== null ? "estado={$mia['estado']}" : '');
            chequear('la fecha viene como Y-m-d',
                $mia !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$mia['fecha']) === 1);
            chequear('el motivo nace en NULL', $mia !== null && $mia['motivo'] === null);

            // b) Rechazar guarda el motivo
            $ok = planillas_cambiar_estado($dbConnect, $consTest, 'Rechazado', PLANILLA_MOTIVOS[1]);
            chequear('cambiar a Rechazado devuelve true', $ok === true);
            $q = sqlsrv_query($dbConnect,
                "SELECT RTRIM(estado) e, motivo m FROM consecutivo_planillas_aka WHERE consecutivo = ?",
                [$consTest]);
            $r1 = $q ? sqlsrv_fetch_array($q, SQLSRV_FETCH_ASSOC) : null;
            if ($q) sqlsrv_free_stmt($q);
            chequear('quedo en Rechazado', $r1 && $r1['e'] === 'Rechazado');
            chequear('el motivo quedo guardado',
                $r1 && trim((string)$r1['m']) === PLANILLA_MOTIVOS[1],
                $r1 ? "motivo=[{$r1['m']}]" : '');

            // c) EL CASO QUE IMPORTA: aprobar limpia el motivo del rechazo anterior
            $ok2 = planillas_cambiar_estado($dbConnect, $consTest, 'Aprobado', PLANILLA_MOTIVOS[0]);
            chequear('cambiar a Aprobado devuelve true', $ok2 === true);
            $q2 = sqlsrv_query($dbConnect,
                "SELECT RTRIM(estado) e, motivo m FROM consecutivo_planillas_aka WHERE consecutivo = ?",
                [$consTest]);
            $r2 = $q2 ? sqlsrv_fetch_array($q2, SQLSRV_FETCH_ASSOC) : null;
            if ($q2) sqlsrv_free_stmt($q2);
            chequear('quedo en Aprobado', $r2 && $r2['e'] === 'Aprobado');
            chequear('al aprobar, el motivo se limpio a NULL', $r2 && $r2['m'] === null,
                $r2 ? "motivo=[" . var_export($r2['m'], true) . "]" : '');

            // d) Un consecutivo inexistente devuelve false
            chequear('consecutivo inexistente devuelve false',
                planillas_cambiar_estado($dbConnect, -999999, 'Aprobado', null) === false);
        }

        // Limpieza: SIEMPRE, y solo nuestra fila (doble guard por marca)
        $del = sqlsrv_query($dbConnect,
            "DELETE FROM consecutivo_planillas_aka WHERE consecutivo = ? AND nombre_cliente = ?",
            [$consTest, $marca]);
        chequear('se borro la fila de prueba', $del !== false);
        if ($del !== false) sqlsrv_free_stmt($del);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/planillas_test.php`
Expected: FAIL — `Call to undefined function planillas_listar()`.

- [ ] **Step 3: Write minimal implementation**

Añadir al final de `admin/lib_planillas.php`:

```php
/**
 * Todas las planillas, de la más reciente a la más antigua.
 *
 * Devuelve el conjunto completo: el filtrado por estado lo hace DataTables en el navegador.
 * Con ~300 filas de texto corto el JSON pesa decenas de kilobytes, y filtrar en cliente evita
 * una ida y vuelta a la RDS por cada cambio de filtro — el enlace desde WMS-LAB es lento.
 *
 * @return array filas ['consecutivo'=>int,'nombre_cliente'=>string,'nit'=>?string,
 *                      'fecha'=>string('Y-m-d'),'estado'=>string,'motivo'=>?string]
 */
function planillas_listar($conn): array {
    $sql = "SELECT consecutivo, nombre_cliente, nit, fecha, estado, motivo
            FROM consecutivo_planillas_aka WITH (NOLOCK)
            ORDER BY consecutivo DESC";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        throw new RuntimeException('Listado de planillas falló: ' . print_r(sqlsrv_errors(), true));
    }

    $filas = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $nit    = isset($r['nit']) ? trim((string)$r['nit']) : '';
        $motivo = isset($r['motivo']) ? trim((string)$r['motivo']) : '';
        $filas[] = [
            'consecutivo'    => (int)$r['consecutivo'],
            'nombre_cliente' => trim((string)$r['nombre_cliente']),
            'nit'            => ($nit !== '') ? $nit : null,
            'fecha'          => ($r['fecha'] instanceof DateTime) ? $r['fecha']->format('Y-m-d') : '',
            'estado'         => trim((string)$r['estado']),
            'motivo'         => ($motivo !== '') ? $motivo : null,
        ];
    }
    sqlsrv_free_stmt($stmt);

    return $filas;
}

/**
 * Aplica el cambio de estado sobre una planilla.
 *
 * Normaliza el motivo: solo se guarda cuando el estado es 'Rechazado'. Para cualquier otro
 * estado queda en NULL aunque el llamador haya pasado uno — así una planilla aprobada nunca
 * arrastra el motivo de un rechazo anterior.
 *
 * NO valida: eso es trabajo de planillas_validar(), que el endpoint corre antes.
 *
 * @return bool false si el consecutivo no existe.
 */
function planillas_cambiar_estado($conn, int $consecutivo, string $estado, ?string $motivo): bool {
    $motivoFinal = null;
    if ($estado === 'Rechazado') {
        $m = trim((string)$motivo);
        $motivoFinal = ($m !== '') ? $m : null;
    }

    $sql = "UPDATE consecutivo_planillas_aka
            SET estado = ?, motivo = ?
            WHERE consecutivo = ?";

    $stmt = sqlsrv_query($conn, $sql, [$estado, $motivoFinal, $consecutivo]);
    if ($stmt === false) {
        throw new RuntimeException('Cambio de estado falló: ' . print_r(sqlsrv_errors(), true));
    }

    $afectadas = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);

    return $afectadas > 0;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/planillas_test.php`
Expected: PASS — el bloque puro más el bloque contra la base, terminando en `TODO OK` y código de salida 0.

- [ ] **Step 5: Confirm the test cleaned up after itself**

Run:

```bash
php -r "require 'conexion/conexion_integracion.php'; \$s=sqlsrv_query(\$dbConnect,\"SELECT COUNT(*) c FROM consecutivo_planillas_aka WHERE nombre_cliente='ZZ_TEST_PLANILLAS'\"); \$r=sqlsrv_fetch_array(\$s); echo \"filas de prueba restantes: {\$r['c']}\n\";"
```

Expected: `filas de prueba restantes: 0`

- [ ] **Step 6: Commit**

```bash
git add admin/lib_planillas.php tests/planillas_test.php
git commit -m "feat(admin): planillas_listar y planillas_cambiar_estado (el motivo se limpia al no rechazar)"
```

---

### Task 4: Endpoints JSON

**Files:**
- Modify: `admin/lib_admin_auth.php` (añadir una función al final)
- Create: `admin/api/planillas_listar.php`
- Create: `admin/api/planillas_estado.php`

**Interfaces:**
- Consumes: `planillas_listar()`, `planillas_cambiar_estado()`, `planillas_validar()`, `PLANILLA_ESTADOS`, `PLANILLA_MOTIVOS` (Tasks 2 y 3); `admin_iniciar_sesion()`, `admin_estado_sesion()`, `admin_cerrar_sesion_admin()` (ya existen en `admin/lib_admin_auth.php`).
- Produces:
  - `admin_exigir_sesion_json(): array` en `admin/lib_admin_auth.php`.
  - `GET admin/api/planillas_listar.php` → `{"ok":true,"filas":[...]}`
  - `POST admin/api/planillas_estado.php` → `{"ok":true}` o `{"ok":false,"error":"..."}`

Contexto necesario sobre las funciones que ya existen en `admin/lib_admin_auth.php`:

```php
function admin_iniciar_sesion(): void          // ini_set de cookie + session_start(); idempotente
function admin_estado_sesion(array $sesion, int $ahora): string   // 'ok' | 'ausente' | 'expirada'
function admin_cerrar_sesion_admin(): void     // unset($_SESSION['admin'])
function admin_exigir_sesion(): array          // guard de PÁGINAS: redirige y termina
```

El namespace de sesión es `$_SESSION['admin'] = ['usuario','correo','imagen','ultima_actividad']`.

- [ ] **Step 1: Add the JSON guard to the auth lib**

Añadir al final de `admin/lib_admin_auth.php` (4 espacios):

```php
/**
 * Guard para endpoints JSON. Mismo criterio que admin_exigir_sesion(), pero en vez de
 * redirigir responde 401 con JSON y termina.
 *
 * La diferencia importa: un fetch() no sigue el Location: de admin_exigir_sesion(); recibiría
 * el HTML del login y reventaría al parsear JSON, dejando al usuario con un error críptico en
 * vez de "tu sesión expiró".
 *
 * @return array Los datos del admin en sesión.
 */
function admin_exigir_sesion_json(): array {
    admin_iniciar_sesion();

    $estado = admin_estado_sesion($_SESSION, time());
    if ($estado !== 'ok') {
        if ($estado === 'expirada') {
            admin_cerrar_sesion_admin();
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'sesion_expirada']);
        exit;
    }

    $_SESSION['admin']['ultima_actividad'] = time();
    return $_SESSION['admin'];
}
```

- [ ] **Step 2: Write the listing endpoint**

Crear `admin/api/planillas_listar.php` (4 espacios):

```php
<?php
/**
 * GET → todas las planillas en JSON. Solo lectura, no muta nada: no exige CSRF.
 * El filtrado por estado lo hace DataTables en el navegador.
 */
require_once __DIR__ . '/../lib_admin_auth.php';
require_once __DIR__ . '/../lib_planillas.php';

header('Content-Type: application/json; charset=utf-8');
admin_exigir_sesion_json();

require __DIR__ . '/../../conexion/conexion_integracion.php';
// Reintento igual que en api/codificacion_solicitudes.php: el enlace con la RDS es lento y
// a veces la primera conexión falla. $servidor e $infoconn vienen del archivo de conexión.
for ($i = 0; $dbConnect === false && $i < 4; $i++) {
    usleep(300000);
    $dbConnect = sqlsrv_connect($servidor, $infoconn);
}
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos.']);
    exit;
}

try {
    $filas = planillas_listar($dbConnect);
} catch (Throwable $e) {
    error_log('admin planillas listar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudieron cargar las planillas.']);
    exit;
}

echo json_encode(['ok' => true, 'filas' => $filas]);
```

- [ ] **Step 3: Write the state-change endpoint**

Crear `admin/api/planillas_estado.php` (4 espacios):

```php
<?php
/**
 * POST → cambia el estado (y el motivo) de una planilla.
 *
 * Es una escritura, así que exige CSRF. El orden de las comprobaciones importa: sesión,
 * método, CSRF y validación de negocio ocurren ANTES de abrir la conexión, para no gastar
 * una conexión a la RDS en una petición que ya sabemos que va a ser rechazada.
 */
require_once __DIR__ . '/../lib_admin_auth.php';
require_once __DIR__ . '/../lib_planillas.php';

header('Content-Type: application/json; charset=utf-8');
admin_exigir_sesion_json();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$tokenEnviado = (string)($_POST['csrf_token'] ?? '');
$tokenSesion  = (string)($_SESSION['admin_csrf_token'] ?? '');
if ($tokenSesion === '' || !hash_equals($tokenSesion, $tokenEnviado)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solicitud no válida. Recarga la página.']);
    exit;
}

$consecutivo = (int)($_POST['consecutivo'] ?? 0);
$estado      = trim((string)($_POST['estado'] ?? ''));
$motivo      = isset($_POST['motivo']) ? trim((string)$_POST['motivo']) : null;

if ($consecutivo <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Consecutivo no válido.']);
    exit;
}

$error = planillas_validar($estado, $motivo);
if ($error !== '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

require __DIR__ . '/../../conexion/conexion_integracion.php';
for ($i = 0; $dbConnect === false && $i < 4; $i++) {
    usleep(300000);
    $dbConnect = sqlsrv_connect($servidor, $infoconn);
}
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos.']);
    exit;
}

try {
    $ok = planillas_cambiar_estado($dbConnect, $consecutivo, $estado, $motivo);
} catch (Throwable $e) {
    error_log('admin planillas estado: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el cambio.']);
    exit;
}

if (!$ok) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'La planilla no existe.']);
    exit;
}

echo json_encode(['ok' => true]);
```

- [ ] **Step 4: Verify syntax and that the existing tests still pass**

Run: `php -l admin/lib_admin_auth.php && php -l admin/api/planillas_listar.php && php -l admin/api/planillas_estado.php`
Expected: `No syntax errors detected` en los tres.

Run: `php tests/admin_auth_test.php && php tests/admin_guard_test.php && php tests/planillas_test.php`
Expected: los tres `TODO OK`.

- [ ] **Step 5: Verify the endpoints over real HTTP**

Apache corre en `http://localhost/plataforma_20/`. Comprobar, con `curl`:

1. **Sin sesión**, `GET admin/api/planillas_listar.php` → HTTP **401** y cuerpo `{"ok":false,"error":"sesion_expirada"}`. Es la comprobación clave: debe ser JSON, **no** el HTML del login.
2. **Sin sesión**, `POST admin/api/planillas_estado.php` → HTTP **401** con el mismo JSON.
3. Iniciar sesión como `Administrador` guardando cookies (GET a `admin/` para sacar el `csrf_token` del input oculto, luego POST con usuario y contraseña reales). La contraseña se lee de la base con un script PHP de un solo uso; **no la escribas en ningún archivo que vayas a commitear ni en tu reporte**.
4. **Con sesión**, `GET planillas_listar.php` → HTTP 200 con `{"ok":true,"filas":[...]}`. Comprobar que trae el número de filas esperado y que cada fila tiene las seis claves.
5. **Con sesión pero SIN `csrf_token`**, POST a `planillas_estado.php` → HTTP **403**.
6. **Con sesión y CSRF válido, pero `estado=Rechazado` sin motivo** → HTTP **400** con el mensaje de motivo obligatorio. **La base no debe cambiar.**
7. **Con sesión y CSRF válido, `estado=Rechazado` con un motivo inventado** ("porque si") → HTTP **400**.
8. **Camino feliz:** crear una fila de prueba propia con `nombre_cliente='ZZ_TEST_ENDPOINT'` (INSERT con un script de un solo uso), rechazarla vía el endpoint con un motivo válido de `PLANILLA_MOTIVOS`, comprobar en la base que `estado='Rechazado'` y que `motivo` quedó guardado, y **borrar la fila al terminar**. Confirmar con una consulta que no queda ninguna fila `ZZ_TEST_ENDPOINT`.

Borra cookies y scripts temporales antes de commitear; confirma con `git status` que no queda nada suelto.

- [ ] **Step 6: Commit**

```bash
git add admin/lib_admin_auth.php admin/api/planillas_listar.php admin/api/planillas_estado.php
git commit -m "feat(admin): endpoints JSON de planillas con guard 401 propio y CSRF en la escritura"
```

---

### Task 5: Página, tabla y cambio de estado

**Files:**
- Create: `admin/planillas.php`
- Modify: `admin/inicio.php` (poblar el array `$menu`)

**Interfaces:**
- Consumes: `admin_exigir_sesion()` (ya existe), `PLANILLA_ESTADOS`, `PLANILLA_MOTIVOS` (Task 2), los dos endpoints (Task 4).
- Produces: la interfaz final. Nada depende de esta tarea.

Contexto de lo que ya existe: `admin/inicio.php` es el shell, con barra superior, menú lateral alimentado por un array `$menu` y área de contenido, estilado por `admin/assets/admin.css`. Sus clases son `.admin-barra`, `.admin-menu`, `.admin-contenido`, `.admin-tarjeta`. El guard `admin_exigir_sesion()` devuelve `['usuario','correo','imagen','ultima_actividad']`.

- [ ] **Step 1: Add the menu entry**

En `admin/inicio.php` está hoy el array vacío con un comentario de ejemplo dentro (una línea comentada que menciona `'Usuarios'`). Reemplazar **todo el bloque, comentario incluido**, por:

```php
	$menu = array(
		array('etiqueta' => 'Planillas', 'icono' => 'fa-file-lines', 'url' => 'planillas.php'),
	);
```

El `if (empty($menu))` que muestra "Próximamente" se deja tal cual: sigue siendo correcto, solo que ahora no se cumple.

Nota sobre la duplicación: el mismo array aparecerá en `inicio.php` y en `planillas.php`. Con una sola entrada es aceptable; cuando haya tres o cuatro páginas, el momento de extraerlo a un `admin/menu.php` compartido será obvio. No lo hagas ahora.

- [ ] **Step 2: Write the page**

Crear `admin/planillas.php` (tabs en el PHP/HTML):

```php
<?php
	require_once __DIR__ . '/lib_admin_auth.php';
	require_once __DIR__ . '/lib_planillas.php';
	$admin = admin_exigir_sesion();

	// Mismo menú que inicio.php. Con una sola entrada la duplicación es aceptable;
	// se extrae a un archivo compartido cuando haya varias páginas.
	$menu = array(
		array('etiqueta' => 'Planillas', 'icono' => 'fa-file-lines', 'url' => 'planillas.php'),
	);
?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['admin_csrf_token'] ?? ''); ?>">
	<title>Planillas — Administración AKA 2.0</title>
	<link rel="shortcut icon" href="../img/aka.ico" type="image/x-icon">
	<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="../awesome/css/fontawesome.min.css">
	<link rel="stylesheet" href="../awesome/css/solid.min.css">
	<link rel="stylesheet" href="assets/admin.css">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-dt@2.1.8/css/dataTables.dataTables.min.css">
	<style>
		.planillas-barra {
			display: flex; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;
		}
		.planillas-barra label {
			font-size: 12px; letter-spacing: 1px; text-transform: uppercase; color: var(--text-light);
		}
		.planillas-barra select {
			font-family: 'Space Grotesk', sans-serif; font-size: 14px;
			padding: 6px 10px; border: 1px solid #d5d5de; background: #fff; color: var(--text);
		}
		.status {
			display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600;
			border-radius: 12px; white-space: nowrap;
		}
		.status-estudio   { background: #ede9fe; color: #4A4782; }
		.status-aprobado  { background: #ecfdf5; color: #065f46; }
		.status-rechazado { background: #fef2f2; color: #991b1b; }
		.status-otro      { background: #fef3c7; color: #92400e; }
		.btn-estado {
			font-family: 'Space Grotesk', sans-serif; font-size: 12px; font-weight: 600;
			background: var(--primary); color: #fff; border: none; padding: 6px 12px;
			cursor: pointer; transition: background 0.15s; white-space: nowrap;
		}
		.btn-estado:hover { background: var(--accent); }
		table.dataTable { font-size: 14px; }
		.dt-empty { color: var(--text-light); font-style: italic; }
	</style>
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
	<?php foreach ($menu as $item) { ?>
		<a href="<?php echo htmlspecialchars($item['url']); ?>">
			<i class="fa-solid <?php echo htmlspecialchars($item['icono']); ?>"></i>
			<?php echo htmlspecialchars($item['etiqueta']); ?>
		</a>
	<?php } ?>
</nav>

<main class="admin-contenido">
	<h1>Planillas</h1>
	<p class="subtitulo">Codificaciones enviadas por los aliados.</p>

	<div class="admin-tarjeta">
		<div class="planillas-barra">
			<label for="filtroEstado">Estado</label>
			<select id="filtroEstado">
				<option value="Estudio">Estudio</option>
				<option value="Aprobado">Aprobado</option>
				<option value="Rechazado">Rechazado</option>
				<option value="">Todos</option>
			</select>
		</div>

		<table id="tablaPlanillas" class="display" style="width:100%">
			<thead>
				<tr>
					<th>Consecutivo</th>
					<th>Cliente</th>
					<th>NIT</th>
					<th>Fecha</th>
					<th>Estado</th>
					<th>Motivo</th>
					<th>Acción</th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net@2.1.8/js/dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const CSRF     = document.querySelector('meta[name="csrf-token"]').content;
const ESTADOS  = <?php echo json_encode(PLANILLA_ESTADOS, JSON_UNESCAPED_UNICODE); ?>;
const MOTIVOS  = <?php echo json_encode(PLANILLA_MOTIVOS, JSON_UNESCAPED_UNICODE); ?>;

function esc(s) {
	return String(s ?? '').replace(/[&<>"']/g, c => ({
		'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
	}[c]));
}

function badge(estado) {
	const map = { 'Estudio':'status-estudio', 'Aprobado':'status-aprobado', 'Rechazado':'status-rechazado' };
	const cls = map[estado] || 'status-otro';
	return '<span class="status ' + cls + '">' + esc(estado) + '</span>';
}

function fechaCorta(iso) {
	if (!iso) return '—';
	const p = String(iso).split('-');
	return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : esc(iso);
}

// Si la sesión murió, avisar en vez de dejar un error de parseo.
function sesionExpirada() {
	Swal.fire({
		icon: 'warning',
		title: 'Tu sesión expiró',
		text: 'Vuelve a iniciar sesión para continuar.',
		confirmButtonText: 'Ir al login',
		confirmButtonColor: '#4A4782',
		allowOutsideClick: false
	}).then(() => { window.location.href = 'index.php?expired=1'; });
}

let tabla = null;

$(function () {
	tabla = new DataTable('#tablaPlanillas', {
		order: [[0, 'desc']],
		pageLength: 25,
		columns: [
			{ data: 'consecutivo' },
			{ data: 'nombre_cliente', render: (d) => esc(d) },
			{ data: 'nit',    render: (d) => d ? esc(d) : '—' },
			{ data: 'fecha',  render: (d, tipo) => tipo === 'display' ? fechaCorta(d) : (d || '') },
			{ data: 'estado', render: (d, tipo) => tipo === 'display' ? badge(d) : (d || '') },
			{ data: 'motivo', render: (d) => d ? esc(d) : '—' },
			{
				data: null, orderable: false, searchable: false,
				render: (fila) => '<button class="btn-estado" data-cons="' + fila.consecutivo + '">Cambiar estado</button>'
			}
		],
		language: {
			emptyTable: '<span class="dt-empty">No hay planillas pendientes de revisar.</span>',
			zeroRecords: '<span class="dt-empty">Ninguna planilla coincide con el filtro.</span>',
			info: 'Mostrando _START_ a _END_ de _TOTAL_ planillas',
			infoEmpty: 'Sin planillas',
			infoFiltered: '(filtrado de _MAX_ en total)',
			lengthMenu: 'Mostrar _MENU_',
			search: 'Buscar:',
			paginate: { first: 'Primera', last: 'Última', next: 'Siguiente', previous: 'Anterior' }
		}
	});

	// Arranca filtrada en las pendientes. Regex anclado para que 'Estudio' no
	// coincida con nada más.
	tabla.column(4).search('^Estudio$', true, false).draw();

	$('#filtroEstado').on('change', function () {
		const v = this.value;
		tabla.column(4).search(v ? '^' + v + '$' : '', true, false).draw();
	});

	cargar();

	$('#tablaPlanillas tbody').on('click', '.btn-estado', function () {
		const cons = parseInt(this.dataset.cons, 10);
		const fila = tabla.rows().data().toArray().find(f => f.consecutivo === cons);
		if (fila) abrirDialogo(fila);
	});
});

function cargar() {
	fetch('api/planillas_listar.php', { credentials: 'same-origin' })
		.then(r => {
			if (r.status === 401) { sesionExpirada(); return null; }
			return r.json();
		})
		.then(j => {
			if (!j) return;
			if (!j.ok) { Swal.fire({ icon:'error', title:'Error', text: j.error || 'No se pudieron cargar las planillas.', confirmButtonColor:'#4A4782' }); return; }
			tabla.clear();
			tabla.rows.add(j.filas);
			tabla.draw();
		})
		.catch(() => {
			Swal.fire({ icon:'error', title:'Sin conexión', text:'No se pudieron cargar las planillas.', confirmButtonColor:'#4A4782' });
		});
}

function abrirDialogo(fila) {
	const opcEstados = ESTADOS
		.map(e => '<option value="' + esc(e) + '"' + (e === fila.estado ? ' selected' : '') + '>' + esc(e) + '</option>')
		.join('');
	const opcMotivos = ['<option value="">— Selecciona un motivo —</option>']
		.concat(MOTIVOS.map(m => '<option value="' + esc(m) + '"' + (m === fila.motivo ? ' selected' : '') + '>' + esc(m) + '</option>'))
		.join('');

	Swal.fire({
		title: 'Planilla ' + fila.consecutivo,
		html:
			'<div style="text-align:left;font-size:14px;">' +
				'<p style="margin:0 0 14px;color:#7b7894;">' + esc(fila.nombre_cliente) + '</p>' +
				'<label style="display:block;font-size:12px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:#4A4782;margin-bottom:4px;">Estado</label>' +
				'<select id="swEstado" class="swal2-select" style="width:100%;margin:0 0 14px;">' + opcEstados + '</select>' +
				'<div id="swMotivoBox" style="display:none;">' +
					'<label style="display:block;font-size:12px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:#4A4782;margin-bottom:4px;">Motivo del rechazo</label>' +
					'<select id="swMotivo" class="swal2-select" style="width:100%;margin:0;">' + opcMotivos + '</select>' +
				'</div>' +
			'</div>',
		showCancelButton: true,
		confirmButtonText: 'Guardar',
		cancelButtonText: 'Cancelar',
		confirmButtonColor: '#4A4782',
		cancelButtonColor: '#7b7894',
		didOpen: () => {
			const sel = document.getElementById('swEstado');
			const box = document.getElementById('swMotivoBox');
			const mot = document.getElementById('swMotivo');
			const sincronizar = () => {
				const esRechazo = sel.value === 'Rechazado';
				box.style.display = esRechazo ? 'block' : 'none';
				mot.disabled = !esRechazo;
				if (!esRechazo) mot.value = '';   // no arrastrar el motivo si ya no se rechaza
			};
			sel.addEventListener('change', sincronizar);
			sincronizar();
		},
		preConfirm: () => {
			const estado = document.getElementById('swEstado').value;
			const motivo = document.getElementById('swMotivo').value;
			// Comodidad para el usuario. La barrera real está en el servidor.
			if (estado === 'Rechazado' && !motivo) {
				Swal.showValidationMessage('Debes indicar el motivo del rechazo.');
				return false;
			}
			return { estado, motivo };
		}
	}).then(res => {
		if (res.isConfirmed) guardar(fila.consecutivo, res.value.estado, res.value.motivo);
	});
}

function guardar(consecutivo, estado, motivo) {
	const cuerpo = new URLSearchParams();
	cuerpo.set('consecutivo', consecutivo);
	cuerpo.set('estado', estado);
	cuerpo.set('motivo', motivo || '');
	cuerpo.set('csrf_token', CSRF);

	fetch('api/planillas_estado.php', {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
		body: cuerpo.toString()
	})
	.then(r => {
		if (r.status === 401) { sesionExpirada(); return null; }
		return r.json();
	})
	.then(j => {
		if (!j) return;
		if (!j.ok) {
			Swal.fire({ icon:'error', title:'No se guardó', text: j.error || 'No se pudo guardar el cambio.', confirmButtonColor:'#4A4782' });
			return;   // la tabla NO se toca: seguiría mintiendo respecto a la base
		}
		Swal.fire({
			icon: 'success', title: 'Estado actualizado',
			toast: true, position: 'top-end', showConfirmButton: false, timer: 2200, timerProgressBar: true
		});
		cargar();   // releer de la base, no adivinar
	})
	.catch(() => {
		Swal.fire({ icon:'error', title:'Sin conexión', text:'No se pudo guardar el cambio.', confirmButtonColor:'#4A4782' });
	});
}
</script>
</body>
</html>
```

- [ ] **Step 3: Verify syntax and no regressions**

Run: `php -l admin/planillas.php && php -l admin/inicio.php`
Expected: `No syntax errors detected` en ambos.

Run: `php tests/admin_auth_test.php && php tests/admin_guard_test.php && php tests/planillas_test.php`
Expected: los tres `TODO OK`.

- [ ] **Step 4: Verify over real HTTP**

Con `curl`, manejando cookies:

1. **Sin sesión**, GET a `admin/planillas.php` → **302** a `index.php`.
2. Iniciar sesión como `Administrador` (GET a `admin/` para el `csrf_token`, luego POST). La contraseña se lee de la base con un script de un solo uso; **no la escribas en ningún archivo ni en tu reporte**.
3. **Con sesión**, GET a `admin/planillas.php` → **200**, y el HTML debe contener: `id="tablaPlanillas"`, `meta name="csrf-token"` con un valor no vacío, los tres `<option>` de estado, y los cinco motivos de `PLANILLA_MOTIVOS`.
4. GET a `admin/inicio.php` → 200 y el menú debe mostrar **Planillas** en vez de "Próximamente".
5. Comprobar que los tres CDN responden 200, para que la página no quede rota por un recurso caído:

```bash
for u in "https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js" \
         "https://cdn.jsdelivr.net/npm/datatables.net@2.1.8/js/dataTables.min.js" \
         "https://cdn.jsdelivr.net/npm/datatables.net-dt@2.1.8/css/dataTables.dataTables.min.css" \
         "https://cdn.jsdelivr.net/npm/sweetalert2@11"; do
  echo "$(curl -s -o /dev/null -w '%{http_code}' -L "$u")  $u"
done
```

Expected: `200` en los cuatro. Si alguno no responde, dilo en el reporte en vez de darlo por bueno.

- [ ] **Step 5: Report what could not be verified**

`curl` no ejecuta JavaScript, así que el comportamiento de DataTables y del SweetAlert (que el segundo select se habilite solo al elegir `Rechazado`, que el toast aparezca, que la tabla arranque filtrada en `Estudio`) **no queda verificado por esta tarea**. Dilo explícitamente en el reporte, señalando que necesita una pasada manual en navegador.

- [ ] **Step 6: Commit**

```bash
git add admin/planillas.php admin/inicio.php
git commit -m "feat(admin): bandeja de planillas con DataTables y cambio de estado por SweetAlert"
```

---

## Verificación final

Antes de dar el trabajo por terminado:

- [ ] `php tests/planillas_test.php` → `TODO OK`, y `0` filas `ZZ_TEST_PLANILLAS` restantes
- [ ] `php tests/admin_auth_test.php` y `php tests/admin_guard_test.php` → `TODO OK` (sin regresión del login)
- [ ] `php -l` limpio en los seis archivos PHP de `admin/` y en `sql/ddl_motivo_planillas.php`
- [ ] `git status` sin cambios fuera de `admin/`, `sql/`, `tests/` y `docs/`
- [ ] Ninguna fila de prueba (`ZZ_TEST_PLANILLAS`, `ZZ_TEST_ENDPOINT`) quedó en `consecutivo_planillas_aka`
- [ ] **Pendiente de Rafael, no automatizable:** una pasada manual en navegador sobre `http://localhost/plataforma_20/admin/planillas.php` — que la tabla cargue filtrada en `Estudio`, que el select de motivos se habilite solo al elegir `Rechazado`, y que un rechazo real quede guardado
- [ ] **Pendiente de Rafael:** entregar la lista definitiva de motivos para reemplazar `PLANILLA_MOTIVOS`
