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

/**
 * Construye el HTML del correo de confirmación (estilos inline, paleta del portal).
 */
function cod_correo_html(array $d): string {
    $primary = '#4A4782';
    $nombre = htmlspecialchars((string)($d['nombre'] ?? ''));
    $nit    = htmlspecialchars((string)($d['nit'] ?? '') !== '' ? (string)$d['nit'] : '—');
    $correo = htmlspecialchars((string)($d['correo'] ?? ''));
    $fecha  = htmlspecialchars((string)($d['fecha'] ?? ''));
    $cons   = (int)($d['consecutivo'] ?? 0);
    $logo   = 'http://bi.stanton.com.co:81/portal/img/logo_aka2_corto.png';

    $items = '';
    foreach (($d['archivos'] ?? []) as $a) {
        $items .= "<li style='margin:2px 0;color:#444;'>".htmlspecialchars((string)$a)."</li>";
    }
    if ($items === '') $items = "<li style='color:#999;'>(sin archivos)</li>";

    return <<<HTML
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:20px;font-family:Helvetica,Arial,sans-serif;background:#f4f4f7;'>
  <div style='max-width:600px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #eaeaea;'>
    <div style='background:{$primary};padding:24px;text-align:center;'>
      <img src='{$logo}' alt='AKA' style='max-height:46px;'>
    </div>
    <div style='padding:32px 28px;'>
      <h2 style='color:{$primary};margin:0 0 4px;font-size:20px;'>Estimado aliado, {$nombre}</h2>
      <p style='color:#555;font-size:15px;line-height:1.5;margin:12px 0;'>
        Hemos recibido el cargue de tu portafolio. Será validado por nuestro equipo de
        curaduría y te daremos respuesta próximamente.
      </p>
      <h3 style='color:{$primary};font-size:15px;margin:24px 0 10px;padding-bottom:6px;border-bottom:2px solid #e8e6f0;'>Detalle del envío</h3>
      <table width='100%' cellpadding='0' cellspacing='0' style='font-size:14px;'>
        <tr><td style='padding:5px 0;color:#888;width:130px;'>Consecutivo:</td><td style='padding:5px 0;color:#333;font-weight:bold;'>#{$cons}</td></tr>
        <tr><td style='padding:5px 0;color:#888;'>Aliado:</td><td style='padding:5px 0;color:#333;'>{$nombre}</td></tr>
        <tr><td style='padding:5px 0;color:#888;'>NIT:</td><td style='padding:5px 0;color:#333;'>{$nit}</td></tr>
        <tr><td style='padding:5px 0;color:#888;'>Correo:</td><td style='padding:5px 0;color:#333;'>{$correo}</td></tr>
        <tr><td style='padding:5px 0;color:#888;'>Fecha:</td><td style='padding:5px 0;color:#333;'>{$fecha}</td></tr>
        <tr><td style='padding:5px 0;color:#888;vertical-align:top;'>Archivos:</td><td style='padding:5px 0;color:#333;'><ul style='margin:0;padding-left:18px;'>{$items}</ul></td></tr>
      </table>
      <p style='color:#555;font-size:14px;margin:24px 0 0;'>Cordialmente,<br><strong style='color:{$primary};'>Equipo Tiendas AKA</strong></p>
    </div>
    <div style='background:#f1f0f6;padding:16px;text-align:center;'>
      <p style='margin:0;color:#999;font-size:12px;'>Mensaje automático · por favor no respondas a este correo.</p>
    </div>
  </div>
</body></html>
HTML;
}

/**
 * Inserta el envío y devuelve el consecutivo (IDENTITY) generado.
 * consecutivo y estado ('Estudio') se autocompletan; nit puede ser NULL.
 */
function cod_registrar_envio($conn, string $nombre, string $fecha, ?string $nit): int {
    $sql = "SET NOCOUNT ON;
            INSERT INTO consecutivo_planillas_aka (nombre_cliente, fecha, nit)
            OUTPUT INSERTED.consecutivo AS consecutivo
            VALUES (?, ?, ?)";
    $stmt = sqlsrv_query($conn, $sql, [$nombre, $fecha, $nit]);
    if ($stmt === false) {
        throw new RuntimeException('INSERT falló: '.print_r(sqlsrv_errors(), true));
    }
    $cons = 0;
    do {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && isset($row['consecutivo'])) { $cons = (int)$row['consecutivo']; break; }
    } while (sqlsrv_next_result($stmt));
    return $cons;
}
