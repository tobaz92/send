<?php
/**
 * Send - Déconnexion admin
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// Vérifier que la requête est bien un POST avec CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Auth::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Requête invalide');
}

Auth::logout();
header('Location: ' . BASE_URL . '/admin/login');
exit;
