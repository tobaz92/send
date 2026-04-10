<?php
/**
 * Send - Téléchargement d'un fichier individuel
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// Paramètres injectés par le router
$slug = $GLOBALS['url_params'][0] ?? '';
$fileId = (int)($GLOBALS['url_params'][1] ?? 0);

$ip = Auth::getClientIp();

// Rate limiting
if (!Security::canDownload($ip)) {
    http_response_code(429);
    exit('Trop de téléchargements. Réessayez plus tard.');
}

$share = Share::findBySlug($slug);

// Vérifications
if (!$share || $share['status'] !== 'active') {
    http_response_code(404);
    exit('Fichier non trouvé.');
}

// Vérifier l'expiration
if (!empty($share['expires_at']) && strtotime($share['expires_at']) < time()) {
    http_response_code(410);
    exit('Ce partage a expiré.');
}

$file = File::findById($fileId);

if (!$file || $file['share_id'] !== $share['id']) {
    http_response_code(404);
    exit('Fichier non trouvé.');
}

// Vérifier le mot de passe si requis
if (!empty($share['password'])) {
    $sessionKey = 'share_access_' . $share['id'];
    if (!isset($_SESSION[$sessionKey]) || $_SESSION[$sessionKey] !== true) {
        header('Location: ' . BASE_URL . '/d/' . $slug);
        exit;
    }
}

$filePath = File::getPath($file['stored_name']);

if (!file_exists($filePath)) {
    http_response_code(404);
    exit('Fichier non trouvé sur le serveur.');
}

// Enregistrer le téléchargement
Database::insert('downloads', [
    'share_id' => $share['id'],
    'file_id' => $file['id'],
    'ip' => $ip,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
]);

// Log pour rate limiting
Security::logAction('download', $share['id'], $file['id']);

// Envoyer la notification email
Mailer::notifyDownload($share, $file);

// Headers sécurisés pour le téléchargement (RFC 5987)
$safeName = preg_replace('/[^\p{L}\p{N}\s\-_\.]/u', '', $file['original_name']);
$asciiName = preg_replace('/[^\x20-\x7E]/', '_', $safeName);

header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"{$asciiName}\"; filename*=UTF-8''" . rawurlencode($safeName));
header('Content-Length: ' . filesize($filePath));
header('Content-Transfer-Encoding: binary');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Désactiver la mise en buffer pour les gros fichiers
if (ob_get_level()) {
    ob_end_clean();
}

// Stream le fichier
readfile($filePath);
exit;
