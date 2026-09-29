-- Migration 009: a generated summary keeps AI Pulse's id for the call that wrote it.
--
-- Voice's AI runs through the AI Pulse gateway. Pulse keeps a content-free
-- record of every call (product, feature, caller, company, model, tokens, cost)
-- and Console's usage carries the same id, so storing it here is what lets
-- somebody trace a summary to what it cost and which model wrote it — `model`
-- (from migration 006) now holds the model Pulse reports.
--
-- NULL for rule-based and human-edited versions: no model call made them.

ALTER TABLE voice_call_summaries ADD COLUMN IF NOT EXISTS ai_task_id TEXT NULL;
