# Web Variant Authoring Spec — Round-2 Adversarial Review

## Round-1 Finding Status (9 items)

### HIGH-1 — Soft-deleted combos become permanent holes
Status: PARTIALLY-RESOLVED
Evidence: v2 now specifies restore-on-regenerate from soft-deleted variants keyed by junction rows (§2 lines 61-62, §3.1 lines 91-95), which is the right direction given the hard `UNIQUE(product_id, variant_code)` in `apps/api/database/migrations/tenant/2026_06_02_100003_create_product_variants_table.php:32`. However, the same migration also has partial unique indexes for `sku`, `barcode`, and `is_default` (`...100003...php:38-43`), and v2 only calls out restore collisions for `sku`/`barcode` (§3.1 line 95).
Residual gap (if any): Restoring a soft-deleted variant that still has `is_default=true` can collide with the active default partial unique index; v2 does not say to clear `is_default` before restore or map `product_variants_default_unique` to a controlled 422. It also does not define the deterministic choice when multiple rows share the same attribute/value combo through manually-created variants with different `variant_code`s, nor the case where an active and soft-deleted row share the same junction key.

### HIGH-2 — Barcode race handling does not cover update
Status: RESOLVED
Evidence: v2 explicitly requires a centralized SQLSTATE `23505` mapper for the specific `product_variants_tenant_barcode_unique` constraint and requires it to cover both create and update, including the current direct `fill()`/`save()` update path (§3.3 lines 112-113). That is scoped enough to avoid mapping `sku`, `variant_code`, or `is_default` unique failures to a barcode field error.
Residual gap (if any): None for the round-1 concern; implementation must check the constraint name, not just SQLSTATE `23505`.

### HIGH-3 — Delete ignores stock/fiscal refs
Status: PARTIALLY-RESOLVED
Evidence: v2 adds a backend delete policy blocking variants with on-hand stock, stock movements, document lines, or POS receipt lines (§3.7 lines 136-142), matching four variant migrations (`...100005`, `...100006`, `...100009`, `...100010`). The codebase has more variant-aware references validated as foreign keys in `apps/api/database/migrations/tenant/2026_06_15_100000_validate_t2_foreign_keys.php:52-62`.
Residual gap (if any): The policy omits at least `stock_reservations`, `product_batches`, `pos_order_lines`, `pos_receipt_line_batch_allocations`, `catalog_cart_items`, `price_list_items`, `recipe_lines.component_variant_id`, and `stock_transfer_lines` (`...100007`, `...100008`, `...100011`, `...100012`, `...100013`, `...100014`, `...100015`, `2026_06_09_120000`). Some may be intentionally safe to ignore, but v2 does not classify them.

### HIGH-4 — Per-product subset not durable
Status: RESOLVED
Evidence: v2 explicitly changes the requirement to inference-based persistence: the subset is the union of attribute/value pairs on existing variants, exposed through `ProductVariantData.attribute_values` (§2 line 61, §3.5 line 125). Sparse existing matrices are also addressed: hydration intentionally expands to the full cross-product of selected values and lets additive generate fill missing combos (§3.5 line 125).
Residual gap (if any): None for the round-1 durability concern.

### MED-5 — Generate-matrix response is a breaking contract change
Status: RESOLVED
Evidence: v2 keeps `data` as the variant array and moves counts into `meta` (§3.1 line 99), and the current only known frontend caller is `generateVariantMatrix` in `apps/web/src/features/catalog/api/variantApi.ts:87-95`. v2 also calls out the existing `apiPost` unwrap behavior and requires raw `api.post + response.data` so `meta` is preserved (§3.6 line 146; `apps/web/src/lib/api.ts:205-208`).
Residual gap (if any): None for known callers.

### MED-6 — Combination cap is not guarded at service boundary
Status: RESOLVED
Evidence: v2 requires `GenerateMatrixRequest` to reject selections over 200 and re-enforces the same cap in `ProductVariantService::generateMatrix()` before `cartesian()` (§3.1 lines 84-90). That closes the current unbounded materialization path in `apps/api/app/Modules/Catalog/Application/Services/ProductVariantService.php:232` and `ProductVariantMatrixGenerator.php:23-45`.
Residual gap (if any): None; v2 intentionally caps selected combinations before filtering existing rows.

### MED-7 — DTO junction lacks relationship contract
Status: RESOLVED
Evidence: v2 now requires `ProductVariant::attributeValues(): HasMany`, eager loading in list/write/generate paths, and a mapper that consumes only a loaded relation (§3.2 lines 105-108). That directly addresses the current absence of the relation on `apps/api/app/Modules/Catalog/Domain/Entities/ProductVariant.php:87-97` and the current DTO omission in `ProductVariantData.php:22-56`.
Residual gap (if any): None.

