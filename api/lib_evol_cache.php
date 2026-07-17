<?php
/**
 * Cache del dataset granular denormalizado de EVOL (enfoque B, mismo patrón que O14/G00 Task 2/3).
 * Materializa el equivalente EXACTO de `#base` de informe_evol.php (fuentes ventas/compras/stock
 * por corte + stock vivo del mes actual, con DELETE ADMIN) más las dims de producto (#refs) y
 * bodega (Bodegas) denormalizadas, por (proveedor+rango-de-meses) en
 * INTEGRACION.dbo.evol_cache_base — para re-agregar/filtrar en tiempo de lectura (Task 3) sin
 * re-escanear SIESA/Ventas_Detal/mov_inv/historico_* en cada request.
 *
 * Parity: el materialize replica BYTE-A-BYTE la derivación de rango/cortes y el build de #base
 * de informe_evol.php:17-153 (ver cabecera de ese archivo para el detalle de fuentes/fórmula).
 * Una diferencia deliberada respecto al `#base` del endpoint (documentada en el brief del Task 2,
 * `.superpowers/sdd/task-2-brief.md`): `#refs` que consume este materialize debe estar SIN podar
 * por filtros de dimensión (el endpoint poda #refs por marca/tipo/.../referencia ANTES de
 * construir #base cuando tab=data; el cache NO — guarda el universo completo del proveedor; los
 * filtros de lectura son responsabilidad de Task 3). Este archivo no poda #refs — asume que el
 * caller (test / endpoint) llamó buildRefsFromMat($conn,$proveedor) sin DELETE posteriores sobre
 * #refs.
 * Las dims de producto/bodega se guardan RAW (sin ISNULL) — la normalización de nulos es
 * responsabilidad de la capa de lectura (Task 3), igual que en o14_cache_base/g00_cache_*.
 *
 * Este archivo requiere que `#refs` YA esté construido en la MISMA conexión ($conn) antes de
 * llamar a ensureEvolCacheBase() (ver tests/verificar_evol_cache.php).
 */

if (!defined('EVOL_CACHE_TTL_MIN')) define('EVOL_CACHE_TTL_MIN', 120);

if (!function_exists('evolCacheKey')) {
    function evolCacheKey($proveedor, $desdeMes, $hastaMes): string {
        return substr(md5($proveedor . '|' . $desdeMes . '|' . $hastaMes), 0, 32);
    }
}

if (!function_exists('evolCacheFresco')) {
    /**
     * ¿Hay cache fresco (dentro del TTL) para esta key?
     *
     * Lectura con WITH (READPAST) — NO NOLOCK — a propósito, mismo fix de concurrencia que
     * o14CacheFresco/g00CacheFresco: NOLOCK podría leer filas de un rebuild EN VUELO (torn read);
     * READPAST omite las filas con lock de fila del DELETE+INSERT en curso, forzando al lector
     * concurrente a entrar por el applock en vez de ver un row-set parcial.
     */
    function evolCacheFresco($conn, $key): bool {
        $sql = "SELECT TOP 1 1 FROM INTEGRACION.dbo.evol_cache_base WITH (READPAST)
                WHERE cache_key=? AND creado > DATEADD(minute, -" . EVOL_CACHE_TTL_MIN . ", SYSDATETIME())";
        $st = sqlsrv_query($conn, $sql, [$key]);
        if ($st === false) return false;
        $hay = sqlsrv_fetch($st) ? true : false;
        sqlsrv_free_stmt($st);
        return $hay;
    }
}

