<?php
// src/CatalogSource.php
namespace App;

class CatalogSource {
    private const DEFAULT_EXPORT_ROOT = '/var/www/html/tautulli-exports';

    public static function getRootPath(): string {
        $configuredRoot = Config::get('EXPORT_ROOT', self::DEFAULT_EXPORT_ROOT);
        if (!is_string($configuredRoot)) {
            return self::DEFAULT_EXPORT_ROOT;
        }

        $configuredRoot = trim($configuredRoot);
        return $configuredRoot !== '' ? $configuredRoot : self::DEFAULT_EXPORT_ROOT;
    }

    public static function listAvailableFolders(): array {
        $root = self::getRootPath();
        if (!is_dir($root) || !is_readable($root)) {
            return [];
        }

        $folders = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $folders[] = [
                    'name' => $entry,
                    'path' => $path,
                ];
            }
        }

        usort($folders, static function (array $left, array $right): int {
            return strnatcasecmp($left['name'], $right['name']);
        });

        return $folders;
    }

    public static function validateImportFolder(string $folderName): array {
        $folderName = trim($folderName);
        if ($folderName === '') {
            return ['error' => getLangMessage('error_catalog_folder_required')];
        }

        if (
            $folderName !== basename($folderName)
            || str_contains($folderName, '/')
            || str_contains($folderName, '\\')
        ) {
            return ['error' => getLangMessage('error_catalog_folder_invalid')];
        }

        $root = realpath(self::getRootPath());
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            return ['error' => getLangMessage('error_catalog_root_unavailable')];
        }

        $folderPath = $root . DIRECTORY_SEPARATOR . $folderName;
        $realFolderPath = realpath($folderPath);
        if ($realFolderPath === false || !is_dir($realFolderPath) || !is_readable($realFolderPath)) {
            return ['error' => getLangMessage('error_catalog_folder_invalid')];
        }

        if (!self::isPathInsideRoot($realFolderPath, $root)) {
            return ['error' => getLangMessage('error_catalog_folder_invalid')];
        }

        $jsonFiles = [];
        $hasSubdirectory = false;
        foreach (scandir($realFolderPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $realFolderPath . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($entryPath)) {
                $hasSubdirectory = true;
                continue;
            }

            if (is_file($entryPath) && strcasecmp(pathinfo($entry, PATHINFO_EXTENSION), 'json') === 0) {
                $jsonFiles[] = $entryPath;
            }
        }

        if (count($jsonFiles) === 0) {
            return ['error' => getLangMessage('error_catalog_json_missing')];
        }

        if (count($jsonFiles) > 1) {
            return ['error' => getLangMessage('error_catalog_json_multiple')];
        }

        if (!$hasSubdirectory) {
            return ['error' => getLangMessage('error_catalog_subdirectories_missing')];
        }

        return [
            'folder_name' => $folderName,
            'folder_path' => $realFolderPath,
            'json_path' => $jsonFiles[0],
        ];
    }

    public static function resolveMediaPath(string $basePath, string $relativePath): ?string {
        $realBasePath = realpath($basePath);
        if ($realBasePath === false || !is_dir($realBasePath)) {
            return null;
        }

        $root = realpath(self::getRootPath());
        if ($root === false || !self::isPathInsideRoot($realBasePath, $root)) {
            return null;
        }

        $relativePath = trim($relativePath);
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }

        $normalizedPath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($normalizedPath === '') {
            return null;
        }

        $candidatePath = realpath($realBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath));
        if ($candidatePath === false || !is_file($candidatePath)) {
            return null;
        }

        if (!self::isPathInsideRoot($candidatePath, $realBasePath)) {
            return null;
        }

        return $candidatePath;
    }

    private static function isPathInsideRoot(string $path, string $root): bool {
        $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $normalizedRoot);
    }
}