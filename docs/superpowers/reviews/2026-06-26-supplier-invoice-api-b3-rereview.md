DO-NOT-SHIP - BLOCKER: 0, HIGH: 2

Review range: `git diff 134e7b382..63ea54b0a` (B3 impl `8072b3d4a` + fix `63ea54b0a` together).

Ground truth: `docs/superpowers/specs/2026-06-24-domestic-procurement-to-pay-gr-ir-design.md:43`-`:48`, `:91`-`:96`, `:126`-`:128`, `:136`-`:141`.

Verification run:

- `php artisan test --filter SupplierInvoiceApiTest` in `apps/api`: PASS, 24 tests, 122 assertions.
- Initial attempted command `composer test -- --filter SupplierInvoiceApiTest` did not run tests because this Composer script does not accept that passthrough form.

## BLOCKER

None.

## HIGH

1. OPEN prior HIGH1 — supplier-invoice creation still truncates invoice legs before the posting boundary instead of round-once `bcround`.

   Evidence: `apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:73`-`:82` computes each line subtotal with `CurrencyScale::bcformat(bcmul(..., $scale + 1), $scale)`, then computes VAT from that already-truncated subtotal and truncates VAT again. `CurrencyScale::bcformat()` is explicitly truncating bcmath formatting (`apps/api/app/Shared/Domain/CurrencyScale.php:151`-`:153`), while the design requires `bcround` once at the boundary for invoice legs (`docs/superpowers/specs/2026-06-24-domestic-procurement-to-pay-gr-ir-design.md:126`-`:128`). The canonical `TaxCalculationService` path also truncates the grouped line-tax accumulator via `CurrencyScale::bcformat()` at `apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:129`-`:133`, and `CreateSupplierInvoiceService` only uses that service's document-level tax total for stamp duty (`CreateSupplierInvoiceService.php:148`-`:160`), not as a round-once source of invoice VAT.

   Impact: user-entered quantities/prices can understate VAT and gross TTC by one or more storage units. Example class: a valid TND unit price whose VAT product lands at `x.xxx5` should half-up round but is truncated. This is exactly the previous precision finding and it still feeds the GR-IR posting document.

   Fix: carry high-precision subtotal/tax accumulators as numeric strings and apply `CurrencyScale::bcround($amount, $scale)` once at the invoice-leg boundary. Do not calculate VAT from a previously truncated line subtotal. Add a regression test with values that distinguish truncation from half-up rounding.

2. NEW — the HTTP post endpoint breaks the posting service's idempotency contract on retries.

   Evidence: `SupplierInvoicePostingService::post()` deliberately checks for an existing supplier-invoice journal entry before re-running the matcher (`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:76`-`:83`) because a true retry would otherwise see `quantity_invoiced` already incremented. The controller performs a pre-check before delegating (`apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:180`-`:191`). On a second POST, the invoice is already posted and the linked PO line has already been incremented at `SupplierInvoicePostingService.php:152`-`:153`; the controller's pre-check calls `assertPostable()` first, whose matchable quantity is `quantity_received - quantity_invoiced` (`apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:141`-`:148`) and whose over-clear check rejects `totalQty > matchable` (`SupplierInvoiceMatcher.php:315`-`:330`). The service no-op at `SupplierInvoicePostingService.php:76`-`:83` is never reached.

   Impact: a legitimate retry/double-click of `POST /api/v1/supplier-invoices/{id}/post` returns `422 POSTING_BLOCKED` instead of idempotently returning the posted invoice. The unique index protects against duplicate GL rows (`apps/api/database/migrations/tenant/2026_06_26_120000_unique_journal_entries_source_procurement.php:44`-`:48`), so this is not double-posting corruption, but it violates the API retry/idempotency contract in spec §6 (`docs/superpowers/specs/2026-06-24-domestic-procurement-to-pay-gr-ir-design.md:96`).

   Fix: remove the controller pre-check or short-circuit already-posted/existing-JE cases before it. Let `SupplierInvoicePostingService` own the authoritative locked/idempotent post boundary, then refresh and return the posted document.

## MEDIUM

1. NEW — lower-level PO-line and journal-entry reads are still not consistently company-scoped.

   Evidence: the controller response path is fixed, but the posting service locks PO lines only by id (`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:69`-`:74`) and checks prior supplier-invoice journal entries only by `source_type/source_id` (`SupplierInvoicePostingService.php:77`-`:80`). The matcher also starts with `DocumentLine::find($sourceLineId)` (`apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:292`-`:309`) and validates parent company after loading.

   Impact: validated API-created invoices should not hit this path with foreign PO line IDs because the request validator binds the line ids to the scoped PO (`apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:143`-`:161`), and tenant DB isolation plus UUIDs make source-id collision low probability. Still, the review criterion was "all PO-line / JournalEntry reads company-scoped"; this remains untrue in the lower-level post path and weakens defense in depth for corrupted documents or internal callers.

   Fix: add `whereHas('document', fn ($q) => $q->where('company_id', $supplierInvoice->company_id))` to PO-line lock queries and `where('company_id', $supplierInvoice->company_id)` to journal-entry idempotency reads. Prefer failing closed if any referenced PO line is missing from the company-scoped lock set.

