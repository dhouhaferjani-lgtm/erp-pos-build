# Verdict (one line)

REQUIRES-DIFFERENT-APPROACH

# Top 5 risks

1. The API inventory is not complete enough to be a master sweep. The required presentation grep returns 117 bare `exists:` matches across 12 modules, but the spec only enumerates treasury, document, inventory, taxation, loyalty, accounting, and POS clusters (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:44-52`, `:322-327`). It omits live clusters with tenant-resource validators in Catalog, Contact, Compliance, Pricing, and Service/Cart-adjacent flows, for example `StoreModifierRequest::component_id` (`apps/api/app/Modules/Catalog/Presentation/Requests/StoreModifierRequest.php:21-29`), `FraudAlertController::assign.assigned_to` (`apps/api/app/Modules/Compliance/Presentation/Controllers/FraudAlertController.php:100-110`), and `PricingController::checkMargin.product_id` plus `Product::findOrFail()` (`apps/api/app/Modules/Pricing/Presentation/Controllers/PricingController.php:363-372`). Executing the current cluster list leaves exploitable callsites outside the claimed "entire product surface."

2. The YAML coordination contract is internally inconsistent. Cluster names are stored as unqualified names such as `treasury` and `document`, but dependencies reference `api.treasury`, `api.document`, `api.*`, and `web.*` (`docs/superpowers/plans/tenant-isolation-sweep-inventory.example.yml:52-58`, `:62-68`, `:149-158`). A parse check succeeds, but every dependency reference is missing if validated against same-file cluster names. Status values also disagree: cluster comments say `pending | claimed | in_progress | done | blocked` (`tenant-isolation-sweep-inventory.example.yml:55`), callsites and progress use `fixed` (`:235-241`, `:377-383`), and the spec says resolve sets callsites to fixed (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:87`). Workers will either deadlock on invalid dependencies or bypass the coordination model by convention.

3. The Tauri/desktop sections point at the wrong application and wrong implementation layer. The spec scans `apps/desktop/src-tauri/src/` and `apps/desktop/src/lib/sqlite.ts` (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:209`, `:521-524`, `:563-566`), but the repo has `apps/pos/src-tauri` and `apps/pos/src`, not `apps/desktop` (`find apps -maxdepth 2 -type d`). More importantly, SQLite schema and sync logic are TypeScript-side in `apps/pos/src/lib/db.ts` and `apps/pos/src/lib/db/migrations.ts`, while Rust only holds crypto/printing/display commands. A scanner that reads Rust sync handlers will find nothing and falsely mark the desktop surface clean.

4. The POS re-fold conflicts with the live POS stabilization roadmap. The master spec makes POS a Codex cluster (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:50`, `:327`), but the consolidation checkpoint states a single orchestrator owns all `apps/pos` and `apps/api/app/Modules/POS` work through go-live, with no parallel POS sessions (`docs/superpowers/plans/2026-04-30-pos-consolidation-checkpoint.md:1-10`). It also names `paymentStore.ts`, `terminalStore.ts`, `syncStore.ts`, `productStore.ts`, and `apps/api/app/Modules/POS` as active conflict surfaces (`2026-04-30-pos-consolidation-checkpoint.md:94-151`). Doing this sweep's POS cluster in parallel risks merge conflicts and, worse, fiscal-chain behavioral drift during a cashier-blocking stabilization effort.

5. The permanent CI gates are underspecified and may become a false source of truth. The spec says architecture tests consume YAML "to compute their failure messages" (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:91-94`), while generation stores identity as `same file+line+pattern` (`:211`). File+line is unstable under normal edits, and tying failure messages to YAML without a clear code-derived pass/fail rule creates a cycle where YAML can say "fixed" while code is still unsafe, or code can be fixed while YAML blocks. The tests must verify code and use YAML only for ownership metadata, not derive pass/fail from YAML status.

# Question-by-question findings

## S1. Coverage completeness

Finding: refuted.

The required greps produced:
- 117 presentation `exists:` matches across Accounting, BatchExpiry, Catalog, Compliance, Contact, Document, Inventory, Loyalty, POS, Pricing, Taxation, and Treasury.
- 89 model `find`/`findOrFail` matches across Accounting, BatchExpiry, Cart, Company, Compliance, Document, Identity, Inventory, POS, Pricing, Service, Taxation, Treasury, and Workshop.
- 642 `useQuery`/`useMutation` matches in 210 web feature files.

The spec enumerates API clusters only for treasury, document, inventory, taxation, loyalty, accounting, POS, super-admin, and module-gating (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:44-52`, `:322-327`). Missing clusters with concrete matches:

