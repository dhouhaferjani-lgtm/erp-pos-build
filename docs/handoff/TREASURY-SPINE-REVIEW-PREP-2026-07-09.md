# Treasury Money-Movement Spine — Review Prep & Handoff (2026-07-09)

**Branch:** `feat/treasury-spine` (worktree `apps/erp.treasury-spine`). **NOT pushed — owner promotes.**
**Commit range:** `6a3292b42..9287e40c0` — 41 commits (base = the plan/spec doc commits on local `dev`).
**Status:** Backend spine **Waves A–F COMPLETE + gated**; Wave G in progress (25 done, 26 landing, 27/28 FE + 29 E2E remain).

---

## 1. What was built (spec §: `docs/superpowers/specs/2026-07-07-treasury-spine-design.md` Rev 2; plan: `docs/superpowers/plans/2026-07-08-treasury-spine.md` Rev 2)

Every money movement now flows through **one append-only ledger + one write port**, each carrying a source-document reference and a GL journal entry written **atomically** — so cash position is trustworthy, drillable, and audit-defensible.

- **Wave A — Foundation:** `repository_movements` append-only ledger (PG immutability trigger modelled on `fiscal_events`), `RepositoryMovement` model + `MovementDirection/SourceType/ReasonCode` enums, spine columns on `payment_repositories` (currency, frozen_at/reason, next_movement_ordinal), `RepositoryMovementRecorded` → `audit_events`.
- **Wave B — GL hardening:** `GeneralLedgerService::postEntryNow()` (synchronous in-transaction posting; the old `DB::afterCommit` posting broke atomicity), per-company `pg_advisory_xact_lock` on chain-sequence/entry-number allocation, closed-fiscal-period guard, `journal_code` (FEC) column + enum.
- **Wave C — The write port:** `TreasuryMovementService::record()` (single-movement) + `transfer()` (paired-leg, sorted two-lock) — idempotent (savepoint recovery + `idempotency_key`), atomic, freeze-aware, GL-linked.
- **Wave D — Convergence + Cutover:** ALL flows migrated onto the port — freeze admin, expense post (+ unpaid→AP bug fix), expense settlement, PaymentController store/storeMultiple (reordered draft→post→record), payment idempotency, income, refunds (+ over-refund lock guard), multipayment cash lines, POS receipt/account/deposit bridges (canonical-index idempotency), refund SALE_RECEIPT branch, LinkedCost + expense-reverse. **Cutover (Task 22):** deleted the 4 legacy port files, dropped `balance` from `$fillable`, and added a **PG trigger forbidding any direct `payment_repositories.balance` INSERT/UPDATE** unless the port's `SET LOCAL app.treasury_movement_port='on'` GUC is set. Plus reconciliation-readiness: every cash movement carries a `journal_entry_id` (exceptions: `opening_balance` legs, same-GL-account transfer legs).
- **Wave E — Adjustments:** gated `POST /payment-repositories/{id}/adjustments` (count-variance/correction) via the port + balanced GL.
- **Wave F — Reconciliation:** `treasury:reconcile` daily command — checks (1) balance == Σ signed movements + continuity, (2) every non-exempt movement's JE exists with matching (authoritative-on-own-cash-line) amount, (3) transfer groups net zero; **on drift → freeze + alert, NEVER repairs.**
- **Wave G (partial):** `GET /treasury/cash-position` (server-side aggregation, done). Remaining: movements drill-down endpoint (26), FE Movements tab + cash-position wiring (27), instrument list contract fix (28), live E2E (29).

## 2. How this was gated (methodology — for the reviewer's confidence calibration)
Every task: fresh implementer (TDD) → per-task spec+quality review → fix loop. Plus **milestone adversarial gates** per wave (`treasury-reviewer`), **`fiscal-pos-reviewer`** on the hash-chain refactor + POS bridges, **two Codex adversarial passes** (Wave B GL hardening, the cutover), and a **Postgres verification pass** per wave (the trigger/advisory-lock/immutability only fire on pgsql). The gates caught real production-money bugs the per-task reviews missed — see §4.

