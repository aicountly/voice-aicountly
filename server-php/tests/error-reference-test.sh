#!/usr/bin/env bash
# error-reference-test.sh — an uncaught error on an API request is never a blank answer.
#
#   bash server-php/tests/error-reference-test.sh        (needs php and curl; no database)
#
# A copy of this API whose front controller throws right inside its one try block (before the relays),
# served by php -S. The answer must be JSON with a message, a code and a reference id; the reference
# must be the caller's X-Request-Id when that is a plausible id (else a generated 32-hex one); the SAME
# id must be in the server's log line with the exception class, message, file:line, method and path;
# and the exception's own text must never reach the body.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API="$(cd "$HERE/.." && pwd)"
PORT="${PORT:-$((36000 + RANDOM % 2000))}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/error-reference.XXXXXX")"
pass=0 fail=0
ok() { echo "PASS $*"; pass=$((pass + 1)); }
no() { echo "FAIL $*"; fail=$((fail + 1)); }
PID=""
cleanup() { [ -n "$PID" ] && kill "$PID" 2>/dev/null; rm -rf "${WORK:?}"; }
trap cleanup EXIT

DOC="$WORK/doc"
mkdir -p "$DOC/api"
( cd "$API" && tar -cf - --exclude=./tests --exclude=./.env --exclude=./vendor . ) | ( cd "$DOC/api" && tar -xf - )
[ -d "$API/vendor" ] && ln -s "$API/vendor" "$DOC/api/vendor"
printf 'APP_ENV=local\n' > "$DOC/api/.env"
# The fault: the first statement inside the front controller's top-level try.
n="$(grep -n '^try {$' "$DOC/api/index.php" | head -n 1 | cut -d: -f1)"
if [ -z "$n" ]; then no "index.php has no top-level try block"; echo "error-reference: $pass passed, $fail failed"; exit 1; fi
sed -i "${n}a\\    if (isset(\$_SERVER['HTTP_X_TEST_THROW'])) { throw new \\\\RuntimeException('probe-internal-detail-' . \$_SERVER['HTTP_X_TEST_THROW']); }" "$DOC/api/index.php"
php -l "$DOC/api/index.php" > /dev/null || { no "the fault injection broke index.php"; exit 1; }

cat > "$WORK/router.php" <<'EOF'
<?php
$_SERVER['SCRIPT_NAME'] = '/api/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/api/index.php';
chdir($_SERVER['DOCUMENT_ROOT'] . '/api');
require $_SERVER['SCRIPT_FILENAME'];
return true;
EOF
php -d log_errors=1 -d error_log="$WORK/php-error.log" -S "127.0.0.1:$PORT" -t "$DOC" "$WORK/router.php" > "$WORK/server.log" 2>&1 &
PID=$!
URL="http://127.0.0.1:$PORT/api/v1/error-reference-probe"
for _ in $(seq 1 50); do curl -s -o /dev/null "$URL" && break; sleep 0.1; done

logs() { cat "$WORK/php-error.log" "$WORK/server.log" 2>/dev/null; }

# 1. The caller's id is the reference.
rid="webprobe$(date +%s)abc"
code="$(curl -s -m 20 -D "$WORK/h1" -o "$WORK/b1" -w '%{http_code}' -H "X-Request-Id: $rid" -H 'X-Test-Throw: one' "$URL")"
[ "$code" = 500 ] && ok "an uncaught error answers 500" || no "status $code"
php -r 'exit(is_array(json_decode((string) file_get_contents($argv[1]), true)) ? 0 : 1);' "$WORK/b1" && ok "the body is JSON" || { no "the body is not JSON: $(head -c 300 "$WORK/b1")"; }
grep -q "\"request_id\":\"$rid\"" "$WORK/b1" && ok "the body carries the caller's X-Request-Id as request_id" || no "no request_id $rid in: $(head -c 400 "$WORK/b1")"
grep -q '"message":"[^"]' "$WORK/b1" && grep -q '"code":"[a-z_]' "$WORK/b1" && ok "the body has a message and a code" || no "no message/code: $(head -c 400 "$WORK/b1")"
grep -q 'probe-internal-detail' "$WORK/b1" && no "the exception text reached the body" || ok "the exception text is not in the body"
tr -d '\r' < "$WORK/h1" | grep -qi "^X-Request-Id: $rid\$" && ok "the X-Request-Id header names the same reference" || no "no X-Request-Id header"
sleep 0.3
line="$(logs | grep -F -- "$rid" | head -n 1)"
if [ -n "$line" ]; then
  ok "the server log has the reference"
  case "$line" in *RuntimeException*probe-internal-detail-one*) ok "the log line has the exception class and message" ;; *) no "log line lacks class/message: $line" ;; esac
  case "$line" in *index.php:[0-9]*) ok "the log line has file:line" ;; *) no "log line lacks file:line: $line" ;; esac
  case "$line" in *GET\ /api/v1/error-reference-probe*) ok "the log line has the method and path" ;; *) no "log line lacks method+path: $line" ;; esac
else
  no "the reference $rid is in no log line"; logs | tail -n 5
fi

# 2. An implausible id is not trusted: a generated 32-hex one, the same in body and log.
curl -s -m 20 -o "$WORK/b2" -H 'X-Request-Id: bad id!' -H 'X-Test-Throw: two' "$URL" > /dev/null
gen="$(sed -n 's/.*"request_id":"\([^"]*\)".*/\1/p' "$WORK/b2" | head -n 1)"
[[ "$gen" =~ ^[0-9a-f]{32}$ ]] && ok "an implausible X-Request-Id gets a generated 32-hex reference" || no "generated reference: '$gen'"
sleep 0.3
[ -n "$gen" ] && logs | grep -F -- "$gen" | grep -q 'probe-internal-detail-two' && ok "the generated reference is in the log line" || no "generated reference not logged"

echo "error-reference: $pass passed, $fail failed"
[ "$fail" = 0 ]
