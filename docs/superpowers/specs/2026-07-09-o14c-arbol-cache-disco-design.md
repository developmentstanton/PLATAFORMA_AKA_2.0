# O14 tab=c — Cache en disco del árbol + prebuild nocturno

**Fecha:** 2026-07-09
**Sub-proyecto:** Follow-up abierto del roadmap de filtrado (o45→g00→o14→evol). Ataca el único caso que el cache de servidor NO resolvió: el árbol de "Por tienda" (tab=c) **sin filtro**.
**Rama:** `feature/o14c-arbol-cache-disco`

---

## 1. Contexto y diagnóstico (medido, no supuesto)

El informe O14, pestaña **"Por tienda" (tab=c)**, arma un árbol `Grupo → Almacén → Negocio` con matriz de tallas. Para un proveedor grande (BRAHMA CONCEPT) son **~46.000 filas** que el endpoint agrupa, trae a PHP, ensambla en árbol y serializa (~5.5 MB). Es el **landing por defecto** de esa pestaña, así que se paga en cada entrada sin filtro.

El cache de servidor `o14_cache_base` (sub-proyecto o14-filtrado-rápido, 2026-07-08) evita **recomputar**, pero no evita **transferir** las 46k filas desde la RDS a PHP. Perfilado read-only (cache-hit, BRAHMA CONCEPT):

| Fase | Tiempo | Veredicto |
|---|---|---|
| Cómputo servidor (GROUP BY 46k) | 396 ms | trivial |
| **Fetch fila-por-fila RDS→PHP (46k filas)** | **~26-29 s** | **cuello real (~99%)** |
| `ensamblarArbol` (PHP) | 102 ms | trivial |
| `json_encode` (5.5 MB) | 20 ms | trivial |

**Causa raíz:** la RDS está en `us-east-1` (Virginia); el servidor PHP (local y **producción**) está en Colombia. El link mide **~0.21 MB/s** (verificado en el servidor de prod `WMS-LAB` con un probe read-only). Transferir ~5.5 MB por ese link cuesta ~26 s **hagas lo que hagas**. El problema pega **igual en producción y para los aliados**, no solo en local.

**Alternativas descartadas empíricamente** (todas medidas contra la misma RDS):

| Idea | Resultado | Por qué no |
|---|---|---|
| `FOR JSON PATH` server-side | **107 s** | serializar 46k filas a JSON en SQL Server es carísimo |
| Cursor `SQLSRV_CURSOR_CLIENT_BUFFERED` | 25.7 s | solo mueve el costo del fetch-loop al `sqlsrv_query`; total igual |
| `SQLSRV_FETCH_NUMERIC` (sin llaves assoc) | 32 s | el costo no es armar arrays en PHP |
| Colapsar tallas a 1 fila/negocio (STRING_AGG) | 10 s | sigue atado a ~21.665 filas tienda×negocio |
| Blob único NVARCHAR(MAX) de 5.5 MB desde la DB | **50 s** | el driver trae los MAX en chunks minúsculos, peor aún |

**Conclusión:** la única salida es **no transferir esos datos desde la RDS en el request del usuario.** Como el árbol sin filtro es determinista, se construye una vez y se sirve desde el **disco local** del servidor PHP (leer 5.5 MB de disco = milisegundos; el link lento es solo RDS→PHP, no disco→PHP).

## 2. Objetivo y criterio de éxito

- **Objetivo:** que "Por tienda" sin filtro cargue **instantáneo** (< ~2 s percibidos) en local, prod y aliados, en lugar de ~26 s.
- **Criterio de éxito:**
  1. Con cache en disco caliente, el endpoint `tab=c` sin filtro responde en < 2 s (dominado por la lectura de disco + la segunda pata al navegador).
  2. **Paridad byte-a-byte:** el árbol servido desde disco es idéntico al que produce el camino de filas vivo (`?nocache=1`) para el mismo `cache_key`.
  3. Ningún usuario paga los ~26 s en operación normal (prebuild nocturno deja el archivo listo).
  4. El caso **filtrado** de tab=c y los tabs **b/reco** quedan **intactos** (ya van bien).

## 3. Alcance

**Dentro:**
- `tab=c`, modo cache (`!nocache`), **sin filtros REF/SKU/BOD** → cache en disco.
- Prebuild nocturno de ese payload por proveedor activo.

