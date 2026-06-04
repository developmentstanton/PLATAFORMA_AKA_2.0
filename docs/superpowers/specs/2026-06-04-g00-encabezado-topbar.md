# G00 — Encabezado al topbar (quitar card, título+período en el nav)

**Fecha:** 2026-06-04 · **Informe:** G00 · **Rama:** `feat/g00-detal-replica-pbi`

## Objetivo

Eliminar el card de encabezado del G00 (`.informe-header`: badge "G00", título, proveedor/período, botones Exportar y Actualizar) y llevar esa información al **topbar compartido** del dashboard, que pasa a mostrar dos líneas cuando el informe G00 está activo.

## Alcance

**Dentro:**
1. Quitar el card `.informe-header` completo de `informes/g00.php` (incluye badge G00, título, línea proveedor·período, botón Exportar y botón Actualizar).
2. Topbar (`dashboard.php`): título + subtítulo (2 líneas) + botón **Actualizar** (icono + texto), todos visibles **solo en G00**.
3. Período en formato largo en español: `"Jueves 01 de Enero de 2026 al Viernes 04 de Junio de 2026 (vs mismo período 2025)"`.

**Fuera:** botón **Exportar** (se elimina, no se reubica). Otros informes/páginas: el topbar se comporta como hoy (1 línea, sin botón Actualizar).

## Diseño

### Topbar (cuando `pageId === 'informes-g00'`)
```
DASHBOARD DE VENTAS - BELTRANY SAS                 [↻ Actualizar] [⚠] [⎋ salir]
Jueves 01 de Enero de 2026 al Viernes 04 de Junio de 2026 (vs mismo período 2025)
```
- **Línea 1 (destacada):** `DASHBOARD DE VENTAS - {PROVEEDOR}`. El `- {PROVEEDOR}` se completa con `proveedorActual`/`window.PROVEEDOR_ACTUAL` (al entrar) y con `data.proveedor` (tras cargar). Si no hay proveedor: `DASHBOARD DE VENTAS - —`.
- **Línea 2 (tenue):** el período. Antes de cargar: "Cargando período…".
- **Derecha:** `Actualizar` (icono `fa-arrows-rotate` + texto, color destacado/acento, `onclick="g00Load()"`) seguido de ⚠ (alertas) y salir (logout), en ese orden.

### Estructura
- `dashboard.php` topbar: el `<h2 id="pageTitle">` se envuelve en `<div class="topbar-titles">` con un `<div id="pageSubtitle" class="topbar-subtitle">` debajo (oculto por defecto). En el grupo derecho se agrega `<button id="topbarG00Refresh">` (oculto por defecto).
- `showPage(pageId, navItem)`: antes de los hooks por página, **resetea** los extras de G00 (oculta `#pageSubtitle` y `#topbarG00Refresh`). Para G00, `g00OnEnter` los activa. `titles['informes-g00']` se mantiene como base `'DASHBOARD DE VENTAS'` (g00OnEnter lo enriquece con el proveedor).
- `informes/g00.php`:
  - Se elimina el bloque `<!-- HEADER -->` … `</div>` (`.informe-header`).
  - `g00OnEnter`: muestra `#topbarG00Refresh`, fija `#pageTitle` = `DASHBOARD DE VENTAS - {proveedorActual||'—'}`, muestra `#pageSubtitle` con "Cargando período…".
  - En `loadDetal().then` (donde hoy se setean `g00-proveedor`/`g00-periodo`): actualizar `#pageTitle` con `data.proveedor` y `#pageSubtitle` con el período largo. Se eliminan las referencias a los ids viejos `g00-proveedor`/`g00-periodo`.
  - Helper `fmtFechaLarga(iso)`: parsea `YYYY-MM-DD` manual (`new Date(y, m-1, d)` local, sin corrimiento UTC) y devuelve `"{DiaSemana} {DD} de {Mes} de {AAAA}"` con arreglos de días/meses en español capitalizados.
  - Período: `fmtFechaLarga(desde_actual) + ' al ' + fmtFechaLarga(hasta_actual) + ' (vs mismo período ' + añoAnterior + ')'`, donde `añoAnterior` = año de `hasta_anterior`. Fallback si no hay `rango`: `'Datos al ' + fecha`.

### CSS (`informes/g00.php` o `dashboard.php` topbar)
- `.topbar-titles { display:flex; flex-direction:column; gap:2px; }`
- `.topbar h2#pageTitle` destacado (mantener/realzar: color primary, peso 700).
- `.topbar-subtitle { font-size:12px; color:var(--text-light); }`
- El botón `#topbarG00Refresh` con estilo destacado (acento) + icono y texto; el topbar acomoda 2 líneas (alinear el grupo derecho arriba).

## Decisiones
- Exportar se elimina (no reubica).
- Actualizar = icono + texto, color destacado.
- Período con comparativo conciso `(vs mismo período {año anterior})`.
- Reset de extras en `showPage` para no “filtrar” el subtítulo/botón a otras páginas.

## Testing
- Sin tests automatizados (cambio puramente de presentación). Verificación en navegador (Rafael): entrar a G00 → topbar 2 líneas + Actualizar; el card G00 ya no aparece; cambiar a otra página → topbar vuelve a 1 línea sin Actualizar; fechas en formato largo correcto; Actualizar recarga.
- `php -l` en los 2 archivos.
