#!/usr/bin/env bash
# Personnalisation : images de fond (rotation), encart HTML, aperçu
source "$(dirname "$0")/lib.sh"
setup_app; start_server; login

db() { sqlite3 "$APP/storage/database.sqlite" "$1"; }
wallpaper_count() { db "SELECT COALESCE(json_array_length(value), 0) FROM settings WHERE key='branding_wallpapers'" | grep . || echo 0; }
branding_csrf() { curl -s -b "$JAR" -c "$JAR" "$BASE/admin/branding" | csrf_from; }
post_branding() {
    curl -s -o "$WORK/branding.html" -w '%{http_code}' -b "$JAR" -c "$JAR" \
        -F "csrf_token=$(branding_csrf)" "$@" "$BASE/admin/branding"
}
page_error() { sed -n 's/.*class="alert alert-error"[^>]*>\([^<]*\)<.*/\1/p' "$WORK/branding.html"; }
header_of() { curl -s -D - -o /dev/null -b "$JAR" "$2" | grep -i "^$1:" | tr -d '\r' | cut -d' ' -f2-; }

php -r '
    foreach ([[$argv[1], [30, 90, 160]], [$argv[2], [160, 60, 40]]] as [$path, $rgb]) {
        $i = imagecreatetruecolor(1600, 900);
        imagefill($i, 0, 0, imagecolorallocate($i, ...$rgb));
        imagepng($i, $path);
    }
    $j = imagecreatetruecolor(800, 600); imagejpeg($j, $argv[3]);
' "$WORK/a.png" "$WORK/b.png" "$WORK/c.jpg"
printf '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' >"$WORK/x.svg"
printf '<?php system($_GET["c"]);' >"$WORK/shell.png"
promo='<h1>Hélène Pêche</h1><p>Photographe <b>Lyon</b> <a href="https://exemple.fr/portfolio">portfolio</a></p><script>alert(1)</script><p><a href="javascript:alert(2)">piège</a></p>'

check "admin sans session → login" 302 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/branding")"
check "aperçu sans session → login" 302 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/admin/branding-preview")"

check "2 images + encart → 302" 302 "$(post_branding -F "wallpapers[]=@$WORK/a.png" -F "wallpapers[]=@$WORK/b.png" --form-string "promo_html=$promo")"
check "2 images enregistrées" 2 "$(wallpaper_count)"
first="$(db "SELECT json_extract(value, '\$[0]') FROM settings WHERE key='branding_wallpapers'")"
check "image servie en image/png" image/png "$(header_of content-type "$BASE/branding/wallpaper/$first")"
check "image servie avec nosniff" nosniff "$(header_of x-content-type-options "$BASE/branding/wallpaper/$first")"
check "nom inconnu → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/branding/wallpaper/ffffffffffffffffffffffffffffffff.png")"
check "nom invalide → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/branding/wallpaper/..%2f..%2fconfig.php")"

