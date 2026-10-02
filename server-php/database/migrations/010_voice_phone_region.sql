-- Migration 010: the region national phone numbers are read in.
--
-- Until now a number typed without a country code had '+' put in front of it,
-- so the Indian mobile 9876543210 was dialled, billed and suppression-checked
-- as +9876543210 — another country. Numbers are now turned into E.164 with a
-- region (Support\PhoneNumber): this company setting, then the server's
-- VOICE_DEFAULT_PHONE_REGION, then IN — the same order Contacts uses for the
-- phones it stores. NULL means "not set here", not "no region".
--
-- Idempotent: safe to apply twice.

ALTER TABLE voice_settings ADD COLUMN IF NOT EXISTS default_phone_region TEXT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'ck_voice_settings_phone_region'
    ) THEN
        ALTER TABLE voice_settings
            ADD CONSTRAINT ck_voice_settings_phone_region
            CHECK (default_phone_region IS NULL OR default_phone_region ~ '^[A-Z]{2}$');
    END IF;
END $$;