if (!function_exists('ensureEvolCacheBase')) {
    /**
     * Materializa el `#base` denormalizado (ventas/compras/stock ⋈ #refs ⋈ Bodegas, ADMIN
     * excluido) para $key si falta o está stale (fuera de EVOL_CACHE_TTL_MIN).
     * $desdeMes/$hastaMes son 'YYYY-MM' — mismo contrato que informe_evol.php ($_GET['desde']/
     * ['hasta']).
     *
     * $force (por defecto false): si es true, saltea el fast-path de frescura pre-lock para que
     * la base se reconstruya aunque su TTL no haya vencido (útil tras cambios de fuente). En el
     * path $force=false, el double-check post-lock es INCONDICIONAL — otro request que haya
     * materializado mientras esperábamos el lock SIEMPRE será reusado, evitando pases redundantes.
     * Callers que pasen $force=true deben serializar por clave externamente (ej. flock del
     * endpoint) para evitar rebuilds redundantes entre llamadas concurrentes; la corrección está
     * garantizada por el sp_getapplock exclusivo de todas formas.
     *
     * Concurrencia: mismo patrón que ensureO14CacheBase/ensureG00Cache* — sp_getapplock
     * (@LockMode='Exclusive', @LockOwner='Transaction') sobre un recurso namespaced
     * ('evolcache:' + $key) dentro de una transacción real (sqlsrv_begin_transaction), con
     * double-checked locking tras adquirir el lock: si otro request ya materializó mientras
     * esperábamos, no se repite el trabajo, solo se hace commit (libera el lock) y se devuelve
     * true.
     */
    function ensureEvolCacheBase($conn, $key, $desdeMes, $hastaMes, bool $force=false): bool {
        // Fast path: sin tocar transacción/lock si ya hay cache fresco. Con $force (miss de disco
        // por cambio de fuente) se salta el fast-path para que la base coincida con la fuente nueva,
        // aunque su TTL de 120 min no haya vencido.
        if (!$force && evolCacheFresco($conn, $key)) return true;

        if (sqlsrv_begin_transaction($conn) === false) return false;

        $lockRes = 'evolcache:' . $key;
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

        // Con $force, borrar el cache ANTES del double-check para forzar rebuild incluso si está fresco
        // (otro request que materialize después será detectado por el double-check).
        if ($force) {
            $del = sqlsrv_query($conn, "DELETE FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?", [$key]);
            if ($del === false) { sqlsrv_rollback($conn); return false; }
            sqlsrv_free_stmt($del);
        }

        // Double-check: otro request pudo haber materializado mientras esperábamos el lock.
        if (evolCacheFresco($conn, $key)) {
            sqlsrv_commit($conn); // libera el applock
            return true;
        }

        // Si no force, borrar ahora (si force, ya se borró arriba antes del double-check).
        if (!$force) {
            $del = sqlsrv_query($conn, "DELETE FROM INTEGRACION.dbo.evol_cache_base WHERE cache_key=?", [$key]);
            if ($del === false) { sqlsrv_rollback($conn); return false; }
            sqlsrv_free_stmt($del);
        }

        // === Derivación de rango/cortes: COPIA VERBATIM de informe_evol.php:17-37 (sin el parseo
        // de $_GET — $desdeMes/$hastaMes ya llegan como 'YYYY-MM' desde el caller). ===
        $mesActual = date('Y-m');
        if ($hastaMes > $mesActual) $hastaMes = $mesActual;
        if ($desdeMes > $hastaMes) $desdeMes = $hastaMes;
        $meses = [];
        for ($c = $desdeMes.'-01'; $c <= $hastaMes.'-01'; $c = date('Y-m-01', strtotime($c.' +1 month'))) $meses[] = substr($c,0,7);

        // Cortes fin-de-mes para meses pasados (el mes en curso usa snapshot vivo).
        $cortes = [];  // ['YYYY-MM' => 'YYYY-MM-DD']
        foreach ($meses as $m) if ($m !== $mesActual) $cortes[$m] = date('Y-m-t', strtotime($m.'-01'));
        $cortesVals = array_values($cortes);
        $incluyeMesActual = in_array($mesActual, $meses, true);

        // Ventana de fechas para ventas/compras: del 1er día del primer mes a AYER (corte, igual
        // que O45), pero sin pasar del fin del último mes del rango.
        $ayer   = date('Y-m-d', strtotime('-1 day'));
        $desdeF = $desdeMes.'-01';
        $hastaF = date('Y-m-t', strtotime($hastaMes.'-01'));
        if ($hastaF > $ayer) $hastaF = $ayer;

        // Materialize en DOS pasos (mismo patrón que O14/G00): (1) build de un temp table con las
        // medidas base (mismo patrón que ya usa el endpoint vivo con #base, con las mismas fuentes
        // e INNER JOIN #refs) y (2) INSERT al cache desde el temp + dims. #evolmat es un nombre
        // DISTINTO a #base del endpoint (misma conexión no debe colisionar) y es un temp de
        // sesión, vive bien dentro de la txn.
        $drop = sqlsrv_query($conn, "IF OBJECT_ID('tempdb..#evolmat') IS NOT NULL DROP TABLE #evolmat;");
        if ($drop === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($drop);

        $create = sqlsrv_query($conn, "CREATE TABLE #evolmat (negocio varchar(120), mes char(7), cia varchar(10),
            bodega varchar(20), referencia varchar(50), color varchar(40), ventas int, compras int, stock int)");
        if ($create === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($create);

        // --- Ventas (Detal 2026 + Acum <=2025), por negocio×mes×cía×bodega. Copia EXACTA de
        // informe_evol.php:71-84, target #evolmat. ---
        $acumV = ($desdeF <= '2025-12-31')
          ? "UNION ALL SELECT rtrim(REFERENCIA)+'-'+rtrim(COLOR), CONVERT(varchar(7),FECHA,120), RIGHT('000'+rtrim(CIA),3), rtrim(BODEGA), rtrim(REFERENCIA), rtrim(COLOR), CAST(CANTIDAD AS int)
               FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?" : "";
        $insV = "INSERT INTO #evolmat (negocio,mes,cia,bodega,referencia,color,ventas,compras,stock)
          SELECT vs.negocio, vs.mes, vs.cia, vs.bodega, vs.referencia, vs.color, SUM(vs.q), 0, 0
          FROM (
            SELECT rtrim(REFERENCIA)+'-'+rtrim(COLOR) negocio, CONVERT(varchar(7),FECHA,120) mes,
                   RIGHT('000'+rtrim(CIA),3) cia, rtrim(BODEGA) bodega, rtrim(REFERENCIA) referencia, rtrim(COLOR) color, CAST(CANTIDAD AS int) q
            FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?
            $acumV
          ) vs INNER JOIN #refs r ON r.REFERENCIA = vs.referencia
          GROUP BY vs.negocio, vs.mes, vs.cia, vs.bodega, vs.referencia, vs.color";
        $pV = [$desdeF,$hastaF]; if ($acumV!=='') array_push($pV,$desdeF,$hastaF);
        $rv = sqlsrv_query($conn, $insV, $pV);
        if ($rv === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($rv);

        // --- Compras (mov_inv_actual año actual + historico_mov_inv <=2025), TIPO_DOCTO de
        // compra, cia<>052. Copia EXACTA de informe_evol.php:87-102, target #evolmat. ---
        $compFilt = "TIPO_DOCTO IN ('DMC','DVC','EAC','EMC') AND cia<>'052' AND FECHA BETWEEN ? AND ?";
        $srcActual = ($hastaF >= date('Y').'-01-01')
          ? "SELECT rtrim(REFERENCIA)+'-'+rtrim(COLOR) negocio, CONVERT(varchar(7),FECHA,120) mes, RIGHT('000'+rtrim(CIA),3) cia, rtrim(BODEGA) bodega, rtrim(REFERENCIA) referencia, rtrim(COLOR) color, CAST(CANT_NET AS int) q
               FROM INTEGRACION.dbo.mov_inv_actual_PBI WITH (NOLOCK) WHERE $compFilt" : "";
        $srcHist = ($desdeF <= (date('Y')-1).'-12-31')
          ? "SELECT rtrim(REFERENCIA)+'-'+rtrim(COLOR), CONVERT(varchar(7),FECHA,120), RIGHT('000'+rtrim(CIA),3), rtrim(BODEGA), rtrim(REFERENCIA), rtrim(COLOR), CAST(CANT_NET AS int)
               FROM INTEGRACION.dbo.historico_mov_inv_PBI WITH (NOLOCK) WHERE $compFilt" : "";
        $compSrc = trim($srcActual . (($srcActual && $srcHist) ? "\n    UNION ALL " : "") . $srcHist);
        if ($compSrc !== '') {
            $insC = "INSERT INTO #evolmat (negocio,mes,cia,bodega,referencia,color,ventas,compras,stock)
              SELECT cs.negocio, cs.mes, cs.cia, cs.bodega, cs.referencia, cs.color, 0, SUM(cs.q), 0
              FROM ( $compSrc ) cs INNER JOIN #refs r ON r.REFERENCIA = cs.referencia
              GROUP BY cs.negocio, cs.mes, cs.cia, cs.bodega, cs.referencia, cs.color";
            // Orden de params $pC: srcActual(desde,hasta) [si aplica] ; srcHist(desde,hasta) [si aplica].
            $pC = []; if ($srcActual) array_push($pC,$desdeF,$hastaF); if ($srcHist) array_push($pC,$desdeF,$hastaF);
            $rc = sqlsrv_query($conn, $insC, $pC);
            if ($rc === false) { sqlsrv_rollback($conn); return false; }
            sqlsrv_free_stmt($rc);
        }

        // --- Stock por cortes fin-de-mes (disp + hold), filtros idénticos a O45. Copia EXACTA de
        // informe_evol.php:106-126, target #evolmat. Hold usa BODEGA_SAL (no BODEGA). ---
        if ($cortesVals) {
            $ph = implode(',', array_fill(0, count($cortesVals), '?'));
            // disponible
            $rsd = sqlsrv_query($conn, "INSERT INTO #evolmat (negocio,mes,cia,bodega,referencia,color,ventas,compras,stock)
              SELECT rtrim(hi.REFERENCIA)+'-'+rtrim(hi.COLOR), CONVERT(varchar(7),hi.FECHA,120), RIGHT('000'+rtrim(hi.CIA),3),
                     rtrim(hi.BODEGA), rtrim(hi.REFERENCIA), rtrim(hi.COLOR), 0, 0, SUM(CAST(hi.CANTIDAD AS int))
              FROM INTEGRACION.dbo.historico_inventarios_PBI hi WITH (NOLOCK)
               INNER JOIN #refs r ON r.REFERENCIA = rtrim(hi.REFERENCIA)
              WHERE hi.FECHA IN ($ph) AND hi.CIA<>'001' AND rtrim(hi.COLUMNA1) IN ('INV1430','INV1435','400')
              GROUP BY rtrim(hi.REFERENCIA)+'-'+rtrim(hi.COLOR), CONVERT(varchar(7),hi.FECHA,120), RIGHT('000'+rtrim(hi.CIA),3), rtrim(hi.BODEGA), rtrim(hi.REFERENCIA), rtrim(hi.COLOR)", $cortesVals);
            if ($rsd === false) { sqlsrv_rollback($conn); return false; }
            sqlsrv_free_stmt($rsd);
            // hold (bodega = BODEGA_SAL, igual que O45)
            $rsh = sqlsrv_query($conn, "INSERT INTO #evolmat (negocio,mes,cia,bodega,referencia,color,ventas,compras,stock)
              SELECT rtrim(hh.REFERENCIA)+'-'+rtrim(hh.COLOR), CONVERT(varchar(7),hh.FECHA,120), RIGHT('000'+rtrim(hh.CIA),3),
                     rtrim(hh.BODEGA_SAL), rtrim(hh.REFERENCIA), rtrim(hh.COLOR), 0, 0, SUM(CAST(hh.CANTIDAD AS int))
              FROM INTEGRACION.dbo.historico_hold_PBI hh WITH (NOLOCK)
               INNER JOIN #refs r ON r.REFERENCIA = rtrim(hh.REFERENCIA)
              WHERE hh.FECHA IN ($ph) AND hh.CIA<>'001'
              GROUP BY rtrim(hh.REFERENCIA)+'-'+rtrim(hh.COLOR), CONVERT(varchar(7),hh.FECHA,120), RIGHT('000'+rtrim(hh.CIA),3), rtrim(hh.BODEGA_SAL), rtrim(hh.REFERENCIA), rtrim(hh.COLOR)", $cortesVals);
            if ($rsh === false) { sqlsrv_rollback($conn); return false; }
            sqlsrv_free_stmt($rsh);
        }

        // --- Stock vivo para el mes en curso (si está en el rango). Copia EXACTA de
        // informe_evol.php:128-145, target #evolmat. Hold usa v.bodega_sal (no v.bodega). ---
        if ($incluyeMesActual) {
            $rvd = sqlsrv_query($conn, "INSERT INTO #evolmat (negocio,mes,cia,bodega,referencia,color,ventas,compras,stock)
              SELECT rtrim(v.referencia)+'-'+rtrim(v.color), '$mesActual', RIGHT('000'+rtrim(v.cia),3),
                     rtrim(v.bodega), rtrim(v.referencia), rtrim(v.color), 0, 0, SUM(CAST(v.cantidad AS int))
              FROM INTEGRACION.dbo.inv_actual_PBI v WITH (NOLOCK)
               INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
              WHERE v.cia<>'001' AND v.COLUMNA1 IN ('INV1430','INV1435','400')
              GROUP BY rtrim(v.referencia)+'-'+rtrim(v.color), RIGHT('000'+rtrim(v.cia),3), rtrim(v.bodega), rtrim(v.referencia), rtrim(v.color)");
            if ($rvd === false) { sqlsrv_rollback($conn); return false; }
            sqlsrv_free_stmt($rvd);
            $rvh = sqlsrv_query($conn, "INSERT INTO #evolmat (negocio,mes,cia,bodega,referencia,color,ventas,compras,stock)
              SELECT rtrim(v.referencia)+'-'+rtrim(v.color), '$mesActual', RIGHT('000'+rtrim(v.cia),3),
                     rtrim(v.bodega_sal), rtrim(v.referencia), rtrim(v.color), 0, 0, SUM(CAST(v.cantidad AS int))
              FROM INTEGRACION.dbo._hold_actual_PBI v WITH (NOLOCK)
               INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
              WHERE v.cia<>'001'
              GROUP BY rtrim(v.referencia)+'-'+rtrim(v.color), RIGHT('000'+rtrim(v.cia),3), rtrim(v.bodega_sal), rtrim(v.referencia), rtrim(v.color)");
            if ($rvh === false) { sqlsrv_rollback($conn); return false; }
            sqlsrv_free_stmt($rvh);
        }

        // Excluir bodegas ADMINISTRATIVAS (no son tiendas), conservando CEDI. Copia EXACTA de
        // informe_evol.php:148-153, target #evolmat.
        $delAdmin = sqlsrv_query($conn, "
          DELETE b FROM #evolmat AS b
          INNER JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK)
            ON rtrim(bo.COD)=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia
          WHERE rtrim(bo.GRUPO)='ADMINISTRATIVAS' AND b.bodega<>'CEDI'");
        if ($delAdmin === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($delAdmin);

        // Paso 2: INSERT al cache desde el temp + dims de producto (#refs) y bodega (Bodegas).
        // #refs llega SIN podar (universo del proveedor completo); dims guardadas RAW.
        $sqlInsert = "
          INSERT INTO INTEGRACION.dbo.evol_cache_base
            (cache_key,negocio,mes,cia,bodega,referencia,color,ventas,compras,stock,
             marca,tipo,categoria,subcategoria,genero,publico_objetivo,grupo,nombre)
          SELECT ?, b.negocio,b.mes,b.cia,b.bodega,b.referencia,b.color,b.ventas,b.compras,b.stock,
                 r.MARCA,r.TIPO,r.CATEGORIA,r.SUBCATEGORIA,r.GENERO,r.PUBLICO_OBJETIVO, bo.GRUPO,bo.NOMBRE
          FROM #evolmat b
           INNER JOIN #refs r ON r.REFERENCIA = b.referencia
           LEFT  JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD = b.bodega AND RIGHT('000'+rtrim(bo.CIA),3) = b.cia";

        $ins = sqlsrv_query($conn, $sqlInsert, [$key]);
        if ($ins === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($ins);

        $dropEnd = sqlsrv_query($conn, "IF OBJECT_ID('tempdb..#evolmat') IS NOT NULL DROP TABLE #evolmat;");
        if ($dropEnd === false) { sqlsrv_rollback($conn); return false; }
        sqlsrv_free_stmt($dropEnd);

        return sqlsrv_commit($conn); // libera el applock
    }
}

if (!function_exists('evolCacheCleanup')) {
    // Borra filas de cache más viejas que el TTL. Llamar periódicamente (no en cada ensure) para
    // no acumular basura de keys viejas ni ampliar el blast-radius del lock de cada materialize.
    function evolCacheCleanup($conn): void {
        $st = sqlsrv_query($conn, "DELETE FROM INTEGRACION.dbo.evol_cache_base WHERE creado < DATEADD(minute, -" . EVOL_CACHE_TTL_MIN . ", SYSDATETIME())");
        if ($st !== false) sqlsrv_free_stmt($st);
    }
}

if (!function_exists('evolBuildFiltros')) {
    /**
     * Catálogo de filtros de evol DERIVADO de evol_cache_base (ya materializado por
     * ensureEvolCacheBase). Mismo CONJUNTO de combos que el build vivo del endpoint
     * (informe_evol.php tab=filtros, que usa #base) — verificado por
     * tests/evol_filtros_paridad_test.php. Devuelve array de combos, o ['error'=>...] si el
     * SELECT falla (para que el caller aplique ok-gate). Las 11 claves y el filtro CEDI son
     * EXACTAMENTE los del endpoint; no cambiar sin actualizar el test de paridad.
     */
    function evolBuildFiltros($conn, string $ekey): array {
        $sql = "SELECT DISTINCT marca, tipo, categoria, subcategoria, genero, publico_objetivo,
                    referencia, negocio, ISNULL(grupo,'') AS grupo, rtrim(bodega) AS cod,
                    ISNULL(nombre,'') AS nombre
                FROM INTEGRACION.dbo.evol_cache_base WITH (NOLOCK)
                WHERE cache_key = ? AND bodega <> 'CEDI'";
        $st = sqlsrv_query($conn, $sql, [$ekey]);
        if ($st === false) return ['error' => sqlsrv_errors()];
        $combos = [];
        while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) {
            $combos[] = [
                'marca'=>trim((string)$r['marca']), 'tipo'=>trim((string)$r['tipo']),
                'categoria'=>trim((string)$r['categoria']), 'subcategoria'=>trim((string)$r['subcategoria']),
                'genero'=>trim((string)$r['genero']), 'publico'=>trim((string)$r['publico_objetivo']),
                'referencia'=>trim((string)$r['referencia']), 'negocio'=>trim((string)$r['negocio']),
                'grupo'=>trim((string)$r['grupo']), 'tienda'=>trim((string)$r['nombre']),
                'tienda_cod'=>trim((string)$r['cod']),
            ];
        }
        sqlsrv_free_stmt($st);
        return $combos;
    }
}
