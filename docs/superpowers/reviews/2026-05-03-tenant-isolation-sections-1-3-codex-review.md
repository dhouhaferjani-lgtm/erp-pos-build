# Verdict (one line)

REQUEST-CHANGES

# Top 3 remaining risks

1. Gate A has real false negatives: it only matches quoted `exists:` strings, so unscoped guarded-table builder rules like `Rule::exists('payment_methods', 'id')` and `Rule::exists('payment_repositories', 'id')` are invisible.
2. Gate B is not a reliable source-of-truth scanner yet: it false-positives legitimate scoped local scopes such as `Document::forCompany($companyId)->findOrFail($id)`, and its scoped-variable state can bleed across methods because it is file-global.
3. Gate C misses nested `useQueries({ queries: [...] })` query keys entirely and over-accepts arbitrary property access ending in `companyId` / `tenantId`, so it has both false negatives and over-broad approvals before Section 11 begins.

# Section-by-section findings

## Section 1 — ScopedExists helper

Finding: partially-fixed
The helper itself satisfies the pure-factory contract: `ScopedExists` has only the three required factories and returns `Rule::exists(...)->where(...)` without `auth()`, `app()`, or service location in `apps/api/app/Shared/Presentation/Validation/ScopedExists.php:28`. The test contract is still too weak for the acceptance criterion. `test_tenant_and_company_rejects_cross_tenant_value_via_real_validator()` never creates a validator, writes rows, or proves cross-tenant rejection; it only asserts the rendered string in `apps/api/tests/Unit/Shared/Presentation/Validation/ScopedExistsTest.php:100`. That makes the test name misleading and keeps the implementation coupled to Laravel's string rendering instead of behavior.

## Section 2 — CrossTenantRoute attribute + middleware

Finding: verified-fixed
`CrossTenantRoute` is method-only via `#[Attribute(Attribute::TARGET_METHOD)]`, has a public readonly required reason, and rejects blank whitespace in `apps/api/app/Shared/Architecture/CrossTenantRoute.php:30`. `CrossTenantContext` sets only the request attribute and the docblock is explicit that authorization remains upstream in `apps/api/app/Http/Middleware/CrossTenantContext.php:20`. The alias registration is a minimal bootstrap change: the import plus `'cross_tenant' => CrossTenantContext::class` at `apps/api/bootstrap/app.php:4` and `apps/api/bootstrap/app.php:48`. No production consumer reads `cross_tenant` yet, but that is acceptable for this foundation if the Section 3 gates consume the attribute correctly.

## Section 3 Gate A — presentation exists: scanner

Finding: still-open
The scanner does not meet the master-plan contract because it only applies `/['"]exists:(...)...['"]/` to source text in `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php:110`. It misses builder-form validation entirely, including unscoped guarded resources in `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseRequest.php:40` and `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseRequest.php:44`. It also does not implement the planned `#[CrossTenantRoute]` skip because it has no controller/method reflection path, and its guarded-table list omits scoped tables visible in current Presentation code, such as `locations`, `accounts`, `services`, `payments`, `loyalty_rewards`, and `tax_configurations`.

## Section 3 Gate B — application find() AST scanner

Finding: still-open
The AST scanner is structurally incomplete. `FindCallVisitor::$scopedVariables` is a flat property for the whole file in `apps/api/tests/Architecture/TenantScopedFindCallsTest.php:188`, and there is no method/function enter/leave reset, so a scoped `$query` in one method can suppress a later unscoped `$query->find()` in another method. The chain detector only recognizes direct `where('tenant_id'|'company_id', ...)` calls in `apps/api/tests/Architecture/TenantScopedFindCallsTest.php:288`, so legitimate local scopes and relationship scopes are not distinguished. Real scoped calls such as `Document::forCompany($companyId)->findOrFail($id)` in `apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php:292` and `:357` are reported as violations. The scanner also does not implement the required `#[CrossTenantRoute]` or `// @cross-tenant-by-design` skip.

## Section 3 Gate C — web TanStack queryKey scanner

Finding: still-open
The script is default-deny for bare identifiers as intended, and it scans the queryClient object methods listed in the plan. It does not correctly handle `useQueries`: the scanner only looks for a top-level `queryKey` property on the first call argument in `apps/web/tools/audit-tanstack-keys.mjs:160`, while actual `useQueries` stores entries under `queries`, as shown in `apps/web/src/features/parts-catalog/hooks/useArticleDetail.ts:32`. The two nested `queryKey` entries at `:35` and `:41` are therefore missed. The approved-scope predicate is also over-broad because any property access whose final segment is `companyId`, `tenantId`, or `currentCompanyId` is accepted in `apps/web/tools/audit-tanstack-keys.mjs:101`, including values unrelated to the active company store.

# Diff to the work (if APPROVE-WITH-MINOR-EDITS-APPLIED)

N/A — verdict is REQUEST-CHANGES.

# Diff to the master plan (if any)

None. The defects are implementation drift from the approved plan, not plan defects.

# Confidence gradient

| Section | Confidence | Question that would change rating |
|---|---:|---|
| 1   ScopedExists | 4/5 | Would a real `Validator::make()` + database-backed test reject cross-tenant rows and pass same-scope rows across Laravel versions? |
| 2   CrossTenantRoute + middleware | 4/5 | Will Section 3/5 scanners consume the attribute and middleware flag consistently, or is one of them redundant? |
| 3A  Gate A | 5/5 | Can the scanner be converted from regex-only string matching to AST/reflection coverage for both string and `Rule::exists()` forms? |
| 3B  Gate B | 5/5 | Can the visitor track method-local scope, local scope methods (`forCompany`, `forTenant`), and explicit cross-tenant annotations without suppressing true positives? |
| 3C  Gate C | 5/5 | Can fixture-backed scanning prove `useQueries`, `satisfies`, factories, and queryClient methods all behave default-deny? |

# Anything still missed

Verification passed: PHPStan on changed API files, Pint `--test`, Section 1/2 PHPUnit files, `vendor/bin/phpunit tests/Architecture/ --group=sweep-progress` (Gate A 59, Gate B 118), default `--testsuite=Architecture` ("No tests executed!"), `pnpm test:arch` (Gate C 847), no POS/Voucher diff, and the full Unit suite (1828 tests, 5513 assertions, 12 skipped, 176 PHPUnit deprecations). Static strict-typing grep found no new `mixed`, `: any`, or `as any` in the added files. The review did not run the full Feature suite; the prompt already identifies the existing `set_time_limit(300)` pollution as pre-existing.
