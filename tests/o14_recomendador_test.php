<?php
/**
 * Tests del motor de recomendaciones O14 (api/o14_recomendador.php).
 * Harness liviano (sin composer/phpunit). Correr:  php tests/o14_recomendador_test.php
 *
 * Convención de fixtures:
 *   $tiendas = lista de tiendas de UN negocio:
 *     ['cod'=>'245','tallas'=>['9'=>['siembra'=>4,'disponible'=>0,'hold'=>0], ...]]
 *   $cedi    = stock disponible en CEDI por talla:  ['9'=>5,'10'=>2]
 * El motor calcula faltante/sobrante = siembra-(disponible+hold) por tienda+talla.
 */

require __DIR__ . '/../api/o14_recomendador.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function check($nombre, $actual, $esperado) {
    if ($actual == $esperado) {
        $GLOBALS['__pass']++;
        echo "  PASS  $nombre\n";
    } else {
        $GLOBALS['__fail']++;
        echo "  FAIL  $nombre\n";
        echo "        esperado: " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n";
        echo "        actual:   " . json_encode($actual,   JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// ====================================================================
// Test 1 — Una tienda con faltante, sin fuentes ni CEDI → solicitud a proveedor
// ====================================================================
(function () {
    $tiendas = [
        ['cod' => '245', 'tallas' => ['9' => ['siembra' => 4, 'disponible' => 0, 'hold' => 0]]],
    ];
    $cedi = [];
    $plan = recomendar($tiendas, $cedi);

    check('t1: sin reubicaciones',  $plan['reubicaciones'],         []);
    check('t1: sin solicitud CEDI', $plan['solicitudes_cedi'],      []);
    check('t1: solicita 4 a proveedor talla 9',
        $plan['solicitudes_proveedor'], [['talla' => '9', 'uds' => 4]]);
    check('t1: resumen',
        $plan['resumen'],
        ['faltante_total' => 4, 'por_reubicacion' => 0, 'por_cedi' => 0, 'a_proveedor' => 4]);
})();

// ====================================================================
// Test 3 — Una tienda con sobrante cubre a otra con faltante (misma talla)
// ====================================================================
(function () {
    $tiendas = [
        ['cod' => '245', 'tallas' => ['9' => ['siembra' => 4, 'disponible' => 1, 'hold' => 0]]], // faltante 3
        ['cod' => '300', 'tallas' => ['9' => ['siembra' => 2, 'disponible' => 5, 'hold' => 0]]], // sobrante 3
    ];
    $plan = recomendar($tiendas, []);

    check('t3: reubica 3 de 300→245 talla 9',
        $plan['reubicaciones'], [['origen' => '300', 'destino' => '245', 'talla' => '9', 'uds' => 3]]);
    check('t3: sin solicitud CEDI',      $plan['solicitudes_cedi'],      []);
    check('t3: sin solicitud proveedor', $plan['solicitudes_proveedor'], []);
    check('t3: resumen',
        $plan['resumen'],
        ['faltante_total' => 3, 'por_reubicacion' => 3, 'por_cedi' => 0, 'a_proveedor' => 0]);
})();

// ====================================================================
// Test 4 — Reubicación parcial; CEDI cubre el resto (no llega a proveedor)
// ====================================================================
(function () {
    $tiendas = [
        ['cod' => '245', 'tallas' => ['10' => ['siembra' => 5, 'disponible' => 0, 'hold' => 0]]], // faltante 5
        ['cod' => '300', 'tallas' => ['10' => ['siembra' => 2, 'disponible' => 4, 'hold' => 0]]], // sobrante 2
    ];
    $cedi = ['10' => 10];
    $plan = recomendar($tiendas, $cedi);

    check('t4: reubica 2 de 300→245 talla 10',
        $plan['reubicaciones'], [['origen' => '300', 'destino' => '245', 'talla' => '10', 'uds' => 2]]);
    check('t4: CEDI cubre 3 a 245 talla 10',
        $plan['solicitudes_cedi'], [['destino' => '245', 'talla' => '10', 'uds' => 3]]);
    check('t4: sin solicitud proveedor', $plan['solicitudes_proveedor'], []);
    check('t4: resumen',
        $plan['resumen'],
        ['faltante_total' => 5, 'por_reubicacion' => 2, 'por_cedi' => 3, 'a_proveedor' => 0]);
})();

// ====================================================================
// Test 5 — Cascada completa: reubicación + CEDI insuficientes → desborde a proveedor
// ====================================================================
(function () {
    $tiendas = [
        ['cod' => '245', 'tallas' => ['11' => ['siembra' => 10, 'disponible' => 0, 'hold' => 0]]], // faltante 10
        ['cod' => '300', 'tallas' => ['11' => ['siembra' => 0,  'disponible' => 2, 'hold' => 0]]], // sobrante 2
    ];
    $cedi = ['11' => 3];
    $plan = recomendar($tiendas, $cedi);

    check('t5: reubica 2', $plan['reubicaciones'], [['origen' => '300', 'destino' => '245', 'talla' => '11', 'uds' => 2]]);
    check('t5: CEDI 3',    $plan['solicitudes_cedi'], [['destino' => '245', 'talla' => '11', 'uds' => 3]]);
    check('t5: proveedor 5', $plan['solicitudes_proveedor'], [['talla' => '11', 'uds' => 5]]);
    check('t5: resumen',
        $plan['resumen'],
        ['faltante_total' => 10, 'por_reubicacion' => 2, 'por_cedi' => 3, 'a_proveedor' => 5]);
})();

// ====================================================================
// Test 6 — Múltiples tallas se resuelven independientemente
// ====================================================================
(function () {
    $tiendas = [
        ['cod' => '245', 'tallas' => [
            '9'  => ['siembra' => 3, 'disponible' => 0, 'hold' => 0],  // faltante 3
            '10' => ['siembra' => 0, 'disponible' => 2, 'hold' => 0],  // sobrante 2 (sin destino)
        ]],
        ['cod' => '300', 'tallas' => [
            '9'  => ['siembra' => 0, 'disponible' => 3, 'hold' => 0],  // sobrante 3 → cubre 245/9
            '10' => ['siembra' => 1, 'disponible' => 0, 'hold' => 0],  // faltante 1 → cubre con 245/10
        ]],
    ];
    $plan = recomendar($tiendas, []);

    check('t6: reubicaciones por talla',
        $plan['reubicaciones'], [
            ['origen' => '300', 'destino' => '245', 'talla' => '9',  'uds' => 3],
            ['origen' => '245', 'destino' => '300', 'talla' => '10', 'uds' => 1],
        ]);
    check('t6: sin proveedor', $plan['solicitudes_proveedor'], []);
    check('t6: resumen',
        $plan['resumen'],
        ['faltante_total' => 4, 'por_reubicacion' => 4, 'por_cedi' => 0, 'a_proveedor' => 0]);
})();

// ====================================================================
// Test 7 — Política default: mayor sobrante primero (B antes que A, aunque A va primero en el arreglo)
// ====================================================================
(function () {
    $tiendas = [
        ['cod' => '245', 'tallas' => ['9' => ['siembra' => 5, 'disponible' => 0, 'hold' => 0]]], // dest faltante 5
        ['cod' => 'A',   'tallas' => ['9' => ['siembra' => 0, 'disponible' => 2, 'hold' => 0]]], // sobrante 2
        ['cod' => 'B',   'tallas' => ['9' => ['siembra' => 0, 'disponible' => 4, 'hold' => 0]]], // sobrante 4
    ];
    $plan = recomendar($tiendas, []);

    check('t7: toma de B (mayor sobrante) antes que A',
        $plan['reubicaciones'], [
            ['origen' => 'B', 'destino' => '245', 'talla' => '9', 'uds' => 4],
            ['origen' => 'A', 'destino' => '245', 'talla' => '9', 'uds' => 1],
        ]);
})();

// ====================================================================
// Test 8 — Política inyectable sobreescribe el default (menor sobrante primero)
// ====================================================================
(function () {
    $tiendas = [
        ['cod' => '245', 'tallas' => ['9' => ['siembra' => 5, 'disponible' => 0, 'hold' => 0]]],
        ['cod' => 'A',   'tallas' => ['9' => ['siembra' => 0, 'disponible' => 2, 'hold' => 0]]],
        ['cod' => 'B',   'tallas' => ['9' => ['siembra' => 0, 'disponible' => 4, 'hold' => 0]]],
    ];
    $policyMenorPrimero = function (array $fuentes) { asort($fuentes); return array_keys($fuentes); };
    $plan = recomendar($tiendas, [], $policyMenorPrimero);

    check('t8: policy custom toma A (menor) antes que B',
        $plan['reubicaciones'], [
            ['origen' => 'A', 'destino' => '245', 'talla' => '9', 'uds' => 2],
            ['origen' => 'B', 'destino' => '245', 'talla' => '9', 'uds' => 3],
        ]);
})();

// ====================================================================
// Test 9 — Sin imbalance (tienda en el ideal) → plan vacío
// ====================================================================
(function () {
    $tiendas = [['cod' => '245', 'tallas' => ['9' => ['siembra' => 4, 'disponible' => 4, 'hold' => 0]]]];
    $plan = recomendar($tiendas, []);
    check('t9: plan vacío',
        [$plan['reubicaciones'], $plan['solicitudes_cedi'], $plan['solicitudes_proveedor']], [[], [], []]);
    check('t9: resumen en cero',
        $plan['resumen'], ['faltante_total' => 0, 'por_reubicacion' => 0, 'por_cedi' => 0, 'a_proveedor' => 0]);
})();

// ====================================================================
// Test 10 — Negocio sin siembra: hay sobrante pero ningún destino → no se mueve nada
// ====================================================================
(function () {
    $tiendas = [['cod' => '245', 'tallas' => ['9' => ['siembra' => 0, 'disponible' => 3, 'hold' => 0]]]];
    $plan = recomendar($tiendas, []);
    check('t10: sin movimientos (sobrante sin destino)',
        [$plan['reubicaciones'], $plan['solicitudes_proveedor']], [[], []]);
    check('t10: faltante_total 0', $plan['resumen']['faltante_total'], 0);
})();

// ====================================================================
// Test 11 — El hold entrante reduce el faltante (entra en la fórmula)
// ====================================================================
(function () {
    $tiendas = [['cod' => '245', 'tallas' => ['9' => ['siembra' => 5, 'disponible' => 2, 'hold' => 2]]]]; // balance 1
    $plan = recomendar($tiendas, []);
    check('t11: faltante 1 a proveedor (hold descuenta)',
        $plan['solicitudes_proveedor'], [['talla' => '9', 'uds' => 1]]);
})();

// ====================================================================
// Test 12 — Tipos de salida: cod/talla como string (no int) en el JSON
// ====================================================================
(function () {
    $tiendas = [
        ['cod' => '245', 'tallas' => ['9' => ['siembra' => 3, 'disponible' => 0, 'hold' => 0]]],
        ['cod' => '300', 'tallas' => ['9' => ['siembra' => 0, 'disponible' => 3, 'hold' => 0]]],
    ];
    $plan = recomendar($tiendas, []);
    check('t12: origen/destino/talla son string en el JSON',
        json_encode($plan['reubicaciones'][0]),
        '{"origen":"300","destino":"245","talla":"9","uds":3}');
})();

// --------------------------------------------------------------------
echo "\n  {$GLOBALS['__pass']} pass, {$GLOBALS['__fail']} fail\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
