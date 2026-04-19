<?php
// src/bootstrap.php

$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
    return;
}

spl_autoload_register(function ($className) {
    $prefix = 'App\\';
    if (strncmp($className, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($className, strlen($prefix));
    $classFile = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($classFile)) {
        require_once $classFile;
    }
});

// Funciones para internacionalización (i18n)

/**
 * Obtiene los idiomas disponibles escaneando la carpeta lang/
 * @return array Códigos de idioma disponibles (ej: ['es', 'en', 'fr'])
 */
function getAvailableLanguages(): array {
    $langDir = __DIR__ . '/../lang';
    if (!is_dir($langDir)) {
        return ['es'];
    }
    
    $languages = [];
    foreach (scandir($langDir) as $file) {
        if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            $languages[] = pathinfo($file, PATHINFO_FILENAME);
        }
    }
    
    sort($languages);
    return !empty($languages) ? $languages : ['es'];
}

/**
 * Carga un archivo de idioma específico
 * @param string $languageCode El código del idioma (ej: 'es', 'en')
 * @return array Array asociativo con las traducciones
 */
function loadLanguage(string $languageCode): array {
    $langFile = __DIR__ . '/../lang/' . basename($languageCode) . '.php';
    
    if (!file_exists($langFile)) {
        // Fallback a español si el idioma no existe
        $langFile = __DIR__ . '/../lang/es.php';
    }
    
    return require $langFile;
}

/**
 * Obtiene el idioma actual del usuario (desde sesión, BD o por defecto)
 * @param \PDO|null $pdo Conexión a BD para obtener idioma del usuario logueado
 * @return string Código del idioma actual
 */
function getCurrentLanguage($pdo = null): string {
    // Prioridad 1: Idioma en sesión
    if (isset($_SESSION['language'])) {
        return $_SESSION['language'];
    }
    
    // Prioridad 2: Idioma del usuario en BD si está logueado
    if (!empty($_SESSION['user_id']) && $pdo) {
        try {
            $stmt = $pdo->prepare("SELECT language FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($result && $result['language']) {
                $_SESSION['language'] = $result['language'];
                return $result['language'];
            }
        } catch (\Exception $e) {
            // Si hay error, usar idioma por defecto
        }
    }
    
    // Prioridad 3: Idioma por defecto
    return 'es';
}

/**
 * Obtiene un mensaje traducido por su clave
 * @param string $key La clave del mensaje
 * @param string|null $default Valor por defecto si la clave no existe
 * @return string El mensaje traducido o el default
 */
function getLangMessage(string $key, ?string $default = null): string {
    static $lang = null;
    
    if ($lang === null) {
        $currentLang = getCurrentLanguage();
        $lang = loadLanguage($currentLang);
    }
    
    return $lang[$key] ?? $default ?? $key;
}