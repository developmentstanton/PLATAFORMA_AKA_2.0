<?php
/**
 * API Informe Análisis de Pagos — Task 1: endpoint base con filas unificadas por NIT.
 * Devuelve {ok, nit, razon_social, filas:[...]} filtrado por $_SESSION['nit'].
 * Cada fila: fecha_venc, dias, documento, causado, moneda, valor, en_pesos,
 *            fecha_pago, anio_pago, mes_pago, base.
 * Fuentes: Doc_Compra_PBI (docs), FLUJO_OC_PBI (flujo), Anticipos_PBI (antic).
 * Task 2 añadirá TRM; por ahora en_pesos = valor.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}
$nit = trim($_SESSION['nit'] ?? '');
if ($nit === '') {
    // Proveedor sin NIT en el maestro (p.ej. resuelto por fallback a ITEMS, como INTERTENIS):
    // no figura en cuentas por pagar → no hay datos que mostrar. Estado vacío, NO error.
    echo json_encode(['ok' => true, 'sin_nit' => true, 'razon_social' => trim($_SESSION['proveedor'] ?? ''), 'filas' => [], 'meses' => []]);
    exit;
}

// --- Filtros opcionales (aplicados en PHP, post-query) ---
$causado = strtoupper(trim($_GET['causado'] ?? 'TODAS'));
$diasMin = isset($_GET['dias_min']) && $_GET['dias_min']!=='' ? (int)$_GET['dias_min'] : null;
$diasMax = isset($_GET['dias_max']) && $_GET['dias_max']!=='' ? (int)$_GET['dias_max'] : null;
$fdesde  = trim($_GET['fdesde'] ?? '');
$fhasta  = trim($_GET['fhasta'] ?? '');

require __DIR__ . '/../conexion/conexion_integracion.php';
// El RDS rechaza intermitentemente conexiones rápidas; reintentar unas veces evita
// caídas espurias ("Conexión DB fallida") sin afectar el caso normal (1 sola conexión).
for ($intento = 0; $dbConnect === false && $intento < 4; $intento++) {
    usleep(300000);
    $dbConnect = sqlsrv_connect($servidor, $infoconn);
}
if ($dbConnect === false) {
    echo json_encode(['ok' => false, 'error' => 'Conexión DB fallida']);
    exit;
}
require __DIR__ . '/lib_trm.php';
$trm = obtener_trm();

// --- SQL unificado: 3 sub-CTEs filtradas por NIT, normalizadas a esquema común, UNION ALL ---
// Parámetros: 1 NIT por sub-CTE (docs=?, flujo=?, antic=?), en orden de aparición.
$sql = "
SET NOCOUNT ON;
;WITH Base AS (
    SELECT
        RTRIM(f200_id) AS NIT,
        RTRIM(f202_descripcion_sucursal) AS Razon_Social,
        RTRIM(f208_id) AS ID,
        RTRIM(f208_descripcion) AS DESCRIPCION_PAGO,
        f208_dias_vcto AS DIAS
    FROM stanton.dbo.t208_mm_condiciones_pago WITH (NOLOCK)
    LEFT JOIN stanton.dbo.t202_mm_proveedores WITH (NOLOCK) ON f202_id_cond_pago = f208_id AND f202_id_cia = f208_id_cia
    LEFT JOIN stanton.dbo.t200_mm_terceros WITH (NOLOCK) ON f200_rowid = f202_rowid_tercero
    WHERE f208_id_cia = '1' AND f200_id IS NOT NULL
), BaseFinal AS (
    SELECT
        RTRIM(C.CIA) AS CIA,
        RTRIM(C.Nro_Docto) AS DOCUMENTO,
        RTRIM(C.nit) AS NIT,
        RTRIM(C.Proveedor) AS PROVEEDOR,
        'SEMANA' AS CLASE_PROVEEDOR,
        RTRIM(C.Tipo_Proveedor) AS TIPO_PROVEEDOR,
        'NO' AS CAUSADO,
        RTRIM(C.Moneda) AS MONEDA,
        C.Valor_Neto AS VALOR,
        CASE
            WHEN C.Fecha_Entrega IS NULL THEN CONVERT(DATE, GETDATE())
            ELSE CONVERT(DATE, C.Fecha_Entrega)
        END AS Fecha_Entrega,
        CASE
            WHEN B.DIAS IS NULL THEN 0
            ELSE B.DIAS
        END AS DIAS
    FROM Integracion.dbo.Doc_Compra_PBI AS C WITH (NOLOCK)
    LEFT JOIN Base AS B ON B.NIT = C.nit
    WHERE C.Consignacion = 'No' AND C.Estado = 'Contabilizado' AND C.CIA = 'Stanton S.A.S.'
      AND RTRIM(C.nit) = ?
), FechasCalculadas AS (
    SELECT
        *,
        DATEADD(DAY, DIAS, Fecha_Entrega) AS FECHA_VENCIMIENTO_REAL
    FROM BaseFinal
), FechaDePago AS (
    SELECT
        *,
        DATEADD(day,
            CASE DATENAME(dw, FECHA_VENCIMIENTO_REAL)
                WHEN 'Monday'    THEN 4
                WHEN 'Tuesday'   THEN 3
                WHEN 'Wednesday' THEN 2
                WHEN 'Thursday'  THEN 1
                WHEN 'Friday'    THEN 0
                WHEN 'Saturday'  THEN 6
                WHEN 'Sunday'    THEN 5
            END,
            FECHA_VENCIMIENTO_REAL) AS FECHA_PAGO_CALCULADA
    FROM FechasCalculadas
), docs AS (
    SELECT
        CIA,
        CONVERT(date, FECHA_VENCIMIENTO_REAL) AS FECHA_VENCIMIENTO,
        DOCUMENTO,
        NIT,
        PROVEEDOR,
        CLASE_PROVEEDOR,
        TIPO_PROVEEDOR,
        CAUSADO,
        'ENTRADA POR CAUSAR' AS DESCRIPCION,
        MONEDA,
        VALOR,
        CONVERT(date,
            CASE
                WHEN FECHA_PAGO_CALCULADA < GETDATE() THEN
                    DATEADD(day,
                        CASE DATENAME(dw, GETDATE())
                            WHEN 'Monday'    THEN 4
                            WHEN 'Tuesday'   THEN 3
                            WHEN 'Wednesday' THEN 2
                            WHEN 'Thursday'  THEN 1
                            WHEN 'Friday'    THEN 0
                            WHEN 'Saturday'  THEN 6
                            WHEN 'Sunday'    THEN 5
                        END,
                        GETDATE())
                ELSE FECHA_PAGO_CALCULADA
            END
        ) AS FECHA_PAGO
    FROM FechaDePago
), flujo AS (
    SELECT
        CIA,
        CONVERT(date, FECHA) AS FECHA_VENCIMIENTO,
        DOCUMENTO,
        NIT,
        PROVEEDOR,
        CLASE_PROVEEDOR,
        TIPO_PROVEEDOR,
        CAUSADO,
        DESCRIPCION,
        MONEDA,
        VALOR,
        CONVERT(date,
            CASE
                WHEN FECHA_PAGO_CALCULADA < GETDATE() THEN
                    DATEADD(day,
                        CASE DATENAME(dw, GETDATE())
                            WHEN 'Monday'    THEN 4
                            WHEN 'Tuesday'   THEN 3
                            WHEN 'Wednesday' THEN 2
                            WHEN 'Thursday'  THEN 1
                            WHEN 'Friday'    THEN 0
                            WHEN 'Saturday'  THEN 6
                            WHEN 'Sunday'    THEN 5
                        END,
                        GETDATE())
                ELSE FECHA_PAGO_CALCULADA
            END
        ) AS FECHA_PAGO
    FROM (
        SELECT
            CIA2.CIA, CIA2.FECHA, CIA2.DOCUMENTO, CIA2.NIT, CIA2.PROVEEDOR,
            CIA2.CLASE_PROVEEDOR, CIA2.TIPO_PROVEEDOR, CIA2.CAUSADO, CIA2.DESCRIPCION,
            CIA2.MONEDA, CIA2.VALOR,
            CASE
                WHEN CIA2.CLASE_PROVEEDOR = 'QUINCENA' AND DATENAME(dw, CIA2.FECHA_CORTE_REAL) IN ('Thursday', 'Friday') THEN
                    CASE
                        WHEN (DATENAME(dw, CIA2.FECHA_CORTE_REAL) = 'Friday' AND DAY(CIA2.FECHA_CORTE_REAL) = 15)
                          OR (DATENAME(dw, CIA2.FECHA_CORTE_REAL) = 'Thursday' AND DAY(CIA2.FECHA_CORTE_REAL) = 1)
                        THEN DATEADD(day, 7, CIA2.FECHA_CORTE_REAL)
                        WHEN (DATENAME(dw, CIA2.FECHA_CORTE_REAL) = 'Friday' AND DAY(CIA2.FECHA_CORTE_REAL) = 1)
                        THEN DATEADD(day, 6, CIA2.FECHA_CORTE_REAL)
                        ELSE DATEADD(day, 8, CIA2.FECHA_CORTE_REAL)
                    END
                ELSE
                    DATEADD(day,
                        CASE DATENAME(dw, CIA2.FECHA_CORTE_REAL)
                            WHEN 'Monday'    THEN 4
                            WHEN 'Tuesday'   THEN 3
                            WHEN 'Wednesday' THEN 2
                            WHEN 'Thursday'  THEN 1
                            WHEN 'Friday'    THEN 0
                            WHEN 'Saturday'  THEN 6
                            WHEN 'Sunday'    THEN 5
                        END,
                        CIA2.FECHA_CORTE_REAL)
            END AS FECHA_PAGO_CALCULADA
        FROM (
            SELECT
                RTRIM(CIA) AS CIA,
                DIA AS FECHA,
                RTRIM(DOCUMENTO) AS DOCUMENTO,
                RTRIM(NIT) AS NIT,
                RTRIM(PROVEEDOR) AS PROVEEDOR,
                CASE
                    WHEN CLASE_PROVEEDOR = '' THEN 'SEMANA'
                    WHEN CLASE_PROVEEDOR LIKE 'PROV.%' THEN 'SEMANA'
                    ELSE RTRIM(CLASE_PROVEEDOR)
                END AS CLASE_PROVEEDOR,
                RTRIM(TIPO_PROVEEDOR) AS TIPO_PROVEEDOR,
                RTRIM(CAUSADO) AS CAUSADO,
                RTRIM(ITEM) AS DESCRIPCION,
                RTRIM(MONEDA) AS MONEDA,
                VALOR,
                CASE
                    WHEN (CASE WHEN CLASE_PROVEEDOR = '' THEN 'SEMANA'
                               WHEN CLASE_PROVEEDOR LIKE 'PROV.%' THEN 'SEMANA'
                               ELSE RTRIM(CLASE_PROVEEDOR) END) = 'QUINCENA' AND DAY(DIA) <= 15
                    THEN CONVERT(DATE, CONVERT(VARCHAR(10), DATEFROMPARTS(YEAR(DIA), MONTH(DIA), 15)))
                    WHEN (CASE WHEN CLASE_PROVEEDOR = '' THEN 'SEMANA'
                               WHEN CLASE_PROVEEDOR LIKE 'PROV.%' THEN 'SEMANA'
                               ELSE RTRIM(CLASE_PROVEEDOR) END) IN ('PUNTUAL', 'SEMANA')
                    THEN DATEADD(DAY,
                            CASE DATENAME(dw, DIA)
                                WHEN 'Monday'    THEN 4
                                WHEN 'Tuesday'   THEN 3
                                WHEN 'Wednesday' THEN 2
                                WHEN 'Thursday'  THEN 1
                                WHEN 'Friday'    THEN 0
                                WHEN 'Saturday'  THEN 6
                                WHEN 'Sunday'    THEN 5
                            END, DIA)
                    ELSE
                        DATEFROMPARTS(
                            CASE WHEN MONTH(DIA) = 12 THEN YEAR(DIA) + 1 ELSE YEAR(DIA) END,
                            CASE
                                WHEN MONTH(DIA) = 1  THEN 2  WHEN MONTH(DIA) = 2  THEN 3
                                WHEN MONTH(DIA) = 3  THEN 4  WHEN MONTH(DIA) = 4  THEN 5
                                WHEN MONTH(DIA) = 5  THEN 6  WHEN MONTH(DIA) = 6  THEN 7
                                WHEN MONTH(DIA) = 7  THEN 8  WHEN MONTH(DIA) = 8  THEN 9
                                WHEN MONTH(DIA) = 9  THEN 10 WHEN MONTH(DIA) = 10 THEN 11
                                WHEN MONTH(DIA) = 11 THEN 12 WHEN MONTH(DIA) = 12 THEN 1
                            END,
                            1
                        )
                END AS FECHA_CORTE_REAL
            FROM INTEGRACION.DBO.FLUJO_OC_PBI WITH (NOLOCK)
            WHERE RTRIM(NIT) = ?
        ) CIA2
    ) CIA3
), antic AS (
    SELECT
        RTRIM(CIA) AS CIA,
        CONVERT(date, FECHA) AS FECHA_VENCIMIENTO,
        RTRIM(DOCTO) AS DOCUMENTO,
        RTRIM(NIT) AS NIT,
        RTRIM(RAZON_SOCIAL) AS PROVEEDOR,
        'PUNTUAL' AS CLASE_PROVEEDOR,
        RTRIM(TIPO_PROVEEDOR) AS TIPO_PROVEEDOR,
        'SI' AS CAUSADO,
        'ANTICIPO' AS DESCRIPCION,
        'COP' AS MONEDA,
        ANTICIPO AS VALOR,
        /* próximo viernes desde hoy (mismo mapeo Lun=4..Vie=0,Sáb=6,Dom=5) */
        CONVERT(date, DATEADD(day,
            CASE DATENAME(dw, CONVERT(date, GETDATE()))
                WHEN 'Monday'    THEN 4
                WHEN 'Tuesday'   THEN 3
                WHEN 'Wednesday' THEN 2
                WHEN 'Thursday'  THEN 1
                WHEN 'Friday'    THEN 0
                WHEN 'Saturday'  THEN 6
                WHEN 'Sunday'    THEN 5
            END,
            CONVERT(date, GETDATE())
        )) AS FECHA_PAGO
    FROM Integracion.dbo.Anticipos_PBI WITH (NOLOCK)
    WHERE RTRIM(NIT) = ?
), u AS (
    SELECT CIA, FECHA_VENCIMIENTO, DOCUMENTO, NIT, PROVEEDOR, CLASE_PROVEEDOR, TIPO_PROVEEDOR,
           CAUSADO, DESCRIPCION, MONEDA, VALOR, FECHA_PAGO, 'Documentos Compra' AS Base FROM docs
    UNION ALL
    SELECT CIA, FECHA_VENCIMIENTO, DOCUMENTO, NIT, PROVEEDOR, CLASE_PROVEEDOR, TIPO_PROVEEDOR,
           CAUSADO, DESCRIPCION, MONEDA, VALOR, FECHA_PAGO, 'Flujo Proyectado' AS Base FROM flujo
    UNION ALL
    SELECT CIA, FECHA_VENCIMIENTO, DOCUMENTO, NIT, PROVEEDOR, CLASE_PROVEEDOR, TIPO_PROVEEDOR,
           CAUSADO, DESCRIPCION, MONEDA, VALOR, FECHA_PAGO, 'Anticipos' AS Base FROM antic
)
SELECT
    CONVERT(varchar(10), FECHA_VENCIMIENTO, 23) AS fecha_venc,
    DATEDIFF(day, FECHA_VENCIMIENTO, CONVERT(date, GETDATE())) AS dias,
    DOCUMENTO AS documento,
    NIT AS nit,
    PROVEEDOR AS razon_social,
    CAUSADO AS causado,
    MONEDA AS moneda,
    VALOR AS valor,
    YEAR(FECHA_PAGO) AS anio_pago,
    MONTH(FECHA_PAGO) AS mes_pago,
    CONVERT(varchar(10), FECHA_PAGO, 23) AS fecha_pago,
    Base AS base
