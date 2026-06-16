# Georeferenciación — Diseño

**Fecha:** 2026-06-12
**Estado:** Aprobado (brainstorming con Rafael)
**Tipo:** informe nuevo en el menú REPORTES de `plataforma_20` (5º informe, junto a Ventas/Siembra-Stock/Índice de Ventas/Evolución Histórica).

## 1. Propósito

El proveedor logueado (el "tercero") ve **en un mapa de Colombia** las tiendas de la red Stanton donde **se vendieron sus productos**, según los filtros aplicados. Cada tienda es un marcador; al pasar el puntero muestra un cuadro con sus **ventas acumuladas** (en valor y en unidades) en el rango/filtros.

Reusa al 100% el **topbar (Año-Mes) y los filtros en cascada** del informe Evolución Histórica.

## 2. Modelo

- **Universo:** tiendas (cía-bodega) con **al menos una venta** del proveedor en el rango/filtros. Filtrado por proveedor vía `#refs` (igual que G00/O14/O45/Evolución).
- **Por tienda:** `Ventas $ = SUM(VALOR)` y `Ventas unidades = SUM(CANTIDAD)` de `Ventas_Detal_PBI` (+ `Ventas_Detal_Acum_PBI` si el rango toca ≤2025). Llave de tienda = (cía, bodega) normalizada (cía 3 díg.).
- **Atributos de tienda** (de `INTEGRACION.dbo.Bodegas`, join por `COD`+`CIA`): `NOMBRE`, `GRUPO`, `CIUDAD`, `COORDENADAS`.
- **Color del marcador = `GRUPO`** (AKA, ZEUS, SPRING STEP, BRAHMA, BODEGA, …). **Tamaño fijo** (no proporcional a ventas).

### 2.1 Coordenadas (gotcha)

`Bodegas.COORDENADAS` es **texto libre con formato inconsistente** (verificado 2026-06-12): `"4.609927 -74.083026"` (espacio), `"4.689665, -74.071421"` (coma+espacio), `"4.6480302,-74.0912985,17"` (coma, sin espacio, con un 3er número = zoom). Cobertura **~72%** (cía 7: 299/416 con coordenadas).

**Parseo (en PHP, backend):** extraer los floats con `preg_match_all('/-?\d+\.\d+/', $coord, $m)`; tomar los **2 primeros** como `lat`, `lng`. Validar rango Colombia aproximado (`lat ∈ [-5,15]`, `lng ∈ [-80,-66]`); si no hay 2 floats válidos en rango → `lat=lng=null` (la tienda **no se mapea** pero **sí cuenta** en `total_tiendas`). El 3er número (zoom) se ignora por construcción.

## 3. Backend — `api/informe_geo.php` (nuevo)

Clon de la maquinaria de `api/informe_evol.php`:
- `#refs` del proveedor (cache compartida `g00_refs_*`) + parser de filtros `getMulti` + mapas `$FILTROS_REF`/`$FILTROS_BOD` (cascada idéntica a Evolución/O45).
- **Rango Año-Mes** Desde/Hasta (igual que Evolución): default ene-año-pasado → mes actual; ventana de fechas `[primer-día-mes-desde, min(fin-mes-hasta, ayer)]` para ventas.
- **Agregación por tienda** (una pasada): `#base` o agregación directa por (cía, bodega) sumando `VALOR` y `CANTIDAD`, joineada a `#refs` (poda por dimensión) y a `Bodegas` (atributos + filtro grupo/tienda, conservando reglas de O45: excluir grupo `ADMINISTRATIVAS`; CEDI no aplica aquí porque CEDI no vende, pero se mantiene la exclusión administrativa).
- **`tab=filtros`**: catálogo de cascada idéntico a Evolución (cache `geo_filtros_*`).
- **Respuesta `tab=data` (JSON):**
  ```
  {
    ok: true, proveedor: "<tercero>",
    rango: { desde:"YYYY-MM", hasta:"YYYY-MM", corte_ventas:"YYYY-MM-DD" },
    tiendas: [
      { cia, cod, nombre, grupo, ciudad, lat, lng, valor, unidades }, ...
    ],
    total_tiendas: <int>,   // tiendas con venta (con o sin coordenadas)
    sin_coord: <int>        // cuántas de esas no tienen coordenada parseable
  }
  ```
  `tiendas` incluye TODAS las tiendas con venta; las que no tienen coordenada llevan `lat=lng=null`. Orden: por `valor` desc.
- **Sin inyección:** filtros parametrizados (mismo patrón que Evolución/O45).

## 4. Frontend — `informes/geo.php` (nuevo)

