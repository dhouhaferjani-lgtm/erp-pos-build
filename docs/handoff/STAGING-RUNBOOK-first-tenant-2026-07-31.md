# Staging Runbook — First Tenant — 2026-07-31

> Consolidates the 7 open staging checklists into ONE ordered, idempotent runbook per
> `docs/handoff/DISPATCH-PLAN-v5-first-tenant-2026-07-31.md` §Lane D2-a task 1. This is gate **E-9** in
> `docs/handoff/OWNER-manual-launch-gates-2026-07-31.md`. **This document is authored, not executed** —
> every Evidence cell below is intentionally empty. No command in this file has been run by Lane D2-a.

**Source checklists (union, in execution order):**
`docs/handoff/treasury-phase3-deploy-checklist.md`, `docs/handoff/treasury-phase4-deploy-checklist.md`,
`docs/handoff/treasury-phase5a-deploy-checklist.md`, `docs/handoff/treasury-phase5b-deploy-checklist.md`,
`docs/handoff/multiloc-deploy-checklist.md`, plus the three standalone owes: `RolesAndPermissionsSeeder` +
`permission:cache-reset` (the Spatie permission cache is **tenant-blind** — a central-only reset silently
leaves accountants/managers forbidden), a Horizon/API/scheduler restart, and a `DemoPharmacySeeder --force`
rerun on the demo tenant. This runbook supersedes `docs/handoff/STAGING-DEPLOY-RUNBOOK-2026-07-28.md` as
the authoritative ordered union (that file remains as historical record; do not execute it separately).

**Environment:** staging auto-deploys on every push to `origin/dev`, including `php artisan tenants:migrate`
— so by the time this runbook is executed, **schema migrations have already run**. Everything below is what
does NOT run automatically: guarded data backfills, permission reseeding, cache resets, process restarts,
environment prep, and verification. Every `php artisan` command runs from `apps/api` **on the staging
release**, in the tenant-aware context (the same shell/container the deploy pipeline uses). Nothing here
runs against production — production cutover is gate **E-10**, a separate document.

**Idempotency:** every backfill command below is written to be safely re-run (dry-run gates first, guarded
apply second); re-running this whole runbook against an already-compliant staging environment is expected
to produce "nothing to do" / zero-diff output at each step, not an error.

**RERUN-ON-FINAL-CANDIDATE:** per the dispatch plan's Phase-E dependency chain, gate **E-9** requires this
runbook to execute on the FINAL release candidate — earlier-revision evidence cannot close it. Every step
below is flagged `RERUN-ON-FINAL-CANDIDATE: YES`, because every step is either release-code-sensitive
(backfill/permission/verification logic tied to the deployed code) or state-sensitive (must reflect the DB
state at the moment of the final candidate's promotion). An "already-done" marking from an earlier candidate
does not close any step here regardless of its own historical evidence.

**Already-done markings (◆FIX-2):** a step may be marked already-done only with immutable historical
evidence — environment, exact revision/artifact, command + output, and timestamp — and even then it does
NOT satisfy a `RERUN-ON-FINAL-CANDIDATE` step (see above). **As of 2026-07-31, no step in this runbook has
such evidence on file.** Lane D2-a searched `docs/sessions/`, `docs/handoff/`, and the treasury/multiloc
memory records for a prior staging execution of these specific commands and found none — the closest
artifacts are `docs/handoff/STAGING-DEPLOY-RUNBOOK-2026-07-28.md` (predecessor of this file, all boxes
unchecked, no evidence recorded) and local/dev-environment E2E evidence (`docs/sessions/treasury-phase5a-e2e/`,
`docs/sessions/treasury-phase5b-e2e/`) which is **not** staging evidence. Every step below is therefore
**OPEN**, not already-done.

---

## Corrections against the source checklists (accuracy pass)

Lane D2-a verified every command below against its command class. One correction was required:

- **`treasury:backfill-location-attribution` requires `--company=` (single company per invocation) — it is
  NOT a fleet-wide command.** `BackfillLocationAttributionCommand.php:20` declares
  `protected $signature = 'treasury:backfill-location-attribution {--company= : Company id (required)}'`
  and `handle()` returns `self::FAILURE` immediately if `--company` is missing or the company is not found.
  `multiloc-deploy-checklist.md:23` names the bare command with no flags at all (`` `treasury:backfill-location-attribution` ``,
  no `--company=`, no `tenants:run` wrapper); the predecessor `STAGING-DEPLOY-RUNBOOK-2026-07-28.md` §2c
  adds the `tenants:run` wrapper but still omits `--company=`
  (`php artisan tenants:run treasury:backfill-location-attribution`). Either form would fail closed (exit
  `FAILURE`, "The --company option is required.") on every tenant/company. Step 6.3 below corrects this to
  the required per-company invocation.

---

## 0. Preflight

### Step 0.1 — Back up every database

- **Source:** normal release procedure (all five checklists require a pre-deploy backup).
- **Command:** per the standing backup procedure for the staging Postgres cluster (central DB +
  every `tenant_<uuid>` DB) — not a single `php artisan` command; run whatever the approved staging backup
  tool/script is (e.g., `pg_dump --format=custom` per database, or the managed-Postgres provider's
  snapshot feature).
- **Expected:** one backup artifact per database (central + every tenant), each with a recorded path and
  checksum.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty — fill at execution time)_