## LOW

None.

## Prior Finding Status

1. BLOCKER1 — CLOSED.

   Controller `store()` is now validation + delegation only: it resolves company/tenant context and calls `CreateSupplierInvoiceService::create()` (`apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:126`-`:137`). The create service sets document fields consumed by posting (`apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:95`-`:116`) and line fields consumed by posting (`CreateSupplierInvoiceService.php:124`-`:141`). The posting service reads recoverable/non-recoverable tax and stamp duty from those fields (`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:156`-`:183`). The GL path posts Dr 408, Dr VAT, Dr timbre, and Cr 401 (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1252`-`:1324`), and the lifecycle test asserts non-zero `stamp_duty_amount`, non-zero `PurchaseStampDuty`, partner-tagged 401, balance, and hash-chain verification (`apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php:856`-`:923`).

   Total correctness: `total = subtotal + recoverable_vat + stamp_duty` is the correct Phase 1 gross TTC because the service makes all line VAT recoverable and sets non-recoverable VAT to zero (`CreateSupplierInvoiceService.php:135`-`:137`). INFERRED: this is not future-complete for non-recoverable VAT; if Phase 2 introduces non-recoverable VAT, `total` must become `subtotal + recoverable_vat + non_recoverable_vat + stamp_duty`, matching the posting invariant at `GeneralLedgerService.php:1216`-`:1223`.

2. BLOCKER2 — CLOSED.

   Currency equality to the source PO is enforced in request validation (`apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:134`-`:140`) and covered by `test_store_rejects_currency_mismatch_with_po()` (`apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php:636`-`:648`).

3. HIGH1 — OPEN.

   The implementation uses bcmath strings, but it still truncates with `CurrencyScale::bcformat()` per line/subtotal/tax before final posting (`apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:73`-`:82`). See HIGH finding 1.

4. HIGH2 — CLOSED.

   `post()` refreshes the document after the posting service returns (`apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:196`-`:204`), and the posting service captures and persists the authoritative pre-increment match status under lock (`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:89`-`:92`, `:185`-`:188`). Warn-mode response coverage exists at `apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php:654`-`:685`.

5. HIGH3 — CLOSED for the response contract; remaining scope hardening tracked as NEW MEDIUM.

   The response match block now uses matcher-owned, tolerance-aware `priceStatus()` (`apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:330`-`:346`; `apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:362`-`:367`) and scopes the displayed PO-line lookup to the same company (`SupplierInvoiceController.php:323`-`:326`). Detail `posted_at` is company-scoped (`SupplierInvoiceController.php:267`-`:275`). Lower-level service reads still need company predicates; see MEDIUM finding 1.

6. HIGH4 — CLOSED.

   A real lifecycle test now creates a PO, receives it through `GoodsReceiptService::receiveGoods()`, creates the supplier invoice through HTTP, posts through HTTP, asserts Dr 408 + Dr 4456 + Dr PurchaseStampDuty + Cr 401 by `SystemAccountPurpose`, asserts debits equal credits, and verifies the hash chain (`apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php:731`-`:923`). Over-invoice no-JE, warn, and block cases are covered at `SupplierInvoiceApiTest.php:930`-`:958`, `:654`-`:685`, and `:691`-`:724`.

7. MED/LOW — CLOSED.

   Detail responses include `attachments: []` (`apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:279`-`:302`); `posted_at` lookup is company-scoped (`SupplierInvoiceController.php:267`-`:275`); VAT-rate precision tests cover rejection and boundary acceptance (`apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php:964`-`:989`); index filter tests cover match status and date ranges (`SupplierInvoiceApiTest.php:995`-`:1058`).

8. NEW DEFECTS sweep — OPEN due the idempotency and lower-level scoping findings above; no mass-assignment, tenant/company create, fiscal-category/partner, tax recoverable Phase 1, or float defect observed.

   Mass-assignment fields used by the service are fillable/cast on `Document` and `DocumentLine` (`apps/api/app/Modules/Document/Domain/Document.php:118`-`:162`, `:183`-`:193`; `apps/api/app/Modules/Document/Domain/DocumentLine.php:71`-`:110`, `:117`-`:143`). Create writes tenant/company IDs from `CompanyContext`, not the request (`apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:128`-`:133`; `apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:95`-`:99`). Partner/source PO/currency/source line validation is scoped through `ScopedExists` plus cross-field checks (`apps/api/app/Modules/Procurement/Presentation/Requests/CreateSupplierInvoiceRequest.php:49`-`:59`, `:113`-`:161`). No float was observed in the supplier-invoice create/post path; arithmetic is bcmath strings, with the truncation problem called out separately.
