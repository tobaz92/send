# Phase 2 — backend upload découpé
**Modèle** : opus
**Cible** : dev
**Pré-requis** : 01
**Dépendance externe** : aucune

## But
API d'upload découpé, idempotente et bornée, qui remplace le POST
monolithique de `/admin/upload`.

## Livrable précis
- `src/Database.php` (mod) : `PRAGMA busy_timeout`, tables `uploads` et
  `upload_chunks` créées par `CREATE TABLE IF NOT EXISTS` à **chaque** init
  (aujourd'hui `createTables` ne tourne que sur base neuve : la prod
  n'aurait jamais les nouvelles tables).
- `src/ChunkUpload.php` (new, ≤ 300 l.) :
  - `start(name, size)` : id `bin2hex(random_bytes(16))`, nom nettoyé,
    contrôle anticipé extension/double extension, taille > 0 et
    ≤ `MAX_FILE_SIZE`, quota des uploads ouverts, `disk_free_space`,
    fichier partiel `storage/tmp/<id>.part` créé et pré-dimensionné.
  - `chunkSize()` : min(32 Mio, `upload_max_filesize`, `post_max_size`
    − marge) ; renvoyé au client.
  - `writeChunk(id, index, tmpFile)` : id `^[a-f0-9]{32}$` + lookup base,
    statut `open`, index entier dans [0, total), taille exacte attendue,
    écriture à l'offset (`fopen 'c'`, `fseek`, copie en flux, contrôle
    octets écrits + `fflush`), puis `INSERT OR IGNORE` du morceau :
    hors ordre et doublons idempotents.
  - `finish(ids[], shareOptions)` : bascule atomique `open → assembling`
    (`UPDATE ... WHERE status='open'`, rowcount), tous les morceaux
    présents, `filesize` == taille annoncée, `File::storeAssembled` ;
    partage créé seulement si ≥ 1 fichier valide, en transaction.
  - `cancel(id)`, `purgeExpired()` (uploads non touchés depuis 24 h :
    `.part` + lignes), appelé à chaque `start`.
- `admin/upload-api.php` (new) : POST `/admin/upload/start|chunk|finish|cancel`,
  réponses JSON uniquement, CSRF via en-tête `X-CSRF-Token` vérifié **sans
  rotation** (le jeton actuel est à usage unique : `Auth::verifyCsrfToken`
  l'efface), codes HTTP explicites.
- `src/Auth.php` (mod) : vérification CSRF sans rotation.
- `index.php` (mod) : routes ; auth en échec sur ces routes = 401 JSON,
  pas de 302 vers le login (session expirée en cours d'upload lisible).
- `admin/upload.php` (mod) : suppression du traitement POST monolithique
  (code mort une fois le JS migré, phase 3 livrée dans la même PR).
- `.gitignore` (mod) : `storage/tmp/*`. `storage/tmp` créé à la volée en 0750.
- `tests/chunked-upload.sh` (new) : copie du repo dans un dossier
  temporaire, `config.php` de test, `php -S` avec `upload_max_filesize=2M`
  pour forcer des dizaines de morceaux, login curl, scénarios nominaux :
  multi-fichiers, ordre inversé, doublon, fichier final identique (sha256).

## Validation
- dev : `php -l` partout ; `tests/chunked-upload.sh` vert.

## Risques / points d'attention
- Écritures concurrentes à des offsets distincts : sûres avec des fd séparés.
- Échec d'écriture (disque plein) : le morceau n'est pas enregistré, le
  client réessaie ; erreur explicite, pas d'enregistrement partiel.
- `max_execution_time` : aucune concaténation (écriture à l'offset), la
  finalisation ne lit que l'en-tête (finfo) et fait un `rename`.

## Hors scope
JS (phase 3), reprise après rechargement de la page.

## Patterns du repo à réutiliser
`Database::*`, `Auth::getClientIp`, insert `audit_logs` comme l'upload actuel.
