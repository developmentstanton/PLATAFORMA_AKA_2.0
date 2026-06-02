<?php
// ==========================================
// PLANTILLA — copiar a conexion_integracion.php y poner credenciales reales.
// El archivo real está en .gitignore (NO se versiona).
// ==========================================
	$servidor  = "TU_SERVIDOR_SQL";        // ej: host.rds.amazonaws.com
	$basedatos = 'INTEGRACION';
	$usuario = 'TU_USUARIO';
	$password = 'TU_PASSWORD';
	$infoconn = array("Database"=>"$basedatos", "UID"=>"$usuario", "PWD"=>"$password", "CharacterSet" => "UTF-8");
	$dbConnect = sqlsrv_connect( $servidor, $infoconn);
	if( $dbConnect === false) {
		echo "<script = 'javaScript'>
		alert('Problemas de conexion!')
		window.location.href='/';
		</script>";
	}
?>
