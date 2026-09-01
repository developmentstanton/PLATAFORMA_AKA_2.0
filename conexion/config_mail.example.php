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

// Modo prueba, OPCIONAL y temporal. Mientras esté definido, TODO el correo va solo a esa
// dirección y MAIL_AUDITORIA_TO se ignora — sirve para que el primer envío de un entorno
// nuevo no llegue a los destinatarios definitivos. Quitarlo una vez verificado.
// define('MAIL_TEST_TO', 'tu_correo@stanton.co');
