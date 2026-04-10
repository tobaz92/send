<?php
/**
 * Send - Dashboard admin
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// Vérifier l'authentification
if (!Auth::check()) {
    header('Location: ' . BASE_URL . '/admin/login');
    exit;
}

// Statistiques globales
$totalShares = Database::fetchOne(
    "SELECT COUNT(*) as count FROM shares WHERE status != 'deleted'"
)['count'] ?? 0;

$totalFiles = Database::fetchOne(
    "SELECT COUNT(*) as count FROM files f
     JOIN shares s ON f.share_id = s.id
     WHERE s.status != 'deleted'"
)['count'] ?? 0;

$totalSize = Database::fetchOne(
    "SELECT SUM(f.size) as total FROM files f
     JOIN shares s ON f.share_id = s.id
     WHERE s.status != 'deleted'"
)['total'] ?? 0;

$totalDownloads = Database::fetchOne(
    "SELECT COUNT(*) as count FROM downloads"
)['count'] ?? 0;

// Derniers partages
$recentShares = Share::all();
$recentShares = array_slice($recentShares, 0, 5);

// Derniers téléchargements
$recentDownloads = Database::fetchAll(
    "SELECT d.*, s.slug, s.title, f.original_name
     FROM downloads d
     JOIN shares s ON d.share_id = s.id
     LEFT JOIN files f ON d.file_id = f.id
     ORDER BY d.downloaded_at DESC
     LIMIT 10"
);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Send</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/public/assets/style.css">
</head>
<body>
    <div class="admin-layout">
        <nav class="admin-nav">
            <a href="<?= BASE_URL ?>/admin" class="nav-brand">Send</a>
            <div class="nav-links">
                <a href="<?= BASE_URL ?>/admin" class="nav-link active">Dashboard</a>
                <a href="<?= BASE_URL ?>/admin/upload" class="nav-link">Nouveau</a>
                <a href="<?= BASE_URL ?>/admin/shares" class="nav-link">Partages</a>
                <form method="POST" action="<?= BASE_URL ?>/admin/logout" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>"><button type="submit" class="nav-link" style="background:none;border:none;cursor:pointer">Déconnexion</button></form>
            </div>
        </nav>

        <main class="admin-main">
            <h1>Dashboard</h1>

            <!-- Stats cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $totalShares ?></div>
                    <div class="stat-label">Partages actifs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $totalFiles ?></div>
                    <div class="stat-label">Fichiers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= File::formatSize((int)$totalSize) ?></div>
                    <div class="stat-label">Espace utilisé</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $totalDownloads ?></div>
                    <div class="stat-label">Téléchargements</div>
                </div>
            </div>

            <!-- Quick action -->
            <div class="section">
                <a href="<?= BASE_URL ?>/admin/upload" class="btn btn-primary btn-lg">
                    + Nouveau partage
                </a>
            </div>

            <!-- Recent shares -->
            <div class="section">
                <h2>Partages récents</h2>

                <?php if (empty($recentShares)): ?>
                    <p class="text-muted">Aucun partage pour le moment.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Titre / Slug</th>
                                <th>Fichiers</th>
                                <th>Vues</th>
                                <th>DL</th>
                                <th>Statut</th>
                                <th>Date</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentShares as $share): ?>
                                <tr>
                                    <td>
                                        <?php if ($share['title']): ?>
                                            <strong><?= htmlspecialchars($share['title']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($share['slug']) ?></small>
                                        <?php else: ?>
                                            <strong><?= htmlspecialchars($share['slug']) ?></strong>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $share['file_count'] ?></td>
                                    <td><?= $share['view_count'] ?></td>
                                    <td><?= $share['download_count'] ?></td>
                                    <td>
                                        <span class="badge badge-<?= htmlspecialchars($share['status']) ?>">
                                            <?= htmlspecialchars($share['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($share['created_at']))) ?></td>
                                    <td>
                                        <a href="<?= BASE_URL ?>/admin/share/<?= htmlspecialchars($share['slug']) ?>" class="btn btn-sm">
                                            Détail
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <a href="<?= BASE_URL ?>/admin/shares" class="btn btn-secondary">
                        Voir tous les partages
                    </a>
                <?php endif; ?>
            </div>

            <!-- Recent downloads -->
            <div class="section">
                <h2>Derniers téléchargements</h2>

                <?php if (empty($recentDownloads)): ?>
                    <p class="text-muted">Aucun téléchargement pour le moment.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Partage</th>
                                <th>Fichier</th>
                                <th>IP</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentDownloads as $dl): ?>
                                <tr>
                                    <td>
                                        <a href="<?= BASE_URL ?>/admin/share/<?= htmlspecialchars($dl['slug']) ?>">
                                            <?= htmlspecialchars($dl['title'] ?: $dl['slug']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?= $dl['original_name'] ? htmlspecialchars($dl['original_name']) : '<em>ZIP complet</em>' ?>
                                    </td>
                                    <td><code><?= htmlspecialchars($dl['ip']) ?></code></td>
                                    <td><?= date('d/m/Y H:i', strtotime($dl['downloaded_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="<?= htmlspecialchars(BASE_URL) ?>/public/assets/app.js"></script>
</body>
</html>
