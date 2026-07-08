# Wave 4 Opus Review Reconciliation

Date: 2026-07-08

## Review Artifacts

- `wave4-opus-review.md` — initial Wave 4 review; found one MAJOR about SalesOrder discounted-line semantics.
- `wave4-opus-review-rerun.md` — review after the first SalesOrder fix; found one MAJOR requiring downstream conversion verification.
- `wave4-opus-review-final.md` — failed attempt; Claude connection closed mid-response.
- `wave4-opus-review-final-rerun.md` — narrowed final review after conversion regression; found **NO BLOCKER/MAJOR FINDINGS**.

## MAJOR Reconciliation

- **SalesOrder validation checked discounted net price while persistence ignored discounts** — Fixed by changing `SalesOrderController` store/update totals and persisted `line_total` to use `DocumentLine::computeLineTotal()` with the same discount fields as the validator and invoices.
- **SalesOrder gross→net semantics could affect downstream conversion** — Reconciled with `test_sales_order_to_invoice_conversion_carries_discounted_net_line_total_once()`, which creates an order with a fixed line discount, converts it to an invoice through the route, and asserts the invoice line total carries the net discounted amount exactly once.

## Accepted Minor Notes

- `Block` and `WarnRequiresPermission` both reject non-holders and warn holders in this FormRequest layer. That is intentional for Phase 1 because the verdict reuses existing `pricing.sell_below_minimum_margin` / `pricing.sell_below_cost` permissions; no new override permission is introduced.
- Variant-specific floors are not active in Phase 1 document payloads, so the validator passes `variantId: null`.
- Advisory default is verified in Wave 1 schema tests; Wave 4 tests set modes explicitly for behavior coverage.

## Verification

```bash
php artisan test tests/Feature/Document/DiscountPolicyDocumentValidationTest.php tests/Feature/Document/DiscountToleranceValidationTest.php
./vendor/bin/pint --test app/Modules/Document/Presentation/Validation/DiscountPolicyDocumentValidator.php app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php app/Modules/Document/Presentation/Controllers/InvoiceController.php app/Modules/Document/Presentation/Controllers/SalesOrderController.php app/Modules/Document/Presentation/Controllers/Concerns/HandlesDocuments.php tests/Feature/Document/DiscountPolicyDocumentValidationTest.php
./vendor/bin/phpstan analyse app/Modules/Document/Presentation/Validation app/Modules/Document/Presentation/Requests app/Modules/Document/Presentation/Controllers/InvoiceController.php app/Modules/Document/Presentation/Controllers/SalesOrderController.php app/Modules/Document/Presentation/Controllers/Concerns/HandlesDocuments.php --memory-limit=2G
rg -n "app\\(" app/Modules/Document/Presentation/Validation app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php
git diff --check
```

Results: tests, Pint, PHPStan, and `git diff --check` passed. The `rg app(` check returned no matches in the new validator/request paths.
