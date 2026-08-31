# Módulo de Verificación (Auditoría de aliados) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar a Auditoría Interna un formulario dentro del portal para registrar, aliado por aliado, si las cifras de los cinco informes cuadran contra el ERP; guardarlo en INTEGRACION y comunicarlo por correo con un PDF adjunto.

**Architecture:** Lógica pura y testeable en `api/lib_verificacion.php` (mismo contrato que `api/lib_login.php` y `admin/lib_admin_auth.php`: no ejecuta nada al incluirse); tres endpoints JSON delgados que solo validan y delegan; una vista incluida por `dashboard.php`. Dos tablas en INTEGRACION (cabecera + detalle). **Armar el paquete y enviarlo son funciones separadas** porque el correo es irreversible.

**Tech Stack:** PHP 8 sobre XAMPP, SQL Server vía `sqlsrv`, PHPMailer (ya en el repo), FPDF (hay que traerlo a mano — no hay Composer), JS sin framework.

**Spec:** `docs/superpowers/specs/2026-08-31-verificacion-auditoria-design.md`

## Global Constraints

- **No hay Composer ni `vendor/`.** Toda librería se vendoriza a mano en el repo, como ya se hizo con `PHPMailer/src/`.
- **Prohibido modificar tablas SIESA** (`t***` en `stanton`/`Siesa_Cloud`). Todo lo nuevo vive en `INTEGRACION.dbo`.
- **`conexion/conexion_integracion.php` está en `.gitignore`** y define `$dbConnect`, `$servidor`, `$infoconn` por *filtrado de scope* al incluirse — no es una función. Incluirlo dentro de una función deja esas variables locales.
- **Toda escritura exige CSRF.** El portal ya genera `$_SESSION['csrf_token']` en `index.php:34`; se reusa ese, no se crea otro.
- **Los tests nunca envían correo.** Solo ejercitan `verif_armar_paquete()`. Las filas de prueba usan `proveedor = '__TEST__'`.
- **Enum de informes:** `g00`, `o14`, `o45`, `evol`, `geo`. **Enum de resultados:** `aprobado`, `no_aprobado`, `no_aplica`. Copiados literalmente del spec; los `CHECK` de la base y las constantes de PHP deben coincidir carácter por carácter.
- Cada test se corre con `php tests/<archivo>.php` y termina con `RESULTADO: OK` o `RESULTADO: FALLAN N`, saliendo con código ≠ 0 si falla (convención de `tests/admin_auth_test.php`).
- Los comentarios y mensajes de la interfaz van **en español**, como todo el repo.

---

### Task 1: Lógica pura de validación

Arranca por lo único que no necesita ni base de datos ni red: qué es un punto de control válido y cuándo una auditoría está completa. Sin esto, los endpoints no tienen qué llamar.

**Files:**
- Create: `api/lib_verificacion.php`
- Test: `tests/verificacion_validar_test.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `const VERIF_INFORMES` — `array<string,string>`, clave → nombre para mostrar. El orden define el orden en la vista y en el PDF.
  - `const VERIF_RESULTADOS` — `string[]`
  - `const VERIF_OBS_MAX` — `int` (1000, el largo de la columna)
  - `const VERIF_PROVEEDOR_TEST` — `string` (`'__TEST__'`)
  - `verif_validar_punto(string $informe, string $resultado, ?string $observacion): string` — cadena vacía si es válido, mensaje de error si no.
  - `verif_faltantes(array $marcados): array` — recibe las claves de informe ya marcadas, devuelve las que faltan, en el orden de `VERIF_INFORMES`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/verificacion_validar_test.php`:

```php
<?php
// Contrato de verif_validar_punto() y verif_faltantes().
//
// Corre en el servidor a proposito: los radios del navegador son una comodidad, no una
// barrera. Cualquiera puede mandar un POST a mano con el informe y el resultado que quiera.
//
//   php tests/verificacion_validar_test.php
//
// Puro: no toca base de datos ni red.

require_once __DIR__ . '/../api/lib_verificacion.php';

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "CONTRATO DE verif_validar_punto()\n" . str_repeat('=', 70) . "\n";

chequear('informe y resultado validos pasan',
    verif_validar_punto('g00', 'aprobado', null) === '');

chequear('observacion opcional en aprobado',
    verif_validar_punto('o14', 'aprobado', 'Cuadra con el ERP.') === '');

chequear('informe desconocido se rechaza',
    verif_validar_punto('pagos', 'aprobado', null) !== '');

chequear('resultado fuera del enum se rechaza',
    verif_validar_punto('g00', 'APROBADO', null) !== '',
    'el enum es sensible a mayusculas; la base tiene el mismo CHECK');

chequear('resultado inventado se rechaza',
    verif_validar_punto('g00', 'pendiente', null) !== '');

chequear('no_aprobado exige observacion',
    verif_validar_punto('g00', 'no_aprobado', '') !== '',
    'un hallazgo sin explicacion no sirve de nada a Auditoria');

chequear('no_aprobado con observacion pasa',
    verif_validar_punto('g00', 'no_aprobado', 'Marzo: ERP 45.489.517 vs portal 45.489.000') === '');

chequear('observacion en el limite pasa',
    verif_validar_punto('g00', 'aprobado', str_repeat('a', VERIF_OBS_MAX)) === '');

chequear('observacion pasada del limite se rechaza',
    verif_validar_punto('g00', 'aprobado', str_repeat('a', VERIF_OBS_MAX + 1)) !== '',
    'la columna es NVARCHAR(1000); truncar en silencio perderia el hallazgo');

echo "\nCONTRATO DE verif_faltantes()\n" . str_repeat('=', 70) . "\n";

chequear('sin nada marcado faltan los cinco',
    verif_faltantes([]) === array_keys(VERIF_INFORMES));

chequear('con cuatro marcados falta el quinto',
    verif_faltantes(['g00', 'o14', 'o45', 'evol']) === ['geo']);

chequear('con los cinco marcados no falta ninguno',
    verif_faltantes(['g00', 'o14', 'o45', 'evol', 'geo']) === []);

chequear('el orden de lo marcado no altera el resultado',
    verif_faltantes(['geo', 'g00']) === ['o14', 'o45', 'evol'],
    'la vista y el PDF listan siempre en el orden de VERIF_INFORMES');

echo "\n" . str_repeat('=', 70) . "\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
```

- [ ] **Step 2: Correr el test para verificar que falla**

```
php tests/verificacion_validar_test.php
```

Esperado: error fatal `Failed opening required '.../api/lib_verificacion.php'`.

- [ ] **Step 3: Escribir la implementación mínima**

Crear `api/lib_verificacion.php`:

```php
<?php
/**
 * Lógica del módulo de Verificación (auditoría de aliados).
 * Pura y testeable. Incluida por la vista, los endpoints y los tests.
 * NO ejecuta nada al incluirse (mismo contrato que api/lib_login.php).
 */

/**
 * Los cinco puntos de control, en el orden en que se muestran y se imprimen.
 * Las claves coinciden EXACTAMENTE con el CHECK de verificacion_auditoria_detalle.informe;
 * agregar un informe es agregar una entrada aquí y ampliar el CHECK, nada más.
 */
const VERIF_INFORMES = [
    'g00'  => 'Ventas',
    'o14'  => 'Siembra / Stock / Ventas',
    'o45'  => 'Índice de Ventas',
    'evol' => 'Evolución Histórica',
    'geo'  => 'Georreferenciación',
];

/** Coinciden con el CHECK de la columna resultado. Sensible a mayúsculas, como el CHECK. */
const VERIF_RESULTADOS = ['aprobado', 'no_aprobado', 'no_aplica'];

/** Largo de verificacion_auditoria_detalle.observacion. Se valida aquí para no truncar en la base. */
const VERIF_OBS_MAX = 1000;

/**
 * Proveedor centinela de los tests. verif_destinatarios() lo rechaza, así que una fila de
 * prueba es incapaz de producir un envío aunque alguien la empuje por el camino real.
 */
const VERIF_PROVEEDOR_TEST = '__TEST__';

/**
 * Valida un punto de control ANTES de tocar la base.
 *
 * @return string Cadena vacía si es válido; el mensaje de error si no.
 */
function verif_validar_punto(string $informe, string $resultado, ?string $observacion): string {
    if (!array_key_exists($informe, VERIF_INFORMES)) {
        return 'Informe no válido.';
    }
    if (!in_array($resultado, VERIF_RESULTADOS, true)) {
        return 'Resultado no válido.';
    }

    $obs = trim((string)$observacion);

    // Un "no aprobado" es un hallazgo de auditoría: sin explicación no le sirve a nadie
    // que lea el PDF tres meses después.
    if ($resultado === 'no_aprobado' && $obs === '') {
        return 'Debes explicar por qué no se aprueba.';
    }

    // Se mide en caracteres, no en bytes: la columna es NVARCHAR y las tildes cuentan
    // como uno. strlen() rechazaría observaciones válidas por llevar acentos.
    if (mb_strlen($obs) > VERIF_OBS_MAX) {
        return 'La observación no puede pasar de ' . VERIF_OBS_MAX . ' caracteres.';
    }

    return '';
}

/**
 * Qué informes quedan sin marcar.
 *
 * @param array $marcados Claves de informe ya registradas.
 * @return array Claves que faltan, en el orden de VERIF_INFORMES.
 */
function verif_faltantes(array $marcados): array {
    return array_values(array_diff(array_keys(VERIF_INFORMES), $marcados));
}
```

- [ ] **Step 4: Correr el test para verificar que pasa**

```
php tests/verificacion_validar_test.php
```

Esperado: `RESULTADO: OK`.

- [ ] **Step 5: Commit**

```bash
git add api/lib_verificacion.php tests/verificacion_validar_test.php
git commit -m "feat(verificacion): validacion de puntos de control y completitud"
```

---

### Task 2: Tablas y persistencia

**Files:**
- Create: `sql/008_verificacion_auditoria.sql`
- Modify: `api/lib_verificacion.php` (añadir funciones de persistencia)
- Test: `tests/verificacion_persistencia_test.php`

**Interfaces:**
- Consumes: `VERIF_INFORMES`, `VERIF_PROVEEDOR_TEST` de Task 1.
- Produces:
  - `verif_abrir($conn, string $proveedor, string $usuarioPortal, string $auditor): array` — `['id'=>int, 'auditor'=>string, 'nueva'=>bool]`. Si hay una `en_curso` para ese proveedor la recupera **conservando su auditor original** e ignorando `$auditor`; si no, crea una nueva.
  - `verif_guardar_punto($conn, int $auditoriaId, string $informe, string $resultado, ?string $obs): void`
  - `verif_cargar($conn, int $auditoriaId): ?array` — `['id','proveedor','usuario_portal','auditor','comentarios','estado','creado_en','cerrado_en','correo_enviado','puntos'=>[informe=>['resultado','observacion']]]`, o `null` si no existe.
  - `verif_cerrar($conn, int $auditoriaId, ?string $comentarios): bool` — `false` si ya estaba cerrada.
  - `verif_marcar_correo_enviado($conn, int $auditoriaId): void`

