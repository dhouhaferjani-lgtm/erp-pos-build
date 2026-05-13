# Secret Rotation — 2026-05-12 (`apps/api/.env.bak`)

> **Status:** OPEN — secrets are exposed in git history until either rewritten or all values revoked.
> **Created:** 2026-05-12 (audit) / acted on 2026-05-13 (remediation M1.1).
> **Source:** `apps/api/.env.bak` was tracked at `dev` tip `127862bd` and earlier. Removed by `dev-remediation/M1.1`.
> **Audit reference:** `docs/superpowers/audits/2026-05-12-dev-go-live-readiness-audit.md` (P0 — Tracked Secret Material).

This document tracks rotation/revocation of every secret-bearing variable that was present in the tracked `.env.bak`. No values are stored here.

## Disposition Of Tracked History

Two options. The first-tenant gate (per plan §M1.1 Step 6 / First-Tenant Ready) requires ONE of these to be true before launch:

- **Option A — History purge.** `git filter-repo --path apps/api/.env.bak --invert-paths` (or BFG) followed by force-push to all remotes and re-clone notice to all collaborators.
- **Option B — Revocation confirmation.** Every value below is revoked at its provider; old credentials no longer authenticate.

Pick one per value, or apply A globally. Until either is complete, treat every value as compromised.

| Decision | Status | Owner | Notes |
| --- | --- | --- | --- |
| Approach (A or B) | TBD | TBD | Default recommendation: A for shared remotes; B as fallback if remotes already replicated. |

## Secret Inventory

Values are not recorded. Severity reflects production impact if the value is real and unrotated. "Sensitive" = anything that authenticates, signs, or encrypts. "Config" = non-secret operational settings.

### Credentials / Keys (high severity — must rotate or purge)

| Variable | Severity | Provider / system | Rotation status | Owner | Verification |
| --- | --- | --- | --- | --- | --- |
| `APP_KEY` | Critical | Laravel encryption / session signing | TBD | TBD | `php artisan key:generate` + redeploy + invalidate active sessions |
| `DB_PASSWORD` | Critical | PostgreSQL `DB_USERNAME` | TBD | TBD | Provider console; revoke and re-issue, restart app pool |
| `REDIS_PASSWORD` | Critical | Redis | TBD | TBD | Provider console; rotate AUTH |
| `MAIL_PASSWORD` | High | SMTP relay (`MAIL_HOST` / `MAIL_USERNAME`) | TBD | TBD | Provider console |
| `AWS_ACCESS_KEY_ID` + `AWS_SECRET_ACCESS_KEY` | Critical | S3-compatible bucket | TBD | TBD | IAM console; revoke key, mint replacement |
| `MEILISEARCH_KEY` | High | Meilisearch master/admin key | TBD | TBD | Provider console; rotate, update consumers |
| `REVERB_APP_SECRET` | High | Reverb websocket app | TBD | TBD | Provider/self-hosted: rotate, update consumers |
| `REVERB_APP_ID` / `REVERB_APP_KEY` | Medium | Reverb websocket app | TBD | TBD | Rotate alongside the secret |
| `SENTRY_LARAVEL_DSN` | Medium | Sentry project DSN | TBD | TBD | Sentry → project keys → revoke + reissue |

### Connection Targets (lower severity but disclose infrastructure)

The tracked file also exposed: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `REDIS_HOST`, `REDIS_PORT`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MEILISEARCH_HOST`, `AWS_ENDPOINT`, `AWS_BUCKET`, `AWS_DEFAULT_REGION`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, `APP_URL`, `CORS_ALLOWED_ORIGINS`.

These do not require rotation but DO reveal production network topology. Decision required:

- [ ] Confirm these hostnames/ports are public-facing or already-known to attackers, so no follow-up action is needed.
- [ ] Or migrate any private hosts to fresh DNS / firewall those endpoints from the public internet.

### Non-Secret Variables (no action)

The remaining variables (`APP_NAME`, `APP_ENV`, `APP_DEBUG`, locales, `BCRYPT_ROUNDS`, `LOG_*`, `BROADCAST_CONNECTION`, `CACHE_STORE`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK`, `SCOUT_DRIVER`, `REDIS_CLIENT`, `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_PATH`, `APP_MAINTENANCE_DRIVER`, `APP_FAKER_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_LOCALE`, `AWS_USE_PATH_STYLE_ENDPOINT`, `VITE_*`, `SENTRY_PROFILES_SAMPLE_RATE`, `SENTRY_TRACES_SAMPLE_RATE`) are configuration only.

## First-Tenant Readiness Gate

This file satisfies the gate when:

- [ ] All "Critical" rows are status `revoked` (Option B) or history is rewritten (Option A).
- [ ] All "High" rows are status `revoked` or `accepted-non-prod-with-owner-signoff`.
- [ ] The history disposition row above has Option A or Option B selected and verified.
- [ ] All collaborators with existing clones have been notified that local history may still contain revoked credentials.
- [ ] Sign-off by security owner: TBD.
