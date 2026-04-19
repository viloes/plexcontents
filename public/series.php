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

$search = trim((string) ($_GET['search'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'added_at_desc');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = max(4, min(80, intval($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $limit;

$seriesContainerOptions = fetchDistinctMediaInfoOptions($pdo, 'episodes', 'container');
$seriesVideoCodecOptions = fetchDistinctMediaInfoOptions($pdo, 'episodes', 'videoCodec');
$seriesResolutionOptions = fetchDistinctMediaInfoOptions($pdo, 'episodes', 'videoResolution');

$ratingMin = getOptionalFloat('rating_min');
$ratingMax = getOptionalFloat('rating_max');
$durationMin = getOptionalFloat('duration_min');
$durationMax = getOptionalFloat('duration_max');
$yearMin = getOptionalInt('year_min');
$yearMax = getOptionalInt('year_max');
$addedFrom = getOptionalDate('added_from');
$addedTo = getOptionalDate('added_to');
$seasonsMin = getOptionalInt('seasons_min');
$seasonsMax = getOptionalInt('seasons_max');
$episodesMin = getOptionalInt('episodes_min');
$episodesMax = getOptionalInt('episodes_max');
$container = getOptionalEnum('container', $seriesContainerOptions);
$videoCodec = getOptionalEnum('video_codec', $seriesVideoCodecOptions);
$resolution = getOptionalEnum('resolution', $seriesResolutionOptions);
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
if ($seasonsMin !== null && $seasonsMax !== null && $seasonsMin > $seasonsMax) {
    [$seasonsMin, $seasonsMax] = [$seasonsMax, $seasonsMin];
}
if ($episodesMin !== null && $episodesMax !== null && $episodesMin > $episodesMax) {
    [$episodesMin, $episodesMax] = [$episodesMax, $episodesMin];
}

Logger::debug('Series list requested', [
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
    'seasons_min' => $seasonsMin,
    'seasons_max' => $seasonsMax,
    'episodes_min' => $episodesMin,
    'episodes_max' => $episodesMax,
    'container' => $container,
    'video_codec' => $videoCodec,
    'resolution' => $resolution,
    'hdr' => $hdr,
    'page' => $page,
    'limit' => $limit
]);

$fromSql = "FROM series
LEFT JOIN (
    SELECT e_total.series_id, COUNT(*) AS total_episodes
    FROM episodes e_total
    GROUP BY e_total.series_id
) episode_totals ON episode_totals.series_id = series.id
LEFT JOIN (
    SELECT s_total.series_id, SUM(COALESCE(s_total.episode_count, 0)) AS total_episodes
    FROM seasons s_total
    GROUP BY s_total.series_id
) season_totals ON season_totals.series_id = series.id";

$totalEpisodesSql = "COALESCE(episode_totals.total_episodes, season_totals.total_episodes, 0)";

$whereSql = "WHERE 1=1";
$params = [];
if ($search) {
    $whereSql .= " AND (title LIKE ? OR original_title LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($ratingMin !== null) {
    $whereSql .= " AND COALESCE(audience_rating, rating) >= CAST(? AS REAL)";
    $params[] = $ratingMin;
}
if ($ratingMax !== null) {
    $whereSql .= " AND COALESCE(audience_rating, rating) <= CAST(? AS REAL)";
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
if ($seasonsMin !== null) {
    $whereSql .= " AND season_count >= ?";
    $params[] = $seasonsMin;
}
if ($seasonsMax !== null) {
    $whereSql .= " AND season_count <= ?";
    $params[] = $seasonsMax;
}
if ($episodesMin !== null) {
    $whereSql .= " AND $totalEpisodesSql >= ?";
    $params[] = $episodesMin;
}
if ($episodesMax !== null) {
    $whereSql .= " AND $totalEpisodesSql <= ?";
    $params[] = $episodesMax;
}

$hasTechnicalFilter = $container !== null || $videoCodec !== null || $resolution !== null || $hdr !== 'any';
if ($hasTechnicalFilter) {
    $whereSql .= " AND EXISTS (SELECT 1 FROM episodes e_filter WHERE e_filter.series_id = series.id";
    if ($container !== null) {
        $whereSql .= " AND lower(json_extract(e_filter.media_info, '$.container')) = lower(?)";
        $params[] = $container;
    }
    if ($videoCodec !== null) {
        $whereSql .= " AND lower(json_extract(e_filter.media_info, '$.videoCodec')) = lower(?)";
        $params[] = $videoCodec;
    }
    if ($resolution !== null) {
        $whereSql .= " AND lower(json_extract(e_filter.media_info, '$.videoResolution')) = lower(?)";
        $params[] = $resolution;
    }

    $hdrTrueSql = "(CAST(json_extract(e_filter.media_info, '$.hdr') AS TEXT) IN ('1','true','TRUE'))";
    if ($hdr === 'yes') {
        $whereSql .= " AND $hdrTrueSql";
    }
    if ($hdr === 'no') {
        $whereSql .= " AND NOT $hdrTrueSql";
    }
    $whereSql .= ")";
}

$sortMap = [
    'added_at_desc' => 'ORDER BY added_at DESC',
    'title_asc' => 'ORDER BY title ASC',
    'rating_desc' => 'ORDER BY audience_rating DESC',
];
if (!isset($sortMap[$sort])) {
    $sort = 'added_at_desc';
}
$orderSql = $sortMap[$sort];

$stmtTotal = $pdo->prepare("SELECT COUNT(*) $fromSql $whereSql");
executeStatementWithTypedParams($stmtTotal, $params);
$total = $stmtTotal->fetchColumn();
$totalPages = ceil($total / $limit);

$stmt = $pdo->prepare("SELECT series.*, $totalEpisodesSql AS total_episodes $fromSql $whereSql $orderSql LIMIT $limit OFFSET $offset");
executeStatementWithTypedParams($stmt, $params);
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

$seriesQueryParams = [
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
    'seasons_min' => $seasonsMin,
    'seasons_max' => $seasonsMax,
    'episodes_min' => $episodesMin,
    'episodes_max' => $episodesMax,
    'container' => $container,
    'video_codec' => $videoCodec,
    'resolution' => $resolution,
    'hdr' => $hdr,
];

$clearSeriesFiltersUrl = 'series.php?per_page=' . urlencode((string) $limit);
$hasSeriesAdvancedFilters = $ratingMin !== null
    || $ratingMax !== null
    || $durationMin !== null
    || $durationMax !== null
    || $yearMin !== null
    || $yearMax !== null
    || $addedFrom !== null
    || $addedTo !== null
    || $seasonsMin !== null
    || $seasonsMax !== null
    || $episodesMin !== null
    || $episodesMax !== null
    || $container !== null
    || $videoCodec !== null
    || $resolution !== null
    || $hdr !== 'any';
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
        <div class="page-header d-flex flex-column gap-3">
            <h1 class="h3 mb-0"><?= $lang['series'] ?></h1>
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
                            <option value="rating_desc" <?= $sort == 'rating_desc' ? 'selected' : '' ?>><?= $lang['sort_rating_desc'] ?></option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-auto">
                        <button type="submit" class="btn btn-brand"><?= $lang['filter'] ?></button>
                    </div>
                    <div class="col-12 col-sm-auto">
                        <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#seriesAdvancedFilters" aria-expanded="<?= $hasSeriesAdvancedFilters ? 'true' : 'false' ?>" aria-controls="seriesAdvancedFilters">
                            <?= htmlspecialchars($lang['advanced_filters'] ?? 'Filtros avanzados') ?>
                        </button>
                    </div>
                    <div class="col-12 col-sm-auto">
                        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($clearSeriesFiltersUrl) ?>"><?= htmlspecialchars($lang['clear_filters'] ?? 'Limpiar filtros') ?></a>
                    </div>
                </div>

                <div class="collapse <?= $hasSeriesAdvancedFilters ? 'show' : '' ?> mt-3" id="seriesAdvancedFilters">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="row g-4">
                                <div class="col-6 col-xl-3">
                                    <h2 class="h6 mb-3"><?= htmlspecialchars($lang['group_quality_duration'] ?? 'Calidad y duración') ?></h2>
                                    <div class="row g-2">
                                        <div class="col-6 col-xl-12">
                                            <label for="series_rating_min" class="form-label small mb-1"><?= htmlspecialchars(($lang['rating'] ?? 'Valoración') . ' ' . ($lang['minimum'] ?? 'Mínimo')) ?></label>
                                            <input id="series_rating_min" type="number" step="0.1" min="0" max="10" name="rating_min" value="<?= htmlspecialchars((string) ($ratingMin ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6 col-xl-12">
                                            <label for="series_rating_max" class="form-label small mb-1"><?= htmlspecialchars(($lang['rating'] ?? 'Valoración') . ' ' . ($lang['maximum'] ?? 'Máximo')) ?></label>
                                            <input id="series_rating_max" type="number" step="0.1" min="0" max="10" name="rating_max" value="<?= htmlspecialchars((string) ($ratingMax ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6 col-xl-12">
                                            <label for="series_duration_min" class="form-label small mb-1"><?= htmlspecialchars(($lang['duration_minutes'] ?? 'Duración (minutos)') . ' ' . ($lang['minimum'] ?? 'Mínimo')) ?></label>
                                            <input id="series_duration_min" type="number" min="0" name="duration_min" value="<?= htmlspecialchars((string) ($durationMin ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6 col-xl-12">
                                            <label for="series_duration_max" class="form-label small mb-1"><?= htmlspecialchars(($lang['duration_minutes'] ?? 'Duración (minutos)') . ' ' . ($lang['maximum'] ?? 'Máximo')) ?></label>
                                            <input id="series_duration_max" type="number" min="0" name="duration_max" value="<?= htmlspecialchars((string) ($durationMax ?? '')) ?>" class="form-control">
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 col-xl-3">
                                    <h2 class="h6 mb-3"><?= htmlspecialchars($lang['group_dates'] ?? 'Fechas y año') ?></h2>
                                    <div class="row g-2">
                                        <div class="col-6 col-xl-12">
                                            <label for="series_year_min" class="form-label small mb-1"><?= htmlspecialchars(($lang['year'] ?? 'Año') . ' ' . ($lang['minimum'] ?? 'Mínimo')) ?></label>
                                            <input id="series_year_min" type="number" min="1888" max="2200" name="year_min" value="<?= htmlspecialchars((string) ($yearMin ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6 col-xl-12">
                                            <label for="series_year_max" class="form-label small mb-1"><?= htmlspecialchars(($lang['year'] ?? 'Año') . ' ' . ($lang['maximum'] ?? 'Máximo')) ?></label>
                                            <input id="series_year_max" type="number" min="1888" max="2200" name="year_max" value="<?= htmlspecialchars((string) ($yearMax ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6 col-xl-12">
                                            <label for="series_added_from" class="form-label small mb-1"><?= htmlspecialchars(($lang['added_at'] ?? 'Añadido el') . ' ' . ($lang['from'] ?? 'Desde')) ?></label>
                                            <input id="series_added_from" type="date" name="added_from" value="<?= htmlspecialchars((string) ($addedFrom ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6 col-xl-12">
                                            <label for="series_added_to" class="form-label small mb-1"><?= htmlspecialchars(($lang['added_at'] ?? 'Añadido el') . ' ' . ($lang['to'] ?? 'Hasta')) ?></label>
                                            <input id="series_added_to" type="date" name="added_to" value="<?= htmlspecialchars((string) ($addedTo ?? '')) ?>" class="form-control">
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 col-xl-3">
                                    <h2 class="h6 mb-3"><?= htmlspecialchars($lang['group_structure'] ?? 'Estructura de la serie') ?></h2>
                                    <div class="row g-2">
                                        <div class="col-6 col-xl-12">
                                            <label for="series_seasons_min" class="form-label small mb-1"><?= htmlspecialchars(($lang['seasons'] ?? 'Temporadas') . ' ' . ($lang['minimum'] ?? 'Mínimo')) ?></label>
                                            <input id="series_seasons_min" type="number" min="0" name="seasons_min" value="<?= htmlspecialchars((string) ($seasonsMin ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6 col-xl-12">
                                            <label for="series_seasons_max" class="form-label small mb-1"><?= htmlspecialchars(($lang['seasons'] ?? 'Temporadas') . ' ' . ($lang['maximum'] ?? 'Máximo')) ?></label>
                                            <input id="series_seasons_max" type="number" min="0" name="seasons_max" value="<?= htmlspecialchars((string) ($seasonsMax ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6 col-xl-12">
                                            <label for="series_episodes_min" class="form-label small mb-1"><?= htmlspecialchars(($lang['episodes'] ?? 'Episodios') . ' ' . ($lang['minimum'] ?? 'Mínimo')) ?></label>
                                            <input id="series_episodes_min" type="number" min="0" name="episodes_min" value="<?= htmlspecialchars((string) ($episodesMin ?? '')) ?>" class="form-control">
                                        </div>
                                        <div class="col-6 col-xl-12">
                                            <label for="series_episodes_max" class="form-label small mb-1"><?= htmlspecialchars(($lang['episodes'] ?? 'Episodios') . ' ' . ($lang['maximum'] ?? 'Máximo')) ?></label>
                                            <input id="series_episodes_max" type="number" min="0" name="episodes_max" value="<?= htmlspecialchars((string) ($episodesMax ?? '')) ?>" class="form-control">
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 col-xl-3">
                                    <h2 class="h6 mb-3"><?= htmlspecialchars($lang['group_technical'] ?? 'Formato técnico') ?></h2>
                                    <div class="row g-2">
                                        <div class="col-12">
                                            <label for="series_container" class="form-label small mb-1"><?= htmlspecialchars($lang['container'] ?? 'Contenedor') ?></label>
                                            <select id="series_container" name="container" class="form-select">
                                                <option value=""><?= htmlspecialchars(($lang['container'] ?? 'Container') . ' - ' . ($lang['all'] ?? 'All')) ?></option>
                                                <?php foreach ($seriesContainerOptions as $option): ?>
                                                    <option value="<?= htmlspecialchars($option) ?>" <?= $container === $option ? 'selected' : '' ?>><?= htmlspecialchars(formatTechnicalOptionLabel($option)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label for="series_video_codec" class="form-label small mb-1"><?= htmlspecialchars($lang['video_codec'] ?? 'Códec de vídeo') ?></label>
                                            <select id="series_video_codec" name="video_codec" class="form-select">
                                                <option value=""><?= htmlspecialchars(($lang['video_codec'] ?? 'Video codec') . ' - ' . ($lang['all'] ?? 'All')) ?></option>
                                                <?php foreach ($seriesVideoCodecOptions as $option): ?>
                                                    <option value="<?= htmlspecialchars($option) ?>" <?= $videoCodec === $option ? 'selected' : '' ?>><?= htmlspecialchars(formatTechnicalOptionLabel($option)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label for="series_resolution" class="form-label small mb-1"><?= htmlspecialchars($lang['resolution'] ?? 'Resolución') ?></label>
                                            <select id="series_resolution" name="resolution" class="form-select">
                                                <option value=""><?= htmlspecialchars(($lang['resolution'] ?? 'Resolution') . ' - ' . ($lang['all'] ?? 'All')) ?></option>
                                                <?php foreach ($seriesResolutionOptions as $option): ?>
                                                    <option value="<?= htmlspecialchars($option) ?>" <?= $resolution === $option ? 'selected' : '' ?>><?= htmlspecialchars(formatTechnicalOptionLabel($option)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label for="series_hdr" class="form-label small mb-1"><?= htmlspecialchars($lang['hdr'] ?? 'HDR') ?></label>
                                            <select id="series_hdr" name="hdr" class="form-select">
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

        <?= Pagination::render($page, (int) $totalPages, 'series.php', $seriesQueryParams, $paginationLabels, 1) ?>
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