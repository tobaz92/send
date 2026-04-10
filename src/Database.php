<?php
/**
 * Send - Database wrapper SQLite
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    /**
     * Récupère l'instance PDO (singleton)
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = new PDO(
                'sqlite:' . DATABASE_PATH,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            // Activer les clés étrangères SQLite
            self::$instance->exec('PRAGMA foreign_keys = ON');
        }

        return self::$instance;
    }

    /**
     * Initialise la base de données (crée les tables si nécessaire)
     */
    public static function init(): void
    {
        $isNewDatabase = !file_exists(DATABASE_PATH);

        $db = self::getInstance();

        // Vérifier si les tables existent
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='shares'");

        if ($result->fetch() === false) {
            self::createTables();
        }

        // Sécuriser les permissions du fichier de base (0600 = lecture/écriture propriétaire uniquement)
        if ($isNewDatabase && file_exists(DATABASE_PATH)) {
            chmod(DATABASE_PATH, 0600);
        }
    }

    /**
     * Crée toutes les tables
     */
    private static function createTables(): void
    {
        $db = self::getInstance();

        $db->exec("
            CREATE TABLE IF NOT EXISTS shares (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT UNIQUE NOT NULL,
                title TEXT,
                password TEXT,
                status TEXT DEFAULT 'active' CHECK(status IN ('active', 'paused', 'deleted')),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                share_id INTEGER NOT NULL,
                original_name TEXT NOT NULL,
                stored_name TEXT NOT NULL,
                size INTEGER NOT NULL,
                mime_type TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (share_id) REFERENCES shares(id) ON DELETE CASCADE
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS downloads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                share_id INTEGER NOT NULL,
                file_id INTEGER,
                ip TEXT NOT NULL,
                user_agent TEXT,
                downloaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (share_id) REFERENCES shares(id) ON DELETE CASCADE,
                FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS views (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                share_id INTEGER NOT NULL,
                ip TEXT NOT NULL,
                viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (share_id) REFERENCES shares(id) ON DELETE CASCADE
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                attempts INTEGER DEFAULT 1,
                last_attempt DATETIME DEFAULT CURRENT_TIMESTAMP,
                blocked_until DATETIME
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action TEXT NOT NULL,
                ip TEXT,
                user_agent TEXT,
                details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Index pour performances
        $db->exec("CREATE INDEX IF NOT EXISTS idx_shares_slug ON shares(slug)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_shares_status ON shares(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_files_share_id ON files(share_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_downloads_share_id ON downloads(share_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_views_share_id ON views(share_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_logs_action_ip ON audit_logs(action, ip, created_at)");
    }

    /**
     * Helper : prépare et exécute une requête
     */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $db = self::getInstance();
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Helper : récupère une seule ligne
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $result = self::query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * Helper : récupère toutes les lignes
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Tables autorisées (whitelist)
     */
    private const ALLOWED_TABLES = [
        'shares', 'files', 'downloads', 'views', 'login_attempts', 'audit_logs'
    ];

    /**
     * Valide un nom de table
     */
    private static function validateTable(string $table): void
    {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new \InvalidArgumentException("Table non autorisée: {$table}");
        }
    }

    /**
     * Helper : insère et retourne l'ID
     */
    public static function insert(string $table, array $data): int
    {
        self::validateTable($table);

        // Valider les noms de colonnes (alphanumériques et underscore uniquement)
        foreach (array_keys($data) as $col) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                throw new \InvalidArgumentException("Nom de colonne invalide: {$col}");
            }
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        self::query(
            "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})",
            array_values($data)
        );

        return (int)self::getInstance()->lastInsertId();
    }

    /**
     * Helper : met à jour des lignes
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        self::validateTable($table);

        // Valider les noms de colonnes
        foreach (array_keys($data) as $col) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                throw new \InvalidArgumentException("Nom de colonne invalide: {$col}");
            }
        }

        $set = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));

        $stmt = self::query(
            "UPDATE {$table} SET {$set} WHERE {$where}",
            array_merge(array_values($data), $whereParams)
        );

        return $stmt->rowCount();
    }

    /**
     * Helper : supprime des lignes
     */
    public static function delete(string $table, string $where, array $params = []): int
    {
        self::validateTable($table);
        return self::query("DELETE FROM {$table} WHERE {$where}", $params)->rowCount();
    }
}
