# Round 2 Review

Date: 2026-06-10
Branch: feat/refund-flow-completion
Reviewer: Codex
Repository: /Users/houssamr/Projects/syneriva/apps/erp.refund-completion

Round 1 review file: docs/superpowers/reviews/2026-06-10-refund-flow-completion-codex-r1.md
R1 baseline for fix diff: 856d81914fb0490ee34dbf5385918281acffc3e4
R1 reviewed code head: d8a28dd9d (parent of the Round 1 review-doc commit, inferred from git log)
Current head: b1612870c7187d8f2f410dec9c60e263da911490

Reviewed fix commits:

- ae491e317c4fac0feffa8697740a6c5781106b19 - B1: lift return-quantity caps to canonical scale 4 (over-refund fix)
- adbbe87929a0433d2656578ee5c03dce47f3f077 - M1: allow Z-report generation for refund-only shifts
- b1612870c7187d8f2f410dec9c60e263da911490 - M2: gate receipt scans during refund checkout + post-settle drift guard

## Fix Verification

### B1 - Return-quantity scale

VERIFIED CLEAN.

I found no remaining scale-3 quantity accounting in the return-quantity cap path. `validateReturnQuantities()` compares positivity, remaining returnable, and the over-return cap at scale 4, with the zero default lifted to `0.0000` at apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1038, apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1043, apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1045, and apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1047.

`calculateAlreadyReturnedQuantities()` now derives absolute return quantities and accumulates both the canonical `original_line_id` path and the legacy product-attribute fallback at scale 4: apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1082, apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1086, and apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1101.

Downstream quantity consumers also preserve scale 4: stock restoration adds returned stock at scale 4 at apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1163; batch restitution receives the scale-4 `already_returned` value at apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:406 and apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:428, computes `newReturned` at scale 4 at apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1233, and computes cumulative/delta restitution at scale 4 at apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1243 and apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1297.

The request floor is now consistent with the repository precision model: `StoreReturnRequest` allows `min:0.0001` with a 4-decimal regex at apps/api/app/Modules/POS/Presentation/Requests/StoreReturnRequest.php:53 and apps/api/app/Modules/POS/Presentation/Requests/StoreReturnRequest.php:56. POS receipt quantities are cast as `decimal:4` at apps/api/app/Modules/POS/Domain/ReceiptLine.php:105, and the canonical quantity migration states all ERP quantity columns use four decimal places at apps/api/database/migrations/tenant/2026_05_29_100002_widen_quantity_columns_to_scale_4.php:11.

The HTTP rejection contract remains the existing 400 `INVALID_RETURN_DATA`: the controller maps `InvalidArgumentException` to that code at apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:348 and apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:351, and the new regression asserts the same status/code for a `1.0009` over-return at apps/api/tests/Feature/POS/ReceiptReturnFlowTest.php:811 and apps/api/tests/Feature/POS/ReceiptReturnFlowTest.php:814.

### M1 - Refund-only local Z report

VERIFIED CLEAN.

The empty-shift guard now runs after `local_refund_records` are loaded. Receipts are read at apps/pos/src/lib/offline/zReportService.ts:148, refund records are read once at apps/pos/src/lib/offline/zReportService.ts:166, and the guard throws only when both arrays are empty at apps/pos/src/lib/offline/zReportService.ts:168. The both-empty throw remains reachable and tested at apps/pos/src/lib/offline/__tests__/zReportService.test.ts:761.

Refund-only Z math is signed correctly. Refund records contribute a positive `refunds_amount` from `bcabs(record.total)` at apps/pos/src/lib/offline/zReportService.ts:676 and apps/pos/src/lib/offline/zReportService.ts:679, cash refunds reduce expected cash through `cash_impact` at apps/pos/src/lib/offline/zReportService.ts:187 and apps/pos/src/lib/offline/zReportService.ts:190, and grand totals subtract refunds via `gross_sales - refunds_amount` at apps/pos/src/lib/offline/zReportService.ts:305. The refund-only regression covers zero sales, zero tax, empty payment methods, refund amount, expected cash, cumulative refunds, perpetual grand total, and zero receipt-count delta at apps/pos/src/lib/offline/__tests__/zReportService.test.ts:715 through apps/pos/src/lib/offline/__tests__/zReportService.test.ts:739.

