<?php
/**
 * Helpers de Documentación (validación pura + acceso a BD). NO ejecuta nada al incluirse.
 * NOTA: archivo en UTF-8 (por el tipo 'Cámara de Comercio').
 */

const DOC_TIPOS     = ['Contrato', 'RUT', 'Cámara de Comercio'];
const DOC_EXT_OK    = ['pdf', 'jpg', 'jpeg', 'png'];
const DOC_MAX_BYTES = 10485760; // 10 MB

/** Valida tipo (allowlist) + archivo (subido, extensión, tamaño). @return ['ok'=>bool,'error'=>?string] */
function doc_validar(?string $tipo, $archivo): array {
    if (!in_array($tipo, DOC_TIPOS, true))
        return ['ok'=>false, 'error'=>'Tipo de documento inválido.'];
    if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
        return ['ok'=>false, 'error'=>'Adjunta un archivo.'];
    $ext = strtolower(pathinfo((string)($archivo['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, DOC_EXT_OK, true))
        return ['ok'=>false, 'error'=>'Solo se permiten PDF o imágenes (.pdf, .jpg, .jpeg, .png).'];
    if ((int)($archivo['size'] ?? 0) > DOC_MAX_BYTES)
        return ['ok'=>false, 'error'=>'El archivo supera el máximo de 10 MB.'];
    return ['ok'=>true, 'error'=>null];
}

/** Mime esperado por extensión. */
function doc_mime_por_ext(string $ext): string {
    switch (strtolower($ext)) {
        case 'pdf':  return 'application/pdf';
        case 'png':  return 'image/png';
        case 'jpg':
        case 'jpeg': return 'image/jpeg';
        default:     return 'application/octet-stream';
    }
}

/** Inserta el documento (binario) y devuelve el id. */
function doc_guardar($conn, string $nombre, ?string $nit, string $tipo, string $nombreArchivo, string $mime, string $contenido): int {
    $sql = "SET NOCOUNT ON;
            INSERT INTO documentos_aliados_aka (nombre_cliente, nit, tipo, nombre_archivo, mime, tamano, contenido)
            OUTPUT INSERTED.id AS id
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $params = [
        $nombre, $nit, $tipo, $nombreArchivo, $mime, strlen($contenido),
        [$contenido, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY), SQLSRV_SQLTYPE_VARBINARY('max')],
    ];
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) throw new RuntimeException('INSERT documento falló: '.print_r(sqlsrv_errors(), true));
    $id = 0;
    do { $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC); if ($row && isset($row['id'])) { $id=(int)$row['id']; break; } } while (sqlsrv_next_result($stmt));
    if ($id === 0) throw new RuntimeException('No se recuperó el id del documento.');
    return $id;
}

/** Lista los documentos de un aliado (SIN el binario), del más reciente al más antiguo. */
function doc_listar($conn, string $nombre): array {
    $sql = "SELECT id, tipo, nombre_archivo, tamano, nit, nombre_cliente, fecha
            FROM documentos_aliados_aka WITH (NOLOCK)
            WHERE nombre_cliente = ?
            ORDER BY id DESC";
    $stmt = sqlsrv_query($conn, $sql, [$nombre]);
    if ($stmt === false) throw new RuntimeException('Listado documentos falló: '.print_r(sqlsrv_errors(), true));
    $rows = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $f = $r['fecha'];
        $rows[] = [
            'id'             => (int)$r['id'],
            'tipo'           => trim((string)$r['tipo']),
            'nombre_archivo' => (string)$r['nombre_archivo'],
            'tamano'         => (int)$r['tamano'],
            'nit'            => $r['nit'] !== null ? trim((string)$r['nit']) : null,
            'nombre'         => trim((string)$r['nombre_cliente']),
            'fecha'          => ($f instanceof DateTime) ? $f->format('Y-m-d H:i') : (string)$f,
        ];
    }
    return $rows;
}

/** Devuelve nombre/mime/contenido de un doc SOLO si pertenece al aliado; null si no. */
function doc_obtener($conn, int $id, string $nombre): ?array {
    $sql = "SELECT nombre_archivo, mime, contenido FROM documentos_aliados_aka WITH (NOLOCK)
            WHERE id = ? AND nombre_cliente = ?";
    $stmt = sqlsrv_query($conn, $sql, [$id, $nombre]);
    if ($stmt === false) throw new RuntimeException('Obtener documento falló: '.print_r(sqlsrv_errors(), true));
    if (!sqlsrv_fetch($stmt)) return null;
    return [
        'nombre_archivo' => (string)sqlsrv_get_field($stmt, 0, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR)),
        'mime'           => (string)sqlsrv_get_field($stmt, 1, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR)),
        'contenido'      => (string)sqlsrv_get_field($stmt, 2, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_BINARY)),
    ];
}
