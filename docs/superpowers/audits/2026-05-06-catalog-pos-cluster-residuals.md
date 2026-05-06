# Tenant-isolation sweep — api.catalog + api.pos-stabilization residual findings

Audit date: 2026-05-06
Status: DEFERRED to a future round (not closure-blocking; defense-in-depth tightening)
Cluster aggregates: api.catalog (in_progress), api.pos-stabilization (in_progress)

## Context

Both clusters underwent multiple review rounds and closed their CRITICAL
findings (live cross-tenant data leaks / mutations). At deeper scrutiny,
each cluster's most-recent Codex verdict surfaced one additional defense-
in-depth finding that violates the literal cluster invariant ("BOTH
tenant_id AND company_id on every route-anchored read") but does NOT
constitute an exploitable production leak. The implementing sessions'
gates (phpunit + phpstan + pint + verify-history) all stayed green.

Documenting these residuals here so they're tracked for a future round
rather than silently ignored. The cluster aggregates remain `in_progress`
until they close.

## api.catalog round-2 residual — ProductImageController implicit route binding

Source: `docs/superpowers/reviews/2026-05-06-api-catalog-reassigned-cluster-codex-round2-review.md` Finding 1 (REQUEST-CHANGES).

ProductImageController routes (`apps/api/app/Modules/Product/routes.php:113-135`) use Laravel's implicit route model binding (`Product $product` / `ProductImage $image`). The controller methods at `ProductImageController.php:25, 36, 55, 76, 86, 98` rely entirely on that binding without overriding `resolveRouteBinding` and without controller-tier `CompanyContext` re-scoping.

Affected: 6 routes (`index`, `store`, `show`, `update`, `destroy`, `download`).

Risk: a request like `GET/PATCH/DELETE /api/v1/products/{tenantAProductId}/images/{tenantBImageId}` can act on the foreign bound image after an unscoped route-param read. `update`/`destroy`/`download` also do not verify that the bound `ProductImage` belongs to the bound `Product`.

Cluster: api.catalog (Product module).

Suggested round-3 remediation:
- Override `Product::resolveRouteBinding` and `ProductImage::resolveRouteBinding` to scope by `CompanyContext` tenant + company.
- In `ProductImageController::update`/`destroy`/`download`, reload via the scoped Product chain and assert `$image->product_id === $product->id` before mutating.
- Add 6 manual stubs to `tenant-isolation-sweep-manual-callsites.yml`.
- Add cross-tenant denial tests + structural-SQL-log invariants in `CatalogTenantIsolationTest.php`.

## api.pos-stabilization round-4 residual — controller reload/readback + service fresh()

Source: `docs/superpowers/reviews/2026-05-06-api-pos-stabilization-cluster-codex-round4-review.md` Finding (REQUEST-CHANGES).

After the round-4 fix at `de7078d0` closed all OrderManagementService locked Order lookups + Table releases:
- Controller-tier reload/readback queries still use `company_id`-only chains (missing `tenant_id`).
- Several service-tier `$order->fresh(['lines'])` readbacks remain unscoped primary-key reads.

These are NOT live cross-tenant leaks: the mutation paths are closed and the company_id-only reload would only return same-tenant rows in practice (UUID uniqueness + company-scoped pre-mutation guard). The literal cluster invariant requires BOTH predicates.

Cluster: api.pos-stabilization (POS module).

Suggested round-5 remediation (with concrete file:line targets from the round-4 implementer report):
- Add `tenant_id` predicate to controller reload reads in OrderController + KitchenDisplayController:
  - `OrderController::addLine:180`, `modifyLine:237`, `removeLine:281`
  - `OrderController::show:98` (route {id} read; currently company_id-only scope)
  - `KitchenDisplayController::updateLineStatus:64`, `bump:100`, `served:129`
- Replace service-tier `$order->fresh(['lines'])` with a scoped chain (or document each `fresh` call as structurally protected by the prior scoped lock):
  - `OrderManagementService.php:404` (sendToKitchen)
  - `OrderManagementService.php:460` (closeOrder)
  - `OrderManagementService.php:516` (cancelOrder)
  - `OrderManagementService.php:611` (markOrderServed)
  - `OrderManagementService.php:663` (bumpOrder)
- Inventory classification fix: stubs `api.pos-stabilization.048` and `.049` were recorded with `expected_scope: company_only` but should be `tenant_and_company` to match the cluster invariant. One-shot mutate to update the field; ALWAYS append a history event in the same cycle (per `2026-05-04-inventory-mutate-orphan-gap.md`).
- Add structural-SQL-log invariant tests for the reload paths.

## Why these are deferred

Both findings are defense-in-depth on already-protected mutation paths. The pragmatic stopping point:
- ~3 prior rounds per cluster, each closing real cross-tenant leaks
- Diminishing returns at this depth
- Fresh-session orchestrator handoff better serves the broader sweep than another round here

A future round (likely after the architectural/bespoke clusters close) can pick these up cleanly. Until then, both clusters' aggregate status remains `in_progress` honestly.

## References

- api.catalog round-2 verdict: `docs/superpowers/reviews/2026-05-06-api-catalog-reassigned-cluster-codex-round2-review.md`
- api.pos-stabilization round-4 verdict: `docs/superpowers/reviews/2026-05-06-api-pos-stabilization-cluster-codex-round4-review.md`
- Inventory-residuals precedent (same deferral pattern): `docs/superpowers/audits/2026-05-05-inventory-cluster-residuals.md`

## Round-5+ additions from api.console-commands triage (2026-05-06)

The `api.console-commands` cluster (orchestrator: claude) classified every Artisan
command class under `apps/api/app/Console/Commands/` and `apps/api/app/Modules/**/Commands/`
into cat-(a) / cat-(b) per master plan §14. Two POS-module Artisan commands fall
outside the cluster's scope (POS surface invariant) and are deferred to
`api.pos-stabilization`. Both are tracked in
`apps/api/tests/Architecture/fixtures/console-command-deferrals.json` so the new
console-command tenant-context architecture test (Step 7 of api.console-commands)
skips them at scan time.

### Deferred commands

- **`pos:verify-chains`** (`App\Modules\POS\Commands\VerifyPosChainCommand`)
  - File: `apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php`
  - Stable-key hint: `manual:api.pos-stabilization:pos-verify-chains`
  - Likely classification when handled: **cat-(b)** (`@cross-tenant-by-design Iterates Terminal::active()->orderBy('code')->get() across all companies for fiscal hash chain integrity verification.`)
  - Scope note: the existing `--company` option is purely a narrowing filter; the design intent is fleet-wide chain integrity for NF525 audit. No semantic change required when the POS orchestrator picks it up — only the §9 grammar annotation.

- **`pos:expire-held-orders`** (`App\Modules\POS\Infrastructure\Commands\ExpireHeldOrdersCommand`)
  - File: `apps/api/app/Modules/POS/Infrastructure/Commands/ExpireHeldOrdersCommand.php`
  - Stable-key hint: `manual:api.pos-stabilization:pos-expire-held-orders`
  - Verified classification: **cat-(a) needing real per-tenant iteration**, NOT by-design.
  - Evidence: the command delegates to `HeldOrderService::expireOrders()` (`apps/api/app/Modules/POS/Application/Services/HeldOrderService.php:153-159`), which executes a fleet-wide `HeldOrder::where('status', Held)->whereNotNull('expires_at')->where('expires_at', '<', now())->update(['status' => Expired])` with NO `tenant_id` / `company_id` predicate. **Accidentally cross-tenant; a real tenant-isolation bug, not by-design.**
  - Scope note for the POS orchestrator: when handling, EITHER add `tenant_id` + `company_id` predicates to the UPDATE (defense-in-depth) AND wrap the command body in per-tenant iteration so `CompanyContext` is bound; OR change the contract so the command takes `--tenant=<id>` + `--company=<id>` and runs once per scheduled invocation per company. The first option preserves the scheduler shape; the second is more invasive.

### Tracking

- Architecture deferrals fixture: `apps/api/tests/Architecture/fixtures/console-command-deferrals.json` (created by api.console-commands cluster).
- Triage doc: `docs/superpowers/audits/2026-05-06-api-console-commands-triage.md`.
- This residuals doc is the single source of truth for the deferral; api.console-commands does NOT mutate the api.pos-stabilization YAML.
- Taxation-residuals precedent: `docs/superpowers/audits/2026-05-04-taxation-cross-cluster-blind-spots.md`
