#!/usr/bin/env bash
# Run the Voice tests against a throwaway PostgreSQL database and a local stub
# standing in for Manage, Contacts, Calendar, CRM and the voice gateway.
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

php -S "127.0.0.1:$STUB_PORT" "$ROOT/tests/stub/router.php" > /dev/null 2>&1 &
STUB_PID=$!
trap 'kill $STUB_PID 2>/dev/null || true' EXIT

# Wait for the stub rather than sleeping a guessed amount.
for _ in $(seq 1 40); do
  if curl -fsS --noproxy '*' "http://127.0.0.1:$STUB_PORT/api/health" > /dev/null 2>&1; then break; fi
  sleep 0.25
done

php "$ROOT/tests/integration.php"
