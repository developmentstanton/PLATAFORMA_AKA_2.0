# O14 — Incluir CEDI como grupo normal en las vistas

**Fecha:** 2026-06-09
**Rama:** `feat/o14-topbar-g00-style`
**Archivos:** `api/informe_o14.php`, `informes/o14.php`, `tests/o14_kpis_arbol_test.mjs`

## Contexto y decisión

Desde el 2026-05-25, O14 trataba al CEDI como caso especial: en el árbol "Por tienda" se forzaba a un grupo sintético `'CEDI'` aparte al final, y se **excluía** de los KPIs y de O14B/O14C (`WHERE bodega<>'CEDI'`). La razón de entonces: "O14 es vista por tienda; el CEDI es fuente de abastecimiento, no tienda". Esa exclusión era la causa documentada de que O14 mostrara menos negocios/cantidades que el Power BI viejo.

**Rafael revierte esa decisión (2026-06-09):** el CEDI debe ser un **grupo normal**. Su grupo real en `INTEGRACION.dbo.Bodegas` es **`BODEGA`** (Centro de Distribución, uno por cía: 002=Brahma, 007=Cauchosol, 049/051/053 otras). Por tanto:
- Los **KPIs incluyen el CEDI**.
- En "Por tienda", el CEDI aparece **dentro de su grupo real `BODEGA`** (ya no como grupo `'CEDI'` aparte ni al final).
- Queda **una sola fila TOTAL** (se elimina la distinción TOTAL sin/con CEDI agregada el 2026-06-09 tarde2).
- Efecto colateral deseado: **O14 vuelve a cuadrar con el Power BI viejo** (que incluía el CEDI).

**El motor de recomendaciones NO se toca aquí:** sigue identificando el CEDI por código de bodega (`bodega='CEDI'`) para la cascada de abastecimiento. La reorganización de grupos es solo de presentación; `#base` sigue teniendo las filas de CEDI con `bodega='CEDI'`.

## Fuera de alcance
- **Recomendaciones generales por filtros** (correr el motor para todos los negocios de la vista filtrada). Va en su **propio spec** a continuación.

## Cambios

### Backend `api/informe_o14.php`
1. **`kpiCounts()`**: quitar `WHERE bodega<>'CEDI'` de las 5 subconsultas (negocios, negocios_con_siembra, tiendas_con_siembra, tiendas_con_inv, tiendas_con_venta) → cuentan todo (incluido CEDI). Donde la condición era `WHERE bodega<>'CEDI' AND <cond>`, queda `WHERE <cond>`; donde era solo `WHERE bodega<>'CEDI'`, se elimina el WHERE.
2. **Tab b** (`if ($tab === 'b')`):
   - El agg: `FROM #base WHERE bodega <> 'CEDI'` → `FROM #base` (sin el WHERE).
   - Las subconsultas `$cnt`: quitar `bodega<>'CEDI' AND` de cada una (queda solo la condición de medida; donde solo había `bodega<>'CEDI'` no aplica — todas tienen `AND <medida>`).
3. **Tab c** (`if ($tab === 'c')`):
   - `grupo`: `CASE WHEN b.bodega='CEDI' THEN 'CEDI' ELSE ISNULL(bo.GRUPO,'SIN GRUPO') END` → `ISNULL(bo.GRUPO,'SIN GRUPO')`. Ajustar el `GROUP BY` para que use la misma expresión (`ISNULL(bo.GRUPO,'SIN GRUPO')` en vez del CASE).
   - `ORDER BY`: quitar el primer criterio `CASE WHEN b.bodega='CEDI' THEN 1 ELSE 0 END` (ya no se fuerza el CEDI al final). Ordenar por `grupo, llave, negocio`.
