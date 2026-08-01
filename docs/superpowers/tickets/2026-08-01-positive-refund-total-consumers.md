# Ticket: 5 aggregates blend pos_receipts.total with no receipt_type filter — HARD PRE-ENABLE GATE for v4 refunds

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

Disposition: fix in Lane C wave 3 (or its own micro-lane) BEFORE the enable step; the
EnableV4RefundAuthoringCommand preflight sequencing in the gate sheet must reference this ticket.
Safe today only because v4 is inert.
