<?php
/**
 * Send - Enregistre un téléchargement (statistiques, rate limiting, notification),
 * sauf quand c'est l'admin qui télécharge ses propres partages
 */

declare(strict_types=1);

class DownloadRecorder
{
    /** @param array|null $file null = ZIP complet du partage */
    public static function record(array $share, ?array $file, string $ip): void
    {
        if (AdminVisitor::isAdmin()) {
            return;
        }

        $fileId = $file === null ? null : (int) $file['id'];
        Database::insert('downloads', [
            'share_id' => $share['id'],
            'file_id' => $fileId,
            'ip' => $ip,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
        Security::logAction('download', (int) $share['id'], $fileId);
        Mailer::notifyDownload($share, $file);
    }
}
