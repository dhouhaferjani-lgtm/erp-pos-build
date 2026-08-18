# Ticket: 5 aggregates blend pos_receipts.total with no receipt_type filter — RESOLVED

Status: **CLOSED — pre-enable gate satisfied**

Verified on 2026-08-17 against `origin/dev` at `7d85232cc54abd6a6b2135f476205ab434e71a66`.
The current consumers normalize each return row with `-ABS(...)` (or branch on
`ReceiptType::Return`) before aggregation, so both legacy negative returns and
v4 positive returns subtract correctly in mixed-era windows:

- `PosAnalyticsService` routes receipt, payment, and line aggregates through
  `netOfReturns()` / `netOfReturnsQualified()`.
- `GrandtotalService::calculatePeriodTotals()` branches on
  `ReceiptType::Return`; `calculatePerpetualTotals()` uses a per-row CASE.
- `ReportGenerationService::buildExpectedPerMethod()` subtracts return payout
  legs and excludes returns from the change-due subtraction.

The focused mixed-era campaign remains in `docs/qa/2026-08-01-money-test-plan.md`
as regression coverage, not as an open authoring gate.

From the Lane C wave-1 consumer inventory (2026-08-01). v4 refunds project POSITIVE totals under
receipt_type='return' (spec §7.7); legacy returns were negative. Consumers relying on the sign to
net will ADD refunds instead of subtracting the moment v4 refund authoring is enabled:

AT RISK (must gain receipt_type-aware handling BEFORE EnableV4RefundAuthoringCommand runs on any
real tenant):
- PosAnalyticsService.php:37 (net_sales), :165 (getSalesByTimePeriod), :194-195
  (getCashierPerformance), :312 (getCustomerAnalytics) — blended SUM(total), no receipt_type filter
- GrandtotalService.php:174 (calculatePerpetualTotals lifetime_sales) — docblock CLAIMS
  "excluding voids/refunds" but only filters is_voided/is_training (misleading)
Same risk class, different column: ReportGenerationService.php:500 (pos_receipt_payments.amount,
no receipt_type filter, shift-close reconciliation).
SAFE (verified): SalesReportService:51, OwnerSalesSummaryService:118-119, ReceiptReturnService:578,
PosAnalyticsService refund-scoped CASE arms.

Disposition: resolved. Keep `MTP-AGG-*` in the regression campaign; remove this
ticket from any list of open pre-enable gates.
