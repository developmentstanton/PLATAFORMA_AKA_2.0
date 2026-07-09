<?php
/**
 * Cache en disco del árbol O14 tab=c SIN filtro (payload determinista por cache_key).
 * Evita transferir 46k filas / ~5.5MB desde la RDS remota en cada request: se construye
 * una vez y se sirve gzip desde disco local. Ver spec 2026-07-09-o14c-arbol-cache-disco.
 * Frescura por .stamp (valor `creado` de o14_cache_base): compara timestamp-de-DB contra
 * timestamp-de-DB, sin cruzar el reloj del server PHP (Colombia) con el de la RDS (UTC).
 */
require_once __DIR__ . '/lib_o14_cache.php'; // O14_CACHE_TTL_MIN, o14CacheKey/Fresco/ensure

if (!function_exists('o14cCacheDir')) {
    function o14cCacheDir(): string { return __DIR__ . '/../cache'; }
    // $key es un hash md5 de 32 hex (o14CacheKey) -> seguro como componente de nombre de archivo.
    function o14cPayloadPath(string $key): string { return o14cCacheDir() . '/o14c_' . $key . '.json.gz'; }
    function o14cStampPath(string $key): string { return o14cCacheDir() . '/o14c_' . $key . '.stamp'; }

    function o14cWritePayload(string $key, string $jsonPlano, string $stamp): bool {
        $dir = o14cCacheDir();
        if (!is_dir($dir) || !is_writable($dir)) return false;       // degradar sin romper
        $gz = gzencode($jsonPlano, 6);
        if ($gz === false) return false;
        // escritura atómica: tmp + rename (un lector nunca ve un archivo a medias).
        // ORDEN IMPORTANTE: primero el .json.gz, DESPUÉS el .stamp. Si el paso del stamp
        // falla, queda payload-nuevo + stamp-viejo/ausente -> o14cDiskFresh da FALSE (mismatch)
        // -> se reconstruye en el próximo request (desperdicio, NO incorrección). NO invertir:
        // stamp-primero podría dejar stamp-nuevo + payload-viejo -> o14cDiskFresh daría TRUE y
        // serviría un árbol STALE. La no-atomicidad del par es segura SOLO con este orden.
        $tmp = o14cPayloadPath($key) . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $gz) === false) { @unlink($tmp); return false; }
        if (!@rename($tmp, o14cPayloadPath($key))) { @unlink($tmp); return false; }
        $tmpS = o14cStampPath($key) . '.tmp.' . getmypid();
        if (@file_put_contents($tmpS, $stamp) === false) { @unlink($tmpS); return false; }
        if (!@rename($tmpS, o14cStampPath($key))) { @unlink($tmpS); return false; }
        return true;
    }

    function o14cReadPayload(string $key): ?string {
        $p = o14cPayloadPath($key);
        if (!is_file($p)) return null;
        $b = @file_get_contents($p);
        return $b === false ? null : $b;
    }

    function o14cCleanup(): void {
        $dir = o14cCacheDir();
        if (!is_dir($dir)) return;
        $limite = time() - O14_CACHE_TTL_MIN * 60;
        foreach (glob($dir . '/o14c_*.json.gz') ?: [] as $f) {
            if (@filemtime($f) < $limite) { @unlink($f); @unlink(substr($f, 0, -8) . '.stamp'); }
        }
        // barrer .tmp.* huérfanos (proceso muerto entre file_put_contents y rename)
        foreach (glob($dir . '/o14c_*.tmp.*') ?: [] as $f) {
            if (@filemtime($f) < $limite) @unlink($f);
        }
        // barrer .lock viejos (uno por cache_key; hasta=hoy cambia la key a diario -> se acumulan).
        // Solo los más viejos que el TTL: un lock recién creado por un build en vuelo nunca se toca.
        foreach (glob($dir . '/o14c_*.lock') ?: [] as $f) {
            if (@filemtime($f) < $limite) @unlink($f);
        }
    }
}

if (!function_exists('o14cServeGz')) {
    function o14cServeGz(string $gz): void {
        header('Content-Type: application/json; charset=utf-8');
        $ae = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        if (stripos($ae, 'gzip') !== false) { header('Content-Encoding: gzip'); echo $gz; }
        else { echo gzdecode($gz); }
    }
}

