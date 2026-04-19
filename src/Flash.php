<?php
// src/Flash.php

namespace App;

class Flash {
    private const SESSION_KEY = '_flash_messages';

    private static function ensureSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function add(string $type, string $message): void {
        self::ensureSession();
        $_SESSION[self::SESSION_KEY][] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    public static function consumeAll(): array {
        self::ensureSession();
        $messages = $_SESSION[self::SESSION_KEY] ?? [];
        unset($_SESSION[self::SESSION_KEY]);
        return is_array($messages) ? $messages : [];
    }
}