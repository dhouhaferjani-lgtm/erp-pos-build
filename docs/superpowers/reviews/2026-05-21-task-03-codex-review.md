# Task 03 Codex Self-Adversarial Review — POS Customer Pull Endpoint

## Scope Reviewed

- Implementation commit: `0886b1549 Phase 2.3.1: Add POS customer pull endpoint`
- Files reviewed:
  - `apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php`
  - `apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php`
  - `apps/api/app/Modules/POS/routes.php`
  - `apps/api/tests/Feature/POS/PosCustomerSyncControllerTest.php`

## Verdict

APPROVE.

No blocker or request-changes issue found.

## Attack Vectors Checked

### Cross-Tenant / Cross-Company Safety

PASS. The controller derives both scope anchors from `CompanyContext` and queries `Partner` with `tenant_id = $tenantId` and `company_id = $companyId` before type filtering. The feature test seeds a same-tenant other-company customer and an other-tenant customer; neither appears in `/api/v1/pos/customers/sync`.

### Fail-Loud vs Silent Downgrade

PASS. The endpoint gates with `Gate::authorize('pos.operate_terminal')`; missing/invalid company context is handled by existing middleware/context behavior, not by falling back to an unscoped query. `updated_since` is parsed as a Carbon timestamp; invalid values fail through parser exceptions rather than being ignored. `limit` rejects non-numeric and non-positive values with 422 and caps valid values at 100.

### Dead-Path Rebuild

PASS. The route is wired in `apps/api/app/Modules/POS/routes.php` as `GET /api/v1/pos/customers/sync`, so the controller has a live endpoint caller. The next plan task will wire the POS client pull path.

### D16 Bounded-Modules Guard

PASS. Production files depend on POS, Partner, CompanyContext, and Laravel HTTP/resource primitives only. There is no Treasury/Accounting/B2B import and no `app()`, `App::make`, or `resolve()` in production code. The only `app(PermissionRegistrar::class)` occurrence is in the feature test setup, matching existing POS permission-test patterns.

### Contract Drift

PASS. The endpoint returns the Task 2 mirror fields: `id`, `tenant_id`, `company_id`, `name`, `phone`, `email`, `tax_number`, `customer_category`, `receivable_balance`, `credit_balance`, `balance_updated_at`, `is_active`, `sync_version`, `updated_at`, and `synced_at`. `tax_number` maps from server `Partner::vat_number`, and customer/both partner types are included while supplier-only rows are excluded.

### Test Matrix Completeness

PASS for Task 3. Tests cover:

- Current tenant/company customers only.
- `PartnerType::Customer` and `PartnerType::Both` included.
- `PartnerType::Supplier` excluded.
- Same-tenant other-company row excluded.
- Other-tenant row excluded.
- `updated_since` cursor uses `updated_at > cursor`.
- Balance/contact/category/tax/active/sync fields serialized.

### Skip-Citation / Per-Method Skips

PASS. No skips added.

## Verification Evidence

- Red test observed first: focused PHPUnit failed with three 404s before route/controller implementation.
- Focused backend test: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/POS/PosCustomerSyncControllerTest.php` — 3 tests, 24 assertions.
- PHPStan L8 on touched backend files — no errors.
- Pint on touched backend files — pass after import-order formatter run.
- Full backend Fiscal/POS suite with explicit test `APP_KEY` — 1090 tests, 3649 assertions, 16 deprecations, 107 skipped, 2 incomplete.
- Full POS test suite — 154 files, 1397 tests passed.
- POS lint — passed with 42 pre-existing warnings outside Task 3.
- POS typecheck — passed.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh` — passed.
- `bash apps/pos/scripts/check-pass-2b-pending.sh` — passed.
- `git diff --check` — passed.
