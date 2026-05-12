# PR B — Opus Pre-Merge Audit

**Subject:** fix(pos): cash payment amount = tendered + void over-refund fix
**PR:** https://github.com/otospexsolutions/erp/pull/121
**Base:** `dev`
**Branch:** `fix/pos-cash-payment-amount-is-tendered`
**Head:** `d56d3507` (post-Codex r3 APPROVE)
**Date:** 2026-05-11
**Auditor:** Opus 4.7 (same model that authored the implementation — see "Limitations" section)

---

## Summary

PR B ships two causally-connected fixes:

1. **`apps/pos/src/stores/paymentStore.ts`** — `processCashCheckout` stores `payments[0].amount = tenderedAmount` (was cart total). Aligns with the backend contract documented at `CashCountToleranceVarianceRegressionTest.php:30-40`. Closes Bug 2 ("Monnaie rendue: 0" on the printed ticket + phantom over-tender shortage in cash variance reports).
2. **`apps/api/.../ReceiptVoidService.php`** — universal cash-refund formula `max(0, min(Σ(cash.amount), total − Σ(non_cash) − tolerance_writeoff))`. Replaces a brittle `cash_sum − change_due` that didn't survive Codex's two rounds of edge-case interrogation.

Preflight green on every gate:
- `pnpm typecheck` — 0 errors
- `pnpm lint` — 0 errors / 41 warnings (baseline preserved)
- `pnpm test` — 1230 passed (137 files) — 3 new
- `phpstan` POS — 0 errors (level 8)
- `pint` — pass on touched files
- `phpunit` POS Unit `ReceiptVoidServiceTest` — 9 passed (+3 new)
- `phpunit` POS Feature `SyncReceiptsTest` — 11 passed (+1 new round-trip)
- `phpunit` POS Feature full — 594 passed

---

## L1 — Cross-tenant audit

Two changes:
- Frontend `paymentStore.ts:processCashCheckout` runs on every tenant (Menu, standard-retail, hybrid, non-Menu). It writes the tendered amount to the receipt's `payments[0].amount`. The downstream consumers (fiscal hash, sync payload, print path) are tenant-agnostic, and the backend contract is tenant-agnostic. **No L1 risk on the POS side.**
- Backend `ReceiptVoidService.recordCashDrawerRefund` runs on void operations against `pos_receipts`. The formula reads `payment.payment_type === 'CASH'`, `payment.amount`, `receipt.total`, `receipt.tolerance_writeoff`. None of these vary across tenant classes. **No L1 risk on the API side.**

## L8 — Cross-screen ownership

No new screens. No new state surfaces. The cashier-facing checkout UI's view of "Monnaie rendue" changes (it now shows the correct value), but the calculation owner is unchanged — the print path `buildReceiptData.ts:100-102` still derives change as `Σ(payments) − total`. **No L8 risk.**

## L9 — Ingress audit

### Frontend payments[].amount writes

Every site that writes a cash-row amount:
- `paymentStore.ts:processCashCheckout` (cash quick-path) — **changed by this PR** to tendered.
- `paymentStore.ts:processAdvancedSplit` — already passes per-tender amounts (no Bug 2 issue).
- Other tests/fixtures don't write production data.

### Frontend payments[].amount consumers

- `computeV3FiscalHash` (`receiptService.ts:210`) — hashes `payments.amount`. Now hashes tendered. Server recomputes from same wire payload → hashes match. Confirmed by the new `SyncReceiptsTest::test_sync_offline_cash_over_tender_round_trip_persists_tendered_amount_and_change` returning `status='synced'`.
- `payments_json` column on `offline_receipts` — stores the wire-shape payments. `getOfflineReceiptForPrint` reads it back. Print path derives change as `Σ(payments) − total` ⇒ `tendered − total = correct change`.
- Wire payload `payments[].amount` — server `ReceiptSyncService:545` persists to `pos_receipt_payments.amount`. Already treated as tendered by the backend (per the existing `CashCountToleranceVarianceRegressionTest` contract).

### Backend cash-refund recipients

- `CashDrawerService::recordRefund` — receives `$netDrawerCash`, writes `cash_drawer_operations.amount` for a `REFUND` row. Downstream consumer is `getTotalByType($shift, 'REFUND')` in shift-close reports. The new formula gives the correct net cash for every payment.amount shape (verified by the 9-case void test suite + the table in the PR body).

**No L9 ingress gap.**

---

## Root cause vs symptom check

