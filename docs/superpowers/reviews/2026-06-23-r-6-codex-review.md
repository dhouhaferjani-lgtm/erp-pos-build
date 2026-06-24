# R-6 Codex Review

Scope reviewed:

- `apps/api/app/Modules/Accounting/Application/Services/AccountingService.php`
- `apps/api/tests/Feature/Accounting/InvoiceAndCreditNoteGLIntegrationTest.php`

Findings:

- No blocking issues found.
- Invoice and credit-note GL persistence remains atomic for journal entries,
  lines, hash assignment, and journal-created events.
- Cached partner balance refresh now happens after GL persistence and is logged
  rather than fatal, preventing refresh-cache failures from deleting sealed
  document GL.
- Regression tests prove both invoice and credit-note GL entries survive a
  throwing `PartnerBalanceService`, and prove retrying refresh with the real
  service updates the partner cache.
- Invoice/media attachment wiring is untouched.

Verification:

- RED before implementation:
  `php artisan test tests/Feature/Accounting/InvoiceAndCreditNoteGLIntegrationTest.php --filter='partner_balance_refresh_fails'`
  failed both updated tests with `RuntimeException: partner balance refresh
  failed`.
- GREEN after implementation:
  the same filtered command passed 2 tests / 11 assertions.
- Scoped GL integration:
  `php artisan test tests/Feature/Accounting/InvoiceAndCreditNoteGLIntegrationTest.php tests/Feature/Accounting/InvoiceGLIntegrationTest.php tests/Feature/Accounting/CreditNoteGLIntegrationTest.php`
  passed 24 tests / 160 assertions.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Application/Services/AccountingService.php`
  reported no errors.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Application/Services/AccountingService.php tests/Feature/Accounting/InvoiceAndCreditNoteGLIntegrationTest.php`
  still reports pre-existing static-analysis issues across the legacy
  integration test file; the production file is clean.
- `./vendor/bin/pint app/Modules/Accounting/Application/Services/AccountingService.php tests/Feature/Accounting/InvoiceAndCreditNoteGLIntegrationTest.php`
  passed.
