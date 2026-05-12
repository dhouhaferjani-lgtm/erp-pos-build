# Non-TanStack Tenant-Isolation Closure Plan - Codex Adversary Review

Review target: `6c16b6f17fc9c1f7445a93410a5701d4f9caebca`

Plan: `docs/superpowers/plans/2026-05-11-tenant-isolation-non-tanstack-closure-plan.md`

Audit basis: `docs/superpowers/audits/2026-05-11-tenant-isolation-non-tanstack-drift-detail.md`

Verdict: REQUEST-CHANGES

## Verification Run

- `cd apps/api && php artisan sweep:inventory:verify-history`
  - `verified 6270 event(s) across 1205 callsite(s); 0 problem(s).`
- `cd apps/api && php artisan sweep:inventory:status --drift`
  - `yaml_says_fixed_code_unsafe: 65`
  - `code_safe_yaml_pending:      0`
  - `unmapped_in_scanner_output:  0`
- Parsed inventory non-TanStack open rows: 21.
- Re-ran all four Task 4 Step 0 grep commands and additional broader `rg` probes across Workshop and Vehicle.
- Verified the cited plan/source line references below against the current checkout.

## Findings

### 1. Task 4 caller audit is not exhaustive: Vehicle mileage listener is missing

Plan lines 302-309 claim the WorkOrderRepository consumer set is 11 sites and does not include the Vehicle listener. Re-running the plan's first grep command finds an additional non-test production caller:

```text
apps/api/app/Modules/Vehicle/Infrastructure/Listeners/WriteMileageReadingFromWorkOrderCompleted.php:10:use App\Modules\Workshop\WorkOrder\Domain\Contracts\WorkOrderRepositoryInterface;
apps/api/app/Modules/Vehicle/Infrastructure/Listeners/WriteMileageReadingFromWorkOrderCompleted.php:26:        private WorkOrderRepositoryInterface $workOrders,
```

The actual unscoped lookup is at `apps/api/app/Modules/Vehicle/Infrastructure/Listeners/WriteMileageReadingFromWorkOrderCompleted.php:35`:

```php
$workOrder = $this->workOrders->findById($event->work_order_id);
```

This is not a trivial "add it to the list" miss. `WorkOrderCompleted` is explicitly signature-locked and carries only `work_order_id`, `completion_mileage`, and `completed_at` at `apps/api/app/Modules/Workshop/WorkOrder/Domain/Events/WorkOrderCompleted.php:16-23`. It does not carry tenant/company, so the plan's proposed `findByIdForScope($tenantId, $companyId, $id)` migration cannot be applied to this caller as written.

Required change: add this caller to Task 4 and specify the safe migration strategy. Either introduce a justified event-anchored lookup pattern with tests, or define a compatible way to obtain authoritative tenant/company without violating the locked event contract. Do not leave it to implementation inference.

### 2. Technician time entries can still persist a foreign `work_order_id`

Plan line 414 says to update `TechnicianTimeEntryController work-order lookups`, but that is not enough to close the actual path. The controller accepts `work_order_id` from the request body, checks only whether the referenced work order is locked, and then persists the supplied ID.

Evidence:

- `StoreTimeEntryRequest` validates `work_order_id` as only `nullable|uuid` at `apps/api/app/Modules/Workshop/Technician/Presentation/Requests/StoreTimeEntryRequest.php:31`.
- `UpdateTimeEntryRequest` validates it as only `sometimes|nullable|uuid` at `apps/api/app/Modules/Workshop/Technician/Presentation/Requests/UpdateTimeEntryRequest.php:24`.
- `TechnicianTimeEntryController::store` reads the body value at `apps/api/app/Modules/Workshop/Technician/Presentation/Controllers/TechnicianTimeEntryController.php:141-144`, then writes it directly at line 159.
- `isWorkOrderLocked()` returns `false` when lookup returns null at lines 256-261.
- `lookupStatus()` currently performs the unscoped lookup at line 274.

If implementation only changes `lookupStatus()` to `findByIdForScope(...)`, a tenant-B work order submitted by tenant A will resolve to null, `isWorkOrderLocked()` will return false, and tenant A's time entry will still be created or updated with tenant B's `work_order_id`.

