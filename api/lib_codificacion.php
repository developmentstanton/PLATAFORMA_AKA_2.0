<?php
/**
 * Helpers de la Carga Masiva de Codificación (lógica pura y testeable).
 * Incluido por api/codificacion_cargar.php y por los tests. NO ejecuta nada al incluirse.
 */

const COD_MAX_ARCHIVOS = 10;
const COD_MAX_BYTES    = 10485760; // 10 MB
const COD_EXT_OK       = ['xlsx', 'xls'];

/**
 * Valida y normaliza el sub-array $_FILES['adjunto'] (escalar o arrays paralelos).
 * @return array ['ok'=>bool,'error'=>?string,'archivos'=>[['name','tmp_name','error','size'], ...]]
 */
function cod_validar_archivos($adjunto): array {
    if (!is_array($adjunto) || !isset($adjunto['name'])) {
        return ['ok'=>false, 'error'=>'No se recibió ningún archivo.', 'archivos'=>[]];
    }
    $names = is_array($adjunto['name'])     ? $adjunto['name']     : [$adjunto['name']];
    $tmps  = is_array($adjunto['tmp_name']) ? $adjunto['tmp_name'] : [$adjunto['tmp_name']];
    $errs  = is_array($adjunto['error'])    ? $adjunto['error']    : [$adjunto['error']];
    $sizes = is_array($adjunto['size'])     ? $adjunto['size']     : [$adjunto['size']];

    $archivos = [];
    for ($i = 0; $i < count($names); $i++) {
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue; // slot vacío
        $archivos[] = ['name'=>$names[$i], 'tmp_name'=>$tmps[$i], 'error'=>$errs[$i], 'size'=>(int)$sizes[$i]];
    }

    if (count($archivos) === 0)               return ['ok'=>false, 'error'=>'Adjunta al menos un archivo .xlsx', 'archivos'=>[]];
    if (count($archivos) > COD_MAX_ARCHIVOS)  return ['ok'=>false, 'error'=>'Máximo '.COD_MAX_ARCHIVOS.' archivos por envío.', 'archivos'=>[]];

    foreach ($archivos as $a) {
        if ($a['error'] !== UPLOAD_ERR_OK)
            return ['ok'=>false, 'error'=>'Error al subir "'.$a['name'].'".', 'archivos'=>[]];
        $ext = strtolower(pathinfo($a['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, COD_EXT_OK, true))
            return ['ok'=>false, 'error'=>'Solo se permiten archivos Excel (.xlsx, .xls). "'.$a['name'].'" no es válido.', 'archivos'=>[]];
        if ($a['size'] > COD_MAX_BYTES)
            return ['ok'=>false, 'error'=>'"'.$a['name'].'" supera el máximo de 10 MB.', 'archivos'=>[]];
    }
    return ['ok'=>true, 'error'=>null, 'archivos'=>$archivos];
}
