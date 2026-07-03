# Sub-proyecto C — Rendimiento: materializar la dimensión de producto (`Items_Mat`)

**Fecha:** 2026-07-03
**Módulo:** `plataforma_20` / informes (capa de datos SQL Server)
**Estado:** diseño aprobado, pendiente plan de implementación

## Contexto y diagnóstico (medido, no teórico)

Se construyó un perfilador read-only (`scratchpad/profiler.php`) y se corrió contra
producción (BD `INTEGRACION`, autorizado por Rafael). Hallazgos clave:

| Operación | Tiempo (frío) | Veredicto |
|---|---|---|
| Scan tabla hechos año actual (105K filas) | 87–124 ms | rápido |
| Scan tabla histórica `Ventas_Detal_Acum_PBI` (2.7M filas) | 0.3–1.9 s | ya está bien |
| Agregación G00 / histórica (join + GROUP BY, con `#refs`) | 2–4 s | moderado |
| Vista `ITEMS` — solo `COUNT` | 8–11 s | lento |
| Vista `ITEMS` — emitir ~31K refs (shape de `getRefsCached`) | 55 s | muy lento |
| Construir `#refs` (INSERT por lotes de 200 desde PHP) | 163 s | crítico |

**Conclusión:** el cuello de botella **NO son las tablas de hechos** (escanean rápido; un
columnstore ahí daría ganancia marginal). El costo está en:
1. La **vista `ITEMS`** (17 LEFT JOIN) — cuesta 8–11 s hasta para un `COUNT`,
   independientemente del filtro de proveedor (los joins se materializan antes de filtrar);
   55 s para emitir todas las columnas de un proveedor grande.
2. La **materialización de `#refs`** — la propia mitigación del código
   (`buildRefsTemp`, INSERT por lotes de 200 filas desde PHP) es hoy el mayor costo: 163 s
   para un proveedor grande (~155 viajes al servidor).

En cache-miss (primera carga del día, proveedor grande) esto suma **~3–4 minutos**, y afecta
a los 5 informes que usan el catálogo de productos.

La maquinaria de `#refs` está **centralizada** en `api/lib_refs.php` (dos funciones
`getRefsCached` y `buildRefsTemp`), usada por `informe_g00.php`, `informe_o14.php`,
`informe_evol.php`, `informe_o45.php`, `informe_geo.php`. Esto hace el fix quirúrgico.

## Objetivo

Reducir el peor caso (cache-miss, proveedor grande) de **~3–4 min a pocos segundos**,
materializando la dimensión de producto en una tabla indexada refrescada de noche, sin tocar
las queries de agregación de los informes ni cambiar sus resultados.

## Arquitectura

### 1. Tabla materializada `INTEGRACION.dbo.Items_Mat`

Copia denormalizada de lo que produce hoy la vista `ITEMS`, con **exactamente las mismas
columnas y normalización `ISNULL`** que el `SELECT` de `getRefsCached` (`lib_refs.php:13-18`),
**más `PROVEEDOR`** (para filtrar):

- Columnas: `PROVEEDOR`, `REFERENCIA`, `MARCA`, `TIPO`, `LINEA`, `SUBLINEA`, `CATEGORIA`,
  `SUBCATEGORIA`, `GENERO`, `PUBLICO_OBJETIVO` (todas con los mismos `ISNULL(...,'SIN ...'/'')`
  que hoy, para paridad exacta).
- **Índice clustered por `(PROVEEDOR, REFERENCIA)`** → leer las refs de un proveedor es un
  *index seek* de milisegundos.

### 2. Refresco nocturno — stored proc + SQL Server Agent job

Rafael tiene acceso a SQL Agent. Un stored proc `dbo.usp_Refresh_Items_Mat` puebla la tabla
desde la vista `ITEMS`, con patrón **staging + swap atómico** para que la tabla viva **nunca
quede vacía ni parcial** durante el refresh:

1. Poblar una tabla de staging `dbo.Items_Mat_stg` (`TRUNCATE` + `INSERT ... SELECT ... FROM
   dbo.ITEMS`). Esta es la parte lenta (la vista), pero corre sobre staging, sin afectar la
   tabla viva.
2. **Swap atómico** en una transacción corta (rename de `Items_Mat` → `Items_Mat_old`,
   `Items_Mat_stg` → `Items_Mat`, drop del viejo; recrear el índice/nombre según haga falta).
   La tabla viva sirve el dataset anterior hasta el instante del swap.

El costo de la vista `ITEMS` (minutos) se paga **una vez por noche para todos los
proveedores**, no por request. Job de SQL Agent nocturno (ej. 03:00) que ejecuta el proc.

**Nota de implementación (para el script SQL que revisa Rafael):** cuidar los nombres de
índices al hacer `sp_rename` para evitar colisiones entre corridas; el script SQL concreto es
parte del plan y Rafael lo revisa/ajusta en SSMS.

