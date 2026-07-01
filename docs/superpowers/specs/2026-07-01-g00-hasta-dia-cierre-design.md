# G00 — Fecha final "hasta la fecha" = día de cierre (ayer)

Fecha: 2026-07-01
Informe: G00 (ventas). No afecta O45/O14/EVOL/Pagos.

## Problema

En G00 el cierre de ventas es diario: el día en curso (hoy) aún no está
cerrado, por lo que sus datos están incompletos. Hoy, el preset "Hasta la
fecha" y el default de carga usan **hoy** como fecha final. En una comparación
de dos años esto produce un sesgo: el año mayor (en curso) no tiene datos de
hoy, pero el año menor sí tiene datos de la misma fecha del año pasado → la
comparación queda desbalanceada.

## Objetivo

Que "Hasta la fecha" y el default signifiquen **hasta el día inmediatamente
anterior (ayer)** — el último día cerrado — replicando el mismo mes/día en
ambos años comparados. "Año completo" no cambia (muestra el año entero).

### Ejemplos (regla)

Con hoy = 2026-07-01 (⇒ ayer = 2026-06-30):

| Selección                         | Fecha final resultante            |
|-----------------------------------|-----------------------------------|
| Hasta la fecha, año 2026          | 2026-06-30                        |
| Hasta la fecha, 2025 vs 2024      | 2025-06-30 y 2024-06-30           |
| Año completo, 2026                | 2026-01-01 → 2026-12-31 (año entero; sin datos futuros) |
| Año completo, año pasado          | sin cambios                       |
| Fecha pasada ya cerrada (manual)  | sin cambios                       |
| Hoy elegido manualmente (año en curso) | recortado a ayer             |

## Diseño

### Parte 1 — Frontend (`informes/g00.php`) — arreglo central

- **`g00QuickRange('ytd')`**: fijar mes/día de la fecha final al **mes/día de
  ayer** (no de hoy). Como la comparación replica el mismo mes/día en el año
  menor (calendario `diaadia`), ambos años quedan alineados automáticamente.
- **Default de carga** (selects PHP `#g00-hasta-mes` / `#g00-hasta-dia`,
  líneas ~231-232): arrancar en el mes/día de **ayer** (rango default
  01/01 → ayer).
- **`g00SyncQuickRange`**: la detección de "Hasta la fecha" activo compara la
  fecha final contra **ayer** (no hoy).
- **"Año completo"** (`g00QuickRange('full')`): sin cambios.
- "Ayer" en cliente = `new Date()` menos 1 día (rueda mes/año de forma
  natural).

### Parte 2 — Backend (`api/informe_g00.php` + `api/lib_g00_rango.php`) — garantía "siempre día de cierre"

Respaldo para que ni eligiendo hoy manualmente entre el día sin cerrar.

- Nueva función pura en `lib_g00_rango.php`:
  ```php
  // Recorta la fecha final al día de cierre (ayer) SOLO si es del año en curso
  // y NO es 31-dic (año completo). Deja intactas fechas pasadas ya cerradas.
  function g00_cap_hasta(string $hasta, string $ayer): string {
      if (substr($hasta, 5) !== '12-31' && $hasta > $ayer) return $ayer;
      return $hasta;
  }
  ```
  Nota: `$hasta > $ayer` (comparación lexicográfica de `YYYY-MM-DD` = cronológica)
  solo es cierto para hoy/futuro, que en la práctica es el año en curso.
- En `informe_g00.php`, tras resolver `$hastaAct` (líneas 39-45) y **antes** de
  `g00_rango_comparacion(...)` (línea 52):
  ```php
  $ayer = date('Y-m-d', strtotime('-1 day'));  // fecha del servidor
  $hastaAct = g00_cap_hasta($hastaAct, $ayer);
  ```
  Al aplicarse antes de derivar el año menor, ambos periodos quedan espejados.
- "Año completo" (31-dic) queda **exento** para no romper el "año entero".
- "Ayer" = fecha del servidor (base del cierre diario del negocio).

## Casos borde

- **1-ene**: en el año en curso aún no hay ningún día cerrado. El mes/día de
  ayer es 12-31, que combinado con el selector de año en curso da 01/01→12/31
  (año en curso, sin datos todavía) — aceptable, no roto. El backend solo
  recorta cuando `hasta > ayer`, así que no fuerza rango invertido
  (desde>hasta). Es un borde de una vez al año, sin manejo especial.
- **Año completo del año en curso**: exento del recorte; muestra 01/01→31/12
  (los días futuros simplemente no tienen datos).

## Testing

- Test unitario de `g00_cap_hasta` en `tests/g00_rango_comparacion_test.php`
  (patrón de funciones puras existente): recorta hoy→ayer; respeta fecha pasada;
  exime 31-dic; no toca año pasado.
- `php -l` en los 3 archivos tocados.
- E2E en navegador por Rafael: "Hasta la fecha", default, y comparación
  2025 vs 2024 muestran hasta ayer; "Año completo" muestra el año entero.

## Alcance / no-objetivos

- Solo G00. No se tocan otros informes.
- No se cambia la lógica de comparación de años existente
  (`g00_rango_comparacion`), solo se le pasa la fecha ya recortada.
