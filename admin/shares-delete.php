<?php
/**
 * Send - Suppression de plusieurs partages (cases cochées dans la liste)
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

const BULK_DELETE_MAX = 500;

if (!Auth::check()) {
    header('Location: ' . BASE_URL . '/admin/login');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Auth::verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    header('Location: ' . BASE_URL . '/admin/shares?error=session');
    exit;
}

$rawIds = $_POST['share_ids'] ?? [];
$ids = is_array($rawIds)
    ? array_values(array_unique(array_map('intval', array_filter($rawIds, fn($id) => is_string($id) && ctype_digit($id)))))
    : [];
if ($ids === [] || count($ids) > BULK_DELETE_MAX) {
    header('Location: ' . BASE_URL . '/admin/shares?error=selection');
    exit;
}

$deleted = 0;
foreach ($ids as $id) {
    $share = Share::findById($id);
    if ($share === null) {
        continue;
    }
    Database::insert('audit_logs', [
        'action' => 'delete_share',
        'ip' => Auth::getClientIp(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'details' => json_encode(['share_id' => $share['id'], 'slug' => $share['slug'], 'bulk' => true]),
    ]);
    Share::hardDelete($share['id']);
    $deleted++;
}

header('Location: ' . BASE_URL . '/admin/shares?deleted=' . $deleted);
exit;
