# G00 — S.S.S en Detalle Tiendas y Periodos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el filtro S.S.S (Same Store) también se aplique a las pestañas Detalle Tiendas (`tab=tiendas`) y Ventas Por Periodos (`tab=periodos`) de G00.

**Architecture:** Reutilizar la cláusula `$sameStoreClause` (EXISTS sobre `Bodegas` por apertura/cierre) que ya existe y se aplica solo con `?sss=same`. Inyectarla en el `WHERE` del CTE de cada uno de los dos tabs y añadir sus parámetros en el orden textual correcto. Tiendas usa los `$desdeAnt/$hastaAct` globales; Periodos usa una variante local con su fecha de año-anterior **calendario** (`$pAntDesde`).

**Tech Stack:** PHP 8 + `sqlsrv` (SQL Server, `INTEGRACION.dbo`). Tests CLI que subprocesan el endpoint real vía `tests/_endpoint_run.php`.

**Spec:** `docs/superpowers/specs/2026-06-16-g00-sss-tiendas-periodos-design.md`

---

## File Structure

- **Modify** `api/informe_g00.php` — bloque `tab=tiendas` (~L378-416) y bloque `tab=periodos` (~L473-498).
- **Create** `tests/g00_sss_test.php` — test de regresión (tiendas + periodos) usando el caso real AKA FUNZA / proveedor BH BRANDS SAS.

**Dato de prueba (verificado en BD):** AKA FUNZA = `Bodegas` COD `621`, cía 7, grupo AKA, abrió 2015-11-01, **cerró 2026-03-08**. El proveedor **BH BRANDS SAS** le vendió en mayo 2025 (4.1M). Periodo de prueba: `desde=2026-05-01&hasta=2026-05-31` (mayo 2026 vs 2025). El JSON de `tab=tiendas` trae `tiendas:[{cod,nombre,grupo,val_act,val_ant,...}]`; el de `tab=periodos` trae `dias:[{mes,dia,val_act,val_ant,ups_act,ups_ant}]`.

---

## Task 1: S.S.S en `tab=tiendas`

**Files:**
- Create: `tests/g00_sss_test.php`
- Modify: `api/informe_g00.php` (CTE `vt` + su array de params)

- [ ] **Step 1: Escribir el test (rojo)**

Create `tests/g00_sss_test.php`:

