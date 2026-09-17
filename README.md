# voice-aicountly

**Aicountly Voice** — the business calling and AI voice platform for AICOUNTLY.
A React single-page app built with Vite and TypeScript, with a PHP API
alongside it. Both halves deploy to cPanel.

Aicountly Interactive Services Private Limited.

| Environment | App | API |
| --- | --- | --- |
| Production | https://voice.aicountly.com | https://voice.aicountly.com/api |
| Sandbox | https://voice.gh.aicountly.com | https://voice.gh.aicountly.com/api |

## What this product does

Business calling — inbound and outbound, through configured telecom providers
and from the browser where the deployed voice infrastructure supports it. Business
numbers, SIP connections, IVR, queues and ring groups. Human agents and AI voice
agents. Campaigns, callbacks and agent-assisted dialling. Recordings, voicemail
and conversation intelligence. Call quality, capacity, usage and provider
administration.

Six dashboards and the working surfaces beneath them:

| Dashboard | Answers |
| --- | --- |
| Command Centre | What is happening across the business today, and what needs somebody |
| Live Operations | The queue, who is free, and the call in front of you right now |
| AI Voice Studio | Whether an AI agent is safe to put in front of a customer |
| Campaigns & Growth | Whether outreach is producing outcomes worth its cost |
| Conversation Intelligence | What was actually said, and what we promised |
| Network & Usage | Whether the plumbing is healthy and what it is costing |

## The rules this codebase is built around

These are not style preferences. Each one is load-bearing, and the code
comments say why at the point where it matters.

### Cross-app access is live API only

There is **no cross-app database synchronisation** anywhere in this product. No
foreign database connection, no mirrored table, no FDW, no CDC, no sync worker,
no cron that copies another product's records, no persisted cross-app cache.

Voice reaches other products through their HTTP APIs, on the request that needs
the answer — see `server-php/src/Clients/`. Ownership:

| Owner | Owns |
| --- | --- |
| my.aicountly.com | authentication |
| manage.aicountly.com | company, branch and financial-year masters |
| Aicountly Contacts | contact master records |
| Aicountly CRM | leads, deals and CRM tasks |
| Aicountly Calendar | calendar events and appointment time allocations |
| Aicountly Pay | payment links and payment status |
| Aicountly Lobby | lobby / receptionist workflows |
| **Aicountly Voice** | calls, recordings, transcripts, campaigns, callbacks and Voice-owned intelligence |

Voice stores external IDs and its own calling-domain records. A transcript that
happens to contain a customer's name is historical conversation evidence — it is
never promoted to a contact record.

A test asserts this structurally: `tests/integration.php` checks that no source
file reaches another product's database, that every table in the schema is
prefixed `voice_`, and that exactly one PDO connection exists in the codebase.

### An unconfirmed write is never reported as done

The case this is built for: an AI agent asks Calendar to create a booking
mid-call and the request times out. Voice knows it sent the request and does not
know whether Calendar acted on it.

`voice_external_operations` records that as `unknown` — not succeeded, not
failed, and never retried blindly. `bin/call-recovery.php` settles it by asking
Calendar what it holds against the correlation id Voice sent. Only an
authoritative acknowledgement produces `succeeded`. A 2xx carrying no identifier
is treated as unknown too, because a booking nobody can link to is not a booking.

The UI carries this through: a call whose outcome the provider did not confirm
removes the Call button rather than inviting a second dial at a member of the
public.

### Telephony is a control plane; media is a separate service

`server-php/src/Telephony/` asks a provider to place or change a call and
verifies its callbacks. SIP, RTP, WebRTC, streaming speech recognition and
synthesis, barge-in and playback belong to the **Voice Gateway**, a separate
service. None of that can happen inside a PHP web request, and a product that
pretends otherwise works in a demo and dies at three concurrent calls.

Capabilities are **discovered per connection** and drive which controls the
console renders. A provider that cannot hold a call gets no Hold button, and the
tooltip says whether the missing piece is the provider or the permission. Mute
and hold stay distinct operations throughout.