- Catalog: `StoreModifierRequest` validates `component_id` with bare `exists:products,id` (`apps/api/app/Modules/Catalog/Presentation/Requests/StoreModifierRequest.php:21-29`), and Catalog controllers also validate `modifier_groups`/`products` per grep.
- Contact: `CreateContactRequest` and `ContactController` accept `party_id` against `partners`; the controller then links the untrusted `partyId` into `ContactService::linkToParty()` (`apps/api/app/Modules/Contact/Presentation/Controllers/ContactController.php:81-109`).
- Compliance: `FraudAlertController::assign()` uses bare `exists:users,id` and then assigns the fraud alert to that id (`apps/api/app/Modules/Compliance/Presentation/Controllers/FraudAlertController.php:100-110`).
- Pricing: `PricingController::checkMargin()` combines bare `exists:products,id` with bare `Product::findOrFail()` (`apps/api/app/Modules/Pricing/Presentation/Controllers/PricingController.php:363-372`).
- Service: `ServiceCatalogService` has bare `Service::findOrFail()` in update, delete, and get flows (`apps/api/app/Modules/Service/Application/Services/ServiceCatalogService.php:63-102`).
- Cart: `CartConversionService` uses bare `Partner::find()` and `Partner::findOrFail()` while creating documents under the current company (`apps/api/app/Modules/Cart/Application/Services/CartConversionService.php:35-50`, `:135-165`).
- Workshop: the `find()` grep returns `DocumentGenerationAdapter`, but no workshop cluster exists.

Counter-proposal: Section 4 must generate clusters from actual module names and create missing API clusters: `api.catalog`, `api.contact`, `api.compliance`, `api.pricing`, `api.service`, `api.cart`, `api.workshop`, and `api.company-identity` in addition to the current list. Section 6 must not be a hand-written list; it should say the generated inventory is authoritative and any cluster with pending callsites blocks final verification.

## S2. POS-stabilization cluster re-folding

Finding: partial/refuted.

The cluster belongs in a comprehensive tenant-isolation sweep because A.1 left known POS service gaps: receipt sync, creation, order, finalization, and voucher lookup. The master spec correctly names that cluster (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:50`, `:327`).

It does not belong as a parallel Codex sweep under the current POS roadmap. The live consolidation checkpoint supersedes earlier POS plans and says the orchestrator owns every change to `apps/pos` and `apps/api/app/Modules/POS` through go-live, with "No parallel POS sessions" (`docs/superpowers/plans/2026-04-30-pos-consolidation-checkpoint.md:1-10`). The same checkpoint lists cashier-blocking sync/fiscal failures and directs a coherent investigation across client POST, server commit, idempotency, and hash-chain reconciliation (`2026-04-30-pos-consolidation-checkpoint.md:42-51`, `:94-121`). The offline-first hardening plan also touches payment config, sync scheduler, terminal store, and product store (`docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md:116-184`, `:186-260`).

Counter-proposal: keep POS in scope, but mark `api.pos-stabilization`, `tauri.sqlite-cache`, and `tauri.sync-envelope` as blocked by the POS orchestrator branch, not merely by `api.treasury`. The tenant-isolation work should be done inside the POS stabilization branch or after it merges.

## S3. Super-admin context boundary

Finding: partial.

Existing super-admin paths are centralized in one route group: `routes/api.php` prefixes `/admin`, applies `auth:sanctum-admin`, `super_admin`, and `throttle:admin-sensitive`, then routes to `SuperAdminController`, `MonitoringController`, and `AdminBillingController` (`apps/api/routes/api.php:53-116`). Auth subroutes use `SuperAdminAuthController` under a separate admin/auth group (`apps/api/routes/api.php:34-45`). The `EnsureSuperAdmin` middleware rejects non-`SuperAdmin` users before controller execution (`apps/api/app/Http/Middleware/EnsureSuperAdmin.php:26-62`). Company context is already path-skipped for `api/v1/admin` and `v1/admin` (`apps/api/app/Http/Middleware/CompanyContextMiddleware.php:33-39`, `:91-97`).

Architectural cost:
- Middleware marker: low cost, justified.
- `CrossTenantExists::cross()` refactor: not justified as proposed. Existing admin inline validations include plan/tenant/billing resources, not the operational tenant tables targeted by `ScopedExists`; for example `AdminBillingController::createInvoice()` validates `tenant_id` and then calls `Tenant::findOrFail()` (`apps/api/app/Modules/Billing/Presentation/Controllers/AdminBillingController.php:159-179`), and `recordPayment()` accepts central billing ids (`:224-267`). Rewriting these to a special validation helper is busywork unless the architecture test needs it.
- Path-based skip: unsafe. The current company-context skip itself is path-based (`CompanyContextMiddleware.php:91-97`), proving the repo already relies on paths, but Section 9 would miss a future one-off super-admin route outside `/admin` (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:417-421`).

