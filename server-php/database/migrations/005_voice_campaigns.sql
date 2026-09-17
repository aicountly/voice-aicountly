-- Migration 005: Campaigns, their audience references, attempts and callbacks.
--
-- THE AUDIENCE IS NOT STORED HERE. voice_campaign_audience_refs holds external
-- IDs or a saved filter definition; the contact's name, number and eligibility
-- are read from Contacts/CRM through their live APIs at execution time and
-- re-checked then. That is the difference between "we called the number they
-- have now" and "we called the number they had when somebody built the list".

CREATE TABLE IF NOT EXISTS voice_campaigns (
    campaign_id     BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT      NOT NULL,
    bo_id           BIGINT      NOT NULL DEFAULT 0,
    name            TEXT        NOT NULL,
    description     TEXT        NOT NULL DEFAULT '',
    mode            TEXT        NOT NULL,
    -- preview | power | announcement | tts | ivr | ai_conversation
    -- | appointment_reminder | requested_callback | renewal_followup
    status          TEXT        NOT NULL DEFAULT 'draft',
    -- draft | ready | scheduled | running | paused | completed | cancelled | failed
    status_reason   TEXT        NULL,

    -- What it calls with
    connection_id   BIGINT      NULL REFERENCES voice_provider_connections (connection_id) ON DELETE SET NULL,
    number_id       BIGINT      NULL REFERENCES voice_numbers (number_id) ON DELETE SET NULL,
    ai_agent_id     BIGINT      NULL REFERENCES voice_ai_agents (ai_agent_id) ON DELETE SET NULL,
    ai_version_id   BIGINT      NULL,
    flow_id         BIGINT      NULL REFERENCES voice_call_flows (flow_id) ON DELETE SET NULL,
    script          JSONB       NOT NULL DEFAULT '{}'::jsonb,
    team_id         BIGINT      NULL REFERENCES voice_teams (team_id) ON DELETE SET NULL,

    -- When it may call. Local minutes past midnight in `timezone`.
    timezone        TEXT        NOT NULL DEFAULT 'Asia/Kolkata',
    window_start_min INTEGER    NOT NULL DEFAULT 600,
    window_end_min  INTEGER     NOT NULL DEFAULT 1140,
    window_days     JSONB       NOT NULL DEFAULT '[1,2,3,4,5]'::jsonb,
    scheduled_start TIMESTAMPTZ NULL,
    scheduled_end   TIMESTAMPTZ NULL,

    -- How fast. Enforced server-side by the worker, never by the browser.
    max_concurrent  INTEGER     NOT NULL DEFAULT 1,
    calls_per_minute INTEGER    NOT NULL DEFAULT 10,
    max_attempts    INTEGER     NOT NULL DEFAULT 2,
    retry_after_minutes INTEGER NOT NULL DEFAULT 240,

    -- Budget ceiling for this campaign in minor currency units (paise for INR).
    budget_minor    BIGINT      NOT NULL DEFAULT 0,   -- 0 = no campaign-level cap
    spent_minor     BIGINT      NOT NULL DEFAULT 0,

    -- Launch readiness, recomputed by /validate and stored so the launch check
    -- and the screen agree about what is outstanding.
    readiness       JSONB       NOT NULL DEFAULT '{}'::jsonb,
    approved_at     TIMESTAMPTZ NULL,
    approved_by     TEXT        NULL,

    started_at      TIMESTAMPTZ NULL,
    paused_at       TIMESTAMPTZ NULL,
    completed_at    TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by      TEXT        NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_campaigns_cmp_name ON voice_campaigns (cmp_id, name);
CREATE INDEX IF NOT EXISTS ix_voice_campaigns_cmp_status
    ON voice_campaigns (cmp_id, status, updated_at DESC);
-- The worker's claim index: which campaigns are due to dispatch right now.
CREATE INDEX IF NOT EXISTS ix_voice_campaigns_runnable
    ON voice_campaigns (status, scheduled_start)
    WHERE status IN ('running', 'scheduled');

-- ---------------------------------------------------------------------------
-- Audience references
-- ---------------------------------------------------------------------------
-- An ID, or a saved query. NEVER a copied contact.
CREATE TABLE IF NOT EXISTS voice_campaign_audience_refs (
    audience_ref_id BIGSERIAL PRIMARY KEY,
    campaign_id     BIGINT      NOT NULL REFERENCES voice_campaigns (campaign_id) ON DELETE CASCADE,
    cmp_id          BIGINT      NOT NULL,
    source          TEXT        NOT NULL,           -- contacts | crm | filter
    -- For source='filter', the query definition that is re-run at execution.
    filter_definition JSONB     NULL,
    -- For an explicit selection, the owner product's id. Nothing else.
    external_ref    TEXT        NULL,
    -- Resolution state, so a member whose number the owner API no longer
    -- returns is visibly skipped rather than silently dropped.
    resolution      TEXT        NOT NULL DEFAULT 'pending',
    -- pending | resolved | ineligible | suppressed | unreachable | no_number
    resolution_detail TEXT      NULL,
    resolved_at     TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_voice_campaign_audience_campaign
    ON voice_campaign_audience_refs (campaign_id, resolution);
CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_campaign_audience_external
    ON voice_campaign_audience_refs (campaign_id, source, external_ref)
    WHERE external_ref IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Attempts
-- ---------------------------------------------------------------------------
-- One row per dial attempt. The unique index is the duplicate-dial guard: two
-- workers that both claim the same audience member cannot both insert attempt 1.
CREATE TABLE IF NOT EXISTS voice_campaign_attempts (
    attempt_id      BIGSERIAL PRIMARY KEY,
    campaign_id     BIGINT      NOT NULL REFERENCES voice_campaigns (campaign_id) ON DELETE CASCADE,
    audience_ref_id BIGINT      NOT NULL REFERENCES voice_campaign_audience_refs (audience_ref_id) ON DELETE CASCADE,
    cmp_id          BIGINT      NOT NULL,
    attempt_no      INTEGER     NOT NULL DEFAULT 1,
    call_id         BIGINT      NULL REFERENCES voice_calls (call_id) ON DELETE SET NULL,
    -- The number actually dialled, as evidence of what this campaign did.
    dialled_e164    TEXT        NULL,
    status          TEXT        NOT NULL DEFAULT 'queued',
    -- queued | dispatching | dialling | connected | completed | no_answer
    -- | busy | failed | skipped | cancelled
    outcome         TEXT        NULL,
    skip_reason     TEXT        NULL,   -- suppressed | outside_window | no_number | budget | ineligible
    scheduled_for   TIMESTAMPTZ NULL,
    dispatched_at   TIMESTAMPTZ NULL,
    completed_at    TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_campaign_attempts
    ON voice_campaign_attempts (campaign_id, audience_ref_id, attempt_no);
CREATE INDEX IF NOT EXISTS ix_voice_campaign_attempts_due
    ON voice_campaign_attempts (campaign_id, status, scheduled_for);

-- ---------------------------------------------------------------------------
-- Callbacks
-- ---------------------------------------------------------------------------
-- A Voice-owned CALL ATTEMPT PLAN: who to ring back, when, and why. It is not a
-- calendar event. If a callback also needs to occupy time in somebody's diary,
-- that event is created through Calendar's API and only its reference is stored
-- in calendar_event_ref.
CREATE TABLE IF NOT EXISTS voice_callbacks (
    callback_id     BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT      NOT NULL,
    bo_id           BIGINT      NOT NULL DEFAULT 0,
    source_call_id  BIGINT      NULL REFERENCES voice_calls (call_id) ON DELETE SET NULL,
    campaign_id     BIGINT      NULL REFERENCES voice_campaigns (campaign_id) ON DELETE SET NULL,
    contact_ref     TEXT        NULL,              -- Contacts id, nothing more
    e164            TEXT        NOT NULL,
    reason          TEXT        NOT NULL DEFAULT '',
    priority        TEXT        NOT NULL DEFAULT 'normal',  -- high | normal | low
    due_at          TIMESTAMPTZ NULL,
    assigned_agent_id BIGINT    NULL REFERENCES voice_agents (agent_id) ON DELETE SET NULL,
    queue_id        BIGINT      NULL REFERENCES voice_queues (queue_id) ON DELETE SET NULL,
    status          TEXT        NOT NULL DEFAULT 'open',
    -- open | scheduled | in_progress | completed | cancelled | failed
    attempts        INTEGER     NOT NULL DEFAULT 0,
    max_attempts    INTEGER     NOT NULL DEFAULT 3,
    last_attempt_at TIMESTAMPTZ NULL,
    completed_call_id BIGINT    NULL REFERENCES voice_calls (call_id) ON DELETE SET NULL,
    -- Calendar's id for the event, when one was created. Nothing about the
    -- event itself is stored — the time is read from Calendar when shown.
    calendar_event_ref TEXT     NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by      TEXT        NULL
);

CREATE INDEX IF NOT EXISTS ix_voice_callbacks_cmp_status
    ON voice_callbacks (cmp_id, status, due_at);
CREATE INDEX IF NOT EXISTS ix_voice_callbacks_agent
    ON voice_callbacks (cmp_id, assigned_agent_id, status);
-- "Callbacks due" on the Command Centre reads exactly this.
CREATE INDEX IF NOT EXISTS ix_voice_callbacks_due
    ON voice_callbacks (cmp_id, due_at)
    WHERE status IN ('open', 'scheduled');
