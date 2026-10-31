#!/usr/bin/env bash
# Run the Voice tests against a throwaway PostgreSQL database and a local stub
# standing in for Manage, Contacts, Calendar, Appointments, CRM and the voice gateway.
#
#   server-php/tests/run.sh
#
# Requires: php with pdo_pgsql and curl, and a reachable PostgreSQL.
#
# The .env it writes is a TEST .env and overwrites any local one — which is why
# this script exists rather than the instructions saying "set these by hand".
#
# NOTHING here reaches a real product. No call is placed, no campaign is
# launched, no message is sent.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# This suite truncates tables: it runs against the test database in the .env written below and never against the one
# Console names for a real deployment, so a developer's exported Console settings are dropped here.
# tests/console_database.php sets what it needs for itself.
unset CONSOLE_API_URL CONSOLE_DB_DETAILS_KEY

DB_NAME="${TEST_DB_NAME:-voice_test}"
DB_USER="${TEST_DB_USER:-voice_test}"
DB_PASS="${TEST_DB_PASS:-voice_test}"
DB_HOST="${TEST_DB_HOST:-127.0.0.1}"
DB_PORT="${TEST_DB_PORT:-5432}"
STUB_PORT="${STUB_PORT:-8794}"

cat > "$ROOT/.env" <<ENVEOF
APP_ENV=local
APP_PRODUCT_KEY=voice
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS

# Every cross-app client and the gateway point at the one stub.
MANAGE_API_BASE=http://127.0.0.1:$STUB_PORT
CONTACTS_API_BASE=http://127.0.0.1:$STUB_PORT
CALENDAR_API_BASE=http://127.0.0.1:$STUB_PORT
CRM_API_BASE=http://127.0.0.1:$STUB_PORT
PAY_API_BASE=http://127.0.0.1:$STUB_PORT
APPOINTMENTS_API_BASE=http://127.0.0.1:$STUB_PORT
VOICE_GATEWAY_URL=http://127.0.0.1:$STUB_PORT
VOICE_GATEWAY_KEY=test-gateway-key

# Enabled so the capability, calendar and CRM paths are exercised for real.
VOICE_GATEWAY_ENABLED=1
VOICE_BROWSER_CALLING_ENABLED=1
VOICE_TRANSCRIPTION_ENABLED=1
VOICE_CALENDAR_ENABLED=1
VOICE_CRM_ENABLED=1
CALENDAR_SERVICE_KEY=test-calendar-service-key-0123456789
CRM_SERVICE_KEY=test-crm-service-key-0123456789
VOICE_APPOINTMENTS_ENABLED=1
APPOINTMENTS_SERVICE_KEY=test-appointments-service-key-0123456789

# The Voice Gateway's key for an AI agent's action steps.
SERVICE_KEYS=gateway:test-gateway-inbound-key-0123456789

# A real 32-byte key, so credential encryption is exercised rather than skipped.
CREDENTIAL_ENCRYPTION_KEY=$(head -c 32 /dev/urandom | base64)

# Recording storage, so playback signing is exercised.
RECORDING_STORAGE_URL=http://127.0.0.1:$STUB_PORT/recordings
RECORDING_SIGNING_KEY=test-recording-signing-key

# A rate card, so usage estimates are produced.
RATE_OUTBOUND_MINOR_PER_MINUTE=120
RATE_INBOUND_MINOR_PER_MINUTE=60
RATE_CARD_VERSION=test-v1

VOICE_SUPPORTED_LANGUAGES=en,hi
ENVEOF

php "$ROOT/bin/migrate.php" > /dev/null

# php -l does not resolve class names: a class used without its `use` is a 500 only when the line runs.
php "$ROOT/tests/class_names.php"

# Where the database name and username come from (Console's SaaS Database Details, or DB_NAME / DB_USER) and what is
# said when they cannot be had: the resolver, /api/health, bin/db-check.php and bin/migrate.php. Needs the migrated
# database above; the cases that take a migration record away put it back.
php "$ROOT/tests/console_database.php"

# bin/migrate.php against a throwaway database of its own (needs CREATEDB, skipped without it): --dry-run on a
# fresh database, every migration replaying on a database that already has the schema, and --baseline.
php "$ROOT/tests/migrate.php"

php -S "127.0.0.1:$STUB_PORT" "$ROOT/tests/stub/router.php" > /dev/null 2>&1 &
STUB_PID=$!
trap 'kill $STUB_PID 2>/dev/null || true' EXIT

# Wait for the stub rather than sleeping a guessed amount.
for _ in $(seq 1 40); do
  if curl -fsS --noproxy '*' "http://127.0.0.1:$STUB_PORT/api/health" > /dev/null 2>&1; then break; fi
  sleep 0.25
done

php "$ROOT/tests/integration.php"

# Plan limits (CONTRACTS §3.6): voice.max_numbers and voice.max_agents from the company's plan in Manage's
# companyinfo — 402 PLAN_LIMIT_REACHED on adding one more, never on a call, a read or a service key.
php "$ROOT/tests/plan_limits.php"

# Usage metering: what is reported to Manage's usage meter for each ended call,
# and that a callback or a hang-up answers the same with metering off or broken.
php "$ROOT/tests/metering.php"
# Manage's company lifecycle (lib/company-lifecycle): the reviewed manifest against this schema (strict, company
# and user scopes), the allow-list, the receiver, the 423 write gate, a signed purge end to end with the stored
# recordings deleted, and the account purge.
php "$ROOT/tests/lifecycle.php"

# The audit trail: writer context, diff/secret handling, immutability, fail-closed rows, coverage through the real
# endpoints, and the read API (filters, scoping, permissions, CSV).
php "$ROOT/tests/audit_trail.php"

# E8-ID / MY-AW-04: a portal outage is 503 + Retry-After, never 401 (real HTTP through index.php).
E8ID_API_PORT="${E8ID_API_PORT:-$((STUB_PORT+40))}" E8ID_PORTAL_FAULT_PORT="${E8ID_PORTAL_FAULT_PORT:-$((STUB_PORT+41))}" php "$ROOT/tests/e8id_portal_outage.php"

# E8-ID / F155: Voice's MessagingClient against the REAL Messaging service API (skipped, and says so,
# unless E8ID_MESSAGING_SERVER_PHP names a messaging-aicountly/server-php checkout and E8ID_MSG_DB_* a
# throwaway database for it).
env -u DB_NAME -u DB_USER -u DB_PASS php "$ROOT/tests/e8id_messaging_contract.php"

# The same-origin relays to Manage's company registry (/api/manage/v1/*) and the portal's logout:
# the allowlist against web/src/company-access/companyRegistryClient.ts, the refusals, and real HTTP
# through index.php against a fake Manage (headers, body, status, 504 when Manage does not answer).
MANAGE_RELAY_API_PORT="${MANAGE_RELAY_API_PORT:-$((STUB_PORT+50))}" php "$ROOT/tests/manage_relay.php"

# Atomic deploys (bin/ops/atomic-switch.sh): the real index.php across two releases — a worker that opened the
# previous release's copy is handed to the current one without a "Cannot redeclare" fatal error. No database.
bash "$ROOT/tests/atomic-entry-test.sh"

# No blank errors: an uncaught error answers JSON with a message, a code and the reference (the caller's
# X-Request-Id), and the same reference is in the log line with the exception. No database.
bash "$ROOT/tests/error-reference-test.sh"
