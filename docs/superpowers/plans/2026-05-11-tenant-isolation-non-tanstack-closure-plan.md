# Tenant Isolation Non-TanStack Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the non-TanStack tenant-isolation sweep residuals by resolving scanner drift, fixing the remaining Workshop code gaps, and locking the explicit open inventory rows.

**Architecture:** Code remains the source of truth; YAML carries workflow metadata only. The drift cleanup separates real production defects from scanner/source bookkeeping defects so `sweep:inventory:status --drift` becomes a meaningful final gate again.

**Tech Stack:** Laravel 12, PHPStan, PHPUnit/Pest, PhpParser sweep scanners, YAML inventory workflow.

---

## Source Report

Read first:

- `docs/superpowers/audits/2026-05-11-tenant-isolation-non-tanstack-drift-detail.md`
- `docs/superpowers/audits/2026-05-04-scanner-tax-configurations-false-positive.md`
- `docs/superpowers/audits/2026-05-04-loyalty-cross-cluster-blind-spots.md`
- `docs/superpowers/audits/2026-05-04-taxation-cross-cluster-blind-spots.md`
- `docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md`

## Files To Modify

- `docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml`: remove fixed manual rows after verifying their review docs AND non-empty `regression_test` field in inventory.
- `apps/api/app/Application/Sweep/Scanners/ManualScanner.php`: update docstring lines 22-25 to document the prune-after-fix lifecycle, OR add a `sweep:inventory:retire` command. (See Task 2.)
- `apps/api/app/Application/Sweep/Scanners/PhpPresentationExistsScanner.php`: remove `tax_configurations` (line 78) AND verify/remove `tax_rates` (line 79) per 2026-05-04-scanner-tax-configurations-false-positive.md.
- `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php`: keep `GUARDED_TABLES` (line 51, line 85) in sync with the scanner.
- `apps/api/app/Application/Sweep/Visitors/ExistsRuleVisitor.php`: extend `wrappingChainContainsTenantOrCompanyScope()` (line 220) to walk DOWN into closure callback bodies AND nested subquery builders (`whereIn(subquery)`, `whereExists`, direct `$query->where('tenant_id'|'company_id', ...)`).
- `apps/api/app/Application/Sweep/Visitors/FindCallVisitor.php`: extend to recognize `Model::where('tenant_id', ...)->find*` chains as scoped (resolves api.loyalty.006-.013).
- `apps/api/tests/Feature/Console/Sweep/*`: cover `tax_configurations`, parent-scoped `modifiers`, `Model::where->find*` chains, AND negative fixtures (closures without tenant scope; OR-branch tenant scope).
- `apps/api/app/Modules/Workshop/WorkOrder/Domain/Contracts/WorkOrderRepositoryInterface.php`: **ADD** scoped methods (`findByIdForScope`, `findForUpdateForScope`); **DO NOT** remove existing `findById(string)` / `findForUpdate(string)` in this PR — mark them `@deprecated` with a follow-up tracked. (See Task 4 for the explicit additive-only commitment.)
- `apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Persistence/EloquentWorkOrderRepository.php`: implement the new scoped methods. Keep the old ones returning the same shape they did (no in-place signature change).
- `apps/api/app/Modules/Workshop/Bundle/Domain/Contracts/BundleRepositoryInterface.php`: **ADD** scoped methods for `findByIdForScope` and `findWithComponentsAndApplicabilitiesForScope` — `EloquentBundleRepository.php:27` and `:32` are tenant-unscoped, NOT previously covered in the plan, discovered during caller audit.
- `apps/api/app/Modules/Workshop/Bundle/Infrastructure/Persistence/EloquentBundleRepository.php`: implement the new scoped methods.
- `apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/BundleExpansionAdapter.php` (lines 42, 49): replace `ServiceBundle::query()->find($bundleId)` with calls into the scoped Bundle repository.
- `apps/api/app/Modules/Workshop/WorkOrder/Application/Commands/AddBundleCommand.php`: extend DTO to carry tenant+company derived from `CompanyContext` if not already present.
- `apps/api/app/Modules/Workshop/WorkOrder/Application/Services/WorkOrderBundleService.php`: pass WorkOrder scope into bundle lookups.
- `apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/DocumentGenerationAdapter.php` (line 90): replace `Document::find($wo->quote_document_id)` with tenant+company predicate.
- `apps/api/tests/Feature/Workshop/WorkOrder/*TenantIsolationTest.php`: add regression tests at HTTP boundary (cross-tenant GET returns 404) AND at repository level (foreign-id lookup returns null).
- `apps/api/app/Modules/Loyalty/Presentation/Controllers/LoyaltyMemberController.php`: no code change required if `FindCallVisitor` enhancement lands. Otherwise see Task 5 Step 2 fallback options.
- `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`: mutate only through `sweep:inventory:*` commands.