Counter-proposal: add `#[CrossTenantRoute(reason: "...")]` attributes or a route middleware alias `cross_tenant` and make architecture tests inspect route definitions/controller attributes, not just paths. Use inline `// @cross-tenant-by-design <reason>` only for non-route console commands and admin services.

## S4. Module-gating exploit class

Finding: partial; the spec is both overbroad and undercounting.

`SuperAdminController::updateExtras()` does not accept a body `tenant_id`; it accepts route `{id}` and `enabled_extras[]` (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:248-255`). The route is inside the super-admin group (`apps/api/routes/api.php:53-67`), and `EnsureSuperAdmin` rejects tenant users (`apps/api/app/Http/Middleware/EnsureSuperAdmin.php:41-62`). A regular tenant attacker cannot exploit this endpoint directly. It is cross-tenant-by-design admin behavior, not the same exploit class as tenant users choosing foreign ids.

`ProgressionService::activateModule()` can target the wrong company if the caller supplies the wrong `X-Company-Id`: `ModuleReadinessController` reads the header directly (`apps/api/app/Modules/Progression/Presentation/Controllers/ModuleReadinessController.php:36-40`), the service passes it unchanged (`apps/api/app/Modules/Progression/Application/Services/ProgressionService.php:70-78`), and the client constructs the Growth Advisor path from that id (`apps/api/app/Modules/Progression/Infrastructure/Http/GrowthAdvisorHttpClient.php:51-54`). However, this route uses the API group (`apps/api/app/Modules/Progression/Presentation/routes.php:11-21`), and the API middleware appends `CompanyContextMiddleware` (`apps/api/bootstrap/app.php:61-65`), which validates `X-Company-Id` against user company access (`apps/api/app/Http/Middleware/CompanyContextMiddleware.php:66-78`). The actual bug is that Progression ignores the already-validated `CompanyContext` and trusts a header string; a future route/middleware ordering change would reopen it. Fix by injecting `CompanyContext` into the controller and using `requireCompanyId()`, then verify the Growth Advisor response company id if the response contains one.

`CompanyConfigService::getConfigForTenant()` cache key is correctly tenant-scoped as `tenant_config:{$tenant->id}` (`apps/api/app/Services/CompanyConfigService.php:34-39`). `RequireModule` obtains the tenant from the authenticated `User` relationship and passes the tenant object into that service (`apps/api/app/Http/Middleware/RequireModule.php:40-60`). This is not a cache poisoning exploit today.

The spec undercounts because all Progression endpoints use raw headers (`CompanyProgressionController.php:18-49`, `RecommendationController.php:18-57`), not just activate.

## Y1. Schema soundness

Finding: refuted.

The example YAML is parsable with Ruby/Psych: top-level keys are `metadata`, `agents`, `clusters`, `callsites`, and `progress`; it contains 15 clusters and 6 example callsites. But validation rules implied by the spec fail:

- `blocking_other_clusters` and `blocked_by` references do not match cluster names. Names are `treasury`, `document`, etc. (`tenant-isolation-sweep-inventory.example.yml:52-115`), but references are `api.document`, `api.treasury`, `api.*`, and `web.*` (`:57`, `:67`, `:154`, `:165`). The command must either store canonical names like `api.treasury` in `clusters[].name` or split `surface`/`name` and resolve references by canonical id.
- Status values conflict. Cluster comment says `done`; progress and callsites use `fixed` (`tenant-isolation-sweep-inventory.example.yml:55`, `:235-241`, `:377-383`). The prompt's desired flow includes `pending -> claimed -> in_progress -> fixed`; the YAML has no `under_review` state for the Codex/Claude review pause.
- `history[]` has no schema; examples are empty arrays (`tenant-isolation-sweep-inventory.example.yml:241`, `:264`, `:287`).
- `agents.can_claim` exists (`tenant-isolation-sweep-inventory.example.yml:36-44`), but the spec only says claim is refused if already owned (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:83-89`). It must explicitly reject clusters outside `can_claim`.

