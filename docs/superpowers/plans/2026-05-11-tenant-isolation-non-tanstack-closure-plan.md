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

Programmatic check (do this for each candidate row). The checker MUST resolve paths relative to `apps/api/` (inventory regression_test paths are stored without the `apps/api/` prefix) AND verify the method/function name after `::` actually exists in the file. The earlier draft of this checker (commit 6c16b6f1) was broken on both counts per Codex adversary review (Finding 4); inventory metadata for `api.broadcast-channels.001` was found to point to a stale method name `test_every_broadcast_channel_is_tenant_classified` when the actual method is `test_every_broadcast_channel_in_routes_channels_php_is_tenant_classified`.

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
python3 - <<'PY'
import yaml, sys, os, re, subprocess
data = yaml.safe_load(open('docs/superpowers/plans/tenant-isolation-sweep-inventory.yml'))
prune_candidates = []  # populate from drift report's manual list
fail = 0
for c in data['callsites']:
    if c['id'] not in prune_candidates: continue
    rg = (c.get('regression_test') or '').strip()
    if not rg:
        print(f'NO regression_test: {c["id"]}'); fail += 1; continue
    # Inventory stores paths relative to apps/api/ for API tests.
    if '::' in rg:
        path, symbol = rg.split('::', 1)
    else:
        path, symbol = rg, None
    # Try apps/api/ prefix first, then bare path
    candidates = [os.path.join('apps/api', path), path]
    resolved = next((p for p in candidates if os.path.exists(p)), None)
    if not resolved:
        print(f'regression_test file missing: {c["id"]} -> tried {candidates}'); fail += 1; continue
    if symbol:
        # PHP method/function pattern: `function <symbol>(` or `function <symbol>:(` (for Pest tests use 'it(' / 'test(' string literal)
        with open(resolved) as fh:
            content = fh.read()
        pat_method = re.compile(r'\bfunction\s+' + re.escape(symbol) + r'\s*\(')
        pat_pest_it = re.compile(r"\bit\s*\(\s*['\"]" + re.escape(symbol) + r"['\"]")
        pat_pest_test = re.compile(r"\btest\s*\(\s*['\"]" + re.escape(symbol) + r"['\"]")
        if not (pat_method.search(content) or pat_pest_it.search(content) or pat_pest_test.search(content)):
            print(f'regression_test symbol missing: {c["id"]} -> {resolved}::{symbol}'); fail += 1; continue
    # Optionally: run the test in isolation and require pass.
    # vendor/bin/phpunit --filter="^<symbol>$" <path>
