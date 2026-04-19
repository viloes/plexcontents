<?php
// src/Logger.php
namespace App;

class Logger {
    private const LEVEL_DEBUG = 10;
    private const LEVEL_INFO = 20;
    private const LEVEL_ERROR = 30;

    private static $initialized = false;
    private static $activeLevel = self::LEVEL_DEBUG;
    private static $logPath = '/storage/log/plexcontents.log';
    private static $maxSizeBytes = 10485760;
    private static $maxFiles = 5;

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
            self::$logPath = $logPath;
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
            return 10485760;
        }
        return $numeric * 1024 * 1024;
    }

    private static function parseMaxFiles($maxFiles): int {
        $numeric = (int) $maxFiles;
        if ($numeric < 1) {
            return 5;
        }
        return $numeric;
    }

    private static function rotateIfNeeded(): void {
        if (!file_exists(self::$logPath)) {
            return;
        }

        $currentSize = @filesize(self::$logPath);
        if ($currentSize === false || $currentSize < self::$maxSizeBytes) {
            return;
        }

        $oldest = self::$logPath . '.' . self::$maxFiles;
        if (file_exists($oldest)) {
            @unlink($oldest);
        }

        for ($i = self::$maxFiles - 1; $i >= 1; $i--) {
            $source = self::$logPath . '.' . $i;
            $target = self::$logPath . '.' . ($i + 1);
            if (file_exists($source)) {
                @rename($source, $target);
            }
        }

        @rename(self::$logPath, self::$logPath . '.1');
    }

    private static function write(string $levelName, int $level, string $message, array $context = []): void {
        if (!self::shouldLog($level)) {
            return;
        }

        $dir = dirname(self::$logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
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
        @file_put_contents(self::$logPath, $line, FILE_APPEND | LOCK_EX);
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
