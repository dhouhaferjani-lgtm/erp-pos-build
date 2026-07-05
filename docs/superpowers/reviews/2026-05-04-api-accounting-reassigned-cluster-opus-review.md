# Opus adversarial review — api.accounting reassigned-callsite remediation

Review date: 2026-05-05
Branch tip reviewed: 382afb30
Reviewer: opus

Verdict: APPROVE
Commit reviewed: c6f8a4b1

## Summary

Remediates the 6 callsites reassigned from `api.unmapped` to `api.accounting`
(api.unmapped.001-005 + .013): three FK validators in `ExpenseRequest`
(`expense_category_id`, `payment_method_id`, `payment_repository_id`), two FK
validators in `ExpenseCategoryRequest` (`parent_id` self-ref, `account_id`),
and the `ExpenseCategoryController::wouldCreateCircularReference` private
helper. Both FormRequests now constructor-inject `CompanyContext`
(`private readonly`) and use `ScopedExists::tenantAndCompany` against tables
that all carry `tenant_id` + `company_id` (verified against migrations
`2025_11_30_090000_create_accounts_table` + `2025_11_30_120000_create_treasury_tables`
+ `2025_11_30_130000_add_company_id_to_existing_tables` +
`2025_11_30_134000_make_company_id_required` +
`2025_12_06_002513_add_company_id_and_system_purpose_to_accounts` +
`2025_12_23_145145_create_expense_categories_table`). Helper now scopes
`ExpenseCategory::query()->where('company_id', $companyId)->find($parentId)` —
defense-in-depth on top of the validator.

Test suite (`AccountingTenantIsolationTest`): 29 tests / 55 assertions, all
green. The 7 new tests added by this commit cover 5 cross-tenant rejections
(422 + correct error key per attribute), 1 controller-tier behavioural test
asserting validator short-circuit prevents the circular-reference helper
from walking a foreign tenant tree (with post-condition that the same-tenant
child was not renamed), and 1 bar-raising structural-SQL-log invariant
pinning that the `expense_categories` validator query contains both
`"tenant_id"` and `"company_id"` predicates. PHPStan + Pint clean on the
three changed files plus the test. POS surface diff `dev..HEAD -- apps/web/src`
is empty (only audit/lint tooling outside POS source).

Test honesty verified: reverting the three modified files to `c6f8a4b1^`
and re-running the new test filter produced 7 failures — three 201s where
422 was expected (validators passed cross-tenant ids), one 19/NOT NULL
constraint surfacing the same gap by another path, one 403 (the
update-route circular check pre-fix), and one structural-SQL assertion
showing the pre-fix SQL was the bare `select count(*) as aggregate from
"expense_categories" where "id" = ?` — exactly the unscoped shape Treasury
Finding 14 forbids. Restoring HEAD turns all 7 green again.

## Findings

None blocking. Three documentation-grade observations:

1. `ExpenseController` anchor lookups (lines 38, 127, 151, 183, 210) scope
   on `company_id` only, not `tenant_id` + `company_id`. The validator-tier
   guard catches cross-tenant bodies, so this is not a regression — but
   tightening the cluster anchor pattern to dual-axis is a deferred sweep
   item if the cluster invariant is hardened. Out of scope for the 6
   reassigned callsites.
2. Cross-cluster bare-exists patterns surfaced during the hostile grep but
   are explicitly out of scope: `POS/GenerateZReportRequest.php:46`
   (`exists:payment_methods,id`), `Accounting/CreateAccountRequest.php:45` +
   `UpdateAccountRequest.php:36` (tenant-only scope on accounts —
   pre-existing, NOT in the 6 reassigned callsites), and
   `Treasury/PaymentRepositoryController.php:78,134` (company-only scope on
   accounts). These are existing inventory items in their own clusters.
3. `ExpenseService` (Application layer) only does `DB::transaction` +
   `Document::create` + `ExpenseMetadata::create` + a company-scoped
   `Document::query()->where('company_id', $companyId)` for number
   generation. No bare `::find` / `::findOrFail` in the service; clean.

## Audit exhaustiveness

- Hostile grep on `app/Modules/Expense/` for `where(['"](id|category_id|parent_id|account_id|tenant_id|company_id)['"]|::find\(|::findOrFail\(|exists:expense|exists:payment|exists:accounts` (excl. tests) returned only company-scoped where chains and the post-fix scoped helper read. No bare reads remain.
- Sibling Presentation files (`ExpenseController` index/show/update/destroy/post) all anchor reads on `company_id`; ExpenseRequest is shared by store + update, so the validator fix covers both verbs.
- Domain models (`ExpenseCategory`, `ExpenseMetadata`) carry company-scope local scopes only; no unscoped finds.
- Controller-tier `wouldCreateCircularReference` parent-walk uses BelongsTo on `parent_id` within the same `expense_categories` table — once the starting node is `company_id`-scoped, transitivity holds (parent rows in foreign tenants cannot be reached through the BelongsTo because `parent_id` resolves only inside the table and the helper's seed row is already proven same-company).
- POS surface diff invariant satisfied: `git diff dev..HEAD -- apps/web/src` returns empty; the apps/web changes that exist (`apps/web/tools`, eslint config, vitest config, audit fixtures) are scanner tooling pre-existing on the tenant-isolation branch from earlier commits (99cc06b4 + ancestors).

## Confidence

High. Schema verified against migrations, fix matches the cluster template
(constructor-injected CompanyContext, ScopedExists::tenantAndCompany,
private readonly), test honesty proven by revert+re-run (7 red → 7 green),
defense-in-depth on the controller helper, and the structural-SQL-log
invariant pins the on-the-wire SQL shape rather than relying on
behavioural-only assertions. PHPStan level-8 + Pint clean. POS surface
unchanged. Recommend merging.