sys.exit(1 if fail else 0)
PY
```

Reject any row that fails this check from the prune list — that row stays in the manual stub until regression coverage is added OR the inventory metadata is corrected to point at a real test.

**Inventory-metadata-fix sub-task**: any row whose `regression_test` field points to a stale method name (e.g., the `api.broadcast-channels.001` finding) must be updated via `sweep:inventory:*` workflow (or by editing the inventory through the same canonical mutation path) BEFORE prune. Do not silently change the metadata without a recorded history event.

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

1b. **Remove `tax_rates` from guarded lists unconditionally** (`PhpPresentationExistsScanner.php:79` and `TenantScopedExistsRulesTest.php:86`).

Verified during Codex adversary review (2026-05-12): no `tax_rates` migration exists in `apps/api/database/migrations/`. Actual migrations are:

- `2025_12_01_192545_create_country_tax_rates_table.php` (creates `country_tax_rates`, country-scoped, no tenant_id/company_id)
- `2025_12_30_200000_set_company_default_tax_rates.php` (sets defaults on `companies`, not a tax-rates table)

`tax_rates` in `DEFAULT_GUARDED_TABLES` is a stale reference to a non-existent table. Drop it from both the scanner and the architecture test. Document in PR body that this entry corresponded to a planned-but-never-shipped table.

Expected: `CreateProductRequest`, `UpdateProductRequest`, `TaxConfigurationController`, and `UpdateCompanyRequest` are no longer classified as tenant-scoped violations solely because they reference a country-scoped global table.

- [ ] **Step 2: Teach `ExistsRuleVisitor` parent-scoped closures**

Modify:

```text
apps/api/app/Application/Sweep/Visitors/ExistsRuleVisitor.php
```

Current visitor at `ExistsRuleVisitor.php:220-238` (`wrappingChainContainsTenantOrCompanyScope`) only walks UP the AST through `MethodCall` ancestors and only matches a direct `->where('tenant_id'|'company_id', ...)` chain. The enhancement must:

1. **Walk INTO closure callback bodies (CONJUNCTIVE positions ONLY)** — when the wrapping `->where(...)` first arg is a `Closure` (not a string), descend into the Closure's `stmts`. Recognize tenant/company predicates ONLY when they appear in a `MethodCall` whose method name is in the CONJUNCTIVE set: `{'where', 'whereIn', 'whereExists'}`. Do NOT recognize them inside `orWhere`, `orWhereIn`, `orWhereExists`, `orWhereNot`, or any closure passed as an argument to one of those disjunctive methods. (The earlier draft listed `orWhere`/`orWhereIn` in the recognized set AND said to reject disjunctive scope — contradictory; this revision removes `orWhere*` from the recognized set entirely.)

2. **Walk INTO nested subquery builders** — when the descend finds `->whereIn($column, $subquery)` or `->whereExists($subquery)` and `$subquery` is a `MethodCall` chain on `DB::table(...)` or `Model::query()`, descend into that chain's `MethodCall` nodes looking for `->where('tenant_id', ...)` AND `->where('company_id', ...)` (or model-appropriate single-column scope per the value-source rule in Step 3). The subquery descent uses the same CONJUNCTIVE-only rule.

3. **Disjunctive-position rejection (precise definition)**: a predicate is in "disjunctive position" iff its enclosing `MethodCall` chain contains an ancestor `MethodCall` whose method name starts with `or` (case-insensitive) OR is `orWhere`/`orWhereIn`/`orWhereExists`/`orWhereNot`, OR it appears inside a Closure passed as an argument to such a call. Disjunctive-position predicates do NOT count toward scope proof.

4. **Unsupported forms (documented false-rejects)**:
   - `whereRaw('tenant_id = ?', [...])` — invisible to AST (the column name is inside a SQL string). Documented as unsupported; affected callsites must use the structured `where('tenant_id', ...)` form OR rely on the `Task 5 Step 2` fallback options.
   - `$query->scopedToTenant($tid)` (helper macros / model scopes) — invisible without a helper allowlist. Documented as unsupported; same fallback.
   - These are SAFE false-rejects: the scanner over-reports rather than under-reports.

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

- [ ] **Step 3: Teach `FindCallVisitor` `Model::where(...)->find*` chain detection WITH value-source allowlist**

Modify:

```text
apps/api/app/Application/Sweep/Visitors/FindCallVisitor.php
```

Extend the visitor so a `Model::where('tenant_id', $tid)->where('company_id', $cid)->find($id)` (or `->findOrFail`, `->first`, etc.) is recognized as scoped. Walk UP from the terminal `find*` MethodCall through the preceding `where`-chain MethodCalls; require ALL of:

1. **Column literals**: `'tenant_id'` AND `'company_id'` string args appear in the chain (or only `'tenant_id'` if the model is in `TENANT_ONLY_TABLES`).

2. **Value-source allowlist** (NEW — per Codex Finding 5): the BOUND VALUE (second arg of each accepted `where(...)` call) MUST be one of:

   a. A `Variable` whose name is `tenantId`, `companyId`, `$tenantId`, or `$companyId` (idiomatic local variables from `CompanyContext`-style resolution). AST `Node\Expr\Variable->name` check.

   b. A `PropertyFetch` or `MethodCall` chain whose root is `$this` AND ends in one of: `requireCompany()->tenant_id`, `requireCompany()->id`, `requireCompanyId()`, `currentCompany()->tenant_id`, `currentCompany()->id`. Match via a small static suffix-pattern list embedded in the visitor.

   c. A `MethodCall` chain whose root is a parameter named `$context` AND has shape `$context->tenant_id` or `$context->company_id` (DTO-passed contexts).

   d. A `MethodCall` whose name is `tenantId()`, `companyId()`, or `requireCompanyId()` on `$this` or a service property (e.g., `$this->companyContext->requireCompanyId()`).

   e. A direct `PropertyFetch` of `tenant_id`/`company_id` on an **already-scoped aggregate** the calling method received as an argument (e.g., `$workOrder->tenant_id` when `$workOrder` is a parameter — the assumption being the caller scoped it). This is a relaxation of the strict source check; the safety relies on caller-discipline. Documented as a known limitation.

3. **Reject unsafe operand patterns** (explicit AST rejection — must be in test fixtures):

   - `request(...)`, `Request::get(...)`, `$request->input(...)`, `$request->tenant_id` — user-controlled.
   - `null` literal — would match nullable global rows.
   - `$otherModel->tenant_id` for `$otherModel` NOT in the calling method's parameter list — foreign-tenant anchoring without a caller-discipline assumption.
   - String literals (`Model::where('tenant_id', 'some-uuid')->find(...)`) — almost certainly wrong.

This resolves `api.loyalty.006-.013` (`LoyaltyMemberController` at lines 87, 124, 150, 178, 197, 217, 236, 265, 312 — all use `LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($id)` where `$tenantId = $this->companyContext->requireCompany()->tenant_id` matches the allowlist).

`TENANT_ONLY_TABLES` allowlist (introduced in this PR — keep small): `loyalty_members` (only model documented at `LoyaltyMemberController.php:83` with the canonical "tenant_id only" comment). Adding to this list requires a documented schema reason.

**Policy fallback** (per Codex Finding 5): if the value-source allowlist proves too constraining and would falsely-reject more than 10% of legitimate scoped chains in this codebase, abandon the visitor enhancement for this PR and resolve `api.loyalty.006-.013` via Task 5 Step 2 fallback (repository refactor). Scanner acceptance is NOT sufficient on its own to close inventory rows — each closed row must also have an HTTP-level cross-tenant regression test passing in `LoyaltyTenantIsolationTest`.

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

// NEGATIVE (must still violate): closure where BOTH tenant_id and company_id are inside an orWhere branch.
Rule::exists('modifiers', 'id')->where(function ($query) use ($tenantId, $companyId) {
    $query->where('is_active', true)
          ->orWhere(function ($q) use ($tenantId, $companyId) {
              $q->where('tenant_id', $tenantId)->where('company_id', $companyId);
          });
}),

// NEGATIVE (must still violate): unscoped Model::find or Model::findOrFail.
LoyaltyMember::find($id);
LoyaltyMember::findOrFail($id);

// NEGATIVE (must still violate): chain has where(...) but not on tenant_id/company_id.
WorkOrder::where('is_active', true)->find($id);

// NEGATIVE (must still violate per FindCallVisitor value-source allowlist):
// user-controlled tenant operand.
LoyaltyMember::where('tenant_id', request('tenant_id'))->findOrFail($id);
LoyaltyMember::where('tenant_id', $request->input('tenant_id'))->findOrFail($id);
LoyaltyMember::where('tenant_id', $request->tenant_id)->findOrFail($id);

// NEGATIVE (must still violate): null literal tenant operand.
LoyaltyMember::where('tenant_id', null)->findOrFail($id);

// NEGATIVE (must still violate): string literal tenant operand.
LoyaltyMember::where('tenant_id', 'tenant-a-uuid')->findOrFail($id);

// NEGATIVE (must still violate): foreign-model tenant operand where the source
// model is NOT a parameter of the calling method (i.e., looked up unscopedly itself).
$other = OtherModel::find($otherId);  // unscoped
LoyaltyMember::where('tenant_id', $other->tenant_id)->findOrFail($id);

// NEGATIVE (must still violate): whereRaw-encoded tenant predicate (documented unsupported).
LoyaltyMember::whereRaw('tenant_id = ?', [$tenantId])->findOrFail($id);

// NEGATIVE (must still violate): helper-scope macro (documented unsupported).
LoyaltyMember::scopedToTenant($tenantId)->findOrFail($id);
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

**WorkOrderRepository consumers (12 sites):**
- Application services: `WorkOrderLineService`, `WorkOrderBundleService`, `WorkOrderAuthoringService`, `WorkOrderTransitionService`, `WorkOrderAssignmentService`
- Infrastructure listener: `WriteDocumentVehicleContextForWorkOrderInvoice`
- Workshop controllers: `WorkOrderController`, `WorkOrderAssignmentController`, `WorkOrderTransitionController`, `WorkOrderLineController`
- Cross-module: `Workshop/Technician/Presentation/Controllers/TechnicianTimeEntryController`
- **Cross-module: `Vehicle/Infrastructure/Listeners/WriteMileageReadingFromWorkOrderCompleted.php`** — uses `WorkOrderRepositoryInterface->findById($event->work_order_id)` at line 35. Missed in the original audit (Codex Finding 1). Migration strategy: see Step 3a below — the `WorkOrderCompleted` event signature is locked (carries only `work_order_id`, `completion_mileage`, `completed_at` per `WorkOrder/Domain/Events/WorkOrderCompleted.php:16-23`), so a different scoping pattern is required.

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

HTTP-level (proves the caller migration actually closes the exfiltration path). Use the ACTUAL route paths from `WorkOrder/Presentation/routes.php`, `Bundle/Presentation/routes.php`, and `Technician/Presentation/routes.php` — NOT made-up paths:

```php
// Tenant A token + tenant B work_order.id — actual routes under /api/v1/workshop/work-orders/...
$response = $this->actingAs($userA)
    ->getJson("/api/v1/workshop/work-orders/{$foreignWorkOrder->id}");
