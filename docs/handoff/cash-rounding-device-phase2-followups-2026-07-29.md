# POS cash rounding — DEVICE Phase 2 follow-ups (tickets)

**Date:** 2026-07-29
**Branch:** `feat/pos-cash-rounding-device` (base `0763bf8bf`, head `92c1a96b1`, 22 commits)
**Source:** triage from the whole-branch final review + 11 per-task review gates. The SDD ledger these came from is git-ignored scratch and is deleted at branch close — this file is the durable record.

Nothing here blocks the merge. The first section blocks **enabling the feature**.

---

## 🔓 BLOCKS ENABLE — owner decision required

### E1. Refund of a rounded receipt refunds the wrong amount (symmetric)

`hydrateFromReceipt.ts:53` rebuilds the refund cart from the stored `lines` JSON and never reads `receipt.total`; the server caps returns by **quantity**, not amount (`ReceiptReturnService.php:1058-1084`); the avoir carries no rounding line (`buildReceiptData.ts:712-713`).

So a full refund of a rounded receipt is off by `|adjustment|` (≤ D/2), **in both directions**:

| | sale | refund | effect |
|---|---|---|---|
| rounded **down** 9.973 → 9.950 | customer paid 9.950 | refunded 9.973 | customer up 0.023 |
| rounded **up** 9.977 → 10.000 | customer paid 10.000 | refunded 9.977 | customer **down 0.023** — short-changed on their own return |

The round-**up** row is the complaint generator. The refund exceeds (or falls short of) the sale it reverses, with no ticket line explaining why.

**Gate:** `docs/handoff/cash-rounding-phase2-deploy-checklist.md:185-227`. Enable requires either the refund-rounding track (`docs/superpowers/specs/2026-07-27-refund-rounding-research.md`) landing, or the owner signing the acceptance blank at `:226`.

**Do not read the merge as clearance to enable.**

---

## 🎫 HIGH VALUE — do these next

### T1. Denomination cap table is not pinned against its PHP authority
`V3_DENOMINATION_CAP_BY_SCALE` (`SaleReceiptV3Payload.ts:54-58`) and `DENOMINATION_CAP_BY_SCALE` (`cashRounding.ts:43-47`) are pinned only against **each other** (`SaleReceiptV3Payload.test.ts:302-303`). The authority `CashRoundingCaps::CAPS` (`apps/api/app/Shared/Domain/CashRoundingCaps.php:40-44`) is pinned by nothing — while the 30-key payload set *is* PHP-pinned (`FiscalPayloadKeyDrift.test.ts:20-26`).

Task 8's cap hoist made this gate **unconditional on every v3 receipt**, so a future PHP cap change either bricks device authoring for a currency scale (`cap === undefined` throws on every sale) or signs a denomination the server quarantines. Values match today.
**Fix:** one `it()` in `FiscalPayloadKeyDrift.test.ts` — the `readPhpNamedConst` harness already exists.

### T2. The 32-column INSERT lockstep has zero executing coverage with non-null values
`insertOfflineReceipt` went 29 → 32 placeholders (`offlineReceiptRepository.ts:99-116`). But `offlineReceiptRepository.insert.test.ts` mocks `execute`; the real-SQLite suites pass an unrounded snapshot (all three new values `null`); and `receiptService.cashRounding.test.ts` mocks `insertOfflineReceipt`. Alignment is correct today **by reading only**.
This is the money-in-the-wrong-column class the plan itself warned about, and it would survive a future edit undetected.
**Fix:** one real-SQLite round-trip asserting three *distinct* non-null values.

### T3. `tolerance_summary` now enters signed Z bytes but its handoff is unasserted
`zReportService.ts:670-676` → `zSessionAuthoring.ts:496` puts real (previously hardcoded-zero) values into the **signed** canonical Z_REPORT payload. `zReportService.cashRounding.test.ts` mocks `appendZSessionCloseAndZReport` and never asserts what it received, so a regression reshaping the argument passes.
**Fix:** one `toHaveBeenCalledWith(expect.objectContaining({ toleranceSummary: … }))`.

### T4. ESLint flat-config defect leaves the FU-2 cart-mutator guard inert app-wide
`apps/pos/eslint.config.js:324-342` re-declares `no-restricted-syntax` on the same `src/**/*.{ts,tsx}` glob as the cart-mutator block at `:287-298` **without spreading `cartMutatorSelectors`**. Flat config *replaces* rule options, so only the BEGIN|COMMIT|ROLLBACK selector survives (confirmed via `--print-config`). This is why `lib/stock/__tests__/cartMutatorGuard.eslint.test.ts:51` fails.
**A live disabled guard, unrelated to this track. File it today.**
**Fix:** spread `cartMutatorSelectors` into the `:332` block — the block at `:304-314` already re-spreads them with a comment naming this exact hazard.

