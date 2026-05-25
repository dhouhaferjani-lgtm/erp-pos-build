# Task 03 Opus-Equivalent Adversarial Review - POS Customer Pull Endpoint

## Verdict

REQUEST-CHANGES

The endpoint is live, tenant/company scoped, and returns the expected customer mirror fields. However, malformed `updated_since` inputs can silently downgrade to a broad first-page sync, which violates the locked fail-loud requirement for cursor params.

## Findings

### P1 - `updated_since` can be present but ignored, widening a delta sync

- `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:61-69`
- `apps/api/tests/Feature/POS/PosCustomerSyncControllerTest.php:106-130`

`parseUpdatedSince()` treats any non-string value or empty string as `null`:

```php
if (! is_string($value) || $value === '') {
    return null;
}
```

That means requests such as `?updated_since=` or `?updated_since[]=2026-05-21T00:00:00Z` take the full-sync path instead of failing. This is not a tenant leak because the query still scopes by `tenant_id` and `company_id`, but it is a silent cursor downgrade: a caller that intended a bounded delta can receive the first broad page, and a later cursor write can make omitted rows hard to detect. The review brief explicitly calls out "bad params must not silently widen sync"; this path does exactly that.

Recommended fix: validate query params before building the query. Missing `updated_since` can remain a full sync; present-but-empty, array, and invalid date values should return a 422. Add regression tests for at least `updated_since=` and `updated_since[]=...`. While touching this, consider making `limit` integer-only as well, because `limit=1.9` currently casts to `1` silently at `PosCustomerSyncController.php:80-89`.

## Standing-Pattern Checks

- Cross-tenant and cross-company safety: PASS. The production query derives scope from `CompanyContext::requireTenantId()` / `requireCompanyId()` and applies both `where('tenant_id', ...)` and `where('company_id', ...)` before returning rows (`PosCustomerSyncController.php:32-40`). Tests seed same-tenant other-company and other-tenant customers and assert exclusion.
- Partner type contract: PASS. `PartnerType::Customer` and `PartnerType::Both` are included; `PartnerType::Supplier` is excluded (`PosCustomerSyncController.php:40`, `PosCustomerSyncControllerTest.php:56-104`).
- Resource contract: PASS. The response includes the Task 2 mirror fields: `id`, `tenant_id`, `company_id`, `name`, `phone`, `email`, `tax_number`, `customer_category`, `receivable_balance`, `credit_balance`, `balance_updated_at`, `is_active`, `sync_version`, `updated_at`, and `synced_at` (`PosCustomerMirrorResource.php:24-40`).
- Dead-path rebuild: PASS. `GET /api/v1/pos/customers/sync` is added inside the existing authenticated POS group and named `pos.customers.sync` (`routes.php:31-96`).
- D16 bounded-modules guard: PASS. New production code depends on POS, Partner, CompanyContext, Laravel HTTP/resource primitives, and Gate only. No new production `app()`, `resolve()`, `App::make()`, Treasury, Accounting, or B2B dependency was introduced.
- Test completeness for locked happy-path/isolation scope: MOSTLY PASS. Tests cover customer/both inclusion, supplier exclusion, tenant/company isolation, `updated_since` happy path, and balance/contact/category fields. Missing coverage is the bad-parameter fail-loud path above.

## Verification Notes

- Reviewed commit: `0886b1549 Phase 2.3.1: Add POS customer pull endpoint`.
- Reviewed Codex self-review: `docs/superpowers/reviews/2026-05-21-task-03-codex-review.md`.
- Ran focused backend test:
  - `APP_KEY=base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY= ./vendor/bin/phpunit tests/Feature/POS/PosCustomerSyncControllerTest.php`
  - Result: PASS, 3 tests, 24 assertions.
- Verified route registration with `php artisan route:list --path=pos/customers/sync`; result shows `GET|HEAD api/v1/pos/customers/sync` named `pos.customers.sync`.
- Ran `git diff --check 0886b1549^..0886b1549`; no whitespace errors.
