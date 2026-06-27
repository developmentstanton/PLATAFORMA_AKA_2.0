# G00: comparación de dos años a elección — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que el usuario elija qué dos años comparar en el Informe de Ventas (G00) — un año mayor vs uno menor, default año actual vs anterior — conservando el rango mes/día, la estructura de las tablas y los formatos de descarga.

**Architecture:** La derivación de rangos actual/anterior se extrae a una función pura PHP (`api/lib_g00_rango.php`) testeable sin BD; el endpoint la usa para armar el periodo anterior desde el año menor (`anioB`) en vez de "−1 año". El endpoint emite el par `anio_a`/`anio_b` (reemplaza el `anio` único) y reconcilia las dos rutas con año fijo (Mensual y Periodos). El frontend añade dos selectores de año + presets de rango rápido, valida mayor>menor, y reemplaza los 9 `b = a−1` (renderers + builders de export) por el par real.

**Tech Stack:** PHP (SQL Server `sqlsrv`) + JS vanilla embebido en `informes/g00.php`; SheetJS/SweetAlert2 ya cargados en `dashboard.php`. Tests: `php tests/*.php` (lógica pura, sin BD) y `node tests/*.mjs` (golden de builders).

## Global Constraints

- **Obligatorio mayor > menor.** Si `anioB >= anioA`: cliente muestra Swal warning y no consulta; servidor responde `{ok:false, error:'El año a comparar debe ser menor que el año principal.'}` (HTTP 400).
- **Default = año actual vs año inmediatamente anterior** (preserva el comportamiento de hoy).
- **Rango de años seleccionable: 2019 .. año actual** (`date('Y')`), orden descendente.
- **Retail:** periodo anterior = `−364 × (añoMayor − añoMenor)` días. **Día a Día:** mismo mes/día en el año menor, con **29-feb → 28-feb** si el año menor no es bisiesto.
- **El endpoint emite `anio_a` (año mayor) y `anio_b` (año menor)** en las 4 pestañas, reemplazando el `anio` único.
- **No cambiar** el orden de columnas ni la estructura de las tablas/Excel; solo cambia qué años se rotulan. Solo se toca G00.
- `php -l` limpio en `api/informe_g00.php`, `api/lib_g00_rango.php`, `informes/g00.php`. Tests node y PHP en verde.
- Builders de export devuelven `{header, filas}` (ya establecido en este branch); aquí solo cambia de dónde sacan el par de años.

---

### Task 1: Librería pura de rangos + test PHP

Extrae la derivación de rangos a un módulo puro (patrón `api/lib_*.php` del repo), testeable sin BD.

**Files:**
- Create: `api/lib_g00_rango.php`
- Test: `tests/g00_rango_comparacion_test.php`

**Interfaces:**
- Produces:
  - `g00_set_anio(string $fecha, int $anio): string` — cambia el año de `YYYY-MM-DD`; 29-feb→28-feb si `$anio` no bisiesto.
  - `g00_rango_comparacion(string $desde, string $hasta, int $anioB, string $cal): array` → `[desdeAct, hastaAct, desdeAnt, hastaAnt, error]`; `error='rango_anios_invalido'` si `anioB<=0` o `anioB>=añoMayor` (año tomado de `$hasta`).

- [ ] **Step 1: Escribir el test que falla** (`tests/g00_rango_comparacion_test.php`)

