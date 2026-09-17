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
