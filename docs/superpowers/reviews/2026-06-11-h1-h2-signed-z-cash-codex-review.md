# Adversarial Review: H1+H2 Signed-Z Cash Reconciliation

## Verdict
REQUEST-CHANGES

## Confidence
86% - I read both reference documents, all five scoped files, and the scoped diff for `1ff7d488b..HEAD`. Confidence is not 100% because I did not read source outside the five-file scope, so the legacy `receipt.total` interpretation is marked as a hypothesis where relevant.

## Findings

### [P1] EOD preview expected_cash omits cash refunds
**File:** apps/pos/src/lib/offline/endOfDayPreview.ts line 255
**Evidence:**
```ts
const expectedCash = bcadd(
  bcsub(bcadd(openingCash, cashTenderedSum), cashChangeDueSum),
  drawerNet,
);
```
The signed Z path subtracts refund cash impact in the changed formula at `apps/pos/src/lib/offline/zReportService.ts` line 217:
```ts
bcsub(bcadd(openingCash, cashSales, decimals), cashRefundImpact, decimals),
```
There is no refund-record read or cash-refund subtraction in the preview formula. The unchanged preview receipt query is not in the diff.
**Impact:** On any shift with a cash refund, the operator preview overstates expected cash relative to the device-signed Z. That breaks the stated zero-drift requirement between `endOfDayPreview` and the signed `report_data`, and can make the counted-cash variance shown before close disagree with the fiscal payload that is hashed.
**Fix:** Load the local refund mirror for `shiftId` in `buildEndOfDayPreview`, sum `cash_impact`, and subtract it exactly once in the same position as `zReportService`.

### [P1] Account-payment-only shifts are rejected before account payments are loaded
**File:** apps/pos/src/lib/offline/zReportService.ts line 209
**Evidence:**
```ts
const accountPayments = await getAccountPaymentRecordsForShift(db, shiftId);
```
This new read occurs after the unchanged empty-shift guard at line 170, which is not in the diff:
```ts
if (receipts.length === 0 && refundRecords.length === 0) {
  throw new Error('Cannot generate Z-report for a shift with no receipts.');
}
```
**Impact:** A shift with only a customer cash account payment has a positive drawer cash movement and a fiscal `ACCOUNT_PAYMENT` event, but `generateZReport` throws before folding it into the signed Z. The device source of truth cannot close that fiscal activity into the hash chain.
**Fix:** Read account-payment records before the empty-shift guard and include them in the closeability test. If drawer-only shifts are intended to be Z-closeable too, load drawer ops before the guard and include those as well.

### [P1] HYPOTHESIS: signed legacy fallback double-nets change
**File:** apps/pos/src/lib/offline/zReportService.ts line 779
**Evidence:**
```ts
methodCode === 'CASH' ? bcsub(receipt.total, receipt.change_due ?? '0') : receipt.total;
```
This fallback runs when `payments_json` is missing or not an array. HYPOTHESIS: within this same function `receipt.total` is the sale/gross total already allocated to the receipt, not the cash tendered amount; it is used as `grossSales` at line 723, which is not itself a changed line. If so, subtracting `change_due` here nets change a second time.
**Impact:** A legacy cash receipt with `total=10.00` and `change_due=2.00` would sign `8.00` as the CASH payment method and feed `8.00` into expected cash, even though the allocated/net cash sale is `10.00`. That undercounts the hashed `payment_methods` and `expected_cash`.
**Fix:** For the no-`payments_json` fallback, treat `receipt.total` as the allocated net amount for the primary method, or fail hard if the cash-tendered amount is required but unavailable. Do not subtract `change_due` unless the fallback source is known to be gross tendered cash.

### [P2] EOD preview has no legacy no-payments_json fallback
**File:** apps/pos/src/lib/offline/endOfDayPreview.ts line 255
**Evidence:**
```ts
const expectedCash = bcadd(
  bcsub(bcadd(openingCash, cashTenderedSum), cashChangeDueSum),
  drawerNet,
);
```
`cashTenderedSum` is populated only from parsed `payments_json`; that parser is at line 178 and is not in the diff. Unlike `zReportService` lines 776-783, there is no fallback to the primary receipt payment method when `payments_json` is empty or malformed.
**Impact:** A receipt without usable `payments_json` is still counted in preview sales totals, but contributes nothing to preview payment methods or expected cash. The signed Z and preview then diverge on the exact legacy edge case called out in the review checklist.
**Fix:** Mirror the signed-Z fallback in preview: when `payments_json` has no usable rows, allocate the receipt to its primary payment method and apply the same cash-net rule chosen for `zReportService`.

### [P2] EOD preview still includes training receipts
**File:** apps/pos/src/lib/offline/endOfDayPreview.ts line 219
**Evidence:**
```ts
// per-method CASH line consistent (and identical to the signed Z).
```
The preview receipt query at line 123 is not in the diff and filters only `terminal_id`, `created_at`, and `voided = 0`; it does not apply `is_training = 0`. The signed Z query does exclude training receipts at `apps/pos/src/lib/offline/zReportService.ts` lines 152-154, but those lines are not newly added in this diff.
**Impact:** Training cash receipts can appear in the EOD preview payment methods and expected cash while being excluded from the hashed Z report. That invalidates the claim that the preview cash line is identical to the signed Z and creates operator-visible drift before close.
**Fix:** Add the same production filter to the preview receipt query: `AND is_training = 0`.

## Summary
The signed Z path gets the core happy-path signs mostly right: cash sales are net, drawer deposits add, payouts subtract, cash account payments add, and `report_data` is passed into `computeZReportHash`. The launch risk is in edge-path drift and closeability: preview does not subtract cash refunds, account-payment-only shifts cannot close, and the legacy no-`payments_json` behavior is inconsistent and likely wrong. I would not ship this as a device-authority fiscal source of truth until those paths are corrected or explicitly ruled unreachable.
