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
                    ISNULL(MARCA,'SIN MARCA') AS MARCA, ISNULL(TIPO,'') AS TIPO,
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
