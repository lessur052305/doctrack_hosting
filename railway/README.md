# Deploying to Railway

This app needs **four Railway services** pointed at the same GitHub repo (`doctrack_hosting`), each with a different **Custom Start Command**, plus a MySQL database. Railway's own build detection (Railpack) already handles the web service correctly — the other three just override the start command on an otherwise-identical build.

| Service | Custom Start Command | Why it's separate |
|---|---|---|
| **web** (existing) | *(leave as Railway auto-detected — do not override)* | Serves the actual app over HTTP |
| **queue-worker** | `sh railway/start-queue-worker.sh` | Sends queued mail and processes delayed SLA-escalation jobs — see [`start-queue-worker.sh`](start-queue-worker.sh) |
| **reverb** | `sh railway/start-reverb.sh` | WebSocket server — every dashboard's real-time push depends on this running | 
| **scheduler** | `sh railway/start-scheduler.sh` | Runs the 5-minute SLA sweeps and the daily backup | 

To create each: **Railway dashboard → New → GitHub Repo → pick `doctrack_hosting` again** (same repo, new service) → **Settings → Deploy → Custom Start Command** → paste the command from the table above. Each one rebuilds from the same source but runs a different process at boot.

## Environment variables

Set these on **every** service (Railway lets you share a variable group across services — do that rather than pasting them four times):

```
APP_NAME=DocTrack
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Manila
APP_URL=https://<your-web-service's-public-domain>.up.railway.app
APP_KEY=                         # generate below, do not leave blank

DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQL_HOST}}          # or whatever Railway names your MySQL plugin's variables
DB_PORT=${{MySQL.MYSQL_PORT}}
DB_DATABASE=${{MySQL.MYSQL_DATABASE}}
DB_USERNAME=${{MySQL.MYSQL_USER}}
DB_PASSWORD=${{MySQL.MYSQL_PASSWORD}}

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=                   # any number, e.g. 100001 — just needs to match across services
REVERB_APP_KEY=                  # random string — generate once, reuse everywhere
REVERB_APP_SECRET=               # random string — generate once, reuse everywhere
REVERB_HOST=<your-reverb-service's-public-domain>.up.railway.app
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

**Two things that trip people up here:**

1. **`REVERB_HOST` must be the Reverb service's own public domain, not the web service's.** Give the `reverb` service a public domain first (Railway → that service → Settings → Networking → Generate Domain), copy it, *then* set `REVERB_HOST` to it everywhere.
2. **The `VITE_REVERB_*` variables get baked into the JS bundle at build time**, not read at runtime — this app already avoids the worst version of this problem (`resources/js/echo.js` uses `window.location.hostname` for the WebSocket host dynamically instead of trusting the build-time value), but `VITE_REVERB_APP_KEY`/`PORT`/`SCHEME` still need to be correct *before* the web service's build runs. If you set or change any `REVERB_*` variable, trigger a fresh deploy of the **web** service afterward — a variable change alone doesn't rebuild already-compiled assets.

## Generating `APP_KEY`

Run this once, locally, and paste the output into every service's `APP_KEY` variable (all four services must share the exact same key — it's what encrypts sessions and cookies):

```bash
php artisan key:generate --show
```

## Running migrations

Railway doesn't auto-run migrations on deploy. After the **web** service deploys successfully, open its **Shell** tab (or use `railway run` from the Railway CLI if you have it installed locally) and run:

```bash
php artisan migrate --force
```

`--force` is required because `APP_ENV=production` otherwise refuses to run migrations without it. Re-run this any time you deploy new migrations.

## Order of operations for first deploy

1. Add a MySQL database to the Railway project (Railway → New → Database → MySQL).
2. Create the `web` service (already done) — confirm it builds successfully now that `composer.json`/`composer.lock` are fixed.
3. Generate `APP_KEY` locally, set all the env vars above on `web`.
4. Give `web` a public domain, set `APP_URL` to it.
5. Run `php artisan migrate --force` via the web service's shell.
6. Create the `reverb` service, give **it** a public domain, set `REVERB_HOST` to that domain everywhere, redeploy `web` so the build picks up the correct `VITE_REVERB_*` values.
7. Create `queue-worker` and `scheduler` services with the env vars shared from the same group.
8. Log in as the seeded admin (see main `README.md` → "Demo accounts") and confirm a document upload shows up live without a manual refresh — that confirms Reverb is actually wired correctly end to end.
