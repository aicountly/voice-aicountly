-- Migration 014: the audit trail grows the context an auditor reads.
--
-- voice_audit_events (001) is the one audit table; this migration extends it
-- rather than adding a second. Additive only: nothing is renamed or dropped, and
-- every column is nullable or defaulted, so rows written before this migration
-- remain valid and keep reading as they did.
--
--   entity_label  the human name of the record ("Autumn renewals", "+91 98xxxxxx23")
--   summary       one readable line
--   changes       [{field, label, old, new}] for an update
--   before_state / after_state   readable snapshots for a material record
--   severity      info | material | security
--   request_id    ties the rows one request wrote together
--   user_agent    the client that did it
--   actor_label   the display name at the time
--
-- The client's address is still kept only as ip_hash (001): Voice deliberately
-- does not hold a log of where staff work from, and this migration does not
-- change that.
--
-- IMMUTABLE. A BEFORE UPDATE / BEFORE DELETE trigger raises, so no code path —
-- application, script or operator at a psql prompt — can quietly rewrite the
-- trail. TRUNCATE does not fire row triggers; tests/support.php resets the table
-- that way and nothing in production does. Company purge / retention must not
-- touch this table.
--
-- Idempotent: safe to apply twice.

ALTER TABLE voice_audit_events ADD COLUMN IF NOT EXISTS entity_label TEXT  NULL;
ALTER TABLE voice_audit_events ADD COLUMN IF NOT EXISTS summary      TEXT  NULL;
ALTER TABLE voice_audit_events ADD COLUMN IF NOT EXISTS changes      JSONB NULL;
ALTER TABLE voice_audit_events ADD COLUMN IF NOT EXISTS before_state JSONB NULL;
ALTER TABLE voice_audit_events ADD COLUMN IF NOT EXISTS after_state  JSONB NULL;
ALTER TABLE voice_audit_events ADD COLUMN IF NOT EXISTS severity     TEXT  NOT NULL DEFAULT 'info';
ALTER TABLE voice_audit_events ADD COLUMN IF NOT EXISTS request_id   TEXT  NULL;
ALTER TABLE voice_audit_events ADD COLUMN IF NOT EXISTS user_agent   TEXT  NULL;
ALTER TABLE voice_audit_events ADD COLUMN IF NOT EXISTS actor_label  TEXT  NULL;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_voice_audit_severity') THEN
        ALTER TABLE voice_audit_events
            ADD CONSTRAINT ck_voice_audit_severity CHECK (severity IN ('info', 'material', 'security'));
    END IF;
END
$$;

CREATE INDEX IF NOT EXISTS ix_voice_audit_entity_time
    ON voice_audit_events (cmp_id, entity_type, entity_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS ix_voice_audit_action_time
    ON voice_audit_events (cmp_id, action, occurred_at DESC);
CREATE INDEX IF NOT EXISTS ix_voice_audit_actor_time
    ON voice_audit_events (cmp_id, actor_uuid, occurred_at DESC);

CREATE OR REPLACE FUNCTION voice_audit_events_immutable() RETURNS trigger AS $fn$
BEGIN
    RAISE EXCEPTION 'voice_audit_events is append-only: % is not allowed', TG_OP
        USING ERRCODE = 'integrity_constraint_violation';
END
$fn$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_voice_audit_events_immutable ON voice_audit_events;
CREATE TRIGGER trg_voice_audit_events_immutable
    BEFORE UPDATE OR DELETE ON voice_audit_events
    FOR EACH ROW EXECUTE FUNCTION voice_audit_events_immutable();