### 3. Rewrite centralizado en `api/lib_refs.php`

Nueva función `buildRefsFromMat($conn, $proveedor)` que reemplaza el par
`getRefsCached()` + `buildRefsTemp()`:

```sql
CREATE TABLE #refs ( REFERENCIA varchar(50) NOT NULL PRIMARY KEY,
    MARCA varchar(40), TIPO varchar(40), LINEA varchar(40), SUBLINEA varchar(40),
    CATEGORIA varchar(40), SUBCATEGORIA varchar(60), GENERO varchar(40), PUBLICO_OBJETIVO varchar(60));
INSERT INTO #refs (REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO)
    SELECT REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO
    FROM dbo.Items_Mat WITH (NOLOCK) WHERE PROVEEDOR = ?;
```

- El `CREATE TABLE #refs` va **sin parámetros** (scope de sesión) y luego el `INSERT ...
  SELECT ... WHERE PROVEEDOR = ?` con el parámetro — respeta el gotcha conocido de `sqlsrv`
  (temp table creada fuera del scope de `sp_executesql`).
- Reemplaza los 55 s (`SELECT` a la vista) **y** los 163 s (INSERT por lotes) por **una sola
  sentencia server-side** que lee la tabla indexada.
- La **estructura de `#refs` queda idéntica** → **todas las queries de agregación de los 5
  informes quedan sin cambios** (cero riesgo en esa capa).
- Cada uno de los 5 endpoints cambia el par de llamadas
  `getRefsCached()` + `buildRefsTemp()` por una sola `buildRefsFromMat($conn, $proveedor)`.
- Se **elimina el cache JSON** de refs (`cache/g00_refs_*.json`) y su validación de esquema:
  ya no aporta, porque leer `Items_Mat` es de milisegundos.

### 4. Fallback de transición

`buildRefsFromMat` verifica `OBJECT_ID('dbo.Items_Mat')`. Si la tabla **no existe**, cae al
camino viejo (`getRefsCached` + `buildRefsTemp`). Así el despliegue PHP es seguro sin importar
si la tabla ya está creada. Una vez confirmada la tabla en prod, el fallback puede retirarse en
una limpieza posterior (las funciones viejas se conservan hasta entonces).

## Correctitud (paridad)

`Items_Mat` es un *snapshot* del **mismo** `SELECT ... FROM ITEMS` con los mismos `ISNULL` →
las filas insertadas en `#refs` son idénticas a las de hoy. La **frescura diaria** ya es el
comportamiento actual (el cache de refs se reconstruye a diario), así que el refresh nocturno
no cambia la semántica observable.

## Propiedad y despliegue (orden importa)

- **DDL/proc/job los ejecuta Rafael en SSMS.** Claude **no** corre DDL/escrituras contra
  producción. Claude entrega **scripts SQL versionados** en `plataforma_20/sql/`:
  - `sql/002_items_mat.sql` — `CREATE TABLE Items_Mat` (+ staging) + índice clustered.
  - `sql/003_usp_refresh_items_mat.sql` — el stored proc de refresh (staging + swap).
  - `sql/004_job_items_mat.sql` — creación del job de SQL Agent (o instrucciones para crearlo
    desde SSMS).
- La parte **PHP** (`lib_refs.php` + los 5 call-sites) va por **rama normal** con revisión.
- **Orden de despliegue:**
  1. Rafael corre `002`/`003`, ejecuta el proc una vez para poblar `Items_Mat`, y **verifica
     que tiene datos** (COUNT por proveedor). Crea el job (`004`).
  2. Se mergea el cambio PHP. (Gracias al fallback, aun si el PHP llegara antes que la tabla,
     no rompe.)

## Verificación

1. **Rendimiento:** re-correr el perfilador → el build de `#refs` debe bajar de ~218 s a
   **sub-segundo / pocos segundos** para un proveedor grande.
2. **Paridad de resultados:** para varios proveedores (uno grande, uno chico), comparar el
   contenido de `#refs` (conteo + hash/orden de filas) construido por el camino viejo vs
   `buildRefsFromMat`, y comparar la salida JSON de un endpoint (ej. G00 tab detal) antes vs
   después — deben coincidir.
3. `php -l` limpio en los archivos PHP tocados. Verificación funcional en navegador de los 5
   informes (cargan y filtran correctamente).

## Fuera de alcance

- **Columnstore / optimización de las agregaciones de hechos (2–4 s).** Rafael decidió dejarlo
  fuera de C. Si tras este fix las agregaciones siguen molestando, se atacan en un **C2**
  aparte, con nueva medición.
- Cualquier cambio a la lógica de negocio o a los resultados de los informes.
