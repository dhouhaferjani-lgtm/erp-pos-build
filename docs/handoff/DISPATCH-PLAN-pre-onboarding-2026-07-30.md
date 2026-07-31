# Pre-onboarding dispatch plan — lanes A / B / C / D

**Date:** 2026-07-30
**Base:** `origin/dev` @ `e7fd6d0e3` (cash rounding server Phase 1 + device Phase 2 both promoted, inert)
**Status:** AWAITING ADVERSARIAL REVIEW — no lane dispatched yet.

This document is the review target. It defines four parallel lanes intended to close
everything standing between today and onboarding the first tenant.

---

## 0. Governing assumptions (owner-stated 2026-07-30) — CHALLENGE THESE FIRST

1. **Greenfield. Zero real tenants.** Staging carries 5 demo tenants / 6 companies / 8 `pos_receipts`.
2. **Rollback v3 → v2 is explicitly UNIMPORTANT** (owner ruling). The "unresolved operational unknown"
   (whether v3 events queued on-device push cleanly under a rolled-back v2 build) is therefore DROPPED,
   not deferred.
3. Because of (1) and (2), these previously-tracked items are claimed MOOT:
   - Checklist §0.2 — `…_100200` rollback is a one-way door for the totals invariant.
   - Checklist §0.3 — `change_due` now written for v3, expected-cash figures shift at cutover.
   - Device follow-ups T10 minor — non-cutover terminals permanently render `Auto-accepts used 0 / 10`.
     Scored Minor-but-common *because a staged fleet cutover was assumed*. There is no fleet.
4. **Derived proposal:** provision tenant #1 at `fiscal_schema_version = 3` with rounding enabled from
   provisioning, so there is never a mixed v2/v3 fleet and no staged cutover.

**Reviewer: assumption 3 and proposal 4 are the load-bearing ones.** If any retired item is retired for
a bad reason, or if proposal 4 is not actually achievable from landed code, say so — the whole plan
shape depends on it.

---

## 1. Lane fencing claim (parallelism safety)

| Lane | Owns |
|---|---|
| A | `apps/api` — Accounting + POS reporting reads |
| B | `apps/pos` — fiscal payload tests + `eslint.config.js` |
| C | the refund path — `ReceiptReturnService`, `hydrateFromReceipt`, `buildReceiptData` |
| D | seeders + provisioning + an ops runbook (docs) |

**Claim: no two lanes touch the same file, so all four may run concurrently.**
Reviewer: verify this rather than accept it. B's T1 *reads* PHP (`CashRoundingCaps.php`) but must not
modify it. C touches both `apps/api` and `apps/pos`. A and C both touch `apps/api` POS/Accounting.

---

## 2. Lane A — server reporting truth

Source: `cash-rounding-phase1-deploy-checklist.md` §6 tickets 1–3.

### A1. `tolerance_writeoff` consumer sweep — highest risk

**Semantics (verified, `Receipt.php:60` docblock):** on v3+ rows this column is ALWAYS written —
canonical `'0.000'` when no tolerance applied, never NULL. NULL means a v1/v2 legacy row or a training
receipt. It does **not** mean "no tolerance". The docblock explicitly says: *do not use `whereNotNull`
as a "has tolerance" predicate on v3 data — compare with bccomp against zero.*

**✅ VERIFIED BY ORCHESTRATOR — one live violation exists:**
`app/Modules/Treasury/Application/Services/PaymentToleranceQueryService.php:189` → `->whereNotNull('tolerance_writeoff')`.
On v3 data this selects **every v3 receipt**.

**⚠️ THE CHECKLIST'S SUSPECT LIST IS WRONG.** §6 ticket 3 names `PosAnalyticsService`,
`Nf525DataProvider`, `ReceiptPaymentService`. A repo grep of `app/` finds `tolerance_writeoff` in:
`CashRoundingCutover.php` (comment), `InvoiceController.php:819`, `PosCoreReceiptProjection.php:378`
(writer), `ReceiptVoidService.php:261,285-286`, `ReceiptPaymentService.php` (writer),
`Shift.php` (different columns — `tolerance_writeoff_total` / `_count`),
`PaymentToleranceQueryService.php:189`, `PaymentAllocationService.php:594,596`.
`PosAnalyticsService` and `Nf525DataProvider` do **not** reference it at all.

