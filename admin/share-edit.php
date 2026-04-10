<?php
/**
 * Send - Détail/édition d'un partage
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// Vérifier l'authentification
if (!Auth::check()) {
    header('Location: ' . BASE_URL . '/admin/login');
    exit;
}

// Paramètre injecté par le router
$slug = $GLOBALS['url_params'][0] ?? '';
$share = Share::findBySlug($slug);

if (!$share || $share['status'] === 'deleted') {
    http_response_code(404);
    echo '<h1>Partage non trouvé</h1>';
    exit;
}

$csrfToken = Auth::generateCsrfToken();
$success = '';
$error = '';

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Session expirée.';
    } else {
        $updateData = [
            'title' => trim($_POST['title'] ?? '')
        ];

        // Mot de passe (vide = supprimer, nouveau = mettre à jour)
        if (isset($_POST['password']) && $_POST['password'] !== '********') {
            $updateData['password'] = $_POST['password'];
        }

        if (Share::update($share['id'], $updateData)) {
            $success = 'Partage mis à jour.';
            $share = Share::findById($share['id']); // Recharger
        } else {
            $error = 'Erreur lors de la mise à jour.';
        }
    }
}

$files = File::getByShareId($share['id']);
$stats = Share::getStats($share['id']);
$downloads = Share::getDownloadHistory($share['id']);
$publicUrl = Share::getPublicUrl($share['slug']);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($share['title'] ?: $share['slug']) ?> - Send</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/public/assets/style.css">
</head>
<body>
    <div class="admin-layout">
        <nav class="admin-nav">
            <a href="<?= BASE_URL ?>/admin" class="nav-brand">Send</a>
            <div class="nav-links">
                <a href="<?= BASE_URL ?>/admin" class="nav-link">Dashboard</a>
                <a href="<?= BASE_URL ?>/admin/upload" class="nav-link">Nouveau</a>
                <a href="<?= BASE_URL ?>/admin/shares" class="nav-link">Partages</a>
                <form method="POST" action="<?= BASE_URL ?>/admin/logout" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>"><button type="submit" class="nav-link" style="background:none;border:none;cursor:pointer">Déconnexion</button></form>
            </div>
        </nav>

        <main class="admin-main">
            <div class="page-header">
                <h1>
                    <?= htmlspecialchars($share['title'] ?: $share['slug']) ?>
                    <span class="badge badge-<?= htmlspecialchars($share['status']) ?>"><?= htmlspecialchars($share['status']) ?></span>
                </h1>
                <a href="<?= BASE_URL ?>/admin/shares" class="btn btn-secondary">
                    ← Retour
                </a>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- URL publique -->
            <div class="section">
                <h2>Lien de partage</h2>
                <div class="share-url-box">
                    <input type="text" value="<?= htmlspecialchars($publicUrl) ?>" readonly id="share-url">
                    <button type="button" class="btn btn-secondary copy-btn">Copier</button>
                    <a href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" class="btn btn-secondary">Ouvrir</a>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid stats-grid-small">
                <div class="stat-card">
                    <div class="stat-value"><?= count($files) ?></div>
                    <div class="stat-label">Fichiers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['views'] ?></div>
                    <div class="stat-label">Vues</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['downloads'] ?></div>
                    <div class="stat-label">Téléchargements</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $stats['unique_visitors'] ?></div>
                    <div class="stat-label">Visiteurs uniques</div>
                </div>
            </div>

            <!-- Actions rapides -->
            <div class="section">
                <h2>Actions</h2>
                <div class="action-buttons">
                    <form method="POST" action="<?= BASE_URL ?>/admin/share/<?= htmlspecialchars($slug) ?>/toggle" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <button type="submit" class="btn btn-secondary">
                            <?= $share['status'] === 'active' ? '⏸ Mettre en pause' : '▶ Réactiver' ?>
                        </button>
                    </form>

                    <form method="POST" action="<?= BASE_URL ?>/admin/share/<?= htmlspecialchars($slug) ?>/delete" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <button type="submit" class="btn btn-danger" data-confirm="Supprimer ce partage et tous ses fichiers ?">
                            🗑 Supprimer
                        </button>
                    </form>
                </div>
            </div>

            <!-- Édition -->
            <div class="section">
                <h2>Modifier</h2>
                <form method="POST" class="edit-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="form-group">
                        <label for="title">Titre</label>
                        <input type="text" id="title" name="title"
                               value="<?= htmlspecialchars($share['title'] ?? '') ?>"
                               placeholder="Sans titre">
                    </div>

                    <div class="form-group">
                        <label for="password">Mot de passe</label>
                        <input type="password" id="password" name="password"
                               value="<?= $share['password'] ? '********' : '' ?>"
                               placeholder="Laisser vide pour supprimer">
                        <?php if ($share['password']): ?>
                            <small class="text-muted">Actuellement protégé. Entrez un nouveau mot de passe pour le changer, ou laissez vide pour le supprimer.</small>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </form>
            </div>

            <!-- Fichiers -->
            <div class="section">
                <h2>Fichiers (<?= count($files) ?>)</h2>

                <?php if (empty($files)): ?>
                    <p class="text-muted">Aucun fichier.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Taille</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($files as $file): ?>
                                <tr>
                                    <td><?= htmlspecialchars($file['original_name']) ?></td>
                                    <td><?= File::formatSize($file['size']) ?></td>
                                    <td><code><?= htmlspecialchars($file['mime_type']) ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="text-muted">
                        Total : <?= File::formatSize(File::getTotalSize($share['id'])) ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Historique téléchargements -->
            <div class="section">
                <h2>Historique des téléchargements</h2>

                <?php if (empty($downloads)): ?>
                    <p class="text-muted">Aucun téléchargement.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Fichier</th>
                                <th>IP</th>
                                <th>User Agent</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($downloads as $dl): ?>
                                <tr>
                                    <td>
                                        <?= $dl['file_name'] ? htmlspecialchars($dl['file_name']) : '<em>ZIP complet</em>' ?>
                                    </td>
                                    <td><code><?= htmlspecialchars($dl['ip']) ?></code></td>
                                    <td class="user-agent">
                                        <small><?= htmlspecialchars(mb_substr($dl['user_agent'] ?? '', 0, 50, 'UTF-8')) ?>...</small>
                                    </td>
                                    <td><?= date('d/m/Y H:i:s', strtotime($dl['downloaded_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Infos -->
            <div class="section">
                <h2>Informations</h2>
                <dl class="info-list">
                    <dt>Slug</dt>
                    <dd><code><?= htmlspecialchars($share['slug']) ?></code></dd>

                    <dt>Créé le</dt>
                    <dd><?= date('d/m/Y à H:i:s', strtotime($share['created_at'])) ?></dd>

                    <dt>Protection</dt>
                    <dd><?= $share['password'] ? 'Oui (mot de passe)' : 'Non' ?></dd>
                </dl>
            </div>
        </main>
    </div>

    <script src="<?= htmlspecialchars(BASE_URL) ?>/public/assets/app.js"></script>
</body>
</html>
