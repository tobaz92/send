<?php
/**
 * Send - Configuration
 *
 * Copiez ce fichier en config.php et adaptez les valeurs.
 * cp config.example.php config.php
 */

// Détection de l'environnement
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isProduction = ($host === 'send.example.com');

// Mode debug (false en production)
define('DEBUG', !$isProduction);

// URL de base - Détection automatique
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
define('BASE_URL', $protocol . '://' . $host);

// Clé secrète pour tokens (générer une clé unique)
// php -r "echo bin2hex(random_bytes(32));"
define('SECRET_KEY', 'CHANGEZ_MOI_avec_une_cle_unique');

// Admin credentials — CHANGEZ le username et le mot de passe !
define('ADMIN_USERNAME', 'CHANGEZ_MOI');
// Générer un hash : php -r "echo password_hash('votre_mot_de_passe', PASSWORD_ARGON2ID);"
define('ADMIN_PASSWORD_HASH', '$argon2id$v=19$m=65536,t=4,p=1$XXXXXXXXXXXXXXXX$XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');

// Email notifications
define('ADMIN_EMAIL', 'vous@example.com');
if ($isProduction) {
    define('EMAIL_FROM', 'noreply@send.example.com');
} else {
    define('EMAIL_FROM', 'noreply@localhost');
}
define('EMAIL_FROM_NAME', 'Send');

// Chemins
define('ROOT_PATH', __DIR__);
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('FILES_PATH', STORAGE_PATH . '/files');
define('DATABASE_PATH', STORAGE_PATH . '/database.sqlite');

// Upload - Extensions autorisées
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'txt', 'csv', 'mp4', 'mov', 'mp3']);
define('MAX_FILE_SIZE', 0); // 0 = pas de limite (utilise la limite serveur)

// Rate limiting
define('RATE_LIMIT_PASSWORD_ATTEMPTS', 5);
define('RATE_LIMIT_PASSWORD_WINDOW', 900); // 15 minutes
define('RATE_LIMIT_DOWNLOADS', 50);
define('RATE_LIMIT_DOWNLOADS_WINDOW', 3600); // 1 heure

// Session
define('SESSION_NAME', 'send_session');
define('SESSION_LIFETIME', 86400); // 24 heures

// Proxy configuration
// Derrière Cloudflare : 'cloudflare'
// Derrière un reverse proxy : 'proxy'
// Accès direct : 'none'
define('TRUSTED_PROXY', $isProduction ? 'cloudflare' : 'none');
