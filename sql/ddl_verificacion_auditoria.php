<?php
/**
 * Aplica sql/008_verificacion_auditoria.sql.
 *
 * Existe porque sqlsrv no acepta lotes separados por GO: hay que partir el archivo y
 * mandar cada lote por su cuenta. El DDL en si es IDEMPOTENTE (los IF OBJECT_ID), asi que
 * correr esto dos veces no falla. La RDS es la MISMA para dev, staging y produccion.
 *
 *   php sql/ddl_verificacion_auditoria.php
 */
require __DIR__ . '/../conexion/conexion_integracion.php';

if ($dbConnect === false) {
    fwrite(STDERR, "No hay conexion a INTEGRACION.\n");
    exit(1);
}

$sql = file_get_contents(__DIR__ . '/008_verificacion_auditoria.sql');
if ($sql === false) {
    fwrite(STDERR, "No se pudo leer 008_verificacion_auditoria.sql\n");
    exit(1);
}

// Parte por lineas que sean solo GO (el separador de lotes de SSMS, no una palabra de T-SQL).
$lotes = preg_split('/^\s*GO\s*$/mi', $sql);

$n = 0;
foreach ($lotes as $lote) {
    $lote = trim($lote);
    if ($lote === '') continue;
    $stmt = sqlsrv_query($dbConnect, $lote);
    if ($stmt === false) {
        fwrite(STDERR, "Lote fallo:\n" . substr($lote, 0, 120) . "...\n");
        fwrite(STDERR, print_r(sqlsrv_errors(), true));
        exit(1);
    }
    sqlsrv_free_stmt($stmt);
    $n++;
}

echo "Lotes aplicados: $n\n";

// Verificacion: las dos tablas y el indice tienen que existir.
$ver = sqlsrv_query($dbConnect,
    "SELECT name FROM sys.tables WHERE name LIKE 'verificacion%' ORDER BY name");
$tablas = [];
while ($r = sqlsrv_fetch_array($ver, SQLSRV_FETCH_ASSOC)) $tablas[] = $r['name'];

$esperadas = ['verificacion_auditoria', 'verificacion_auditoria_detalle'];
if ($tablas !== $esperadas) {
    fwrite(STDERR, "Las tablas no quedaron como se esperaba: " . implode(', ', $tablas) . "\n");
    exit(1);
}

foreach ($tablas as $t) echo "  $t\n";
echo "OK\n";
exit(0);
