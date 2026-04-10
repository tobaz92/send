<?php
/**
 * Send - Authentification admin
 */

declare(strict_types=1);

class Auth
{
    /**
     * Vérifie si l'utilisateur est connecté
     */
    public static function check(): bool
    {
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            return false;
        }

        // Timeout d'inactivité (30 minutes)
        $inactivityTimeout = 1800;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $inactivityTimeout) {
            self::logout();
            return false;
        }
        $_SESSION['last_activity'] = time();

        return true;
    }

    /**
     * Tente une connexion
     */
    public static function attempt(
        #[\SensitiveParameter] string $username,
        #[\SensitiveParameter] string $password
    ): bool {
        $ip = self::getClientIp();

        // Vérifier le rate limiting
        if (self::isBlocked($ip)) {
            self::log('login_blocked', $ip, ['reason' => 'rate_limit']);
            return false;
        }

        // Vérifier les credentials
        $validUsername = hash_equals(ADMIN_USERNAME, $username);
        $validPassword = password_verify($password, ADMIN_PASSWORD_HASH);

        if ($validUsername && $validPassword) {
            // Succès
            self::clearAttempts($ip);
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_ip'] = $ip;
            $_SESSION['admin_login_time'] = time();
            $_SESSION['last_activity'] = time();

            self::log('login_success', $ip);
            return true;
        }

        // Échec
        self::recordAttempt($ip);
        self::log('login_fail', $ip);
        return false;
    }

    /**
     * Déconnexion
     */
    public static function logout(): void
    {
        $ip = self::getClientIp();
        self::log('logout', $ip);

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Vérifie si une IP est bloquée
     */
    public static function isBlocked(string $ip): bool
    {
        $attempt = Database::fetchOne(
            "SELECT * FROM login_attempts WHERE ip = ?",
            [$ip]
        );

        if (!$attempt) {
            return false;
        }

        // Vérifier si le blocage est toujours actif
        if ($attempt['blocked_until']) {
            if (strtotime($attempt['blocked_until']) > time()) {
                return true;
            }
            // Blocage expiré, réinitialiser
            self::clearAttempts($ip);
        }

        return false;
    }

    /**
     * Enregistre une tentative échouée
     */
    private static function recordAttempt(string $ip): void
    {
        $attempt = Database::fetchOne(
            "SELECT * FROM login_attempts WHERE ip = ?",
            [$ip]
        );

        if ($attempt) {
            $newAttempts = $attempt['attempts'] + 1;
            $blockedUntil = null;

            // Bloquer après X tentatives
            if ($newAttempts >= RATE_LIMIT_PASSWORD_ATTEMPTS) {
                $blockedUntil = date('Y-m-d H:i:s', time() + RATE_LIMIT_PASSWORD_WINDOW);
            }

            Database::update(
                'login_attempts',
                [
                    'attempts' => $newAttempts,
                    'last_attempt' => date('Y-m-d H:i:s'),
                    'blocked_until' => $blockedUntil
                ],
                'ip = ?',
                [$ip]
            );
        } else {
            Database::insert('login_attempts', [
                'ip' => $ip,
                'attempts' => 1,
                'last_attempt' => date('Y-m-d H:i:s')
            ]);
        }
    }

    /**
     * Efface les tentatives pour une IP
     */
    private static function clearAttempts(string $ip): void
    {
        Database::delete('login_attempts', 'ip = ?', [$ip]);
    }

    /**
     * Log une action d'authentification
     */
    private static function log(string $action, string $ip, array $details = []): void
    {
        Database::insert('audit_logs', [
            'action' => $action,
            'ip' => $ip,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'details' => !empty($details) ? json_encode($details) : null
        ]);
    }

    /**
     * Récupère l'IP du client de manière sécurisée
     * Utilise TRUSTED_PROXY pour déterminer quels headers sont fiables
     */
    public static function getClientIp(): string
    {
        $trustedProxy = defined('TRUSTED_PROXY') ? TRUSTED_PROXY : 'none';

        // Définir les headers à vérifier selon le type de proxy
        $headers = match ($trustedProxy) {
            'cloudflare' => [
                'HTTP_CF_CONNECTING_IP',     // Seul Cloudflare peut définir ce header
                'REMOTE_ADDR'                // Fallback
            ],
            'proxy' => [
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_REAL_IP',
                'REMOTE_ADDR'
            ],
            default => [
                'REMOTE_ADDR'                // Sans proxy, utiliser uniquement REMOTE_ADDR
            ]
        };

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Prendre la première IP si plusieurs (X-Forwarded-For peut en avoir plusieurs)
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Génère un token CSRF
     */
    public static function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Vérifie un token CSRF
     */
    public static function verifyCsrfToken(string $token): bool
    {
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            return false;
        }
        // Renouveler le token après usage
        unset($_SESSION['csrf_token']);
        return true;
    }

    /**
     * Retourne le temps restant de blocage en secondes
     */
    public static function getBlockTimeRemaining(string $ip): int
    {
        $attempt = Database::fetchOne(
            "SELECT blocked_until FROM login_attempts WHERE ip = ?",
            [$ip]
        );

        if ($attempt && $attempt['blocked_until']) {
            $remaining = strtotime($attempt['blocked_until']) - time();
            return max(0, $remaining);
        }

        return 0;
    }
}