if (!function_exists('o14cCurrentStamp')) {
    function o14cCurrentStamp($conn, string $key): ?string {
        $st = sqlsrv_query($conn,
            "SELECT TOP 1 CONVERT(varchar(30), creado, 126) s FROM INTEGRACION.dbo.o14_cache_base WITH (READPAST) WHERE cache_key=? ORDER BY creado DESC",
            [$key]);
        if ($st === false) return null;
        $r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($st);
        return $r ? $r['s'] : null;
    }

    function o14cDiskFresh($conn, string $key): bool {
        if (!is_file(o14cPayloadPath($key)) || !is_file(o14cStampPath($key))) return false;
        $stampDisco = @file_get_contents(o14cStampPath($key));
        if ($stampDisco === false) return false;
        $stampDb = o14cCurrentStamp($conn, $key);
        return $stampDb !== null && $stampDisco === $stampDb;
    }

    function o14cBuildPayloadC($conn, string $key, string $desde, string $hasta): array {
        // Query verbatim de informe_o14.php tab=c cacheMode SIN filtro (whereFiltros='', params=[key]).
        $sql = "
            SELECT ISNULL(c.grupo,'SIN GRUPO') grupo, (c.cia + '-' + c.bodega) llave, c.cia, c.bodega,
              ISNULL(c.nombre, c.bodega) nombre, c.negocio, c.referencia, c.color, c.talla,
              SUM(c.siembra) siembra, SUM(c.disponible) disponible, SUM(c.hold) hold, SUM(c.ventas) ventas
            FROM INTEGRACION.dbo.o14_cache_base c
            WHERE c.cache_key=?
            GROUP BY ISNULL(c.grupo,'SIN GRUPO'), c.cia, c.bodega, ISNULL(c.nombre,c.bodega), c.negocio, c.referencia, c.color, c.talla
            ORDER BY ISNULL(c.grupo,'SIN GRUPO'), (c.cia+'-'+c.bodega), c.negocio";
        $st = sqlsrv_query($conn, $sql, [$key]);
        $rows = [];
        if ($st !== false) { while ($x = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $rows[] = $x; sqlsrv_free_stmt($st); }

        [$grupos, $tallas, $kpi] = ensamblarArbol($rows);
        $kpi['total_stock'] = $kpi['disponible'] + $kpi['hold'];

        // MANTENER EN SYNC con kpiCounts() de api/informe_o14.php: si allí se agrega/cambia un conteo, replicar aquí (parity).
        // Conteos (kpiCounts de informe_o14.php:95-105, unfiltered: WHERE cache_key=?).
        $cnt = sqlsrv_query($conn, "
            SELECT
              (SELECT COUNT(DISTINCT cia+'|'+negocio) FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=?)                  negocios,
              (SELECT COUNT(DISTINCT cia+'|'+negocio) FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=? AND siembra>0)     negocios_con_siembra,
              (SELECT COUNT(DISTINCT cia+'-'+bodega)  FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=? AND siembra>0)     tiendas_con_siembra,
              (SELECT COUNT(DISTINCT cia+'-'+bodega)  FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=? AND disponible>0)  tiendas_con_inv,
              (SELECT COUNT(DISTINCT cia+'-'+bodega)  FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=? AND ventas<>0)     tiendas_con_venta",
            [$key, $key, $key, $key, $key]);
        if ($cnt !== false) { $c = sqlsrv_fetch_array($cnt, SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($cnt);
            if ($c) $kpi = array_merge($kpi, array_map('intval', $c)); }

        return ['ok'=>true, 'tab'=>'c', 'rango'=>['desde'=>$desde,'hasta'=>$hasta],
                'tallas'=>$tallas, 'medidas'=>['siembra','disponible','hold','disphold','sobrante','faltante','ventas'],
                'grupos'=>$grupos, 'kpis'=>$kpi];
    }
}

if (!function_exists('ensamblarArbol')) {
    /** Arma grupos→almacenes→negocios desde filas planas de o14_cache_base/#base.
     *  KPIs de cantidad se acumulan sobre TODOS los grupos (incluido CEDI). */
    function ensamblarArbol($rows) {
        $tallasSet=[]; $arbol=[];
        $kpi=['siembra'=>0,'disponible'=>0,'hold'=>0,'ventas'=>0,'sobrantes'=>0,'faltante'=>0];
        foreach ($rows as $r) {
            $g=$r['grupo']; $ll=$r['llave']; $neg=$r['negocio']; $talla=(string)$r['talla']; $tallasSet[$talla]=true;
            $si=(int)$r['siembra']; $di=(int)$r['disponible']; $ho=(int)$r['hold']; $ve=(int)$r['ventas'];
            $bal=$si-($di+$ho); $fal=max(0,$bal); $sob=max(0,-$bal);
            if(!isset($arbol[$g])) $arbol[$g]=['grupo'=>$g,'almacenes'=>[]];
            if(!isset($arbol[$g]['almacenes'][$ll])) $arbol[$g]['almacenes'][$ll]=['llave'=>$ll,'bodega'=>$r['bodega'],'nombre'=>$r['nombre'],'negocios'=>[]];
            if(!isset($arbol[$g]['almacenes'][$ll]['negocios'][$neg])) $arbol[$g]['almacenes'][$ll]['negocios'][$neg]=['negocio'=>$neg,'referencia'=>$r['referencia'],'color'=>$r['color'],'valores'=>[]];
            $vals=&$arbol[$g]['almacenes'][$ll]['negocios'][$neg]['valores'];
            foreach(['siembra'=>$si,'disponible'=>$di,'hold'=>$ho,'disphold'=>$di+$ho,'sobrante'=>$sob,'faltante'=>$fal,'ventas'=>$ve] as $m=>$v)
                $vals[$m][$talla]=($vals[$m][$talla]??0)+$v;
            unset($vals);
            $kpi['siembra']+=$si; $kpi['disponible']+=$di; $kpi['hold']+=$ho; $kpi['ventas']+=$ve; $kpi['sobrantes']+=$sob; $kpi['faltante']+=$fal;
        }
        $grupos=[];
        foreach($arbol as $g){
            $g['almacenes']=array_values(array_map(function($a){ $a['negocios']=array_values($a['negocios']); return $a; }, $g['almacenes']));
            $grupos[]=$g;
        }
        $tallas=array_keys($tallasSet);
        usort($tallas, fn($a,$b)=>(is_numeric($a)&&is_numeric($b))?($a<=>$b):strcmp($a,$b));
        return [$grupos, $tallas, $kpi];
    }
}
