# Staging deploy runbook — Treasury ③④⑤a⑤b + multi-location owes

**Date:** 2026-07-28 · **Audience:** developer running the staging pass · **Est. time:** 2–4 h

Staging auto-deploys on every push to `origin/dev`, including `php artisan tenants:migrate`.
So **all schema migrations have already run**. What has NOT run is everything below:
guarded data backfills, permission reseeding, cache resets, process restarts, environment
prep, and verification. Until §4 runs, the new treasury screens are invisible to non-admin
users — the permissions literally don't exist in the tenant DBs yet.

Every `php artisan` command below runs from `apps/api` **on the staging release**, in the
tenant-aware context (the same shell/container the deploy pipeline uses). Nothing here runs
against production.

Source checklists (in `docs/handoff/`) if you need depth on any step:
`treasury-phase3-deploy-checklist.md`, `treasury-phase4-deploy-checklist.md`,
`treasury-phase5a-deploy-checklist.md`, `treasury-phase5b-deploy-checklist.md`,
`multiloc-deploy-checklist.md`. **This runbook is the ordered union of their open items.**

---

## 0. Preflight

- [ ] Back up the central DB and every tenant DB per the normal release procedure.
- [ ] `php artisan tenants:migrate --force` → expect "Nothing to migrate" everywhere.
      Any pending migration = stop; the deploy didn't finish.
- [ ] Horizon/queue workers healthy (no new queues are introduced by these phases).
- [ ] No tenant has an unresolved treasury freeze or portfolio drift:
      `php artisan treasury:reconcile --tenant=<uuid>` per tenant → exit 0, all zeros.

## 1. One-time environment prep (Phase ⑤b)

- [ ] `storage/app/private/bank-statements` must live on **node-stable shared storage**.
      Upload preview and confirm are separate HTTP requests and may hit different
      replicas; per-node ephemeral storage makes valid confirms fail.
- [ ] Env: leave `TREASURY_ACQUIRER_FEE_VAT_RATE` **unset** (or `0.000`). Non-zero
      intentionally fails closed — do not "fix" that by bypassing the guard.
- [ ] Env: leave `TREASURY_STATEMENT_STALE_DAYS` unset (30-day default) unless an
      operational value has been approved; document any override.
- [ ] Schedule the abandoned-preview cleanup: delete only **unreferenced** staged
      statement files older than the retention window. Any path referenced by
      `bank_statements.source_file_path` — including voided statements — is audit
      evidence and must never be deleted.

## 2. Guarded backfills (order matters; grep gates are mandatory)

`tenants:run` does **not** propagate a child command's failure — the grep checks after
each log are the actual release gates. Keep all four logs with the deployment evidence.

### 2a. Payable instrument accounts (Phase ⑤a — chart codes 403/4035)

```bash
php artisan tenants:run treasury:backfill-payable-instrument-accounts --option='dry-run=1' 2>&1 | tee treasury-backfill-dry-run.log
grep -Eqi 'Tenant treasury tables are unavailable|missing supplier parent account|has wrong type|is inactive|[1-9][0-9]* invalid account\(s\)' treasury-backfill-dry-run.log && echo "STOP — investigate" || echo OK
php artisan tenants:run treasury:backfill-payable-instrument-accounts 2>&1 | tee treasury-backfill.log
grep -Eqi 'Tenant treasury tables are unavailable|missing supplier parent account|has wrong type|is inactive|[1-9][0-9]* invalid account\(s\)' treasury-backfill.log && echo "STOP — investigate" || echo OK
```

Any dry-run hit (missing supplier parent, inactive/wrong-type existing `403`/`4035`) is a
stop — investigate, never hand-edit deployed chart rows.

### 2b. CARD → repository routing (Phase ⑤b)

Pick the real, active, GL-linked **bank repository code** the tenant's companies settle
cards into (ask the owner if unsure — do NOT guess and do NOT rely on fallback routing).
If different tenants need different codes, run explicit tenant batches with `--tenants=`.

```bash
php artisan tenants:run treasury:configure-method-routing --option='card-to=<REPO-CODE>' --option='dry-run=1' 2>&1 | tee treasury-routing-dry-run.log
grep -Eqi 'Tenant Treasury routing tables are unavailable|[1-9][0-9]* invalid company configuration\(s\)' treasury-routing-dry-run.log && echo "STOP" || echo OK
php artisan tenants:run treasury:configure-method-routing --option='card-to=<REPO-CODE>' 2>&1 | tee treasury-routing.log
grep -Eqi 'Tenant Treasury routing tables are unavailable|[1-9][0-9]* invalid company configuration\(s\)' treasury-routing.log && echo "STOP" || echo OK
```

### 2c. Multi-location financial attribution (multiloc Wave 3)

- [ ] First assign cash registers and safes to locations explicitly in the UI/API
      (bank repositories may stay company-level / NULL).
