@echo off
REM ============================================================================
REM Refresco nocturno de INTEGRACION.dbo.Items_Mat (Sub-proyecto C - rendimiento)
REM ----------------------------------------------------------------------------
REM Alternativa al job de SQL Agent (no disponible con el login actual).
REM Programar en el Programador de tareas de Windows:
REM   - Disparador: diario, p.ej. 03:00
REM   - Accion: Iniciar programa -> este .bat
REM   - Marcar "Ejecutar con privilegios mas altos" y
REM     "Ejecutar aunque el usuario no haya iniciado sesion".
REM
REM AJUSTA la variable SERVIDOR abajo con tu servidor\instancia real.
REM   -E  = autenticacion de Windows (la cuenta que corre la tarea debe tener
REM         permisos de DDL en INTEGRACION, los mismos con que corriste el proc
REM         manualmente en SSMS).
REM   Para login SQL, reemplaza  -E  por:   -U tu_usuario -P tu_clave
REM ============================================================================
setlocal

set SERVIDOR=localhost

sqlcmd -S %SERVIDOR% -d INTEGRACION -E -b -Q "EXEC dbo.usp_Refresh_Items_Mat;"

if errorlevel 1 (
  echo [%date% %time%] ERROR al refrescar Items_Mat >> "%~dp0refrescar_items_mat.log"
) else (
  echo [%date% %time%] OK refresco Items_Mat >> "%~dp0refrescar_items_mat.log"
)

endlocal