printf 'abc' >"$WORK/abc.txt"
slug="$(body_of "$(api_finish "$(upload_file "$WORK/abc.txt" abc.txt)")" | jget slug)"
page="$(curl -s "$BASE/d/$slug")"
check "page : 2 images transmises à la rotation" 2 "$(grep -o 'data-wallpapers="[^"]*"' <<<"$page" | grep -o 'branding/wallpaper/' | wc -l | tr -d ' ')"
check "page : script de rotation chargé" 1 "$(grep -c 'wallpaper-rotator.js' <<<"$page")"
check "encart : titre et gras gardés" 1 "$(grep -c '<h1>Hélène Pêche</h1><p>Photographe <strong>Lyon</strong>' <<<"$page")"
check "encart : lien http(s) gardé en sponsored" 1 "$(grep -c 'href="https://exemple.fr/portfolio" target="_blank" rel="noopener noreferrer sponsored"' <<<"$page")"
check "encart : script supprimé" 0 "$(grep -c 'alert(1)' <<<"$page")"
check "encart : lien javascript: retiré" 0 "$(grep -c 'javascript:' <<<"$page")"

for f in x.svg shell.png; do
    post_branding -F "wallpapers[]=@$WORK/c.jpg" -F "wallpapers[]=@$WORK/$f" --form-string "promo_html=$promo" >/dev/null
    check "lot avec [$f] refusé, fichier nommé" 1 "$(page_error | grep -c "« $f » refusée")"
done
check "lot refusé : rien n'a été ajouté" 2 "$(wallpaper_count)"
check "lot refusé : aucun fichier écrit" 2 "$(ls "$APP/storage/branding" | wc -l | tr -d ' ')"

args=(); for _ in $(seq 1 19); do args+=(-F "wallpapers[]=@$WORK/c.jpg"); done
post_branding "${args[@]}" --form-string "promo_html=$promo" >/dev/null
check "21 images au total → refus" 1 "$(page_error | grep -c 'Trop d&#039;images\|Trop d.images')"

post_branding --form-string "promo_html=<p>$(printf 'a%.0s' $(seq 1 601))</p>" >/dev/null
check "encart de 601 caractères refusé" 1 "$(page_error | grep -c 'trop long')"
c="$(curl -s -o "$WORK/branding.html" -w '%{http_code}' -b "$JAR" -F csrf_token=faux -F promo_html=x "$BASE/admin/branding")"
check "jeton CSRF invalide → refus" 1 "$(page_error | grep -c 'Session expirée')"

check "retrait d'une image → 302" 302 "$(post_branding -F "remove_wallpapers[]=$first" --form-string "promo_html=$promo")"
check "1 image restante" 1 "$(wallpaper_count)"
check "image retirée effacée du disque" 0 "$(ls "$APP/storage/branding" | grep -c "$first")"
check "image retirée → 404" 404 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/branding/wallpaper/$first")"
check "une seule image : pas de script de rotation" 0 "$(curl -s "$BASE/d/$slug" | grep -c 'wallpaper-rotator.js')"

preview="$(curl -s -b "$JAR" "$BASE/admin/branding-preview")"
check "aperçu : faux partage" 1 "$(grep -c '<h1>Exemple de partage</h1>' <<<"$preview")"
check "aperçu : encart affiché" 1 "$(grep -c 'promo-card' <<<"$preview")"
check "aperçu encadrable par le même site" SAMEORIGIN "$(header_of x-frame-options "$BASE/admin/branding-preview")"
check "aperçu : frame-ancestors 'self'" 1 "$(header_of content-security-policy "$BASE/admin/branding-preview" | grep -c "frame-ancestors 'self'")"
check "page réelle toujours non encadrable" DENY "$(header_of x-frame-options "$BASE/d/$slug")"

check "encart vidé → 302" 302 "$(post_branding --form-string 'promo_html=<p><br></p>')"
check "encart vide : masqué" 0 "$(curl -s "$BASE/d/$slug" | grep -c 'promo-card')"

db "DELETE FROM settings; PRAGMA user_version = 2;"
db "INSERT INTO settings (key, value) VALUES ('branding_wallpaper', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png'), ('branding_promo_text', 'Mon <site>'), ('branding_promo_url', 'https://exemple.fr')"
curl -s -o /dev/null -b "$JAR" "$BASE/admin/branding"
check "migration v3 : appliquée (version ≥ 3)" 1 "$([[ $(db 'PRAGMA user_version') -ge 3 ]] && echo 1)"
check "migration v3 : image reprise en liste" '["aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png"]' "$(db "SELECT value FROM settings WHERE key='branding_wallpapers'")"
check "migration v3 : texte + lien repris en HTML échappé" \
    '<p><a href="https://exemple.fr" target="_blank" rel="noopener noreferrer sponsored">Mon &lt;site&gt;</a></p>' \
    "$(db "SELECT value FROM settings WHERE key='branding_promo_html'")"
check "migration v3 : anciennes clés supprimées" 0 "$(db "SELECT COUNT(*) FROM settings WHERE key IN ('branding_wallpaper','branding_promo_text','branding_promo_url')")"

finish_report