```php
<?php // tests/g00_rango_comparacion_test.php
//   php tests/g00_rango_comparacion_test.php
// Verifica g00_rango_comparacion() y g00_set_anio() (lógica pura, sin BD).
require __DIR__ . '/../api/lib_g00_rango.php';
$fail=0; function chk($c,$m){ global $fail; if(!$c){ echo "FALLO: $m\n"; $fail=1; } }

// 1) diaadia adyacente (2026 vs 2025) — comportamiento por defecto histórico.
$r = g00_rango_comparacion('2026-01-01','2026-06-26', 2025, 'diaadia');
chk($r === ['2026-01-01','2026-06-26','2025-01-01','2025-06-26', null], "diaadia adyacente: ".json_encode($r));

// 2) diaadia no adyacente (2026 vs 2023).
$r = g00_rango_comparacion('2026-01-01','2026-06-26', 2023, 'diaadia');
chk($r === ['2026-01-01','2026-06-26','2023-01-01','2023-06-26', null], "diaadia no adyacente: ".json_encode($r));

// 3) retail brecha 1 año = -364 días.
$r = g00_rango_comparacion('2026-06-26','2026-06-26', 2025, 'retail');
chk($r[2] === date('Y-m-d', strtotime('2026-06-26 -364 days')) && $r[4] === null, "retail gap1: ".json_encode($r));

// 4) retail brecha 3 años = -1092 días (364*3).
$r = g00_rango_comparacion('2026-06-26','2026-06-26', 2023, 'retail');
chk($r[2] === date('Y-m-d', strtotime('2026-06-26 -1092 days')), "retail gap3: ".json_encode($r));

// 5) 29-feb en año menor NO bisiesto (2023) → 28-feb.
$r = g00_rango_comparacion('2024-02-29','2024-02-29', 2023, 'diaadia');
chk($r[2] === '2023-02-28' && $r[3] === '2023-02-28', "29feb→28feb: ".json_encode($r));

// 6) 29-feb en año menor bisiesto (2020) se conserva.
chk(g00_set_anio('2024-02-29', 2020) === '2020-02-29', "29feb año bisiesto se conserva");

// 7) anioB >= anioA → error.
$r = g00_rango_comparacion('2026-01-01','2026-06-26', 2026, 'diaadia');
chk($r[4] === 'rango_anios_invalido', "anioB=anioA inválido: ".json_encode($r));
$r = g00_rango_comparacion('2026-01-01','2026-06-26', 2027, 'diaadia');
chk($r[4] === 'rango_anios_invalido', "anioB>anioA inválido: ".json_encode($r));

echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (diaadia/retail/29feb/validación)\n"; exit($fail);
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php tests/g00_rango_comparacion_test.php`
Expected: error fatal de PHP `require(...lib_g00_rango.php): Failed to open stream` (el lib aún no existe) — RED.

- [ ] **Step 3: Crear el lib** (`api/lib_g00_rango.php`)

```php
<?php
// api/lib_g00_rango.php — derivación pura de los rangos de comparación de dos años para G00.
// Sin acceso a BD. Testeable con: php tests/g00_rango_comparacion_test.php

/** Cambia el año de una fecha YYYY-MM-DD a $anio; 29-feb→28-feb si $anio no es bisiesto. */
function g00_set_anio($fecha, $anio) {
    $anio = (int) $anio;
    $mmdd = substr($fecha, 5); // 'MM-DD'
    if ($mmdd === '02-29' && !date('L', mktime(0, 0, 0, 1, 1, $anio))) {
        $mmdd = '02-28';
    }
    return $anio . '-' . $mmdd;
}

/**
 * Deriva [desdeAct, hastaAct, desdeAnt, hastaAnt, error] para comparar dos años.
 * Periodo MAYOR = [$desde,$hasta] (año tomado de $hasta). Periodo MENOR = año $anioB.
 * $cal: 'retail' → anterior = -364*(añoMayor-añoMenor) días; otro → mismo mes/día en $anioB.
 * error='rango_anios_invalido' si $anioB<=0 o $anioB>=añoMayor (devuelve el mayor en ambos lados).
 */
function g00_rango_comparacion($desde, $hasta, $anioB, $cal) {
    $anioA = (int) substr($hasta, 0, 4);
    $anioB = (int) $anioB;
    if ($anioB <= 0 || $anioB >= $anioA) {
        return [$desde, $hasta, $desde, $hasta, 'rango_anios_invalido'];
    }
    if ($cal === 'retail') {
        $dias = ' -' . (364 * ($anioA - $anioB)) . ' days';
        $desdeAnt = date('Y-m-d', strtotime($desde . $dias));
        $hastaAnt = date('Y-m-d', strtotime($hasta . $dias));
    } else {
        $desdeAnt = g00_set_anio($desde, $anioB);
        $hastaAnt = g00_set_anio($hasta, $anioB);
    }
    return [$desde, $hasta, $desdeAnt, $hastaAnt, null];
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php tests/g00_rango_comparacion_test.php`
Expected: `RESULTADO: OK (diaadia/retail/29feb/validación)` (exit 0)

