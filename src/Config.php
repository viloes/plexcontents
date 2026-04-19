<?php
// src/Config.php

namespace App;

class Config {
    private static $loaded = false;
    private static $values = [];

    private static function getEnvironmentValue(string $key, bool &$found) {
        $value = getenv($key);
        if ($value !== false) {
            $found = true;
            return $value;
        }

        if (array_key_exists($key, $_ENV)) {
            $found = true;
            return $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            $found = true;
            return $_SERVER[$key];
        }

        $found = false;
        return null;
    }

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

        $found = false;
        $value = self::getEnvironmentValue($key, $found);
        if ($found) {
            return $value;
        }

        return self::$values[$key] ?? $default;
    }
}
