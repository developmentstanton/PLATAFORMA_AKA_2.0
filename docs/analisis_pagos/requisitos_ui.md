# Análisis de Pagos — Requisitos de UI (vista proveedor)

Audiencia: **proveedor** (portal de aliados). Cada aliado ve SOLO sus pagos
(filtrado por su NIT de sesión). Es la vista personal "¿cuándo me pagan?".

## Tabla principal: "Resumen Mensual Flujo de Egresos"
(Ref. imagen `Desktop/p78/WhatsApp Image 2026-06-22 at 11.15.51 AM.jpeg`)

Matriz tipo Power BI:
- **Filas (jerarquía):** `Fecha vencimiento` → `Dias` → `RAZON_SOCIAL`
  (RAZON_SOCIAL es constante para el proveedor logueado). Ordenadas por
  Fecha vencimiento ascendente. Con expandir/colapsar (+/-).
- **Columnas:** `Año Pago` (año, ej. 2026) → **mes** (JUNIO, …) + subtotal `Total` por año.
- **Total general:** última columna, resaltada amarillo.
- **Valores:** SUMA de `En Pesos`. Admite **negativos** (notas crédito / devoluciones).
- Nota: documentos vencidos se reprograman a "próximo viernes" → caen todos en el
  mes actual (por eso en la captura casi todo está en JUNIO 2026).

## Filtros / slicers
(Ref. imagen `Desktop/p78/WhatsApp Image 2026-06-22 at 11.16.54 AM.jpeg`)

1. **Causado** — dropdown (default "Todas"; valores SI / NO).
2. **Dias Vto Flujo** — rango numérico (min / max), filtra por `Dias`
   (= DATEDIFF(Fecha vencimiento, hoy)). Ej. rango -394 a 3857.
3. **Fecha** — rango de fechas (desde / hasta) sobre `Fecha vencimiento`.
4. **PROVEEDOR** — en el PBI interno es selector libre; en la versión del portal
   queda **fijo al NIT del proveedor en sesión** (no editable).

## Pendientes de definir en el brainstorming
- Origen de datos: vivo vs materializado; cómo refrescar `*_PBI` y manejar `Anticipos` (DDL).
- TRM (Valor DIVISAS): API actual vs TRM oficial; cacheo.
- Mapeo sesión → NIT del proveedor (hoy `$_SESSION['proveedor']` guarda razón social).
- Qué muestra el expandir de `Fecha vencimiento` (¿documentos?).
