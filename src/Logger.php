<?php
// src/Logger.php
namespace App;

class Logger {
    private const LEVEL_DEBUG = 10;
    private const LEVEL_INFO = 20;
    private const LEVEL_ERROR = 30;
    private const DEFAULT_MAX_SIZE_BYTES = 10485760;
    private const DEFAULT_MAX_FILES = 5;

    private static $initialized = false;
    private static $activeLevel = self::LEVEL_DEBUG;
    private static $logPath = '';
    private static $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES;
    private static $maxFiles = self::DEFAULT_MAX_FILES;

    private static function getDefaultLogPath(): string {
        return __DIR__ . '/../storage/log/plexcontents.log';
    }

    private static function callWithoutWarning(callable $callback, ?string &$warning = null) {
        $warning = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    private static function reportInternalError(string $message, array $context = []): void {
        $line = '[plexcontents-logger] ' . $message;

        if (!empty($context)) {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $line .= ' ' . $encoded;
            }
        }

        error_log($line);
    }

    private static function init(): void {
        if (self::$initialized) {
            return;
        }

        $logLevel = Config::get('LOG_LEVEL');
        if (!empty($logLevel)) {
            self::$activeLevel = self::parseLevel($logLevel);
        }

        $logPath = Config::get('LOG_PATH');
        if (!empty($logPath)) {
            self::$logPath = trim((string) $logPath);
        }

        if (self::$logPath === '') {
            self::$logPath = self::getDefaultLogPath();
            self::reportInternalError('LOG_PATH is empty, using default path', [
                'default_path' => self::$logPath,
            ]);
        }

        $maxSizeMb = Config::get('LOG_MAX_SIZE_MB');
        if (!empty($maxSizeMb)) {
            self::$maxSizeBytes = self::parseMaxSize($maxSizeMb);
        }

        $maxFiles = Config::get('LOG_MAX_FILES');
        if (!empty($maxFiles)) {
            self::$maxFiles = self::parseMaxFiles($maxFiles);
        }

        self::$initialized = true;
    }

    private static function parseLevel(string $level): int {
        $normalized = strtoupper(trim($level));
        if ($normalized === 'ERROR') {
            return self::LEVEL_ERROR;
        }
        if ($normalized === 'INFO') {
            return self::LEVEL_INFO;
        }
        return self::LEVEL_DEBUG;
    }

    private static function shouldLog(int $messageLevel): bool {
        self::init();
        return $messageLevel >= self::$activeLevel;
    }

    private static function parseMaxSize($sizeMb): int {
        $numeric = (int) $sizeMb;
        if ($numeric < 1) {
            return self::DEFAULT_MAX_SIZE_BYTES;
        }
        return $numeric * 1024 * 1024;
    }

    private static function parseMaxFiles($maxFiles): int {
        $numeric = (int) $maxFiles;
        if ($numeric < 1) {
            return self::DEFAULT_MAX_FILES;
        }
        return $numeric;
    }

    private static function canUseLogPath(string $path): bool {
        $dir = dirname($path);
        if ($dir === '' || $dir === '.') {
            self::reportInternalError('Resolved log directory is invalid', [
                'log_path' => $path,
            ]);
            return false;
        }

        if (!is_dir($dir)) {
            $warning = null;
            $created = self::callWithoutWarning(static function () use ($dir) {
                return mkdir($dir, 0775, true);
            }, $warning);

            if (!$created && !is_dir($dir)) {
                self::reportInternalError('Failed to create log directory', [
                    'directory' => $dir,
                    'log_path' => $path,
                    'warning' => $warning,
                ]);
                return false;
            }
        }

        if (!is_writable($dir)) {
            self::reportInternalError('Log directory is not writable', [
                'directory' => $dir,
                'log_path' => $path,
            ]);
            return false;
        }

        return true;
    }

    private static function activateFallbackLogPath(string $failedPath): bool {
        $fallbackPath = self::getDefaultLogPath();
        if ($fallbackPath === $failedPath) {
            return false;
        }

        if (!self::canUseLogPath($fallbackPath)) {
            return false;
        }

        self::reportInternalError('Primary log path unavailable, using fallback path', [
            'failed_path' => $failedPath,
            'fallback_path' => $fallbackPath,
        ]);
        self::$logPath = $fallbackPath;
        return true;
    }

    private static function rotateIfNeeded(): void {
        if (!file_exists(self::$logPath)) {
            return;
        }

        $currentSize = filesize(self::$logPath);
        if ($currentSize === false || $currentSize < self::$maxSizeBytes) {
            return;
        }

        $oldest = self::$logPath . '.' . self::$maxFiles;
        if (file_exists($oldest)) {
            if (!unlink($oldest)) {
                self::reportInternalError('Failed to delete oldest rotated log', [
                    'path' => $oldest,
                ]);
            }
        }

        for ($i = self::$maxFiles - 1; $i >= 1; $i--) {
            $source = self::$logPath . '.' . $i;
            $target = self::$logPath . '.' . ($i + 1);
            if (file_exists($source)) {
                if (!rename($source, $target)) {
                    self::reportInternalError('Failed to rotate log file', [
                        'source' => $source,
                        'target' => $target,
                    ]);
                }
            }
        }

        if (!rename(self::$logPath, self::$logPath . '.1')) {
            self::reportInternalError('Failed to move active log during rotation', [
                'source' => self::$logPath,
                'target' => self::$logPath . '.1',
            ]);
        }
    }

    private static function ensureLogDirectory(): bool {
        if (self::canUseLogPath(self::$logPath)) {
            return true;
        }

        return self::activateFallbackLogPath(self::$logPath);
    }

    private static function write(string $levelName, int $level, string $message, array $context = []): void {
        if (!self::shouldLog($level)) {
            return;
        }

        if (!self::ensureLogDirectory()) {
            return;
        }

        self::rotateIfNeeded();

        $timestamp = date('Y-m-d H:i:s');
        $line = sprintf('[%s] %s %s', $timestamp, $levelName, $message);

        if (!empty($context)) {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $line .= ' ' . $encoded;
            }
        }

        $line .= PHP_EOL;
        $warning = null;
        $result = self::callWithoutWarning(static function () use ($line) {
            return file_put_contents(self::$logPath, $line, FILE_APPEND | LOCK_EX);
        }, $warning);
        if ($result === false) {
            self::reportInternalError('Failed to write log entry', [
                'log_path' => self::$logPath,
                'level' => $levelName,
                'warning' => $warning,
            ]);

            if (self::activateFallbackLogPath(self::$logPath)) {
                $retryWarning = null;
                $retryResult = self::callWithoutWarning(static function () use ($line) {
                    return file_put_contents(self::$logPath, $line, FILE_APPEND | LOCK_EX);
                }, $retryWarning);

                if ($retryResult === false) {
                    self::reportInternalError('Failed to write log entry on fallback path', [
                        'log_path' => self::$logPath,
                        'level' => $levelName,
                        'warning' => $retryWarning,
                    ]);
                }
            }
        }
    }

    public static function debug(string $message, array $context = []): void {
        self::write('DEBUG', self::LEVEL_DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void {
        self::write('INFO', self::LEVEL_INFO, $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::write('ERROR', self::LEVEL_ERROR, $message, $context);
    }
}
