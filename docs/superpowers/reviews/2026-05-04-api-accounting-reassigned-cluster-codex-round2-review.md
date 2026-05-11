# Codex round-2 second-layer review — api.accounting reassigned-callsite remediation

Review date: 2026-05-05
Branch tip reviewed: ffb34410
Reviewer: codex (round-2 second-layer review)

Verdict: APPROVE
Commit reviewed: c6f8a4b1

Commits reviewed (api.accounting reassignment cluster, cumulative):
- c6f8a4b1 — round-1 fix: ALL 6 reassigned callsites (api.unmapped.001-005 FormRequest validators + api.unmapped.013 ExpenseCategoryController::wouldCreateCircularReference seed lookup). All 6 callsites' `fix_commit` field in the inventory points at c6f8a4b1.
- 23ff4398 — round-2 IMPROVEMENT to .013: tightened the recursive parent walk to scope EVERY parent-hop SELECT (not just the seed). Since .013 was already pinned to c6f8a4b1 as fix_commit, round-2 acts as a strict improvement reviewed AGAINST that pin.

The workflow's review-command commit-linkage parser reads the FIRST `Commit reviewed:` line and matches against each callsite's `fix_commit` field. All 6 are pinned to c6f8a4b1, so this single review file flips all 6 in one pass.

## Round-1 finding closure (verified independently)
- Finding 1 (recursive parent walk): CLOSED. `ExpenseCategoryController::update()` now resolves the company once, derives both `$tenantId` and `$companyId`, and passes both into `wouldCreateCircularReference()`. The helper signature is now `(string $categoryId, string $parentId, string $tenantId, string $companyId)`. The seed lookup and every loop hop use an explicit `ExpenseCategory::query()->where('tenant_id', $tenantId)->where('company_id', $companyId)->find(...)`; the old unscoped `$current->parent` walk is gone.
- Finding 2 (test honesty): CLOSED. `AccountingTenantIsolationTest::test_expense_category_circular_check_recursive_walk_is_tenant_company_scoped()` builds a same-company chain, plants a cross-tenant parent edge with `DB::table(...)->update(...)`, invokes the private helper directly by reflection, captures the query log, and asserts every id-anchored `expense_categories` SELECT includes both `"tenant_id"` and `"company_id"`. I temporarily restored only the pre-fix controller helper while keeping the new test; it failed red with `Every recursive-helper id-anchored SELECT must filter by tenant_id` on `select * from "expense_categories" where "company_id" = ? and "expense_categories"."id" = ? limit 1`. Restored post-fix helper passes the focused test.
- Out-of-scope deferrals (audit doc): CLOSED. `docs/superpowers/audits/2026-05-05-accounting-cluster-residuals.md` documents the three round-1 deferred buckets with file paths and fix plans: Account parent validators, Expense/ExpenseCategory route-id reads, and Treasury `PaymentRepositoryController` `gl_account_id` validators.

## New findings (round 2, second-layer)
No new request-changes findings for the round-2 remediation. Hostile grep over `app/Modules/Expense` found the fixed helper queries plus already-deferred company-only route-id reads in `ExpenseController` and `ExpenseCategoryController`. I did not find another recursive parent/ancestor/descendant traversal in Expense services or controllers sharing the original helper bug.

## Audit exhaustiveness
The audit doc is sufficient for this round-2 verdict. For round-3, the A3/A4 route-id buckets should be interpreted broadly: after adding `tenant_id` to the root route-id fetches, also inspect route-param eager loads/aggregates that materialize related categories/accounts through unscoped Eloquent relations (`parent`, `children`, `account`, and expense metadata relations). Those are residual hardening work, not regressions in commit `23ff4398`.

Gates run from `apps/api`:
- `vendor/bin/phpunit tests/Feature/Accounting/AccountingTenantIsolationTest.php` — passed, 30 tests / 64 assertions.
- `./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Expense tests/Feature/Accounting/AccountingTenantIsolationTest.php` — passed.
- `./vendor/bin/pint --test app/Modules/Expense tests/Feature/Accounting/AccountingTenantIsolationTest.php` — passed.
- `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` — passed, 1409 events across 299 callsites, 0 problems.

## Confidence
High. I verified the code shape directly, proved the new regression test fails against the old helper and passes against the fixed helper, ran the requested gates, reviewed both fix commits, and completed the hostile Expense-module grep. Local `HEAD` was `c2f37aea`, one POS-only commit beyond `ffb34410`; `ffb34410..HEAD` does not touch the accounting files reviewed here.