Task: fix `:189`; audit every other reader; classify each as writer / value-read / predicate.
Two needing a judgement call, not a blind rewrite:
- `ReceiptVoidService.php:285` — `!== null` guarding a value read, not a "has tolerance" test. Probably fine.
- `PaymentAllocationService.php:594,596` — `$a['tolerance_writeoff'] !== null` on Treasury **allocation
  arrays**, likely a different domain object entirely. Confirm before touching.

### A2. `SalesReportService::paymentMethodBreakdown` overstates cash

**✅ VERIFIED:** `SalesReportService.php:~184` does
`COALESCE(SUM(pos_receipt_payments.amount), 0) as amount` joined to `pos_receipts`, with **no**
`change_due` subtraction anywhere in the query. That is TENDERED cash, so from the moment v3 traffic
starts it overstates by change given back.
Fix = subtract `pos_receipts.change_due`, or read the Treasury `payments` rows. Implementer must pick
one and justify which in the report.

### A3. Converge the split cash predicate

**✅ VERIFIED:** `ReportGenerationService.php:513` uses
`whereRaw('UPPER(pos_receipt_payments.payment_method_code) = ?', ['CASH'])`, while the rounding/netting
path uses `is_cash_tender`. They disagree on any case-variant method.

**⚠️ CORRECTION TO THE CHECKLIST'S FRAMING:** that same subquery already selects
`MAX(COALESCE(pos_receipts.change_due, 0)) as change_due` and aggregates `SUM(change_due) as
total_change_due`. So the Z path **does** already account for change. The "overstates cash" defect is
specific to A2; A3 is purely about predicate convergence on `is_cash_tender`.

---

## 3. Lane B — signed-payload guards + one inert guard

Source: `cash-rounding-device-phase2-followups-2026-07-29.md` T1–T4.

### B1. Pin the denomination cap table to its PHP authority
`V3_DENOMINATION_CAP_BY_SCALE` (`SaleReceiptV3Payload.ts:54-58`) and `DENOMINATION_CAP_BY_SCALE`
(`cashRounding.ts:43-47`) are pinned only against **each other**
(`SaleReceiptV3Payload.test.ts:302-303`). The authority is pinned by nothing.
**✅ VERIFIED:** `apps/api/app/Shared/Domain/CashRoundingCaps.php` `private const CAPS = [0 => '10', 2 => '1.00', 3 => '1.000']`.
Task 8's cap hoist made this gate unconditional on **every** v3 receipt, so a future PHP cap change either
bricks device authoring for a currency scale (`cap === undefined` throws on every sale) or signs a
denomination the server quarantines. Add one `it()` to `FiscalPayloadKeyDrift.test.ts`; the
`readPhpNamedConst` harness already exists. Read the PHP, never modify it.

### B2. Real coverage for the 32-column INSERT lockstep
`insertOfflineReceipt` went 29 → 32 placeholders (`offlineReceiptRepository.ts:99-116`). The unit test
mocks `execute()`; the real-SQLite suites pass an unrounded snapshot (all three new values `null`);
`receiptService.cashRounding.test.ts` mocks `insertOfflineReceipt`. Alignment is correct **by reading
only** — the money-in-the-wrong-column class the plan itself warned about. Add one real-SQLite
round-trip asserting three **distinct** non-null values.

### B3. Assert the Z `tolerance_summary` handoff
`appendZSessionCloseAndZReport` is never asserted, and `tolerance_summary` now enters **signed** Z bytes
with real values for the first time.

