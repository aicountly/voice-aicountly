-- Migration 013: short-lived tickets for the live event stream.
--
-- The browser's EventSource cannot set headers, so the stream used to take the
-- user's ses_key in the URL (?access_token=…) — a ~15-minute bearer for every
-- product, in access logs, proxies and history (G18#14, G18#24). The SPA now
-- asks for a ticket over an authenticated POST (Authorization header) and opens
-- the stream with that: single use, 30 seconds, bound to the user, the company
-- and the stream route. Only the ticket's sha256 is stored.
--
-- Idempotent: safe to apply twice.

CREATE TABLE IF NOT EXISTS voice_stream_tickets (
    ticket_hash  TEXT        PRIMARY KEY,
    user_uuid    TEXT        NOT NULL,
    cmp_id       BIGINT      NOT NULL,
    bo_id        BIGINT      NOT NULL DEFAULT 0,
    is_owner     BOOLEAN     NOT NULL DEFAULT FALSE,
    expires_at   TIMESTAMPTZ NOT NULL,
    used_at      TIMESTAMPTZ NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_voice_stream_tickets_expiry ON voice_stream_tickets (expires_at);
