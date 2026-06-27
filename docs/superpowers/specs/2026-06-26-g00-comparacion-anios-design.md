# Diseño — G00: comparación de dos años a elección (Informe de Ventas)

**Fecha:** 2026-06-26
**Estado:** Aprobado (pendiente revisión del spec por Rafael)
**Rama:** `feature/exports-dataset` (continúa sobre la estandarización de descargas; ambas tocan g00.php)

## 1. Problema

Hoy el "Informe de Ventas" (G00) está estructuralmente atado a comparar **el año en curso vs el inmediatamente anterior**:

- Los selectores Desde/Hasta solo tienen **mes + día**; `dateVal()` (`informes/g00.php:632`) les pega `new Date().getFullYear()` a la fuerza → el usuario no puede expresar otro año.
- El backend (`api/informe_g00.php:37-54`) deriva el periodo "anterior" como el rango pedido **−1 año** (o −364 días en Retail). No existe un parámetro de año.
- El frontend pinta `b = a − 1` en 9 lugares (solo como etiqueta de columna), confiando en las columnas `_act`/`_ant` que ya parte el backend.

Se quiere que el usuario **elija qué dos años comparar** (uno mayor vs uno menor), conservando la estructura y formatos actuales del informe.

## 2. Objetivo

- El usuario selecciona **Año (mayor)** y **Comparar vs (menor)**; **obligatorio mayor > menor**.
- **Default = año actual vs año inmediatamente anterior** (comportamiento de hoy).
- Se **conserva el rango Desde/Hasta (mes/día)**, que se aplica **a ambos años** (comparación parcial/YTD).
- Se agrega un **rango rápido** con dos presets: **"Hasta la fecha"** (Ene-01 → hoy) y **"Año completo"** (Ene-01 → Dic-31).
- Se admite **cualquier par** mayor>menor en el rango **2019..año actual** (incluye años no consecutivos).
- Se **respetan los formatos y estructuras** de todas las pestañas (Detal, Tiendas, Periodos, Productos) y de las descargas.

**Fuera de alcance:** otros informes (O45, O14, Evol, Pagos). Solo G00.

## 3. UI — barra de filtros de G00 (Fila 1)

En `informes/g00.php:218-251` (Fila 1, junto a Desde/Hasta/Calendario/S.S.S):

- **Dos `<select>` de año** como nuevos `filter-group`:
  - `#g00-anio-a` ("Año") y `#g00-anio-b` ("Comparar vs").
  - Opciones: de `año actual` (`date('Y')`) hacia abajo hasta **2019** (orden descendente).
  - Defaults: `#g00-anio-a` = año actual; `#g00-anio-b` = año actual − 1.
- **Rango rápido**: dos botones (clase reutilizando `.g00-btn` existente) **"Hasta la fecha"** y **"Año completo"**:
  - "Hasta la fecha": setea `#g00-desde-mes=1, #g00-desde-dia=1, #g00-hasta-mes=mes(hoy), #g00-hasta-dia=día(hoy)`.
  - "Año completo": setea `desde=01/01, hasta=12/31`.
  - No disparan la consulta por sí solos; el usuario sigue dando **Aplicar** (consistente con el flujo actual).
- **Validación (cliente)**: en `g00Load()`, si `anioB >= anioA` → `Swal.fire('Comparación inválida','El año a comparar debe ser menor que el año principal.','warning')` y **no** consulta.

Se conservan los selectores de mes/día tal cual (estructura intacta).

## 4. Contrato de parámetros (frontend → backend)

`buildParams` (`informes/g00.php:635-647`) cambia:

- `dateVal(prefix)` deja de usar `new Date().getFullYear()` y usa `#g00-anio-a` (el año mayor): devuelve `<anioA>-MM-DD`.
- Se agrega `p.append('anioB', <valor de #g00-anio-b>)`.
- `desde`/`hasta` siguen siendo fechas completas, ahora en `anioA`.
- `cal`, `sss`, y los `FILTER_FIELDS` no cambian.

