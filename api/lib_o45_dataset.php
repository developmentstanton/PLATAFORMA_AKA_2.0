<?php
/**
 * Construye el dataset granular de o45 para filtrado en cliente: grano
 * (cia,bodega,ref,color,talla) + dims (de #refs y Bodegas) + medidas.
 * NO aplica filtros de usuario (marca/negocio/grupo/tienda): eso es del cliente.
 * SÍ aplica la exclusión siempre-on de bodegas ADMINISTRATIVAS (conservando CEDI).
 * Requiere #refs ya poblado (buildRefsFromMat) con TODAS las refs del proveedor.
 * Solo SELECT sobre SIESA/INTEGRACION (no modifica nada). Reusa la lógica de build
 * de informe_o45.php (modo vivo/corte, ventas + Acum, #inv_hist).
 */
if (!function_exists('buildO45Dataset')) {
    function buildO45Dataset($conn, $desde, $hasta): array {
        $w30desde = date('Y-m-d', strtotime($hasta . ' -29 days'));
        $dias = (int) floor((strtotime($hasta) - strtotime($desde)) / 86400) + 1; if ($dias < 1) $dias = 1;

        // === Corte de stock: foto viva (fechada ayer) o corte de fin de mes <= hasta ===
        // (idéntico a informe_o45.php:52-62)
        $fv = sqlsrv_query($conn, "SELECT TOP 1 CONVERT(varchar(10),FECHA,120) f FROM INTEGRACION.dbo.inv_actual_PBI WITH (NOLOCK)");
        $row = null;
        if ($fv !== false) { $row = sqlsrv_fetch_array($fv, SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($fv); }
        $fechaViva = ($row && !empty($row['f'])) ? $row['f'] : date('Y-m-d', strtotime('-1 day'));
        if ($hasta >= $fechaViva) {
            $modoStock = 'vivo'; $corteStock = null;
        } else {
            $modoStock = 'corte';
            $finMes = date('Y-m-t', strtotime($hasta));                 // ultimo dia del mes de hasta
            $corteStock = ($hasta >= $finMes) ? $finMes                  // hasta ES fin de mes
                        : date('Y-m-t', strtotime(date('Y-m-01', strtotime($hasta)) . ' -1 day')); // fin del mes anterior
        }

        $meta = ['desde'=>$desde,'hasta'=>$hasta,'w30desde'=>$w30desde,'dias'=>$dias,
            'stock_corte'=>($modoStock==='vivo'?'vivo':$corteStock)];

        // Libera las temp tables en cualquier salida (feliz o con error).
        $dropTemps = function () use ($conn) {
            $d1 = sqlsrv_query($conn, "IF OBJECT_ID('tempdb..#base') IS NOT NULL DROP TABLE #base");
            if ($d1 !== false) sqlsrv_free_stmt($d1);
            $d2 = sqlsrv_query($conn, "IF OBJECT_ID('tempdb..#inv_hist') IS NOT NULL DROP TABLE #inv_hist");
            if ($d2 !== false) sqlsrv_free_stmt($d2);
        };

        // --- #base: disponible, hold, ventas (rango), ventas30 (30d hasta hasta) ---
        // (idéntico a informe_o45.php:65-68)
        $cre = sqlsrv_query($conn, "CREATE TABLE #base (cia varchar(10), bodega varchar(20), negocio varchar(120),
            referencia varchar(50), color varchar(40), talla varchar(40),
            disponible int, hold int, ventas int, ventas30 int, inv_hist int)");
        if ($cre === false) {
            $errs = sqlsrv_errors();
            $dropTemps();
            return ['rows'=>[], 'meta'=>$meta, 'error'=>$errs];
        }
        sqlsrv_free_stmt($cre);

        // #inv_hist: llaves (cia,bodega,ref,color,talla) con inventario>0 en ALGUN corte de fin de mes dentro del rango.
        // (idéntico a informe_o45.php:70-73)
        $creH = sqlsrv_query($conn, "CREATE TABLE #inv_hist (cia varchar(10), bodega varchar(20),
            referencia varchar(50), color varchar(40), talla varchar(40))");
        if ($creH === false) {
            $errs = sqlsrv_errors();
            $dropTemps();
            return ['rows'=>[], 'meta'=>$meta, 'error'=>$errs];
        }
        sqlsrv_free_stmt($creH);

        // Poblar #inv_hist (idéntico a informe_o45.php:76-91, siempre — buildO45Dataset es el
        // equivalente de tab=data, su escaneo histórico siempre debe correr).
        $insHist = "INSERT INTO #inv_hist
          SELECT DISTINCT cia,bodega,referencia,color,talla FROM (
            SELECT RIGHT('000'+rtrim(hi.CIA),3) cia, rtrim(hi.BODEGA) bodega, rtrim(hi.REFERENCIA) referencia,
                   rtrim(hi.COLOR) color, rtrim(hi.TALLA) talla
              FROM INTEGRACION.dbo.historico_inventarios_PBI hi WITH (NOLOCK)
               INNER JOIN #refs r ON r.REFERENCIA = rtrim(hi.REFERENCIA)
              WHERE hi.FECHA BETWEEN ? AND ? AND hi.CIA<>'001'
                    AND rtrim(hi.COLUMNA1) IN ('INV1430','INV1435','400') AND CAST(hi.CANTIDAD AS int) > 0
            UNION
            SELECT RIGHT('000'+rtrim(hh.CIA),3), rtrim(hh.BODEGA_SAL), rtrim(hh.REFERENCIA), rtrim(hh.COLOR), rtrim(hh.TALLA)
              FROM INTEGRACION.dbo.historico_hold_PBI hh WITH (NOLOCK)
               INNER JOIN #refs r ON r.REFERENCIA = rtrim(hh.REFERENCIA)
              WHERE hh.FECHA BETWEEN ? AND ? AND hh.CIA<>'001' AND CAST(hh.CANTIDAD AS int) > 0
          ) z";
        $rh = sqlsrv_query($conn, $insHist, [$desde,$hasta,$desde,$hasta]);
        if ($rh === false) {
            $errs = sqlsrv_errors();
            $dropTemps();
            return ['rows'=>[], 'meta'=>$meta, 'error'=>$errs];
        }
        sqlsrv_free_stmt($rh);

        // Partición de ventas: Ventas_Detal_PBI cubre 2026+ y Ventas_Detal_Acum_PBI ≤2025 (sin solape).
        // Solo se une Acum si la ventana toca ≤2025 (evita escanear 2.7M filas en vano y NO duplica años).
        // (idéntico a informe_o45.php:94-97)
        $acumV   = ($desde    <= '2025-12-31') ? "UNION ALL SELECT rtrim(CIA),rtrim(BODEGA),rtrim(REFERENCIA),rtrim(COLOR),rtrim(TALLA),CANTIDAD FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?" : "";
        $acumV30 = ($w30desde <= '2025-12-31') ? "UNION ALL SELECT rtrim(CIA),rtrim(BODEGA),rtrim(REFERENCIA),rtrim(COLOR),rtrim(TALLA),CANTIDAD FROM INTEGRACION.dbo.Ventas_Detal_Acum_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ?" : "";

        // CTE de stock (d=disponible, h=hold) según el modo: vivo (foto) o corte (fin de mes histórico).
        // (idéntico a informe_o45.php:99-132)
        if ($modoStock === 'vivo') {
            $dCte = "d AS (
                SELECT RIGHT('000'+rtrim(v.cia),3) cia, rtrim(v.bodega) bodega, rtrim(v.referencia) referencia,
                       rtrim(v.color) color, rtrim(v.talla) talla, SUM(CAST(v.cantidad AS int)) q
                FROM INTEGRACION.dbo.inv_actual_PBI v WITH (NOLOCK)
                 INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
                WHERE v.cia<>'001' AND v.COLUMNA1 IN ('INV1430','INV1435','400')
                GROUP BY RIGHT('000'+rtrim(v.cia),3),rtrim(v.bodega),rtrim(v.referencia),rtrim(v.color),rtrim(v.talla))";
            $hCte = "h AS (
                SELECT RIGHT('000'+rtrim(v.cia),3) cia, rtrim(v.bodega_sal) bodega, rtrim(v.referencia) referencia,
                       rtrim(v.color) color, rtrim(v.talla) talla, SUM(CAST(v.cantidad AS int)) q
                FROM INTEGRACION.dbo._hold_actual_PBI v WITH (NOLOCK)
                 INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.referencia)
                WHERE v.cia<>'001'
                GROUP BY RIGHT('000'+rtrim(v.cia),3),rtrim(v.bodega_sal),rtrim(v.referencia),rtrim(v.color),rtrim(v.talla))";
            $pStock = [];
        } else {
            $dCte = "d AS (
                SELECT RIGHT('000'+rtrim(v.CIA),3) cia, rtrim(v.BODEGA) bodega, rtrim(v.REFERENCIA) referencia,
                       rtrim(v.COLOR) color, rtrim(v.TALLA) talla, SUM(CAST(v.CANTIDAD AS int)) q
                FROM INTEGRACION.dbo.historico_inventarios_PBI v WITH (NOLOCK)
                 INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.REFERENCIA)
                WHERE v.FECHA = ? AND v.CIA<>'001' AND rtrim(v.COLUMNA1) IN ('INV1430','INV1435','400')
                GROUP BY RIGHT('000'+rtrim(v.CIA),3),rtrim(v.BODEGA),rtrim(v.REFERENCIA),rtrim(v.COLOR),rtrim(v.TALLA))";
            $hCte = "h AS (
                SELECT RIGHT('000'+rtrim(v.CIA),3) cia, rtrim(v.BODEGA_SAL) bodega, rtrim(v.REFERENCIA) referencia,
                       rtrim(v.COLOR) color, rtrim(v.TALLA) talla, SUM(CAST(v.CANTIDAD AS int)) q
                FROM INTEGRACION.dbo.historico_hold_PBI v WITH (NOLOCK)
                 INNER JOIN #refs r ON r.REFERENCIA = rtrim(v.REFERENCIA)
                WHERE v.FECHA = ? AND v.CIA<>'001'
                GROUP BY RIGHT('000'+rtrim(v.CIA),3),rtrim(v.BODEGA_SAL),rtrim(v.REFERENCIA),rtrim(v.COLOR),rtrim(v.TALLA))";
            $pStock = [$corteStock, $corteStock];
        }

        // (idéntico a informe_o45.php:134-171)
        $insBase = "
          WITH $dCte,
          $hCte,
          ventas_src AS (
            SELECT RIGHT('000'+rtrim(CIA),3) cia, rtrim(BODEGA) bodega, rtrim(REFERENCIA) referencia, rtrim(COLOR) color, rtrim(TALLA) talla, CANTIDAD
            FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ? $acumV
          ),
          v AS (
            SELECT vv.cia, vv.bodega, vv.referencia, vv.color, vv.talla, SUM(CAST(vv.CANTIDAD AS int)) q
            FROM ventas_src vv INNER JOIN #refs r ON r.REFERENCIA = vv.referencia
            GROUP BY vv.cia, vv.bodega, vv.referencia, vv.color, vv.talla
          ),
          ventas30_src AS (
            SELECT RIGHT('000'+rtrim(CIA),3) cia, rtrim(BODEGA) bodega, rtrim(REFERENCIA) referencia, rtrim(COLOR) color, rtrim(TALLA) talla, CANTIDAD
            FROM INTEGRACION.dbo.Ventas_Detal_PBI WITH (NOLOCK) WHERE FECHA BETWEEN ? AND ? $acumV30
          ),
          v30 AS (
            SELECT vv.cia, vv.bodega, vv.referencia, vv.color, vv.talla, SUM(CAST(vv.CANTIDAD AS int)) q
            FROM ventas30_src vv INNER JOIN #refs r ON r.REFERENCIA = vv.referencia
            GROUP BY vv.cia, vv.bodega, vv.referencia, vv.color, vv.talla
          ),
          llaves AS (
            SELECT cia,bodega,referencia,color,talla FROM d
            UNION SELECT cia,bodega,referencia,color,talla FROM h
            UNION SELECT cia,bodega,referencia,color,talla FROM v
            UNION SELECT cia,bodega,referencia,color,talla FROM v30
            UNION SELECT cia,bodega,referencia,color,talla FROM #inv_hist
          )
          INSERT INTO #base
          SELECT k.cia, k.bodega, k.referencia+'-'+k.color, k.referencia, k.color, k.talla,
                 CAST(ISNULL(d.q,0) AS int), CAST(ISNULL(h.q,0) AS int), CAST(ISNULL(v.q,0) AS int), CAST(ISNULL(v30.q,0) AS int),
                 CASE WHEN ih.cia IS NOT NULL THEN 1 ELSE 0 END
          FROM llaves k
           LEFT JOIN d   ON d.cia=k.cia AND d.bodega=k.bodega AND d.referencia=k.referencia AND d.color=k.color AND d.talla=k.talla
           LEFT JOIN h   ON h.cia=k.cia AND h.bodega=k.bodega AND h.referencia=k.referencia AND h.color=k.color AND h.talla=k.talla
           LEFT JOIN v   ON v.cia=k.cia AND v.bodega=k.bodega AND v.referencia=k.referencia AND v.color=k.color AND v.talla=k.talla
           LEFT JOIN v30 ON v30.cia=k.cia AND v30.bodega=k.bodega AND v30.referencia=k.referencia AND v30.color=k.color AND v30.talla=k.talla
           LEFT JOIN #inv_hist ih ON ih.cia=k.cia AND ih.bodega=k.bodega AND ih.referencia=k.referencia AND ih.color=k.color AND ih.talla=k.talla";

        // Orden de params: stock(corte) ; ventas_src(desde,hasta) [+acumV(desde,hasta)] ; ventas30_src(w30desde,hasta) [+acumV30(w30desde,hasta)]
        $p = $pStock;                       // <- corte de stock primero (si aplica)
        array_push($p, $desde, $hasta);     // ventas_src
        if ($acumV   !== '') array_push($p, $desde, $hasta);
        array_push($p, $w30desde, $hasta);  // ventas30_src
        if ($acumV30 !== '') array_push($p, $w30desde, $hasta);
        $ins = sqlsrv_query($conn, $insBase, $p);
        if ($ins === false) {
            $errs = sqlsrv_errors();
            $dropTemps();
            return ['rows'=>[], 'meta'=>$meta, 'error'=>$errs];
        }
        sqlsrv_free_stmt($ins);

        // Excluir bodegas ADMINISTRATIVAS (no son tiendas), conservando CEDI. Siempre-on.
        // (idéntico a informe_o45.php:182-188)
        $delAdmin = sqlsrv_query($conn, "
          DELETE b FROM #base AS b
          INNER JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK)
            ON rtrim(bo.COD)=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia
          WHERE rtrim(bo.GRUPO)='ADMINISTRATIVAS' AND b.bodega<>'CEDI'");
        if ($delAdmin !== false) sqlsrv_free_stmt($delAdmin);

        // Enriquecido: grano + dims (de #refs) + atributos de bodega
        $sql = "SELECT b.cia, b.bodega, ISNULL(bo.GRUPO,'') grupo, ISNULL(bo.NOMBRE,'') tienda,
                   CASE WHEN b.bodega='CEDI' THEN 1 ELSE 0 END es_cedi,
                   b.referencia, b.color, b.talla,
                   r.MARCA marca, r.TIPO tipo, r.CATEGORIA categoria, r.SUBCATEGORIA subcategoria, r.GENERO genero, r.PUBLICO_OBJETIVO publico,
                   b.disponible, b.hold, b.ventas, b.ventas30, b.inv_hist
                FROM #base b
                 INNER JOIN #refs r ON r.REFERENCIA = b.referencia
                 LEFT JOIN INTEGRACION.dbo.Bodegas bo WITH (NOLOCK) ON bo.COD=b.bodega AND RIGHT('000'+rtrim(bo.CIA),3)=b.cia";
        $st = sqlsrv_query($conn, $sql);
        if ($st === false) {
            $errs = sqlsrv_errors();
            $dropTemps();
            return ['rows'=>[], 'meta'=>$meta, 'error'=>$errs];
        }
        $rows = [];
        while ($x = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) {
            $rows[] = [
                'cia'=>rtrim((string)$x['cia']), 'bodega'=>rtrim((string)$x['bodega']),
                'grupo'=>rtrim((string)$x['grupo']), 'tienda'=>rtrim((string)$x['tienda']),
                'es_cedi'=>(int)$x['es_cedi'], 'referencia'=>rtrim((string)$x['referencia']),
                'color'=>rtrim((string)$x['color']), 'talla'=>rtrim((string)$x['talla']),
                'marca'=>rtrim((string)$x['marca']), 'tipo'=>rtrim((string)$x['tipo']),
                'categoria'=>rtrim((string)$x['categoria']), 'subcategoria'=>rtrim((string)$x['subcategoria']),
                'genero'=>rtrim((string)$x['genero']), 'publico'=>rtrim((string)$x['publico']),
                'disponible'=>(int)$x['disponible'], 'hold'=>(int)$x['hold'],
                'ventas'=>(int)$x['ventas'], 'ventas30'=>(int)$x['ventas30'], 'inv_hist'=>(int)$x['inv_hist'],
            ];
        }
        sqlsrv_free_stmt($st);
        $dropTemps();
        return ['rows'=>$rows, 'meta'=>$meta, 'error'=>null];
    }
}