$response->assertNotFound();  // NOT 200, NOT a leaked payload

$response = $this->actingAs($userA)
    ->patchJson("/api/v1/workshop/work-orders/{$foreignWorkOrder->id}", $payload);
$response->assertNotFound();

$response = $this->actingAs($userA)
    ->postJson("/api/v1/workshop/work-orders/{$foreignWorkOrder->id}/approval", []);
$response->assertNotFound();
```

Required endpoint matrix (Codex Finding 3). Each row must have a cross-tenant 404/422 regression test:

| Verb | Path | ID source | Test target |
|------|------|-----------|-------------|
| GET | `/api/v1/workshop/work-orders/{id}` | URL segment | 404 |
| PATCH | `/api/v1/workshop/work-orders/{id}` | URL segment | 404 |
| POST | `/api/v1/workshop/work-orders/{id}/lines` | URL segment | 404 |
| POST | `/api/v1/workshop/work-orders/{id}/lines/bundle` | URL segment | 404 |
| PATCH | `/api/v1/workshop/work-orders/{id}/lines/{lineId}` | URL segment | 404 |
| DELETE | `/api/v1/workshop/work-orders/{id}/lines/{lineId}` | URL segment | 404 |
| PUT | `/api/v1/workshop/work-orders/{id}/lines/reorder` | URL segment | 404 |
| POST | `/api/v1/workshop/work-orders/{id}/assignments` | URL segment | 404 |
| DELETE | `/api/v1/workshop/work-orders/{id}/assignments/{assignmentId}` | URL segment | 404 |
| PUT | `/api/v1/workshop/work-orders/{id}/primary-technician` | URL segment | 404 |
| POST | `/api/v1/workshop/work-orders/{id}/approval` | URL segment | 404 |
| POST | `/api/v1/workshop/work-orders/{id}/transition` | URL segment | 404 |
| POST | `/api/v1/workshop/work-orders/{id}/cancel` | URL segment | 404 |
| POST | `/api/v1/workshop/work-orders/{id}/complete` | URL segment | 404 |
| GET | `/api/v1/workshop/bundles/{id}` | URL segment | 404 |
| PATCH | `/api/v1/workshop/bundles/{id}` | URL segment | 404 |
| DELETE | `/api/v1/workshop/bundles/{id}` | URL segment | 404 |
| GET | `/api/v1/workshop/bundles/{id}/expansion` | URL segment | 404 |
| POST | `/api/v1/workshop/bundles/{id}/components` | URL segment | 404 |
| PATCH | `/api/v1/workshop/bundles/{id}/components/{componentId}` | URL segment | 404 |
| DELETE | `/api/v1/workshop/bundles/{id}/components/{componentId}` | URL segment | 404 |
| PUT | `/api/v1/workshop/bundles/{id}/vehicle-applicabilities` | URL segment | 404 |
| POST | `/api/v1/workshop/technicians/{technicianId}/time-entries` | **body** `work_order_id` (Step 3b) | 422 |
| PATCH | `/api/v1/workshop/technicians/{technicianId}/time-entries/{timeEntryId}` | **body** `work_order_id` (Step 3b) | 422 |

Run `php artisan route:list --path=workshop` before writing tests to confirm the final endpoint set has not drifted since this plan was authored.

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

- [ ] **Step 3a: Address event-anchored callers (Vehicle listener)**

The `WorkOrderCompleted` event signature is locked at `Workshop/WorkOrder/Domain/Events/WorkOrderCompleted.php:16-23` to carry only `work_order_id`, `completion_mileage`, `completed_at` — no tenant/company payload. The Vehicle listener `WriteMileageReadingFromWorkOrderCompleted.php:35` calls `$this->workOrders->findById($event->work_order_id)` and cannot use the new `findByIdForScope($tenantId, $companyId, $id)` signature directly.

Choose ONE migration pattern (Codex implementation MUST pick and document in the PR body):

**Option A (preferred — event signature extension)**: Add `public readonly string $tenant_id` and `public readonly string $company_id` to `WorkOrderCompleted`. Event classes are immutable forever per AutoERP convention (`apps/erp/CLAUDE.md` Rule 8: "Events are Immutable Forever; create versioned replacements"), so this MUST be done as a versioned event `WorkOrderCompletedV2`. Update the dispatcher to fire V2 (carrying tenant/company derived from the WorkOrder aggregate that's already in scope at dispatch time). Keep V1 registered for serialization compatibility on already-queued jobs but route new dispatches to V2. The Vehicle listener subscribes to V2 and uses `findByIdForScope($event->tenant_id, $event->company_id, $event->work_order_id)`.

**Option B (queue-context binding)**: Document and enforce that the queue worker rebinds `TenantContext` and `CompanyContext` before invoking event listeners (Laravel's `ShouldQueue` listeners run in a worker that does not inherit the request context). The listener then reads `$this->companyContext->requireCompany()->tenant_id` instead of pulling from the event. Requires verifying that the dispatcher serializes tenant/company into the queue job (Laravel's `Queueable` trait does not do this by default; usually requires a `ShouldBeEncrypted` + custom serialization). Higher implementation risk than Option A.

**Option C (defensive load with discard)**: Listener loads via `findById($id)`, then aborts if the loaded WorkOrder's `tenant_id` doesn't match `TenantContext::current()` (if any). Treat this as defense-in-depth ONLY when Options A/B are unavailable — does not close the exfiltration class because `TenantContext::current()` may be null in queue context, leading to a no-op skip rather than a hard rejection. Document the residual risk explicitly in the PR body.

**Required test (regardless of option)**: dispatch `WorkOrderCompleted` for a foreign tenant's work_order_id from tenant-A's context. Assert the listener does NOT write a mileage reading on the foreign vehicle.

- [ ] **Step 3b: Close Technician time-entry body work_order_id leak (Codex Finding 2)**

NOT a `findById` migration — this is a NEW defect surface not in the original plan.

`StoreTimeEntryRequest.php:31` validates `work_order_id` as `nullable|uuid` only. `UpdateTimeEntryRequest.php:24` validates as `sometimes|nullable|uuid`. `TechnicianTimeEntryController::store` reads the body value at lines 141-144 and persists it directly at line 159. `isWorkOrderLocked()` at lines 256-261 returns `false` when the lookup returns null. Net effect: tenant-A submitting a tenant-B `work_order_id` results in tenant A's time entry being created/updated WITH tenant B's `work_order_id` because the unscoped `lookupStatus()` (line 274) returns null (the foreign WO isn't visible) → `isWorkOrderLocked()` returns false → persist proceeds.

Fix:

1. Modify both `StoreTimeEntryRequest.php` and `UpdateTimeEntryRequest.php` to validate `work_order_id` as a tenant-scoped exists:

   ```php
   'work_order_id' => [
       'nullable',
       'uuid',
       ScopedExists::tenantAndCompany('workshop_work_orders', $tenantId, $companyId),
   ],
   ```

   Source `$tenantId`/`$companyId` from `$this->companyContext->requireCompany()` inside `rules()`.

2. Update `TechnicianTimeEntryController::lookupStatus()` at line 274 to use `findByIdForScope($tenantId, $companyId, $id)` so a foreign WO returns null (defense-in-depth even after the validator rejects).

3. Add HTTP regression tests at:
   - `POST /api/v1/workshop/technicians/{technicianId}/time-entries` with body `work_order_id` = foreign WO → 422 (validation failure), NOT 201 with leaked persistence.
   - `PATCH /api/v1/workshop/technicians/{technicianId}/time-entries/{timeEntryId}` with body `work_order_id` = foreign WO → 422.

4. Inventory: add a new manual row `api.workshop.010` for the Technician body-validation gap. Add to `tenant-isolation-sweep-manual-callsites.yml` via the canonical workflow.

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

**Fallback (only if Task 3 Step 3 cannot land in this PR):** use the **repository refactor** pattern. The `#[TenantScoped]` attribute option proposed in the prior draft is dropped per Codex Finding 8 — no codebase precedent, would require designing an attribute class + visitor support + regression tests + scope-of-application policy, and the failure mode is "broad scanner suppression mechanism" that swallows real defects.

