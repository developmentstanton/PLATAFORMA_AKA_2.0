<?php
	session_start();

	$nombre = isset($_SESSION['usuario']) ? $_SESSION['usuario'] : '';
	if ($nombre) {
		require('conexion/conexion_integracion.php');
		$consulta = "INSERT INTO log_usuarios_portal_aka VALUES (?, GETDATE(), 'SALIDA')";
		$params = array($nombre);
		$resultado = sqlsrv_query($dbConnect, $consulta, $params);
		if ($resultado !== false) {
			sqlsrv_free_stmt($resultado);
		}
		sqlsrv_close($dbConnect);
	}

	session_unset();
	session_destroy();

	header("Location: index.php");
	exit;
?>
