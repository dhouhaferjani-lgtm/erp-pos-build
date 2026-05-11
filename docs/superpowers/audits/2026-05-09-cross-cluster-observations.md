# Cross-cluster observations 2026-05-09

Findings surfaced during cluster work that fall outside the active cluster's scope. Logged here per kickoff brief; do NOT expand cluster scope mid-claim.

## From api.inventory cluster (locked at 11302ad1)

### CC-1: InventoryOpeningService:~220 — bare `Company::findOrFail($batch->company_id)`

**File:** `apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php` line ~220.

**Pattern:** Defense-in-depth gap. `$batch->company_id` is upstream-trusted (loaded from a scoped repository call upstream), so cross-tenant exfiltration via this path requires the upstream forge — but the Company lookup itself is unscoped.

**Why deferred:** Adding it mid-cluster (api.inventory had 7→8 callsites) felt like scope creep. Codex review M4 confirmed the finding but graded it acceptable as a tracked gap.

**Severity:** low (defense-in-depth, not exploitable on the current call graph).

**Recommended action:** add to a future api.inventory follow-up cluster (e.g., `api.inventory-defense-in-depth`) or fold into a broader "unscoped Company::findOrFail" sweep across modules. Either way, scope decision is for the next sweep planning round.

**Surfaced by:** hostile-grep during api.inventory triage (2026-05-09).

## From api.catalog cluster (2026-05-09 fast-batch round-1 codex review of 027-033 at fix_commit 48ff4bb9)

### CC-2: ProductController::index — company-only scoping

**File:** `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:80-82`.

**Pattern:** Defense-in-depth gap. `Product::where('company_id', $companyId)` lists products without an explicit `tenant_id` predicate, even though the `products` table carries both columns (migrations `2025_11_30_052910_create_products_table.php` + `2025_11_30_130000_add_company_id_to_existing_tables.php:49-54`). Not a route-id exposure shape (no foreign-id parameter), but a defense-in-depth mismatch with the two-predicate pattern used in the fixed 027-033 reads.

**Why deferred:** Out-of-scope for the api.catalog 027-033 fast-batch (codex round-1). Adding the tenant_id predicate to the index listing is a sibling defense-in-depth pass, not in any of the seven callsite specs.

**Severity:** low (defense-in-depth, not exploitable on the current call graph because `companyContext->requireCompany()` already binds tenant via the auth middleware chain).

**Recommended action:** add to a future api.catalog defense-in-depth follow-up cluster, OR fold into the same broader "two-predicate listing" pass that CC-1 belongs to.

**Surfaced by:** Codex round-1 review of api.catalog.027-033 (`docs/superpowers/reviews/2026-05-09-api-catalog-fast-batch-codex-review.md` finding 2).

### CC-3: ProductController::stockLevels — incoming-PO aggregate company-only

**File:** `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:600-613`.

**Pattern:** Defense-in-depth gap on the `DocumentLine::join('documents', ...)` aggregate calculating incoming purchase-order stock. The query has `documents.company_id` but lacks `documents.tenant_id`. The `documents` table carries `tenant_id` per `2025_11_30_080000_create_documents_table.php`. The product_id used in the aggregate is already obtained from a tenant+company-scoped product lookup at `:572-576`, so this is not a direct cross-tenant read at the route-id callsite; however, the aggregate should follow the same two-predicate defense-in-depth standard.

**Why deferred:** Same scope-discipline rationale as CC-2 — out-of-scope for the 027-033 fast-batch.

**Severity:** low (no direct exfiltration; product_id upstream-scoped).

**Recommended action:** same as CC-2 — fold into a defense-in-depth pass alongside ProductController::index.

**Surfaced by:** Codex round-1 review of api.catalog.027-033 (same source as CC-2, finding 3).