This repository ships a real adapter for the Aicountly Voice Gateway (whose
contract is ours) and a `NullAdapter` that refuses every action with a reason.
Carrier adapters — Exotel, Airtel IQ, Tata, Twilio — are **deliberately absent**:
each needs its own file written against that carrier's real documentation with
credentials to test against, and an adapter written from a guess is worse than
no adapter because it looks finished.

### AI is Voice's own, with keys governed centrally

Voice owns its prompts, its calling-domain workflows and its action allowlist
(`src/Ai/AiClient.php`). Provider keys are resolved per request from
console.aicountly.org and never come to rest in a file next to this code, never
reach the browser, and never appear in a log.

A consequential action — booking, rescheduling, taking a payment — can never be
configured as merely "allowed"; the server forces it to require caller
confirmation. An action outside the allowlist is denied whatever the
configuration says. A transcript is untrusted input: a caller saying "ignore your
previous instructions" is a caller saying an odd sentence.

### Policies are the business's, not this product's claims

Recording disclosure, AI disclosure, calling windows, suppression and retention
are settings a company configures. Voice enforces what it is told and **does not
certify that any combination meets a legal obligation** — there is no green
"compliant" badge anywhere. Campaign readiness asks explicitly why an audience
may be called, and says in the check itself that an existing customer
relationship is not by itself permission for marketing calls.

### Every state is told apart

Loading, empty, error, forbidden, another-product-unavailable, and partial are
six different things with six different renderings. "Calendar is unavailable" is
not "there are no appointments". A metric with no previous period says "no
comparison", not 0%. An agent whose console stopped reporting reads "unknown",
never "available". A call whose provider has gone quiet reads "state unknown"
rather than looking connected.

## Layout

```
web/                      React app (Vite). Builds to web/dist, deployed to the document root.
  src/dashboards/           the six dashboards, one lazy chunk each
  src/pages/                the working surfaces
  src/voice/                waveform, call controls, transcript, copilot, handover, flow editor
  src/ui/                   the component library and the design system
  src/shell/                app frame, navigation, company switcher
  src/services/             API client and the typed wire contracts
server-php/               PHP API. Deployed to the api/ folder inside the document root.
  src/Clients/              one live API client per Aicountly product
  src/Telephony/            provider adapters, capability model, gateway client
  src/Domain/               calls, campaigns, callbacks, retention, AI agents, policy
  src/Dashboards/           one class per dashboard, each answering in a single request
  src/Controllers/          the HTTP surface
  database/migrations/      numbered SQL, applied by bin/migrate.php
  bin/                      campaign worker, retention sweep, call-state recovery
  tests/                    integration suite and the stub that stands in for other products
design-reference/voice/   the static visual reference. Sample data, clearly labelled, not the data layer.
docs/                     deployment and auth notes
```

## Getting started

Requires Node.js 22 or newer, PHP 8.1+ with `pdo_pgsql`, and PostgreSQL.

```bash
# Frontend
cd web
npm install
npm run dev              # http://localhost:5173

# Backend
cd server-php
cp .env.example .env     # set APP_ENV=local and the DB_* values
php bin/migrate.php      # apply the schema
php -S localhost:8000
```

Point `VITE_API_BASE_URL` at the API and add your dev origin to
`CORS_ALLOWED_ORIGINS` in the server `.env` — localhost is the one case where
the app and API are not same-origin.

| Script | Purpose |
| --- | --- |
| `npm run dev` | Vite dev server |
| `npm run build` | Type-check, then build to `web/dist/` |
| `npm run typecheck` | Type-check only |
| `npm run test:ui` | Frontend unit tests |
| `php bin/migrate.php` | Apply pending migrations (`--status`, `--dry-run`) |
| `server-php/tests/run.sh` | The integration suite |

### Background workers

Voice-owned work only. None of these copies another product's database.

| Script | Cadence | Does |
| --- | --- | --- |
| `bin/campaign-worker.php` | every minute | Claims a bounded batch of campaign attempts and dials them |
| `bin/call-recovery.php` | every few minutes | Marks calls whose provider went quiet as stale; reconciles unknown external writes |
| `bin/retention.php` | daily, off-peak | Deletes recordings, transcripts and index entries that are past their retention |