- [ ] **Step 1: Escribir el DDL**

Crear `sql/008_verificacion_auditoria.sql`:

```sql
-- ============================================================
-- Módulo de Verificación (auditoría de aliados) — 2026-08-31
-- Idempotente: la RDS es compartida (dev/staging/prod).
-- ============================================================
USE INTEGRACION;
GO

IF OBJECT_ID('dbo.verificacion_auditoria', 'U') IS NULL
CREATE TABLE dbo.verificacion_auditoria (
    id              INT IDENTITY(1,1) NOT NULL,
    proveedor       NVARCHAR(120)  NOT NULL,  -- aliado auditado ($_SESSION['proveedor'])
    usuario_portal  NVARCHAR(50)   NOT NULL,  -- credenciales con las que se entró
    auditor         NVARCHAR(120)  NOT NULL,  -- nombre escrito por el auditor
    comentarios     NVARCHAR(MAX)  NULL,
    estado          NVARCHAR(20)   NOT NULL CONSTRAINT DF_verif_estado DEFAULT 'en_curso',
    creado_en       DATETIME2      NOT NULL CONSTRAINT DF_verif_creado DEFAULT SYSDATETIME(),
    cerrado_en      DATETIME2      NULL,
    correo_enviado  BIT            NOT NULL CONSTRAINT DF_verif_correo DEFAULT 0,
    CONSTRAINT PK_verificacion_auditoria PRIMARY KEY (id),
    CONSTRAINT CK_verif_estado CHECK (estado IN ('en_curso','cerrada'))
);
GO

IF OBJECT_ID('dbo.verificacion_auditoria_detalle', 'U') IS NULL
CREATE TABLE dbo.verificacion_auditoria_detalle (
    id             INT IDENTITY(1,1) NOT NULL,
    auditoria_id   INT            NOT NULL,
    informe        NVARCHAR(10)   NOT NULL,
    resultado      NVARCHAR(20)   NOT NULL,
    observacion    NVARCHAR(1000) NULL,
    actualizado_en DATETIME2      NOT NULL CONSTRAINT DF_verif_det_act DEFAULT SYSDATETIME(),
    CONSTRAINT PK_verificacion_detalle PRIMARY KEY (id),
    CONSTRAINT FK_verificacion_detalle FOREIGN KEY (auditoria_id)
        REFERENCES dbo.verificacion_auditoria (id),
    CONSTRAINT CK_verif_det_informe   CHECK (informe   IN ('g00','o14','o45','evol','geo')),
    CONSTRAINT CK_verif_det_resultado CHECK (resultado IN ('aprobado','no_aprobado','no_aplica')),
    -- Es lo que hace que re-marcar un informe actualice en vez de duplicar.
    CONSTRAINT UQ_verificacion_detalle UNIQUE (auditoria_id, informe)
);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'idx_verificacion_proveedor')
CREATE INDEX idx_verificacion_proveedor
    ON dbo.verificacion_auditoria (proveedor, creado_en DESC);
GO
```

- [ ] **Step 2: Aplicar el DDL y verificar que las tablas existen**

Ejecutarlo con un script PHP de un solo uso (mismo patrón que `sql/ddl_motivo_planillas.php`), o pegarlo en SSMS. Después comprobar:

```
php -r "require 'conexion/conexion_integracion.php'; \
  \$s=sqlsrv_query(\$dbConnect,\"SELECT name FROM sys.tables WHERE name LIKE 'verificacion%' ORDER BY name\"); \
  while(\$r=sqlsrv_fetch_array(\$s,SQLSRV_FETCH_ASSOC)) echo \$r['name'],PHP_EOL;"
```

Esperado, exactamente estas dos líneas:
```
verificacion_auditoria
verificacion_auditoria_detalle
```

- [ ] **Step 3: Escribir el test que falla**

Crear `tests/verificacion_persistencia_test.php`. **La limpieza se registra ANTES de insertar nada**, para que un abort a media prueba no deje basura en una tabla viva:

```php
<?php
// Contrato de la persistencia de auditorías.
//
// El caso que más importa es el 4: re-marcar un informe ACTUALIZA, no duplica. Eso es lo
// que sostiene UQ_verificacion_detalle, y sin ello el PDF mostraría dos veces el mismo
// informe con resultados distintos.
//
//   php tests/verificacion_persistencia_test.php
//
// Escribe en la base, pero SOLO con proveedor '__TEST__' y limpia al terminar.

require_once __DIR__ . '/../conexion/conexion_integracion.php';
require_once __DIR__ . '/../api/lib_verificacion.php';

if ($dbConnect === false) {
    fwrite(STDERR, "No hay conexion a INTEGRACION; el test no puede correr.\n");
    exit(1);
}

// Limpieza a prueba de abortos: queda registrada ANTES de que exista la primera fila.
$creadas = [];
register_shutdown_function(function () use (&$creadas, $dbConnect) {
    foreach ($creadas as $id) {
        sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria_detalle WHERE auditoria_id = ?", [$id]);
        sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria WHERE id = ?", [$id]);
    }
});

// Barrido previo: una corrida abortada pudo dejar una auditoria '__TEST__' en curso, y
// verif_abrir() la recuperaria en vez de crear una nueva. El test fallaria sin que nada
// este roto. Se limpia antes de empezar para que la prueba sea repetible.
sqlsrv_query($dbConnect, "DELETE d FROM verificacion_auditoria_detalle d
    JOIN verificacion_auditoria a ON a.id = d.auditoria_id WHERE a.proveedor = ?", [VERIF_PROVEEDOR_TEST]);
sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria WHERE proveedor = ?", [VERIF_PROVEEDOR_TEST]);

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "PERSISTENCIA DE AUDITORIAS\n" . str_repeat('=', 70) . "\n";

// 1) Abrir crea una auditoria nueva
$a = verif_abrir($dbConnect, VERIF_PROVEEDOR_TEST, 'usr_test', 'Auditor Uno');
$creadas[] = $a['id'];
chequear('abrir crea una auditoria', $a['id'] > 0 && $a['nueva'] === true);

// 2) Abrir de nuevo recupera la misma y CONSERVA el auditor original
$b = verif_abrir($dbConnect, VERIF_PROVEEDOR_TEST, 'usr_test', 'Auditor Dos');
chequear('abrir de nuevo recupera la misma auditoria', $b['id'] === $a['id'] && $b['nueva'] === false);
chequear('el auditor original se conserva', $b['auditor'] === 'Auditor Uno',
    'una auditoria no puede acabar firmada por dos personas');

// 3) Guardar un punto
verif_guardar_punto($dbConnect, $a['id'], 'g00', 'aprobado', null);
$c = verif_cargar($dbConnect, $a['id']);
chequear('el punto guardado se lee', ($c['puntos']['g00']['resultado'] ?? '') === 'aprobado');

// 4) Re-marcar ACTUALIZA, no duplica
verif_guardar_punto($dbConnect, $a['id'], 'g00', 'no_aprobado', 'Marzo no cuadra.');
$c = verif_cargar($dbConnect, $a['id']);
chequear('re-marcar actualiza el resultado', $c['puntos']['g00']['resultado'] === 'no_aprobado');
chequear('re-marcar actualiza la observacion', $c['puntos']['g00']['observacion'] === 'Marzo no cuadra.');
$st = sqlsrv_query($dbConnect,
    "SELECT COUNT(*) AS n FROM verificacion_auditoria_detalle WHERE auditoria_id = ? AND informe = 'g00'",
    [$a['id']]);
$n = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)['n'];
chequear('re-marcar NO duplica la fila', $n === 1, "hay $n filas");

// 5) Las tildes sobreviven el viaje (CharacterSet UTF-8 + NVARCHAR)
verif_guardar_punto($dbConnect, $a['id'], 'o45', 'no_aprobado', 'Índice de Ventas: año 2025 sin señal.');
$c = verif_cargar($dbConnect, $a['id']);
chequear('las tildes sobreviven',
    $c['puntos']['o45']['observacion'] === 'Índice de Ventas: año 2025 sin señal.');

// 6) Completitud sobre datos reales: es la composicion exacta que corre el endpoint de
//    cierre (verif_cargar -> array_keys(puntos) -> verif_faltantes). Task 1 prueba
//    verif_faltantes con arreglos a mano; esto prueba que lo que sale de la base encaja.
$c = verif_cargar($dbConnect, $a['id']);
chequear('con dos informes marcados faltan los otros tres',
    verif_faltantes(array_keys($c['puntos'])) === ['o14', 'evol', 'geo']);

foreach (['o14', 'evol', 'geo'] as $clave) {
    verif_guardar_punto($dbConnect, $a['id'], $clave, 'aprobado', null);
}
$c = verif_cargar($dbConnect, $a['id']);
chequear('con los cinco marcados no falta ninguno',
    verif_faltantes(array_keys($c['puntos'])) === [],
    'es la condicion que el endpoint de cierre exige antes de enviar nada');

// 7) Cerrar
chequear('cerrar devuelve true la primera vez',
    verif_cerrar($dbConnect, $a['id'], 'Revisión completa.') === true);
$c = verif_cargar($dbConnect, $a['id']);
chequear('queda en estado cerrada', $c['estado'] === 'cerrada');
chequear('guarda los comentarios', $c['comentarios'] === 'Revisión completa.');
chequear('sella cerrado_en', !empty($c['cerrado_en']));
chequear('cerrar de nuevo devuelve false',
    verif_cerrar($dbConnect, $a['id'], 'otra vez') === false,
    'evita reenviar el correo de una auditoria ya cerrada');

// 8) Tras cerrar, abrir crea una NUEVA (es lo que produce el historial)
$d = verif_abrir($dbConnect, VERIF_PROVEEDOR_TEST, 'usr_test', 'Auditor Tres');
$creadas[] = $d['id'];
chequear('tras cerrar, abrir crea una nueva', $d['id'] !== $a['id'] && $d['nueva'] === true);
chequear('la nueva toma el auditor nuevo', $d['auditor'] === 'Auditor Tres');

// 9) Auditoria inexistente
chequear('cargar una auditoria inexistente devuelve null',
    verif_cargar($dbConnect, 0) === null);

echo "\n" . str_repeat('=', 70) . "\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
```

- [ ] **Step 4: Correr el test para verificar que falla**

```
php tests/verificacion_persistencia_test.php
```

Esperado: `Call to undefined function verif_abrir()`.

- [ ] **Step 5: Escribir la implementación**

