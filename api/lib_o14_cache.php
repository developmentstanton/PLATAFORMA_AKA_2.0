<?php
/**
 * Cache del dataset granular denormalizado de O14 (enfoque B, mismo patrón que G00 Task 2/3).
 * Materializa el equivalente EXACTO de `#base` de informe_o14.php (CTEs s/d/h/v + llaves,
 * cia normalizada a 3 díg) más las dims de producto (#refs) y bodega (Bodegas) denormalizadas,
 * por (proveedor+rango-de-ventas) en INTEGRACION.dbo.o14_cache_base — para re-agregar/filtrar
 * en tiempo de lectura (Task 3) sin re-escanear SIESA + inv_actual_PBI + _hold_actual_PBI en
 * cada request.
 *
 * Parity: el materialize replica BYTE-A-BYTE las CTEs `s` (siembra, t400 SIESA), `d`
 * (disponible, inv_actual_PBI), `h` (hold, _hold_actual_PBI) y la unión `llaves` de
 * informe_o14.php:122-193 (ver cabecera de ese archivo para el detalle de fuentes/fórmula).
 * Dos diferencias deliberadas respecto al `#base` del endpoint (documentadas en el brief del
 * Task 2, `.superpowers/sdd/task-2-brief.md`):
 *  1. `#refs` que consume este materialize debe estar SIN podar por filtros de REF (el
 *     endpoint poda #refs por marca/tipo/.../referencia ANTES de construir #base; el cache NO
 *     — guarda el universo completo del proveedor; los filtros de lectura son responsabilidad
 *     de Task 3). Este archivo no poda #refs — asume que el caller (test / endpoint) llamó
 *     `buildRefsFromMat($conn,$proveedor)` sin DELETE posteriores sobre #refs.
 *  2. Ventas se incluye SIEMPRE (el endpoint la omite para tab=reco vía $wantVentas — el cache
 *     es tab-agnóstico, así que replicamos la CTE `v` incondicionalmente).
 * La exclusión de bodegas ADMINISTRATIVAS (DELETE separado en informe_o14.php:199-204) se
 * pliega acá en el WHERE del SELECT final: `(ISNULL(bo.GRUPO,'')<>'ADMINISTRATIVAS' OR
 * k.bodega='CEDI')` — mismo resultado neto (CEDI nunca se excluye aunque su GRUPO sea
 * 'ADMINISTRATIVAS' en algún caso raro), solo que integrado a la única sentencia de materialize
 * en vez de un DELETE posterior.
 * Las dims de producto/bodega se guardan RAW (sin ISNULL) — la normalización de nulos es
 * responsabilidad de la capa de lectura (Task 3), igual que en g00_cache_ventas/siembra.
 *
 * Este archivo requiere que `#refs` YA esté construido en la MISMA conexión ($conn) antes de
 * llamar a ensureO14CacheBase() (ver tests/verificar_o14_cache.php).
 */

if (!defined('O14_CACHE_TTL_MIN')) define('O14_CACHE_TTL_MIN', 120);

if (!function_exists('o14CacheKey')) {
    function o14CacheKey($proveedor, $desde, $hasta): string {
        return substr(md5($proveedor . '|' . $desde . '|' . $hasta), 0, 32);
    }
}

if (!function_exists('o14CacheFresco')) {
    /**
     * ¿Hay cache fresco (dentro del TTL) para esta key?
     *
     * Lectura con WITH (READPAST) — NO NOLOCK — a propósito, mismo fix de concurrencia que
     * g00CacheFresco (ver comentario extenso en lib_g00_cache.php): NOLOCK podría leer filas
     * de un rebuild EN VUELO (torn read); READPAST omite las filas con lock de fila del
     * DELETE+INSERT en curso, forzando al lector concurrente a entrar por el applock en vez de
     * ver un row-set parcial.
     */
    function o14CacheFresco($conn, $key): bool {
        $sql = "SELECT TOP 1 1 FROM INTEGRACION.dbo.o14_cache_base WITH (READPAST)
                WHERE cache_key=? AND creado > DATEADD(minute, -" . O14_CACHE_TTL_MIN . ", SYSDATETIME())";
        $st = sqlsrv_query($conn, $sql, [$key]);
        if ($st === false) return false;
        $hay = sqlsrv_fetch($st) ? true : false;
        sqlsrv_free_stmt($st);
        return $hay;
    }
}

