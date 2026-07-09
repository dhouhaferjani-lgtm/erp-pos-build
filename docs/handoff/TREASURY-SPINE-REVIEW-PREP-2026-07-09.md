# Treasury Money-Movement Spine — Review Prep & Handoff (2026-07-09)

**Branch:** `feat/treasury-spine` (worktree `apps/erp.treasury-spine`). **NOT pushed — owner promotes.**
**Commit range:** `6a3292b42..bc0f30585` — 57 commits (base = the plan/spec doc commits on local `dev`).
**Status: ✅ ALL 29 TASKS COMPLETE. Final whole-branch review (Fable 5) verdict: READY FOR OWNER PROMOTION.** See §8 for the Wave G/H completion + final-review record; §5 + §8.3 for the deploy checklist (one item is TIME-CRITICAL).

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
- **Known pre-existing pgsql-only failure (NOT this work):** `EloquentPaymentMethodResolverTest` (`2BP01` schema-teardown) — confirmed identical at the base tree.
- **Correction (2026-07-09 independent audit, finding N2):** an earlier draft of this doc misclassified `PaymentAllocationServiceTolerancePersistenceTest::tolerance_writeoff_column_is_persisted_on_allocation_row_for_underpayment` (sqlite) as pre-existing. It is **branch-caused**: the fixture's backdated `payment_date` (`2025-01-15`) falls in a period the branch's new closed-fiscal-period guard (Wave B Task 8, `GeneralLedgerService.php:2462-2463`) now rejects; the test file is untouched at base and the exception class doesn't exist there. Found by the independent audit (`docs/superpowers/audits/2026-07-09-treasury-spine-independent-audit.md`) and fixed by this commit (fixture-only: `payment_date` moved to the current, always-open period).
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

## 7. Recommendation (superseded by §8)
~~The backend spine (A–F) is the high-risk, done part — Fable 5 review can run on the current branch now.~~ Done — see §8. Nothing is pushed; the ledger + per-task reports + this doc are the full audit trail.

---

## 8. Wave G/H completion + final whole-branch review (2026-07-09, fresh session)

### 8.1 What landed (commits `9287e40c0..bc0f30585`)
- **Task 26** — movements drill-down endpoint (was landing at original handoff; verified + gated).
- **Task 27** (`e4ea860ee..498798def`) — `RepositoryMovementsTab` + `useRepositoryMovements`/`useCashPosition`; `TreasuryOverviewPage` now consumes `GET /treasury/cash-position` (client-side summing deleted); treasury i18n (en+fr); review clean (rule-14 pagination, tenantScopedKey, tokens, enums verbatim vs backend).
- **Task 28** (`5e6fe21ef` + `c02fcf0ac`) — `InstrumentListPage` aligned to the real `formatInstrument` shape (phantom `instrument_number`/`type`/`partner_name` and nonexistent `meta` reads eliminated); full 9-status i18n.
- **Task 29** (`215bf21db` + `72650ec66`/`52b079b87`/`0db5d270d`) — **live E2E on the db-per-tenant stack, 8/8 green twice consecutively**: login → payment +50.000 → full refund −50.000 (`refund_request_id`) → +10.000 count-variance adjustment → `treasury:reconcile` **checked 7 / froze 0 (before AND after)** → UI Total Cash + Movements tab + API grand_total all agree. Screenshots: `docs/sessions/treasury-spine-e2e/`. Spec lives at `apps/web/e2e/smoke/treasury-spine.smoke.ts` (smoke convention, CI skip guard, baseline-relative BigInt-millimes assertions, pagination-proof).
- **Final-review fixes** (`567e8db04`, `bc0f30585`) — see 8.2.

### 8.2 Final whole-branch review (Fable 5, full 6a3292b42..HEAD range) — **READY FOR OWNER PROMOTION**
All §4 high-risk surfaces re-verified in the final state (port idempotency; advisory→repo lock order at all 14 MovementIntent sites; GUC lifecycle incl. INSERT+UPDATE trigger; hash-chain sealing **byte-identical by source diff**; POS canonical-index idempotency; Wave-G authz/scoping). Three blockers found and FIXED in-branch:
1. **`VendorRefundService` null-JE cash movement** on unledgered repos (would GUARANTEE a reconcile freeze via `POST /documents/{document}/refund-prepayment`) → guard mirroring `PaymentRefundService`, red→green test, both drivers (`567e8db04`).
2. useCashPosition stale contract comment (`567e8db04`).
3. **8 tests red since Wave D** in `tests/Feature/Fiscal/PaymentOriginWriterInventoryTest.php` — unledgered fixtures tripping the (correct) Wave-D guards; invisible because milestone gates ran by-path on Treasury/Expense/Income/Accounting. Fixture-only repair, 15/15 both drivers (`bc0f30585`). *Process lesson: by-path gating leaves sibling-suite blind spots.*

