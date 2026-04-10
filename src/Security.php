<?php
/**
 * Send - Fonctions de sécurité
 */

declare(strict_types=1);

class Security
{
    /**
     * Vérifie le rate limiting pour une action
     */
    public static function checkRateLimit(string $action, string $ip, int $limit, int $window): bool
    {
        // Compter les actions récentes
        $count = Database::fetchOne(
            "SELECT COUNT(*) as count FROM audit_logs
             WHERE action = ? AND ip = ? AND created_at > datetime('now', '-' || ? || ' seconds')",
            [$action, $ip, $window]
        );

        return ($count['count'] ?? 0) < $limit;
    }

    /**
     * Vérifie le rate limit pour les téléchargements
     */
    public static function canDownload(string $ip): bool
    {
        return self::checkRateLimit(
            'download',
            $ip,
            RATE_LIMIT_DOWNLOADS,
            RATE_LIMIT_DOWNLOADS_WINDOW
        );
    }

    /**
     * Log une action pour le rate limiting
     */
    public static function logAction(string $action, ?int $shareId = null, ?int $fileId = null): void
    {
        Database::insert('audit_logs', [
            'action' => $action,
            'ip' => Auth::getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'details' => json_encode(array_filter([
                'share_id' => $shareId,
                'file_id' => $fileId
            ]))
        ]);
    }

    /**
     * Valide et nettoie une entrée utilisateur
     */
    public static function sanitizeInput(string $input, int $maxLength = 255): string
    {
        // Supprimer les null bytes
        $input = str_replace("\0", '', $input);

        // Trim
        $input = trim($input);

        // Limiter la longueur
        if (mb_strlen($input) > $maxLength) {
            $input = mb_substr($input, 0, $maxLength);
        }

        return $input;
    }

    /**
     * Échappe une sortie HTML
     */
    public static function escape(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Génère un token sécurisé
     */
    public static function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Vérifie que la requête vient bien du site (referer check basique)
     */
    public static function checkReferer(): bool
    {
        if (empty($_SERVER['HTTP_REFERER'])) {
            return false;
        }

        $refererHost = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
        $siteHost = parse_url(BASE_URL, PHP_URL_HOST);

        return $refererHost === $siteHost;
    }

    /**
     * Vérifie le rate limit pour les tentatives de mot de passe d'un partage
     */
    public static function checkSharePasswordRateLimit(string $ip, int $shareId): bool
    {
        return self::checkRateLimit(
            "share_password_{$shareId}",
            $ip,
            RATE_LIMIT_PASSWORD_ATTEMPTS,
            RATE_LIMIT_PASSWORD_WINDOW
        );
    }

    /**
     * Enregistre une tentative échouée de mot de passe d'un partage
     */
    public static function logSharePasswordAttempt(string $ip, int $shareId): void
    {
        Database::insert('audit_logs', [
            'action' => "share_password_{$shareId}",
            'ip' => $ip,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'details' => json_encode(['share_id' => $shareId, 'type' => 'password_fail'])
        ]);
    }

    /**
     * Retourne le temps de blocage restant pour les tentatives de mot de passe
     */
    public static function getSharePasswordBlockTime(string $ip, int $shareId): int
    {
        $key = "share_password_{$shareId}";

        // Trouver la plus ancienne tentative dans la fenêtre
        $oldest = Database::fetchOne(
            "SELECT MIN(created_at) as oldest FROM audit_logs
             WHERE action = ? AND ip = ? AND created_at > datetime('now', '-' || ? || ' seconds')",
            [$key, $ip, RATE_LIMIT_PASSWORD_WINDOW]
        );

        if ($oldest && $oldest['oldest']) {
            $oldestTime = strtotime($oldest['oldest']);
            $windowEnd = $oldestTime + RATE_LIMIT_PASSWORD_WINDOW;
            return max(0, $windowEnd - time());
        }

        return 0;
    }

    /**
     * Nettoie les vieilles entrées de la base (maintenance)
     */
    public static function cleanup(): void
    {
        // Supprimer les logs de plus de 90 jours
        Database::query(
            "DELETE FROM audit_logs WHERE created_at < datetime('now', '-90 days')"
        );

        // Supprimer les tentatives de connexion de plus de 24h
        Database::query(
            "DELETE FROM login_attempts WHERE last_attempt < datetime('now', '-24 hours')"
        );

        // Supprimer les vues de plus de 90 jours
        Database::query(
            "DELETE FROM views WHERE viewed_at < datetime('now', '-90 days')"
        );
    }
}