**Fuera (YAGNI):**
- tab=c **filtrado** (pocas filas, ya ~2-3 s) → sigue por el camino de filas actual.
- tabs **b** y **reco** → intactos.
- Prewarm en tiempo real al login (redundante con el prebuild nocturno).
- Generalizar el patrón a evol/g00 (sus payloads son chicos, ya aceptables).

## 4. Arquitectura

### 4.1. Nuevo lib `api/lib_o14c_payload.php`

Encapsula el cache en disco. Todas las rutas relativas a la carpeta ya existente `cache/` (en `.gitignore`, `cache/*` con `.gitkeep`; se re-sincroniza aparte igual que hoy).

- `o14cPayloadPath($key): string` → ruta absoluta `cache/o14c_<key>.json.gz`.
- `o14cDiskFresh($conn, $key): bool` → true sí y solo sí:
  1. el archivo existe, **y**
  2. `filemtime(archivo) >= creado` de la fila de `o14_cache_base` para `$key` (se escribió *después* de la última reconstrucción del cache de DB).
  (El TTL global lo sigue garantizando `o14CacheFresco`, que se llama antes en el endpoint; si el cache de DB no está fresco, ni se llega a leer el disco.)
- `o14cReadPayload($key): ?string` → devuelve los **bytes gzip** del archivo, o `null` si no existe/ilegible.
- `o14cWritePayload($key, $jsonPlano): bool` → gzip + **escritura atómica** (escribe a `<archivo>.tmp.<pid>` y `rename()`), para que un lector nunca vea un archivo a medias.
- `o14cCleanup(): void` → borra `cache/o14c_*.json.gz` con `mtime` más viejo que el TTL (paralelo a `o14CacheCleanup`); se llama junto a él, no en cada request.

### 4.2. Cambio en `api/informe_o14.php` (bloque `tab === 'c'`)

Antes de correr la query de filas, cuando **`$cacheMode` y no hay filtros activos** (REF/SKU/BOD todos vacíos):

```
if ($tab==='c' && $cacheMode && sinFiltros()) {
    require_once lib_o14c_payload.php;
    if (o14cDiskFresh($dbConnect, $okey)) {
        servirGz(o14cReadPayload($okey));   // HIT → instantáneo
        exit;
    }
    // MISS: flock exclusivo sobre el archivo + double-check (evita 2 builds de 26s)
    //   -> si tras el lock ya está fresco, servir y salir
    //   -> si no, seguir al camino de filas de abajo, y ANTES de responder:
    //         $json = json_encode($payload);
    //         o14cWritePayload($okey, $json);
    //         servirGz(gzencode($json)); exit;
}
```

- `sinFiltros()`: todos los `getMulti()` de `$FILTROS_REF + $FILTROS_SKU + $FILTROS_BOD` vacíos.
- `servirGz($gz)`: si el request trae `Accept-Encoding: gzip` (todos los navegadores) → `header('Content-Encoding: gzip')` + `echo $gz` (abarata también la 2ª pata PHP→navegador: 5.5MB→~0.7MB). Si no, `echo gzdecode($gz)`. **Verificar en E2E que Apache/mod_deflate no re-comprima** (doble-gzip); si lo hace, desactivar deflate para esta respuesta o servir plano.
- El request **filtrado** de tab=c ni entra a este bloque: cae directo al camino de filas actual (intacto).

### 4.3. Prebuild nocturno

- `api/prebuild_o14c.php` (PHP CLI, read-mostly: solo escribe el `.json.gz` en disco y refresca `o14_cache_base` vía el `ensure` existente):
  1. Enumera **proveedores distintos** de `usuarios_portal_aka`, resolviéndolos con el resolver del login (`login_resolver_proveedor` / `lib_login.php`), y deduplica.
  2. Por cada proveedor, con `desde='2025-01-01'`, `hasta=date('Y-m-d')` (mismos defaults del landing sin filtro):
     `buildRefsFromMat` → `ensureO14CacheBase` → correr la query de filas de tab=c sin filtro → `ensamblarArbol` → `json_encode` → `o14cWritePayload`.
  3. Loguea por proveedor: filas, ms, tamaño gz, OK/FALLO (a `sql/prebuild_o14c.log`).
