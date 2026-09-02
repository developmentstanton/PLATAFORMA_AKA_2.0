<?php
// Plantilla de credenciales SMTP. Copiar a config_mail.php y rellenar MAIL_PASS.
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USER', 'plataforma@tiendasaka.co');
define('MAIL_PASS', 'REEMPLAZAR_CON_CLAVE_DE_APLICACION');
define('MAIL_FROM_NAME', 'PLATAFORMA AKA');
define('MAIL_BCC', ['jpmartin@stanton.co', 'masterdata2@stanton.co', 'directorplataformaaka@cauchosol.co']);

// Destinatarios del aviso de Verificación (auditoría de aliados).
// Lista vacía = verif_enviar() falla con error explícito: si se despliega sin configurar,
// se nota de inmediato en vez de enviar a nadie en silencio.
define('MAIL_AUDITORIA_TO', ['rlancheros@stanton.co', 'ymontoya@stanton.co']);

// Copia VISIBLE del mismo aviso (2026-09-02). Asimetría deliberada frente a la de arriba:
// si esta falta, el aviso sale IGUAL, solo que sin copia y sin avisar a nadie. Un despliegue
// a medio configurar no debe dejar de comunicar la auditoría, pero el olvido es mudo:
// tests/verificacion_envio_test.php falla donde falte, y es la única forma de notarlo.
define('MAIL_AUDITORIA_CC', ['coordinventarios@stanton.co']);

// Modo prueba, OPCIONAL y temporal. Mientras esté definido, TODO el correo va solo a esa
// dirección y MAIL_AUDITORIA_TO se ignora — sirve para que el primer envío de un entorno
// nuevo no llegue a los destinatarios definitivos. Quitarlo una vez verificado.
// define('MAIL_TEST_TO', 'tu_correo@stanton.co');
