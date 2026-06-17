# Adversarial Design Review — Web Variant Authoring Spec (2026-06-15)

**Overall Verdict:** REVISE

**Rationale:** The spec correctly identifies the current backend/frontend gaps and proposes the right general direction, but several core guarantees are still under-specified or internally incomplete. The biggest risks are regenerate behavior after a soft-delete under a hard `variant_code` unique constraint, update-time barcode races that bypass the proposed service catch, deletion of variants that may already carry stock or fiscal references, and the absence of durable per-product subset state. No listed source file was missing.

---

## Findings

### [HIGH] Soft-Deleted Combinations Become Permanent Matrix Holes
**Location in spec / code:** Spec §3.1 lines 87-93; `apps/api/database/migrations/tenant/2026_06_02_100003_create_product_variants_table.php:32`; `apps/api/app/Modules/Catalog/Presentation/Controllers/ProductVariantController.php:165-183`; `apps/api/app/Modules/Catalog/Application/Services/ProductVariantService.php:232-288`
**Finding:** The migration confirms `UNIQUE(product_id, variant_code)` is a hard unique with no `deleted_at` predicate, so a soft-deleted variant still blocks insertion of the same generated code. The spec addresses the 500 by loading soft-deleted variants and excluding their codes, but that means a deleted combination is skipped forever rather than restored or recreated. This contradicts the user-facing "Generate / sync matrix" expectation that missing selected combos are produced, especially after an explicit delete followed by re-selection.
**Recommendation:** Define the intended semantics explicitly: either restore soft-deleted matching variants during generate, or keep them deleted but report them separately as `deleted_skipped_count` with UI copy that deleted combos are not regenerated. If restore is chosen, implement it transactionally and reload the junction rows before returning the DTO.

### [HIGH] Barcode Race Handling Does Not Cover Update
**Location in spec / code:** Spec §3.3 lines 101-104 and §4 lines 130-132; `apps/api/database/migrations/tenant/2026_06_02_100003_create_product_variants_table.php:40-41`; `apps/api/app/Modules/Catalog/Presentation/Requests/CreateVariantRequest.php:28-32`; `apps/api/app/Modules/Catalog/Presentation/Requests/UpdateVariantRequest.php:26-31`; `apps/api/app/Modules/Catalog/Presentation/Controllers/ProductVariantController.php:156-157`
**Finding:** The DB enforces barcode uniqueness with the partial PG index `product_variants_tenant_barcode_unique`, while both current FormRequests only use `max:255` and have no uniqueness rule. The spec says the service catches SQLSTATE `23505` and maps it to a structured 422, but the current update path does not call a service; it directly `fill()`s and `save()`s the Eloquent model. As written, an update-time validate-then-save race would still fall through Laravel's normal exception path unless the design also refactors update or catches `QueryException` at the controller/exception-handler layer.
**Recommendation:** Route both create and update through variant service methods that catch the specific PG unique violation, or add a centralized exception mapper for `23505` on `product_variants_tenant_barcode_unique`. Keep the request uniqueness rule as a fast-path only, not the race-safety mechanism.

### [HIGH] Delete Policy Ignores Stock And Fiscal References
**Location in spec / code:** Spec §3.5 lines 117-119 and §6 line 159; `apps/api/app/Modules/Catalog/Presentation/routes.php:51-52`; `apps/api/app/Modules/Catalog/Presentation/Controllers/ProductVariantController.php:165-183`; `apps/api/database/migrations/tenant/2026_06_02_100005_add_variant_id_to_stock_levels.php:47-57`; `apps/api/database/migrations/tenant/2026_06_02_100006_add_variant_id_to_stock_movements.php:24-28`; `apps/api/database/migrations/tenant/2026_06_02_100009_add_variant_id_to_document_lines.php:24-28`; `apps/api/database/migrations/tenant/2026_06_02_100010_add_variant_id_to_pos_receipt_lines.php:24-28`
**Finding:** The controller has a real per-variant delete endpoint gated by `catalog.variants.delete`, and it immediately soft-deletes the row. Variant IDs are also threaded into stock levels, stock movements, document lines, and POS receipt lines, all of which indicate downstream operational and fiscal significance. The spec only adds a frontend confirmation and does not define whether deleting a variant with stock, reservations, stock movements, document lines, or receipts should be blocked, converted to inactive, or allowed with warnings.
**Recommendation:** Specify a backend deletion policy before implementation. A conservative rule is to block delete when on-hand stock, open reservations, stock movements, fiscal document lines, or POS receipt lines exist, and offer `is_active=false` as the normal way to retire a used variant.

