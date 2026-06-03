# Diseño — G00 Inc 2A: barra de filtros núcleo + Calendario/S.S.S + regla Mensual

**Fecha:** 2026-06-03
**Autor:** Rafael Lancheros + Claude
**Estado:** Aprobado para plan de implementación
**Proyecto:** plataforma_20 — módulo informes
**Sigue a:** `2026-06-03-g00-detal-replica-pbi-design.md` (Inc 1)
**Contexto:** ver [[project_plataforma20_informes]]

## Objetivo

Construir la barra de filtros del informe G00 al estilo Power BI (Incremento 2A): los filtros "baratos" en **cascada bidireccional + multi-select**, más los controles **Calendario** y **S.S.S**, y la regla especial de la tabla **Resumen Ventas Mensual**. Los filtros caros (**color, talla, status**) son **Inc 2B** (otro spec) y NO entran aquí.

## Alcance Inc 2A

### A. Filtros multi-select en cascada (barra superior, compartida por los 4 tabs del G00)

Componente: **Tom Select vía CDN** (sin npm/build; searchable, multi, chips). Reemplaza los `<select>` simples actuales (Grupo/Marca).

Filtros incluidos:
- **Grano referencia** (atributos de `ITEMS`/`#refs`): Marca, Tipo, Categoría, Subcategoría, Género, Público Objetivo, Referencia.
- **Grano tienda** (de `Bodegas`): Departamento, Ciudad.
- Se conservan los **date pickers** Desde–Hasta existentes.
- El filtro "Grupo" actual se retira de la barra (no está en la lista pedida).

**Cascada bidireccional:** cualquier filtro restringe las opciones disponibles de todos los demás (elegir Categoría limita Marcas y viceversa; elegir Ciudad limita Marcas, etc.).

**Mecanismo (cliente, sin round-trips por cambio):** un **catálogo de combinaciones** cacheado por proveedor —las parejas distintas `(REFERENCIA, BODEGA)` que el proveedor vendió, enriquecidas con atributos de referencia (marca/tipo/categoría/subcategoría/género/público) y de tienda (departamento/ciudad)— se entrega al front una vez. Tom Select recalcula las opciones de cada filtro contra ese arreglo en memoria según lo ya seleccionado. El catálogo se escanea **una vez al día** (mismo patrón de frescura que `#refs`) y se cachea en `cache/g00_filtros_{md5(proveedor)}.json`.

Departamento/Ciudad listan **solo donde el proveedor vendió** (coherente con el filtrado por proveedor de todo el portal).

### B. Controles Calendario y S.S.S (en la barra, estilo botones PBI)

- **Calendario** — `Día a Día` (default) | `Retail`. Define cómo se calcula el **período anterior** para los comparativos YoY:
  - **Día a Día:** período anterior = mismas fechas **− 1 año** (alineación calendario), cortado al mismo día.
  - **Retail:** período anterior = mismas fechas **− 364 días** (52 semanas), que **preserva el día de la semana** (ej. `2026-01-01` jueves → `2025-01-02` jueves). Respeta el filtro de fechas aplicado.
  - Aplica a KPIs y a las tablas con comparación YoY (Detal y el tab Tiendas).
- **S.S.S** — `No Same` (default = todas) | `Same` (same-store). Mueve a la barra el control que en Inc 1 quedó como toggle placeholder. `Same` = el filtro `?sss=same` ya implementado (tiendas operativas en ambos años). `No Same` = sin restricción.
- Se **retira el toggle de modo** del panel Detal (Inc 1); su función vive ahora en estos dos controles de la barra.

### C. Regla de la tabla Resumen Ventas Mensual
- **Ignora** el filtro de fechas Desde–Hasta.
- Muestra **Enero → mes actual** (sin meses futuros).
- **Ambos años cortados al mismo día:** los meses completos van completos en los dos años; el **mes en curso** se compara cortado al día de hoy (ej. hoy 3-jun → jun-2026 = 1–3 jun y el comparativo = 1–3 del mes equivalente del año anterior, según la alineación del Calendario: −1 año o −364 días).
- **Sí** respeta los demás filtros (marca, tipo, … departamento, ciudad, S.S.S).

