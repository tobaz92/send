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

$files = [];
if (!$passwordRequired || $passwordValid) {
    $files = File::getByShareId($share['id']);
}

$totalSize = File::getTotalSize($share['id']);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($share['title'] ?: 'Téléchargement') ?> - Send</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/public/assets/style.css">
</head>
<body class="public-page">
    <div class="download-container">
        <div class="download-box">
            <div class="download-header">
                <h1><?= htmlspecialchars($share['title'] ?: 'Fichiers partagés') ?></h1>
                <?php if (!$passwordRequired || $passwordValid): ?>
                    <p class="file-info">
                        <?= count($files) ?> fichier<?= count($files) > 1 ? 's' : '' ?>
                        &bull; <?= File::formatSize($totalSize) ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($passwordRequired && !$passwordValid): ?>
                <!-- Formulaire mot de passe -->
                <div class="password-form">
                    <p>Ce partage est protégé par un mot de passe.</p>

                    <?php if ($passwordBlocked): ?>
                        <div class="alert alert-error">
                            Trop de tentatives. Réessayez dans <span id="countdown"><?= (int)$passwordBlockTime ?></span> secondes.
                        </div>
                    <?php elseif ($passwordError): ?>
                        <div class="alert alert-error"><?= htmlspecialchars($passwordError) ?></div>
                    <?php endif; ?>

                    <form method="POST" <?= $passwordBlocked ? 'style="display:none"' : '' ?>>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>">
                        <div class="form-group">
                            <input type="password"
                                   name="password"
                                   placeholder="Mot de passe"
                                   required
                                   autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            Accéder aux fichiers
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <!-- Liste des fichiers -->
                <?php if (count($files) > 1): ?>
                    <a href="<?= BASE_URL ?>/d/<?= htmlspecialchars($slug) ?>/zip"
                       class="btn btn-primary btn-block btn-download-all">
                        Tout télécharger (ZIP)
                    </a>
                <?php endif; ?>

                <ul class="file-list-public">
                    <?php foreach ($files as $file): ?>
                        <li>
                            <a href="<?= BASE_URL ?>/d/<?= htmlspecialchars($slug) ?>/file/<?= $file['id'] ?>"
                               class="file-item">
                                <span class="file-name"><?= htmlspecialchars($file['original_name']) ?></span>
                                <span class="file-size"><?= File::formatSize($file['size']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if (empty($files)): ?>
                    <p class="text-muted text-center">Aucun fichier disponible.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <footer class="public-footer">
            <p>Partagé via <strong>Send</strong></p>
        </footer>
    </div>

    <?php if ($passwordBlocked): ?>
    <script>
        let countdown = <?= (int)$passwordBlockTime ?>;
        const el = document.getElementById('countdown');
        const interval = setInterval(() => {
            countdown--;
            el.textContent = countdown;
            if (countdown <= 0) {
                clearInterval(interval);
                location.reload();
            }
        }, 1000);
    </script>
    <?php endif; ?>
</body>
</html>
