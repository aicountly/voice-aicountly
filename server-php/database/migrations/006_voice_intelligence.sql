-- Migration 006: Conversation intelligence — summaries, commitments and quality.

-- An AI summary is a GENERATED ARTEFACT with a version history, not a fact.
-- Editing one does not overwrite what the model wrote; both are kept, because
-- "the summary said X" and "somebody corrected it to Y" are different claims.
CREATE TABLE IF NOT EXISTS voice_call_summaries (
    summary_id     BIGSERIAL PRIMARY KEY,
    call_id        BIGINT      NOT NULL REFERENCES voice_calls (call_id) ON DELETE CASCADE,
    cmp_id         BIGINT      NOT NULL,
    version_no     INTEGER     NOT NULL DEFAULT 1,
    kind           TEXT        NOT NULL DEFAULT 'generated', -- generated | edited
    body           TEXT        NOT NULL DEFAULT '',
    -- Transcript segment ids the summary is drawn from, so every line can be
    -- checked against what was actually said.
    evidence       JSONB       NOT NULL DEFAULT '[]'::jsonb,
    -- 'rules' when no model was configured and the deterministic path produced
    -- it. The screen says which, rather than implying a model ran.
    engine         TEXT        NOT NULL DEFAULT 'rules',
    model          TEXT        NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by     TEXT        NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_call_summaries
    ON voice_call_summaries (call_id, version_no);

-- ---------------------------------------------------------------------------
-- Commitments
-- ---------------------------------------------------------------------------
-- An inferred promise is NOT a task. It is a suggestion with evidence attached,
-- and it becomes a task in CRM only when a person confirms it — at which point
-- external_task_ref holds CRM's id and nothing about the task is copied here.
--
-- `party` keeps the two directions apart: what the CALLER asked for is not the
-- same as what the BUSINESS agreed to do, and a ledger that merges them
-- produces follow-ups nobody ever promised.
CREATE TABLE IF NOT EXISTS voice_commitments (
    commitment_id  BIGSERIAL PRIMARY KEY,
    call_id        BIGINT      NOT NULL REFERENCES voice_calls (call_id) ON DELETE CASCADE,
    cmp_id         BIGINT      NOT NULL,
    bo_id          BIGINT      NOT NULL DEFAULT 0,
    party          TEXT        NOT NULL DEFAULT 'business', -- business | caller
    description    TEXT        NOT NULL,
    owner_agent_id BIGINT      NULL REFERENCES voice_agents (agent_id) ON DELETE SET NULL,
    owner_hint     TEXT        NULL,               -- what was said, when no agent matched
    -- NULL means the call did not settle a date. That is reported as "needs
    -- clarification", never guessed into next Friday.
    due_at         TIMESTAMPTZ NULL,
    due_text       TEXT        NULL,               -- "by Friday", as spoken
    evidence       JSONB       NOT NULL DEFAULT '[]'::jsonb, -- transcript segment ids
    confidence     NUMERIC(5,4) NULL,
    status         TEXT        NOT NULL DEFAULT 'suggested',
    -- suggested | confirmed | rejected | completed
    -- CRM's id for the task, once one was created there. Never a copy of it.
    external_system TEXT       NULL,               -- crm | appointments | …
    external_task_ref TEXT     NULL,
    confirmed_at   TIMESTAMPTZ NULL,
    confirmed_by   TEXT        NULL,
    completed_at   TIMESTAMPTZ NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_voice_commitments_cmp_status
    ON voice_commitments (cmp_id, status, due_at);
CREATE INDEX IF NOT EXISTS ix_voice_commitments_call
    ON voice_commitments (call_id);

-- ---------------------------------------------------------------------------
-- Quality
-- ---------------------------------------------------------------------------
-- Deterministic checks and model assessments are stored in separate columns on
-- purpose. "The agent did not say the disclosure line" is checkable; "the agent
-- sounded impatient" is an opinion, and a rubric that presents both with the
-- same authority is a rubric that gets someone disciplined over a guess.
CREATE TABLE IF NOT EXISTS voice_quality_reviews (
    review_id        BIGSERIAL PRIMARY KEY,
    call_id          BIGINT      NOT NULL REFERENCES voice_calls (call_id) ON DELETE CASCADE,
    cmp_id           BIGINT      NOT NULL,
    rubric_key       TEXT        NOT NULL DEFAULT 'default',
    -- Checks the code can prove from the record: greeting present, disclosure
    -- played, confirmation requested, handover completed.
    deterministic    JSONB       NOT NULL DEFAULT '[]'::jsonb,
    -- Model assessments, clearly labelled as such and never merged above.
    model_assessment JSONB       NOT NULL DEFAULT '[]'::jsonb,
    score            NUMERIC(5,2) NULL,
    reviewer_uuid    TEXT        NULL,
    reviewer_verdict TEXT        NULL,             -- pass | fail | needs_coaching
    reviewer_note    TEXT        NULL,
    -- A reviewer may overturn any of the above; the reason is required so the
    -- override is auditable rather than anonymous.
    override_reason  TEXT        NULL,
    status           TEXT        NOT NULL DEFAULT 'pending', -- pending | reviewed
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    reviewed_at      TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS ix_voice_quality_cmp_status
    ON voice_quality_reviews (cmp_id, status, created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_quality_call_rubric
    ON voice_quality_reviews (call_id, rubric_key);

CREATE TABLE IF NOT EXISTS voice_quality_rubrics (
    rubric_id   BIGSERIAL PRIMARY KEY,
    cmp_id      BIGINT      NOT NULL,
    rubric_key  TEXT        NOT NULL,
    name        TEXT        NOT NULL,
    criteria    JSONB       NOT NULL DEFAULT '[]'::jsonb,
    is_active   BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_quality_rubrics
    ON voice_quality_rubrics (cmp_id, rubric_key);

-- Saved searches for Conversation Intelligence. A user preference, not data.
CREATE TABLE IF NOT EXISTS voice_saved_searches (
    saved_search_id BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT      NOT NULL,
    user_uuid       TEXT        NOT NULL,
    name            TEXT        NOT NULL,
    surface         TEXT        NOT NULL DEFAULT 'intelligence',
    query           JSONB       NOT NULL DEFAULT '{}'::jsonb,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_saved_searches
    ON voice_saved_searches (cmp_id, user_uuid, surface, name);
