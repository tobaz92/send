<?php
/**
 * Send - Personnalisation des pages de téléchargement (images de fond, encart)
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (!Auth::check()) {
    header('Location: ' . BASE_URL . '/admin/login');
    exit;
}

/**
 * $_FILES['wallpapers'] (champ multiple) → une entrée par fichier réellement choisi
 *
 * @return array[]
 */
function brandingUploadedWallpapers(): array
{
    $field = $_FILES['wallpapers'] ?? null;
    if (!is_array($field) || !is_array($field['name'] ?? null)) {
        return [];
    }
    $uploads = [];
    foreach (array_keys($field['name']) as $index) {
        if (($field['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $uploads[] = [
            'name' => (string) $field['name'][$index],
            'tmp_name' => (string) $field['tmp_name'][$index],
            'error' => (int) $field['error'][$index],
        ];
    }
    return $uploads;
}

/**
 * @throws Exception message destiné à l'admin
 */
function brandingHandlePost(): void
{
    // Corps au-delà de post_max_size : PHP vide $_POST, le CSRF échouerait avec un message trompeur
    $postMax = UploadStorage::postMaxBytes();
    if ($postMax > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMax) {
        throw new Exception('Envoi trop lourd pour le serveur : ajoutez moins d\'images à la fois.');
    }
    if (!Auth::verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        throw new Exception('Session expirée, réessayez.');
    }

    $promoHtml = Branding::normalizePromo((string) ($_POST['promo_html'] ?? ''));
    $toRemove = array_filter((array) ($_POST['remove_wallpapers'] ?? []), [Branding::class, 'isStoredName']);

    Branding::updateWallpapers(array_values($toRemove), brandingUploadedWallpapers());
    Branding::savePromo($promoHtml);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        brandingHandlePost();
        Security::logAction('branding_update');
        // Redirection : un rechargement ne renverra pas les images
        header('Location: ' . BASE_URL . '/admin/branding?saved=1');
        exit;
    } catch (RuntimeException $e) {
        error_log('Send branding : ' . $e->getMessage());
        $error = 'Erreur serveur lors de l\'enregistrement.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$wallpapers = Branding::wallpapers();
// Après une erreur, l'encart saisi n'est pas perdu (il sera re-nettoyé à l'envoi suivant)
$promoHtml = $error !== '' ? HtmlSanitizer::sanitize((string) ($_POST['promo_html'] ?? '')) : Branding::promoHtml();
$csrfToken = Auth::generateCsrfToken();
$asset = fn(string $file) => BASE_URL . '/public/assets/' . $file . '?v=' . filemtime(ROOT_PATH . '/public/assets/' . $file);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Personnalisation - Send</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($asset('style.css')) ?>">
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

        <main class="admin-main branding-page">
            <h1>Personnalisation</h1>
            <p class="text-muted">Images de fond et encart affichés sur toutes les pages de téléchargement.</p>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php elseif (isset($_GET['saved'])): ?>
                <div class="alert alert-success" role="status">Personnalisation enregistrée.</div>
            <?php endif; ?>

            <div class="branding-columns">
                <form method="POST" enctype="multipart/form-data" class="branding-form" id="branding-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="form-section">
                        <h3>Images de fond</h3>
                        <p class="text-muted">Une image différente, tirée au hasard, toutes les 7 secondes, en fondu.</p>
                        <?php if ($wallpapers !== []): ?>
                            <ul class="wallpaper-grid">
                                <?php foreach ($wallpapers as $name): ?>
                                    <li class="wallpaper-item">
                                        <img src="<?= htmlspecialchars(BASE_URL . '/branding/wallpaper/' . $name) ?>" alt="" loading="lazy">
                                        <label class="wallpaper-remove">
                                            <input type="checkbox" name="remove_wallpapers[]" value="<?= htmlspecialchars($name) ?>">
                                            <span>Retirer</span>
                                        </label>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <div class="form-group">
                            <label for="wallpapers">Ajouter des images</label>
                            <input type="file" id="wallpapers" name="wallpapers[]" multiple accept="image/jpeg,image/png,image/webp">
                            <p class="text-muted">
                                JPEG, PNG ou WebP, 8 Mo max chacune, <?= Branding::MAX_WALLPAPERS ?> images au total.
                                Paysage conseillé (1920 × 1080 ou plus).
                            </p>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Encart</h3>
                        <div class="editor" data-editor>
                            <div class="editor-toolbar" role="toolbar" aria-label="Mise en forme de l'encart">
                                <button type="button" class="btn btn-sm" data-command="h1" title="Titre">Titre</button>
                                <button type="button" class="btn btn-sm" data-command="p" title="Paragraphe">¶</button>
                                <button type="button" class="btn btn-sm" data-command="bold" title="Gras"><strong>G</strong></button>
                                <button type="button" class="btn btn-sm" data-command="link" title="Lien">Lien</button>
                                <button type="button" class="btn btn-sm" data-command="unlink" title="Retirer le lien">Sans lien</button>
                            </div>
                            <div class="editor-area" contenteditable="true" role="textbox" aria-multiline="true"
                                 aria-label="Texte de l'encart"><?= $promoHtml ?></div>
                            <input type="hidden" name="promo_html" value="">
                        </div>
                        <p class="text-muted">Laisser vide pour masquer l'encart.</p>
                    </div>

                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </form>

                <section class="branding-preview-panel" aria-label="Aperçu">
                    <div class="branding-preview-header">
                        <h3>Aperçu</h3>
                        <a href="<?= BASE_URL ?>/admin/branding-preview" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">Ouvrir en grand</a>
                    </div>
                    <div class="preview-frame" data-preview-frame>
                        <iframe src="<?= BASE_URL ?>/admin/branding-preview" title="Aperçu d'une page de téléchargement" loading="lazy"></iframe>
                    </div>
                    <p class="text-muted">Faux partage, liens inactifs. L'aperçu reflète la dernière version enregistrée.</p>
                </section>
            </div>
        </main>
    </div>

    <script src="<?= htmlspecialchars($asset('branding-editor.js')) ?>"></script>
</body>
</html>