### B4. Fix the inert guard — one line
**✅ VERIFIED:** `apps/pos/eslint.config.js` spreads `cartMutatorSelectors` into `no-restricted-syntax`
at `:296` and `:308-310`, but the third re-declaration at `:332` does **not**. Flat config REPLACES rule
options, so the FU-2 cart-mutator guard is **inert app-wide**. The file's own comment at `:39-43` warns
about exactly this hazard. Confirm with `--print-config` before and after. This is also why
`lib/stock/__tests__/cartMutatorGuard.eslint.test.ts` fails — that red is pre-existing and should go green.

---

## 4. Lane C — E1 refund of a rounded receipt (SPEC FIRST, not code)

**Blocks enabling the feature.** Refunding a rounded receipt is off by `|adjustment|` (≤ D/2) in **both**
directions; the round-**up** case short-changes the customer on their own return, with no receipt line
explaining it. Greenfield does NOT retire this — it is prospective, and tenant #1 will hit it.

Three constraints that make the fix non-obvious, which is why this lane is spec-first:
- `hydrateFromReceipt.ts:53` rebuilds the refund cart from the stored `lines` JSON and never reads `receipt.total`.
- `ReceiptReturnService.php:1058-1084` caps returns by **quantity**, not amount — so "refund the rounded
  total" has no obvious insertion point.
- `buildReceiptData.ts:712-713` — the avoir carries no rounding line.

Adopted pattern (from `2026-07-27-refund-rounding-research.md`): independent Swedish rounding of the cash
payout at refund time; no unwinding; partials independent; VAT exact; delta → 6580/7580; its own receipt line.

**Reviewer: is spec-first correct here, or is this small enough to brief directly as code?**

---

## 5. Lane D — first-tenant provisioning path

1. **Wire `CountryPaymentSettingsSeeder` into the demo seeders.** §6 ticket 4: only `ProductionSeeder`
   and `TenantInitializationService` call it; `DatabaseSeeder`, `CoffeeShopSeeder`, `ParapharmacySeeder`,
   `DemoPharmacySeeder` do not — so a freshly seeded demo tenant has no `country_payment_settings` row
   and both mechanisms fail closed to disabled.
   **✅ VERIFIED it has NOT bitten staging:** all 5 staging tenants have their TN row
   (`rounding=false denom=0.0500 postol=false`). This is a NEW-tenant defect only.
   ⚠️ Seeders with `?Company $company = null` silently no-op under `tenants:run db:seed`.
2. **Establish the provision-at-v3 path** — new tenant's terminals created at `fiscal_schema_version = 3`
   with rounding enabled from provisioning. Determine from landed code whether this is already achievable
   or needs a change, and report which.
3. **Consolidate the staging chore backlog into one ordered runbook** — treasury 3/4/5a/5b, multiloc,
   `RolesAndPermissionsSeeder` + `permission:cache-reset` (the Spatie cache is TENANT-BLIND), Horizon
   restart, `DemoPharmacySeeder` rerun. Treasury Task 8 productized the banks/chart backfills as artisan
   commands — use those, not raw SQL. Deliverable is a document; do NOT execute against staging.

---

## 6. Out of scope / parked (confirm these are correctly parked)

- `idempotency_key` on count submission — owner-DEFERRED to a post-promotion lane. Bounded: quantity is
  last-write-wins, exposure is a false `clock_skew` flag. Copy the `StockTransfer` precedent.
- B5 mobile flagged-for-review state — open; recommendation is keep review web-owned (preserves blind counting).
- Deptrac ratchet 97 vs baseline 61 — pre-existing and IDENTICAL on `origin/dev` pre-push; CI does not run
  on push→dev. Proposal: re-baseline to 97 with a ticket for the 36, rather than fix now.
- Device Phase 2's final scoped re-review verdict was never recorded in the ledger (dispatched, no verdict
  line). Documentation gap, not a known defect.

---

## 7. What the review must answer

1. Are the §0 assumptions sound — especially the three MOOT claims and the provision-at-v3 proposal?
2. Is the lane fencing real, or will A and C collide in `apps/api`?
3. Are the verified citations correct, and did the sweep miss consumers?
4. Is any lane mis-scoped — too big to be one lane, or too small to be its own?
5. What is missing entirely that blocks onboarding tenant #1?