Several copies of the campaign worker can run at once: every attempt is claimed
with a conditional `UPDATE` before anything is dialled, and a unique index is the
second line of defence behind it.

## Tests

```bash
server-php/tests/run.sh      # 152 assertions against a real PostgreSQL
cd web && npm run test:ui    # frontend unit tests
```

The suite drives the real controllers through the real router with an adopted
identity, so permission checks are exercised rather than bypassed. A local stub
stands in for Manage, Contacts, Calendar, CRM and the gateway: **no test places a
call, launches a campaign or writes to a real product.**

What it covers: tenant isolation including a transcript read across companies,
permission enforcement per surface, duplicate and out-of-order provider events,
idempotent call creation, the timed-out external write, Calendar and Contacts
failures creating no local mirror, provider capability gating, webhook signature
and replay, campaign pause and duplicate-dial prevention, launch readiness, AI
action permissions, flow validation, publish gating and version immutability,
budget and concurrency enforcement, calling windows across timezones, retention
with legal holds, audited recording access, and the structural no-cross-app-DB
check.

## Environment variables

`.env` is git-ignored and never deployed — `.env.example` is the tracked
template. There are two, and they work in opposite ways:

| File | Read | Used by |
| --- | --- | --- |
| `.env.example` | **Build time**, inlined into the bundle | `web/` |
| `server-php/.env.example` | **Runtime**, on every request | `server-php/` |

Only `VITE_`-prefixed variables reach the browser bundle, and Vite inlines them
at build time, so **treat every one of them as public**. Never put a secret,
token or password in a `VITE_` variable. Changing a `VITE_*` endpoint means
rebuilding and redeploying; `server-php` reads its `.env` on every request.

`server-php/.env.example` documents every server variable and what is disabled
without it. The ones with no safe default:

- `CREDENTIAL_ENCRYPTION_KEY` — 32 bytes, base64. **Without it, storing a
  provider credential is refused** rather than stored in the clear.
- `VOICE_GATEWAY_URL` / `VOICE_GATEWAY_KEY` — no gateway means no browser
  calling and no live transcription, and the UI says so rather than showing
  controls that do nothing.
- `CONSOLE_API_URL` / `CONSOLE_SERVICE_KEY` — no AI. The deterministic paths
  answer instead and the screen says the result is rule-based.

## Deployment

Deployment is **manual only**. Nothing deploys on push or merge — both workflows
trigger exclusively via `workflow_dispatch`.

**Actions** → pick a workflow → **Run workflow** → pick a branch → **Run**.

| Workflow | Deploys | To |
| --- | --- | --- |
| Deploy to cPanel Production | `web/dist/` then `server-php/` | document root, then `api/` inside it |
| Deploy to cPanel Sandbox | `web/dist/` then `server-php/` | document root, then `api/` inside it |

After the first deploy of this release, on the server:

```bash
cd <document root>/api
cp .env.example .env     # fill in DB_*, CREDENTIAL_ENCRYPTION_KEY and the rest
php bin/migrate.php
```

Then add the three workers to cron. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)
for the rsync excludes and the SSH secrets each workflow needs.

## Authentication

Signing in is the AICOUNTLY portal's job, the same as every other AICOUNTLY
SaaS: the app redirects to the portal, the portal returns an `auth_token`, and
the app exchanges it for a short-lived `ses_key`. A user already signed in to
another AICOUNTLY product lands straight on the dashboard.

The `ses_key` lives in memory only and never reaches localStorage — that split is
the whole point of the two-token model. The one exception is the SSE stream,
where the browser's `EventSource` cannot set headers; the key travels as a query
parameter on that route alone, same-origin, and the reasoning is written at
`src/Auth.php`.

See [docs/auth/AICOUNTLY_AUTH_WORKFLOW.md](docs/auth/AICOUNTLY_AUTH_WORKFLOW.md).

## Design reference

`design-reference/voice/` holds the static HTML/CSS/JS reference that pins the
visual direction. It uses sample data, says so on every screen, and is **not the
production data layer**. The React implementation in `web/src/` is the product;
the reference is the swatch.
