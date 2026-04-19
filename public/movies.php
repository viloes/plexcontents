<?php
// public/movies.php
require_once __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\DB;
use App\Logger;
use App\Pagination;

Auth::requireLogin();
Auth::startSession();

$pdo = DB::getConnection();
$currentLanguage = getCurrentLanguage($pdo);
$lang = loadLanguage($currentLanguage);

// Filtros y paginación
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'added_at_desc';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

Logger::debug('Movies list requested', [
    'user_id' => $_SESSION['user_id'] ?? null,
    'search' => $search,
    'sort' => $sort,
    'page' => $page,
    'limit' => $limit
]);

// Construir consulta dinámica
$whereSql = "WHERE 1=1";
$params = [];
if ($search) {
    $whereSql .= " AND (title LIKE ? OR original_title LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$orderSql = "ORDER BY added_at DESC";
if ($sort === 'title_asc') $orderSql = "ORDER BY title ASC";
if ($sort === 'year_desc') $orderSql = "ORDER BY year DESC";
if ($sort === 'rating_desc') $orderSql = "ORDER BY rating DESC";

// Total para paginación
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM movies $whereSql");
$stmtTotal->execute($params);
$total = $stmtTotal->fetchColumn();
$totalPages = ceil($total / $limit);

// Registros
$sql = "SELECT * FROM movies $whereSql $orderSql LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movies = $stmt->fetchAll();
Logger::debug('Movies list loaded', [
    'result_count' => count($movies),
    'total' => (int) $total,
    'total_pages' => (int) $totalPages
]);

$paginationLabels = [
    'first' => $lang['pagination_first'],
    'last' => $lang['pagination_last'],
    'previous' => $lang['pagination_previous'],
    'next' => $lang['pagination_next'],
    'navigation' => $lang['pagination_navigation'],
    'go_to_page' => $lang['pagination_go_to_page'],
    'current_page' => $lang['pagination_current_page'],
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage) ?>">
<head>
    <?php $pageTitle = $lang['movies'] . ' - ' . $lang['app_title']; ?>
    <?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body>
    <div class="app-shell d-flex flex-column flex-lg-row">
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
            <h1 class="h3 mb-0"><?= $lang['movies'] ?></h1>
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-12 col-sm-auto">
                    <input type="text" name="search" placeholder="<?= $lang['search'] ?>" value="<?= htmlspecialchars($search) ?>" class="form-control">
                </div>
                <div class="col-12 col-sm-auto">
                <select name="sort" class="form-select" onchange="this.form.requestSubmit()">
                    <option value="added_at_desc" <?= $sort == 'added_at_desc' ? 'selected' : '' ?>><?= $lang['sort_newest'] ?></option>
                    <option value="title_asc" <?= $sort == 'title_asc' ? 'selected' : '' ?>><?= $lang['sort_title_asc'] ?></option>
                    <option value="year_desc" <?= $sort == 'year_desc' ? 'selected' : '' ?>><?= $lang['sort_year_desc'] ?></option>
                    <option value="rating_desc" <?= $sort == 'rating_desc' ? 'selected' : '' ?>><?= $lang['sort_rating_desc'] ?></option>
                </select>
                </div>
                <div class="col-12 col-sm-auto">
                <button type="submit" class="btn btn-brand"><?= $lang['filter'] ?></button>
                </div>
            </form>
        </div>

        <div class="grid">
            <?php foreach ($movies as $m): ?>
                <?php $posterSrc = !empty($m['thumb_file']) ? ('image.php?type=movie&file=' . rawurlencode($m['thumb_file'])) : 'https://via.placeholder.com/200x300?text=Poster'; ?>
                <?php
                $moviePayload = $m;
                $moviePayload['poster_src'] = $posterSrc;
                $moviePayloadJson = htmlspecialchars(
                    json_encode($moviePayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
                    ENT_QUOTES,
                    'UTF-8'
                );
                ?>
                <div class="card" role="button" data-bs-toggle="modal" data-bs-target="#itemModal" data-movie='<?= $moviePayloadJson ?>' onclick="openModalFromCard(this)">
                    <img src="<?= htmlspecialchars($posterSrc) ?>" alt="<?= htmlspecialchars($m['title']) ?>" class="card-img" onerror="this.onerror=null;this.src='https://via.placeholder.com/200x300?text=Poster';">
                    <div class="card-body">
                        <h5 class="card-title"><?= htmlspecialchars($m['title']) ?></h5>
                        <div class="card-meta">
                            <?= $m['year'] ?> • <?= $m['duration_human'] ?><br>
                            ⭐ <?= $m['rating'] ?? 'N/A' ?> | 🕒 <?= substr($m['added_at'], 0, 10) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?= Pagination::render($page, (int) $totalPages, 'movies.php', ['search' => $search, 'sort' => $sort], $paginationLabels, 1) ?>
    </main>

    <div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="mTitle"></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="detail-modal-layout">
                        <div class="detail-modal-poster-wrap">
                            <img id="mPoster" src="https://via.placeholder.com/400x600?text=Poster" alt="Poster" class="detail-modal-poster" onerror="this.onerror=null;this.src='https://via.placeholder.com/400x600?text=Poster';">
                        </div>
                        <div class="detail-modal-content">
                            <p><strong><?= $lang['original_title'] ?>:</strong> <span id="mOriginal"></span></p>
                            <p><strong><?= $lang['year'] ?>:</strong> <span id="mYear"></span> | <strong><?= $lang['duration'] ?>:</strong> <span id="mDuration"></span></p>
                            <p><strong><?= $lang['rating'] ?>:</strong> <span id="mRating"></span> | <strong><?= $lang['user_rating'] ?>:</strong> <span id="mUserRating"></span></p>
                            <p><strong><?= $lang['added_at'] ?>:</strong> <span id="mAdded"></span></p>

                            <div class="media-info-panel">
                                <h3 class="h6 mb-2"><?= $lang['technical_info'] ?></h3>
                                <div class="media-info-grid">
                                    <div><strong><?= $lang['container'] ?>:</strong> <span id="mContainer">-</span></div>
                                    <div><strong><?= $lang['resolution'] ?>:</strong> <span id="mResolution">-</span></div>
                                    <div><strong><?= $lang['video_codec'] ?>:</strong> <span id="mVideoCodec">-</span></div>
                                    <div><strong><?= $lang['hdr'] ?>:</strong> <span id="mHdr">-</span></div>
                                </div>
                            </div>

                            <hr>
                            <p id="mSummary" class="text-secondary mb-0"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function parseMovieMediaInfo(rawMediaInfo) {
            if (!rawMediaInfo) {
                return null;
            }

            if (typeof rawMediaInfo === 'object') {
                return rawMediaInfo;
            }

            try {
                return JSON.parse(rawMediaInfo);
            } catch (e) {
                return null;
            }
        }

        function formatResolution(mediaInfo) {
            if (!mediaInfo) {
                return '-';
            }

            const videoResolution = mediaInfo.videoResolution || '-';
            const width = mediaInfo.width || '?';
            const height = mediaInfo.height || '?';
            return `${videoResolution} (${width} x ${height})`;
        }

        function openModalFromCard(card) {
            const payload = card.getAttribute('data-movie');
            if (!payload) {
                return;
            }

            let data;
            try {
                data = JSON.parse(payload);
            } catch (e) {
                return;
            }

            const mediaInfo = parseMovieMediaInfo(data.media_info);

            document.getElementById('mTitle').innerText = data.title;
            document.getElementById('mOriginal').innerText = data.original_title || 'N/A';
            document.getElementById('mYear').innerText = data.year || '-';
            document.getElementById('mDuration').innerText = data.duration_human || '-';
            document.getElementById('mRating').innerText = data.rating || '-';
            document.getElementById('mUserRating').innerText = data.user_rating || '-';
            document.getElementById('mAdded').innerText = data.added_at || '-';
            document.getElementById('mSummary').innerText = data.summary || '';
            document.getElementById('mPoster').src = data.poster_src || 'https://via.placeholder.com/400x600?text=Poster';

            document.getElementById('mContainer').innerText = mediaInfo?.container || '-';
            document.getElementById('mResolution').innerText = formatResolution(mediaInfo);
            document.getElementById('mVideoCodec').innerText = mediaInfo?.videoCodec || '-';

            const hdrValue = mediaInfo?.hdr;
            const hdrText = hdrValue === true || hdrValue === 1 || hdrValue === '1'
                ? '<?= addslashes($lang['yes']) ?>'
                : '<?= addslashes($lang['no']) ?>';
            document.getElementById('mHdr').innerText = mediaInfo ? hdrText : '-';
        }
    </script>
    <?php include __DIR__ . '/partials/scripts.php'; ?>
    </div>
</body>
</html>