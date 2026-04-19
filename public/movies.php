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

function getOptionalInt(string $key): ?int {
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }

    $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
    return $value !== false ? $value : null;
}

function getOptionalFloat(string $key): ?float {
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }

    $value = filter_var($_GET[$key], FILTER_VALIDATE_FLOAT);
    return $value !== false ? (float) $value : null;
}

function minutesToStoredDuration(?float $minutes): ?int {
    if ($minutes === null) {
        return null;
    }

    return max(0, (int) round($minutes * 60 * 1000));
}

function getOptionalDate(string $key): ?string {
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }

    $value = trim((string) $_GET[$key]);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

function getOptionalEnum(string $key, array $allowed): ?string {
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }

    $value = trim((string) $_GET[$key]);
    return in_array($value, $allowed, true) ? $value : null;
}

function fetchDistinctMediaInfoOptions(PDO $pdo, string $table, string $jsonKey): array {
    $allowedTables = ['movies', 'episodes'];
    $allowedKeys = ['container', 'videoCodec', 'videoResolution'];

    if (!in_array($table, $allowedTables, true) || !in_array($jsonKey, $allowedKeys, true)) {
        return [];
    }

    $sql = "SELECT DISTINCT lower(CAST(json_extract(media_info, '$.$jsonKey') AS TEXT)) AS option_value
        FROM $table
        WHERE media_info IS NOT NULL
          AND trim(CAST(json_extract(media_info, '$.$jsonKey') AS TEXT)) <> ''
        ORDER BY option_value ASC";

    $stmt = $pdo->query($sql);
    $values = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return array_values(array_filter(array_map(static function ($value) {
        return is_string($value) ? trim($value) : null;
    }, $values)));
}

function formatTechnicalOptionLabel(string $value): string {
    $labels = [
        'av1' => 'AV1',
        'h264' => 'H.264',
        'h265' => 'H.265',
        'hevc' => 'HEVC',
        'mpeg' => 'MPEG',
        'mpeg1video' => 'MPEG-1',
        'mpeg2video' => 'MPEG-2',
        'mpeg4' => 'MPEG-4',
        'mkv' => 'MKV',
        'mov' => 'MOV',
        'mp4' => 'MP4',
        'm4v' => 'M4V',
        'msmpeg4v3' => 'MSMPEG4V3',
        'sd' => 'SD',
        'ts' => 'TS',
        'vc1' => 'VC-1',
        'vp9' => 'VP9',
        'webm' => 'WEBM',
    ];

    return $labels[$value] ?? strtoupper($value);
}

function executeStatementWithTypedParams(PDOStatement $stmt, array $params): void {
    foreach (array_values($params) as $index => $value) {
        $parameterIndex = $index + 1;

        if (is_int($value)) {
            $stmt->bindValue($parameterIndex, $value, PDO::PARAM_INT);
            continue;
        }

        if ($value === null) {
            $stmt->bindValue($parameterIndex, null, PDO::PARAM_NULL);
            continue;
        }

        $stmt->bindValue($parameterIndex, (string) $value, PDO::PARAM_STR);
    }

    $stmt->execute();
}

