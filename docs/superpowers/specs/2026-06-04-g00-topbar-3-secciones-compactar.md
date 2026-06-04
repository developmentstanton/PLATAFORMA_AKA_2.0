# G00 — Topbar en 3 secciones + compactar informe

**Fecha:** 2026-06-04 · **Informe:** G00 · **Rama:** `feat/g00-detal-replica-pbi`

## Objetivo
Reorganizar el topbar del G00 en 3 secciones (tabla de fechas | título centrado | botones), compactar la barra de filtros y el espaciado entre cards, mover los sub-textos de los KPIs a tooltips, y reducir la fuente general del informe. Todo lo de compactación/fuente se acota a `#page-informes-g00` para no afectar O14 ni otras páginas.

## Cambios

### A. Topbar en 3 secciones (solo en G00)
Estructura del topbar (`dashboard.php`): `#topbarDates` (izq) | `.topbar-titles` (centro) | `.topbar-actions` (der).
- **`#topbarDates`** (oculto por defecto): tabla 2×3 — fila 1 encabezados `DESDE`/`HASTA`; fila 2 fechas año actual; fila 3 fechas año anterior (formato largo `fmtFechaLarga`). Reemplaza el `#pageSubtitle` de texto en G00.
- **Título** centrado: `DASHBOARD DE VENTAS - {PROVEEDOR}`.
- **Botones:** Actualizar + ⚠ + salir (actuales).
- Modo G00 = clase `topbar--g00` en `#topbar` (grid `auto 1fr auto`, título centrado, `#pageSubtitle` oculto, `#topbarDates` visible). `g00OnEnter` lo activa y `loadDetal` puebla la tabla (`renderTopbarDates(rango)`); `showPage` lo desactiva al salir de G00 (quita la clase, oculta `#topbarDates`). Otras páginas: topbar normal (título izq, sin tabla).

### B. Barra de filtros compacta (acotado a `#page-informes-g00`, porque O14 reusa `.g00-filters`)
- `#page-informes-g00 .g00-filters`: `padding 8px 12px`, `gap 6px`, `margin-bottom 10px`.
- `#page-informes-g00 .g00-filter-row`: `padding-bottom 6px`.

### C. Menos espacio entre cards (acotado a `#page-informes-g00`)
- `#page-informes-g00 .card { margin-bottom: 10px; }`
- `#page-informes-g00 .stats-grid { margin-bottom: 14px; }`

### D. Sub-texto de KPIs → tooltip nativo
- Quitar los 3 `.g00-kpi-sub`. Agregar `title` a cada `.g00-kpi`:
  - Tiendas → `bodegas con venta`; $ Prom → `$ por par (valor ÷ unidades)`; MB → `margen %`.

### E. Fuente general del G00 más pequeña (acotado a `#page-informes-g00`)
- `#page-informes-g00 { font-size: 13px; }` (base).
- `#page-informes-g00 .g00-kpi-value { font-size: 22px; }` (era 26).
- `#page-informes-g00 table.disp-table th, ... td { font-size: 11px; }` (era 12).
- `#page-informes-g00 .card-title { font-size: 13px; }`.

## Decisiones
- Tooltip = nativo (`title`).
- TODA compactación/fuente (B, C, E) va bajo `#page-informes-g00` para no afectar O14 (que reusa `.g00-filters`, `.card`, `.stats-grid`, `.disp-table`).
- Topbar: el modo 3-secciones es exclusivo de G00 vía la clase `topbar--g00`; otras páginas conservan el topbar actual.

## Testing
- Sin tests automatizados (presentacional). `php -l` en ambos archivos. Verificación en navegador (Rafael): topbar 3 secciones en G00, tabla de fechas correcta, título centrado, botones; otras páginas sin cambios; filtros y cards más compactos; tooltips en KPIs; fuente menor; **O14 sin romperse**.
