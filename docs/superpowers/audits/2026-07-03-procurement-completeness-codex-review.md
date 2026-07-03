# Procurement Completeness Design Review

**Spec reviewed:** `docs/superpowers/specs/2026-07-03-procurement-completeness-design.md`  
**Repo checked:** `/Users/houssamr/Projects/syneriva/apps/erp`, branch `dev`  
**Verdict:** **RETHINK**

The spec is directionally right on the three customer gaps, but it should not ship as written. Gap 2 uses PO-line state to model receipt-price reality. That is the wrong grain for daily procurement: it forces a 422 on legitimate second deliveries at a new price, accepts a WAC-vs-GL break at invoice time, and has no clean place for free quantity, landed-cost allocation, or audit/approval of a price override. Gap 1 and Gap 3 are easier to revise, but Gap 1 also has a hard schema miss: `purchase_quote_request` does not fit the current `documents.type` column.

## Findings

### Critical 1. The received-price model is at the wrong grain

The spec makes `received_unit_price` a field on `document_lines` and keeps one immutable `accrual_unit_cost` per PO line. That cannot model a normal PO line received in multiple deliveries at different prices without either blocking the warehouse or corrupting 408 clearing.

Code evidence:

- Receipt is PO-line based. `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:154-157` reads the cost from the PO line: `$unitCostStr = (string) ($line->landed_unit_cost ?? $line->unit_price);`.
- The first receipt stamps a single line-level accrual basis. `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:216-221`: `if ($line->accrual_unit_cost === null) { $line->accrual_unit_cost = $unitCostStr; }`.
- Invoice posting then clears every invoiced quantity for that PO line at that same current PO-line basis. `apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:127-154` uses `$poLine->landed_unit_cost ?? $poLine->unit_price`, asserts it equals `accrual_unit_cost`, and multiplies by the aggregated invoiced quantity.

The spec's proposed 422 (`RECEIPT_PRICE_BASIS_LOCKED`) is enforceable, but it is not acceptable for the stated bar. A parapharmacy buyer can receive the first half of a supplier order at one real delivery price and the second half at a new supplier price. Forcing "receive at the old basis or split the PO" after goods arrive is a workflow failure compared with competitor ERPs that keep receipt/voucher rows.

Recommendation: add receipt-grain state now. Minimum viable shape: `goods_receipts` header plus `goods_receipt_lines` or a smaller `purchase_receipt_lines` ledger with `po_line_id`, `received_qty`, `received_unit_price`, `landed_unit_cost`, `movement_id`, actor/approval fields, and invoiced/cleared quantity. Keep PO-line `quantity_received` as a summary counter, not the accounting grain. Supplier invoices should match and clear selected/FIFO receipt lines, not only PO lines.

### Critical 2. The spec accepts a WAC/GL divergence that will bite immediately

The walk-through says the invoice-vs-received residual is a known v1 divergence and defers WAC true-up. That is not expert-comptable-clean accounting for high-volume stocked products.

Code evidence:

- WAC updates only on receipt. `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:236-248` blends the incoming quantity and landed cost into `cost_price`; `:285-286` updates `cost_price` and `last_purchase_cost`.
- Supplier invoice clearing uses Inventory as a GL-only balancing plug. `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1225-1230` computes the plug, and `:1291-1311` posts it to Inventory debit/credit. No WAC service is called in this path.

Quantification using the spec's own numbers:

- Receive 100 units at 5.200: WAC/subledger and GL inventory both start at 520.000.
- Invoice later at 5.000: GL credits Inventory by 20.000 through the plug, but perpetual WAC remains 5.200.
- If 40 units remain on hand, the stock subledger says 40 x 5.200 = 208.000 while GL inventory after the invoice plug is 188.000. If all units sold before invoice, GL inventory can be pushed negative by the 20.000 plug while WAC on hand is zero.

Recommendation: v1 must either true up WAC at invoice posting or route the residual to an explicit purchase price variance account with a documented reconciliation rule. For parapharmacy stock valuation, the better design is a receipt-line cost adjustment that splits residual across on-hand inventory and already-sold COGS using the existing linked-cost direction, then leaves 408 at zero.

### High 3. Bonus/free quantity composition is underspecified and currently wrong at service level