- [ ] Then: `php artisan tenants:run treasury:backfill-location-attribution`
- [ ] Check deploy logs for `multiloc.backfill.skipped_ambiguous_users`. For each
      skipped multi-company user, choose the company explicitly:
      `php artisan tenants:run users:backfill-memberships --tenants=<uuid> --option=company=<companyId> --option=user=<userId>`
      Never bulk-grant every skipped user into one company.

## 3. Permissions + restart (covers ④, ⑤a, ⑤b, multiloc in one pass)

```bash
php artisan tenants:run db:seed --option='class=Database\Seeders\RolesAndPermissionsSeeder' --option='force=1'
php artisan tenants:run permission:cache-reset
```

- The cache reset must run **inside every tenant** — the Spatie cache key is
  tenant-blind; a central-only reset silently leaves accountants forbidden.
- Then **restart API, scheduler, and Horizon/queue processes**. Required for the fiscal
  projection's per-method routing and the Expense/Treasury event listeners — not just UI.
- Users must **sign in again** (tokens minted before the reseed don't carry the new
  server-authoritative permission claims).

Expected grants afterwards: admin = everything; accountant gains
`bank-statements.view/import/reconcile` (NOT `reopen` — admin-only),
`instruments.clear-outbound`, `instruments.cancel-outbound`, `expenses.export`,
`expense-recurrences.*`; manager gains none of the statement permissions.

## 4. Verification before declaring done

Per tenant (the demo tenant is fine as the primary):

**Phase ③/④ (quick):**
- [ ] Transfer a small amount between two repositories → both balances move, two
      movement legs, one balanced JE. (③)
- [ ] Scheduler picked up `expenses:generate-recurring` (check `schedule:list`). (④)
- [ ] `VatDeductible` purpose resolves to account `4456` on each staging tenant —
      verification only, no backfill. (④)

**Phase ⑤a (API-driven — no dedicated outbound UI buttons this phase):**
- [ ] `403`/`4035` exist: active, liability type, system-managed, correct supplier parent.
- [ ] Issue one deferred-supplier cheque → exactly one JE (Dr supplier / Cr 4035), **no**
      bank line, **no** repository movement at issue.
- [ ] Clear it → one bank-credit JE + one outbound movement + balance change.
- [ ] Bounce + re-present → append-only compensating entries; event history shows
      `re_presented`.
- [ ] Settle a posted expense by cheque: issue leaves unpaid, clear marks paid.
- [ ] `/treasury/instruments` shows separate receivable/payable schedules.

**Phase ⑤b:**
- [ ] Upload a controlled CSV via `/treasury/statements` → preview shows
      accepted/duplicate/dropped-zero/unparseable counts, **no** GL/movement writes.
- [ ] Confirm import → statement `Imported`, lines atomic.
- [ ] Reconcile it through the workspace: Tier 1 exact match, create an expense from a
      line, ignore one line with explanation, complete with signed acknowledgment →
      `Reconciled`, zero remaining.
- [ ] Reopen as admin (accountant must get 403) → statement back to `Reconciling`,
      checkpoint recomputed.
- [ ] Legacy bank-reconciliation mutation/summary routes return 404; Finance Hub card
      opens `/treasury/statements`.
- [ ] Optional but recommended — run the shipped smoke against staging:
      `cd apps/web && STAGING_URL=<staging-web-url> TREASURY_PHASE5B_BASE_URL=<staging-web-url> TREASURY_PHASE5B_API_BASE=<staging-api>/api/v1 pnpm exec playwright test --config playwright.smoke.config.ts e2e/smoke/treasury-phase5b-reconciliation.smoke.ts --project=chromium`
      (it self-provisions a `SMOKE-*` bank repository if no clean fixture exists).
- [ ] Final gate, per tenant: `php artisan treasury:reconcile --tenant=<uuid>` →
      exit 0, **zero freezes, zero portfolio drift, zero statement alerts, zero errors**.
- [ ] `php artisan treasury:instrument-maturity-alerts` once in the scheduler context.

## 5. Unrelated stacked owes (same staging pass, non-treasury — optional but pending)

- [ ] `php artisan tenants:run db:seed --option='class=Database\Seeders\DemoPharmacySeeder' --option='force=1'`
      on the demo tenant (replenishment + UoM demo data refresh).
- [ ] POS devices: update to sqlite schema v62 (stacks v60/v61) — owner coordinates.

## Stop conditions

Any of these = stop and escalate, do not improvise: a grep gate match; a chart-backfill
error; non-zero `treasury:reconcile` exit; an unexpected freeze or portfolio drift; a
pending tenant migration. **Never** repair data by editing journal entries, repository
balances, movements, instrument events, or statement lines — every correction goes
through the authorized compensating lifecycle actions (see the rollback sections of the
source checklists).
