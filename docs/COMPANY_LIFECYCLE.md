# Company lifecycle (Manage) in Voice

Manage decides **when** a company that stopped paying for Voice becomes read-only (view and export
only, for 45 days), and when its data here is deleted; Voice decides **how**, through a reviewed
manifest. Manage also asks, when an AICOUNTLY account is closed, for that person's own non-company data
to be deleted. Contract: manage-aicountly `docs/company-lifecycle/README.md`. Implementation: the
AICOUNTLY company-lifecycle receiver kit **v1.1.0**, vendored unchanged at
`server-php/lib/company-lifecycle/` (copy it again from manage-aicountly
`Saas Common Handler/company-lifecycle/` to update; never edit it here).

| Piece | Where |
|---|---|
| Tables (`aic_lifecycle_events`, `_state`, `_jobs`, `_acks`) | `server-php/database/migrations/014_aic_company_lifecycle.sql` — the kit's `schema.sql`, verbatim |
| Stored-recording deletions (audit + retry queue) | `server-php/database/migrations/015_voice_lifecycle_file_deletions.sql`, `src/LifecycleFiles.php` |
| Kit 1.1 tables (`aic_lifecycle_expiries`; jobs may be `held`) | `server-php/database/migrations/016_aic_company_lifecycle_1_1.sql` — the kit's `schema-1.1.sql`, verbatim |
| Legal hold (kit 1.1 `legal_hold`) | `CompanyLifecycle::legalHold()`, passed as the Kit option by `CompanyLifecycle::build()` (so by `config/lifecycle-kit.php` too) |
| Voice as a sender (kit 1.1 `DeferredSend`) | `Domain\CallbackDiary::classify()` / its settle, `ExternalOperations::reviewRestricted()` (run by `bin/call-recovery.php`); `server-php/database/migrations/017_voice_external_operations_restricted.sql` (`voice_external_operations.restricted_until`) |
| Receiver `POST /api/internal/manage/company-lifecycle` | `src/Routes.php` → `CompanyLifecycle::receive()`; no session, no company scope — the HMAC (`LIFECYCLE_WEBHOOK_SECRET`) is its only credential |
| Write gate (423 `COMPANY_RESTRICTED`) | `Context::assertAllowed()` — **after** the company check (Manage's companyinfo for a person, the stream ticket, the product's company binding), so a stranger still gets 403; people, stream tickets and products alike. Kit 1.1: a product acting with its own key and no person is a **machine caller** (`checkMachine()`), and so is the telephony provider's signed webhook (`WebhooksController::telephony()` → `CompanyLifecycle::enforceMachine()`); `CompanyLifecycle::MACHINE_ALLOW` keeps open only the telephony webhook (`POST /api/webhooks/telephony/*`) and the Voice Gateway's AI action on a call it is holding (`POST /api/v1/calls/*/ai-actions`) — they record or act on calls that are happening and cannot be replayed later. Everything else a product writes (Lobby booking a callback, CRM placing a call) answers 423 and its sender defers it. |
| companyinfo write-through (`lifecycle`, `restricted_until`) | `Clients/ManageClient::companyInfo()`; `GET /v1/access` carries the enforced `lifecycle` for the SPA |
| Allow-list (writes that stay open while read-only) | `CompanyLifecycle::ALLOW`: search, flow and campaign validation, the live-events stream ticket, and the agent's own presence. Every GET passes (calls, recordings playback/download, transcripts, dashboards). |
| Purge manifest | `server-php/config/lifecycle-manifest.php` (schema = `DB_SCHEMA` when set) |
| Worker bootstrap | `server-php/config/lifecycle-kit.php` (also retries due recording deletions on each plain cron run) |
| SPA | `web/src/services/companyRestriction.ts` (423 + `/v1/access`), `web/src/components/CompanyRestrictedBanner.tsx` in `AppShell` (banner above every screen — it tells the customer to download recordings now — and a toast on a refused write) |
| Tests | `server-php/tests/lifecycle.php` (in `tests/run.sh`): manifest problems for the company **and** user scopes are empty on the migrated schema, every allow-list entry is a real route, receiver 401/422/202, 423 after the company check, 403 before 423, companyinfo sync, the machine allow-list against the routes and the machine 423, a signed purge **held** while a recording is under legal hold (acked `held` with the reason, nothing touched, not looked at again before the daily re-check) and run once the hold is cleared (calls, recordings and transcripts deleted, the recording file deleted), external recording storage left pending for ops, the account purge held for an agent of a held call and run after, the cron bootstrap and `--check-manifest`, a Calendar 423 deferred / dropped and the hourly re-check (wait, resume, drop with an audit event); `web/tests/company-restriction.test.mjs`. `tests/integration.php`'s "every table is voice_" checks admit the kit's `aic_lifecycle_*` tables. |

## Manifest decisions (`server-php/config/lifecycle-manifest.php`)