The spec says paid units use `received_unit_price` and bonus units move at zero. In the current receipt service there is exactly one `recordPurchase()` call per PO line, with no bonus split.

Code evidence:

- Current receipt loop calls `recordPurchase()` once for a received line. `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:167-176`.
- `recordPurchase()` sets `last_purchase_cost` to the movement unit cost. `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:285-286`.
- The spec itself correctly notes `free_quantity`, `price_entry_mode`, and `is_bonus_line` are not in the merged tree.

If implementation emits paid movement first and free movement second, `last_purchase_cost` becomes `0.000000` after the bonus movement. If it emits one blended movement, the audit trail loses the supplier's paid price. Either result undermines the spec's claim that received price becomes the authoritative cost basis.

Recommendation: define bonus receipt accounting explicitly. Store paid quantity, free quantity, paid received price, effective unit cost, and movement links. Decide whether `last_purchase_cost` means last paid supplier price or effective blended acquisition cost, then test that behavior.

### High 4. Landed-cost allocation by PO `line_total` conflicts with received-price truth

The spec keeps landed-cost allocation weights based on the PO line total while changing the line's own value base to the received price. That produces incoherent allocations when the real received price materially differs from the PO price or when partial receipts land in different mixes.

Code evidence:

- Current landed cost computes per-line cost from `lineTotal + allocatedCost + nonRecoverableTax` over full line quantity. `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:395-420`.
- Allocation share uses `lineTotal / subtotal`. `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:365-383`.
- Cost modification is blocked only after full receipt payload `goods_received_at` is set. `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:519-525`.

Recommendation: receipt-grain landed cost must allocate over actual received value or an explicit allocation basis selected for that receipt batch. Do not mix PO-value allocation with receipt-price valuation without a documented reconciliation and tests for partial receipts, zero/free lines, and freight/insurance documents.

### High 5. Switching the matcher basis can reclassify existing draft invoices at post time

Changing `SupplierInvoiceMatcher` from PO price to received price is not just advisory UI behavior. Posting re-runs the matcher against current state.

Code evidence:

- Current matcher compares invoice unit price to PO unit price. `apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:375-381`: `$invoiceUnitPrice = $invoiceLine->unit_price;` and `$poUnitPrice = $poLine->unit_price;`.
- Posting rechecks matcher invariants and captures match status inside the posting transaction. `apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:87-93`.
- Under `Block`, a price variance throws. `apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:220-228`.

An invoice created and matched under the old PO-price basis can become a price variance after a receipt price is entered, then fail posting under block enforcement. Existing draft invoices need a transition rule.

Recommendation: snapshot `price_match_basis` and `matched_unit_price_basis` on supplier invoice lines at creation, or grandfather draft invoices whose match was computed before the basis switch. At minimum, add a data migration/re-match command and release note for tenants using `match_enforcement=block`.

### High 6. Gap 1's "no DB migration" claim is false

`PurchaseQuoteRequest = 'purchase_quote_request'` is 22 characters. The current `documents.type` column is `string(20)`.

Code evidence:

- `documents.type` is `string('type', 20)`. `apps/api/database/migrations/tenant/2025_11_30_080000_create_documents_table.php:18`.
- Current enum values fit the column; there is no RFQ case. `apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:7-18`.

Recommendation: either choose a shorter stored value such as `purchase_rfq` or add a migration widening `documents.type` before adding the enum. Also update any generated TS types and constraints/tests around document type length.

### Medium 7. A first-class RFQ document can work, but the opt-out surface is not enumerated

Using `documents` gives numbering, lines, attachments, PDFs, and conversion. It also drags in mandatory and adjacent document machinery that an RFQ must explicitly opt out of.

Code evidence:

- `documents` requires `partner_id`, `status`, `document_number`, `document_date`, `currency`, totals, balance, and payload fields. `apps/api/database/migrations/tenant/2025_11_30_080000_create_documents_table.php:16-34`.
- Fiscal columns exist for every document. `apps/api/database/migrations/tenant/2025_12_11_054216_add_fiscal_fields_to_documents_table.php:16-23`.
- `FiscalCategory::fromDocumentType()` defaults unknown/non-fiscal types to `NonFiscal`. `apps/api/app/Modules/Document/Domain/Enums/FiscalCategory.php:38-46`.
- `DocumentStatus` has only `Draft`, `Confirmed`, `Posted`, `Paid`, `Received`, `Cancelled`. `apps/api/app/Modules/Document/Domain/Enums/DocumentStatus.php:7-15`.

