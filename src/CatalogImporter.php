<?php
// src/CatalogImporter.php
namespace App;

use PDO;
use PDOStatement;

/**
 * Encapsula toda la lógica de inserción masiva en la base de datos
 * para películas, temporadas y episodios importados desde JSON de Tautulli.
 */
class CatalogImporter {

    // -------------------------------------------------------------------------
    // Películas
    // -------------------------------------------------------------------------

    /**
     * Vacía la tabla movies e inserta todos los ítems del array JSON.
     *
     * @param PDO   $pdo  Conexión activa (dentro de una transacción)
     * @param array $data Array decodificado del JSON de Tautulli
     * @return int        Número de películas insertadas
     */
    public static function importMovies(PDO $pdo, array $data): int {
        $pdo->exec("DELETE FROM movies");

        $stmt = $pdo->prepare(
            "INSERT INTO movies (
                guid, rating_key, title, title_sort, original_title, year,
                rating, rating_image, audience_rating, audience_rating_image,
                user_rating, duration, duration_human, added_at,
                originally_available_at, content_rating, edition_title,
                has_credits_marker, studio, tagline, summary, thumb_file,
                locations, media_info
            ) VALUES (
                :guid, :rating_key, :title, :title_sort, :original_title, :year,
                :rating, :rating_image, :audience_rating, :audience_rating_image,
                :user_rating, :duration, :duration_human, :added_at,
                :originally_available_at, :content_rating, :edition_title,
                :has_credits_marker, :studio, :tagline, :summary, :thumb_file,
                :locations, :media_info
            )"
        );

        $imported = 0;
        foreach ($data as $item) {
            if (isset($item['type']) && $item['type'] !== 'movie') {
                continue;
            }
            self::insertMovie($stmt, $item);
            $imported++;
        }

