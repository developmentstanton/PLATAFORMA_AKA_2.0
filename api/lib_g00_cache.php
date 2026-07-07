<?php
/**
 * Cache del dataset granular de G00 (enfoque B). Materializa ventas denormalizadas
 * (2 años ⋈ #refs ⋈ Bodegas) por (proveedor+período) en INTEGRACION.dbo.g00_cache_ventas,
 * para re-agregar filtros sin re-escanear las fact tables. Solo INTEGRACION (no SIESA).
 *
 * Parity: el materialize replica EXACTAMENTE la fuente de filas del consolidado de
 * informe_g00.php ($sqlConsolidado, ~L672-690): `ventas v INNER JOIN #refs i ON
 * i.REFERENCIA = v.REFERENCIA LEFT JOIN Bodegas b ON b.COD = v.BODEGA AND b.CIA = 7`,
 * SIN rtrim() (las columnas fuente ya vienen sin padding) y SIN normalización ISNULL
 * sobre columnas de Bodegas (esa normalización — ISNULL(GRUPO,'SIN GRUPO'), etc. — es
 * responsabilidad de la capa de agregación que consume el cache, igual que hoy).
 * `#refs` (buildRefsFromMat) ya sale ISNULL-normalizado ('SIN MARCA'/'SIN TIPO'/... o ''),
 * así que copiar i.MARCA/i.TIPO/... tal cual es correcto.
 *
 * Este archivo requiere que `#refs` YA esté construido en la MISMA conexión ($conn) antes
 * de llamar a ensureG00CacheVentas() (ver tests/verificar_g00_cache.php).
 */

if (!defined('G00_CACHE_TTL_MIN')) define('G00_CACHE_TTL_MIN', 120);

if (!function_exists('g00CacheKey')) {
    function g00CacheKey($proveedor, $anioA, $anioB, $desde, $hasta): string {
        return substr(sha1($proveedor . '|' . $anioA . '|' . $anioB . '|' . $desde . '|' . $hasta), 0, 32);
    }
}

if (!function_exists('g00CteVentasCache')) {
    /**
     * Copia literal de cteVentas() (informe_g00.php:83-95). Duplicada aquí a propósito:
     * NO se puede `require informe_g00.php` porque ese script tiene efectos de lado
     * (session_start, chequeo de auth con exit, header JSON) que rompen cualquier otro
     * contexto que lo incluya. Si cteVentas() cambia en informe_g00.php, replicar el
     * cambio acá — están linkeadas solo por contrato/comentario, no por código compartido.
     */
    function g00CteVentasCache(): string {
        return "
        WITH ventas AS (
            SELECT FECHA, BODEGA, REFERENCIA, CANTIDAD, VALOR, MARGEN, COLOR, TALLA
            FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK)
            WHERE FECHA BETWEEN ? AND ?
            UNION ALL
            SELECT FECHA, BODEGA, REFERENCIA, CANTIDAD, VALOR, MARGEN, COLOR, TALLA
            FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK)
            WHERE FECHA BETWEEN ? AND ?
        )
        ";
    }
}

if (!function_exists('g00CacheFresco')) {
    // ¿Hay cache fresco (dentro del TTL) para esta key? Lectura NOLOCK, sin transacción.
    function g00CacheFresco($conn, $tabla, $key): bool {
        $sql = "SELECT TOP 1 1 FROM INTEGRACION.dbo.$tabla WITH (NOLOCK)
                WHERE cache_key=? AND creado > DATEADD(minute, -" . G00_CACHE_TTL_MIN . ", SYSDATETIME())";
        $st = sqlsrv_query($conn, $sql, [$key]);
        if ($st === false) return false;
        $hay = sqlsrv_fetch($st) ? true : false;
        sqlsrv_free_stmt($st);
        return $hay;
    }
}

