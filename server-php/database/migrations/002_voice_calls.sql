-- Migration 002: Calls, legs, events, recordings and transcripts.
--
-- THE MODEL THAT MATTERS HERE: a call is a logical INTERACTION; a leg is one
-- provider-side channel within it. A caller who reaches an IVR, waits in a
-- queue, speaks to an AI agent and is then transferred to a human has one
-- voice_calls row and four voice_call_legs rows. Counting legs as calls is how
-- a dashboard reports four conversations where a customer had one, and it is
-- why "Calls today" reads from voice_calls and nothing else.

CREATE TABLE IF NOT EXISTS voice_calls (
    call_id          BIGSERIAL PRIMARY KEY,
    call_uuid        TEXT        NOT NULL,          -- stable public identifier
    cmp_id           BIGINT      NOT NULL,
    bo_id            BIGINT      NOT NULL DEFAULT 0,
    connection_id    BIGINT      NULL REFERENCES voice_provider_connections (connection_id) ON DELETE SET NULL,
    number_id        BIGINT      NULL REFERENCES voice_numbers (number_id) ON DELETE SET NULL,

    direction        TEXT        NOT NULL,          -- inbound | outbound | internal
    -- Proven by the credential that created the call, never claimed in a body.
    origin           TEXT        NOT NULL DEFAULT 'AGENT_CONSOLE',

    -- The other party's number. This is CALL EVIDENCE — the number actually
    -- dialled or received — and not a contact record. The contact's name, email
    -- and history live in Contacts and are read from Contacts at the point of
    -- display.
    remote_e164      TEXT        NULL,
    local_e164       TEXT        NULL,

    -- External references. IDs only. No copied names, no copied addresses.
    contact_ref      TEXT        NULL,              -- Contacts: contact id
    crm_lead_ref     TEXT        NULL,              -- CRM: lead/deal id
    campaign_id      BIGINT      NULL,
    ai_agent_id      BIGINT      NULL,
    ai_version_id    BIGINT      NULL,              -- the version PINNED at session start

    queue_id         BIGINT      NULL,
    owner_agent_id   BIGINT      NULL REFERENCES voice_agents (agent_id) ON DELETE SET NULL,
    handled_by       TEXT        NOT NULL DEFAULT 'unassigned', -- ai | human | both | unassigned

    -- Provider lifecycle, not a browser timer. See Domain/CallStateMachine.php.
    state            TEXT        NOT NULL DEFAULT 'initiated',
    -- initiated | queued | ringing | answered | held | transferring | completed
    -- | busy | unanswered | cancelled | failed | unknown
    state_reason     TEXT        NULL,
    -- Set when the provider has gone quiet and we can no longer assert a state.
    state_stale_at   TIMESTAMPTZ NULL,

    initiated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    answered_at      TIMESTAMPTZ NULL,
    ended_at         TIMESTAMPTZ NULL,
    queued_seconds   INTEGER     NOT NULL DEFAULT 0,
    talk_seconds     INTEGER     NOT NULL DEFAULT 0,
    total_seconds    INTEGER     NOT NULL DEFAULT 0,

    -- Outcome, split so the dashboards can stop conflating them. "AI answered"
    -- is not "AI resolved"; see Dashboards/CommandCentreDashboard.php.
    outcome          TEXT        NULL,
    -- ai_completed | human_completed | handover_completed | abandoned
    -- | voicemail | failed | no_answer
    disposition_id   BIGINT      NULL,
    disposition_note TEXT        NULL,
    abandoned        BOOLEAN     NOT NULL DEFAULT FALSE,

    -- Recording and consent are recorded as evidence of what was done.
    recording_state  TEXT        NOT NULL DEFAULT 'none', -- none | recording | stored | refused | failed
    consent_state    TEXT        NOT NULL DEFAULT 'not_applicable', -- not_applicable | announced | granted | refused
    consent_evidence JSONB       NOT NULL DEFAULT '{}'::jsonb,

    language         TEXT        NULL,
    correlation_id   TEXT        NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_calls_uuid ON voice_calls (call_uuid);
-- The index every list screen and every dashboard aggregate starts from.
CREATE INDEX IF NOT EXISTS ix_voice_calls_cmp_time
    ON voice_calls (cmp_id, initiated_at DESC);
CREATE INDEX IF NOT EXISTS ix_voice_calls_cmp_state
    ON voice_calls (cmp_id, state) WHERE ended_at IS NULL;
CREATE INDEX IF NOT EXISTS ix_voice_calls_cmp_queue
    ON voice_calls (cmp_id, queue_id, initiated_at DESC);
CREATE INDEX IF NOT EXISTS ix_voice_calls_campaign
    ON voice_calls (campaign_id, initiated_at DESC) WHERE campaign_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS ix_voice_calls_contact_ref
    ON voice_calls (cmp_id, contact_ref) WHERE contact_ref IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Legs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voice_call_legs (
    leg_id         BIGSERIAL PRIMARY KEY,
    call_id        BIGINT      NOT NULL REFERENCES voice_calls (call_id) ON DELETE CASCADE,
    cmp_id         BIGINT      NOT NULL,
    provider_leg_ref TEXT      NULL,               -- the carrier's own call/leg id
    leg_role       TEXT        NOT NULL,           -- customer | agent | ai | transfer_target | conference
    agent_id       BIGINT      NULL REFERENCES voice_agents (agent_id) ON DELETE SET NULL,
    endpoint       TEXT        NULL,               -- e164, sip uri or 'browser'
    state          TEXT        NOT NULL DEFAULT 'initiated',
    started_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    answered_at    TIMESTAMPTZ NULL,
    ended_at       TIMESTAMPTZ NULL,
    end_reason     TEXT        NULL,
    talk_seconds   INTEGER     NOT NULL DEFAULT 0,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_voice_call_legs_call ON voice_call_legs (call_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_call_legs_provider_ref
    ON voice_call_legs (cmp_id, provider_leg_ref) WHERE provider_leg_ref IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Provider events
-- ---------------------------------------------------------------------------
-- Every signed callback lands here BEFORE it is applied, with the provider's own
-- event id. Two things depend on that:
--
--   1. Deduplication. Carriers retry; the unique index below is what makes a
--      redelivered event a no-op instead of a second state change.
--   2. Ordering. Carriers deliver out of order. `provider_sequence` and
--      `provider_timestamp` are what let CallStateMachine refuse a `ringing`
--      that arrives after `completed` instead of resurrecting a finished call.
CREATE TABLE IF NOT EXISTS voice_call_events (
    event_id           BIGSERIAL PRIMARY KEY,
    cmp_id             BIGINT      NOT NULL,
    call_id            BIGINT      NULL REFERENCES voice_calls (call_id) ON DELETE CASCADE,
    connection_id      BIGINT      NULL REFERENCES voice_provider_connections (connection_id) ON DELETE SET NULL,
    provider_event_id  TEXT        NOT NULL,
    provider_leg_ref   TEXT        NULL,
    event_type         TEXT        NOT NULL,
    provider_timestamp TIMESTAMPTZ NULL,
    provider_sequence  BIGINT      NULL,
    payload            JSONB       NOT NULL DEFAULT '{}'::jsonb,
    applied            BOOLEAN     NOT NULL DEFAULT FALSE,
    applied_at         TIMESTAMPTZ NULL,
    -- Why an event was accepted but not applied: 'out_of_order', 'terminal',
    -- 'unknown_call'. Reading this is how you find out why a call looks stuck.
    skip_reason        TEXT        NULL,
    received_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- The deduplication key. Scoped to the connection because two carriers can
-- legitimately mint the same event id.
CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_call_events_provider
    ON voice_call_events (connection_id, provider_event_id);
CREATE INDEX IF NOT EXISTS ix_voice_call_events_call
    ON voice_call_events (call_id, received_at);

-- ---------------------------------------------------------------------------
-- Participants
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voice_call_participants (
    participant_id BIGSERIAL PRIMARY KEY,
    call_id        BIGINT      NOT NULL REFERENCES voice_calls (call_id) ON DELETE CASCADE,
    cmp_id         BIGINT      NOT NULL,
    party          TEXT        NOT NULL,           -- customer | agent | ai | supervisor
    agent_id       BIGINT      NULL REFERENCES voice_agents (agent_id) ON DELETE SET NULL,
    -- A supervisor who listened is a participant and is recorded as one, because
    -- monitoring a colleague is an act somebody may need to account for.
    monitor_mode   TEXT        NULL,               -- listen | whisper | barge
    joined_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    left_at        TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS ix_voice_call_participants_call
    ON voice_call_participants (call_id);

-- ---------------------------------------------------------------------------
-- Dispositions
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voice_dispositions (
    disposition_id BIGSERIAL PRIMARY KEY,
    cmp_id         BIGINT      NOT NULL,
    code           TEXT        NOT NULL,
    label          TEXT        NOT NULL,
    category       TEXT        NOT NULL DEFAULT 'other', -- resolved | follow_up | qualified | not_interested | other
    requires_note  BOOLEAN     NOT NULL DEFAULT FALSE,
    sort_order     INTEGER     NOT NULL DEFAULT 0,
    is_active      BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_dispositions_cmp_code
    ON voice_dispositions (cmp_id, code);

-- ---------------------------------------------------------------------------
-- Recordings
-- ---------------------------------------------------------------------------
-- The FILE is in private object storage. What is here is the pointer, the
-- retention clock and the access controls. No row here ever holds a playable
-- URL: those are minted on demand, short-lived, and never logged.
CREATE TABLE IF NOT EXISTS voice_recordings (
    recording_id   BIGSERIAL PRIMARY KEY,
    recording_uuid TEXT        NOT NULL,
    call_id        BIGINT      NOT NULL REFERENCES voice_calls (call_id) ON DELETE CASCADE,
    cmp_id         BIGINT      NOT NULL,
    bo_id          BIGINT      NOT NULL DEFAULT 0,
    kind           TEXT        NOT NULL DEFAULT 'call',   -- call | voicemail
    storage_key    TEXT        NULL,                      -- private storage object key
    provider_ref   TEXT        NULL,                      -- carrier-side recording id
    media_type     TEXT        NOT NULL DEFAULT 'audio/mpeg',
    duration_seconds INTEGER   NOT NULL DEFAULT 0,
    bytes          BIGINT      NOT NULL DEFAULT 0,
    status         TEXT        NOT NULL DEFAULT 'pending', -- pending | available | failed | deleted
    -- Retention. `delete_after` is computed when the recording lands, from the
    -- policy in force then; `legal_hold` stops the sweeper regardless.
    delete_after   TIMESTAMPTZ NULL,
    legal_hold     BOOLEAN     NOT NULL DEFAULT FALSE,
    deleted_at     TIMESTAMPTZ NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_recordings_uuid ON voice_recordings (recording_uuid);
CREATE INDEX IF NOT EXISTS ix_voice_recordings_call ON voice_recordings (call_id);
-- The retention sweeper's index: due, not held, not already gone.
CREATE INDEX IF NOT EXISTS ix_voice_recordings_retention
    ON voice_recordings (delete_after)
    WHERE deleted_at IS NULL AND legal_hold = FALSE AND delete_after IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Transcripts
-- ---------------------------------------------------------------------------
-- A transcript is HISTORICAL CONVERSATION EVIDENCE. A customer's name occurring
-- in one is a thing they said, not a contact record, and nothing in this
-- product may read a name out of here to stand in for Contacts.
CREATE TABLE IF NOT EXISTS voice_transcript_segments (
    segment_id     BIGSERIAL PRIMARY KEY,
    call_id        BIGINT      NOT NULL REFERENCES voice_calls (call_id) ON DELETE CASCADE,
    cmp_id         BIGINT      NOT NULL,
    leg_id         BIGINT      NULL REFERENCES voice_call_legs (leg_id) ON DELETE SET NULL,
    sequence_no    INTEGER     NOT NULL,
    speaker        TEXT        NOT NULL,           -- caller | agent | ai | unknown
    speaker_label  TEXT        NULL,
    started_ms     INTEGER     NOT NULL DEFAULT 0, -- offset from call start
    ended_ms       INTEGER     NULL,
    -- `is_final` is the difference between what the recogniser is still
    -- revising and what it has committed to. A UI that shows partial text as
    -- final is a UI that quotes somebody saying something they did not say.
    is_final       BOOLEAN     NOT NULL DEFAULT TRUE,
    language       TEXT        NULL,
    text           TEXT        NOT NULL DEFAULT '',
    translated_text TEXT       NULL,
    translated_to  TEXT        NULL,
    -- Recogniser confidence where the engine reports one. NULL means "not
    -- reported", which is not the same as "certain".
    confidence     NUMERIC(5,4) NULL,
    redacted       BOOLEAN     NOT NULL DEFAULT FALSE,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_transcript_call_seq
    ON voice_transcript_segments (call_id, sequence_no);
CREATE INDEX IF NOT EXISTS ix_voice_transcript_call
    ON voice_transcript_segments (call_id, started_ms);

-- Free-text search over Voice-owned transcripts only. It indexes nothing from
-- Contacts or CRM, so it cannot become a back door into another product's data.
CREATE INDEX IF NOT EXISTS ix_voice_transcript_fts
    ON voice_transcript_segments
    USING GIN (to_tsvector('simple', text));
