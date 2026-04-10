<?php
/**
 * Send - Envoi d'emails
 */

declare(strict_types=1);

class Mailer
{
    /**
     * Notifie l'admin d'un téléchargement
     */
    public static function notifyDownload(array $share, ?array $file): void
    {
        $ip = Auth::getClientIp();
        $fileName = $file ? $file['original_name'] : 'ZIP complet';
        $shareTitle = $share['title'] ?: $share['slug'];

        $subject = "Téléchargement : {$shareTitle}";

        $body = "Nouveau téléchargement sur Send\n";
        $body .= "================================\n\n";
        $body .= "Partage : {$shareTitle}\n";
        $body .= "Fichier : {$fileName}\n";
        $body .= "IP : {$ip}\n";
        $body .= "Date : " . date('d/m/Y à H:i:s') . "\n\n";
        $body .= "Voir les détails : " . BASE_URL . "/admin/share/{$share['slug']}\n";

        self::send(ADMIN_EMAIL, $subject, $body);
    }

    /**
     * Nettoie une valeur de header email contre l'injection
     */
    private static function sanitizeHeader(string $value): string
    {
        // Supprimer CR, LF et null bytes (injection de headers)
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /**
     * Envoie un email
     */
    public static function send(string $to, string $subject, string $body): bool
    {
        // Nettoyer les entrées contre l'injection de headers
        $to = self::sanitizeHeader($to);
        $subjectOriginal = $subject; // Garder l'original pour le log
        $subject = self::sanitizeHeader($subject);

        // Valider le format email
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Database::insert('audit_logs', [
                'action' => 'email_failed',
                'ip' => Auth::getClientIp(),
                'details' => json_encode(['error' => 'Invalid email address', 'to' => $to])
            ]);
            return false;
        }

        $headers = [
            'From' => EMAIL_FROM_NAME . ' <' . EMAIL_FROM . '>',
            'Reply-To' => EMAIL_FROM,
            'X-Mailer' => 'Send/1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ];

        $headerString = '';
        foreach ($headers as $key => $value) {
            $headerString .= "{$key}: " . self::sanitizeHeader($value) . "\r\n";
        }

        // Encoder le sujet en UTF-8
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $result = mail($to, $encodedSubject, $body, $headerString);

        // Log l'envoi (sujet original pour lisibilité)
        Database::insert('audit_logs', [
            'action' => $result ? 'email_sent' : 'email_failed',
            'ip' => Auth::getClientIp(),
            'details' => json_encode([
                'to' => $to,
                'subject' => $subjectOriginal
            ])
        ]);

        return $result;
    }
}
