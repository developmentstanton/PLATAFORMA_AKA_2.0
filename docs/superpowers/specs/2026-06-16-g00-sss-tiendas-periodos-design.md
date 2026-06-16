# G00 — Extender el filtro S.S.S (Same Store) a Detalle Tiendas y Ventas Por Periodos

**Fecha:** 2026-06-16
**Informe:** G00 (Dashboard de Ventas)
**Archivo:** `api/informe_g00.php` (solo backend; sin frontend)

## Problema

El filtro **S.S.S (Same Store)** del topbar de G00 solo se aplica hoy a 3 vistas: **Detal** (consolidado + Mensual) y **Ventas Por Productos**. Las pestañas **Detalle Tiendas** (`tab=tiendas`) y **Ventas Por Periodos** (`tab=periodos`) **no** lo aplican, por lo que el botón Same/No-Same no tiene efecto en ellas.

Caso reportado por Rafael: comparando **mayo 2026 vs mayo 2025** con **Same** activo, en Detalle Tiendas aparece **AKA FUNZA** (cía 7, COD 621), que abrió 2015-11-01 y **cerró 2026-03-08**. Como vendió en mayo 2025 (estaba abierta) y la pestaña no filtra por same-store, sigue apareciendo con ventas 2025 y 0 en 2026.

Verificado en BD: AKA FUNZA tiene una sola ficha en `Bodegas`; el `EXISTS` de same-store para mayo-2026 vs 2025 da **"no pasa"** correctamente. O sea: la lógica de same-store es correcta; simplemente esos dos tabs no la invocan.

## Objetivo

Que al elegir **Same**, **Detalle Tiendas** y **Ventas Por Periodos** filtren igual que el resto: solo tiendas abiertas en el periodo actual **y** en su comparativo del año anterior. Con **No Same**, comportamiento idéntico al actual.

## Diseño

La cláusula reutilizable ya existe (`api/informe_g00.php` ~líneas 121-132):

```sql
AND EXISTS (
  SELECT 1 FROM INTEGRACION.dbo.Bodegas sb WITH (NOLOCK)
  WHERE sb.COD = v.BODEGA AND sb.CIA = 7
    AND (sb.FECHA_APERTURA IS NULL OR sb.FECHA_APERTURA <= ?)   -- inicio periodo año anterior
    AND (sb.FECHA_CIERRE   IS NULL OR sb.FECHA_CIERRE   >= ?)   -- fin periodo actual
)
```

Lee `v.BODEGA`, así que puede inyectarse en cualquier CTE que seleccione de `ventas v`. Se aplica **solo cuando `?sss=same`** (por defecto `$sameStoreClause` es `''`).

### 1. Detalle Tiendas (`tab=tiendas`, CTE `vt`)

- Agregar `$sameStoreClause` en el `WHERE` del CTE `vt`, inmediatamente después de `$filtroExtra`.
- Insertar `$sameStoreParams` en el array de parámetros en el orden textual correcto: **después de `$paramsExtra`** (que alimenta `$filtroExtra`) y **antes** del bloque de parámetros del `SELECT` (val_act/val_ant/…). Por defecto `$sameStoreParams` es `[]`, así que con No Same el orden no cambia.
- Usa los `$desdeAnt`/`$hastaAct` **globales**, consistentes con cómo `vt` ya compara act vs ant.

### 2. Ventas Por Periodos (`tab=periodos`, CTE de `ventas v`)

- Periodos calcula su **propio** año anterior alineado por **calendario**: `$pAntDesde = desdeAct − 1 año`, `$pAntHasta = hastaAct − 1 año`.
- **Decisión (Rafael):** usar la **fecha de calendario** para el chequeo de apertura → construir un clause/params **local** con `[$pAntDesde, $hastaAct]` en vez de los globales `[$desdeAnt, $hastaAct]`. Así el "abierta en el año anterior" coincide con el comparativo que esa pestaña realmente muestra.
- Inyectar el clause local tras `$filtroExtra` en el `WHERE` (que hoy es `WHERE 1=1 $filtroExtra`), y **anexar** sus params al final del array (el `WHERE` va textualmente después del `SELECT`, así que sus params van al final).

### Variable local para Periodos

```php
$sameStorePeriodos = '';
$ssParamsPeriodos  = [];
if ($sss === 'same') {
    $sameStorePeriodos = $sameStoreClause;          // mismo texto SQL
    $ssParamsPeriodos  = [$pAntDesde, $hastaAct];    // pero con fecha calendario
}
```

(Se reutiliza el **texto** de `$sameStoreClause`; solo cambian los parámetros.)

## Comportamiento esperado

- **Mayo 2026 vs 2025, Same:** AKA FUNZA **desaparece** de Detalle Tiendas (y de Periodos si tuviera grano ahí); en general solo quedan tiendas presentes en ambos años.
- **No Same:** sin cambios respecto a hoy.
- Las otras pestañas (Detal, Mensual, Productos) **no se tocan**.

## Testing

Test de regresión nuevo (`tests/g00_sss_tiendas_test.php`) con un proveedor que venda en una tienda **cerrada** dentro del rango de comparación (caso AKA FUNZA / grupo AKA, o equivalente del proveedor de prueba):

1. `tab=tiendas&sss=same` (mayo 2026 vs 2025) → la tienda cerrada **no** aparece en las filas de tienda.
2. `tab=tiendas&sss=nosame` → la tienda cerrada **sí** aparece (control).
3. Invariante: el conjunto de tiendas con `sss=same` ⊆ el de `sss=nosame`.
4. No-regresión: `tab=tiendas` sin `sss` (default) devuelve lo mismo que antes del cambio (smoke).

Además: `php -l` limpio y la suite G00 existente (`g00_detal_smoke`, `g00_mensual`, `g00_productos`) sin cambios.

## Fuera de alcance

- Frontend (el toggle Same/No-Same ya envía `?sss=`).
- Cambiar la definición de same-store (apertura/cierre) — se reutiliza tal cual.
- Aplicar S.S.S a `tab=filtros` (catálogo, no compara años).
