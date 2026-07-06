<?php
// Captura por proveedor: el dataset (tab=dataset) y la salida oráculo (tab=data), a JSON.
// Ademas captura oraculos tab=data CON filtros reales derivados del propio dataset
// (marca, grupo, tienda, negocio) para que el golden JS compare tambien el camino filtrado.
// Los valores de filtro se derivan de filas con ACTIVIDAD real (ventas!=0 o disp+hold>0), y
// para grupo/tienda ademas se excluyen filas de BODEGA/ADMINISTRATIVAS, para garantizar que
// al menos un caso filtrado ejercite de verdad la rama de total.tiendas>0.
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

// Una fila "tiene actividad" si vendió algo O tiene stock (disp+hold) — mismo criterio que
// el review pidió para garantizar que el filtro elegido de veras ejercite la rama de tiendas
// (si eligiéramos un valor de una fila 100% inactiva, total.tiendas daría 0 igual que antes).
function filaActiva(array $row, int $iv, int $id, int $ih): bool {
    return (int)$row[$iv] !== 0 || ((int)$row[$id] + (int)$row[$ih]) > 0;
}

// Elige un valor de $col tomado de una fila con actividad real. Para grupo/tienda además
// excluye filas cuyo grupo sea BODEGA o ADMINISTRATIVAS (esos grupos nunca cuentan como
// "tienda" en aggregateO45, así que un valor derivado de ahí nunca movería total.tiendas).
function valorActivo(array $ds, string $col, array $excluirGrupos = []): ?string {
    if (empty($ds['columnas']) || empty($ds['filas'])) return null;
    $ic = array_search($col, $ds['columnas'], true);
    $ig = array_search('grupo', $ds['columnas'], true);
    $iv = array_search('ventas', $ds['columnas'], true);
    $id = array_search('disponible', $ds['columnas'], true);
    $ih = array_search('hold', $ds['columnas'], true);
    if ($ic === false || $iv === false || $id === false || $ih === false) return null;
    foreach ($ds['filas'] as $row) {
        $v = $row[$ic];
        if ($v === '' || $v === null) continue;
        if ($excluirGrupos && $ig !== false && in_array($row[$ig], $excluirGrupos, true)) continue;
        if (!filaActiva($row, $iv, $id, $ih)) continue;
        return $v;
    }
    return null;
}

function negocioActivo(array $ds): ?string {
    if (empty($ds['columnas']) || empty($ds['filas'])) return null;
    $ir = array_search('referencia', $ds['columnas'], true);
    $ic = array_search('color', $ds['columnas'], true);
    $iv = array_search('ventas', $ds['columnas'], true);
    $id = array_search('disponible', $ds['columnas'], true);
    $ih = array_search('hold', $ds['columnas'], true);
    if ($ir === false || $ic === false || $iv === false || $id === false || $ih === false) return null;
    foreach ($ds['filas'] as $row) {
        if ($row[$ir] === '' || $row[$ir] === null) continue;
        if (!filaActiva($row, $iv, $id, $ih)) continue;
        return $row[$ir] . '-' . $row[$ic];
    }
    return null;
}

// Proveedores rápidos primero; BRAHMA CONCEPT es lento (dataset ~15-30s + cada oráculo ~15-30s).
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
        'marca'   => ['col' => 'marca',   'valor' => valorActivo($ds, 'marca')],
        'grupo'   => ['col' => 'grupo',   'valor' => valorActivo($ds, 'grupo', ['BODEGA', 'ADMINISTRATIVAS'])],
        'tienda'  => ['col' => 'tienda',  'valor' => valorActivo($ds, 'tienda', ['BODEGA', 'ADMINISTRATIVAS'])],
        'negocio' => ['col' => 'negocio', 'valor' => negocioActivo($ds)],
    ];
    // El dataset de proveedores grandes (BRAHMA CONCEPT ~60k filas) ocupa >100MB decodificado;
    // ya derivamos los valores de filtro, así que lo liberamos antes de los shell_exec lentos.
    unset($ds, $datasetJson);

    foreach ($casos as $tag => $c) {
        if ($c['valor'] === null) continue;
        $filtros = [$c['col'] => [$c['valor']]];
        file_put_contents(__DIR__ . "/tabdata_{$slug}__{$tag}.json", endpoint('data', $p, ["{$c['col']}={$c['valor']}"]));
        file_put_contents(__DIR__ . "/filtros_{$slug}__{$tag}.json", json_encode($filtros, JSON_UNESCAPED_UNICODE));
        echo "  filtro {$c['col']}={$c['valor']}\n";
    }
}

echo "\nFixtures listas en " . __DIR__ . "\n";
