<?php
/**
 * Lógica del módulo de Verificación (auditoría de aliados).
 * Pura y testeable. Incluida por la vista, los endpoints y los tests.
 * NO ejecuta nada al incluirse (mismo contrato que api/lib_login.php).
 */

/**
 * Los cinco puntos de control, en el orden en que se muestran y se imprimen.
 * Las claves coinciden EXACTAMENTE con el CHECK de verificacion_auditoria_detalle.informe;
 * agregar un informe es agregar una entrada aquí y ampliar el CHECK, nada más.
 */
const VERIF_INFORMES = [
    'g00'  => 'Ventas',
    'o14'  => 'Siembra / Stock / Ventas',
    'o45'  => 'Índice de Ventas',
    'evol' => 'Evolución Histórica',
    'geo'  => 'Georreferenciación',
];

/** Coinciden con el CHECK de la columna resultado. Sensible a mayúsculas, como el CHECK. */
const VERIF_RESULTADOS = ['aprobado', 'no_aprobado', 'no_aplica'];

/** Largo de verificacion_auditoria_detalle.observacion. Se valida aquí para no truncar en la base. */
const VERIF_OBS_MAX = 1000;

/**
 * Proveedor centinela de los tests. verif_destinatarios() lo rechaza, así que una fila de
 * prueba es incapaz de producir un envío aunque alguien la empuje por el camino real.
 */
const VERIF_PROVEEDOR_TEST = '__TEST__';

/**
 * Valida un punto de control ANTES de tocar la base.
 *
 * @return string Cadena vacía si es válido; el mensaje de error si no.
 */
function verif_validar_punto(string $informe, string $resultado, ?string $observacion): string {
    if (!array_key_exists($informe, VERIF_INFORMES)) {
        return 'Informe no válido.';
    }
    if (!in_array($resultado, VERIF_RESULTADOS, true)) {
        return 'Resultado no válido.';
    }

    $obs = trim((string)$observacion);

    // Un "no aprobado" es un hallazgo de auditoría: sin explicación no le sirve a nadie
    // que lea el PDF tres meses después.
    if ($resultado === 'no_aprobado' && $obs === '') {
        return 'Debes explicar por qué no se aprueba.';
    }

    // Se mide en caracteres, no en bytes: la columna es NVARCHAR y las tildes cuentan
    // como uno. strlen() rechazaría observaciones válidas por llevar acentos.
    if (mb_strlen($obs) > VERIF_OBS_MAX) {
        return 'La observación no puede pasar de ' . VERIF_OBS_MAX . ' caracteres.';
    }

    return '';
}

/**
 * Qué informes quedan sin marcar.
 *
 * @param array $marcados Claves de informe ya registradas.
 * @return array Claves que faltan, en el orden de VERIF_INFORMES.
 */
function verif_faltantes(array $marcados): array {
    return array_values(array_diff(array_keys(VERIF_INFORMES), $marcados));
}

/**
 * Abre una auditoría para el aliado, o recupera la que esté en curso.
 *
 * Si ya hay una 'en_curso' se devuelve esa y se IGNORA $auditor: la auditoría conserva
 * quien la abrió, para que no termine firmada por dos personas. Cuando la última está
 * 'cerrada' se abre una nueva — eso es lo que produce el historial por aliado.
 *
 * @return array{id:int, auditor:string, nueva:bool}
 */
