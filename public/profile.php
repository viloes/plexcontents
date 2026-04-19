<?php
// public/profile.php
require_once __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\DB;
use App\Flash;
use App\Logger;

Auth::requireLogin();
Auth::startSession();

$pdo = DB::getConnection();
$currentLanguage = getCurrentLanguage($pdo);
$lang = loadLanguage($currentLanguage);

Logger::debug('Profile page opened', [
    'user_id' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['username'] ?? null
]);

$message = '';
$error = '';

// Obtener idioma actual del usuario
$stmt = $pdo->prepare("SELECT language FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$userLanguage = $stmt->fetchColumn() ?? 'es';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        Logger::info('Profile password change requested', [
            'user_id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null
        ]);

        if (strlen($newPassword) < 6) {
            $error = $lang['error_password_short'];
            Logger::error('Profile password change failed: too short', [
                'user_id' => $_SESSION['user_id'] ?? null
            ]);
        } elseif ($newPassword !== $confirmPassword) {
            $error = $lang['error_password_mismatch'];
            Logger::error('Profile password change failed: mismatch', [
                'user_id' => $_SESSION['user_id'] ?? null
            ]);
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $_SESSION['user_id']]);
            $message = $lang['success_password_changed'];
            Logger::info('Profile password change success', [
                'user_id' => $_SESSION['user_id'] ?? null,
                'username' => $_SESSION['username'] ?? null
            ]);
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'change_language') {
        $newLanguage = trim($_POST['language'] ?? '');
        $availableLanguages = getAvailableLanguages();
        
        if (in_array($newLanguage, $availableLanguages)) {
            $stmt = $pdo->prepare("UPDATE users SET language = ? WHERE id = ?");
            $stmt->execute([$newLanguage, $_SESSION['user_id']]);
            $_SESSION['language'] = $newLanguage;
            $userLanguage = $newLanguage;
            $currentLanguage = $newLanguage;
            $lang = loadLanguage($currentLanguage);
            $message = $lang['success_language_changed'] ?? 'Idioma actualizado correctamente.';
            Logger::info('Profile language change success', [
                'user_id' => $_SESSION['user_id'] ?? null,
                'language' => $newLanguage
            ]);
        } else {
            $error = $lang['error_invalid_language'] ?? 'Invalid language.';
            Logger::error('Profile language change failed: invalid language', [
                'user_id' => $_SESSION['user_id'] ?? null,
                'language' => $newLanguage
            ]);
        }
    }
}

$alerts = Flash::consumeAll();
if ($message) {
    $alerts[] = ['type' => 'success', 'message' => $message];
}
if ($error) {
    $alerts[] = ['type' => 'danger', 'message' => $error];
}

// Obtener idiomas disponibles
$availableLanguages = getAvailableLanguages();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage) ?>">
<head>
    <?php $pageTitle = $lang['my_profile'] . ' - ' . $lang['app_title']; ?>
    <?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body>
    <div class="app-shell d-flex flex-column flex-lg-row">
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <h1 class="h3 mb-4"><?= $lang['my_profile'] ?></h1>

        <?php include __DIR__ . '/partials/alerts.php'; ?>

        <div class="form-card profile-card">
            <h2 class="h5 mb-4"><?= $lang['choose_language'] ?></h2>
            <form method="POST">
                <input type="hidden" name="action" value="change_language">
                <div class="mb-3">
                    <label class="form-label"><?= $lang['language'] ?></label>
                    <select name="language" class="form-select" required>
                        <?php foreach ($availableLanguages as $langCode): ?>
                            <option value="<?= $langCode ?>" <?= $userLanguage === $langCode ? 'selected' : '' ?>>
                                <?= ucfirst($langCode) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-brand"><?= $lang['save'] ?></button>
            </form>
        </div>

        <div class="form-card profile-card">
            <h2 class="h5 mb-4"><?= $lang['change_password'] ?></h2>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="mb-3">
                    <label class="form-label"><?= $lang['new_password'] ?></label>
                    <input type="password" name="new_password" class="form-control" required>
                </div>
                <div class="mb-4">
                    <label class="form-label"><?= $lang['confirm_password'] ?></label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-brand"><?= $lang['change'] ?></button>
            </form>
        </div>
    </main>
    </div>
    <?php include __DIR__ . '/partials/scripts.php'; ?>
</body>
</html>