### Step 0.2 — Confirm schema migrations are already applied

- **Command:**
  ```bash
  php artisan tenants:migrate --force
  ```
- **Expected:** "Nothing to migrate" for every tenant. **Any pending migration is a stop** — the deploy
  pipeline did not finish; do not proceed until it has.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 0.3 — Confirm Horizon/queue workers are healthy

- **Command:** `php artisan horizon:status` (or the process manager's equivalent health check).
- **Expected:** Horizon running; no new queue names are introduced by phases ③/④/⑤a/⑤b/multiloc (verify
  every `onQueue()` used by this release has a matching entry in `apps/api/config/horizon.php`
  `defaults.*.queue` per the standing Horizon-queue-coverage rule).
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 0.4 — Confirm no unresolved treasury freeze or portfolio drift, per tenant

- **Command (repeat per tenant UUID):**
  ```bash
  php artisan treasury:reconcile --tenant=<uuid>
  ```
- **Expected:** exit 0, all zeros (0 freezes, 0 portfolio drifts, 0 statement alerts, 0 errors).
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

---

## 1. One-time environment prep (Phase ⑤b — `treasury-phase5b-deploy-checklist.md` §Before deployment)

### Step 1.1 — Shared storage for bank-statement uploads

- **Check:** `storage/app/private/bank-statements` lives on **node-stable shared storage** (not
  per-node ephemeral). Upload-preview and confirm are separate HTTP requests and may hit different
  replicas; ephemeral per-node storage makes valid confirms fail.
- **Command:** infra verification, not an artisan command (confirm the mount/volume configuration for the
  staging deployment).
- **Expected:** the path resolves to shared storage on every app-server replica.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 1.2 — `TREASURY_ACQUIRER_FEE_VAT_RATE`

- **Check:** env var is unset, or explicitly `0.000`. Any non-zero value intentionally fails closed until
  the Phase ④ VAT-split posting is explicitly wired — do not bypass the guard.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 1.3 — `TREASURY_STATEMENT_STALE_DAYS`

- **Check:** unset (30-day default) or an approved positive operational value (values below 1 are clamped
  to 1 day — document any non-default value here).
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 1.4 — Abandoned-preview cleanup scheduled

- **Check:** a scheduled process deletes only **unreferenced** staged statement files older than the
  approved retention window. Any path referenced by `bank_statements.source_file_path` — including a
  **voided** statement — is audit evidence and must never be deleted.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 1.5 — CARD-method settlement mapping confirmed

- **Check:** for every card method eligible for net-settlement suggestions: active, `has_deducted_fees=true`,
  active expense `fee_account_id`, and `default_repository_id` mapping to the exact active GL-linked bank
  repository receiving the statement.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 1.6 — Multi-location §3 package present

- **Check:** confirm the multi-location §3 package (location hierarchy) is present in the release revision.
  Phase ⑤b must not deploy without it — a nullable statement `location_id` does not waive the dependency.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

---

## 2. Guarded backfill — payable instrument accounts (Phase ⑤a — chart codes `403`/`4035`)

Source: `docs/handoff/treasury-phase5a-deploy-checklist.md` §Deploy order.
`tenants:run` does **not** propagate a child command's failure — the `grep` gates below are the actual
release gates. Keep both logs with the deployment evidence.

### Step 2.1 — Dry-run

- **Command:**
  ```bash
  php artisan tenants:run treasury:backfill-payable-instrument-accounts --option='dry-run=1' 2>&1 | tee treasury-backfill-dry-run.log
  ```
- **Expected:** log written; then run the gate:
  ```bash
  grep -Eqi 'Tenant treasury tables are unavailable|missing supplier parent account|has wrong type|is inactive|[1-9][0-9]* invalid account\(s\)' treasury-backfill-dry-run.log && echo "STOP — investigate" || echo OK
  ```
  Expected: `OK`. Any match is a stop — investigate; never hand-edit deployed chart rows.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 2.2 — Apply

- **Command:**
  ```bash
  php artisan tenants:run treasury:backfill-payable-instrument-accounts 2>&1 | tee treasury-backfill.log
  grep -Eqi 'Tenant treasury tables are unavailable|missing supplier parent account|has wrong type|is inactive|[1-9][0-9]* invalid account\(s\)' treasury-backfill.log && echo "STOP — investigate" || echo OK
  ```
- **Expected:** `OK`. Creates missing liability children beneath account `40` (TN/FR charts) or `4000`
  (generic charts), or promotes a valid existing row to system-managed.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

---

## 3. Guarded backfill — CARD → repository routing (Phase ⑤b)

Source: `docs/handoff/treasury-phase5b-deploy-checklist.md` §Deploy order. Pick the real, active,
GL-linked **bank repository code** the tenant's companies settle cards into — ask the owner if unsure; do
NOT guess and do NOT rely on fallback routing. If different tenants need different codes, run explicit
tenant batches with `--tenants=`.

### Step 3.1 — Dry-run

- **Command:**
  ```bash
  php artisan tenants:run treasury:configure-method-routing --option='card-to=<REPO-CODE>' --option='dry-run=1' 2>&1 | tee treasury-routing-dry-run.log
  grep -Eqi 'Tenant Treasury routing tables are unavailable|[1-9][0-9]* invalid company configuration\(s\)' treasury-routing-dry-run.log && echo "STOP" || echo OK
  ```
- **Expected:** `OK`.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty — record the `<REPO-CODE>` chosen)_

