<?php
/**
 * Send - Migrations du schéma, suivies par PRAGMA user_version
 *
 * Chaque version s'applique une seule fois, dans l'ordre, sur une base neuve
 * comme sur une base existante.
 */

declare(strict_types=1);

class Schema
{
    private const MIGRATIONS = [
        1 => 'createUploadTables',
        2 => 'createSettingsTable',
        3 => 'migrateBrandingToLists',
    ];

    public static function migrate(PDO $db): void
    {
        $current = (int) $db->query('PRAGMA user_version')->fetchColumn();

        foreach (self::MIGRATIONS as $version => $method) {
            if ($version <= $current) {
                continue;
            }
            self::$method($db);
            $db->exec('PRAGMA user_version = ' . $version);
        }
    }

    private static function createUploadTables(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS uploads (
                id TEXT PRIMARY KEY,
                original_name TEXT NOT NULL,
                size INTEGER NOT NULL,
                chunk_size INTEGER NOT NULL,
                total_chunks INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open', 'assembling')),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS upload_chunks (
                upload_id TEXT NOT NULL,
                chunk_index INTEGER NOT NULL,
                PRIMARY KEY (upload_id, chunk_index),
                FOREIGN KEY (upload_id) REFERENCES uploads(id) ON DELETE CASCADE
            )
        ");
    }

    private static function createSettingsTable(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    /**
     * Image unique → liste d'images ; texte + lien → HTML de l'encart
     */
    private static function migrateBrandingToLists(PDO $db): void
    {
        $read = $db->prepare('SELECT value FROM settings WHERE key = ?');
        $value = function (string $key) use ($read): ?string {
            $read->execute([$key]);
            $found = $read->fetchColumn();
            return $found === false ? null : (string) $found;
        };
        $write = $db->prepare(
            'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );

        $wallpaper = $value('branding_wallpaper');
        if ($wallpaper !== null) {
            $write->execute(['branding_wallpapers', json_encode([$wallpaper])]);
        }

        $text = $value('branding_promo_text') ?? '';
        $url = $value('branding_promo_url') ?? '';
        if ($text !== '') {
            $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            $inner = $url === ''
                ? $escapedText
                : '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $escapedText . '</a>';
            $write->execute(['branding_promo_html', HtmlSanitizer::sanitize('<p>' . $inner . '</p>')]);
        }

        $db->exec("DELETE FROM settings WHERE key IN ('branding_wallpaper', 'branding_promo_text', 'branding_promo_url')");
    }
}