The spec says lifecycle `Draft -> Sent -> Responded -> Converted/Closed`, then suggests payload flags instead of statuses. That is not enough. The spec must state how RFQ avoids payment status, balance due, fiscal sealing, stock/GL hooks, generic "confirmed" semantics, document totals, and generic document list filters. It should also decide whether a supplier is mandatory in v1; competitor RFQ flows often start with an item list and invite multiple suppliers.

Recommendation: keep first-class document only if the spec adds an RFQ invariants section: fiscal category always `NON_FISCAL`, no payment actions, no stock/GL listeners, no receivable/payable status, allowed operational statuses, PDF/email template, conversion preconditions, and how `Converted`/`Closed` are represented.

### Medium 8. The from-receipts invoice entry point contradicts the backend's one-PO contract

The spec says "select received PO(s)" from the receipts page, but the existing create endpoint requires one `source_document_id`, and every invoice line must belong to that PO.

Code evidence:

- `source_document_id` is required. `apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:55-59`.
- The referenced source must be a purchase order. `apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:113-123`.
- Every `source_line_id` must belong to that one PO. `apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:143-162`.
- The receipts page is a view over purchase orders, not receipt headers. `apps/web/src/features/purchases/GoodsReceiptListPage.tsx:99-123`.

Recommendation: v1 from-receipts UX must be one of: select exactly one PO; group selected rows by PO and create one invoice per PO; or block multi-PO selection before navigation with a clear message. Do not let the user compose a multi-PO draft that can only fail at submit.

### Medium 9. The supplier reference recommendation is not grounded in current storage

The spec says v1 stores supplier reference in `documents.payload->supplier_reference`. The backend already accepts `supplier_reference` and writes it to `external_document_number`.

Code evidence:

- Request field exists. `apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:63`.
- Create service persists it as `external_document_number`. `apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:123`.
- The column is indexed by company. `apps/api/database/migrations/tenant/2025_12_11_100001_add_is_historical_to_tables.php:32-36`.

Recommendation: update the spec to use `external_document_number` for supplier invoice numbers and decide whether v1 adds uniqueness/duplicate warnings on `(company_id, partner_id, external_document_number)` or defers that explicitly.

### Medium 10. Receipt price edit needs explicit authorization and audit, not only a permission

The spec adds `goods-receipt.edit-price`, but it rejects explicit audit columns and says stock movement plus standard audit log are enough. That is too weak for a cost-changing warehouse action.

Code evidence:

- Receipt service currently has no actor parameter and stores movement reference as the PO document only. `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:167-176`.
- Stock movement records unit cost, total cost, and reference, but not the human who overrode a supplier delivery price in the receipt call path shown. `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:258-278`.

Recommendation: receipt price override should record actor, timestamp, old basis, new basis, reason, source document reference, and optional supervisor approval. This should live on receipt-line state, not only in generic logs.

### Low 11. Code-grounding corrections

The spec is mostly grounded, but several citations or statements need correction:

- `SupplierInvoiceMatcher.php:376` is the right line, but the actual path is `apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php`, not a Document domain service path.
- `GoodsReceiptService` is at `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php`; the line claims around `receiveGoods`, cost basis, event emission, and `accrual_unit_cost` are correct there.
- There is no backend `ReceiveGoodsRequest` FormRequest. There is a frontend interface named `ReceiveGoodsRequest` in `apps/web/src/features/purchases/components/ReceiveGoodsDialog.tsx:14-17`, so the spec should say "no Laravel FormRequest."
- The claim that sales `Quote` is proved by `affectsReceivable()` is weak/wrong as written: `DocumentType::Quote` exists at `apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:9`, but `affectsReceivable()` only returns true for `Invoice` and `CreditNote` at `:61-67`. Quote is sales-side by controllers/routes/converters, not by receivable impact.
- `useCreateSupplierInvoice()` is truly frontend definition-only. `apps/web/src/features/purchases/supplier-invoices/api.ts:90-95` defines it, and `rg` found no frontend caller outside that definition.

