#!/usr/bin/env bash
# Banc d'essai : copie jetable du repo, config de test, serveur PHP intégré,
# session admin ouverte dans un cookie jar.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_USER="admin"
TEST_PASS="test-password-$$"
UPLOAD_MAX="${UPLOAD_MAX:-2M}"
POST_MAX="${POST_MAX:-3M}"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/send-test.XXXXXX")"
APP="$WORK/app"
JAR="$WORK/cookies.txt"
PORT="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0");echo explode(":",stream_socket_get_name($s,false))[1];')"
BASE="http://127.0.0.1:$PORT"
SERVER_PID=""
FAILURES=0

teardown() {
    [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true
    rm -rf "$WORK"
}
trap teardown EXIT

setup_app() {
    mkdir -p "$APP"
    rsync -a --exclude .git --exclude config.php --exclude 'storage/database.sqlite' \
        --exclude 'storage/files/*' --exclude 'storage/tmp' --exclude tests \
        "$REPO_ROOT/" "$APP/"
    local hash
    hash="$(php -r 'echo password_hash($argv[1], PASSWORD_ARGON2ID);' "$TEST_PASS")"
    php -r '
        $c = file_get_contents($argv[1]);
        $c = str_replace("CHANGEZ_MOI_avec_une_cle_unique", bin2hex(random_bytes(32)), $c);
        $c = str_replace("define(\x27ADMIN_USERNAME\x27, \x27CHANGEZ_MOI\x27)", "define(\x27ADMIN_USERNAME\x27, \x27" . $argv[3] . "\x27)", $c);
        $c = preg_replace("/define\(\x27ADMIN_PASSWORD_HASH\x27, \x27[^\x27]*\x27\)/", "define(\x27ADMIN_PASSWORD_HASH\x27, " . var_export($argv[2], true) . ")", $c);
        file_put_contents($argv[4], $c);
    ' "$APP/config.example.php" "$hash" "$TEST_USER" "$APP/config.php"
}

start_server() {
    (cd "$APP" && php -d upload_max_filesize="$UPLOAD_MAX" -d post_max_size="$POST_MAX" \
        -S "127.0.0.1:$PORT" index.php >"$WORK/server.log" 2>&1) &
    SERVER_PID=$!
    for _ in $(seq 1 50); do
        curl -s -o /dev/null "$BASE/admin/login" && return 0
        sleep 0.1
    done
    echo "serveur PHP injoignable" >&2
    cat "$WORK/server.log" >&2
    exit 1
}

csrf_from() {
    sed -n 's/.*name="csrf_token" value="\([a-f0-9]*\)".*/\1/p' | head -1
}

login() {
    local token
    token="$(curl -s -c "$JAR" -b "$JAR" "$BASE/admin/login" | csrf_from)"
    curl -s -o /dev/null -c "$JAR" -b "$JAR" \
        --data-urlencode "csrf_token=$token" --data-urlencode "username=$TEST_USER" \
        --data-urlencode "password=$TEST_PASS" "$BASE/admin/login"
    local page
    page="$(curl -s -c "$JAR" -b "$JAR" "$BASE/admin/upload")"
    CSRF="$(csrf_from <<<"$page")"
    UPLOAD_TOKEN="$(sed -n 's/.*data-upload-token="\([a-f0-9]*\)".*/\1/p' <<<"$page" | head -1)"
    [[ -n "$CSRF" && -n "$UPLOAD_TOKEN" ]] || { echo "login échoué" >&2; exit 1; }
}

check() {
    local label="$1" expected="$2" actual="$3"
    if [[ "$actual" == "$expected" ]]; then
        echo "PASS $label"
    else
        echo "FAIL $label : attendu [$expected], obtenu [$actual]"
        FAILURES=$((FAILURES + 1))
    fi
}

finish_report() {
    if [[ "$FAILURES" -gt 0 ]]; then
        echo "$FAILURES échec(s)"
        echo "--- log serveur ---"; tail -20 "$WORK/server.log"
        exit 1
    fi
    echo "tout est vert"
}

# --- API d'upload découpé ---

jget() {
    php -r '
        $v = json_decode(stream_get_contents(STDIN), true);
        foreach (explode(".", $argv[1]) as $k) { $v = is_array($v) ? ($v[$k] ?? null) : null; }
        if (is_array($v)) { echo json_encode($v); } elseif (is_bool($v)) { echo $v ? "true" : "false"; } else { echo (string) $v; }
    ' "$1"
}

api() {
    local action="$1"; shift
    curl -s -b "$JAR" -H "X-CSRF-Token: $UPLOAD_TOKEN" -w '\n%{http_code}' "$@" "$BASE/admin/upload/$action"
}

body_of() { sed '$d' <<<"$1"; }
code_of() { tail -1 <<<"$1"; }

api_start() {
    local name="$1" size="$2"
    api start --data-urlencode "name=$name" --data-urlencode "size=$size"
}

send_chunk() {
    local id="$1" index="$2" file="$3" chunk_size="$4"
    local part="$WORK/chunk-$id-$index"
    dd if="$file" of="$part" bs="$chunk_size" skip="$index" count=1 2>/dev/null
    api chunk -F "upload_id=$id" -F "index=$index" -F "chunk=@$part;type=application/octet-stream"
}

# Envoie un fichier complet, ordre des morceaux donné par $3 (asc|desc)
upload_file() {
    local file="$1" name="$2" order="${3:-asc}"
    local r id chunk_size total
    r="$(api_start "$name" "$(wc -c <"$file" | tr -d ' ')")"
    id="$(body_of "$r" | jget upload_id)"
    chunk_size="$(body_of "$r" | jget chunk_size)"
    total="$(body_of "$r" | jget total_chunks)"
    [[ -n "$id" ]] || { echo "start KO : $r" >&2; return 1; }
    local indexes; indexes="$(seq 0 $((total - 1)))"
    [[ "$order" == desc ]] && indexes="$(seq $((total - 1)) -1 0)"
    for i in $indexes; do
        r="$(send_chunk "$id" "$i" "$file" "$chunk_size")"
        [[ "$(code_of "$r")" == 200 ]] || { echo "chunk $i KO : $r" >&2; return 1; }
    done
    echo "$id"
}

api_finish() {
    local args=(-F slug_type=random -F title= -F password= -F custom_slug=)
    for id in "$@"; do args+=(-F "upload_ids[]=$id"); done
    api finish "${args[@]}"
}