Required change: Task 4 must require a scoped validation/resolution step before create/update. A cross-tenant `work_order_id` in the time-entry body must be rejected or normalized to null by an explicit, tested rule. Add HTTP tests for both `POST /api/v1/workshop/technicians/{technicianId}/time-entries` and `PATCH /api/v1/workshop/technicians/{technicianId}/time-entries/{timeEntryId}`.

### 3. HTTP coverage list is too vague and includes non-existent routes

Task 4 Step 1 uses example routes at plan lines 340-347:

```text
/api/work-orders/{id}
/api/work-orders/{id}/transitions/start
```

Those routes do not match the current route files. The real WorkOrder routes are under `/api/v1/workshop` at `apps/api/app/Modules/Workshop/WorkOrder/Presentation/routes.php:25-49`; transition is `POST work-orders/{id}/transition`, not `/transitions/start`.

The route audit also needs to enumerate every endpoint that accepts a WorkOrder or Bundle ID:

- WorkOrder route-param paths at `WorkOrder/Presentation/routes.php:30-49`.
- Bundle route-param paths at `Bundle/Presentation/routes.php:21-31`.
- Technician time-entry body `work_order_id` paths at `Technician/Presentation/routes.php:81-84`.

Required change: replace the placeholder "same shape" requirement at plan line 349 with an explicit endpoint matrix and expected response for foreign IDs. Include both URL-segment IDs and body-supplied IDs.

### 4. Manual prune regression-test gate is incorrectly implemented

Plan lines 73-79 require the named regression test to exist, but the embedded checker at lines 85-99 only checks whether the file path before `::` exists. It does not verify the method/function name. It also runs from the repo root while API inventory paths are relative to `apps/api`, so the checker falsely reports normal API tests as missing.

I spot-checked five rows:

```text
api.compliance.006 -> tests/Feature/Compliance/ComplianceCrossTenantHardeningTest.php::test_export_jet_ignores_cross_tenant_body_company_id
api.catalog.027 -> tests/Feature/Catalog/CatalogTenantIsolationTest.php::test_show_product_rejects_cross_tenant_id
api.broadcast-channels.001 -> tests/Architecture/BroadcastChannelTenantContextTest.php::test_every_broadcast_channel_is_tenant_classified
api.platform-integration.001 -> tests/Feature/PlatformIntegration/OutboundHttpTenantTaggingTest.php::test_platform_http_client_get_attaches_tenant_and_company_headers
api.auth-permissions.001 -> tests/Feature/Identity/AuthPermissionsTenantIsolationTest.php::test_tenant_suspension_revokes_all_user_tokens
```

All five file paths are absent from repo root but present under `apps/api/`. Four named methods exist under `apps/api`; one does not. For `api.broadcast-channels.001`, the file only contains:

```text
apps/api/tests/Architecture/BroadcastChannelTenantContextTest.php:136:    public function test_every_broadcast_channel_in_routes_channels_php_is_tenant_classified(): void
```

There is no `test_every_broadcast_channel_is_tenant_classified` method.

Required change: update the gate to resolve API-relative paths correctly and verify the symbol after `::`. Rows with stale method names must not be pruned until the inventory metadata is corrected or the named regression is added.

### 5. FindCallVisitor acceptance is under-specified for unsafe value sources

The current visitor accepts any chain containing a literal `where('tenant_id'| 'company_id', ...)` as scoped at `apps/api/app/Application/Sweep/Visitors/FindCallVisitor.php:258-285`. The revised plan says to require both tenant and company for generic `where(...)->find*` chains, but it still treats literal column-name detection as the core proof.

That is not sufficient for the risk cases in the review prompt:

- `Model::where('tenant_id', request('tenant_id'))->where('company_id', $companyId)->find($id)` looks scoped but is user-controlled.
- `Model::where('tenant_id', null)->where('company_id', $companyId)->find($id)` looks scoped but can match nullable/global rows in schemas that allow them.
- `Model::where('tenant_id', $otherModel->tenant_id)->where('company_id', $companyId)->find($id)` looks scoped but may be anchored to the wrong tenant.

The current AST strategy has no value-source proof that the tenant/company operands came from `CompanyContext`, auth context, an already-scoped aggregate, or an immutable event with tenant/company. The plan's proposed tests do not include these negative fixtures.

