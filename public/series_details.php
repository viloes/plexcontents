<?php
// public/series_details.php
require_once __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\DB;
use App\Logger;

Auth::requireLogin();
Auth::startSession();

header('Content-Type: application/json; charset=UTF-8');

$seriesId = (int) ($_GET['id'] ?? 0);
if ($seriesId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_series_id']);
    exit;
}

try {
    $pdo = DB::getConnection();

    $stmtSeasons = $pdo->prepare(
        'SELECT id, season_number, episode_count, title, summary
         FROM seasons
         WHERE series_id = ?
         ORDER BY season_number ASC'
    );
    $stmtSeasons->execute([$seriesId]);
    $seasons = $stmtSeasons->fetchAll(PDO::FETCH_ASSOC);

    if (empty($seasons)) {
        echo json_encode(['ok' => true, 'seasons' => []]);
        exit;
    }

    $seasonIds = array_column($seasons, 'id');
    $placeholders = implode(',', array_fill(0, count($seasonIds), '?'));

    $stmtEpisodes = $pdo->prepare(
        "SELECT season_id, episode_number, season_episode, title, duration_human, rating, summary, media_info
         FROM episodes
         WHERE season_id IN ($placeholders)
         ORDER BY season_number ASC, episode_number ASC"
    );
    $stmtEpisodes->execute($seasonIds);
    $episodes = $stmtEpisodes->fetchAll(PDO::FETCH_ASSOC);

    $episodesBySeason = [];
    foreach ($episodes as $episode) {
        $mediaInfo = [];
        if (!empty($episode['media_info'])) {
            $decoded = json_decode((string) $episode['media_info'], true);
            if (is_array($decoded)) {
                $mediaInfo = $decoded;
            }
        }

        $episodesBySeason[(int) $episode['season_id']][] = [
            'episode_number' => $episode['episode_number'],
            'season_episode' => $episode['season_episode'],
            'title' => $episode['title'],
            'duration_human' => $episode['duration_human'],
            'rating' => $episode['rating'],
            'summary' => $episode['summary'],
            'media' => [
                'container' => $mediaInfo['container'] ?? null,
                'video_resolution' => $mediaInfo['videoResolution'] ?? null,
                'width' => $mediaInfo['width'] ?? null,
                'height' => $mediaInfo['height'] ?? null,
                'video_codec' => $mediaInfo['videoCodec'] ?? null,
                'hdr' => $mediaInfo['hdr'] ?? null,
            ],
        ];
    }

    $payload = [];
    foreach ($seasons as $season) {
        $seasonId = (int) $season['id'];
        $payload[] = [
            'id' => $seasonId,
            'season_number' => $season['season_number'],
            'episode_count' => $season['episode_count'],
            'title' => $season['title'],
            'summary' => $season['summary'],
            'episodes' => $episodesBySeason[$seasonId] ?? [],
        ];
    }

    echo json_encode(['ok' => true, 'seasons' => $payload]);
} catch (Throwable $e) {
    Logger::error('Failed to load series detail payload', [
        'series_id' => $seriesId,
        'error' => $e->getMessage(),
        'user_id' => $_SESSION['user_id'] ?? null,
    ]);

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
