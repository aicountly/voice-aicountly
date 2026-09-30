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
#                      in the workflows); see verify-live.sh.
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

# It is the Voice API (fatal). "degraded" (HTTP 503) means its database is unreachable or not
# migrated: configuration on the server, not this deploy, so it only warns.
check json "Voice API (${target})" "${base}/api/health" \
  '.data.app == "Voice"' \
  '.data.status == "ok"'

# The web root serves the build just deployed, or at least the Voice page (fatal).
if [ -n "$entry" ]; then
  check page "Voice web (${target})" "${base}/" "$entry"
else
  check page "Voice web (${target})" "${base}/" '<title>Voice · Aicountly</title>'
fi

if [ "$failed" -gt 0 ]; then
  echo "${failed} post-deploy check(s) failed for Voice ${target}."
  exit 1
fi
echo "All post-deploy checks passed for Voice ${target}."