## 5. Backend — derivación de rangos (`api/informe_g00.php`)

Reemplazar la derivación actual (`:37-54`) por una **función pura testeable**:

```php
/**
 * Deriva los rangos actual/anterior para la comparación de dos años.
 * @param string $desde  fecha completa del periodo MAYOR (YYYY-MM-DD)
 * @param string $hasta  fecha completa del periodo MAYOR (YYYY-MM-DD)
 * @param int    $anioB  año MENOR a comparar
 * @param string $cal    'diaadia' | 'retail'
 * @return array{0:string,1:string,2:string,3:string,4:?string}
 *         [desdeAct, hastaAct, desdeAnt, hastaAnt, error]
 *         error != null si la comparación es inválida (anioB >= anioA).
 */
function g00_rango_comparacion($desde, $hasta, $anioB, $cal) {
    $anioA = (int) substr($hasta, 0, 4);
    $anioB = (int) $anioB;
    if ($anioB <= 0 || $anioB >= $anioA) {
        return [$desde, $hasta, $desde, $hasta, 'rango_anios_invalido'];
    }
    $desdeAct = $desde;
    $hastaAct = $hasta;
    if ($cal === 'retail') {
        $gap = $anioA - $anioB;                       // años de diferencia
        $dias = ' -' . (364 * $gap) . ' days';
        $desdeAnt = date('Y-m-d', strtotime($desdeAct . $dias));
        $hastaAnt = date('Y-m-d', strtotime($hastaAct . $dias));
    } else {                                          // diaadia
        $desdeAnt = g00_set_anio($desdeAct, $anioB);  // mismo mes/día en anioB
        $hastaAnt = g00_set_anio($hastaAct, $anioB);
    }
    return [$desdeAct, $hastaAct, $desdeAnt, $hastaAnt, null];
}

/** Cambia el año de una fecha YYYY-MM-DD a $anio; 29-feb→28-feb si $anio no es bisiesto. */
function g00_set_anio($fecha, $anio) {
    $mmdd = substr($fecha, 5); // MM-DD
    if ($mmdd === '02-29' && !date('L', strtotime($anio . '-01-01'))) {
        $mmdd = '02-28';
    }
    return $anio . '-' . $mmdd;
}
```

- El endpoint lee `$_GET['anioB']` y `$cal`; si faltan `desde`/`hasta`, mantiene el default actual (Ene-01 → hoy del año en curso) y `anioB = añoActual − 1` (preserva el comportamiento por defecto).
- Si `g00_rango_comparacion` devuelve `error`, el endpoint responde `{ok:false, error:'El año a comparar debe ser menor que el año principal.'}` (defensa servidor; el cliente ya valida).
- `$gmin`/`$gmax` (`:56-58`) — la ventana que abarca ambos periodos para el `UNION ALL` — se calcula igual sobre los 4 valores resultantes (funciona sin cambios para años arbitrarios).
- **Salida del par de años**: cada pestaña deja de emitir `anio` (único, `:822/883, 501, 554, 647`) y emite **`anio_a`** (= `(int)substr($hastaAct,0,4)`) y **`anio_b`** (= `$anioB`).

### Rutas con año fijo a reconciliar

- **Mensual** (`:744-757`): hoy fuerza Ene-01→hoy del año en curso. Pasa a:
  `$mensDesA = "$anioA-01-01"; $mensHasA = $hastaAct;`
  `$mensDesB = g00_set_anio($mensDesA,$anioB); $mensHasB = $hastaAnt;` (o el equivalente retail).
  Así la tabla Mensual compara los **dos años elegidos** (Ene→Hasta), no el año en curso.
- **Periodos** (`:507-513`): se elimina el override que fuerza "anterior = −1 año calendario". Periodos usa los mismos `$desdeAnt/$hastaAnt` derivados por la función (año menor, con su `cal`). `$pGmin/$pGmax` se recalcula sobre esos valores.

