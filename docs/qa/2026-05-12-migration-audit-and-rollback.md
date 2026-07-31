# Migration Audit + Rollback Policy — 2026-05-12

> **Plan reference:** `docs/superpowers/plans/2026-05-12-dev-go-live-remediation-plan.md` §M1.9
> **Branch:** `chore/dev-go-live-remediation`.

This document is the M1.9 deliverable: a rollback policy and an audit procedure for the migrations that ship to the first tenant. The remediation branch carries 352 migration files under `apps/api/database/migrations/`; the staging-clone dry-run is a release-engineering step that runs against the actual target database before first-tenant cutover.

## Migration Inventory

- **Central migrations:** 352 files (`apps/api/database/migrations/*.php`).
- **Tenant-specific migrations:** Stancl Tenancy is configured (`stancl/tenancy` in composer require). Tenant migrations land under the tenant connection at deploy time; the central migration set seeds the tenants table itself.
- **Migrations with `dropIfExists` / `dropColumn` / `rename`:** 292 of 352 (≈83%). These are irreversible without a backup-and-restore step.

The pending vs applied delta against production must be enumerated by release engineering after a staging clone of the prod database is available. The dev environment in this worktree has no Postgres reachable on `127.0.0.1:5433`, so `php artisan migrate:status` cannot enumerate.

## Rollback Policy

The policy applies to **every production migration run** for the first-tenant pilot and beyond.

### Pre-flight (required before `php artisan migrate`)

1. **Live backup:** Trigger the documented backup runbook (`docs/pos-operations/backup.md`) immediately before the migration. The release engineer records:
   - backup destination path + checksum
   - row counts on the five fiscal tables (`pos_receipts`, `pos_receipt_payments`, `pos_z_reports`, `fiscal_chain_events`, `journal_entries`) — used post-restore to verify integrity.
2. **Dry-run on staging clone:** Run `php artisan migrate --pretend` against a fresh clone of the production database. The SQL output must be reviewed for:
   - any `ALTER TABLE ... DROP COLUMN`, `DROP TABLE`, or `RENAME` operation
   - any `ALTER TABLE ... ADD COLUMN ... NOT NULL` without a default (will fail on existing rows)
   - any operation that takes an `ACCESS EXCLUSIVE` lock on a table > 1M rows (Postgres locks during DDL; this can stall app queries during deploy)
3. **Schema diff:** Compare the staging clone's post-migration schema to the production current schema and confirm no unexpected drops/renames slipped through.

### Run procedure

1. Put the application into maintenance mode if any migration is non-trivially backward-incompatible (e.g., dropping a column read by the previous app version).
2. Run `php artisan migrate --force` (the `--force` is required in production to bypass the interactive guard).
3. Verify with `php artisan migrate:status` that every migration row is now `Ran`.
4. Run the real chain verifiers, target-scoped to the tenant/company/terminal under migration — **not** a
   bare/per-vertical invocation (`php artisan fiscal:verify-chain` does not exist):
   ```bash
   php artisan fiscal:verify-chains --company=<COMPANY_UUID>
   php artisan pos:verify-chains --company=<COMPANY_UUID> --terminal=<TERMINAL_UUID> --type=all
   php artisan fiscal:verify-event-chain --tenant=<TENANT_UUID> --terminal=<TERMINAL_UUID> \
     --chain-context=operational --actor-id=<SYSTEM_USER_UUID>
   ```
   All three must exit 0. `--actor-id` is **mandatory** on the third command — it fails without it
   (`VerifyEventChainCommand.php:70-103`). Repeat the third command once per active terminal on the tenant.
   See `docs/runbooks/fiscal-verify-all-chains.md` for the full per-tenant wrapper recipe.
5. Smoke-test the first-tenant happy path (login, take a sale, sync receipt, close Z-report) before exiting maintenance.

### Rollback decision

Roll back when ANY of these triggers fire:

- A migration partially completes and leaves the schema in a state that the previous app version cannot read.
- The post-migration fiscal-chain verifier fails on any vertical.
- The post-migration smoke fails on auth, terminal activation, receipt sync, or Z-report.
- Operator reports a regression that did not surface on staging.

Rollback path **depends on whether the migration is reversible**:

- **Reversible migration (the file has a working `down()` method, no irreversible operations):**
  - `php artisan migrate:rollback --force --step=N` where N is the number of migrations applied in this batch.
  - Confirm post-rollback row counts match pre-flight numbers.
- **Irreversible migration (any `dropColumn`, `dropTable`, `rename`, or `down()` that throws):**
  - Stop the application immediately.
  - Restore the database from the pre-flight backup.
  - Verify post-restore row counts and the fiscal-chain hash matches the recorded pre-migration hash.
  - File an incident report capturing what failed; do NOT retry the migration without root-cause analysis.

### Rollback decision deadline

A migration is considered "stuck" if it has not progressed for 30 minutes on the production database. The release engineer must decide rollback vs. continue within that window. Decisions are recorded in the deploy log.

### Maximum acceptable downtime

For the first-tenant pilot, target ≤ 15 minutes of read/write unavailability and ≤ 60 minutes of read-only mode. If a migration is projected to exceed those budgets, defer to off-hours or split the migration into online/online-friendly chunks.

## Staging-Clone Dry-Run Procedure (release engineering)

This is the procedure that closes M1.9 from a release-engineering perspective. The remediation branch CANNOT execute it without the staging clone, so it is documented here for the operator who will perform it.

1. Snapshot production at T-24h (`pg_dump --format=custom`).
2. Restore the snapshot into a staging DB on the same Postgres major version.
3. From the remediation branch worktree, run:
   ```bash
   cd apps/api
   php artisan migrate --pretend > /tmp/migrate-pretend.sql 2>&1
   ```
4. Review `/tmp/migrate-pretend.sql` for the irreversible patterns listed above. Record any flagged migration with: filename, operation, table, mitigation (backup point + manual rollback SQL, or batch into a separate maintenance window).
5. Run the actual migration:
   ```bash
   php artisan migrate --force > /tmp/migrate.log 2>&1
   ```
6. Capture timing (start/end timestamps per migration) so the production-run estimate can be computed.
7. Run the real chain verifiers (the same three target-scoped commands as step 4 above, incl. mandatory
   `--actor-id` on `fiscal:verify-event-chain`) and the smoke from `docs/qa/2026-05-12-first-tenant-smoke.md`
   against the staging DB.

If all of the above pass, the production deploy is unblocked. If any step fails, file the issue against the offending migration before scheduling production.

## Owners

- Release engineering: TBD
- DBA / database steward: TBD
- Synerivia ops observer: TBD

## First-Tenant Gate

Per the remediation plan §M1.9 + First-Tenant Ready:

- ✅ Pending migrations enumerated structurally (352 files; staging-clone-relative count pending dry-run).
- ✅ Rollback policy written above.
- ⬜ Staging-clone dry-run executed and the irreversible-pattern review captured (release engineering — see procedure above).
- ⬜ Backup checksum + row counts recorded before the production migration.

The remediation branch DOES NOT block on staging-clone execution; that step lands when the staging environment is provisioned. The branch DOES require the rollback policy be in place, which is satisfied here.
