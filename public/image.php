<?php
// public/image.php
require_once __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Catalog;
use App\CatalogSource;
use App\Logger;

Auth::requireLogin();

$type = trim((string) ($_GET['type'] ?? ''));
$relativeFile = trim((string) ($_GET['file'] ?? ''));
$basePath = Catalog::getBasePathForType($type);

if ($basePath === null || $relativeFile === '') {
    Logger::error('Image request rejected: missing configuration', [
        'type' => $type,
        'file' => $relativeFile,
        'user_id' => $_SESSION['user_id'] ?? null,
    ]);
    http_response_code(404);
    exit;
}

$resolvedPath = CatalogSource::resolveMediaPath($basePath, $relativeFile);
if ($resolvedPath === null) {
    Logger::debug('Image request could not be resolved', [
        'type' => $type,
        'file' => $relativeFile,
        'base_path' => $basePath,
        'user_id' => $_SESSION['user_id'] ?? null,
    ]);
    http_response_code(404);
    exit;
}

$mimeType = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detected = finfo_file($finfo, $resolvedPath);
        if (is_string($detected) && $detected !== '') {
            $mimeType = $detected;
        }
        finfo_close($finfo);
    }
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($resolvedPath));
header('Cache-Control: private, max-age=300');
readfile($resolvedPath);
exit;