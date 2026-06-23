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
