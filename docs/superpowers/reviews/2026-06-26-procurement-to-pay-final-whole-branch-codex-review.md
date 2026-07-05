VERDICT: DO-NOT-SHIP for go-live.

# Final Whole-Branch Procurement-to-Pay GR-IR Audit

Scope: holistic read of the named files, traced against `docs/superpowers/specs/2026-06-24-domestic-procurement-to-pay-gr-ir-design.md` sections 3, 6, and 7. I did not diff the whole branch.

## BLOCKER

### GO-LIVE BLOCKER — Supplier payments can be posted against Draft supplier invoices with no real 401 payable

Evidence: the supplier-payment branch classifies any `DocumentType::SupplierInvoice` as AP at `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:217`, caps against `$document->balance_due ?? $document->total` at `PaymentController.php:214`-`225`, and then posts `Dr SupplierPayable / Cr Bank` at `PaymentController.php:518`-`531`. There is no guard that the supplier invoice is `Posted`, no check that a `supplier_invoice` journal entry exists, and no check that the 401 credit being paid was actually created.

Impact: a user can pay a Draft supplier invoice created by `CreateSupplierInvoiceService` before `SupplierInvoicePostingService::post()` has posted the GR-IR clearing entry. That creates cash out and a `Dr 401` payment leg against no prior `Cr 401`, violating spec §3's "payment against a real payable" requirement and potentially driving the supplier payable subledger negative.

Fix: in the locked document section, require `type=SupplierInvoice`, `status=Posted`, and an existing posted `journal_entries(source_type='supplier_invoice', source_id=document.id, company_id=document.company_id)` before allowing supplier payment. Initialize `balance_due` to `total` when the supplier invoice posts, or compute the outstanding payable from the posted 401 subledger instead of `balance_due ?? total`.

### GO-LIVE BLOCKER — Supplier credit notes do not reduce the linked supplier invoice outstanding balance, so later payments can overpay AP

Evidence: `SupplierCreditNotePostingService` locks the linked supplier invoice at `apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:126`-`127`, posts the reversing entry at `SupplierCreditNotePostingService.php:183`-`189`, and then only marks the credit note itself `Posted` at `SupplierCreditNotePostingService.php:191`-`193`. The payment branch later uses the supplier invoice document's stale `balance_due ?? total` as the payment ceiling at `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:214`-`225`.

Impact: invoice 119.600, credit note 11.900, then payment still allows 119.600 because the invoice balance was never reduced by the credit. GL will contain `Cr 401` from invoice, `Dr 401` from credit note, and another full `Dr 401` from payment, over-clearing AP. This breaks the invoice -> credit-note -> payment cycle in spec §7.

Fix: while holding the linked invoice lock, decrement the supplier invoice's outstanding balance by the posted supplier-credit-note gross, keep status coherent, and reject credits/payments that would make it negative. Prefer deriving the payable ceiling from posted 401 lines if document balance is not the AP source of truth.

### GO-LIVE BLOCKER — 408 clearing still depends on mutable PO-line landed cost, not the immutable receipt accrual amount

Evidence: the receipt GL method accrues 408 from the receipt event's `unitCost` string at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1051`-`1075`. The invoice posting service later recomputes the 408 clearing basis from the current PO line with `$poLine->landed_unit_cost ?? $poLine->unit_price` at `apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:127`-`135`. Goods receipt captures `landed_unit_cost ?? unit_price` before the WAC float boundary at `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:154`-`158`, while landed costs can be reallocated onto the same PO line later at `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:247`-`287`.

Impact: if a PO line's landed cost changes between partial receipts, or after a receipt but before invoice posting, the invoice clears 408 at the current PO-line cost rather than the amount originally credited to 408. Example: receive 5 at 100.000, later reallocate landed cost to 110.000 and receive 5 more. Receipt accruals total 1,050.000, but invoicing 10 clears 1,100.000, leaving a 50.000 408 residue/over-clear. This fails the user's explicit no-residue requirement.

Fix: store immutable receipt accrual allocations per PO line movement (quantity, unit cost, rounded amount, remaining-to-clear amount) and clear 408 against those records under lock. If Phase 1 will not add receipt allocation records, block landed-cost mutations after any receipt, or post explicit 408 adjustment entries when landed cost is reallocated.

## HIGH

### GO-LIVE HIGH — Goods-return supplier credit notes credit Inventory in GL but do not remove stock quantity

