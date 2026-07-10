@echo off
REM Prebuild nocturno de las 3 caches en disco (o14c/evol/o45). Correr DESPUES del refresh de Items_Mat.
REM Programar diario (p.ej. 03:30) con "privilegios mas altos" + "aunque el usuario no haya iniciado sesion".
setlocal
set PHP_EXE=C:\xampp\php\php.exe
"%PHP_EXE%" "%~dp0prebuild_all.php" >> "%~dp0prebuild_all.log" 2>&1
endlocal