### [HIGH] Per-Product Value Subset Is Not Durable
**Location in spec / code:** Spec §2 lines 60-62 and §3.5 lines 114-117; `apps/api/database/migrations/tenant/2026_06_02_100001_create_product_attributes_table.php:13-25`; `apps/api/database/migrations/tenant/2026_06_02_100004_create_product_variant_attribute_values_table.php:13-20`; `apps/web/src/features/catalog/components/ProductVariantMatrixEditor.tsx:78`
**Finding:** The spec says the UX is "per-product value subset" and explicitly says no new tables, but the existing schema has tenant-wide attributes and only a variant-to-value junction. There is no product-level whitelist of selected attribute values, and the current editor state is an in-memory `selectedAxes` array. Without a durable subset, reopening the product cannot know the intended selected values except by inferring from existing variants, and the orphan badge depends on an unstored "current selection."
**Recommendation:** Either add an explicit product-level subset persistence model, or make the design inference-based and specify the hydration algorithm from existing active variant junction rows. If the subset is intentionally session-only, rename the requirement so it does not promise durable per-product configuration.

### [MED] Generate-Matrix Response Is A Breaking Contract Change
**Location in spec / code:** Spec §3.1 lines 74-91 and §3.6 line 123; `apps/api/app/Modules/Catalog/Presentation/Requests/GenerateMatrixRequest.php:21-23`; `apps/api/app/Modules/Catalog/Presentation/Controllers/ProductVariantController.php:126-133`; `apps/web/src/features/catalog/api/variantApi.ts:87-94`; `apps/web/src/features/catalog/hooks/useVariants.ts:101-105`
**Finding:** The current request contract is required `attribute_ids`, and the current response is `201 { data: ProductVariant[] }`. The spec preserves legacy request input, but changes the response to `{ data: { created, created_count, skipped_count } }` and updates the local frontend caller to post `{ axes }`. That is a breaking API response change for any existing caller still using `attribute_ids`, even though the request path is described as back-compatible.
**Recommendation:** Decide whether this endpoint is allowed to break. If not, keep legacy response shape for legacy requests or add a new endpoint/version for the summary response; otherwise call out the response break and update all known callers/tests in the implementation plan.

### [MED] Combination Cap Is Not Guarded At The Service Boundary
**Location in spec / code:** Spec §2 lines 62-66, §3.1 lines 83-85, and §7 lines 162-164; `apps/api/app/Modules/Catalog/Presentation/Requests/GenerateMatrixRequest.php:21-23`; `apps/api/app/Modules/Catalog/Application/Services/ProductVariantService.php:182-232`; `apps/api/app/Modules/Catalog/Application/Services/ProductVariantMatrixGenerator.php:23-44`
**Finding:** The current code has no cap: the request only validates UUID arrays, the service builds every axis, and the generator materializes the full cartesian result before the creation loop. The spec places the hard cap in `GenerateMatrixRequest`, which protects the HTTP endpoint if implemented correctly, but leaves the public application service and pure generator unbounded. Any future backend caller can bypass the cap and still allocate a large cartesian array.
**Recommendation:** Normalize axes once, then enforce `MAX_VARIANTS_PER_GENERATE` in the service before calling `cartesian()`, with the FormRequest as an early HTTP validation layer. Return the same structured 422 from the controller for either validation source.

