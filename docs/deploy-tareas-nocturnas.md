# Tareas programadas nocturnas (WMS-LAB / producción)

Guía para montar en el **servidor WMS-LAB** (producción) las 2 tareas nocturnas que mantienen calientes las caches. Orden importa: **ETL → Items_Mat → prebuild_all**.

## Qué hace cada una

| Tarea | Script | Hora sugerida | Para qué |
|---|---|---|---|
| Refresco Items_Mat | `sql\refrescar_items_mat.bat` | **03:00** | Refresca la tabla materializada `Items_Mat` (dim producto). Base de `#refs`. **Pendiente desde 2026-07-03.** |
| Prebuild de caches | `sql\prebuild_all.bat` | **03:30** | Construye las caches en disco de o14/evol/o45 para todos los proveedores. Debe correr **después** de Items_Mat. |

> El **login-prewarm** (recalentar lo stale al iniciar sesión) NO es una tarea programada: se dispara solo desde `index.php`. No requiere configuración de Task Scheduler, solo que la ruta a `php.exe` en el spawn de `index.php` sea correcta (ver §Ajustes).

## Antes de montar — Ajustes en WMS-LAB

Verifica la ruta real de `php.exe` en WMS-LAB (puede no ser `C:\xampp\php\php.exe`):
```cmd
where php
```
Si difiere, ajusta en **3 lugares**:
1. `sql\refrescar_items_mat.bat` → línea `set PHP_EXE=...`
2. `sql\prebuild_all.bat` → línea `set PHP_EXE=...`
3. `index.php` → `$phpExe = 'C:\\xampp\\php\\php.exe';` (el spawn del login-prewarm). Si la ruta es incorrecta, el login-prewarm simplemente no corre (el login NO se rompe), pero conviene dejarlo bien.

Y asegura que la carpeta `cache\` sea **escribible** por la cuenta que corre las tareas.

## Comandos `schtasks` (ejecutar en un `cmd` **como Administrador** en WMS-LAB)

Ajusta `RUTA_APP` a la ruta real de la app en WMS-LAB (donde están `index.php`, `sql\`, etc.).

```cmd
set RUTA_APP=C:\ruta\a\plataforma_20

REM 1) Refresco de Items_Mat a las 03:00
schtasks /Create /TN "Plataforma20\Refresco Items_Mat" ^
  /TR "\"%RUTA_APP%\sql\refrescar_items_mat.bat\"" ^
  /SC DAILY /ST 03:00 /RL HIGHEST /RU SYSTEM /F

REM 2) Prebuild de caches a las 03:30 (despues de Items_Mat)
schtasks /Create /TN "Plataforma20\Prebuild caches" ^
  /TR "\"%RUTA_APP%\sql\prebuild_all.bat\"" ^
  /SC DAILY /ST 03:30 /RL HIGHEST /RU SYSTEM /F
```

Notas:
- `/RU SYSTEM` = corre como la cuenta del sistema (no necesita que haya sesión iniciada; equivale a marcar "Ejecutar aunque el usuario no haya iniciado sesión"). Si prefieres una cuenta de servicio con permisos a la RDS/carpeta, usa `/RU "DOMINIO\cuenta" /RP *` (pedirá la contraseña).
- `/RL HIGHEST` = privilegios más altos.
- `/F` = sobreescribe si la tarea ya existe (para re-crear).
- Si Items_Mat tarda mucho, deja más margen entre 03:00 y 03:30 (o encadénalas: que `prebuild_all.bat` sea llamado al final de la de Items_Mat).

## Verificar que corren

- Forzar una corrida ya (sin esperar a la noche):
  ```cmd
  schtasks /Run /TN "Plataforma20\Refresco Items_Mat"
  schtasks /Run /TN "Plataforma20\Prebuild caches"
  ```
- Revisar los logs (se crean junto a los `.bat`):
  - `sql\refrescar_items_mat.log`
  - `sql\prebuild_all.log` → debe terminar con `[prebuild_all] fin: OK=N FALLO=0`
  - `sql\prewarm_login.log` → se llena cuando un usuario inicia sesión (login-prewarm)
- Ver estado / última ejecución:
  ```cmd
  schtasks /Query /TN "Plataforma20\Prebuild caches" /V /FO LIST
  ```

## Prueba manual rápida (opcional, sin Task Scheduler)

```cmd
cd /d %RUTA_APP%
C:\xampp\php\php.exe sql\prebuild_all.php --dry-run      REM lista proveedores, no construye
C:\xampp\php\php.exe sql\prebuild_all.php "BELTRANY SAS" REM construye 1 proveedor (rapido)
```

## Recordatorio

Cada servidor PHP (local, staging, WMS-LAB) tiene su **propia** cache en disco → si quieres que WMS-LAB esté caliente, las tareas deben montarse **en WMS-LAB** (no basta con correrlas en local/staging).
