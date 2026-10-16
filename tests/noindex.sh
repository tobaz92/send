#!/usr/bin/env bash
# Aucune réponse PHP de Send ne doit être indexable
source "$(dirname "$0")/lib.sh"
setup_app; start_server; login

robots() { curl -s -D - -o /dev/null -b "$JAR" "$@" | grep -i '^x-robots-tag:' | tr -d '\r' | cut -d' ' -f2-; }
expected="noindex, nofollow, noarchive"

printf 'abc' >"$WORK/abc.txt"
id="$(upload_file "$WORK/abc.txt" abc.txt)"
slug="$(body_of "$(api_finish "$id")" | jget slug)"
file_id="$(sqlite3 "$APP/storage/database.sqlite" 'SELECT id FROM files LIMIT 1')"

for path in /admin/login /admin /admin/upload /admin/branding "/d/$slug" "/d/$slug/file/$file_id" /d/inexistant; do
    check "X-Robots-Tag sur $path" "$expected" "$(robots "$BASE$path")"
done
check "X-Robots-Tag sur l'API" "$expected" "$(robots -X POST -F x=1 "$BASE/admin/api/upload/start")"

finish_report
