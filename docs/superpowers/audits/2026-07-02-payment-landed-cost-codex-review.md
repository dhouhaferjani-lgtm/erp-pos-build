I completed the adversarial review and attempted to write:

`docs/superpowers/audits/2026-07-02-payment-landed-cost-codex-review.md`

The write was rejected because this session’s filesystem is read-only, so the file was not created.

Verdict: **REVISE**.

Main blockers found:

1. `canModifyCosts()` only checks `payload['goods_received_at']`, but `GoodsReceiptService` sets that only when the PO is fully received. A partially received PO can still be treated as pre-receipt, risking post-receipt landed-cost reallocation and the 408 GR-IR divergence guard.

2. Post-receipt WAC adjustment capitalizes the full late fee into current company-owned quantity. If 60% of the receipt was already sold, the spec claims the sold portion hits P&L, but the actual proposed `recordCostAdjustment()` path would push the whole fee into remaining inventory.

3. The Expense spine is not enough for Tunisian supplier fee reality: no partner/AP invoice, no installments, no fee TVA split, no retenue à la source, and no clean 401/payable lifecycle.

4. Phase 1 defers reversal, but WAC + GL + cash side effects cannot be corrected manually without divergence.

5. Supplier invoice multi-PO resolution is overstated: the matcher can validate arbitrary PO lines, but `CreateSupplierInvoiceRequest` currently requires all source lines to belong to one `source_document_id` PO.

6. `WeightedAverageCostService::recordCostAdjustment()` still takes `float $additionalCost`; this must be a hard prerequisite before any money passes through it.

I also verified industry baselines from Odoo and Microsoft Business Central docs/source:
- Odoo landed cost split methods include equal, quantity, current cost, weight, and volume: https://raw.githubusercontent.com/odoo/odoo/19.0/addons/stock_landed_costs/models/stock_landed_cost.py
- Business Central supports item charges on purchase/sales docs and separate invoices linked to posted receipt/shipment lines: https://learn.microsoft.com/en-us/dynamics365/business-central/payables-how-assign-item-charges

SAP B1 and NetSuite public primary docs did not open through the web tool in this run, so I treated those comparisons as industry-practice inference rather than cited verification.

Codex session ID: 019f2283-b2ae-73b1-8a63-e53a28f1cfc2
Resume in Codex: codex resume 019f2283-b2ae-73b1-8a63-e53a28f1cfc2
