<?php
// src/Config.php

namespace App;

class Config {
    private static $loaded = false;
    private static $values = [];

    private static function load(): void {
        if (self::$loaded) {
            return;
        }

        $envPath = __DIR__ . '/../.env';
        if (file_exists($envPath) && is_readable($envPath)) {
            $parsed = parse_ini_file($envPath, false, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                self::$values = $parsed;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, $default = null) {
        self::load();
        return self::$values[$key] ?? $default;
    }
}
