-- Migration 003: Queues, ring groups and call flows.

CREATE TABLE IF NOT EXISTS voice_queues (
    queue_id        BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT      NOT NULL,
    bo_id           BIGINT      NOT NULL DEFAULT 0,
    name            TEXT        NOT NULL,
    kind            TEXT        NOT NULL DEFAULT 'queue',  -- queue | ring_group
    strategy        TEXT        NOT NULL DEFAULT 'longest_idle',
    -- longest_idle | round_robin | simultaneous | skill_based | linear
    required_skills JSONB       NOT NULL DEFAULT '[]'::jsonb,
    languages       JSONB       NOT NULL DEFAULT '[]'::jsonb,
    ring_seconds    INTEGER     NOT NULL DEFAULT 20,
    wrap_up_seconds INTEGER     NOT NULL DEFAULT 30,
    -- What happens when nobody answers. A queue with no overflow destination is
    -- a queue that drops callers, so the flow validator treats a missing one as
    -- an error rather than a default.
    timeout_seconds INTEGER     NOT NULL DEFAULT 120,
    overflow_type   TEXT        NULL,   -- queue | voicemail | number | ai_agent | hangup
    overflow_ref    TEXT        NULL,
    max_waiting     INTEGER     NOT NULL DEFAULT 0,   -- 0 = unbounded
    announcements   JSONB       NOT NULL DEFAULT '{}'::jsonb,
    business_hours  JSONB       NOT NULL DEFAULT '{}'::jsonb,
    holiday_routing JSONB       NOT NULL DEFAULT '{}'::jsonb,
    -- Escalation from an AI agent to a human, and the other way for overflow.
    ai_agent_id     BIGINT      NULL,
    escalation_queue_id BIGINT  NULL,
    is_active       BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_queues_cmp_name ON voice_queues (cmp_id, name);
CREATE INDEX IF NOT EXISTS ix_voice_queues_cmp ON voice_queues (cmp_id, is_active);

CREATE TABLE IF NOT EXISTS voice_queue_members (
    queue_member_id BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT      NOT NULL,
    queue_id        BIGINT      NOT NULL REFERENCES voice_queues (queue_id) ON DELETE CASCADE,
    agent_id        BIGINT      NULL REFERENCES voice_agents (agent_id) ON DELETE CASCADE,
    team_id         BIGINT      NULL REFERENCES voice_teams (team_id) ON DELETE CASCADE,
    priority        INTEGER     NOT NULL DEFAULT 0,
    is_active       BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    -- A membership row names an agent or a team, never both and never neither.
    CONSTRAINT ck_voice_queue_member_target
        CHECK ((agent_id IS NULL) <> (team_id IS NULL))
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_queue_members_agent
    ON voice_queue_members (queue_id, agent_id) WHERE agent_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_queue_members_team
    ON voice_queue_members (queue_id, team_id) WHERE team_id IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Call flows
-- ---------------------------------------------------------------------------
-- A flow is a NAMED, VERSIONED, EXECUTABLE configuration — not a picture. The
-- executable graph is in voice_call_flow_versions.definition; the editor draws
-- that same graph rather than a decorative diagram beside it.
CREATE TABLE IF NOT EXISTS voice_call_flows (
    flow_id          BIGSERIAL PRIMARY KEY,
    cmp_id           BIGINT      NOT NULL,
    bo_id            BIGINT      NOT NULL DEFAULT 0,
    name             TEXT        NOT NULL,
    description      TEXT        NOT NULL DEFAULT '',
    published_version_id BIGINT  NULL,
    draft_version_id BIGINT      NULL,
    is_active        BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_call_flows_cmp_name ON voice_call_flows (cmp_id, name);

CREATE TABLE IF NOT EXISTS voice_call_flow_versions (
    version_id     BIGSERIAL PRIMARY KEY,
    flow_id        BIGINT      NOT NULL REFERENCES voice_call_flows (flow_id) ON DELETE CASCADE,
    cmp_id         BIGINT      NOT NULL,
    version_no     INTEGER     NOT NULL,
    status         TEXT        NOT NULL DEFAULT 'draft',  -- draft | published | archived
    -- { "entry": "<node id>", "nodes": { "<id>": {type, config, next…} } }
    definition     JSONB       NOT NULL DEFAULT '{}'::jsonb,
    -- The validator's verdict at the moment of the last save. Publishing is
    -- refused while this holds an error.
    validation     JSONB       NOT NULL DEFAULT '{}'::jsonb,
    published_at   TIMESTAMPTZ NULL,
    published_by   TEXT        NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by     TEXT        NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_call_flow_versions
    ON voice_call_flow_versions (flow_id, version_no);
