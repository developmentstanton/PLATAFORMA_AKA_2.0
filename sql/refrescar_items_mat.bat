@echo off
REM ============================================================================
REM Refresco nocturno de INTEGRACION.dbo.Items_Mat (Sub-proyecto C - rendimiento)
REM ----------------------------------------------------------------------------
REM Llama al script PHP (que reutiliza conexion_integracion.php de la app, con
REM autenticacion SQL contra RDS). NO duplica credenciales ni depende de sqlcmd.
REM
REM Programar en el Programador de tareas de Windows:
REM   - Disparador: diario, p.ej. 03:00
REM   - Accion: Iniciar programa -> este .bat
REM   - Marcar "Ejecutar con privilegios mas altos" y
REM     "Ejecutar aunque el usuario no haya iniciado sesion".
REM
REM AJUSTA PHP_EXE si en el servidor php.exe esta en otra ruta.
REM ============================================================================
setlocal

set PHP_EXE=C:\xampp\php\php.exe

"%PHP_EXE%" "%~dp0refrescar_items_mat.php"

endlocal
