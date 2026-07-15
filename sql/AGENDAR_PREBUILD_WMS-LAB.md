# Agendar el prebuild nocturno de caches (o14c/evol/o45) en WMS-LAB

Deja las 3 caches en disco calientes tras el ETL nocturno, para que el primer usuario del día
obtenga hit de disco (no la reconstrucción lenta). Correr DESPUÉS de que termine el ETL/Items_Mat.

## Comando (PowerShell/CMD como administrador, en WMS-LAB)

Ajustar la hora (`/st`) para que quede DESPUÉS del ETL. Ajustar la ruta del .bat a la carpeta
real del proyecto en WMS-LAB si no es `C:\xampp\htdocs\plataforma_20`.

    schtasks /Create /TN "Plataforma20 Prebuild Caches" /TR "C:\xampp\htdocs\plataforma_20\sql\prebuild_all.bat" /SC DAILY /ST 05:30 /RU SYSTEM /RL HIGHEST /F

## Verificar

    schtasks /Query /TN "Plataforma20 Prebuild Caches" /V /FO LIST

## Probar a mano (sin esperar a la noche)

    schtasks /Run /TN "Plataforma20 Prebuild Caches"

Luego revisar que se escribieron los .gz recientes:

    dir C:\xampp\htdocs\plataforma_20\cache\evol_*.json.gz

## Notas
- `/RU SYSTEM` evita depender de una sesión de usuario logueada.
- La tarea corre `prebuild_all.php` para TODOS los proveedores (warmProveedor con onlyIfStale=false).
- El login-prewarm queda como red de seguridad si un proveedor no se alcanzó a precalentar.
