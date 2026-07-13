# Treasury Phase ④ — Expense Depth Progress

## Setup — 2026-07-13

- Branch: `feat/treasury-phase4-expense-depth`
- Base: `94a7c08cc0f4bde729a5dd8cf4c23388641840b5` (`origin/dev`)
- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase4`
- Dependencies: real `composer install` in `apps/api`; root `pnpm install --frozen-lockfile`.
- Binding inputs read in required order: spec Rev 2.1, plan Rev 2, spec review, Fable W1 plan review, Opus W2–W4 plan review.
- Plan review: no unresolved contradiction found; all review findings are reconciled in plan Rev 2.
- Deviations: none.

## Task 1 — VAT fields on expense metadata — 2026-07-13

- Files:
  - `apps/api/database/migrations/tenant/2026_07_14_100000_add_vat_fields_to_expense_metadata.php`
  - `apps/api/app/Modules/Expense/Domain/ExpenseMetadata.php`
  - `apps/api/tests/Feature/Expense/ExpenseVatSchemaTest.php`
- RED: `php artisan test tests/Feature/Expense/ExpenseVatSchemaTest.php` exited 1 with 2 failed tests (2 assertions): the VAT column was absent and `vat_rate` was dropped during mass assignment.
- GREEN: `php artisan test tests/Feature/Expense/ExpenseVatSchemaTest.php --display-warnings` exited 0 with 2 passed tests (4 assertions).
- Implementation deviations: none. No `document_tax_details` migration or modification was added.
- Environment note: standalone `php artisan migrate --env=testing --force` reached the repository's named `central` PostgreSQL connection and failed because the local `root` role is unavailable. The focused test's `RefreshDatabase` path successfully applied the migration under the PHPUnit SQLite test configuration.
