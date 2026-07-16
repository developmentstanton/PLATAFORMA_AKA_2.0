<?php
/** evol cache en disco: constructor del payload tab=data sin filtro + frescura, sobre lib_disk_cache. */
require_once __DIR__ . '/lib_disk_cache.php';
require_once __DIR__ . '/lib_evol_cache.php'; // evolCacheKey/ensure/EVOL_CACHE_TTL_MIN

// TTL del barrido de DISCO. Ojo: NO es EVOL_CACHE_TTL_MIN (120 min), que es la vida de
// evol_cache_base en la BD — otra cosa, con otro dueño. Reusar aquel borraba archivos que
// evolDiskFresh() daba por FRESCOS: desde que evol usa stamp de fuente su cache vale todo el día,
// así que el miss de cualquier proveedor barría los .json.gz de los demás con >2h y los obligaba a
// reconstruir un payload idéntico (~40s). 1500 min (~25h) = mismo criterio que O45_DISK_TTL_MIN:
// el archivo del día sobrevive hasta el próximo ETL y solo se barre lo realmente abandonado.
// Ver tests/disk_ttl_test.php.
if (!defined('EVOL_DISK_TTL_MIN')) define('EVOL_DISK_TTL_MIN', 1500);

if (!function_exists('evolCurrentStamp')) {
    // Stamp GLOBAL de fuente (mismo enfoque que o45CurrentStamp): avanza cuando el ETL nocturno
    // carga las 3 fuentes VIVAS de evol. ISNULL para que nunca sea NULL por una fuente vacía
    // (si la query falla -> null -> diskCacheFresh=false -> rebuild).
    //
    // VENTANA (arreglo 2026-07-16): `FECHA <= ayer` = el MISMO tope que el payload
    // (informe_evol.php:37 clampea $hastaF a $ayer; el BETWEEN de compras/ventas nunca ve el día en
    // curso). Con MAX(FECHA) global, las filas del día de mov_inv_actual_PBI (950 el 2026-07-16)
    // movían el stamp a media mañana e invalidaban TODO el cache en disco para reconstruir un
    // payload IDÉNTICO (~40s), y además tiraban a la basura el prebuild nocturno. Sigue avanzando
    // cada medianoche —correcto: la ventana se corre y el contenido sí cambia, y la key de evol
    // (proveedor+rango de meses) no rota sola. Ver tests/stamp_ventana_test.php.
    // NOTA de cobertura: el dataset lee más tablas (Ventas_Detal_Acum, historico_inventarios/
    // hold/mov_inv, _hold_actual), pero el stamp solo mira inv_actual + Ventas_Detal + mov_inv_actual
    // porque: (a) las históricas/Acum son append-only para un [desde,hasta] fijo y su recarga
    // siempre viene en el mismo ETL nocturno; (b) _hold_actual/stock del corte vivo tiene staleness
    // intradía que el diseño ya acepta (evolIsStalenessOnly); (c) esas 3 avanzando de noche son
    // proxy fiable del ETL completo. El prebuild nocturno corre con onlyIfStale=false (fuerza
    // rebuild), respaldo para cualquier recarga histórica.
    function evolCurrentStamp($conn): ?string {
        $ayer = date('Y-m-d', strtotime('-1 day'));   // tope idéntico al $ayer de informe_evol.php:34
        $sql = "SELECT ISNULL(CONVERT(varchar(19),(SELECT MAX(FECHA) FROM INTEGRACION.dbo.inv_actual_PBI     WITH (NOLOCK) WHERE FECHA <= ?),120),'') + '|'
                     + ISNULL(CONVERT(varchar(19),(SELECT MAX(FECHA) FROM INTEGRACION.dbo.Ventas_Detal_PBI   WITH (NOLOCK) WHERE FECHA <= ?),120),'') + '|'
                     + ISNULL(CONVERT(varchar(19),(SELECT MAX(FECHA) FROM INTEGRACION.dbo.mov_inv_actual_PBI WITH (NOLOCK) WHERE FECHA <= ?),120),'') s";
        $st = sqlsrv_query($conn, $sql, [$ayer, $ayer, $ayer]);
        if ($st === false) return null;
        $r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($st);
        return $r ? (string)$r['s'] : null;
    }
    function evolDiskFresh($conn, string $ekey): bool { return diskCacheFresh('evol', $ekey, evolCurrentStamp($conn)); }
    function evolReadPayload(string $ekey): ?string { return diskCacheRead('evol', $ekey); }
    function evolWritePayload(string $ekey, string $json, string $stamp): bool { return diskCacheWrite('evol', $ekey, $json, $stamp); }
    function evolCleanup(): void { diskCacheCleanup('evol', EVOL_DISK_TTL_MIN); }
    function evolServeGz(string $gz): void { diskCacheServeGz($gz); }

    function evolFetch($conn,$sql,$p){ $s=sqlsrv_query($conn,$sql,$p); if($s===false) return ['error'=>sqlsrv_errors()];
        $r=[]; while($x=sqlsrv_fetch_array($s,SQLSRV_FETCH_ASSOC))$r[]=$x; sqlsrv_free_stmt($s); return $r; }

    function evolBuildPayload($conn, string $proveedor, string $ekey, string $desdeMes, string $hastaMes): array {
        $mesActual = date('Y-m');
        $meses = [];
        for ($c=$desdeMes.'-01'; $c<=$hastaMes.'-01'; $c=date('Y-m-01', strtotime($c.' +1 month'))) $meses[]=substr($c,0,7);
        $ayer=date('Y-m-d', strtotime('-1 day'));
        $hastaF=date('Y-m-t', strtotime($hastaMes.'-01')); if ($hastaF>$ayer) $hastaF=$ayer;
        $diasMes = function($m) use ($mesActual){
            if ($m === $mesActual) return max(1, (int)date('j', strtotime('-1 day')));
            return (int)date('t', strtotime($m.'-01'));
        };

        // $agg: COPIADO VERBATIM de informe_evol.php:255-268 (rama cacheMode), SIN $whereFiltros;
        // params=[$ekey] (en vez de array_merge([$ekey],$paramsFiltros)).
        $agg = evolFetch($conn, "
      WITH perbod AS (
        SELECT c.negocio, c.mes, c.cia, c.bodega, MAX(c.referencia) referencia, MAX(c.color) color,
               SUM(c.ventas) ventas, SUM(c.compras) compras, SUM(c.stock) stock, MAX(ISNULL(c.grupo,'')) grupo
        FROM INTEGRACION.dbo.evol_cache_base c
        WHERE c.cache_key=?
        GROUP BY c.negocio, c.mes, c.cia, c.bodega )
      SELECT pb.negocio, pb.mes, MAX(pb.referencia) referencia, MAX(pb.color) color,
             SUM(pb.ventas) ventas, SUM(pb.compras) compras, SUM(pb.stock) stock,
             COUNT(DISTINCT CASE WHEN pb.stock>0 AND pb.grupo NOT IN ('BODEGA','ADMINISTRATIVAS')
                                  THEN pb.cia+'-'+pb.bodega END) tiendas
      FROM perbod pb
      GROUP BY pb.negocio, pb.mes
      ORDER BY pb.negocio, pb.mes", [$ekey]);
        if (isset($agg['error'])) return ['ok'=>false, 'error'=>'Consulta fallida', 'detalle'=>$agg['error']];

        // marca por negocio: COPIADO VERBATIM de informe_evol.php:289 (rama cacheMode), SIN $whereFiltros.
        $marcaMap=[];
        $rm = evolFetch($conn, "SELECT c.negocio, MAX(c.marca) marca FROM INTEGRACION.dbo.evol_cache_base c WHERE c.cache_key=? GROUP BY c.negocio", [$ekey]);
        if (!isset($rm['error'])) foreach($rm as $x) $marcaMap[$x['negocio']]=trim((string)$x['marca']);

        // ensamblado $neg + sort: COPIADO VERBATIM de informe_evol.php:299-323.
        $neg = [];   // negocio => fila tidy
        foreach ($agg as $r) {
            $key = $r['negocio'];
            if (!isset($neg[$key])) $neg[$key] = [
                'negocio'=>$key, 'referencia'=>trim((string)$r['referencia']), 'color'=>trim((string)$r['color']),
                'marca'=>$marcaMap[$key] ?? '', 'foto'=>$key,
                'valores'=>['compras'=>[], 'ventas'=>[], 'stock'=>[], 'tiendas'=>[], 'mesesInv'=>[], 'indice'=>[]],
                'totales'=>['compras'=>0, 'ventas'=>0],
            ];
            $m = $r['mes'];
            $ventas = (int)$r['ventas']; $compras = (int)$r['compras']; $stock = (int)$r['stock']; $tiendas = (int)$r['tiendas'];
            $N =& $neg[$key];
            $N['valores']['ventas'][$m]  = $ventas;
            $N['valores']['compras'][$m] = $compras;
            $N['valores']['stock'][$m]   = $stock;
            $N['valores']['tiendas'][$m] = $tiendas;
            $N['valores']['mesesInv'][$m]= $ventas > 0 ? (int)round($stock / $ventas) : 0;
            $N['valores']['indice'][$m]  = $tiendas > 0 ? round(($ventas / $tiendas) / ($diasMes($m) / 30), 2) : 0.0;
            $N['totales']['compras'] += $compras;
            $N['totales']['ventas']  += $ventas;
            unset($N);
        }
        // Orden: por total de ventas desc (como O45).
        $negocios = array_values($neg);
        usort($negocios, fn($a,$b) => $b['totales']['ventas'] <=> $a['totales']['ventas']);

        // $aggTot: COPIADO VERBATIM de informe_evol.php:329-341 (rama cacheMode), SIN $whereFiltros;
        // params=[$ekey].
        $aggTot = evolFetch($conn, "
      WITH perbod AS (
        SELECT c.mes, c.cia, c.bodega, SUM(c.ventas) ventas, SUM(c.compras) compras, SUM(c.stock) stock,
               MAX(ISNULL(c.grupo,'')) grupo
        FROM INTEGRACION.dbo.evol_cache_base c
        WHERE c.cache_key=?
        GROUP BY c.mes, c.cia, c.bodega )
      SELECT pb.mes,
             SUM(pb.ventas) ventas, SUM(pb.compras) compras, SUM(pb.stock) stock,
             COUNT(DISTINCT CASE WHEN pb.stock>0 AND pb.grupo NOT IN ('BODEGA','ADMINISTRATIVAS')
                                  THEN pb.cia+'-'+pb.bodega END) tiendas
      FROM perbod pb
      GROUP BY pb.mes", [$ekey]);
        if (isset($aggTot['error'])) return ['ok'=>false, 'error'=>'Consulta fallida', 'detalle'=>$aggTot['error']];

        // $totalGeneral: COPIADO VERBATIM de informe_evol.php:357-372.
        $totalGeneral = [
            'valores'=>['compras'=>[], 'ventas'=>[], 'stock'=>[], 'tiendas'=>[], 'mesesInv'=>[], 'indice'=>[]],
            'totales'=>['compras'=>0, 'ventas'=>0],
        ];
        foreach ($aggTot as $r) {
            $m = $r['mes'];
            $ventas=(int)$r['ventas']; $compras=(int)$r['compras']; $stock=(int)$r['stock']; $tiendas=(int)$r['tiendas'];
            $totalGeneral['valores']['ventas'][$m]   = $ventas;
            $totalGeneral['valores']['compras'][$m]  = $compras;
            $totalGeneral['valores']['stock'][$m]    = $stock;
            $totalGeneral['valores']['tiendas'][$m]  = $tiendas;
            $totalGeneral['valores']['mesesInv'][$m] = $ventas > 0 ? (int)round($stock / $ventas) : 0;
            $totalGeneral['valores']['indice'][$m]   = $tiendas > 0 ? round(($ventas / $tiendas) / ($diasMes($m) / 30), 2) : 0.0;
            $totalGeneral['totales']['compras'] += $compras;
            $totalGeneral['totales']['ventas']  += $ventas;
        }

        return ['ok'=>true, 'proveedor'=>$proveedor, 'meses'=>$meses, 'mesActual'=>$mesActual,
                'rango'=>['desde'=>$desdeMes,'hasta'=>$hastaMes,'corte_ventas'=>$hastaF],
                'negocios'=>$negocios, 'totalGeneral'=>$totalGeneral];
    }
}
