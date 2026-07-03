<?php
/**
 * Refresco nocturno de INTEGRACION.dbo.Items_Mat (Sub-proyecto C - rendimiento).
 * Reutiliza la conexión de la app (conexion_integracion.php) — sin duplicar credenciales.
 * Uso (Task Scheduler): php.exe refrescar_items_mat.php
 * Escribe una línea de resultado en refrescar_items_mat.log (junto a este archivo).
 */
require __DIR__ . '/../conexion/conexion_integracion.php';

$log = __DIR__ . '/refrescar_items_mat.log';
$ts  = date('Y-m-d H:i:s');

if ($dbConnect === false) {
    @file_put_contents($log, "[$ts] ERROR: conexión DB fallida\n", FILE_APPEND);
    fwrite(STDERR, "conexión DB fallida\n");
    exit(1);
}

// sp_rename emite un aviso informativo ("Caution: Changing any part of an object name...").
// Por defecto sqlsrv trata los warnings como errores; lo desactivamos para no dar falso error.
sqlsrv_configure('WarningsReturnAsErrors', 0);

$t0   = microtime(true);
$stmt = sqlsrv_query($dbConnect, "EXEC dbo.usp_Refresh_Items_Mat");
$ms   = round((microtime(true) - $t0) * 1000);

if ($stmt === false) {
    $err = print_r(sqlsrv_errors(), true);
    @file_put_contents($log, "[$ts] ERROR ({$ms}ms): $err\n", FILE_APPEND);
    fwrite(STDERR, "error en refresh: $err\n");
    exit(1);
}
// Consumir cualquier resultado/mensaje del proc antes de cerrar.
while (sqlsrv_next_result($stmt)) { /* no-op */ }
sqlsrv_free_stmt($stmt);

@file_put_contents($log, "[$ts] OK refresco Items_Mat ({$ms}ms)\n", FILE_APPEND);
echo "OK ({$ms}ms)\n";
exit(0);
