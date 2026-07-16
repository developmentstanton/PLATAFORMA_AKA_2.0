@echo off
REM Prebuild nocturno de las 3 caches en disco (o14c/evol/o45). Correr DESPUES del refresh de Items_Mat.
REM Programar diario con "privilegios mas altos" + "aunque el usuario no haya iniciado sesion".
setlocal
REM Autodeteccion de php.exe: prod (E:\WMS\PHP), XAMPP dev/staging (C:\xampp), o PATH.
set "PHP_EXE="
if exist "E:\WMS\PHP\8.2\php.exe" set "PHP_EXE=E:\WMS\PHP\8.2\php.exe"
if not defined PHP_EXE if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
if not defined PHP_EXE for %%p in (php.exe) do set "PHP_EXE=%%~$PATH:p"
REM OJO: NADA de parentesis dentro de este bloque, ni siquiera dentro de un echo. Un ")" sin
REM escapar cierra el if antes de tiempo y deja el "exit /b 1" FUERA: el .bat se muere siempre,
REM con errorlevel 1 y sin alcanzar a escribir el log. Paso 6 dias asi en WMS-LAB (2026-07-16):
REM la version desplegada tenia "(revisar rutas en el .bat)" sin escapar. No confiamos en los ^:
REM se quitaron los parentesis para que no haya nada que escapar. Ver tests/bat_sintaxis_test.php.
if not defined PHP_EXE (
  echo [prebuild_all] ERROR: no se encontro php.exe - revisar las rutas en este .bat >> "%~dp0prebuild_all.log"
  exit /b 1
)
"%PHP_EXE%" "%~dp0prebuild_all.php" >> "%~dp0prebuild_all.log" 2>&1
endlocal
