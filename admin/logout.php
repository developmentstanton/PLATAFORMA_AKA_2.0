<?php
	require_once __DIR__ . '/lib_admin_auth.php';
	admin_iniciar_sesion();

	// Nombre distinto de $usuario a propósito: conexion_integracion.php declara su PROPIA
	// variable global $usuario (la credencial UID de la conexión SQL) y, al incluirse en
	// este mismo scope, la sobreescribiría — corrompiendo el usuario que se audita.
	$usuarioAdmin = $_SESSION['admin']['usuario'] ?? '';
	if ($usuarioAdmin !== '') {
		require __DIR__ . '/../conexion/conexion_integracion.php';
		if ($dbConnect !== false) {
			admin_registrar_evento($dbConnect, $usuarioAdmin, 'ADMIN_OUT');
			sqlsrv_close($dbConnect);
		}
	}

	// Solo el namespace admin: el portal de aliados puede estar abierto en la misma cookie.
	admin_cerrar_sesion_admin();
	session_regenerate_id(true);

	header('Location: index.php');
	exit;
