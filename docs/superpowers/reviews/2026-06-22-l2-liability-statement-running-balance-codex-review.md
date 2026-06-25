# L-2 Liability Statement Running Balance — Codex Review

## Scope

Review target: statement presentation only for `PartnerBalanceService::getPartnerStatement()`.

Acceptance criteria:
- Liability statement sign is explicit and tested.
- `SupplierPayable` and `CustomerAdvance` statements use credit-normal running balances.
- Receivable statement behavior remains debit-normal.

## Diff Reviewed

- `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php`
- `apps/api/tests/Feature/Accounting/PartnerBalanceServiceTest.php`

## Findings

No BLOCKER, HIGH, MEDIUM, or LOW findings.

The implementation keeps the query and transaction set unchanged, computes a single `isCreditNormalPurpose` flag, and swaps the running-balance increase/decrease operands only for `CustomerAdvance` and `SupplierPayable`. That limits the blast radius to statement presentation and leaves the existing receivable statement test covering the debit-normal path.

## Verification Reviewed

- Red observed first: `php artisan test --filter 'statement_uses_credit_normal'` failed with `-50.0000` and `-80.0000`.
- Green after implementation: `php artisan test --filter 'statement_uses_credit_normal'` passed 2 tests, 5 assertions.
- `php artisan test --filter PartnerBalanceServiceTest` passed 20 tests, 40 assertions, with pre-existing PHPUnit 12 doc-comment metadata warnings.
- `./vendor/bin/phpstan analyse app/Modules/Accounting/Application/Services/PartnerBalanceService.php --level=8` passed.
- `./vendor/bin/pint --test app/Modules/Accounting/Application/Services/PartnerBalanceService.php tests/Feature/Accounting/PartnerBalanceServiceTest.php` passed.
- `git diff --check` passed.

