<?php
// Captura por proveedor: el dataset (tab=dataset) y la salida oráculo (tab=data), a JSON.
// Ademas captura 2-3 oraculos tab=data CON filtros reales derivados del propio dataset
// (marca, grupo, negocio) para que el golden JS compare tambien el camino filtrado.
//
// GOTCHA: los warnings de arranque de PHP (xdebug/dio/openssl) en este entorno contaminan
// stdout y corrompen el JSON capturado por shell_exec. Por eso invocamos con
// -d display_errors=0 -d display_startup_errors=0 ademas del error_reporting(0) interno.
error_reporting(0); ini_set('display_errors','0');

function endpoint($tab, $prov, $extra = []) {
    $parts = array_map('escapeshellarg', array_merge([$tab, $prov], $extra));
    $cmd = sprintf(
        'php -d display_errors=0 -d display_startup_errors=0 %s/../../o45_call.php %s',
        __DIR__,
        implode(' ', $parts)
    );
    return shell_exec($cmd);
}

function primerValor(array $ds, string $col): ?string {
    if (empty($ds['columnas']) || empty($ds['filas'])) return null;
    $idx = array_search($col, $ds['columnas'], true);
    if ($idx === false) return null;
    foreach ($ds['filas'] as $row) {
        $v = $row[$idx];
        if ($v !== '' && $v !== null) return $v;
    }
    return null;
}

function primerNegocio(array $ds): ?string {
    if (empty($ds['columnas']) || empty($ds['filas'])) return null;
    $ir = array_search('referencia', $ds['columnas'], true);
    $ic = array_search('color', $ds['columnas'], true);
    if ($ir === false || $ic === false) return null;
    foreach ($ds['filas'] as $row) {
        if ($row[$ir] !== '' && $row[$ir] !== null) return $row[$ir] . '-' . $row[$ic];
    }
    return null;
}

// Proveedores rápidos primero; BRAHMA CONCEPT es lento (dataset ~15s + cada oráculo ~15s).
$provs = ['BH BRANDS SAS', 'CALZADO WALDOS', 'BELLINO', 'BRAHMA CONCEPT'];

foreach ($provs as $p) {
    $slug = preg_replace('/[^a-z0-9]+/i', '_', strtolower($p));

    $datasetJson = endpoint('dataset', $p);
    file_put_contents(__DIR__ . "/dataset_$slug.json", $datasetJson);
    file_put_contents(__DIR__ . "/tabdata_$slug.json", endpoint('data', $p));
    echo "capturado $p (sin filtro)\n";

    $ds = json_decode($datasetJson, true);
    if (!is_array($ds) || empty($ds['columnas']) || empty($ds['filas'])) {
        echo "  (dataset vacio o invalido, sin fixtures filtradas para $p)\n";
        continue;
    }

    $casos = [
        'marca'   => ['col' => 'marca',   'valor' => primerValor($ds, 'marca')],
        'grupo'   => ['col' => 'grupo',   'valor' => primerValor($ds, 'grupo')],
        'negocio' => ['col' => 'negocio', 'valor' => primerNegocio($ds)],
    ];

    foreach ($casos as $tag => $c) {
        if ($c['valor'] === null) continue;
        $filtros = [$c['col'] => [$c['valor']]];
        file_put_contents(__DIR__ . "/tabdata_{$slug}__{$tag}.json", endpoint('data', $p, ["{$c['col']}={$c['valor']}"]));
        file_put_contents(__DIR__ . "/filtros_{$slug}__{$tag}.json", json_encode($filtros, JSON_UNESCAPED_UNICODE));
        echo "  filtro {$c['col']}={$c['valor']}\n";
    }
}

echo "\nFixtures listas en " . __DIR__ . "\n";