### Step 3.2 — Apply

- **Command:**
  ```bash
  php artisan tenants:run treasury:configure-method-routing --option='card-to=<REPO-CODE>' 2>&1 | tee treasury-routing.log
  grep -Eqi 'Tenant Treasury routing tables are unavailable|[1-9][0-9]* invalid company configuration\(s\)' treasury-routing.log && echo "STOP" || echo OK
  ```
- **Expected:** `OK`.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

---

## 4. Manual precondition — assign locations to cash registers/safes (multiloc Wave 3)

Source: `docs/handoff/multiloc-deploy-checklist.md` §Wave 3.

### Step 4.1 — Explicit location assignment

- **Action:** in the UI/API, explicitly assign every cash register and safe to a location. Bank
  repositories may remain company-level (`NULL`).
- **Command:** n/a — UI/API action, not a CLI command.
- **Expected:** every cash-register/safe repository has a non-null `location_id` before step 6.3 runs.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

---

## 5. Multi-location membership backfill check (Wave 1 — already ran automatically at `tenants:migrate`)

Source: `docs/handoff/multiloc-deploy-checklist.md` §Wave 1.

### Step 5.1 — Check migration logs for skipped ambiguous users

- **Command:** inspect the `tenants:migrate` output/log captured at step 0.2 for the line
  `multiloc.backfill.skipped_ambiguous_users`.
- **Expected:** either no occurrences, or a list of skipped multi-company users to resolve in step 5.2.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 5.2 — Resolve each skipped multi-company user explicitly

- **Command (repeat per skipped user; never bulk-grant):**
  ```bash
  php artisan tenants:run users:backfill-memberships --tenants=<uuid> --option='company=<companyId>' --option='user=<userId>'
  ```
  (`BackfillMembershipsCommand.php:18-22` — `--company=` and `--user=` are both required for this
  auditable per-user path; a bulk `--all-memberless` path exists but is explicitly NOT used here per the
  source checklist's "never bulk-grant every skipped user into one company" rule.)
- **Expected:** the named user is mapped into the named company; command exits successfully.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty — one row per resolved user)_