Required history entry schema:

```yaml
history:
  - at: "2026-05-02T14:20:00Z"
    actor: "codex"
    action: "claim|start|resolve|block|review_request|review_approve|review_reject|regenerate"
    from_status: "pending"
    to_status: "claimed"
    commit: null
    test: null
    note: "optional short reason"
```

## Y2. Concurrency safety

Finding: partial.

Local advisory `flock()` is sufficient only if both agents run on the same filesystem and both mutate exclusively through the artisan commands. The spec assumes that: "commands acquire a file lock" (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:76-82`) and "Two agents cannot claim the same cluster" (`:230-243`). It is not sufficient across separate worktrees, network filesystems with weak locking, or manual edits.

Counter-proposal: use optimistic concurrency in addition to file locks. `sweep:inventory:claim` should read the file, compute a SHA-256 of the full YAML, lock, re-read, verify the hash is unchanged, validate schema, apply mutation, write to a temp file, fsync, atomic rename, then optionally create a tiny `.lock` sidecar with `pid/host/agent/cluster/expires_at`. CI should reject direct hand-edits unless history contains a matching command-generated event.

## Y3. Inventory generation idempotency

Finding: refuted.

The spec keys merge preservation by "same file+line+pattern" (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:211`). Line numbers shift on every nearby edit, so claimed work will duplicate or reset.

Counter-proposal: stable callsite id should be derived from:

```text
surface + scanner + normalized_relative_path + symbol_fqn + ast_node_kind +
model_or_table + field_or_method + normalized_argument_name + statement_fingerprint
```

For PHP AST: include class FQN, method name, static model FQN or validator table, target field path, and a normalized AST fingerprint with literals normalized except table/model names. Keep line number as display metadata only. For TS: include exported function/component name plus normalized `queryKey` AST fingerprint. On regeneration, if fingerprint changed but path/symbol/resource match, mark `status: needs_recheck` rather than creating a new pending row.

## Y4. YAML vs architecture tests

Finding: refuted as written.

Source of truth must be code. The architecture tests should parse code and fail on unsafe patterns. YAML should only provide owner/status/cluster context for the failure message. The spec wording says tests "consume the YAML to compute their failure messages" (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:91-94`), which is acceptable only if pass/fail is not derived from YAML status. Section 9 also says the initial violation list is the inventory (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:421-424`), which supports code as truth. Make this explicit: if code is fixed and YAML says pending, test passes but emits an inventory-drift warning; if YAML says fixed and code is unsafe, test fails.

## T1. Section 9 PHP-Parser dependency

Finding: verified mostly sound.

`nikic/php-parser` is present in `composer.lock`, but not directly in `composer.json`. It is pulled transitively by dev tooling (`composer.lock` contains `nikic/php-parser` entries; `apps/api/composer.json` requires Larastan/PHPStan/PHPUnit in `require-dev` at `:32-44`). For a test-only architecture scanner, add a direct dev dependency, not runtime dependency:

