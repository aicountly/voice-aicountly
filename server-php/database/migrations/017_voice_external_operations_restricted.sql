-- ---------------------------------------------------------------------------
-- Company lifecycle, kit 1.1 DeferredSend (docs/COMPANY_LIFECYCLE.md): Voice as a SENDER.
--
-- A write Voice makes into another product (voice_external_operations) that the product answers
-- 423 COMPANY_RESTRICTED — the company is read-only THERE — is deferred, not failed: status 'deferred',
-- error_code 'deferred_restricted', no attempt spent, and not sent again until restricted_until passes or
-- Manage's company-status (asked at most hourly, bin/call-recovery.php) says the company is active again.
-- restricted_until is the date the receiver gave; next_check_at (existing) is when to look again. When the
-- receiver says the company was purged there, or Manage reports it deleted, the operation fails with
-- error_code 'dropped_company_deleted' (and an audit event) — never lost without a trace.
-- ---------------------------------------------------------------------------

ALTER TABLE voice_external_operations ADD COLUMN IF NOT EXISTS restricted_until TIMESTAMPTZ NULL;
