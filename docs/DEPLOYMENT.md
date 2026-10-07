# Deploying Voice

## Layout

```
web/          React app (Vite). Builds to web/dist.
server-php/   PHP API. Plain PHP, no build step — deployed as-is.
docs/         this file, plus the auth notes
```

## What lands where on cPanel

| Workflow | Deploys | Destination | Reachable at |
| --- | --- | --- | --- |
| Deploy to cPanel Production | `web/dist/` then `server-php/` | `<remote root>/` and `<remote root>/api/` | https://voice.aicountly.com (+ `/api`) |
| Deploy to cPanel Sandbox | `web/dist/` then `server-php/` | `<remote root>/` and `<remote root>/api/` | https://voice.gh.aicountly.com (+ `/api`) |

`<remote root>` is the `*_SSH_REMOTE_ROOT` secret for that environment,
normally `public_html` (or the subdomain's own document root).

Deployment is manual only — **Actions → pick a workflow → Run workflow**.
Nothing deploys on push or merge.

## One workflow per environment, not per half

Production and sandbox are genuinely separate targets — different SSH
credentials, different servers — so each gets its own workflow. Within one
environment, though, the web build and the API are deployed by the same run,
one after the other: first `web/dist/` to the document root, then
`server-php/` to `api/` inside it. Splitting those into separate workflows
would only mean clicking twice for something that is always meant to happen
together, with two SSH sessions and two sets of runner setup instead of one.

### Why the api folder survives the web deploy step

The web deploy step runs `rsync --delete` against the document root, which
would otherwise remove everything not in the build — including `api/`, since
the API lives inside the document root. That step therefore excludes `api/`
explicitly. **Removing that exclude would delete the entire backend on the
next deploy.**

### Why the API's .env survives the API deploy step

The API deploy step also runs `rsync --delete`, this time against `api/`. The
API's `.env` is created once by hand on the server and exists nowhere else, so
both `--exclude='.env'` and `--exclude='.env.*'` are what keep it alive.
Removing them would wipe the live configuration on the next deploy.

Neither `.env` is ever uploaded either: `.gitignore` keeps them out of the
repository, and the workflow fails the build outright if a committed `.env`
appears under `server-php/`.

## Configuration: two different mechanisms

This is the part worth reading carefully, because the frontend and the backend
behave in opposite ways.

### React (web/) — build time

Vite inlines every `VITE_*` value into the JavaScript bundle when the app is
compiled. The deployed result is plain static files that **never read a `.env`
from disk**. Putting a `.env` in the document root has no effect.

To change a frontend value: change it in the workflow (or in the optional
repository variable), then re-run the workflow. The rebuild is what applies it.

Never put a secret in a `VITE_` variable — anything inlined into the bundle is
public to anyone who views the page source.

The API URL needs no configuration in the normal case: with
`PROD_API_BASE_URL` / `SANDBOX_API_BASE_URL` unset, the app calls its own origin
+ `/api`, which is where the same workflow's API step deploys `server-php`.
Those repository variables exist only to override that — for example if the API
moves to its own domain.

### server-php — runtime

PHP reads its `.env` on **every request**. So the API's `.env` belongs on the
server, and only on the server.

Create it once by hand — cPanel File Manager or SSH — at
`<remote root>/api/.env`, from `server-php/.env.example`:

```
APP_ENV=production
```

That is the whole file for a production deploy; `APP_ENV=sandbox` for the
sandbox. `GET /api/health` reports the value back, which is how you confirm
you are looking at the environment you think you are.

The API has no database yet. When the product needs one, add the credentials to
this same file — and note that cPanel prefixes both database and user with the
account name, so a database entered as `app` becomes `<cpaneluser>_app`. Use the
full prefixed names, add the user to the database with **ALL PRIVILEGES**, and
set `DB_HOST=localhost` (on cPanel the database is on the same machine).

### Protecting the API's .env over HTTP

Because `api/` sits inside the document root, `.env` would be fetchable at
`https://voice.aicountly.com/api/.env` unless Apache is told otherwise.
`server-php/.htaccess` ships the rule that denies it:

```apache
RedirectMatch 404 /\.(?!well-known)
```

The web build does the same for the document root via `web/public/.htaccess`,
but those rules stop applying inside `api/` once the API's own take over.

The same file also lets **only the front controller answer**: any other real file
or folder under `api/` — `tests/`, `bin/`, `database/`, `src/`, or a PHP
`error_log` written at any depth — returns 404, so nothing is served as source or
run as PHP against the live `.env`. `.well-known/` stays reachable for certificate
renewal. The deploy reinforces this by never shipping `server-php/tests/` (an
rsync `--filter='H /tests/'`, which also deletes any copy an earlier deploy left
on the server); `bin/` and `database/` still ship, because the cron workers and
migrations run them over SSH.

### The Authorization header

`server-php/.htaccess` also copies the `Authorization` header into the request
environment. Apache does not pass it to PHP under CGI/FastCGI unless told to,
and without it the auth relay forwards no credential — the portal answers 401
and sign-in fails for everyone, with nothing in the logs to explain why.

## Required secrets

Per environment, under Settings → Secrets and variables → Actions → Secrets:

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT` — and the same five with a `SANDBOX_` prefix.

Both workflows validate these before building, and verify SSH authentication
before writing anything to the server. Because the deploys run with
`--delete`, a `*_SSH_REMOTE_ROOT` that would resolve to the home directory
itself, a system directory, or anything containing `..` is refused.

## First deploy checklist

1. Create the subdomain in cPanel and note its document root.
2. Add the five SSH secrets for that environment.
3. Run **Deploy to cPanel …**. This deploys web and API together; the API is
   deployed but unconfigured until the next step.
4. Create `api/.env` on the server (see above), from `server-php/.env.example`.
5. Re-run **Deploy to cPanel …** (or just confirm the API), then confirm
   `https://<host>/api/health` returns the right `env` and open the site to
   sign in. See [auth/AICOUNTLY_AUTH_WORKFLOW.md](auth/AICOUNTLY_AUTH_WORKFLOW.md)
   for what a healthy login looks like.

---

## Voice API: first deploy of this release

The API now has a database, background workers and provider credentials. Three
steps on the server after the first deploy that includes them.

### 1. Create the database and fill in the server `.env`

`server-php/.env` is created once on the server and survives every deploy — the
API rsync excludes it. `server-php/.env.example` documents every variable.

```bash
cd <document root>/api
cp .env.example .env
```

Fill in `DB_NAME`, `DB_USER`, `DB_PASS`, then:

```bash
# 32 bytes, base64. WITHOUT THIS, STORING A PROVIDER CREDENTIAL IS REFUSED —
# this product does not fall back to plaintext.
head -c 32 /dev/urandom | base64
```

Put the result in `CREDENTIAL_ENCRYPTION_KEY`. Rotating it later makes existing
stored credentials undecryptable and they must be re-entered.

### 2. Apply the schema

```bash
php bin/migrate.php --status    # what would run, changes nothing
php bin/migrate.php             # apply it
```

Each file runs in its own transaction and is recorded by name and checksum, so a
half-applied migration cannot exist. An already-applied file that has since been
edited is reported as drift and stops the run rather than being reapplied.

### 3. Add the workers to cron

Voice-owned work only. None of these copies another product's database.

```cron
# Campaign dispatch. Safe to run several copies: every attempt is claimed with a
# conditional UPDATE before anything is dialled.
* * * * * cd /home/<user>/public_html/api && php bin/campaign-worker.php >> ~/logs/voice-campaign.log 2>&1

# Marks calls whose provider went quiet as stale, and settles external writes
# whose outcome was never learned by asking the owning product.
*/5 * * * * cd /home/<user>/public_html/api && php bin/call-recovery.php >> ~/logs/voice-recovery.log 2>&1

# Retention. Off-peak: it deletes recordings, transcripts and index entries.
17 3 * * * cd /home/<user>/public_html/api && php bin/retention.php >> ~/logs/voice-retention.log 2>&1
```

Run `php bin/retention.php --dry-run` first on a live database to see what is
due before anything is deleted.

### 4. Check what the deployment can actually do

```bash
curl -s https://voice.aicountly.com/api/health | python3 -m json.tool
```

`capabilities` lists what is switched on. `unconfigured` names, for each
capability that is off, the exact environment variable that would enable it. The
endpoint is unauthenticated and deliberately says nothing about any tenant and
never names a credential's value.

### AI

Voice's AI runs through the AI Pulse gateway, with Voice's own gateway key and
the signed-in user's own session. There is no model key, model name or provider
to configure here:

```
VOICE_AI_ENABLED=1
# PULSE_API_ORIGIN=https://pulse.aicountly.com   only to override the host-derived origin
# Voice's own AI Pulse gateway key, sent on every AI call:
PULSE_SERVICE_KEY=…
```

`PULSE_API_ORIGIN` left unset means production Pulse on voice.aicountly.com and
the sandbox, https://pulse.gh.aicountly.com, on voice.gh.aicountly.com.

`PULSE_SERVICE_KEY` is this product's own AI Pulse gateway key, minted on Pulse
with `php spark pulse:gateway-key mint voice` (production and sandbox Pulse each
mint their own), and set in `api/.env` on the server only — never in a `VITE_*`
variable. It is sent on every AI call as `X-Pulse-Service-Key`, beside
`X-Pulse-Product: voice` and the user's session. Until it is set a user's call
goes with the session alone, which Pulse accepts only until **2026-11-15
(UTC)**; after that Pulse answers 401 `product_key_required` and summaries fall
back to the rule-based path. `CONSOLE_SERVICE_KEY` is no fallback (Pulse retires
it with 401 `service_key_retired`). A value holding a line break or another
control character is refused and nothing is sent.

When moving an existing deployment onto AI Pulse:

1. Deploy, then run `php bin/migrate.php` straight away: 009 adds the column
   that keeps Pulse's id on each model-written summary, and until it has run
   such a summary cannot be saved.
2. Delete `CONSOLE_API_URL` from `api/.env` — nothing reads it any more, and
   neither does anything read `CONSOLE_SERVICE_KEY`: delete it. Set
   `PULSE_SERVICE_KEY` to the key minted for `voice` on that environment's Pulse
   (do not reuse the Console key — Pulse refuses it).
3. Set `VOICE_AI_ENABLED=1` if it is not already: it is now the only switch.
4. In Console → AI → Domains, switch Voice to **Using AI Pulse** and remove its
   stored keys.

`GET /api/health` reports `ai.service: "AI Pulse"`; the AI Voice Studio screen
shows whether Pulse has a model for Voice, asked with the viewer's session.

### Aicountly Calendar (callback diary entries)

Off by default, and to stay off until it has been checked against the Calendar
it will talk to. When on, a callback can hold its time in a diary: a 15-minute
busy entry in the assigned agent's Aicountly Calendar (the creator's when nobody
is assigned), titled only "Callback · #<id>", which Voice moves when the
callback is rescheduled, cancels when it is cancelled, and moves to the new
agent's diary when it is reassigned (`src/Domain/CallbackDiary.php`). It is
written under Calendar's Events API v1 — calendar-react-app
`docs/ecosystem-alignment/CONTRACTS.md` — with Voice's own service key, the
assigned agent as `X-Actor-Uuid` and the company as `X-Tenant-Ref`.

