# Phase 3 — client JS découpé
**Modèle** : sonnet
**Cible** : dev
**Pré-requis** : 02
**Dépendance externe** : aucune

## But
Le formulaire d'upload utilise l'API découpée, et tout échec s'affiche
avec un message lisible.

## Livrable précis
- `public/assets/app.js` (mod, ≤ 300 l., sinon `public/assets/upload.js`
  new + `admin/upload.php` qui l'inclut) :
  - `start` par fichier, `Blob.slice` à la taille renvoyée par le serveur,
    envoi séquentiel, progression globale en octets.
  - Réessai par morceau (3 essais, attente croissante) sur erreur réseau,
    5xx, 408, 429 ; pas de réessai sur 400/401/403/409/413.
  - Réponse non JSON : message selon le code (413 « morceau refusé par le
    serveur (trop gros) », 401 « session expirée, reconnectez-vous »,
    502/503/504 « serveur indisponible »), jamais « Erreur serveur
    inattendue. » nu.
  - `cancel` best effort des uploads ouverts en cas d'échec ;
    `beforeunload` tant qu'un upload tourne.
  - `finish` avec titre, mot de passe, type de slug, slug custom ;
    affichage du résultat et des erreurs par fichier comme aujourd'hui
    (DOM, pas d'innerHTML sur des données serveur).
  - Plus d'`alert` + `location.reload()` qui perd la sélection : erreur
    dans la page, formulaire de nouveau utilisable.

## Validation
- dev : parcours réel sur `php -S` (upload_max_filesize=2M) d'un fichier
  de 20 Mo + 2 petits, avec contrôle du sha256 téléchargé ; capture
  `/pixel` de l'état succès et d'un état erreur (serveur coupé).

## Risques / points d'attention
- CSP `script-src 'self' 'unsafe-inline'` : script externe autorisé.
- Le jeton CSRF ne tourne plus sur ces routes : un seul jeton pour toute
  la session d'upload.

## Hors scope
Reprise après rechargement, upload parallèle.

## Patterns du repo à réutiliser
`formatSize`, `escapeHtml`, structure `initUpload`.