```php
<?php
// S.S.S (Same Store) en tab=tiendas y tab=periodos de G00.
// Caso real: BH BRANDS SAS vendió en AKA FUNZA (COD 621, grupo AKA) en mayo 2025.
// AKA FUNZA cerró el 2026-03-08, así que para mayo 2026 vs 2025 con sss=same debe quedar excluida.
//   php tests/g00_sss_test.php ["PROVEEDOR"]

$prov   = $argv[1] ?? 'BH BRANDS SAS';
$runner = __DIR__ . '/_endpoint_run.php';
$php    = PHP_BINARY;
$nul    = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
$PER    = 'desde=2026-05-01&hasta=2026-05-31';

function call_endpoint($php, $runner, $prov, $qs, $nul) {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg($qs) . ' 2>' . $nul;
    $raw = (string) shell_exec($cmd);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    $json = ($a !== false && $b !== false && $b >= $a) ? substr($raw, $a, $b - $a + 1) : $raw;
    return json_decode($json, true);
}
function tiene_funza($d) {
    foreach (($d['tiendas'] ?? []) as $t) {
        if (trim((string)($t['cod'] ?? '')) === '621') return true;
    }
    return false;
}

$fail = 0;

// 1) tab=tiendas, No Same -> AKA FUNZA presente (control: vendió en may-2025)
$nos = call_endpoint($php, $runner, $prov, "tab=tiendas&$PER&sss=nosame", $nul);
if (!($nos['ok'] ?? false)) { echo "FALLO: tiendas nosame no ok\n"; exit(1); }
$funzaNoSame = tiene_funza($nos);
echo "tiendas nosame: FUNZA=".($funzaNoSame?'SI':'NO')." (tiendas=".count($nos['tiendas']??[]).")\n";
if (!$funzaNoSame) { echo "FALLO: con nosame AKA FUNZA debería aparecer (precondición del caso)\n"; $fail=1; }

// 2) tab=tiendas, Same -> AKA FUNZA ausente (cerró 2026-03-08)
$sam = call_endpoint($php, $runner, $prov, "tab=tiendas&$PER&sss=same", $nul);
if (!($sam['ok'] ?? false)) { echo "FALLO: tiendas same no ok\n"; exit(1); }
$funzaSame = tiene_funza($sam);
echo "tiendas same:   FUNZA=".($funzaSame?'SI':'NO')." (tiendas=".count($sam['tiendas']??[]).")\n";
if ($funzaSame) { echo "FALLO: con same AKA FUNZA NO debería aparecer (cerrada)\n"; $fail=1; }

// 3) invariante: tiendas(same) ⊆ tiendas(nosame)
$codsNoSame = array_map(fn($t)=>trim((string)$t['cod']), $nos['tiendas']??[]);
foreach (($sam['tiendas']??[]) as $t) {
    if (!in_array(trim((string)$t['cod']), $codsNoSame, true)) {
        echo "FALLO: tienda ".$t['cod']." aparece en same pero no en nosame\n"; $fail=1;
    }
}

echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK\n";
exit($fail);
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php tests/g00_sss_test.php "BH BRANDS SAS"`
Expected: **FALLO** — con `sss=same` AKA FUNZA todavía aparece (la cláusula aún no se aplica a `tab=tiendas`), así que el chequeo 2 falla. (Ignorar warnings de xdebug/php_dio/openssl.)

- [ ] **Step 3: Implementar — inyectar la cláusula en el CTE `vt`**

En `api/informe_g00.php`, dentro del bloque `if ($tab === 'tiendas')`, en el `WHERE` del CTE `vt`:

Reemplazar:
```php
            WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
              $filtroExtra
        )
```
por:
```php
            WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
              $filtroExtra
              $sameStoreClause
        )
```

- [ ] **Step 4: Implementar — añadir `$sameStoreParams` en el orden correcto**

