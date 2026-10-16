#!/usr/bin/env bash
# Upload découpé : scénarios nominaux et tolérance aux morceaux hors ordre / en double
source "$(dirname "$0")/lib.sh"
setup_app; start_server; login

big="$WORK/video.mp4"
php -r '$h = fopen($argv[1], "wb"); fwrite($h, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom"); for ($i = 0; $i < 9; $i++) { fwrite($h, random_bytes(1024 * 1024)); } fclose($h);' "$big"
printf 'bonjour\n' >"$WORK/note.txt"

r="$(api_start video.mp4 "$(wc -c <"$big" | tr -d ' ')")"
check "start 200" 200 "$(code_of "$r")"
chunk_size="$(body_of "$r" | jget chunk_size)"
check "chunk_size borné par upload_max_filesize (2M)" 1 "$([[ "$chunk_size" -le 2097152 && "$chunk_size" -gt 0 ]] && echo 1)"
api cancel -F "upload_id=$(body_of "$r" | jget upload_id)" >/dev/null

id_big="$(upload_file "$big" video.mp4 desc)"
check "morceaux en ordre inverse acceptés" 1 "$([[ -n "$id_big" ]] && echo 1)"
r="$(send_chunk "$id_big" 0 "$big" "$chunk_size")"
check "morceau en double idempotent" 200 "$(code_of "$r")"
id_note="$(upload_file "$WORK/note.txt" note.txt)"

r="$(api_finish "$id_big" "$id_note")"
check "finish 200" 200 "$(code_of "$r")"
check "2 fichiers enregistrés" 2 "$(body_of "$r" | jget files_count)"
slug="$(body_of "$r" | jget slug)"

stored="$(ls -S "$APP/storage/files" | grep '\.mp4$' | head -1)"
check "contenu identique (sha256)" "$(shasum -a 256 <"$big" | cut -d' ' -f1)" \
    "$(shasum -a 256 <"$APP/storage/files/$stored" | cut -d' ' -f1)"
check "storage/tmp vidé" 0 "$(find "$APP/storage/tmp" -type f | wc -l | tr -d ' ')"
check "uploads purgés en base" 0 "$(sqlite3 "$APP/storage/database.sqlite" 'SELECT COUNT(*) FROM uploads')"
check "partage public accessible" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/d/$slug")"

r="$(api_finish "$id_big")"
check "second finish du même upload refusé" 404 "$(code_of "$r")"

finish_report
