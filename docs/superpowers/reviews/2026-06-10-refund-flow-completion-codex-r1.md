# Overall Verdict
REQUEST-CHANGES
Confidence: 87%

The branch closes several important refund-flow gaps: the HTTP idempotency path has the expected post-lock re-check and unique-violation backstop, the POS submit path is online-only/fail-closed for reachable server errors, approval evidence is bound to server receipt/line ids, variant-aware restock is implemented, and settled refunds are folded into device Z totals. I would not ship it yet. I found one confirmed backend precision bug that can over-refund/restock beyond the original line, plus two major fiscal/state-machine edges: refund-only shifts cannot generate a local signed Z, and receipt scan hydration can still mutate return lines after the checkout store's stale-cart guard has already passed.

# Findings

## BLOCKER
[B1] apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1045 — Return quantity caps still compare at scale 3, so a 4-decimal request can over-refund/restock by up to 0.0009.

Confirmed reachable through the production HTTP path. `StoreReturnRequest` allows up to four decimals for `lines.*.quantity` at apps/api/app/Modules/POS/Presentation/Requests/StoreReturnRequest.php:53, then `ReceiptController::processReturn` passes the validated string directly into `ReceiptReturnService::processReturn` at apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:289. The service calculates remaining quantity with `bcsub(..., 3)` and compares with `bccomp(..., 3)`, while `calculateAlreadyReturnedQuantities()` also truncates returned quantities at scale 3 at apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1079. Example: original quantity `1.0000`, requested return `1.0009`. At scale 3, the cap comparison treats both as `1.000`, so the request passes; `computeReturnTotals()` then uses the full `1.0009` ratio, `restoreStock()` adds `1.0009`, and the signed return receipt overstates the refund. The new batch restitution code caps each batch allocation, but aggregate stock and fiscal money totals are already wrong.

## MAJOR
[M1] apps/pos/src/lib/offline/zReportService.ts:156 — Refund-only shifts cannot generate/sign a local Z report.

`generateZReport()` throws when `offline_receipts` is empty before it reads `local_refund_records` at apps/pos/src/lib/offline/zReportService.ts:169. A real cashier can open a shift, process only a refund through the new online return flow, get a `local_refund_records` row, and then fail Z close with "Cannot generate Z-report for a shift with no receipts." The B2 tests cover refunds folded into a shift that also has a sale, different-shift exclusion, and empty refund records, but I did not find a refund-only shift test. This leaves a compliant refund transaction without a local signed Z closure path.

[M2] apps/pos/src/pages/HomePage.tsx:449 — Receipt scan hydration remains active during refund approval/submission and can bypass the one-time stale-cart guard.

The scanner is enabled for any open shift, independent of `refundCheckoutStore.step`. A scanned receipt can still mount `ReceiptScanConfirmationSheet` at apps/pos/src/pages/HomePage.tsx:1535, and accepting it hydrates/replaces return items at apps/pos/src/pages/HomePage.tsx:543. The checkout store checks the return-line fingerprint only once, before async manager PIN authoring/sync/submit starts, at apps/pos/src/stores/refundCheckoutStore.ts:318. If a cashier accepts another receipt scan after that check but before `submitRefundReturn()` resolves, the server settles the old prepared lines while the live cart/draft now points at different return lines. The existing stale-cart test covers mutation before `approveAndSubmit()` starts; it does not cover mutation after the guard but before/during submit.

## MINOR
No issues found.

# Per-Area Notes

1. Backend HTTP contract additions: `refund_request_id` is required in the request, `refund_destination` is allowlisted and excludes `exchange_deferred`, and the service has cheap lookup, post-lock lookup, and unique-violation replay. The remaining issue is B1's scale-3 cap in the service.

2. POS refund settlement layer: QR-index local-to-server resolution and number fallback are guarded against token mismatch. Line mapping is variant-aware and uses scale-4 decimal helpers. 5xx/408/429 retry then fail closed; typed timeout/connectivity false are the only offline downgrade paths. No double unwrap found.

3. Refund checkout UI/state machine: Pay interception blocks mixed sale/return carts and re-checks sale payment modals. Approval evidence is authored once, synced separately, and cached for retry. M2 is the open race: scan hydration is not gated while approval/submission is in flight.

4. AVOIR/voucher printing: print data is built from the server settlement response, not the live cart. The builder validates numeric strings before handing them to Big/bcformat, and voucher ticket printing is tied to `issued_voucher`. No issues found.

5. Device Z refund accounting: settled refunds are mirrored idempotently by server return receipt id, cash impact is only applied to cash destinations, refunds amount is positive, and perpetual grand total subtracts refunds. M1 remains: refund-only shifts are rejected before refund records are considered.

6. Backend variant-aware restock and batch restitution: restock now targets the exact variant/null row and copies `variant_id` onto return lines. Batch restitution uses the cumulative `min(a, trunc4(a * R / Q))` invariant, and voiding return receipts is guarded. B1 can still over-restore aggregate stock before batch caps matter.

7. Web-admin return surface quarantine: `ReturnItemsModal`, `ReceiptSearchPage`, and web `processReturn` helpers were removed/quarantined, so the web admin no longer calls the changed return contract. No cross-app caller breakage found.

# Recommended Next Steps

1. Fix `ReceiptReturnService` return-quantity accounting to use canonical quantity scale 4 end-to-end (`alreadyReturned`, `remainingReturnable`, comparisons, legacy fallback), and add regression tests for `1.0009` requested against `1.0000` original plus repeated 4-decimal partial returns.
2. Move `local_refund_records` loading before the empty-receipt guard, allow Z generation when either sales or settled refunds exist, and add a refund-only Z-report test covering refunds_count, refunds_amount, expected_cash, cumulative_refunds, and perpetual_grand_total.
3. Gate receipt-scan confirmation/hydration while `refundCheckoutStore.step` is not `idle`, or re-run the snapshot fingerprint immediately before submit and before clearing return items. Add a test for cart mutation after approval begins but before `apiPost` resolves.
