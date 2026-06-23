# Spec — Codificación: pestaña "Mis solicitudes" con historial real

**Fecha:** 2026-06-23
**Proyecto:** plataforma_20
**Sección:** GESTIÓN → Codificación → pestaña "Mis solicitudes"

## Objetivo

Reemplazar la tabla **demo** de la pestaña "Mis solicitudes" por el **historial real** de envíos de
codificación del aliado logueado, leído de `INTEGRACION.dbo.consecutivo_planillas_aka` (la misma tabla donde
la Carga Masiva registra cada envío; ver [[2026-06-23-codificacion-carga-masiva-design]]).

La tabla muestra, **del más reciente al más antiguo**, 5 columnas: número de solicitud, fecha, NIT del tercero,
nombre del tercero y estado (con color: Estudio=morado, Rechazado=rojo, Aprobado=verde).

## Decisiones (brainstorming 2026-06-23, aprobadas por Rafael)

- **Alcance:** SOLO las solicitudes del **aliado logueado** (consistente con el portal por-proveedor).
- **Filtro:** por `nombre_cliente = $_SESSION['usuario']`. Es la clave que comparten las filas **viejas**
  (304 filas con `nit` NULL) y las **nuevas** (la Carga Masiva inserta `nombre_cliente = $_SESSION['usuario']`).
  Filtrar por `nit` perdería las filas viejas, por eso se filtra por nombre.
- **Orden:** `consecutivo DESC` (IDENTITY ascendente ⇒ DESC = más reciente primero).
- **Enfoque:** endpoint JSON + render en JS al abrir la pestaña (patrón de los informes del portal). No render
  PHP inline (engordaría `dashboard.php` y correría en cada carga aunque no se abra la pestaña).
- **Estados y colores:** Estudio → morado (clase nueva `.status-estudio`), Rechazado → rojo (`.status-rechazado`,
  ya existe), Aprobado → verde (`.status-aprobado`, ya existe).
- **YAGNI:** sin paginación, sin filtros, sin botón "Ver"/detalle, sin edición de estado (el estado se cambia por
  otro medio; aquí solo se muestra).

## Estado actual

- Tabla `consecutivo_planillas_aka`: `consecutivo` int IDENTITY (PK), `nombre_cliente` varchar(60), `fecha` date,
  `estado` varchar(20) NOT NULL DEFAULT 'Estudio' (valores: Estudio / Rechazado / Aprobado), `nit` varchar(20) NULL.
- Pestaña "Mis solicitudes" hoy = `<div class="card" id="codTab-solicitudes" style="display:none;">` con una tabla
  **demo estática** (columnas Referencia/Descripción/Marca/Colores/Tallas/Recepción/P.V.S.P/Estado/Ver, datos ficticios).
- Clases CSS de estado ya existentes (en `dashboard.php`): `.status-aprobado` (verde `#ecfdf5/#065f46`),
  `.status-rechazado` (rojo `#fef2f2/#991b1b`), `.status-revision` (morado `#ede9fe/var(--primary)`).
- `lib_codificacion.php`, `conexion/conexion_integracion.php` y el patrón de reintento RDS ya existen.

## Arquitectura

```
[ Pestaña "Mis solicitudes" (dashboard.php) ]
   showCodTab('solicitudes')  →  cargarSolicitudes()  --(fetch)-->  [ api/codificacion_solicitudes.php ]
                                                                        ├─ valida sesión
                                                                        ├─ reintento conexión RDS
                                                                        └─ cod_listar_solicitudes($conn, $_SESSION['usuario'])
                                                                              SELECT ... WHERE nombre_cliente=? ORDER BY consecutivo DESC
   ← JSON {ok, solicitudes:[...]}  →  render de filas con badge de estado coloreado
```

### Componentes

1. **`api/lib_codificacion.php` — nueva función `cod_listar_solicitudes`**
   - Firma: `cod_listar_solicitudes($conn, string $nombre): array`.
   - SQL parametrizado:
     ```sql
     SELECT consecutivo, fecha, nit, nombre_cliente, estado
     FROM consecutivo_planillas_aka WITH (NOLOCK)
     WHERE nombre_cliente = ?
     ORDER BY consecutivo DESC
     ```
   - Devuelve lista de filas: `['consecutivo'=>int, 'fecha'=>string('Y-m-d'|''), 'nit'=>?string, 'nombre'=>string, 'estado'=>string]`.
     `fecha` se formatea de la columna `date` (DateTime de sqlsrv) a `'Y-m-d'`; si es null → `''`.
   - Lanza `RuntimeException` si la query falla.