## Competitor-Gap Table

| Capability | Spec coverage | Review |
|---|---:|---|
| Single-supplier RFQ -> PO with price carry-over | Partial | Good v1 workflow, but enum value exceeds DB column and RFQ opt-outs/statuses need precision. |
| Multi-supplier RFQ comparison/award | Deferred | Risky for a prospect already using a competitor ERP. If "demande de prix" means supplier comparison, v1 is below bar. |
| Per-receipt actual price and cost accrual | Insufficient | Must be receipt-line grain. PO-line `received_unit_price` plus first-receipt lock is not competitor-grade. |
| Multi-price partial receipts | Blocked | Unacceptable as v1 default for daily warehouse flow. Requires receipt rows or explicit split-before-receipt workflow. |
| Bonus/free quantity with effective WAC | Underspecified | Needs exact movement and `last_purchase_cost` semantics. |
| Landed cost on received value | Deferred/contradictory | Allocation by PO value while cost basis is received value will misstate costs. |
| GR price edit authorization/audit | Partial | Permission exists in spec; audit evidence is not strong enough for cost-changing actions. |
| Invoice matching tolerances | Existing backend | `procurement_policies` has percent/max amount and warn/block enforcement, but the spec needs basis migration and UI policy visibility. |
| Supplier invoice creation UI | Good direction | Backend is ready; UI must handle one-PO constraint explicitly. |
| Multi-PO supplier invoices | Deferred | Acceptable only if UX blocks/groups selections; many supplier invoices cover multiple POs in real life. |
| Supplier credit notes / price adjustments | Existing backend, not integrated | `SupplierCreditNoteReason` supports `PriceAdjustment` and `GoodsReturn`, but the spec does not connect credit notes to received-price WAC/GL true-up. |
| Supplier invoice number duplicate detection | Partial | Existing `external_document_number` storage should be used; duplicate guard is still a real AP control gap. |

## Concrete Spec-Change Recommendations

1. Replace Gap 2's PO-line `received_unit_price` design with a receipt-line ledger/header. Store each receipt event's quantity, delivered price, landed cost, movement id, accrual amount, actor, approval, and remaining uninvoiced quantity.
2. Make supplier invoice matching consume receipt-line cost bases. Keep PO-line matching as an aggregate fallback only for old data.
3. Remove the v1 422 multi-price block as the primary design. If a temporary block remains, scope it to a clearly documented compatibility mode, not competitor-grade v1.
4. Require WAC true-up or explicit purchase price variance accounting at invoice posting. Do not accept silent WAC/GL divergence for stocked parapharmacy products.
5. Define bonus/free receipt semantics with tests for WAC, stock movement audit, `last_purchase_cost`, invoice matching, and supplier credit note reversal.
6. Rework landed-cost allocation around actual receipt value/quantity, with test cases for partial receipt, free lines, and price changes after PO confirmation.
7. Add a transition plan for the matcher basis switch: snapshot basis on invoice lines, grandfather old drafts, or ship a rematch/migration command.
8. Fix Gap 1 storage: shorten the enum value or widen `documents.type`; then enumerate RFQ opt-outs from fiscal, payment, stock, GL, generic status, and document totals behavior.
9. Decide whether multi-supplier RFQ comparison is v1. If the customer expects competitor parity on "demande de prix," single-supplier RFQ is likely not enough.
10. Change Gap 3 from-receipts UX to enforce one PO before create, or group selections into one invoice draft per PO. The failure case should be client-side and explicit.
11. Update supplier invoice reference storage to `external_document_number`, not payload, and add at least a duplicate warning for same supplier/reference.
12. Add explicit receipt price override audit fields and tests; permission alone is not enough.

## Revised Minimum Shape

The revised implementation can still be phased, but the phase boundary should move:

- Gap 1 can ship after the enum storage fix and RFQ opt-out/status rules.
- Gap 3 can ship after the one-PO UX constraint and supplier reference correction.
- Gap 2 should not ship until receipt-line grain exists or the owner consciously accepts a below-competitor temporary mode. For the stated mandate, receipt-line grain is the right v1 foundation, not a Phase 2 nice-to-have.