### D. Backend (`api/informe_g00.php`)
- **Parámetros multi-valor:** `marca[]`, `tipo[]`, `categoria[]`, `subcategoria[]`, `genero[]`, `publico[]`, `referencia[]`, `depto[]`, `ciudad[]`. Cada uno con valores → `WHERE col IN (?, ?, …)` parametrizado. Los de referencia se aplican filtrando `#refs` (que se extiende con subcategoría/género/público); los de tienda filtrando el join a `Bodegas`. Vacío = sin filtro (todos).
- **`#refs` se amplía** con: SUBCATEGORIA, GENERO, PUBLICO_OBJETIVO (ya tiene MARCA, TIPO, LINEA, SUBLINEA, CATEGORIA). El cache `g00_refs_*.json` se invalida (estructura nueva).
- **Catálogo de filtros/combinaciones:** nuevo builder cacheado (`g00_filtros_{md5}.json`, frescura diaria) que produce el arreglo de combinaciones para la cascada cliente. Un solo scan de hechos del proveedor al día.
- **`cal=diaadia|retail`** (default diaadia): define el offset del período anterior (`$desdeAnt/$hastaAnt`).
- **`sss=nosame|same`** (default nosame): ya implementado.
- Los filtros aplican a los **4 tabs**; Calendario/S.S.S a los tabs con YoY (Detal, Tiendas).
- **Mensual:** query propia que ignora `desde/hasta` y usa Ene→hoy con corte al mismo día (según `cal`), aplicando los demás filtros.
- Mantener optimizaciones existentes (#refs cacheado, NOLOCK, pushdown de fecha, GROUPING SETS de una pasada) y los gotchas de [[project_plataforma20_informes]] (`AND b.CIA=7`; temp tables sqlsrv; fact tables sin índice).

### E. Frontend (`informes/g00.php`)
- Reemplazar la barra de filtros (Grupo/Marca) por la nueva: 9 Tom Select en cascada + date pickers + controles Calendario y S.S.S.
- Cargar el catálogo de combinaciones al entrar al informe; inicializar Tom Select y la lógica de cascada bidireccional client-side.
- `buildParams()` serializa los filtros multi-valor + `cal` + `sss`.
- Quitar el toggle de modo del panel Detal.
- La tabla Mensual se renderiza desde su propio bloque de datos (independiente de fecha).

## Fuera de alcance (Inc 2A)
- **Color, Talla, Status** (Inc 2B): grano hechos / join `PORTAFOLIO.STATUS_N`.
- Export a Excel/PDF.
- Refinar otras secciones del G00 más allá de heredar los filtros.

## Criterios de éxito
1. La barra muestra los 9 filtros multi-select (Tom Select) + date pickers + Calendario + S.S.S; "Grupo" ya no aparece.
2. La cascada bidireccional funciona en el cliente sin recargar: elegir valores en un filtro recorta las opciones de los demás coherentemente.
3. Aplicar filtros recalcula KPIs y las 3 tablas del Detal (y los demás tabs heredan el filtrado).
4. Calendario: "Día a Día" compara contra −1 año; "Retail" contra −364 días (mismo día de semana). El cambio se refleja en los comparativos YoY.
5. S.S.S: "No Same" (default) = todas; "Same" = same-store. El control vive en la barra.
6. La tabla Mensual muestra Ene→mes actual, ambos años al corte del mismo día, ignorando el filtro de fechas pero respetando los demás filtros.
7. Sin regresión de performance perceptible (cascada client-side; catálogo y refs cacheados a diario; un solo scan/día por proveedor para el catálogo).
8. Las 4 pestañas siguen funcionando.
