<?php
/**
 * Send - Model Share (partage)
 */

declare(strict_types=1);

class Share
{
    /**
     * Crée un nouveau partage
     */
    public static function create(array $data): int
    {
        $slug = $data['slug'] ?? self::generateSlug($data['slug_type'] ?? 'random');

        // Vérifier l'unicité du slug
        while (self::findBySlug($slug)) {
            $slug = self::generateSlug('random');
        }

        $password = null;
        if (!empty($data['password'])) {
            $password = password_hash($data['password'], PASSWORD_ARGON2ID);
        }

        return Database::insert('shares', [
            'slug' => $slug,
            'title' => $data['title'] ?? null,
            'password' => $password,
            'status' => 'active'
        ]);
    }

    /**
     * Génère un slug unique
     */
    public static function generateSlug(string $type = 'random'): string
    {
        return match ($type) {
            'short' => substr(bin2hex(random_bytes(4)), 0, 8),
            'random' => self::randomString(6),
            default => self::randomString(6)
        };
    }

    /**
     * Génère une chaîne aléatoire URL-safe
     */
    private static function randomString(int $length): string
    {
        $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $result = '';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $max)];
        }

        return $result;
    }

    /**
     * Trouve un partage par slug
     */
    public static function findBySlug(string $slug): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM shares WHERE slug = ?",
            [$slug]
        );
    }

    /**
     * Trouve un partage par ID
     */
    public static function findById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM shares WHERE id = ?",
            [$id]
        );
    }

    /**
     * Récupère tous les partages actifs
     */
    public static function all(?string $status = null): array
    {
        $sql = "SELECT s.*,
                (SELECT COUNT(*) FROM files WHERE share_id = s.id) as file_count,
                (SELECT SUM(size) FROM files WHERE share_id = s.id) as total_size,
                (SELECT COUNT(*) FROM views WHERE share_id = s.id) as view_count,
                (SELECT COUNT(*) FROM downloads WHERE share_id = s.id) as download_count
                FROM shares s";

        if ($status) {
            $sql .= " WHERE s.status = ?";
            $sql .= " ORDER BY s.created_at DESC";
            return Database::fetchAll($sql, [$status]);
        }

        $sql .= " WHERE s.status != 'deleted' ORDER BY s.created_at DESC";
        return Database::fetchAll($sql);
    }

    /**
     * Met à jour un partage
     */
    public static function update(int $id, array $data): bool
    {
        $allowedFields = ['title', 'status', 'expires_at'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        // Gestion spéciale du mot de passe
        if (isset($data['password'])) {
            if (empty($data['password'])) {
                $updateData['password'] = null;
            } else {
                $updateData['password'] = password_hash($data['password'], PASSWORD_ARGON2ID);
            }
        }

        if (empty($updateData)) {
            return false;
        }

        return Database::update('shares', $updateData, 'id = ?', [$id]) > 0;
    }

    /**
     * Change le statut d'un partage
     */
    public static function toggleStatus(int $id): ?string
    {
        $share = self::findById($id);
        if (!$share) {
            return null;
        }

        $newStatus = $share['status'] === 'active' ? 'paused' : 'active';
        Database::update('shares', ['status' => $newStatus], 'id = ?', [$id]);

        return $newStatus;
    }

    /**
     * Supprime un partage (soft delete)
     */
    public static function delete(int $id): bool
    {
        return Database::update('shares', ['status' => 'deleted'], 'id = ?', [$id]) > 0;
    }

    /**
     * Supprime définitivement un partage et ses fichiers
     */
    public static function hardDelete(int $id): bool
    {
        // Supprimer les fichiers physiques
        $files = File::getByShareId($id);
        foreach ($files as $file) {
            File::deletePhysical($file['stored_name']);
        }

        // Supprimer en cascade (grâce aux FK)
        return Database::delete('shares', 'id = ?', [$id]) > 0;
    }

    /**
     * Vérifie le mot de passe d'un partage
     */
    public static function verifyPassword(array $share, #[\SensitiveParameter] string $password): bool
    {
        if (empty($share['password'])) {
            return true;
        }

        return password_verify($password, $share['password']);
    }

    /**
     * Récupère l'URL publique d'un partage
     */
    public static function getPublicUrl(string $slug): string
    {
        return BASE_URL . '/d/' . $slug;
    }

    /**
     * Récupère les statistiques d'un partage
     */
    public static function getStats(int $id): array
    {
        $views = Database::fetchOne(
            "SELECT COUNT(*) as count FROM views WHERE share_id = ?",
            [$id]
        );

        $downloads = Database::fetchOne(
            "SELECT COUNT(*) as count FROM downloads WHERE share_id = ?",
            [$id]
        );

        $uniqueIps = Database::fetchOne(
            "SELECT COUNT(DISTINCT ip) as count FROM downloads WHERE share_id = ?",
            [$id]
        );

        return [
            'views' => (int)($views['count'] ?? 0),
            'downloads' => (int)($downloads['count'] ?? 0),
            'unique_visitors' => (int)($uniqueIps['count'] ?? 0)
        ];
    }

    /**
     * Récupère l'historique des téléchargements
     */
    public static function getDownloadHistory(int $id, int $limit = 50): array
    {
        return Database::fetchAll(
            "SELECT d.*, f.original_name as file_name
             FROM downloads d
             LEFT JOIN files f ON d.file_id = f.id
             WHERE d.share_id = ?
             ORDER BY d.downloaded_at DESC
             LIMIT ?",
            [$id, $limit]
        );
    }
}
