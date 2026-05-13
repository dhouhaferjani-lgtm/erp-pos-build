# Secret Rotation — 2026-05-12 (`apps/api/.env.bak`)

> **Status:** OPEN — pending provider-side revocation.
> **Created:** 2026-05-12 (audit) — acted on 2026-05-13 (M1.1 file removal) — playbook locked 2026-05-13 (Phase A.1).
> **Source of exposure:** `apps/api/.env.bak` was tracked from commit `aee9892c` (2025-12-27) through commit `7ca37bfa` (2026-05-13, M1.1 removal). The file sat in `dev` history for ~5 months and is still recoverable from any clone that fetched the repo in that window.
> **Audit reference:** `docs/superpowers/audits/2026-05-12-dev-go-live-readiness-audit.md` (P0 — Tracked Secret Material).
> **Prior remediation:** `dev-remediation/M1.1` removed the file from the working tree and tightened ignore patterns. The credentials in the file remained valid until provider-side rotation; that rotation is what this document tracks.

This document tracks rotation/revocation of every secret-bearing variable that was present in the tracked `.env.bak`. No values are stored here.

---

## Disposition Decision — Option B (Revoke At Provider)

| Field | Value |
| --- | --- |
| Selected option | **B — Revoke at provider** |
| Decided by | Release owner (`@otospexsolutions` / `admin@otospex.com`) |
| Decided on | 2026-05-13 |
| Rationale | Two collaborators have local clones containing the leaked history (audit + remediation worktrees). A `git filter-repo` rewrite + force-push of `dev` does not retroactively remove the bytes from those local clones, so it cannot guarantee revocation on its own. Option B revokes the values regardless of who still holds the history. |
| Implication for engineering | The file is gone from current trees (Option A's filesystem half is already in place). The remaining work is provider-side revocation per the inventory below. |
| Implication for collaborators | Any developer with an older local clone is reminded that their `.git/objects` still contains the bytes. Encourage `git clone --depth=1` for fresh setups. No mass re-clone required because Option B makes the bytes useless. |

If circumstances change (e.g., we discover the leak reached a public mirror), escalate to Option A in addition: run `git filter-repo --path apps/api/.env.bak --invert-paths`, force-push every shared remote, and require collaborators to re-clone. That decision changes the destructive blast radius and requires release-owner approval first.

---

## Acknowledged Scope Limit

The Phase A.1 engineering worker (`chore/dev-go-live-remediation-2`) operates from a developer terminal without production credentials. The worker can:

- Lock the disposition above.
- Pre-populate per-row playbook commands and verification checks.
- Set owners and re-check dates.
- Rotate local-dev secrets if requested (not done by default because local rotation does not affect production exposure).

The worker CANNOT, from this terminal:

- Revoke an AWS IAM access key.
- Rotate a Sentry project DSN.
- Rotate the SMTP provider password.
- Reset a managed Redis or Postgres credential.
- Rotate the production `APP_KEY` on the live server.

Each row below carries an explicit `executor` field naming who must run the revocation against production.

---

## Secret Inventory

Severity reflects production impact if the value is real and unrotated. "Sensitive" = anything that authenticates, signs, or encrypts.

### Credentials / Keys (high severity — must rotate)

Every row below requires `status = revoked` before the first-tenant gate closes.

| Variable | Severity | Provider / system | Status | Executor / owner | Playbook |
| --- | --- | --- | --- | --- | --- |
| `APP_KEY` | Critical | Laravel encryption / session signing | `pending-provider-rotation` | Release owner (prod-server access) | See [Playbook 1](#playbook-1--app_key) |
| `DB_PASSWORD` | Critical | PostgreSQL — production database account | `pending-provider-rotation` | Release owner (DB admin) | See [Playbook 2](#playbook-2--db_password) |
| `REDIS_PASSWORD` | Critical | Redis — production cache / queue / Horizon | `pending-provider-rotation` | Release owner (Redis admin) | See [Playbook 3](#playbook-3--redis_password) |
| `MAIL_PASSWORD` | High | SMTP relay (`MAIL_HOST` / `MAIL_USERNAME`) | `pending-provider-rotation` | Release owner (SMTP provider console) | See [Playbook 4](#playbook-4--mail_password) |
| `AWS_ACCESS_KEY_ID` + `AWS_SECRET_ACCESS_KEY` | Critical | S3-compatible bucket (`AWS_ENDPOINT`) | `pending-provider-rotation` | Release owner (IAM console) | See [Playbook 5](#playbook-5--aws-keys) |
| `MEILISEARCH_KEY` | High | Meilisearch master / admin key | `pending-provider-rotation` | Release owner (Meilisearch admin) | See [Playbook 6](#playbook-6--meilisearch_key) |
| `REVERB_APP_SECRET` | High | Reverb websocket app secret | `pending-provider-rotation` | Release owner (Reverb config) | See [Playbook 7](#playbook-7--reverb-app-trio) |
| `REVERB_APP_ID` / `REVERB_APP_KEY` | Medium | Reverb websocket app id/key (paired with secret) | `pending-provider-rotation` | Release owner (Reverb config) | Rotate alongside `REVERB_APP_SECRET` — see [Playbook 7](#playbook-7--reverb-app-trio) |
| `SENTRY_LARAVEL_DSN` | Medium | Sentry project DSN | `pending-provider-rotation` | Release owner (Sentry console) | See [Playbook 8](#playbook-8--sentry_laravel_dsn) |

### Connection Targets (configuration, no rotation but disclose topology)

The tracked file also exposed these connection targets:

`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `REDIS_HOST`, `REDIS_PORT`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MEILISEARCH_HOST`, `AWS_ENDPOINT`, `AWS_BUCKET`, `AWS_DEFAULT_REGION`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, `APP_URL`, `CORS_ALLOWED_ORIGINS`.

Decision required by the release owner:

- [ ] Confirm these hostnames/ports are public-facing or already-known to attackers (no follow-up).
- [ ] Or migrate private hosts to fresh DNS / firewall those endpoints from the public internet.

Default presumption (pending owner confirmation): these are public-facing endpoints behind the standard Dokploy/Cloudflare edge. No additional action.

### Non-Secret Variables (no action)

`APP_NAME`, `APP_ENV`, `APP_DEBUG`, locales, `BCRYPT_ROUNDS`, `LOG_*`, `BROADCAST_CONNECTION`, `CACHE_STORE`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK`, `SCOUT_DRIVER`, `REDIS_CLIENT`, `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `APP_MAINTENANCE_DRIVER`, `APP_FAKER_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_LOCALE`, `AWS_USE_PATH_STYLE_ENDPOINT`, `VITE_*`, `SENTRY_PROFILES_SAMPLE_RATE`, `SENTRY_TRACES_SAMPLE_RATE` are configuration only.

---

## Playbooks

Each playbook is the exact procedure the executor runs against the relevant provider. After successful execution, the executor updates the row above from `pending-provider-rotation` to `revoked` and records the verification evidence (provider screenshot path / timestamp) at the bottom of this document.

### Playbook 1 — `APP_KEY`

Goal: invalidate the previously-leaked Laravel encryption key, replace with a fresh value, redeploy, force re-issue of sessions and encrypted-column reads.

```bash
# On production app server (or wherever the live .env is managed):
cd /path/to/apps/api
php artisan key:generate --force                  # writes a fresh base64:... key to .env
php artisan config:cache
php artisan queue:restart
# Clear sessions so leaked-key cookies cannot decrypt:
#   file driver:    rm -f storage/framework/sessions/*
#   redis driver:   php artisan cache:clear --tags=session     # or rotate the prefix
#   database driver: TRUNCATE TABLE sessions;
# Cycle PHP-FPM / Horizon to pick up the new key.
```

Verification:

```bash
php artisan tinker --execute='echo strlen(config("app.key"));'
# expect 51 characters (base64: + 32 raw bytes encoded as base64 = 51)
```

Side-effects to anticipate:

- All active web sessions invalidated → users must log back in.
- Any `Crypt::encrypt(...)` payload stored at rest becomes unreadable. Inventory of encrypted columns (run before rotation): `rg -n "encrypt|Crypt::" apps/api/app`. Confirm whether any production data depends on the old key being decryptable; if so, plan a re-encryption pass.
- Password reset tokens become invalid; advise affected users.

### Playbook 2 — `DB_PASSWORD`

Goal: revoke the leaked PostgreSQL password and rotate the application's connection credential.

```sql
-- As DB admin, against the production cluster:
ALTER USER <app_role> WITH PASSWORD '<new-strong-password>';
-- Optional: log out existing sessions:
SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE usename = '<app_role>' AND pid <> pg_backend_pid();
```

Update `DB_PASSWORD` in the production `.env`, run `php artisan config:cache`, and restart the app pool. Verify:

```bash
php artisan tinker --execute='DB::select("select 1 as ok");'
```

If a separate read-replica role exists, rotate it in the same window.

### Playbook 3 — `REDIS_PASSWORD`

Goal: rotate the production Redis AUTH credential used by cache, queue, Horizon, and sessions.

Managed Redis (DigitalOcean / Upstash / AWS ElastiCache): rotate via provider console → "Reset password" or "Rotate credentials". Self-hosted: edit `redis.conf` `requirepass`, `CONFIG SET requirepass <new>`, and reload.

Update `.env` (`REDIS_PASSWORD`), then:

```bash
php artisan config:cache
php artisan queue:restart            # restart workers
# Horizon: php artisan horizon:terminate (supervisor respawns workers with new env)
```

Verify:

```bash
php artisan tinker --execute='Redis::ping();'   # expect "PONG"
```

### Playbook 4 — `MAIL_PASSWORD`

Goal: revoke the leaked SMTP credential.

Provider console (Mailgun / Postmark / SES / generic SMTP relay): regenerate the SMTP password or rotate the API token used for SMTP. Update `MAIL_PASSWORD` in the production `.env`, redeploy config.

Verify:

```bash
php artisan tinker --execute='Mail::raw("rotation smoke", fn($m) => $m->to("admin@otospex.com")->subject("APP_KEY rotation verification 2026-05"));'
# expect: success log + receipt of the email in admin@otospex.com
```

### Playbook 5 — AWS Keys

Goal: revoke the leaked IAM access key pair and replace it with a fresh one with the same minimal scope.

IAM console: Locate the IAM user whose access key matches the leaked `AWS_ACCESS_KEY_ID`. Create a new access key. Update `.env` with the new pair. Confirm production reads/writes succeed. Then delete the leaked key from IAM (not just deactivate — delete it).

Verify before deletion:

```bash
php artisan tinker --execute='Storage::disk("s3")->put("rotation-smoke-2026-05.txt", "ok"); echo Storage::disk("s3")->exists("rotation-smoke-2026-05.txt") ? "OK" : "FAIL";'
```

Clean up the smoke file afterwards: `Storage::disk("s3")->delete("rotation-smoke-2026-05.txt")`.

### Playbook 6 — `MEILISEARCH_KEY`

Goal: rotate the master/admin key used by Scout / search-index ingestion.

Managed Meilisearch: provider console → API Keys → Reset master key. Self-hosted: stop Meilisearch, set new `MEILI_MASTER_KEY` env, start. New deployment will mint scoped API keys derived from the master.

Update `.env` `MEILISEARCH_KEY`, redeploy, then re-index core models if Scout uses scoped keys:

```bash
php artisan scout:flush "App\\Models\\..."   # per indexed model
php artisan scout:import "App\\Models\\..."
```

Verify:

```bash
php artisan scout:status 2>&1 | head -20
```

### Playbook 7 — Reverb App Trio

Goal: rotate Reverb websocket `APP_ID` / `APP_KEY` / `APP_SECRET` together. They are a coupled triple.

Self-hosted Reverb: edit the Reverb configuration to mint a new app id / key / secret; redeploy Reverb. Update production `.env` with all three. Update the frontend's `VITE_REVERB_APP_KEY` build env and rebuild the SPA. Existing browser sessions reconnect with the new key after page reload.

Verify after redeploy:

```bash
# From the app server:
curl -s -H "Accept: application/json" "https://reverb.example.com/apps/${REVERB_APP_ID}/channels?auth_key=${REVERB_APP_KEY}" | head -c 200
```

### Playbook 8 — `SENTRY_LARAVEL_DSN`

Goal: revoke the leaked Sentry DSN and reissue.

Sentry console → Settings → Projects → [project] → Client Keys (DSN) → Revoke the leaked client key → Generate a new one. Update production `.env`, redeploy.

Verify:

```bash
php artisan tinker --execute='\Sentry\captureMessage("Rotation smoke 2026-05");'
# Confirm the event lands in Sentry's project Issues view.
```

---

## First-Tenant Readiness Gate

This file satisfies the gate when:

- [ ] All "Critical" rows are status `revoked`.
- [ ] All "High" rows are status `revoked` or `accepted-non-prod-with-owner-signoff`.
- [ ] All "Medium" rows are status `revoked` or `accepted-with-owner-signoff`.
- [ ] Connection-target review row (above) is checked off by the release owner.
- [ ] Sign-off by security owner: TBD (record name + date below).

### Verification Evidence

For each completed rotation, append an entry below with the date, executor, verification command output (redacted) or provider-console screenshot reference. Do not paste secret values.

| Row | Executor | Date | Evidence reference |
| --- | --- | --- | --- |
| `APP_KEY` | TBD | TBD | TBD |
| `DB_PASSWORD` | TBD | TBD | TBD |
| `REDIS_PASSWORD` | TBD | TBD | TBD |
| `MAIL_PASSWORD` | TBD | TBD | TBD |
| `AWS_ACCESS_KEY_ID` + `AWS_SECRET_ACCESS_KEY` | TBD | TBD | TBD |
| `MEILISEARCH_KEY` | TBD | TBD | TBD |
| `REVERB_APP_*` | TBD | TBD | TBD |
| `SENTRY_LARAVEL_DSN` | TBD | TBD | TBD |

### Final Sign-Off

| Field | Value |
| --- | --- |
| Security owner | TBD |
| Sign-off date | TBD |
| First-tenant approval | TBD |