En el mismo bloque, el `array_merge` de `$params`. Reemplazar:
```php
    $params = array_merge(
        [$gmin, $gmax, $gmin, $gmax],                 // CTE pushdown
        [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt], // vt OR-filter (act, ant)
        $paramsExtra,
        [$desdeAct,$hastaAct, $desdeAnt,$hastaAnt,    // val_act / val_ant
         $desdeAct,$hastaAct, $desdeAnt,$hastaAnt,    // ups_act / ups_ant
         $desdeAct,$hastaAct]                          // margen_prom
    );
```
por:
```php
    $params = array_merge(
        [$gmin, $gmax, $gmin, $gmax],                 // CTE pushdown
        [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt], // vt OR-filter (act, ant)
        $paramsExtra,
        $sameStoreParams,                             // S.S.S (vacío si no es same)
        [$desdeAct,$hastaAct, $desdeAnt,$hastaAnt,    // val_act / val_ant
         $desdeAct,$hastaAct, $desdeAnt,$hastaAnt,    // ups_act / ups_ant
         $desdeAct,$hastaAct]                          // margen_prom
    );
```
(El orden textual del SQL es: CTE → WHERE fechas → `$filtroExtra` → `$sameStoreClause` → CASE del SELECT. `$sameStoreParams` es `[]` salvo `sss=same`, así que No Same no cambia.)

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php tests/g00_sss_test.php "BH BRANDS SAS"`
Expected: `RESULTADO: OK` (FUNZA=SI en nosame, FUNZA=NO en same, invariante OK).

- [ ] **Step 6: Lint + commit**

```bash
php -l api/informe_g00.php
git add tests/g00_sss_test.php api/informe_g00.php
git commit -m "feat(g00): aplicar S.S.S (same-store) a tab=tiendas + test caso AKA FUNZA"
```
(Terminar con el trailer Co-Authored-By.)

---

## Task 2: S.S.S en `tab=periodos` (fecha calendario)

**Files:**
- Modify: `api/informe_g00.php` (bloque `tab=periodos`)
- Modify: `tests/g00_sss_test.php` (añadir assertions de periodos)

- [ ] **Step 1: Ampliar el test (rojo)**

En `tests/g00_sss_test.php`, **antes** de la línea final `echo $fail ? ...`, insertar:

```php
// ---- PERIODOS: same-store reduce las ventas del año anterior (excluye tiendas cerradas) ----
function sum_periodos($d, $campo) {
    $s = 0; foreach (($d['dias'] ?? []) as $x) $s += (float)($x[$campo] ?? 0); return $s;
}
$pNos = call_endpoint($php, $runner, $prov, "tab=periodos&$PER&sss=nosame", $nul);
$pSam = call_endpoint($php, $runner, $prov, "tab=periodos&$PER&sss=same", $nul);
if (!($pNos['ok'] ?? false) || !($pSam['ok'] ?? false)) { echo "FALLO: periodos no ok\n"; $fail=1; }
$antNos = sum_periodos($pNos, 'val_ant');
$antSam = sum_periodos($pSam, 'val_ant');
echo "periodos val_ant: nosame=".number_format($antNos)."  same=".number_format($antSam)."\n";
// BH BRANDS vendió en AKA FUNZA (cerrada) en may-2025 -> same debe excluir esas ventas del año anterior
if (!($antSam < $antNos)) { echo "FALLO: periodos same val_ant debería ser MENOR que nosame (excluye tienda cerrada)\n"; $fail=1; }
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php tests/g00_sss_test.php "BH BRANDS SAS"`
Expected: **FALLO** en la parte de periodos — sin el cambio, `tab=periodos` no aplica same-store, así que `antSam == antNos` y el `<` falla. (Las assertions de tiendas siguen en verde.)

- [ ] **Step 3: Implementar — clause local con fecha calendario**

En `api/informe_g00.php`, dentro de `if ($tab === 'periodos')`, **después** de la línea:
```php
    $pGmax = ($hastaAct   > $pAntHasta) ? $hastaAct  : $pAntHasta;
```
insertar:
```php
    // S.S.S en Periodos: misma cláusula EXISTS, pero con la fecha de año-anterior CALENDARIO
    // de esta pestaña ($pAntDesde), no la global ($desdeAnt). Vacío salvo sss=same.
    $sameStorePeriodos = ''; $ssParamsPeriodos = [];
    if ($sss === 'same') { $sameStorePeriodos = $sameStoreClause; $ssParamsPeriodos = [$pAntDesde, $hastaAct]; }
```

- [ ] **Step 4: Implementar — inyectar en el WHERE y en los params**

En el mismo bloque, el `WHERE` del CTE. Reemplazar:
```php
        WHERE 1=1
          $filtroExtra
        GROUP BY MONTH(FECHA), DAY(FECHA)
```
por:
```php
        WHERE 1=1
          $filtroExtra
          $sameStorePeriodos
        GROUP BY MONTH(FECHA), DAY(FECHA)
```

Y el `array_merge` de `$params`. Reemplazar:
```php
    $params = array_merge(
        [$pGmin, $pGmax, $pGmin, $pGmax],            // CTE pushdown
        [$desdeAct,$hastaAct, $pAntDesde,$pAntHasta, // val_act / val_ant
         $desdeAct,$hastaAct, $pAntDesde,$pAntHasta],// ups_act / ups_ant
        $paramsExtra
    );
