# T5 Owner Reporting MVP Adversarial Review

Reviewer: adversarial subagent using current available model. The user requested Opus; no Opus model override was available in this Codex session.

## Findings

### P0: `dashboard.owner` is not seeded or granted

The new routes require `can:dashboard.owner`, but the seeders did not create that permission or assign it to intended owner/admin roles. Tests masked this by creating the permission inline.

Resolution: fixed. Added `dashboard.owner` to both permission seeders and assigned it to manager/admin role sets. Admin receives all permissions via `Permission::all()`.

### P0: Location/company permission filtering is not enforced

`OwnerReportScope` originally allowed the active company plus child companies and returned every location in scope, ignoring `allowed_location_ids`.

Resolution: fixed. `OwnerReportScope::locationIds()` now intersects locations with active `UserCompanyMembership::allowed_location_ids`, rejects crafted out-of-scope location IDs, and preserves root-owner child-company visibility via the active root membership fallback. Added regression coverage.

### P0: Training receipts are included in sales/payment reports

Receipt-backed reports filtered voided receipts but not `training_flag`.

Resolution: fixed. Sales by location, top SKUs, revenue by category, and payment method breakdown now filter `pos_receipts.training_flag = false`. Added a negative training receipt assertion.

### P1: Frontend filter acceptance is incomplete

The MVP has date inputs and granularity, but does not yet implement preset date ranges, company/location multi-selects, or searchable location behavior for 30 locations.

Resolution: deferred. Backend endpoint contracts support `company_ids[]` and `location_ids[]`; frontend multi-select data-source wiring remains a follow-up.

### P1: Required widget interactions are incomplete

Top SKUs lacked a revenue/quantity toggle, payment methods lacked amount/percentage mode, and category revenue lacked click drill-down.

Resolution: partially fixed. Added Top SKU revenue/quantity toggle and payment amount/percentage mode. Category SKU drill-down remains a follow-up because no category-detail endpoint exists in the T5 backend surface.

### P1: Cash variance severity ignores configured tolerance

Cash reconciliation hardcoded variance severity thresholds instead of using company fraud settings.

Resolution: fixed. Cash reconciliation now joins `company_fraud_settings` and classifies variance against configured over/under soft and hard thresholds, with defaults when settings are absent. Added regression coverage.

### P2: Frontend manually duplicates generated DTO types

`ownerReportsApi.ts` redefines DTO interfaces even though `typescript:transform` generated matching backend DTOs.

Resolution: fixed. `ownerReportsApi.ts` now aliases `App.Modules.Accounting.Application.DTOs.Reports.*Data` generated types for all response DTOs.

## Missing Acceptance Items After Fixes

- Frontend date presets.
- Frontend company/location multi-select filters, including searchable behavior for high location counts.
- Category-to-SKU drill-down.
- Seeded role integration test for `dashboard.owner`.
- Tenant boundary test beyond company/location membership tests.
- 10k receipts/month + 30 locations performance assertion.

## Verification

- `php artisan test tests/Feature/Accounting/OwnerReportingTest.php`
- `./vendor/bin/phpstan analyse app/Modules/Accounting/Application/Services/Reports app/Modules/Accounting/Application/DTOs/Reports app/Modules/Accounting/Presentation/Controllers/ReportsController.php app/Modules/Accounting/Presentation/Requests --level=8 --no-progress`
- `pnpm --filter @autoerp/web test -- owner-dashboard`
- `pnpm --filter @autoerp/web typecheck`

Full `./scripts/preflight.sh` is blocked by unrelated baseline failures observed before frontend checks: `Tests\Unit\POS\Fiscal\ReceiptQrTokenSignerTest`, `Tests\Feature\ExampleTest`, and two password-reset assertions in `Tests\Feature\Identity\UserManagement\UserActionsTest`.