2. **`api/codificacion_solicitudes.php` (nuevo endpoint)**
   - `session_start()`; header JSON; si no hay `$_SESSION['usuario']` → 401 `{ok:false,error}`.
   - `require conexion_integracion.php` + reintento RDS 4× (mismo patrón que el endpoint de carga).
   - `$rows = cod_listar_solicitudes($dbConnect, $_SESSION['usuario'])` (try/catch → 500 `{ok:false}`).
   - Devuelve `{ok:true, solicitudes:$rows}`.

3. **`dashboard.php` — pestaña "Mis solicitudes" + JS + CSS**
   - Reemplazar la tabla demo dentro de `#codTab-solicitudes` por:
     ```html
     <table>
       <thead><tr><th># Solicitud</th><th>Fecha</th><th>NIT</th><th>Nombre del tercero</th><th>Estado</th></tr></thead>
       <tbody id="codSolBody"></tbody>
     </table>
     ```
   - JS `cargarSolicitudes()`: `fetch('api/codificacion_solicitudes.php')` → pinta filas en `#codSolBody`.
     - Fecha `Y-m-d` → `dd/mm/yyyy`. NIT vacío/null → `—`. Nombre escapado.
     - Estado → `<span class="status status-{clase}">{Estado}</span>` con mapa: `Estudio→status-estudio`,
       `Rechazado→status-rechazado`, `Aprobado→status-aprobado` (fallback: sin clase de color para valores inesperados).
     - Sin filas → una fila "No tienes solicitudes registradas." (colspan 5, centrado, gris).
     - Error → fila "No se pudieron cargar las solicitudes."
   - Disparo: en `showCodTab('solicitudes')` llamar `cargarSolicitudes()` (lazy; refleja envíos recién hechos en la
     pestaña Archivos). El número de solicitud se escapa/castea a entero.
   - CSS nuevo: `.status-estudio { background:#ede9fe; color:var(--primary); }` (morado), junto a las otras `.status-*`.

## Manejo de errores

- Sin sesión → 401 JSON; el front muestra la fila de error.
- Falla de conexión RDS tras reintentos → 500 JSON `{ok:false}`; front muestra fila de error.
- Falla de la query → `RuntimeException` → catch en el endpoint → 500.
- Nunca se exponen `sqlsrv_errors` crudos al cliente (se loguean con `error_log`).

## Seguridad

- SQL parametrizado (filtro por `?`).
- El filtro usa `$_SESSION['usuario']` (no un parámetro del cliente) → el aliado solo ve lo suyo.
- Nombre del tercero escapado en el render (evita XSS, aunque el valor venga de BD).

## Testing

- **`tests/codificacion_solicitudes_test.php`** (guardado con `COD_TEST_DB=1`, como el de registro):
  - Inserta (vía `cod_registrar_envio`) 2 filas para un nombre de prueba `__TESTSOL__xxxx` y 1 para otro nombre distinto.
  - `cod_listar_solicitudes($conn, '__TESTSOL__xxxx')` → devuelve exactamente las 2 propias (filtro), **ordenadas
    consecutivo DESC** (la última insertada primero), con los campos esperados (consecutivo int, estado 'Estudio', nit).
  - No incluye la fila del otro nombre (aislamiento).
  - **Limpieza:** DELETE de las 3 filas insertadas.
  - Sin `COD_TEST_DB` → SKIP.
- **No-regresión:** `php -l` en `dashboard.php`, `api/codificacion_solicitudes.php`, `api/lib_codificacion.php`;
  la suite de codificación previa (validar/correo/registro) sigue verde; `showCodTab` sigue alternando pestañas.
- **E2E navegador (Rafael):** abrir "Mis solicitudes" → ver el historial propio ordenado, con NIT/nombre/fecha y
  los 3 colores de estado; hacer un envío en "Archivos" y confirmar que aparece arriba al volver a la pestaña.

## Fuera de alcance (YAGNI)

- Paginación, búsqueda, filtros por fecha/estado.
- Botón "Ver"/detalle de cada solicitud (la tabla actual de envíos no guarda los archivos).
- Edición del estado desde la UI.
- Vista de "todas" las solicitudes (admin) — explícitamente descartada (es por aliado).

## Artefactos

- **Nuevo:** `api/codificacion_solicitudes.php`, `tests/codificacion_solicitudes_test.php`.
- **Modificado:** `api/lib_codificacion.php` (función `cod_listar_solicitudes`), `dashboard.php`
  (tabla de `#codTab-solicitudes` + JS `cargarSolicitudes` + disparo en `showCodTab` + CSS `.status-estudio`).
