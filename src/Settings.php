<?php
/**
 * Send - Réglages globaux clé/valeur (table settings)
 */

declare(strict_types=1);

class Settings
{
    public static function get(string $key): ?string
    {
        $row = Database::fetchOne('SELECT value FROM settings WHERE key = ?', [$key]);
        return $row['value'] ?? null;
    }

    public static function set(string $key, string $value): void
    {
        Database::query(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP',
            [$key, $value]
        );
    }

    public static function delete(string $key): void
    {
        Database::delete('settings', 'key = ?', [$key]);
    }
}
