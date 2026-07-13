# Task F4 implementation report

## Result

- Replaced `whereRaw('company_id = ?', ...)` with the fluent `where('company_id', ...)` call inside the existing company-membership `whereHas` query.
- Replaced `whereRaw('status = ?', ...)` with the behavior-equivalent fluent `where('status', 'active')` call in the same query.
- No surrounding recipient-resolution behavior changed, and the existing test file remained unmodified.

## Verification

- Default focused path: `./vendor/bin/phpunit tests/Feature/Treasury/TreasuryAlertRecipientsTest.php` exited 0 with 4 tests, 3 assertions, and 3 PostgreSQL-only tests skipped.
- Full focused PostgreSQL path: `DB_CONNECTION=pgsql DB_DATABASE=autoerp_treasury_test DB_HOST=/tmp DB_USERNAME=houssamr DB_PASSWORD= ./vendor/bin/phpunit tests/Feature/Treasury/TreasuryAlertRecipientsTest.php` exited 0 with 4 tests and 11 assertions.

## Full-gate PHPStan fix

- RED: `./vendor/bin/phpstan analyse app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php --memory-limit=1G --no-progress` exited 1 with two `argument.type` errors on the fluent `company_id` and `status` predicates because Larastan inferred the `whereHas` callback as `Builder<Model>`.
- Root cause: `User::companyMemberships()` is correctly declared as `HasMany<UserCompanyMembership, $this>`, but that related-model generic is lost at the `whereHas` callback boundary.
- The initial `@param Builder<UserCompanyMembership>` placed directly before the anonymous callback was not retained because PHPStan did not attach that argument-position PHPDoc to the closure; both `Builder<Model>` errors remained. A typed callback-factory return alone was also not retained because PHPStan does not contextually apply that return signature inside an anonymous closure body.
- Fix: imported Eloquent `Builder`, `UserCompanyMembership`, and `Closure`; the callback factory now converts a locally scoped invokable object to a closure. Its named `__invoke` parameter has the ordinary `@param Builder<UserCompanyMembership>` contract, so PHPStan sees the related model without any inline `@var` override. Both fluent predicates and runtime query behavior remain unchanged; no suppression, baseline, or raw SQL was added.
- GREEN after Pint: the same focused PHPStan command exited 0 with `[OK] No errors`.
- Post-fix default recipient path: exit 0 with 4 tests, 3 assertions, and the 3 expected PostgreSQL-only skips.
- Post-fix PostgreSQL recipient path: exit 0 with 4 tests and 11 assertions.
- `./vendor/bin/pint app/Modules/Treasury/Application/Services/TreasuryAlertRecipients.php` exited 0 with `{"result":"pass"}`.

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
