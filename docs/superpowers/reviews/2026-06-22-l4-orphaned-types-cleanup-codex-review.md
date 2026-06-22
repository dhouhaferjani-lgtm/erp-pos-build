# L-4 Orphaned Types Cleanup — Codex Review

## Scope

Review target: confirmed unreferenced service/DTO cleanup and generated-type refresh.

Acceptance criteria:
- Confirm each target is unused.
- Remove or wire unused types.
- Refresh generated types.
- No behavior changes unless a type is wired.

## Findings

No BLOCKER, HIGH, MEDIUM, or LOW findings.

The reference scan found no runtime consumers for `InvoiceConsolidationService`, `ExpenseData`, `LoginData`, or `PartNeedData`. `LoginData` and `PartNeedData` were only exposed through generated shared TypeScript types; those generated exports were removed after deleting the PHP source classes. Active docs that named the removed definitions were updated; historical audit/planning docs were left intact.

## Verification Reviewed

- Red observed first: `php artisan test tests/Architecture/OrphanedTypesCleanupTest.php` failed on all four still-present files.
- Green after cleanup: `php artisan test tests/Architecture/OrphanedTypesCleanupTest.php` passed 4 tests, 4 assertions.
- `CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php artisan typescript:transform` passed and transformed 349 PHP types.
- `pnpm --filter @autoerp/web typecheck` passed.
- `./vendor/bin/phpstan analyse tests/Architecture/OrphanedTypesCleanupTest.php --level=8` passed.
- `./vendor/bin/pint --test tests/Architecture/OrphanedTypesCleanupTest.php` passed after formatting.
- Active reference scan passed: no `LoginData`, `PartNeedData`, `ExpenseData`, or `InvoiceConsolidationService` references remain in active app/shared/current-doc paths.
- `git diff --check` passed.