The load-before-guard change does not add a double-load. `getRefundRecordsForShift()` is a single `SELECT ... WHERE shift_id = $1` at apps/pos/src/lib/db/repositories/localRefundRecordRepository.ts:78 and apps/pos/src/lib/db/repositories/localRefundRecordRepository.ts:87. The persisted Z transaction still begins later, immediately before insert/chain/grand-total writes, at apps/pos/src/lib/offline/zReportService.ts:374 through apps/pos/src/lib/offline/zReportService.ts:381.

### M2 - Scan gate and post-settle drift

VERIFIED CLEAN.

Receipt-token scan ingress is gated in all observable paths I found. HomePage blocks new `receipt-token` scan results while `refundCheckoutStore.step !== 'idle'`, with the translated toast at apps/pos/src/pages/HomePage.tsx:427 and apps/pos/src/pages/HomePage.tsx:434. The store seam independently rejects non-null pending scans while checkout is active at apps/pos/src/stores/refundFlowStore.ts:72 and apps/pos/src/stores/refundFlowStore.ts:77. If a confirmation sheet opened while idle and checkout became active before the cashier accepted it, `acceptPendingScan()` clears the pending scan and emits no accepted token at apps/pos/src/stores/refundFlowStore.ts:81 through apps/pos/src/stores/refundFlowStore.ts:94.

The accepted-token hydration path also has a final guard before cart mutation. HomePage consumes the accepted token at apps/pos/src/pages/HomePage.tsx:532, drops it with the blocked-scan toast when checkout is active at apps/pos/src/pages/HomePage.tsx:538 through apps/pos/src/pages/HomePage.tsx:541, and only then hydrates/replaces return items at apps/pos/src/pages/HomePage.tsx:562 and apps/pos/src/pages/HomePage.tsx:565. `ReceiptLocatorScreen` emits through the same store seam, not a separate direct hydration path, at apps/pos/src/components/pos/ReceiptLocatorScreen.tsx:137 and apps/pos/src/components/pos/ReceiptLocatorScreen.tsx:138.

The post-settle drift guard is correct. After a successful server settle and after the epoch guard, the store recomputes the live return-line fingerprint at apps/pos/src/stores/refundCheckoutStore.ts:425 through apps/pos/src/stores/refundCheckoutStore.ts:427. It clears return lines only when the live fingerprint still matches the begin snapshot at apps/pos/src/stores/refundCheckoutStore.ts:428 and apps/pos/src/stores/refundCheckoutStore.ts:429. On drift, it keeps the live lines, records the settled response, records local Z-accounting status, and surfaces `refundFlow.checkout.errorCartChangedAfterSettle` at apps/pos/src/stores/refundCheckoutStore.ts:431 through apps/pos/src/stores/refundCheckoutStore.ts:438.

`acknowledgeSettled()` preserves that error while moving the flow back to idle, so the idle banner can render it: apps/pos/src/stores/refundCheckoutStore.ts:454 through apps/pos/src/stores/refundCheckoutStore.ts:468. The regression test covers the old race window by mutating return lines after the POST has started and before it resolves, then asserting settled response recorded, new lines kept, drift error surfaced, and the error surviving acknowledge at apps/pos/src/stores/__tests__/refundCheckoutStore.test.ts:403 through apps/pos/src/stores/__tests__/refundCheckoutStore.test.ts:452.

## Regression Checks

### Over-return contract

VERIFIED CLEAN. The scale-4 accumulator still blocks over-returns: prior return quantities are accumulated at scale 4 at apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1082 through apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1101, remaining returnable is computed at scale 4 at apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1045, and the cap comparison is scale 4 at apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1047. The new tests cover both `1.0009` against `1.0000` and repeated `0.3333 + 0.3333 + 0.3333 + 0.0001` with a final rejected `0.0001` at apps/api/tests/Feature/POS/ReceiptReturnFlowTest.php:785 and apps/api/tests/Feature/POS/ReceiptReturnFlowTest.php:827.

### Batch restitution R_prev consumption

VERIFIED CLEAN. `already_returned` now carries scale-4 prior returns from validation into `restoreBatchAllocations()` at apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:406 through apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:428. The batch restitution invariant remains cumulative `R_prev -> R_new`, with `newReturned` at scale 4, scale-4 cumulative calculations, and allocation cap via `min(allocation, cumulative)` at apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1233, apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1239, apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1240, apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1297, and apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1299.

