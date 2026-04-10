<?php
/**
 * Send - Téléchargement ZIP de tous les fichiers
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// Paramètre injecté par le router
$slug = $GLOBALS['url_params'][0] ?? '';

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
    exit('Partage non trouvé.');
}

// Vérifier l'expiration
if (!empty($share['expires_at']) && strtotime($share['expires_at']) < time()) {
    http_response_code(410);
    exit('Ce partage a expiré.');
}

// Vérifier le mot de passe si requis
if (!empty($share['password'])) {
    $sessionKey = 'share_access_' . $share['id'];
    if (!isset($_SESSION[$sessionKey]) || $_SESSION[$sessionKey] !== true) {
        header('Location: ' . BASE_URL . '/d/' . $slug);
        exit;
    }
}

$files = File::getByShareId($share['id']);

if (empty($files)) {
    http_response_code(404);
    exit('Aucun fichier à télécharger.');
}

// Enregistrer le téléchargement
Database::insert('downloads', [
    'share_id' => $share['id'],
    'file_id' => null, // null = ZIP complet
    'ip' => $ip,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
]);

// Log pour rate limiting
Security::logAction('download', $share['id'], null);

// Envoyer la notification email
Mailer::notifyDownload($share, null);

// Créer le ZIP à la volée
$zipName = ($share['title'] ?: $share['slug']) . '.zip';
$safeName = preg_replace('/[^\p{L}\p{N}\s\-_\.]/u', '', $zipName);
$asciiName = preg_replace('/[^\x20-\x7E]/', '_', $safeName);

header('Content-Type: application/zip');
header("Content-Disposition: attachment; filename=\"{$asciiName}\"; filename*=UTF-8''" . rawurlencode($safeName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Désactiver la mise en buffer
if (ob_get_level()) {
    ob_end_clean();
}

// Créer le ZIP
$zip = new ZipArchive();
$tempFile = tempnam(sys_get_temp_dir(), 'send_zip_');

if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Erreur lors de la création du ZIP.');
}

foreach ($files as $file) {
    $filePath = File::getPath($file['stored_name']);

    if (file_exists($filePath)) {
        $zip->addFile($filePath, basename($file['original_name']));
    }
}

$zip->close();

// Envoyer le fichier
header('Content-Length: ' . filesize($tempFile));
readfile($tempFile);

// Supprimer le fichier temporaire
unlink($tempFile);
exit;