```bash
composer require --dev nikic/php-parser:^5.7
```

Then implement `tests/Architecture/TenantScopedFindCallsTest.php` using `PhpParser\ParserFactory` and `NodeTraverser`. Do not add it under `require`.

## T2. Vitest queryKey audit

Finding: partial/refuted.

Vitest is configured only to include `src/**/*.{test,spec}.{ts,tsx}` (`apps/web/vitest.config.ts:8-13`), but the spec proposes `apps/web/tools/audit-tanstack-keys.test.ts` (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:462-465`). That file will not run unless the include pattern changes. Also, a heuristic requiring `companyId` or `tenantId` literal is too crude: `CompanyConfigContext` has no `companyId` in its returned value (`apps/web/src/contexts/CompanyConfigContext.tsx:25-30`, `:49-58`), while the actual selected company lives in `companyStore` and API headers are added from `currentCompanyId` (`apps/web/src/lib/api.ts:116-118` from grep output).

Concrete implementation: create `apps/web/src/architecture/auditTanstackKeys.test.ts` as a Vitest wrapper that shells into or imports a Node scanner. Better: create `apps/web/tools/audit-tanstack-keys.mjs` and add package script `"test:arch": "node tools/audit-tanstack-keys.mjs"`; then run it in CI beside Vitest. The scanner should use TypeScript AST, not grep, and support approved key factories:

```ts
// accepted if queryKey includes a call to tenantScopedKey(...), currentCompanyId,
// companyStore.currentCompanyId, tenantId, or an explicit super-admin namespace.
expectNoBareUseQueryKeys({
  root: new URL('../src', import.meta.url),
  allowSuperAdminPrefix: ['admin', 'super-admin'],
})
```

## T3. Skipping super-admin paths

Finding: refuted.

The existing admin route group is path-based (`apps/api/routes/api.php:53-116`), and `CompanyContextMiddleware` path-skips admin routes (`apps/api/app/Http/Middleware/CompanyContextMiddleware.php:91-97`). That does not make path-skipping reliable for architecture tests. A future route can use `super_admin` middleware outside `/admin`, or a route under `/admin` can call tenant-scoped code accidentally. Section 7/9's "skip files inside super-admin paths" (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:366`, `:417-421`) is too weak.

Counter-proposal: tests should build a route map from Laravel routes and require either `cross_tenant` middleware or `#[CrossTenantRoute]` on the controller method for skips. For non-route files, require a local `@cross-tenant-by-design` annotation with reason and ticket/review link.

## M1. Stancl multi-DB migration compatibility

Finding: partial.

The helper remains useful during the row-level phase and migration window. It does not have to be undone immediately. But the research explicitly says that under multi-DB, tenant-side tables move into tenant databases and the sweep B `where('tenant_id', ...)` scoping is dropped as redundant (`docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md:138-151`). It also says the architecture test evolves from catching unscoped `exists:` to catching tenant-table queries against the central connection (`:145-151`).

The current master spec claims "the helper, tests, and convention doc apply to both row-level and multi-DB modes" (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:7`). That is overstated. Under multi-DB:
- `ScopedExists::tenantAndCompany()` should become `company()` or no-op tenant filtering inside tenant DBs, depending on whether `tenant_id` metadata remains.
- Architecture tests need a mode switch: `TENANCY_MODE=row_level` enforces tenant/company predicates; `TENANCY_MODE=multi_db` enforces no tenant-table queries on the central connection.
- Super-admin cross-tenant operations should align with Stancl's `$tenant->run(fn () => ...)` model, which the research names as the clean cross-tenant pattern (`docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md:246-270`).

Build the mode-switching abstraction now as test configuration and docs, but leave full multi-DB enforcement for the migration branch.

## C1. Codex per-cluster review loop

Finding: refuted.

The spec asks the same Codex agent to self-review its own cluster, then Claude reviews the diff (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:329-338`). Self-review catches mechanical omissions, but it is not an adversarial review. Since the human explicitly wants adversarial proof, require a different reviewer for every cluster: Claude fixes Treasury, Codex reviews; Codex fixes a cluster, Claude reviews; if Claude cannot review immediately, the cluster status is `under_review` and blocked from unblocking dependents.

