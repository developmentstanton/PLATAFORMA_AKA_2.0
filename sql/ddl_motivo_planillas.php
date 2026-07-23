<?php
/**
 * Agrega consecutivo_planillas_aka.motivo — el motivo por el cual se rechaza una planilla.
 *
 * IDEMPOTENTE: comprueba con COL_LENGTH antes de tocar nada, así que se puede correr dos
 * veces sin fallar. La RDS es la MISMA para dev, staging y producción, de modo que aplicarlo
 * una vez sirve para los tres entornos.
 *
 *   php sql/ddl_motivo_planillas.php
 */
require __DIR__ . '/../conexion/conexion_integracion.php';

if ($dbConnect === false) {
    fwrite(STDERR, "No hay conexion a INTEGRACION.\n");
    exit(1);
}

$chk = sqlsrv_query($dbConnect, "SELECT COL_LENGTH('dbo.consecutivo_planillas_aka','motivo') AS l");
if ($chk === false) {
    fwrite(STDERR, "No se pudo consultar el esquema.\n");
    exit(1);
}
$row = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($chk);

if ($row !== null && $row['l'] !== null) {
    echo "OK: la columna 'motivo' ya existe (VARCHAR({$row['l']})). Nada que hacer.\n";
    exit(0);
}

$alter = sqlsrv_query(
    $dbConnect,
    "ALTER TABLE INTEGRACION.dbo.consecutivo_planillas_aka ADD motivo VARCHAR(200) NULL"
);
if ($alter === false) {
    fwrite(STDERR, "ALTER TABLE fallo.\n");
    exit(1);
}
sqlsrv_free_stmt($alter);

$ver = sqlsrv_query($dbConnect, "SELECT COL_LENGTH('dbo.consecutivo_planillas_aka','motivo') AS l");
$rowVer = $ver ? sqlsrv_fetch_array($ver, SQLSRV_FETCH_ASSOC) : null;
if ($rowVer === null || $rowVer['l'] === null) {
    fwrite(STDERR, "El ALTER no fallo pero la columna no aparece.\n");
    exit(1);
}

echo "OK: columna 'motivo' creada, VARCHAR({$rowVer['l']}) NULL.\n";
exit(0);