if (!function_exists('ensureO14CacheBase')) {
    /**
     * Materializa el `#base` denormalizado (siembra/disponible/hold/ventas ⋈ #refs ⋈ Bodegas,
     * ADMIN excluido) para $key si falta o está stale (fuera de O14_CACHE_TTL_MIN).
     * $desde/$hasta acotan SOLO la ventana de ventas (siembra/disponible/hold son foto actual).
     *
     * Concurrencia: mismo patrón que ensureG00CacheVentas/ensureG00CacheSiembra —
     * sp_getapplock (@LockMode='Exclusive', @LockOwner='Transaction') sobre un recurso
     * namespaced ('o14cache:' + $key) dentro de una transacción real (sqlsrv_begin_transaction),
     * con double-checked locking tras adquirir el lock: si otro request ya materializó mientras
     * esperábamos, no se repite el trabajo, solo se hace commit (libera el lock) y se devuelve
     * true.
     */
    function ensureO14CacheBase($conn, $key, $desde, $hasta): bool {
        // Fast path: sin tocar transacción/lock si ya hay cache fresco.
        if (o14CacheFresco($conn, $key)) return true;

        if (sqlsrv_begin_transaction($conn) === false) return false;

        $lockRes = 'o14cache:' . $key;
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
        if (o14CacheFresco($conn, $key)) {
            sqlsrv_commit($conn); // libera el applock
            return true;
        }

        $del = sqlsrv_query($conn, "DELETE FROM INTEGRACION.dbo.o14_cache_base WHERE cache_key=?", [$key]);
        if ($del === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($del);

        // Ventas SIEMPRE se incluye (el cache es tab-agnóstico; el endpoint la omite solo para
        // tab=reco). inclAcum replica EXACTO informe_o14.php:131 (Detal cubre 2026+, Acum ≤2025).
        $inclAcum = ($desde <= '2025-12-31');
        $acumSql = $inclAcum ? "
      UNION ALL
      SELECT rtrim(CIA), rtrim(BODEGA), rtrim(REFERENCIA), rtrim(COLOR), rtrim(TALLA), CANTIDAD
      FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?" : "";

        // Materialize en DOS pasos (medido: BELTRANY 23s->2.4s, BRAHMA 7.5s->9.2s consistente).
        // Un único INSERT...SELECT con las 4 CTEs + #refs + Bodegas directo a la tabla persistente
        // o14_cache_base sufría un plan patológico (proveedor CHICO más lento que uno GRANDE,
        // síntoma clásico de inestabilidad de plan). Partirlo en (1) build de un temp table con
        // las medidas base (mismo patrón que ya usa el endpoint vivo con #base) y (2) INSERT al
        // cache desde el temp + dims evita el plan malo. Paridad byte-a-byte preservada: mismas
        // CTEs s/d/h/v/llaves, mismo INNER JOIN #refs, misma regla ADMIN/CEDI — solo cambia DÓNDE
        // aterriza el resultado intermedio. #o14mat es un nombre DISTINTO a #base del endpoint
        // (misma conexión no debe colisionar) y es un temp de sesión, vive bien dentro de la txn.
        $drop = sqlsrv_query($conn, "IF OBJECT_ID('tempdb..#o14mat') IS NOT NULL DROP TABLE #o14mat;");
        if ($drop === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($drop);

        $create = sqlsrv_query($conn, "CREATE TABLE #o14mat (cia varchar(10), bodega varchar(20), negocio varchar(120), referencia varchar(50), color varchar(40), talla varchar(40), siembra int, disponible int, hold int, ventas int);");
        if ($create === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($create);

        // Paso 1: CTEs s/d/h/v/llaves copiadas BYTE-A-BYTE de informe_o14.php:122-193 (INNER JOIN
        // #refs r agregado en s/d/h/v tal como en el original — poda por universo de proveedor,
        // no por filtros de UI, ya que #refs llega sin podar). WITH debe ser la primera cláusula
        // del batch (mismo gotcha T-SQL documentado en lib_g00_cache.php): INSERT va DESPUÉS del
        // WITH. Sin #refs/Bodegas/cache_key acá — solo las 10 columnas base.
        $sqlBuild = "
  WITH s AS (
    SELECT RIGHT('000'+rtrim(f400_id_cia),3) cia, rtrim(f150_id) bodega, rtrim(f120_referencia) referencia,
           rtrim(f121_id_ext1_detalle) color, rtrim(f121_id_ext2_detalle) talla, SUM(CAST(f400_cant_nivel_min_1 AS int)) q
    FROM stanton.dbo.t400_cm_existencia
     INNER JOIN stanton.dbo.t150_mc_bodegas ON f150_rowid=f400_rowid_bodega
     INNER JOIN stanton.dbo.t121_mc_items_extensiones ON f121_rowid=f400_rowid_item_ext
     INNER JOIN stanton.dbo.t120_mc_items ON f120_rowid=f121_rowid_item
     INNER JOIN #refs r ON r.REFERENCIA = rtrim(f120_referencia)
    WHERE (f400_cant_nivel_min_1>0 OR f400_cant_nivel_pedido>0) AND f120_referencia<>'GIFTCARD'
    GROUP BY RIGHT('000'+rtrim(f400_id_cia),3),rtrim(f150_id),rtrim(f120_referencia),rtrim(f121_id_ext1_detalle),rtrim(f121_id_ext2_detalle)
  ),
  d AS (
    SELECT RIGHT('000'+rtrim(v.cia),3) cia, rtrim(v.bodega) bodega, rtrim(v.referencia) referencia,
           rtrim(v.color) color, rtrim(v.talla) talla, SUM(CAST(v.cantidad AS int)) q
    FROM INTEGRACION.dbo.inv_actual_PBI v WITH (NOLOCK)
     INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
    WHERE v.cia<>'001' AND v.COLUMNA1 IN ('INV1430','INV1435','400')
    GROUP BY RIGHT('000'+rtrim(v.cia),3),rtrim(v.bodega),rtrim(v.referencia),rtrim(v.color),rtrim(v.talla)
  ),
  h AS (
    SELECT RIGHT('000'+rtrim(v.cia),3) cia, rtrim(v.bodega_ent) bodega, rtrim(v.referencia) referencia,
           rtrim(v.color) color, rtrim(v.talla) talla, SUM(CAST(v.cantidad AS int)) q
    FROM INTEGRACION.dbo._hold_actual_PBI v WITH (NOLOCK)
     INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
    WHERE v.cia<>'001'
    GROUP BY RIGHT('000'+rtrim(v.cia),3),rtrim(v.bodega_ent),rtrim(v.referencia),rtrim(v.color),rtrim(v.talla)
  ),
  v AS (
    SELECT vv.cia, vv.bodega, vv.referencia, vv.color, vv.talla, SUM(CAST(vv.cantidad AS int)) q
    FROM (
      SELECT RIGHT('000'+rtrim(CIA),3) cia, rtrim(BODEGA) bodega, rtrim(REFERENCIA) referencia, rtrim(COLOR) color, rtrim(TALLA) talla, CANTIDAD cantidad
      FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?$acumSql
    ) vv INNER JOIN #refs r ON r.REFERENCIA = vv.referencia
    GROUP BY vv.cia, vv.bodega, vv.referencia, vv.color, vv.talla
  ),
  llaves AS (
    SELECT cia,bodega,referencia,color,talla FROM s
    UNION SELECT cia,bodega,referencia,color,talla FROM d
    UNION SELECT cia,bodega,referencia,color,talla FROM h
    UNION SELECT cia,bodega,referencia,color,talla FROM v
  )
  INSERT INTO #o14mat (cia,bodega,negocio,referencia,color,talla,siembra,disponible,hold,ventas)
  SELECT k.cia, k.bodega, k.referencia+'-'+k.color, k.referencia, k.color, k.talla,
         CAST(ISNULL(s.q,0) AS int), CAST(ISNULL(d.q,0) AS int), CAST(ISNULL(h.q,0) AS int), CAST(ISNULL(v.q,0) AS int)
  FROM llaves k
   LEFT  JOIN s ON s.cia=k.cia AND s.bodega=k.bodega AND s.referencia=k.referencia AND s.color=k.color AND s.talla=k.talla
   LEFT  JOIN d ON d.cia=k.cia AND d.bodega=k.bodega AND d.referencia=k.referencia AND d.color=k.color AND d.talla=k.talla
   LEFT  JOIN h ON h.cia=k.cia AND h.bodega=k.bodega AND h.referencia=k.referencia AND h.color=k.color AND h.talla=k.talla
   LEFT  JOIN v ON v.cia=k.cia AND v.bodega=k.bodega AND v.referencia=k.referencia AND v.color=k.color AND v.talla=k.talla";

        // Orden de params: los `?` de la CTE `v` (PBI desde,hasta [+ Acum desde,hasta si
        // $inclAcum]) — mismo orden que $pVentas en informe_o14.php:150. Sin cache_key acá.
        $paramsBuild = $inclAcum ? [$desde, $hasta, $desde, $hasta] : [$desde, $hasta];

        $build = sqlsrv_query($conn, $sqlBuild, $paramsBuild);
        if ($build === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($build);

        // Paso 2: INSERT al cache desde el temp + dims de producto (#refs) y bodega (Bodegas).
        // Misma regla ADMIN/CEDI que antes (documentada en la cabecera del archivo), mismo
        // INNER JOIN #refs (poda por universo de proveedor), dims guardadas RAW.
        $sqlInsert = "
  INSERT INTO INTEGRACION.dbo.o14_cache_base
    (cache_key,cia,bodega,negocio,referencia,color,talla,siembra,disponible,hold,ventas,
     marca,tipo,categoria,subcategoria,genero,publico_objetivo,
     grupo,nombre,centro_comercial,depto,ciudad)
  SELECT ?, b.cia,b.bodega,b.negocio,b.referencia,b.color,b.talla,b.siembra,b.disponible,b.hold,b.ventas,
         r.MARCA,r.TIPO,r.CATEGORIA,r.SUBCATEGORIA,r.GENERO,r.PUBLICO_OBJETIVO,
         bo.GRUPO,bo.NOMBRE,bo.CENTRO_COMERCIAL,bo.DEPTO,bo.CIUDAD
  FROM #o14mat b
   INNER JOIN #refs r ON r.REFERENCIA = b.referencia
   LEFT  JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD = b.bodega AND RIGHT('000'+rtrim(bo.CIA),3) = b.cia
  WHERE (ISNULL(bo.GRUPO,'') <> 'ADMINISTRATIVAS' OR b.bodega = 'CEDI')";

        $ins = sqlsrv_query($conn, $sqlInsert, [$key]);
        if ($ins === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($ins);

        $dropEnd = sqlsrv_query($conn, "IF OBJECT_ID('tempdb..#o14mat') IS NOT NULL DROP TABLE #o14mat;");
        if ($dropEnd === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($dropEnd);

        return sqlsrv_commit($conn); // libera el applock
    }
}

if (!function_exists('o14CacheCleanup')) {
    // Borra filas de cache más viejas que el TTL. Llamar periódicamente (no en cada ensure) para
    // no acumular basura de keys viejas ni ampliar el blast-radius del lock de cada materialize.
    function o14CacheCleanup($conn): void {
        $st = sqlsrv_query($conn, "DELETE FROM INTEGRACION.dbo.o14_cache_base WHERE creado < DATEADD(minute, -" . O14_CACHE_TTL_MIN . ", SYSDATETIME())");
        if ($st !== false) sqlsrv_free_stmt($st);
    }
}
