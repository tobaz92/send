<?php
/**
 * Send - Image de fond des pages de téléchargement (servie depuis storage/branding)
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

const WALLPAPER_CACHE_SECONDS = 2592000;

$wallpaper = Branding::wallpaperFile();
if ($wallpaper === null) {
    http_response_code(404);
    exit;
}

// L'URL change avec l'image (?v=), le navigateur peut la garder longtemps
header('Content-Type: ' . $wallpaper['mime']);
header('Content-Length: ' . filesize($wallpaper['path']));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=' . WALLPAPER_CACHE_SECONDS . ', immutable');
header_remove('Pragma');
header_remove('Expires');

readfile($wallpaper['path']);
