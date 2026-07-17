# Diseño — Calentar el catálogo de filtros de evol (sub-proyecto C)

**Fecha:** 2026-07-17
**Estado:** aprobado, listo para plan
**Contexto previo:** [[plataforma-20-prewarm-layer]], [[plataforma-20-evol-cache-disco]], merge `1811e4f` (rendimiento de informes), merge `4b9d423` (proveedor vacío ≠ fallo).

## Problema

Tras el arreglo del cache de filtros (commit `4ef8ec4`, "filtros antes de #base"), un **hit** del catálogo de filtros de evol se sirve instantáneo desde `cache/evol_filtros_<md5>.json`. Pero el **miss** (primera carga del día del primer aliado de cada proveedor, y tras cada cambio de día) sigue construyendo `#base` desde cero: **~204 s medidos** (3.4 min; variable según la RDS).

El prebuild nocturno (`warmProveedor`) calienta el **dataset** de evol/o45/o14 (`tab=data`), pero **no** el catálogo de filtros. Así que el primer aliado del día paga ese miss de ~204 s en evol.

Los otros tres endpoints con catálogo (o45/o14/geo) tienen miss de 2–5 s: fuera de alcance. **Solo evol.**

## Objetivo

Que el primer aliado del día **nunca** pague el miss de filtros de evol: el nocturno (y el login-prewarm) dejan el cache diario de filtros ya escrito, de modo que el endpoint siempre encuentre un hit.

## Idea central

`warmProveedor`, en su rama de evol, **ya materializa `evol_cache_base`** (`ensureEvolCacheBase`) para calentar el dataset. En ese punto `cache_base` está caliente y contiene **todas las columnas** que el catálogo de filtros necesita (verificado): `marca, tipo, categoria, subcategoria, genero, publico_objetivo, referencia, negocio, grupo, nombre, bodega`.

Por tanto el catálogo de filtros se **deriva** de `cache_base` con un solo `SELECT DISTINCT` (~ms), en vez de reconstruir `#base` (~204 s). El costo incremental de calentar filtros es casi nulo: se cuelga de una materialización que ya ocurre.

**Paridad verificada (oráculo, 2026-07-17):** el catálogo derivado de `cache_base` es **idéntico** al del build vivo del endpoint. BH BRANDS SAS: 1151 combos, 0 diffs. D&E OLAM SAS: 37 combos, 0 diffs.

## Arquitectura

### Componente 1 — `evolBuildFiltros($conn, $ekey): array`
Ubicación: `api/lib_evol_cache.php` (junto a `ensureEvolCacheBase`, que produce la fuente).

`SELECT DISTINCT` sobre `INTEGRACION.dbo.evol_cache_base WHERE cache_key = ? AND bodega <> 'CEDI'`, mapeado al **mismo shape de combo** que hoy produce `informe_evol.php` en el bloque `tab=filtros`:
```
['marca','tipo','categoria','subcategoria','genero','publico',
 'referencia','negocio','grupo','tienda','tienda_cod']
```
(`publico` ← `publico_objetivo`; `tienda` ← `nombre`; `tienda_cod` ← `rtrim(bodega)`; `grupo` ← `ISNULL(grupo,'')`.)

Devuelve `array` de combos en éxito, o `['error'=>...]` si el `SELECT` falla (para que el caller aplique ok-gate y no escriba un cache envenenado). Fuente **única** de la derivación (no se duplica el SQL en warmProveedor).

**Paridad de conjunto, no de orden:** el catálogo es una lista de combinaciones que el front consume como catálogo (cascadas/dedup por campo); el orden de la lista no es significativo. La derivación y el endpoint deben producir el **mismo conjunto** de combos, no necesariamente en el mismo orden ni byte-idénticos. El test de paridad compara como conjunto.

### Componente 2 — escritura del cache diario en `warmProveedor`
Ubicación: `api/lib_prewarm.php`, rama evol, **después** de `ensureEvolCacheBase` (que ya se llama).

Tras materializar `cache_base` para el dataset:
1. `$combos = evolBuildFiltros($conn, $ek)`
2. Escribir `cache/evol_filtros_<md5($proveedor)>.json` con el shape idéntico al del endpoint:
   `['ok'=>true, 'tab'=>'filtros', 'combos'=>$combos]` (via `json_encode`, `@file_put_contents`).

Se reporta en `$out['evol_filtros']` (`'warmed'` / `'vacio'` / `'failed'`) sin alterar el reporte existente de `$out['evol']` (dataset). El nombre del archivo y el shape deben coincidir EXACTAMENTE con lo que lee `informe_evol.php:22` (el hit de B), o el hit no se serviría.

### Lo que NO cambia
- `informe_evol.php` queda intacto. B ya sirve el hit del cache diario antes de construir `#base`. C solo garantiza que el archivo exista antes de que llegue el aliado.
- Si el nocturno no corrió (fallback), el endpoint construye `#base` como hoy: degradación conocida, nada se rompe.
- o45/o14/geo: sin cambios.

## Frescura y concurrencia
- El cache diario de filtros es **por día** (el endpoint valida `date('Y-m-d', filemtime) === date('Y-m-d')`). El nocturno lo escribe a las ~04:00 → válido todo el día. El login-prewarm (`onlyIfStale=true`) lo refresca si el proveedor no fue calentado.
- Escritura atómica no crítica (es un catálogo regenerable); se mantiene el patrón `@file_put_contents` que ya usa el endpoint. Si dos procesos lo escriben, ambos escriben el mismo contenido del día.
- `ok-gate`: solo escribir si `ensureEvolCacheBase` tuvo éxito y la derivación no dio error. Un proveedor sin datos (cache_base sin filas) produce `combos=[]` → se reporta `'vacio'` y **no** se escribe archivo (coherente con [[plataforma-20]] fix `29a9ba8`: vacío ≠ fallo).

## Testing
1. **Test de paridad permanente** (`tests/evol_filtros_paridad_test.php`): el catálogo derivado de `cache_base` (`evolBuildFiltros`) == el catálogo del endpoint vivo (`informe_evol.php?tab=filtros`), para al menos un proveedor con datos. Convierte el oráculo del brainstorming en regresión: si alguien cambia el catálogo de un lado, el test falla hasta alinear el otro. Es la red contra el drift entre las dos fuentes.
2. **Test de calentamiento** (extender `tests/prewarm_vacio_test.php` o nuevo): tras `warmProveedor(prov, false)`, el archivo `cache/evol_filtros_<md5>.json` existe, es de hoy, y su contenido es un hit válido (`ok`, `combos` no vacío para un proveedor con datos).
3. **Regresión existente:** `verificar_prewarm --run` (warmed/skipped), `filtros_cache_test` (los 4 endpoints siguen sirviendo su hit), `verificar_evol_cache --paridad`.

## Fuera de alcance
- o45/o14/geo (miss de filtros 2–5 s, tolerable).
- Cambiar el endpoint para derivar de `cache_base` en el miss (mantiene `#base` como fallback; el nocturno evita el miss en la práctica).
- El TTL de `evol_cache_base` en la BD (120 min) no afecta: warmProveedor deriva **inmediatamente** después de materializar, dentro de la misma corrida.

## Riesgos
- **Drift entre las dos fuentes del catálogo** (endpoint `#base` vs derivado `cache_base`): mitigado por el test de paridad permanente (1).
- **`cache_base` incompleto para el catálogo**: descartado por el oráculo (paridad exacta en 2 proveedores). El test permanente lo vigila hacia el futuro.
