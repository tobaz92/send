<?php
/**
 * Send - Aperçu d'une page de téléchargement avec la personnalisation enregistrée,
 * sur un faux partage (affiché dans un cadre de la page Personnalisation)
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (!Auth::check()) {
    header('Location: ' . BASE_URL . '/admin/login');
    exit;
}

// Seule page encadrable, et uniquement par l'admin du même site
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'self'");

const PREVIEW_FILES = [
    ['name' => 'Photos-mariage-selection.zip', 'size' => 734003200],
    ['name' => 'Film-du-jour-J.mp4', 'size' => 1288490188],
    ['name' => 'Notes-et-remerciements.pdf', 'size' => 2411724],
];

$files = array_map(fn(array $file) => $file + ['href' => '#'], PREVIEW_FILES);

$page = [
    'title' => 'Exemple de partage',
    'files_visible' => true,
    'files' => $files,
    'total_size' => array_sum(array_column(PREVIEW_FILES, 'size')),
    'zip_href' => '#',
    'password' => null,
];

require ROOT_PATH . '/public/partials/download-page.php';