        return $imported;
    }

    private static function insertMovie(PDOStatement $stmt, array $item): void {
        $stmt->execute([
            ':guid'                    => $item['guid']                   ?? null,
            ':rating_key'              => $item['ratingKey']              ?? null,
            ':title'                   => $item['title']                  ?? 'Sin título',
            ':title_sort'              => $item['titleSort']              ?? null,
            ':original_title'          => $item['originalTitle']          ?? null,
            ':year'                    => $item['year']                   ?? null,
            ':rating'                  => $item['rating']                 ?? null,
            ':rating_image'            => $item['ratingImage']            ?? null,
            ':audience_rating'         => $item['audienceRating']         ?? null,
            ':audience_rating_image'   => $item['audienceRatingImage']    ?? null,
            ':user_rating'             => $item['userRating']             ?? null,
            ':duration'                => $item['duration']               ?? null,
            ':duration_human'          => $item['durationHuman']          ?? null,
            ':added_at'                => $item['addedAt']                ?? null,
            ':originally_available_at' => $item['originallyAvailableAt'] ?? null,
            ':content_rating'          => $item['contentRating']          ?? null,
            ':edition_title'           => $item['editionTitle']           ?? null,
            ':has_credits_marker'      => isset($item['hasCreditsMarker']) ? (int) $item['hasCreditsMarker'] : 0,
            ':studio'                  => $item['studio']                 ?? null,
            ':tagline'                 => $item['tagline']                ?? null,
            ':summary'                 => $item['summary']                ?? null,
            ':thumb_file'              => $item['thumbFile']              ?? null,
            ':locations'               => isset($item['locations'])  ? json_encode($item['locations'])  : null,
            ':media_info'              => isset($item['media'][0])   ? json_encode($item['media'][0])   : null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Series, Temporadas y Episodios
    // -------------------------------------------------------------------------

    /**
     * Vacía la tabla series (la cascade borra seasons y episodes automáticamente)
     * e inserta series completas con todas sus temporadas y episodios.
     *
     * @param PDO   $pdo  Conexión activa (dentro de una transacción)
     * @param array $data Array decodificado del JSON de Tautulli
     * @return array      [seriesInsertadas, temporadasInsertadas, episodiosInsertados]
     */
    public static function importSeries(PDO $pdo, array $data): array {
        $pdo->exec("DELETE FROM episodes");
        $pdo->exec("DELETE FROM seasons");
        $pdo->exec("DELETE FROM series");

        $stmtSeries = $pdo->prepare(
            "INSERT INTO series (
                guid, rating_key, title, title_sort, original_title, year,
                rating, audience_rating, audience_rating_image, user_rating,
                duration, duration_human, added_at, originally_available_at,
                content_rating, child_count, season_count, network,
                studio, tagline, summary, thumb_file
            ) VALUES (
                :guid, :rating_key, :title, :title_sort, :original_title, :year,
                :rating, :audience_rating, :audience_rating_image, :user_rating,
                :duration, :duration_human, :added_at, :originally_available_at,
                :content_rating, :child_count, :season_count, :network,
                :studio, :tagline, :summary, :thumb_file
            )"
        );

        $stmtSeason = $pdo->prepare(
            "INSERT INTO seasons (
                series_id, season_number, episode_count,
                guid, rating_key, parent_guid,
                title, title_sort, summary, thumb_file,
                added_at, user_rating, year
            ) VALUES (
                :series_id, :season_number, :episode_count,
                :guid, :rating_key, :parent_guid,
                :title, :title_sort, :summary, :thumb_file,
                :added_at, :user_rating, :year
            )"
        );

        $stmtEpisode = $pdo->prepare(
            "INSERT INTO episodes (
                season_id, series_id,
                guid, rating_key,
                episode_number, season_number, season_episode,
                title, title_sort, summary,
                added_at, originally_available_at, year,
                duration, duration_human,
                rating, audience_rating, audience_rating_image,
                content_rating, user_rating,
                has_intro_marker, has_credits_marker, has_commercial_marker,
                locations, media_info,
                parent_guid, parent_rating_key,
                grandparent_guid, grandparent_rating_key
            ) VALUES (
                :season_id, :series_id,
                :guid, :rating_key,
                :episode_number, :season_number, :season_episode,
                :title, :title_sort, :summary,
                :added_at, :originally_available_at, :year,
                :duration, :duration_human,
                :rating, :audience_rating, :audience_rating_image,
                :content_rating, :user_rating,
                :has_intro_marker, :has_credits_marker, :has_commercial_marker,
                :locations, :media_info,
                :parent_guid, :parent_rating_key,
                :grandparent_guid, :grandparent_rating_key
            )"
        );

        $seriesInserted   = 0;
        $seasonsInserted  = 0;
        $episodesInserted = 0;

        foreach ($data as $item) {
            $seasonsData = $item['seasons'] ?? [];
            $seasonCount = count($seasonsData) ?: (int) ($item['seasonCount'] ?? 0);

            $stmtSeries->execute([
                ':guid'                    => $item['guid']                   ?? uniqid('show_', true),
                ':rating_key'              => $item['ratingKey']              ?? null,
                ':title'                   => $item['title']                  ?? 'Sin título',
                ':title_sort'              => $item['titleSort']              ?? null,
                ':original_title'          => $item['originalTitle']          ?? null,
                ':year'                    => $item['year']                   ?? null,
                ':rating'                  => $item['rating']                 ?? null,
                ':audience_rating'         => $item['audienceRating']         ?? null,
                ':audience_rating_image'   => $item['audienceRatingImage']    ?? null,
                ':user_rating'             => $item['userRating']             ?? null,
                ':duration'                => $item['duration']               ?? null,
                ':duration_human'          => $item['durationHuman']          ?? null,
                ':added_at'                => $item['addedAt']                ?? null,
                ':originally_available_at' => $item['originallyAvailableAt'] ?? null,
                ':content_rating'          => $item['contentRating']          ?? null,
                ':child_count'             => $item['childCount']             ?? null,
                ':season_count'            => $seasonCount,
                ':network'                 => $item['network']                ?? null,
                ':studio'                  => $item['studio']                 ?? null,
                ':tagline'                 => $item['tagline']                ?? null,
                ':summary'                 => $item['summary']                ?? null,
                ':thumb_file'              => $item['thumbFile']              ?? null,
            ]);
            $seriesInserted++;
            $seriesId = (int) $pdo->lastInsertId();

            foreach ($seasonsData as $season) {
                $episodesData = $season['episodes'] ?? [];
                $episodeCount = count($episodesData);

                self::insertSeason($stmtSeason, $seriesId, $season, $episodeCount);
                $seasonId = (int) $pdo->lastInsertId();
                $seasonsInserted++;

                foreach ($episodesData as $episode) {
                    self::insertEpisode($stmtEpisode, $seasonId, $seriesId, $episode);
                    $episodesInserted++;
                }
            }
        }

        return [$seriesInserted, $seasonsInserted, $episodesInserted];
    }

    private static function insertSeason(
        PDOStatement $stmt,
        int $seriesId,
        array $season,
        int $episodeCount
    ): void {
        $stmt->execute([
            ':series_id'     => $seriesId,
            ':season_number' => $season['seasonNumber'] ?? null,
            ':episode_count' => $episodeCount,
            ':guid'          => $season['guid']         ?? null,
            ':rating_key'    => $season['ratingKey']    ?? null,
            ':parent_guid'   => $season['parentGuid']   ?? null,
            ':title'         => $season['title']        ?? null,
            ':title_sort'    => $season['titleSort']    ?? null,
            ':summary'       => $season['summary']      ?? null,
            ':thumb_file'    => $season['thumbFile']    ?? null,
            ':added_at'      => $season['addedAt']      ?? null,
            ':user_rating'   => $season['userRating']   ?? null,
            ':year'          => $season['year']         ?? null,
        ]);
    }

    private static function insertEpisode(
        PDOStatement $stmt,
        int $seasonId,
        int $seriesId,
        array $episode
    ): void {
        $stmt->execute([
            ':season_id'               => $seasonId,
            ':series_id'               => $seriesId,
            ':guid'                    => $episode['guid']                   ?? null,
            ':rating_key'              => $episode['ratingKey']              ?? null,
            ':episode_number'          => $episode['episodeNumber']          ?? null,
            ':season_number'           => $episode['seasonNumber']           ?? null,
            ':season_episode'          => $episode['seasonEpisode']          ?? null,
            ':title'                   => $episode['title']                  ?? null,
            ':title_sort'              => $episode['titleSort']              ?? null,
            ':summary'                 => $episode['summary']                ?? null,
            ':added_at'                => $episode['addedAt']                ?? null,
            ':originally_available_at' => $episode['originallyAvailableAt'] ?? null,
            ':year'                    => $episode['year']                   ?? null,
            ':duration'                => $episode['duration']               ?? null,
            ':duration_human'          => $episode['durationHuman']          ?? null,
            ':rating'                  => $episode['rating']                 ?? null,
            ':audience_rating'         => $episode['audienceRating']         ?? null,
            ':audience_rating_image'   => $episode['audienceRatingImage']    ?? null,
            ':content_rating'          => $episode['contentRating']          ?? null,
            ':user_rating'             => $episode['userRating']             ?? null,
            ':has_intro_marker'        => isset($episode['hasIntroMarker'])     ? (int) $episode['hasIntroMarker']     : 0,
            ':has_credits_marker'      => isset($episode['hasCreditsMarker'])   ? (int) $episode['hasCreditsMarker']   : 0,
            ':has_commercial_marker'   => isset($episode['hasCommercialMarker'])? (int) $episode['hasCommercialMarker']: 0,
            ':locations'               => isset($episode['locations'])  ? json_encode($episode['locations'])  : null,
            ':media_info'              => isset($episode['media'][0])   ? json_encode($episode['media'][0])   : null,
            ':parent_guid'             => $episode['parentGuid']             ?? null,
            ':parent_rating_key'       => $episode['parentRatingKey']        ?? null,
            ':grandparent_guid'        => $episode['grandparentGuid']        ?? null,
            ':grandparent_rating_key'  => $episode['grandparentRatingKey']   ?? null,
        ]);
    }
}
