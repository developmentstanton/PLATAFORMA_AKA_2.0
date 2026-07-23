<?php
	// El módulo administrativo entra directo a Planillas (única sección hoy). inicio.php
	// queda solo como redirección, para no dejar cabos sueltos: cualquier enlace o marcador
	// viejo hacia inicio.php aterriza en planillas.php. El guard se conserva: si no hay
	// sesión admin válida, admin_exigir_sesion() manda al login antes de redirigir.
	require_once __DIR__ . '/lib_admin_auth.php';
	admin_exigir_sesion();

	header('Location: planillas.php');
	exit;