### Step 5.3 — Verify location-scoped access enforcement

- **Check:** a restricted manager cannot create or update a user outside their allowed locations,
  including when `allowed_location_ids` is omitted.
- **Command:** n/a — functional/API check.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

---

## 6. Guarded backfill — multi-location financial attribution (multiloc Wave 3)

Source: `docs/handoff/multiloc-deploy-checklist.md` §Wave 3. **Corrected per the accuracy pass above** —
`treasury:backfill-location-attribution` requires `--company=` and runs once per company, not fleet-wide.

### Step 6.1 — Enumerate companies per tenant

- **Command:** n/a — obtain the company ID list for each tenant from the tenant/company directory (e.g.,
  `php artisan tinker` against the tenant connection, or the admin UI).
- **Expected:** a company-UUID list per tenant to drive step 6.3.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 6.2 — Confirm step 4.1 (location assignment) is complete for every company before backfilling

- **Command:** n/a — precondition check.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 6.3 — Run the backfill once per company

- **Command (repeat for every company in every tenant):**
  ```bash
  php artisan tenants:run treasury:backfill-location-attribution --tenants=<uuid> --option='company=<companyId>'
  ```
  (`BackfillLocationAttributionCommand.php:20` — `--company=` is required; the command fails closed with
  "The --company option is required." if omitted, and again with "Company {id} not found." if the ID is
  wrong. There is no fleet-wide/all-companies mode.)
- **Expected:** `Backfilled N payment(s) and M instrument(s) for company <id>.` for each company (N/M may
  be 0 if nothing was NULL).
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty — one row per company)_

---

## 7. Permissions + restart (covers Phase ④, ⑤a, ⑤b, and multiloc in one pass)

Source: all five checklists require this; the reseed and cache-reset are idempotent and safe to run once
covering every phase's new permissions.

### Step 7.1 — Reseed roles and permissions in every tenant

- **Command:**
  ```bash
  php artisan tenants:run db:seed --option='class=Database\Seeders\RolesAndPermissionsSeeder' --option='force=1'
  ```
- **Expected:** exits successfully for every tenant; grants added per phase — `treasury.transfer` (③);
  `expenses.export`, `expense-recurrences.*` (④); `instruments.clear-outbound`, `instruments.cancel-outbound`
  (⑤a); `bank-statements.view/import/reconcile/reopen` (⑤b); `users.manage_location_access` (multiloc) —
  admin gets everything; accountant gains treasury/statement/instrument grants but NOT
  `bank-statements.reopen` (admin-only); manager gains none of the statement permissions.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 7.2 — Reset the permission cache inside every tenant

- **Command:**
  ```bash
  php artisan tenants:run permission:cache-reset
  ```
- **Expected:** exits successfully for every tenant. **Must run inside every tenant** — the Spatie
  permission cache key is tenant-blind; a central-only reset silently leaves the new grants invisible.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 7.3 — Restart long-running processes

- **Command:**
  ```bash
  php artisan horizon:terminate
  ```
  (supervisor respawns Horizon workers with the new env/code; also restart the API process pool and
  scheduler per the staging process manager.)
- **Expected:** API, scheduler, and Horizon/queue processes are running the released code — required for
  the fiscal projection's per-method routing and the Expense/Treasury event listeners, not just UI pickup.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 7.4 — Users re-authenticate

- **Note:** tokens minted before the reseed do not carry the new server-authoritative permission claims.
  Affected users must sign in again (or use the established token-refresh flow). No command — informational.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

---

## 8. Demo data refresh (unrelated stacked owe — HANDOVER §"Unrelated stacked owes")

### Step 8.1 — Rerun `DemoPharmacySeeder` on the demo tenant

- **Command:**
  ```bash
  php artisan tenants:run db:seed --tenants=<demo-tenant-uuid> --option='class=Database\Seeders\DemoPharmacySeeder' --option='force=1'
  ```
  (`apps/api/database/seeders/DemoPharmacySeeder.php`, namespace `Database\Seeders`.)
- **Expected:** exits successfully; refreshes replenishment + UoM demo data on the demo tenant only.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

---

## 9. Verification before declaring done

Per tenant (the demo tenant is fine as the primary target for the functional checks in 9.2–9.6).

### Step 9.1 — Phase ③ transfer smoke

