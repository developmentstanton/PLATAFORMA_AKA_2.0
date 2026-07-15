<?php
/**
 * Prueba el fix de fondo: con disco fresco, el endpoint tab=data SIN filtro sirve de disco
 * SIN necesitar evol_cache_base. Borramos las filas de la base y, aun así, debe responder ok:true
 * desde disco (si dependiera de la base, fallaría o la reconstruiría lento).
 *   php tests/verificar_evol_disco_primero.php   (requiere DB)
 */
error_reporting(E_ERROR | E_PARSE);
require __DIR__ . '/../api/lib_disk_cache.php';
require __DIR__ . '/../api/lib_evol_cache.php';
require __DIR__ . '/../api/lib_evol_disk.php';
require __DIR__ . '/_task4_paridad_evol.php'; // evolCallEndpoint / evolDefaultDesdeMes / evolDefaultHastaMes / evolPurgeKey
require __DIR__ . '/../conexion/conexion_integracion.php';
require __DIR__ . '/../api/lib_refs.php';
if ($dbConnect===false){ echo "SKIP sin DB\n"; exit(0); }
$conn=$dbConnect; $fail=0; function ckp($c,$m){ global $fail; echo ($c?"OK  ":"FAIL")."  $m\n"; if(!$c)$fail++; }
$prov='BH BRANDS SAS';
$ekey=evolCacheKey($prov, evolDefaultDesdeMes(), evolDefaultHastaMes());

// 1) Calentar el disco vía endpoint (miss -> materialize -> write).
$r1 = evolCallEndpoint($prov, 'tab=data');
ckp(is_array($r1) && ($r1['ok']??false)===true, 'calentamiento: endpoint tab=data ok:true');
ckp(evolDiskFresh($conn,$ekey)===true, 'disco fresco tras calentamiento');

// 2) Vaciar evol_cache_base para esta key: si el endpoint dependiera de la base, se notaría.
$d=sqlsrv_query($conn,"DELETE FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?",[$ekey]);
if($d!==false) sqlsrv_free_stmt($d);
$cnt=sqlsrv_query($conn,"SELECT COUNT(*) n FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?",[$ekey]);
$row=sqlsrv_fetch_array($cnt,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($cnt);
ckp((int)$row['n']===0, 'evol_cache_base vaciada para la key');

// 3) Con disco fresco + base vacía, el endpoint DEBE servir de disco (hit), sin reconstruir.
$r2 = evolCallEndpoint($prov, 'tab=data');
ckp(is_array($r2) && ($r2['ok']??false)===true, 'DISCO-PRIMERO: endpoint tab=data ok:true con base vacía (sirvió de disco)');
ckp(json_encode($r2)===json_encode($r1), 'DISCO-PRIMERO: mismo payload que el calentamiento (hit real de disco)');

// DISCO-PRIMERO (prueba directa): la base debe seguir VACÍA — si el endpoint la hubiera
// materializado (código viejo: ensure incondicional antes del disco), COUNT sería > 0.
$cnt2=sqlsrv_query($conn,"SELECT COUNT(*) n FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?",[$ekey]);
$row2=sqlsrv_fetch_array($cnt2,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($cnt2);
ckp((int)$row2['n']===0, 'DISCO-PRIMERO: evol_cache_base sigue vacía tras servir (base NO materializada)');

echo $fail?"\n$fail FALLO(S)\n":"\nEVOL DISCO-PRIMERO OK\n"; exit($fail?1:0);
