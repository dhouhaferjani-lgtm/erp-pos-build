VERDICT: SHIP - BLOCKER: 0, HIGH: 0

Review range: `git diff 134e7b382..26b98616b` at current HEAD `26b98616b`.

Ground truth checked: `docs/superpowers/specs/2026-06-24-domestic-procurement-to-pay-gr-ir-design.md:43`-`:46` (§3 supplier-invoice GL legs), `:91`-`:96` (§6 cumulative invoiced quantity, locked/idempotent post retry), and `:126`-`:128` (bcmath strings, no float, round-once `bcround` for invoice legs).

Verification run:

- `php artisan test --filter SupplierInvoiceApiTest` in `apps/api`: PASS, 26 tests, 138 assertions.
- `./vendor/bin/phpstan analyse app/Modules/Procurement --level=8 --no-progress` in `apps/api`: PASS, `[OK] No errors`.
- `rg -n "bcformat\(|bcformatStrict\(|number_format\(|\(float\)|floatval|doubleval" apps/api/app/Modules/Procurement apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php`: no matches.

## BLOCKER

None.

## HIGH

None.

## MEDIUM

None.

## LOW

None.

## Prior Finding Status

HIGH1 (precision) - CLOSED.

`CreateSupplierInvoiceService` now carries invoice-line subtotal at high precision with `$working = $scale + 4` and `bcmul($qty, $unitPrice, $working)` before any currency-boundary amount is persisted (`apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:78`-`:82`). VAT is computed from that unrounded high-precision subtotal, not from the stored line subtotal (`apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:83`-`:89`). Both the HT line leg and the VAT line leg use `CurrencyScale::bcround()` (`CreateSupplierInvoiceService.php:82`, `:89`), and the service totals those rounded invoice legs with bcmath strings (`CreateSupplierInvoiceService.php:91`-`:92`, `:170`-`:175`).

No remaining `bcformat`, `bcformatStrict`, `number_format`, float casts, `floatval`, or `doubleval` were found in `apps/api/app/Modules/Procurement` or this supplier-invoice feature test. The broader `TaxCalculationService` still has a truncating line-tax path (`apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:129`-`:133`), but the supplier-invoice create service does not use that result for the supplier-invoice VAT leg; it uses only `documentTaxTotal` for document-level stamp duty (`CreateSupplierInvoiceService.php:158`-`:162`; `TaxCalculationService.php:187`-`:192`). For fixed stamp duty, `TaxConfiguration::calculateAmount()` returns the configured fixed decimal directly (`apps/api/app/Modules/Taxation/Domain/Entities/TaxConfiguration.php:192`-`:195`).

The regression test genuinely distinguishes truncation from half-up rounding: `qty=1.0000`, `unit_price=10.003`, `vat_rate=19.00` gives VAT `1.9005700`, which truncates to `1.900` but half-up rounds to `1.901` (`apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php:1061`-`:1070`). It asserts the persisted supplier-invoice line has `recoverable_tax_amount = 1.901` and `tax_amount = 1.901` (`SupplierInvoiceApiTest.php:1128`-`:1131`), then posts the invoice and asserts the 4456/VatDeductible journal debit is also `1.901` (`SupplierInvoiceApiTest.php:1136`-`:1150`). The posting GL path rounds GL legs with `CurrencyScale::bcround()` (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1202`-`:1214`) and posts the recoverable VAT debit to the VatDeductible account (`GeneralLedgerService.php:1265`-`:1275`).

HIGH2 (idempotency) - CLOSED.

The controller no longer pre-checks `assertPostable()`; it delegates directly to `SupplierInvoicePostingService::post()` and maps only the service's `DomainException` to `422 POSTING_BLOCKED` (`apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:180`-`:189`). The posting service owns the locked/idempotent boundary: it locks referenced PO lines first (`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:61`-`:75`), checks for an existing same-company supplier-invoice journal entry before rerunning the matcher (`SupplierInvoicePostingService.php:77`-`:85`), and returns no-op on retry.

Double POST is covered by `test_post_endpoint_is_idempotent_on_retry()`: first POST asserts 200/posted, second POST asserts 200/posted, the supplier-invoice JE count is exactly one, and the PO line `quantity_invoiced` is exactly `5.0000` after both calls (`apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php:1157`-`:1201`). Genuine over-invoice still maps to 422 with no JE and draft status in both the original endpoint test and the supplemental no-JE test (`SupplierInvoiceApiTest.php:290`-`:328`, `:930`-`:957`). The service still enforces match/post validation internally via `assertPostable()` (`SupplierInvoicePostingService.php:87`-`:89`), aggregate positive-quantity checks (`SupplierInvoicePostingService.php:95`-`:106`), and the authoritative locked-row over-clear write guard (`SupplierInvoicePostingService.php:137`-`:155`).

MEDIUM (scoping) - CLOSED.

The posting-service PO-line lock is now company-scoped through the parent document relation (`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:69`-`:75`; relation at `apps/api/app/Modules/Document/Domain/DocumentLine.php:149`-`:152`). The supplier-invoice JE idempotency read is also company-scoped (`SupplierInvoicePostingService.php:77`-`:82`). A foreign PO line fails closed: the matcher marks any referenced PO line whose parent document is not a purchase order for the supplier invoice's company as `exception` (`apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:292`-`:310`), and `assertPostable()` throws on that hard violation (`SupplierInvoiceMatcher.php:192`-`:205`). If the matcher path were bypassed or stale, the locked set still excludes the foreign row and the posting service throws before incrementing (`SupplierInvoicePostingService.php:116`-`:124`). INFERRED from the code paths above; there is no dedicated HTTP regression test that constructs a corrupted same-tenant, foreign-company supplier-invoice line and posts it.

The same-company lock predicate does not exclude legitimate PO lines in observed coverage: the happy-path post and full PO -> goods receipt -> supplier invoice -> post lifecycle tests both pass through this company-scoped lock and post successfully (`apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php:240`-`:283`, `:731`-`:923`).

## New/Regressed Defect Sweep

No new/regressed defect observed in the changed supplier-invoice HTTP API.

The round-once refactor still balances posts. The create path sets total from `subtotal + recoverable VAT + stamp duty` (`apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:165`-`:175`), and the GL service refuses to post if `invoice.total` does not equal `billedHt + recoverableVat + nonRecoverableVat + timbre` after the leg boundary rounding (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1216`-`:1223`). The lifecycle test asserts debits equal credits and verifies the hash chain after posting (`apps/api/tests/Feature/Procurement/SupplierInvoiceApiTest.php:884`-`:923`).

Removing the controller pre-check did not drop required validation that the service lacks. The service still runs matcher enforcement inside the posting transaction (`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:87`-`:89`), checks non-positive aggregate quantities (`SupplierInvoicePostingService.php:95`-`:106`), checks locked-row over-clear before saving `quantity_invoiced` (`SupplierInvoicePostingService.php:137`-`:155`), and relies on the GL invariant before journal rows are created (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1216`-`:1223`).

All supplier-invoice create/post arithmetic reviewed is bcmath string arithmetic. PHPStan level 8 is clean for `app/Modules/Procurement`, and the supplier-invoice API surface search found no float conversions.