// Filtros y paginación
$search = trim((string) ($_GET['search'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'added_at_desc');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = max(4, min(80, intval($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $limit;

$movieContainerOptions = fetchDistinctMediaInfoOptions($pdo, 'movies', 'container');
$movieVideoCodecOptions = fetchDistinctMediaInfoOptions($pdo, 'movies', 'videoCodec');
$movieResolutionOptions = fetchDistinctMediaInfoOptions($pdo, 'movies', 'videoResolution');

$ratingMin = getOptionalFloat('rating_min');
$ratingMax = getOptionalFloat('rating_max');
$durationMin = getOptionalFloat('duration_min');
$durationMax = getOptionalFloat('duration_max');
$yearMin = getOptionalInt('year_min');
$yearMax = getOptionalInt('year_max');
$addedFrom = getOptionalDate('added_from');
$addedTo = getOptionalDate('added_to');
$container = getOptionalEnum('container', $movieContainerOptions);
$videoCodec = getOptionalEnum('video_codec', $movieVideoCodecOptions);
$resolution = getOptionalEnum('resolution', $movieResolutionOptions);
$hdr = getOptionalEnum('hdr', ['any', 'yes', 'no']) ?? 'any';

if ($ratingMin !== null && $ratingMax !== null && $ratingMin > $ratingMax) {
    [$ratingMin, $ratingMax] = [$ratingMax, $ratingMin];
}
if ($durationMin !== null && $durationMax !== null && $durationMin > $durationMax) {
    [$durationMin, $durationMax] = [$durationMax, $durationMin];
}

$durationMinStored = minutesToStoredDuration($durationMin);
$durationMaxStored = minutesToStoredDuration($durationMax);
if ($yearMin !== null && $yearMax !== null && $yearMin > $yearMax) {
    [$yearMin, $yearMax] = [$yearMax, $yearMin];
}
if ($addedFrom !== null && $addedTo !== null && $addedFrom > $addedTo) {
    [$addedFrom, $addedTo] = [$addedTo, $addedFrom];
}

Logger::debug('Movies list requested', [
    'user_id' => $_SESSION['user_id'] ?? null,
    'search' => $search,
    'sort' => $sort,
    'rating_min' => $ratingMin,
    'rating_max' => $ratingMax,
    'duration_min' => $durationMin,
    'duration_max' => $durationMax,
    'year_min' => $yearMin,
    'year_max' => $yearMax,
    'added_from' => $addedFrom,
    'added_to' => $addedTo,
    'container' => $container,
    'video_codec' => $videoCodec,
    'resolution' => $resolution,
    'hdr' => $hdr,
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

if ($ratingMin !== null) {
    $whereSql .= " AND rating >= CAST(? AS REAL)";
    $params[] = $ratingMin;
}
if ($ratingMax !== null) {
    $whereSql .= " AND rating <= CAST(? AS REAL)";
    $params[] = $ratingMax;
}
if ($durationMin !== null) {
    $whereSql .= " AND duration >= ?";
    $params[] = $durationMinStored;
}
if ($durationMax !== null) {
    $whereSql .= " AND duration <= ?";
    $params[] = $durationMaxStored;
}
if ($yearMin !== null) {
    $whereSql .= " AND year >= ?";
    $params[] = $yearMin;
}
if ($yearMax !== null) {
    $whereSql .= " AND year <= ?";
    $params[] = $yearMax;
}
if ($addedFrom !== null) {
    $whereSql .= " AND date(added_at) >= date(?)";
    $params[] = $addedFrom;
}
if ($addedTo !== null) {
    $whereSql .= " AND date(added_at) <= date(?)";
    $params[] = $addedTo;
}
if ($container !== null) {
    $whereSql .= " AND lower(json_extract(media_info, '$.container')) = lower(?)";
    $params[] = $container;
}
if ($videoCodec !== null) {
    $whereSql .= " AND lower(json_extract(media_info, '$.videoCodec')) = lower(?)";
    $params[] = $videoCodec;
}
if ($resolution !== null) {
    $whereSql .= " AND lower(json_extract(media_info, '$.videoResolution')) = lower(?)";
    $params[] = $resolution;
}

$hdrTrueSql = "(CAST(json_extract(media_info, '$.hdr') AS TEXT) IN ('1','true','TRUE'))";
if ($hdr === 'yes') {
    $whereSql .= " AND $hdrTrueSql";
}
if ($hdr === 'no') {
    $whereSql .= " AND NOT $hdrTrueSql";
}

$sortMap = [
    'added_at_desc' => 'ORDER BY added_at DESC',
    'title_asc' => 'ORDER BY title ASC',
    'year_desc' => 'ORDER BY year DESC',
    'rating_desc' => 'ORDER BY rating DESC',
];
if (!isset($sortMap[$sort])) {
    $sort = 'added_at_desc';
}
$orderSql = $sortMap[$sort];

// Total para paginación
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM movies $whereSql");
executeStatementWithTypedParams($stmtTotal, $params);
$total = $stmtTotal->fetchColumn();
$totalPages = ceil($total / $limit);

// Registros
$sql = "SELECT * FROM movies $whereSql $orderSql LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
executeStatementWithTypedParams($stmt, $params);
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

$movieQueryParams = [
    'search' => $search,
    'sort' => $sort,
    'per_page' => $limit,
    'rating_min' => $ratingMin,
    'rating_max' => $ratingMax,
    'duration_min' => $durationMin,
    'duration_max' => $durationMax,
    'year_min' => $yearMin,
    'year_max' => $yearMax,
    'added_from' => $addedFrom,
    'added_to' => $addedTo,
    'container' => $container,
    'video_codec' => $videoCodec,
    'resolution' => $resolution,
    'hdr' => $hdr,
];

$clearMovieFiltersUrl = 'movies.php?per_page=' . urlencode((string) $limit);
$hasMovieAdvancedFilters = $ratingMin !== null
    || $ratingMax !== null
    || $durationMin !== null
    || $durationMax !== null
    || $yearMin !== null
    || $yearMax !== null
    || $addedFrom !== null
    || $addedTo !== null
    || $container !== null
    || $videoCodec !== null
    || $resolution !== null
    || $hdr !== 'any';
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
        <div class="page-header d-flex flex-column gap-3">
            <h1 class="h3 mb-0"><?= $lang['movies'] ?></h1>
            <form method="GET" class="w-100">
                <input type="hidden" name="per_page" value="<?= (int) $limit ?>">
                <div class="row g-2 align-items-center">
                    <div class="col-12 col-md-5 col-xl-4">
                        <input type="text" name="search" placeholder="<?= $lang['search'] ?>" value="<?= htmlspecialchars($search) ?>" class="form-control">
                    </div>
                    <div class="col-12 col-md-4 col-xl-3">
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
                    <div class="col-12 col-sm-auto">
                        <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#movieAdvancedFilters" aria-expanded="<?= $hasMovieAdvancedFilters ? 'true' : 'false' ?>" aria-controls="movieAdvancedFilters">
                            <?= htmlspecialchars($lang['advanced_filters'] ?? 'Filtros avanzados') ?>
                        </button>
                    </div>
                    <div class="col-12 col-sm-auto">
                        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($clearMovieFiltersUrl) ?>"><?= htmlspecialchars($lang['clear_filters'] ?? 'Limpiar filtros') ?></a>
                    </div>
                </div>

                <div class="collapse <?= $hasMovieAdvancedFilters ? 'show' : '' ?> mt-3" id="movieAdvancedFilters">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="row g-4">
                                <div class="col-12 col-xl-4">
                                    <h2 class="h6 mb-3"><?= htmlspecialchars($lang['group_quality_duration'] ?? 'Calidad y duración') ?></h2>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label for="movies_rating_min" class="form-label small mb-1"><?= htmlspecialchars(($lang['rating'] ?? 'Valoración') . ' ' . ($lang['minimum'] ?? 'Mínimo')) ?></label>
                                            <input id="movies_rating_min" type="number" step="0.1" min="0" max="10" name="rating_min" value="<?= htmlspecialchars((string) ($ratingMin ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6">
                                            <label for="movies_rating_max" class="form-label small mb-1"><?= htmlspecialchars(($lang['rating'] ?? 'Valoración') . ' ' . ($lang['maximum'] ?? 'Máximo')) ?></label>
                                            <input id="movies_rating_max" type="number" step="0.1" min="0" max="10" name="rating_max" value="<?= htmlspecialchars((string) ($ratingMax ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6">
                                            <label for="movies_duration_min" class="form-label small mb-1"><?= htmlspecialchars(($lang['duration_minutes'] ?? 'Duración (minutos)') . ' ' . ($lang['minimum'] ?? 'Mínimo')) ?></label>
                                            <input id="movies_duration_min" type="number" min="0" name="duration_min" value="<?= htmlspecialchars((string) ($durationMin ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6">
                                            <label for="movies_duration_max" class="form-label small mb-1"><?= htmlspecialchars(($lang['duration_minutes'] ?? 'Duración (minutos)') . ' ' . ($lang['maximum'] ?? 'Máximo')) ?></label>
                                            <input id="movies_duration_max" type="number" min="0" name="duration_max" value="<?= htmlspecialchars((string) ($durationMax ?? '')) ?>" class="form-control">
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 col-xl-4">
                                    <h2 class="h6 mb-3"><?= htmlspecialchars($lang['group_dates'] ?? 'Fechas y año') ?></h2>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label for="movies_year_min" class="form-label small mb-1"><?= htmlspecialchars(($lang['year'] ?? 'Año') . ' ' . ($lang['minimum'] ?? 'Mínimo')) ?></label>
                                            <input id="movies_year_min" type="number" min="1888" max="2200" name="year_min" value="<?= htmlspecialchars((string) ($yearMin ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6">
                                            <label for="movies_year_max" class="form-label small mb-1"><?= htmlspecialchars(($lang['year'] ?? 'Año') . ' ' . ($lang['maximum'] ?? 'Máximo')) ?></label>
                                            <input id="movies_year_max" type="number" min="1888" max="2200" name="year_max" value="<?= htmlspecialchars((string) ($yearMax ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6">
                                            <label for="movies_added_from" class="form-label small mb-1"><?= htmlspecialchars(($lang['added_at'] ?? 'Añadido el') . ' ' . ($lang['from'] ?? 'Desde')) ?></label>
                                            <input id="movies_added_from" type="date" name="added_from" value="<?= htmlspecialchars((string) ($addedFrom ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6">
                                            <label for="movies_added_to" class="form-label small mb-1"><?= htmlspecialchars(($lang['added_at'] ?? 'Añadido el') . ' ' . ($lang['to'] ?? 'Hasta')) ?></label>
                                            <input id="movies_added_to" type="date" name="added_to" value="<?= htmlspecialchars((string) ($addedTo ?? '')) ?>" class="form-control">
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 col-xl-4">
                                    <h2 class="h6 mb-3"><?= htmlspecialchars($lang['group_technical'] ?? 'Formato técnico') ?></h2>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label for="movies_container" class="form-label small mb-1"><?= htmlspecialchars($lang['container'] ?? 'Contenedor') ?></label>
                                            <select id="movies_container" name="container" class="form-select">
                                                <option value=""><?= htmlspecialchars(($lang['container'] ?? 'Container') . ' - ' . ($lang['all'] ?? 'All')) ?></option>
                                                <?php foreach ($movieContainerOptions as $option): ?>
                                                    <option value="<?= htmlspecialchars($option) ?>" <?= $container === $option ? 'selected' : '' ?>><?= htmlspecialchars(formatTechnicalOptionLabel($option)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label for="movies_video_codec" class="form-label small mb-1"><?= htmlspecialchars($lang['video_codec'] ?? 'Códec de vídeo') ?></label>
                                            <select id="movies_video_codec" name="video_codec" class="form-select">
                                                <option value=""><?= htmlspecialchars(($lang['video_codec'] ?? 'Video codec') . ' - ' . ($lang['all'] ?? 'All')) ?></option>
                                                <?php foreach ($movieVideoCodecOptions as $option): ?>
                                                    <option value="<?= htmlspecialchars($option) ?>" <?= $videoCodec === $option ? 'selected' : '' ?>><?= htmlspecialchars(formatTechnicalOptionLabel($option)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label for="movies_resolution" class="form-label small mb-1"><?= htmlspecialchars($lang['resolution'] ?? 'Resolución') ?></label>
                                            <select id="movies_resolution" name="resolution" class="form-select">
                                                <option value=""><?= htmlspecialchars(($lang['resolution'] ?? 'Resolution') . ' - ' . ($lang['all'] ?? 'All')) ?></option>
                                                <?php foreach ($movieResolutionOptions as $option): ?>
                                                    <option value="<?= htmlspecialchars($option) ?>" <?= $resolution === $option ? 'selected' : '' ?>><?= htmlspecialchars(formatTechnicalOptionLabel($option)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label for="movies_hdr" class="form-label small mb-1"><?= htmlspecialchars($lang['hdr'] ?? 'HDR') ?></label>
                                            <select id="movies_hdr" name="hdr" class="form-select">
                                                <option value="any" <?= $hdr === 'any' ? 'selected' : '' ?>><?= htmlspecialchars(($lang['hdr'] ?? 'HDR') . ' - ' . ($lang['all'] ?? 'All')) ?></option>
                                                <option value="yes" <?= $hdr === 'yes' ? 'selected' : '' ?>><?= htmlspecialchars(($lang['yes'] ?? 'Yes')) ?></option>
                                                <option value="no" <?= $hdr === 'no' ? 'selected' : '' ?>><?= htmlspecialchars(($lang['no'] ?? 'No')) ?></option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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

        <?= Pagination::render($page, (int) $totalPages, 'movies.php', $movieQueryParams, $paginationLabels, 1) ?>
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
        (function syncPerPageWithViewport() {
            const grid = document.querySelector('.grid');
            if (!grid) {
                return;
            }

            const rowsPerPage = 4;
            let lastColumns = null;
            let resizeTimer = null;

            function getColumnCount() {
                const templateColumns = window.getComputedStyle(grid).gridTemplateColumns;
                if (!templateColumns || templateColumns === 'none') {
                    return 1;
                }

                return Math.max(1, templateColumns.split(' ').filter((value) => value.trim() !== '').length);
            }

            function applyPerPageIfNeeded(forceCheck = false) {
                const columns = getColumnCount();
                const columnChanged = lastColumns === null || columns !== lastColumns;

                if (!forceCheck && !columnChanged) {
                    return;
                }

                lastColumns = columns;
                const desiredPerPage = Math.max(4, Math.min(80, columns * rowsPerPage));

                const params = new URLSearchParams(window.location.search);
                const currentPerPage = parseInt(params.get('per_page') || '20', 10);

                if (currentPerPage === desiredPerPage) {
                    return;
                }

                params.set('per_page', String(desiredPerPage));
                params.set('page', '1');
                window.location.replace(`${window.location.pathname}?${params.toString()}`);
            }

            function onViewportChange() {
                if (resizeTimer) {
                    window.clearTimeout(resizeTimer);
                }

                resizeTimer = window.setTimeout(() => {
                    applyPerPageIfNeeded(false);
                }, 180);
            }

            applyPerPageIfNeeded(true);
            window.addEventListener('resize', onViewportChange);
            window.addEventListener('orientationchange', onViewportChange);
        })();

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