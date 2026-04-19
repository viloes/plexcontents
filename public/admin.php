<?php
// public/admin.php
require_once __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Catalog;
use App\CatalogSource;
use App\DB;
use App\Flash;
use App\Logger;
use App\Settings;

require_once __DIR__ . '/../init_db.php';

// Obtener idioma del usuario
Auth::startSession();
$pdo = DB::getConnection();
$currentLanguage = getCurrentLanguage($pdo);
$lang = loadLanguage($currentLanguage);

Auth::requireAdmin();
Logger::debug('Admin page opened', [
    'user_id' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['username'] ?? null
]);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- GESTIÓN DE USUARIOS ---
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_user') {
            $newUsername = trim($_POST['new_username'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
            Logger::info('Admin add user requested', [
                'admin_id' => $_SESSION['user_id'] ?? null,
                'admin_username' => $_SESSION['username'] ?? null,
                'target_username' => $newUsername,
                'target_is_admin' => $isAdmin
            ]);

            if ($newUsername !== '' && $newPassword !== '') {
                try {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, is_admin, language) VALUES (?, ?, ?, 'es')");
                    $stmt->execute([$newUsername, $hash, $isAdmin]);
                    $message = $lang['success_user_added'];
                    Logger::info('Admin add user success', ['target_username' => $newUsername]);
                } catch (PDOException $e) {
                    $error = $lang['error_user_exists'];
                    Logger::error('Admin add user failed', [
                        'target_username' => $newUsername,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } elseif ($_POST['action'] === 'delete_user') {
            $deleteId = intval($_POST['user_id']);
            Logger::info('Admin delete user requested', [
                'admin_id' => $_SESSION['user_id'] ?? null,
                'target_user_id' => $deleteId
            ]);
            if ($deleteId === $_SESSION['user_id']) {
                $error = $lang['error_self_delete'];
                Logger::error('Admin attempted self delete', ['user_id' => $deleteId]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$deleteId]);
                $message = $lang['success_user_deleted'];
                Logger::info('Admin delete user success', ['target_user_id' => $deleteId]);
            }
        } elseif ($_POST['action'] === 'change_user_password') {
            $targetUserId = intval($_POST['user_id']);
            $newPassword = $_POST['new_password'] ?? '';
            Logger::info('Admin change user password requested', [
                'admin_id' => $_SESSION['user_id'] ?? null,
                'target_user_id' => $targetUserId
            ]);
            
            if (strlen($newPassword) < 6) {
                $error = $lang['error_password_too_short'];
                Logger::error('Admin change user password failed: too short', [
                    'target_user_id' => $targetUserId
                ]);
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hash, $targetUserId]);
                $message = $lang['success_password_updated'];
                Logger::info('Admin change user password success', ['target_user_id' => $targetUserId]);
            }
        } elseif ($_POST['action'] === 'import_movies') {
            $selectedFolder = trim($_POST['movies_folder'] ?? '');
            Logger::info('Admin movies import requested', [
                'admin_id' => $_SESSION['user_id'] ?? null,
                'folder_name' => $selectedFolder,
            ]);

            $result = Catalog::importMovies($selectedFolder);
            if ($result === true) {
                $message = $lang['success_movies_imported'];
            } else {
                $error = $result;
            }
        } elseif ($_POST['action'] === 'import_series') {
            $selectedFolder = trim($_POST['series_folder'] ?? '');
            Logger::info('Admin series import requested', [
                'admin_id' => $_SESSION['user_id'] ?? null,
                'folder_name' => $selectedFolder,
            ]);

            $result = Catalog::importSeries($selectedFolder);
            if ($result === true) {
                $message = $lang['success_series_imported'];
            } else {
                $error = $result;
            }
        } elseif ($_POST['action'] === 'reset_database') {
            Logger::info('Admin database reset requested', [
                'admin_id' => $_SESSION['user_id'] ?? null,
                'admin_username' => $_SESSION['username'] ?? null,
            ]);

            $result = resetDatabase();
            if (($result['success'] ?? false) === true) {
                Logger::info('Admin database reset success', [
                    'admin_id' => $_SESSION['user_id'] ?? null,
                    'db_path' => $result['db_path'] ?? null,
                ]);
                Auth::logout();
                Flash::add('warning', $lang['success_database_reset']);
                header('Location: login.php');
                exit;
            }

            $error = $lang['error_database_reset'] . ' ' . ($result['message'] ?? '');
            Logger::error('Admin database reset failed', [
                'admin_id' => $_SESSION['user_id'] ?? null,
                'db_path' => $result['db_path'] ?? null,
                'error' => $result['message'] ?? null,
            ]);
        }
    }
}

// Obtener lista de usuarios
$users = $pdo->query("SELECT id, username, is_admin, language FROM users ORDER BY username ASC")->fetchAll();

$moviesBasePath = Settings::get('movies_base_path');
$seriesBasePath = Settings::get('series_base_path');
$folderOptions = CatalogSource::listAvailableFolders();
$selectedMoviesFolder = trim($_POST['movies_folder'] ?? basename($moviesBasePath ?? ''));
$selectedSeriesFolder = trim($_POST['series_folder'] ?? basename($seriesBasePath ?? ''));