### MED-8 — `variant_code` brittle for idempotency
Status: PARTIALLY-RESOLVED
Evidence: v2 correctly declares the junction table as the source of truth and says `variant_code` is output-only (§3.1 lines 91-94), matching the existing authoritative junction schema in `apps/api/database/migrations/tenant/2026_06_02_100004_create_product_variant_attribute_values_table.php:15-20`.
Residual gap (if any): `ProductVariantMatrixGenerator::cartesian()` still accepts `$excluded` as attribute-code to value-code combos (`apps/api/app/Modules/Catalog/Application/Services/ProductVariantMatrixGenerator.php:19-23`), while v2 says existing variants are keyed by attribute/value IDs and then passed to `$excluded` (§3.1 lines 91-93). The spec needs to define the ID-to-current-code projection or change the generator/filtering contract to ID-keyed combos, especially for the promised "value code changed" test (§5 line 165).

### LOW-9 — Duplicate barcode message adds avoidable scope
Status: RESOLVED
Evidence: v2 demotes the conflict-name lookup to best-effort polish, requires the field-scoped barcode 422 regardless, and calls out that the lookup must be tenant-scoped because the current repository helper is company-scoped (§3.3 line 115; `apps/api/app/Modules/Catalog/Infrastructure/Repositories/EloquentProductVariantRepository.php:18-22`).
Residual gap (if any): None.

## New Findings

### HIGH-1 — Concurrent matrix generation can still violate idempotency
Finding: v2 guarantees unchanged regeneration "throws nothing" and says generate remains transactional (§3.1 line 101, §4 line 156), but it does not serialize concurrent generate/sync calls for the same product. Two requests can both load the same missing combo set, then one succeeds while the other hits `product_id, variant_code` or tenant `sku` uniqueness (`apps/api/database/migrations/tenant/2026_06_02_100003_create_product_variants_table.php:32,38-39`); v2 only defines a unique-violation mapper for barcode (§3.3 lines 112-113).
Evidence: spec §3.1 / migration `2026_06_02_100003_create_product_variants_table.php`
Fix: Serialize generate per product with a row lock or PostgreSQL advisory lock on `product_id`, or catch expected `variant_code`/`sku` unique conflicts during generate, re-read the winning rows, and return an idempotent `{ created_count: 0, skipped_count: n }` result instead of a raw exception.

### HIGH-2 — Delete policy omits additional variant-aware tables
Finding: v2 says delete checks are "across the four reference tables + the stock-on-hand lookup" (§3.7 line 142), but the codebase has many more variant foreign keys. Ignoring those tables makes "unused variant" (§3.7 line 141) under-defined and can allow soft-deleting variants still referenced by reservations, transfer/order/cart/pricing/recipe/batch records.
Evidence: spec §3.7; `apps/api/database/migrations/tenant/2026_06_15_100000_validate_t2_foreign_keys.php:52-62`; `2026_06_09_120000_add_variant_id_to_stock_transfer_lines.php:41-53`
Fix: Add an explicit reference matrix for every variant-aware table: block, ignore with rationale, cascade-safe, or stale-cleanup-safe. At minimum classify `stock_reservations`, `product_batches`, `pos_order_lines`, `pos_receipt_line_batch_allocations`, `catalog_cart_items`, `price_list_items`, `recipe_lines`, and `stock_transfer_lines`.

### MED-3 — Barcode 422 envelope is inconsistent with the existing frontend API contract
Finding: v2 specifies barcode races should become `{ errors: { barcode: [...] } }` and the editor should map `422 errors.barcode` inline (§3.3 line 113, §3.5 line 130). The existing frontend API error type expects `error.message` plus optional `error.details`, and `getErrorMessage()` only reads that shape (`apps/web/src/lib/api.ts:35-45,61-73`); the current editor only toasts generic errors (`apps/web/src/features/catalog/components/ProductVariantMatrixEditor.tsx:127-137`).
Evidence: spec §3.3 / §3.5; `apps/web/src/lib/api.ts`
Fix: Define the validation error envelope in the spec to match the app-wide API contract, or extend the API client with a typed validation-error parser and require the editor to read that parser for `barcode` field errors.

### MED-4 — Hydration does not define behavior for soft-deleted or hidden axes
Finding: v2 hydrates selection from active variants' `attribute_values` and renders chips by fetching current attribute values (§3.5 lines 125-126), but attributes themselves are soft-deletable (`apps/api/database/migrations/tenant/2026_06_02_100001_create_product_attributes_table.php:23`; `AttributeController.php:119-133`). The normal attribute list uses Eloquent's soft-delete scope (`EloquentAttributeRepository.php:27-35`), so an active variant can reference an axis that no longer appears in the axis selector, leaving hydration/orphan logic undefined.
Evidence: spec §3.5; `apps/api/app/Modules/Catalog/Presentation/Controllers/AttributeController.php`
Fix: Specify that hidden/deleted axes referenced by active variants render as read-only legacy axes, are treated as orphan metadata, or are excluded with a clear warning. The same rule should apply if an attribute is deactivated or no longer marked `is_variant_axis`.

## Overall Verdict
REVISE

v2 is substantially more implementable than round 1 and resolves most contract-level issues, but it is not ready to implement as written. Before implementation starts, the spec needs to close restore/default collision semantics, define the ID-to-code excluded projection, classify all variant reference tables for delete, and add concurrency/error-envelope rules so idempotent generation and inline validation hold under real races and existing frontend API conventions.
