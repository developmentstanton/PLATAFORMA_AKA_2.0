<?php
/**
 * Capa de calentamiento: calienta las caches en disco de los 3 informes lentos de UN proveedor.
 * Fuente unica de "construir+escribir por informe", compartida por el prebuild nocturno
 * (sql/prebuild_all.php) y el login-prewarm (sql/prewarm_login.php). Ver spec 2026-07-10-prewarm-layer.
 * Aislamiento: opera sobre el $proveedor pasado, con su #refs en $conn; caches llaveadas por proveedor.
 */
require_once __DIR__ . '/lib_refs.php';
require_once __DIR__ . '/lib_o14c_payload.php';
require_once __DIR__ . '/lib_evol_disk.php';
require_once __DIR__ . '/lib_o45_disk.php';

if (!function_exists('warmProveedor')) {
    function warmProveedor($conn, string $proveedor, bool $onlyIfStale = false): array {
        // #refs una vez (los 3 builders lo comparten en $conn).
        if (!buildRefsFromMat($conn, $proveedor)) return ['o14c'=>'failed-refs','evol'=>'failed-refs','o45'=>'failed-refs'];
        $out = [];

        // --- o14c: 2025-01-01 .. hoy ---
        $d='2025-01-01'; $h=date('Y-m-d'); $k=o14CacheKey($proveedor,$d,$h);
        if ($onlyIfStale && o14cDiskFresh($conn,$k)) $out['o14c']='skipped';
        elseif (!ensureO14CacheBase($conn,$k,$d,$h)) $out['o14c']='failed-ensure';
        else { $st=o14cCurrentStamp($conn,$k); $p=o14cBuildPayloadC($conn,$k,$d,$h);
            $out['o14c']=(($p['ok']??false)===true && $st!==null && o14cWritePayload($k,json_encode($p,JSON_UNESCAPED_UNICODE),$st))?'warmed':'failed'; }

        // --- evol: (Y-1)-01 .. Y-m ---
        $ed=(date('Y')-1).'-01'; $eh=date('Y-m'); $ek=evolCacheKey($proveedor,$ed,$eh);
        if ($onlyIfStale && evolDiskFresh($conn,$ek)) $out['evol']='skipped';
        elseif (!ensureEvolCacheBase($conn,$ek,$ed,$eh)) $out['evol']='failed-ensure';
        else { $st=evolCurrentStamp($conn); $p=evolBuildPayload($conn,$proveedor,$ek,$ed,$eh);
            $out['evol']=(($p['ok']??false)===true && $st!==null && evolWritePayload($ek,json_encode($p,JSON_UNESCAPED_UNICODE),$st))?'warmed':'failed'; }

        // --- o45: 2025-01-01 .. ayer (o45 no tiene ensure; el build ES la materializacion) ---
        $od='2025-01-01'; $oh=date('Y-m-d',strtotime('-1 day')); $ok=o45CacheKey($proveedor,$od,$oh);
        if ($onlyIfStale && o45DiskFresh($conn,$ok)) $out['o45']='skipped';
        else { $st=o45CurrentStamp($conn); $p=o45BuildPayload($conn,$proveedor,$od,$oh);
            $out['o45']=(($p['ok']??false)===true && $st!==null && o45WritePayload($ok,json_encode($p,JSON_UNESCAPED_UNICODE),$st))?'warmed':'failed'; }

        return $out;
    }
}