### [MED] DTO Junction Exposure Lacks The Required Relationship Contract
**Location in spec / code:** Spec §3.2 line 97; `apps/api/app/Modules/Catalog/Application/DTOs/ProductVariantData.php:22-56`; `apps/api/app/Modules/Catalog/Domain/Entities/ProductVariant.php:87-97`; `apps/api/app/Modules/Catalog/Domain/Entities/ProductVariantAttributeValue.php:40-55`; `apps/api/app/Modules/Catalog/Presentation/Controllers/ProductVariantController.php:45-53,131-132,156-159`
**Finding:** The current DTO has no `attribute_values`, and the current `ProductVariant` model only declares `product()` and `company()` relationships, while the junction model points back to the variant. The spec says eager-load in index/store/update/generateMatrix, but does not specify adding a `ProductVariant::attributeValues()` relation or how newly-created/updated variants are reloaded before DTO transformation. If implementers query the junction inside `fromModel()` instead, listing 200 variants will create an avoidable N+1 path.
**Recommendation:** Add a named relationship on `ProductVariant`, require controllers/services to `with('attributeValues')` or `loadMissing('attributeValues')`, and make `ProductVariantData::fromModel()` consume the loaded relation without issuing per-variant queries.

### [MED] Excluding Existing Combos By `variant_code` Is Brittle
**Location in spec / code:** Spec §3.1 lines 87-93; `apps/api/app/Modules/Catalog/Application/Services/ProductVariantService.php:203-232,248-255,264-288`; `apps/api/database/migrations/tenant/2026_06_02_100004_create_product_variant_attribute_values_table.php:15-20`
**Finding:** The spec says to derive existing combinations from `variant_code`s and map them back to combo shape before passing `$excluded`. The database already stores the authoritative attribute/value mapping in `product_variant_attribute_values`, and the service already writes those pairs for each generated variant. Reconstructing combos from generated strings is fragile if product SKU or value codes ever change, and it is unnecessary given the junction table.
**Recommendation:** Build `$excluded` from existing variants' junction rows keyed by attribute/value IDs, then translate to the generator's code shape only after validating the selected axes. Treat `variant_code` as an output identifier, not the source of truth for idempotency.

### [LOW] Duplicate Barcode Message Adds Avoidable Scope
**Location in spec / code:** Spec §3.3 lines 101-104; `apps/api/app/Modules/Catalog/Infrastructure/Repositories/EloquentProductVariantRepository.php:18-22`; `apps/api/database/migrations/tenant/2026_06_02_100003_create_product_variants_table.php:40-41`
**Finding:** The core requirement is field-scoped 422 behavior, but the spec also requires naming the conflicting variant. The current lookup helpers resolve barcode by `company_id`, while the DB uniqueness index is tenant-scoped, so identifying the conflicting row robustly may need a new tenant-scoped lookup and careful handling of soft-deleted rows. This is useful polish, but it is not necessary to close the race or provide inline validation.
**Recommendation:** Defer the conflict-name lookup or make it best-effort after the core 422 mapping is implemented. If kept, specify a tenant-scoped conflict query that mirrors the partial unique index exactly.

---

## Summary Table
| # | Severity | Title | Verdict impact |
|---|----------|-------|----------------|
| 1 | HIGH | Soft-Deleted Combinations Become Permanent Matrix Holes | Requires revision before approval |
| 2 | HIGH | Barcode Race Handling Does Not Cover Update | Requires revision before approval |
| 3 | HIGH | Delete Policy Ignores Stock And Fiscal References | Requires revision before approval |
| 4 | HIGH | Per-Product Value Subset Is Not Durable | Requires revision before approval |
| 5 | MED | Generate-Matrix Response Is A Breaking Contract Change | Needs explicit compatibility decision |
| 6 | MED | Combination Cap Is Not Guarded At The Service Boundary | Needs service-level guard |
| 7 | MED | DTO Junction Exposure Lacks The Required Relationship Contract | Needs implementation detail |
| 8 | MED | Excluding Existing Combos By `variant_code` Is Brittle | Needs idempotency source-of-truth change |
| 9 | LOW | Duplicate Barcode Message Adds Avoidable Scope | Defer or clarify |