4. **`ensamblarArbol()`**: la suma de KPIs hoy es `if($g!=='CEDI'){ $kpi[...]+=...; }`. Quitar la condición → **siempre** acumular (incluye CEDI). (Como además el grupo ya no se llama 'CEDI', la condición sería siempre verdadera, pero se simplifica para que quede explícito.)
5. **`total_stock`**: ya es `disponible + hold` (del lote anterior). Sin cambios; ahora esas sumas incluyen CEDI.

### Frontend `informes/o14.php`
6. **`kpisFromArbol(data)`**: quitar la línea `if(g.grupo==='CEDI') return;` → incluye todos los grupos en el cómputo client-side.
7. **`renderArbol()`**: dejar **una sola fila TOTAL**:
   - Eliminar el acumulador `gtotC` y la condición `if(gr.grupo!=='CEDI')` del acumulador `gtot` (ahora `gtot` suma **todos** los grupos).
   - Eliminar la 2ª fila `TOTAL (con CEDI)`. La fila final queda: `<tr class="o14-total"><td class="dim">TOTAL</td>'+rowCells(gtot,...)`.
   - La fila "Total &lt;grupo&gt;" al final de cada grupo se **mantiene** (el CEDI ahora aparece dentro del grupo `BODEGA`, con su propio total de grupo).

### Tests
8. **`tests/o14_kpis_arbol_test.mjs`**: hoy el árbol sintético tiene un 3er grupo `grupo:'CEDI'` y los esperados asumen que se **excluye**. Actualizar: el 3er grupo pasa a un grupo real (renombrar a `'BODEGA'`) y los valores esperados **incluyen** ese grupo. Nuevos esperados (con el grupo BODEGA = llave `007-CEDI`, negocio A-NEG, siembra 9, disp 9, hold 0, ventas 0):
   - siembra = 5+9 = **14**; disponible = 2+9 = **11**; hold = 1+0 = **1**; total_stock = 11+1 = **12**; ventas = 4+0 = **4**.
   - faltante/sobrante: AKA 245 → bal=5-(2+1)=2 → faltante 2. BODEGA 007-CEDI → bal=9-(9+0)=0 → nada. → **faltante=2, sobrantes=0**.
   - negocios distintos (cia|negocio): `007|A-NEG` aparece en AKA y BODEGA pero es el mismo key → **1**.
   - negocios_con_siembra: `007|A-NEG` (si>0 en 245 y en CEDI) → **1**.
   - tiendas_con_siembra: almacenes con siembra>0 = `007-245` (5) y `007-CEDI` (9) → **2**.
   - tiendas_con_inv: `007-245` (2) y `007-CEDI` (9) → **2**.
   - tiendas_con_venta: `007-245` (3) y `007-547` (1) → **2** (CEDI tiene ventas 0).

   La función `kpisFromArbol` del test debe ser **idéntica** a la de `informes/o14.php` (ya sin el `if(g.grupo==='CEDI') return;`).

## Verificación
- `node tests/o14_kpis_arbol_test.mjs` → OK con los nuevos esperados (siembra 14, disp 11, stock 12, faltante 2, tiendas_con_siembra 2, etc.).
- **Backend (endpoint, BELTRANY):** tab b y c responden `ok`; los KPIs **suben** respecto a antes (ahora incluyen CEDI); el árbol de "Por tienda" muestra el CEDI dentro del grupo `BODEGA` (no como grupo aparte) y hay una sola fila `TOTAL`.
- **Invariante** O14 por negocio (`faltante-sobrante == siembra-(disponible+hold)`) se mantiene (`o14_filtros`, `o14_ventas_rango`).
- **Regresión:** motor 27/27; `o14_total_stock` OK (total_stock=disp+hold sigue, ahora con CEDI); `o14_filtros` OK; `o14_ventas_rango` OK; `g00_detal_smoke` OK; `php -l` limpio.
- **E2E navegador (Rafael):** KPIs incluyen CEDI; en "Por tienda" el CEDI aparece bajo `BODEGA` (con otras bodegas de ese grupo si las hay), no como grupo separado al final; una sola fila TOTAL; los números de O14 cuadran con el Power BI viejo.
