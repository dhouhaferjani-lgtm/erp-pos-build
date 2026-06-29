# Plan Adversarial Review — Parapharmacy Merchandising (2026-06-28)

## Executive Summary
2 Critical, 4 Important, 4 Nits.
Top finding: Task 20 places nested `brand` flattening in `rowToProduct`, but the live sync path writes API rows through `upsertProducts` before `rowToProduct` ever runs, so POS brand data is lost when the backend emits nested `ProductData.brand`.
Executability verdict: not executable as-is; executable after the listed fixes.
The ProductController base-vs-parapharmacy load split is real and the plan uses the right lists: base `$with` currently starts at `['category', 'unitOfMeasure']`, and parapharmacy metadata loads are already isolated in the vertical blocks.
The Task 8/9 five-argument `belongsToMany` anchors are correct for this codebase because `ParapharmacyProductMetadata` already uses `product_id` as the parent key for existing ingredient/key-component pivots.
The POS cursor and product parameter-count claims are correct: max migration is v57, `pullCustomers` persists `customers.updated_since`, and product upsert currently has 15 data params.

## Critical Findings

### C-1 — Task 20 — apps/pos/src/lib/db/repositories/productRepository.ts:25 — Nested brand flattening is in the wrong direction

Observation: Task 20 says to flatten nested `brand:{id,name}` in `rowToProduct` (`docs/superpowers/plans/2026-06-28-parapharmacy-merchandising.md:482-484`). That cannot preserve data during sync. The live `/products` pull reads API rows and sends them directly to `upsertProducts` (`apps/pos/src/lib/sync/syncService.ts:590-594`). `rowToProduct` only maps SQLite rows back out after persistence (`apps/pos/src/lib/db/repositories/productRepository.ts:25-54`). The spec explicitly says the device flatten point is the `upsertProducts` mapping when POS receives nested `ProductData.brand` (`docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md:113`).

Concrete Fix: Change Task 20 Step 3 to normalize the incoming product before building SQL params, e.g. accept `POSProduct & { brand?: { id: string; name: string } | null }`, then derive `brand_id = p.brand_id ?? p.brand?.id ?? null` and `brand_name = p.brand_name ?? p.brand?.name ?? null` inside `upsertProducts` or immediately before calling it from `pullProductsCore`. Keep `rowToProduct` only for parsing `parapharmacy_metadata` JSON from SQLite.

### C-2 — Task 4 — apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php:147 — Brand is not user-populatable and manual provenance is missing

Observation: The spec requires brand to be user-populatable in ERP and `brand_source='user'` on manual edit (`docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md:12`, `docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md:120`). Task 4 only lists `Product.php`, `ProductController.php`, and `ProductData.php` (`docs/superpowers/plans/2026-06-28-parapharmacy-merchandising.md:193-196`). The actual store/update paths use validated request data (`ProductController.php:321-352`, `ProductController.php:459-478`), but `CreateProductRequest` and `UpdateProductRequest` rules do not include `brand_id` or any brand provenance rule (`CreateProductRequest.php:147-180`, `UpdateProductRequest.php:148-182`). A user cannot set a brand through the product API, and `brand_source=User` is never assigned.

Concrete Fix: Add `CreateProductRequest.php` and `UpdateProductRequest.php` to Task 4. Validate `brand_id` as nullable UUID scoped to the current tenant's `brands` table. In store/update, when `brand_id` is present and changed or cleared by the request, set `brand_source = BrandSource::User` for non-null manual assignment and decide whether clearing should null `brand_source`. Add API tests for create and update with `brand_id`, not only a model-created product.

## Important Findings

### I-1 — Task 11 — apps/api/app/Modules/POS/Presentation/Controllers/PosCustomerSyncController.php:56 — The resource fallback must be the primary implementation

Observation: The plan's first implementation path passes the vertical flag through `additional()` and then returns `array_merge([...], $this->mergeWhen(...))` from `toArray()` (`docs/superpowers/plans/2026-06-28-parapharmacy-merchandising.md:395-410`). The controller currently manually invokes `toArray()` while mapping rows (`PosCustomerSyncController.php:53-57`), and the resource currently returns a plain flat array (`PosCustomerMirrorResource.php:21-51`). In this manual call style, Laravel's normal resource resolution/filtering is bypassed; `mergeWhen()` produces a resource helper value intended for `resolve()`, not a raw `array_merge()`. The controller already injects `CompanyContext` (`PosCustomerSyncController.php:25-27`), and `requireCompany()` already eager-loads `tenant` (`CompanyContext.php:100-104`), so the flag source is sound.

Concrete Fix: Make the constructor path primary: `new PosCustomerMirrorResource($customer, $isParapharmacy)`. In `toArray()`, build `$payload = [existing keys]`, then conditionally assign `skin_type` and `skin_advice_note` with normal PHP `if ($this->isParapharmacy)`. Because `$isParapharmacy` is captured in the map closure, remove `static` from the current mapper.

### I-2 — Task 22 — apps/pos/src/lib/customer/customerSyncService.ts:133 — The auth IDs need a strict guard and failure must affect degraded accounting

Observation: `pullCustomers` requires `tenantId: string` and `companyId: string` (`apps/pos/src/lib/customer/customerSyncService.ts:133-137`). The auth store has `user.tenantId` on the user object and `companyId: string | null` at store level (`apps/pos/src/stores/authStore.ts:20`, `apps/pos/src/stores/authStore.ts:88`). Task 22's minimal implementation says to read IDs from `authStore` and call `pullCustomers(db, tenantId, companyId)` (`docs/superpowers/plans/2026-06-28-parapharmacy-merchandising.md:496-498`) without a missing-ID branch, which will fail strict TS or pass invalid empty/null values. Also, current `computeDegraded()` only considers receipt failures, Z-report failures, payment config failure, and `errors.length` (`apps/pos/src/lib/sync/syncService.ts:201-212`), while the spec requires customer pull failure to mark the sync degraded (`docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md:171`).