Añadir al final de `api/lib_verificacion.php`:

```php
/**
 * Abre una auditoría para el aliado, o recupera la que esté en curso.
 *
 * Si ya hay una 'en_curso' se devuelve esa y se IGNORA $auditor: la auditoría conserva
 * quien la abrió, para que no termine firmada por dos personas. Cuando la última está
 * 'cerrada' se abre una nueva — eso es lo que produce el historial por aliado.
 *
 * @return array{id:int, auditor:string, nueva:bool}
 */
function verif_abrir($conn, string $proveedor, string $usuarioPortal, string $auditor): array {
    $sql = "SELECT TOP 1 id, auditor FROM verificacion_auditoria
            WHERE proveedor = ? AND estado = 'en_curso'
            ORDER BY id DESC";
    $stmt = sqlsrv_query($conn, $sql, [$proveedor]);
    if ($stmt === false) {
        throw new RuntimeException('Buscar auditoría en curso falló: ' . print_r(sqlsrv_errors(), true));
    }
    $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if ($fila) {
        return ['id' => (int)$fila['id'], 'auditor' => (string)$fila['auditor'], 'nueva' => false];
    }

    // SCOPE_IDENTITY() y no @@IDENTITY: @@IDENTITY devolvería el id que generara un
    // trigger en otra tabla, no el nuestro.
    $ins = "INSERT INTO verificacion_auditoria (proveedor, usuario_portal, auditor)
            VALUES (?, ?, ?);
            SELECT CAST(SCOPE_IDENTITY() AS INT) AS id;";
    $stmt = sqlsrv_query($conn, $ins, [$proveedor, $usuarioPortal, $auditor]);
    if ($stmt === false) {
        throw new RuntimeException('Crear auditoría falló: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_next_result($stmt);              // saltar del INSERT al SELECT
    $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return ['id' => (int)$r['id'], 'auditor' => $auditor, 'nueva' => true];
}

/**
 * Guarda un punto de control. Re-marcar el mismo informe actualiza la fila existente.
 *
 * HOLDLOCK no es decorativo: sin él, dos MERGE simultáneos sobre la misma llave pueden
 * pasar los dos por WHEN NOT MATCHED y el segundo INSERT revienta contra UQ_verificacion_detalle.
 */
function verif_guardar_punto($conn, int $auditoriaId, string $informe, string $resultado, ?string $obs): void {
    $o = ($obs === null || trim($obs) === '') ? null : trim($obs);

    $sql = "MERGE verificacion_auditoria_detalle WITH (HOLDLOCK) AS destino
            USING (SELECT ? AS auditoria_id, ? AS informe) AS origen
                ON destino.auditoria_id = origen.auditoria_id
               AND destino.informe      = origen.informe
            WHEN MATCHED THEN
                UPDATE SET resultado = ?, observacion = ?, actualizado_en = SYSDATETIME()
            WHEN NOT MATCHED THEN
                INSERT (auditoria_id, informe, resultado, observacion)
                VALUES (origen.auditoria_id, origen.informe, ?, ?);";

    $stmt = sqlsrv_query($conn, $sql, [$auditoriaId, $informe, $resultado, $o, $resultado, $o]);
    if ($stmt === false) {
        throw new RuntimeException('Guardar punto falló: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);
}

/**
 * La auditoría completa con sus puntos, o null si no existe.
 *
 * Los DATETIME2 vuelven de sqlsrv como objetos DateTime; se formatean aquí para que
 * quien consuma (JSON, PDF, correo) reciba cadenas y no tenga que saberlo.
 */
function verif_cargar($conn, int $auditoriaId): ?array {
    $sql = "SELECT id, proveedor, usuario_portal, auditor, comentarios, estado,
                   creado_en, cerrado_en, correo_enviado
            FROM verificacion_auditoria WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$auditoriaId]);
    if ($stmt === false) {
        throw new RuntimeException('Cargar auditoría falló: ' . print_r(sqlsrv_errors(), true));
    }
    $cab = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if (!$cab) return null;

    $fmt = function ($d) { return $d instanceof DateTime ? $d->format('Y-m-d H:i:s') : null; };

    $sqlDet = "SELECT informe, resultado, observacion
               FROM verificacion_auditoria_detalle WHERE auditoria_id = ?";
    $stmt = sqlsrv_query($conn, $sqlDet, [$auditoriaId]);
    if ($stmt === false) {
        throw new RuntimeException('Cargar puntos falló: ' . print_r(sqlsrv_errors(), true));
    }
    $puntos = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $puntos[$r['informe']] = [
            'resultado'   => (string)$r['resultado'],
            'observacion' => $r['observacion'] !== null ? (string)$r['observacion'] : null,
        ];
    }
    sqlsrv_free_stmt($stmt);

    return [
        'id'             => (int)$cab['id'],
        'proveedor'      => (string)$cab['proveedor'],
        'usuario_portal' => (string)$cab['usuario_portal'],
        'auditor'        => (string)$cab['auditor'],
        'comentarios'    => $cab['comentarios'] !== null ? (string)$cab['comentarios'] : null,
        'estado'         => (string)$cab['estado'],
        'creado_en'      => $fmt($cab['creado_en']),
        'cerrado_en'     => $fmt($cab['cerrado_en']),
        'correo_enviado' => (bool)$cab['correo_enviado'],
        'puntos'         => $puntos,
    ];
}

/**
 * Cierra la auditoría. Devuelve false si ya estaba cerrada.
 *
 * La condición estado='en_curso' viaja DENTRO del WHERE, no en un if previo: es la
 * barrera atómica que impide que dos peticiones simultáneas cierren la misma auditoría
 * y disparen dos correos.
 */
function verif_cerrar($conn, int $auditoriaId, ?string $comentarios): bool {
    $c = ($comentarios === null || trim($comentarios) === '') ? null : trim($comentarios);

    $sql = "UPDATE verificacion_auditoria
            SET estado = 'cerrada', comentarios = ?, cerrado_en = SYSDATETIME()
            WHERE id = ? AND estado = 'en_curso'";
    $stmt = sqlsrv_query($conn, $sql, [$c, $auditoriaId]);
    if ($stmt === false) {
        throw new RuntimeException('Cerrar auditoría falló: ' . print_r(sqlsrv_errors(), true));
    }
    $filas = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);
    return $filas === 1;
}

/** Deja constancia de que el correo salió. Se separa del cierre a propósito. */
function verif_marcar_correo_enviado($conn, int $auditoriaId): void {
    $stmt = sqlsrv_query($conn, "UPDATE verificacion_auditoria SET correo_enviado = 1 WHERE id = ?", [$auditoriaId]);
    if ($stmt === false) {
        throw new RuntimeException('Marcar correo falló: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);
}
```

- [ ] **Step 6: Correr los dos tests**

```
php tests/verificacion_validar_test.php && php tests/verificacion_persistencia_test.php
```

Esperado: `RESULTADO: OK` en ambos.

- [ ] **Step 7: Verificar que el test no dejó basura**

```
php -r "require 'conexion/conexion_integracion.php'; \
  \$s=sqlsrv_query(\$dbConnect,\"SELECT COUNT(*) n FROM verificacion_auditoria WHERE proveedor='__TEST__'\"); \
  echo 'filas de prueba restantes: ', sqlsrv_fetch_array(\$s,SQLSRV_FETCH_ASSOC)['n'], PHP_EOL;"
```

Esperado: `filas de prueba restantes: 0`.

- [ ] **Step 8: Commit**

```bash
git add sql/008_verificacion_auditoria.sql api/lib_verificacion.php tests/verificacion_persistencia_test.php
git commit -m "feat(verificacion): tablas en INTEGRACION y persistencia de auditorias"
```

---

### Task 3: Destinatarios y armado del paquete

Aquí se materializa la separación que protege del envío accidental. **Ninguna función de esta tarea abre una conexión de red.**

**Files:**
- Modify: `api/lib_verificacion.php`
- Modify: `conexion/config_mail.example.php` (documentar la constante nueva)
- Test: `tests/verificacion_paquete_test.php`

**Interfaces:**
- Consumes: `verif_cargar()`, `VERIF_PROVEEDOR_TEST`, `VERIF_INFORMES` de Tasks 1-2.
- Produces:
  - `verif_destinatarios(string $proveedor): array` — correos a los que va el aviso. Devuelve `[]` para `VERIF_PROVEEDOR_TEST`.
  - `verif_armar_paquete($conn, int $auditoriaId): array` — `['auditoria'=>array, 'asunto'=>string, 'cuerpo_html'=>string, 'nombre_pdf'=>string, 'filas'=>array]`. `filas` es la tabla ya lista para el PDF: una entrada por informe **en el orden de `VERIF_INFORMES`**, con `['clave','nombre','resultado','etiqueta','observacion']`.
  - `verif_etiqueta(string $resultado): string` — `'Aprobado' | 'No aprobado' | 'No aplica'`.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/verificacion_paquete_test.php`:

```php
<?php
// Contrato del armado del paquete y de los destinatarios.
//
// Este test existe sobre todo por una razon: demostrar que armar el paquete NO envia nada.
// El correo es irreversible y los tests corren antes que cualquier validacion, asi que la
// unica defensa real es que el dato de prueba sea incapaz de producir un envio: el
// proveedor '__TEST__' no resuelve a ningun destinatario.
//
//   php tests/verificacion_paquete_test.php

require_once __DIR__ . '/../conexion/conexion_integracion.php';
require_once __DIR__ . '/../api/lib_verificacion.php';

if ($dbConnect === false) {
    fwrite(STDERR, "No hay conexion a INTEGRACION; el test no puede correr.\n");
    exit(1);
}

$creadas = [];
register_shutdown_function(function () use (&$creadas, $dbConnect) {
    foreach ($creadas as $id) {
        sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria_detalle WHERE auditoria_id = ?", [$id]);
        sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria WHERE id = ?", [$id]);
    }
});