function verif_abrir($conn, string $proveedor, string $usuarioPortal, string $auditor): array {
    $sql = "SELECT TOP 1 id, auditor FROM verificacion_auditoria
            WHERE proveedor = ? AND estado = 'en_curso'
            ORDER BY id DESC";
    $stmt = sqlsrv_query($conn, $sql, [$proveedor]);
    if ($stmt === false) {
        throw new RuntimeException('Buscar auditoría en curso falló: ' . print_r(sqlsrv_errors(), true));
    }
    $fila = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if ($fila) {
        return ['id' => (int)$fila['id'], 'auditor' => (string)$fila['auditor'], 'nueva' => false];
    }

    // SCOPE_IDENTITY() y no @@IDENTITY: @@IDENTITY devolvería el id que generara un
    // trigger en otra tabla, no el nuestro.
    $ins = "INSERT INTO verificacion_auditoria (proveedor, usuario_portal, auditor)
            VALUES (?, ?, ?);
            SELECT CAST(SCOPE_IDENTITY() AS INT) AS id;";
    $stmt = sqlsrv_query($conn, $ins, [$proveedor, $usuarioPortal, $auditor]);
    if ($stmt === false) {
        throw new RuntimeException('Crear auditoría falló: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_next_result($stmt);              // saltar del INSERT al SELECT
    $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    return ['id' => (int)$r['id'], 'auditor' => $auditor, 'nueva' => true];
}

/**
 * Guarda un punto de control. Re-marcar el mismo informe actualiza la fila existente.
 *
 * HOLDLOCK no es decorativo: sin él, dos MERGE simultáneos sobre la misma llave pueden
 * pasar los dos por WHEN NOT MATCHED y el segundo INSERT revienta contra UQ_verificacion_detalle.
 */
function verif_guardar_punto($conn, int $auditoriaId, string $informe, string $resultado, ?string $obs): void {
    $o = ($obs === null || trim($obs) === '') ? null : trim($obs);

    $sql = "MERGE verificacion_auditoria_detalle WITH (HOLDLOCK) AS destino
            USING (SELECT ? AS auditoria_id, ? AS informe) AS origen
                ON destino.auditoria_id = origen.auditoria_id
               AND destino.informe      = origen.informe
            WHEN MATCHED THEN
                UPDATE SET resultado = ?, observacion = ?, actualizado_en = SYSDATETIME()
            WHEN NOT MATCHED THEN
                INSERT (auditoria_id, informe, resultado, observacion)
                VALUES (origen.auditoria_id, origen.informe, ?, ?);";

    $stmt = sqlsrv_query($conn, $sql, [$auditoriaId, $informe, $resultado, $o, $resultado, $o]);
    if ($stmt === false) {
        throw new RuntimeException('Guardar punto falló: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);
}

/**
 * La auditoría completa con sus puntos, o null si no existe.
 *
 * Los DATETIME2 vuelven de sqlsrv como objetos DateTime; se formatean aquí para que
 * quien consuma (JSON, PDF, correo) reciba cadenas y no tenga que saberlo.
 */
function verif_cargar($conn, int $auditoriaId): ?array {
    $sql = "SELECT id, proveedor, usuario_portal, auditor, comentarios, estado,
                   creado_en, cerrado_en, correo_enviado
            FROM verificacion_auditoria WHERE id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$auditoriaId]);
    if ($stmt === false) {
        throw new RuntimeException('Cargar auditoría falló: ' . print_r(sqlsrv_errors(), true));
    }
    $cab = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if (!$cab) return null;

    $fmt = function ($d) { return $d instanceof DateTime ? $d->format('Y-m-d H:i:s') : null; };

    $sqlDet = "SELECT informe, resultado, observacion
               FROM verificacion_auditoria_detalle WHERE auditoria_id = ?";
    $stmt = sqlsrv_query($conn, $sqlDet, [$auditoriaId]);
    if ($stmt === false) {
        throw new RuntimeException('Cargar puntos falló: ' . print_r(sqlsrv_errors(), true));
    }
    $puntos = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $puntos[$r['informe']] = [
            'resultado'   => (string)$r['resultado'],
            'observacion' => $r['observacion'] !== null ? (string)$r['observacion'] : null,
        ];
    }
    sqlsrv_free_stmt($stmt);

    return [
        'id'             => (int)$cab['id'],
        'proveedor'      => (string)$cab['proveedor'],
        'usuario_portal' => (string)$cab['usuario_portal'],
        'auditor'        => (string)$cab['auditor'],
        'comentarios'    => $cab['comentarios'] !== null ? (string)$cab['comentarios'] : null,
        'estado'         => (string)$cab['estado'],
        'creado_en'      => $fmt($cab['creado_en']),
        'cerrado_en'     => $fmt($cab['cerrado_en']),
        'correo_enviado' => (bool)$cab['correo_enviado'],
        'puntos'         => $puntos,
    ];
}

/**
 * Cierra la auditoría. Devuelve false si ya estaba cerrada.
 *
 * La condición estado='en_curso' viaja DENTRO del WHERE, no en un if previo: es la
 * barrera atómica que impide que dos peticiones simultáneas cierren la misma auditoría
 * y disparen dos correos.
 */
function verif_cerrar($conn, int $auditoriaId, ?string $comentarios): bool {
    $c = ($comentarios === null || trim($comentarios) === '') ? null : trim($comentarios);

    $sql = "UPDATE verificacion_auditoria
            SET estado = 'cerrada', comentarios = ?, cerrado_en = SYSDATETIME()
            WHERE id = ? AND estado = 'en_curso'";
    $stmt = sqlsrv_query($conn, $sql, [$c, $auditoriaId]);
    if ($stmt === false) {
        throw new RuntimeException('Cerrar auditoría falló: ' . print_r(sqlsrv_errors(), true));
    }
    $filas = sqlsrv_rows_affected($stmt);
    sqlsrv_free_stmt($stmt);
    return $filas === 1;
}

/** Deja constancia de que el correo salió. Se separa del cierre a propósito. */
function verif_marcar_correo_enviado($conn, int $auditoriaId): void {
    $stmt = sqlsrv_query($conn, "UPDATE verificacion_auditoria SET correo_enviado = 1 WHERE id = ?", [$auditoriaId]);
    if ($stmt === false) {
        throw new RuntimeException('Marcar correo falló: ' . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);
}

/**
 * A quién se avisa cuando se cierra una auditoría.
 *
 * El proveedor centinela devuelve lista vacía SIEMPRE, y verif_enviar() trata la lista
 * vacía como error. Así una fila de prueba es incapaz de producir un envío aunque alguien
 * la empuje por el camino de producción: no depende de recordar apagar ninguna bandera.
 *
 * El require de config_mail.php va AQUÍ y no en verif_enviar(): PHP evalúa los argumentos
 * antes de entrar a la función, así que en verif_enviar($p, verif_destinatarios(...), $r)
 * esta función correría ANTES de que el require de allá definiera la constante, y
 * devolvería [] siempre — incluso con los destinatarios bien configurados.
 */
function verif_destinatarios(string $proveedor): array {
    if ($proveedor === VERIF_PROVEEDOR_TEST) return [];

    $cfg = __DIR__ . '/../conexion/config_mail.php';
    if (is_file($cfg)) require_once $cfg;

    if (!defined('MAIL_AUDITORIA_TO')) return [];
    return array_values(array_filter(array_map('trim', (array)MAIL_AUDITORIA_TO)));
}

/**
 * Los correos que reciben COPIA VISIBLE del aviso (Coordinación de Inventarios).
 *
 * Hermana de verif_destinatarios(), con la misma guarda del proveedor centinela y el mismo
 * motivo para hacer el require aquí. Pero con una diferencia deliberada: una lista vacía
 * NO es un error. Sin destinatarios el aviso no le llega a nadie y hay que fallar; sin
 * copia el aviso llega igual a quien debe, y quedarse sin enviar sería peor que ir sin
 * copia. Por eso el que lanza la excepción es verif_enviar() y solo mira los destinatarios.
 */
function verif_copias(string $proveedor): array {
    if ($proveedor === VERIF_PROVEEDOR_TEST) return [];

    $cfg = __DIR__ . '/../conexion/config_mail.php';
    if (is_file($cfg)) require_once $cfg;

    if (!defined('MAIL_AUDITORIA_CC')) return [];
    return array_values(array_filter(array_map('trim', (array)MAIL_AUDITORIA_CC)));
}

/**
 * A quién se le escribe de verdad, ya separado en destinatarios y copias.
 *
 * Es una función pura y existe por eso: la regla que encierra no se puede comprobar de
 * ninguna otra forma sin mandar un correo de verdad. En modo prueba el aviso va SOLO al
 * buzón de ensayo Y LAS COPIAS SE DESCARTAN — si no, ensayar un cierre le escribiría a
 * Coordinación de Inventarios, que es justo a quien no se quiere molestar al ensayar.
 *
 * @return array{to:string[], cc:string[]}
 */
function verif_envio_destinos(array $destinatarios, array $copias): array {
    if (defined('MAIL_TEST_TO') && MAIL_TEST_TO !== '') {
        return ['to' => [MAIL_TEST_TO], 'cc' => []];
    }
    return ['to' => array_values($destinatarios), 'cc' => array_values($copias)];
}

/** El resultado, en el texto que ve una persona. Cadena vacía = sin marcar. */
function verif_etiqueta(string $resultado): string {
    switch ($resultado) {
        case 'aprobado':    return 'Aprobado';
        case 'no_aprobado': return 'No aprobado';
        case 'no_aplica':   return 'No aplica';
        default:            return '—';
    }
}

/**
 * Compone todo lo que hace falta para comunicar una auditoría. NO ENVÍA NADA.
 *
 * Existe separada de verif_enviar() a propósito: el envío es irreversible, así que lo
 * que ejercitan los tests es solo esta mitad.
 *
 * @return array{auditoria:array, asunto:string, cuerpo_html:string, nombre_pdf:string, filas:array}
 */
function verif_armar_paquete($conn, int $auditoriaId): array {
    $a = verif_cargar($conn, $auditoriaId);
    if ($a === null) {
        throw new RuntimeException('La auditoría ' . $auditoriaId . ' no existe.');
    }

    // Se recorre VERIF_INFORMES y no lo que trajo la base: así el orden es estable y un
    // informe sin marcar aparece igual, en su sitio, en vez de desaparecer de la tabla.
    $filas = [];
    foreach (VERIF_INFORMES as $clave => $nombre) {
        $p = $a['puntos'][$clave] ?? null;
        $filas[] = [
            'clave'       => $clave,
            'nombre'      => $nombre,
            'resultado'   => $p['resultado'] ?? '',
            'etiqueta'    => verif_etiqueta($p['resultado'] ?? ''),
            'observacion' => $p['observacion'] ?? null,
        ];
    }

    $fecha = substr((string)($a['cerrado_en'] ?? $a['creado_en']), 0, 10);

    // El nombre del adjunto se arma con el proveedor, que viene de la base: se le quita
    // todo lo que no sea alfanumérico para que no pueda colarse un separador de ruta.
    $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $a['proveedor']);
    $slug = trim((string)$slug, '_');
    if ($slug === '') $slug = 'aliado';

    $cuerpo = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#2d2b4e;">'
        . '<p>Se diligenció el formulario de control de la Plataforma AKA 2.0.</p>'
        . '<table cellpadding="6" style="border-collapse:collapse;font-size:14px;">'
        . '<tr><td><b>Tercero auditado</b></td><td>' . htmlspecialchars($a['proveedor'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td><b>Auditor</b></td><td>' . htmlspecialchars($a['auditor'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td><b>Usuario del portal</b></td><td>' . htmlspecialchars($a['usuario_portal'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '<tr><td><b>Fecha</b></td><td>' . htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . '</table>'
        . '<p>El detalle de los puntos de control y sus observaciones va en el PDF adjunto.</p>'
        . '</div>';

    return [
        'auditoria'   => $a,
        'asunto'      => 'VERIFICACIÓN DE PLATAFORMA — ' . $a['proveedor'] . ' — ' . $fecha,
        'cuerpo_html' => $cuerpo,
        'nombre_pdf'  => 'verificacion_' . $slug . '_' . str_replace('-', '', $fecha) . '.pdf',
        'filas'       => $filas,
    ];
}

/**
 * Envía el aviso con el PDF adjunto. ES IRREVERSIBLE — por eso vive separada de
 * verif_armar_paquete() y ningún test la invoca con datos que puedan salir.
 *
 * Las dos guardas de arriba no son paranoia: una lista vacía significaría un envío a
 * nadie (despliegue sin configurar) y un PDF ausente significaría avisar sin el contenido.
 * En ambos casos es preferible fallar ruidosamente. Van ANTES de cualquier require: si se
 * invirtiera el orden, un despliegue sin configurar abriría una conexión SMTP antes de
 * descubrir que no tiene a quién escribirle.
 *
 * $copias va al final y con valor por omisión: un despliegue sin MAIL_AUDITORIA_CC manda
 * el aviso igual, solo que sin copia.
 *
 * @throws RuntimeException si no hay destinatarios, si falta el PDF o si el SMTP falla.
 */
function verif_enviar(array $paquete, array $destinatarios, string $rutaPdf, array $copias = []): void {
    if (!$destinatarios) {
        throw new RuntimeException('No hay destinatarios configurados (MAIL_AUDITORIA_TO).');
    }
    if (!is_file($rutaPdf)) {
        throw new RuntimeException('No se encontró el PDF a adjuntar: ' . $rutaPdf);
    }

    require_once __DIR__ . '/../conexion/config_mail.php';
    require_once __DIR__ . '/../PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USER;
    $mail->Password   = MAIL_PASS;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;

    $mail->setFrom(MAIL_USER, MAIL_FROM_NAME);

    // Quién recibe qué lo decide verif_envio_destinos() y no este bloque: allá vive la
    // regla del modo prueba y allá es donde los tests pueden mirarla. Aquí solo se copian
    // las dos listas al mensaje, y no hay ninguna tercera vía de agregar a nadie.
    $destinos = verif_envio_destinos($destinatarios, $copias);
    foreach ($destinos['to'] as $d) $mail->addAddress($d);
    foreach ($destinos['cc'] as $c) $mail->addCC($c);

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = $paquete['asunto'];
    $mail->Body    = $paquete['cuerpo_html'];
    $mail->addAttachment($rutaPdf, $paquete['nombre_pdf']);

    $mail->send();
}
