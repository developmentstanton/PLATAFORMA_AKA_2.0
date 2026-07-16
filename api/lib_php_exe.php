<?php
/**
 * Resuelve la ruta del intérprete php.exe para lanzar procesos en 2o plano (login-prewarm).
 *
 * Por qué existe: index.php la tenía QUEMADA a 'C:\xampp\php\php.exe' con un "AJUSTAR en WMS-LAB si
 * difiere" que nunca se ajustó. En WMS-LAB no hay C:\xampp → la guarda is_file() daba falso y el
 * login-prewarm no se disparaba NUNCA, en silencio (todo iba con @). Espeja la autodetección que
 * sql/prebuild_all.bat ya tiene desde el commit 4e14f4f.
 *
 * NO sirven los caminos "obvios" (verificado en dev, 2026-07-16):
 *   - PHP_BINARY  bajo Apache vale 'C:\xampp\apache\bin\httpd.exe' (lanzaría el servidor web).
 *   - PHP_BINDIR  es constante de COMPILACIÓN: dice 'C:\php', que ni siquiera existe.
 * Sí sirve php_ini_loaded_file(): refleja la instalación que está corriendo de verdad.
 *
 * Ver tests/php_exe_test.php.
 */
if (!function_exists('resolverPhpExe')) {
    function resolverPhpExe(): ?string {
        $exe = (stripos(PHP_OS, 'WIN') === 0) ? 'php.exe' : 'php';

        // 1) Derivar del php.ini que cargó ESTE proceso: es la instalación real, sin adivinar.
        $ini = php_ini_loaded_file();
        if ($ini !== false && $ini !== '') {
            $c = dirname($ini) . DIRECTORY_SEPARATOR . $exe;
            if (@is_file($c)) return $c;
        }

        // 2) En CLI el propio binario es la respuesta (el .bat y el prebuild corren así).
        if (PHP_SAPI === 'cli' && defined('PHP_BINARY') && PHP_BINARY !== ''
            && stripos(PHP_BINARY, 'httpd') === false && @is_file(PHP_BINARY)) return PHP_BINARY;

        // 3) Respaldo: las mismas rutas conocidas que prueba sql/prebuild_all.bat, en el mismo orden.
        foreach (['E:\\WMS\\PHP\\8.2\\php.exe', 'C:\\xampp\\php\\php.exe'] as $c)
            if (@is_file($c)) return $c;

        return null;   // el caller DEBE registrarlo: el silencio es lo que ocultó este bug 6 días
    }
}
