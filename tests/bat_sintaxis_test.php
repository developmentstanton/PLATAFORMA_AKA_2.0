<?php
// Regresión de la SINTAXIS de los .bat de sql/: cada uno debe ser parseable por cmd.exe y llegar
// hasta su última línea.
//
// Bug (2026-07-16, 6 días caído en WMS-LAB): la copia desplegada de prebuild_all.bat tenía, dentro
// del bloque `if not defined PHP_EXE ( ... )`, un echo con paréntesis SIN escapar:
//     echo ... no se encontro php.exe (revisar rutas en el .bat) >> "%~dp0prebuild_all.log"
// El ")" de "el .bat)" cierra el if antes de tiempo y deja el `exit /b 1` FUERA del bloque, así que
// se ejecuta SIEMPRE: la tarea nocturna arrancaba puntual, moría en milisegundos con errorlevel 1 y
// ni siquiera alcanzaba a crear el log, así que no dejaba rastro. Reproducido: la variante con ^(
// imprime la última línea y sale 0; la variante sin escapar sale 1 en silencio, incluso con
// PHP_EXE definido (el fallo es de PARSEO, no de la condición).
// Misma familia: los saltos LF rompen los bloques multilínea de cmd -> .gitattributes fuerza CRLF.
//
//   php tests/bat_sintaxis_test.php
//
// Ejecuta cada .bat DE VERDAD con cmd, pero sobre una copia donde la invocación a php se sustituye
// por un marcador: comprueba el flujo real sin lanzar prebuilds ni tocar la BD.

if (stripos(PHP_OS, 'WIN') !== 0) { echo "SKIP: los .bat solo se pueden ejecutar en Windows.\n"; exit(0); }

$dir   = __DIR__ . '/../sql';
$bats  = glob($dir . '/*.bat') ?: [];
$tmp   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'batsintaxis_' . getmypid();
$MARCA = 'BAT-LLEGO-AL-FINAL';
@mkdir($tmp, 0777, true);

$fallos = []; $limpiar = [];
echo "SINTAXIS DE LOS .bat DE sql/\n" . str_repeat('=', 72) . "\n";

foreach ($bats as $bat) {
    $nom = basename($bat);
    $src = (string) file_get_contents($bat);

    // (1) CRLF: un .bat con saltos LF hace que cmd parsee mal los bloques if(...)/for(...).
    $lfSueltos = preg_match_all('/(?<!\r)\n/', $src);
    $crlfOk    = ($lfSueltos === 0);

    // (2) Parseo real: copia con la llamada a php sustituida por el marcador, para no lanzar el
    //     trabajo pesado. Si la sustitución NO aplica, abortamos: NO queremos correr el prebuild.
    $mod = preg_replace('/^\s*"%PHP_EXE%".*$/m', 'echo ' . $MARCA, $src, -1, $n);
    if ($n < 1) { $fallos[] = "$nom: no se encontró la línea que invoca a php; el test no lo ejecuta para no disparar el trabajo real"; continue; }
    $mod = preg_replace('/^\s*endlocal\s*$/m', "echo $MARCA-ENDLOCAL\r\nendlocal", $mod, 1);

    $copia = $tmp . DIRECTORY_SEPARATOR . $nom;
    file_put_contents($copia, $mod);
    $limpiar[] = $copia;

    $out = []; $code = 1;
    exec('cmd /c "' . $copia . '" 2>&1', $out, $code);
    $salida  = implode("\n", $out);
    $llego   = (strpos($salida, $MARCA) !== false);

    printf("[%-24s] CRLF=%-3s  parsea_y_llega=%-3s  exit=%d\n", $nom,
        $crlfOk ? 'SI' : 'NO', $llego ? 'SI' : 'NO', $code);

    if (!$crlfOk) $fallos[] = "$nom: tiene $lfSueltos salto(s) LF sueltos — cmd parsea mal los bloques multilínea (debe ser CRLF)";
    if (!$llego)  $fallos[] = "$nom: cmd NO llegó a la última línea (exit=$code). Sospecha nº1: un paréntesis sin escapar dentro de un bloque if(...) que lo cierra antes de tiempo. Salida: " . substr(trim($salida), 0, 160);
    elseif ($code !== 0) $fallos[] = "$nom: llegó al final pero salió con código $code";
}

foreach ($limpiar as $f) @unlink($f);
foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmp);

echo str_repeat('=', 72) . "\n";
if (!$bats)   { echo "FALLO: no se encontró ningún .bat en sql/\n"; exit(1); }
if ($fallos)  { foreach ($fallos as $f) echo "FALLO: $f\n"; exit(1); }
echo "OK: los " . count($bats) . " .bat son CRLF y cmd los recorre hasta el final.\n";
exit(0);
