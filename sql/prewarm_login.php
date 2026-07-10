<?php
/** Login-prewarm: calienta en segundo plano SOLO lo stale del proveedor pasado, con throttle por-proveedor.
 * Lanzado detached por index.php al iniciar sesion. NO bloquea el login.
 *   php sql/prewarm_login.php "PROVEEDOR" */
error_reporting(E_ERROR | E_PARSE);
$t0=microtime(true);
$prov=$argv[1] ?? '';
if ($prov==='') exit(0);
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_prewarm.php';
if ($dbConnect===false){ fwrite(STDERR,"[prewarm_login] ".date('Y-m-d H:i:s')." $prov: sin DB\n"); exit(1); }
// throttle: un prewarm por proveedor a la vez (no apilar en re-logins).
$lockPath=__DIR__.'/../cache/prewarm_'.substr(md5($prov),0,32).'.lock';
$lk=@fopen($lockPath,'c');
if (!$lk || !flock($lk,LOCK_EX|LOCK_NB)){ echo date('Y-m-d H:i:s')." $prov: throttled\n"; exit(0); }
$r=warmProveedor($dbConnect,$prov,true);
flock($lk,LOCK_UN); fclose($lk);
printf("%s %s: o14c=%s evol=%s o45=%s (%.1fs)\n",date('Y-m-d H:i:s'),$prov,$r['o14c'],$r['evol'],$r['o45'],microtime(true)-$t0);
sqlsrv_close($dbConnect); exit(0);
