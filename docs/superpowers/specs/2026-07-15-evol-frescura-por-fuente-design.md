# EVOL — Frescura por stamp de fuente (alinear a O45)

**Fecha:** 2026-07-15
**Autor:** Rafael Lancheros (+ Claude)
**Estado:** Diseño para aprobación

## Problema

El informe de **Evolución Histórica** (evol) se siente lento en **la primera carga del día**, a pesar de tener cache en disco (mergeado 2026-07-10) y capa de prewarm/prebuild. En cargas siguientes va rápido.

Evidencia recogida (2026-07-15):
- Log de login-prewarm: `BH BRANDS SAS: o14c=warmed evol=skipped o45=warmed`. **evol siempre sale `skipped`** mientras o14c/o45 sí se calientan.
- Los archivos de cache de evol en disco tienen timestamp **posterior** al prewarm → el prewarm los saltó, y luego el endpoint los reconstruyó en la primera carga real (la lenta).
- No existe **ninguna tarea programada** en la máquina que corra el prebuild nocturno.

## Causa raíz

Evol encadena **dos** capas de cache y las dos se invalidan por la razón equivocada:

1. **`INTEGRACION.dbo.evol_cache_base`** (tabla intermedia, la operación cara: escanea Ventas_Detal / mov_inv / historico_*). Su frescura la decide `evolCacheFresco()` con un **TTL de reloj de 120 minutos** (`creado > now-120min`), **no** por cambio de datos.

2. **Payload en disco** (`cache/evol_<key>.json.gz` + `.stamp`). Su `.stamp` guarda el `creado` de `evol_cache_base` al momento de construir.

El endpoint (`api/informe_evol.php`) hace, en `tab=data` cache-mode:

```
:96   ensureEvolCacheBase(...)          // INCONDICIONAL, ANTES del disco
:106  if (tab==data && cacheMode) {
:111    if (evolDiskFresh(ekey)) serve  // compara .stamp vs evol_cache_base.creado
:117    else lock + build + write disk
```

Secuencia en la primera carga del día:
1. `ensureEvolCacheBase` ve la base con `creado` de anoche (>120 min) → **la reconstruye desde la fuente** (lento) → nuevo `creado = ahora`.
2. `evolDiskFresh` compara `.stamp` (de anoche) contra el nuevo `creado` (ahora) → **no coinciden** → reconstruye también el payload de disco.

Resultado: se paga TODO en frío. Y como el TTL es de 120 min, esto se repite **cada ~2 h** durante el día, no solo en la mañana.

**Por qué el login-prewarm no lo evita:** `warmProveedor($conn,$prov, onlyIfStale=true)` hace `if (evolDiskFresh(...)) skipped` **sin** llamar antes a `ensureEvolCacheBase`. Compara el `.stamp` del disco contra el `creado` **viejo** de la base (que sigue presente) → coinciden → **skip**. El endpoint, en cambio, reconstruye la base primero e invalida justo esa cache. Prewarm y endpoint usan **definiciones de frescura inconsistentes**.

**Por qué o45 sí funciona:** o45 usa un **stamp de fuente** (`MAX(FECHA)` de inv_actual + Ventas_Detal), estable durante el día, y **mira el disco primero** (no tiene base intermedia con TTL). Evol quedó atado a una tabla con TTL de reloj. Esa es toda la diferencia.

## Diseño propuesto: alinear evol al patrón de o45

Tres cambios coordinados. **El contenido del payload NO cambia** — solo cambian (a) de qué depende la frescura y (b) el orden en que se decide servir-de-disco vs. reconstruir.

### 1. Stamp de fuente para evol (`lib_evol_disk.php`)

Reemplazar `evolCurrentStamp($conn, $ekey)` (lee `evol_cache_base.creado`) por un stamp global de fuente, sin key, igual que o45:

```php
function evolCurrentStamp($conn): ?string {
    // MAX(FECHA) de las 3 fuentes VIVAS de evol (avanzan con el ETL nocturno).
    $sql = "SELECT ISNULL(CONVERT(varchar(19),(SELECT MAX(FECHA) FROM INTEGRACION.dbo.inv_actual_PBI      WITH(NOLOCK)),120),'') + '|'
                 + ISNULL(CONVERT(varchar(19),(SELECT MAX(FECHA) FROM INTEGRACION.dbo.Ventas_Detal_PBI    WITH(NOLOCK)),120),'') + '|'
                 + ISNULL(CONVERT(varchar(19),(SELECT MAX(FECHA) FROM INTEGRACION.dbo.mov_inv_actual_PBI  WITH(NOLOCK)),120),'') s";
    ...
}
function evolDiskFresh($conn, string $ekey): bool { return diskCacheFresh('evol', $ekey, evolCurrentStamp($conn)); }
```

