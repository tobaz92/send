#!/usr/bin/env bash
# Personnalisation : image de fond et encart des pages de téléchargement
source "$(dirname "$0")/lib.sh"
setup_app; start_server; login

db() { sqlite3 "$APP/storage/database.sqlite" "$1"; }
branding_csrf() { curl -s -b "$JAR" -c "$JAR" "$BASE/admin/branding" | csrf_from; }
post_branding() {
    curl -s -o "$WORK/branding.html" -w '%{http_code}' -b "$JAR" -c "$JAR" \
        -F "csrf_token=$(branding_csrf)" "$@" "$BASE/admin/branding"
}
page_error() { sed -n 's/.*class="alert alert-error"[^>]*>\([^<]*\)<.*/\1/p' "$WORK/branding.html"; }

php -r '$i = imagecreatetruecolor(1600, 900); imagefill($i, 0, 0, imagecolorallocate($i, 30, 90, 160)); imagepng($i, $argv[1]);' "$WORK/fond.png"
php -r '$i = imagecreatetruecolor(800, 600); imagejpeg($i, $argv[1]);' "$WORK/fond2.jpg"
printf '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' >"$WORK/x.svg"
printf '<?php system($_GET["c"]);' >"$WORK/shell.png"

check "page sans session → redirection login" 302 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/branding")"
check "pas d'image tant que rien n'est réglé" 404 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/branding/wallpaper")"

check "envoi image + encart → 302" 302 "$(post_branding -F "wallpaper=@$WORK/fond.png" \
    -F 'promo_text=Photographe à Lyon <script>alert(1)</script>' -F 'promo_url=https://exemple.fr/portfolio')"
headers="$(curl -s -D - -o "$WORK/served.png" "$BASE/branding/wallpaper")"
check "image servie en image/png" 1 "$(grep -ci '^content-type: image/png' <<<"$headers")"
check "image servie avec nosniff" 1 "$(grep -ci '^x-content-type-options: nosniff' <<<"$headers")"
check "image servie à l'identique" "$(shasum <"$WORK/fond.png" | cut -d' ' -f1)" "$(shasum <"$WORK/served.png" | cut -d' ' -f1)"

printf 'abc' >"$WORK/abc.txt"
id="$(upload_file "$WORK/abc.txt" abc.txt)"
slug="$(body_of "$(api_finish "$id")" | jget slug)"
page="$(curl -s "$BASE/d/$slug")"
check "page publique : image de fond" 1 "$(grep -c 'class="page-wallpaper"' <<<"$page")"
check "page publique : encart avec lien sponsorisé" 1 "$(grep -c 'rel="noopener noreferrer sponsored"' <<<"$page")"
check "page publique : texte échappé" 1 "$(grep -c '&lt;script&gt;alert(1)&lt;/script&gt;' <<<"$page")"
check "page publique : aucun script injecté" 0 "$(grep -c '<script>alert(1)' <<<"$page")"

old_file="$(ls "$APP/storage/branding")"
check "remplacement → 302" 302 "$(post_branding -F "wallpaper=@$WORK/fond2.jpg" -F 'promo_text=Texte' -F promo_url=)"
check "ancienne image effacée" 0 "$(ls "$APP/storage/branding" | grep -c "$old_file")"
check "nouvelle image servie en JPEG" 1 \
    "$(curl -s -D - -o /dev/null "$BASE/branding/wallpaper" | grep -ci '^content-type: image/jpeg')"

for f in x.svg shell.png; do
    post_branding -F "wallpaper=@$WORK/$f" -F promo_text=T -F promo_url= >/dev/null
    check "image [$f] refusée" "Format refusé : JPEG, PNG ou WebP uniquement." "$(page_error)"
done
check "image refusée : l'ancienne reste" 1 "$(ls "$APP/storage/branding" | wc -l | tr -d ' ')"

for url in 'javascript:alert(1)' 'data:text/html,x' '//evil.example' 'ftp://exemple.fr' 'https://'; do
    post_branding -F promo_text=T -F "promo_url=$url" >/dev/null
    check "lien [$url] refusé" 1 "$(page_error | grep -c 'Lien invalide')"
done
post_branding -F promo_text= -F promo_url=https://exemple.fr >/dev/null
check "lien sans texte refusé" 1 "$(page_error | grep -c 'sans texte')"
post_branding -F "promo_text=$(printf 'a%.0s' $(seq 1 201))" -F promo_url= >/dev/null
check "texte de 201 caractères refusé" 1 "$(page_error | grep -c 'trop long')"
check "réglages inchangés après refus" Texte "$(db "SELECT value FROM settings WHERE key='branding_promo_text'")"

c="$(curl -s -o "$WORK/branding.html" -w '%{http_code}' -b "$JAR" -F csrf_token=faux -F promo_text=X -F promo_url= "$BASE/admin/branding")"
check "sans jeton CSRF valide → refus" 1 "$(page_error | grep -c 'Session expirée')"

check "retrait de l'image + encart vidé → 302" 302 "$(post_branding -F remove_wallpaper=1 -F promo_text= -F promo_url=)"
check "image retirée du disque" 0 "$(ls "$APP/storage/branding" | wc -l | tr -d ' ')"
check "route image → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/branding/wallpaper")"
page="$(curl -s "$BASE/d/$slug")"
check "page publique sans fond ni encart" 0 "$(grep -c 'page-wallpaper\|promo-card' <<<"$page")"

db "DROP TABLE settings; PRAGMA user_version = 1;"
check "base existante en v1 migrée vers la table settings" 200 \
    "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/admin/branding")"
check "user_version passé à 2" 2 "$(db 'PRAGMA user_version')"

finish_report