- [ ] **Step 5: Lint**

Run: `php -l api/lib_g00_rango.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add api/lib_g00_rango.php tests/g00_rango_comparacion_test.php
git commit -m "feat(g00): lib pura de rangos de comparación de años + test"
```

---

### Task 2: Endpoint — usar `anioB`, emitir `anio_a`/`anio_b`, reconciliar Periodos

Conecta el lib al endpoint, deriva el periodo anterior desde `anioB`, emite el par de años en las 4 pestañas y elimina el override de "−1 año calendario" de Periodos. (Mensual se reconcilia en la Task 3.)

**Files:**
- Modify: `api/informe_g00.php` (require del lib; derivación `:33-58`; Periodos `:509-554`; salidas `anio` en `:501, 554, 647, 883`)

**Interfaces:**
- Consumes: `g00_rango_comparacion`, `g00_set_anio` (Task 1).
- Produces: respuestas JSON con `anio_a` (int, año mayor) y `anio_b` (int, año menor) en lugar de `anio`. Param de entrada `?anioB=<int>`.

- [ ] **Step 1: Verificación anti-duplicado (informa, no bloquea)**

Confirmar que `Ventas_Detal_PBI` y `Ventas_Detal_Acum_PBI` NO solapan años (si solaparan, el `UNION ALL` duplicaría al elegir el año frontera). Ejecutar (vía un script PHP temporal con `conexion_integracion.php`, o pedir a Rafael):
`SELECT MIN(YEAR(FECHA)) a, MAX(YEAR(FECHA)) b FROM INTEGRACION.dbo.Ventas_Detal_PBI` y lo mismo para `..._Acum_PBI`.
Expected: rangos disjuntos (p. ej. Acum ≤2025, PBI =2026). Si solapan, **escalar** antes de continuar (habría que acotar cada SELECT del UNION por su rango de años). El default actual (2026 vs 2025) funciona hoy, lo que sugiere disjunción.

- [ ] **Step 2: Agregar el `require_once` del lib** en `api/informe_g00.php`, justo después de la línea 22 (`header('Content-Type: application/json; charset=utf-8');`):

```php
require_once __DIR__ . '/lib_g00_rango.php';
```

- [ ] **Step 3: Reemplazar la derivación de rangos** (`api/informe_g00.php:33-58`) por:

```php
$desdeIn = $_GET['desde'] ?? '';
$hastaIn = $_GET['hasta'] ?? '';
$sss     = strtolower(trim($_GET['sss'] ?? 'nosame')); // S.S.S: 'same' aplica same-store; default 'nosame'
$anioBIn = (int)($_GET['anioB'] ?? 0);

if ($desdeIn && $hastaIn) {
    $desdeAct = $desdeIn;
    $hastaAct = $hastaIn;
} else {
    $desdeAct = date('Y-01-01');
    $hastaAct = date('Y-m-d');
}
$cal = strtolower(trim($_GET['cal'] ?? 'diaadia'));
if ($cal !== 'retail') $cal = 'diaadia';

// Año menor a comparar: si no llega, año del periodo mayor − 1 (comportamiento por defecto).
if ($anioBIn <= 0) $anioBIn = (int)date('Y', strtotime($hastaAct)) - 1;

list($desdeAct, $hastaAct, $desdeAnt, $hastaAnt, $rangoErr) =
    g00_rango_comparacion($desdeAct, $hastaAct, $anioBIn, $cal);
if ($rangoErr !== null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El año a comparar debe ser menor que el año principal.']);
    exit;
}
$yearAct = (int)date('Y', strtotime($hastaAct)); // año mayor, para rótulos y para Mensual

// Rango global para el pushdown de fecha en el CTE.
$gmin = ($desdeAnt < $desdeAct) ? $desdeAnt : $desdeAct;
$gmax = ($hastaAct > $hastaAnt) ? $hastaAct : $hastaAnt;
```

