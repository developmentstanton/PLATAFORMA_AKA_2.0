<?php
/**
 * Helper de precios para O45 (y reutilizable). Devuelve el precio vigente por (referencia,color)
 * SOLO para las referencias del proveedor ya materializadas en la temp table #refs.
 *
 * Reemplaza la query lenta que evaluaba la vista INTEGRACION.dbo.LISTA_PRECIOS_DETAL sin filtro:
 * esa vista hace `left join ITEMS` (17 LEFT JOIN) DOS veces (una en un CONCAT-IN correlacionado no
 * sargable) y calcula la lista de precios de TODOS los productos en cada request → >180s (timeout).
 *
 * Reescritura: lee SIESA t126/t121/t120 directo (SELECT read-only; NO modifica SIESA), toma la fila
 * de última fecha de activación por (ref,color) con RANK() y su MAX(precio), filtrando temprano por
 * #refs (JOIN, no IN: soporta proveedores con >2100 refs). Paridad IDÉNTICA verificada contra la
 * vista sobre el catálogo completo (36.222 llaves, 0 diferencias) — ver tests/verificar_precios_o45.php.
 */
if (!function_exists('preciosPorRefs')) {
    /**
     * @param resource $conn  conexión sqlsrv con una temp table #refs ya poblada (buildRefsFromMat).
     * @return array  mapa 'REFERENCIA|COLOR' => (float)precio. Vacío en error (los precios son complementarios).
     */
    function preciosPorRefs($conn) {
        // Semántica replicada de LISTA_PRECIOS_DETAL (verificada por paridad de catálogo completo):
        //  - RANK() PARTITION BY (ref,color) SIN cía: la vista toma la fecha de activación máxima
        //    global por (ref,color) pooleando cía 7 y 2 (su subquery agrupa por ref,color sin cía);
        //    RANK sobre (ref,color) hace lo mismo. Luego MAX(precio) sobre las filas rk=1, igual que
        //    el MAX(f126_precio) que hacía o45 sobre la vista.
        //  - La vista además filtraba TIPO IS NOT NULL (TIPO venía de su `left join ITEMS`, la vista
        //    de 17 joins que evitamos). Aquí se OMITE ese join: empíricamente 0 diferencias en todo el
        //    catálogo (ninguna ref con precio tiene ITEMS.TIPO null hoy). Solo afectaría el campo
        //    `precio` (complementario) si a futuro apareciera tal ref. Ver tests/verificar_precios_o45.php --full.
        $sql = "SELECT ref, col, MAX(precio) precio FROM (
            SELECT rtrim(f120_referencia) ref, rtrim(f121_id_ext1_detalle) col, f126_precio precio,
                   RANK() OVER (PARTITION BY f120_referencia, f121_id_ext1_detalle
                                ORDER BY f126_fecha_activacion DESC) rk
            FROM STANTON.DBO.t126_mc_items_precios WITH (NOLOCK)
             LEFT JOIN STANTON.DBO.t121_mc_items_extensiones WITH (NOLOCK)
                    ON f126_rowid_item_ext = f121_rowid AND f126_id_cia = f121_id_cia
             LEFT JOIN STANTON.DBO.t120_mc_items WITH (NOLOCK)
                    ON f121_rowid_item = f120_rowid AND f121_id_cia = f120_id_cia
             INNER JOIN #refs r ON r.REFERENCIA = rtrim(f120_referencia)
            WHERE f126_id_lista_precio = '100' AND (f126_id_cia = '7' OR f126_id_cia = '2')
        ) x WHERE rk = 1 GROUP BY ref, col";
        $st = sqlsrv_query($conn, $sql);
        if ($st === false) return [];
        $map = [];
        while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) {
            $map[trim((string)$r['ref']) . '|' . trim((string)$r['col'])] = (float)$r['precio'];
        }
        sqlsrv_free_stmt($st);
        return $map;
    }
}
