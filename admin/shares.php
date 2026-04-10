<?php
/**
 * Send - Liste des partages admin
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// Vérifier l'authentification
if (!Auth::check()) {
    header('Location: ' . BASE_URL . '/admin/login');
    exit;
}

$shares = Share::all();

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partages - Send</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/public/assets/style.css">
</head>
<body>
    <div class="admin-layout">
        <nav class="admin-nav">
            <a href="<?= BASE_URL ?>/admin" class="nav-brand">Send</a>
            <div class="nav-links">
                <a href="<?= BASE_URL ?>/admin" class="nav-link">Dashboard</a>
                <a href="<?= BASE_URL ?>/admin/upload" class="nav-link">Nouveau</a>
                <a href="<?= BASE_URL ?>/admin/shares" class="nav-link active">Partages</a>
                <form method="POST" action="<?= BASE_URL ?>/admin/logout" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>"><button type="submit" class="nav-link" style="background:none;border:none;cursor:pointer">Déconnexion</button></form>
            </div>
        </nav>

        <main class="admin-main">
            <div class="page-header">
                <h1>Partages</h1>
                <a href="<?= BASE_URL ?>/admin/upload" class="btn btn-primary">
                    + Nouveau partage
                </a>
            </div>

            <?php if (empty($shares)): ?>
                <div class="empty-state">
                    <p>Aucun partage pour le moment.</p>
                    <a href="<?= BASE_URL ?>/admin/upload" class="btn btn-primary">
                        Créer un partage
                    </a>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Titre / Slug</th>
                            <th>Fichiers</th>
                            <th>Taille</th>
                            <th>Vues</th>
                            <th>DL</th>
                            <th>Statut</th>
                            <th>Créé le</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shares as $share): ?>
                            <tr class="<?= $share['status'] === 'paused' ? 'row-muted' : '' ?>">
                                <td>
                                    <?php if ($share['title']): ?>
                                        <strong><?= htmlspecialchars($share['title']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($share['slug']) ?></small>
                                    <?php else: ?>
                                        <strong><?= htmlspecialchars($share['slug']) ?></strong>
                                    <?php endif; ?>
                                    <?php if ($share['password']): ?>
                                        <span class="badge badge-info" title="Protégé par mot de passe">🔒</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $share['file_count'] ?></td>
                                <td><?= File::formatSize((int)($share['total_size'] ?? 0)) ?></td>
                                <td><?= $share['view_count'] ?></td>
                                <td><?= $share['download_count'] ?></td>
                                <td>
                                    <span class="badge badge-<?= htmlspecialchars($share['status']) ?>">
                                        <?= htmlspecialchars($share['status']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($share['created_at']))) ?></td>
                                <td class="actions">
                                    <a href="<?= BASE_URL ?>/admin/share/<?= htmlspecialchars($share['slug']) ?>"
                                       class="btn btn-sm" title="Détail">
                                        Détail
                                    </a>
                                    <a href="<?= Share::getPublicUrl($share['slug']) ?>"
                                       target="_blank"
                                       class="btn btn-sm btn-secondary" title="Voir">
                                        Lien
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </main>
    </div>

    <script src="<?= htmlspecialchars(BASE_URL) ?>/public/assets/app.js"></script>
</body>
</html>
