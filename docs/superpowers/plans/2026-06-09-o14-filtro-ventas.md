# O14 — Filtro de fechas solo-ventas · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hacer que la medida Ventas de O14 acumule histórico (cruzando `Ventas_Detal_PBI` + `Ventas_Detal_Acum_PBI`) con default desde `2025-01-01`, expuesto por un control Desde/Hasta en el topbar que afecta únicamente a ventas.

**Architecture:** En O14 el rango `desde/hasta` ya afecta solo a ventas (siembra/disp/hold son foto actual sin fecha). El cambio es (1) backend: cambiar el default de `$desde` a `2025-01-01` (dispara la unión de `Acum` vía `$inclAcum`); (2) frontend: inyectar un control Desde/Hasta etiquetado "Ventas" en la columna izquierda del topbar (`#topbarDates`) y que `buildParams` lo lea. La maquinaria de `#base`/`llaves`/`v` no cambia, así que una tienda con solo venta histórica aparece como fila.

**Tech Stack:** PHP + sqlsrv (SQL Server), JS vanilla, tests CLI propios (sin phpunit) vía `tests/_endpoint_run_o14.php`.

**Spec:** `docs/superpowers/specs/2026-06-09-o14-filtro-ventas.md`

---

## Estructura de archivos

- **Modify** `api/informe_o14.php:27` — default de `$desde` → `'2025-01-01'` + comentario aclaratorio.
- **Create** `tests/o14_ventas_rango_test.php` — test del caso 245 (positivo con histórico, negativo solo-2026, invariante).
- **Modify** `informes/o14.php` — `o14OnEnter` inyecta el control; `buildParams` lo lee.
- **Modify** `dashboard.php` — CSS del control de fecha de ventas en el topbar.

---

## Task 1: Backend — default de ventas a 2025-01-01 (TDD con el caso 245)

**Files:**
- Test: `tests/o14_ventas_rango_test.php` (crear)
- Modify: `api/informe_o14.php:27`

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/o14_ventas_rango_test.php`:

```php
<?php
// Verifica el filtro de fecha que afecta SOLO a ventas en O14.
// Caso real: negocio 555005911A-NEG en tienda 245 (AKA CENTRO TUNJA) tiene venta 2025-07-19 (en Acum).
//  - Con rango por defecto (backend usa desde=2025-01-01) DEBE aparecer en tab=c con ventas>=1.
//  - Con desde=2026-01-01 NO debe aparecer en 245 (control negativo).
//  - Invariante O14: faltante-sobrante == siembra-(disp+hold) por negocio (ventas no lo altera).
//   php tests/o14_ventas_rango_test.php ["PROVEEDOR"]
$prov   = $argv[1] ?? 'BH BRANDS SAS';
$runner = __DIR__ . '/_endpoint_run_o14.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
$REF = '555005911A'; $COL = 'NEG'; $BOD = '245';

function ep($php, $runner, $prov, $qs, $nul) {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    return json_decode(($a !== false && $b !== false) ? substr($raw, $a, $b - $a + 1) : $raw, true);
}
// Busca el negocio (ref,color) en una bodega dada dentro del árbol tab=c. Devuelve la fila o null.
function buscarNegEnBodega($resp, $bod, $ref, $col) {
    foreach (($resp['grupos'] ?? []) as $g)
        foreach (($g['almacenes'] ?? []) as $a)
            if (rtrim((string)$a['bodega']) === $bod)
                foreach (($a['negocios'] ?? []) as $n)
                    if (rtrim((string)$n['referencia']) === $ref && rtrim((string)$n['color']) === $col) return $n;
    return null;
}
$sum = fn($n, $m) => array_sum($n['valores'][$m] ?? []);
$fail = 0;

// 1) Rango por defecto (backend usa desde=2025-01-01): 245 debe aparecer con venta histórica.
$def = ep($php, $runner, $prov, 'tab=c', $nul);
if (!($def['ok'] ?? false)) { echo "FALLO: tab=c default no ok\n"; $fail = 1; }
$n245 = buscarNegEnBodega($def, $BOD, $REF, $COL);
if (!$n245) { echo "FALLO: $REF-$COL NO aparece en bodega $BOD con rango por defecto\n"; $fail = 1; }
else {
    $v = $sum($n245, 'ventas');
    echo "OK default: $REF-$COL en $BOD presente, ventas=$v\n";
    if ($v < 1) { echo "FALLO: ventas esperada >=1 en $BOD, hubo $v\n"; $fail = 1; }
    if ($sum($n245,'siembra') !== 0 || $sum($n245,'disponible') !== 0 || $sum($n245,'hold') !== 0)
        echo "NOTA: $BOD trae stock actual además de la venta histórica (no es fila solo-ventas)\n";
}

