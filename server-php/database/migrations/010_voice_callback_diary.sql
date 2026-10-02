-- Migration 010: a callback's diary entry is a projection Voice maintains in
-- Aicountly Calendar under the Calendar Events API v1 contract.
--
-- WHAT IS STORED, AND WHY IT IS NOT A COPY. Calendar owns the event. Voice owns
-- the callback, its due time included: a diary entry Voice writes is product-
-- owned (`managed_by_app`), so nobody can move it from Calendar's side and its
-- time only ever changes because Voice changed it. What Voice keeps is what it
-- needs to address and maintain that one entry:
--
--   calendar_owner_uuid   whose diary it is in. Calendar answers 404 for an
--                         event addressed as anybody else, so the owner is
--                         part of the reference, not a detail of it.
--   calendar_source_ref   the natural key it was created under (callback id,
--                         with a suffix for a second entry after a reassign or
--                         a re-open — a cancelled entry is never revived).
--   calendar_version      the version Calendar last confirmed, sent back as
--                         If-Match so an edit cannot overwrite a newer one.
--   calendar_due_at       the due time Calendar last confirmed the entry at, so
--                         Voice knows whether its projection is behind the
--                         callback. Never shown and never read as "the time":
--                         the callback's own due_at is that.
--   calendar_state/detail what the last attempt established, in words a screen
--                         can show (linked, pending, unknown, deferred, refused,
--                         failed, cancelled, abandoned).
--
-- `exact_time` is the callback's own fact: the caller was promised this time,
-- so the diary write asks Calendar to refuse it when the person is already busy
-- (conflict_policy "reject") instead of double-booking them.

ALTER TABLE voice_callbacks
    ADD COLUMN IF NOT EXISTS exact_time          BOOLEAN     NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS calendar_requested  BOOLEAN     NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS calendar_owner_uuid TEXT        NULL,
    ADD COLUMN IF NOT EXISTS calendar_source_ref TEXT        NULL,
    ADD COLUMN IF NOT EXISTS calendar_version    INTEGER     NULL,
    ADD COLUMN IF NOT EXISTS calendar_ref_seq    INTEGER     NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS calendar_due_at     TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS calendar_state      TEXT        NULL,
    ADD COLUMN IF NOT EXISTS calendar_detail     TEXT        NULL,
    ADD COLUMN IF NOT EXISTS calendar_checked_at TIMESTAMPTZ NULL;

-- One attempt of one intended change, recorded BEFORE it is sent (contract
-- §12.1): the Idempotency-Key is the operation's correlation_id, and the exact
-- request is kept so a retry of THAT attempt resends the same body under the
-- same key — Calendar replays it instead of acting twice. `deferred` joins the
-- statuses: Calendar refused before acting (credentials, scope, schema), so the
-- attempt is retried later, unchanged, and is neither failed nor unknown.
ALTER TABLE voice_external_operations
    ADD COLUMN IF NOT EXISTS owner_uuid       TEXT    NULL,
    ADD COLUMN IF NOT EXISTS source_ref       TEXT    NULL,
    ADD COLUMN IF NOT EXISTS request_method   TEXT    NULL,
    ADD COLUMN IF NOT EXISTS request_path     TEXT    NULL,
    ADD COLUMN IF NOT EXISTS request_body     JSONB   NULL,
    ADD COLUMN IF NOT EXISTS if_match         TEXT    NULL,
    ADD COLUMN IF NOT EXISTS external_version INTEGER NULL;

-- The reconciler's index, now including attempts waiting to be resent.
CREATE INDEX IF NOT EXISTS ix_voice_external_operations_open
    ON voice_external_operations (status, next_check_at)
    WHERE status IN ('pending', 'unknown', 'deferred');

-- "Is a diary change for this callback still unresolved?" — asked before every
-- new write, because two writes in flight for one entry is how it ends up wrong.
CREATE INDEX IF NOT EXISTS ix_voice_external_operations_callback
    ON voice_external_operations (callback_id, target_app, status)
    WHERE callback_id IS NOT NULL;
