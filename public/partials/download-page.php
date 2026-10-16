<?php
/**
 * Send - Gabarit de la page de téléchargement, partagé par la vraie page
 * et l'aperçu de l'admin
 *
 * Attend $page : title, files_visible, files [name, size, href], total_size,
 * zip_href (ou null), password (null ou [blocked, block_time, error]).
 */

declare(strict_types=1);

if (!defined('SEND_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

$fileCount = count($page['files']);
$wallpaperUrls = Branding::wallpaperUrls();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page['title'] ?: 'Téléchargement') ?> - Send</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/public/assets/style.css?v=<?= filemtime(ROOT_PATH . '/public/assets/style.css') ?>">
</head>
<body class="public-page<?= $wallpaperUrls !== [] ? ' has-wallpaper' : '' ?>">
    <div class="download-container">
        <div class="download-box">
            <div class="download-header">
                <h1><?= htmlspecialchars($page['title'] ?: 'Fichiers partagés') ?></h1>
                <?php if ($page['files_visible']): ?>
                    <p class="file-info">
                        <?= $fileCount ?> fichier<?= $fileCount > 1 ? 's' : '' ?>
                        &bull; <?= File::formatSize((int) $page['total_size']) ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($page['password'] !== null): ?>
                <div class="password-form">
                    <p>Ce partage est protégé par un mot de passe.</p>

                    <?php if ($page['password']['blocked']): ?>
                        <div class="alert alert-error">
                            Trop de tentatives. Réessayez dans <span id="countdown" data-seconds="<?= (int) $page['password']['block_time'] ?>"><?= (int) $page['password']['block_time'] ?></span> secondes.
                        </div>
                    <?php elseif ($page['password']['error']): ?>
                        <div class="alert alert-error"><?= htmlspecialchars($page['password']['error']) ?></div>
                    <?php endif; ?>

                    <form method="POST" <?= $page['password']['blocked'] ? 'style="display:none"' : '' ?>>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>">
                        <div class="form-group">
                            <input type="password" name="password" placeholder="Mot de passe" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Accéder aux fichiers</button>
                    </form>
                </div>
            <?php else: ?>
                <?php if ($page['zip_href'] !== null): ?>
                    <a href="<?= htmlspecialchars($page['zip_href']) ?>" class="btn btn-primary btn-block btn-download-all">
                        Tout télécharger (ZIP)
                    </a>
                <?php endif; ?>

                <ul class="file-list-public">
                    <?php foreach ($page['files'] as $file): ?>
                        <li>
                            <a href="<?= htmlspecialchars($file['href']) ?>" class="file-item">
                                <span class="file-name"><?= htmlspecialchars($file['name']) ?></span>
                                <span class="file-size"><?= File::formatSize($file['size']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($fileCount === 0): ?>
                    <p class="text-muted text-center">Aucun fichier disponible.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <footer class="public-footer">
            <p>Partagé via <strong>Send</strong></p>
        </footer>

        <?php require ROOT_PATH . '/public/partials/branding.php'; ?>
    </div>

    <?php if ($page['password'] !== null && $page['password']['blocked']): ?>
        <script src="<?= htmlspecialchars(BASE_URL) ?>/public/assets/countdown.js?v=<?= filemtime(ROOT_PATH . '/public/assets/countdown.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