$alerts = Flash::consumeAll();
if ($message) {
    $alerts[] = ['type' => 'success', 'message' => $message];
}
if ($error) {
    $alerts[] = ['type' => 'danger', 'message' => $error];
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage) ?>">
<head>
    <?php $pageTitle = $lang['settings'] . ' - ' . $lang['app_title']; ?>
    <?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body>
    <div class="app-shell d-flex flex-column flex-lg-row">
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <h1 class="h3 mb-4"><?= $lang['settings'] ?></h1>

        <?php include __DIR__ . '/partials/alerts.php'; ?>

        <div class="form-card">
            <h2 class="h5 mb-3"><?= $lang['users_management'] ?></h2>
            
            <div class="table-responsive mb-4">
            <table class="table table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th><?= $lang['username'] ?></th>
                        <th><?= $lang['role'] ?></th>
                        <th><?= $lang['actions'] ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td>
                            <?= $u['is_admin'] ? "<strong>{$lang['admin']}</strong>" : $lang['normal_user'] ?>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                            <form method="POST" class="d-flex gap-2 align-items-center">
                                <input type="hidden" name="action" value="change_user_password">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="password" name="new_password" placeholder="Nueva Clave" required class="form-control form-control-sm admin-password-input">
                                <button type="submit" class="btn btn-sm btn-brand"><?= $lang['change'] ?></button>
                            </form>

                            <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                            <form method="POST" onsubmit="return confirm('¿Seguro que quieres borrar este usuario?');">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"><?= $lang['delete'] ?></button>
                            </form>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <h3 class="h6 mb-3"><?= $lang['add_user'] ?></h3>
            <form method="POST" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="add_user">
                <div class="col-12 col-md-4">
                    <label class="form-label"><?= $lang['username'] ?></label>
                    <input type="text" name="new_username" required class="form-control">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label"><?= $lang['password'] ?></label>
                    <input type="password" name="new_password" required class="form-control">
                </div>
                <div class="col-12 col-md-2">
                    <div class="form-check mt-2">
                        <input type="checkbox" name="is_admin" value="1" class="form-check-input" id="is_admin">
                        <label class="form-check-label" for="is_admin">
                        <?= $lang['admin'] ?>
                        </label>
                    </div>
                </div>
                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-brand w-100"><?= $lang['add_user'] ?></button>
                </div>
            </form>
        </div>

        <hr class="my-4">

        <div class="form-card">
            <h2 class="h5 mb-3"><?= $lang['catalog_sources'] ?></h2>
            <p class="text-secondary mb-3"><?= $lang['catalog_source_help'] ?></p>
            <div class="mb-3">
                <label class="form-label"><?= $lang['catalog_source_root'] ?></label>
                <code class="d-block p-2 rounded bg-light"><?= htmlspecialchars(CatalogSource::getRootPath()) ?></code>
            </div>
            <div class="row g-3">
                <div class="col-12 col-xl-6">
                    <label class="form-label"><?= $lang['current_movies_path'] ?></label>
                    <code class="d-block p-2 rounded bg-light"><?= htmlspecialchars($moviesBasePath ?: $lang['not_configured']) ?></code>
                </div>
                <div class="col-12 col-xl-6">
                    <label class="form-label"><?= $lang['current_series_path'] ?></label>
                    <code class="d-block p-2 rounded bg-light"><?= htmlspecialchars($seriesBasePath ?: $lang['not_configured']) ?></code>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-xl-6">
            <div class="form-card h-100">
                <h2 class="h6 mb-3"><?= $lang['movies_source_folder'] ?></h2>
                <form method="POST">
                    <input type="hidden" name="action" value="import_movies">
                    <label class="form-label" for="movies_folder"><?= $lang['select_folder'] ?></label>
                    <select name="movies_folder" id="movies_folder" class="form-select mb-3" <?= empty($folderOptions) ? 'disabled' : '' ?> required>
                        <option value=""><?= $lang['select_folder'] ?></option>
                        <?php foreach ($folderOptions as $folder): ?>
                        <option value="<?= htmlspecialchars($folder['name']) ?>" <?= $selectedMoviesFolder === $folder['name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($folder['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($folderOptions)): ?>
                    <p class="text-danger small mb-3"><?= $lang['no_catalog_folders_available'] ?></p>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-brand" <?= empty($folderOptions) ? 'disabled' : '' ?>><?= $lang['import'] ?></button>
                </form>
            </div>
            </div>

            <div class="col-12 col-xl-6">
            <div class="form-card h-100">
                <h2 class="h6 mb-3"><?= $lang['series_source_folder'] ?></h2>
                <form method="POST">
                    <input type="hidden" name="action" value="import_series">
                    <label class="form-label" for="series_folder"><?= $lang['select_folder'] ?></label>
                    <select name="series_folder" id="series_folder" class="form-select mb-3" <?= empty($folderOptions) ? 'disabled' : '' ?> required>
                        <option value=""><?= $lang['select_folder'] ?></option>
                        <?php foreach ($folderOptions as $folder): ?>
                        <option value="<?= htmlspecialchars($folder['name']) ?>" <?= $selectedSeriesFolder === $folder['name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($folder['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($folderOptions)): ?>
                    <p class="text-danger small mb-3"><?= $lang['no_catalog_folders_available'] ?></p>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-brand" <?= empty($folderOptions) ? 'disabled' : '' ?>><?= $lang['import'] ?></button>
                </form>
            </div>
            </div>
        </div>

        <div class="form-card mt-3 border border-danger-subtle">
            <h2 class="h5 mb-3 text-danger"><?= $lang['database_maintenance'] ?></h2>
            <p class="text-secondary mb-3"><?= $lang['database_reset_help'] ?></p>
            <form method="POST" onsubmit="return confirm('<?= htmlspecialchars($lang['database_reset_confirm'], ENT_QUOTES) ?>');">
                <input type="hidden" name="action" value="reset_database">
                <button type="submit" class="btn btn-danger"><?= $lang['reset_database'] ?></button>
            </form>
        </div>
    </main>
    </div>
    <?php include __DIR__ . '/partials/scripts.php'; ?>
</body>
</html>