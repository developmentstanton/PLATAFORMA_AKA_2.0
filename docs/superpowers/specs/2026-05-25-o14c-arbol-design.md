# O14C — Árbol jerárquico Grupo → Almacén → Negocio

**Fecha:** 2026-05-25
**Estado:** Aprobado (pendiente revisión del spec por Rafael)
**Contexto:** Rediseño de la pestaña **O14C** del informe O14 (`plataforma_20`). Reemplaza la vista actual "por tienda de un solo negocio" por un árbol jerárquico colapsable, modelado sobre el Power BI viejo que O14 reemplaza. Ver diseño base en `2026-05-22-o14-design.md`.

## Objetivo

O14C debe presentar **todos los negocios** del proveedor en una matriz en árbol de 3 niveles desplegables, agrupada por grupo de bodegas, replicando la navegación del Power BI (captura de referencia revisada con Rafael).

## Decisiones (confirmadas con Rafael)

1. **Estructura: árbol de 3 niveles**
   - **N1 — Grupo de bodegas** (AKA, ZEUS, BODEGA, …) + **CEDI como grupo aparte** al final.
   - **N2 — Almacén** (tienda; `cia-bodega`).
   - **N3 — Negocio** (referencia-color) — fila hoja con valores por talla.
2. **CEDI**: se muestra como un **grupo propio** rotulado "CEDI" en O14C (`bodega='CEDI'` → `grupo='CEDI'`, ignorando su `Bodegas.GRUPO`). **O14B y los KPIs siguen SIN CEDI** (decisión del 2026-05-25 se mantiene para esas vistas).
3. **Subtotales**: las filas de Grupo (N1) y Almacén (N2) muestran subtotales agregados por talla + total de bloque, para cada medida.
4. **Estado por defecto al cargar**: grupos **expandidos** mostrando sus almacenes; almacenes **colapsados** (los negocios se revelan al hacer clic). Evita volcar ~51 negocios de golpe.
5. **TOTAL general**: fila TOTAL al pie **sin CEDI** (suma solo de los grupos de tiendas).
6. **Selector "Negocio"** (barra de filtros): pasa a **filtrar el árbol en el cliente** (muestra ese negocio en todas las tiendas/grupos); "— Todos —" muestra todo. Sin refetch. El clic en un negocio en O14B sigue llevando a O14C (ahora filtrado a ese negocio).
7. **Medidas**: se conservan las actuales y su orden — `siembra, disponible, hold, disphold, sobrante, faltante, ventas` (ventas al final, columna Disp+Hold incluida). Texto centrado y totales resaltados como en O14B.
8. **Ventas en el universo**: O14C hereda el `#base` con ventas incluida → aparecen negocios/tiendas con inventario cero pero con ventas en el período.

## Enfoque técnico

**A) Árbol colapsable en el cliente a partir de datos tidy** (elegido). El endpoint entrega filas tidy; el cliente agrupa, calcula subtotales y arma una sola tabla HTML con filas mostrables/ocultables vía clases CSS y `data-*`. Sin librerías (coherente con "tidy-data + pivote en cliente"). Descartadas: pre-agregación anidada en el endpoint (formato a medida, más complejo) y librerías tree-table (proyecto vanilla).

## Contrato de datos

