# Task 6 report — W1 closeout and Gate 1 verification

Status: **DONE — Phase 4 changes are green; origin/dev-wide Pint drift is documented**

## Outcome

- Regenerated backend-driven TypeScript declarations with `CACHE_STORE=array`; 434 PHP types transformed and `packages/shared/types/generated.d.ts` remained byte-clean. There was no legitimate generated diff to commit.
- Ran the repository preflight exactly. It stopped at its first unconditional full-repository Pint check on 18 files that are byte-identical to `origin/dev`; Phase 4 does not change any reported file or Pint configuration.
- Corrected the request helper's invalid response generic and, after independent review, restored the established non-null general `Document` partner contract that the branch had incorrectly widened.
- Ran every Gate 1 verification path and each pinned Gate 1 money-path test. All runtime, frontend, formatting, and branch-owned static checks are green.
- Confirmed `TreasuryMovementService.php` is byte-untouched relative to `origin/dev`.

## Commits

- `3928e7f7f chore(expense): correct response test generic`
- `700212281 fix(expense): restore document partner contract`
- Task report/progress evidence is committed separately by the Task 6 closeout commit.

The transform produced no file change, so creating an empty generated-types commit would have misrepresented the repository state.

## Type generation

Command:

```text
cd apps/api
CACHE_STORE=array php artisan typescript:transform
```

Result: exit 0, `Transformed 434 PHP types to TypeScript`. Both `git diff --exit-code -- packages/shared/types/generated.d.ts` and the branch history/diff for that file were empty.

## Preflight diagnosis

Command:

```text
./scripts/preflight.sh
```

Result: exit 1 at the first step, `./vendor/bin/pint --test`, before PHPStan, PHPUnit, type generation, or frontend checks could run. Pint reported these 18 upstream files:

- `tests/Unit/Product/Enums/PricingModeTest.php`
- `tests/Unit/Product/EffectiveMarginsTest.php`
- `tests/Unit/Product/ValidatesMarginBandTest.php`
- `tests/Feature/Fiscal/RetryFiscalProjectionsCommandTest.php`
- eight files under `tests/Feature/Product`
- five files under `tests/Feature/Inventory`
- `tests/Feature/Company/CompanyMarginExposureTest.php`

Evidence of upstream ownership: `git diff --name-only origin/dev..HEAD` is empty for every reported path, and `git diff --quiet origin/dev..HEAD -- apps/api/pint.json apps/api/composer.json` exits 0. These files were deliberately not reformatted because Task 6 permits fixes only for branch-caused failures.

## Gate 1 backend matrix

- `./vendor/bin/phpunit tests/Feature/Expense`: exit 0, 68 tests / 272 assertions. A fresh post-PHPDoc-fix rerun produced the same result.
- `./vendor/bin/phpunit tests/Feature/Accounting`: exit 0, 478 tests / 2,156 assertions, 4 intentional skips, 1 existing deprecation.
- `./vendor/bin/phpunit tests/Feature/Treasury`: exit 0, 603 tests / 2,411 assertions, 25 intentional skips, 39 existing deprecations.
- `./vendor/bin/pint --dirty`: exit 0, `{"result":"pass"}`. The corrected test file also passes an explicit Pint `--test` run.

### PHPStan

- Initial exact `./vendor/bin/phpstan`: reached 2,529/2,529 files but exited 1 because four parallel workers exhausted the configured 512 MB memory limit.
- Initial `./vendor/bin/phpstan --memory-limit=2G`: completed analysis and reported 34 errors. The first diagnosis incorrectly attributed those errors to upstream because their downstream file paths were unchanged. That diagnosis was incomplete: all 34 errors were induced by this branch widening `Document::$partner_id` and `Document::$partner` to nullable.
- Changed-file analysis was therefore insufficient as the sole new-error check: it excluded the unchanged consumers whose inferred types had been altered by the shared model annotation.
- The first changed-file run correctly found the invalid `TestResponse<array<string,mixed>>` generic. After commit `3928e7f7f`, the focused request test passed 6 tests / 21 assertions, its focused PHPStan run passed, its focused Pint run passed, and the complete changed-file PHPStan run passed.

