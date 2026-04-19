<?php
// src/Settings.php
namespace App;

use PDO;

class Settings {
    public static function ensureTable(): void {
        DB::getConnection()->exec("CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )");
    }

    public static function get(string $key, ?string $default = null): ?string {
        self::ensureTable();

        $stmt = DB::getConnection()->prepare("SELECT value FROM app_settings WHERE key = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return $default;
        }

        return is_string($value) ? $value : (string) $value;
    }

    public static function set(string $key, ?string $value): void {
        self::ensureTable();

        $stmt = DB::getConnection()->prepare(
            "INSERT INTO app_settings (key, value) VALUES (?, ?)\n             ON CONFLICT(key) DO UPDATE SET value = excluded.value"
        );
        $stmt->execute([$key, $value]);
    }

    public static function setWithConnection(PDO $pdo, string $key, ?string $value): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )");

        $stmt = $pdo->prepare(
            "INSERT INTO app_settings (key, value) VALUES (?, ?)\n             ON CONFLICT(key) DO UPDATE SET value = excluded.value"
        );
        $stmt->execute([$key, $value]);
    }
}