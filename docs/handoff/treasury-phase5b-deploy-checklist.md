# Treasury Phase ⑤b — deploy checklist

Date: 2026-07-20
Scope: bank-statement import, void-aware deduplication, matching, reconciliation checkpoints, replacement UI, and legacy cutover.

This checklist is created at Gate 2 and must be extended if Waves 3–5 add deploy obligations. It stacks on the Phase ⑤a checklist; do not omit its migration, seeding, cache-reset, worker-restart, or verification steps.

## Binding void and deduplication interpretation

> §5.1's file-sha256 uniqueness and line-fingerprint uniqueness range over **active** rows only: statements with `status <> 'voided'`, and lines with `dedupe_active = true`. The invariant's purpose — its own words — is that re-import is "rejected, not silently deduped to zero lines": it prevents duplicate *live* imports; it does not permanently consume a file identity after a §5.3.4 void. Void is a pure status transition: nothing is deleted; a voided statement's lines are retained verbatim for audit but cease participating in dedupe. At most one non-voided statement may exist per (repository, sha256); re-importing the identical file after void is permitted and creates a new active statement.

This is the mandatory Gate 2 Fable interpretation of locked Rev 2. Do not restore unconditional deduplication or delete voided statement lines.

## Before deployment

- [ ] Back up the central database and every tenant database using the normal release procedure.
- [ ] Confirm all Phase ⑤b tenant migrations are present. The Gate 2 set is:
  1. `2026_07_19_110000_create_statement_import_profiles.php`
  2. `2026_07_19_110001_create_bank_statements.php`
  3. `2026_07_19_110002_create_bank_statement_lines.php`
  4. `2026_07_19_110003_create_bank_statement_line_allocations.php`
  5. `2026_07_19_110004_create_bank_statement_match_executions.php`
  6. `2026_07_19_110005_make_statement_deduplication_void_aware.php`
  7. `2026_07_19_110006_add_matching_window_to_statement_profiles.php`
  8. `2026_07_19_110007_add_checkpoint_flag_to_repository_movements.php`
- [ ] Treat `110005` as mandatory even on developer/preview databases that already ran `110000`–`110004`; it converts the two unconditional Gate 1 indexes to void-aware partial indexes and adds `dedupe_active`.
- [ ] Provision `storage/app/private/bank-statements` on node-stable shared storage. Upload preview and confirm are separate requests and may hit different replicas; ephemeral per-node storage causes valid confirms to fail.
- [ ] Define and enable an abandoned-preview retention/cleanup process before enabling statement upload. It must delete only unreferenced staged files older than the approved retention window; a path referenced by any `bank_statements.source_file_path`, including a voided statement, is audit evidence and must be retained.
- [ ] Leave `TREASURY_ACQUIRER_FEE_VAT_RATE` unset or set it to the launch value `0.000`. Any non-zero value intentionally fails closed until the Phase ④ VAT-split posting is explicitly wired; do not bypass that guard.
- [ ] For every card method eligible for net-settlement suggestions, confirm it is active, has `has_deducted_fees=true`, has an active expense `fee_account_id`, and maps through `default_repository_id` to the exact active GL-linked bank repository receiving the statement.

## Deploy order

Run from `apps/api` on the released revision:

```bash
php artisan tenants:migrate --force
php artisan tenants:run db:seed --option='class=Database\Seeders\RolesAndPermissionsSeeder' --option='force=1'
php artisan tenants:run permission:cache-reset
```

Then restart long-running API, scheduler, Horizon, and queue processes. Require affected users to refresh server-authoritative permission claims.

The permission steps are load-bearing. Phase ⑤b adds `bank-statements.view`, `bank-statements.import`, `bank-statements.reconcile`, and `bank-statements.reopen`; skipping tenant reseeding or the tenant-context cache reset silently leaves accountants forbidden.

## Database verification before traffic

For every tenant database:

- [ ] No Phase ⑤b migration is pending.
- [ ] `bank_statement_lines.dedupe_active` is `NOT NULL DEFAULT true`.
- [ ] `statement_import_profiles.matching_window_days` is `NOT NULL DEFAULT 5` with the `0..30` database bound.
- [ ] `repository_movements.recorded_behind_checkpoint` is `NOT NULL DEFAULT false`; do not rename or reuse `recorded_while_frozen`.
- [ ] `bank_statements_repository_file_unique` is partial on `status <> 'voided'`.
- [ ] `bank_statement_lines_repository_fingerprint_unique` is partial on `dedupe_active`.
- [ ] Admin has all four statement permissions; accountant has view/import/reconcile but not reopen; manager has none.

## Runtime verification

- [ ] Import a controlled CSV or XLSX statement and confirm preview reports accepted, duplicate, dropped-zero, and unparseable counts without creating GL entries or repository movements.
- [ ] Confirm it and verify statement plus lines are atomic and status `Imported`.
- [ ] Void a zero-allocation/zero-execution statement; verify its lines and source file remain visible with `dedupe_active = false`.
- [ ] Re-import the same file; verify one new active statement contains the full line set and no duplicate live file exists.
- [ ] Confirm a tier-4 card batch containing a refund: verify grouping stays within one payment method and fiscal business date, the dedicated entry is Dr method fee account / Cr exact repository GL account in journal `BQ`, and the signed allocations close `gross - refunds - fee = statement net`.
- [ ] Unmatch and reconfirm that batch; verify the immutable execution, fee movement, and fee journal are reused rather than duplicated.
- [ ] Create an expense and an income from controlled statement lines; verify document, journal, and repository movement dates equal the statement value date, location comes from the line, and income credits the explicitly selected active revenue account.
- [ ] Complete statements in period order and verify `last_reconciled_at` represents end-of-day `period_end` in the company timezone and `last_reconciled_balance` equals the statement closing balance.
- [ ] Verify a new interactive `record()` and `transfer()` on the reconciled-through business date are rejected before any movement write; verify an exact replay of a pre-existing keyed movement still returns the original row.
- [ ] Drive one controlled offline-device projection behind the checkpoint; verify the movement is preserved with `recorded_behind_checkpoint = true`, `recorded_while_frozen` remains independent, and the warning/audit payload is emitted.
- [ ] Reopen only the latest reconciled statement as an admin; verify accountant receives 403, the statement returns to `Reconciling`, and the repository checkpoint recomputes to the latest remaining reconciled statement without a gap.
- [ ] Complete the Wave 3–5 matching, checkpoint, UI, and legacy-cutover checks added before final handback.

## Rollback and retention notes

- Prefer an application rollback while retaining additive Phase ⑤b schema. Rolling `110005` down can fail after a legitimate void/re-import because unconditional indexes cannot represent both the preserved voided identities and their active replacements.
- Never delete statement lines, allocations, execution provenance, referenced source files, GL entries, or repository movements to repair an import. Use the authorized void/reopen/compensating workflow.
- A missing partial predicate, a non-shared private disk, permission-cache drift, or absent abandoned-preview cleanup is a deployment stop.
