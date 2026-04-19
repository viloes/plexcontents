<?php
// src/DB.php
namespace App;

use PDO;
use PDOException;

class DB {
    private static $pdo = null;

    public static function getConnection() {
        if (self::$pdo === null) {
            $dbPath = __DIR__ . '/../data/database.sqlite';
            Logger::debug('Initializing database connection', ['db_path' => $dbPath]);
            try {
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