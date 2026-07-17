<?php
/** Prebuild nocturno de las 3 caches en disco (o14c/evol/o45) por proveedor. Correr DESPUES del ETL/Items_Mat.
 *   php sql/prebuild_all.php               (todos los proveedores)
 *   php sql/prebuild_all.php "BELTRANY SAS" (solo esos)
 *   php sql/prebuild_all.php --dry-run     (lista, no construye) */
error_reporting(E_ERROR | E_PARSE);
$t0=microtime(true);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_login.php';
require __DIR__ . '/../api/lib_prewarm.php';
if ($dbConnect===false){ fwrite(STDERR,"[prebuild_all] Conexion DB fallida\n"); exit(1); }
$args=array_slice($argv,1); $dry=in_array('--dry-run',$args,true); $args=array_values(array_filter($args,fn($a)=>$a!=='--dry-run'));

if ($args) { $provs=$args; }
else {
    $provs=[]; $st=sqlsrv_query($dbConnect,"SELECT DISTINCT nombre_usuario FROM usuarios_portal_aka WHERE nombre_usuario IS NOT NULL");
    if ($st!==false){ while($u=sqlsrv_fetch_array($st,SQLSRV_FETCH_ASSOC)){ $r=login_resolver_proveedor($dbConnect,trim((string)$u['nombre_usuario']));
        $p=trim((string)($r['proveedor']??'')); if($p!==''&&$p!=='__SIN_PROVEEDOR__')$provs[$p]=true; } sqlsrv_free_stmt($st); }
    $provs=array_keys($provs);
}
echo "[prebuild_all] ".date('Y-m-d H:i:s')." proveedores=".count($provs).($dry?" (DRY-RUN)":"")."\n";
if ($dry){ foreach($provs as $p) echo "  - $p\n"; sqlsrv_close($dbConnect); exit(0); }

$okN=0;$failN=0;
foreach($provs as $prov){ $tp=microtime(true); $r=warmProveedor($dbConnect,$prov,false);
    $bad=in_array('failed',$r,true)||in_array('failed-refs',$r,true)||in_array('failed-ensure',$r,true);
    printf("  %s %-28s o14c=%s evol=%s evol_filtros=%s o45=%s %.1fs\n",$bad?'FALLO':'OK  ',$prov,$r['o14c'],$r['evol'],$r['evol_filtros']??'-',$r['o45'],microtime(true)-$tp);
    $bad?$failN++:$okN++; }
printf("[prebuild_all] fin: OK=%d FALLO=%d en %.1fs\n",$okN,$failN,microtime(true)-$t0);
sqlsrv_close($dbConnect); exit($failN?1:0);
