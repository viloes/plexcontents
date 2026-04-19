<?php
// public/login.php
require_once __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Flash;
use App\Logger;

Auth::startSession();

// Cargar idioma (para login, se usa idioma por defecto)
$currentLanguage = getCurrentLanguage();
$lang = loadLanguage($currentLanguage);
if (Auth::isLoggedIn()) {
    Logger::debug('Login page redirect: already authenticated', [
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null
    ]);
    header("Location: movies.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    Logger::debug('Login form submitted', ['username' => $username]);

    if (Auth::login($username, $password)) {
        Logger::info('Login page authentication success', ['username' => $username]);
        header("Location: movies.php");
        exit;
    } else {
        Logger::error('Login page authentication failed', ['username' => $username]);
        $error = getLangMessage('error_invalid_credentials');
    }
}

$alerts = Flash::consumeAll();
if ($error) {
    $alerts[] = ['type' => 'danger', 'message' => $error];
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage) ?>">
<head>
    <?php $pageTitle = $lang['login'] . ' - ' . $lang['app_title']; ?>
    <?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body>
    <main class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
        <div class="card shadow-sm w-100 login-card">
            <div class="card-body p-4 p-md-5">
                <h1 class="h4 text-center mb-4"><?= $lang['app_title'] ?></h1>
                <?php include __DIR__ . '/partials/alerts.php'; ?>

                <form method="POST" action="login.php">
                    <div class="mb-3">
                        <label for="username" class="form-label"><?= $lang['username'] ?></label>
                        <input type="text" id="username" name="username" class="form-control" required>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label"><?= $lang['password'] ?></label>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-brand w-100"><?= $lang['login'] ?></button>
                </form>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/partials/scripts.php'; ?>
</body>
</html>