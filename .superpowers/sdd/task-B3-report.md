# Task B3 report

## Scope

- Added `TreasuryAlertNotification` with synchronous database-only delivery, a stable `databaseType()`, and the supplied alert payload.
- Added constructor-injected `TreasuryAlertRecipients`.
- The resolver sets the Spatie team to the tenant ID, flushes cached permissions before the permission query, filters to an active company membership, and restores the previous team in `finally`.

## TDD evidence

- RED: `./vendor/bin/phpunit tests/Feature/Treasury/TreasuryAlertRecipientsTest.php`
  - Failed because `TreasuryAlertNotification` did not exist.
- GREEN (real PostgreSQL DB-per-tenant harness):
  - `DB_CONNECTION=pgsql DB_DATABASE=autoerp_treasury_test DB_HOST=/tmp DB_USERNAME=houssamr DB_PASSWORD= ./vendor/bin/phpunit tests/Feature/Treasury/TreasuryAlertRecipientsTest.php`
  - Result: 4 tests, 11 assertions, 0 skipped.
  - The multi-tenant test creates and migrates two physical tenant databases, primes the singleton registrar with tenant A's permission records, then resolves tenant B with a deliberately different permission ID.
  - Mutation check: removing `forgetCachedPermissions()` made tenant B resolve no recipients; restoring it returned the suite to 4 tests/11 assertions GREEN.

## Verification

- `php -d memory_limit=1G ./vendor/bin/phpstan analyse app/Modules/Treasury/Application/Notifications/TreasuryAlertNotification.php app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php tests/Feature/Treasury/TreasuryAlertRecipientsTest.php --no-progress` — clean.
- `./vendor/bin/pint app/Modules/Treasury/Application/Notifications/TreasuryAlertNotification.php app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php tests/Feature/Treasury/TreasuryAlertRecipientsTest.php` — applied.

## Notes

- No registrar spy or mock is used.
- No unrelated production files were changed.

## Reviewer fix: stored alert type

- RED: the focused notification test supplied a conflicting `alert_type` and observed that `toDatabase()` preserved the wrong caller value.
- GREEN: `toDatabase()` now appends the constructor's stable type, adding the key when absent and overriding caller input when present.
- Real PostgreSQL B3 suite: 4 tests, 11 assertions, 0 skipped.
- PHPStan with a 1 GB memory limit: clean. Pint: pass.