* **company_tables — everything company-scoped.** Call recordings and transcripts are personal data that no statute requires Voice to keep, and nothing retained here refers to them (Voice retains nothing), so they are purged with the calls. Calls, legs, participants, summaries, commitments, quality reviews, voicemails, campaigns, flows, AI agents, numbers, queues, teams, agents, settings, access, suppressions, usage estimates (`voice_usage_entries` is Voice's own estimate for budgets; the billed usage is metered in Manage) and Voice's own audit trail (who played a recording, who changed a flow — no statute) all go. Archived first, children before parents.
* **company_retain — none.**
* **A legal hold holds the purge** (kit 1.1): while any of the company's recordings is under legal hold (`voice_recordings.legal_hold`, not yet deleted) the company purge is **held** — nothing is touched, Manage is acked `held` with `detail.reason` (Manage marks the purge `blocked`, legal_hold, and alerts ops once), the worker looks at the job again daily and purges (ack `purged`) once the hold is cleared. An account purge is held the same way while a recording of a call the person took part in as an agent is under hold. The hold is set and cleared inside Voice (the retention sweeper, `bin/retention.php`, already respects it); the lifecycle only reads it.
* **Retention periods / scrub** (kit 1.1): none — nothing is retained, so `--expire-retained` has nothing to expire; no retained row holds personal fields.
* **user_tables** — `voice_saved_searches` and `voice_stream_tickets`: the person's own, in every company. Agents and access grants that name the person are the company's records (ignore, user scope).

A migration that adds a table with a company column (or a personal one) must list it in the manifest in
the same commit: strict mode refuses to purge while one is missing, and the lifecycle test fails CI.

## Object storage

Recordings are stored under `RECORDING_STORAGE_PATH` and played through signed `RECORDING_STORAGE_URL` links. The kit deletes rows only, so `src/LifecycleFiles.php` is the kit's company hook: after the purge **commits** it reads the purged `voice_recordings` rows (call and voicemail recordings) back out of that purge's archive schema, records each stored object in `voice_lifecycle_file_deletions` and deletes it, confined to `RECORDING_STORAGE_PATH` exactly as `bin/retention.php` does. A deletion that fails stays `pending` and is retried with back-off (1, 2, 4 … min, ≤ 6 h, 12 attempts) by `config/lifecycle-kit.php` on every cron run, then `failed` and logged; the table is the audit and the ack detail counts the files. **With no `RECORDING_STORAGE_PATH` the recordings live behind the storage gateway, which this server cannot delete from: each deletion stays pending and ends `failed` for ops to delete at the gateway — never a silent success**. Kit 1.1 review: the gateway exposes no delete API Voice could call with a service credential — Voice holds only `RECORDING_STORAGE_URL` and the `RECORDING_SIGNING_KEY` it signs short-lived *playback* links with, and nothing in this repository documents a delete endpoint — so the ops-visible `failed` path stays (open question for the gateway's owners: a signed DELETE, or lifecycle rules keyed on the company).

## Cron

cPanel → Cron Jobs, with the PHP the deploy names:

```cron
*/5 * * * * cd <remote root>/api && <php> lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php >> $HOME/logs/voice-company-lifecycle.log 2>&1
37 3 * * *  cd <remote root>/api && <php> lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --expire-retained >> $HOME/logs/voice-company-lifecycle.log 2>&1
```

The second line (kit 1.1) is the daily retention expiry: Voice retains nothing today, so it expires nothing, but
a retained table given a period later is expired without a deploy step (and a held target is skipped). Console
monitors (Console `cron_monitors`, migration 059 pattern): `voice.company_lifecycle` and
`voice.company_lifecycle_expiry`.

**Voice as a sender** (kit 1.1 `DeferredSend`): a callback's diary entry that Aicountly Calendar answers 423
`COMPANY_RESTRICTED` (the company is read-only THERE) is `deferred` with `error_code = deferred_restricted` — the
attempt given back, `restricted_until` kept — and `bin/call-recovery.php` asks Manage's `internal/company-status`
at most hourly per company (`MANAGE_PRODUCT_SERVICE_KEY`): still read-only → wait (next check at
`restricted_until` or in an hour); active again or `restricted_until` passed → the same attempt is sent again;
deleted there (or Calendar says `lifecycle: purged`) → `failed` with `error_code = dropped_company_deleted` and an
audit event. An AI agent's live booking in Appointments cannot wait for a renewal and stays a plain refusal.

Weekly, drop purge archives (they hold personal data) after the 90-day window:

```cron
17 3 * * 0 cd <remote root>/api && <php> lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --drop-archives-older-than=90 --confirm >> $HOME/logs/voice-company-lifecycle.log 2>&1
```

Operator commands (`--status`, `--dry-run=company:<cmp_id>`, `--dry-run=account:<uuid>`,
`--requeue=<event_id>`, `--list-archives`, `--restore=<schema> --confirm`, kit 1.1 `--expire-retained=dry-run`,
`--check-manifest`): kit README §5, always with
`--bootstrap=config/lifecycle-kit.php` (this product's PDO — database name and user from Console when
`CONSOLE_DB_DETAILS_KEY` is set — its .env and its hooks).

## Environment (`api/.env`, never the SPA)

| Variable | |
|---|---|
| `LIFECYCLE_WEBHOOK_SECRET` | required; ≥ 32 chars (`openssl rand -hex 32`); the same value goes into Manage as `LIFECYCLE_WEBHOOK_SECRET_VOICE`. Unset: events answer 503, nothing is restricted or purged. |
| `MANAGE_API_URL` | required for acks: `https://manage.aicountly.com` (sandbox `https://manage.gh.aicountly.com`). No default, so a sandbox never acks production. |
| `MANAGE_PRODUCT_SERVICE_KEY` | optional: Manage's `internal/company-status` is asked just before each purge; a purge Manage has since reversed is refused. |
| `LIFECYCLE_MANIFEST` | optional override of `config/lifecycle-manifest.php`. |

The worker needs `CREATE` on the product database for the archive schemas
(`GRANT CREATE ON DATABASE <db> TO <user>;`). Voice has no separate grant script: the API's database user owns
every table, the kit's five (`aic_lifecycle_events / _state / _jobs / _acks / _expiries`) and their sequences
included.

**Go live:** deploy → apply the migration → install the cron → set `LIFECYCLE_WEBHOOK_SECRET` and
`MANAGE_API_URL` here → in Manage set `LIFECYCLE_WEBHOOK_SECRET_VOICE` (same value; and
`LIFECYCLE_WEBHOOK_URL_VOICE` if the catalog host is not this API) → update manage-aicountly
`docs/company-lifecycle/README.md` §10.
