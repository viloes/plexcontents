<?php
// public/logout.php
require_once __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Flash;
use App\Logger;

Auth::startSession();
Logger::info('Logout requested', [
	'user_id' => $_SESSION['user_id'] ?? null,
	'username' => $_SESSION['username'] ?? null
]);

// Ejecutar la función de cierre de sesión
Auth::logout();
Flash::add('info', getLangMessage('success_logout'));

// Redirigir a la página de inicio de sesión
header("Location: login.php");
exit;