## 3. How to review / run
- **Diff:** `git diff 6a3292b42..9287e40c0` (or per-wave ranges in the ledger).
- **Tests — sqlite (fast, default):** `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury` (run BY PATH; never the full suite — crashes the laptop).
- **Tests — Postgres (where triggers/locks fire):** `DB_CONNECTION=pgsql DB_DATABASE=autoerp_treasury_test DB_CENTRAL_DATABASE=autoerp_treasury_test ./vendor/bin/phpunit tests/Feature/Treasury/<T>.php` against a throwaway DB on 127.0.0.1:5433 (user autoerp/autoerp_secret). **⚠️ NEVER run two pgsql RefreshDatabase suites at once — they corrupt the shared test DB (mass `2BP01` errors that look real but aren't). Run pgsql SERIALLY.**
- **Known pre-existing pgsql-only failures (NOT this work):** `EloquentPaymentMethodResolverTest` (`2BP01` schema-teardown), a `ClosedFiscalPeriod` tolerance test on sqlite — both confirmed identical at the base tree.
- **Detailed per-task reports + every gate finding + adjudication live in `.superpowers/sdd/progress.md`** (the SDD ledger — gitignored, so a copy of its key content is in §4/§5 below). Per-task reports: `.superpowers/sdd/task-*-report.md`.

## 4. Highest-risk surfaces — where a Fable 5 adversarial review should focus
1. **The write port** `TreasuryMovementService::record()`/`transfer()` — idempotency correctness (a non-`idempotency_key` unique violation must fail loudly, not return a wrong row), gapless ordinal + non-double-applied balance under replay, atomicity/lock-order (advisory→repo), the MED-10 GUC lifecycle (set before savepoint, reset on ALL exit paths incl. exceptions).
2. **The cutover trigger** (`*_forbid_direct_payment_repository_balance_writes.php`) — INSERT+UPDATE guarded; the GUC is a caller-settable app-convention guard (NOT a security boundary — trigger + architecture test are the practical guard; verify only the port sets it via `SET LOCAL`).
3. **Reconciliation-readiness** — no converged writer records a null-JE cash movement (Codex/treasury-reviewer both found real leaks here — deposit/account-payment null-actor path was the last one). The `treasury:reconcile` amount-match (authoritative on the repo's own cash line; tolerant fallback only when the JE books no line on that account).
4. **POS bridges** (projection/replay context) — canonical-index idempotency stability across replays, complete-set replay, legacy null-key fallback, device-vs-server `allowWhileFrozen` (`isServerOnly()`), refund SALE_RECEIPT direction.
5. **Hash chain** — `postEntryNow`/`sealAndPersistEntry` was verified byte-identical to the pre-refactor sealing; confirm no drift. `journal_code` is NOT in the hash whitelist.

## 5. Owner-review & deploy checklist (tracked during the build — DECISIONS/ACTIONS NEEDED)
- **CI must run Treasury against Postgres** — the balance-write trigger, advisory locks, and immutability trigger only fire on pgsql; sqlite skips them.
- **`refund_request_id` is now REQUIRED** on the admin full/partial refund endpoints (idempotency, prevents double-refund) → **FE refund calls must send it**.
- **Adjustment endpoint (Task 23):** the 658/758 variance purposes are NOT seeded in the TN/FR chart-of-accounts seeders (only the generic one) → the endpoint 500s for a real TN/FR tenant whose COA lacks them. Seed 658/758 in TN/FR charts OR add a graceful missing-purpose→422.
- **GL-account refinements (owner accounting call):** refund SALE_RECEIPT reversal debits ProductRevenue directly (dedicated 709 sales-return contra deferred); a refund of a payment that carried an advance-excess reverses to AR (should reverse to CustomerAdvance) — both cash-correct, GL-account-refinable.
- **Cutover deploy:** the new `(company_id, chain_sequence)` unique index will FAIL to migrate if a prod DB already has forked chain sequences (from the pre-existing legacy race) → dedupe first. Opening balances are established via `opening_balance` movements (seeders reworked); production repos are born at balance 0.
- **Deploy runtime:** all migrations apply per-tenant (`php artisan tenants:migrate`), + `permission:cache-reset` (Spatie cache is tenant-blind) for the new `treasury.adjust`/`treasury.view` perms, + a new Horizon `ingestion`/projection queue if bridges introduced one (none did).
- **Minor follow-ups:** MultiPaymentService silently skips a non-null-but-unledgered repository_id (PaymentController rejects it — tighten for parity); payment-idempotency replay has no request-fingerprint check (reused key + different body returns the original — add fingerprint validation); TRAINING receipts hit the real-money path (pre-existing); the account-charge Draft-orphan records no movement (verified reconcile-safe).

## 6. Remaining work (Wave G/H — for a fresh session)
- **Task 26** — `GET /api/v1/payment-repositories/{id}/movements` (paginated `{data,meta}`, filters, JE links). *(In flight at handoff — check git for `RepositoryMovementController`; verify + review.)*
- **Task 27** — **FE**: `RepositoryMovementsTab.tsx` + `useRepositoryMovements`/`useCashPosition` hooks; wire `TreasuryOverviewPage` to the cash-position endpoint (`grand_total`=Total Cash, group totals→register/bank/safe StatCards); `treasury` i18n namespace; `php artisan typescript:transform`. **Playwright-verify the new Movements tab + cash position.** (web/node_modules already installed in the worktree.)
- **Task 28** — FE: align `InstrumentListPage.tsx` fields to the actual `PaymentInstrumentController::formatInstrument` shape (G3).
- **Task 29** — **Live E2E (Playwright)** on the db-per-tenant stack (recipe: `.claude/.../reference_local_db_per_tenant_demo_launch.md` — central=iziposcentral, API:8010, token auth, multi-queue worker, DemoPharmacySeeder). Exercise: create sale → refund → adjustment → run `treasury:reconcile` (must NOT freeze the demo tenant) → view cash position + movements drill-down.

## 7. Recommendation
The backend spine (A–F) is the high-risk, done part — **Fable 5 review can run on the current branch now** (§3 diff range, §4 focus areas). The FE + E2E tail (26–29) is lower-risk and should continue in a **fresh session** (this one is context-bound). Nothing is pushed; the ledger + per-task reports + this doc are the full audit trail.
