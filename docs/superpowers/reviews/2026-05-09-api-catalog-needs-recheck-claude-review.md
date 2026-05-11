# api.catalog needs_recheck recovery — Claude adversarial review

Verdict: APPROVE

Commit reviewed: 516c6f61

Branch: feat/tenant-isolation-sweep-execution
Cluster: api.catalog (codex-owned, 4 callsites recovered from needs_recheck)
Reviewer: Claude (cross-agent reviewer per agent-recalibration §16.1; codex-owned → claude-reviewed)
Date: 2026-05-09

## Scope

Four callsites previously locked at `516c6f61` were `stale_mark`-flipped to
`needs_recheck` on 2026-05-05 due to scanner stable_key drift; the YAML note
explicitly stated `path/symbol/resource unchanged`. An atomic-mutation
transitioned them back to `under_review` anchored at the same fix_commit
`516c6f61` (no code change required — re-verification only).

| ID | File | Symbol | Disposition |
|---|---|---|---|
| api.catalog.002 | `apps/api/app/Modules/Catalog/Presentation/Requests/UpdateCompositeItemRequest.php:58` | `UpdateCompositeItemRequest::rules` | tax_configurations annotation — country-scoped global reference (structurally protected) |
| api.catalog.005 | `apps/api/app/Modules/Catalog/Presentation/Requests/StoreCompositeItemRequest.php:51` | `StoreCompositeItemRequest::rules` | mirrors api.catalog.002 |
| api.catalog.012 | `apps/api/app/Modules/Catalog/Presentation/Controllers/ModifierController.php:54-59` | `ModifierController::update` | `Modifier::whereHas('group', q.whereRaw(tenant_id).whereRaw(company_id))->findOrFail` |
| api.catalog.013 | `apps/api/app/Modules/Catalog/Presentation/Controllers/ModifierController.php:91-96` | `ModifierController::destroy` | same parent-modifier_group whereHas pattern as ::update |

## Verification

### Code state at HEAD

- **api.catalog.002:** `UpdateCompositeItemRequest.php:52-58` — annotation block (lines 52-57) accurately describes the structural disposition: `tax_configurations` is country-scoped global reference, no `tenant_id` / `company_id` columns. Cross-tenant access via `default_tax_configuration_id` requires assigning a foreign-country tax configuration whose `country_code` mismatch is rejected at the billing-flow tier. Annotation matches `2026-05-04-scanner-tax-configurations-false-positive.md` audit.
- **api.catalog.005:** `StoreCompositeItemRequest.php:50-51` — sibling annotation referencing `UpdateCompositeItemRequest comment`. Same disposition.
- **api.catalog.012:** `ModifierController.php:54-59` — `Modifier::whereHas('group', function (Builder $q) use ($company) { $q->whereRaw('tenant_id = ?', [$company->tenant_id])->whereRaw('company_id = ?', [$company->id]); })->with('group')->findOrFail($id)`. The `modifiers` table itself has no `tenant_id` / `company_id` columns; scoping is enforced via the parent `modifier_group` (which carries both). Annotation block on lines 52-53 explains this clearly. The `whereRaw` form is intentional: `whereHas` closures don't accept ScopedExists-style rule helpers, and parameterised `whereRaw('column = ?', [$value])` is SQL-injection-safe.
- **api.catalog.013:** `ModifierController.php:91-96` — identical pattern to `::update`. Same annotation rationale applies.

### Cross-tenant exfiltration analysis

- **002 / 005:** `tax_configurations` schema (no tenant_id / company_id) makes cross-tenant exfiltration via this validator structurally impossible. The cross-country business-rule concern is out-of-scope for the tenant-isolation sweep per the audit.
- **012 / 013:** A request with a foreign `id` falls through to `findOrFail` after the whereHas filter, returning 404 (no exfiltration; no mutation occurred because the controller throws before reaching `update()` / `delete()`). Defense-in-depth probe: even if `whereHas` failed open, the `Modifier::with('group')` load doesn't re-query group cross-tenant — the eager-load uses the modifier's `modifier_group_id` FK and is gated by the parent's tenant+company in the SELECT. No second-order leak.

### Stable-key drift rationale

The 2026-05-05 `stale_mark` event was a scanner-pipeline artifact, not a code regression: the YAML history events explicitly say `path/symbol/resource unchanged`. Comparing the live code at HEAD against `git show 516c6f61 -- <files>` confirms the fix bytes are byte-identical to the locked snapshot. No recheck-driven re-fix was warranted; the atomic-mutation re-anchored the callsites in the audit-trail without disturbing code.

### Bar-raising checks

- **Hostile-grep for sibling drifts:** `grep -rn "Modifier::where\|Modifier::find" apps/api/app/Modules/Catalog/` returns no static-call form; the `whereHas`-on-Modifier pattern is the only Modifier route lookup.
- **Annotation-accuracy probe:** the `mirrors api.catalog.002 / 005 precedent` claim referenced by 011/012 (codex-owned, locked separately at f88ad4a5) is wording-consistent with 002/005's actual annotation; annotation chain stays coherent.
- **`whereHas` closure tenant-binding:** the closure captures `$company` by `use ($company)`, so a call with a stale CompanyContext between requests cannot leak: the closure binds at call time, and `$company = $this->companyContext->requireCompany()` at line 50 / 89 forces a fresh resolve per request.

### Open follow-ups (out of scope for this lock pass)

The Codex round-2 review (`2026-05-04-api-catalog-cluster-codex-round2-review.md`) flagged three additional findings not covered by the four needs_recheck callsites here. Those are tracked as separate api.catalog pending callsites (.024 ModifierController::update component_type Enum asymmetry, .025 CompositeItemController::checkAvailability scoped location_id, .026 NoCircularCompositeItemReference unscoped composite-item lookup) and will be fixed in the second fix-cycle of this session.

## Verdict

APPROVE for all four callsites. The needs_recheck transition was a scanner
artifact; the underlying fixes from `516c6f61` are intact at HEAD; the cross-
tenant analysis confirms no remaining gap in either the country-scoped
reference table validation (002/005) or the parent-group-scoped Modifier
route lookups (012/013).
