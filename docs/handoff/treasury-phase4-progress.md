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

## Task 2 — Expense request partner + VAT format validation — 2026-07-13

- Files:
  - `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseRequest.php`
  - `apps/api/tests/Feature/Expense/ExpenseRequestVatValidationTest.php`
- RED: `php artisan test tests/Feature/Expense/ExpenseRequestVatValidationTest.php --display-warnings` exited 1 with 4 failed and 1 passed (5 assertions): the endpoint returned 201 for a cross-company partner and each invalid VAT-format/range payload.
- GREEN: the same focused command exited 0 with 5 passed tests (13 assertions).
- Implementation deviations: none. Task 3 service/persistence semantics were not implemented.

## Task 3 — VAT-aware ExpenseService totals and merged guards — 2026-07-13

- Files:
  - `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php`
  - `apps/api/tests/Feature/Expense/ExpenseServiceVatTest.php`
  - `docs/handoff/treasury-phase4-progress.md`
- RED: `php artisan test tests/Feature/Expense/ExpenseServiceVatTest.php --display-warnings` exited 1 with 11 failed and 3 passed (22 assertions). Failures directly showed missing VAT subtotal/tax persistence, document-date and partner passthrough, merged/equality/currency-grid guards, stored linked-cost-kind rejection, and console-safe company resolution.
- Review-fix RED: the same focused command exited 1 with 3 failed and 14 passed (30 assertions), proving that EUR subminor VAT, zero-decimal-currency fractions, and digits beyond `$scale + 1` could bypass the initial grid comparison.
- GREEN: the final focused command exited 0 with 17 passed tests (30 assertions) in 11.21s.
- Expense cutoff: `php artisan test tests/Feature/Expense --display-warnings` initially exited 0 with 56 passed tests (239 assertions); the fresh final rerun after the grid-edge fix exited 0 with 59 passed tests (242 assertions) in 61.51s.
- Scoped verification:
  - `./vendor/bin/phpstan analyse app/Modules/Expense/Application/Services/ExpenseService.php --no-progress` exited 0 with no errors; this includes the configured `ForbidHardcodedBcmathScale` rule.
  - `./vendor/bin/pint --test app/Modules/Expense/Application/Services/ExpenseService.php tests/Feature/Expense/ExpenseServiceVatTest.php` exited 0 (`{"result":"pass"}`).
  - `php -l` on both changed PHP files exited 0 with no syntax errors.
  - `git diff --check` exited 0.
- Invariants: create resolves `Company` from explicit `company_id`; update resolves it from the stored document; all scale lookups receive that explicit currency; VAT math remains string/bcmath-based; a full fractional-digit check rejects every nonzero digit beyond the currency grid before exact-zero normalization or strict formatting; zero VAT becomes the VAT-less shape; update guards use merged total/VAT and stored expense kind; clearing VAT clears the full metadata trio.
- Implementation deviations: none. Posting, settlement, linked-cost capitalization, treasury movement, and fiscal surfaces were not changed. The broad repository preflight was intentionally not run per the Task 3 brief.

### Task 3 post-commit review fix — effective linked-cost VAT trio

- Accepted finding: the initial invariant shape omitted `vat_rate` and `vat_deductible_percent`, allowing rate-only or deductible-percent-only linked-cost create/update payloads to bypass the stored-kind guard and be silently cleared.
- RED: `php artisan test tests/Feature/Expense/ExpenseServiceVatTest.php --display-warnings` exited 1 with 4 failed and 17 passed (34 assertions). Rate-only and deductible-percent-only cases failed on both valid linked-cost create and stored-kind update.
- GREEN: the focused command exited 0 with 21 passed tests (38 assertions) in 10.21s. Each new case pins the exact linked-cost `DomainException` message.
- Expense cutoff: `php artisan test tests/Feature/Expense --display-warnings` exited 0 with 63 passed tests (250 assertions) in 39.99s.
- Scoped verification: focused PHPStan exited 0 with no errors; focused Pint exited 0 (`{"result":"pass"}`).
- Implementation: `assertVatInvariants()` now receives the effective merged VAT trio. Any non-null trio field on a linked-cost create or stored-kind update throws before persistence can clear it. Generic zero VAT still normalizes the entire trio to null.
- Review disposition: the VAT-less-total finding was not implemented. The binding brief states, **"When VAT is present, total and vat_amount must be ON THE CURRENCY GRID,"** and separately requires zero VAT to normalize to null for the VAT-less backward-compatible path. Rejecting VAT-less EUR `119.005` here would add an unplanned breaking validation change outside Task 3.