// 2) Control negativo: solo-2026 -> NO debe aparecer en 245.
$y2026 = ep($php, $runner, $prov, 'tab=c&desde=2026-01-01', $nul);
if (!($y2026['ok'] ?? false)) { echo "FALLO: tab=c 2026 no ok\n"; $fail = 1; }
if (buscarNegEnBodega($y2026, $BOD, $REF, $COL)) { echo "FALLO: con desde=2026-01-01 $REF-$COL NO debería estar en $BOD\n"; $fail = 1; }
else echo "OK control: con solo-2026, $REF-$COL ausente en $BOD\n";

// 3) Invariante por negocio (ventas no altera el balance).
foreach (($def['grupos'] ?? []) as $g) foreach (($g['almacenes'] ?? []) as $a) foreach (($a['negocios'] ?? []) as $n) {
    $lhs = $sum($n,'faltante') - $sum($n,'sobrante');
    $rhs = $sum($n,'siembra') - ($sum($n,'disponible') + $sum($n,'hold'));
    if ($lhs !== $rhs) { echo "FALLO: invariante rota en " . $n['negocio'] . ": $lhs != $rhs\n"; $fail = 1; break 3; }
}
if (!$fail) echo "invariante OK\n";

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
```

- [ ] **Step 2: Correr el test y verificar que FALLA**

Run: `php tests/o14_ventas_rango_test.php`
Expected: **FALLO** — con el default actual (`date('Y-01-01')` = 2026-01-01) el negocio `555005911A-NEG` NO aparece en la bodega 245 → línea "FALLO: 555005911A-NEG NO aparece en bodega 245…" y `RESULTADO: FALLO` (exit 1). (El paso 2 control y el invariante pasan.)

- [ ] **Step 3: Cambiar el default del rango de ventas**

En `api/informe_o14.php`, línea 27, reemplazar:

```php
$desde = $_GET['desde'] ?? date('Y-01-01');
```

por:

```php
// desde/hasta delimitan SOLO la ventana de Ventas (siembra/disp/hold son foto actual, sin fecha).
// Default: histórico desde 2025-01-01 → cruza Ventas_Detal_Acum_PBI + Ventas_Detal_PBI (ver $inclAcum).
$desde = $_GET['desde'] ?? '2025-01-01';
```

- [ ] **Step 4: Correr el test y verificar que PASA**

Run: `php tests/o14_ventas_rango_test.php`
Expected: **PASS** — `OK default: 555005911A-NEG en 245 presente, ventas=1`, `OK control: con solo-2026, 555005911A-NEG ausente en 245`, `invariante OK`, `RESULTADO: OK` (exit 0).

- [ ] **Step 5: `php -l` y commit**

Run: `php -l api/informe_o14.php`
Expected: `No syntax errors detected`

```bash
git add api/informe_o14.php tests/o14_ventas_rango_test.php
git commit -m "feat(o14): ventas histórica por defecto (desde 2025-01-01) + test caso 245"
```

---

## Task 2: Frontend — control Desde/Hasta de ventas en el topbar

**Files:**
- Modify: `informes/o14.php` (`o14OnEnter` y `buildParams`)
- Modify: `dashboard.php` (CSS, tras la línea 188 — bloque de reglas `.topbar.topbar--o14`)

- [ ] **Step 1: Inyectar el control en `o14OnEnter`**

En `informes/o14.php`, reemplazar el cuerpo actual de `o14OnEnter`:

```js
  window.o14OnEnter = function(){
    // Topbar estilo G00: título centrado "SIEMBRA / STOCK", sin tablita de fechas, botón Actualizar.
    document.getElementById('pageTitle').textContent = 'SIEMBRA / STOCK';
    document.getElementById('topbar').classList.add('topbar--o14');
    document.getElementById('pageSubtitle').style.display = 'none';
    // Spacer izquierdo (col 1fr) vacío: ocupa la celda para centrar el título; sin tablita de fechas.
    const td = document.getElementById('topbarDates'); td.innerHTML = ''; td.style.display = '';
    const rb = document.getElementById('topbarO14Refresh'); if(rb) rb.style.display = '';
    if (!filtrosInit) { initFiltros(); filtrosInit = true; }
    if(!tabState.b) loadB();
  };
