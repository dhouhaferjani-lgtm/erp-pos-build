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

## Task 4 — Expense VAT split posting and input-VAT declaration wiring — 2026-07-13

- Files:
  - `apps/api/app/Shared/Domain/ExpenseVatSplit.php`
  - `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
  - `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php`
  - `apps/api/tests/Feature/Accounting/ExpenseVatPostingTest.php`
  - `docs/handoff/treasury-phase4-progress.md`
- RED: `php artisan test tests/Feature/Accounting/ExpenseVatPostingTest.php --display-warnings` exited 1 with 7 failed and 2 passed (13 assertions). The failures directly showed the legacy gross-only debit instead of the VAT split and the absent `document_tax_details` row; VAT-less posting and the supplier-balance regression were already green.
- GREEN: the focused command exited 0 with 9 passed tests (29 assertions). Coverage pins unpaid/paid 100%, 80% remainder math and matching tax detail, 1-millime half-up, 0% with no 4456 line, byte-identical VAT-less shape/no tax row, EUR scale, supplier balance post-to-settle, and reconcile checks #1–#4 over a purpose-resolved repository GL account.
- Relevant regression cutoff: `php artisan test tests/Feature/Expense/ExpenseServiceVatTest.php tests/Feature/Expense/ExpenseSettlementTest.php tests/Feature/Accounting/AccountingTenantIsolationTest.php tests/Feature/Treasury/ReconcileTreasuryTest.php --display-warnings` exited 0 with 79 passed tests (240 assertions).
- Scoped verification:
  - PHPStan over the shared helper, both modified services, and the new feature test exited 0 with no errors.
  - Pint `--test` over the same four files exited 0 (`{"result":"pass"}`).
  - `git diff --check` exited 0.
- Invariants: both GL and tax-detail persistence consume `App\Shared\Domain\ExpenseVatSplit`; the helper uses `scale + 2` intermediates and one `CurrencyScale::bcround` at the posting boundary; GL uses the explicit expense currency scale and the remainder method; deductible VAT at zero omits 4456; the AP/Cash credit remains gross total; VAT-less posting preserves its exact two-line payload; tax detail is written in the existing posting transaction immediately after the JE is posted.
- Reconcile harness note: this `TenantScopedCommand` run returns no captured `Artisan::output()`. The test therefore pins exit 0 plus authoritative cash-line matching, movement ordinal/balance continuity, Posted JE linkage, unfrozen/unflagged repository state, and absence of cash/portfolio drift audit events.
- Deliberate architecture edge: `ExpenseService` writes `DocumentTaxDetail` directly as accepted by the binding plan; the shared math remains in `Shared\Domain` to avoid an Accounting-to-Expense dependency.
- Implementation deviations: none. Settlement, linked-cost posting/capitalization, `TreasuryMovementService`, fiscal surfaces, and migrations were not changed. Broad preflight/full repository suites were intentionally not run per the Task 4 brief.

### Task 4 post-commit review fix — collision-safe TVA detail identity

- Accepted finding: `firstOrCreate` keyed only on `document_id + tax_type` could reuse an unrelated stacked percentage detail and silently drop the expense TVA defaults, breaking the required equality between the 4456 debit and declared deductible VAT.
- RED: the focused posting suite exited 1 with 1 failed and 9 passed (31 assertions). The new fixture pre-seeded an unrelated percentage detail and proved posting left only that row instead of creating the required TVA snapshot.
- GREEN: the focused suite exited 0 with 10 passed tests (40 assertions). `firstOrCreate` now identifies an already-correct TVA row by the complete required semantic/calculated identity: document, type, name, rate, base, and deductible amount. Sequence, tax code, fixed amount, and stamp-duty flag remain creation defaults; no tax-code identity convention was introduced.
- Test strengthening: the EUR case now uses `0.01 VAT × 50%`, proving scale-2 half-up produces `0.010` at rest rather than a scale-3 `0.005`; the VAT-less regression now compares every deterministic line business attribute and order, excluding only IDs/timestamps that are nondeterministic.
- Idempotency: the collision test proves the upstream Posted-state guard rejects a replay before another TVA detail can be created, and the detail count remains exactly two (the unrelated row plus the required TVA row).
- Scoped regression cutoff: the required four-file command exited 0 with 79 passed tests (240 assertions). Its first run had one transient pre-existing `ReconcileTreasuryTest` one-millime tolerance failure; that case passed alone, the full reconcile file passed 20/20, and the exact four-file command passed on immediate rerun. No Treasury code or test was changed.
- Scoped PHPStan and Pint over the modified service/test exited 0; final fresh verification is recorded in the Task 4 report.
- Scope: no changes to GL split math, settlement, linked costs, treasury services, fiscal surfaces, or migrations.
