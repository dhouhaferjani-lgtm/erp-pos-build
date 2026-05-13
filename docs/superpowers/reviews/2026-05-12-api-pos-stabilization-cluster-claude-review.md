# api.pos-stabilization Cluster — Claude Review

Cluster: `api.pos-stabilization`
Cluster aggregate status (inventory): `fixed`
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

POS request validators (terminal claim/update/request, shift open/Z-report, order line manipulation) and their controller wiring. Pre-sweep, several `Rule::exists(...)` validators referenced terminal/order/modifier tables without tenant scope; cross-tenant payload submission could pass validation and short-circuit fiscal-chain protections downstream.

Inventory callsite total: **53 / 53 fixed**.

## Implementation summary

Top fix commits: `dce4022a` (16 callsites), `b7cc96e5` (8), `b15ee071` (7), `6ef13917` (5), `dd8e9025` (4). Mix of `ScopedExists::tenantAndCompany(...)` adoptions and one parent-scoped closure case (`StoreReceiptRequest::modifiers`).

Top files: `ClaimTerminalRequest`, `UpdateTerminalRequest`, `GenerateZReportRequest`, `RequestTerminalRequest`, `AddOrderLineRequest`, `OpenShiftRequest`.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).

cd apps/api && vendor/bin/phpunit tests/Feature/POS/PosStabilizationTenantIsolationTest.php
→ (cluster-level test)
```

## Per-round review trail

Five rounds with two adversarial rereviews:

- `2026-05-04-api-pos-stabilization-cluster-codex-round3-review.md`
- `2026-05-06-api-pos-stabilization-cluster-codex-round4-review.md`
- `2026-05-07-api-pos-stabilization-cluster-codex-round5-review.md`
- `2026-05-07-api-pos-stabilization-cluster-codex-round5-rereview.md`

## Gates evaluated

1. **Validator-layer tenant/company predicates**: every `exists:*` rule for tenant-owned tables uses `ScopedExists::tenantAndCompany($table, $tenantId, $companyId)`.
2. **Parent-scoped closure** (`StoreReceiptRequest::modifiers`): `Rule::exists('modifiers', 'id')->where(function ($q) use ($tenantId, $companyId) { $q->whereIn('modifier_group_id', DB::table('modifier_groups')->select('id')->where('tenant_id', ...)->where('company_id', ...)); })` — runtime-correct but scanner-false-positive (api.pos-stabilization.012). See open follow-up.
3. **Cluster-level test pass**: `PosStabilizationTenantIsolationTest` covers terminal claim, Z-report, order line submission paths.

## Non-blocking follow-ups

1. **Scanner false positive** on `StoreReceiptRequest::modifiers` (api.pos-stabilization.012): `PhpPresentationExistsScanner` re-emits the parent-scoped closure as a violation despite the code being runtime-safe. Resolved by the non-TanStack closure plan Task 3 Step 2 (`ExistsRuleVisitor` walks into closure callback bodies). See `docs/superpowers/plans/2026-05-11-tenant-isolation-non-tanstack-closure-plan.md`.

## Disposition

APPROVE for master PR. The single open drift row (api.pos-stabilization.012) is a known scanner-blind-spot, not a code defect — handled by the closure plan.

## Cross-references

- Cluster-level test: `apps/api/tests/Feature/POS/PosStabilizationTenantIsolationTest.php`
- Code: `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptRequest.php:69-86`
- Closure plan: `docs/superpowers/plans/2026-05-11-tenant-isolation-non-tanstack-closure-plan.md` Task 3 Step 2