## Task 1: Freeze And Recompute Drift Snapshot

- [ ] **Step 1: Confirm working tree and concurrent state**

Run:

```bash
pwd
git status --short
cd apps/api && php artisan sweep:inventory:verify-history
cd apps/api && php artisan sweep:inventory:status --drift || true
```

Expected:

- Current directory is `/Users/houssamr/Projects/syneriva/apps/erp`.
- `verify-history` reports `0 problem(s)`.
- `status --drift` still reports `yaml_says_fixed_code_unsafe: 65` unless another session already landed this plan.

- [ ] **Step 2: Regenerate the row-level drift list**

Use the same comparison as `SweepInventoryStatusCommand`: scanner stable keys vs YAML stable keys. Confirm the row-level report still matches `2026-05-11-tenant-isolation-non-tanstack-drift-detail.md`.

Expected: 62 manual rows, 2 `tax_configurations` false positives, 1 `modifiers` parent-scoped false positive.

## Task 2: Prune Fixed Manual Scanner Rows

- [ ] **Step 1: Verify review evidence AND regression-test coverage for the 62 fixed manual rows**

For each row to prune, the inventory entry MUST satisfy ALL of:

1. `status: fixed`
2. `review.verdict ∈ {APPROVE, APPROVE-WITH-MINOR-EDITS-APPLIED}`
3. `review.review_file` exists on disk under `docs/superpowers/reviews/`
4. `review.review_commit` resolves to a valid git SHA
5. **`regression_test` is non-empty AND the named test exists** — this is the actual anti-revert protection once the manual stub entry is gone. Without it, do not prune.

Programmatic check (do this for each candidate row):

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
python3 - <<'PY'
import yaml, sys, os, subprocess
data = yaml.safe_load(open('docs/superpowers/plans/tenant-isolation-sweep-inventory.yml'))
prune_candidates = []  # populate from drift report's manual list
fail = 0
for c in data['callsites']:
    if c['id'] not in prune_candidates: continue
    rg = c.get('regression_test') or ''
    if not rg:
        print(f'NO regression_test: {c["id"]}'); fail += 1; continue
    # extract file path before ::
    f = rg.split('::')[0]
    if not os.path.exists(f):
        print(f'regression_test file missing: {c["id"]} -> {f}'); fail += 1
