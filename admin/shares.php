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
$totalSize = array_sum(array_map(fn(array $share) => (int) ($share['total_size'] ?? 0), $shares));
$totalFiles = array_sum(array_column($shares, 'file_count'));
$deletedCount = isset($_GET['deleted']) ? (int) $_GET['deleted'] : null;
$bulkError = match ($_GET['error'] ?? null) {
    'session' => 'Session expirée, réessayez.',
    'selection' => 'Aucun partage sélectionné.',
    default => null,
};

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
                <a href="<?= BASE_URL ?>/admin/branding" class="nav-link">Personnalisation</a>
                <form method="POST" action="<?= BASE_URL ?>/admin/logout" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>"><button type="submit" class="nav-link" style="background:none;border:none;cursor:pointer">Déconnexion</button></form>
            </div>
        </nav>

        <main class="admin-main">
            <div class="page-header">
                <div>
                    <h1>Partages</h1>
                    <?php if (!empty($shares)): ?>
                        <p class="text-muted">Espace occupé : <strong><?= File::formatSize($totalSize) ?></strong></p>
                    <?php endif; ?>
                </div>
                <a href="<?= BASE_URL ?>/admin/upload" class="btn btn-primary">
                    + Nouveau partage
                </a>
            </div>

            <?php if ($bulkError !== null): ?>
                <div class="alert alert-error" role="alert"><?= htmlspecialchars($bulkError) ?></div>
            <?php elseif ($deletedCount !== null): ?>
                <div class="alert alert-success" role="status">
                    <?= $deletedCount ?> partage<?= $deletedCount > 1 ? 's supprimés' : ' supprimé' ?>.
                </div>
            <?php endif; ?>

            <?php if (empty($shares)): ?>
                <div class="empty-state">
                    <p>Aucun partage pour le moment.</p>
                    <a href="<?= BASE_URL ?>/admin/upload" class="btn btn-primary">
                        Créer un partage
                    </a>
                </div>
            <?php else: ?>
                <form method="POST" action="<?= BASE_URL ?>/admin/shares-delete" id="bulk-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::generateCsrfToken()) ?>">
                <div class="bulk-bar">
                    <button type="submit" class="btn btn-danger btn-sm" id="bulk-delete" disabled>
                        Supprimer la sélection
                    </button>
                    <span class="text-muted" id="bulk-count" aria-live="polite"></span>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th class="col-check">
                                <input type="checkbox" id="select-all" aria-label="Tout sélectionner">
                            </th>
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
                                <td class="col-check">
                                    <input type="checkbox" name="share_ids[]" value="<?= (int) $share['id'] ?>"
                                           class="row-check" aria-label="Sélectionner <?= htmlspecialchars($share['title'] ?: $share['slug']) ?>">
                                </td>
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
                    <tfoot>
                        <tr class="table-total">
                            <td></td>
                            <td><?= count($shares) ?> partage<?= count($shares) > 1 ? 's' : '' ?></td>
                            <td><?= (int) $totalFiles ?></td>
                            <td><?= File::formatSize($totalSize) ?></td>
                            <td colspan="5"></td>
                        </tr>
                    </tfoot>
                </table>
                </form>
            <?php endif; ?>
        </main>
    </div>

    <script src="<?= htmlspecialchars(BASE_URL) ?>/public/assets/app.js"></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/public/assets/shares.js?v=<?= filemtime(ROOT_PATH . '/public/assets/shares.js') ?>"></script>
</body>
</html>