// Barrido previo (ver verificacion_persistencia_test.php): sin esto, una auditoria
// '__TEST__' en curso de una corrida abortada se recupera en vez de crearse una nueva.
sqlsrv_query($dbConnect, "DELETE d FROM verificacion_auditoria_detalle d
    JOIN verificacion_auditoria a ON a.id = d.auditoria_id WHERE a.proveedor = ?", [VERIF_PROVEEDOR_TEST]);
sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria WHERE proveedor = ?", [VERIF_PROVEEDOR_TEST]);

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "DESTINATARIOS\n" . str_repeat('=', 70) . "\n";

chequear('el proveedor centinela no resuelve a nadie',
    verif_destinatarios(VERIF_PROVEEDOR_TEST) === [],
    'es lo que hace imposible que una fila de prueba produzca un envio');

echo "\nARMADO DEL PAQUETE\n" . str_repeat('=', 70) . "\n";

$a = verif_abrir($dbConnect, VERIF_PROVEEDOR_TEST, 'usr_test', 'Auditor Uno');
$creadas[] = $a['id'];
verif_guardar_punto($dbConnect, $a['id'], 'g00',  'aprobado',    null);
verif_guardar_punto($dbConnect, $a['id'], 'o14',  'aprobado',    null);
verif_guardar_punto($dbConnect, $a['id'], 'o45',  'no_aplica',   'El aliado no maneja este informe.');
verif_guardar_punto($dbConnect, $a['id'], 'evol', 'no_aprobado', 'Marzo: ERP 45.489.517 vs portal 45.489.000');
verif_guardar_punto($dbConnect, $a['id'], 'geo',  'aprobado',    null);
verif_cerrar($dbConnect, $a['id'], 'Sin novedades adicionales.');

$p = verif_armar_paquete($dbConnect, $a['id']);

chequear('el paquete lleva los cinco puntos', count($p['filas']) === 5);
chequear('las filas van en el orden de VERIF_INFORMES',
    array_column($p['filas'], 'clave') === array_keys(VERIF_INFORMES),
    'el PDF y la vista deben coincidir informe por informe');
chequear('el asunto nombra al aliado auditado',
    strpos($p['asunto'], VERIF_PROVEEDOR_TEST) !== false);
chequear('el cuerpo nombra al auditor',
    strpos($p['cuerpo_html'], 'Auditor Uno') !== false);
chequear('el nombre del PDF no lleva caracteres de ruta',
    !preg_match('#[/\\\\]#', $p['nombre_pdf']), $p['nombre_pdf']);

$evol = null;
foreach ($p['filas'] as $f) if ($f['clave'] === 'evol') $evol = $f;
chequear('la etiqueta se traduce a texto legible', $evol['etiqueta'] === 'No aprobado');
chequear('la observacion viaja completa',
    $evol['observacion'] === 'Marzo: ERP 45.489.517 vs portal 45.489.000');

chequear('un informe sin marcar sale como vacio, no revienta',
    verif_etiqueta('') === '—' || verif_etiqueta('') === '-');

echo "\n" . str_repeat('=', 70) . "\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
```

- [ ] **Step 2: Correr el test para verificar que falla**

```
php tests/verificacion_paquete_test.php
```

Esperado: `Call to undefined function verif_destinatarios()`.

- [ ] **Step 3: Escribir la implementación**

Añadir al final de `api/lib_verificacion.php`:

```php
/**
 * A quién se avisa cuando se cierra una auditoría.
 *
 * El proveedor centinela devuelve lista vacía SIEMPRE, y verif_enviar() trata la lista
 * vacía como error. Así una fila de prueba es incapaz de producir un envío aunque alguien
 * la empuje por el camino de producción: no depende de recordar apagar ninguna bandera.
 *
 * MAIL_AUDITORIA_TO se define en conexion/config_mail.php (gitignored).
 */
function verif_destinatarios(string $proveedor): array {
    if ($proveedor === VERIF_PROVEEDOR_TEST) return [];
    if (!defined('MAIL_AUDITORIA_TO')) return [];
    return array_values(array_filter(array_map('trim', (array)MAIL_AUDITORIA_TO)));
}

/** El resultado, en el texto que ve una persona. Cadena vacía = sin marcar. */
function verif_etiqueta(string $resultado): string {
    switch ($resultado) {
        case 'aprobado':    return 'Aprobado';
        case 'no_aprobado': return 'No aprobado';
        case 'no_aplica':   return 'No aplica';
        default:            return '—';
    }
}

/**
 * Compone todo lo que hace falta para comunicar una auditoría. NO ENVÍA NADA.
 *
 * Existe separada de verif_enviar() a propósito: el envío es irreversible, así que lo
 * que ejercitan los tests es solo esta mitad.
 *
 * @return array{auditoria:array, asunto:string, cuerpo_html:string, nombre_pdf:string, filas:array}
 */
function verif_armar_paquete($conn, int $auditoriaId): array {
    $a = verif_cargar($conn, $auditoriaId);
    if ($a === null) {
        throw new RuntimeException('La auditoría ' . $auditoriaId . ' no existe.');
    }

    // Se recorre VERIF_INFORMES y no lo que trajo la base: así el orden es estable y un
    // informe sin marcar aparece igual, en su sitio, en vez de desaparecer de la tabla.
    $filas = [];
    foreach (VERIF_INFORMES as $clave => $nombre) {
        $p = $a['puntos'][$clave] ?? null;
        $filas[] = [
            'clave'       => $clave,
            'nombre'      => $nombre,
            'resultado'   => $p['resultado'] ?? '',
            'etiqueta'    => verif_etiqueta($p['resultado'] ?? ''),
            'observacion' => $p['observacion'] ?? null,
        ];
    }

    $fecha = substr((string)($a['cerrado_en'] ?? $a['creado_en']), 0, 10);

    // El nombre del adjunto se arma con el proveedor, que viene de la base: se le quita
    // todo lo que no sea alfanumérico para que no pueda colarse un separador de ruta.
    $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $a['proveedor']);
    $slug = trim((string)$slug, '_');
    if ($slug === '') $slug = 'aliado';

    $cuerpo = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#2d2b4e;">'
        . '<p>Se diligenció el formulario de control de la Plataforma AKA 2.0.</p>'
        . '<table cellpadding="6" style="border-collapse:collapse;font-size:14px;">'
        . '<tr><td><b>Tercero auditado</b></td><td>' . htmlspecialchars($a['proveedor'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td><b>Auditor</b></td><td>' . htmlspecialchars($a['auditor'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td><b>Usuario del portal</b></td><td>' . htmlspecialchars($a['usuario_portal'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td><b>Fecha</b></td><td>' . htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '</table>'
        . '<p>El detalle de los puntos de control y sus observaciones va en el PDF adjunto.</p>'
        . '</div>';

    return [
        'auditoria'   => $a,
        'asunto'      => 'VERIFICACIÓN DE PLATAFORMA — ' . $a['proveedor'] . ' — ' . $fecha,
        'cuerpo_html' => $cuerpo,
        'nombre_pdf'  => 'verificacion_' . $slug . '_' . str_replace('-', '', $fecha) . '.pdf',
        'filas'       => $filas,
    ];
}
```

- [ ] **Step 4: Documentar la constante en la plantilla de configuración**

Añadir al final de `conexion/config_mail.example.php`:

```php
// Destinatarios del aviso de Verificación (auditoría de aliados).
// Lista vacía = verif_enviar() falla con error explícito: si se despliega sin configurar,
// se nota de inmediato en vez de enviar a nadie en silencio.
define('MAIL_AUDITORIA_TO', ['REEMPLAZAR@stanton.co']);
```

Y añadir la misma línea al `conexion/config_mail.php` real (que no se versiona), con la lista vacía `[]` mientras Rafael no indique los correos.

- [ ] **Step 5: Correr el test para verificar que pasa**

```
php tests/verificacion_paquete_test.php
```

Esperado: `RESULTADO: OK`.

- [ ] **Step 6: Commit**

```bash
git add api/lib_verificacion.php conexion/config_mail.example.php tests/verificacion_paquete_test.php
git commit -m "feat(verificacion): armado del paquete separado del envio"
```

---

### Task 4: PDF

**Files:**
- Create: `fpdf/fpdf.php` (vendorizado)
- Create: `api/lib_verificacion_pdf.php`
- Test: `tests/verificacion_pdf_test.php`

**Interfaces:**
- Consumes: `verif_armar_paquete()` de Task 3.
- Produces: `verif_pdf(array $paquete, string $rutaSalida): string` — escribe el PDF y devuelve la ruta.

- [ ] **Step 1: Vendorizar FPDF**

Descargar FPDF 1.86 de `http://www.fpdf.org/` y dejar `fpdf.php` en `fpdf/`. Es un archivo único sin dependencias. Comprobar:

```
php -r "require 'fpdf/fpdf.php'; \$p=new FPDF(); \$p->AddPage(); \$p->SetFont('Helvetica','',12); \$p->Cell(0,10,'ok'); echo 'FPDF ', FPDF_VERSION, ' cargado', PHP_EOL;"
```

Esperado: `FPDF 1.86 cargado`.

- [ ] **Step 2: Escribir el test que falla**

Crear `tests/verificacion_pdf_test.php`:

```php
<?php
// Contrato del PDF de verificacion.
//
// Lo que mas importa aqui es que una observacion larga NO se recorte: un hallazgo de
// auditoria truncado es peor que una tabla fea. Por eso el caso 4 mete un texto largo
// y comprueba que el PDF crece de alto en vez de quedarse igual.
//
//   php tests/verificacion_pdf_test.php
//
// Escribe un PDF temporal y lo borra. No toca la red.

require_once __DIR__ . '/../conexion/conexion_integracion.php';
require_once __DIR__ . '/../api/lib_verificacion.php';
require_once __DIR__ . '/../api/lib_verificacion_pdf.php';

if ($dbConnect === false) {
    fwrite(STDERR, "No hay conexion a INTEGRACION; el test no puede correr.\n");
    exit(1);
}

$creadas = [];
$temporales = [];
register_shutdown_function(function () use (&$creadas, &$temporales, $dbConnect) {
    foreach ($creadas as $id) {
        sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria_detalle WHERE auditoria_id = ?", [$id]);
        sqlsrv_query($dbConnect, "DELETE FROM verificacion_auditoria WHERE id = ?", [$id]);
    }
    foreach ($temporales as $f) if (is_file($f)) @unlink($f);
});

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

function armar(array $puntos, string $comentarios, $conn, array &$creadas): array {
    $a = verif_abrir($conn, VERIF_PROVEEDOR_TEST, 'usr_test', 'Auditor de Prueba');
    $creadas[] = $a['id'];
    foreach ($puntos as $clave => $v) {
        verif_guardar_punto($conn, $a['id'], $clave, $v[0], $v[1]);
    }
    verif_cerrar($conn, $a['id'], $comentarios);
    return verif_armar_paquete($conn, $a['id']);
}

echo "PDF DE VERIFICACION\n" . str_repeat('=', 70) . "\n";

// 1) Caso corto
$corto = armar([
    'g00'  => ['aprobado', null],
    'o14'  => ['aprobado', null],
    'o45'  => ['no_aplica', 'No lo maneja.'],
    'evol' => ['no_aprobado', 'Marzo no cuadra.'],
    'geo'  => ['aprobado', null],
], 'Sin novedades.', $dbConnect, $creadas);

$f1 = sys_get_temp_dir() . '/verif_corto.pdf';
$temporales[] = $f1;
verif_pdf($corto, $f1);

chequear('el archivo se crea', is_file($f1));
chequear('es un PDF de verdad', strncmp((string)file_get_contents($f1, false, null, 0, 5), '%PDF-', 5) === 0);
chequear('no esta vacio', filesize($f1) > 1000, filesize($f1) . ' bytes');

// 2) Caso con observaciones largas: el PDF debe CRECER, no recortar
$largo = armar([
    'g00'  => ['no_aprobado', str_repeat('Diferencia detectada contra el ERP. ', 25)],
    'o14'  => ['no_aprobado', str_repeat('Stock por tienda no coincide. ', 25)],
    'o45'  => ['no_aprobado', str_repeat('Indice fuera de rango. ', 25)],
    'evol' => ['no_aprobado', str_repeat('Serie mensual con huecos. ', 25)],
    'geo'  => ['no_aprobado', str_repeat('Tiendas sin coordenadas. ', 25)],
], str_repeat('Comentario general extenso. ', 30), $dbConnect, $creadas);

$f2 = sys_get_temp_dir() . '/verif_largo.pdf';
$temporales[] = $f2;
verif_pdf($largo, $f2);

chequear('el PDF con texto largo tambien es valido',
    strncmp((string)file_get_contents($f2, false, null, 0, 5), '%PDF-', 5) === 0);
chequear('el PDF largo pesa mas que el corto',
    filesize($f2) > filesize($f1),
    filesize($f1) . ' -> ' . filesize($f2) . ' bytes; si fueran iguales el texto se estaria recortando');

// 3) Las tildes no rompen el PDF (FPDF trabaja en ISO-8859-1; hay que convertir)
$tildes = armar([
    'g00'  => ['aprobado', 'Año 2026: señal correcta. Índice OK.'],
    'o14'  => ['aprobado', null],
    'o45'  => ['aprobado', null],
    'evol' => ['aprobado', null],
    'geo'  => ['aprobado', null],
], 'Revisión con tildes: ñ, á, é, í, ó, ú, ü.', $dbConnect, $creadas);

$f3 = sys_get_temp_dir() . '/verif_tildes.pdf';
$temporales[] = $f3;
verif_pdf($tildes, $f3);
chequear('las tildes no rompen la generacion',
    is_file($f3) && strncmp((string)file_get_contents($f3, false, null, 0, 5), '%PDF-', 5) === 0);

echo "\n" . str_repeat('=', 70) . "\n";
echo "Para MIRARLOS antes de dar esto por bueno:\n";
echo "  $f1\n  $f2\n  $f3\n";
echo "(se borran al terminar; comenta el unlink del shutdown si necesitas conservarlos)\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
```

- [ ] **Step 3: Correr el test para verificar que falla**

```
php tests/verificacion_pdf_test.php
```

Esperado: `Failed opening required '.../api/lib_verificacion_pdf.php'`.

- [ ] **Step 4: Escribir la implementación**

Crear `api/lib_verificacion_pdf.php`:

```php
<?php
/**
 * Construcción del PDF de verificación sobre FPDF.
 * Separado de lib_verificacion.php porque es presentación pura: cambiar el diseño del
 * documento no debería obligar a releer la lógica de la auditoría.
 */
require_once __DIR__ . '/../fpdf/fpdf.php';

/**
 * FPDF trabaja en ISO-8859-1 con las fuentes básicas. El texto del portal es UTF-8, así
 * que hay que convertirlo o las tildes salen como basura. Lo que no exista en el destino
 * se translitera en vez de desaparecer.
 */
function verif_pdf_txt(?string $s): string {
    $s = (string)$s;
    $conv = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
    return $conv === false ? $s : $conv;
}

/**
 * Color de fondo de la celda de resultado.
 * El color acompaña, nunca sustituye: la palabra siempre está escrita, porque este PDF se
 * imprime en blanco y negro para archivo físico.
 */
function verif_pdf_color(string $resultado): array {
    switch ($resultado) {
        case 'aprobado':    return [223, 245, 231];
        case 'no_aprobado': return [252, 226, 226];
        case 'no_aplica':   return [238, 238, 238];
        default:            return [255, 255, 255];
    }
}

/**
 * Escribe el PDF de una auditoría.
 *
 * @param array  $paquete     Lo que devuelve verif_armar_paquete().
 * @param string $rutaSalida  Dónde escribirlo.
 * @return string La misma ruta.
 */
function verif_pdf(array $paquete, string $rutaSalida): string {
    $a = $paquete['auditoria'];

    $pdf = new FPDF('P', 'mm', 'LETTER');
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    // --- Encabezado ---
    $logo = __DIR__ . '/../img/logo_aka.png';
    if (is_file($logo)) $pdf->Image($logo, 15, 12, 28);

    $pdf->SetXY(50, 14);
    $pdf->SetFont('Helvetica', 'B', 15);
    $pdf->SetTextColor(74, 71, 130);            // var(--primary) del portal
    $pdf->Cell(0, 8, verif_pdf_txt('VERIFICACIÓN DE PLATAFORMA'), 0, 1);
    $pdf->SetX(50);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(0, 6, verif_pdf_txt('Portal de Aliados AKA 2.0 — Control de Auditoría Interna'), 0, 1);

    $pdf->Ln(10);
    $pdf->SetTextColor(40, 40, 40);

    // --- Ficha ---
    $ficha = [
        'Tercero auditado'  => $a['proveedor'],
        'Auditor'           => $a['auditor'],
        'Usuario del portal'=> $a['usuario_portal'],
        'Fecha de cierre'   => (string)($a['cerrado_en'] ?? $a['creado_en']),
        'Registro N.'       => (string)$a['id'],
    ];
    foreach ($ficha as $etq => $val) {
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(42, 6, verif_pdf_txt($etq), 0, 0);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(0, 6, verif_pdf_txt($val), 0, 1);
    }

    $pdf->Ln(6);

    // --- Tabla ---
    $anchos = ['informe' => 55, 'resultado' => 30, 'observacion' => 101];

    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetFillColor(74, 71, 130);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($anchos['informe'],     8, verif_pdf_txt(' Informe'),     1, 0, 'L', true);
    $pdf->Cell($anchos['resultado'],   8, verif_pdf_txt('Resultado'),    1, 0, 'C', true);
    $pdf->Cell($anchos['observacion'], 8, verif_pdf_txt(' Observación'), 1, 1, 'L', true);
    $pdf->SetTextColor(40, 40, 40);

    foreach ($paquete['filas'] as $f) {
        // El alto de la fila lo manda la observación: se mide cuántas líneas ocupa y la
        // fila crece. Recortar aquí perdería el hallazgo, que es justo el dato valioso.
        $pdf->SetFont('Helvetica', '', 8);
        $obs = verif_pdf_txt($f['observacion'] ?? '');
        $lineas = $obs === '' ? 1 : max(1, count(verif_pdf_partir($pdf, $obs, $anchos['observacion'] - 4)));
        $alto = max(9, $lineas * 4.2 + 3);

        // Salto de página manual: si la fila no cabe entera, se abre página y se repite
        // el encabezado, para que ninguna fila quede partida entre dos hojas.
        if ($pdf->GetY() + $alto > 260) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetFillColor(74, 71, 130);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell($anchos['informe'],     8, verif_pdf_txt(' Informe'),     1, 0, 'L', true);
            $pdf->Cell($anchos['resultado'],   8, verif_pdf_txt('Resultado'),    1, 0, 'C', true);
            $pdf->Cell($anchos['observacion'], 8, verif_pdf_txt(' Observación'), 1, 1, 'L', true);
            $pdf->SetTextColor(40, 40, 40);
            $pdf->SetFont('Helvetica', '', 8);
        }

        $x = $pdf->GetX();
        $y = $pdf->GetY();

        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->MultiCell($anchos['informe'], $alto, verif_pdf_txt(' ' . $f['nombre']), 1, 'L');

        $rgb = verif_pdf_color($f['resultado']);
        $pdf->SetXY($x + $anchos['informe'], $y);
        $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->MultiCell($anchos['resultado'], $alto, verif_pdf_txt($f['etiqueta']), 1, 'C', true);

        $pdf->SetXY($x + $anchos['informe'] + $anchos['resultado'], $y);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->MultiCell($anchos['observacion'], $alto, verif_pdf_txt('  ' . ($f['observacion'] ?? '')), 1, 'L');

        $pdf->SetXY($x, $y + $alto);
    }

    // --- Comentarios generales ---
    if (!empty($a['comentarios'])) {
        $pdf->Ln(8);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(0, 6, verif_pdf_txt('Comentarios generales'), 0, 1);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->MultiCell(0, 4.6, verif_pdf_txt($a['comentarios']), 1, 'L');
    }

    // --- Pie ---
    $pdf->Ln(8);
    $pdf->SetFont('Helvetica', 'I', 7);
    $pdf->SetTextColor(130, 130, 130);
    $pdf->Cell(0, 5, verif_pdf_txt('Generado automáticamente por la Plataforma AKA 2.0 el '
        . date('d/m/Y H:i') . '. Registro N. ' . $a['id'] . '.'), 0, 1, 'C');

    $pdf->Output('F', $rutaSalida);
    return $rutaSalida;
}

/**
 * En cuántas líneas parte FPDF un texto para un ancho dado.
 * FPDF no lo expone, así que se mide con GetStringWidth palabra por palabra: es lo que
 * permite calcular el alto de la fila ANTES de dibujarla.
 */
function verif_pdf_partir(FPDF $pdf, string $texto, float $ancho): array {
    $lineas = [];
    foreach (explode("\n", $texto) as $parrafo) {
        $actual = '';
        foreach (explode(' ', $parrafo) as $palabra) {
            $prueba = $actual === '' ? $palabra : $actual . ' ' . $palabra;
            if ($pdf->GetStringWidth($prueba) > $ancho && $actual !== '') {
                $lineas[] = $actual;
                $actual = $palabra;
            } else {
                $actual = $prueba;
            }
        }
        $lineas[] = $actual;
    }
    return $lineas;
}
```

- [ ] **Step 5: Correr el test para verificar que pasa**

```
php tests/verificacion_pdf_test.php
```

Esperado: `RESULTADO: OK`, y que el PDF largo pese más que el corto.

- [ ] **Step 6: MIRAR los tres PDF**

Comentar temporalmente el `@unlink` del `register_shutdown_function`, volver a correr el test y **abrir los tres archivos**. Un PDF que pasa los asserts puede seguir siendo ilegible; esto no es opcional.

Qué revisar:
- El logo no pisa el título ni se sale del margen.
- Los encabezados de la tabla quedan alineados con sus columnas.
- En `verif_largo.pdf`, las observaciones largas **se leen completas**, la fila creció, y ninguna fila quedó partida entre dos páginas.
- En `verif_tildes.pdf`, `ñ á é í ó ú ü` salen bien y no como `?` ni como basura.
- Impreso en blanco y negro, el resultado sigue siendo legible: la palabra está escrita, no solo el color.

Volver a poner el `@unlink` cuando termine.

- [ ] **Step 7: Commit**

```bash
git add fpdf/ api/lib_verificacion_pdf.php tests/verificacion_pdf_test.php
git commit -m "feat(verificacion): PDF de la auditoria con FPDF"
```

---

### Task 5: Endpoints

**Files:**
- Create: `api/verificacion_abrir.php`, `api/verificacion_guardar.php`, `api/verificacion_cerrar.php`
- Test: `tests/verificacion_guard_test.php`

**Interfaces:**
- Consumes: todo `api/lib_verificacion.php`.
- Produces: contrato HTTP que consume la vista de Task 6.
  - `POST verificacion_abrir.php` `{csrf_token, auditor}` → `{ok, id, auditor, nueva, puntos, estado}`
  - `POST verificacion_guardar.php` `{csrf_token, auditoria_id, informe, resultado, observacion}` → `{ok}`
  - `POST verificacion_cerrar.php` `{csrf_token, auditoria_id, comentarios}` → `{ok, correo_enviado, aviso?}` o 422 `{ok:false, faltantes:[...]}`

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/verificacion_guard_test.php`. Golpea los endpoints por HTTP sin sesión y sin CSRF:

```php
<?php
// Los tres endpoints de verificacion deben rechazar a quien no tiene sesion (401) y a quien
// no trae CSRF (403), ANTES de tocar la base. Un endpoint de escritura sin guard propio es
// una puerta abierta aunque la seccion este escondida detras de ?auditoria=1: el parametro
// es visibilidad, no seguridad.
//
//   php tests/verificacion_guard_test.php
//
// Requiere Apache corriendo en localhost.

$base = 'http://localhost/plataforma_20/api/';

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

function postear(string $url, array $campos): int {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($campos),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $codigo;
}

echo "GUARDS DE LOS ENDPOINTS DE VERIFICACION\n" . str_repeat('=', 70) . "\n";

foreach (['verificacion_abrir.php', 'verificacion_guardar.php', 'verificacion_cerrar.php'] as $ep) {
    $codigo = postear($base . $ep, ['auditor' => 'X', 'auditoria_id' => 1,
                                    'informe' => 'g00', 'resultado' => 'aprobado']);
    chequear("$ep sin sesion responde 401", $codigo === 401, "respondio $codigo");
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "Nota: el caso 403 (sesion valida sin CSRF) se verifica a mano desde el navegador,\n";
echo "porque exige una sesion real. Ver Task 6, paso de verificacion visual.\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
```

- [ ] **Step 2: Correr el test para verificar que falla**

```
php tests/verificacion_guard_test.php
```

Esperado: los tres devuelven `404` (los endpoints aún no existen), no `401`.

- [ ] **Step 3: Escribir `api/verificacion_abrir.php`**

```php
<?php
/**
 * POST → abre una auditoría para el aliado de la sesión, o recupera la que esté en curso.
 *
 * El orden de las comprobaciones importa: sesión, método y CSRF ocurren ANTES de abrir la
 * conexión, para no gastar una conexión a la RDS en una petición ya rechazada.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/lib_verificacion.php';

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}
$tokenSesion = (string)($_SESSION['csrf_token'] ?? '');
if ($tokenSesion === '' || !hash_equals($tokenSesion, (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solicitud no válida. Recarga la página.']);
    exit;
}

$proveedor = trim((string)($_SESSION['proveedor'] ?? ''));
$usuario   = trim((string)$_SESSION['usuario']);
$auditor   = trim((string)($_POST['auditor'] ?? ''));

if ($proveedor === '') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'La sesión no tiene aliado asociado; no hay a quién auditar.']);
    exit;
}
if ($auditor === '' || mb_strlen($auditor) > 120) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Escribe tu nombre (máximo 120 caracteres).']);
    exit;
}
// El proveedor centinela es de los tests: no puede entrar por el camino real.
if ($proveedor === VERIF_PROVEEDOR_TEST) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Aliado no auditable.']);
    exit;
}

require __DIR__ . '/../conexion/conexion_integracion.php';
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos.']);
    exit;
}

try {
    $a = verif_abrir($dbConnect, $proveedor, $usuario, $auditor);
    $completa = verif_cargar($dbConnect, $a['id']);
} catch (Throwable $e) {
    error_log('verificacion abrir: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo abrir la verificación.']);
    exit;
}

echo json_encode([
    'ok'        => true,
    'id'        => $a['id'],
    'auditor'   => $a['auditor'],
    'nueva'     => $a['nueva'],
    'estado'    => $completa['estado'],
    'puntos'    => $completa['puntos'],
    'proveedor' => $proveedor,
]);
```

- [ ] **Step 4: Escribir `api/verificacion_guardar.php`**

```php
<?php
/**
 * POST → guarda un punto de control. Re-marcar el mismo informe actualiza, no duplica.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/lib_verificacion.php';

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}
$tokenSesion = (string)($_SESSION['csrf_token'] ?? '');
if ($tokenSesion === '' || !hash_equals($tokenSesion, (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solicitud no válida. Recarga la página.']);
    exit;
}

$auditoriaId = (int)($_POST['auditoria_id'] ?? 0);
$informe     = trim((string)($_POST['informe'] ?? ''));
$resultado   = trim((string)($_POST['resultado'] ?? ''));
$observacion = isset($_POST['observacion']) ? trim((string)$_POST['observacion']) : null;

if ($auditoriaId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Verificación no válida.']);
    exit;
}
$error = verif_validar_punto($informe, $resultado, $observacion);
if ($error !== '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

require __DIR__ . '/../conexion/conexion_integracion.php';
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos.']);
    exit;
}

try {
    // La auditoría tiene que ser del aliado de ESTA sesión y seguir abierta. Sin esta
    // comprobación, un id ajeno en el POST escribiría sobre la auditoría de otro aliado.
    $a = verif_cargar($dbConnect, $auditoriaId);
    if ($a === null || $a['proveedor'] !== trim((string)($_SESSION['proveedor'] ?? ''))) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'La verificación no existe.']);
        exit;
    }
    if ($a['estado'] !== 'en_curso') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Esta verificación ya está cerrada.']);
        exit;
    }
    verif_guardar_punto($dbConnect, $auditoriaId, $informe, $resultado, $observacion);
} catch (Throwable $e) {
    error_log('verificacion guardar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar.']);
    exit;
}

echo json_encode(['ok' => true]);
```

- [ ] **Step 5: Escribir `api/verificacion_cerrar.php`**

El envío va en Task 7; aquí queda el cierre y el armado, con el envío detrás de una función que todavía no existe. **Este endpoint no envía nada todavía** y lo dice en su respuesta.

```php
<?php
/**
 * POST → valida completitud, cierra la auditoría y (Task 7) envía el correo.
 *
 * El cierre y el envío están separados a propósito: si el SMTP falla, la auditoría YA
 * quedó guardada. El registro es el dato valioso; el correo es la notificación.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/lib_verificacion.php';

if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}
$tokenSesion = (string)($_SESSION['csrf_token'] ?? '');
if ($tokenSesion === '' || !hash_equals($tokenSesion, (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solicitud no válida. Recarga la página.']);
    exit;
}

$auditoriaId = (int)($_POST['auditoria_id'] ?? 0);
$comentarios = isset($_POST['comentarios']) ? trim((string)$_POST['comentarios']) : null;
if ($auditoriaId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Verificación no válida.']);
    exit;
}

require __DIR__ . '/../conexion/conexion_integracion.php';
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos.']);
    exit;
}

try {
    $a = verif_cargar($dbConnect, $auditoriaId);
    if ($a === null || $a['proveedor'] !== trim((string)($_SESSION['proveedor'] ?? ''))) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'La verificación no existe.']);
        exit;
    }

    // La completitud se exige en el SERVIDOR, no solo en el navegador: es la condición
    // que le da sentido al registro.
    $faltan = verif_faltantes(array_keys($a['puntos']));
    if ($faltan) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'faltantes' => $faltan,
            'error' => 'Faltan informes por marcar: '
                . implode(', ', array_map(fn($k) => VERIF_INFORMES[$k], $faltan))]);
        exit;
    }

    if (!verif_cerrar($dbConnect, $auditoriaId, $comentarios)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Esta verificación ya estaba cerrada.']);
        exit;
    }
} catch (Throwable $e) {
    error_log('verificacion cerrar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo cerrar la verificación.']);
    exit;
}

// El envío llega en Task 7. Hasta entonces se cierra y se avisa con honestidad.
echo json_encode(['ok' => true, 'correo_enviado' => false,
    'aviso' => 'La verificación quedó guardada. El envío por correo aún no está habilitado.']);
```

- [ ] **Step 6: Correr el test para verificar que pasa**

```
php tests/verificacion_guard_test.php
```

Esperado: `RESULTADO: OK` — los tres responden `401`.

- [ ] **Step 7: Commit**

```bash
git add api/verificacion_abrir.php api/verificacion_guardar.php api/verificacion_cerrar.php tests/verificacion_guard_test.php
git commit -m "feat(verificacion): endpoints con guard de sesion, CSRF y completitud en servidor"
```

---

### Task 6: Vista y modo auditoría

**Files:**
- Create: `informes/verificacion.php`
- Modify: `dashboard.php` (bandera de modo auditoría, `nav-section`, `include`, título, `showPage`, meta CSRF)

**Interfaces:**
- Consumes: los tres endpoints de Task 5.
- Produces: `verifOnEnter()` — función global que `showPage()` llama al entrar en la sección, igual que `g00OnEnter` / `o14OnEnter`.

- [ ] **Step 1: Encender el modo auditoría en `dashboard.php`**

Junto a `$MOSTRAR_PAGOS` (que quedó de la tanda anterior), añadir:

```php
	// Modo auditoría. Auditoría Interna entra con las credenciales del aliado que revisa,
	// así que la sesión no la distingue de un aliado real: sin este interruptor, el aliado
	// vería un formulario de auditoría sobre sí mismo.
	// NO es un control de seguridad — quien conozca el parámetro puede escribirlo. Es
	// visibilidad. Lo que protege los endpoints es el guard de sesión y el CSRF.
	if (isset($_GET['auditoria'])) {
		$_SESSION['modo_auditoria'] = ($_GET['auditoria'] === '1');
	}
	$MODO_AUDITORIA = !empty($_SESSION['modo_auditoria']);

	// El token ya existe desde index.php; se garantiza por si la sesión es vieja.
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
```

`?auditoria=0` lo apaga, para poder volver a la vista normal sin cerrar sesión.

- [ ] **Step 2: Exponer el token al JS**

En el `<head>` de `dashboard.php`, junto al resto de metas:

```html
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
```

- [ ] **Step 3: Añadir la sección al final del menú lateral**

Después del `nav-section` de GESTIÓN, antes de cerrar `</nav>`:

```php
            <?php if ($MODO_AUDITORIA): ?>
            <div class="nav-section">
                <div class="nav-section-title">VERIFICACI&Oacute;N</div>
                <div class="nav-item" onclick="showPage('verificacion', this)">
                    <span class="icon"><i class="fa-solid fa-clipboard-check"></i></span> Verificaci&oacute;n
                </div>
            </div>
            <?php endif; ?>
```

- [ ] **Step 4: Incluir la vista y registrarla en `showPage`**

Junto al resto de includes de informes:

```php
            <?php if ($MODO_AUDITORIA): ?>
            <?php include __DIR__ . '/informes/verificacion.php'; ?>
            <?php endif; ?>
```

En el mapa `titles` de `showPage()`:

```javascript
            'verificacion':'VERIFICACIÓN DE PLATAFORMA',
```

Y junto a los demás `OnEnter`:

```javascript
        if (pageId === 'verificacion' && typeof verifOnEnter === 'function') verifOnEnter();
```

- [ ] **Step 5: Escribir la vista**

Crear `informes/verificacion.php`:

```php
<style>
  #page-verificacion .verif-fila { display:grid; grid-template-columns: 260px 340px 1fr; gap:14px;
    align-items:start; padding:14px 0; border-bottom:1px solid var(--border); }
  #page-verificacion .verif-fila:last-child { border-bottom:none; }
  #page-verificacion .verif-nombre { font-weight:600; color:var(--primary); padding-top:6px; }
  #page-verificacion .verif-ops { display:flex; gap:16px; padding-top:6px; flex-wrap:wrap; }
  #page-verificacion .verif-ops label { display:flex; align-items:center; gap:6px; font-size:13px; cursor:pointer; }
  #page-verificacion textarea { width:100%; min-height:52px; padding:8px 10px; border:1px solid var(--border);
    border-radius:6px; font-family:'Space Grotesk',sans-serif; font-size:12px; resize:vertical; }
  #page-verificacion textarea:focus { outline:none; border-color:var(--primary); }
  #page-verificacion .verif-estado { font-size:11px; color:var(--text-light); min-height:14px; padding-top:4px; }
  #page-verificacion .verif-estado.ok { color:#2e7d4f; }
  #page-verificacion .verif-estado.error { color:#c0392b; }
  #page-verificacion .verif-ficha { display:flex; gap:26px; flex-wrap:wrap; font-size:12px; color:var(--text-light); }
  #page-verificacion .verif-ficha b { color:var(--text); }
</style>
<div class="page" id="page-verificacion">
  <div class="card" id="verif-identificacion">
    <h3 style="margin-bottom:6px;">Identificación del auditor</h3>
    <p style="font-size:12px;color:var(--text-light);margin-bottom:14px;">
      Esta sesión usa las credenciales del aliado auditado, así que el registro no puede
      deducir quién eres. Escribe tu nombre para abrir la verificación.
    </p>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <input type="text" id="verif-auditor" maxlength="120" placeholder="Nombre del auditor"
             style="padding:8px 12px;border:1px solid var(--border);border-radius:6px;min-width:280px;
                    font-family:'Space Grotesk',sans-serif;font-size:13px;">
      <button class="btn btn-primary" onclick="verifAbrir()">Comenzar verificación</button>
    </div>
    <div class="verif-estado error" id="verif-error-abrir"></div>
  </div>

  <div class="card" id="verif-formulario" style="display:none;">
    <div class="verif-ficha" style="margin-bottom:16px;">
      <div>Tercero auditado: <b id="verif-proveedor"></b></div>
      <div>Auditor: <b id="verif-nombre-auditor"></b></div>
      <div>Registro N.: <b id="verif-id"></b></div>
    </div>
    <div id="verif-filas"></div>

    <h4 style="margin:20px 0 8px;">Comentarios generales</h4>
    <textarea id="verif-comentarios" placeholder="Observaciones sobre la revisión en conjunto (opcional)"></textarea>

    <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:18px;align-items:center;">
      <span class="verif-estado" id="verif-estado-cierre"></span>
      <button class="btn btn-primary" onclick="verifCerrar()">Cerrar verificación y enviar</button>
    </div>
  </div>
</div>

<script>
// Los cinco puntos de control. El orden debe coincidir con VERIF_INFORMES de
// api/lib_verificacion.php: la vista, el PDF y la base listan lo mismo en el mismo orden.
const VERIF_INFORMES = [
  ['g00',  'Ventas'],
  ['o14',  'Siembra / Stock / Ventas'],
  ['o45',  'Índice de Ventas'],
  ['evol', 'Evolución Histórica'],
  ['geo',  'Georreferenciación'],
];
const VERIF_CSRF = document.querySelector('meta[name="csrf-token"]').content;
let verifId = 0;

function verifOnEnter() {
  // Si ya se abrió en esta visita, no se vuelve a preguntar el nombre.
  if (verifId) return;
  document.getElementById('verif-auditor').focus();
}

function verifPost(url, datos) {
  const cuerpo = new URLSearchParams(datos);
  cuerpo.set('csrf_token', VERIF_CSRF);
  return fetch('api/' + url, { method:'POST', body: cuerpo })
    .then(r => r.json().then(j => ({ status: r.status, json: j })));
}

function verifAbrir() {
  const auditor = document.getElementById('verif-auditor').value.trim();
  const err = document.getElementById('verif-error-abrir');
  err.textContent = '';
  if (!auditor) { err.textContent = 'Escribe tu nombre.'; return; }

  verifPost('verificacion_abrir.php', { auditor })
    .then(({ status, json }) => {
      if (status !== 200 || !json.ok) { err.textContent = json.error || 'No se pudo abrir.'; return; }
      verifId = json.id;
      document.getElementById('verif-proveedor').textContent     = json.proveedor;
      document.getElementById('verif-nombre-auditor').textContent = json.auditor;
      document.getElementById('verif-id').textContent            = json.id;
      // Si se recupera una en curso, el auditor original manda sobre lo que se escribió.
      document.getElementById('verif-auditor').value = json.auditor;
      verifPintar(json.puntos || {});
      document.getElementById('verif-identificacion').style.display = 'none';
      document.getElementById('verif-formulario').style.display = '';
    })
    .catch(() => { err.textContent = 'Error de red.'; });
}

function verifPintar(puntos) {
  const cont = document.getElementById('verif-filas');
  cont.innerHTML = VERIF_INFORMES.map(([clave, nombre]) => {
    const p   = puntos[clave] || {};
    const sel = r => p.resultado === r ? ' checked' : '';
    const obs = p.observacion ? String(p.observacion) : '';
    return '<div class="verif-fila">'
      + '<div class="verif-nombre">' + nombre + '</div>'
      + '<div>'
      +   '<div class="verif-ops">'
      +     '<label><input type="radio" name="r-' + clave + '" value="aprobado"' + sel('aprobado') + '> Aprobado</label>'
      +     '<label><input type="radio" name="r-' + clave + '" value="no_aprobado"' + sel('no_aprobado') + '> No aprobado</label>'
      +     '<label><input type="radio" name="r-' + clave + '" value="no_aplica"' + sel('no_aplica') + '> No aplica</label>'
      +   '</div>'
      +   '<div class="verif-estado" id="verif-msg-' + clave + '"></div>'
      + '</div>'
      + '<textarea id="verif-obs-' + clave + '" maxlength="1000" '
      +   'placeholder="Observación (obligatoria si no se aprueba)">' + obs + '</textarea>'
      + '</div>';
  }).join('');

  // Se guarda al marcar y al salir del textarea: cada punto viaja solo, para que una
  // sesión caída a media revisión no se lleve el avance por delante.
  VERIF_INFORMES.forEach(([clave]) => {
    document.querySelectorAll('input[name="r-' + clave + '"]').forEach(radio => {
      radio.addEventListener('change', () => verifGuardar(clave));
    });
    document.getElementById('verif-obs-' + clave).addEventListener('blur', () => {
      if (document.querySelector('input[name="r-' + clave + '"]:checked')) verifGuardar(clave);
    });
  });
}

function verifGuardar(clave) {
  const radio = document.querySelector('input[name="r-' + clave + '"]:checked');
  if (!radio) return;
  const msg = document.getElementById('verif-msg-' + clave);
  msg.className = 'verif-estado';
  msg.textContent = 'Guardando…';

  verifPost('verificacion_guardar.php', {
    auditoria_id: verifId,
    informe:      clave,
    resultado:    radio.value,
    observacion:  document.getElementById('verif-obs-' + clave).value,
  }).then(({ status, json }) => {
    if (status === 200 && json.ok) { msg.className = 'verif-estado ok'; msg.textContent = 'Guardado'; }
    else { msg.className = 'verif-estado error'; msg.textContent = json.error || 'No se pudo guardar.'; }
  }).catch(() => { msg.className = 'verif-estado error'; msg.textContent = 'Error de red.'; });
}

function verifCerrar() {
  const est = document.getElementById('verif-estado-cierre');
  est.className = 'verif-estado';
  est.textContent = '';

  Swal.fire({
    title: '¿Cerrar la verificación?',
    text:  'Se guardará el registro y se enviará por correo. No se puede deshacer.',
    icon:  'question',
    showCancelButton: true,
    confirmButtonText: 'Sí, cerrar y enviar',
    cancelButtonText:  'Cancelar',
    confirmButtonColor: '#4A4782',
  }).then(res => {
    if (!res.isConfirmed) return;
    verifPost('verificacion_cerrar.php', {
      auditoria_id: verifId,
      comentarios:  document.getElementById('verif-comentarios').value,
    }).then(({ status, json }) => {
      if (status === 200 && json.ok) {
        Swal.fire({ icon: json.correo_enviado ? 'success' : 'warning',
                    title: 'Verificación cerrada',
                    text: json.aviso || 'Se envió el aviso por correo.' });
        document.getElementById('verif-formulario').style.display = 'none';
        verifId = 0;
      } else {
        Swal.fire({ icon:'error', title:'No se pudo cerrar', text: json.error || 'Error inesperado.' });
      }
    }).catch(() => Swal.fire({ icon:'error', title:'Error de red' }));
  });
}
</script>
```

- [ ] **Step 6: Verificar en el navegador — y MIRARLO**

Levantar el portal y recorrer estos casos. No basta con que no salgan errores en consola:

1. `http://localhost/plataforma_20/dashboard.php` (sin parámetro) → **no** aparece VERIFICACIÓN en el menú.
2. `http://localhost/plataforma_20/dashboard.php?auditoria=1` → aparece al final del menú lateral.
3. Entrar sin escribir nombre → "Escribe tu nombre.", no se abre nada.
4. Escribir un nombre y comenzar → aparece el formulario con los cinco informes en el orden correcto y la ficha con el aliado.
5. Marcar "Aprobado" en Ventas → sale "Guardado" junto al radio.
6. Marcar "No aprobado" sin observación → el servidor lo rechaza con "Debes explicar por qué no se aprueba." (es el `verif_validar_punto` de Task 1 haciendo su trabajo desde el servidor).
7. Recargar con `?auditoria=1` y volver a entrar con **otro nombre** → recupera la auditoría con lo marcado y muestra **el auditor original**, no el nuevo.
8. Intentar cerrar con informes sin marcar → error nombrando los que faltan.
9. Marcar los cinco y cerrar → confirma con SweetAlert y avisa que quedó guardada (el correo llega en Task 7).
10. `?auditoria=0` → la sección desaparece.

**Verificación del CSRF (el caso 403 que el test automatizado no cubre):** en la consola del navegador, con sesión abierta:

```javascript
fetch('api/verificacion_guardar.php', { method:'POST',
  body: new URLSearchParams({ auditoria_id: 1, informe:'g00', resultado:'aprobado', csrf_token:'basura' })
}).then(r => console.log('esperado 403 ->', r.status));
```

Esperado: `esperado 403 -> 403`.

- [ ] **Step 7: Comprobar en la base que quedó bien guardado**

```
php -r "require 'conexion/conexion_integracion.php'; \
  \$s=sqlsrv_query(\$dbConnect,\"SELECT TOP 5 a.id,a.proveedor,a.auditor,a.estado,\
    (SELECT COUNT(*) FROM verificacion_auditoria_detalle d WHERE d.auditoria_id=a.id) puntos \
    FROM verificacion_auditoria a ORDER BY a.id DESC\"); \
  while(\$r=sqlsrv_fetch_array(\$s,SQLSRV_FETCH_ASSOC)) print_r(\$r);"
```

Esperado: la auditoría recién cerrada, con `estado = cerrada` y `puntos = 5`.

- [ ] **Step 8: Commit**

```bash
git add dashboard.php informes/verificacion.php
git commit -m "feat(verificacion): seccion en el portal con modo auditoria por URL"
```

---

### Task 7: Envío del correo

Lo último a propósito: hasta aquí nada podía mandar un correo ni por accidente.

**Files:**
- Modify: `api/lib_verificacion.php` (añadir `verif_enviar()`)
- Modify: `api/verificacion_cerrar.php` (llamarla)
- Modify: `conexion/config_mail.php` (poner los destinatarios reales)
- Test: `tests/verificacion_envio_test.php`

**Interfaces:**
- Consumes: `verif_armar_paquete()`, `verif_destinatarios()`, `verif_pdf()`.
- Produces: `verif_enviar(array $paquete, array $destinatarios, string $rutaPdf): void` — lanza `RuntimeException` si la lista está vacía.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/verificacion_envio_test.php`. **Comprueba que el envío se NIEGA**; nunca que se produce:

```php
<?php
// Contrato de verif_enviar(). Este test NO envia correo: comprueba que se NIEGA a hacerlo
// cuando no hay a quien. Es la ultima red: si alguien despliega sin configurar
// MAIL_AUDITORIA_TO, el sistema falla ruidosamente en vez de mandar a nadie en silencio.
//
//   php tests/verificacion_envio_test.php

require_once __DIR__ . '/../api/lib_verificacion.php';

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

echo "CONTRATO DE verif_enviar()\n" . str_repeat('=', 70) . "\n";

$paqueteFalso = ['auditoria' => ['id' => 0, 'proveedor' => VERIF_PROVEEDOR_TEST],
                 'asunto' => 'x', 'cuerpo_html' => 'x', 'nombre_pdf' => 'x.pdf', 'filas' => []];

$lanzo = false;
try { verif_enviar($paqueteFalso, [], sys_get_temp_dir() . '/no_existe.pdf'); }
catch (RuntimeException $e) { $lanzo = true; }
chequear('sin destinatarios lanza excepcion en vez de enviar', $lanzo);

$lanzo = false;
try { verif_enviar($paqueteFalso, ['a@b.co'], sys_get_temp_dir() . '/no_existe_' . uniqid() . '.pdf'); }
catch (RuntimeException $e) { $lanzo = true; }
chequear('sin PDF adjunto lanza excepcion en vez de enviar un aviso vacio', $lanzo);

chequear('el proveedor centinela sigue sin resolver destinatarios',
    verif_destinatarios(VERIF_PROVEEDOR_TEST) === []);

echo "\n" . str_repeat('=', 70) . "\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
```

- [ ] **Step 2: Correr el test para verificar que falla**

```
php tests/verificacion_envio_test.php
```

Esperado: `Call to undefined function verif_enviar()`.

- [ ] **Step 3: Escribir `verif_enviar()`**

Añadir al final de `api/lib_verificacion.php`:

```php
/**
 * Envía el aviso con el PDF adjunto. ES IRREVERSIBLE — por eso vive separada de
 * verif_armar_paquete() y ningún test la invoca con datos que puedan salir.
 *
 * Las dos guardas de arriba no son paranoia: una lista vacía significaría un envío a
 * nadie (despliegue sin configurar) y un PDF ausente significaría avisar sin el contenido.
 * En ambos casos es preferible fallar ruidosamente.
 *
 * @throws RuntimeException si no hay destinatarios, si falta el PDF o si el SMTP falla.
 */
function verif_enviar(array $paquete, array $destinatarios, string $rutaPdf): void {
    if (!$destinatarios) {
        throw new RuntimeException('No hay destinatarios configurados (MAIL_AUDITORIA_TO).');
    }
    if (!is_file($rutaPdf)) {
        throw new RuntimeException('No se encontró el PDF a adjuntar: ' . $rutaPdf);
    }

    require_once __DIR__ . '/../conexion/config_mail.php';
    require_once __DIR__ . '/../PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USER;
    $mail->Password   = MAIL_PASS;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;

    $mail->setFrom(MAIL_USER, MAIL_FROM_NAME);
    if (defined('MAIL_TEST_TO') && MAIL_TEST_TO !== '') {
        // Mismo modo prueba que usa Codificación: si está definido, el correo va SOLO ahí.
        $mail->addAddress(MAIL_TEST_TO);
    } else {
        foreach ($destinatarios as $d) $mail->addAddress($d);
    }

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = $paquete['asunto'];
    $mail->Body    = $paquete['cuerpo_html'];
    $mail->addAttachment($rutaPdf, $paquete['nombre_pdf']);

    $mail->send();
}
```

- [ ] **Step 4: Correr el test para verificar que pasa**

```
php tests/verificacion_envio_test.php
```

Esperado: `RESULTADO: OK`.

- [ ] **Step 5: Enganchar el envío en `api/verificacion_cerrar.php`**

Reemplazar el bloque final (el `echo json_encode` con `'aviso' => 'El envío por correo aún no está habilitado.'`) por:

```php
// La auditoría YA quedó cerrada. Lo que sigue es la notificación: si falla, se informa,
// pero NO se revierte el cierre — el registro es el dato valioso.
require_once __DIR__ . '/lib_verificacion_pdf.php';

$rutaPdf = '';
try {
    $paquete = verif_armar_paquete($dbConnect, $auditoriaId);
    $rutaPdf = sys_get_temp_dir() . '/' . $paquete['nombre_pdf'];
    verif_pdf($paquete, $rutaPdf);
    verif_enviar($paquete, verif_destinatarios($a['proveedor']), $rutaPdf);
    verif_marcar_correo_enviado($dbConnect, $auditoriaId);
    echo json_encode(['ok' => true, 'correo_enviado' => true]);
} catch (Throwable $e) {
    error_log('verificacion envio: ' . $e->getMessage());
    echo json_encode(['ok' => true, 'correo_enviado' => false,
        'aviso' => 'La verificación quedó guardada, pero el correo no se pudo enviar. '
                 . 'Avisa a sistemas; el registro N.° ' . $auditoriaId . ' ya está en la base.']);
} finally {
    if ($rutaPdf !== '' && is_file($rutaPdf)) @unlink($rutaPdf);
}
```

- [ ] **Step 6: Configurar los destinatarios y probar de punta a punta**

Poner en `conexion/config_mail.php` los correos que indique Rafael:

```php
define('MAIL_AUDITORIA_TO', ['correo1@stanton.co', 'correo2@stanton.co']);
```

**Antes de la prueba real, definir `MAIL_TEST_TO`** con un correo propio, para que el primer envío no llegue a los destinatarios definitivos. Luego, desde el navegador: abrir una verificación, marcar los cinco informes (al menos uno con observación larga y tildes), cerrarla y **abrir el correo recibido**. Comprobar que el asunto nombra al aliado, que el PDF viene adjunto con el nombre esperado y que se abre y se lee bien.

Quitar `MAIL_TEST_TO` cuando esté verificado.

- [ ] **Step 7: Comprobar que el envío quedó registrado**

```
php -r "require 'conexion/conexion_integracion.php'; \
  \$s=sqlsrv_query(\$dbConnect,\"SELECT TOP 3 id,proveedor,estado,correo_enviado FROM verificacion_auditoria ORDER BY id DESC\"); \
  while(\$r=sqlsrv_fetch_array(\$s,SQLSRV_FETCH_ASSOC)) print_r(\$r);"
```

Esperado: `correo_enviado = 1` en la auditoría recién cerrada.

- [ ] **Step 8: Correr la suite completa del módulo**

```
php tests/verificacion_validar_test.php && \
php tests/verificacion_persistencia_test.php && \
php tests/verificacion_paquete_test.php && \
php tests/verificacion_pdf_test.php && \
php tests/verificacion_guard_test.php && \
php tests/verificacion_envio_test.php
```

Esperado: `RESULTADO: OK` en los seis.

- [ ] **Step 9: Commit**

```bash
git add api/lib_verificacion.php api/verificacion_cerrar.php tests/verificacion_envio_test.php
git commit -m "feat(verificacion): envio del PDF por correo al cerrar la auditoria"
```

---

## Cierre

Antes de dar el módulo por terminado:

- [ ] Anotar en `docs/DESPLIEGUE-CHECKLIST-2026-07-21.md` (o en un checklist nuevo) los tres pasos manuales que el despliegue a WMS-LAB necesita: **aplicar `sql/008_verificacion_auditoria.sql` en la RDS**, **copiar la carpeta `fpdf/`** (no está en ningún manifiesto de dependencias, así que es lo primero que se olvida) y **definir `MAIL_AUDITORIA_TO` en el `config_mail.php` del servidor**, que no se versiona.
- [ ] Confirmar que `conexion/config_mail.php` real quedó con los destinatarios y **sin** `MAIL_TEST_TO`.
- [ ] Usar `superpowers:finishing-a-development-branch` para decidir la integración.