### Z math for mixed sale+refund shifts

VERIFIED CLEAN. Mixed shifts still aggregate receipts once and refund records once, then combine them in `aggregateReportData()` at apps/pos/src/lib/offline/zReportService.ts:176. Sales totals and payment methods are driven only by `receipts` at apps/pos/src/lib/offline/zReportService.ts:686 through apps/pos/src/lib/offline/zReportService.ts:714; refund totals are driven only by `refundRecords` at apps/pos/src/lib/offline/zReportService.ts:676 through apps/pos/src/lib/offline/zReportService.ts:680. Expected cash subtracts only `record.cash_impact`, not all refund destinations, at apps/pos/src/lib/offline/zReportService.ts:187 through apps/pos/src/lib/offline/zReportService.ts:190. The existing mixed sale+refund regression still asserts `gross_sales=50.00`, `tax_amount=8.00`, `refunds_amount=22.75`, and the one-call grand-total update at apps/pos/src/lib/offline/__tests__/zReportService.test.ts:519 through apps/pos/src/lib/offline/__tests__/zReportService.test.ts:545.

### Epoch-guard semantics

VERIFIED CLEAN. The M1 fix did not touch the refund checkout epoch model. `begin()` increments the epoch at apps/pos/src/stores/refundCheckoutStore.ts:243 and bails stale prepare continuations at apps/pos/src/stores/refundCheckoutStore.ts:261. `approveAndSubmit()` captures the epoch at apps/pos/src/stores/refundCheckoutStore.ts:289 and checks it after approval authoring, approval sync, and local Z accounting at apps/pos/src/stores/refundCheckoutStore.ts:346, apps/pos/src/stores/refundCheckoutStore.ts:353, apps/pos/src/stores/refundCheckoutStore.ts:363, apps/pos/src/stores/refundCheckoutStore.ts:370, and apps/pos/src/stores/refundCheckoutStore.ts:408. `cancel()` and `reset()` still bump the epoch at apps/pos/src/stores/refundCheckoutStore.ts:447 and apps/pos/src/stores/refundCheckoutStore.ts:472.

### i18n key completeness

VERIFIED CLEAN. New keys are present in both English and French:

- `refundFlow.checkout.errorCartChangedAfterSettle`: apps/pos/src/locales/en/pos.json:143 and apps/pos/src/locales/fr/pos.json:143
- `refundFlow.checkout.scanBlockedDuringCheckout`: apps/pos/src/locales/en/pos.json:149 and apps/pos/src/locales/fr/pos.json:149

Missing keys: none found.

## Diff Scope Check

VERIFIED CLEAN.

`git log --reverse 856d81914..b1612870c` contains exactly the three requested fix commits:

- ae491e317c4fac0feffa8697740a6c5781106b19
- adbbe87929a0433d2656578ee5c03dce47f3f077
- b1612870c7187d8f2f410dec9c60e263da911490

`git diff --name-status 856d81914..b1612870c` contains only the expected B1, M1, and M2 files:

- B1: ReceiptReturnService, StoreReturnRequest, ReceiptReturnFlowTest
- M1: zReportService, zReportService.test
- M2: en/fr POS translations, HomePage, refundCheckoutStore, refundFlowStore, and their focused tests

`git diff --check 856d81914..b1612870c` returned no whitespace/errors. I found no unrelated schema, API, styling, dependency, or docs churn in the fix range.

## Findings Summary

| severity | file:line | description |
| --- | --- | --- |
| None | n/a | No confirmed BLOCKER, MAJOR, or MINOR findings in Round 2. |

## Verdict

APPROVE

Confidence: 91%

Rationale: The three Round 1 findings are closed by concrete code changes and focused regressions. B1 now uses canonical scale 4 end-to-end for return-quantity validation and prior-return accumulation. M1 now allows refund-only Z generation without changing mixed-shift math or the both-empty guard. M2 gates receipt-token scans at dispatcher/store/hydration ingress points and adds a post-settle fingerprint check that keeps drifted lines while preserving the settled response and surfaced error. Static diff scope is limited to the requested fixes and tests.

Residual risk: I did not execute the full backend/frontend test suites in this read-only review pass; this verdict is based on source inspection, targeted grep, JSON key validation, and diff checks.
