#!/usr/bin/env bash
# Chaîne de la prod reproduite : nginx (128 Mio max) → Apache + PHP 8.1 avec
# les .htaccess du projet. Prouve qu'un fichier de 200 Mo passe en morceaux
# là où l'envoi d'un bloc est refusé, et que storage/ reste fermé.
source "$(dirname "$0")/lib.sh"
COMPOSE=(docker compose -p send-proxy-test -f "$REPO_ROOT/tests/docker/compose.yml")

setup_app
chmod -R a+rwX "$APP/storage"
export SEND_APP_DIR="$APP" SEND_PROXY_PORT="$PORT"
trap '"${COMPOSE[@]}" down -v >/dev/null 2>&1; rm -rf "$WORK"' EXIT
"${COMPOSE[@]}" up -d --build --quiet-pull >/dev/null 2>&1
for _ in $(seq 1 60); do
    curl -s -o /dev/null "$BASE/admin/login" && break
    sleep 1
done
login

big="$WORK/video.mp4"
php -r '$h = fopen($argv[1], "wb"); fwrite($h, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom"); for ($i = 0; $i < 200; $i++) { fwrite($h, random_bytes(1024 * 1024)); } fclose($h);' "$big"

c="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -F "files[]=@$big" "$BASE/admin/upload")"
check "200 Mo en une requête → 413 du proxy" 413 "$c"

r="$(api_start video.mp4 "$(wc -c <"$big" | tr -d ' ')")"
check "chunk_size = 32 Mio (plafond)" 33554432 "$(body_of "$r" | jget chunk_size)"
api cancel -F "upload_id=$(body_of "$r" | jget upload_id)" >/dev/null

id="$(upload_file "$big" video.mp4)"
r="$(api_finish "$id")"
check "200 Mo en morceaux → partage créé" 200 "$(code_of "$r")"
stored="$(ls -S "$APP/storage/files" | head -1)"
check "contenu identique (sha256)" "$(shasum -a 256 <"$big" | cut -d' ' -f1)" \
    "$(shasum -a 256 <"$APP/storage/files/$stored" | cut -d' ' -f1)"

id="$(body_of "$(api_start probe.txt 3)" | jget upload_id)"
for path in "storage/tmp/$id.part" storage/database.sqlite "storage/files/$stored" src/ChunkUpload.php config.php; do
    check "accès direct /$path → 403" 403 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$path")"
done
php -r '$i = imagecreatetruecolor(64, 64); imagepng($i, $argv[1]);' "$WORK/fond.png"
csrf="$(curl -s -b "$JAR" -c "$JAR" "$BASE/admin/branding" | csrf_from)"
curl -s -o /dev/null -b "$JAR" -c "$JAR" -F "csrf_token=$csrf" -F "wallpaper=@$WORK/fond.png" -F promo_text= -F promo_url= "$BASE/admin/branding"
wallpaper="$(ls "$APP/storage/branding")"
check "image de fond servie par sa route" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/branding/wallpaper")"
check "accès direct /storage/branding/<image> → 403" 403 \
    "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/storage/branding/$wallpaper")"
check "upload-api.php appelé directement sans session → 401" 401 \
    "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/admin/upload-api.php")"
check "upload-api.php direct avec session → 404 (pas d'action)" 404 \
    "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H "X-CSRF-Token: $UPLOAD_TOKEN" -X POST "$BASE/admin/upload-api.php")"

finish_report
