# Post-flip Tenant Operations

> Operational runbook for the database-per-tenant world (T6 Phase 0b).
> Backup/restore + PgBouncer + monitoring.
> Last updated: 2026-05-28.

See `BACKUP-RECOVERY.md` for the legacy single-DB scripts (still relevant for
the **central** database — `synerivia_central` — which holds the tenant
directory and is NOT swapped by Stancl).

---

## 1. Per-tenant backups (`tenant:backup`)

```bash
# Back up one tenant by slug
php artisan tenant:backup acme

# Back up every tenant in the central directory
php artisan tenant:backup --all
```

**What it does**

- Runs `pg_dump --format=custom --no-owner --no-acl` against the tenant's
  database, writing to `<TENANT_BACKUP_ROOT>/<tenant-uuid>/<YYYYMMDD-HHMMSS>.dump`.
- Defaults to `storage/app/tenant-backups`; override with `TENANT_BACKUP_ROOT`
  (e.g. a mounted volume on the production host).
- Records every attempt to the central `tenant_backups` table:
  `status` (`in_progress` → `completed` | `failed`), `file_path`,
  `file_size_bytes`, `sha256`, `started_at`, `completed_at`, `error_message`.
- Applies retention after each successful backup — keeps the last
  `TENANT_BACKUP_KEEP` (default 14) and deletes older files + rows.
- Gated on `TENANCY_DB_PER_TENANT=true`. In compat mode the command throws
  (there's no per-tenant database to dump).

**What it does NOT do (deliberate launch-day scope)**

- No S3 / object-store mirroring. Add a follow-up sidecar (e.g. `rclone`
  cronjob from `<TENANT_BACKUP_ROOT>` → bucket) once the first paying
  tenant lands.
- No encryption beyond filesystem permissions.

## 2. Per-tenant restore (`tenant:restore`)

```bash
php artisan tenant:restore acme /var/backups/autoerp/<uuid>/20260601-031500.dump
```

**Destructive.** Drops the per-tenant database and recreates it before
`pg_restore` runs. Any data not in the dump is lost. The command prompts
for confirmation unless `--force` is passed.

**Sha256 verification.** If a `tenant_backups` row exists for `file_path`,
the service recomputes the file's SHA-256 and compares it to the stored
value before touching the database; mismatch aborts. Hand-copied dumps
(no central row) log a warning and proceed — trust is on the operator who
ran the command.

## 3. Routine backup schedule

Use the host's cron to run `tenant:backup --all` daily.

```cron
# /etc/cron.d/autoerp-tenant-backups
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
0 3 * * * www-data cd /var/www/autoerp/apps/api && /usr/bin/php artisan tenant:backup --all >> /var/log/autoerp/tenant-backup.log 2>&1
```

Pair with monitoring on `tenant_backups.completed_at` (see §5) — if a
tenant's last successful backup is older than a day, alert.

## 4. PgBouncer

Local docker-compose ships a PgBouncer service in **transaction-pool mode**
(the only mode compatible with database-per-tenant — session-mode would pin
a request to a single backend DB).

```bash
docker compose up -d pgbouncer
# Connect through the pool:
psql -h 127.0.0.1 -p 6432 -U autoerp synerivia_central
```

**Application config**

In every environment that connects through PgBouncer, set:

```env
DB_HOST=<pgbouncer-host>
DB_PORT=6432         # or whatever PGBOUNCER_PORT maps to
DB_PGBOUNCER=true    # enables PDO::ATTR_EMULATE_PREPARES (required)
```

**Transaction-pool mode caveats**

- Cross-transaction state does not persist: `LISTEN`/`NOTIFY`, session-level
  prepared statements, advisory locks, temp tables outside an explicit
  transaction, and `SET` outside `SET LOCAL` all break.
- Laravel queries the database through PDO; the existing `DB_PGBOUNCER=true`
  knob disables real prepared statements (uses query interpolation instead),
  which is the supported pattern for transaction-pool mode.

**CREATE / DROP DATABASE must bypass PgBouncer.**

Tenant signup (`CreateDatabase` job) and `tenant:deprovision` /
`tenant:restore` issue DDL that can fail or behave unpredictably through
a transaction pool. In production:

- Run signup/deprovision/restore from a host whose `DB_HOST` points at the
  backend Postgres directly (not PgBouncer), OR
- Maintain a separate ops env (`.env.ops`) where `DB_HOST` is the direct
  Postgres host. Use `php artisan --env=ops tenant:create` etc.

(Single-env shops with low signup volume can also just leave PgBouncer
in place — DDL through transaction-pool mode works in practice for most
cases, but the failure modes are subtle when they occur. Documenting the
safer pattern.)

## 5. Monitoring

See A4.3 (next commit) — `tenant:status` artisan + `/api/admin/tenants/health`
JSON endpoint. Surfaces per tenant:

- Does the per-tenant database physically exist?
- Database size (via `pg_database_size`).
- Active backend connection count (`pg_stat_database.numbackends`).
- Last successful backup timestamp + age (from `tenant_backups`).
- Last failed backup timestamp + error message (from `tenant_backups`).

**Why not PostHog / Prometheus right now?** PostHog is product analytics —
it indexes user sessions and funnels, not Postgres metrics. Prometheus +
Grafana is the right answer when we have several paying tenants and want
trends, but standing up that stack pre-launch is overkill for the parapharmacy
scope. The artisan + JSON endpoint gives us the signal a human operator
actually needs (and an alerting cron can scrape the endpoint trivially).
