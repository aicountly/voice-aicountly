#!/usr/bin/env bash
# post-deploy-checks.sh — is the live Voice really this app, and is it the build just deployed?
#
# Run by the deploy workflows after a deploy, and by verify-live.yml to check the live app without
# deploying anything. Every check goes through verify-live.sh, which passes only on the app's real
# answer, never on the host's anti-bot page, and repeats the request from the server (VERIFY_SSH)
# when the runner is shown that page.
#
# Usage: scripts/ci/post-deploy-checks.sh <production|sandbox>
#
# Environment:
#   VERIFY_SSH         command prefix that runs one command on the server ("ssh deploy-target"
#                      in the workflows); see verify-live.sh. Set: the must-not-be-served probes
#                      are asked from the server only.
#   EXPECTED_ENTRY     the hashed entry script of the build just deployed, e.g.
#                      assets/index-C5tx8mVh.js from web/dist/index.html. Empty (checking without
#                      a deploy): the page's <title> is checked instead.
#   EXPECTED_REVISION  the commit just deployed. The Voice API does not report the revision it
#                      runs, so it is not compared; the entry script stands for the build.
#   VERIFY_BASE_URL    tests only: check this origin (e.g. http://127.0.0.1:18777) instead of the
#                      environment's real one.
set -uo pipefail

target="${1:-}"
case "$target" in
  production) origin="https://voice.aicountly.com" ;;
  sandbox) origin="https://voice.gh.aicountly.com" ;;
  *) echo "usage: $0 <production|sandbox>" >&2; exit 2 ;;
esac
base="${VERIFY_BASE_URL:-$origin}"
verify="$(cd "$(dirname "$0")" && pwd)/verify-live.sh"
entry="${EXPECTED_ENTRY:-}"

echo "Checking Voice ${target} at ${base}"
if [ -n "${EXPECTED_REVISION:-}" ]; then
  echo "The Voice API does not report its revision, so ${EXPECTED_REVISION} is not compared; the web entry script stands for the build."
fi

failed=0
# check <verify-live.sh arguments...>: one check; a failure is counted, the rest still run.
check() {
  bash "$verify" "$@" || failed=$((failed + 1))
}
# check_with_hint <hint> <verify-live.sh arguments...>: the same, and prints <hint> when it warned.
check_with_hint() {
  local hint="$1" out
  shift
  out="$(bash "$verify" "$@")" || failed=$((failed + 1))
  printf '%s\n' "$out"
  case "$out" in
    *'::warning title=Post-deploy check::'*) echo "  ${hint}" ;;
  esac
}

# It is the Voice API (fatal). "degraded" (HTTP 503) means its database is unreachable or not
# migrated: configuration on the server, not this deploy, so it only warns. A console_* reason in
# .data.database.reason means the database name and username could not be had from Console
# (CONSOLE_API_URL / CONSOLE_DB_DETAILS_KEY in api/.env); php bin/db-check.php in api/ says which.
check_with_hint "degraded: the database is unreachable or not migrated. Check the DB_* values in api/.env and the migrations; a console_* .data.database.reason means the name and username could not be had from Console (CONSOLE_API_URL / CONSOLE_DB_DETAILS_KEY) - run php bin/db-check.php in api/." \
  json "Voice API (${target})" "${base}/api/health" \
  '.data.app == "Voice"' \
  '.data.status == "ok"'

# The server says it is the environment this workflow deployed to (fatal). Sibling hosts — the
# Manage tenant check included — follow AIC_ENVIRONMENT in api/.env and never the request's Host,
# so a sandbox .env copied from the production template would otherwise talk to production.
check json "Voice API environment (${target})" "${base}/api/health" \
  ".data.env == \"${target}\""

# The web root serves the build just deployed, or at least the Voice page (fatal).
if [ -n "$entry" ]; then
  check page "Voice web (${target})" "${base}/" "$entry"
else
  check page "Voice web (${target})" "${base}/" '<title>Voice · Aicountly</title>'
fi

# Must never be served: what the deploy leaves under the document root that is a secret, a log,
# SQL, tests, scripts or dependency manifests. Read-only GETs of the first 64 KB, never of a .php
# file under tests/, bin/ or scripts/ (a GET would run it); a failure logs the status, type and
# size of what was served, never its content. /.git/HEAD may instead get the SPA's own page.
# With VERIFY_SSH they are asked from the server itself, never from the runner (verify-live.sh,
# absent): the host's WAF graylists a runner that asks for these, and its SSH with it.
for path in /api/.env /api/.env.example /api/error_log \
  /api/database/migrations/001_voice_foundation.sql /api/tests/run.sh /api/bin/; do
  check absent "Voice ${path} must not be served (${target})" "${base}${path}"
done
check absent "Voice /.git/HEAD must not be served (${target})" "${base}/.git/HEAD" \
  '<title>Voice · Aicountly</title>'

if [ "$failed" -gt 0 ]; then
  echo "${failed} post-deploy check(s) failed for Voice ${target}."
  exit 1
fi
echo "All post-deploy checks passed for Voice ${target}."