## C2. Treasury reference cluster risk

Finding: partial.

The spec has a Treasury checkpoint: Claude lands it, then hands to Codex for adversarial cluster review (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:250-314`). That is good. It still allows Section 6 to proceed "Once Treasury is Codex-clean" (`:318-320`) without saying the review must be APPROVE and all findings fixed.

Counter-proposal: add a hard gate: no worker may claim non-Treasury API clusters until `docs/superpowers/reviews/2026-05-02-treasury-cluster-codex-review.md` has verdict `APPROVE` or `APPROVE-WITH-MINOR-EDITS-APPLIED` and the YAML cluster status is `fixed`.

## C3. Cluster ownership constraints

Finding: partial/refuted.

The YAML gives Claude `can_claim: ["*"]` (`tenant-isolation-sweep-inventory.example.yml:37-40`), while the spec reserves Treasury, super-admin-context, and module-gating for Claude (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:44-52`). If ownership is load-bearing, enforce it in `can_claim`.

Suggested:

```yaml
agents:
  claude:
    can_claim:
      - api.treasury
      - api.super-admin-context
      - api.module-gating
      - web.super-admin-frontend
  codex:
    can_claim:
      - api.document
      - api.inventory
      - api.taxation
      - api.loyalty
      - api.accounting
      - api.catalog
      - api.contact
      - api.compliance
      - api.pricing
      - api.service
      - api.cart
      - tauri.*
      - web.tanstack-keys
      - web.form-selectors
      - web.stores-localstorage
```

Allow override only with `--force --reason --approved-by`.

## O1. Anything missed?

Finding: verified gaps.

