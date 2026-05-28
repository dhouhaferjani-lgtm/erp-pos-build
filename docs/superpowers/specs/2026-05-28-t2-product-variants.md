# T2 — Product Variants (locked spec for next-cycle implementation)

**Track:** T2 (productization sprint, Wave 1 server-side + Wave 2 POS deltas)
**Date:** 2026-05-28
**Version:** v1 (this session) — supersedes baseline `2026-05-24-t2-variants.md` (v2 post Codex round-1) by widening scope to cover recipe ingredient resolution, B2B + ecommerce surfaces, and the post-T6 migration topology.
**Relationship to prior work:** the 2026-05-24 v2 spec is the schema-design baseline. Its `ProductAttribute / ProductAttributeValue / ProductVariant / ProductVariantAttributeValue` shape and partial-index strategy are carried forward verbatim and re-verified against current `dev`. The owner's 2026-05-28 briefing adds three non-negotiable requirements (recipes built from variants with earliest-expiry inheritance; ecommerce surfacing; B2B surfacing) that the v2 spec marked out-of-scope or only gestured at. This spec closes those gaps and is the artifact the Phase-2 Codex implementation session should execute against.
**Workflow recommendation:** Opus owns schema design + recipe-expiry algorithm + partial-index correctness (rigorous, low headcount). Codex owns the mechanical service-layer ripple (touches ~30 files; high headcount) and the admin matrix UI. POS Wave 2 deltas log to the coordination log and are gated by fiscal Phase-1 sign-off.
**Estimated effort:** ~21 PD (revised up from v2's 16 because recipe-variant resolution and B2B/ecommerce surfacing add real work; POS picker remains a Wave 2 delta).
**Roadmap reference:** [`2026-05-24-productization-sprint-roadmap.md`](../coordination/2026-05-24-productization-sprint-roadmap.md)
**Constitutional reference:** [`2026-05-24-migration-topology-contract.md`](../coordination/2026-05-24-migration-topology-contract.md). T6 phase-0 has merged (`apps/api/database/migrations/tenant/` is populated; all tenant-scoped migrations live there). Every new migration in this spec lands in that directory.

---

## 1. Purpose

The current `Product` aggregate is single-SKU. Five verticals AutoERP serves (parapharmacy orthopedic line, fashion / footwear, eyewear, cosmetics, sportswear) cannot be properly modeled because every size / colour / material combination needs its own stock, barcode, batch chain, and price point. The same data shape (variant as the unit of stock, variant as the unit of batch) is reusable across these verticals; **only the attribute taxonomy (Size vs. Vehicle-Year-Make vs. Volume) differs**.

T2 ships:

1. A generic `ProductVariant` aggregate (with `ProductAttribute` / `ProductAttributeValue` / `ProductVariantAttributeValue`) that decorates an existing `Product`.
2. A backward-compatible `variant_id` (nullable) column on every table that currently scopes to `product_id`.
3. Variant-aware service signatures (every method that today accepts a `product_id` becomes capable of accepting an optional `variant_id`).
4. Variant-aware batch attachment and FEFO selection: batches and expiry attach to **variants**, not products, when variants exist (the TODO at `apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:23` is removed).
5. Recipe / composite-item ingredient resolution at variant grain. A recipe line whose ingredient is a variant-bearing product **must** resolve to a specific variant; recipe expiry derives from the earliest-expiring batch among consumed-variant batches.
6. Variant-grain pricing for B2B (`price_list_items.variant_id`, `partner_price_lists` flow), ecommerce (`channel_product_mappings` already pre-positioned), and POS.
7. Admin UI: tenant-level attribute management + per-product variant matrix editor + variant-aware stock view.
8. POS Wave-2 deltas: variant picker modal, barcode-to-variant resolution, cart line variant suffix, SQLite `variant_id` schema migration.

T2 **does not** ship: bulk variant CSV import (defer); variant-level workflow / draft-publish (defer); WooCommerce / Shopify adapter implementations (deferred to channel-adapter sprint; the variant model is the contract those adapters consume); marketplace listing variant fan-out (existing `marketplace_listings.source_product_id` stays product-grain).

---

## 2. Owner non-negotiable requirements (2026-05-28 briefing)

These five points anchor every design decision in §3–§11. Each is restated verbatim and mapped to the section that satisfies it.

1. **"Variants are the unit of stock, not products. Most things in the system that scope to 'product' today must scope to 'variant' instead."** → §3 (domain) + §4 (schema) + §5 (cross-cutting reference-site map) + §6 (service contracts).
2. **"Batches (existing batch management) attach to VARIANTS, not products. Expiry too."** → §4 (`product_batches.variant_id`) + §7 (FEFO + batch lifecycle at variant grain) + §10 acceptance criteria.
3. **"Composite products / recipes can be built FROM variations of existing products. Selling a recipe consumes batches of its ingredient variants. Recipe expiry derives from the earliest-expiring ingredient batch."** → §8 (recipe + variant + expiry inheritance).
4. **"All of this must surface in ecommerce AND B2B sales."** → §9 (ecommerce + B2B surface).
5. **"Must preserve modularity per vertical (parapharmacy ≠ pharmacy ≠ retail ≠ F&B coffee shop with recipes)."** → §11 (vertical examples) + §3 generic attribute taxonomy + acceptance criteria proving same code-path serves each vertical.

If any subsequent revision (or downstream PR) trades one of these for ergonomic convenience, that's a regression and a blocker.

---

## 3. Architecture grounding (verified against `dev` 2026-05-28)

Every file path below was re-verified post the T6 phase-0 migration reorg. The 2026-05-24 v2 spec referenced paths under `apps/api/database/migrations/` directly; almost all those migrations have moved to `apps/api/database/migrations/tenant/`. Paths in this section are authoritative.

**Code that the implementation MUST read before writing a line:**

1. `apps/api/app/Modules/Catalog/Domain/Entities/CompositeItemVariant.php` — small Eloquent style reference (fillable + casts + one relation + `calculatePrice()`). **Caveat (from v2 P1-7):** this is NOT a full lifecycle pattern. Use as style only; the new `ProductVariant` aggregate has its own full lifecycle.
2. `apps/api/database/migrations/tenant/2026_02_19_100004_create_composite_item_variants_table.php` — migration shape reference (note tenant path).
3. `apps/api/app/Modules/Product/Domain/Product.php` — the aggregate that variants decorate. `ProductType` enum is `Part | Service | Consumable`; **variants only attach to physical products** (`is_physical=true`), i.e., `Part` or `Consumable`. Services do not carry variants.
4. `apps/api/app/Modules/Product/Domain/ParapharmacyProductMetadata.php` and `AutomotiveProductMetadata.php` — vertical metadata pattern. Metadata stays on the parent `Product`; variants do not duplicate vertical metadata. Decision rationale in §11.
5. `apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:23` — the literal TODO comment this spec removes (`// $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete(); // TODO: Add when product_variants table exists`).
6. `apps/api/database/migrations/tenant/2025_11_30_110000_create_inventory_tables.php` — `stock_levels`, `stock_movements` creation. **Existing unique constraint on `stock_levels`** is `(tenant_id, product_id, location_id)` per lines 16–30; this is the constraint we must split into two partial indexes (§4.4).
7. `apps/api/database/migrations/tenant/2025_11_30_131000_add_company_id_to_stock_tables.php` — added `company_id` columns + indexes but did **not** touch the unique constraint (`company_id` is still nullable on legacy rows; if we naively include it in the new partial index two `company_id=NULL` rows for same `(tenant_id, product_id, location_id)` could coexist and weaken the constraint). Strategy in §4.4 keeps `company_id` out of the new unique key shape; tightening is out of T2 scope.
8. `apps/api/database/migrations/tenant/2025_12_24_133728_create_stock_reservations_table.php` — reservation table needs `variant_id`.
9. `apps/api/database/migrations/tenant/2025_12_02_065035_add_cost_tracking_to_stock_movements_table.php` + `2025_12_24_133827_extend_stock_movements_table.php` — recent stock_movements extensions; verify column ordering when adding `variant_id`.
10. `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` — `receive()`, `issue()`, `transfer()`, `reserve()` get variant-aware overloads (§6).
11. `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php` — receipt path that needs variant awareness.
12. `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php` — `findOrCreateBatch` + `transferBatchStock` signatures must become variant-aware.
13. `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php` — FEFO queries must filter by `(product_id, variant_id)` tuple. Existing query orders by `expiry_date ASC`; that stays.
14. `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php` — aggregate root. `is_recalled` and `is_expired` semantics carry; `canBeSold()` invariant unchanged.
15. `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php` (especially `decrementStock` around lines 837–904 per v2 P1-3) — POS sale path writes `StockMovement` rows directly (bypassing `StockAdjustmentService`). **MUST be updated to write `variant_id`** otherwise variant sales silently produce `variant_id=NULL` movements.
16. `apps/api/app/Modules/Catalog/Domain/Entities/Recipe.php` + `RecipeLine.php` + `Enums/ComponentType.php` — recipe + ingredient model. The current `ComponentType` enum is exactly `Product | CompositeItem`; T2 needs **either** a new enum case `ProductVariant` **or** an explicit nullable `component_variant_id` column. §8.2 picks one and explains why.
17. `apps/api/database/migrations/tenant/2026_03_03_100000_add_composite_item_id_to_pos_receipt_lines.php` (lines 30–45 contain the active XOR constraint `(product_id IS NOT NULL AND composite_item_id IS NULL) OR (product_id IS NULL AND composite_item_id IS NOT NULL)`). Variant_id must hang off the `product_id` side only — §4.3 adds a complementary CHECK.
18. `apps/api/database/migrations/tenant/2026_02_19_000001_create_pos_receipt_line_batch_allocations_table.php` — batch allocations table; needs `variant_id`.
19. `apps/api/database/migrations/tenant/2025_12_01_201028_create_price_list_items_table.php` — `price_list_items.product_id`, no `variant_id`. T2 adds nullable `variant_id` per §9.2.
20. `apps/api/database/migrations/tenant/2025_12_01_201044_create_partner_price_lists_table.php` + `apps/api/app/Modules/Pricing/Domain/PartnerPriceList.php` — B2B price-list linking flow; no schema change needed (linking is partner→price-list; variant-grain happens at `price_list_items`).
21. `apps/api/database/migrations/tenant/2026_05_24_120002_create_channel_product_mappings_table.php` — **ALREADY HAS** `variant_id` (nullable) and the composite unique `(channel_id, product_id, variant_id)`. T2's ecommerce surface consumes this pre-existing column; no migration needed for channel mapping itself (§9.1).
22. `apps/api/database/migrations/tenant/2026_03_10_500000_create_catalog_carts_tables.php` — ecommerce cart items; T2 adds `variant_id` (nullable) per §9.1.
23. `apps/api/database/migrations/tenant/2025_12_02_070002_create_inventory_counting_items_table.php` — **ALREADY HAS** `variant_id` (nullable). Pre-positioned for T2; no migration needed (§5 reference-site map confirms).
24. `apps/api/app/Modules/Document/Domain/Events/DraftLineAddedV2.php` (and `DraftLineModifiedV2`) — V2 events. Adding `variant_id` to the payload **changes shape** → must spawn V3 events (rule 8 — immutability). §6.4 lists every event affected.
25. `apps/web/src/features/catalog/components/VariantEditor.tsx` — existing CompositeItem variant editor (style reference only; new `ProductVariantMatrixEditor` is its own component).
26. `apps/pos/src/pages/HomePage.tsx` + `apps/pos/src/stores/cartStore.ts` — POS cart / product-selection target for Wave-2 picker modal.

**Patterns to mirror:**

- **Hexagonal split** — Domain (entities + VOs + enums) → Application (services + DTOs + listeners) → Infrastructure (Eloquent repositories) → Presentation (controllers + React components).
- **Backward-compat at service layer** — every method taking `product_id` today gains an optional `?UUID $variantId = null` (PHP) / `variantId?: string` (TypeScript). When null, behavior identical to today. This is **not** a feature flag; non-variant products run the null branch in perpetuity.
- **Stock at variant level when variants exist, product level when they don't** — `stock_levels` row with `variant_id=NULL` means product-level stock for products without variants. Mixed-mode within the same product is forbidden (an invariant; §6.6 enforces it at the service layer).
- **Tenant + company + location scoping** preserved everywhere via Stancl tenant context (DB boundary post-T6).
- **Constructor injection only**; no `app()` helper; strict types; no `mixed`; PHPStan L8 zero errors; TDD red-then-green per AutoERP CLAUDE.md rules.
- **Migration placement:** every new migration → `apps/api/database/migrations/tenant/` per topology contract §3. No cross-DB FKs (intra-tenant only). All FKs point at tenant-DB tables.

---

## 4. Schema

All new tables and column additions land in `apps/api/database/migrations/tenant/`. Filenames follow the `YYYY_MM_DD_HHMMSS_*` convention; T2's migrations are dated 2026-06-02 onward (post-T6-Phase-0 merge, post-T1 land).

### 4.1 New tables

**`product_attributes`** — reusable attribute definitions per tenant.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK (`HasUuids`) | |
| `tenant_id` | UUID NOT NULL | Stancl scope. |
| `code` | varchar(64) NOT NULL | snake_case; unique per tenant. |
| `name` | varchar(128) NOT NULL | display label; i18n at presentation layer. |
| `data_type` | enum NOT NULL | `Text \| Numeric \| Boolean \| Date \| Selection \| Color \| Image`. |
| `is_variant_axis` | bool NOT NULL DEFAULT false | if true, this attribute participates in variant matrix generation. |
| `display_order` | int NOT NULL DEFAULT 0 | |
| `is_active` | bool NOT NULL DEFAULT true | |
| `created_at`, `updated_at`, `deleted_at` | timestamps | soft deletes. |

Constraints: `UNIQUE (tenant_id, code)`. Index on `(tenant_id, is_active, is_variant_axis)`.

**`product_attribute_values`** — enumerated values for `Selection` / `Color` / `Image` data types and for any attribute used as a variant axis.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `tenant_id` | UUID NOT NULL | |
| `attribute_id` | UUID NOT NULL FK → `product_attributes.id` CASCADE | |
| `code` | varchar(64) NOT NULL | snake_case; unique per attribute. |
| `label` | varchar(128) NOT NULL | display label. |
| `hex_color` | varchar(7) NULL | populated only when parent's `data_type=Color`. CHECK: hex_color matches `^#[0-9A-Fa-f]{6}$` or is NULL. |
| `image_url` | varchar(2048) NULL | populated only when parent's `data_type=Image`. |
| `display_order` | int NOT NULL DEFAULT 0 | |
| `created_at`, `updated_at` | timestamps | |

Constraints: `UNIQUE (attribute_id, code)`. Index on `(tenant_id, attribute_id, display_order)`.

**`product_variants`** — variant aggregate.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK (`HasUuids`) | |
| `tenant_id` | UUID NOT NULL | |
| `company_id` | UUID NOT NULL FK → `companies.id` CASCADE | mirrors `products.company_id` of parent. |
| `product_id` | UUID NOT NULL FK → `products.id` CASCADE | parent product. |
| `variant_code` | varchar(64) NOT NULL | human code, unique per product (e.g., `39-NOIR`). |
| `sku` | varchar(64) NOT NULL | tenant-unique SKU (matches `products.sku` constraint shape). |
| `barcode` | varchar(64) NULL | tenant-unique when present. |
| `name_suffix` | varchar(128) NOT NULL | rendered after parent name (e.g., `"39 / Noir"`). |
| `is_default` | bool NOT NULL DEFAULT false | at most one default per product. |
| `is_active` | bool NOT NULL DEFAULT true | |
| `display_order` | int NOT NULL DEFAULT 0 | |
| `price_override` | numeric(15,4) NULL | falls back to `products.sale_price` if NULL. |
| `cost_override` | numeric(15,4) NULL | falls back to `products.cost_price` if NULL. |
| `image_url` | varchar(2048) NULL | variant-specific image. |
| `created_at`, `updated_at`, `deleted_at` | timestamps | soft deletes (preserves audit + open-cart references). |

Constraints:
- `UNIQUE (product_id, variant_code)`.
- `UNIQUE (tenant_id, sku)` — matches `products.sku` per-tenant uniqueness.
- `UNIQUE (tenant_id, barcode) WHERE barcode IS NOT NULL` — partial index.
- Partial unique enforcing one default: `UNIQUE (product_id) WHERE is_default = true`.
- CHECK: `price_override IS NULL OR price_override >= 0`. Same for `cost_override`.

Indexes: `(tenant_id, product_id, is_active, display_order)`, `(tenant_id, company_id, is_active)`.

**Why `company_id` on variants?** The 2026-05-24 v2 spec did not include `company_id` on the variant table. Including it (matched to parent product's `company_id`) lets queries scope variants by company without joining through products, which the FEFO and POS paths do hundreds of times per shift. Codex's r1 review on v2 flagged the absence as a potential perf issue. Including it costs 16 bytes/row and a `companies.id` FK; the trade is worth it.

**`product_variant_attribute_values`** — junction; defines which axis values this variant carries.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID PK | |
| `variant_id` | UUID NOT NULL FK → `product_variants.id` CASCADE | |
| `attribute_id` | UUID NOT NULL FK → `product_attributes.id` RESTRICT | RESTRICT (not CASCADE) so deleting an attribute requires removing all variant assignments first — preserves variant integrity. |
| `attribute_value_id` | UUID NOT NULL FK → `product_attribute_values.id` RESTRICT | same rationale. |

Constraints: `UNIQUE (variant_id, attribute_id)`. Index on `(attribute_value_id)` for "which variants use this value?" queries.

### 4.2 Column additions to existing tenant-DB tables (all nullable)

| Table | Column | FK | Migration concern |
|---|---|---|---|
| `stock_levels` | `variant_id` (UUID NULL) | → `product_variants.id` ON DELETE RESTRICT | Drop+replace unique constraint per §4.4. |
| `stock_movements` | `variant_id` (UUID NULL) | → `product_variants.id` ON DELETE RESTRICT | Append-only; no constraint surgery. Add index `(tenant_id, product_id, variant_id, created_at)` for FEFO scans. |
| `stock_reservations` | `variant_id` (UUID NULL) | → `product_variants.id` ON DELETE RESTRICT | Update unique to `(tenant_id, product_id, variant_id, source_type, source_id)` if uniqueness exists today. |
| `product_batches` | `variant_id` (UUID NULL) | → `product_variants.id` ON DELETE RESTRICT | **Removes the TODO** at `:23`. Drop+replace unique per §4.4. |
| `document_lines` | `variant_id` (UUID NULL) | → `product_variants.id` ON DELETE RESTRICT | No existing constraint conflict. |
| `pos_receipt_lines` | `variant_id` (UUID NULL) | → `product_variants.id` ON DELETE RESTRICT | Add CHECK: `(variant_id IS NULL OR product_id IS NOT NULL)` — variants attach only to product-side lines, not composite-side. |
| `pos_order_lines` | `variant_id` (UUID NULL) | → `product_variants.id` ON DELETE RESTRICT | Symmetric with `pos_receipt_lines`. |
| `pos_receipt_line_batch_allocations` | `variant_id` (UUID NULL) | → `product_variants.id` ON DELETE RESTRICT | Preserves the batch↔variant audit link. |
| `catalog_cart_items` | `variant_id` (UUID NULL) | → `product_variants.id` ON DELETE RESTRICT | Ecommerce surface; §9.1. |
| `price_list_items` | `variant_id` (UUID NULL) | → `product_variants.id` ON DELETE CASCADE | B2B surface; §9.2. Update unique constraint per §4.4. |
| `recipe_lines` | `component_variant_id` (UUID NULL) | → `product_variants.id` ON DELETE RESTRICT | Recipe ingredient at variant grain; §8. |
| `coupons.qualifying_product_ids` (JSON) | unchanged — see §6.7 | — | If a tenant wants coupon scope at variant grain in future, that's PR-after-T2. |

**Tables NOT touched** (intentional — see §5 vertical-specific impact):
- `marketplace_listings.source_product_id` — stays product-grain; if a marketplace sells variants, that's a future adapter sprint deliverable.
- `fraud_alerts.flagged_products` (JSON) — stays product-grain (fraud rules don't need to flag by colour).
- `workshop_work_order_lines.product_id` — automotive workshop parts; variants for parts are a separate workstream (automotive variants are typically per-vehicle-fitment, not per-size-colour, so the data model differs; §11 vertical examples).
- `enrichment_results.product_id` — AI enrichment is product-level metadata, variants inherit via parent FK.

### 4.3 Constraint additions (CHECK + partial unique)

**`pos_receipt_lines` and `pos_order_lines` co-existence with existing XOR:**
```sql
ALTER TABLE pos_receipt_lines ADD CONSTRAINT pos_receipt_lines_variant_requires_product
  CHECK (variant_id IS NULL OR product_id IS NOT NULL);
ALTER TABLE pos_order_lines ADD CONSTRAINT pos_order_lines_variant_requires_product
  CHECK (variant_id IS NULL OR product_id IS NOT NULL);
```
Combined with the existing `pos_receipt_lines_sellable_xor` (lines 36–40 of `2026_03_03_100000_add_composite_item_id_to_pos_receipt_lines.php`), this guarantees variant rows can never reference a composite_item — variants live in the product domain only.

**`product_variants.is_default` exclusivity:** the partial unique index `UNIQUE (product_id) WHERE is_default = true` prevents two defaults per product. Service-layer `setDefault()` (§6) clears previous default in the same transaction.

### 4.4 Partial-unique-index strategy for new `variant_id` columns

For every table whose pre-existing unique constraint scoped on `product_id`, we drop the old unique and replace it with two partial unique indexes — one for the `variant_id IS NULL` rows (preserves pre-migration semantics) and one for `variant_id IS NOT NULL` rows. PostgreSQL treats NULLs as distinct in unique indexes; the partial-index pair handles this correctly.

**`stock_levels`** (existing: `UNIQUE (tenant_id, product_id, location_id)`):
```sql
DROP INDEX stock_levels_tenant_id_product_id_location_id_unique;
CREATE UNIQUE INDEX stock_levels_non_variant
  ON stock_levels (tenant_id, product_id, location_id)
  WHERE variant_id IS NULL;
CREATE UNIQUE INDEX stock_levels_with_variant
  ON stock_levels (tenant_id, product_id, variant_id, location_id)
  WHERE variant_id IS NOT NULL;
```
**Note:** `company_id` is deliberately not in the unique key shape — see §3 path 7 explanation. Tightening to include `company_id` (when it becomes non-nullable) is a follow-up migration.

**`product_batches`** (existing: `UNIQUE (company_id, product_id, batch_number)`; `company_id` is NOT NULL on this table per migration line 19, so safe to include):
```sql
DROP INDEX product_batches_company_id_product_id_batch_number_unique;
CREATE UNIQUE INDEX product_batches_non_variant
  ON product_batches (company_id, product_id, batch_number)
  WHERE variant_id IS NULL;
CREATE UNIQUE INDEX product_batches_with_variant
  ON product_batches (company_id, product_id, variant_id, batch_number)
  WHERE variant_id IS NOT NULL;
```
Removes the TODO comment at `2026_01_05_150000:23`.

**`price_list_items`** (existing: `UNIQUE (price_list_id, product_id, min_quantity)`):
```sql
DROP INDEX price_list_items_price_list_id_product_id_min_quantity_unique;
CREATE UNIQUE INDEX price_list_items_non_variant
  ON price_list_items (price_list_id, product_id, min_quantity)
  WHERE variant_id IS NULL;
CREATE UNIQUE INDEX price_list_items_with_variant
  ON price_list_items (price_list_id, product_id, variant_id, min_quantity)
  WHERE variant_id IS NOT NULL;
```

**`stock_reservations`** — if a unique constraint exists on `(product_id, source_type, source_id)` (verify in migration), apply the same pattern. If none exists, only add `variant_id` column + index.

**Acceptance check:** dual-row insertion test — insert `(tenant, product, location, variant=NULL)` and `(tenant, product, location, variant=X)` — both succeed; duplicate of either fails. Tested per acceptance criterion in §10.

### 4.5 Migration ordering and online-DDL safety

All `ALTER TABLE` operations are nullable-add or constraint-replace; PostgreSQL handles these with minimal locking on tables of moderate size (our largest tenant `stock_movements` is ~5M rows; well within an acceptable maintenance-window).

Ordering matters across the migration sequence (because the new FKs reference `product_variants.id`):

```
01  create product_attributes
02  create product_attribute_values        (FK → 01)
03  create product_variants                (FK → products)
04  create product_variant_attribute_values (FK → 03, 01, 02)
05  add variant_id to stock_levels         (FK → 03; partial-index swap)
06  add variant_id to stock_movements      (FK → 03; index)
07  add variant_id to stock_reservations   (FK → 03; index)
08  add variant_id to product_batches      (FK → 03; partial-index swap; removes TODO)
09  add variant_id to document_lines       (FK → 03; index)
10  add variant_id to pos_receipt_lines    (FK → 03; CHECK)
11  add variant_id to pos_order_lines      (FK → 03; CHECK)
12  add variant_id to pos_receipt_line_batch_allocations  (FK → 03)
13  add variant_id to catalog_cart_items   (FK → 03; index)
14  add variant_id to price_list_items     (FK → 03; partial-index swap)
15  add component_variant_id to recipe_lines (FK → 03; index)
```

**Reversibility:** every migration has a working `down()`. Dropping `variant_id` columns is straightforward; restoring the old unique constraints requires the partial indexes to be dropped first. The down path is exercised by the standard `migrate:rollback` test in CI.

---

## 5. Cross-cutting impact map (product_id → product_id+variant_id)

Exhaustive enumeration of every backend reference site. Codex round-1 review will fact-check this list — gaps are findings. The map is organized by module; within each module the impact is graded:

- **STOCK-CRITICAL** — must be variant-aware on day 1; data correctness depends on it.
- **SURFACE** — must be variant-aware to satisfy owner non-negotiables (recipe / B2B / ecommerce / POS).
- **OPTIONAL** — variants can be carried transparently as a passthrough; no service logic changes.
- **OUT OF SCOPE** — explicitly deferred to a future workstream; reasoned in §11 vertical impact.

### 5.1 Inventory module — STOCK-CRITICAL

Reference sites: `apps/api/app/Modules/Inventory/Domain/StockLevel.php`, `StockMovement.php`, `StockReservation.php`, `InventoryCountingItem.php`. Service callsites: `StockAdjustmentService::receive / issue / transfer / reserve / release`, `StockQueryService::getStockLevel`, `GoodsReceiptService::finalize`, `InventoryCountingService::*`.

T2 work:
- Every service method gains an optional `?UUID $variantId = null` parameter.
- All Eloquent queries that filter by `product_id` add a `where('variant_id', $variantId)` or `whereNull('variant_id')` based on the input.
- `StockLevel` row uniqueness handled by §4.4 partial indexes; no service-level dedup needed.
- New invariant (§6.6): once a product has any active variant, all its `stock_levels` rows must have `variant_id` set (no mixed-mode). Enforced by `ProductVariantService::createVariant` migrating any pre-existing product-level stock to the default variant in the same transaction.

`InventoryCountingItem.variant_id` already exists (pre-positioned at `2025_12_02_070002_create_inventory_counting_items_table.php:24` per agent reconnaissance). No migration needed; service-layer just stops passing NULL when product has variants.

### 5.2 Document module — STOCK-CRITICAL + SURFACE

Reference sites: `Document/Domain/DocumentLine.php` (line item for invoices, sales orders, purchase orders, credit notes, delivery notes, return notes, refund documents). Events: `DraftLineAddedV2`, `DraftLineModifiedV2`, `DraftLineRemoved`, `SalesOrderConfirmed`, plus their predecessor V1 events.

T2 work:
- `document_lines.variant_id` (nullable, §4.2).
- `DraftLineAddedV3` — new event payload includes `variantId`, `variantName` (the snapshot suffix), `variantSku`. V2 stays for backward compat (existing replay log).
- `DraftLineModifiedV3`, `DraftLineRemovedV2` (current is V1).
- Document printing (PDF + email + receipt) renders parent name + variant `name_suffix` when present. The translation key for this is `document.line.variant_label` in i18n.
- `product_code` snapshot column (`document_lines.product_code`, added 2025-12-22) gains a sibling `variant_code` snapshot column for audit fidelity.

### 5.3 Pricing module — SURFACE (B2B)

Reference sites: `Pricing/Domain/PriceListItem.php`, `PartnerPriceList.php`, `Pricing/Domain/Services/PricingService.php` (`::getPrice($priceListId, $productId, $quantity)`).

T2 work (cross-references §9.2):
- `price_list_items.variant_id` (nullable, §4.2).
- `PricingService::getPrice` gains optional `?UUID $variantId = null`. Resolution order:
  1. Variant-specific row `(price_list_id, product_id, variant_id, min_quantity)` matched.
  2. Variant-agnostic row `(price_list_id, product_id, NULL, min_quantity)` matched.
  3. Product `sale_price` fallback.
  4. Variant `price_override` (when set, takes precedence over product fallback).
- Resolution-order documentation lands in `apps/api/app/Modules/Pricing/README.md` (new doc — keeps the contract findable).
- Partner price-list flow unchanged at the linking layer (`partner_price_lists` table); the linked `price_list_items` now optionally carry variant_id.

This is **not** the same as the T11-impl-B `PricingStrategyResolver` (channel-override + partner-price-list + default-price-list + base-price resolution chain). T11 wraps PricingService; T2 makes PricingService variant-aware. The two compose cleanly.

### 5.4 Batch module — STOCK-CRITICAL

Reference sites: `BatchExpiry/Domain/Entities/Batch.php`, `BatchStock.php`, `BatchMovement.php`. Services: `BatchStockService::findOrCreateBatch / transferBatchStock / writeOff`, `FEFOInventoryService::suggestBatchesForSale / getExpiredBatchesWithStock`. Controller `BatchController`.

T2 work:
- `product_batches.variant_id` (nullable, §4.2). Removes the TODO.
- `BatchStockService::findOrCreateBatch(?UUID $variantId = null, ...)` — when null, batch is product-scoped; when set, batch is variant-scoped.
- `FEFOInventoryService::suggestBatchesForSale($productId, $locationId, $qty, ?UUID $variantId = null)` — query filters by `(product_id, variant_id)` tuple, orders by `expiry_date ASC` (FEFO unchanged).
- New invariant: when a product has variants and `requires_batch_tracking=true`, batches MUST be variant-scoped. Service-layer raises `MissingVariantException` if a service tries to create a product-level batch for a variant-bearing product.
- Recall + write-off APIs unchanged at the controller surface — batches stay first-class, with optional `variant_id` decoration.

### 5.5 Catalog (Recipe + CompositeItem) — SURFACE

Reference sites: `Catalog/Domain/Entities/CompositeItem.php`, `Recipe.php`, `RecipeLine.php`, `CompositeItemVariant.php`, `Enums/ComponentType.php`. Services: `CompositeItemAvailabilityService`, `RecipeCostCalculationService`.

T2 work — fully spelled out in §8 (recipe + variant + expiry inheritance). Headline:
- `recipe_lines.component_variant_id` (UUID nullable) added.
- `ComponentType` enum stays `Product | CompositeItem`. We do NOT add a `ProductVariant` case — keeping the binary distinction simpler and letting the optional `component_variant_id` column carry the refinement when `component_type = Product`.
- `RecipeCostCalculationService` updated to consume variant `cost_override` when present.
- New service `RecipeExpiryService::resolveEarliestExpiry($recipeId, $locationId)` returning the minimum expiry date across the variant-batches that would be drawn FEFO for each ingredient.
- `CompositeItemAvailabilityService::getAvailableQuantity` updated to consume variant stock when ingredients are variant-scoped.

### 5.6 POS module — STOCK-CRITICAL + SURFACE

Reference sites: `POS/Domain/ReceiptLine.php`, `OrderLine.php`. Services: `ReceiptCreationService` (including `decrementStock` at lines 837–904), `OrderManagementService`, `ReceiptVoidService`, `ReceiptReturnService`, `OrderToReceiptService`. Projection: `PosCoreReceiptProjection`.

T2 work:
- `pos_receipt_lines.variant_id`, `pos_order_lines.variant_id`, `pos_receipt_line_batch_allocations.variant_id` (§4.2).
- `ReceiptCreationService::decrementStock` rewritten to read each line's `variant_id` and decrement variant-scoped `stock_levels` row. **Critical** — this is the path that bypasses `StockAdjustmentService`; v2 P1-3 caught this; we honor it.
- `OrderLineData` DTO gains `variantId: string | null`.
- `PosCoreReceiptProjection` includes `variant_id` in the projection row so reporting downstream can dimension by variant.
- Wave 2 POS UI deltas: variant picker modal, barcode-to-variant resolution, cart line display with variant suffix, SQLite schema migration. See §10.4.

### 5.7 Cart / Catalog ecommerce — SURFACE

Reference sites: `Cart/Domain/Models/CatalogCartItem.php`, `Cart/Application/Services/CartService`, `CartConversionService`, `CatalogCartController`.

T2 work (cross-references §9.1):
- `catalog_cart_items.variant_id` (nullable, §4.2).
- `CartService::add(productId, variantId?, qty)` — variant required when the product has any active variant; otherwise null.
- Cart-to-document conversion writes `variant_id` to the resulting `document_lines`.

### 5.8 Channel sync (omnichannel) — SURFACE

Reference sites: `Channel/Domain/Models/ChannelProductMapping.php`, `Channel/Application/Services/ChannelService`, `DispatchProductToChannelJob`, `DispatchStockChangeToChannels`, `ChannelReconciliationJob`. Migrations: `channel_product_mappings.variant_id` ALREADY EXISTS (`2026_05_24_120002:17`), composite unique already includes it.

T2 work — small (channel layer is pre-positioned; agent confirmed):
- `ChannelService::publishProduct($productId, ?UUID $variantId = null)` — variant-aware publishing.
- `DispatchStockChangeToChannels` listener — when `StockMovementRecorded` carries a `variantId` (V3 event), propagate to channels with variant scope.
- No new migrations.
- Concrete WooCommerce / Shopify adapter still deferred to channel-adapter sprint (only the mapping contract changes here).

### 5.9 Loyalty / Coupon / Promotion / Compliance / Marketplace — OPTIONAL or OUT OF SCOPE

- **Loyalty** (`loyalty_registry`) — earn-rate rules use category not product; no variant change needed. Out of T2 scope.
- **Coupon** (`coupons.qualifying_product_ids` JSON) — stays product-grain in T2. Variant-grain coupons are a follow-up; cost ≈ 2 PD, defer.
- **Promotion** (`Promotion/Domain/Services/PromotionEvaluationService`) — `CartItemContext.product_id` stays product-grain in T2 evaluations; variant-grain promotions defer.
- **Compliance/Fraud** (`fraud_alerts.flagged_products`) — product-grain.
- **Marketplace** (`marketplace_listings.source_product_id`) — product-grain; the variant fan-out to marketplace listings is a future sprint deliverable.

### 5.10 Workshop / Automotive parts — OUT OF SCOPE for size/colour variants

Workshop work order lines reference parts (products). Automotive variants (per-vehicle-fitment, e.g., "this brake pad fits 2018 Peugeot 308 1.6 HDi") are a fundamentally different data model from size/colour variants — they're typically a fitment-lookup product attribute, not a stock-distinct variant. The parapharmacy use-case (size/colour) drives T2; automotive fitment is a future workstream that **may** reuse the `ProductAttribute` taxonomy but **will not** reuse `stock_levels.variant_id` (because a single physical part fits many vehicles).

This is explicitly called out so a Codex reviewer doesn't flag the absence of workshop variant integration as a blocker.

### 5.11 Frontend TypeScript DTOs — passthrough

All TypeScript DTOs are generated from PHP DTOs via `php artisan typescript:transform` (CLAUDE.md rule 7). T2 changes the PHP DTOs (`OrderLineData`, `DocumentLineData`, `BatchData`, `RecipeLineData`, `PriceListItemData`, `CatalogCartItemData`); regenerated TS types automatically carry `variantId?: string`. The hand-written components consume the new field where needed (matrix editor, variant picker, cart line suffix).

---

## 6. Service contracts and domain events

### 6.1 New services (Catalog module — alongside existing CompositeItem*)

```php
namespace App\Modules\Catalog\Application\Services;

final class AttributeService
{
    public function __construct(private readonly AttributeRepository $repo) {}

    public function create(CreateAttributeCommand $cmd): ProductAttribute;
    public function update(UpdateAttributeCommand $cmd): ProductAttribute;
    public function addValue(AddAttributeValueCommand $cmd): ProductAttributeValue;
    public function listForTenant(): Collection;
}

final class ProductVariantService
{
    public function __construct(
        private readonly ProductVariantRepository $repo,
        private readonly StockLevelMigrationService $stockMigrator,
        private readonly EventDispatcher $events,
    ) {}

    public function createVariant(CreateVariantCommand $cmd): ProductVariant;
    public function generateMatrix(UUID $productId, array $attributeIds): Collection;
    public function setDefault(UUID $variantId): ProductVariant;
    public function deactivate(UUID $variantId): ProductVariant;
    public function resolveBarcode(string $barcode, UUID $companyId): ProductVariant|Product|null;
    public function resolveSku(string $sku, UUID $companyId): ProductVariant|Product|null;
}

final class ProductVariantMatrixGenerator
{
    public function generate(UUID $productId, array $attributeIds, array $excludedCombos = []): array; // cartesian minus excluded
}
```

`CreateVariantCommand` is a DTO carrying `productId`, `variantCode`, `sku`, optional `barcode`, `nameSuffix`, `priceOverride`, `costOverride`, `imageUrl`, array of `(attributeId, attributeValueId)` tuples, and `isDefault`. Strict types throughout.

### 6.2 Modified service signatures (other modules)

```php
// Inventory
StockAdjustmentService::receive(UUID $productId, UUID $locationId, string $quantity, /* ... */, ?UUID $variantId = null): StockMovement;
StockAdjustmentService::issue(/* same shape */, ?UUID $variantId = null): StockMovement;
StockAdjustmentService::transfer(/* same shape */, ?UUID $variantId = null): array;
StockAdjustmentService::reserve(/* same shape */, ?UUID $variantId = null): StockReservation;
StockQueryService::getStockLevel(UUID $productId, UUID $locationId, ?UUID $variantId = null): StockLevel|null;

// Batch
BatchStockService::findOrCreateBatch(UUID $productId, string $batchNumber, /* ... */, ?UUID $variantId = null): Batch;
BatchStockService::transferBatchStock(UUID $batchId, UUID $fromLocation, UUID $toLocation, string $qty): void; // batch_id already carries variant_id implicitly
FEFOInventoryService::suggestBatchesForSale(UUID $productId, UUID $locationId, string $qty, ?UUID $variantId = null): Collection;

// Pricing
PricingService::getPrice(UUID $priceListId, UUID $productId, string $quantity, ?UUID $variantId = null): string;

// POS
ReceiptCreationService::decrementStock(/* ... */): void; // reads variant_id from line, decrements correct stock row

// Catalog (recipes)
CompositeItemAvailabilityService::getAvailableQuantity(UUID $compositeItemId, UUID $locationId): string; // resolves variant ingredients
RecipeCostCalculationService::calculate(UUID $recipeId): string; // uses variant cost_override when present
RecipeExpiryService::resolveEarliestExpiry(UUID $recipeId, UUID $locationId): ?Carbon; // NEW (§8)

// Cart / catalog ecommerce
CartService::add(UUID $cartId, UUID $productId, string $qty, ?UUID $variantId = null): CatalogCartItem;
```

**Backward-compat invariant:** every modified signature places `?UUID $variantId = null` as a trailing optional parameter. No call-site that passes positional args breaks (call-sites using named args are explicit). Every implementation has the `$variantId = null` branch behaving identically to today.

### 6.3 New repository contracts

```php
interface ProductVariantRepository
{
    public function findById(UUID $id): ?ProductVariant;
    public function findByBarcode(string $barcode, UUID $companyId): ?ProductVariant;
    public function findBySku(string $sku, UUID $companyId): ?ProductVariant;
    public function listForProduct(UUID $productId, bool $onlyActive = true): Collection;
    public function save(ProductVariant $variant): void;
    public function softDelete(UUID $id): void;
}

interface AttributeRepository { /* mirror */ }
interface ProductVariantAttributeValueRepository { /* mirror */ }
```

All repositories use the standard Eloquent infrastructure under `Catalog/Infrastructure/Repositories/` with Stancl tenant context.

### 6.4 Domain events (immutable rule 8 — every shape change creates a new V)

**New events (V1 — never carry product without variant):**
- `ProductVariantCreated(variantId, productId, tenantId, companyId, sku, variantCode, isDefault)`
- `ProductVariantUpdated(variantId, productId, [...changedFields])`
- `ProductVariantDeactivated(variantId, productId)`
- `ProductVariantSetAsDefault(variantId, productId, previousDefaultVariantId | null)`
- `ProductAttributeCreated(attributeId, tenantId, code, dataType)`
- `ProductAttributeValueAdded(attributeValueId, attributeId, code, label)`

**Existing events that gain `variantId` — must be V'ed:**
- `DraftLineAddedV2` → spawn `DraftLineAddedV3` adding `variantId`, `variantName`, `variantSku`. V2 stays alive for replay.
- `DraftLineModifiedV2` → `DraftLineModifiedV3`.
- `DraftLineRemoved` (V1) → `DraftLineRemovedV2` adding `variantId`.
- `SalesOrderConfirmed` — carries line items by reference; the snapshot in the audit payload references `variantId` from line state. If `SalesOrderConfirmed` previously serialized lines into its payload, spawn `SalesOrderConfirmedV2`; if it merely references line IDs, no V bump needed. Implementation MUST verify the current payload shape — verify pre-implementation, document the decision in the impl PR.
- `StockMovementRecorded` — payload-shape change. Spawn `StockMovementRecordedV2` carrying `variantId`. Existing V1 stays alive.
- `ReservationCreated`, `ReservationExpired`, `ReservationReleased` — V2 spawns adding `variantId`.

**Events deferred (justification):**
- `ProductCreated`, `ProductUpdated`, `ProductCostPriceUpdated` — unchanged. Variants don't impact product-level events.
- Channel events (`ProductPublishedToChannel`, `ProductUnpublishedFromChannel`) — already accept nullable `variantId` via the mapping; payload may already accommodate. Verify before spawning V2.

### 6.5 New CHECK / invariant: no mixed mode within a product

Once a product has at least one `is_active=true` variant, every `stock_levels`, `stock_movements`, `stock_reservations`, `product_batches`, `document_lines`, `pos_receipt_lines` row that references that `product_id` MUST also carry `variant_id`. Enforced by:

1. `ProductVariantService::createVariant` — when creating the **first** active variant for a product, runs `StockLevelMigrationService::migrateToDefaultVariant($productId, $defaultVariantId)` in the same DB transaction. This re-writes any existing product-level `stock_levels` rows (`variant_id=NULL`) to the new default variant. Documented atomic operation, fail-safe rollback on error.
2. Service-layer guard in `StockAdjustmentService` (and POS `decrementStock`): when invoked with `productId` for a product that has variants and `variantId=null`, raises `VariantRequiredException`. CHECK invariant; no silent fallback.
3. Test fixture: `tests/Feature/Catalog/ProductVariantNoMixedModeTest.php`.

### 6.6 New service: `StockLevelMigrationService`

```php
final class StockLevelMigrationService
{
    public function migrateToDefaultVariant(UUID $productId, UUID $defaultVariantId): void;
    // Within a transaction:
    //   - UPDATE stock_levels SET variant_id = $defaultVariantId WHERE product_id = $productId AND variant_id IS NULL
    //   - UPDATE stock_movements ditto for open / unreversed rows? — NO, movements are append-only and historical context is product-level — leave them
    //   - UPDATE stock_reservations ditto WHERE released_at IS NULL
    //   - UPDATE product_batches ditto WHERE is_active = true
    //   - Emit StockLevelsMigratedToDefaultVariant event
}
```

**Critical** — historical `stock_movements` (already-recorded receives/issues) MUST NOT be retroactively assigned to a variant. They are append-only audit records; rewriting them corrupts history. Only *open / unreversed / active* state is migrated. The acceptance criteria in §10 verify this distinction.

### 6.7 Out-of-scope changes (explicit non-goals)

- **Coupon variant scoping** — `coupons.qualifying_product_ids` JSON stays product-grain.
- **Marketplace listing fan-out** — `marketplace_listings` stays product-grain.
- **Variant-specific metadata** — `ParapharmacyProductMetadata`, `AutomotiveProductMetadata` stay on parent product. Variants inherit. If a vertical eventually needs variant-grain metadata (e.g., per-size dosage for liquid medicines), that's a future PR.
- **Variant-level cost calculation in WAC** — WAC stays company-wide per product per memory `project_inventory_costing`. Variants don't introduce per-variant cost. Variant `cost_override` is a display/pricing fallback only; `LandedCostService` and the WAC chain continue at product grain. Confirmed compatible by the §8 recipe math (recipes use `cost_override` when set, otherwise product cost).

---

## 7. Batch and FEFO at variant grain

Section 4.5 of v2 spec describes this in summary; this section spells out the algorithmic detail.

### 7.1 Variant-aware batch creation

When a product has `requires_batch_tracking=true` and has any active variants, every batch row MUST carry `variant_id`. This is enforced by `BatchStockService::findOrCreateBatch` raising `MissingVariantException` if called with `variantId=null` against a variant-bearing product.

Goods receipt flow:
1. PO references variant: GoodsReceipt UI requires variant selection per receipt line (or implicit via barcode).
2. Receipt processing calls `BatchStockService::findOrCreateBatch($productId, $batchNumber, $expiry, ..., $variantId)`.
3. New batch row carries `variant_id`; unique-index pair from §4.4 enforces that two batches with the same `batch_number` can coexist across variants.
4. `inventory_batch_stock` row created; `inventory_batch_movements` audit row written; `StockMovementRecordedV2` event emitted (V2 carries `variantId`).

### 7.2 FEFO selection at variant grain

`FEFOInventoryService::suggestBatchesForSale($productId, $locationId, $qty, ?UUID $variantId = null)` query (simplified):

```sql
SELECT b.id, b.batch_number, b.expiry_date, ibs.available_quantity
FROM product_batches b
JOIN inventory_batch_stock ibs ON ibs.batch_id = b.id
WHERE b.tenant_id = :tenant
  AND b.product_id = :product
  AND (
    (:variantId IS NULL AND b.variant_id IS NULL)
    OR (:variantId IS NOT NULL AND b.variant_id = :variantId)
  )
  AND b.is_active = true
  AND b.is_recalled = false
  AND b.expiry_date >= CURRENT_DATE
  AND ibs.location_id = :location
  AND ibs.available_quantity > 0
ORDER BY b.expiry_date ASC, b.created_at ASC
LIMIT :qty;
```

When the product has no variants (`variant_id=NULL`), the query returns product-scoped batches — backward compat. When the product has variants and `variantId` is passed, the query returns only variant-scoped batches. **Mixed-mode** queries (where some legacy batches are product-scoped and some are variant-scoped for the same product) are forbidden by the §6.5 invariant — `StockLevelMigrationService` re-scopes batches at variant introduction.

### 7.3 Expiry detection at variant grain

The daily expiry-check job (`UpdateBatchExpiryStatusJob`, scheduled in Laravel Scheduler) iterates all active batches per tenant and updates `is_expired` when `expiry_date < today()`. Variant_id is incidental to the check; the job continues unchanged. **Performance note** — the job's loop currently scans `product_batches`; the new `variant_id` index `(tenant_id, product_id, variant_id, expiry_date)` keeps it fast.

Expiry notifications (`certification_expiry_notifications`) attach to product today; T2 adds `variant_id` (nullable) to that table per §4.2 supplement (added to the impact map late — see verification check), but if not present in the existing schema this is a minor follow-on PR not blocking T2.

### 7.4 Recall semantics

`Batch::recall($reason)` works unchanged — recalled batches are excluded from FEFO regardless of variant_id. If a recall affects an entire variant (e.g., manufacturing defect on size 39), the operator recalls each affected batch individually. Variant-grain recall as a single operation is a future ergonomic improvement; out of T2 scope.

---

## 8. Recipe + Variant + Expiry inheritance (owner non-negotiable #3)

**This section is the biggest delta from v2 spec.** v2 explicitly excluded recipes from variant integration. The 2026-05-28 briefing makes it non-negotiable: "Composite products / recipes can be built FROM variations of existing products. Selling a recipe consumes batches of its ingredient variants. Recipe expiry derives from the earliest-expiring ingredient batch."

### 8.1 The two use-cases

**8.1.1 Parapharmacy combo (sized).** A parapharmacy sells "Orthopedic Care Kit" = (1 orthopedic shoe, size N) + (1 bottle hosiery, size matching) + (1 jar of foot cream, 50ml). The kit is a `CompositeItem` whose recipe references three products; two of the three are variant-bearing (shoe by size, hosiery by size). When the cashier scans the kit barcode, the POS must (a) ask "what size?" once and propagate to both variant-bearing ingredients, OR (b) treat the kit as a variant-bearing composite (CompositeItem variants — already exist as `CompositeItemVariant`).

T2 picks **option (b)** because `CompositeItemVariant` already exists. The mechanism:
- The kit's `CompositeItem` has variants (e.g., `Kit-39`, `Kit-40`, `Kit-41`).
- Each `CompositeItemVariant` is linked to an underlying recipe (`Recipe.id`).
- Each recipe has its own `RecipeLine`s; for variant-bearing ingredients, `recipe_lines.component_variant_id` points to the size-matched variant.
- Selling a `CompositeItemVariant` consumes the variant-matched ingredient batches.

This pattern keeps the recipe explicit (operator decides which variant goes with which kit variant) and avoids implicit size-cascading magic that's brittle.

**8.1.2 F&B coffee shop recipe.** "Cappuccino, Large" recipe references (espresso shot, 18g; milk, 200ml). Neither ingredient is variant-bearing (espresso bean is just a product; milk is a product). Recipe expiry derives from the earliest of the FEFO-selected ingredient batches — espresso bean batches and milk carton batches.

Both ingredients can be product-grain; T2's recipe expiry algorithm works whether ingredients are variant-scoped or product-scoped.

### 8.2 Schema: recipe lines at variant grain

Add `recipe_lines.component_variant_id` (UUID, nullable, FK to `product_variants.id`, ON DELETE RESTRICT). Index on `(component_variant_id)` for "which recipes use this variant?" queries.

**Why not extend `ComponentType` enum to add `ProductVariant`?** Considered. Rejected because:
1. A variant *is* a product — extending the enum splits the polymorphism artificially.
2. Many queries today join on `component_type='product' AND component_id = products.id`. Adding a third case `'product_variant'` forces every consumer to handle three branches.
3. Adding `component_variant_id` as a refinement column when `component_type='product'` keeps the existing two-way polymorphism intact and makes the variant relationship optional and explicit.

**CHECK constraint:**
```sql
ALTER TABLE recipe_lines ADD CONSTRAINT recipe_lines_variant_requires_product
  CHECK (component_variant_id IS NULL OR component_type = 'product');
```
Composite-item ingredients are never variant-scoped (CompositeItem variants are a different mechanism — see 8.1.1).

### 8.3 Service surface

```php
// RecipeService — existing, gains variant awareness
RecipeService::addLine(UUID $recipeId, ComponentType $type, UUID $componentId, ?UUID $componentVariantId = null, /* ... */): RecipeLine;

// CompositeItemAvailabilityService — existing, signature unchanged but logic upgraded
public function getAvailableQuantity(UUID $compositeItemId, UUID $locationId): string
{
    // For each active recipe line:
    //   - If component_variant_id IS NOT NULL: query stock_levels for (product_id, variant_id, location_id)
    //   - Else: query stock_levels for (product_id, NULL, location_id)
    //   - Divide ingredient stock by recipe line quantity * recipe yield_quantity
    // Return min across all ingredients
}

// RecipeCostCalculationService — existing, signature unchanged
public function calculate(UUID $recipeId): string
{
    // For each line:
    //   - If component_variant_id IS NOT NULL: use variant's cost_override (or fall back to product cost)
    //   - Else: use product cost
    // Sum (unit_cost * quantity * (1 + wastage_percent/100))
}

// RecipeExpiryService — NEW
final class RecipeExpiryService
{
    public function __construct(private readonly FEFOInventoryService $fefo) {}

    /**
     * Returns the earliest expiry date across the batches that FEFO would draw
     * to fulfill ONE unit of the recipe at the given location. Returns null if
     * no ingredients are batch-tracked (recipe has no derivable expiry).
     */
    public function resolveEarliestExpiry(UUID $recipeId, UUID $locationId): ?\Carbon\Carbon
    {
        $earliest = null;
        foreach ($recipe->lines as $line) {
            $batches = $this->fefo->suggestBatchesForSale(
                productId: $line->component_id,
                locationId: $locationId,
                qty: bcmul($line->quantity, $line->recipe->yield_quantity, 4),
                variantId: $line->component_variant_id,
            );
            if ($batches->isEmpty()) {
                continue; // ingredient not batch-tracked; skip
            }
            $lineExpiry = $batches->first()->expiry_date; // FEFO returns earliest first
            if ($earliest === null || $lineExpiry < $earliest) {
                $earliest = $lineExpiry;
            }
        }
        return $earliest;
    }
}
```

### 8.4 Sale of recipe → ingredient batch consumption

When a `CompositeItem` (or `CompositeItemVariant`) is sold:
1. POS / sales path creates a `pos_receipt_line` (or `document_line`) with `composite_item_id` set.
2. **Inventory dispatch** (existing pattern in `OrderToReceiptService` and the F&B sale flow): for each recipe line, dispatch a `StockMovement` (issue) for `quantity * recipe.yield_quantity` of the ingredient.
3. For variant-scoped ingredients: the issue references `(product_id, variant_id)`; FEFO selects variant-batches; `inventory_batch_movements` audit rows link the batches to the parent receipt-line.
4. For product-scoped ingredients (F&B case): existing behavior, no variant_id on the issue.

**Critical correctness invariant**: the per-ingredient `StockMovementRecordedV2` events carry `variantId` when applicable, so reporting + reconciliation downstream sees the correct grain. No silent NULL variants.

### 8.5 Recipe expiry display

The POS and admin UI may want to show "earliest sellable date" for kits — derived from `RecipeExpiryService::resolveEarliestExpiry`. **No DB column** for recipe expiry — it's computed on read (the source-of-truth is the batches; pre-computing risks staleness). If perf demands it later, add a materialized view; out of T2 scope.

### 8.6 Recall propagation

When a variant-scoped batch is recalled and that batch is the only / earliest source for a recipe ingredient, the recipe becomes effectively unsellable. T2 does NOT implement automatic recipe disablement (recipes might still be sellable from later batches; logic is non-trivial). Instead:
- Operator-facing dashboard surfaces "recipes whose earliest expiry comes from recalled batches".
- Manual operator action to disable the affected `CompositeItem` / `CompositeItemVariant`.

This is consistent with existing batch-recall ergonomics; automation is a future workstream.

### 8.7 Coffee shop / F&B vertical compatibility

The F&B vertical typically does not flag ingredients with `requires_batch_tracking=true` (coffee beans aren't expiry-managed at lot grain in most shops). The recipe-expiry computation degrades gracefully — `RecipeExpiryService` returns `null` when no ingredients are batch-tracked, which the UI renders as "no expiry derived." No special-case code needed.

If a coffee shop later turns on batch tracking for milk (or chooses sized cups as variants), the same code path serves them.

---

## 9. Ecommerce and B2B surface (owner non-negotiable #4)

### 9.1 Ecommerce surface

The ecommerce surface has two layers in AutoERP: (a) the **catalog cart** (in-app B2C cart for browser users), (b) the **channel sync** (publishing to external channels like WooCommerce / Shopify).

**Catalog cart:**
- `catalog_cart_items.variant_id` (nullable) — §4.2.
- `CatalogCartItemData` DTO gains `variantId`.
- `CartService::add(UUID $cartId, UUID $productId, string $qty, ?UUID $variantId = null)`. When the product has any active variant, `$variantId` is required (raises `VariantRequiredException` if null).
- `CartConversionService` — when converting cart → document, propagates `variant_id` to `document_lines`.
- Variant picker on the catalog product detail page (`apps/web/src/features/catalog/components/ProductDetailVariantPicker.tsx` — new component); selecting a variant updates the cart-add intent's `variantId`.
- Stock availability shown per variant via `StockQueryService::getStockLevel($productId, $locationId, $variantId)`.
- Variant images: catalog rendering uses `variant.image_url` if set, else parent `product_images.url`.

**Channel sync:**
- `channel_product_mappings.variant_id` (nullable) — **already exists** (`2026_05_24_120002:17`).
- Channel mapping composite unique `(channel_id, product_id, variant_id)` — **already exists**.
- `ChannelService::publishProduct(UUID $productId, ?UUID $variantId = null)` — publishes one variant at a time. Bulk publish helper iterates all active variants.
- `DispatchStockChangeToChannels` listener — subscribes to `StockMovementRecordedV2`; reads `variantId`; calls `ChannelAdapter::syncStock($mapping, $qty)` for the matching mapping.
- WooCommerce / Shopify adapter implementations remain deferred to the channel-adapter sprint. T2 ships only the contract.

**Out-of-T2:**
- Per-channel variant overrides (per-channel description, per-channel image): deferred to a PIM extension.
- Variant-level SEO / canonical-URL handling: deferred.

### 9.2 B2B surface

**B2B sales** in AutoERP run through the existing `Document` module (web ERP) — quotes, sales orders, invoices — and use `PartnerPriceList` linking to scope prices per partner.

**T2 changes at variant grain:**
- `price_list_items.variant_id` (nullable) — §4.2.
- `PricingService::getPrice($priceListId, $productId, $qty, ?UUID $variantId = null)` resolution order: variant-specific row → variant-agnostic row → product `sale_price` → variant `price_override` fallback (see §5.3).
- B2B document line UI in web ERP gains a variant selector (`DocumentLineVariantSelector.tsx` — new component). For variant-bearing products, variant selection is required to complete the line.
- B2B price-list import / export tools (existing CSV importer at `apps/web/src/features/pricing/import/`) gain a `variant_code` column. Optional; when absent, the row is variant-agnostic (applies to all variants of that product).
- Quote → Sales Order → Invoice promotion preserves `variant_id` through the document state machine. The existing `DocumentService::promote` method passes line state through unchanged; `variant_id` rides along.

**T11 cohabitation (informational):** T11-impl-B introduces `PricingStrategyResolver` that wraps `PricingService` with channel-override + partner-resolution chain. T2's `PricingService::getPrice` becomes the variant-aware leaf that T11's resolver calls. The two compose cleanly (T11-impl-B passes through `variantId` to the wrapped call). **T2 does not depend on T11.** If T11-impl-B ships later, T11 adapts; if T11 ships before T2, T11's resolver signature already accommodates an optional `variantId` per the T11 spec — verify before T2 implementation.

**B2B-specific UX considerations:**
- Bulk-order forms (where a B2B customer enters quantities by SKU): each row in the bulk form maps to a (product, variant) pair via SKU. The SKU is variant-grained (`product_variants.sku`) and unique per tenant, so the existing SKU-lookup flow extends naturally — `ProductVariantService::resolveSku` returns variant or product accordingly.
- Customer-account credit limits + payment terms (T11-impl-A territory) are partner-level, not variant-level. No T2 changes.

### 9.3 Variant identity across surfaces (table summary)

| Surface | Identity used | Notes |
|---|---|---|
| Web ERP B2B doc line | `variant_id` (UUID FK) | Required when product has variants. |
| Catalog cart (B2C) | `variant_id` (UUID FK) | Required when product has variants. |
| Channel mapping | `variant_id` (UUID FK) | Already pre-positioned. |
| POS cart line | `variant_id` (UUID FK) on `pos_receipt_lines` | Cashier picks via modal (Wave 2). |
| POS barcode scan | barcode → `ProductVariant` resolution | `ProductVariantService::resolveBarcode`. |
| External: marketplace listing | `source_product_id` only | Variant fan-out deferred. |

---

## 10. Acceptance criteria

### 10.1 Domain + schema correctness

- [ ] Migration ordering 01–15 (§4.5) runs cleanly on a tenant DB with existing data; rollback works.
- [ ] Insert two rows in `stock_levels` for same `(tenant_id, product_id, location_id)` — one with `variant_id=NULL`, one with `variant_id=X` — both succeed. Duplicate of either fails. Same for `product_batches` and `price_list_items`.
- [ ] CHECK constraints reject illegal combos: `variant_id` set with `product_id=NULL` on `pos_receipt_lines` (XOR + variant_requires_product); `component_variant_id` set with `component_type='composite_item'` on `recipe_lines`.
- [ ] `product_variants.is_default` partial unique rejects two defaults per product.

### 10.2 Backward compat sweep

- [ ] Existing non-variant products: receive 10 units, sell 1, refund 1 — all paths identical to today; no `variant_id` in `stock_movements` for product-scoped batches.
- [ ] PHPUnit suite green with no test changes to existing non-variant feature tests.
- [ ] PHPStan L8 zero errors on touched files.
- [ ] All existing event V1/V2 listeners continue to work; V2/V3 events fire only when variant_id is non-null.
- [ ] Existing tests for `CompositeItemAvailabilityService`, `RecipeCostCalculationService`, `FEFOInventoryService` continue to pass when ingredients have no variant.

### 10.3 Variant-aware flows

- [ ] Create attribute `Taille` (Selection) with values 36–41; attribute `Couleur` (Color) with hex values for Noir/Blanc/Beige.
- [ ] Create product `Chaussure orthopédique X`; generate matrix `Taille × Couleur` → 18 variants created, unique SKUs, default variant set.
- [ ] Set price_override and barcode on three specific variants.
- [ ] Receive 10 units of `(Taille 39, Couleur Noir)` via PO — `stock_levels` row created with correct `variant_id`; `StockMovementRecordedV2` fires.
- [ ] Sell from POS — `decrementStock` writes correct variant row; `pos_receipt_line_batch_allocations` carries `variant_id`.
- [ ] FEFO at POS for `(Taille 39, Couleur Noir)` returns variant batches only.
- [ ] Existing scenario with non-variant product mixed in same receipt — separate lines, separate stock decrements, no cross-bleed.

### 10.4 POS Wave 2 deltas (logged to coordination log, gated by fiscal Phase-1)

- [ ] POS variant picker modal opens on tap of variant-bearing product; shows matrix with per-cell available stock.
- [ ] Direct barcode scan of variant barcode adds correct variant to cart; falls back to product when only product barcode matches.
- [ ] Cart line displays "Chaussure X — 39 / Noir".
- [ ] Receipt print + email include variant suffix.
- [ ] SQLite schema migration on POS device: `pos_receipt_lines + variant cache` columns added; offline mode works.
- [ ] Variant-aware sync to backend: queued offline transactions write `variant_id` correctly.

### 10.5 Recipe + variant + expiry

- [ ] Create `CompositeItem` "Orthopedic Care Kit" with variants `Kit-39`, `Kit-40`, `Kit-41`. Each variant's recipe references variant-matched shoe + hosiery + product-scoped foot cream.
- [ ] Sell `Kit-39` — stock movements decrement: shoe `(size 39)` variant batch; hosiery `(size 39)` variant batch; cream product-batch.
- [ ] `RecipeExpiryService::resolveEarliestExpiry($kitRecipe, $location)` returns earliest of the three ingredient batches.
- [ ] Recall one of the three ingredient batches — `resolveEarliestExpiry` after recall returns next-earliest non-recalled.
- [ ] F&B recipe with no batch-tracked ingredients: `resolveEarliestExpiry` returns `null` cleanly.

### 10.6 Ecommerce + B2B

- [ ] Add variant to catalog cart via product detail page; variant selector required when product has variants.
- [ ] Cart → quote → sales order → invoice promotion preserves `variant_id` end-to-end.
- [ ] B2B partner with `PartnerPriceList` containing variant-specific `price_list_items` — `PricingService::getPrice` returns variant price; without variant row, falls back to product row.
- [ ] B2B bulk-order SKU upload — variant SKUs resolve correctly; ambiguous SKUs raise validation error.
- [ ] Channel mapping for one variant — publishes via mock channel adapter; stock-change listener fires for correct mapping.

### 10.7 Vertical-modularity proofs

- [ ] Parapharmacy variant (orthopedic shoe, size × colour) — works.
- [ ] F&B composite (cappuccino + sized cup) — sized cup as `CompositeItemVariant` works without invoking product variants.
- [ ] Retail (sportswear, size × colour) — same parapharmacy code path serves.
- [ ] Automotive product without variants — works; workshop work-order line writes `variant_id=NULL`.

### 10.8 Multi-tenant isolation

- [ ] Variants from tenant A invisible to tenant B (DB-per-tenant boundary post-T6).
- [ ] Cross-tenant SKU collision allowed (each tenant has its own `(tenant_id, sku)` unique scope).

---

## 11. Vertical modularity examples

### 11.1 Parapharmacy (driving customer)

Orthopedic shoes: attributes `Taille` (Selection, values 36–41) + `Couleur` (Color, values Noir/Blanc/Beige). Matrix generates 18 variants.

Compression hosiery: attributes `Taille` (Selection, values S/M/L/XL) + `Compression` (Numeric, values 15/20/30 mmHg) + `Couleur` (Color). 24+ variants typical.

Sized cosmetics: attributes `Volume` (Numeric, values 30/50/100/200 ml). 4 variants.

All three use the same `ProductVariantService::generateMatrix` and `ProductVariantMatrixEditor` UI. The driving customer (Nénupharma) launches with these three product lines.

### 11.2 Retail (sportswear / fashion / footwear)

Same model: `Taille × Couleur` matrices. No code differences from parapharmacy. Demonstrated by acceptance test that swaps a parapharmacy product for a sportswear product and re-runs the variant flow.

### 11.3 Automotive (Otospex) — explicitly different

Automotive parts (brake pads, oil filters, spark plugs) typically have:
- Fitment attributes: per-vehicle-make / per-model / per-year compatibility. A single physical part fits many vehicles.
- OEM / cross-reference codes: many-to-many mapping (one of our part = several OEM part numbers).

These are **not** size/colour variants in the T2 sense — fitment is a search dimension, not a stock-distinguishing axis. The same physical SKU is stocked once and looked up by vehicle.

T2 leaves automotive products variant-free. The `automotive_product_metadata` tables already exist for fitment data. If a future automotive workstream needs both fitment AND a true variant dimension (e.g., a brake pad in two colours), the variant axis attaches; the fitment lookup stays on the parent product.

### 11.4 F&B (coffee shop, Tunisia secondary customer)

Coffee shop sells:
- Espresso (single product, no variants).
- Cappuccino (CompositeItem with recipe; no variants in standard menu).
- Cappuccino sizes (Small / Regular / Large) — modeled via `CompositeItemVariant` (existing mechanism), NOT product variants. Each `CompositeItemVariant` has a `recipe_multiplier` (Small = 0.8, Regular = 1.0, Large = 1.4) that scales the recipe yield + cost.

T2's recipe + variant integration serves the rare F&B case where a recipe ingredient is itself variant-scoped (e.g., a coffee shop also selling sized cups as an inventoried product variant — uncommon but plausible). The `RecipeExpiryService` handles both cases cleanly: variant ingredients return variant-batch expiry; product ingredients return product-batch expiry; products without batch tracking return null.

### 11.5 Pharmacy (regulated, future workstream — not in T2)

A regulated pharmacy (Tunisia ordonnance medicaments) has additional concerns: lot-level traceability for substances, ATC code per variant, prescription / dispensation logging. Parapharmacy ≠ pharmacy: parapharmacy sells non-prescription cosmetic / orthopedic items.

T2 builds the foundation parapharmacy needs; if a regulated-pharmacy workstream later opens, it can add variant-level regulatory metadata (e.g., `pharmacy_variant_metadata` table FK to `product_variants.id`) without touching the T2 schema.

---

## 12. Adversarial review checklist

**Reviewer instruction:** *Verify findings against actual code at cited file paths (post-T6 migration reorg). Read the files. Do not rely on summaries.* Pay special attention to:

1. **Cross-cutting impact completeness** — walk every module under `apps/api/app/Modules/` and confirm §5 enumerates every reference site. Gaps are P1 findings.
2. **Backward compat** — every modified service method works with `variant_id=null`. Grep callsites; verify each.
3. **Partial unique index correctness** — both indexes exist, behave correctly with NULL variant_id, do not weaken pre-T2 uniqueness.
4. **Migration ordering** — no FK reference to a not-yet-created table; rollback works for every migration.
5. **Event immutability (rule 8)** — every shape-changing event has a new V; old Vs not modified. Verify the `SalesOrderConfirmed` payload-shape question in §6.4.
6. **§6.5 no-mixed-mode invariant** — `StockLevelMigrationService` correctly moves only active state, not historical movements.
7. **Recipe expiry algorithm** — §8.3 `RecipeExpiryService::resolveEarliestExpiry` math correct; FEFO ordering preserved; null-return cases handled.
8. **POS `decrementStock` path** — v2 P1-3 finding addressed (`ReceiptCreationService` directly writes movements bypassing `StockAdjustmentService`); variant_id flows through.
9. **Concurrency** — variant-level batch consumption under contention. The existing batch consumption uses row-level locks via `SELECT ... FOR UPDATE` in `inventory_batch_stock`; adding `variant_id` to the filter doesn't change the lock semantics. **Verify** the FEFO + WAC concurrency story is not broken at variant grain.
10. **Vertical modularity** — §11 examples each verified by acceptance test in §10.7.
11. **Migration topology** — every new migration in `apps/api/database/migrations/tenant/`; no cross-DB FKs.
12. **Naming** — service class is `ProductVariantService` (not `VariantService` — avoid `ImageVariantService` collision).
13. **PHPStan L8** — zero new errors on touched files; no `mixed`; constructor injection only; enums for all status/type columns.
14. **TDD discipline** — every new method has a failing test before implementation. Per CLAUDE.md rule 2.
15. **POS receipt printing** — variant suffix renders in PDF, thermal printer, email. Fonts + encoding handle hex colour names + size numerals.

---

## 13. Out of scope (explicit non-goals)

- Bulk variant CSV/Excel import — defer to next sprint.
- Variant-level workflow / draft-publish — defer to PIM extension.
- WooCommerce / Shopify / PrestaShop adapter implementations — defer to channel-adapter sprint.
- Marketplace listing fan-out to variants — defer.
- Variant-level coupon / promotion scoping — defer.
- Variant-level fraud rules — defer.
- Per-channel variant overrides (description, image) — defer to PIM extension.
- Automotive fitment-variant integration — separate workstream.
- Regulated pharmacy variant metadata — separate workstream.
- Variant-grain recall as a single operation — operator recalls per-batch today.
- Variant-grain workshop work-order parts — separate workstream.
- Per-variant WAC / per-variant cost ledger — out of scope (per `project_inventory_costing` memory; variants share product cost).

---

## 14. Workflow recommendation

Following the v2 spec's Opus/Codex split and the productization sprint workflow:

**Phase 1 (Opus, ~4 PD).** Schema + partial-index strategy + StockLevelMigrationService + RecipeExpiryService design. Write migrations 01–15 (§4.5). Write RecipeExpiryService + tests. PHPStan L8 + Pint clean.

**Phase 2 (Codex, ~6 PD).** Mechanical service ripple — every `?UUID $variantId = null` parameter, every Eloquent query update, every callsite update. Chunk by module:
- 2a Inventory (StockAdjustmentService, StockQueryService, GoodsReceiptService).
- 2b Batch (BatchStockService, FEFOInventoryService).
- 2c Pricing (PricingService variant resolution).
- 2d POS (ReceiptCreationService.decrementStock, OrderManagementService, projection).
- 2e Catalog (RecipeService.addLine, CompositeItemAvailabilityService).

Each sub-chunk has its own PR + Opus mini-review.

**Phase 3 (Opus, ~5 PD).** Admin UI — `AttributeListPage`, `ProductFormPage` extension, `ProductVariantMatrixEditor`, `ProductVariantStockView`. React + Tailwind tokens + i18n via `t()`. End-to-end smoke against backend.

**Phase 4 (Codex, ~4 PD).** B2B + ecommerce surface — `DocumentLineVariantSelector`, `ProductDetailVariantPicker`, B2B bulk-order SKU resolution, channel publish iteration. Tests + i18n.

**Phase 5 (Codex, ~2 PD).** Adversarial review prep + acceptance test sweep. Run §10 acceptance criteria end-to-end against a seeded parapharmacy + F&B + automotive demo tenant.

**Wave 2 POS deltas (logged to `2026-05-24-pos-coordination-log.md`, ~3 PD):** Variant picker modal, barcode resolution, cart line variant suffix, SQLite migration. Owned by next POS session; gated by fiscal Phase-1 sign-off.

**Total: ~21 PD server + 3 PD Wave-2 POS = 24 PD.** Up from v2's 16 PD due to recipe-variant work + B2B/ecommerce surface.

---

## 15. Coordination notes

- **Depends on:** T6 Phase 0 (merged — migration directory ready).
- **Owns:** every `variant_id` column added across §4.2 tables + the new attribute + variant tables.
- **Blocks:** no other Wave-1 track strictly. T1 (stock transfer) references `variant_id` but column is nullable; T1 work proceeds independently and integrates after T2 merges.
- **Compatible with T11:** T11-impl-B's `PricingStrategyResolver` wraps `PricingService`; T2's PricingService variant-aware extension composes cleanly. Verify with T11 spec authors before either merges.
- **Compatible with channel-adapter sprint:** T2 ships the variant model + the `channel_product_mappings` consumption; future adapter sprint plugs in concrete adapters using this contract.
- **POS Wave 2:** all Tauri changes logged for fiscal-coordination per `2026-05-24-pos-coordination-log.md`.

---

## 16. Reading order for the implementer

1. This spec (in full).
2. v2 baseline spec `2026-05-24-t2-variants.md` (in full — many design decisions carry over verbatim).
3. Migration topology contract `2026-05-24-migration-topology-contract.md`.
4. Productization sprint roadmap `2026-05-24-productization-sprint-roadmap.md`.
5. `apps/erp/CLAUDE.md` — global rules.
6. The 26 file paths in §3.
7. Memory: `project_monetary_precision.md` (price_override math), `project_inventory_costing.md` (variant cost composes with product WAC).
8. UnoPim repo (concepts only; do NOT copy code; MIT licensed but our hexagonal stack differs).
9. T1 spec — variant_id column coordination.
10. T11-impl-B spec — PricingStrategyResolver composition.

---

## 17. Glossary (canonical terms used in this spec)

- **Product** — top-level sellable item (`apps/api/app/Modules/Product/Domain/Product.php`). Has SKU, barcode, sale_price, cost_price, type (Part/Service/Consumable). Carries vertical metadata.
- **Variant / ProductVariant** — a stock-distinct child of a Product, defined by a tuple of attribute values. Has its own SKU, barcode, price_override, cost_override, image_url. Soft-deletable.
- **Attribute / ProductAttribute** — reusable named property (e.g., `Taille`). Has a data_type and an `is_variant_axis` flag.
- **AttributeValue / ProductAttributeValue** — enumerated value of an attribute (e.g., `Taille 39`). Optional hex_color / image_url per data_type.
- **Variant matrix** — cartesian product of selected attribute axes, expressed as N variants per product (e.g., 6 sizes × 3 colours = 18 variants).
- **Default variant** — exactly one variant per product flagged `is_default=true`. The variant new stock receipts default to when not explicitly variant-routed.
- **No mixed mode** — invariant: once a product has any active variant, all of its stock / batches / open reservations / open documents must be variant-scoped. Historical `stock_movements` may remain product-scoped (append-only audit).
- **Recipe ingredient at variant grain** — `recipe_lines.component_variant_id` is non-null when the ingredient is a specific variant of the referenced product.
- **Recipe earliest expiry** — `RecipeExpiryService::resolveEarliestExpiry` returns min(expiry) across FEFO-selected batches for each batch-tracked ingredient.
- **Variant-aware service method** — accepts optional `?UUID $variantId = null`. Null branch behaves identically to today; non-null branch scopes to variant.

---

End of spec.
