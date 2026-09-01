<?php
// Los tres endpoints de verificacion deben rechazar a quien no tiene sesion (401) y a quien
// no trae CSRF (403), ANTES de tocar la base. Un endpoint de escritura sin guard propio es
// una puerta abierta aunque la seccion este escondida detras de ?auditoria=1: el parametro
// es visibilidad, no seguridad.
//
//   php tests/verificacion_guard_test.php
//
// Requiere Apache corriendo en localhost.

$base = 'http://localhost/plataforma_20/api/';

$fallos = [];
function chequear(string $caso, bool $ok, string $detalle = '') {
    global $fallos;
    echo ($ok ? '  OK   ' : '  FALLA') . "  $caso" . ($detalle ? " -> $detalle" : '') . "\n";
    if (!$ok) $fallos[] = $caso;
}

function postear(string $url, array $campos): int {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($campos),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $codigo;
}

echo "GUARDS DE LOS ENDPOINTS DE VERIFICACION\n" . str_repeat('=', 70) . "\n";

foreach (['verificacion_abrir.php', 'verificacion_guardar.php', 'verificacion_cerrar.php'] as $ep) {
    $codigo = postear($base . $ep, ['auditor' => 'X', 'auditoria_id' => 1,
                                    'informe' => 'g00', 'resultado' => 'aprobado']);
    chequear("$ep sin sesion responde 401", $codigo === 401, "respondio $codigo");
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "Nota: el caso 403 (sesion valida sin CSRF) se verifica a mano desde el navegador,\n";
echo "porque exige una sesion real. Ver Task 6, paso de verificacion visual.\n";
if ($fallos) { echo 'RESULTADO: FALLAN ' . count($fallos) . "\n"; exit(1); }
echo "RESULTADO: OK\n";