**Follow-up tickets (Important, non-blocking):** (a) receipt-bridge partial-replay lock-order inversion (ABBA deadlock risk; transient, port-idempotent recovery) — take company advisory at top of bridge txn; (b) `treasury:reconcile` TOCTOU can false-freeze a healthy repo if run mid-trading (02:15 schedule is safe; manual midday runs aren't) — snapshot-read or double-confirm before freeze; (c) pure-advance JE seals in afterCommit (crash window → Draft-linked movement → freeze; fails safe) = known Task-16(a). Minor M1–M9 + triage table: see the SDD ledger (`.superpowers/sdd/progress.md`, gitignored).

### 8.3 DEPLOY — new items beyond §5 (first one is TIME-CRITICAL)
1. **🔴 Brownfield opening-balance backfill BEFORE the first scheduled reconcile.** `treasury:reconcile` runs daily at 02:15. Any pre-spine tenant (repos with balances, zero movements) gets **every repository frozen the first night**. Sequence per tenant: `tenants:migrate` → backfill → only then let 02:15 arrive. Demo remediation script (per repo, one txn: GUC-guarded balance→0 + port-recorded `opening_balance` movement): productize as an artisan command before prod. Owner also decides whether legacy cached balances are even trustworthy enough to convert (the treasury audit found POS bridges never moved them).
2. **`payments.refund` + `payments.reverse` never existed in the permission catalog** — both endpoints were 403 for every non-admin role in every tenant ever provisioned. Seeder fixed (`215bf21db`); existing tenants need reseed + `permission:cache-reset`. **Owner decision:** which roles get refund/reverse (currently admin-only via `Permission::all()`; manager has `treasury.adjust` but cannot refund).
3. **`smoke-test.yml` re-armed:** `playwright.smoke.config.ts` was discovering **0 tests** (missing `testMatch`) — fixed; 3 dormant staging smoke tests are now live again, 2 of which **create data on staging**. Dispatch the workflow once deliberately before relying on it. The treasury spec skips in CI unless `TREASURY_SPINE_API_BASE` is set.
4. §5's chain-sequence dedupe note, amplified: the `(company_id, chain_sequence)` unique-index migration is **not re-runnable** — a failed CREATE INDEX must be resolved by dedupe, never re-run-and-hope.
5. Also applied to the local demo tenant during E2E (repeat on prod TN/FR tenants): 658/758 payment-tolerance accounts seeded (adjustment endpoint 500s without them — §5 item confirmed real).

### 8.4 Local stack state (for the next session on this machine)
- The **scan-to-doc** dev servers were stopped to free :8010/:5173 (restart: `cd apps/erp.scan-to-doc/apps/api && php artisan serve --port=8010`, `cd ../web && pnpm dev`, plus its multi-queue worker incl. `ingestion`).
- The **treasury-spine** stack is running in their place (same ports, worktree `.env` already db-per-tenant). Demo tenant was mutated: spine migrations applied, perms reseeded, opening-balance backfill done, 658/758 seeded, and the append-only ledger grows +10.000 TND net per E2E run on Main Cash Register (by design; assertions are baseline-relative).

### 8.5 Reviewer confidence notes (probe here in the owner pass)
pgsql-only runtime guards (balance-write trigger, advisory serialization, immutability) were verified statically + via the ledger's earlier controller-run pgsql passes, not re-executed in the final cycle; fix-wave-2's pgsql 15/15 claim was not independently re-run (sqlite was); hash-chain byte-identity proven by source diff, not runtime hash comparison — re-verify one tenant's chain after the staging deploy as belt-and-braces.

---

## 9. Post-promotion-readiness round (2026-07-09 evening): independent audit fixes + FE conventions pass

Two parallel closing efforts landed after §8, both on this branch (final HEAD for this round: see git log through the commit carrying this doc):

### 9.1 Independent adversarial audit → fix waves (other session; see `docs/superpowers/audits/2026-07-09-treasury-spine-independent-audit.md` + its remediation record)
`a2090e5be` per-tenant/per-repo reconcile failure isolation + truthful failure alert (audit N1) · `f6cd0d15c` freeze outcome survives post-freeze alerting failures · `a45f9363a` open-period fixture for the tolerance test (audit N2 — §3 correction: it was branch-caused, not pre-existing) · `f4ec2aa16` UUID guards on adjustment/movements/pay endpoints + re-runnable immutability migration (closes final-review M9 + UUID-500s) · `2c238ce7f` **658/758 tolerance purposes seeded in TN/FR charts + 422 on missing purpose — closes §5's adjustment-endpoint deploy item IN CODE** (runbook step no longer required for new tenants).

### 9.2 FE conventions / atomic-design review (owner-mandated) → CONVENTIONS-CLEAN
Adversarial review of all branch FE against the repo's atomic-design system + docs/conventions. Verified clean from the start: design tokens (In/Out = success/error semantics, money never in accent), i18n en/fr parity, tenantScopedKey + rule-14 hooks, atomic placement, StatCard/Select/StatusBadge/EntityLink/DataTable reuse where they fit. Findings fixed in `d892f775c` + `594305d43`: the Movements tab now reuses **OffsetPagination** (new backward-compatible `hidePerPage` prop — 3 compat tests, 6 consumer suites green), **EmptyState**, **DateRangeFilter**, **QueryError** (with retry); hand-rolled `<table>` kept as a documented accepted deviation (shared DataTable has no pagination; genre peers hand-roll); orphan i18n keys dropped. Follow-up ticket outside this branch: `DateRangeFilter` itself has a hardcoded `text-gray-700` + placeholder-only inputs (pre-existing shared-component debt).

### 9.3 Final sweep on the settled branch (this session, controller-run)
- Live treasury smoke E2E: **8/8 green** on final HEAD (post-UUID-guards, post-conventions-refactor).
- `treasury:reconcile` (the reworked, per-tenant-isolated command): **checked 7; froze 0; 0 error(s)** on the demo tenant.
- Treasury FE vitest 27 files / 215 tests green; `pnpm typecheck` clean; lint 0 errors (at the conventions-fix commit; the two later commits are backend-only).