Concrete Fix: In Task 22, require:
`const tenantId = auth.user?.tenantId; const companyId = auth.companyId; if (!tenantId || !companyId) { customersFailed = true; errors.push('Customer pull skipped: missing tenant/company context'); } else { ... }`.
On caught customer pull errors, push a sanitized error string into `errors` or extend `computeDegraded()` input to include `customersFailed`, then assert `result.degraded === true` in the failure test.

### I-3 — Tasks 18/19 — apps/pos/src/lib/db/migrations.ts:1 — Device migrations omit the spec-required idempotent ALTER guard

Observation: Task 18 gives bare SQL `ALTER TABLE products ADD COLUMN ...` for v58 (`docs/superpowers/plans/2026-06-28-parapharmacy-merchandising.md:464-471`), and Task 19 similarly says to add v59 ALTERs (`docs/superpowers/plans/2026-06-28-parapharmacy-merchandising.md:475-477`). The spec requires guarding ALTERs idempotently (`docs/superpowers/specs/2026-06-28-parapharmacy-merchandising-design.md:167`). The migration system supports custom `run` handlers (`apps/pos/src/lib/db/migrations.ts:1-5`) and already has `isDuplicateColumnError()` for safe duplicate-column handling (`migrations.ts:8-16`).

Concrete Fix: Change Tasks 18/19 to use `run` instead of bare `sql`, loop each `ALTER TABLE ... ADD COLUMN ...`, catch duplicate-column errors via `isDuplicateColumnError`, then always execute the cursor delete. Add a test that pre-creates one target column, runs the migration, and still verifies all columns plus cursor deletion.

### I-4 — Tasks 26-29 — docs/superpowers/plans/2026-06-28-parapharmacy-merchandising.md:521 — UI tasks still contain zero-context hand-waves

Observation: Tasks 26-29 collapse implementation into "Implement" / "Step 2-3" for the filters drawer, skin advice bar, detail tabs, and customers page (`docs/superpowers/plans/2026-06-28-parapharmacy-merchandising.md:521-535`). That is not executable by a zero-context engineer. The current tree has no `/customers` route in `AppShell` (`apps/pos/src/components/AppShell.tsx:145-150`), while `ProductDetailDrawer` is reached through an organism re-export to the legacy POS file (`apps/pos/src/components/organisms/ProductDetailDrawer/index.ts:1`, `apps/pos/src/components/pos/ProductDetailDrawer.tsx:22`). The plan correctly gates Phase D on a rebase, but these tasks do not state the post-rebase file targets, state ownership, or data flow contracts.

Concrete Fix: Expand each UI task into explicit targets after Task 23: route file/page file for `/customers`, exact drawer component location, filter state owner (`HomePage` vs `ProductGrid`), selected-customer source, local product lookup helper for equivalent/complement/routine IDs, and the API/offline path for customer create/update. Keep the "do not create parallel page" constraint, but name the file to edit once Task 23 verifies it.

## Nits

- Task 3 — `apps/api/app/Modules/Product/Domain/Product.php:80` — Plan says to use `booted()` for UUIDs, but local models already use `HasUuids`; prefer `use HasUuids` on `Brand` for consistency.
- Task 5 — `apps/api/app/Modules/Product/Application/Services/EnrichmentReviewService.php:87` — Put all accept-side mutations inside the transaction, including product updates, enrichment tracking clear, and `accepted_fields`, not just the brand upsert.
- Tasks 1-30 — `docs/superpowers/plans/2026-06-28-parapharmacy-merchandising.md:216` — Commit messages use `feat(...)`; repository instructions require `Phase <major.minor.patch>: <imperative summary>`.
- Task 21 — `apps/pos/src/lib/db/repositories/customerRepository.ts:69` — The plan says "INSERT/ON CONFLICT + params" but does not mention the parameter count update; add exact placeholder and params edits like Task 20 does.

## Spec Coverage Check

- Manual brand editing/provenance has no sufficient task: spec requires `brand_source='user'` on manual edit, but Task 4 does not update create/update request validation or source assignment.
- POS flat server payload is not fully pinned: spec says `/products` should be flat `brand_id`/`brand_name` or device should flatten nested `ProductData` in `upsertProducts`; Task 20 currently names the wrong flatten point.
- Migration idempotency is not covered by the plan tests: spec requires guarded ALTERs, but Tasks 18/19 only test the clean migration path and cursor deletion.
- Everything else material has a task: shared `SkinType`, Brand entity, enrichment accept, partner skin fields, metadata-anchored suitability/routines/equivalents/complements, parapharmacy-only eager loads, customer sync vertical guard, `Merchandising` module key, seeding, v58/v59 cursor resets, customer pull wiring, POS UI overlays, and REALIGNMENT-LOG.

## Verdict

Not executable as-is. The plan is executable after the Critical and Important fixes above. The load-bearing anchors requested in the prompt are mostly sound: ProductController base vs parapharmacy lists are the right lists; `ParapharmacyProductMetadataData::fromModel(ParapharmacyProductMetadata $metadata)` is real; `CompanyContext` is already injected and can eager-load tenant; the enrichment brand drop and `accepted_fields` path are exactly where the plan says; v57 is the max migration; the customer cursor key is `customers.updated_since`; product upsert is correctly 15→18 for three new columns; and `authStore` exposes tenant/company IDs, with the nullability caveat in I-2.