**`api/informe_o14.php`, `tab=c`:** deja de exigir `ref`/`color`. Consulta `#base` (ya trae siembra/disponible/hold/**ventas**, cía normalizada a 3 díg) uniendo `Bodegas` solo para `NOMBRE/GRUPO`:

- Grano de salida: una fila tidy por `(grupo, llave=cia-bodega, bodega, nombre, negocio, referencia, color)` con `valores[medida][talla]`.
- `grupo = CASE WHEN bodega='CEDI' THEN 'CEDI' ELSE ISNULL(Bodegas.GRUPO,'SIN GRUPO') END`.
- **No** excluye CEDI (a diferencia de O14B).
- Respuesta JSON:
  ```json
  {
    "ok": true, "tab": "c",
    "tallas": ["5.5","6", …],
    "medidas": ["siembra","disponible","hold","disphold","sobrante","faltante","ventas"],
    "grupos": [
      { "grupo": "AKA",
        "almacenes": [
          { "llave":"007-548", "bodega":"548", "nombre":"AKA Centro",
            "negocios": [
              { "negocio":"434500-NEG", "referencia":"434500", "color":"NEG",
                "valores": { "siembra": {"7":1,"8":1}, "disponible": {...}, … } }
            ] } ] }
    ],
    "kpis": { … sin CEDI … }
  }
  ```
  (El orden de grupos coloca **CEDI al final**. Los subtotales de grupo/almacén los calcula el cliente sumando los `valores` de sus hijos — el endpoint NO los precalcula.)

**Contrato definitivo: la forma anidada de arriba** (`grupos[] → almacenes[] → negocios[] → valores[medida][talla]`). El endpoint arma la jerarquía en PHP a partir de las filas tidy de `#base`; no se entrega plano.

## Render (`informes/o14.php`)

- Una tabla `.o14-matriz` (reusa estilos). Encabezado igual que O14B (bloques de medida × tallas + Tot).
- Filas con clase por nivel: `o14-row-grupo` (N1), `o14-row-alm` (N2), `o14-row-neg` (N3) y atributos `data-grupo` / `data-alm` para el toggle.
- Toggle ▼/▶ en la primera celda de N1 y N2; al colapsar se ocultan los descendientes (clase `.o14-collapsed`).
- Subtotales N1/N2 calculados en cliente; estilo resaltado (reusa `blocktot`/`o14-total` o variante).
- Fila **TOTAL (sin CEDI)** al pie.
- Indentación por nivel en la columna dimensión.

## Interacciones

- **Cargar O14C**: `loadC()` ya no exige negocio; pide `tab=c` (sin ref/color) y construye el árbol. Estado por defecto: grupos abiertos, almacenes cerrados.
- **Toggle**: clic en fila grupo/almacén alterna sus hijos.
- **Filtro por negocio** (selector o clic en O14B): filtra el árbol mostrando solo el negocio elegido (oculta negocios que no matchean y colapsa/expande lo necesario); "— Todos —" limpia.
- El cambio de fechas/cía recarga (refetch) porque afecta los datos.

## Casos borde

- Proveedor admin sin `#refs` → árbol vacío, "Sin datos." (igual que hoy).
- Grupo sin almacenes con datos / negocio sin tallas → no se renderiza.
- Negocio que solo existe en CEDI → aparece bajo el grupo CEDI; no entra al TOTAL ni a KPIs.
- `Bodegas.GRUPO` nulo → "SIN GRUPO".
- Filtro de negocio que deja un grupo/almacén sin hijos visibles → ese grupo/almacén se oculta.

## Verificación

- **Backend (harness CLI, BELTRANY SAS):**
  - `tab=c` devuelve grupos con CEDI **al final**; suma de negocios de un almacén = subtotal del almacén; suma de almacenes (sin CEDI) de un grupo = subtotal del grupo.
  - **Invariante por hoja**: `faltante − sobrante == siembra − (disponible+hold)`.
  - **Consistencia O14B↔O14C**: para un negocio, `Σ tiendas (O14C, sin CEDI) == fila(O14B)`.
  - `disphold == disponible+hold` por talla.
  - KPIs sin CEDI.
- **Navegador (Rafael):** despliegue/colapso por grupo y almacén; CEDI como grupo aparte; filtro por negocio; totales y centrado; ventas al final + columna Disp+Hold.

## Fuera de alcance

- Persistencia / "Generar solicitud" (sub-proyecto 3, sigue diferido).
- Endurecer truncado decimal (follow-up aparte).
- Cambios en O14B, KPIs (salvo que sigan sin CEDI) y motor de recomendaciones.
