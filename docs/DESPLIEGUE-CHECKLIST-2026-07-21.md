# Checklist de despliegue a producción (WMS-LAB) — 2026-07-21

> WMS-LAB **es** el servidor de producción. Mecanismo de copia: **manual (share/RDP)**.
> RDS `INTEGRACION` es **compartida** (dev/staging/prod) → el DDL ya aplicado sirve para prod.
>
> **Rutas (OJO — convivencia v1.0 / v2.0 en el mismo servidor de producción):**
> - Staging local (origen de la copia): `C:\xampp\htdocs\plataforma_20_produccion`
> - ⛔ `E:\wms\www\plataforma` = **versión 1.0 EN PRODUCCIÓN y funcionando. NO TOCAR.**
> - ✅ `E:\wms\www\plataforma_20` = versión 2.0 (esta). **Todo el despliegue va aquí.**
> - 🔄 **Cutover final** (paso último, cuando todo esté ajustado): reemplazar la v1.0 → `plataforma_20` pasa a ser `plataforma`. Hasta entonces, nada destructivo sobre `\plataforma`.

## Estado de partida (verificado hoy)

| Ítem | Estado |
|---|---|
| Código en `main` = `origin/main`, sin ediciones a mano | ✅ verificado |
| Todas las ramas feature mergeadas a `main` (último commit 2026-07-17) | ✅ |
| DDL en RDS: `usuarios_portal_aka.proveedor_items` | ✅ existe, 21/25 aliados curados |
| DDL en RDS: `Items_Mat` (65.943 filas) | ✅ existe |
| DDL en RDS: `g00_cache_ventas` / `g00_cache_siembra` | ✅ existen |
| DDL en RDS: `o14_cache_base` / `evol_cache_base` | ✅ existen |
| `php.exe` ya NO quemado en `index.php` (usa `resolverPhpExe()`) | ✅ |
| Paquete de deploy limpio (`git archive origin/main`) | ✅ generado |
| Cloudinary `cloud_name` en `informes/geo.php:63` | ⚠️ vacío (fotos = placeholder; no bloqueante) |

**Conclusión:** DDL listo. Falta: copiar código, agendar 2 tareas nocturnas, validar E2E.

---

## FASE 0 — (Opcional) Validación local antes de subir
- [ ] Correr una suite de paridad clave para confianza (ej. `evol_golden_test.php`, `o14c_paridad`).
      Claude puede correrlas aquí a pedido.

## FASE 1 — Copiar el código a WMS-LAB
Paquete: `plataforma20_deploy_2026-07-21.zip` (export limpio de `origin/main`).

- [ ] Copiar el ZIP a WMS-LAB por RDP/share.
- [ ] Extraer **sobre** la carpeta de la app en WMS-LAB (`RUTA_APP`), sobrescribiendo.
      - El ZIP solo trae `cache/.gitkeep` → **NO** borra los `.gz` de cache ya calientes.
      - **NO** copiar/editar `conexion/` a mano: la RDS es la misma para todos los entornos.
- [ ] (Opcional, más limpio) Extraer a carpeta nueva y hacer swap, para eliminar archivos viejos
      ya borrados en git (ej. `prebuild_o14c.php/.bat` que se eliminaron). No crítico.

> ⚠️ **`admin/` viaja en el paquete desde 2026-07-22.** El módulo administrativo se mergeó a
> `main`, así que el export de `origin/main` lo incluye y quedará accesible en
> `RUTA_APP\admin\`. Es una decisión tomada, no un descuido: el login exige credenciales
> válidas **y** `link1 = 'Administrador'`, y el módulo todavía no tiene ninguna página con
> funcionalidad (solo un shell vacío). Lo que sí suma es **una superficie de login extra**,
> con las mismas limitaciones heredadas del portal: contraseñas en texto plano y bloqueo por
> intentos evadible descartando cookies. Ver
> `docs/superpowers/specs/2026-07-22-admin-login-design.md`.

## FASE 2 — Ajustes en WMS-LAB
- [ ] `where php` → confirmar la ruta real de `php.exe` en WMS-LAB.
- [ ] Si NO es `C:\xampp\php\php.exe`, ajustar `set PHP_EXE=...` en:
      - `sql\refrescar_items_mat.bat`
      - `sql\prebuild_all.bat`
      (`index.php` ya se auto-resuelve, no requiere ajuste.)
- [ ] Confirmar que la carpeta `cache\` es **escribible** por la cuenta de la tarea (SYSTEM).

## FASE 3 — Tareas nocturnas (Task Scheduler)
Orden importa: **Items_Mat (03:00) → prebuild_all (03:30)**. Correr en `cmd` **como Administrador**.

```cmd
REM RUTA_APP = la v2.0 (plataforma_20). NUNCA apuntar a \plataforma (v1.0 en producción).
set RUTA_APP=E:\wms\www\plataforma_20

schtasks /Create /TN "Plataforma20\Refresco Items_Mat" ^
  /TR "\"%RUTA_APP%\sql\refrescar_items_mat.bat\"" ^
  /SC DAILY /ST 03:00 /RL HIGHEST /RU SYSTEM /F

schtasks /Create /TN "Plataforma20\Prebuild caches" ^
  /TR "\"%RUTA_APP%\sql\prebuild_all.bat\"" ^
  /SC DAILY /ST 03:30 /RL HIGHEST /RU SYSTEM /F
```

Forzar una corrida ya (sin esperar la noche) y revisar logs:
- [ ] `schtasks /Run /TN "Plataforma20\Refresco Items_Mat"`
- [ ] `schtasks /Run /TN "Plataforma20\Prebuild caches"`
- [ ] `sql\prebuild_all.log` termina con `[prebuild_all] fin: OK=N FALLO=0`
- [ ] `dir cache\evol_*.json.gz` muestra `.gz` recientes

## FASE 4 — Validación E2E en el navegador (WMS-LAB)
- [ ] Iniciar sesión en el portal como un **aliado con mapeo curado** (uno de los 21).
- [ ] Abrir cada informe y confirmar que **carga con datos** (no vacío) y **filtra rápido**:
      - [ ] O45 (Índice de Ventas)
      - [ ] G00
      - [ ] O14 (curva de tallas)
      - [ ] EVOL
- [ ] Confirmar que un aliado **sin** mapeo curado no rompe (degrada, no error).
- [ ] Revisar `sql\prewarm_login.log` — el login-prewarm se dispara al iniciar sesión.

## FASE 5 — (Opcional) Cloudinary — fotos de tiendas (informe geo)
- [ ] Si se quieren fotos reales: poner el `cloud_name` en `informes/geo.php` línea 63
      (`const CLOUDINARY_CLOUD = '...';`). Vacío = solo placeholder (sin red, sin error).

---

## Rollback rápido
- Código: volver a extraer el ZIP del despliegue anterior (o `git checkout` del commit previo si WMS-LAB tuviera git).
- DDL: no hay rollback necesario — todo el DDL nuevo es idempotente/aditivo (`proveedor_items` es columna nueva NULL; las tablas de cache se repueblan solas).
- Tareas: `schtasks /Delete /TN "Plataforma20\..." /F` si hubiera que quitarlas.
