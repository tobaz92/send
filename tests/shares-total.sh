#!/usr/bin/env bash
# Poids total des partages dans /admin/shares
source "$(dirname "$0")/lib.sh"
setup_app; start_server; login

php -r 'file_put_contents($argv[1], str_repeat("a", 1048576)); file_put_contents($argv[2], str_repeat("b", 524288));' "$WORK/a.txt" "$WORK/b.txt"
api_finish "$(upload_file "$WORK/a.txt" a.txt)" >/dev/null
api_finish "$(upload_file "$WORK/b.txt" b.txt)" "$(upload_file "$WORK/b.txt" c.txt)" >/dev/null

page="$(curl -s -b "$JAR" "$BASE/admin/shares")"
check "espace occupé en en-tête (2 Mo)" 1 "$(grep -c 'Espace occupé : <strong>2 Mo</strong>' <<<"$page")"
check "pied de tableau : 2 partages" 1 "$(grep -c '2 partages' <<<"$page")"
check "pied de tableau : 3 fichiers" 1 "$(tr -d '\n ' <<<"$page" | grep -c '<td>2partages</td><td>3</td><td>2Mo</td>')"

finish_report