In order:

1. **Calendar serves contract v1.** `GET https://calendar.aicountly.com/api/health`
   reports `contract_version: 1` (Calendar's `09_contract_v1.sql` is applied).
   Against an older Calendar every write stays "not confirmed" — a 2xx without
   an event version is never taken as success.
2. **Calendar's host knows Voice's key, under the label `voice`.** The label is
   the product identity: Calendar stamps it on every entry Voice writes, and
   Voice's reconciliation looks entries up by `source_app=voice`.

   ```bash
   openssl rand -hex 32        # once; the same value goes on both hosts
   ```

   ```
   # Calendar host, api/.env
   CALENDAR_SERVICE_KEYS=appointments:<its key>,voice:<the 64 hex characters>
   ```

   The default scopes for `voice` (everything except recurring events) are what
   Voice uses: create, own-event read/update/cancel/lookup, free/busy.
3. **Voice's `api/.env`:**

   ```
   VOICE_CALENDAR_ENABLED=1
   CALENDAR_SERVICE_KEY=<the same 64 hex characters>
   ```

4. **Schema:** `php bin/migrate.php` — `010_voice_callback_diary.sql` adds the
   entry's reference columns. Additive; nothing existing changes.
5. **Cron:** `bin/call-recovery.php` (step 3 above) settles diary writes whose
   outcome was not confirmed — by asking Calendar, never by guessing — and
   resends attempts Calendar refused before acting once it accepts them.
6. **Prove it:** Integrations → **Test all**. Calendar must read **Connected**, which
   means an authenticated free/busy read for you, as Voice, for this company,
   was accepted. **Degraded** says what is wrong: the key is not accepted,
   Calendar is not on contract v1, or its v1 schema is not applied.

Each agent who should get entries needs a Voice agent profile whose `user_uuid`
is their AICOUNTLY subscriber id, and membership of the company in Manage —
Calendar checks the person against the company named in `X-Tenant-Ref`.

Switching it off again (`VOICE_CALENDAR_ENABLED=0`) leaves entries already
written in the diaries. Voice stops changing them, and a callback changed while
it is off says its entry was not changed.

### Telephony

A company can place calls only once it has an active provider connection, which
is a per-company row rather than a deployment setting — configured in the app
under Settings → Provider connections, by somebody holding
`voice.providers.manage`.

`VOICE_GATEWAY_URL` and `VOICE_GATEWAY_KEY` point at the Voice Gateway, the
separate service that owns SIP, WebRTC, media and streaming speech. Without it
there is no browser calling and no live transcription, and the UI says so on
every screen that needs them rather than showing controls that do nothing.

Carrier adapters (Exotel, Airtel IQ, Tata and the rest) are not in this
repository. Each needs its own class written against that carrier's real
documentation, with credentials to test against.

### Provider callbacks

Point each provider's webhook at:

```
https://voice.aicountly.com/api/webhooks/telephony/<connection_id>
```

That route carries no AICOUNTLY identity. It is verified by the provider's own
signature scheme against the connection named in the path, and the only thing it
can do is move Voice-owned call state on that connection. A correctly signed
callback almost always answers 200 — including duplicates and out-of-order
events — because a carrier that receives a non-2xx retries for hours.

## Aicountly Appointments (bookings an AI agent makes on a call)

Appointments owns every customer booking. An AI agent's `check_availability`
and `create_booking` steps are carried out by Voice through Appointments'
partner API, and the agent may say "booked" only with Appointments' own answer
in hand (the booking's id and reference). Voice keeps the operation and the
booking id, never a copy of the booking. Contract: calendar-react-app
`docs/ecosystem-alignment/CONTRACTS.md` §0, §12–§14.

**Off by default** (`VOICE_APPOINTMENTS_ENABLED=0`). While it is off:

- a call flow or AI agent with a booking or availability step cannot be
  published — the validation error reads `"Create a booking" is not available
  in this deployment: Voice books through Aicountly Appointments, which is not
  connected here. Turned off for this deployment. Set
  VOICE_APPOINTMENTS_ENABLED=1 in the server environment to enable it.`;
- an agent that reaches such a step anyway creates a high-priority callback for
  the team and says "I can't book that from this call; I'll pass your request
  to the team, and someone will call you back." (without the promise when no
  callback could be made);
- Integrations and the Command Centre show Appointments "Not connected".

**Never available yet, whatever the flag:** `reschedule_booking` and
`cancel_booking` (Appointments has no way for a partner to move or cancel a
booking on a caller's behalf under the booking's own client rules),
`create_payment_link` and `create_task` (no executor). Flows containing them do
not publish; a call that reaches one is handed to a person in the same way.

### Switching it on

1. Generate a key: `openssl rand -hex 32`.
2. On the Appointments host, add it to `SERVICE_KEYS` as `voice:<key>`.
3. On the Voice host:

   ```
   VOICE_APPOINTMENTS_ENABLED=1
   APPOINTMENTS_SERVICE_KEY=<the same key>
   # APPOINTMENTS_API_BASE=   only to override the derived host
   ```

4. Give the Voice Gateway its own key for the action endpoint:
   `SERVICE_KEYS=…,gateway:<another openssl rand -hex 32>` on the Voice host,
   and the same value in the Gateway's configuration.
5. Integrations → **Test all** must show Appointments **Connected** — an
   authenticated read of one service with Voice's key, for that company. Until
   it does, the Command Centre shows "Enabled, not verified".
6. `bin/call-recovery.php` (already on cron) settles any booking whose answer
   was lost: it reads back what Appointments holds for that time, service and
   caller's number, adopts it, or resends the same request under the same
   Idempotency-Key. No new cron is needed.

Switching it off again stops new bookings at once; bookings already made stay
in Appointments, where they are managed.

### The Gateway's contract: `POST /api/v1/calls/{call_id}/ai-actions?cmp_id=<id>`

`X-Service-Key: <the gateway key>`. Body:

```json
{
  "action": "create_booking",
  "tool_call_id": "turn-14-book",
  "caller_confirmed": true,
  "arguments": {
    "service_uuid": "…", "member_uuid": "…",
    "starts_at": "2026-10-06T10:30:00+05:30",
    "client_name": "Asha Rao"
  }
}
```

- `tool_call_id` is generated once per intent and reused on every retry of it;
  Voice derives the Appointments `Idempotency-Key` from it
  (`voice:ai:<call_id>:<tool_call_id>`). A different request under the same id
  is refused (`422 tool_call_reused`).
- `member_uuid` and `starts_at` come from a `check_availability` slot;
  `starts_at` must carry its offset. The caller's number is the call's own
  (`client_phone` is accepted only when the call has none).
- A consequential action needs `caller_confirmed: true` (`409
  confirmation_required` otherwise). The call must be live and pinned to an AI
  agent version, whose stored permissions decide.

The answer (`200`) carries `outcome` (`slots`, `no_slots`, `booked`,
`booked_pending_confirmation`, `requested`, `slot_taken`, `not_bookable`,
`pending_verification`, `refused`, `unavailable`, `handoff`), `confirmed` (true
only with Appointments' booking in hand), `say` — **the exact sentence the
agent may speak, and nothing stronger** — and `booking`, `slots`,
`alternatives`, `callback`, `operation`, `detail` (for operators; never
spoken). Refusals carry `error.details.say` for the same reason.
