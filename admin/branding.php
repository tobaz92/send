<?php
/**
 * Send - Personnalisation des pages de téléchargement (image de fond, encart pub)
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (!Auth::check()) {
    header('Location: ' . BASE_URL . '/admin/login');
    exit;
}

/**
 * @throws Exception message destiné à l'admin
 */
function brandingHandlePost(): void
{
    // Corps au-delà de post_max_size : PHP vide $_POST, le CSRF échouerait avec un message trompeur
    $postMax = UploadStorage::postMaxBytes();
    if ($postMax > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMax) {
        throw new Exception('Image trop lourde pour le serveur.');
    }
    if (!Auth::verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        throw new Exception('Session expirée, réessayez.');
    }

    $promo = Branding::normalizePromo((string) ($_POST['promo_text'] ?? ''), (string) ($_POST['promo_url'] ?? ''));
    $wallpaper = $_FILES['wallpaper'] ?? null;
    $hasNewWallpaper = is_array($wallpaper) && ($wallpaper['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (isset($_POST['remove_wallpaper'])) {
        Branding::removeWallpaper();
    } elseif ($hasNewWallpaper) {
        Branding::replaceWallpaper($wallpaper);
    }
    Branding::savePromo($promo);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        brandingHandlePost();
        Security::logAction('branding_update');
        // Redirection : un rechargement ne renverra pas l'image
        header('Location: ' . BASE_URL . '/admin/branding?saved=1');
        exit;
    } catch (RuntimeException $e) {
        error_log('Send branding : ' . $e->getMessage());
        $error = 'Erreur serveur lors de l\'enregistrement.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$branding = Branding::current();
$wallpaperUrl = Branding::wallpaperUrl();
$promoText = $error !== '' ? (string) ($_POST['promo_text'] ?? '') : $branding['promo_text'];
$promoUrl = $error !== '' ? (string) ($_POST['promo_url'] ?? '') : $branding['promo_url'];
$csrfToken = Auth::generateCsrfToken();

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personnalisation - Send</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/public/assets/style.css?v=<?= filemtime(ROOT_PATH . '/public/assets/style.css') ?>">
</head>
<body>
    <div class="admin-layout">
        <nav class="admin-nav">
            <a href="<?= BASE_URL ?>/admin" class="nav-brand">Send</a>
            <div class="nav-links">
                <a href="<?= BASE_URL ?>/admin" class="nav-link">Dashboard</a>
                <a href="<?= BASE_URL ?>/admin/upload" class="nav-link">Nouveau</a>
                <a href="<?= BASE_URL ?>/admin/shares" class="nav-link">Partages</a>
                <a href="<?= BASE_URL ?>/admin/branding" class="nav-link active">Personnalisation</a>
                <form method="POST" action="<?= BASE_URL ?>/admin/logout" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><button type="submit" class="nav-link" style="background:none;border:none;cursor:pointer">Déconnexion</button></form>
            </div>
        </nav>

        <main class="admin-main">
            <h1>Personnalisation</h1>
            <p class="text-muted">Image de fond et encart affichés sur toutes les pages de téléchargement.</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php elseif (isset($_GET['saved'])): ?>
                <div class="alert alert-success" role="status">Personnalisation enregistrée.</div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="branding-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="form-section">
                    <h3>Image de fond</h3>
                    <?php if ($wallpaperUrl !== null): ?>
                        <img src="<?= htmlspecialchars($wallpaperUrl) ?>" alt="Image de fond actuelle" class="branding-preview">
                        <label class="radio-label">
                            <input type="checkbox" name="remove_wallpaper" value="1">
                            <span>Retirer l'image</span>
                        </label>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="wallpaper"><?= $wallpaperUrl !== null ? 'Remplacer par' : 'Choisir une image' ?></label>
                        <input type="file" id="wallpaper" name="wallpaper" accept="image/jpeg,image/png,image/webp">
                        <p class="text-muted">JPEG, PNG ou WebP, 8 Mo max. Paysage conseillé (1920 × 1080 ou plus).</p>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Encart</h3>
                    <div class="form-group">
                        <label for="promo_text">Texte</label>
                        <input type="text" id="promo_text" name="promo_text"
                               maxlength="<?= Branding::MAX_PROMO_TEXT_LENGTH ?>"
                               value="<?= htmlspecialchars($promoText) ?>"
                               placeholder="Photographe à Lyon : découvrez mon travail">
                    </div>
                    <div class="form-group">
                        <label for="promo_url">Lien (optionnel)</label>
                        <input type="url" id="promo_url" name="promo_url"
                               maxlength="<?= Branding::MAX_PROMO_URL_LENGTH ?>"
                               value="<?= htmlspecialchars($promoUrl) ?>"
                               placeholder="https://exemple.fr">
                    </div>
                    <p class="text-muted">Laisser le texte vide pour masquer l'encart.</p>
                </div>

                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </form>
        </main>
    </div>
</body>
</html>
