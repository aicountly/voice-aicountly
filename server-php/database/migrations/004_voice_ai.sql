-- Migration 004: AI voice agents, their immutable versions, and test runs.
--
-- VERSIONS ARE IMMUTABLE ONCE PUBLISHED. A live call pins the version it began
-- with (voice_calls.ai_version_id), so a rollback changes what NEW callers get
-- and never rewrites what a caller in progress was actually told.

CREATE TABLE IF NOT EXISTS voice_ai_agents (
    ai_agent_id    BIGSERIAL PRIMARY KEY,
    cmp_id         BIGINT      NOT NULL,
    bo_id          BIGINT      NOT NULL DEFAULT 0,
    name           TEXT        NOT NULL,
    role           TEXT        NOT NULL DEFAULT '',
    description    TEXT        NOT NULL DEFAULT '',
    status         TEXT        NOT NULL DEFAULT 'draft',
    -- draft | tested | published | paused | archived
    published_version_id BIGINT NULL,
    draft_version_id     BIGINT NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by     TEXT        NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_ai_agents_cmp_name ON voice_ai_agents (cmp_id, name);
CREATE INDEX IF NOT EXISTS ix_voice_ai_agents_cmp ON voice_ai_agents (cmp_id, status);

CREATE TABLE IF NOT EXISTS voice_ai_agent_versions (
    version_id     BIGSERIAL PRIMARY KEY,
    ai_agent_id    BIGINT      NOT NULL REFERENCES voice_ai_agents (ai_agent_id) ON DELETE CASCADE,
    cmp_id         BIGINT      NOT NULL,
    version_no     INTEGER     NOT NULL,
    status         TEXT        NOT NULL DEFAULT 'draft',  -- draft | published | archived

    persona        JSONB       NOT NULL DEFAULT '{}'::jsonb, -- name, tone, description
    languages      JSONB       NOT NULL DEFAULT '[]'::jsonb,
    voice_config   JSONB       NOT NULL DEFAULT '{}'::jsonb, -- tts voice id, speed
    knowledge      JSONB       NOT NULL DEFAULT '[]'::jsonb, -- source refs, not copied documents

    -- ACTION PERMISSIONS, per action: allowed | confirm_with_caller | handoff | denied.
    -- This is an ALLOWLIST the server enforces. A prompt, a knowledge document
    -- or a caller's own words can never add to it.
    action_permissions JSONB   NOT NULL DEFAULT '{}'::jsonb,

    flow_id        BIGINT      NULL REFERENCES voice_call_flows (flow_id) ON DELETE SET NULL,
    flow_version_id BIGINT     NULL,

    guardrails     JSONB       NOT NULL DEFAULT '{}'::jsonb,
    -- silence_timeout_seconds, max_clarifications, barge_in, handover_target…

    validation     JSONB       NOT NULL DEFAULT '{}'::jsonb,
    published_at   TIMESTAMPTZ NULL,
    published_by   TEXT        NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by     TEXT        NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_ai_agent_versions
    ON voice_ai_agent_versions (ai_agent_id, version_no);

-- Test runs record what ACTUALLY happened in a rehearsal. A readiness badge on
-- the Studio screen is rendered from these rows; there is no hardcoded "passed".
CREATE TABLE IF NOT EXISTS voice_ai_test_runs (
    test_run_id    BIGSERIAL PRIMARY KEY,
    ai_agent_id    BIGINT      NOT NULL REFERENCES voice_ai_agents (ai_agent_id) ON DELETE CASCADE,
    version_id     BIGINT      NULL REFERENCES voice_ai_agent_versions (version_id) ON DELETE SET NULL,
    cmp_id         BIGINT      NOT NULL,
    scenario       TEXT        NOT NULL,
    -- SIMULATED. A rehearsal never places a call and never writes to another
    -- product; the fixtures it runs against are declared here.
    mode           TEXT        NOT NULL DEFAULT 'simulated',
    status         TEXT        NOT NULL DEFAULT 'running', -- running | passed | failed | error
    checks         JSONB       NOT NULL DEFAULT '[]'::jsonb,
    transcript     JSONB       NOT NULL DEFAULT '[]'::jsonb,
    failure_reason TEXT        NULL,
    started_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at    TIMESTAMPTZ NULL,
    started_by     TEXT        NULL
);

CREATE INDEX IF NOT EXISTS ix_voice_ai_test_runs_agent
    ON voice_ai_test_runs (ai_agent_id, started_at DESC);
