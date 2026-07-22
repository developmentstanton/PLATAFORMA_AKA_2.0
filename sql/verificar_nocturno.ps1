<#
  verificar_nocturno.ps1 - Verifica en UNA pasada que el trabajo nocturno corrio bien.
  Revisa: (1) logs, (2) estado de las tareas programadas, (3) frescura de las caches .gz.

  Uso (en WMS-LAB, PowerShell):
      powershell -ExecutionPolicy Bypass -File E:\wms\www\plataforma_20\sql\verificar_nocturno.ps1

  OJO: por defecto apunta a plataforma_20 (la v2.0). NO a \plataforma (v1.0 en produccion).

  Parametros opcionales:
      -App "E:\wms\www\plataforma_20"   ruta de la app v2.0 (por defecto)
      -MaxAgeHours 20                 antiguedad maxima aceptable de logs/caches

  NOTA: archivo en ASCII puro a proposito (sin tildes ni guion largo) para que
  el Windows PowerShell del servidor no falle por codificacion.
#>
param(
  [string]$App = "E:\wms\www\plataforma_20",
  [int]$MaxAgeHours = 20
)

$ErrorActionPreference = "SilentlyContinue"
$now = Get-Date
$fallos = 0

function Line($n=70){ Write-Host ("-" * $n) -ForegroundColor DarkGray }
function OK($m){ Write-Host "  [OK]   $m" -ForegroundColor Green }
function BAD($m){ Write-Host "  [FALLA] $m" -ForegroundColor Red; $script:fallos++ }
function INFO($m){ Write-Host "  $m" -ForegroundColor Gray }

Write-Host ""
Write-Host "VERIFICACION DEL TRABAJO NOCTURNO - $($now.ToString('yyyy-MM-dd HH:mm'))" -ForegroundColor Cyan
Write-Host "App: $App    (fresco = ultimas $MaxAgeHours h)" -ForegroundColor Cyan

# ------------------------------------------------------------------
# 1) LOGS
# ------------------------------------------------------------------
Line
Write-Host "1) LOGS" -ForegroundColor White

# --- prebuild_all.log ---
$preLog = Join-Path $App "sql\prebuild_all.log"
if (-not (Test-Path $preLog)) {
  BAD "No existe prebuild_all.log (la tarea de prebuild nunca escribio)."
} else {
  $edadPre = ($now - (Get-Item $preLog).LastWriteTime).TotalHours
  $edadPreR = [math]::Round($edadPre,1)
  $ultima = (Get-Content $preLog | Where-Object { $_.Trim() -ne "" } | Select-Object -Last 1)
  INFO "prebuild_all.log  (modificado hace $edadPreR h)"
  INFO "  ultima linea: $ultima"
  if ($ultima -match 'fin:\s*OK=(\d+)\s*FALLO=(\d+)') {
    $okN = [int]$Matches[1]; $failN = [int]$Matches[2]
    if ($failN -eq 0) { OK "prebuild termino con FALLO=0 (OK=$okN proveedores)" }
    else { BAD "prebuild termino con FALLO=$failN (OK=$okN). Revisa las lineas 'FALLO' del log" }
  } elseif ($ultima -match 'ERROR: no se encontro php.exe') {
    BAD "prebuild no encontro php.exe. Pon 'set PHP_EXE=E:\WMS\PHP\8.2\php.exe' en prebuild_all.bat"
  } else {
    BAD "prebuild_all.log no termina en 'fin: OK=.. FALLO=0' (corrio a medias o sigue corriendo)"
  }
  if ($edadPre -gt $MaxAgeHours) { BAD "prebuild_all.log esta viejo ($edadPreR h). Quiza no corrio anoche" }
}

# --- refrescar_items_mat.log ---
$imLog = Join-Path $App "sql\refrescar_items_mat.log"
if (-not (Test-Path $imLog)) {
  BAD "No existe refrescar_items_mat.log (la tarea de Items_Mat nunca escribio)."
} else {
  $edadIm = ($now - (Get-Item $imLog).LastWriteTime).TotalHours
  $edadImR = [math]::Round($edadIm,1)
  $errs = Select-String -Path $imLog -Pattern "ERROR","Fatal","Exception" -SimpleMatch
  INFO "refrescar_items_mat.log  (modificado hace $edadImR h)"
  if ($errs) { BAD "refrescar_items_mat.log tiene $($errs.Count) linea(s) de ERROR. Revisalas" }
  else { OK "refrescar_items_mat.log sin errores" }
  if ($edadIm -gt $MaxAgeHours) { BAD "refrescar_items_mat.log esta viejo ($edadImR h). Quiza no corrio anoche" }
}