### T5. `accountChargeCartMapper.ts:104` computes a percentage discount at default scale 3 on a signed payload
Same defect class Task 5 fixed for the cart total — still live on the **ACCOUNT_CHARGE** signed event (reached from `paymentStore.ts:714-717`). Pick of the low-severity batch because it is on signed bytes.

### T6. `processCardCheckout` uses a discount-blind card leg amount
`paymentStore.ts:1218/:1261` uses `bcsum(line_total)` — the raw subtotal — as the card leg while the receipt total is `computeExactCartTotal`. Nothing checks `Σ payments == total`, so a discounted card sale would sign a payload whose leg exceeds `total_amount` by the discount.
**Currently dead**: `processCardCheckout` has no production caller (HomePage wires only cash / advanced / account charge).
**Must not ship if the card path is ever wired without reconciling the total first.**

### T7. `formatCheckoutError` surfaces every `error.message` verbatim
`paymentStore.ts:464-473` puts raw messages on the cashier's banner, including internal ones (`'tenantId, companyId, shiftId, and seller are required…'`, `FiscalChainContentionError`). Structural rule-11 gap, pre-dating this branch; more visible now that translated errors sit beside untranslated siblings (`receiptService.ts:302`, `:337`).
A name/code dispatch would fix the class — in-repo precedent at `lib/safeErrorNames.ts:41`.

### T8. `paymentRepository` `SELECT *` onto a hand-maintained row interface, no runtime validation
`paymentRepository.ts:76,191-208`. `is_cash_tender` is the first field whose silent-`false` default changes **checkout** behaviour rather than display.

---

## 🎫 LOWER VALUE — batch when convenient

- **EOD auto-accept row renders unconditionally** (`EndOfDayPreviewModal.tsx:344-361`) — shows `Auto-accepts used 0 / 10` even where tolerance can never apply, which pre-enable is *every* shift close. The row directly above it is deliberately hidden under the same condition. Gate it, or accept as intentional.
- **A manager-PIN-approved shortfall is invisible on the device EOD.** `tolerance_shortfall` is written only under `toleranceDecision.applied` (`receiptService.ts:581`), so a shift whose shortfalls were all PIN-approved has `writeoffCount === 0` and never renders the server-backed drill-down that would show them. The server books them regardless (`TreasuryReceiptBridge.php:486-500`). No regression — but the "real aggregation" covers only half the write-offs.
- **`getToleranceAutoAcceptCount` is fail-OPEN on a corrupt row** (`toleranceAutoAcceptRepository.ts:31-32` returns `0` = full budget) directly under a comment saying it must never read as budget-available. Unreachable via the writer; code and comment disagree.
- **Device `cash_rounding_summary` is absent-when-zero; the server's is always the zero shape.** Harmless today (Zs are chain-verified on canonical bytes, never `report_data`) but pre-poisons any future cross-check.
- **Z aggregation window includes voided receipts** (`zReportService.ts:177-193` filters `is_training` only) while EOD (`endOfDayPreview.ts:169`) and the server (`ZReportProjection.php:226`) exclude them — the same-named metric over three windows, and the device value goes into signed Z bytes. Inert (nothing sets `voided = 1` on `offline_receipts`); fixing it also moves `gross_sales`, so not a drive-by.
- **`hydrateFromReceipt.ts:28,56`** — `quantity: number` and `-Math.abs(line.quantity)` is float arithmetic on a quantity (rule 19). Belongs with the refund-rounding track that E1 gates on.
- Comment-accuracy: `HomePage.tsx:1226-1231` overstates its guarantee (a policy tick between the display and sealed snapshots *can* move the signed total; should say "cannot move it **after Confirm**"); `zReportService.ts:792-793` "offline receipts don't have a voided flag" is false and sits beside the new aggregates.
- `receipt_template.rs:60` `has_cash_rounding: Option<bool>` vs sibling `has_tolerance: bool` — asymmetric for no behavioural reason.
- Modal sign tests run against an identity `format` mock, so nothing covers real `formatCurrency` on a negative amount.
- Byte-stability fixture is single-shape (EUR/scale-2, one untaxed line, no discount/buyer/vouchers/approvals).

---

## ⚠️ Known-red at merge (pre-existing, NOT this track)

- `src/lib/sync/__tests__/syncService.test.ts` — 2 failures. Verified byte-identical to base (the branch diff to that file contains no deletion lines).
- `src/components/pos/__tests__/ReportsMenu.test.tsx` — fails to **collect** (`react-i18next` mock hoisting, 0 tests run). Zero commits to it in the branch range.

## ⚠️ Unresolved operational unknown

Whether v3 fiscal events already **queued on-device** at rollback time push cleanly under a rolled-back v2 build. Nobody could settle it; it is recorded as an explicit open question in the deploy checklist rather than assumed safe.
