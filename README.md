<p align="center">
  <img src=".github/banner.svg" alt="Send">
</p>

Alternative self-hosted à WeTransfer. PHP + SQLite, zéro dépendance. Ça tourne sur un mutualisé classique.

## Pourquoi ?

WeTransfer, Smash... t'as pas la main sur les limites, le branding, les CGU. Send se pose sur n'importe quel hébergement PHP. Pas besoin de VPS, de Docker ou de Node.

## Fonctionnalités

- Upload drag & drop, multi-fichiers
- Liens de partage avec slug custom ou aléatoire
- Mot de passe optionnel sur les partages
- Téléchargement ZIP groupé
- Panel admin avec stats et gestion des partages
- Notification email à chaque téléchargement
- Pause/reprise des partages sans les supprimer
- Rate limiting sur les downloads et les tentatives de mot de passe
- Audit log

## Prérequis

- PHP 8.1+ avec `pdo_sqlite`, `finfo`, `zip`
- Apache avec `mod_rewrite`

## Installation

```bash
git clone https://github.com/tobaz92/send.git
cd send
cp config.example.php config.php
```

Ouvre `config.php` et remplis :

```php
// Génère une clé secrète :
// php -r "echo bin2hex(random_bytes(32));"
define('SECRET_KEY', 'votre_cle_secrete');

define('ADMIN_USERNAME', 'votre_username');

// Génère le hash du mot de passe :
// php -r "echo password_hash('votre_mot_de_passe', PASSWORD_ARGON2ID);"
define('ADMIN_PASSWORD_HASH', 'le_hash_genere');

define('ADMIN_EMAIL', 'vous@example.com');
```

Vérifie les permissions :

```bash
chmod 755 storage/
chmod 755 storage/files/
```

La base SQLite se crée toute seule au premier accès.

## Utilisation

1. Connecte-toi sur `/admin`
2. Va sur `/admin/upload`, dépose tes fichiers, choisis un slug et un mot de passe si besoin
3. Copie le lien (`/d/ton-slug`) et envoie-le
4. Le destinataire ouvre la page, entre le mot de passe si y'en a un, et télécharge

## Configuration

Tout est dans `config.php`. Les options utiles :

| Paramètre | Défaut | Description |
|-----------|--------|-------------|
| `ALLOWED_EXTENSIONS` | jpg, pdf, zip, mp4... | Extensions acceptées |
| `MAX_FILE_SIZE` | `0` (limite serveur) | Taille max par fichier en octets |
| `RATE_LIMIT_PASSWORD_ATTEMPTS` | `5` | Tentatives avant blocage |
| `RATE_LIMIT_PASSWORD_WINDOW` | `900` | Durée du blocage (15 min) |
| `TRUSTED_PROXY` | `'none'` | `'cloudflare'`, `'proxy'` ou `'none'` |
| `SESSION_LIFETIME` | `86400` | Durée de session admin (secondes) |

Pour augmenter la limite d'upload sur mutualisé, décommente dans `.htaccess` :

```apache
php_value upload_max_filesize 500M
php_value post_max_size 500M
php_value max_execution_time 300
```

## Déploiement sur mutualisé

1. Upload tous les fichiers via FTP/SFTP
2. Vérifie que `config.php` est bon (`DEBUG` à `false`, `SECRET_KEY` changée)
3. Le `.htaccess` gère le routage et la sécu
4. Derrière Cloudflare ? Passe `TRUSTED_PROXY` à `'cloudflare'`

## Dev local

```bash
php -S localhost:8000
# Admin : http://localhost:8000/admin
```

## Structure

```
├── index.php              # Router
├── config.php             # Config (non versionné)
├── config.example.php     # Template de config
├── .htaccess              # Réécriture URL + sécu Apache
├── admin/                 # Panel admin
├── public/                # Pages publiques, assets
├── src/                   # Classes PHP (Auth, Database, File, Share...)
└── storage/               # Données (non versionné)
    ├── database.sqlite
    └── files/
```

## Sécurité

- Hashage Argon2ID (admin + partages)
- CSRF sur tous les formulaires
- Rate limiting (login, mots de passe, downloads)
- Validation uploads : whitelist d'extensions, vérif MIME + magic bytes, 85+ extensions dangereuses bloquées, doubles extensions détectées
- Headers sécu (CSP, X-Frame-Options, X-Content-Type-Options)
- Protection path traversal
- Requêtes SQL préparées (PDO)
- Accès direct à `config.php`, `storage/` et `src/` bloqué par `.htaccess`

## Licence

[MIT](LICENSE) -- tobaz92