Evidence: for `GoodsReturn`, the credit-note service decrements only `quantity_invoiced` at `apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:161`-`164` and `SupplierCreditNotePostingService.php:380`-`410`. The GL reverses inventory value via the plug at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1468`-`1477`. INFERRED: no inventory movement/removal call is present in `SupplierCreditNotePostingService`.

Impact: a returned-goods credit note reduces the Inventory GL account but leaves physical stock/on-hand quantity unchanged. That breaks inventory subledger to GL reconciliation and only works for price-only credits, not the returned-goods scenario in spec §7.

Fix: either block `SupplierCreditNoteReason::GoodsReturn` until it is tied to a stock return/removal workflow, or create the inventory movement in the same posting transaction/order and use the resulting inventory valuation as the credit-note inventory leg.

## MEDIUM

### POST-GO-LIVE MEDIUM — Credit-note concurrency coverage is not a real two-connection PostgreSQL contention test

Evidence: the current regression simulates a stale Draft model by manually updating the DB row to `posted` in one connection at `apps/api/tests/Feature/Accounting/SupplierCreditNoteGlTest.php:1014`-`1081`. The unique-index test directly inserts a duplicate `supplier_credit_note` journal entry at `SupplierCreditNoteGlTest.php:1083`-`1133`. These are useful, but they are not two independent DB connections blocking on `FOR UPDATE`.

Impact: the observed lock order itself does not show a deadlock cycle: invoice posting locks PO lines (`SupplierInvoicePostingService.php:69`-`75`), credit-note posting locks credit note -> linked invoice -> PO lines (`SupplierCreditNotePostingService.php:77`-`80`, `:126`-`:142`), and supplier payment locks invoice document -> repository (`PaymentController.php:390`-`394`, `:469`-`:475`). Still, a real two-connection PG test is the only proof that duplicate credit-note post attempts serialize as intended.

Fix: add a PostgreSQL-only test with two connections/processes that holds the first credit-note post inside the transaction, attempts the second post concurrently, then asserts one posted JE, one `quantity_invoiced` decrement, and no deadlock.

### POST-GO-LIVE MEDIUM — Supplier invoice internal service boundaries trust caller scoping more than the HTTP boundary

Evidence: HTTP creation scopes `partner_id` and `source_document_id` with `ScopedExists::tenantAndCompany` at `apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:49`-`59`, and validates PO partner/currency/line membership at `CreateSupplierInvoiceRequest.php:125`-`161`. The matcher also rejects PO lines whose parent company differs at `apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:302`-`310`. But `CreateSupplierInvoiceService::create()` accepts validated arrays and writes `partner_id`, `source_document_id`, and `source_line_id` directly at `apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:105`-`151`.

Impact: the public HTTP path is scoped, but an internal caller or corrupted draft can still pair a supplier invoice partner with a different supplier's PO line until the HTTP request layer catches it. The posting path will then partner-tag 401 using the invoice partner at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1315`-`1324`.

Fix: move the PO partner/currency/source-line membership guard into the application service or posting service, leaving the FormRequest as presentation-layer early rejection.

## LOW

### POST-GO-LIVE LOW — Missing HTTP regression for same-tenant foreign-company supplier-invoice line is a real coverage gap, but not an observed runtime bypass

Evidence: the HTTP test only covers a source line from another PO in the same company at `apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php:569`-`609`. The matcher has a lower-level same-tenant/different-company test at `apps/api/tests/Feature/Procurement/SupplierInvoiceMatcherTest.php:540`-`596`. The request rules scope `source_document_id` by tenant and company at `apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:55`-`59` before the after-hook checks line membership.

Impact: I do not see an HTTP bypass because the source PO itself must be company-scoped, and the submitted line must belong to that PO. But the exact same-tenant/foreign-company HTTP case is not pinned and should be added to prevent regressions around the unscoped `Document::find()` and `DocumentLine::where('document_id')` in the after-hook at `CreateSupplierInvoiceRequest.php:113`-`161`.

Fix: add an HTTP test with two companies in the same tenant: company A request context, company A PO as `source_document_id`, and company B PO line as `lines.0.source_line_id`; assert 422 on `lines.0.source_line_id`, plus a same-company positive control.

### POST-GO-LIVE LOW — Procurement partial unique index protects invoice and credit-note posting, with a theoretical same-tenant UUID false-collision edge

Evidence: the migration creates `UNIQUE (source_type, source_id)` only where `source_type IN ('supplier_invoice', 'supplier_credit_note')` at `apps/api/database/migrations/tenant/2026_06_26_120000_unique_journal_entries_source_procurement.php:44`-`48`. It includes `source_type`, so a supplier invoice and supplier credit note with the same UUID do not collide with each other.

Impact: this is the right structural guard for duplicate invoice/credit-note posting. The only theoretical false collision is two same-tenant companies with the same UUID for the same source type because `company_id` is not part of the index; with generated UUIDs this is negligible, but imported deterministic IDs could make it real.

Fix: either keep as-is and document the UUID assumption, or include `company_id` in the partial unique index for semantic company scoping.

## Trace Notes

- Receipt, invoice posting, payment, and credit-note GL legs balance locally: receipt uses the same rounded amount for Dr Inventory and Cr 408 (`GeneralLedgerService.php:1071`-`1129`); invoice clearing defensively asserts debits equal credits before posting (`GeneralLedgerService.php:1328`-`1343`); payment uses equal amount legs (`GeneralLedgerService.php:578`-`603`); credit note asserts debits equal credits (`GeneralLedgerService.php:1492`-`1509`).
- Hash-chain posting is intact for these GL factories because `postEntryWithOptionalActor()` assigns `chain_sequence`, `fiscal_hash`, `previous_hash`, and posted metadata at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1777`-`1790`.
- Precision in the named procurement/accounting/payment paths is string/bcmath-based with `CurrencyScale::bcround()` at the GL boundaries (`GeneralLedgerService.php:1075`, `:1202`-`:1214`, `:1400`-`:1408`) and no `bcformatStrict`/float usage observed in the named posting paths. The adjacent landed-cost allocation system still has float/truncation surfaces, but the go-live blocker above is about mutability of the receipt basis rather than float drift.
