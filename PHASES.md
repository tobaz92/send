# Upload découpé — phases

Contexte : en prod (OVH mutualisé), le reverse proxy openresty coupe toute
requête > ~128 Mio (413 HTML). L'upload part aujourd'hui en une seule
requête : au-delà, « Erreur serveur inattendue. ». Cible : upload découpé
en morceaux, chaque requête bornée, toutes les vérifications de sécurité
appliquées au fichier final.

- [x] 01 — extraction de la validation de fichier  (sha b5626e2)
- [x] 02 — backend upload découpé  (sha bb2830a)
- [x] 03 — client JS découpé  (sha 44e969f)
- [ ] 04 — pentest et tests adverses
