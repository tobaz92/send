<?php
/**
 * Send - Router principal
 */

declare(strict_types=1);

// Pour le serveur PHP intégré : servir les fichiers statiques directement
if (PHP_SAPI === 'cli-server') {
    $uri = $_SERVER['REQUEST_URI'];
    $file = __DIR__ . parse_url($uri, PHP_URL_PATH);

    // Servir les assets statiques directement
    if (preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2)$/', $uri) && file_exists($file)) {
        return false;
    }
}

// Initialisation (config, autoloader, DB, session, headers)
require_once __DIR__ . '/src/bootstrap.php';

// Nettoyage périodique des logs (1% des requêtes)
if (random_int(1, 100) === 1) {
    Security::cleanup();
}

// Parser l'URL
$requestUri = $_SERVER['REQUEST_URI'];
$basePath = parse_url(BASE_URL, PHP_URL_PATH) ?: '';
$path = str_replace($basePath, '', parse_url($requestUri, PHP_URL_PATH) ?: '/');
$path = '/' . trim($path, '/');

// Router
$routes = [
    // Racine → redirection vers l'admin
    'GET /' => ['_redirect_admin'],

    // Admin
    'GET /admin' => ['admin/index.php', 'auth'],
    'GET /admin/login' => ['admin/login.php'],
    'POST /admin/login' => ['admin/login.php'],
    'POST /admin/logout' => ['admin/logout.php', 'auth'],
    'GET /admin/upload' => ['admin/upload.php', 'auth'],
    'POST /admin/upload' => ['admin/upload.php', 'auth'],
    'GET /admin/shares' => ['admin/shares.php', 'auth'],
    'GET /admin/share/([a-zA-Z0-9_-]+)' => ['admin/share-edit.php', 'auth'],
    'POST /admin/share/([a-zA-Z0-9_-]+)' => ['admin/share-edit.php', 'auth'],
    'POST /admin/share/([a-zA-Z0-9_-]+)/delete' => ['admin/share-delete.php', 'auth'],
    'POST /admin/share/([a-zA-Z0-9_-]+)/toggle' => ['admin/share-toggle.php', 'auth'],

    // Public
    'GET /d/([a-zA-Z0-9_-]+)' => ['public/download.php'],
    'POST /d/([a-zA-Z0-9_-]+)' => ['public/download.php'],
    'GET /d/([a-zA-Z0-9_-]+)/file/([0-9]+)' => ['public/file.php'],
    'GET /d/([a-zA-Z0-9_-]+)/zip' => ['public/zip.php'],
];

$method = $_SERVER['REQUEST_METHOD'];
$matched = false;

foreach ($routes as $route => $config) {
    [$routeMethod, $routePath] = explode(' ', $route, 2);

    if ($method !== $routeMethod) {
        continue;
    }

    $pattern = '#^' . $routePath . '$#';

    if (preg_match($pattern, $path, $matches)) {
        $file = $config[0];

        // Redirection interne
        if ($file === '_redirect_admin') {
            header('Location: ' . BASE_URL . '/admin');
            exit;
        }

        $requireAuth = isset($config[1]) && $config[1] === 'auth';

        // Vérifier l'authentification si requise
        if ($requireAuth && !Auth::check()) {
            header('Location: ' . BASE_URL . '/admin/login');
            exit;
        }

        // Extraire les paramètres de l'URL
        array_shift($matches);
        $GLOBALS['url_params'] = $matches;

        // Charger le fichier
        $filePath = ROOT_PATH . '/' . $file;
        if (file_exists($filePath)) {
            require_once $filePath;
            $matched = true;
            break;
        }
    }
}

// 404 si aucune route trouvée
if (!$matched) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404 - Page non trouvée</title></head>';
    echo '<body style="font-family: system-ui; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0;">';
    echo '<div style="text-align: center;"><h1 style="font-size: 72px; margin: 0;">404</h1><p>Page non trouvée</p></div>';
    echo '</body></html>';
}
