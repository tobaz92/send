<?php
/**
 * Send - Page d'upload admin
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// Vérifier l'authentification
if (!Auth::check()) {
    header('Location: ' . BASE_URL . '/admin/login');
    exit;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau partage - Send</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/public/assets/style.css?v=<?= filemtime(ROOT_PATH . '/public/assets/style.css') ?>">
</head>
<body>
    <div class="admin-layout">
        <nav class="admin-nav">
            <a href="<?= BASE_URL ?>/admin" class="nav-brand">Send</a>
            <div class="nav-links">
                <a href="<?= BASE_URL ?>/admin" class="nav-link">Dashboard</a>
                <a href="<?= BASE_URL ?>/admin/upload" class="nav-link active">Nouveau</a>
                <a href="<?= BASE_URL ?>/admin/shares" class="nav-link">Partages</a>
                <a href="<?= BASE_URL ?>/admin/branding" class="nav-link">Personnalisation</a>
                <form method="POST" action="<?= BASE_URL ?>/admin/logout" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>"><button type="submit" class="nav-link" style="background:none;border:none;cursor:pointer">Déconnexion</button></form>
            </div>
        </nav>

        <main class="admin-main">
            <h1>Nouveau partage</h1>

            <form id="upload-form" class="upload-form"
                  data-upload-token="<?= htmlspecialchars(Auth::generateUploadToken()) ?>"
                  data-api-base="<?= htmlspecialchars(BASE_URL) ?>/admin/api/upload"
                  data-share-base="<?= htmlspecialchars(BASE_URL) ?>/admin/share/">
                <!-- Zone de drop -->
                <div id="drop-zone" class="drop-zone">
                    <div class="drop-zone-content">
                        <svg class="drop-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        <p>Glissez vos fichiers ici</p>
                        <p class="text-muted">ou</p>
                        <label class="btn btn-secondary">
                            Parcourir
                            <input type="file" name="files[]" id="file-input" multiple hidden>
                        </label>
                    </div>
                </div>

                <!-- Liste des fichiers -->
                <div id="file-list" class="file-list" style="display: none;">
                    <h3>Fichiers sélectionnés</h3>
                    <ul id="files-ul"></ul>
                    <p class="total-size">Total : <span id="total-size">0</span></p>
                </div>

                <!-- Options -->
                <div class="form-section">
                    <h3>Options</h3>

                    <div class="form-group">
                        <label for="title">Titre (optionnel)</label>
                        <input type="text" id="title" name="title" placeholder="Mon partage">
                    </div>

                    <div class="form-group">
                        <label for="password">Mot de passe (optionnel)</label>
                        <input type="password" id="password" name="password" placeholder="Laisser vide pour aucun">
                    </div>

                    <div class="form-group">
                        <label>Type de lien</label>
                        <div class="radio-group">
                            <label class="radio-label">
                                <input type="radio" name="slug_type" value="random" checked>
                                <span>Aléatoire (6 caractères)</span>
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="slug_type" value="short">
                                <span>Court (8 caractères)</span>
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="slug_type" value="custom">
                                <span>Personnalisé</span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group" id="custom-slug-group" style="display: none;">
                        <label for="custom_slug">Slug personnalisé</label>
                        <div class="input-with-prefix">
                            <span class="input-prefix"><?= htmlspecialchars(BASE_URL) ?>/d/</span>
                            <input type="text" id="custom_slug" name="custom_slug" pattern="[a-zA-Z0-9_-]{3,50}" placeholder="mon-partage">
                        </div>
                    </div>
                </div>

                <div id="upload-error" class="alert alert-error" role="alert" style="display: none;"></div>

                <!-- Progress bar -->
                <div id="progress-section" class="progress-section" style="display: none;">
                    <div id="progress-bar" class="progress-bar" role="progressbar"
                         aria-label="Progression totale" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <div id="progress-fill" class="progress-fill"></div>
                    </div>
                    <p id="progress-text" class="progress-text">Préparation…</p>
                    <ul id="progress-files" class="progress-files"></ul>
                </div>

                <!-- Résultat -->
                <div id="result-section" class="result-section" style="display: none;">
                    <div class="result-success">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                        <h3>Partage créé !</h3>
                        <div class="share-url-box">
                            <input type="text" id="share-url" readonly>
                            <button type="button" id="copy-btn" class="btn btn-secondary">Copier</button>
                        </div>
                        <div class="result-actions">
                            <a href="<?= BASE_URL ?>/admin/upload" class="btn btn-primary">Nouveau partage</a>
                            <a id="view-share-link" href="#" class="btn btn-secondary">Voir le détail</a>
                        </div>
                    </div>
                </div>

                <button type="submit" id="submit-btn" class="btn btn-primary btn-block" disabled>
                    Créer le partage
                </button>
            </form>
        </main>
    </div>

    <script src="<?= htmlspecialchars(BASE_URL) ?>/public/assets/app.js?v=<?= filemtime(ROOT_PATH . '/public/assets/app.js') ?>"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/public/assets/upload-client.js?v=<?= filemtime(ROOT_PATH . '/public/assets/upload-client.js') ?>"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/public/assets/upload-progress.js?v=<?= filemtime(ROOT_PATH . '/public/assets/upload-progress.js') ?>"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/public/assets/upload.js?v=<?= filemtime(ROOT_PATH . '/public/assets/upload.js') ?>"></script>
</body>
</html>
