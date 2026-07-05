# Codex second-layer review — api.accounting reassigned-callsite remediation

Review date: 2026-05-05
Branch tip reviewed: 382afb30
Reviewer: codex

Verdict: REQUEST-CHANGES
Commit reviewed: c6f8a4b1

## Opus claim verification

Verified the prior Opus verdict end-to-end, `git show c6f8a4b1`, and `git show 382afb30`.

Confirmed:

- `ExpenseRequest` and `ExpenseCategoryRequest` constructor-inject `CompanyContext` as `private readonly`.
- The five FormRequest validators now use `ScopedExists::tenantAndCompany(...)` for `expense_categories`, `payment_methods`, `payment_repositories`, and `accounts`.
- The four referenced tables carry both `tenant_id` and `company_id` through the cited migrations.
- Reassigned rows `api.unmapped.001-005` and `api.unmapped.013` currently have `cluster_id: api.accounting`, `fix_commit: c6f8a4b1`, and regression-test pins.
- Gates rerun:
  - `vendor/bin/phpunit tests/Feature/Accounting/AccountingTenantIsolationTest.php`: pass, 29 tests / 55 assertions.
  - `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Expense tests/Feature/Accounting/AccountingTenantIsolationTest.php`: pass.
  - `./vendor/bin/pint --test app/Modules/Expense tests/Feature/Accounting/AccountingTenantIsolationTest.php`: pass.
  - `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`: pass, 1409 events / 299 callsites / 0 problems.
  - `git diff dev..HEAD -- apps/web/src`: empty.
- Test honesty: reversed only the c6 code changes while retaining the new tests, ran `vendor/bin/phpunit tests/Feature/Accounting/AccountingTenantIsolationTest.php --filter 'test_expense'`, and got 7/7 failures. The exact failure shape in this checkout differs from Opus for the expense-create cases (500 `documents.partner_id` NOT NULL after the bare validators let the request through), but the honesty claim holds: the new tests fail against pre-fix code.

Not confirmed:

- Opus says `ExpenseCategoryController::wouldCreateCircularReference` is clean defense-in-depth. The initial lookup is now company-scoped, but it is not tenant-scoped, and the recursive parent walk is unscoped. See Finding 1.

## New findings (round 1, second-layer)

1. REQUEST-CHANGES: `api.unmapped.013` is not remediated to the row's expected `tenant_and_company` scope.

`apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseCategoryController.php` now does:

```php
$current = ExpenseCategory::query()
    ->where('company_id', $companyId)
    ->find($parentId);
```

That omits `tenant_id`, even though the inventory row for `api.unmapped.013` has `expected_scope: tenant_and_company` and `expense_categories` carries both columns. More importantly, the loop then does `$current = $current->parent;`; the `parent()` relationship is a plain `belongsTo(ExpenseCategory::class, 'parent_id')`, so every recursive SELECT after the seed row is unscoped.

The schema does not rescue this. `2025_12_23_145145_create_expense_categories_table.php` has `parent_id` as a simple FK to `expense_categories.id`, not a composite tenant/company constraint. A legacy cross-company parent link created before this remediation, or any non-FormRequest write path, can still make the helper traverse a foreign category tree. The fix should either scope each parent-hop query by `tenant_id` and `company_id`, or define/use a scoped relationship/helper for parent traversal.

2. The circular-reference test does not prove the helper's recursive SQL shape.

`test_expense_category_update_circular_check_does_not_walk_foreign_tenant_tree` asserts validator short-circuiting on a cross-tenant `parent_id` and a no-mutation post-condition. It does not capture SQL from `wouldCreateCircularReference`, and because validation returns 422 first, it does not exercise the helper at all for the hostile parent. The only structural SQL test added by c6 pins the `ExpenseRequest` `expense_category_id` exists-validator query, not the recursive parent walk.

3. Opus's out-of-scope catalog is partly inaccurate.

- `POS/GenerateZReportRequest.php` payment-method validation is genuinely different cluster/module/route (`api.pos-stabilization.029`) and is out of scope for c6; current HEAD has it fixed by `ScopedExists::tenantAndCompany`.
- `Accounting/CreateAccountRequest.php` and `UpdateAccountRequest.php` parent-account validators are not cross-cluster; they are same `api.accounting` cluster, but outside the six reassigned Expense callsites. They remain tenant-only, not tenant+company.
- `Treasury/PaymentRepositoryController.php` `gl_account_id` account validators are different module/route and out of scope for c6, but I did not find matching inventory rows for those account validators. The nearby fixed treasury rows `api.treasury.056/.057` cover `responsible_user_id`, not `gl_account_id`.

## Audit exhaustiveness

Hostile grep over `app/Modules/Expense` returned only company-scoped route queries, the c6 helper lookup, model scopes, and service number-generation queries. The five reassigned validator callsites are clean. The sixth reassigned callsite is not clean because the helper is only company-scoped and the recursive relation reads are unscoped.

Sibling Expense review:

- `ExpenseRequest` is shared by `ExpenseController::store` and `ExpenseController::update`, so the five validator fixes cover POST and PATCH/PUT variants.
- No separate `UpdateExpenseRequest` exists.
- `ExpenseService` has no bare `find()` / `findOrFail()`; it receives already-loaded `Document` instances for update/post and creates metadata from validated data.
- `ExpenseController::{show,update,destroy,post}` and `ExpenseCategoryController::{show,update,destroy}` route-id reads are still `company_id` only. These are not part of the six c6 rows, but they are same-module residuals if the hardened route-anchor invariant is interpreted literally as requiring both predicates.
- Domain models and resources add no additional unscoped finds; the relevant risk is the plain `ExpenseCategory::parent()` relation used inside the helper.

Workflow/history:

- The reassignment is present in history as `command: sweep:inventory:reassign-unmapped (one-shot)` and current `cluster_id: api.accounting` for all six rows. The git commit that introduced the reassignment diff is `9630e58b`; the embedded event `commit` field records `b23e09db`, so the chain is understandable from git history but the in-row event metadata does not literally say `9630e58b`.
- Submit events are present for all six rows and were introduced by `382afb30`; the embedded event `commit` field correctly points at the fix commit `c6f8a4b1`.

## Confidence

Medium-high. The validator portion of c6 is solid, the gates pass, and the tests are honest. The remaining issue is isolated but in-scope: `api.unmapped.013` still does not meet tenant+company scope and its test does not exercise the recursive parent walk it claims to defend.
