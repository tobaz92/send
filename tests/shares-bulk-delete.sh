#!/usr/bin/env bash
# Suppression groupée depuis /admin/shares
source "$(dirname "$0")/lib.sh"
setup_app; start_server; login

db() { sqlite3 "$APP/storage/database.sqlite" "$1"; }
shares_csrf() { curl -s -b "$JAR" -c "$JAR" "$BASE/admin/shares" | csrf_from; }
bulk() { curl -s -o /dev/null -w '%{redirect_url}' -b "$JAR" -c "$JAR" "$@" "$BASE/admin/shares-delete"; }

printf 'Contenu de test\n' >"$WORK/a.txt"
for n in 1 2 3; do api_finish "$(upload_file "$WORK/a.txt" "f$n.txt")" >/dev/null; done
ids=($(db 'SELECT id FROM shares ORDER BY id'))
check "3 partages créés" 3 "${#ids[@]}"

check "sans session → login" 1 "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST -F "share_ids[]=${ids[0]}" "$BASE/admin/shares-delete" | grep -c '/admin/login')"
check "jeton invalide → refus" 1 "$(bulk -F csrf_token=faux -F "share_ids[]=${ids[0]}" | grep -c 'error=session')"
check "jeton invalide : rien supprimé" 3 "$(db 'SELECT COUNT(*) FROM shares')"
check "sélection vide → refus" 1 "$(bulk -F "csrf_token=$(shares_csrf)" | grep -c 'error=selection')"
check "ids forgés ignorés → refus" 1 "$(bulk -F "csrf_token=$(shares_csrf)" -F 'share_ids[]=1 OR 1=1' -F 'share_ids[]=-1' | grep -c 'error=selection')"

check "suppression de 2 partages" 1 "$(bulk -F "csrf_token=$(shares_csrf)" -F "share_ids[]=${ids[0]}" -F "share_ids[]=${ids[1]}" -F 'share_ids[]=99999' | grep -c 'deleted=2')"
check "1 partage restant" "${ids[2]}" "$(db 'SELECT id FROM shares')"
check "fichiers des partages supprimés effacés" 1 "$(ls "$APP/storage/files" | grep -vc gitkeep)"
check "suppressions journalisées" 2 "$(db "SELECT COUNT(*) FROM audit_logs WHERE action='delete_share'")"
check "message de confirmation" 1 "$(curl -s -b "$JAR" "$BASE/admin/shares?deleted=2" | grep -c '2 partages supprimés')"

finish_report