# ------------------------------------------------------------------
# 2) TAREAS PROGRAMADAS
# ------------------------------------------------------------------
Line
Write-Host "2) TAREAS PROGRAMADAS" -ForegroundColor White

$tareas = @(
  @{ Nombre = "Refresco Items_Mat"; Path = "\Plataforma20\" },
  @{ Nombre = "Prebuild caches";    Path = "\Plataforma20\" }
)
foreach ($t in $tareas) {
  $info = Get-ScheduledTaskInfo -TaskName $t.Nombre -TaskPath $t.Path -ErrorAction SilentlyContinue
  if (-not $info) {
    BAD "Tarea '$($t.Nombre)' no existe (se creo con schtasks?)"
    continue
  }
  $lr = $info.LastRunTime
  $res = $info.LastTaskResult
  $edadRun = if ($lr) { [math]::Round(($now - $lr).TotalHours,1) } else { "?" }
  INFO "$($t.Nombre): ultima ejecucion = $lr (hace $edadRun h), codigo = $res"
  switch ($res) {
    0        { OK "$($t.Nombre): ultimo resultado 0 (exito)" }
    267009   { INFO "  ($($t.Nombre) esta CORRIENDO ahora mismo)" }
    267011   { BAD "$($t.Nombre): aun no se ha ejecutado nunca" }
    default  { BAD "$($t.Nombre): ultimo resultado = $res (distinto de 0 = fallo)" }
  }
  if ($lr -and (($now - $lr).TotalHours -gt $MaxAgeHours)) {
    BAD "$($t.Nombre): no corre desde hace $edadRun h (se apago la tarea?)"
  }
}

# ------------------------------------------------------------------
# 3) CACHES .gz FRESCAS
# ------------------------------------------------------------------
Line
Write-Host "3) CACHES EN DISCO (.gz)" -ForegroundColor White

$cacheDir = Join-Path $App "cache"
if (-not (Test-Path $cacheDir)) {
  BAD "No existe la carpeta cache\"
} else {
  $gz = Get-ChildItem (Join-Path $cacheDir "*.json.gz") -ErrorAction SilentlyContinue
  if (-not $gz -or $gz.Count -eq 0) {
    BAD "No hay archivos .gz en cache\ (el prebuild no escribio nada)"
  } else {
    $frescos = $gz | Where-Object { ($now - $_.LastWriteTime).TotalHours -le $MaxAgeHours }
    $viejos  = $gz | Where-Object { ($now - $_.LastWriteTime).TotalHours -gt $MaxAgeHours }
    INFO "Total .gz: $($gz.Count)   frescos: $($frescos.Count)   viejos: $($viejos.Count)"
    foreach ($f in ($gz | Sort-Object LastWriteTime -Descending | Select-Object -First 12)) {
      $h = [math]::Round(($now - $f.LastWriteTime).TotalHours,1)
      $tag = if ($h -le $MaxAgeHours) { "fresco" } else { "VIEJO " }
      INFO ("  [{0}] {1,-42} {2}  (hace {3} h)" -f $tag, $f.Name, $f.LastWriteTime.ToString('MM-dd HH:mm'), $h)
    }
    if ($frescos.Count -eq 0) { BAD "Ningun .gz es fresco. El prebuild de anoche no escribio caches" }
    else { OK "$($frescos.Count) cache(s) .gz frescas (< $MaxAgeHours h)" }
  }
}

# ------------------------------------------------------------------
# VEREDICTO
# ------------------------------------------------------------------
Line
if ($fallos -eq 0) {
  Write-Host "VEREDICTO: TODO OK - el trabajo nocturno corrio bien." -ForegroundColor Green
  exit 0
} else {
  Write-Host "VEREDICTO: $fallos problema(s) detectado(s). Revisa las lineas [FALLA] de arriba." -ForegroundColor Red
  exit 1
}