(El bloque `require __DIR__ . '/../conexion/conexion_integracion.php';` de `:60` y siguientes queda igual — el `exit` por rango inválido ocurre antes de conectar a BD.)

- [ ] **Step 4: Reconciliar Periodos** (`api/informe_g00.php:509-513`): reemplazar el override de −1 año por los rangos derivados (que ya honran `anioB` y `cal`):

```php
if ($tab === 'periodos') {
    $pAntDesde = $desdeAnt;
    $pAntHasta = $hastaAnt;
    $pGmin = $gmin;
    $pGmax = $gmax;
```

(El resto del bloque Periodos — query, params, `$sameStorePeriodos` que usa `$pAntDesde`/`$hastaAct` — queda igual.)

- [ ] **Step 5: Emitir `anio_a`/`anio_b`** en las 4 salidas JSON. Reemplazos exactos:

- Tiendas (`:501`): `'anio'=>(int)date('Y',strtotime($hastaAct)),` → `'anio_a'=>$yearAct, 'anio_b'=>$anioBIn,`
- Periodos (`:554`): `'anio'=>(int)date('Y',strtotime($hastaAct)),` → `'anio_a'=>$yearAct, 'anio_b'=>$anioBIn,`
- Productos (`:647`): `'anio' => (int)date('Y', strtotime($hastaAct)),` → `'anio_a' => $yearAct, 'anio_b' => $anioBIn,`
- Detal (`:883`, dentro de `$out`): `'anio'      => $yearAct,` → `'anio_a'    => $yearAct,` y agregar la línea `'anio_b'    => $anioBIn,` justo debajo.

(En Detal, la línea `$yearAct = (int)date('Y', strtotime($hastaAct));` que existía en `:822` ahora es redundante porque `$yearAct` se define arriba en el Step 3 — eliminar la reasignación duplicada de `:822` dejando solo el comentario, o quitar ambas. Verificar que no haya otra reasignación de `$yearAct`.)

- [ ] **Step 6: Lint**

Run: `php -l api/informe_g00.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add api/informe_g00.php
git commit -m "feat(g00): periodo anterior desde anioB + par anio_a/anio_b + Periodos sin override -1a"
```

---

### Task 3: Mensual — comparar los dos años elegidos

Hoy la tabla Mensual (`api/informe_g00.php:741-834`) fuerza "Ene→hoy del año en curso vs −1 año". Pasa a comparar **añoMayor Ene→Hasta vs añoMenor**, honrando `cal`.

**Files:**
- Modify: `api/informe_g00.php` (bloque Mensual `:744-757` y el bucle de serie `:819, 824`)

**Interfaces:**
- Consumes: `$yearAct` (año mayor), `$anioBIn` (año menor), `$hastaAct`, `$hastaAnt`, `$cal`, `g00_set_anio` (Tasks 1-2).

- [ ] **Step 1: Reemplazar la cabecera del bloque Mensual** (`api/informe_g00.php:744-757`):