- **Action:** sign in as a user with `treasury.transfer`, open `/treasury/repositories`, transfer a small
  amount from a cash-register repository to a bank repository.
- **Expected:** success toast; both balance changes; two repository movement legs; one posted balanced
  journal entry (Dr destination GL / Cr source GL).
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 9.2 — Phase ④ scheduler + VAT check

- **Action:** confirm `expenses:generate-recurring` is registered (`php artisan schedule:list`); confirm
  `VatDeductible` purpose resolves to account `4456` on each staging tenant (verification only, no
  backfill).
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 9.3 — Phase ⑤a outbound-instrument lifecycle smoke (API-driven; no dedicated outbound UI buttons this phase)

- **Action:** confirm `403`/`4035` exist (active, liability, system-managed, correct supplier parent);
  issue one deferred-supplier cheque (expect exactly one JE Dr supplier / Cr `4035`, no bank line, no
  repository movement); clear it (expect one bank-credit JE + one outbound movement + balance change);
  bounce + re-present (expect append-only compensating entries, event history shows `re_presented`);
  settle a posted expense by cheque (issue leaves unpaid, clear marks paid); open `/treasury/instruments`
  and confirm separate receivable/payable schedules.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 9.4 — Phase ⑤b statement import + reconciliation smoke

- **Action:** upload a controlled CSV via `/treasury/statements` (expect preview accepted/duplicate/
  dropped-zero/unparseable counts, no GL/movement writes); confirm import (statement `Imported`, lines
  atomic); reconcile through the workspace — Tier 1 exact match, create an expense from a line, ignore one
  line with explanation, complete with signed acknowledgment (expect `Reconciled`, zero remaining); reopen
  as admin (accountant must get 403; statement returns to `Reconciling`, checkpoint recomputed); confirm
  legacy bank-reconciliation mutation/summary routes return 404 and the Finance Hub card opens
  `/treasury/statements`.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 9.5 — Optional: shipped Playwright smoke against staging

- **Command:**
  ```bash
  cd apps/web
  STAGING_URL=<staging-web-url> TREASURY_PHASE5B_BASE_URL=<staging-web-url> TREASURY_PHASE5B_API_BASE=<staging-api>/api/v1 \
    pnpm exec playwright test --config playwright.smoke.config.ts e2e/smoke/treasury-phase5b-reconciliation.smoke.ts --project=chromium
  ```
- **Expected:** 6/6 passed (self-provisions a `SMOKE-*` bank repository if no clean fixture exists).
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

### Step 9.6 — Final reconciliation gate, per tenant

- **Command:**
  ```bash
  php artisan treasury:reconcile --tenant=<uuid>
  ```
- **Expected:** exit 0; zero freezes, zero portfolio drifts, zero statement alerts, zero errors.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty — one row per tenant)_

### Step 9.7 — Instrument maturity alerts, once in the scheduler context

- **Command:**
  ```bash
  php artisan treasury:instrument-maturity-alerts
  ```
- **Expected:** exits successfully; outbound due instruments use the payable alert keys/deep links.
- **RERUN-ON-FINAL-CANDIDATE:** YES.
- **Evidence:** _(empty)_

---

## Stop conditions

Any of these = stop and escalate, do not improvise: a `grep` gate match; a chart-backfill error; a
non-zero `treasury:reconcile` exit; an unexpected freeze or portfolio drift; a pending tenant migration;
`treasury:backfill-location-attribution` returning `FAILURE` for a missing/invalid `--company`. **Never**
repair data by editing journal entries, repository balances, movements, instrument events, or statement
lines — every correction goes through the authorized compensating lifecycle actions documented in the
rollback sections of the five source checklists.

## Rollback notes (summarized from the five source checklists — see each for full detail)

- Prefer an application rollback while retaining additive migrations/chart rows/tables; all five phases'
  schema changes are documented as backward-compatible with the pre-phase application.
- Never reverse a completed transfer, cheque lifecycle action, statement import, or location backfill by
  hand-editing journal entries, balances, movements, instrument events, or statement lines/allocations —
  use the authorized compensating action (a new transfer, a bounce/re-present, a void/reopen, etc.).
- If a lifecycle request times out, retry with the same idempotency/action semantics; never create a
  replacement solely because a response was lost — inspect the instrument event action key or the
  transfer-group ID first.
