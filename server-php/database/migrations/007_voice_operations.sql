-- Migration 007: Usage and cost, budgets, external write tracking, idempotency
-- and voicemail.

-- ---------------------------------------------------------------------------
-- Usage and cost
-- ---------------------------------------------------------------------------
-- MONEY IS INTEGER MINOR UNITS. Paise for INR, cents for USD. Never a float:
-- summing 0.1 + 0.2 across ten thousand calls is how a usage page and an
-- invoice stop agreeing.
--
-- `basis` is the distinction §10 asks for and the UI must keep: an estimate we
-- computed from a rate card is not the same claim as a charge the provider has
-- confirmed, and a total that mixes them silently is a total nobody can defend.
CREATE TABLE IF NOT EXISTS voice_usage_entries (
    usage_id       BIGSERIAL PRIMARY KEY,
    cmp_id         BIGINT      NOT NULL,
    bo_id          BIGINT      NOT NULL DEFAULT 0,
    call_id        BIGINT      NULL REFERENCES voice_calls (call_id) ON DELETE SET NULL,
    connection_id  BIGINT      NULL REFERENCES voice_provider_connections (connection_id) ON DELETE SET NULL,
    campaign_id    BIGINT      NULL REFERENCES voice_campaigns (campaign_id) ON DELETE SET NULL,
    category       TEXT        NOT NULL,
    -- voice_minutes | ai_processing | transcription | tts | number_rental | other
    quantity       NUMERIC(14,4) NOT NULL DEFAULT 0,
    unit           TEXT        NOT NULL DEFAULT 'minute', -- minute | second | character | month | request
    -- The rate card version this was priced against, so a later rate change
    -- does not silently restate last month.
    rate_version   TEXT        NULL,
    rate_minor     BIGINT      NOT NULL DEFAULT 0,   -- per unit, minor units
    amount_minor   BIGINT      NOT NULL DEFAULT 0,
    currency       TEXT        NOT NULL DEFAULT 'INR',
    basis          TEXT        NOT NULL DEFAULT 'estimated', -- estimated | provider_confirmed
    provider_ref   TEXT        NULL,
    occurred_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_voice_usage_cmp_time
    ON voice_usage_entries (cmp_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS ix_voice_usage_cmp_category
    ON voice_usage_entries (cmp_id, category, occurred_at DESC);
CREATE INDEX IF NOT EXISTS ix_voice_usage_campaign
    ON voice_usage_entries (campaign_id, occurred_at) WHERE campaign_id IS NOT NULL;
-- A provider's own usage line, imported once. Stops a re-import double-counting.
CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_usage_provider_ref
    ON voice_usage_entries (connection_id, provider_ref)
    WHERE provider_ref IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Budgets
-- ---------------------------------------------------------------------------
-- ENFORCED SERVER-SIDE. The browser shows the bar; the dispatcher refuses.
CREATE TABLE IF NOT EXISTS voice_budget_policies (
    policy_id      BIGSERIAL PRIMARY KEY,
    cmp_id         BIGINT      NOT NULL,
    scope          TEXT        NOT NULL DEFAULT 'company', -- company | campaign
    scope_ref      TEXT        NULL,
    period         TEXT        NOT NULL DEFAULT 'monthly', -- daily | monthly
    limit_minor    BIGINT      NOT NULL DEFAULT 0,
    currency       TEXT        NOT NULL DEFAULT 'INR',
    warn_percent   INTEGER     NOT NULL DEFAULT 80,
    -- 'warn' still allows the call and raises an alert; 'block' refuses it.
    on_exceed      TEXT        NOT NULL DEFAULT 'warn',    -- warn | block
    is_active      BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_budget_policies
    ON voice_budget_policies (cmp_id, scope, COALESCE(scope_ref, ''), period);

-- ---------------------------------------------------------------------------
-- External operations
-- ---------------------------------------------------------------------------
-- THE RECORD OF EVERY WRITE VOICE MAKES INTO ANOTHER PRODUCT.
--
-- This table exists for one case above all: we sent a booking to Calendar, the
-- connection timed out, and we do not know whether it landed. That is not a
-- failure and must not be retried blindly — a blind retry is how one caller
-- gets two appointments. The row goes to 'unknown' and is reconciled by asking
-- Calendar, through Calendar's API, what it has against our correlation id.
--
-- It stores the operation's STATE and the external REFERENCE. It never stores
-- the foreign record.
CREATE TABLE IF NOT EXISTS voice_external_operations (
    operation_id    BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT      NOT NULL,
    bo_id           BIGINT      NOT NULL DEFAULT 0,
    target_app      TEXT        NOT NULL,          -- calendar | crm | contacts | pay | …
    operation       TEXT        NOT NULL,          -- create_event | create_task | …
    -- Ours, sent to the owner API so a reconcile can find what we sent.
    correlation_id  TEXT        NOT NULL,
    idempotency_key TEXT        NULL,
    call_id         BIGINT      NULL REFERENCES voice_calls (call_id) ON DELETE SET NULL,
    commitment_id   BIGINT      NULL REFERENCES voice_commitments (commitment_id) ON DELETE SET NULL,
    callback_id     BIGINT      NULL REFERENCES voice_callbacks (callback_id) ON DELETE SET NULL,
    request_summary JSONB       NOT NULL DEFAULT '{}'::jsonb, -- what we asked for, not what they hold
    status          TEXT        NOT NULL DEFAULT 'pending',
    -- pending | succeeded | failed | unknown | reconciled | abandoned
    -- Present ONLY once the owner API acknowledged authoritatively.
    external_ref    TEXT        NULL,
    http_status     INTEGER     NULL,
    error_code      TEXT        NULL,
    error_message   TEXT        NULL,
    attempts        INTEGER     NOT NULL DEFAULT 0,
    last_attempt_at TIMESTAMPTZ NULL,
    next_check_at   TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by      TEXT        NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_external_operations_correlation
    ON voice_external_operations (cmp_id, target_app, correlation_id);
-- The reconciler's index: everything whose outcome we are not sure of.
CREATE INDEX IF NOT EXISTS ix_voice_external_operations_unknown
    ON voice_external_operations (status, next_check_at)
    WHERE status IN ('pending', 'unknown');

-- ---------------------------------------------------------------------------
-- Idempotency
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voice_idempotency_keys (
    key_id          BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT      NOT NULL,
    scope           TEXT        NOT NULL,
    idempotency_key TEXT        NOT NULL,
    response_status INTEGER     NOT NULL,
    response_body   JSONB       NOT NULL DEFAULT '{}'::jsonb,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_idempotency_keys
    ON voice_idempotency_keys (cmp_id, scope, idempotency_key);
CREATE INDEX IF NOT EXISTS ix_voice_idempotency_created
    ON voice_idempotency_keys (created_at);

-- ---------------------------------------------------------------------------
-- Voicemail
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voice_voicemails (
    voicemail_id   BIGSERIAL PRIMARY KEY,
    cmp_id         BIGINT      NOT NULL,
    bo_id          BIGINT      NOT NULL DEFAULT 0,
    call_id        BIGINT      NULL REFERENCES voice_calls (call_id) ON DELETE SET NULL,
    recording_id   BIGINT      NULL REFERENCES voice_recordings (recording_id) ON DELETE SET NULL,
    number_id      BIGINT      NULL REFERENCES voice_numbers (number_id) ON DELETE SET NULL,
    queue_id       BIGINT      NULL REFERENCES voice_queues (queue_id) ON DELETE SET NULL,
    from_e164      TEXT        NULL,
    contact_ref    TEXT        NULL,
    duration_seconds INTEGER   NOT NULL DEFAULT 0,
    status         TEXT        NOT NULL DEFAULT 'new',  -- new | heard | actioned | archived
    heard_by       TEXT        NULL,
    heard_at       TIMESTAMPTZ NULL,
    callback_id    BIGINT      NULL REFERENCES voice_callbacks (callback_id) ON DELETE SET NULL,
    received_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_voice_voicemails_cmp_status
    ON voice_voicemails (cmp_id, status, received_at DESC);

-- ---------------------------------------------------------------------------
-- Integration configuration
-- ---------------------------------------------------------------------------
-- Which Aicountly products this company has switched on for Voice, and their
-- last observed health. It holds NO business data from those products — only
-- whether Voice is allowed to call them and whether the last call worked.
CREATE TABLE IF NOT EXISTS voice_integrations (
    integration_id  BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT      NOT NULL,
    app             TEXT        NOT NULL,          -- calendar | contacts | crm | pay | lobby | books | billing
    enabled         BOOLEAN     NOT NULL DEFAULT FALSE,
    config          JSONB       NOT NULL DEFAULT '{}'::jsonb,
    status          TEXT        NOT NULL DEFAULT 'not_configured',
    -- not_configured | configured | connected | degraded | unavailable | forbidden
    status_detail   TEXT        NULL,
    checked_at      TIMESTAMPTZ NULL,
    last_ok_at      TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_integrations ON voice_integrations (cmp_id, app);
