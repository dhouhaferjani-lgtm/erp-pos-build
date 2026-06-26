# R-7 Codex Review

Scope reviewed:

- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
- `apps/api/tests/Feature/Accounting/GeneralLedgerPostEntryScaleTest.php`

Findings:

- No blocking issues found.
- `postEntryWithOptionalActor()` now uses storage-scale totals for the balance
  guard, so scale-0 currencies cannot hide sub-unit imbalances.
- `JournalEntryPosted` payload totals remain formatted at the currency display
  scale after the Opus review follow-up, preserving downstream event payload
  behavior.
- Hash-chain behavior is unchanged because hash calculation still uses the
  journal entry and company currency, not the guard/event total strings.
- Invoice/media attachment wiring is untouched.

Verification:

- RED before implementation:
  `php artisan test tests/Feature/Accounting/GeneralLedgerPostEntryScaleTest.php`
  failed because the expected unbalanced-entry exception was not thrown.
- GREEN after the balance-guard fix:
  the focused test passed, then an Opus review identified the event-total format
  risk.
- RED for the Opus minor:
  the focused test file failed the display-scale event total assertion.
- GREEN after the event-total follow-up:
  `php artisan test tests/Feature/Accounting/GeneralLedgerPostEntryScaleTest.php`
  passed 2 tests / 3 assertions.
- Scoped GL/hash/event regression:
  `php artisan test tests/Feature/Accounting/GeneralLedgerPostEntryScaleTest.php tests/Feature/Accounting/GLIntegrationTest.php tests/Feature/Accounting/GLHashIntegrationTest.php tests/Feature/Accounting/CompleteGLHashChainE2ETest.php tests/Feature/Accounting/AccountingEventsTest.php`
  passed 54 tests / 209 assertions.
- `./vendor/bin/phpstan analyse --level=8 app/Modules/Accounting/Domain/Services/GeneralLedgerService.php tests/Feature/Accounting/GeneralLedgerPostEntryScaleTest.php`
  reported no errors.
- `./vendor/bin/pint app/Modules/Accounting/Domain/Services/GeneralLedgerService.php tests/Feature/Accounting/GeneralLedgerPostEntryScaleTest.php`
  passed.