## 6. Etiquetas — reemplazar `b = a − 1`

El backend ahora entrega el par `anio_a`/`anio_b`. En `informes/g00.php` se reemplazan los 9 sitios `b = a - 1` para usar el par real:

- Renderers en pantalla (`:690, 804, 890, 932, 993, 1030`): `const a = d.anio_a, b = d.anio_b;` (cada uno lee del objeto de datos de su pestaña; hoy ya reciben `anio` y derivan b).
- Builders de export (ya reescritos en este branch): pasan a recibir el par:
  - `comparativaAOA(dim, rows, opts)` → `opts.anioA` y `opts.anioB` (en vez de `opts.anio` + `a-1`).
  - `periodosAOA(dias, anioA, anioB)`.
  - `mensualAOA(rows, tdas, anioA, anioB)`.
  Cada `g00Exp*` pasa `lastX.anio_a` y `lastX.anio_b`.

Los valores numéricos (`_act`/`_ant`) ya vienen partidos por el backend; el front solo cambia **qué números rotula** como año mayor/menor (sin recalcular pertenencia). El orden de columnas y la estructura de las tablas/Excel **no cambian**.

## 7. Datos — verificación anti-duplicado

`cteVentas()` (`:73-85`) hace `UNION ALL` de `Ventas_Detal_PBI` (año en curso) + `Ventas_Detal_Acum_PBI` (histórico). Como es `UNION ALL`, si un año existe en **ambas** tablas se **duplicaría**.

- **Verificación (en el plan, antes de implementar):** consultar `MIN/MAX(YEAR(FECHA))` de cada tabla y confirmar que **no solapan años** (se espera Acum ≤ 2025, PBI = 2026). El default actual (2026 vs 2025) funciona hoy, lo que sugiere disjunción.
- **Si solaparan:** acotar cada SELECT del UNION por su rango de años (p. ej. PBI solo el año en curso) — se decidirá con el dato real. No se implementa salvo que la verificación lo exija.

## 8. Pruebas

- **Backend (PHP, guard de DB no requerido — es lógica pura):** `tests/g00_rango_comparacion_test.php` cubre:
  - diaadia adyacente (2026 vs 2025) = comportamiento actual.
  - diaadia no adyacente (2026 vs 2023).
  - retail con brecha de 1 y de N años (−364×gap).
  - 29-feb en `anioB` no bisiesto → 28-feb.
  - `anioB >= anioA` → error.
- **Frontend (node golden, convención del repo):** extender `tests/g00_periodos_export_test.mjs` y `tests/g00_comparativa_export_test.mjs` para el par de años explícito (header con `anio_a`/`anio_b`, no `a-1`). Nuevo caso en mensual si aplica.
- **E2E navegador (Rafael):** elegir pares (default, no adyacente, retail), validar etiquetas de columna, presets de rango rápido, validación mayor>menor, y que las descargas reflejen el par.

## 9. Despliegue

Tras E2E: junto con la estandarización de descargas (misma rama), re-sync de runtime tocado (`informes/g00.php`, `api/informe_g00.php`) a `plataforma_20_produccion` + commit/push + copia manual al servidor (flujo habitual).

## 10. Riesgos / notas

- **Retail con años no adyacentes:** −364×gap días es una aproximación de alineación retail multi-año; para gaps grandes el desfase de día de semana puede no ser exacto, pero es consistente y predecible. Aceptado.
- **Año completo del año en curso:** incluye meses futuros sin datos (salen 0). Inofensivo.
- **Límite inferior 2019:** asume que Acum arranca en 2019 (memoria del proyecto); se confirma con la verificación de §7. Si el dato real arranca después, se ajusta el límite.
- **Acoplamiento con descargas:** esta función extiende los builders ya reescritos en `feature/exports-dataset`; deben implementarse después de que ese trabajo esté en la rama (ya lo está).
