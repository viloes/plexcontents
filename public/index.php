<?php
// public/index.php
require_once __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Logger;

// Asegurarnos de que la sesión está iniciada para poder comprobarla
Auth::startSession();

// Lógica de enrutamiento básico
if (Auth::isLoggedIn()) {
    // Si el usuario ya está logueado, lo enviamos al catálogo principal
    Logger::debug('Index redirect to movies', [
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null
    ]);
    header("Location: movies.php");
    exit;
} else {
    // Si no está logueado, lo enviamos a la pantalla de acceso
    Logger::debug('Index redirect to login');
    header("Location: login.php");
    exit;
}