```

por:

```js
  window.o14OnEnter = function(){
    // Topbar estilo G00: título centrado "SIEMBRA / STOCK", botón Actualizar.
    document.getElementById('pageTitle').textContent = 'SIEMBRA / STOCK';
    document.getElementById('topbar').classList.add('topbar--o14');
    document.getElementById('pageSubtitle').style.display = 'none';
    // Columna izquierda del topbar: control de fecha que afecta SOLO a ventas (default histórico desde 2025-01-01).
    const td = document.getElementById('topbarDates'); td.style.display = '';
    if (!document.getElementById('o14-vdesde')) {
      const hoy = new Date().toISOString().slice(0,10);
      td.innerHTML =
        '<div class="o14-vfilter"><span class="o14-vfilter-lbl">Ventas</span>'
        + '<label>Desde<input type="date" id="o14-vdesde" value="2025-01-01"></label>'
        + '<label>Hasta<input type="date" id="o14-vhasta" value="'+hoy+'"></label></div>';
    }
    const rb = document.getElementById('topbarO14Refresh'); if(rb) rb.style.display = '';
    if (!filtrosInit) { initFiltros(); filtrosInit = true; }
    if(!tabState.b) loadB();
  };
```

- [ ] **Step 2: Leer el control en `buildParams`**

En `informes/o14.php`, reemplazar el inicio de `buildParams`:

```js
  function buildParams(tab){
    const hoy = new Date();
    const desde = hoy.getFullYear()+'-01-01', hasta = hoy.toISOString().slice(0,10);
    const p = new URLSearchParams({ tab:tab, desde:desde, hasta:hasta });
```

por:

```js
  function buildParams(tab){
    const hoy = new Date().toISOString().slice(0,10);
    const desde = val('o14-vdesde') || '2025-01-01';   // rango SOLO de ventas
    const hasta = val('o14-vhasta') || hoy;
    const p = new URLSearchParams({ tab:tab, desde:desde, hasta:hasta });
```

(El resto de `buildParams` —`cia`, `FILTER_FIELDS`, el bloque `reco`— no cambia. `val(id)` ya existe en el archivo.)

- [ ] **Step 3: CSS del control en `dashboard.php`**

En `dashboard.php`, justo después de la línea 188 (`.topbar.topbar--o14 .topbar-actions { justify-self: end; }`), añadir:

```css
        .topbar.topbar--o14 .o14-vfilter { display: flex; align-items: center; gap: 10px; }
        .o14-vfilter-lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 700; }
        .o14-vfilter label { display: flex; flex-direction: column; gap: 2px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-light); font-weight: 600; }
        .o14-vfilter input[type="date"] { font-family: 'Space Grotesk', sans-serif; font-size: 11px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 6px; background: white; color: var(--text); }
```

- [ ] **Step 4: Lint y checks estáticos**

Run: `php -l informes/o14.php && php -l dashboard.php`
Expected: `No syntax errors detected` en ambos.

Run: `grep -c "o14-vdesde\|o14-vhasta" informes/o14.php`
Expected: `4` (2 ids en `o14OnEnter` + 2 lecturas en `buildParams`).

- [ ] **Step 5: Commit**

```bash
git add informes/o14.php dashboard.php
git commit -m "feat(o14): control Desde/Hasta de ventas en topbar (afecta solo ventas)"
```

---

## Task 3: Regresión + verificación final

**Files:** ninguno (solo corre suites existentes).

- [ ] **Step 1: Regresión O14 y motor**

Run: `php tests/o14_recomendador_test.php`
Expected: `27/27` verde (exit 0).

Run: `php tests/o14_filtros_test.php`
Expected: `RESULTADO: OK …` (exit 0).

Run: `php tests/o14_ventas_rango_test.php`
Expected: `RESULTADO: OK` (exit 0).

- [ ] **Step 2: Regresión G00 (comparte `lib_refs`/cache, no debe romperse)**

Run: `php tests/g00_detal_smoke_test.php`
Expected: permutaciones `ok=1` (exit 0). Si el runner difiere, usar el smoke G00 vigente del repo.

- [ ] **Step 3: Verificación E2E en navegador (Rafael)** — checklist, no automatizable:
  - El control **Desde/Hasta** etiquetado **"Ventas"** aparece a la **izquierda del título**, con default `2025-01-01` / hoy.
  - Al pulsar **Actualizar**, en "Por tienda" el negocio **555005911A-NEG** aparece bajo grupo **AKA / tienda 245** con su venta de 2025.
  - Siembra / Stock / Faltante / Sobrante **no cambian** al mover el rango.
  - Acotar **Desde a 2026-01-01** y Actualizar → 555005911A-NEG **desaparece** de 245.
  - Cambiar de pestaña dentro de O14 **conserva** las fechas elegidas.

---

## Notas de ejecución

- **Costo:** con el default llegando a 2025, cada carga (y cada test que use el rango por defecto) escanea `Ventas_Detal_Acum_PBI` (~2.7M filas, ~10-15s). Esperar tiempos de test de ~30-60s. Tradeoff aceptado en el spec.
- **Sin migración de datos.** Cambios solo de comportamiento/UI.
- **Rama:** continuar en `feat/o14-topbar-g00-style` (no mergeada; acumula el trabajo de O14).
