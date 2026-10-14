# Company lifecycle (Manage) in Voice

Manage decides **when** a company that stopped paying for Voice becomes read-only (view and export
only, for 45 days), and when its data here is deleted; Voice decides **how**, through a reviewed
manifest. Manage also asks, when an AICOUNTLY account is closed, for that person's own non-company data
to be deleted. Contract: manage-aicountly `docs/company-lifecycle/README.md`. Implementation: the
AICOUNTLY company-lifecycle receiver kit **v1.0.0**, vendored unchanged at
`server-php/lib/company-lifecycle/` (copy it again from manage-aicountly
`Saas Common Handler/company-lifecycle/` to update; never edit it here).

| Piece | Where |
|---|---|
| Tables (`aic_lifecycle_events`, `_state`, `_jobs`, `_acks`) | `server-php/database/migrations/014_aic_company_lifecycle.sql` — the kit's `schema.sql`, verbatim |
| Stored-recording deletions (audit + retry queue) | `server-php/database/migrations/015_voice_lifecycle_file_deletions.sql`, `src/LifecycleFiles.php` |
| Receiver `POST /api/internal/manage/company-lifecycle` | `src/Routes.php` → `CompanyLifecycle::receive()`; no session, no company scope — the HMAC (`LIFECYCLE_WEBHOOK_SECRET`) is its only credential |
| Write gate (423 `COMPANY_RESTRICTED`) | `Context::assertAllowed()` — **after** the company check (Manage's companyinfo for a person, the stream ticket, the product's company binding), so a stranger still gets 403; people, stream tickets and products alike. The telephony provider's signed webhooks are not gated: they record calls that already happened. |
| companyinfo write-through (`lifecycle`, `restricted_until`) | `Clients/ManageClient::companyInfo()`; `GET /v1/access` carries the enforced `lifecycle` for the SPA |
| Allow-list (writes that stay open while read-only) | `CompanyLifecycle::ALLOW`: search, flow and campaign validation, the live-events stream ticket, and the agent's own presence. Every GET passes (calls, recordings playback/download, transcripts, dashboards). |
| Purge manifest | `server-php/config/lifecycle-manifest.php` (schema = `DB_SCHEMA` when set) |
| Worker bootstrap | `server-php/config/lifecycle-kit.php` (also retries due recording deletions on each plain cron run) |
| SPA | `web/src/services/companyRestriction.ts` (423 + `/v1/access`), `web/src/components/CompanyRestrictedBanner.tsx` in `AppShell` (banner above every screen — it tells the customer to download recordings now — and a toast on a refused write) |
| Tests | `server-php/tests/lifecycle.php` (in `tests/run.sh`): manifest problems for the company **and** user scopes are empty on the migrated schema, every allow-list entry is a real route, receiver 401/422/202, 423 after the company check, 403 before 423, companyinfo sync, a signed purge end to end (calls, recordings — a legal-hold one included — and transcripts deleted, the recording file deleted), external recording storage left pending for ops, the account purge, the cron bootstrap; `web/tests/company-restriction.test.mjs`. `tests/integration.php`'s "every table is voice_" checks admit the kit's `aic_lifecycle_*` tables. |

## Manifest decisions (`server-php/config/lifecycle-manifest.php`)

* **company_tables — everything company-scoped.** Call recordings and transcripts are personal data that no statute requires Voice to keep, and nothing retained here refers to them (Voice retains nothing), so they are purged with the calls. Calls, legs, participants, summaries, commitments, quality reviews, voicemails, campaigns, flows, AI agents, numbers, queues, teams, agents, settings, access, suppressions, usage estimates (`voice_usage_entries` is Voice's own estimate for budgets; the billed usage is metered in Manage) and Voice's own audit trail (who played a recording, who changed a flow — no statute) all go. Archived first, children before parents.
* **company_retain — none.**
* **A legal hold set inside Voice does not survive the company's own purge** (open question for legal: the hold is the company's own instrument, the purge follows the company's lapsed subscription after 45 days of read-only access in which the recording can be downloaded).
* **user_tables** — `voice_saved_searches` and `voice_stream_tickets`: the person's own, in every company. Agents and access grants that name the person are the company's records (ignore, user scope).

A migration that adds a table with a company column (or a personal one) must list it in the manifest in
the same commit: strict mode refuses to purge while one is missing, and the lifecycle test fails CI.

## Object storage

Recordings are stored under `RECORDING_STORAGE_PATH` and played through signed `RECORDING_STORAGE_URL` links. The kit deletes rows only, so `src/LifecycleFiles.php` is the kit's company hook: after the purge **commits** it reads the purged `voice_recordings` rows (call and voicemail recordings) back out of that purge's archive schema, records each stored object in `voice_lifecycle_file_deletions` and deletes it, confined to `RECORDING_STORAGE_PATH` exactly as `bin/retention.php` does. A deletion that fails stays `pending` and is retried with back-off (1, 2, 4 … min, ≤ 6 h, 12 attempts) by `config/lifecycle-kit.php` on every cron run, then `failed` and logged; the table is the audit and the ack detail counts the files. **With no `RECORDING_STORAGE_PATH` the recordings live behind the storage gateway, which this server cannot delete from: each deletion stays pending and ends `failed` for ops to delete at the gateway — never a silent success** (open question: a gateway delete API).

## Cron

cPanel → Cron Jobs, with the PHP the deploy names:

```cron
*/5 * * * * cd <remote root>/api && <php> lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php >> $HOME/logs/voice-company-lifecycle.log 2>&1
```

Weekly, drop purge archives (they hold personal data) after the 90-day window:

```cron
17 3 * * 0 cd <remote root>/api && <php> lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --drop-archives-older-than=90 --confirm >> $HOME/logs/voice-company-lifecycle.log 2>&1
```

Operator commands (`--status`, `--dry-run=company:<cmp_id>`, `--dry-run=account:<uuid>`,
`--requeue=<event_id>`, `--list-archives`, `--restore=<schema> --confirm`): kit README §5, always with
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
(`GRANT CREATE ON DATABASE <db> TO <user>;`).

**Go live:** deploy → apply the migration → install the cron → set `LIFECYCLE_WEBHOOK_SECRET` and
`MANAGE_API_URL` here → in Manage set `LIFECYCLE_WEBHOOK_SECRET_VOICE` (same value; and
`LIFECYCLE_WEBHOOK_URL_VOICE` if the catalog host is not this API) → update manage-aicountly
`docs/company-lifecycle/README.md` §10.
