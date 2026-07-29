# HANDOFF — POS Cash Rounding + Tolerance, DEVICE Phase 2 (apps/pos)

**Date:** 2026-07-28. **For:** a fresh Claude session executing the device half of the cash-rounding track.
**Mission:** the device signs Swedish-rounded cash totals into SALE_RECEIPT v3, auto-accepts in-tolerance shortfalls without a PIN, and mirrors the fiscal surfaces — completing the last functional blocker before the first parapharmacy client onboards.

## 1. State you inherit

- **Server Phase 1 is COMPLETE and merged to LOCAL dev at `e920a971e`** (13 SDD tasks, per-task Opus review gates, 560-test verification + DB-backed E2E smoke, 2-lane whole-branch final review clean). Everything is **inert**: all new server behavior is gated on `event_version >= 3` (`App\Shared\Domain\CashRoundingCutover`) and no device authors v3 yet.
- **origin/dev promotion status: CHECK BEFORE STARTING.** If `git log origin/dev | grep cash-rounding` is empty, Phase 1 is not yet on staging — the device build MUST NOT ship until it is (a v3 event hitting a pre-Phase-1 server quarantines; repairable, but don't create the mess). Local-dev merge is enough to BUILD against; promotion + staging checklist run is required before any real device/terminal gets the build.
- Spec: `docs/superpowers/specs/2026-07-27-pos-cash-rounding-tolerance-design.md` (Rev 2.2 — 3 adversarial rounds; §8 owner decisions all resolved, incl. §8.1 tolerance floor `max(min(pct×total, max_amount), D)` + per-shift auto-accept counter default 10).
- Operator runbook: `docs/handoff/cash-rounding-phase1-deploy-checklist.md` — **§6 is your blocker list** (below), §7 the Phase-2 enable sequence.
- Phase-1 record (fix rounds, rulings, tickets): `docs/superpowers/specs/reviews/2026-07-28-cash-rounding-phase1-sdd-ledger.md`; verification evidence: `docs/handoff/cash-rounding-phase1-verification-2026-07-28.md`.

## 2. Your plan

`docs/superpowers/plans/2026-07-27-cash-rounding-device-phase2.md` — 11 tasks, bite-sized, code-verified at authoring time. **Caveat: it was authored BEFORE Phase 1 executed.** Verify every Plan-A-dependent fact against the LANDED code, not the plan's guesses. Known-good landed facts:
- Endpoint `GET /api/v1/pos/payment-policy` → `PosPaymentPolicyController` → `PosPaymentPolicyDTO` with camelCase fields: `companyId, currencyCode, currencyScale (int), cashRoundingEnabled (bool), cashRoundingDenomination (string, company-currency scale e.g. '0.050'), tenderToleranceEnabled, tenderTolerancePercentage, tenderToleranceMaxAmount, refreshedAt`. The plan's `paymentPolicyApi.ts` adapter isolates any residual naming drift — verify the wire shape first (plan Task 3 has the verify step).
- `payment_methods` API now serves `is_cash_tender` (18th key in `formatMethod`); invariant `is_cash_tender=true ⇔ code === 'CASH'` exact/case-sensitive; codes normalize to uppercase on write.
- PHP `SALE_RECEIPT_PAYLOAD_KEYS_V3` = named const in `FiscalPayloadConstraintValidator` (30 keys, lex-sorted; `cash_rounding_adjustment`/`cash_rounding_denomination` between `buyer` and `cashier_id`). Server accepts versions [1,2,3]; binds: identity `subtotal+vat == (total−adj)+discount`, `adj≠0 ⇒ denom>0 ∧ |adj| ≤ denom/2 ∧ total ≡ 0 (mod denom)`, static caps via `CashRoundingCaps` (s3 ≤ 1.000 / s2 ≤ 1.00 / s0 ≤ 10, unlisted scale rejected), **`-0`/`-0.000` rejected** (`payload_money_negative_zero`).
- Server Z: `cash_rounding_summary {total_adjustment (signed, scale 3), receipt_count (int, adj≠0 rows)}` derived in `ZReportProjection::legacyReportData()`; hash normalization block landed in `ZReportHashService.php` (~:148-152, isset-guarded, additive); **`report_data.schema_version` stays 2 on the device**.
- Drill-down endpoint `GET /pos/shifts/{shiftId}/tolerance-receipts` emits `TolerancePaymentReceiptDTO`: `receiptNumber, userId, userName, writeoffAmount, currencyCode, occurredAt`.

## 3. HARD BLOCKERS (checklist §6 — non-negotiable ordering)

1. **TS 30-key mirror + drift gate BEFORE the device ever signs v3** — `SALE_RECEIPT_PAYLOAD_KEYS_V3` in `FiscalEventEngine.ts` (append-time const swap, `:1691` line-item precedent; after the swap the device CANNOT author v2 — that is by design) + rewrite `FiscalPayloadKeyDrift.test.ts` for named consts (30 keys + sortedness). Missing this = 100% quarantine of every receipt.
2. **Canonical-zero normalization** — the device must NEVER emit `'-0.000'` (big.js normalizes `-0`, but pin it with a test; server rejects).
3. **Device rounding gate = `terminal.fiscal_schema_version === 3`, fail-closed** (server-assigned column the device only READS — `terminalStore` caches it). Pin test: device signs `adj ≠ 0` ONLY at schema 3. This is the GrandtotalService/net_sales foreclosure — without it a non-cutover terminal poisons NF525 grand totals.
4. **Device Z hash mirror** — identical isset-guarded `cash_rounding_summary` normalization block in `apps/pos/src/lib/fiscal/zReportHashService.ts` (~:86-95 region, mirroring the tolerance block) BEFORE any device emits the key; zero-shape contract `total_adjustment '0.000'` / `receipt_count` int `0`; NO schema_version bump (re-normalizes `refunds_amount`, breaks parity). Also: real `tolerance_summary` aggregation replaces the hardcoded zero-shape (`zReportService.ts:~323` TODO names this feature).
5. **`toleranceApi.ts` field fix before enabling the drill-down panel** — client declares `receiptId`/`cashierName`; endpoint emits `receiptNumber`/`userId`/`userName`/`writeoffAmount`/`currencyCode`/`occurredAt`. Fix the CLIENT (`key={r.receiptNumber}`, `cashierName`→`userName`); never the frozen DTO.
6. **String fidelity every hop** — policy denomination is TEXT in sqlite (NUMERIC affinity strips trailing zeros), string in TS; never parseFloat/Number on money (`no-parsefloat-on-money`).

## 4. Plan-recorded gotchas (authors verified these; don't rediscover)

- **sqlite `Migration.run` silently overrides `sql`** (`db.ts:~121-133`) — v63's CREATE TABLE must live inside `run`. Device migrations at v62; this phase ships **v63** (policy cache + `offline_receipts` rounding columns + `payment_methods.is_cash_tender` INTEGER).
- `payment_methods` device wire = 4 lockstep sites: `types/payment.ts` interface, `paymentRepository.ts` `PaymentMethodRow`/`rowToMethod`/`upsertPaymentMethods` (explicit column list + binds).
- **Print-label collision**: existing Rust line prints the tolerance write-off under the `rounding` label with a hardcoded `-`; plan gives cash rounding its own signed line under `rounding` and moves tolerance to a new `tolerance` label (en/fr keys).
- `endOfDayPreview.ts` reads `payments_json.tolerance_writeoff` which NO writer produces — plan Task 10 replaces with the receipt-level column.
- **`computeExactCartTotal` unification** (plan Task 5): `estimateCartTotal` (scale=decimals) vs `receiptService` (default scale 3) diverge on scale-2 currencies — one exported implementation, both call it; `changeDue` goes number→string through `PaymentState`/`CheckoutSuccessModal`; `receiptService.ts:252,575` parseFloat fixed in passing.
- **CheckoutPolicySnapshot** `{exactTotal, roundedTotal, adjustment, denomination, cashOnly, toleranceDecision, fiscalSchemaVersion, policyRefreshedAt}` — built once at tender time, threads to signing; the snapshot is what signs.
- Cash-only = every leg in the **union** of `paymentLines` AND `voucherTenders` has `is_cash_tender` (vouchers are payment legs, never cash). Quick-cash method SELECTION predicate (`paymentStore.ts:~855,~1230`) migrates to `is_cash_tender && is_active`.
- Change eligibility: refuse completion when `change > Σ cash legs` (card-only over-tender rejected pre-sign; the server bridge alerts on violations from old events).
- Tolerance auto-accept: `shortfall ≤ max(min(pct×total, max_amount), D)` when rounding active and `exact_total > 0`; per-shift auto-accept counter (default 10) escalates to the PIN path; PIN path itself byte-identical.
- Missing/never-synced policy ⇒ BOTH mechanisms disabled (fail-closed to today's exact behavior); `V1`/`V2` payload builders byte-immutable — v3 = new `SaleReceiptV3Payload.ts`: build V2 with `exactTotal` → REPLACE `total` key with `roundedTotal` → add the 2 fields → `assertSaleReceiptAggregatesV3`.
- Rule 20: any JS timestamp compared against `DEFAULT (datetime('now'))` columns routes `toSqliteUtc()`.

## 5. Process for the session

- **Worktree**: create fresh off local dev (`git worktree add ../erp.cash-device -b feat/pos-cash-rounding-device dev`) — do NOT reuse `../erp.cash-rounding` (prunable) and never commit in the shared dev checkout.
- **Method**: superpowers:subagent-driven-development over the Plan B file; fresh **Opus** implementer per task (owner rule: Fable orchestrates only); review gate every task with **fiscal-pos-reviewer** (device/fiscal surfaces) — treasury-reviewer for anything touching payment semantics; ledger in the SDD workspace; briefs via the skill's `task-brief` script.
- **Tests by path only** (vitest for apps/pos; the server-side canaries via `phpunit-pgsql.xml` → `autoerp_cash_rounding_test:5433` if the worktree has apps/api vendor+.env — fresh worktrees need `composer install` + COPY `.env` from the main checkout, never symlink; `pnpm install` for web/pos).
- **On-device Tauri verification** at the end (recipe: `reference_pos_tauri_dev_computer_use_recipe.md` in memory; local stack recipe `reference_local_db_per_tenant_demo_launch.md`).
- Phase-2 ENABLE sequence (§7 of the checklist, after both phases on staging): terminal fiscal-schema cutover to 3 → `pos:configure-cash-rounding --option='country=TN' --option='enable-rounding=1'` (+tolerance) with the token gates → THEN the device build rollout.

## 6. Out of scope (do not creep)

Refund payout rounding (separate approved follow-up track — `docs/superpowers/specs/2026-07-27-refund-rounding-research.md`); canonical REFUND/VOID authoring; Z_REPORT payload v2; the §6 server-side pre-cutover tickets (SalesReportService tendered-cash, predicate split, is_active resolver, approval-magnitude — server lane's, not yours); training-GL and void-GL pre-existing debt.
