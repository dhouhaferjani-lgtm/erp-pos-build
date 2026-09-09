# Benchmark note — three owner decisions (2026-09-09): accountant PO revert, freight on lost units, receiving accountability

Convention 10, requested by the owner: "research standards and what makes sense from a management and accounting perspective."

## 1. May the accountant role revert (unlock) a confirmed purchase order by default?

| System / norm | Who may undo a confirmed PO | Source |
|---|---|---|
| Odoo (Lock Confirmed Orders) | Only the **Purchase Administrator** can Unlock; Purchase Users cannot edit confirmed orders | https://www.cybrosys.com/blog/how-to-lock-purchase-orders-after-confirmation-in-odoo-19 · https://www.odoo.com/documentation/13.0/applications/inventory_and_mrp/purchase/purchases/rfq/lock_orders.html |
| ERPNext | Cancel/Amend on Purchase Order is a **Purchase Manager** right; Accounts users record invoices and cannot approve/submit purchasing documents by default | https://cloud.casesolved.co.uk/erpnext/en/role-based-permissions · https://docs.erpnext.com/docs/v12/user/manual/en/customize-erpnext/custom-scripts/restrict-cancel-rights |
| Segregation of duties (purchase-to-pay) | The person who records or pays should not be able to alter the commitment; initiating/approving purchases, recording invoices and paying are separate hands | https://zapliance.com/en/blog/segregation-of-duties-in-purchase-to-pay/ · https://www.securends.com/blog/segregation-of-duties-in-accounts-payable/ |
| AutoERP today (PR #210) | Revert gated on `purchase-orders.confirm`: admin, manager, purchasing hold it; operator and accountant do not; grantable per tenant | `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (per gate r3 report) |

**Recommendation: KEEP** (accountant denied by default, grantable). Reverting a confirmation is a purchasing decision; letting the role that records and pays supplier invoices also undo commitments removes a control. A tenant that runs purchasing and accounting in one person grants it explicitly.

## 2. Freight attributable to units written off or returned on a transfer

| Norm / system | Treatment | Source |
|---|---|---|
| IAS 2 ¶10–16 | Cost of inventories = purchase cost + conversion + **other costs to bring inventories to their present location and condition** (transport to the point of sale qualifies); **abnormal** amounts of wasted materials/freight are expensed in the period; on loss or damage the inventory is written down/off and its carrying amount, freight included, becomes an expense | https://www.ifrs.org/issued-standards/list-of-standards/ias-2-inventories/ · https://ifrscommunity.com/knowledge-base/cost-of-inventories/ · https://legalclarity.org/is-freight-in-included-in-inventory-accounting-rules/ |
| Odoo | Landed costs are spread over the received quantity; internal transfers create no journal entries; scrapped/lost units leave inventory at their carrying amount into the loss account | https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/inventory_valuation/cheat_sheet.html |
| ERPNext | Landed Cost Voucher allocates freight to the received items; differences go to the Stock Adjustment expense account | https://docs.frappe.io/erpnext/user/manual/en/stock-entry |
| AutoERP today | Transfer freight is capitalized into destination WAC at completion only; **there is no GL leg for transfer freight** (the expense is booked wherever the freight was recorded, e.g. the expense module) | `WeightedAverageCostService.php` capitalization (gate r2/r3 citations) |

**Recommendation: the rev-3/rev-4 default.** Capitalize only the freight share of the units that actually arrived good (pool = freight × good received ÷ sent); the residual for lost/damaged/returned units is NOT capitalized and is recorded on the transfer as `freight_uncapitalized` with no additional journal. Accounting reading: because transfer freight never left the expense where it was booked, "not capitalizing" IS the IAS 2 expense outcome for the lost units; adding a shrinkage journal for it (option a) would count the same cost twice. Revisit only if transfer freight ever gets its own capitalization journal.

## 3. Who is accountable for a shortage when several receivers touch one transfer?

| Practice | What is measured / who signs | Source |
|---|---|---|
| Blind receiving (WMS practice) | Receiver counts without the expected figure; receiving accuracy is measured **per receiver on what they recorded** (items received and recorded correctly ÷ items received); discrepancies are logged and root-caused, and charged back upstream (carrier/supplier) rather than absorbed | https://racklify.com/encyclopedia/blind-receipt-eliminating-bias-in-supply-chain-operations/ · https://g10fulfillment.com/blog/receiving-accuracy-metrics · https://www.buske.com/what-is/blind-receiving |
| Discrepancy reports | Signed by the receiver **and** a supervisor; the supervisor resolves, the receiver only attests what they counted | https://goaudits.com/blog/warehouse-receiving/ · https://www.argosoftware.com/blog/avoid-stock-discrepancies/ |
| ERP transit models (Odoo transit location, ERPNext transit warehouse) | Loss between shipment and receipt is a **transit** fact on the transfer, not on the last person who opened the box | (see §0 of the parent brief) |

**Recommendation: the rev-3/rev-4 default.** Each receiver's stored line events carry only what they posted (received, damaged, blind flag). A shortage discovered at close is a transfer-level fact whose event actor is the supervisor who closed it; it is never pinned on the last receiver. The pattern query measures, per company and per receiver, their damage reports and the supervisor-confirmed variance on lines **they** posted, against the company baseline. This is how blind-receiving accuracy is measured in practice and it avoids blaming a receiver for a carrier loss.
