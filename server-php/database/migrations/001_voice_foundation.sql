-- Migration 001: Voice foundation — tenancy, provider connections, numbers,
-- agents, teams, permissions, settings and the audit trail.
--
-- EVERY table here is Voice-owned. None of them mirrors another product's
-- master: a company lives in Manage, a contact lives in Contacts, a calendar
-- event lives in Calendar. What is stored here is either a calling-domain
-- record or an external ID pointing at the product that owns the record.
--
-- cmp_id / bo_id are Manage's company and branch identifiers, stored as plain
-- references. There is no voice_companies table and there must never be one.

-- ---------------------------------------------------------------------------
-- Telephony provider connections
-- ---------------------------------------------------------------------------
-- One row per configured carrier/gateway account. Credentials are encrypted at
-- rest by the application (see src/Crypto.php) and never leave the server.
CREATE TABLE IF NOT EXISTS voice_provider_connections (
    connection_id    BIGSERIAL PRIMARY KEY,
    cmp_id           BIGINT      NOT NULL,
    bo_id            BIGINT      NOT NULL DEFAULT 0,
    provider         TEXT        NOT NULL,          -- adapter key: 'sip_gateway', 'null', …
    label            TEXT        NOT NULL,
    role             TEXT        NOT NULL DEFAULT 'primary',   -- primary | backup
    -- What this connection can actually do, as reported by the adapter at
    -- configuration time. The UI reads THIS, never a hardcoded feature list, so
    -- a provider that cannot transfer does not show a Transfer button.
    capabilities     JSONB       NOT NULL DEFAULT '{}'::jsonb,
    -- Encrypted credential blob. Never selected into an API response.
    credentials_enc  TEXT        NULL,
    -- Non-secret settings: hostnames, caller-id policy, concurrency ceiling.
    config           JSONB       NOT NULL DEFAULT '{}'::jsonb,
    max_concurrent   INTEGER     NOT NULL DEFAULT 0,  -- 0 = no product-side ceiling
    status           TEXT        NOT NULL DEFAULT 'configured', -- configured | connected | degraded | unavailable | disabled
    status_detail    TEXT        NULL,
    status_checked_at TIMESTAMPTZ NULL,
    last_tested_at   TIMESTAMPTZ NULL,
    last_test_result JSONB       NULL,
    is_active        BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by       TEXT        NULL
);

CREATE INDEX IF NOT EXISTS ix_voice_provider_connections_cmp
    ON voice_provider_connections (cmp_id, is_active, role);

