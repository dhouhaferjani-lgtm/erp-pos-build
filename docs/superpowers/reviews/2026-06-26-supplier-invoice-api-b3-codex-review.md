DO-NOT-SHIP — BLOCKER: 2, HIGH: 4

## BLOCKER

1. `apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:153` hand-rolls supplier-invoice monetary/tax totals in the HTTP controller, and `:187`-`:190` persists those values as the GL source of truth without `stamp_duty_amount`.

   Impact: this violates the API contract that this layer is validation + delegation only, but more importantly it underfeeds the posting service. The section-3 GL requires Dr 408 + Dr 4456 + Dr PurchaseStampDuty / Cr 401. This controller sets `line_tax_amount`, `tax_amount`, and `total` to VAT-only and never sets `stamp_duty_amount`, so posted AP excludes timbre and the stamp-duty leg is zero. INFERRED from the existing posting path: `SupplierInvoicePostingService` later reads `subtotal`, per-line recoverable/non-recoverable VAT, and `stamp_duty_amount` to call the GR-IR clearing service. Recommended fix: move supplier-invoice creation into an application/domain service or delegate to the canonical document/tax calculator, set `stamp_duty_amount`, and assert the resulting JE has the exact section-3 legs including timbre.

2. `apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:60` accepts any 3-letter `currency`, and the cross-field validator at `:113`-`:132` checks PO type and partner but never checks invoice currency against the referenced PO currency.

   Impact: a caller can invoice a TND domestic PO as `USD`/`EUR`; the matcher compares unit prices as plain decimal strings and the poster clears received PO cost using the invoice currency/scale. INFERRED GL impact: user-controlled currency can push wrong-currency amounts into the supplier-invoice posting path. Recommended fix: for Phase 1 domestic receipt-first procurement, require `currency === $po->currency` (and probably company currency) before creation; leave FX/import behavior to the future import mode.

## HIGH

1. `apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:165`-`:169` and `:205`-`:226` compute subtotals and VAT with `bcmul(..., $scale)` at each step, not the canonical calculator/rounding path.

   Impact: qty x unit price and VAT are truncated at the currency scale before totals are accumulated; the spec requires bcmath strings and round-once `CurrencyScale::bcround` behavior for invoice legs. This can silently lose sub-millime value and feed different amounts to GL than the canonical calculator would. Recommended fix: create lines from validated strings, then delegate total/tax calculation to the canonical service or a supplier-invoice application service that uses the same rounding contract as posting.

2. `apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:301` captures `$hasPriceVariance` from the stored `match_status` before the authoritative post path runs, and `:315`-`:318` emits the warn-mode warning only from that stale value.

   Impact: warn-mode price variance is required to post successfully with a warning. If the stored match status is stale relative to the matcher/posting recheck, the service can post a price-variance invoice while the HTTP response omits the warning. Recommended fix: have `SupplierInvoicePostingService::post()` return the authoritative match status/warnings, or refresh after posting and base the warning on the persisted post-time status.

3. `apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:433`-`:456` builds the response match block by fetching `DocumentLine` rows directly and recomputing `price_variance` as any non-zero unit-price difference.

   Impact: the response disagrees with matcher policy. A variance inside configured tolerance can have `match.status = matched` while `per_line[].price_variance = true`, which is a contract-breaking shape for the web. The query is also not company-scoped, so corrupted invoice lines could surface another company's PO-line quantities in show/match responses. Recommended fix: expose per-line match details from the matcher, including tolerance-aware price status, and scope any PO-line reads through the validated source PO/company.

4. `apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php:228`-`:272` only proves that some supplier-invoice JE exists after post; it does not assert debits equal credits, resolved accounts/purposes, partner-tagged 401, VAT, timbre, or the exact section-3 GL legs. The setup also bypasses the real PO->receive lifecycle: `:43`-`:45` says fixtures are inserted directly, and `:235`-`:242` calls `GeneralLedgerService::createGoodsReceiptGrIrEntry()` directly instead of receiving through the procurement flow.

   Impact: the tests would pass while the API omits timbre, truncates totals, or posts the wrong leg mix. They do cover `RolesAndPermissionsSeeder` and a write-permission 403, but they do not prove the required PO->receive->create->match->post balanced supplier-invoice JE contract. Recommended fix: add a true feature path with real PO confirmation/receipt, create/match/post through HTTP, assert the journal entry lines by `SystemAccountPurpose`, assert total debits == credits, assert over-invoice 422 leaves no JE, and add warn/block price-variance cases.

## MEDIUM

1. `apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:391`-`:413` omits `attachments` from the detail response even though the contract says detail includes `attachments[]`.

   Impact: the web can be built against an attachment slot that never appears in the payload. Recommended fix: include `attachments: []` until the unified media query is wired, or return the actual media attachments for `MediaOwnerType::SupplierInvoice`.

2. `apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:384`-`:387` queries `JournalEntry` for `posted_at` without tenant/company scoping.

   Impact: source IDs are UUIDs, so practical collision risk is low, but this violates the review vector that every query in this API should be company/tenant scoped. Recommended fix: add `where('company_id', $doc->company_id)` and, where available, tenant scoping.

3. `apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:79`-`:85` validates percent precision, but `apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php:529`-`:555` only exercise quantity and unit-price precision.

   Impact: the requested precision-ceiling test coverage is incomplete for VAT rate, one of the fields that feeds recoverable VAT. Recommended fix: add a rejection test for `vat_rate = 19.001` and a valid boundary test for `19.00`.

## LOW

1. `apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php:323`-`:382` cover partner/status index filters, but not `match_status`, `date_from`, or `date_to`.

   Impact: low confidence that the list endpoint matches the contract filters the web will depend on. Recommended fix: add filter assertions for match status and date range.

