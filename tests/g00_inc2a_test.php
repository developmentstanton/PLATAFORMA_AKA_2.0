<?php
// Verifica Inc2A backend: catálogo de combinaciones + dimensiones distintas.
//   php tests/g00_inc2a_test.php
$srv="siesa-m1-sqlsw-db15.cbm3ohogeajr.us-east-1.rds.amazonaws.com";
$cn=sqlsrv_connect($srv,["Database"=>"INTEGRACION","UID"=>"admistanton","PWD"=>"adminstanton\$12\$%","CharacterSet"=>"UTF-8"]);
if($cn===false){fwrite(STDERR,"sin conexion\n");exit(1);}
function q($cn,$s,$p=[]){ $st=sqlsrv_query($cn,$s,$p); if($st===false){fwrite(STDERR,print_r(sqlsrv_errors(),true));exit(1);} $r=[];while($x=sqlsrv_fetch_array($st,SQLSRV_FETCH_ASSOC))$r[]=$x;return $r;}
$prov=q($cn,"SELECT TOP 1 i.PROVEEDOR FROM INTEGRACION.dbo.Ventas_Detal_PBI v WITH(NOLOCK) INNER JOIN INTEGRACION.dbo.ITEMS i WITH(NOLOCK) ON i.REFERENCIA=v.REFERENCIA WHERE i.PROVEEDOR<>'' GROUP BY i.PROVEEDOR ORDER BY SUM(v.VALOR) DESC")[0]['PROVEEDOR'];
echo "Proveedor: $prov\n";
sqlsrv_query($cn,"CREATE TABLE #refs (REFERENCIA varchar(50) PRIMARY KEY, MARCA varchar(40), TIPO varchar(40), CATEGORIA varchar(40))");
$refs=q($cn,"SELECT REFERENCIA, ISNULL(MARCA,'SIN MARCA') MARCA, ISNULL(TIPO,'SIN TIPO') TIPO, ISNULL(CATEGORIA,'') CATEGORIA FROM INTEGRACION.dbo.ITEMS WITH(NOLOCK) WHERE PROVEEDOR=?",[$prov]);
foreach(array_chunk($refs,200) as $ch){$v=[];$p=[];foreach($ch as $r){$v[]='(?,?,?,?)';array_push($p,$r['REFERENCIA'],$r['MARCA'],$r['TIPO'],$r['CATEGORIA']);}sqlsrv_query($cn,"INSERT INTO #refs (REFERENCIA,MARCA,TIPO,CATEGORIA) VALUES ".implode(',',$v),$p);}
$combos=q($cn,"SELECT DISTINCT i.MARCA, i.TIPO, i.CATEGORIA, ISNULL(b.DEPTO,'') DEPTO, ISNULL(b.CIUDAD,'') CIUDAD FROM (SELECT DISTINCT REFERENCIA,BODEGA FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH(NOLOCK) UNION SELECT DISTINCT REFERENCIA,BODEGA FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH(NOLOCK)) v INNER JOIN #refs i ON i.REFERENCIA=v.REFERENCIA LEFT JOIN INTEGRACION.dbo.Bodegas b WITH(NOLOCK) ON b.COD=v.BODEGA AND b.CIA=7");
$marcas=array_values(array_unique(array_map(fn($r)=>$r['MARCA'],$combos)));
$deptos=array_values(array_filter(array_unique(array_map(fn($r)=>trim($r['DEPTO']),$combos))));
echo "Combos: ".count($combos)." | marcas distintas: ".count($marcas)." | deptos distintos: ".count($deptos)."\n";
$fail=0;
if(count($combos)<1){echo "FALLO: catalogo vacio\n";$fail=1;}
if(count($marcas)<1){echo "FALLO: sin marcas\n";$fail=1;}
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (catalogo con combos y dimensiones)\n";
exit($fail);