-- ---------------------------------------------------------------------------
-- Business numbers
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voice_numbers (
    number_id        BIGSERIAL PRIMARY KEY,
    cmp_id           BIGINT      NOT NULL,
    bo_id            BIGINT      NOT NULL DEFAULT 0,
    connection_id    BIGINT      NULL REFERENCES voice_provider_connections (connection_id) ON DELETE SET NULL,
    e164             TEXT        NOT NULL,          -- +918066XXXXXX
    label            TEXT        NOT NULL DEFAULT '',
    country          TEXT        NOT NULL DEFAULT 'IN',
    number_type      TEXT        NOT NULL DEFAULT 'landline', -- landline | mobile | tollfree | virtual
    team_id          BIGINT      NULL,
    inbound_flow_id  BIGINT      NULL,
    -- 'inherit' defers to the company recording policy in voice_settings.
    recording_policy TEXT        NOT NULL DEFAULT 'inherit',  -- inherit | always | never | on_consent
    business_hours   JSONB       NOT NULL DEFAULT '{}'::jsonb,
    -- Provider's own identifier for this number, so a rename here does not
    -- break the mapping.
    provider_ref     TEXT        NULL,
    routing_status   TEXT        NOT NULL DEFAULT 'active',   -- active | paused | unrouted | failed
    status_detail    TEXT        NULL,
    is_active        BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- One company cannot hold the same number twice; two companies legitimately can
-- (a number can be ported between tenants over time).
CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_numbers_cmp_e164
    ON voice_numbers (cmp_id, e164);
CREATE INDEX IF NOT EXISTS ix_voice_numbers_cmp_active
    ON voice_numbers (cmp_id, is_active);

-- ---------------------------------------------------------------------------
-- Teams and agents
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voice_teams (
    team_id     BIGSERIAL PRIMARY KEY,
    cmp_id      BIGINT      NOT NULL,
    bo_id       BIGINT      NOT NULL DEFAULT 0,
    name        TEXT        NOT NULL,
    description TEXT        NOT NULL DEFAULT '',
    is_active   BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_voice_teams_cmp ON voice_teams (cmp_id, is_active);

-- A Voice agent is a CALLING PROFILE attached to an AICOUNTLY user, not a copy
-- of that user. The name, email and photo belong to the portal and are read
-- from it; what lives here is the extension, the skills used for routing and
-- the presence state, which are Voice's own.
CREATE TABLE IF NOT EXISTS voice_agents (
    agent_id        BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT      NOT NULL,
    bo_id           BIGINT      NOT NULL DEFAULT 0,
    user_uuid       TEXT        NOT NULL,          -- portal identity; the master is my.aicountly.com
    extension       TEXT        NULL,
    voice_role      TEXT        NOT NULL DEFAULT 'agent',  -- agent | supervisor | admin
    skills          JSONB       NOT NULL DEFAULT '[]'::jsonb,
    languages       JSONB       NOT NULL DEFAULT '[]'::jsonb,
    capabilities    JSONB       NOT NULL DEFAULT '{}'::jsonb, -- browser_calling, sip_device, …
    -- Presence. `presence_expires_at` is what makes a stale state visible: an
    -- agent whose browser died is not "available", they are unknown, and the
    -- routing engine must be able to tell those apart.
    presence        TEXT        NOT NULL DEFAULT 'offline',  -- available | busy | wrap_up | away | offline
    presence_reason TEXT        NULL,
    presence_since  TIMESTAMPTZ NULL,
    presence_expires_at TIMESTAMPTZ NULL,
    is_active       BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_agents_cmp_user
    ON voice_agents (cmp_id, user_uuid);
CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_agents_cmp_extension
    ON voice_agents (cmp_id, extension) WHERE extension IS NOT NULL;
CREATE INDEX IF NOT EXISTS ix_voice_agents_presence
    ON voice_agents (cmp_id, presence) WHERE is_active;

CREATE TABLE IF NOT EXISTS voice_team_members (
    team_member_id BIGSERIAL PRIMARY KEY,
    cmp_id         BIGINT      NOT NULL,
    team_id        BIGINT      NOT NULL REFERENCES voice_teams (team_id) ON DELETE CASCADE,
    agent_id       BIGINT      NOT NULL REFERENCES voice_agents (agent_id) ON DELETE CASCADE,
    member_role    TEXT        NOT NULL DEFAULT 'member',  -- member | lead
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_team_members
    ON voice_team_members (team_id, agent_id);

-- ---------------------------------------------------------------------------
-- Permissions
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voice_permission_profiles (
    profile_id  BIGSERIAL PRIMARY KEY,
    cmp_id      BIGINT      NOT NULL,
    name        TEXT        NOT NULL,
    description TEXT        NOT NULL DEFAULT '',
    permissions JSONB       NOT NULL DEFAULT '[]'::jsonb,
    is_active   BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_permission_profiles_cmp_name
    ON voice_permission_profiles (cmp_id, name);

CREATE TABLE IF NOT EXISTS voice_permission_assignments (
    assignment_id BIGSERIAL PRIMARY KEY,
    cmp_id        BIGINT      NOT NULL,
    profile_id    BIGINT      NOT NULL REFERENCES voice_permission_profiles (profile_id) ON DELETE CASCADE,
    user_uuid     TEXT        NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by    TEXT        NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_permission_assignments
    ON voice_permission_assignments (cmp_id, profile_id, user_uuid);
CREATE INDEX IF NOT EXISTS ix_voice_permission_assignments_user
    ON voice_permission_assignments (cmp_id, user_uuid);

-- ---------------------------------------------------------------------------
-- Settings and policy
-- ---------------------------------------------------------------------------
-- One row per company. The policy fields here are what §14 calls
-- "tenant-configurable policies": they are settings a business chooses, and the
-- product does not claim any of them constitutes legal compliance.
CREATE TABLE IF NOT EXISTS voice_settings (
    cmp_id                   BIGINT PRIMARY KEY,
    timezone                 TEXT        NOT NULL DEFAULT 'Asia/Kolkata',
    currency                 TEXT        NOT NULL DEFAULT 'INR',
    -- Recording and disclosure
    recording_policy         TEXT        NOT NULL DEFAULT 'never',  -- never | always | on_consent
    recording_disclosure     BOOLEAN     NOT NULL DEFAULT TRUE,
    ai_disclosure            BOOLEAN     NOT NULL DEFAULT TRUE,
    ai_disclosure_text       TEXT        NOT NULL DEFAULT '',
    -- Retention, in days. 0 = keep until deleted by hand.
    recording_retention_days INTEGER     NOT NULL DEFAULT 0,
    transcript_retention_days INTEGER    NOT NULL DEFAULT 0,
    -- Calling windows, stored as local minutes past midnight in `timezone`.
    calling_window_start_min INTEGER     NOT NULL DEFAULT 540,   -- 09:00
    calling_window_end_min   INTEGER     NOT NULL DEFAULT 1200,  -- 20:00
    calling_window_days      JSONB       NOT NULL DEFAULT '[1,2,3,4,5,6]'::jsonb,
    -- Operations
    supervisor_monitoring    BOOLEAN     NOT NULL DEFAULT FALSE,
    campaign_approval_required BOOLEAN   NOT NULL DEFAULT TRUE,
    max_concurrent_calls     INTEGER     NOT NULL DEFAULT 0,      -- 0 = provider ceiling only
    wrap_up_seconds          INTEGER     NOT NULL DEFAULT 60,
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_by               TEXT        NULL
);

-- Suppression / do-not-call. Voice-owned, because it records who asked NOT to
-- be called by this company — that is a calling-domain fact, not a contact
-- profile, and it must survive a contact being deleted in Contacts.
CREATE TABLE IF NOT EXISTS voice_suppressions (
    suppression_id BIGSERIAL PRIMARY KEY,
    cmp_id         BIGINT      NOT NULL,
    e164           TEXT        NOT NULL,
    reason         TEXT        NOT NULL DEFAULT 'opt_out',  -- opt_out | dnc_registry | complaint | manual
    source         TEXT        NOT NULL DEFAULT 'manual',
    note           TEXT        NOT NULL DEFAULT '',
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by     TEXT        NULL,
    expires_at     TIMESTAMPTZ NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_suppressions_cmp_number
    ON voice_suppressions (cmp_id, e164);

-- ---------------------------------------------------------------------------
-- Audit
-- ---------------------------------------------------------------------------
-- Append-only. Recording access, exports, campaign launches, provider changes
-- and retention edits all land here, because those are the actions somebody
-- will need to account for later.
CREATE TABLE IF NOT EXISTS voice_audit_events (
    audit_id     BIGSERIAL PRIMARY KEY,
    cmp_id       BIGINT      NOT NULL,
    bo_id        BIGINT      NOT NULL DEFAULT 0,
    occurred_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    actor_uuid   TEXT        NULL,
    actor_kind   TEXT        NOT NULL DEFAULT 'user',  -- user | service | system | provider
    actor_app    TEXT        NULL,
    action       TEXT        NOT NULL,                 -- voice.recording.played, voice.campaign.launched, …
    entity_type  TEXT        NULL,
    entity_id    TEXT        NULL,
    -- Never a signed URL, never transcript content, never a credential.
    detail       JSONB       NOT NULL DEFAULT '{}'::jsonb,
    correlation_id TEXT      NULL,
    ip_hash      TEXT        NULL
);

CREATE INDEX IF NOT EXISTS ix_voice_audit_cmp_time
    ON voice_audit_events (cmp_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS ix_voice_audit_entity
    ON voice_audit_events (cmp_id, entity_type, entity_id);
