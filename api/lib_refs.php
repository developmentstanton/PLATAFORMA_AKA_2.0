<?php
/** Helpers compartidos para materializar las referencias del proveedor en #refs. */
if (!function_exists('getRefsCached')) {
    function getRefsCached($conn, $proveedor) {
        $cacheDir = __DIR__ . '/../cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        $cacheFile = $cacheDir . '/g00_refs_' . md5($proveedor) . '.json';
        if (file_exists($cacheFile) && date('Y-m-d', filemtime($cacheFile)) === date('Y-m-d')) {
            $data = json_decode(file_get_contents($cacheFile), true);
            // Validar esquema: una caché vieja (sin las dims nuevas) se ignora y se reconstruye.
            if (is_array($data) && (!count($data) || array_key_exists('PUBLICO_OBJETIVO', $data[0]))) return $data;
        }
        $sql = "SELECT REFERENCIA,
                    ISNULL(MARCA,'SIN MARCA') AS MARCA, ISNULL(TIPO,'SIN TIPO') AS TIPO,
                    ISNULL(LINEA,'SIN LINEA') AS LINEA, ISNULL(SUBLINEA,'') AS SUBLINEA,
                    ISNULL(CATEGORIA,'') AS CATEGORIA, ISNULL(SUBCATEGORIA,'') AS SUBCATEGORIA,
                    ISNULL(GENERO,'') AS GENERO, ISNULL(PUBLICO_OBJETIVO,'') AS PUBLICO_OBJETIVO
                FROM INTEGRACION.dbo.ITEMS WITH (NOLOCK) WHERE PROVEEDOR = ?";
        $stmt = sqlsrv_query($conn, $sql, [$proveedor]);
        if ($stmt === false) return [];
        $rows = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
        sqlsrv_free_stmt($stmt);
        @file_put_contents($cacheFile, json_encode($rows));
        return $rows;
    }
    function buildRefsTemp($conn, $refs) {
        // Idempotente: permite reconstruir #refs varias veces en la misma conexión (p.ej. warmProveedor).
        $drop = sqlsrv_query($conn, "DROP TABLE IF EXISTS #refs");
        if ($drop !== false) sqlsrv_free_stmt($drop);
        $ok = sqlsrv_query($conn, "CREATE TABLE #refs (
            REFERENCIA varchar(50) NOT NULL PRIMARY KEY,
            MARCA varchar(40), TIPO varchar(40), LINEA varchar(40), SUBLINEA varchar(40),
            CATEGORIA varchar(40), SUBCATEGORIA varchar(60), GENERO varchar(40), PUBLICO_OBJETIVO varchar(60))");
        if ($ok === false) return false;
        sqlsrv_free_stmt($ok);
        if (empty($refs)) return true;
        foreach (array_chunk($refs, 200) as $chunk) {
            $vals = []; $params = [];
            foreach ($chunk as $r) {
                $vals[] = '(?,?,?,?,?,?,?,?,?)';
                array_push($params, $r['REFERENCIA'], $r['MARCA']??'', $r['TIPO']??'', $r['LINEA']??'', $r['SUBLINEA']??'',
                    $r['CATEGORIA']??'', $r['SUBCATEGORIA']??'', $r['GENERO']??'', $r['PUBLICO_OBJETIVO']??'');
            }
            $ins = sqlsrv_query($conn, "INSERT INTO #refs (REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO) VALUES " . implode(',', $vals), $params);
            if ($ins === false) return false;
            sqlsrv_free_stmt($ins);
        }
        return true;
    }
}

if (!function_exists('buildRefsFromMat')) {
    /**
     * Construye #refs leyendo la tabla materializada dbo.Items_Mat (index seek por PROVEEDOR).
     * Reemplaza getRefsCached()+buildRefsTemp(). Si Items_Mat no existe, cae al camino viejo.
     * La estructura de #refs es idéntica a la de buildRefsTemp (queries de agregación intactas).
     */
    function buildRefsFromMat($conn, $proveedor) {
        // Fallback de transición: si la tabla materializada aún no existe, usar el camino viejo.
        $chk = sqlsrv_query($conn, "SELECT OBJECT_ID('INTEGRACION.dbo.Items_Mat') AS oid");
        $existe = false;
        if ($chk !== false) {
            $row = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
            $existe = $row && $row['oid'] !== null;
            sqlsrv_free_stmt($chk);
        }
        if (!$existe) {
            return buildRefsTemp($conn, getRefsCached($conn, $proveedor));
        }
        // Camino nuevo: CREATE #refs (sin params) + INSERT ... SELECT (con param) — respeta gotcha sqlsrv.
        // Idempotente: permite reconstruir #refs varias veces en la misma conexión (p.ej. warmProveedor).
        $drop = sqlsrv_query($conn, "DROP TABLE IF EXISTS #refs");
        if ($drop !== false) sqlsrv_free_stmt($drop);
        $ok = sqlsrv_query($conn, "CREATE TABLE #refs (
            REFERENCIA varchar(50) NOT NULL PRIMARY KEY,
            MARCA varchar(40), TIPO varchar(40), LINEA varchar(40), SUBLINEA varchar(40),
            CATEGORIA varchar(40), SUBCATEGORIA varchar(60), GENERO varchar(40), PUBLICO_OBJETIVO varchar(60))");
        if ($ok === false) return false;
        sqlsrv_free_stmt($ok);
        $ins = sqlsrv_query($conn,
            "INSERT INTO #refs (REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO)
             SELECT REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO
             FROM INTEGRACION.dbo.Items_Mat WITH (NOLOCK) WHERE PROVEEDOR = ?",
            [$proveedor]);
        if ($ins === false) return false;
        sqlsrv_free_stmt($ins);
        return true;
    }
}