**Nota de cobertura** (a documentar en el código, espejo de o45): el dataset lee más tablas (Ventas_Detal_Acum, historico_inventarios/hold/mov_inv, _hold_actual), pero el stamp solo mira las 3 vivas porque: (a) las históricas/Acum son append-only para un `[desde,hasta]` fijo y su cambio siempre viene en el mismo ETL nocturno; (b) el `_hold_actual`/stock del corte vivo tiene staleness intradía que el diseño ya acepta; (c) `inv_actual/Ventas_Detal/mov_inv` avanzando de noche son proxy fiable del ETL completo. El **prebuild nocturno corre con `onlyIfStale=false`** (fuerza rebuild), así que cualquier recarga histórica se recoge sí o sí cada noche.

Impacto automático: `warmProveedor` y `evolDiskFresh` del endpoint pasan a usar el mismo stamp → **prewarm deja de hacer skip erróneo** y coincide con el endpoint.

### 2. Reordenar el endpoint: disco-primero (`informe_evol.php`)

Mover el corto-circuito de disco **antes** de `ensureEvolCacheBase`. La base solo se materializa cuando de verdad se va a leer de ella:

- **`tab=data` cache-mode SIN filtros:**
  1. `if (evolDiskFresh(ekey)) → serve gz + exit` (**sin tocar la base**).
  2. Miss → `flock` → double-check → `evolBuildPayload` → `evolWritePayload(stamp_de_fuente)` → serve. **Antes de construir el payload en el miss**, materializar la base con `ensureEvolCacheBase(..., force=true)` (porque un miss aquí = la fuente cambió, la base debe reconstruirse aunque su TTL de 120 min no haya vencido).
- **`tab=data` cache-mode CON filtros** y **camino vivo (`nocache`) / `tab=filtros`:** conservan `ensureEvolCacheBase(...)` (TTL normal) antes de leer filas de `evol_cache_base`, **exactamente como hoy**. No cambia su comportamiento.

`evolCacheCleanup` / `evolCleanup` se mantienen; se llaman en los caminos que tocan la base (no en el hit de disco).

### 3. `force` en `ensureEvolCacheBase` (`lib_evol_cache.php`)

Añadir parámetro opcional `bool $force=false`. Cuando `true`, salta el fast-path `evolCacheFresco` (pero conserva el applock + double-check de concurrencia). Solo lo usa el camino de miss de disco sin filtros del punto 2. Sin `force`, comportamiento idéntico al actual.

### 4. Operativo: agendar el prebuild nocturno

Con lo anterior, tras el ETL nocturno el stamp de fuente avanza una vez; `sql/prebuild_all.php` (ya invoca `warmProveedor(...,false)`) reconstruye base + disco; y el stamp queda **estable todo el día** → el primer usuario de la mañana obtiene **hit de disco**. Falta instalar la tarea (guía `docs/.../prewarm-layer` / schtasks) en **WMS-LAB** (prod) tras el ETL; opcional en local. El login-prewarm queda como red de seguridad.

## Paridad y pruebas

- **Contenido idéntico:** `evolBuildPayload` no se toca. La suite E2E de paridad existente (`evolRunE2E`, `--e2e`) debe seguir pasando: mismo JSON servido de disco que el camino vivo `nocache`.
- **Gotcha (memoria):** NO correr dos suites de paridad a la vez.
- **Nuevas verificaciones:**
  - Stamp: `evolCurrentStamp()` devuelve string no vacío y estable entre requests con datos sin cambio.
  - Prewarm: con cache fresca, `warmProveedor(...,true)` marca `evol=warmed` (o `skipped` **solo** cuando el disco realmente está fresco vs. la fuente), y el endpoint da **hit de disco** después.
  - Miss por cambio de fuente: forzar stamp distinto → el endpoint reconstruye base+disco una vez y luego sirve de disco.
- **Medición:** primera carga tras “nueva fuente” (miss) vs. cargas siguientes (hit), local y anotar para validar en WMS-LAB.

## Riesgos

- **Camino filtrado depende de la base:** si por error se deja de llamar `ensureEvolCacheBase` en el camino filtrado/rows, esos requests leerían base vieja o vacía. Mitigación: mantener el `ensure` en esos ramos y cubrir con la suite de paridad.
- **Ventana de `force` en miss:** un miss fuerza rebuild de base; bajo concurrencia el `flock` (disco) + applock (base) serializan; tras el primer write, el resto va a disco. Aceptable.
- **Cobertura del stamp:** documentada arriba; el prebuild `onlyIfStale=false` es el respaldo para recargas históricas.

## Fuera de alcance

- Otros módulos (codificación, documentos, ventas, login) — este spec es solo evol.
- Cambiar el esquema de `evol_cache_base` o su materialización (byte-a-byte intacta).
