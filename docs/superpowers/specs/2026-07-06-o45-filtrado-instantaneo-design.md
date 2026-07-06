# Diseño — o45: filtrado instantáneo (piloto) + 2º cuello del build

- **Fecha:** 2026-07-06
- **Estado:** Aprobado (brainstorming)
- **Informe piloto:** o45 (Índice de Ventas)
- **Relacionado:** `2026-07-03-rendimiento-items-mat-design.md` (patrón `Items_Mat` / `#refs`), fix de precios de o45 (`api/lib_precios.php`, rama `feature/o45-precios-rendimiento`), sub-proyecto A (`filtrosUI`).

## Objetivo

Que al aplicar filtros en un informe la información se recargue **de manera inmediata**, sin volver al servidor. Se aborda como **piloto en o45** y, una vez probado, se replica al resto (g00/o14/evol/geo), cada uno con su propio spec+plan.

Dos metas acopladas:
- **P1 — 1ª carga rápida (2º cuello):** bajar el build de o45 de ~7-27s a pocos segundos.
- **P2 — filtrado instantáneo:** tras la 1ª carga, cada cambio de filtro de dimensión es instantáneo.

## Enfoque elegido: filtrar en el navegador (cliente)

La 1ª carga (o un cambio de **rango de fechas**) trae **una vez** el dataset granular del proveedor; los filtros de **dimensión** (marca/tipo/categoría/subcategoría/género/público/referencia, grupo/tienda, negocio) se aplican y re-agregan **en JS**, sin round-trip.

Descartados: (B) cache en servidor + re-agregar en PHP — rápido (~100-300ms) pero no instantáneo; (C) motor Python/DuckDB — overkill: el dato cabe en el navegador (medido: aliados reales 92 KB–1,7 MB; STANTON interno 4,2 MB, gzip <1 MB).

### Frontera de filtros
- **Instantáneo (cliente):** todas las dimensiones (ref-dims, bodega-dims, negocio).
- **Recarga (backend):** rango de fechas `desde/hasta` — cambia qué ventas entran al dataset (el stock es foto viva, date-independiente). Es la interacción menos frecuente.

## Arquitectura y flujo

```
1ª carga / cambio de fechas:
  navegador → GET informe_o45.php?tab=dataset&desde&hasta
  backend arma el dataset GRANULAR del proveedor (build optimizado, P1) → JSON columnar + gzip
  navegador guarda en memoria y renderiza (agregando en JS)

Cambio de filtro de dimensión:
  navegador filtra + re-agrega el dataset en memoria (JS) → re-render   ⚡ instantáneo
```

El backend pasa a ser **proveedor de dataset**. **La agregación de producción vive en el cliente**. La agregación actual del backend (`tab=data`) se **conserva** — como **oráculo del test de paridad** y como **fallback** (red de seguridad por si algo del filtrado en cliente falla). Se jubilará más adelante, en otro ciclo, con confianza.

## Contrato del dataset granular

Grano por fila: `(cia, bodega, referencia, color, talla)`.

| Grupo | Campos |
|---|---|
| Grano | cia, bodega (cod), referencia, color, talla |
| Dims de referencia (filtros) | marca, tipo, categoria, subcategoria, genero, publico |
| Dims de bodega (filtros + lógica tiendas) | grupo, tienda (nombre), `es_cedi` |
| Medidas | disponible, hold, ventas, ventas30, `inv_hist` (flag 0/1) |

Aparte: **mapa `precio` por `ref|color`** (no por fila) y **metadata** (`desde`, `hasta`, `dias`, `modo_stock`).

El contrato del dataset es **idéntico en modo vivo y modo corte** (histórico, cuando `hasta` cae en un mes ya cerrado): solo cambia el query de build en el backend (CTEs `d`/`h` de stock vivo vs. corte de fin de mes, lógica ya presente en o45). El cliente no distingue: recibe el mismo grano + medidas.

- El backend deja aplicadas las exclusiones **siempre-on** (bodegas ADMINISTRATIVAS no-CEDI); solo viaja lo filtrable.
- **Compactación:** JSON **columnar + diccionario** (strings de dims/bodegas → índices enteros) para reducir el payload antes del gzip.

