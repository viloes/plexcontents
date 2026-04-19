<?php
// public/sidebar.php
use App\Auth;

if (!isset($lang) || !is_array($lang)) {
    $lang = loadLanguage(getCurrentLanguage());
}
$isAdmin = Auth::isAdmin();
$currentPage = basename($_SERVER['PHP_SELF']);

$links = [
    ['href' => 'movies.php', 'label' => $lang['movies']],
    ['href' => 'series.php', 'label' => $lang['series']],
];

if ($isAdmin) {
    $links[] = ['href' => 'admin.php', 'label' => $lang['settings']];
}

function navLinkClass(string $currentPage, string $targetPage): string {
    return $currentPage === $targetPage ? 'sidebar-nav-link active' : 'sidebar-nav-link';
}
?>
<header class="mobile-topbar d-lg-none px-3 py-2 d-flex align-items-center justify-content-between">
    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar">
        Menu
    </button>
    <p class="mobile-topbar-title"><?= $lang['app_title'] ?></p>
</header>

<aside class="sidebar d-none d-lg-flex flex-column">
    <div class="sidebar-header"><?= $lang['app_title'] ?></div>
    <nav class="sidebar-nav flex-grow-1">
        <?php foreach ($links as $link): ?>
            <a href="<?= $link['href'] ?>" class="<?= navLinkClass($currentPage, $link['href']) ?>"><?= $link['label'] ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <small><?= $lang['hello'] ?>, <strong><?= $_SESSION['username'] ?? '' ?></strong></small>
        <a href="profile.php" class="sidebar-profile-link d-block mt-3 mb-2"><?= $lang['my_profile'] ?></a>
        <a href="logout.php" class="sidebar-logout-link d-block"><?= $lang['logout'] ?></a>
    </div>
</aside>

<div class="offcanvas offcanvas-start sidebar-offcanvas" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="mobileSidebarLabel"><?= $lang['app_title'] ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column p-0">
        <nav class="sidebar-nav flex-grow-1">
            <?php foreach ($links as $link): ?>
                <a href="<?= $link['href'] ?>" class="<?= navLinkClass($currentPage, $link['href']) ?>"><?= $link['label'] ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <small><?= $lang['hello'] ?>, <strong><?= $_SESSION['username'] ?? '' ?></strong></small>
            <a href="profile.php" class="sidebar-profile-link d-block mt-3 mb-2"><?= $lang['my_profile'] ?></a>
            <a href="logout.php" class="sidebar-logout-link d-block"><?= $lang['logout'] ?></a>
        </div>
    </div>
</div>