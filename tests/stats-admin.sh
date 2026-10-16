#!/usr/bin/env bash
# Téléchargements de l'admin non comptés, sans notification ; nettoyage de l'historique (migration 4)
source "$(dirname "$0")/lib.sh"
setup_app; start_server; login

db() { sqlite3 "$APP/storage/database.sqlite" "$1"; }
printf 'Contenu de test\n' >"$WORK/a.txt"
slug="$(body_of "$(api_finish "$(upload_file "$WORK/a.txt" a.txt)" "$(upload_file "$WORK/a.txt" b.txt)")" | jget slug)"
file_id="$(db 'SELECT id FROM files LIMIT 1')"
downloads() { db 'SELECT COUNT(*) FROM downloads'; }

check "fichier téléchargé par l'admin" 200 "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/d/$slug/file/$file_id")"
check "ZIP téléchargé par l'admin" 200 "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/d/$slug/zip")"
check "téléchargements de l'admin non comptés" 0 "$(downloads)"
check "aucun journal de téléchargement pour l'admin" 0 "$(db "SELECT COUNT(*) FROM audit_logs WHERE action='download'")"

db "UPDATE audit_logs SET ip = '203.0.113.7' WHERE action = 'login_success'"
curl -s -o /dev/null "$BASE/d/$slug/file/$file_id"
curl -s -o /dev/null "$BASE/d/$slug/zip"
check "visiteur : fichier et ZIP comptés" 2 "$(downloads)"
check "visiteur : ZIP enregistré sans fichier" 1 "$(db 'SELECT COUNT(*) FROM downloads WHERE file_id IS NULL')"

db "UPDATE audit_logs SET ip = '127.0.0.1' WHERE action = 'login_success'"
db "INSERT INTO views (share_id, ip) SELECT id, '198.51.100.9' FROM shares"
db "INSERT INTO views (share_id, ip) SELECT id, '127.0.0.1' FROM shares"
db "PRAGMA user_version = 3"
curl -s -o /dev/null "$BASE/admin/login"
check "migration 4 appliquée" 4 "$(db 'PRAGMA user_version')"
check "historique : vues de l'IP admin retirées" 0 "$(db "SELECT COUNT(*) FROM views WHERE ip = '127.0.0.1'")"
check "historique : vues des visiteurs gardées" 1 "$(db "SELECT COUNT(*) FROM views WHERE ip = '198.51.100.9'")"
check "historique : téléchargements de l'IP admin retirés" 0 "$(downloads)"

finish_report
