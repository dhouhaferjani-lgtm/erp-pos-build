# R-5 Codex Review

Scope reviewed:

- `apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php`
- `apps/api/tests/Feature/Document/DocumentConversionScenarioTest.php`

Findings:

- No blocking issues found.
- The converter keeps its pre-existing graceful-degradation behavior for
  prepayment GL failures and now includes `InvalidArgumentException` from strict
  GL guard checks.
- The GL service still throws on invalid direct clearing attempts; only the
  sales-order conversion workflow catches and records the skip.
- The regression test exercises the acceptance case where the customer advance
  has already been cleared but a stale sales-order allocation is still
  transferred.
- Invoice/media attachment wiring is untouched.

Verification:

- RED before implementation:
  `php artisan test tests/Feature/Document/DocumentConversionScenarioTest.php --filter=it_converts_order_when_transferred_prepayment_is_already_cleared`
  failed with `InvalidArgumentException: Cannot clear customer advance beyond
  available balance (0).`
- GREEN after implementation:
  the same filtered test passed 1 test / 5 assertions.
- Scoped scenario suite:
  `php artisan test tests/Feature/Document/DocumentConversionScenarioTest.php`
  passed 13 tests / 35 assertions.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php tests/Feature/Document/DocumentConversionScenarioTest.php`
  reported no errors.
- `./vendor/bin/pint app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php tests/Feature/Document/DocumentConversionScenarioTest.php`
  passed.