### Agregación en cliente (reproduce `tab=data` exactamente)
- Agrupar por negocio `(cia, ref, color)`; `SUM` de `ventas`/`ventas30` (bodega≠CEDI), `stock_cedi` (CEDI), `stock_tiendas` (≠CEDI).
- **Conteos distintos** (recalculados desde el grano bajo cualquier filtro): `tallas` (con disp+hold>0 ∨ inv_hist ∨ ventas≠0); `tiendas` (distinct `cia-bodega` con grupo ∉ {BODEGA, ADMINISTRATIVAS} y actividad).
- `total_stock = stock_cedi + stock_tiendas`; `ind_inventario = total_stock/ventas30`; `ind_ventas_mes = (ventas/tiendas)/(dias/30)`; `marca = MAX(marca)` por negocio; precio del mapa; orden por `ind_ventas_mes` desc; fila TOTAL global.
- **Filtrado JS:** dims subsetean filas (CEDI siempre se conserva, igual que hoy) y luego se re-agrega.

## P1 — 2º cuello: acelerar el build (medición primero)

Medición de fases (warm), fechas por defecto:

| Fase | BH BRANDS | BRAHMA | STANTON |
|---|---|---|---|
| `#inv_hist` (scan históricas 19,6M+884K) | 2,7s | 2,4s | 3,9s |
| `INSERT #base` (CTE UNION + 5 LEFT JOIN) | 1,1s | 1,8s | 3,2s |
| agregación | 1,4s | 5,3s | 7,3s |

**Hallazgo:** la agregación es la fase más cara y la que peor escala, y **el enfoque A la saca del backend gratis** (pasa al cliente). Backend build bajo A ≈ `#inv_hist + #base`: **~3,8s (BH), ~4,1s (BRAHMA), ~7,1s (STANTON)**.

Palanca restante: `#inv_hist` y `#base` sufren el antipatrón de joins con claves normalizadas `rtrim()`/`RIGHT('000'+…,3)` → escanean en vez de *seek*. Optimización, **solo dentro de `INTEGRACION` (nunca SIESA)**:
1. **Normalizar las claves de join** (columna computada persistida o base materializada con claves limpias) para habilitar *seek*. Objetivo build ~2-4s.
2. Índice de apoyo en `historico_inventarios_PBI` **si el ETL no la recrea** (verificar antes).
3. Reducir trabajo redundante (rango de `#inv_hist`, scans unificables).

Si el ETL no permite tocar esas tablas y la reescritura no basta, la **materialización nocturna de un base granular** (patrón `Items_Mat`) queda como palanca mayor documentada — no se compromete de antemano. P1 es independiente de P2: aunque el build quede en ~4-7s, el filtrado ya es instantáneo.

## Paridad y testing

- **Oráculo:** agregación actual del backend (`tab=data`).
- **Módulo JS puro** `o45_aggregate.js`: `(dataset, filtros) → misma estructura que el backend`. Sin DOM, testeable en Node.
- **Test golden Node (`.mjs`):** varios proveedores (todos los tamaños) × varias combinaciones de filtros; capturar del backend real `(dataset, salida agregada)` y exigir `aggregate(dataset, filtros) === salida_backend` (idéntico, con redondeo).
- **Doble oráculo (PHP, opcional):** agregar el dataset en PHP y comparar con la agregación vieja (verifica que el dataset no perdió información).
- **E2E navegador (Rafael):** filtros instantáneos + números que cuadran.
- **Medición antes/después:** build de 1ª carga y latencia de filtro (objetivo < 50 ms percibido).

## Rollout

1. Optimizar build P1 (medición → palanca INTEGRACION).
2. Endpoint `tab=dataset` (dataset columnar + mapa precios + metadata).
3. Módulo `o45_aggregate.js` + test golden Node.
4. Cablear `informes/o45.php`: fetch dataset en memoria; re-agregar en `filtrosUI` (recablear el evento de filtro a la re-agregación local en vez de fetch).
5. E2E navegador.
6. Deploy: re-sync a `plataforma_20_produccion` + copia al servidor de aliados.
7. **Conservar `tab=data`** como fallback + oráculo (se jubila en un ciclo posterior, con confianza).
8. **Después del piloto:** replicar patrón a g00/o14/evol/geo (cada uno su spec+plan).

## Fuera de alcance
- Generalización a otros informes (se hace después, por informe).
- Filtrado instantáneo del rango de fechas (recarga, por diseño).
- Materialización nocturna del base granular (solo si P1 no alcanza dentro de INTEGRACION).

## Riesgos
- **Paridad JS↔backend:** mitigado por el test golden como red de seguridad antes de jubilar el backend.
- **Payload STANTON (~4 MB):** interno, no aliado; gzip + columnar/diccionario lo bajan; aceptable como caso extremo.
- **Tocar `INTEGRACION._PBI`/históricas:** verificar comportamiento del ETL antes de indexar; si recrea, ir por materialización.