if (!function_exists('ensureG00CacheVentas')) {
    /**
     * Materializa el granular denormalizado (ventas ⋈ #refs ⋈ Bodegas) para $key si falta
     * o está stale (fuera de G00_CACHE_TTL_MIN). $desde2/$hasta deben cubrir los 2 años
     * (año A + año B) que consumirán las pestañas.
     *
     * Concurrencia: dos requests concurrentes para la MISMA $key que ambos ven "sin cache
     * fresco" ejecutarían DELETE+INSERT en paralelo -> filas duplicadas -> agregados
     * inflados. Se serializa con sp_getapplock (@LockMode='Exclusive', @LockOwner=
     * 'Transaction') sobre un recurso derivado de $key, dentro de una transacción real
     * (sqlsrv_begin_transaction). Tras adquirir el lock se re-chequea frescura
     * (double-checked locking): si otro request ya materializó mientras esperábamos el
     * lock, no se repite el trabajo, solo se hace commit (libera el lock) y se devuelve
     * true.
     */
    function ensureG00CacheVentas($conn, $key, $desde2, $hasta): bool {
        // Fast path: sin tocar transacción/lock si ya hay cache fresco.
        if (g00CacheFresco($conn, 'g00_cache_ventas', $key)) return true;

        if (sqlsrv_begin_transaction($conn) === false) return false;

        $lockRes = 'g00cache_ventas:' . $key; // namespace propio para no chocar con siembra (Task 3)
        $lockSql = "DECLARE @res int;
                     EXEC @res = sp_getapplock @Resource = ?, @LockMode = 'Exclusive',
                          @LockOwner = 'Transaction', @LockTimeout = 30000;
                     SELECT @res AS res;";
        $lockSt = sqlsrv_query($conn, $lockSql, [$lockRes]);
        if ($lockSt === false) { sqlsrv_rollback($conn); return false; }
        $lockRow = sqlsrv_fetch_array($lockSt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($lockSt);
        $lockCode = $lockRow['res'] ?? -999;
        if ($lockCode < 0) { // -1 timeout, -2 cancelado, -3 deadlock, -999 sin resultado
            sqlsrv_rollback($conn);
            return false;
        }

        // Double-check: otro request pudo haber materializado mientras esperábamos el lock.
        if (g00CacheFresco($conn, 'g00_cache_ventas', $key)) {
            sqlsrv_commit($conn); // libera el applock
            return true;
        }

        $del = sqlsrv_query($conn, "DELETE FROM INTEGRACION.dbo.g00_cache_ventas WHERE cache_key=?", [$key]);
        if ($del === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($del);

        // Fuente de filas idéntica al consolidado de informe_g00.php (ver comentario de cabecera):
        // ventas v INNER JOIN #refs i ON i.REFERENCIA=v.REFERENCIA LEFT JOIN Bodegas b
        // ON b.COD=v.BODEGA AND b.CIA=7. Sin rtrim, sin ISNULL sobre columnas de Bodegas.
        // OJO T-SQL: el WITH (CTE) debe ser la PRIMERA cláusula del batch; INSERT INTO va
        // DESPUÉS del WITH, no antes (si no: error 156/319 "Incorrect syntax near WITH").
        $sql = g00CteVentasCache() .
                "INSERT INTO INTEGRACION.dbo.g00_cache_ventas
                  (cache_key,FECHA,anio,mes,dia,BODEGA,REFERENCIA,COLOR,TALLA,
                   MARCA,TIPO,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO,
                   GRUPO,NOMBRE,CENTRO_COMERCIAL,DEPTO,CIUDAD,CANTIDAD,VALOR,MARGEN)
                SELECT ?, v.FECHA, YEAR(v.FECHA), MONTH(v.FECHA), DAY(v.FECHA),
                       v.BODEGA, v.REFERENCIA, v.COLOR, v.TALLA,
                       i.MARCA, i.TIPO, i.CATEGORIA, i.SUBCATEGORIA, i.GENERO, i.PUBLICO_OBJETIVO,
                       b.GRUPO, b.NOMBRE, b.CENTRO_COMERCIAL, b.DEPTO, b.CIUDAD,
                       v.CANTIDAD, v.VALOR, v.MARGEN
                FROM ventas v
                INNER JOIN #refs i                                 ON i.REFERENCIA = v.REFERENCIA
                LEFT  JOIN INTEGRACION.dbo.Bodegas b WITH (NOLOCK) ON b.COD        = v.BODEGA AND b.CIA = 7";
        // Orden de params: los 4 `?` de g00CteVentasCache() (PBI desde,hasta; Acum desde,hasta)
        // van PRIMERO (posicional dentro del batch), luego el `?` del SELECT (cache_key).
        $ins = sqlsrv_query($conn, $sql, [$desde2, $hasta, $desde2, $hasta, $key]);
        if ($ins === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($ins);

        return sqlsrv_commit($conn); // libera el applock
    }
}

if (!function_exists('g00CacheCleanup')) {
    // Borra filas de cache (ventas + siembra) más viejas que el TTL. Llamar periódicamente
    // (p.ej. al inicio de un materialize) para no acumular basura de keys viejas.
    function g00CacheCleanup($conn): void {
        foreach (['g00_cache_ventas', 'g00_cache_siembra'] as $t) {
            $st = sqlsrv_query($conn, "DELETE FROM INTEGRACION.dbo.$t WHERE creado < DATEADD(minute, -" . G00_CACHE_TTL_MIN . ", SYSDATETIME())");
            if ($st !== false) sqlsrv_free_stmt($st);
        }
    }
}
