-- Migration 012: inbound calls and who the caller is.
--
-- Nothing created inbound calls (the only writer of voice_calls placed
-- outbound ones), and the console said "This number is not linked to a
-- contact" without ever looking (G18#4). Inbound calls are now created from the
-- gateway's signed `call.inbound` event; the caller is identified by a COMPANY
-- lookup in Aicountly Contacts under the answering agent's own session, and a
-- contact is linked only when Contacts reports exactly one match that holds the
-- number (meta.matchCount = 1).
--
-- The lookup's OUTCOME is Voice's own record (it decides what the console may
-- say); the contact's name is never stored. States:
--   not_attempted | matched | no_match | ambiguous | unavailable | forbidden
--
-- Idempotent: safe to apply twice.

ALTER TABLE voice_calls ADD COLUMN IF NOT EXISTS contact_lookup_state TEXT NULL;
ALTER TABLE voice_calls ADD COLUMN IF NOT EXISTS contact_lookup_at TIMESTAMPTZ NULL;
ALTER TABLE voice_calls ADD COLUMN IF NOT EXISTS contact_lookup_matches INTEGER NULL;