```php
$mensDesA   = "$yearAct-01-01";
$mensHasA   = $hastaAct;
if ($cal === 'retail') {
    $shiftToActualDays = 364 * ($yearAct - $anioBIn);
    $mensDesB = date('Y-m-d', strtotime($mensDesA . ' -' . $shiftToActualDays . ' days'));
    $mensHasB = $hastaAnt;
} else {
    $shiftToActualDays = 0;   // diaadia: el mes calendario ya coincide
    $mensDesB = g00_set_anio($mensDesA, $anioBIn);
    $mensHasB = $hastaAnt;
}
$mensGmin = min($mensDesB, $mensDesA);
$mensGmax = max($mensHasA, $mensHasB);
```

(Se elimina la línea `$hoy = date('Y-m-d');` si ya no se usa en este bloque — verificar con grep; `$hoy` puede usarse en otros lados, en cuyo caso dejarla.)

- [ ] **Step 2: Acotar el bucle de serie al mes del Hasta** (`api/informe_g00.php:819`): reemplazar `$mesActual = (int)date('n');` por:

```php
$mesActual = (int)date('n', strtotime($hastaAct));   // Ene → mes del Hasta del año mayor
```

(El bucle `for ($m = 1; $m <= $mesActual; $m++)` en `:824` queda igual; ahora recorre Ene→mes del Hasta.)

- [ ] **Step 3: Lint**

Run: `php -l api/informe_g00.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add api/informe_g00.php
git commit -m "feat(g00): tabla Mensual compara los dos años elegidos (Ene→Hasta)"
```

---

### Task 4: Frontend — selectores de año, rango rápido y validación

Agrega los dos `<select>` de año + los botones de rango rápido en la Fila 1 de filtros, hace que `dateVal` use el año mayor, `buildParams` envíe `anioB`, y `g00Load` valide mayor>menor.

**Files:**
- Modify: `informes/g00.php` (HTML filtros `:218-251`; `dateVal` `:628-633`; `buildParams` `:635-647`; `g00Load`; nueva `g00QuickRange`)

**Interfaces:**
- Produces: controles `#g00-anio-a`, `#g00-anio-b`; función global `window.g00QuickRange(mode)`. `buildParams` agrega `anioB`. `g00Load` bloquea si `anioB>=anioA`.

- [ ] **Step 1: Agregar los controles** en `informes/g00.php`, dentro de la Fila 1, **después** del `filter-group` de "Hasta" (tras la línea 233, antes del de "Calendario"):

```php
            <div class="filter-group">
                <label>Año</label>
                <select id="g00-anio-a"><?php $ya=(int)date('Y'); for($y=$ya;$y>=2019;$y--) printf('<option value="%d"%s>%d</option>',$y,$y==$ya?' selected':'',$y); ?></select>
            </div>
            <div class="filter-group">
                <label>Comparar vs</label>
                <select id="g00-anio-b"><?php for($y=$ya;$y>=2019;$y--) printf('<option value="%d"%s>%d</option>',$y,$y==$ya-1?' selected':'',$y); ?></select>
            </div>
            <div class="filter-group">
                <label>Rango rápido</label>
                <div class="g00-md">
                    <button type="button" class="g00-btn-export" onclick="g00QuickRange('ytd')">Hasta la fecha</button>
                    <button type="button" class="g00-btn-export" onclick="g00QuickRange('full')">Año completo</button>
                </div>
            </div>
```

- [ ] **Step 2: Cambiar `dateVal`** (`informes/g00.php:628-633`) para usar el año mayor:

```js
    function dateVal(prefix) { // prefix = 'desde' | 'hasta'
        const m = document.getElementById('g00-' + prefix + '-mes').value;
        const d = document.getElementById('g00-' + prefix + '-dia').value;
        if (!m || !d) return '';
        const ya = document.getElementById('g00-anio-a');
        const year = (ya && ya.value) ? ya.value : new Date().getFullYear();
        return year + '-' + m + '-' + d;
    }
```

- [ ] **Step 3: `buildParams` envía `anioB`** (`informes/g00.php:635-647`): agregar antes del `return`:

