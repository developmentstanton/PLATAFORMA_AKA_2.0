<?php
// Regresión del CACHE DIARIO DE FILTROS (evol/o45/o14/geo): el cache debe ahorrar el trabajo CARO,
// no solo el SELECT final.
//
// Bug (2026-07-16, llegó a producción): los 4 endpoints chequeaban su cache diario DESPUÉS de
// construir #base (seis INSERT contra la RDS), así que un hit costaba lo mismo que un miss y solo
// se ahorraba el SELECT DISTINCT. Medido en dev con el cache ya escrito: evol 13.9s, o14 3.0s,
// o45 2.1s, geo 1.5s — en WMS-LAB (enlace de 0.21 MB/s) evol llegaba a ~19s. Se paga en CADA carga
// de CADA usuario. Arreglo: chequear el cache arriba, antes de conectar y de construir #base.
//
//   php tests/filtros_cache_test.php ["PROVEEDOR"] [evol|o45|o14|geo]
//
// Subprocesa los endpoints REALES (vía tests/_endpoint_run_*.php) → sin duplicar SQL ni credenciales.

$prov  = $argv[1] ?? 'BH BRANDS SAS';
$solo  = $argv[2] ?? null;
$php   = PHP_BINARY;
$nul   = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
$UMBRAL_MS = 3000;   // un hit es leer un .json: ~ms. 3s deja margen de sobra al arranque de PHP.

$ENDPOINTS = [   // clave => [runner, prefijo del archivo de cache]
    'evol' => ['_endpoint_run_evol.php', 'evol_filtros_'],
    'o45'  => ['_endpoint_run_o45.php',  'o45_filtros_'],
    'o14'  => ['_endpoint_run_o14.php',  'o14_filtros_'],
    'geo'  => ['_endpoint_run_geo.php',  'geo_filtros_'],
];
if ($solo !== null) {
    if (!isset($ENDPOINTS[$solo])) { echo "Endpoint desconocido: $solo (evol|o45|o14|geo)\n"; exit(2); }
    $ENDPOINTS = [$solo => $ENDPOINTS[$solo]];
}

function call_filtros($php, $runner, $prov, $nul): array {
    $cmd = escapeshellarg($php) . ' -d display_startup_errors=0 -d display_errors=stderr '
         . escapeshellarg($runner) . ' ' . escapeshellarg($prov) . ' ' . escapeshellarg('tab=filtros') . ' 2>' . $nul;
    $t0  = microtime(true);
    $raw = (string) shell_exec($cmd);
    $ms  = (int) round((microtime(true) - $t0) * 1000);
    $a = strpos($raw, '{'); $b = strrpos($raw, '}');
    $json = ($a === false || $b === false) ? '' : substr($raw, $a, $b - $a + 1);
    return [$ms, $json, json_decode($json, true)];
}

echo "CACHE DIARIO DE FILTROS — proveedor = $prov\n" . str_repeat('=', 74) . "\n";
$fallos = [];

foreach ($ENDPOINTS as $nom => [$runnerFile, $prefijo]) {
    $runner    = __DIR__ . '/' . $runnerFile;
    $cacheFile = __DIR__ . '/../cache/' . $prefijo . md5($prov) . '.json';
    if (!is_file($runner)) { echo "[$nom] SKIP: no existe $runnerFile\n\n"; continue; }

    // Partir de cero: un archivo del día previo enmascararía la transición miss->hit.
    if (is_file($cacheFile)) unlink($cacheFile);

    [$ms1, $j1, $d1] = call_filtros($php, $runner, $prov, $nul);
    [$ms2, $j2, $d2] = call_filtros($php, $runner, $prov, $nul);

    printf("[%-4s] miss %6d ms   hit %6d ms   %s\n", $nom, $ms1, $ms2, number_format(strlen($j2)) . ' bytes');

    if (!is_array($d1) || ($d1['ok'] ?? false) !== true) { $fallos[] = "$nom: la 1ª llamada no devolvió ok=true"; echo "\n"; continue; }
    if (!is_file($cacheFile))                            { $fallos[] = "$nom: la 1ª llamada no escribió el cache"; echo "\n"; continue; }
    if (!is_array($d2) || ($d2['ok'] ?? false) !== true)   $fallos[] = "$nom: la 2ª llamada no devolvió ok=true";
    if ($j1 !== $j2)                                       $fallos[] = "$nom: el hit devolvió un payload DISTINTO al del miss (el cache no es fiel)";
    if ($ms2 > $UMBRAL_MS)                                 $fallos[] = sprintf('%s: el hit tardó %d ms (umbral %d ms) — el cache se consulta DESPUÉS del build de #base', $nom, $ms2, $UMBRAL_MS);
    echo "\n";
}

echo str_repeat('=', 74) . "\n";
if ($fallos) { foreach ($fallos as $f) echo "FALLO: $f\n"; exit(1); }
echo "OK: los " . count($ENDPOINTS) . " endpoints sirven su cache de filtros sin tocar la BD.\n";
exit(0);