Required change: either keep this enhancement conservative by requiring a value-source allowlist for accepted `where(...)->find*` chains, or explicitly state that scanner acceptance is not enough to close inventory rows without endpoint/repository tests. Add negative fixtures for request-sourced, null, and foreign-model tenant operands.

### 6. ExistsRuleVisitor closure enhancement needs stricter disjunction semantics

Task 3 Step 2 proposes walking into closure callbacks and nested subqueries. For the specific prompt cases:

- A closure with a variable named `tenant_id` but no `where()` call should be rejected by a method-call based visitor. That is safe.
- `whereRaw('tenant_id = ?', [...])` will be invisible if only `where`, `whereIn`, `whereExists`, `orWhere`, and `orWhereIn` are recognized. That is a safe false reject, but the plan should document it as unsupported.
- `$query->scopedToTenant($tid)` will also be invisible without a helper allowlist. That is a safe false reject.

The actual risk is disjunction. Plan line 185 includes `orWhere`/`orWhereIn` in the recognized method set, while line 189 says to reject disjunctive scope only if "only tenant_id predicate is on orWhere branch." A visitor that counts both tenant and company inside an `orWhere` branch can falsely approve a predicate that is not globally constraining the `exists` rule.

Required change: define "non-disjunctive" precisely in the AST rule and add negative fixtures where tenant/company predicates appear inside `orWhere` branches. Treat `whereRaw` and helper scopes as explicit unsupported false rejects unless a separate allowlist is designed.

### 7. `tax_rates` should be removed from guarded tables unless a real scoped table exists

Task 3 Step 1b correctly says to inspect the `tax_rates` migration and remove it if it lacks tenant/company. The current code has guarded `tax_rates` entries at:

- `apps/api/app/Application/Sweep/Scanners/PhpPresentationExistsScanner.php:79`
- `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php:86`

But the migration present in this checkout is `apps/api/database/migrations/2025_12_01_192545_create_country_tax_rates_table.php`, creating `country_tax_rates`, not `tax_rates`. It has `country_code` and no tenant/company columns. I found no `tax_rates` table migration.

Spot checks on other guarded tables:

- `accounts`: tenant-scoped.
- `withholding_certificates`: tenant/company-scoped.
- `workshop_work_orders`: tenant/company-scoped.
- `modifiers`: no tenant/company, but known parent-scoped through `modifier_groups`.

Required change: make the plan explicit that `tax_rates` is a stale/nonexistent guarded table entry and must be removed from both the scanner and the architecture test unless a real `tax_rates` migration exists elsewhere.

### 8. `#[TenantScoped]` fallback has no codebase precedent

The fallback at plan lines 522-528 proposes a new `#[TenantScoped(...)]` attribute. I found no existing `TenantScoped` attribute; the closest precedent is `#[CrossTenantRoute]`.

This is acceptable only as a designed fallback, not a quick escape hatch. It requires an attribute class, visitor support, regression tests, and a policy for where the attribute is allowed. Otherwise it can become a broad scanner suppression mechanism.

Required change: if this fallback remains in the plan, add concrete file/test tasks and constraints. If not, remove it and keep the repository-refactor fallback only.

## Section Answers

1. Caller audit completeness: failed. The plan missed `WriteMileageReadingFromWorkOrderCompleted`, and the missed caller has no tenant/company payload available through the current event signature.
2. FindCallVisitor risk: failed. Literal column-name detection cannot prove value source; add negative fixtures and either a value-source allowlist or a policy that scanner acceptance alone cannot close rows.
3. ExistsRuleVisitor risk: request changes. The intended false rejects are safe, but disjunctive closure semantics are underspecified.
4. Additive interface strategy: no legitimate super-admin unscoped Workshop caller found. The deprecated methods probably will not flood current PHPStan because `apps/api/phpstan.neon` does not enable a deprecation rule, but event-driven callers need an explicit strategy.
5. Manual stub pruning: failed. The checker uses the wrong path base and does not verify the method after `::`; one sampled inventory row points to a stale method name.
6. `tax_rates` schema: failed. Current migration is `country_tax_rates`, not `tax_rates`, and it has no tenant/company columns.
7. HTTP test coverage: failed. The example routes are wrong, endpoint coverage is not enumerated, and body-supplied `work_order_id` in Technician time entries is missed.
8. Loyalty fallback escape hatch: request changes if kept. `#[TenantScoped]` has no existing precedent and needs full design/test tasks.
