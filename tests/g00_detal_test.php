<?php
// Verificación backend del tab Detal del G00 (Inc 1). Correr por CLI:
//   php tests/g00_detal_test.php "NOMBRE PROVEEDOR" 2026-05-01 2026-05-31
// Si no se pasan args, usa un proveedor por defecto detectado.
$srv = "siesa-m1-sqlsw-db15.cbm3ohogeajr.us-east-1.rds.amazonaws.com";
$cn  = sqlsrv_connect($srv, ["Database"=>"INTEGRACION","UID"=>"admistanton","PWD"=>"adminstanton\$12\$%","CharacterSet"=>"UTF-8"]);
if ($cn === false) { fwrite(STDERR, "Sin conexion\n"); exit(1); }

$proveedor = $argv[1] ?? null;
$desdeAct  = $argv[2] ?? date('Y-05-01');
$hastaAct  = $argv[3] ?? date('Y-05-31');
$desdeAnt  = date('Y-m-d', strtotime($desdeAct.' -1 year'));
$hastaAnt  = date('Y-m-d', strtotime($hastaAct.' -1 year'));
$gmin = min($desdeAnt, $desdeAct); $gmax = max($hastaAct, $hastaAnt);

function q($cn,$sql,$p=[]){ $s=sqlsrv_query($cn,$sql,$p); if($s===false){fwrite(STDERR,print_r(sqlsrv_errors(),true));exit(1);} $r=[]; while($x=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC))$r[]=$x; return $r; }

if (!$proveedor) {
    $row = q($cn, "SELECT TOP 1 PROVEEDOR FROM INTEGRACION.dbo.ITEMS WHERE PROVEEDOR IS NOT NULL AND PROVEEDOR <> '' GROUP BY PROVEEDOR ORDER BY COUNT(*) DESC");
    $proveedor = $row[0]['PROVEEDOR'];
}
echo "Proveedor: $proveedor | Rango 2026 $desdeAct..$hastaAct vs 2025 $desdeAnt..$hastaAnt\n";

// #refs con TIPO
sqlsrv_query($cn, "CREATE TABLE #refs (REFERENCIA varchar(50) PRIMARY KEY, MARCA varchar(40), TIPO varchar(40))");
$refs = q($cn, "SELECT REFERENCIA, ISNULL(MARCA,'SIN MARCA') MARCA, ISNULL(TIPO,'SIN TIPO') TIPO FROM INTEGRACION.dbo.ITEMS WITH (NOLOCK) WHERE PROVEEDOR = ?", [$proveedor]);
foreach (array_chunk($refs, 200) as $ch) {
    $vals=[];$p=[]; foreach($ch as $r){$vals[]='(?,?,?)';array_push($p,$r['REFERENCIA'],$r['MARCA'],$r['TIPO']);}
    sqlsrv_query($cn, "INSERT INTO #refs (REFERENCIA,MARCA,TIPO) VALUES ".implode(',',$vals), $p);
}

$sqlBase = "
WITH ventas AS (
  SELECT FECHA,BODEGA,REFERENCIA,CANTIDAD,VALOR,MARGEN FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?
  UNION ALL
  SELECT FECHA,BODEGA,REFERENCIA,CANTIDAD,VALOR,MARGEN FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?
), ventas_enriq AS (
  SELECT v.FECHA,v.BODEGA,v.CANTIDAD,v.VALOR,v.MARGEN, ISNULL(b.GRUPO,'SIN GRUPO') GRUPO, i.MARCA, i.TIPO
  FROM ventas v
  INNER JOIN #refs i ON i.REFERENCIA=v.REFERENCIA
  LEFT JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD=v.BODEGA AND b.CIA=7
  WHERE (v.FECHA BETWEEN ? AND ? OR v.FECHA BETWEEN ? AND ?)
  __SAMESTORE__
)
SELECT
  SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR ELSE 0 END) val_act,
  SUM(CASE WHEN FECHA BETWEEN ? AND ? THEN VALOR ELSE 0 END) val_ant,
  COUNT(DISTINCT CASE WHEN FECHA BETWEEN ? AND ? THEN BODEGA END) tiendas_act
FROM ventas_enriq";

$same = " AND EXISTS (SELECT 1 FROM INTEGRACION.dbo.Bodegas sb WITH (NOLOCK) WHERE sb.COD=v.BODEGA AND sb.CIA=7 AND (sb.FECHA_APERTURA IS NULL OR sb.FECHA_APERTURA<=?) AND (sb.FECHA_CIERRE IS NULL OR sb.FECHA_CIERRE>=?))";

// Sin same-store
$pAll = [$gmin,$gmax,$gmin,$gmax, $desdeAct,$hastaAct,$desdeAnt,$hastaAnt, $desdeAct,$hastaAct,$desdeAnt,$hastaAnt,$desdeAct,$hastaAct];
$all = q($cn, str_replace('__SAMESTORE__','',$sqlBase), $pAll);
// Con same-store (2 params extra del EXISTS, tras el OR-filter)
$pSame = [$gmin,$gmax,$gmin,$gmax, $desdeAct,$hastaAct,$desdeAnt,$hastaAnt, $desdeAnt,$hastaAct, $desdeAct,$hastaAct,$desdeAnt,$hastaAnt,$desdeAct,$hastaAct];
$ss = q($cn, str_replace('__SAMESTORE__',$same,$sqlBase), $pSame);

$va_all=(float)$all[0]['val_act']; $va_ss=(float)$ss[0]['val_act'];
printf("Ventas 2026  TODAS=%s  SAME=%s\n", number_format($va_all), number_format($va_ss));
printf("Tiendas 2026 TODAS=%s  SAME=%s\n", $all[0]['tiendas_act'], $ss[0]['tiendas_act']);

$fail = 0;
if ($va_ss > $va_all + 0.5) { echo "FALLO: same-store > todas (imposible)\n"; $fail=1; }
if ($va_ss <= 0 && $va_all > 0) { echo "AVISO: same-store dio 0 con todas>0 (revisar criterio fechas)\n"; }
echo $fail ? "RESULTADO: FALLO\n" : "RESULTADO: OK (same-store <= todas, forma correcta)\n";
exit($fail);