- **Topbar** estilo Evolución (`topbar--o14`): título centrado **"GEOREFERENCIACIÓN - {Tercero}"**, control **Año-Mes Desde/Hasta** a la izquierda, botón Actualizar + 🔔 + 🚪 a la derecha.
- **Filtros en cascada** clonados de Evolución (Tom Select: marca…referencia, negocio, grupo/tienda).
- **Mapa Leaflet** (CDN) centrado en Colombia (`[4.6, -74.1]`, zoom ~6):
  - Capa base **satélite Esri World Imagery** (`https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}`, atribución Esri) + capa de **etiquetas** Esri (`World_Boundaries_and_Places`) para el look "híbrido".
  - **Marcadores** `L.circleMarker` de **radio fijo** (~7px), `fillColor` por **GRUPO** (paleta asignada por grupo en cliente), borde blanco. Solo tiendas con `lat/lng` no null.
  - **Hover** (`marker.bindTooltip` sticky, o mouseover): cuadro con **`COD - NOMBRE`** (encabezado), **Ventas $** (`$ 1.234.567`, es-CO) y **Ventas unidades** (`1.234`).
  - Los marcadores se **recrean** en cada carga (capa `L.layerGroup` que se limpia y repuebla).
- **Contador "# Tiendas"** (caja flotante abajo-izquierda) = `total_tiendas`.
- **Panel "Descripción Tiendas"** (caja flotante abajo-derecha, scroll) = lista `COD - NOMBRE` de las tiendas con venta (orden por COD).
- **Carga:** `geoOnEnter` inicializa topbar+filtros+mapa (idempotente: el mapa Leaflet se crea una sola vez; las cargas posteriores solo repueblan marcadores). Spinner SweetAlert durante el fetch.

## 5. Wiring — `dashboard.php`

- **`<head>`:** agregar Leaflet CSS + JS por CDN (junto a los otros CDN: Tom Select, SheetJS, etc.).
- **Menú REPORTES:** nuevo ítem **"Georeferenciación"** (icono mapa) → `showPage('georreferenciacion', this)`.
- **Topbar:** botón `topbarGeoRefresh` (oculto por defecto, `onclick="geoLoad()"`).
- **Página:** `include __DIR__ . '/informes/geo.php';` (define su propio `#page-georreferenciacion`).
- **`showPage`:** entrada en `titles` (`'georreferenciacion':'GEOREFERENCIACIÓN'`), reset del botón geo, y dispatch `if (pageId==='georreferenciacion' && typeof geoOnEnter==='function') geoOnEnter();`.

> **Gotcha Leaflet:** un mapa creado mientras su contenedor está `display:none` (la página inactiva) calcula mal el tamaño. `geoOnEnter` debe llamar `map.invalidateSize()` tras mostrar la página (la página ya está activa cuando corre `geoOnEnter`). Crear el mapa **la primera vez que se entra** a la página (no en el include), e `invalidateSize()` en cada entrada.

## 6. Performance

Una sola agregación por tienda (más liviana que Evolución; sin stock ni cortes). `Acum` solo se une si el rango toca ≤2025. `WITH (NOLOCK)`, push-down de `FECHA`/`#refs`. Marcadores ≤ ~300 → Leaflet sin problema.

## 7. Testing

Test PHP (`tests/_endpoint_run_geo.php` + `tests/geo_*`), contra proveedor real (BELTRANY SAS / BH BRANDS SAS):
1. **Estructura** `tab=data`: `tiendas[]` con las 9 claves; `total_tiendas` ≥ count de tiendas con coord.
2. **Suma por tienda**: `valor`/`unidades` de una tienda cuadran contra query directo a `Ventas_Detal_PBI`/`Acum` por (cía,bodega) en el rango.
3. **Parseo de coordenadas**: función `parseCoord` (unit test PHP) cubre los 3 formatos (`"a b"`, `"a, b"`, `"a,b,17"`), descarta basura y fuera de rango → null.
4. **Universo**: una tienda con venta pero sin coordenada aparece en `tiendas` con `lat=lng=null` y suma en `total_tiendas`; `sin_coord` la cuenta.
5. `php -l` limpio en `api/informe_geo.php`, `informes/geo.php`, `dashboard.php`.

**E2E navegador (Rafael):** menú Georeferenciación; mapa satélite de Colombia con marcadores por grupo; hover muestra COD-NOMBRE + $ + unidades; contador # Tiendas; lista Descripción Tiendas; los filtros (rango Año-Mes + cascada) recargan los marcadores; el mapa dimensiona bien al entrar/volver.

## 8. Fuera de alcance (YAGNI)

- Leyenda de grupos (color→grupo) — no pedida.
- Aviso/lista de tiendas sin ubicación — no pedido (solo el contador las incluye).
- Clustering de marcadores.
- Marcador proporcional a ventas (tamaño fijo).
- Exportar a Excel (este informe es visual; si se pide después, se añade).

## 9. Decisiones abiertas (cerrar en el plan)

- Paleta exacta color↔grupo: asignación determinística por nombre de grupo (hash a una paleta fija) vs mapa explícito de los grupos conocidos (AKA/ZEUS/SPRING STEP/BRAHMA/BODEGA). El plan fija una paleta explícita para los grupos comunes + fallback por hash.
- Versión de Leaflet CDN (fijar una estable, p. ej. 1.9.x) en el plan.
