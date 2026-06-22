<?php
// TRM con cache diario en archivo + fallback al último valor conocido.
function obtener_trm(): array {
  $dir = __DIR__ . '/../cache';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $hoy = date('Y-m-d');
  $file = $dir . '/trm_' . $hoy . '.json';
  if (is_file($file)) { $j = json_decode((string)file_get_contents($file), true); if ($j) return $j; }
  // Fetch (mismo proveedor que el PBI): exchangerate-api
  $usd = null; $eur = null;
  $ctx = stream_context_create(['http'=>['timeout'=>3]]);
  $ru = @file_get_contents('https://api.exchangerate-api.com/v4/latest/USD', false, $ctx);
  $re = @file_get_contents('https://api.exchangerate-api.com/v4/latest/EUR', false, $ctx);
  if ($ru) { $a=json_decode($ru,true); $usd=$a['rates']['COP']??null; }
  if ($re) { $a=json_decode($re,true); $eur=$a['rates']['COP']??null; }
  if ($usd !== null && $eur !== null) {
    $res = ['USD'=>(float)$usd,'EUR'=>(float)$eur,'fecha'=>$hoy,'fallback'=>false];
    @file_put_contents($file, json_encode($res));
    return $res;
  }
  // Fallback: último trm_*.json
  $prev = glob($dir . '/trm_*.json') ?: []; rsort($prev);
  if ($prev) { $j = json_decode((string)file_get_contents($prev[0]), true); if ($j) { $j['fallback']=true; return $j; } }
  return ['USD'=>0.0,'EUR'=>0.0,'fecha'=>$hoy,'fallback'=>true];
}
