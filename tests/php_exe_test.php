<?php
// Regresión del resolutor de php.exe (api/lib_php_exe.php), del que depende el login-prewarm.
//
// Bug (2026-07-16): index.php:96 tenía la ruta QUEMADA `C:\xampp\php\php.exe` con un comentario
// "AJUSTAR en WMS-LAB si difiere". En WMS-LAB no existe C:\xampp (el PHP es 8.2.17 y la app vive en
// E:\WMS\www) → la guarda @is_file($phpExe) daba falso, el spawn del login-prewarm NUNCA se
// disparaba, y como todo iba con @ no quedaba rastro: prewarm_login.log jamás se creó en el
// servidor. El mismo bug ya se había arreglado en sql/prebuild_all.bat (commit 4e14f4f), pero
// index.php se quedó con la ruta fija.
//
//   php tests/php_exe_test.php
//
// El resolutor NO puede depender de PHP_BINARY (bajo Apache es httpd.exe) ni de PHP_BINDIR
// (constante de compilación: aquí dice C:\php, que no existe). Deriva del php.ini realmente
// cargado, con las rutas conocidas del .bat como respaldo.

require_once __DIR__ . '/../api/lib_php_exe.php';

$fallos = [];
echo "RESOLUTOR DE php.exe\n" . str_repeat('=', 62) . "\n";

$php = resolverPhpExe();
echo "resolverPhpExe() = " . var_export($php, true) . "\n";

if ($php === null) {
    echo "\nFALLO: no resolvió ningún php.exe en esta máquina.\n";
    exit(1);
}
if (!is_file($php)) $fallos[] = "la ruta devuelta no existe: $php";

// La prueba de fuego: que sea un PHP que de verdad ejecuta. Una ruta que existe pero no arranca
// (o que apunta a otro binario) dejaría el prewarm igual de muerto, y en silencio.
// OJO: comillas SIMPLES dentro del -r. escapeshellarg() en Windows envuelve en comillas dobles y
// se come las dobles internas, dejando `echo  php-ok-  . PHP_MAJOR_VERSION;` = error de sintaxis.
$out = @shell_exec(escapeshellarg($php) . ' -r ' . escapeshellarg("echo ('php-ok-' . PHP_MAJOR_VERSION);") . ' 2>&1');
$out = is_string($out) ? trim($out) : '';
$ok  = (strpos($out, 'php-ok-') !== false);
printf("ejecuta de verdad: %s\n", $ok ? 'SI (' . substr($out, strpos($out, 'php-ok-')) . ')' : 'NO — salida: ' . substr($out, 0, 120));
if (!$ok) $fallos[] = 'la ruta devuelta no ejecuta código PHP';

// No debe apoyarse en PHP_BINARY: bajo Apache vale httpd.exe y el spawn lanzaría el servidor web.
if (stripos($php, 'httpd') !== false) $fallos[] = 'devolvió httpd.exe (se está apoyando en PHP_BINARY)';

echo str_repeat('=', 62) . "\n";
if ($fallos) { foreach ($fallos as $f) echo "FALLO: $f\n"; exit(1); }
echo "OK: resolvió un php.exe real y ejecutable.\n";
exit(0);
