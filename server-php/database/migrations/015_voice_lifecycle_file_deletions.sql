-- ---------------------------------------------------------------------------
-- Aicountly Voice — stored files of purged rows (Manage's company lifecycle).
--
-- The company-lifecycle kit (lib/company-lifecycle, migration 014) archives and deletes ROWS.
-- The files those rows point at (call and voicemail recordings under RECORDING_STORAGE_PATH) are Aicountly Voice's to delete: after a purge commits,
-- src/LifecycleFiles.php reads the purged rows back out of the purge's archive schema, records
-- every stored file here, and deletes it — retried with back-off by the cron worker until it is
-- gone. Rows that are retained by statute are never purged, so their files are never listed here.
--
-- This table is also the audit of those deletions: which purge (archive schema), which file, when
-- it went, how many attempts it took, and why a deletion is still pending or failed.
--
-- The company / person is named in target_cmp_id / target_uuid on purpose — not cmp_id — so the
-- record of a purge is never mistaken for company data by the manifest's strict scan, and
-- survives the purge it records. Idempotent and additive.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS voice_lifecycle_file_deletions (
    id               BIGSERIAL    PRIMARY KEY,
    target_cmp_id    BIGINT       NULL,
    target_uuid      VARCHAR(64)  NULL,
    archive_schema   VARCHAR(63)  NOT NULL,
    source_table     VARCHAR(63)  NOT NULL,
    storage          VARCHAR(20)  NOT NULL,
    storage_key      TEXT         NOT NULL,
    -- pending | deleted | missing (already gone) | failed (gave up; ops) | kept (not ours to delete)
    status           VARCHAR(10)  NOT NULL DEFAULT 'pending',
    attempts         INTEGER      NOT NULL DEFAULT 0,
    next_attempt_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    last_error       TEXT         NULL,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    finished_at      TIMESTAMPTZ  NULL,
    CONSTRAINT voice_lifecycle_file_deletions_status_chk CHECK (status IN ('pending', 'deleted', 'missing', 'failed', 'kept')),
    CONSTRAINT voice_lifecycle_file_deletions_uq UNIQUE (archive_schema, source_table, storage_key)
);
CREATE INDEX IF NOT EXISTS voice_lifecycle_file_deletions_due_idx ON voice_lifecycle_file_deletions (status, next_attempt_at);
