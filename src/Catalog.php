<?php
// src/Catalog.php
namespace App;

use Exception;
use App\CatalogImporter;

class Catalog {
    private const MOVIES_BASE_PATH_KEY = 'movies_base_path';
    private const SERIES_BASE_PATH_KEY = 'series_base_path';

    public static function importMovies($folderName) {
        return self::importFromFolder(
            (string) $folderName,
            self::MOVIES_BASE_PATH_KEY,
            'movies',
            'movie',
            'error_invalid_json_movies'
        );
    }

    public static function importSeries($folderName) {
        return self::importFromFolder(
            (string) $folderName,
            self::SERIES_BASE_PATH_KEY,
            'series',
            'series',
            'error_invalid_json_series'
        );
    }

    public static function getBasePathForType(string $type): ?string {
        return match ($type) {
            'movie', 'movies' => Settings::get(self::MOVIES_BASE_PATH_KEY),
            'series'          => Settings::get(self::SERIES_BASE_PATH_KEY),
            default           => null,
        };
    }

    private static function importFromFolder(
        string $folderName,
        string $settingsKey,
        string $tableName,
        string $logType,
        string $invalidJsonMessageKey
    ) {
        $folderInfo = CatalogSource::validateImportFolder($folderName);
        if (isset($folderInfo['error'])) {
            Logger::error(ucfirst($tableName) . ' import failed: invalid folder', [
                'folder_name' => $folderName,
                'error'       => $folderInfo['error'],
            ]);
            return $folderInfo['error'];
        }

        Logger::info(ucfirst($tableName) . ' import started', [
            'folder_name' => $folderInfo['folder_name'],
            'folder_path' => $folderInfo['folder_path'],
            'json_path'   => $folderInfo['json_path'],
        ]);

        $jsonContent = @file_get_contents($folderInfo['json_path']);
        if ($jsonContent === false) {
            Logger::error(ucfirst($tableName) . ' import failed: JSON unreadable', [
                'json_path' => $folderInfo['json_path'],
            ]);
            return getLangMessage('error_catalog_json_unreadable');
        }

        $data = json_decode($jsonContent, true);
        if (!is_array($data) || empty($data) || !isset($data[0]['title'])) {
            Logger::error(ucfirst($tableName) . ' import failed: invalid JSON structure', [
                'json_path' => $folderInfo['json_path'],
            ]);
            return getLangMessage($invalidJsonMessageKey);
        }

        $pdo = DB::getConnection();
        $pdo->beginTransaction();

        try {
            if ($tableName === 'movies') {
                $imported = CatalogImporter::importMovies($pdo, $data);
                Settings::setWithConnection($pdo, $settingsKey, $folderInfo['folder_path']);
                $pdo->commit();
                Logger::info('Movies import completed', [
                    'total_input' => count($data),
                    'imported'    => $imported,
                    'folder_path' => $folderInfo['folder_path'],
                ]);
                return true;
            }

            [$seriesImported, $seasonsImported, $episodesImported] = CatalogImporter::importSeries($pdo, $data);
            Settings::setWithConnection($pdo, $settingsKey, $folderInfo['folder_path']);
            $pdo->commit();
            Logger::info('Series import completed', [
                'total_input'       => count($data),
                'series_imported'   => $seriesImported,
                'seasons_imported'  => $seasonsImported,
                'episodes_imported' => $episodesImported,
                'folder_path'       => $folderInfo['folder_path'],
            ]);
            return true;

        } catch (Exception $e) {
            $pdo->rollBack();
            Logger::error(ucfirst($tableName) . ' import failed', [
                'type'        => $logType,
                'folder_path' => $folderInfo['folder_path'],
                'error'       => $e->getMessage(),
            ]);
            $messageKey = $tableName === 'movies' ? 'error_import_movies' : 'error_import_series';
            return getLangMessage($messageKey) . ' ' . $e->getMessage();
        }
    }
}