### Independent-review HIGH — shared Document partner contract

- RED: fresh `./vendor/bin/phpstan --memory-limit=2G --error-format=json` analyzed all 2,529 files and reported exactly 34 file errors across 22 consumers. Every error was nullability propagation from the two branch-changed `Document` PHPDoc properties.
- Root cause: the database permits a nullable partner for the Expense exception, but the shared `Document` domain contract and the 34 affected non-expense paths require a partner. Widening the global model annotation changed inference for every document type and forced an unnecessary optional chain into the supplier-invoice mapper.
- Fix: commit `700212281` restores origin/dev's `string $partner_id` and `Partner $partner` model annotations and restores `partner->name` in `ExpenseController::linkableInvoices()`. Expense response disclosure remains safe because `ExpenseResource` still derives supplier data only from the explicitly company-constrained loaded relation.
- GREEN: fresh `./vendor/bin/phpstan --memory-limit=2G` analyzed 2,529/2,529 files with `[OK] No errors`. A subsequent exact `./vendor/bin/phpstan` also analyzed 2,529/2,529 with `[OK] No errors`; the earlier default-memory OOM did not recur after the contract fix/cache warm-up.
- Regression verification: focused `ExpenseShowTest.php + ExpenseRequestVatValidationTest.php` passed 9 tests / 35 assertions; full `tests/Feature/Expense` passed 68 tests / 272 assertions; scoped Pint passed; `git diff --check` and the Treasury port diff both exited 0.

## Gate 1 frontend matrix

- `pnpm typecheck && pnpm lint`: exit 0. TypeScript passed; ESLint reported 0 errors and 6,447 existing warnings; TanStack audit reported 0 unscoped keys; design audit reported 753 acknowledged / 0 new / 0 stale; custom ESLint rule tests passed 5 valid + 5 invalid cases.
- `pnpm vitest run src/features/expenses src/features/notifications src/hooks/usePermissions.test.ts`: exit 0, 14 files, 90 passed / 3 todo. Existing React `act(...)` and Node local-storage warnings remain non-failing.
- `node tools/audit-design-system.mjs`: exit 0, 753 acknowledged / 0 new / 0 stale.
- `node tools/audit-tanstack-keys.mjs`: exit 0, 0 violations / 0 new / 0 stale.

## Gate-1-specific proofs

- Paid VAT reconcile fixture: `ExpenseVatPostingTest::test_paid_vat_expense_is_green_across_treasury_reconcile_checks_one_through_four` — 1 test / 12 assertions, exit 0. The fixture pins the repository GL account to the purpose-resolved cash account and exercises the authoritative branch.
- VAT-less regression: `ExpenseVatPostingTest::test_vatless_expense_keeps_the_exact_legacy_two_line_shape_and_has_no_tax_detail` — 1 test / 2 assertions, exit 0.
- Console-shape create: `ExpenseServiceVatTest::test_create_uses_explicit_company_when_no_company_context_is_bound` — 1 test / 2 assertions, exit 0.

## Inviolate surface and final hygiene

- `git diff --exit-code origin/dev..HEAD -- apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php`: exit 0, empty.
- No fiscal perimeter, settlement amount semantics, or linked-cost capitalization surface was changed during Task 6.
- `.superpowers/sdd/progress.md` remains the sole unstaged controller ledger and was never staged.

## Deviations and blockers

- Type generation was a no-op, so no empty commit was created.
- Exact preflight cannot become green without unrelated origin/dev formatting changes.
- The original attribution of 34 full-PHPStan errors to origin/dev was a Task 6 verification mistake; it is explicitly retracted above. Full PHPStan is now green without a baseline, suppression, or downstream edits.
- No branch-caused blocker remains. Formal Gate 1 tagging and `claude -p` review are intentionally left to the root controller.
