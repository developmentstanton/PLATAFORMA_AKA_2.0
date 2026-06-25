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
function login_resolver_proveedor($conn, string $usuario): array {
    $busqueda = str_replace('_', ' ', $usuario);

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
