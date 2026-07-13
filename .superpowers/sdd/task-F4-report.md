# Task F4 implementation report

## Result

- Replaced `whereRaw('company_id = ?', ...)` with the fluent `where('company_id', ...)` call inside the existing company-membership `whereHas` query.
- Replaced `whereRaw('status = ?', ...)` with the behavior-equivalent fluent `where('status', 'active')` call in the same query.
- No surrounding recipient-resolution behavior changed, and the existing test file remained unmodified.

## Verification

- Default focused path: `./vendor/bin/phpunit tests/Feature/Treasury/TreasuryAlertRecipientsTest.php` exited 0 with 4 tests, 3 assertions, and 3 PostgreSQL-only tests skipped.
- Full focused PostgreSQL path: `DB_CONNECTION=pgsql DB_DATABASE=autoerp_treasury_test DB_HOST=/tmp DB_USERNAME=houssamr DB_PASSWORD= ./vendor/bin/phpunit tests/Feature/Treasury/TreasuryAlertRecipientsTest.php` exited 0 with 4 tests and 11 assertions.

## Bookkeeping

- Updated both follow-up ledgers from the initial `bb44387f195dd8ac9383803e30e5d43492f1c9ec` base to the current rebased base, latest `origin/dev` at `a4ecd97182bf4aeade693d6f5801e361183d3361`.
- The base update records the completed rebase only; it does not change F4's behavior scope.

## Files changed

- `apps/api/app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php`
- `.superpowers/sdd/progress.md`
- `.superpowers/sdd/task-F4-report.md`
- `docs/handoff/treasury-phase3-followups-progress.md`

## Concerns

- None.
