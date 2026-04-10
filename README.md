# Send

Alternative self-hosted à WeTransfer, conçue pour fonctionner sur un **hébergement mutualisé** classique. Zéro dépendance, juste du PHP et une base SQLite.

## Pourquoi ?

Les services comme WeTransfer ou Smash imposent leurs limites, leur branding et leurs conditions d'utilisation. Send s'héberge sur n'importe quel hébergement PHP mutualisé — pas besoin de VPS, de Docker, ni de Node.js.

## Fonctionnalités

- Upload drag & drop avec support multi-fichiers
- Liens de partage avec slugs personnalisables (aléatoire ou custom)
- Protection par mot de passe optionnelle sur les partages
- Téléchargement ZIP de tous les fichiers d'un partage
- Panel admin avec statistiques et gestion des partages
- Notifications email à chaque téléchargement
- Pause/reprise des partages sans suppression
- Rate limiting sur les téléchargements et tentatives de mot de passe
- Audit log de toutes les actions

## Prérequis

- PHP 8.1+ avec les extensions : `pdo_sqlite`, `finfo`, `zip`
- Apache avec `mod_rewrite` activé

## Installation

```bash
git clone https://github.com/votre-user/send.git  # Adaptez l'URL
cd send
cp config.example.php config.php
```

Éditez `config.php` :

```php
// Générer une clé secrète unique :
// php -r "echo bin2hex(random_bytes(32));"
define('SECRET_KEY', 'votre_cle_secrete');

define('ADMIN_USERNAME', 'votre_username');

// Générer le hash du mot de passe :
// php -r "echo password_hash('votre_mot_de_passe', PASSWORD_ARGON2ID);"
define('ADMIN_PASSWORD_HASH', 'le_hash_genere');

define('ADMIN_EMAIL', 'vous@example.com');
```

Vérifiez les permissions du dossier `storage/` :

```bash
chmod 755 storage/
chmod 755 storage/files/
```

La base de données SQLite est créée automatiquement au premier accès.

## Utilisation

1. Connectez-vous sur `/admin` avec les credentials configurés
2. Allez sur `/admin/upload`, déposez vos fichiers, choisissez un slug et un mot de passe optionnel
3. Copiez le lien généré (`/d/votre-slug`) et partagez-le
4. Le destinataire accède à la page, entre le mot de passe si nécessaire, et télécharge les fichiers

## Configuration

Toutes les options sont dans `config.php`. Les plus utiles :

| Paramètre | Défaut | Description |
|-----------|--------|-------------|
| `ALLOWED_EXTENSIONS` | jpg, pdf, zip, mp4... | Liste blanche des extensions acceptées |
| `MAX_FILE_SIZE` | `0` (limite serveur) | Taille max en octets par fichier |
| `RATE_LIMIT_PASSWORD_ATTEMPTS` | `5` | Tentatives mot de passe avant blocage |
| `RATE_LIMIT_PASSWORD_WINDOW` | `900` | Durée du blocage en secondes (15 min) |
| `TRUSTED_PROXY` | `'none'` | `'cloudflare'`, `'proxy'`, ou `'none'` |
| `SESSION_LIFETIME` | `86400` | Durée de session admin en secondes |

Pour augmenter la limite d'upload sur mutualisé, décommentez dans `.htaccess` :

```apache
php_value upload_max_filesize 500M
php_value post_max_size 500M
php_value max_execution_time 300
```

## Déploiement sur mutualisé

1. Uploadez tous les fichiers via FTP/SFTP
2. Vérifiez que `config.php` est bien configuré (`DEBUG` à `false`, `SECRET_KEY` changée)
3. Le `.htaccess` gère le routage et la sécurité automatiquement
4. Si vous êtes derrière Cloudflare, passez `TRUSTED_PROXY` à `'cloudflare'`

## Développement local

```bash
php -S localhost:8000
# Accès admin : http://localhost:8000/admin
```

## Structure du projet

```
├── index.php              # Router principal
├── config.php             # Configuration (non versionné)
├── config.example.php     # Template de configuration
├── .htaccess              # Réécriture URL et sécurité Apache
├── admin/                 # Panel d'administration
├── public/                # Pages publiques (download, zip, assets)
├── src/                   # Classes PHP (Auth, Database, File, Share...)
└── storage/               # Données (non versionné)
    ├── database.sqlite
    └── files/
```

## Sécurité

- Hashage Argon2ID pour les mots de passe (admin et partages)
- Protection CSRF sur tous les formulaires
- Rate limiting sur login, mots de passe et téléchargements
- Validation des uploads : whitelist d'extensions, vérification MIME et magic bytes, 85+ extensions dangereuses bloquées, détection des doubles extensions
- Headers de sécurité (CSP, X-Frame-Options, X-Content-Type-Options)
- Protection contre le path traversal
- Requêtes SQL préparées (PDO)
- Accès direct à `config.php`, `storage/` et `src/` bloqué par `.htaccess`

## Licence

[MIT](LICENSE) -- tobaz92