- `sql/prebuild_o14c.bat` → wrapper para Task Scheduler (mismo patrón que `sql/refrescar_items_mat.bat`): invoca `php api/prebuild_o14c.php` con `-b` y redirige a log.
- **Orden:** correr **después** del refresh nocturno de `Items_Mat` y del ETL (para que `hasta=hoy` y los datos estén frescos). El `hasta=hoy` implica que la key cambia cada día → el prebuild diario reconstruye la del día; los usuarios de ese día pegan la misma key → hit.

> Para evitar duplicar la lógica de "armar el payload de tab=c sin filtro", esa construcción (query de filas + `ensamblarArbol` + arreglo de respuesta) se extrae a una función reutilizable compartida por el endpoint (rama miss) y el prebuild — misma fuente de verdad, sin drift.

## 5. Concurrencia

- **DB:** la reconstrucción de `o14_cache_base` ya está serializada por `sp_getapplock` + double-check (heredado). Sin cambios.
- **Disco:** en un miss, `flock(LOCK_EX)` sobre el archivo destino (o un `.lock` hermano) + double-check de `o14cDiskFresh` tras adquirir el lock. El primer request construye; los concurrentes esperan y sirven el archivo recién escrito. En frío (post-refresh) el evento es raro, pero evita que N requests paguen 26 s a la vez.
- Escritura atómica (`rename`) garantiza que un lector nunca lea un `.json.gz` parcial.

## 6. Pruebas / paridad

- **Paridad (bloqueante):** test que compara, para 2-3 proveedores (BELTRANY chico, BRAHMA grande, uno mediano), el árbol servido desde disco vs. el camino vivo `?nocache=1` de tab=c sin filtro → **0 diffs** (comparar el JSON normalizado: grupos/almacenes/negocios/valores/tallas/kpis).
- **Frescura:** test que (a) escribe payload, (b) fuerza rebuild del cache de DB (avanza `creado`), (c) verifica que `o14cDiskFresh` da `false` y que el siguiente read reconstruye.
- **Atomicidad/gzip:** `o14cWritePayload` deja un archivo `gzdecode`-able == JSON original; el `.tmp` no queda.
- **Smoke prebuild:** correr `prebuild_o14c.php` para 1 proveedor deja el `.json.gz` y un read posterior da hit.
- `php -l` limpio en los archivos nuevos/modificados.

## 7. Despliegue (manual, Rafael — post E2E)

1. `cache/` ya existe y está en `.gitignore`; asegurar que sea **escribible** por el usuario de Apache/PHP en prod y aliados.
2. Re-sync a `plataforma_20_produccion` + servidor aliados: `api/informe_o14.php`, `api/lib_o14c_payload.php` (nuevo), `api/prebuild_o14c.php` (nuevo), `sql/prebuild_o14c.bat` (nuevo). (Sin DDL nuevo — reusa `o14_cache_base`, ya desplegado por el sub-proyecto anterior… **ojo:** verificar que `sql/006_o14_cache.sql` esté ejecutado en la RDS, que quedó pendiente del sub-proyecto o14-filtrado.)
3. Montar la **tarea programada nocturna** con `sql/prebuild_o14c.bat` (después del refresh de Items_Mat), en cada servidor PHP (prod y aliados; cada uno arma su propio cache en disco).
4. Borrar el probe `medir_link_db.php` del servidor de prod si sigue ahí.

## 8. Riesgos y follow-ups

- **2ª pata (PHP→navegador):** si el navegador del aliado también está lejos del server, servir gzip la abarata ~8×; validar en E2E. Si mod_deflate interfiere, ajustar.
- **`cache/` no escribible en prod** → el write falla; el endpoint debe **degradar** al camino de filas (26 s) sin romper (nunca 500 por no poder escribir el cache).
- **Proveedor sin filas** (0 negocios) → cachea un árbol vacío válido (hit rápido), consistente con el comportamiento actual.
- **Crecimiento de `cache/`**: `o14cCleanup` acota por TTL; el prebuild diario sobreescribe la key del día.
- **Follow-up (no ahora):** si la 2ª pata resulta ser el nuevo cuello para algún aliado muy remoto, evaluar lazy-load del árbol (Enfoque A) como fase futura.