FROM u
ORDER BY FECHA_VENCIMIENTO ASC;
";

// Parámetros en orden de aparición de ? en el SQL:
// 1. docs CTE (BaseFinal WHERE): RTRIM(C.nit) = ?
// 2. flujo CTE (CalculosIntermedios WHERE): RTRIM(NIT) = ?
// 3. antic CTE (WHERE): RTRIM(NIT) = ?
$params = [$nit, $nit, $nit];

$stmt = sqlsrv_query($dbConnect, $sql, $params);
if ($stmt === false) {
    error_log(json_encode(sqlsrv_errors()));
    echo json_encode(['ok' => false, 'error' => 'Consulta fallida']);
    exit;
}

$filas = [];
$razon = '';
while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $razon = $razon ?: trim((string)($r['razon_social'] ?? ''));
    // --- Filtros post-query ---
    $cau = trim((string)($r['causado'] ?? ''));
    if ($causado!=='TODAS' && strtoupper($cau)!==$causado) continue;
    $di = (int)$r['dias'];
    if ($diasMin!==null && $di < $diasMin) continue;
    if ($diasMax!==null && $di > $diasMax) continue;
    if ($fdesde!=='' && (string)$r['fecha_venc'] < $fdesde) continue;
    if ($fhasta!=='' && (string)$r['fecha_venc'] > $fhasta) continue;
    // Descartar filas sin mes de pago válido (FECHA_PAGO nula → YEAR/MONTH = 0):
    // no tienen columna donde ubicarse en el pivote mensual.
    if ((int)$r['anio_pago'] < 1 || (int)$r['mes_pago'] < 1) continue;
    $fv = $r['fecha_venc']; $fp = $r['fecha_pago'];
    $filas[] = [
        'fecha_venc' => is_object($fv) ? $fv->format('Y-m-d') : ($fv !== null ? (string)$fv : null),
        'dias'       => (int)$r['dias'],
        'documento'  => trim((string)($r['documento'] ?? '')),
        'causado'    => strtoupper(trim((string)($r['causado'] ?? ''))),
        'moneda'     => trim((string)($r['moneda'] ?? '')),
        'valor'      => (float)($r['valor'] ?? 0),
        'en_pesos'   => (function() use ($r, $trm) {
            $m = trim((string)($r['moneda'] ?? '')); $val = (float)($r['valor'] ?? 0);
            $tasa = ($m==='COP') ? 1.0 : (($m==='USD') ? ($trm['USD']?:1) : (($m==='EU'||$m==='EUR') ? ($trm['EUR']?:1) : 1));
            return $val * $tasa;
        })(),
        'fecha_pago' => is_object($fp) ? $fp->format('Y-m-d') : ($fp !== null ? (string)$fp : null),
        'anio_pago'  => (int)$r['anio_pago'],
        'mes_pago'   => (int)$r['mes_pago'],
        'base'       => $r['base'],
    ];
}
sqlsrv_free_stmt($stmt);
sqlsrv_close($dbConnect);

$mset = [];
foreach ($filas as $f) { $mset[sprintf('%04d-%02d',$f['anio_pago'],$f['mes_pago'])] = [$f['anio_pago'],$f['mes_pago']]; }
ksort($mset);
$meses = array_values(array_map(fn($x)=>['anio'=>$x[0],'mes'=>$x[1]], $mset));

echo json_encode(
    ['ok' => true, 'nit' => $nit, 'razon_social' => $razon, 'trm' => $trm, 'meses' => $meses, 'filas' => $filas],
    JSON_UNESCAPED_UNICODE
);
