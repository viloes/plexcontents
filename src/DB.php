<?php
// src/DB.php
namespace App;

use PDO;
use PDOException;

class DB {
    private static $pdo = null;

    private const REQUIRED_TABLES = ['users', 'app_settings'];

    private static function getDatabasePath(): string {
        return __DIR__ . '/../data/database.sqlite';
    }

    private static function ensureDatabaseReady(string $dbPath): void {
        if (!file_exists($dbPath) || (is_file($dbPath) && (int) @filesize($dbPath) === 0)) {
            self::initializeDatabaseFile($dbPath, 'missing_or_empty_file');
            return;
        }

        try {
            $probe = new PDO('sqlite:' . $dbPath);
            $probe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if (!self::hasRequiredSchema($probe)) {
                self::initializeDatabaseFile($dbPath, 'missing_required_schema');
            }
        } catch (PDOException $e) {
            Logger::error('Database probe failed before initialization', [
                'db_path' => $dbPath,
                'error' => $e->getMessage(),
            ]);
            throw new PDOException('No se pudo validar la base de datos SQLite: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function hasRequiredSchema(PDO $pdo): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?"
        );

        foreach (self::REQUIRED_TABLES as $tableName) {
            $stmt->execute([$tableName]);
            if ((int) $stmt->fetchColumn() === 0) {
                return false;
            }
        }

        return true;
    }

    private static function initializeDatabaseFile(string $dbPath, string $reason): void {
        $dataDir = dirname($dbPath);
        if (!is_dir($dataDir) && !@mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
            throw new PDOException('No se pudo crear el directorio de datos para SQLite.');
        }

        Logger::info('Initializing SQLite database automatically', [
            'db_path' => $dbPath,
            'reason' => $reason,
        ]);

        require_once __DIR__ . '/../init_db.php';

        if (!function_exists('initializeDatabase')) {
            throw new PDOException('La funcion initializeDatabase() no esta disponible.');
        }

        $result = \initializeDatabase($dbPath);
        if (($result['success'] ?? false) !== true) {
            Logger::error('Automatic SQLite initialization failed', [
                'db_path' => $dbPath,
                'reason' => $reason,
                'message' => $result['message'] ?? 'Unknown error',
            ]);
            throw new PDOException($result['message'] ?? 'Error desconocido al inicializar la base de datos.');
        }

        Logger::info('SQLite database initialized automatically', [
            'db_path' => $dbPath,
            'reason' => $reason,
        ]);
    }

    public static function getConnection() {
        if (self::$pdo === null) {
            $dbPath = self::getDatabasePath();
            Logger::debug('Initializing database connection', ['db_path' => $dbPath]);
            try {
                self::ensureDatabaseReady($dbPath);
                self::$pdo = new PDO("sqlite:" . $dbPath);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                // Por defecto, devolver resultados como arrays asociativos
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                // En SQLite, las FK no se aplican si no se activa este PRAGMA por conexión.
                self::$pdo->exec('PRAGMA foreign_keys = ON');
                Logger::info('Database connection established');
            } catch (PDOException $e) {
                Logger::error('Database connection error', ['error' => $e->getMessage()]);
                die("Error de conexión a la base de datos: " . $e->getMessage());
            }
        }
        return self::$pdo;
    }
}