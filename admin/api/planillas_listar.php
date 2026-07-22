<?php
/**
 * GET → todas las planillas en JSON. Solo lectura, no muta nada: no exige CSRF.
 * El filtrado por estado lo hace DataTables en el navegador.
 */
require_once __DIR__ . '/../lib_admin_auth.php';
require_once __DIR__ . '/../lib_planillas.php';

header('Content-Type: application/json; charset=utf-8');
admin_exigir_sesion_json();

require __DIR__ . '/../../conexion/conexion_integracion.php';
// Reintento igual que en api/codificacion_solicitudes.php: el enlace con la RDS es lento y
// a veces la primera conexión falla. $servidor e $infoconn vienen del archivo de conexión.
for ($i = 0; $dbConnect === false && $i < 4; $i++) {
    usleep(300000);
    $dbConnect = sqlsrv_connect($servidor, $infoconn);
}
if ($dbConnect === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos.']);
    exit;
}

try {
    $filas = planillas_listar($dbConnect);
} catch (Throwable $e) {
    error_log('admin planillas listar: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudieron cargar las planillas.']);
    exit;
}

echo json_encode(['ok' => true, 'filas' => $filas]);
