<?php
/**
 * Send - Toggle statut partage
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// Vérifier l'authentification
if (!Auth::check()) {
    header('Location: ' . BASE_URL . '/admin/login');
    exit;
}

$slug = $GLOBALS['url_params'][0] ?? '';
$share = Share::findBySlug($slug);

if (!$share || $share['status'] === 'deleted') {
    http_response_code(404);
    exit('Partage non trouvé');
}

// Vérifier CSRF
if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Session expirée');
}

Share::toggleStatus($share['id']);

// Log
Database::insert('audit_logs', [
    'action' => 'toggle_share',
    'ip' => Auth::getClientIp(),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'details' => json_encode(['share_id' => $share['id']])
]);

header('Location: ' . BASE_URL . '/admin/share/' . $slug);
exit;
