<?php
/**
 * Send - Page de téléchargement publique
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// Paramètre injecté par le router
$slug = $GLOBALS['url_params'][0] ?? '';
$share = Share::findBySlug($slug);

// Vérifier si le partage existe et est accessible
if (!$share || $share['status'] === 'deleted') {
    http_response_code(404);
    require ROOT_PATH . '/public/404.php';
    exit;
}

if ($share['status'] === 'paused') {
    http_response_code(410);
    require ROOT_PATH . '/public/paused.php';
    exit;
}

// Vérifier l'expiration
if (!empty($share['expires_at']) && strtotime($share['expires_at']) < time()) {
    http_response_code(410);
    require ROOT_PATH . '/public/404.php';
    exit;
}

// Enregistrer la vue (une fois par session)
$viewKey = 'viewed_' . $share['id'];
if (!isset($_SESSION[$viewKey])) {
    Database::insert('views', [
        'share_id' => $share['id'],
        'ip' => Auth::getClientIp()
    ]);
    $_SESSION[$viewKey] = true;
}

// Gestion du mot de passe
$passwordRequired = !empty($share['password']);
$passwordValid = false;
$passwordError = '';
$passwordBlocked = false;
$passwordBlockTime = 0;
$ip = Auth::getClientIp();

if ($passwordRequired) {
    // Vérifier si déjà validé dans la session
    $sessionKey = 'share_access_' . $share['id'];
    if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true) {
        $passwordValid = true;
    }

    // Check rate limiting
    if (!Security::checkSharePasswordRateLimit($ip, $share['id'])) {
        $passwordBlocked = true;
        $passwordBlockTime = Security::getSharePasswordBlockTime($ip, $share['id']);
    }

    // Traitement du formulaire de mot de passe
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$passwordValid && !$passwordBlocked) {
        // Verify CSRF token
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Auth::verifyCsrfToken($csrfToken)) {
            $passwordError = 'Session expirée, veuillez réessayer.';
        } else {
            $password = $_POST['password'] ?? '';

            if (Share::verifyPassword($share, $password)) {
                $_SESSION[$sessionKey] = true;
                $passwordValid = true;
            } else {
                // Log failed attempt for rate limiting
                Security::logSharePasswordAttempt($ip, $share['id']);

                // Check if now blocked
                if (!Security::checkSharePasswordRateLimit($ip, $share['id'])) {
                    $passwordBlocked = true;
                    $passwordBlockTime = Security::getSharePasswordBlockTime($ip, $share['id']);
                } else {
                    $passwordError = 'Mot de passe incorrect.';
                }
            }
        }
    }
}

$filesVisible = !$passwordRequired || $passwordValid;
$shareBase = BASE_URL . '/d/' . rawurlencode($slug);
$files = [];
if ($filesVisible) {
    foreach (File::getByShareId($share['id']) as $file) {
        $files[] = [
            'name' => $file['original_name'],
            'size' => (int) $file['size'],
            'href' => $shareBase . '/file/' . (int) $file['id'],
        ];
    }
}

$page = [
    'title' => $share['title'],
    'files_visible' => $filesVisible,
    'files' => $files,
    'total_size' => File::getTotalSize($share['id']),
    'zip_href' => count($files) > 1 ? $shareBase . '/zip' : null,
    'password' => $filesVisible ? null : [
        'blocked' => $passwordBlocked,
        'block_time' => (int) $passwordBlockTime,
        'error' => $passwordError,
    ],
];

require ROOT_PATH . '/public/partials/download-page.php';
