<?php
/** o45 cache en disco: builder del payload tab=dataset + frescura por stamp de fuente. */
require_once __DIR__ . '/lib_disk_cache.php';
require_once __DIR__ . '/lib_o45_dataset.php';
require_once __DIR__ . '/lib_precios.php';

if (!defined('O45_DISK_TTL_MIN')) define('O45_DISK_TTL_MIN', 1500); // ~25h: el archivo del dia sobrevive al proximo ETL

if (!function_exists('o45CacheKey')) {
    function o45CacheKey($proveedor, $desde, $hasta): string { return substr(md5($proveedor.'|'.$desde.'|'.$hasta),0,32); }

    // Stamp GLOBAL de fuente: avanza cuando el ETL nocturno carga inv_actual/Ventas_Detal.
    // ISNULL para que nunca sea NULL por una fuente vacia (si la query falla -> null -> rebuild).
    function o45CurrentStamp($conn): ?string {
        $sql = "SELECT ISNULL(CONVERT(varchar(19),(SELECT MAX(FECHA) FROM INTEGRACION.dbo.inv_actual_PBI  WITH (NOLOCK)),120),'') + '|'
                     + ISNULL(CONVERT(varchar(19),(SELECT MAX(FECHA) FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK)),120),'') s";
        $st = sqlsrv_query($conn, $sql);
        if ($st === false) return null;
        $r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($st);
        return $r ? (string)$r['s'] : null;
    }

    function o45DiskFresh($conn, string $key): bool { return diskCacheFresh('o45', $key, o45CurrentStamp($conn)); }
    function o45ReadPayload(string $key): ?string { return diskCacheRead('o45', $key); }
    function o45WritePayload(string $key, string $json, string $stamp): bool { return diskCacheWrite('o45', $key, $json, $stamp); }
    function o45Cleanup(): void { diskCacheCleanup('o45', O45_DISK_TTL_MIN); }
    function o45ServeGz(string $gz): void { diskCacheServeGz($gz); }

    function o45BuildPayload($conn, string $proveedor, string $desde, string $hasta): array {
        // asume #refs ya construido (el endpoint lo hace en linea 41; el prebuild debe hacerlo antes).
        $ds = buildO45Dataset($conn, $desde, $hasta);
        if (!empty($ds['error'])) return ['ok'=>false, 'error'=>'Consulta fallida', 'detalle'=>$ds['error']];
        $precios = preciosPorRefs($conn);
        // VERBATIM de informe_o45.php:49-52:
        $columnas = ['cia','bodega','grupo','tienda','es_cedi','referencia','color','talla',
                     'marca','tipo','categoria','subcategoria','genero','publico',
                     'disponible','hold','ventas','ventas30','inv_hist'];
        $filas = array_map(fn($r) => array_map(fn($c) => $r[$c], $columnas), $ds['rows']);
        return ['ok'=>true, 'tab'=>'dataset', 'proveedor'=>$proveedor,
                'columnas'=>$columnas, 'filas'=>$filas, 'precios'=>$precios, 'rango'=>$ds['meta']];
    }
}
