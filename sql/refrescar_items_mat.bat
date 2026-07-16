@echo off
REM ============================================================================
REM Refresco nocturno de INTEGRACION.dbo.Items_Mat (Sub-proyecto C - rendimiento)
REM ----------------------------------------------------------------------------
REM Llama al script PHP (que reutiliza conexion_integracion.php de la app, con
REM autenticacion SQL contra RDS). NO duplica credenciales ni depende de sqlcmd.
REM
REM Programar en el Programador de tareas de Windows:
REM   - Disparador: diario 03:00 (ANTES de prebuild_all, que corre a las 04:00)
REM   - Accion: Iniciar programa -> este .bat
REM   - Marcar "Ejecutar con privilegios mas altos" y
REM     "Ejecutar aunque el usuario no haya iniciado sesion".
REM
REM 2026-07-16: php.exe ya NO va quemado. Decia C:\xampp\php\php.exe, que en
REM WMS-LAB no existe (php vive en E:\WMS\PHP\8.2) -> habria fallado, y como
REM tampoco redirigia a un log, habria fallado en SILENCIO. Es el mismo bug de
REM la ruta quemada que tenian index.php y prebuild_all.bat.
REM
REM OJO: NADA de parentesis dentro del bloque if de abajo, ni siquiera dentro de
REM un echo. Un ")" sin escapar cierra el if antes de tiempo y deja el
REM "exit /b 1" FUERA del bloque: el .bat se muere SIEMPRE, en silencio y con
REM errorlevel 1. Eso tuvo el prebuild nocturno 6 dias caido sin dejar rastro.
REM Este archivo DEBE guardarse con saltos de linea CRLF (ver .gitattributes).
REM Verificado por tests/bat_sintaxis_test.php.
REM ============================================================================
setlocal
REM Autodeteccion de php.exe: prod (E:\WMS\PHP), XAMPP dev/staging (C:\xampp), o PATH.
set "PHP_EXE="
if exist "E:\WMS\PHP\8.2\php.exe" set "PHP_EXE=E:\WMS\PHP\8.2\php.exe"
if not defined PHP_EXE if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
if not defined PHP_EXE for %%p in (php.exe) do set "PHP_EXE=%%~$PATH:p"
if not defined PHP_EXE (
  echo [refrescar_items_mat] ERROR: no se encontro php.exe - revisar las rutas en este .bat >> "%~dp0refrescar_items_mat.log"
  exit /b 1
)
"%PHP_EXE%" "%~dp0refrescar_items_mat.php" >> "%~dp0refrescar_items_mat.log" 2>&1
endlocal
