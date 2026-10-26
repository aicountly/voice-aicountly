#!/usr/bin/env bash
# atomic-entry-test.sh — this API's real front controller under an atomic deploy (bin/ops/atomic-switch.sh).
#
#   bash server-php/tests/atomic-entry-test.sh        (needs php, curl and rsync; no database)
#
# api/ becomes a symlink to a release folder. A PHP worker may still resolve api/ to the release it saw
# before a switch (realpath cache), so index.php starts with the atomic entry guard, which hands the
# request to the CURRENT release's index.php. That second index.php declares the same functions: they
# must be declared conditionally, or the hand-over is a "Cannot redeclare" fatal error — an empty 500 at
# exactly the moment the guard exists for. Proved here, with two copies of this very API:
#   1. the plain api/ folder answers (release A)
#   2. atomic-switch.sh converts it and the symlink answers (release B)
#   3. a request a stale worker opens through release A's index.php is answered by release B, not a 500
#   4. a request whose script resolves to itself is answered by that release (the guard does nothing)
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API="$(cd "$HERE/.." && pwd)"
SWITCH="$API/bin/ops/atomic-switch.sh"
PORT="${PORT:-$((34000 + RANDOM % 2000))}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/atomic-entry.XXXXXX")"
export HOME="$WORK/home"
DOC="$HOME/public_html/site.example"
pass=0 fail=0
ok() { echo "PASS $*"; pass=$((pass + 1)); }
no() { echo "FAIL $*"; fail=$((fail + 1)); }
PID=""
cleanup() { [ -n "$PID" ] && kill "$PID" 2>/dev/null; rm -rf "${WORK:?}"; }
trap cleanup EXIT

grep -q '^// >>> aicountly atomic entry' "$API/index.php" && ok "index.php carries the atomic entry guard" || no "index.php has no atomic entry guard"

# A copy of the API as a deploy uploads it (no tests/, no .env), marked with the release it is.
release() { # dir name
  mkdir -p "$1"
  rsync -a --exclude='/tests/' --exclude='/.env' --exclude='/vendor/' "$API/" "$1/"
  [ -d "$API/vendor" ] && ln -s "$API/vendor" "$1/vendor"
  # Says which release's index.php ran past the guard.
  sed -i "s#^// <<< aicountly atomic entry <<<\$#&\nheader('X-Test-Release: $2');#" "$1/index.php"
  grep -q "X-Test-Release: $2" "$1/index.php" || { echo "could not mark $1/index.php"; exit 1; }
}
mkdir -p "$DOC"
release "$DOC/api" A
printf 'APP_ENV=local\n' > "$DOC/api/.env"
release "$WORK/stage" B

cat > "$WORK/router.php" <<'EOF'
<?php
// As Apache/LiteSpeed run it: SCRIPT_FILENAME is the path THROUGH the api symlink. X-Test-Entry names
// the file the worker actually opened (a stale realpath cache opens the previous release's copy).
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($path, '/api/') !== 0) { http_response_code(404); return true; }
$_SERVER['SCRIPT_NAME'] = '/api/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/api/index.php';
$entry = (string) ($_SERVER['HTTP_X_TEST_ENTRY'] ?? $_SERVER['SCRIPT_FILENAME']);
chdir(dirname($entry));
require $entry;
return true;
EOF
php -S "127.0.0.1:$PORT" -t "$DOC" "$WORK/router.php" > "$WORK/server.log" 2>&1 &
PID=$!
URL="http://127.0.0.1:$PORT/api/__atomic_entry_probe__"
for _ in $(seq 1 50); do curl -s -o /dev/null "$URL" && break; sleep 0.1; done

ask() { # [entry] -> "<status> <release>" ; body in $WORK/body
  local h=()
  [ -n "${1:-}" ] && h=(-H "X-Test-Entry: $1")
  curl -s -m 20 -D "$WORK/headers" -o "$WORK/body" -w '%{http_code}' "${h[@]}" "$URL" > "$WORK/code"
  printf '%s %s' "$(cat "$WORK/code")" "$(tr -d '\r' < "$WORK/headers" | sed -n 's/^X-Test-Release: //Ip' | tail -n 1)"
}
answered() { # "<status> <release>" expected-release
  local code="${1%% *}" rel="${1#* }"
  [ "$rel" = "$2" ] && [ "$code" != 500 ] && [ "$code" != 000 ] && ! grep -qi 'redeclare\|fatal error' "$WORK/body"
}

got="$(ask)"
answered "$got" A && ok "the plain api/ folder answers ($got)" || { no "plain folder: $got"; head -c 400 "$WORK/body"; echo; }

out="$(bash "$SWITCH" --src="$WORK/stage" --live="$DOC/api" --revision=test-b 2>&1)"
rc=$?
[ "$rc" = 0 ] && [ -L "$DOC/api" ] && ok "atomic-switch converted api/ to a release symlink" || { no "atomic-switch exit $rc"; echo "$out"; }
echo "$out" | grep -q 'converted from a plain folder' && ok "the switch log says it converted the plain folder" || no "no conversion line: $out"
got="$(ask)"
answered "$got" B && ok "the symlink answers from the new release ($got)" || { no "after the switch: $got"; head -c 400 "$WORK/body"; echo; }

old="$(ls -d "$HOME"/aicountly-releases/site.example-api/releases/*-pre-atomic 2>/dev/null | head -n 1)"
if [ -n "$old" ] && [ -f "$old/index.php" ]; then
  got="$(ask "$old/index.php")"
  answered "$got" B && ok "a stale worker opening the previous release's index.php is answered by the current release ($got)" \
    || { no "stale worker: $got"; head -c 600 "$WORK/body"; echo; }
else
  no "the previous release was not kept as pre-atomic"
fi
cur="$(readlink "$DOC/api")"
got="$(ask "$cur/index.php")"
answered "$got" B && ok "a script that resolves to itself runs as it is ($got)" || no "current release direct: $got"
grep -qi 'redeclare' "$WORK/server.log" && no "the server log has a redeclare error" || ok "no redeclare error in the server log"

echo "atomic-entry: $pass passed, $fail failed"
[ "$fail" = 0 ]
