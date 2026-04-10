<?php
/**
 * Send - Bootstrap
 * Fichier d'initialisation commun
 */

declare(strict_types=1);

// Éviter double initialisation
if (defined('SEND_BOOTSTRAPPED')) {
    return;
}
define('SEND_BOOTSTRAPPED', true);

// Charger la configuration
require_once dirname(__DIR__) . '/config.php';

// Autoloader simple
spl_autoload_register(function ($class) {
    $file = ROOT_PATH . '/src/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Initialiser la base de données
Database::init();

// Configuration des erreurs
if (DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// Headers de sécurité
if (!headers_sent()) {
    header_remove('X-Powered-By');
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

    // HSTS en production (HTTPS)
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    }
}

// Session sécurisée
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.name', SESSION_NAME);
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }

    session_start();

    // Cache-Control pour les pages admin
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (str_contains($requestPath, '/admin')) {
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Pragma: no-cache");
    }
}
