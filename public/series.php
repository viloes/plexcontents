<?php
// public/series.php
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

$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'added_at_desc';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

Logger::debug('Series list requested', [
    'user_id' => $_SESSION['user_id'] ?? null,
    'search' => $search,
    'sort' => $sort,
    'page' => $page,
    'limit' => $limit
]);

$whereSql = "WHERE 1=1";
$params = [];
if ($search) {
    $whereSql .= " AND (title LIKE ? OR original_title LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$orderSql = "ORDER BY added_at DESC";
if ($sort === 'title_asc') $orderSql = "ORDER BY title ASC";
if ($sort === 'rating_desc') $orderSql = "ORDER BY audience_rating DESC";

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM series $whereSql");
$stmtTotal->execute($params);
$total = $stmtTotal->fetchColumn();
$totalPages = ceil($total / $limit);

$stmt = $pdo->prepare("SELECT * FROM series $whereSql $orderSql LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$series = $stmt->fetchAll();
Logger::debug('Series list loaded', [
    'result_count' => count($series),
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
    <?php $pageTitle = $lang['series'] . ' - ' . $lang['app_title']; ?>
    <?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body>
    <div class="app-shell d-flex flex-column flex-lg-row">
    <?php include 'sidebar.php'; ?>
    <main class="main-content">
        <div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
            <h1 class="h3 mb-0"><?= $lang['series'] ?></h1>
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-12 col-sm-auto">
                <input type="text" name="search" placeholder="<?= $lang['search'] ?>" value="<?= htmlspecialchars($search) ?>" class="form-control">
                </div>
                <div class="col-12 col-sm-auto">
                <select name="sort" class="form-select" onchange="this.form.requestSubmit()">
                    <option value="added_at_desc" <?= $sort == 'added_at_desc' ? 'selected' : '' ?>><?= $lang['sort_newest'] ?></option>
                    <option value="title_asc" <?= $sort == 'title_asc' ? 'selected' : '' ?>><?= $lang['sort_title_asc'] ?></option>
                    <option value="rating_desc" <?= $sort == 'rating_desc' ? 'selected' : '' ?>><?= $lang['sort_rating_desc'] ?></option>
                </select>
                </div>
                <div class="col-12 col-sm-auto">
                <button type="submit" class="btn btn-brand"><?= $lang['filter'] ?></button>
                </div>
            </form>
        </div>

        <div class="grid">
            <?php foreach ($series as $s): ?>
                <?php $posterSrc = !empty($s['thumb_file']) ? ('image.php?type=series&file=' . rawurlencode($s['thumb_file'])) : 'https://via.placeholder.com/200x300?text=Poster'; ?>
                <?php
                $seriesPayload = $s;
                $seriesPayload['poster_src'] = $posterSrc;
                $seriesPayloadJson = htmlspecialchars(
                    json_encode($seriesPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
                    ENT_QUOTES,
                    'UTF-8'
                );
                ?>
                <div class="card" role="button" data-bs-toggle="modal" data-bs-target="#itemModal" data-series='<?= $seriesPayloadJson ?>' onclick="openModalFromCard(this)">
                    <img src="<?= htmlspecialchars($posterSrc) ?>" alt="<?= htmlspecialchars($s['title']) ?>" class="card-img" onerror="this.onerror=null;this.src='https://via.placeholder.com/200x300?text=Poster';">
                    <div class="card-body">
                        <h5 class="card-title"><?= htmlspecialchars($s['title']) ?></h5>
                        <div class="card-meta">
                            <?= $lang['seasons'] ?>: <?= $s['season_count'] ?><br>
                            ⭐ <?= $s['audience_rating'] ?? 'N/A' ?> | 🕒 <?= substr($s['added_at'], 0, 10) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?= Pagination::render($page, (int) $totalPages, 'series.php', ['search' => $search, 'sort' => $sort], $paginationLabels, 1) ?>
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
                            <p><strong><?= $lang['year'] ?>:</strong> <span id="mYear"></span> | <strong><?= $lang['rating'] ?>:</strong> <span id="mRating"></span></p>
                            <p><strong><?= $lang['added_at'] ?>:</strong> <span id="mAdded"></span></p>
                            <hr>
                            <p id="mSummary" class="text-secondary"></p>

                            <div class="seasons-list">
                                <h4 class="h6 mb-3"><?= $lang['seasons'] ?> y <?= $lang['episodes'] ?></h4>
                                <div id="mSeasons" class="accordion"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function escapeHtml(text) {
            if (text === null || text === undefined) {
                return '';
            }

            const div = document.createElement('div');
            div.innerText = String(text);
            return div.innerHTML;
        }

        function formatResolution(media) {
            if (!media) {
                return '-';
            }

            const videoResolution = media.video_resolution || '-';
            const width = media.width || '?';
            const height = media.height || '?';
            return `${videoResolution} (${width} x ${height})`;
        }

        async function loadSeriesDetails(seriesId) {
            const response = await fetch(`series_details.php?id=${encodeURIComponent(seriesId)}`, {
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('request_failed');
            }

            const payload = await response.json();
            if (!payload.ok) {
                throw new Error(payload.error || 'invalid_payload');
            }

            return payload.seasons || [];
        }

        function renderEpisodes(episodes) {
            if (!episodes || episodes.length === 0) {
                return `<p class="text-muted mb-0"><?= addslashes($lang['no_episode_data']) ?></p>`;
            }

            return episodes.map((episode) => {
                const code = escapeHtml(episode.season_episode || '-');
                const title = escapeHtml(episode.title || '-');
                const duration = escapeHtml(episode.duration_human || '-');
                const rating = escapeHtml(episode.rating ?? '-');
                const summary = escapeHtml(episode.summary || '<?= addslashes($lang['no_summary']) ?>');
                const container = escapeHtml(episode.media?.container || '-');
                const resolution = escapeHtml(formatResolution(episode.media));
                const videoCodec = escapeHtml(episode.media?.video_codec || '-');

                const hdrRaw = episode.media?.hdr;
                const hasHdr = hdrRaw === true || hdrRaw === 1 || hdrRaw === '1';
                const hdr = escapeHtml(episode.media ? (hasHdr ? '<?= addslashes($lang['yes']) ?>' : '<?= addslashes($lang['no']) ?>') : '-');

                return `
                    <details class="episode-detail-item">
                        <summary>
                            ${code} - ${title}
                        </summary>
                        <div class="episode-detail-content">
                            <div><strong><?= addslashes($lang['duration']) ?>:</strong> ${duration}</div>
                            <div><strong><?= addslashes($lang['rating']) ?>:</strong> ${rating}</div>
                            <div><strong><?= addslashes($lang['container']) ?>:</strong> ${container}</div>
                            <div><strong><?= addslashes($lang['resolution']) ?>:</strong> ${resolution}</div>
                            <div><strong><?= addslashes($lang['video_codec']) ?>:</strong> ${videoCodec}</div>
                            <div><strong><?= addslashes($lang['hdr']) ?>:</strong> ${hdr}</div>
                            <div class="episode-summary"><strong><?= addslashes($lang['summary'] ?? 'Summary') ?>:</strong> ${summary}</div>
                        </div>
                    </details>
                `;
            }).join('');
        }

        function renderSeasons(seasons) {
            const container = document.getElementById('mSeasons');

            if (!seasons || seasons.length === 0) {
                container.innerHTML = `<p class="text-muted mb-0"><?= addslashes($lang['no_episode_data']) ?></p>`;
                return;
            }

            container.innerHTML = seasons.map((season, index) => {
                const seasonNumber = escapeHtml(season.season_number ?? '-');
                const episodeCount = escapeHtml(season.episode_count ?? '-');
                const headingId = `seasonHeading${index}`;
                const collapseId = `seasonCollapse${index}`;

                return `
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="${headingId}">
                            <button class="accordion-button ${index === 0 ? '' : 'collapsed'}" type="button" data-bs-toggle="collapse" data-bs-target="#${collapseId}" aria-expanded="${index === 0 ? 'true' : 'false'}" aria-controls="${collapseId}">
                                <?= addslashes($lang['season']) ?> ${seasonNumber} (${episodeCount} <?= addslashes($lang['episodes']) ?>)
                            </button>
                        </h2>
                        <div id="${collapseId}" class="accordion-collapse collapse ${index === 0 ? 'show' : ''}" aria-labelledby="${headingId}" data-bs-parent="#mSeasons">
                            <div class="accordion-body">
                                ${renderEpisodes(season.episodes || [])}
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        async function openModalFromCard(card) {
            const payload = card.getAttribute('data-series');
            if (!payload) {
                return;
            }

            let data;
            try {
                data = JSON.parse(payload);
            } catch (e) {
                return;
            }

            document.getElementById('mTitle').innerText = data.title;
            document.getElementById('mOriginal').innerText = data.original_title || 'N/A';
            document.getElementById('mYear').innerText = data.year || '-';
            document.getElementById('mRating').innerText = data.audience_rating || '-';
            document.getElementById('mAdded').innerText = data.added_at || '-';
            document.getElementById('mSummary').innerText = data.summary || '';

            document.getElementById('mPoster').src = data.poster_src || 'https://via.placeholder.com/400x600?text=Poster';
            document.getElementById('mSeasons').innerHTML = `<p class="text-muted mb-0"><?= addslashes($lang['loading']) ?></p>`;

            try {
                const seasons = await loadSeriesDetails(data.id);
                renderSeasons(seasons);
            } catch (e) {
                document.getElementById('mSeasons').innerHTML = `<p class="text-danger mb-0"><?= addslashes($lang['error_loading_series_details']) ?></p>`;
            }
        }
    </script>
    <?php include __DIR__ . '/partials/scripts.php'; ?>
    </div>
</body>
</html>