<?php
/**
 * Send - Reconnaît l'admin quand il visite une page publique, pour ne pas
 * fausser les statistiques de ses propres partages
 */

declare(strict_types=1);

class AdminVisitor
{
    // Couvre la box, la 4G ou le wifi d'un client utilisés récemment pour se connecter
    private const RECENT_LOGIN_WINDOW = '-30 days';

    public static function isAdmin(): bool
    {
        // Lecture seule de la session : Auth::check() prolongerait la session admin depuis une page publique
        if (($_SESSION['admin_logged_in'] ?? false) === true) {
            return true;
        }

        return Database::fetchOne(
            "SELECT 1 FROM audit_logs
             WHERE action = 'login_success' AND ip = ? AND created_at > datetime('now', ?)
             LIMIT 1",
            [Auth::getClientIp(), self::RECENT_LOGIN_WINDOW]
        ) !== null;
    }
}
