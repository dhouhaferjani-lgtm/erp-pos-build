# `api.accounting` cluster residuals — round-3 follow-up

Date: 2026-05-05
Branch: `feat/tenant-isolation-sweep-execution`
Trigger: Codex round-2 review of `api.accounting` reassigned-callsite remediation
(`docs/superpowers/reviews/2026-05-04-api-accounting-reassigned-cluster-codex-review.md`).

This doc captures same-cluster and adjacent residuals that Codex round-1
flagged as **out-of-scope for the round-2 commit** but that should be
swept in a future `api.accounting` round-3 (or a dedicated chart-of-
accounts isolation pass). Round-2 closed only Codex Findings 1+2 against
the recursive parent walk in `ExpenseCategoryController::wouldCreateCircularReference`.

## Same-cluster residuals (`api.accounting`)

### A1. `CreateAccountRequest::rules` parent-account validator is tenant-only

File: `apps/api/app/Modules/Accounting/Presentation/Requests/CreateAccountRequest.php:42`

```php
'parent_id' => [
    'nullable',
    'uuid',
    Rule::exists('accounts', 'id')->where('tenant_id', $tenantId),
],
```

`accounts` carries both `tenant_id` and `company_id`. The validator only
pins `tenant_id`. A multi-company tenant could attach a child account
under a foreign-company chart-of-accounts root via this endpoint.

Expected fix: `ScopedExists::tenantAndCompany('accounts', 'id', $tenantId, $companyId)`
using the controller's `CompanyContext`.

### A2. `UpdateAccountRequest::rules` parent-account validator is tenant-only

File: `apps/api/app/Modules/Accounting/Presentation/Requests/UpdateAccountRequest.php:33`

Same shape as A1 — `Rule::exists('accounts', 'id')->where('tenant_id', $tenantId)`
with no `company_id` predicate. Same exploit shape (cross-company chart
re-parent).

Expected fix: same as A1.

### A3. `ExpenseController` route-id reads scope by `company_id` only

File: `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php`

Methods affected: `show`, `update`, `destroy`, `post`. Each fetches the
target document via `where('company_id', $companyId)->where(...)` without
a `tenant_id` predicate. Defense-in-depth gap: the `company_id` predicate
already pins one half of the cluster invariant, but the hardened route-
anchor invariant established by Treasury R3 Finding 14 requires both.

Expected fix: add `where('tenant_id', $tenantId)` to each route-anchored
chain (or migrate to a `forTenantAndCompany` model scope).

### A4. `ExpenseCategoryController` route-id reads scope by `company_id` only

File: `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseCategoryController.php`

Methods affected: `show`, `update`, `destroy`. Same shape as A3. The
recursive helper itself was hardened in round-2; the surrounding route-
id reads were not. Same expected fix.

## Adjacent-module residuals (different cluster)

### B1. `Treasury/PaymentRepositoryController` `gl_account_id` validators

File: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php`

The `gl_account_id` exists-validators against `accounts` are flagged by
hostile grep but live in the Treasury module / `api.treasury` cluster.
Codex round-1 second-layer noted no matching inventory rows exist for
these validators yet (treasury rows `api.treasury.056/.057` cover
`responsible_user_id`, not `gl_account_id`).

Expected fix: convert each `Rule::exists('accounts', 'id', ...)` chain
to `ScopedExists::tenantAndCompany`. Out of scope for `api.accounting`
round-3; should be picked up by a future `api.treasury` cluster sweep
(or a chart-of-accounts cross-cluster pass).

## Why deferred

Codex round-1 explicitly scope-flagged each of A1–A4 and B1 as outside
the six reassigned Expense callsites that round-2 was scoped to. The
round-2 commit kept the surface tight on Codex's two REQUIRED findings
to keep the review chain narrow.

These residuals do **not** block the round-2 verdict. They are tracked
here so a future Opus or Codex pass can re-grep and ship them as a
batched commit, alongside any other `tenant-only scope, no company_id`
residuals across the cluster.

## How to discover the rest

The hostile-grep recipe in `2026-05-04-bare-where-scanner-gap.md`
applies cleanly to this module. From `apps/erp/apps/api/`:

```bash
/usr/bin/grep -rnE -- "->where\([\"'](id|parent_id|account_id|partner_id)[\"']" \
  app/Modules/Accounting/ app/Modules/Expense/ | grep -v '/tests/'
```

For each match, inspect ±20 lines for sibling `where('tenant_id', ...)`
AND `where('company_id', ...)` predicates. Anything missing both is a
candidate row for the round-3 inventory regenerate.

## References

- Codex round-2 review (round-1 second-layer findings):
  `docs/superpowers/reviews/2026-05-04-api-accounting-reassigned-cluster-codex-review.md`
- Round-2 fix commit: see git log on `feat/tenant-isolation-sweep-execution`
  (commit titled `fix(expense): scope wouldCreateCircularReference recursive parent walk by tenant+company`).
- Existing audit gap doc: `docs/superpowers/audits/2026-05-04-bare-where-scanner-gap.md`