sys.exit(1 if fail else 0)
PY
```

Reject any row that fails this check from the prune list — that row stays in the manual stub until regression coverage is added.

- [ ] **Step 2: Reconcile manual stub lifecycle docstring (architectural debt)**

`apps/api/app/Application/Sweep/Scanners/ManualScanner.php` lines 22-25 currently document the manual stub lifecycle as: "removed via `sweep:inventory:defer` / `sweep:inventory:resolve` workflow." This contradicts the prune-after-fix approach below.

Pick ONE:

A. **Update the docstring** to: "Manual stub entries are removed from this file once their inventory row reaches `status: fixed` AND has a non-empty `regression_test`. The inventory row is preserved as the historical record; the regression test is the anti-revert anchor. Use `sweep:inventory:defer`/`sweep:inventory:resolve` for status transitions only — file edits are now part of the close ceremony."

B. **Add `sweep:inventory:retire` command** that does both the inventory close AND atomic stub prune. Update tests under `apps/api/tests/Feature/Console/Sweep/`.

Choose A for this PR unless command-driven atomicity is explicitly desired. Document the choice in the PR body.

- [ ] **Step 3: Remove only fixed manual rows from the manual stub**

Modify:

```text
docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml
```

Remove the 62 entries listed as `scanner=manual` in the drift report. Do not remove open manual rows:

```text
api.document.043
api.document.044
api.document.045
api.workshop.005
api.workshop.006
api.workshop.007
```

Expected: `ManualScanner` no longer re-emits already-fixed manual stable keys, but still emits unresolved manual findings.

- [ ] **Step 4: Run drift**

Run:

```bash
cd apps/api && php artisan sweep:inventory:status --drift || true
```

Expected: `yaml_says_fixed_code_unsafe` drops by 62. Any remaining unsafe-fixed rows should be the 2 `tax_configurations` rows and 1 `modifiers` row.

## Task 3: Correct Scanner False Positives

- [ ] **Step 1: Remove `tax_configurations` AND verify `tax_rates` in tenant-guarded table lists**

Modify both:

```text
apps/api/app/Application/Sweep/Scanners/PhpPresentationExistsScanner.php
apps/api/tests/Architecture/TenantScopedExistsRulesTest.php
```

1a. Remove `tax_configurations` from `DEFAULT_GUARDED_TABLES` (`PhpPresentationExistsScanner.php:78`) and from `TenantScopedExistsRulesTest.php:85`. Reference: 2026-05-04-scanner-tax-configurations-false-positive.md.

1b. **Verify `tax_rates` schema** (`PhpPresentationExistsScanner.php:79`):

```bash
ls apps/api/database/migrations/*tax_rates*.php
grep -l "tax_rates" apps/api/database/migrations/*.php | head -3
# Inspect each migration: does the table have tenant_id / company_id columns?
```

Decision matrix:
- If `tax_rates` has `tenant_id` (and/or `company_id`) columns → keep guarded.
- If `tax_rates` has NO tenant/company columns (country-scoped like `tax_configurations`) → remove from guarded lists in the same edit. Document the schema finding in the PR body.

Expected: `CreateProductRequest`, `UpdateProductRequest`, `TaxConfigurationController`, and `UpdateCompanyRequest` are no longer classified as tenant-scoped violations solely because they reference a country-scoped global table.

- [ ] **Step 2: Teach `ExistsRuleVisitor` parent-scoped closures**

Modify:

```text
apps/api/app/Application/Sweep/Visitors/ExistsRuleVisitor.php
```

Current visitor at `ExistsRuleVisitor.php:220-238` (`wrappingChainContainsTenantOrCompanyScope`) only walks UP the AST through `MethodCall` ancestors and only matches a direct `->where('tenant_id'|'company_id', ...)` chain. The enhancement must:

1. **Walk INTO closure callback bodies** — when the wrapping `->where(...)` first arg is a `Closure` (not a string), descend into the Closure's `stmts` and search for `MethodCall` nodes where the method name is in `{'where', 'whereIn', 'whereExists', 'orWhere', 'orWhereIn'}` AND the first arg is a literal `'tenant_id'` or `'company_id'`.

2. **Walk INTO nested subquery builders** — when the descend finds `->whereIn($column, $subquery)` where `$subquery` is a `MethodCall` chain on `DB::table(...)` or `Model::query()`, descend into that chain's `MethodCall` nodes looking for `->where('tenant_id', ...)` AND `->where('company_id', ...)`. **Both** predicates must be present to count as fully scoped.

3. **Reject disjunctive scope** — if the only `where('tenant_id', ...)` predicate is on an `orWhere` branch (inside an `orWhere(function ($q) { ... })` callback), do NOT count it as scoped. Disjunction means the predicate is optional.

Target shape from `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptRequest.php:74-86`:

```php
Rule::exists('modifiers', 'id')->where(
    function ($query) use ($tenantId, $companyId) {
        $query->whereIn(
            'modifier_group_id',
            DB::table('modifier_groups')
                ->select('id')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
        );
    }
)
```

Keep the rule conservative: only treat the closure as scoped when literal `'tenant_id'` AND `'company_id'` string-args are found in the descend, in non-disjunctive position.

- [ ] **Step 3: Teach `FindCallVisitor` `Model::where(...)->find*` chain detection**

Modify:

```text
apps/api/app/Application/Sweep/Visitors/FindCallVisitor.php
```

Extend the visitor so a `Model::where('tenant_id', $tid)->where('company_id', $cid)->find($id)` (or `->findOrFail`, `->first`, etc.) is recognized as scoped. Walk UP from the terminal `find*` MethodCall through the preceding `where`-chain MethodCalls; if `'tenant_id'` AND `'company_id'` string args appear in the chain, mark scoped.

This resolves `api.loyalty.006-.013` (`LoyaltyMemberController` at lines 87, 124, 150, 178, 197, 217, 236, 265, 312 — all use `LoyaltyMember::where('tenant_id', $tid)->findOrFail($id)`). Without this enhancement, those rows will re-emit on every scanner run and drift will never reach 0.

Conservative rule: same as ExistsRuleVisitor — both `tenant_id` AND `company_id` predicates required UNLESS the underlying model has only one of them (e.g., `LoyaltyMember` has only `tenant_id` per `LoyaltyMemberController.php:83` comment "loyalty_members has tenant_id only (no company_id column)"). For tenant-only models, accept `where('tenant_id')->find*` as scoped if the model's table is explicitly listed in a new `TENANT_ONLY_TABLES` allowlist. Keep the allowlist tiny.

- [ ] **Step 4: Add scanner regression tests (positive AND negative fixtures)**

Add or extend sweep scanner tests covering ALL of the following:

```php
// POSITIVE: country-scoped global reference no longer guarded (after Step 1).
'default_tax_configuration_id' => ['nullable', 'uuid', 'exists:tax_configurations,id'];

// POSITIVE: parent-scoped closure/subquery.
'lines.*.modifiers.*.modifier_id' => [
    'required',
    'uuid',
    Rule::exists('modifiers', 'id')->where(function ($query) use ($tenantId, $companyId) {
        $query->whereIn(
            'modifier_group_id',
            DB::table('modifier_groups')
                ->select('id')
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
        );
    }),
];

// POSITIVE: Model::where->find* chain (Step 3).
LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($id);
WorkOrder::query()->where('tenant_id', $tid)->where('company_id', $cid)->find($id);

// NEGATIVE (must still violate): guarded direct bare exists.
'partner_id' => ['required', 'uuid', 'exists:partners,id'];

// NEGATIVE (must still violate): closure with NO tenant/company predicate.
Rule::exists('modifiers', 'id')->where(function ($query) {
    $query->where('is_active', true);
}),

// NEGATIVE (must still violate): closure where tenant_id is ONLY on an OR branch.
Rule::exists('modifiers', 'id')->where(function ($query) use ($tenantId) {
    $query->where('is_active', true)
          ->orWhere(function ($q) use ($tenantId) {
              $q->where('tenant_id', $tenantId);
          });
}),

// NEGATIVE (must still violate): unscoped Model::find or Model::findOrFail.
LoyaltyMember::find($id);
LoyaltyMember::findOrFail($id);

// NEGATIVE (must still violate): chain has where(...) but not on tenant_id/company_id.
WorkOrder::where('is_active', true)->find($id);
```

Run:

```bash
cd apps/api
vendor/bin/phpunit tests/Feature/Console/Sweep tests/Architecture/TenantScopedExistsRulesTest.php
php artisan sweep:inventory:status --drift || true
```

Expected: positive fixtures pass without violations; negative fixtures still report violations; the 3 scanner false-positive unsafe-fixed rows AND the 9 loyalty find-call rows disappear from drift.

## Task 4: Fix Real Workshop Open Callsites

**Architectural commitment: ADDITIVE ONLY.** Do not change the existing signatures of `WorkOrderRepositoryInterface::findById(string $id)` or `findForUpdate(string $id)` in this PR. Add new scoped methods alongside; deprecate the old ones with a `@deprecated` tag; track removal as a follow-up. Reason: Domain contract is published; broad in-place signature change conflates "fix tenant isolation" with "rewrite domain contract" and makes git-bisect harder.

Same commitment for `BundleRepositoryInterface`.

- [ ] **Step 0: Caller audit (must precede any code change)**

Confirm the caller set below is exhaustive before touching the interface. Run:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
grep -rn "WorkOrderRepositoryInterface\|workOrderRepo\(sitory\)\?->find\(ById\|ForUpdate\)" apps/api/app | grep -v test
grep -rn "BundleRepositoryInterface\|bundleRepository\?->find" apps/api/app | grep -v test
grep -rn "ServiceBundle::query\(\)\->find\|ServiceBundle::find" apps/api/app | grep -v test
grep -rn "Document::find(.*quote_document_id\|Document::find\$wo" apps/api/app | grep -v test
```

Verified caller set (from grep on the current main):

**WorkOrderRepository consumers (11 sites):**
- Application services: `WorkOrderLineService`, `WorkOrderBundleService`, `WorkOrderAuthoringService`, `WorkOrderTransitionService`, `WorkOrderAssignmentService`
- Infrastructure listener: `WriteDocumentVehicleContextForWorkOrderInvoice`
- Workshop controllers: `WorkOrderController`, `WorkOrderAssignmentController`, `WorkOrderTransitionController`, `WorkOrderLineController`
- Cross-module: `Workshop/Technician/Presentation/Controllers/TechnicianTimeEntryController`

**BundleRepository consumers (8 sites):**
- Application services: `BundleExpansionService`, `BundleAuthoringService`, `BundleResolutionService`
- Bundle controllers: `BundleComponentController`, `BundleApplicabilityController`, `BundleController`, `BundleExpansionController`
- Cross-module adapter: `Workshop/WorkOrder/Infrastructure/Adapters/BundleExpansionAdapter` (currently bypasses interface via `ServiceBundle::query()->find(...)` at lines 42, 49)

**Unscoped finds outside the repository pattern (workshop.006/.007):**
- `Workshop/WorkOrder/Infrastructure/Adapters/BundleExpansionAdapter.php:42, :49` — `ServiceBundle::query()->find($bundleId)` (bypasses interface entirely)
- `Workshop/WorkOrder/Infrastructure/Adapters/DocumentGenerationAdapter.php:90` — `Document::find($wo->quote_document_id)`

**Newly discovered (not in original plan, not yet in inventory):**
- `Workshop/Bundle/Infrastructure/Persistence/EloquentBundleRepository.php:27` — `ServiceBundle::query()->find($bundleId)` inside `findById(string $bundleId)`
- `Workshop/Bundle/Infrastructure/Persistence/EloquentBundleRepository.php:32` — `findWithComponentsAndApplicabilities(string $bundleId)` is also tenant-unscoped

These two repo methods are the underlying engine for the entire `BundleRepository` caller set above. Must be fixed in the same PR. Add new manual inventory rows for them (`api.workshop.008` and `api.workshop.009`) via `sweep:inventory:generate` after updating the manual stub.

If grep returns additional callers not in this list, halt and add them before proceeding.

- [ ] **Step 1: Add failing tests at TWO levels (repository AND HTTP)**

Repository-level (proves the new scoped methods work):

```php
$repo->findByIdForScope($tenantA->id, $companyA->id, $foreignWorkOrder->id) === null;
$repo->findForUpdateForScope($tenantA->id, $companyA->id, $foreignWorkOrder->id) === null;
$bundleRepo->findByIdForScope($tenantA->id, $companyA->id, $foreignBundle->id) === null;
```

HTTP-level (proves the caller migration actually closes the exfiltration path):

```php
// Tenant A token + tenant B work_order.id
$response = $this->actingAs($userA)
    ->getJson("/api/work-orders/{$foreignWorkOrder->id}");
$response->assertNotFound();  // NOT 200, NOT a leaked payload

$response = $this->actingAs($userA)
    ->postJson("/api/work-orders/{$foreignWorkOrder->id}/transitions/start", []);
$response->assertNotFound();

// Same shape for bundle endpoints and quote-from-work-order endpoints.
```

Both test layers MUST exist. Repository-level alone proves the new method works but says nothing about whether the controllers actually call it. HTTP-level closes the loop.

- [ ] **Step 2: Implement scoped repository finders (additive)**

Modify:

```text
apps/api/app/Modules/Workshop/WorkOrder/Domain/Contracts/WorkOrderRepositoryInterface.php
apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Persistence/EloquentWorkOrderRepository.php
apps/api/app/Modules/Workshop/Bundle/Domain/Contracts/BundleRepositoryInterface.php
apps/api/app/Modules/Workshop/Bundle/Infrastructure/Persistence/EloquentBundleRepository.php
```

Add to `WorkOrderRepositoryInterface`:

```php
public function findByIdForScope(string $tenantId, string $companyId, string $id): ?WorkOrder;

public function findForUpdateForScope(string $tenantId, string $companyId, string $id): ?WorkOrder;
```

Mark existing `findById(string $id)` and `findForUpdate(string $id)` with `@deprecated` PHPDoc and a follow-up tracker. DO NOT remove them in this PR.

Implementations:

```php
public function findByIdForScope(string $tenantId, string $companyId, string $id): ?WorkOrder
{
    return WorkOrder::query()
        ->where('tenant_id', $tenantId)
        ->where('company_id', $companyId)
        ->find($id);
}

public function findForUpdateForScope(string $tenantId, string $companyId, string $id): ?WorkOrder
{
    return WorkOrder::query()
        ->where('tenant_id', $tenantId)
        ->where('company_id', $companyId)
        ->whereKey($id)
        ->lockForUpdate()
        ->first();
}
```

Apply the same additive pattern to `BundleRepositoryInterface`:

```php
public function findByIdForScope(string $tenantId, string $companyId, string $bundleId): ?ServiceBundle;

public function findWithComponentsAndApplicabilitiesForScope(string $tenantId, string $companyId, string $bundleId): ?ServiceBundle;
```

- [ ] **Step 3: Update all callers (from Step 0 audit) to the scoped methods**

Update every WorkOrderRepository caller to `findByIdForScope($tenantId, $companyId, $id)` / `findForUpdateForScope(...)`. Required call sites (from Step 0 audit):

```text
WorkOrderController::show/update
WorkOrderTransitionController::requireWorkOrder
WorkOrderAssignmentController::requireWorkOrder
WorkOrderLineController::requireWorkOrder
TechnicianTimeEntryController work-order lookups
WriteDocumentVehicleContextForWorkOrderInvoice
WorkOrderTransitionService::loadForUpdate
WorkOrderBundleService::requireMutableWorkOrder
WorkOrderLineService
WorkOrderAssignmentService
WorkOrderAuthoringService
```

Update every BundleRepository caller to the scoped methods. Required call sites:

```text
BundleExpansionService
BundleAuthoringService
BundleResolutionService
BundleComponentController
BundleApplicabilityController
BundleController
BundleExpansionController
```

For HTTP layer callers: source tenant/company from `CompanyContext::requireCompany()`. For Application services: extend command DTOs to carry `tenantId`/`companyId` populated at the HTTP boundary, where missing.

For the cross-module `BundleExpansionAdapter` in WorkOrder: pass `$wo->tenant_id` and `$wo->company_id` from the WorkOrder, not from `CompanyContext` directly — the WorkOrder's anchor is the canonical scope for downstream bundle lookups.

- [ ] **Step 4: Scope bundle adapter lookups via the new scoped repo**

Modify:

```text
apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/BundleExpansionAdapter.php
apps/api/app/Modules/Workshop/WorkOrder/Application/Services/WorkOrderBundleService.php
apps/api/app/Modules/Workshop/WorkOrder/Application/Commands/AddBundleCommand.php
```

Replace the two unscoped `ServiceBundle::query()->find($bundleId)` calls (`BundleExpansionAdapter.php:42, :49`) with calls into `BundleRepositoryInterface::findByIdForScope`. Inject the interface into the adapter (it currently uses the raw model directly).

Update `pricingModeOf(string $bundleId)` and `bundleName(string $bundleId)` signatures to accept `string $tenantId, string $companyId`:

```php
public function pricingModeOf(string $tenantId, string $companyId, string $bundleId): ?BundlePricingMode
{
    $bundle = $this->bundles->findByIdForScope($tenantId, $companyId, $bundleId);
    return $bundle?->pricing_mode;
}
```

Then update `WorkOrderBundleService` to pass `$wo->tenant_id`/`$wo->company_id` into these methods.

Note: `BundleExpansionService::expandForWorkOrder()` already accepts a WorkOrder — verify it propagates scope to internal bundle resolution. If it uses the unscoped `BundleRepositoryInterface::findById`, fix that caller in Step 3.

- [ ] **Step 5: Scope quote source document lookup**

Modify:

```text
apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/DocumentGenerationAdapter.php
```

Replace:

```php
Document::find($wo->quote_document_id)
```

with:

```php
Document::query()
    ->where('tenant_id', $wo->tenant_id)
    ->where('company_id', $wo->company_id)
    ->find($wo->quote_document_id)
```

- [ ] **Step 6: Run Workshop tests and static analysis**

Run:

```bash
cd apps/api
vendor/bin/phpunit tests/Feature/Workshop/WorkOrder tests/Feature/Workshop/Bundle
vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Workshop/WorkOrder tests/Feature/Workshop
```

Expected: tests pass and PHPStan reports no errors.

## Task 5: Close Explicit Open Inventory Rows

- [ ] **Step 1: Verify document rows**

Run:

```bash
cd apps/api
vendor/bin/phpunit tests/Feature/Document/RefundResidualTenantIsolationTest.php
vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Document/Domain/Services/DraftPersistenceService.php tests/Feature/Document/RefundResidualTenantIsolationTest.php
```

Expected: document tests prove `api.document.043`, `.044`, and `.045` are already code-fixed.

- [ ] **Step 2: Verify loyalty rows (and confirm Task 3 Step 3 unblocks scanner)**

The code at `LoyaltyMemberController.php` lines 87, 124, 150, 178, 197, 217, 236, 265, 312 already uses tenant-scoped `LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($id)`. The OPEN drift on `api.loyalty.006-.013` is purely a scanner false positive: `FindCallVisitor` does not currently recognize the `where(...)->find*` chain as scoped.

Resolution path:

**Primary (preferred):** Task 3 Step 3 lands the `FindCallVisitor` enhancement. After that lands, the 9 rows are no longer in drift and the inventory rows transition to `fixed` via the normal `sweep:inventory:claim → start → submit → review` ceremony backed by existing tests.

**Fallback (only if Task 3 Step 3 cannot land in this PR):** pick ONE of the following per-callsite remediation patterns:

a. **Repository refactor**: route the `findOrFail($id)` calls through a new `LoyaltyMemberRepository::findForTenant($tenantId, $id)` interface method. The scanner's existing scoped-repository allowlist will then accept the calls. Larger change but kills the false-positive class.

b. **Explicit attribute allowlist**: add a `#[TenantScoped(reason: "where('tenant_id') chain at LoyaltyMember::query()", verified_by: "tests/...")]` attribute to each method containing the calls. The scanner already supports `#[CrossTenantRoute]` for routes; extend the visitor with a sibling `TenantScoped` attribute that takes the same shape and gates emission.

Same fallback choices apply to `api.loyalty.001, .003` (stamp card request parent-scoped closures): Task 3 Step 2 unblocks them; fallback is per-callsite annotation or refactor.

Run:

```bash
cd apps/api
vendor/bin/phpunit tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php
vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Loyalty/Presentation/Controllers/LoyaltyMemberController.php app/Modules/Loyalty/Presentation/Requests/CreateStampCardRequest.php app/Modules/Loyalty/Presentation/Requests/UpdateStampCardRequest.php tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php
php artisan sweep:inventory:status --drift || true
```

Expected: tests prove `api.loyalty.001`, `.003`, `.006-.013` are code-fixed; drift report no longer includes them once Task 3 Step 3 (or chosen fallback) lands.

- [ ] **Step 3: Verify taxation rows**

Run:

```bash
cd apps/api
vendor/bin/phpunit tests/Feature/Taxation
vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Taxation/Presentation/Controllers/TaxConfigurationController.php
```

Expected: taxation code is country-scoped. After Task 3, `tax_configurations` false positives no longer block drift.

- [ ] **Step 4: Move open rows through workflow**

Use only `sweep:inventory:*` commands. For already-fixed code rows, do not hand-edit YAML.

Rows to close or defer with explicit evidence:

```text
api.document.043-.045
api.loyalty.001, .003, .006-.013
api.taxation.007-.010
api.identity-company.001
api.workshop.005-.007
```

Expected:

```bash
cd apps/api && php artisan sweep:inventory:verify-history
```

reports `0 problem(s)`.

## Task 6: Final Gates

- [ ] **Step 1: Full drift gate**

Run:

```bash
cd apps/api && php artisan sweep:inventory:status --drift
```

Expected:

```text
yaml_says_fixed_code_unsafe: 0
unmapped_in_scanner_output:  0
```

- [ ] **Step 2: Non-TanStack open-row check**

Run a YAML status check confirming no non-TanStack rows remain in these statuses:

```text
pending
claimed
in_progress
under_review
needs_recheck
blocked
deferred
```

Expected: all non-TanStack rows are `fixed`, unless a consciously accepted final deferral is documented in the PR body.

- [ ] **Step 3: Final verification**

Run:

```bash
cd apps/api
php artisan sweep:inventory:verify-history
vendor/bin/phpunit tests/Feature/Console/Sweep tests/Architecture/TenantScopedExistsRulesTest.php tests/Feature/Workshop/WorkOrder tests/Feature/Workshop/Bundle tests/Feature/Document/RefundResidualTenantIsolationTest.php tests/Feature/Loyalty/LoyaltyTenantIsolationTest.php tests/Feature/Taxation
vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Application/Sweep app/Modules/Workshop/WorkOrder app/Modules/Document/Domain/Services/DraftPersistenceService.php app/Modules/Loyalty/Presentation app/Modules/Taxation/Presentation
```

Expected: all commands pass.

## Opus Review Prompt

```text
Review the non-TanStack tenant-isolation closure plan.

Primary artifacts:
- docs/superpowers/audits/2026-05-11-tenant-isolation-non-tanstack-drift-detail.md
- docs/superpowers/plans/2026-05-11-tenant-isolation-non-tanstack-closure-plan.md

Context:
- TanStack code scanner is already at 0; this review is for the non-TanStack residuals.
- verify-history is clean.
- drift currently reports 65 yaml_says_fixed_code_unsafe rows.
- Row-level triage says 62 are fixed manual rows still emitted by ManualScanner, 2 are known tax_configurations scanner false positives, and 1 is a parent-scoped modifiers validator false positive.
- There are also 21 explicit non-TanStack open YAML rows. Source inspection suggests document/loyalty/taxation are code-fixed or false-positive/status-behind-code, identity-company is the known country-scoped tax_configurations false positive, and workshop.005-.007 are the real remaining code fixes.

Please check:
1. Is the 65-row drift triage correct, especially the recommendation to prune fixed manual rows from tenant-isolation-sweep-manual-callsites.yml?
2. Is removing tax_configurations from tenant-guarded scanner tables the right correction, or should it become an explicit country-scoped scanner category?
3. Is enhancing ExistsRuleVisitor to recognize parent-scoped closure/subquery validators safe enough for StoreReceiptRequest::modifiers, or should this be handled with a narrower annotation/allowlist?
4. Is the Workshop plan correctly scoped, or should the repository interface avoid broad signature changes by adding scoped methods and migrating callers incrementally?
5. Are any of the 21 explicit open rows real code defects beyond workshop.005-.007?

Return APPROVE or REQUEST-CHANGES with file/line-specific concerns and any required changes before implementation.
```