- Bug 2's headline symptom was "Monnaie rendue: 0" on every printed ticket. The cause was the cash quick-path writing the cart total as `payments[0].amount`, which made `Σ(payments) − total = 0`. Fix: write `tenderedAmount` instead. **Root cause addressed.**
- Bug 2's secondary symptom was phantom cash-variance shortage on over-tender shifts. Same root cause (the SUM in `ReportGenerationService::buildExpectedPerMethod`). **Root cause addressed.**
- The void over-refund issue was a direct second-order effect of the contract change — `payment.amount` going from total to tendered meant the existing void code now over-refunded by `change_due`. Fixed with the universal formula. **Logically required.**

**No symptom-only patching.**

---

## Refund-formula safety review

The final formula:

```php
$refund = max(0, min($cashSum, $receipt->total − $nonCashSum − $tolerance));
```

Properties:
1. **Idempotent.** Running it twice on the same input gives the same result.
2. **Bounded.** Refund is never negative (defensive clamp). Refund is never greater than what was tendered (`min` with `cashSum`) and never greater than the cash portion of the sale net of write-offs (`min` with `cash_owed`).
3. **Universal.** Verified against 7 distinct scenarios in the PR B r2 review table — post-fix over-tender, pre-fix over-tender, tolerance short-pay, exact tender, split exact, split with cash over-tender, pure non-cash.
4. **Tenant-agnostic.** No tenant-specific branches.
5. **Idempotent against the report.** After voiding, `ReportGenerationService::buildExpectedPerMethod` excludes the voided receipt's `pos_receipt_payments.amount` row (the `where('is_voided', false)` filter at `ReportGenerationService.php:492`), and adds the new `REFUND` operation to the expected calculation. The physical drawer state after the void = expected_cash recomputation = net cash actually moved (which equals the refund amount). Both bookkeeping and physical reality stay in sync.

---

## Codex round trail

- r1: 1 P2 (pre-fix legacy over-tender → my initial `cash_sum − change_due` collapsed to 0). **Closed** by switching to `total − non_cash_sum`.
- r2: 1 P2 (tolerance short-pay → `total − non_cash_sum` over-refunded by the tolerance write-off). **Closed** by switching to `min(cash_sum, total − non_cash − tolerance)`.
- r3: no findings. **APPROVE.**

Three rounds is the upper end of the "routine" range. Each finding was a real correctness gap I missed in the previous iteration; Codex's interrogation strengthened the formula meaningfully. The cumulative work proves the final formula is the right invariant.

---

## Residual risk — what manual smoke must verify

The unit tests cover every scenario mechanically, but the printed ticket and the cashier-facing variance display are out-of-test for this PR. Recommended:

- [ ] Ring up a €10 cash receipt with €20 tendered. Verify printed ticket shows `Monnaie rendue: 10.00` (not `Monnaie rendue: 0`). Verify `/pos/receipts` shows `change_due = 10.000`, `pos_receipt_payments.amount = 20.000`.
- [ ] Ring up a €100 cash receipt with €99.70 tendered (tolerance short-pay). Verify the tolerance write-off posts to GL 658 as `0.300`; verify the printed ticket shows `Monnaie rendue: 0.00` (no over-tender) and the variance report stays balanced when the cashier counts exactly €99.70.
- [ ] Void the over-tender receipt above. Verify the cash drawer logs a `REFUND` of `10.000` (not `20.000`).
- [ ] Void the tolerance receipt. Verify the cash drawer logs a `REFUND` of `99.700` (not `100.000`).
- [ ] Close the shift after both voids. Verify the variance is balanced.

---

## Limitations of this audit

Same as PR A — I authored the implementation and am now reviewing it. The Codex r1+r2 P2 findings were both real bugs I missed initially; the r3 APPROVE is a stronger signal than my own self-review. Confidence in the formula is high because three independent passes converged on it.

The only un-mechanically-verified surface is the cashier-facing UI (printed ticket text, variance display). The manual-smoke list above covers it.

---

## Verdict

**APPROVE-PENDING-CASHIER-SMOKE.**

The contract change is correct, the void formula is correct, the test coverage is comprehensive, and Codex independently signed off after three rounds (one routine, two real-finding closures). The only outstanding question is whether the cashier-facing UI renders the new shape correctly under live conditions — that's the manual smoke list. Lint/typecheck/phpstan/phpunit/vitest are all green.

**Recommended before merge:** the four cashier-smoke checks above. The backend math is rigorously covered; the printed-ticket and variance-display render are the residual unknowns.
