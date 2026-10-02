-- Migration 011: how the campaign worker reads Contacts without a person.
--
-- The worker used to call Contacts with an EMPTY session, got 401, called that
-- "directory unavailable" and re-queued every attempt every ten minutes,
-- forever (G18#1). Contacts now issues company-scoped DELEGATION GRANTS
-- (contract v1 §3.9): a signed-in user with launch permission asks for one
-- while they configure, start or resume a campaign; the worker presents it.
--
-- Only the token's ENCRYPTED form is kept (Crypto, CREDENTIAL_ENCRYPTION_KEY),
-- with its expiry; Contacts keeps only a hash. A grant past its expiry, or one
-- Contacts refuses, pauses the campaign with a reason until a person renews it.
--
-- Idempotent: safe to apply twice.

CREATE TABLE IF NOT EXISTS voice_directory_grants (
    delegation_id  BIGSERIAL PRIMARY KEY,
    cmp_id         BIGINT      NOT NULL,
    campaign_id    BIGINT      NULL REFERENCES voice_campaigns (campaign_id) ON DELETE CASCADE,
    purpose        TEXT        NOT NULL,
    grant_id       TEXT        NOT NULL,
    token_enc      TEXT        NOT NULL,
    scopes         JSONB       NOT NULL DEFAULT '[]'::jsonb,
    actor_uuid     TEXT        NOT NULL,           -- the person who issued it (Contacts records them too)
    environment    TEXT        NULL,
    expires_at     TIMESTAMPTZ NOT NULL,
    status         TEXT        NOT NULL DEFAULT 'active',  -- active | replaced | revoked | invalid
    status_detail  TEXT        NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ended_at       TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS ix_voice_directory_grants_campaign
    ON voice_directory_grants (cmp_id, campaign_id, status);

-- One live grant per campaign: a renewal replaces the previous one.
CREATE UNIQUE INDEX IF NOT EXISTS uq_voice_directory_grants_active
    ON voice_directory_grants (campaign_id) WHERE status = 'active';

-- Deferrals are counted, so a directory outage backs off and ends, instead of
-- re-queueing at +10 minutes forever.
ALTER TABLE voice_campaign_attempts ADD COLUMN IF NOT EXISTS defer_count INTEGER NOT NULL DEFAULT 0;
