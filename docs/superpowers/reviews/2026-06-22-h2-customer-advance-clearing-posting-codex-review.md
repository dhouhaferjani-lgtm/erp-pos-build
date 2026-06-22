# Codex Review — H-2.5 Customer Advance Clearing Posting

Date: 2026-06-22

Scope:
- `GeneralLedgerService::clearCustomerAdvanceToReceivable()`
- `SalesOrderToInvoiceConverter`
- `DocumentConversionController::convertOrderToInvoice()`
- document conversion prepayment-transfer regression coverage

Verdict: CLEAN.

Findings:
- No blocker/high issues found.

Acceptance criteria check:
- Sales-order-to-invoice conversion now passes an actor id from the HTTP controller into conversion options.
- `SalesOrderToInvoiceConverter` forwards that actor id and invoice currency into the customer-advance clearing GL helper.
- `clearCustomerAdvanceToReceivable()` validates the actor before journal creation and posts through the canonical after-commit path when an actor is supplied.
- Actorless service conversions keep the previous draft-only behavior.

Residuals:
- Final H-2 completeness scan still needs to classify any remaining non-POS draft creators.

Verification reviewed:
- Red observed first: `php artisan test tests/Feature/Document/DocumentConversionScenarioTest.php --filter it_posts_prepayment_application_when_order_invoice_conversion_has_actor`
- Green: `php artisan test tests/Feature/Document/DocumentConversionScenarioTest.php tests/Feature/Accounting/GLIntegrationTest.php --filter 'prepayment|customer_advance_clearing|it_posts_prepayment_application|services_only_orders'`
- `php artisan test --filter PartnerBalanceServiceTest`
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Domain/Services/GeneralLedgerService.php app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php app/Modules/Document/Presentation/Controllers/DocumentConversionController.php`
- Pint and `git diff --check`