```js
        const ab = document.getElementById('g00-anio-b');
        if (ab && ab.value) p.append('anioB', ab.value);
```

- [ ] **Step 4: Agregar `g00QuickRange`** (junto a `dateVal`, p. ej. tras `:633`):

```js
    // Presets de rango: 'ytd' = Ene-01 → hoy; 'full' = Ene-01 → Dic-31. Solo rellenan mes/día.
    window.g00QuickRange = function (mode) {
        const set = (id, v) => { const e = document.getElementById(id); if (e) e.value = v; };
        set('g00-desde-mes', '01'); set('g00-desde-dia', '01');
        if (mode === 'full') { set('g00-hasta-mes', '12'); set('g00-hasta-dia', '31'); }
        else { const now = new Date(); set('g00-hasta-mes', String(now.getMonth() + 1).padStart(2, '0')); set('g00-hasta-dia', String(now.getDate()).padStart(2, '0')); }
    };
```

- [ ] **Step 5: Validación en `g00Load`** — localizar `window.g00Load = function () {` (cerca del dispatcher, ~`:1298`) y reemplazar su cuerpo por:

```js
    window.g00Load = function () {
        const aEl = document.getElementById('g00-anio-a'), bEl = document.getElementById('g00-anio-b');
        if (aEl && bEl && parseInt(bEl.value, 10) >= parseInt(aEl.value, 10)) {
            Swal.fire('Comparación inválida', 'El año a comparar debe ser menor que el año principal.', 'warning');
            return;
        }
        // Filtros cambiaron: invalida todos, recarga el actual, los demás se recargarán al visitarse
        Object.keys(tabState).forEach(k => tabState[k] = false);
        loadCurrentTab();
    };
```

- [ ] **Step 6: Lint**

Run: `php -l informes/g00.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add informes/g00.php
git commit -m "feat(g00): selectores de año + rango rápido + validación mayor>menor"
```

---

### Task 5: Frontend — rotular con el par `anio_a`/`anio_b` (renderers + exports)

Reemplaza los 9 `b = a − 1` por el par real: los renderers leen `data.anio_a`/`data.anio_b`; los 3 builders de export reciben el par; los `g00Exp*` pasan `lastX.anio_a`/`lastX.anio_b`. Extiende los golden tests node.

**Files:**
- Modify: `informes/g00.php` (callers en `loadDetal`/`loadTiendas`/`loadPeriodos`/`loadProductos`; renderers `:690, 804, 890, 932, 993, 1030`; builders `comparativaAOA`/`periodosAOA`/`mensualAOA` y los `g00Exp*`)
- Test: `tests/g00_comparativa_export_test.mjs`, `tests/g00_periodos_export_test.mjs` (extender)

**Interfaces:**
- Consumes: respuestas con `anio_a`/`anio_b` (Task 2).
- Produces: `comparativaAOA(dim, rows, opts)` usa `opts.anioA`/`opts.anioB`; `periodosAOA(dias, anioA, anioB)`; `mensualAOA(rows, tdas, anioA, anioB)`. Renderers reciben `(..., anioA, anioB)`.

- [ ] **Step 1: Extender los golden tests** para fijar el par explícito (no `a−1`).

En `tests/g00_comparativa_export_test.mjs`, cambiar la llamada del caso padre/hijo y su aserción de header para usar años no adyacentes:
- Cambiar `{anio:2026, full:false}` por `{anioA:2026, anioB:2023, full:false}` y `{anio:2026, full:true}` por `{anioA:2026, anioB:2023, full:true}`.
- Y la copia de `comparativaAOA` dentro del test: `const a = opts.anio` → `const a = opts.anioA, b = opts.anioB;` (quitar el `b = a - 1`).
- Añadir aserción: `assert(rPC.header[0]==='Tienda' && rPC.header[2]===2023, 'header usa año menor 2023')` (col 2 = `b` = 2023) y `assert(rG.header[1]===2023, 'una dim: año menor 2023')`.

