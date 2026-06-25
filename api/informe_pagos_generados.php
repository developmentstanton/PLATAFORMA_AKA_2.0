<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }
$nit = trim($_SESSION['nit'] ?? '');
if ($nit === '') { echo json_encode(['ok'=>true,'sin_nit'=>true,'nodos'=>[],'total'=>['valor'=>0]]); exit; }

$fdesde = trim($_GET['fdesde'] ?? '');
$fhasta = trim($_GET['fhasta'] ?? '');

require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i=0; $dbConnect === false && $i < 4; $i++) { usleep(300000); $dbConnect = sqlsrv_connect($servidor, $infoconn); }
if ($dbConnect === false) { echo json_encode(['ok'=>false,'error'=>'Conexión DB fallida']); exit; }

$where = "WHERE RTRIM(NIT) = ?";
$params = [$nit];
if ($fdesde !== '') { $where .= " AND CONVERT(date, FECHA) >= ?"; $params[] = $fdesde; }
if ($fhasta !== '') { $where .= " AND CONVERT(date, FECHA) <= ?"; $params[] = $fhasta; }

$sql = "
SELECT CONVERT(date, FECHA) dia, SUM(VALOR_NETO) valor, COUNT(*) n,
       SUM(DATEDIFF(day, FECHA_VCTO, FECHA)) sumdias
FROM (
    SELECT FECHA, FECHA_VCTO, VALOR_NETO, NIT FROM Integracion.dbo.PAGOS_FECHA_VCTO_PBI WITH (NOLOCK)
    UNION ALL
    SELECT FECHA, FECHA_VCTO, VALOR_NETO, NIT FROM Integracion.dbo.HIST_PAGOS_FECHA_VCTO_PBI WITH (NOLOCK)
) u
$where
GROUP BY CONVERT(date, FECHA)
ORDER BY dia";

$stmt = sqlsrv_query($dbConnect, $sql, $params);
if ($stmt === false) { error_log(json_encode(sqlsrv_errors())); echo json_encode(['ok'=>false,'error'=>'Consulta fallida']); exit; }

// Agregados por día -> estructura años/meses/días con sum(valor), sum(n), sum(sumdias)
$tree = []; // [anio][mes][diaStr] = ['valor'=>,'n'=>,'sumdias'=>]
while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $diaObj = $r['dia']; // DateTime
    $diaStr = is_object($diaObj) ? $diaObj->format('Y-m-d') : (string)$diaObj;
    $anio = (int)substr($diaStr,0,4); $mes = (int)substr($diaStr,5,2);
    $tree[$anio][$mes][$diaStr] = ['valor'=>(float)$r['valor'], 'n'=>(int)$r['n'], 'sumdias'=>(float)$r['sumdias']];
}
sqlsrv_close($dbConnect);

$MES = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$nodos = []; $id = 0; $totV = 0; $totN = 0; $totSD = 0;
krsort($tree); // años descendente
foreach ($tree as $anio => $meses) {
    $aV=0; $aN=0; $aSD=0; $anioId = ++$id; $anioIdx = count($nodos);
    $nodos[] = ['id'=>$anioId,'pid'=>null,'nivel'=>1,'label'=>(string)$anio,'anio'=>$anio,'mes'=>null,'dia'=>null,'valor'=>0,'dias'=>0];
    ksort($meses); // meses calendario asc
    foreach ($meses as $mes => $dias) {
        $mV=0; $mN=0; $mSD=0; $mesId = ++$id; $mesIdx = count($nodos);
        $nodos[] = ['id'=>$mesId,'pid'=>$anioId,'nivel'=>2,'label'=>$MES[$mes],'anio'=>$anio,'mes'=>$mes,'dia'=>null,'valor'=>0,'dias'=>0];
        ksort($dias);
        foreach ($dias as $diaStr => $agg) {
            $dt = DateTime::createFromFormat('Y-m-d', $diaStr);
            $label = $dt ? $dt->format('d/m/Y') : $diaStr;
            $dDias = $agg['n'] > 0 ? $agg['sumdias']/$agg['n'] : 0;
            $nodos[] = ['id'=>++$id,'pid'=>$mesId,'nivel'=>3,'label'=>$label,'anio'=>$anio,'mes'=>$mes,'dia'=>$diaStr,
                        'valor'=>$agg['valor'],'dias'=>round($dDias,2)];
            $mV+=$agg['valor']; $mN+=$agg['n']; $mSD+=$agg['sumdias'];
        }
        $nodos[$mesIdx]['valor'] = $mV; $nodos[$mesIdx]['dias'] = $mN>0 ? round($mSD/$mN,2) : 0;
        $aV+=$mV; $aN+=$mN; $aSD+=$mSD;
    }
    $nodos[$anioIdx]['valor'] = $aV; $nodos[$anioIdx]['dias'] = $aN>0 ? round($aSD/$aN,2) : 0;
    $totV+=$aV; $totN+=$aN; $totSD+=$aSD;
}

echo json_encode([
    'ok'=>true, 'nit'=>$nit, 'nodos'=>$nodos,
    'total'=>['valor'=>$totV, 'dias'=>$totN>0 ? round($totSD/$totN,2) : 0],
], JSON_UNESCAPED_UNICODE);
