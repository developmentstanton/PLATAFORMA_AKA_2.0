@echo off
REM ============================================================================
REM Prebuild nocturno del cache en disco de O14 tab=c (arbol sin filtro).
REM Reutiliza conexion_integracion.php de la app (auth SQL contra RDS). Sin sqlcmd.
REM Programar en el Programador de tareas de Windows:
REM   - Disparador: diario, DESPUES del refresh de Items_Mat (p.ej. 03:30)
REM   - Accion: Iniciar programa -> este .bat
REM   - "Ejecutar con privilegios mas altos" + "aunque el usuario no haya iniciado sesion"
REM AJUSTA PHP_EXE si php.exe esta en otra ruta.
REM ============================================================================
setlocal
set PHP_EXE=C:\xampp\php\php.exe
"%PHP_EXE%" "%~dp0prebuild_o14c.php" >> "%~dp0prebuild_o14c.log" 2>&1
endlocal