En `tests/g00_periodos_export_test.mjs`, cambiar la copia de `periodosAOA(dias, anio)` a `periodosAOA(dias, anioA, anioB)` con `const a = anioA, b = anioB;`, llamarla `periodosAOA(dias, 2026, 2023)`, y añadir `assert(r.header[5]===2023, 'cant año menor = 2023')` (header[5] = `b`).

- [ ] **Step 2: Correr los tests** (copias puras → deben pasar como golden del nuevo contrato)

Run: `node tests/g00_comparativa_export_test.mjs && node tests/g00_periodos_export_test.mjs`
Expected: `RESULTADO: OK ...` en ambos.

- [ ] **Step 3: Builders de export** (`informes/g00.php`) — usar el par:

- `comparativaAOA(dim, rows, opts)`: cambiar `const a = opts.anio, b = a - 1, full = opts.full !== false;` por `const a = opts.anioA, b = opts.anioB, full = opts.full !== false;`.
- `periodosAOA(dias, anio)` → `periodosAOA(dias, anioA, anioB)`: cambiar `const a = anio, b = a - 1;` por `const a = anioA, b = anioB;`.
- `mensualAOA(rows, tdas, anio)` → `mensualAOA(rows, tdas, anioA, anioB)`: cambiar `const a = anio, b = a - 1;` por `const a = anioA, b = anioB;`.

- [ ] **Step 4: Callers de export** (`g00Exp*`) — pasar el par desde `lastX`:

- En cada `g00Exp*` que llama `comparativaAOA`, cambiar `{ anio: lastX.anio, ... }` por `{ anioA: lastX.anio_a, anioB: lastX.anio_b, ... }` (lastX = `lastDetal`/`lastTiendas`/`lastProductos` según el cuadro).
- `g00ExpMensual`: `mensualAOA(lastDetal.mensual, lastDetal.mensual_tdas, lastDetal.anio)` → `mensualAOA(lastDetal.mensual, lastDetal.mensual_tdas, lastDetal.anio_a, lastDetal.anio_b)`.
- `g00ExpPeriodos`: `periodosAOA(lastPeriodos.dias, lastPeriodos.anio)` → `periodosAOA(lastPeriodos.dias, lastPeriodos.anio_a, lastPeriodos.anio_b)`.

- [ ] **Step 5: Renderers en pantalla** — recibir y usar el par. Reemplazos:

- `renderTablaTienda(tiendas, anio)` → `renderTablaTienda(tiendas, anioA, anioB)`; `const a = anio, b = anio - 1;` → `const a = anioA, b = anioB;`. Caller en `loadTiendas`: `renderTablaTienda(data.tiendas, data.anio)` → `renderTablaTienda(data.tiendas, data.anio_a, data.anio_b)`.
- `renderTablaPeriodos(arbol, anio)` (`:804`) → `(arbol, anioA, anioB)`; `const a = anio, b = anio - 1;` → `const a = anioA, b = anioB;`. Caller en `loadPeriodos`: `renderTablaPeriodos(buildArbolPeriodos(data.dias), data.anio)` → `... data.anio_a, data.anio_b)`.
- `renderTablaGrupo(rows, anio)` (`:890`) → `(rows, anioA, anioB)`; idem `a/b`. Caller `loadDetal`: `renderTablaGrupo(data.por_grupo, data.anio)` → `(data.por_grupo, data.anio_a, data.anio_b)`.
- `renderTablaMarcaTipo(rows, anio, kpis)` (`:932`) → `(rows, anioA, anioB, kpis)`; idem. Caller: `renderTablaMarcaTipo(data.por_marca, data.anio, data.kpis)` → `(data.por_marca, data.anio_a, data.anio_b, data.kpis)`.
- `renderTablaMensual(rows, anio, tdas)` (`:993`) → `(rows, anioA, anioB, tdas)`; idem. Caller: `renderTablaMensual(data.mensual, data.anio, data.mensual_tdas)` → `(data.mensual, data.anio_a, data.anio_b, data.mensual_tdas)`.
- `renderTablaArbol(tbodyId, data, anio, opts)` (`:1030`) → `(tbodyId, data, anioA, anioB, opts)`; idem. Callers (Productos negocio/categoria/genero): pasar `data.anio_a, data.anio_b` en vez de `data.anio`.
- `renderKpis(kpis, anio, prefix)`: pasa a recibir `data.anio_a` (los KPIs rotulan solo el año mayor). Callers: `renderKpis(data.kpis, data.anio, ...)` → `renderKpis(data.kpis, data.anio_a, ...)` (sin cambiar la firma interna; solo el argumento).

