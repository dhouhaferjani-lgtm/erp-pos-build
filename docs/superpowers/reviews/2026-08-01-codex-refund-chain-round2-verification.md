# Scoped verification report

- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain`
- **Commit range:** `6cb629d96..ce06b4005`
- **Date:** 2026-08-01

## Per-item verdicts

| Item | Verdict | Evidence |
|---:|---|---|
| #2 | **RESOLVED** | The resolver selects row identity columns and rejects mismatched envelope `event_type`, `event_version`, `terminal_id`, or `sequence_number` before returning signed fields ([fiscalEventRepository.ts:250](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:250), [fiscalEventRepository.ts:361](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:361)). |
| #3 | **RESOLVED** | The sole exported `REFUND_QUANTITY_SCALE = 3` drives boundary normalization, intent cap arithmetic, and the local receipt mirror; no scale-4 normalization remains in this path ([RefundReceiptV4Payload.ts:270](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/fiscal/payloads/RefundReceiptV4Payload.ts:270), [refundCheckoutStore.ts:321](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/stores/refundCheckoutStore.ts:321), [refundIntentRepository.ts:20](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/db/repositories/refundIntentRepository.ts:20), [refundReceiptService.ts:95](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/offline/refundReceiptService.ts:95)). |
| #6 | **RESOLVED** | Unreadable or missing signed `approval_id` now throws `ApprovalIdentityUnreadableError` before the override append; no candidate-ID fallback remains ([posOverrideAuthoring.ts:100](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/operatorApproval/posOverrideAuthoring.ts:100), [posOverrideAuthoring.ts:238](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/operatorApproval/posOverrideAuthoring.ts:238)). |
| #9 | **RESOLVED** | Nothing in this range bypasses the parked launch design: the device retains three-attempt retry plus persisted failure marker, while the server rejects ACK before the capability is offered and guards legacy corrections after ACK ([syncService.ts:1363](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/sync/syncService.ts:1363), [V4RefundAuthoringAcknowledgementService.php:31](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/api/app/Modules/POS/Application/Services/V4RefundAuthoringAcknowledgementService.php:31), [LegacyCorrectionGuard.php:34](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/api/app/Modules/POS/Application/Services/LegacyCorrectionGuard.php:34)). |
| #11 | **RESOLVED** | Read-only active-intent lookup and resume occur before the cap; appended reuse verifies event source, receipt idempotency, and canonical-byte linkage, with a real-repository SQLite ordering test ([refundCheckoutStore.ts:851](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/stores/refundCheckoutStore.ts:851), [refundCheckoutStore.ts:1233](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/stores/refundCheckoutStore.ts:1233), [refundCheckoutStore.reuseOrdering.test.ts:197](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/stores/__tests__/refundCheckoutStore.reuseOrdering.test.ts:197)). |
| #12 | **RESOLVED** | Fiscal-event and receipt flips explicitly require `rowsAffected === 1`; the PK-scoped intent transition throws on zero and only permits a confirmed already-synced duplicate ACK ([syncService.ts:371](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/sync/syncService.ts:371), [refundIntentRepository.ts:206](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/db/repositories/refundIntentRepository.ts:206)). |
| #19 | **RESOLVED** | The ceiling uses validator-checked signed `total` and `cash_rounding_adjustment`, derives exact value as `total − adjustment`, and throws typed exceeded/unreadable errors without clamping ([fiscalEventRepository.ts:327](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:327), [refundCheckoutStore.ts:1097](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/stores/refundCheckoutStore.ts:1097), [refundCheckoutStore.ts:1138](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/pos/src/stores/refundCheckoutStore.ts:1138)). |

## Wave-4 findings

- **CASE-expression aggregates — none found:** return contributions use per-row `-ABS(column)` across summary, period, cashier, customer, and perpetual aggregates, correctly handling both sign eras ([PosAnalyticsService.php:36](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php:36), [PosAnalyticsService.php:430](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php:430), [GrandtotalService.php:230](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/api/app/Modules/POS/Domain/Services/GrandtotalService.php:230)).

- **Period totals — none found:** gross/tax net return magnitudes while the signed count/refund-key meanings remain unchanged ([GrandtotalService.php:164](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/api/app/Modules/POS/Domain/Services/GrandtotalService.php:164)).

- **Shift totals/payment netting — none found:** `refunds_amount` is positive magnitude and return payment legs are subtracted in both shift reporting and expected-per-method aggregation ([ReportGenerationService.php:521](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:521), [ReportGenerationService.php:994](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:994)).

- **`SUM(ABS)` placement — none found:** owner returns use per-row `SUM(CASE … ABS(total) …)`, preventing mixed-era cancellation ([OwnerSalesSummaryService.php:105](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/api/app/Modules/Accounting/Application/Services/Reports/OwnerSalesSummaryService.php:105)).

- **Important — float leakage:** the changed cashier `AVG(-ABS(return)/sale)` monetary aggregate is still cast to PHP `float` and rounded, violating the decimal-only contract and risking precision loss ([PosAnalyticsService.php:194](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php:194), [PosAnalyticsService.php:204](/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php:204)). The cast existed at the base commit, so this is not counted as new breakage.

## New breakage introduced strictly by this diff

None found at Critical or Important severity. The wave-4 float finding above is retained pre-existing code—the range’s `@@ -194,2 +194,2` hunk changes only the SQL expressions, not the cast at line 204.

## Overall verdict

**NOT APPROVED** — all seven prior findings are resolved and no new Critical/Important regression was introduced, but wave-4 still leaves monetary `average_ticket` flowing through PHP floating-point arithmetic. Fresh verification: 29 targeted API tests passed with 166 assertions, TypeScript no-emit and PHP syntax checks passed, and the worktree remained clean at `ce06b4005`. POS tests are **UNVERIFIED** because Vite attempted to write a temporary config module and the mandated read-only filesystem blocked it.

## Summary counts

**RESOLVED: 7/7, NOT RESOLVED: 0/7, NEW CRITICAL: 0, NEW IMPORTANT: 0.**
