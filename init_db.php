<?php
require_once __DIR__ . '/src/bootstrap.php';

function addColumnIfNotExists(PDO $pdo, string $table, string $column, string $definition): void {
    try {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    } catch (PDOException $e) {
        // SQLite lanza una excepción si la columna ya existe; la migración debe ser idempotente.
    }
}

function initializeDatabase(?string $dbPath = null): array {
    $dbPath = $dbPath ?? __DIR__ . '/data/database.sqlite';

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            is_admin INTEGER DEFAULT 0,
            language TEXT DEFAULT 'es'
        )");
        addColumnIfNotExists($pdo, 'users', 'language', "TEXT DEFAULT 'es'");

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $pdo->exec("INSERT INTO users (username, password, is_admin) VALUES ('admin', '$hash', 1)");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS movies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            guid TEXT,
            rating_key INTEGER,
            title TEXT NOT NULL,
            title_sort TEXT,
            original_title TEXT,
            year INTEGER,
            rating REAL,
            rating_image TEXT,
            audience_rating REAL,
            audience_rating_image TEXT,
            user_rating REAL,
            duration INTEGER,
            duration_human TEXT,
            added_at TEXT,
            originally_available_at TEXT,
            content_rating TEXT,
            edition_title TEXT,
            has_credits_marker INTEGER DEFAULT 0,
            studio TEXT,
            tagline TEXT,
            summary TEXT,
            thumb_file TEXT,
            locations TEXT,
            media_info TEXT
        )");

        addColumnIfNotExists($pdo, 'movies', 'guid', 'TEXT');
        addColumnIfNotExists($pdo, 'movies', 'rating_key', 'INTEGER');
        addColumnIfNotExists($pdo, 'movies', 'title_sort', 'TEXT');
        addColumnIfNotExists($pdo, 'movies', 'rating_image', 'TEXT');
        addColumnIfNotExists($pdo, 'movies', 'audience_rating', 'REAL');
        addColumnIfNotExists($pdo, 'movies', 'audience_rating_image', 'TEXT');
        addColumnIfNotExists($pdo, 'movies', 'duration', 'INTEGER');
        addColumnIfNotExists($pdo, 'movies', 'originally_available_at', 'TEXT');
        addColumnIfNotExists($pdo, 'movies', 'content_rating', 'TEXT');
        addColumnIfNotExists($pdo, 'movies', 'edition_title', 'TEXT');
        addColumnIfNotExists($pdo, 'movies', 'has_credits_marker', 'INTEGER DEFAULT 0');
        addColumnIfNotExists($pdo, 'movies', 'studio', 'TEXT');
        addColumnIfNotExists($pdo, 'movies', 'tagline', 'TEXT');
        addColumnIfNotExists($pdo, 'movies', 'locations', 'TEXT');
        addColumnIfNotExists($pdo, 'movies', 'media_info', 'TEXT');

        $pdo->exec("CREATE TABLE IF NOT EXISTS series (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            guid TEXT UNIQUE,
            rating_key INTEGER,
            title TEXT NOT NULL,
            title_sort TEXT,
            original_title TEXT,
            year INTEGER,
            rating REAL,
            audience_rating REAL,
            audience_rating_image TEXT,
            user_rating REAL,
            duration INTEGER,
            duration_human TEXT,
            added_at TEXT,
            originally_available_at TEXT,
            content_rating TEXT,
            child_count INTEGER,
            season_count INTEGER,
            network TEXT,
            studio TEXT,
            tagline TEXT,
            summary TEXT,
            thumb_file TEXT
        )");

        addColumnIfNotExists($pdo, 'series', 'rating_key', 'INTEGER');
        addColumnIfNotExists($pdo, 'series', 'title_sort', 'TEXT');
        addColumnIfNotExists($pdo, 'series', 'audience_rating', 'REAL');
        addColumnIfNotExists($pdo, 'series', 'audience_rating_image', 'TEXT');
        addColumnIfNotExists($pdo, 'series', 'duration', 'INTEGER');
        addColumnIfNotExists($pdo, 'series', 'originally_available_at', 'TEXT');
        addColumnIfNotExists($pdo, 'series', 'content_rating', 'TEXT');
        addColumnIfNotExists($pdo, 'series', 'child_count', 'INTEGER');
        addColumnIfNotExists($pdo, 'series', 'network', 'TEXT');
        addColumnIfNotExists($pdo, 'series', 'studio', 'TEXT');
        addColumnIfNotExists($pdo, 'series', 'tagline', 'TEXT');

        $pdo->exec("CREATE TABLE IF NOT EXISTS seasons (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            series_id INTEGER,
            season_number INTEGER,
            episode_count INTEGER DEFAULT 0,
            guid TEXT,
            rating_key INTEGER,
            parent_guid TEXT,
            title TEXT,
            title_sort TEXT,
            summary TEXT,
            thumb_file TEXT,
            added_at TEXT,
            user_rating REAL,
            year INTEGER,
            FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
        )");

        addColumnIfNotExists($pdo, 'seasons', 'guid', 'TEXT');
        addColumnIfNotExists($pdo, 'seasons', 'rating_key', 'INTEGER');
        addColumnIfNotExists($pdo, 'seasons', 'parent_guid', 'TEXT');
        addColumnIfNotExists($pdo, 'seasons', 'title', 'TEXT');
        addColumnIfNotExists($pdo, 'seasons', 'title_sort', 'TEXT');
        addColumnIfNotExists($pdo, 'seasons', 'summary', 'TEXT');
        addColumnIfNotExists($pdo, 'seasons', 'thumb_file', 'TEXT');
        addColumnIfNotExists($pdo, 'seasons', 'added_at', 'TEXT');
        addColumnIfNotExists($pdo, 'seasons', 'user_rating', 'REAL');
        addColumnIfNotExists($pdo, 'seasons', 'year', 'INTEGER');

        $pdo->exec("CREATE TABLE IF NOT EXISTS episodes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            season_id INTEGER,
            series_id INTEGER,
            guid TEXT UNIQUE,
            rating_key INTEGER,
            episode_number INTEGER,
            season_number INTEGER,
            season_episode TEXT,
            title TEXT,
            title_sort TEXT,
            summary TEXT,
            added_at TEXT,
            originally_available_at TEXT,
            year INTEGER,
            duration INTEGER,
            duration_human TEXT,
            rating REAL,
            audience_rating REAL,
            audience_rating_image TEXT,
            content_rating TEXT,
            user_rating REAL,
            has_intro_marker INTEGER DEFAULT 0,
            has_credits_marker INTEGER DEFAULT 0,
            has_commercial_marker INTEGER DEFAULT 0,
            locations TEXT,
            media_info TEXT,
            parent_guid TEXT,
            parent_rating_key INTEGER,
            grandparent_guid TEXT,
            grandparent_rating_key INTEGER,
            FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
            FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )");

        return [
            'success' => true,
            'message' => "Base de datos inicializada correctamente en: $dbPath",
            'db_path' => $dbPath,
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'db_path' => $dbPath,
        ];
    }
}

function resetDatabase(?string $dbPath = null): array {
    $dbPath = $dbPath ?? __DIR__ . '/data/database.sqlite';

    if (file_exists($dbPath) && !@unlink($dbPath)) {
        return [
            'success' => false,
            'message' => 'Error: no se pudo eliminar la base de datos actual.',
            'db_path' => $dbPath,
        ];
    }

    return initializeDatabase($dbPath);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $result = initializeDatabase();
    echo $result['message'] . PHP_EOL;
}