- [ ] **Step 6: Correr tests + lint**

Run: `node tests/g00_comparativa_export_test.mjs && node tests/g00_periodos_export_test.mjs && php -l informes/g00.php`
Expected: `RESULTADO: OK ...` en ambos node y `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add informes/g00.php tests/g00_comparativa_export_test.mjs tests/g00_periodos_export_test.mjs
git commit -m "feat(g00): rotular tablas y exports con el par de años elegido"
```

---

### Task 6: Verificación integral + E2E

**Files:** ninguno (verificación)

- [ ] **Step 1: Suite + lint completos**

Run:
```bash
php tests/g00_rango_comparacion_test.php && \
node tests/g00_comparativa_export_test.mjs && node tests/g00_periodos_export_test.mjs && \
node tests/export_filename_test.mjs && node tests/o45_export_test.mjs && node tests/o14_reco_export_test.mjs && \
php -l api/lib_g00_rango.php && php -l api/informe_g00.php && php -l informes/g00.php
```
Expected: todos `RESULTADO: OK` / `No syntax errors detected` (incluye no-regresión de los tests de descargas del trabajo previo).

- [ ] **Step 2: E2E navegador (Rafael) en `localhost/plataforma_20` → Dashboard de Ventas:**
  - Default (sin tocar): compara año actual vs anterior; tablas y descargas con esos años. Idéntico a hoy.
  - Elegir Año=2025, Comparar vs=2023 → todas las pestañas (Detal/Grupo/Marca, Tiendas, Periodos, Mensual, Productos) y los Excel rotulan 2025 vs 2023 con datos correctos.
  - Botones "Hasta la fecha" / "Año completo" rellenan Desde/Hasta y al Aplicar consultan el rango esperado.
  - Modo Retail con par no adyacente: el periodo anterior se desfasa −364×años.
  - Validación: elegir menor ≥ mayor → Swal "El año a comparar debe ser menor…" y no consulta.

- [ ] **Step 3:** Esta feature comparte rama y despliegue con la estandarización de descargas (`feature/exports-dataset`). El re-sync a `plataforma_20_produccion` + commit/push + copia al servidor se hace al cerrar la rama (tras el E2E conjunto). Actualizar `changelog_plataforma20.md` y memorias.

---

## Notas de implementación
- **Convención de tests:** PHP puro → `php tests/x.php` (helper `chk()` + `RESULTADO: OK` + `exit($fail)`, como `tests/login_resolver_test.php`). Node golden → copiar la función al `.mjs` (como `tests/o14_kpis_arbol_test.mjs`). Al editar un builder en g00.php, replicar el cambio en su `.mjs`.
- **Drift de líneas:** los números son del estado actual de la rama; localizar por nombre de función si difieren (Task 2/3 dependen de variables definidas en Task 2 Step 3: `$yearAct`, `$anioBIn`, `$desdeAnt`, `$hastaAnt`).
- **Orden:** 1 → 2 → 3 → 4 → 5 → 6. Tasks 2 y 3 tocan el mismo bloque del endpoint; 4 y 5 el mismo g00.php — secuencial obligatorio.
