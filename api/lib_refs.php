<?php
/** Helpers compartidos para materializar las referencias del proveedor en #refs. */

if (!function_exists('refs_marca_curada')) {
    /**
     * ¿El nombre que traen los informes es en realidad una MARCA y no un proveedor?
     *
     * Un aliado puede estar acotado a una marca (usuarios_portal_aka.marca_items); en ese caso
     * el login deja esa marca en $_SESSION['proveedor'] — ver login_resolver_proveedor(). Aquí
     * se decide con qué columna de Items_Mat/ITEMS se arma su universo.
     *
     * La comprobación se hace AQUÍ, dentro del constructor de #refs, y no como parámetro nuevo:
     * así los 5 endpoints, lib_prewarm y los tests siguen llamando igual y todos quedan
     * correctos a la vez. Un parámetro habría que acordarse de pasarlo en cada sitio, y el que
     * se olvidara construiría un #refs VACÍO en silencio (informes en blanco, sin error).
     *
     * Si la columna aún no existe (migración sql/007 sin aplicar) devuelve false y todo se
     * comporta como antes.
     */
    function refs_marca_curada($conn, string $nombre): bool {
        static $memo = [];
        static $hayColumna = null;
        if ($nombre === '') return false;
        if ($hayColumna === null) {
            $st = sqlsrv_query($conn, "SELECT COL_LENGTH('dbo.usuarios_portal_aka','marca_items') AS c");
            $hayColumna = false;
            if ($st !== false) {
                $r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
                $hayColumna = $r && $r['c'] !== null;
                sqlsrv_free_stmt($st);
            }
        }
        if (!$hayColumna) return false;
        if (array_key_exists($nombre, $memo)) return $memo[$nombre];

        $st = sqlsrv_query($conn, "SELECT TOP 1 1 AS hay FROM usuarios_portal_aka
                                   WHERE RTRIM(ISNULL(marca_items,'')) = ?", array($nombre));
        $es = false;
        if ($st !== false) {
            $es = (bool) sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($st);
        }
        return $memo[$nombre] = $es;
    }
}

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
        // Aliado de marca (p.ej. Ibiza, que vive bajo el proveedor STANTON): su universo es
        // la MARCA, no el PROVEEDOR. Ver refs_marca_curada().
        $col = refs_marca_curada($conn, $proveedor) ? 'MARCA' : 'PROVEEDOR';
        $sql = "SELECT REFERENCIA,
                    ISNULL(MARCA,'SIN MARCA') AS MARCA, ISNULL(TIPO,'SIN TIPO') AS TIPO,
                    ISNULL(LINEA,'SIN LINEA') AS LINEA, ISNULL(SUBLINEA,'') AS SUBLINEA,
                    ISNULL(CATEGORIA,'') AS CATEGORIA, ISNULL(SUBCATEGORIA,'') AS SUBCATEGORIA,
                    ISNULL(GENERO,'') AS GENERO, ISNULL(PUBLICO_OBJETIVO,'') AS PUBLICO_OBJETIVO
                FROM INTEGRACION.dbo.ITEMS WITH (NOLOCK) WHERE $col = ?";
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
        // Aliado de marca (p.ej. Ibiza, que vive bajo el proveedor STANTON): su universo es la
        // MARCA, no el PROVEEDOR. Sin esto veria el catalogo entero de su proveedor.
        // El nombre de columna se elige de una lista cerrada, nunca sale del parametro.
        $col = refs_marca_curada($conn, $proveedor) ? 'MARCA' : 'PROVEEDOR';
        $ins = sqlsrv_query($conn,
            "INSERT INTO #refs (REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO)
             SELECT REFERENCIA,MARCA,TIPO,LINEA,SUBLINEA,CATEGORIA,SUBCATEGORIA,GENERO,PUBLICO_OBJETIVO
             FROM INTEGRACION.dbo.Items_Mat WITH (NOLOCK) WHERE $col = ?",
            [$proveedor]);
        if ($ins === false) return false;
        sqlsrv_free_stmt($ins);
        return true;
    }
}
