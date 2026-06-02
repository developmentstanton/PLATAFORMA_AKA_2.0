<?php

require_once 'conexion.php';

$nombre = htmlspecialchars($_POST['nombre']);
$pass = htmlspecialchars($_POST['clave']);

$infoconn = array( "Database"=>"$catalogo", "UID"=>"$usuario", "PWD"=>"$clave");
$conn = sqlsrv_connect( $servidor, $infoconn);

$tsql = "SELECT * FROM claves WHERE usuario = '$nombre' AND clave ='$pass'";
$param1 = "";
$params = array(&$param1);
$stmt = sqlsrv_prepare($conn, $tsql, $params);
sqlsrv_execute($stmt);

$resultado = sqlsrv_query($conn, $tsql);

if ($resultado == false) {
  
  die(FormatErrors(sqlsrv_errors()));
  
}

$usuario = '';
$password = '';

while ($row = sqlsrv_fetch_array($resultado, SQLSRV_FETCH_ASSOC)) {

    $usuario = $row['usuario'];
    $usuario = trim($usuario);
    $password = $row['clave'];
    $password = trim($password);
}

sqlsrv_free_stmt($resultado);

if ($nombre == $usuario && $pass == $password ) {
  //echo ("Bienvenido $usuario Conexión exitosa.<br />");
  session_start();
  $_SESSION['usuario'] = $usuario;
  header("location: ../pide_cedula.php");
} else {
  echo '<script type="text/javascript">
    alert("Usuario y/o Contraseña incorrectos");
    window.location.href="../index.php";
    </script>';
}

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);

?>