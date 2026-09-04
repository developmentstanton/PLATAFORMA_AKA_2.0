<?php
/**
 * Resolución del proveedor del portal a partir del nombre de usuario.
 * Lógica pura y testeable. Incluida por index.php y por los tests. NO ejecuta nada al incluirse.
 */

/**
 * Resuelve el proveedor (y su NIT si está disponible) para un usuario del portal.
 *
 * Los informes filtran por INTEGRACION.dbo.ITEMS.PROVEEDOR. Para obtener ese nombre:
 *   1) Cruza el usuario con el maestro de proveedores de SIESA (t202, cía 7) por LIKE
 *      → razón social + NIT (el NIT lo necesita Análisis de Pagos).
 *   2) FALLBACK: si el usuario no está en el maestro (p.ej. INTERTENIS, que vende pero no
 *      figura en t202), busca el nombre directamente en ITEMS.PROVEEDOR — que es justo lo
 *      que filtran los informes. En este caso el NIT queda en null (no hay maestro).
 *
 * Se elige el match más corto (más específico), igual que la lógica original del login.
 *
 * @return array{proveedor: ?string, nit: ?string, fuente: ?string}  fuente ∈ {'t202','items',null}
 */
/**
 * ¿El aliado está acotado a una MARCA en vez de a un proveedor?
 * Devuelve el nombre de la marca (tal cual está en ITEMS.MARCA) o null.
 *
 * Consulta aparte y tolerante a propósito: si `marca_items` todavía no existe (migración
 * sql/007 sin aplicar), esto devuelve null y TODA la resolución de abajo sigue intacta.
 * Si se colara en la consulta del curado, un error de columna la tumbaría entera y todos
 * los aliados pasarían a resolverse por t202 — un cambio de conducta general y silencioso.
 */
function login_marca_curada($conn, string $usuario): ?string {
    static $hayColumna = null;
    if ($hayColumna === null) {
        $st = sqlsrv_query($conn, "SELECT COL_LENGTH('dbo.usuarios_portal_aka','marca_items') AS c");
        $hayColumna = false;
        if ($st !== false) {
            $r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
            $hayColumna = $r && $r['c'] !== null;
            sqlsrv_free_stmt($st);
        }
    }
    if (!$hayColumna) return null;

    $st = sqlsrv_query($conn, "SELECT TOP 1 RTRIM(marca_items) AS marca
                               FROM usuarios_portal_aka WHERE nombre_usuario = ?", array($usuario));
    if ($st === false) return null;
    $row = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($st);
    $marca = $row ? trim((string)$row['marca']) : '';
    return $marca !== '' ? $marca : null;
}

function login_resolver_proveedor($conn, string $usuario): array {
    $busqueda = str_replace('_', ' ', $usuario);

    // 0-bis) Aliado de MARCA (usuarios_portal_aka.marca_items). Va ANTES del curado por
    //   proveedor porque para estos aliados la marca ES su identidad: el caso Ibiza vive
    //   bajo el proveedor STANTON, así que resolver por proveedor le mostraría el catálogo
    //   entero de Stanton. El nombre devuelto es la marca, y de ahí sale todo lo demás
    //   gratis: los títulos ("... - IBIZA") y las claves de caché, que son md5 del nombre.
    //   Quien filtra por marca es #refs; ver refs_marca_curada() en api/lib_refs.php.
    $marca = login_marca_curada($conn, $usuario);
    if ($marca !== null) {
        $nit = null;
        $stN = sqlsrv_query($conn, "SELECT TOP 1 RTRIM(link2) AS nit FROM usuarios_portal_aka WHERE nombre_usuario = ?", array($usuario));
        if ($stN !== false) {
            $rN = sqlsrv_fetch_array($stN, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stN);
            if ($rN && trim((string)$rN['nit']) !== '') $nit = trim((string)$rN['nit']);
        }
        return array('proveedor' => $marca, 'nit' => $nit, 'fuente' => 'curado-marca', 'marca' => $marca);
    }

    // 0) Nombre canónico CURADO (usuarios_portal_aka.proveedor_items). Prioridad máxima:
    //    los informes filtran ITEMS.PROVEEDOR por match exacto, y la razón social de t202
    //    a veces no coincide (ej. 'BRAHMA CONCEPT S A S' vs 'BRAHMA CONCEPT'). Si el aliado
    //    tiene proveedor_items curado, ese es el valor exacto de ITEMS.PROVEEDOR.
    //    Se usa $usuario (nombre_usuario exacto), NO $busqueda.
    $sqlCurado = "SELECT TOP 1 RTRIM(proveedor_items) AS prov, RTRIM(link2) AS nit
                  FROM usuarios_portal_aka WHERE nombre_usuario = ?";
    $stCur = sqlsrv_query($conn, $sqlCurado, array($usuario));
    if ($stCur !== false) {
        $rowCur = sqlsrv_fetch_array($stCur, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stCur);
        if ($rowCur && trim((string)$rowCur['prov']) !== '') {
            return array(
                'proveedor' => trim((string)$rowCur['prov']),
                'nit'       => (!empty($rowCur['nit'])) ? trim((string)$rowCur['nit']) : null,
                'fuente'    => 'curado',
            );
        }
    }

    // 1) Maestro de proveedores SIESA (razón + NIT)
    $sqlProv = "SELECT TOP 1 RTRIM(p.f202_descripcion_sucursal) AS razon, RTRIM(t.f200_nit) AS nit
                FROM stanton.dbo.t202_mm_proveedores p
                JOIN stanton.dbo.t200_mm_terceros t ON t.f200_rowid = p.f202_rowid_tercero
                WHERE p.f202_id_cia = '7'
                  AND p.f202_descripcion_sucursal LIKE '%' + ? + '%'
                ORDER BY LEN(p.f202_descripcion_sucursal) ASC";
    $stmt = sqlsrv_query($conn, $sqlProv, array($busqueda));
    if ($stmt !== false) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if ($row && trim((string)$row['razon']) !== '') {
            return array(
                'proveedor' => trim((string)$row['razon']),
                'nit'       => (!empty($row['nit'])) ? trim((string)$row['nit']) : null,
                'fuente'    => 't202',
            );
        }
    }

    // 2) Fallback: nombre del proveedor directo desde ITEMS.PROVEEDOR (lo que filtran los informes)
    $sqlItems = "SELECT TOP 1 RTRIM(PROVEEDOR) AS proveedor
                 FROM INTEGRACION.dbo.ITEMS WITH (NOLOCK)
                 WHERE PROVEEDOR LIKE '%' + ? + '%'
                 GROUP BY PROVEEDOR
                 ORDER BY LEN(RTRIM(PROVEEDOR)) ASC";
    $stmt2 = sqlsrv_query($conn, $sqlItems, array($busqueda));
    if ($stmt2 !== false) {
        $row2 = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt2);
        if ($row2 && trim((string)$row2['proveedor']) !== '') {
            return array(
                'proveedor' => trim((string)$row2['proveedor']),
                'nit'       => null,
                'fuente'    => 'items',
            );
        }
    }

    return array('proveedor' => null, 'nit' => null, 'fuente' => null);
}