**Repository refactor pattern** (chosen fallback):

Introduce `LoyaltyMemberRepository::findForTenant(string $tenantId, string $id): ?LoyaltyMember` in `apps/api/app/Modules/Loyalty/Domain/Contracts/LoyaltyMemberRepositoryInterface.php` (create if it does not exist) and bind to an `EloquentLoyaltyMemberRepository` implementation. Migrate the 9 `LoyaltyMemberController` callsites at lines 87, 124, 150, 178, 197, 217, 236, 265, 312 from `LoyaltyMember::where('tenant_id', $tid)->findOrFail($id)` to `$this->members->findForTenant($tid, $id) ?? throw new ModelNotFoundException(...)`.

The scanner's existing scoped-repository pattern (per `EloquentBundleRepository::paginateForCompany` at line 49) is recognized as scoped because the scope predicate lives inside the repository, not the caller. Same pattern as Workshop Task 4 — additive interface methods, deprecate-don't-remove the legacy patterns.

Same fallback applies to `api.loyalty.001, .003` (stamp card request parent-scoped closures): Task 3 Step 2 unblocks them; if that step is descoped, refactor the stamp-card validators to call a scoped `LoyaltyRewardRepository::existsForProgramInTenant(...)` helper.

**Policy commitment**: scanner acceptance alone (whether via the FindCallVisitor enhancement OR the repository refactor) is NOT sufficient to close inventory rows. Each closed row MUST have a passing HTTP-level cross-tenant regression test in `LoyaltyTenantIsolationTest.php`.

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
