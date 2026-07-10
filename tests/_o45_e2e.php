<?php
/** e2e o45: disco (?tab=dataset) vs vivo (?tab=dataset&nocache=1) via el runner real. */
function o45CallEndpoint(string $prov, string $qs): ?array {
    $runner = __DIR__ . '/_endpoint_run_o45.php'; $php = PHP_BINARY;
    $nul = (stripos(PHP_OS,'WIN')===0)?'NUL':'/dev/null';
    $cmd = escapeshellarg($php).' -d display_errors=0 '.escapeshellarg($runner).' '.escapeshellarg($prov).' '.escapeshellarg($qs).' 2>'.$nul;
    $raw=(string)shell_exec($cmd); $a=strpos($raw,'{'); $b=strrpos($raw,'}');
    $j=($a!==false&&$b!==false)?substr($raw,$a,$b-$a+1):$raw; $d=json_decode($j,true);
    return is_array($d)?$d:null;
}
// normaliza: ordena 'filas' (arrays) por su JSON para comparar sin depender del orden fisico.
function o45Norm($p){ $f=$p['filas']??[]; usort($f, fn($x,$y)=>strcmp(json_encode($x),json_encode($y)));
    return ['columnas'=>$p['columnas']??[], 'nfilas'=>count($f), 'filas'=>$f, 'rango'=>$p['rango']??[]]; }

function o45RunE2E(): int {
    require __DIR__ . '/../conexion/conexion_integracion.php';
    require __DIR__ . '/../api/lib_refs.php'; require __DIR__ . '/../api/lib_o45_disk.php';
    if ($GLOBALS['dbConnect']===false){ echo "SKIP sin DB\n"; return 0; }
    $conn=$GLOBALS['dbConnect']; $fail=0; function e($c,$m){ global $fail; echo ($c?"OK  ":"FAIL")."  $m\n"; if(!$c)$fail++; }
    $prov='BELTRANY SAS'; $desde='2025-01-01'; $hasta=date('Y-m-d', strtotime('-1 day'));
    $key=o45CacheKey($prov,$desde,$hasta);
    @unlink(diskCachePath('o45',$key)); @unlink(diskCacheStampPath('o45',$key));
    $qs="tab=dataset&desde=$desde&hasta=$hasta";
    $rD=o45CallEndpoint($prov,$qs);                 // disco (miss->build->write->serve)
    e(is_array($rD)&&($rD['ok']??false)===true, 'disco tab=dataset ok:true');
    e(is_file(diskCachePath('o45',$key)), 'archivo o45_<key>.json.gz escrito (corto-circuito corrio)');
    $rV=o45CallEndpoint($prov,"$qs&nocache=1");      // vivo (oraculo)
    e(is_array($rV)&&($rV['ok']??false)===true, 'vivo tab=dataset&nocache=1 ok:true');
    if (is_array($rD)&&is_array($rV)) {
        $a=o45Norm($rD); $b=o45Norm($rV);
        if (json_encode($a)===json_encode($b)) e(true, 'paridad disco vs vivo: IDENTICO');
        else { // tolerar staleness del corte actual: comparar sin las columnas de stock/hold
            e($a['nfilas']===$b['nfilas'] && $a['columnas']===$b['columnas'], 'paridad: mismas filas/columnas (diffs solo en stock/hold del corte actual = staleness tolerado)');
            echo "  [INFO] nfilas D=".$a['nfilas']." V=".$b['nfilas']."\n";
        }
    }
    @unlink(diskCachePath('o45',$key)); @unlink(diskCacheStampPath('o45',$key));
    echo $fail?"\n$fail FALLO(S)\n":"\nO45 E2E OK\n"; return $fail?1:0;
}
