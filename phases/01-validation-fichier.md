# Phase 1 — extraction de la validation de fichier
**Modèle** : sonnet
**Cible** : dev
**Pré-requis** : aucun
**Dépendance externe** : aucune

## But
Rendre les vérifications de `File::upload` applicables à un fichier
assemblé sur disque (pas seulement à un `$_FILES`), sans en perdre aucune.
Ramener `src/File.php` (448 lignes) sous 300.

## Livrable précis
- `src/FileValidator.php` (new) : `MIME_MAP`, `DANGEROUS_EXTENSIONS`,
  `sanitizeFilename`, et `validate(string $path, string $originalName): array`
  qui renvoie `['name' => ..., 'extension' => ..., 'mime' => ...]` ou lève
  une `Exception` : double extension dangereuse, extension hors
  `ALLOWED_EXTENSIONS`, MIME php/x-httpd, MIME incohérent avec l'extension
  (message MIME détaillé seulement si DEBUG), taille > `MAX_FILE_SIZE`.
- `src/File.php` (mod) : `File::upload` délègue à `FileValidator`, garde
  `is_uploaded_file` / `move_uploaded_file`. Nouvelle méthode
  `File::storeAssembled(string $path, string $originalName, int $shareId): int`
  qui valide puis `rename()` vers `FILES_PATH`, chmod 0640, insert en base.
  `File::sanitizeFilename` reste appelable (délégation) si utilisé ailleurs.

## Validation
- dev : `php -l` sur tous les fichiers ; script CLI jetable (hors repo) qui
  appelle `FileValidator::validate` sur : jpg réel OK, `x.php.jpg` refusé,
  php renommé en `.jpg` refusé (MIME), `.exe` refusé, extension non listée
  refusée. Upload actuel (non découpé) toujours fonctionnel via `php -S`.

## Risques / points d'attention
- Comportement identique à l'existant : ordre des contrôles, messages.
- `storeAssembled` : `rename` peut échouer entre systèmes de fichiers ;
  le tmp sera sous `storage/` (même FS que `storage/files`).

## Hors scope
Routes, JS, schéma de base, chunking.

## Patterns du repo à réutiliser
Classes statiques, `declare(strict_types=1)`, exceptions avec message
utilisateur en français, `Database::insert`.