```
por:
```php
    $params = array_merge(
        [$pGmin, $pGmax, $pGmin, $pGmax],            // CTE pushdown
        [$desdeAct,$hastaAct, $pAntDesde,$pAntHasta, // val_act / val_ant
         $desdeAct,$hastaAct, $pAntDesde,$pAntHasta],// ups_act / ups_ant
        $paramsExtra,
        $ssParamsPeriodos                            // S.S.S (vacío si no es same) — va al final: el WHERE es textualmente posterior al SELECT
    );
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php tests/g00_sss_test.php "BH BRANDS SAS"`
Expected: `RESULTADO: OK` (tiendas same/nosame OK + `periodos val_ant: nosame=... same=...` con same estrictamente menor).

- [ ] **Step 6: Lint + commit**

```bash
php -l api/informe_g00.php
git add tests/g00_sss_test.php api/informe_g00.php
git commit -m "feat(g00): aplicar S.S.S a tab=periodos con fecha calendario + assertions"
```
(Terminar con el trailer Co-Authored-By.)

---

## Task 3: No-regresión y handoff

**Files:** ninguno (salvo correcciones que surjan)

- [ ] **Step 1: Suite G00 + lint final**

Run:
```bash
php tests/g00_sss_test.php "BH BRANDS SAS"
php tests/g00_detal_smoke_test.php "BELTRANY SAS"
php tests/g00_mensual_test.php "BELTRANY SAS"
php tests/g00_productos_test.php "BELTRANY SAS"
php -l api/informe_g00.php
```
Expected: el nuevo test `RESULTADO: OK`; los de detal/mensual/productos `OK` (sin regresión — esos tabs no se tocaron); `No syntax errors detected`.

- [ ] **Step 2: Commit (si hubo correcciones)**

```bash
git add api/informe_g00.php tests/g00_sss_test.php
git commit -m "test(g00): suite verde S.S.S + no-regresion"
```
(Solo si Step 1 obligó a tocar algo. Terminar con el trailer Co-Authored-By.)

- [ ] **Step 3: Handoff E2E a Rafael** (navegador). NO ejecutar navegador aquí.

Checklist (REPORTES → Ventas, periodo mayo 2026 vs mayo 2025):
1. **Detalle Tiendas** con **Same** → AKA FUNZA (y demás tiendas cerradas en el rango) **desaparecen**; con **No Same** vuelven a aparecer.
2. **Ventas Por Periodos** con **Same** → los totales del año anterior **bajan** respecto a No Same (se descuentan las tiendas cerradas).
3. **Detal**, **Mensual** y **Productos** se comportan igual que antes (sin cambios).

> Tras el OK de Rafael: push + PR de `fix/g00-sss-tiendas-periodos` contra `main`.

---

## Self-Review (autor del plan)

**1. Cobertura del spec:**
- Detalle Tiendas aplica S.S.S (fechas globales) → Task 1. ✓
- Periodos aplica S.S.S con fecha **calendario** (`$pAntDesde`) → Task 2 (variable local `$sameStorePeriodos`/`$ssParamsPeriodos`). ✓
- No Same idéntico a hoy → `$sameStoreParams`/`$ssParamsPeriodos` vacíos salvo `sss=same`; verificado por no-regresión Task 3. ✓
- Otras pestañas intactas → no se tocan; cubierto por Task 3 (detal/mensual/productos). ✓
- Test del caso tienda cerrada (AKA FUNZA) presente con nosame / ausente con same → Task 1; + efecto en periodos → Task 2. ✓

**2. Placeholders:** ninguno; todo el código (test + ediciones) es literal.

**3. Consistencia de tipos/nombres:** `$sameStoreClause`/`$sameStoreParams` ya existen (L121-132) y se reutilizan tal cual en Task 1; en Task 2 se reutiliza el **texto** de `$sameStoreClause` en `$sameStorePeriodos` con params `[$pAntDesde,$hastaAct]`. Claves del JSON usadas en el test (`tiendas[].cod`, `dias[].val_ant`) coinciden con lo que emite el endpoint (L440-465 tiendas; L506-512 periodos). Runner `tests/_endpoint_run.php` y patrón `call_endpoint` idénticos a los tests G00 existentes. ✓