- Platform integration is wrongly dismissed as "works regardless of tenancy model" (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:29`). `PlatformHttpClient` uses one global `services.platform.api_key` header for all outbound calls (`apps/api/app/Modules/PlatformIntegration/Infrastructure/Http/PlatformHttpClient.php:205-216`), while the survey says platform identity is carried as a partner/API-key concept and platform-side data has tenant ids (`docs/superpowers/research/2026-05-01-multi-tenancy-architecture-survey.md:113-131`). The sweep should at least verify every outbound payload contains tenant/company identity where needed and that the API key is not a shared tenant credential.
- Incoming webhooks are not covered. Stripe webhook is unauthenticated but signed (`apps/api/routes/api.php:26-28`). Product enrichment webhooks exist in PlatformIntegration/Product listeners per grep, and should be audited for tenant binding from signature/tracking id to tenant-owned records.
- Broadcast channels are not covered. They use tenant/company channel names and `canAccessCompanyChannel()` (`apps/api/routes/channels.php:19-21`, `:32-46`, `:58-72`). This is a tenant boundary and should be part of the architecture tests or explicitly out of scope with a reason.
- Queue/scheduled jobs are not covered. Stancl queue bootstrapper is configured (`apps/api/config/tenancy.php:39-44`) but current scheduled jobs run globally (`apps/api/routes/console.php:27-35`). `DailyExpiryCheck` marks and notifies batches across all companies without tenant iteration (`apps/api/app/Modules/BatchExpiry/Jobs/DailyExpiryCheck.php:79-121`) and then tries `User::whereRaw('company_id = ?')` even though users are tenant-scoped, not company-scoped (`:131-158`). Jobs can cross tenants today.
- Tauri local cache is broader than `src-tauri`: the SQLite database name is per company id (`apps/pos/src/lib/db.ts:7-25`), but tables such as `products`, `payment_methods`, and `offline_receipts` lack `tenant_id`/`company_id` columns in early migrations (`apps/pos/src/lib/db/migrations.ts:13-67`, `:89-121`). Logout removes auth/settings keys but does not delete SQLite DB files (`apps/pos/src/stores/authStore.ts:210-220`).
- ML/analytics flows are only mentioned as out of scope (`docs/superpowers/plans/2026-05-02-tenant-isolation-sweep.md:30`). If they consume POS/customer/document data, they must be explicitly rejected with a reason and owner, not silently excluded from a "whole product surface" claim.

# Counter-proposal

Use a two-track sweep: fix the coordination/test substrate first, then run generated clusters.

## Track 0: Contract and gates before feature clusters

1. Create `docs/conventions/08-TENANT-ISOLATION.md` first. Define row-level mode vs future multi-DB mode, resource scoping classes, cross-tenant annotations, and code-as-source-of-truth rule.
2. Replace YAML schema:
   - canonical `cluster_id: api.treasury`, not `surface + name` with mixed references;
   - statuses: `pending`, `claimed`, `in_progress`, `under_review`, `fixed`, `blocked`, `deferred`, `needs_recheck`;
   - stable callsite identity;
   - structured history entries;
   - enforced `agents.can_claim`.
3. Implement inventory commands with optimistic concurrency plus file lock.
4. Implement architecture tests in informational `sweep-progress` mode:
   - PHP presentation exists scanner;
   - PHP AST find scanner with direct dev dependency `nikic/php-parser`;
   - web Node/TS AST queryKey scanner run via `pnpm test:arch`;
   - POS local-cache scanner against `apps/pos/src`, not `apps/desktop`.
5. Regenerate inventory and let generated clusters define Section 6. No hand-written cluster list is authoritative.

## Track 1: Execution order

1. Claude: `api.treasury` reference cluster.
2. Codex: adversarial review of Treasury. Hard gate before all non-Treasury API clusters.
3. Codex/Claude split non-POS API clusters from generated inventory: `api.document`, `api.inventory`, `api.taxation`, `api.loyalty`, `api.accounting`, `api.catalog`, `api.contact`, `api.compliance`, `api.pricing`, `api.service`, `api.cart`, `api.workshop`, `api.identity-company`.
4. Claude: `api.super-admin-context` using route/controller attributes, not path-only skip.
5. Claude: `api.module-gating`, narrowed to actual issues: Progression header trust, updateExtras admin-only proof, config cache proof.
6. Web: first add company/tenant-scope key helper and tenant-switch query clearing; then sweep feature hooks.
7. POS/Tauri: block until the POS orchestrator branch is merged or explicitly gives ownership. Then run POS API, `apps/pos/src` SQLite/cache, and sync-envelope work together.

## Required files

- `apps/api/app/Shared/Presentation/Validation/ScopedExists.php`
- `apps/api/app/Shared/Presentation/Validation/CrossTenantAccess.php` or PHP attribute `App\Shared\Architecture\CrossTenantRoute`
- `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php`
- `apps/api/tests/Architecture/TenantScopedFindCallsTest.php`
- `apps/api/app/Application/Sweep/InventoryService.php`
- `apps/api/app/Application/Sweep/InventoryYamlSchema.json`
- `apps/web/tools/audit-tanstack-keys.mjs`
- `apps/web/package.json` add `test:arch`
- `apps/pos/tools/audit-local-cache.mjs` or include POS checks in web tooling
- `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml`

# Diff to the master spec

Not applicable. Verdict is not `APPROVE-WITH-MINOR-EDITS-APPLIED`.

# Diff to the YAML schema

Required changes:

```yaml
clusters:
  - id: "api.treasury"          # canonical id; replaces name+surface references
    display_name: "treasury"
    surface: "api"
    owner: null
    required_owner: "claude"    # optional
    status: "pending"          # pending|claimed|in_progress|under_review|fixed|blocked|deferred|needs_recheck
    blocked_by: []
    blocks: ["api.document", "api.inventory"]

callsites:
  - id: "api.treasury.001"
    stable_key: "sha256:..."
    surface: "api"
    cluster_id: "api.treasury"
    scanner: "php_presentation_exists|php_ast_find|ts_query_key|pos_sqlite_cache|manual"
    file: "apps/api/..."
    line: 23
    symbol: "App\\...\\Class::method"
    pattern_type: "bare_exists_validator"
    resource: "payment_methods"
    expected_scope: "tenant_company|tenant_only|company_only|parent_context|cross_tenant"
    status: "pending"
    owner: null
    claimed_at: null
    review:
      reviewer: null
      status: null
    fix_commit: null
    regression_test: null
    history:
      - at: "2026-05-02T00:00:00Z"
        actor: "generator"
        action: "generate"
        from_status: null
        to_status: "pending"
        commit: null
        test: null
        note: "initial detection"
```

Validation rules:
- `clusters[].id` unique and all `blocked_by`/`blocks` refs must exist unless they are explicit glob refs supported by schema.
- `callsites[].cluster_id` must reference an existing cluster.
- Claim command must verify `agent.can_claim` against `cluster_id`.
- Resolve command must require `fix_commit`, `regression_test`, and transition to `under_review` unless reviewer override is provided.
- Regeneration must preserve rows by `stable_key`, not line.

# Confidence gradient

- Section 1: 4/5. The helper design fixed the prior review's hard objections. Rating changes if original Phase B code still includes static auth/app.
- Section 2: 3/5. Guarded table list is good, but missing generated clusters and cross-tenant annotations.
- Section 3: 4/5. Docs correction is needed. Rating changes if docs claim row-level plus future multi-DB mode explicitly.
- Section 4: 2/5. Inventory generation has unstable identity, invalid Tauri path, and broken dependency schema.
- Section 5: 4/5. Treasury reference cluster is a good pattern, but must be a hard gate.
- Section 6: 2/5. It omits generated clusters and includes POS in conflict with stabilization.
- Section 7: 3/5. Middleware marker is sound; path-based skip and CrossTenantExists helper are not.
- Section 8: 3/5. Real issue is Progression header trust; updateExtras/cache claims are overbroad.
- Section 9: 3/5. AST approach is right; dependency and super-admin skip rules need correction.
- Section 10: 2/5. QueryKey problem is real; heuristic and Vitest file placement are wrong.
- Section 11: 3/5. Store/localStorage risk is real; needs exact tenant-switch source and query clearing contract.
- Section 12: 3/5. Super-admin frontend mode is reasonable but depends on corrected API boundary and queryKey exception.
- Section 13: 1/5. Wrong path and wrong layer; actual POS SQLite is TypeScript in `apps/pos`.
- Section 14: 2/5. Valid concern, but sync envelope must be audited with POS API/client sync code, not Rust-only handlers.
- Section 15: 3/5. Final verification shape is good, but commands use `apps/desktop`, and preflight cannot be trusted until architecture tests are code-derived.

# Out-of-scope items the sweep should add OR explicitly reject

Add:
- PlatformIntegration outbound identity/API-key audit. `PlatformHttpClient` uses a global API key header (`apps/api/app/Modules/PlatformIntegration/Infrastructure/Http/PlatformHttpClient.php:205-216`).
- Incoming webhook tenant binding audit for Stripe and enrichment webhooks (`apps/api/routes/api.php:26-28`).
- Broadcast channel authorization audit (`apps/api/routes/channels.php:19-21`, `:32-46`, `:58-72`).
- Queue and scheduled job tenant-boundary audit. Scheduled jobs run globally (`apps/api/routes/console.php:27-35`), and `DailyExpiryCheck` crosses companies/tenants (`apps/api/app/Modules/BatchExpiry/Jobs/DailyExpiryCheck.php:79-121`).
- POS local SQLite schema audit in `apps/pos/src/lib/db/migrations.ts`, not `apps/desktop`.

Explicitly reject or defer with owner/date:
- Full Stancl multi-DB migration. The research estimates 4-6 weeks and says tests and scoping rules change materially (`docs/superpowers/research/2026-05-01-db-per-tenant-migration-mechanics.md:272-283`).
- RLS implementation. It is defense-in-depth, not required to close the current app-layer bugs.
- Cross-tenant ML/data platform redesign. The survey says pooled row-level is operationally simpler for analytics and DB-per-tenant needs event push/per-tenant CDC (`docs/superpowers/research/2026-05-01-multi-tenancy-architecture-survey.md:113-131`, `:197-210`).
