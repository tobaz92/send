#!/usr/bin/env bash
# Les vues de l'admin (session ou IP de connexion récente) ne sont pas comptées
source "$(dirname "$0")/lib.sh"
setup_app; start_server; login

db() { sqlite3 "$APP/storage/database.sqlite" "$1"; }
printf 'Contenu de test\n' >"$WORK/a.txt"
slug="$(body_of "$(api_finish "$(upload_file "$WORK/a.txt" a.txt)")" | jget slug)"
views() { db 'SELECT COUNT(*) FROM views'; }

curl -s -o /dev/null -b "$JAR" "$BASE/d/$slug"
check "admin connecté : vue non comptée" 0 "$(views)"

curl -s -o /dev/null "$BASE/d/$slug"
check "même IP que la connexion admin, sans session : non comptée" 0 "$(views)"

db "UPDATE audit_logs SET created_at = datetime('now', '-31 days') WHERE action = 'login_success'"
curl -s -o /dev/null "$BASE/d/$slug"
check "connexion admin vieille de 31 jours : vue comptée" 1 "$(views)"

db "UPDATE audit_logs SET ip = '203.0.113.7' WHERE action = 'login_success'"
db "DELETE FROM views"
curl -s -o /dev/null "$BASE/d/$slug"
check "visiteur d'une autre IP : vue comptée" 1 "$(views)"

finish_report
