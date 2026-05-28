# T2 Product Variants — Codex headless adversarial review round 1

**Reviewer:** Codex (gpt-5.5)
**Spec under review:** `apps/erp/docs/superpowers/specs/2026-05-28-t2-product-variants.md`
**Plan under review:** `apps/erp/docs/superpowers/plans/2026-05-28-t2-product-variants-impl-plan.md`
**Verdict:** REJECT
**Confidence:** medium

## Summary

v2 fixes the four Opus r1 P1s, but it is not implementation-ready. The largest blockers are code-grounded: two partial-unique migrations drop the wrong existing constraint names, so the old full-product uniqueness remains and variant rows cannot coexist; the pricing section and Task 17 describe a `PricingService::getPrice($priceListId, $productId, ...)` API that does not exist; the channel mapping contract incorrectly trusts a nullable-column unique index that PostgreSQL will not enforce for product-level rows; and the FEFO/concurrency story assumes row locks in `suggestBatchesForSale()` that the current service does not take.

The cross-cutting impact map is also materially incomplete for modules the prompt explicitly named. Some omissions can stay product-grain by policy, but they still need explicit decisions because they currently aggregate, report, reserve, or sync by `product_id` only. The plan's own "no placeholders" self-check is false: there are multiple `/* ... */`, "TBD", "find via likely", and "following Task 1 shape" steps in high-risk tasks.

## P1 findings (must-fix before implementation)

### P1-1 — Product batch partial-unique migration drops the wrong constraint, so variant batches cannot coexist

Spec §4.4 says to drop `product_batches_company_id_product_id_batch_number_unique`; plan Task 7 repeats that exact drop. The actual tenant migration names the existing unique constraint `unique_batch_per_product` at `apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:47`.

Because `DROP CONSTRAINT IF EXISTS product_batches_company_id_product_id_batch_number_unique` is a no-op, the old unique `(company_id, product_id, batch_number)` remains. The acceptance case "same batch_number can coexist across variants" then fails immediately, even if the new partial indexes are created.

Fix: drop `unique_batch_per_product`, and add a regression that inserts two variant-scoped batches with the same `(company_id, product_id, batch_number)` and different `variant_id`.

### P1-2 — Price-list partial-unique migration has the same wrong-constraint bug

Spec §4.4 says to drop `price_list_items_price_list_id_product_id_min_quantity_unique`; plan Task 9 does the same. The actual constraint name is `price_list_product_qty_unique` in `apps/api/database/migrations/tenant/2025_12_01_201028_create_price_list_items_table.php:23`.

Result: the old unique `(price_list_id, product_id, min_quantity)` stays active and blocks the core B2B acceptance case: one product-level row plus one variant-specific row at the same quantity break.

Fix: drop `price_list_product_qty_unique`, then create the two partial unique indexes. Add a test that fails against the old constraint name.

### P1-3 — PricingService is specified with the wrong signature and return type

Spec §5.3 cites `PricingService::getPrice($priceListId, $productId, $quantity)`. The real method is `getPrice(string $productId, ?string $partnerId = null, string $quantity = '1.00', string $currency = 'USD', ?DateTimeInterface $date = null): array` in `apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:32`.

Plan Task 17's test calls:

```php
getPrice(priceListId: ..., productId: ..., quantity: '1', variantId: ...)
```

That will not compile. It also expects a string price, while the real service returns `array{price, source, price_list_id}`. Existing T11 impl-B explicitly depends on the current signature and parity call: `PricingService::getPrice($productId, $partnerId, $quantity, $currency, $date)` (`docs/superpowers/specs/2026-05-24-t11-impl-b-pricing-resolver-doc-policy.md:112-120`).

Fix: redefine T2 pricing as `getPrice(string $productId, ?string $partnerId = null, string $quantity = '1.00', string $currency = 'USD', ?DateTimeInterface $date = null, ?string $variantId = null): array`, update private `getPartnerPrice`, `getDefaultPriceListPrice`, `getPriceFromList`, `getQuantityBreaks`, `getBulkPrices`, the controller endpoint, and the T11 parity contract. Variant `price_override` must be resolved before the product base-price fallback, not after it.

### P1-4 — Channel product mapping's nullable unique does not protect product-level mappings

Spec §5.8 says `channel_product_mappings.variant_id` already exists and the composite unique already includes it, so no migration is needed. The actual migration creates a normal unique on `(channel_id, product_id, variant_id)` at `apps/api/database/migrations/tenant/2026_05_24_120002_create_channel_product_mappings_table.php:27`.

PostgreSQL treats NULLs as distinct in unique indexes, so multiple rows with the same `(channel_id, product_id, variant_id=NULL)` are allowed. That makes product-level channel mappings ambiguous before T2 even reaches variant fan-out.

Fix: T2 must replace this with the same partial-unique pair used elsewhere:

- `(channel_id, product_id) WHERE variant_id IS NULL`
- `(channel_id, product_id, variant_id) WHERE variant_id IS NOT NULL`

Also decide whether `product_id` / `variant_id` should gain tenant-DB FKs or remain bare UUIDs per T3.

### P1-5 — FEFO/concurrency premise is false: `suggestBatchesForSale()` does not lock rows

The prompt's premise and spec §7 assume FEFO row locking. Current `FEFOInventoryService::suggestBatchesForSale()` builds a query and calls `get()` without `lockForUpdate()` (`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:27-40`). The row lock happens later in `BatchStockService::issueBatchStock()`, one batch at a time.

That means two cashiers can both receive the same suggestions. One will eventually fail or partially allocate later, but the algorithm is not "select and lock FEFO rows". For POS, this is worse: `ReceiptCreationService::allocateBatches()` logs FEFO shortfall and still lets the sale proceed (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:1312-1321`). That violates the T2 requirement that batch-tracked variant sales consume variant batches with audit allocations.

Fix: define an atomic FEFO consumption API that selects and locks `inventory_batch_stock` rows inside the same transaction as stock decrement and fails the sale/reservation on shortfall. Do not keep a read-only "suggest" API as the consumption primitive.

### P1-6 — Recipe selling has no atomic availability/FEFO contract for variant ingredients

`CompositeItemAvailabilityService` is a read-side helper. It reads `StockLevel` by `product_id` and `location_id` only today (`apps/api/app/Modules/Catalog/Application/Services/CompositeItemAvailabilityService.php:55-57`) and does not participate in the POS sale transaction. `ReceiptCreationService::deductCompositeItemStock()` simply recurses recipe lines and calls `decrementStock()` per ingredient (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:1139-1184`), then batch allocation can shortfall and log.

Aggregate stock races are mostly caught by `decrementStock()`'s row lock, but batch-tracked recipe ingredient consumption is not all-or-nothing at the FEFO allocation layer. If aggregate stock says enough and batch stock does not, the receipt can still commit with incomplete batch allocations.

Fix: the recipe sale flow needs an atomic "reserve/consume all ingredient batches" contract: resolve variant ingredients, lock required FEFO batch rows for every ingredient in deterministic order, validate full fulfillment, then decrement stock and write allocations. Add a two-cashier simultaneous recipe sale test.

### P1-7 — StockMovementRecorded dual-dispatch plan under-enumerates producers

Plan Task 19's code comment says "In WeightedAverageCostService::recordCostAdjustment (the actual producer)" (`plan:1977`). That method does not exist, and `WeightedAverageCostService` is not the only producer. `StockAdjustmentService` dispatches `StockMovementRecorded` directly in receive, issue, transfer-out, transfer-in, and adjust paths (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:79`, `:168`, `:277`, `:292`, `:452`). `WeightedAverageCostService` has its own dispatcher (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:469-482`).

If implementation follows the plan literally, some movement producers will remain V1-only and variant-aware writes will silently miss `variantId` in events.

Fix: Task 19 must list every producer method and line, not only subscribers. Acceptance should assert dual-dispatch from `StockAdjustmentService::{receive,issue,transfer,adjust}` and `WeightedAverageCostService::{recordPurchase,recordSale,recordReturn}`.

### P1-8 — POS Wave 2 scope is contradictory

The plan says Wave 2 POS work is "separate session, NOT in this plan" and lists variant picker, barcode wiring, cart suffix, SQLite migration, and offline sync at `plan:2702-2710`. The spec acceptance criterion §10.4 nevertheless includes those as acceptance checkboxes for this spec (`spec:830-837`), and §10.3 includes "Sell from POS" as a variant-aware flow.

This creates an implementation gate that cannot pass in the planned PR. If the PR only logs POS work, the acceptance suite cannot include §10.4 as executable pass/fail tests. If it ships the tests, they must be skipped/pending and clearly marked Wave 2.

Fix: split acceptance criteria into Wave 1 server tests and Wave 2 POS tests, or move POS implementation into the plan. Do not leave Wave 2 checkboxes in the Wave 1 acceptance gate.

## P2 findings (should-fix in v3; not blocking)

### P2-1 — Cross-cutting map omits Accounting reports that will aggregate variant rows incorrectly

The prompt explicitly asked to check Accounting. Spec §5 does not mention it. Current `SalesReportService::topSkus()` groups by `pos_receipt_lines.product_id`, `product_name`, and product SKU only (`apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:74-85`). Current `StockAlertReportService` reports low stock by product and location only (`apps/api/app/Modules/Accounting/Application/Services/Reports/StockAlertReportService.php:27-49`).

After T2, those reports will collapse variants into the parent product or show multiple indistinguishable rows with the same product name. That may be acceptable for product-level rollups, but it conflicts with the spec's claim that POS projection/reporting can dimension by variant.

Fix: add Accounting to §5 with explicit decisions: product rollup vs variant dimension. At minimum, stock alerts need variant suffix/SKU when `stock_levels.variant_id IS NOT NULL`.

### P2-2 — Marketplace is declared product-grain, but its stock listener will sum all variant stock silently

Spec §5.9 says marketplace listing fan-out is out of scope. That is fine. But existing `ListingSyncService::getAvailableQuantity()` sums every `StockLevel` for a product (`apps/api/app/Modules/Marketplace/Application/Services/ListingSyncService.php:76-90`). Once variant rows exist, a product-level marketplace listing for "shoe" can advertise total stock across all sizes/colours.

If marketplace stays product-grain, this needs an explicit policy: either delist variant-bearing products from product-grain marketplace listings, publish only default variant stock, or accept aggregate stock with documented risk. Current spec only says fan-out is deferred.

### P2-3 — Loyalty product filters are not just category-based

Spec §5.9 says Loyalty earn-rate rules use category, not product, and need no variant change. Actual Loyalty has product-specific include/exclude arrays in rule DTOs/requests and services (`PointEarningService`, `RewardRedemptionService`, `StampCardService`, `LoyaltyPOSController` all reference `product_id` / `product_ids`).

This can stay product-grain like coupons, but the spec's factual claim is wrong. Add Loyalty semantics matching coupon semantics: a product-scoped loyalty rule applies to all variants unless a future variant-specific rule is introduced.

### P2-4 — Product module product-history and image surfaces are not enumerated

The Product module has direct product line and stock lookups, including product detail/history surfaces such as `ProductController` queries over `stock_levels` and `document_lines`, and `ProductImageService` / `ProductImageController` product-only image management. Spec §4.1 mentions variant image URL but does not add `product_images.product_variant_id`, and §5 does not list Product module impact.

Opus r1 deferred full variant image management, but v2 now says "full image management via `product_images.product_variant_id`" in a column note without adding the migration. Pick one: either add `product_images.product_variant_id` to schema/tasks or explicitly keep single-image `product_variants.image_url` for T2.

### P2-5 — T11 cohabitation claim is overstated

T11 impl-B has `ResolveContext::$variantId`, but the parity guarantee explicitly calls `PricingService::getPrice($productId, $partnerId, $quantity, $currency, $date)` without passing variant (`docs/superpowers/specs/2026-05-24-t11-impl-b-pricing-resolver-doc-policy.md:112-120`). T2 spec §9.2 says T11 "passes through `variantId` to the wrapped call"; that is not true in the current T11 spec.

This is fixable, but the coordination note should say T2 must update the resolver leaf call and parity tests when T2 changes `PricingService`.

### P2-6 — Migration online-DDL story is too optimistic

Spec §4.5 says nullable adds and constraint replacement have minimal locking and are acceptable for a tenant with ~5M `stock_movements`. In PostgreSQL, adding a foreign key validates existing rows and takes locks; replacing unique constraints with non-concurrent index creation blocks writes; Laravel's `Schema` helpers do not create `CREATE INDEX CONCURRENTLY`.

For `stock_movements`, the new nullable FK should be added as `NOT VALID` and validated later, or the FK should be deferred to a maintenance migration. For `stock_levels`, `product_batches`, and `price_list_items`, create replacement indexes concurrently before dropping old constraints where possible.

### P2-7 — Module-boundary placement is questionable

The plan puts `ProductVariant` under `Catalog`, then has Inventory, Pricing, POS, Cart, Channel, Document, and Product flows use it directly. CLAUDE.md rule 6 says cross-module communication goes through Shared contracts, events, or public services. The plan snippets also call `ProductVariant::where(...)` directly inside Inventory service logic.

Either move the aggregate to the Product module, or define a shared/public ProductVariant lookup contract so modules do not import Catalog domain models directly.

### P2-8 — SalesOrderConfirmed almost certainly needs a V2, but the spec leaves it conditional

`SalesOrderConfirmed` serializes line arrays with `line_id`, `product_id`, `quantity`, and `location_id` in its payload (`apps/api/app/Modules/Document/Domain/Events/SalesOrderConfirmed.php:17-31`). That payload will need `variant_id` for variant-scoped reservations/fraud audit. The spec says "if lines are serialized, spawn V2"; they are serialized. Make `SalesOrderConfirmedV2` a required task, not an implementation-time question.

### P2-9 — `StockLevelMigrationService` lock window is still too broad for POS-adjacent products

The large-migration guard helps, but the service still updates `stock_levels`, open `stock_reservations`, active `product_batches`, and `recipe_lines` in one transaction based only on `product_id` (`spec:537-542`). That conflicts with concurrent POS sales/reservations of that product because POS locks the same `stock_levels` rows. This may be acceptable as an admin-only operation, but the spec needs an operational rule: first-variant migration is maintenance-mode/product-locked, or it uses chunked retries with an application-level product lock.

### P2-10 — Plan still contains placeholders and underspecified tasks

The plan claims no placeholders, but examples include:

- "verify exact path" for service provider (`plan:306`)
- repository/entity implementation "following Task 1 shape" (`plan:398`, `:446`, `:584`)
- `addValue(...): ProductAttributeValue { /* ... */ }` (`plan:1186`)
- event payloads `/* ... */` and `return [...]` (`plan:1230`, `:1253`, `:1966-1970`)
- large-migration test `// ...` (`plan:1471-1472`)
- POS receipt test `finalize(/* ... shape ... */)` (`plan:1846`)
- B2B importer "find via likely" (`plan:2409`)
- acceptance suite methods all `/* ... */` (`plan:2628-2635`)

This is not merely polish; it affects the highest-risk tasks.

### P2-11 — Plan commit format conflicts with repository instructions

AGENTS.md requires commits like `Phase <major.minor.patch>: <imperative summary>`. The plan mandates `feat(t2): ...`, `refactor(t2): ...`, and `test(t2): ...` at `plan:17`. Align the plan with the repo's commit convention.

### P2-12 — Existing `channel_product_mappings` has no product/variant FKs

The spec describes ecommerce mapping as a contract for variants, but the current migration uses bare UUID columns for `product_id` and `variant_id` (`2026_05_24_120002_create_channel_product_mappings_table.php:16-17`). That may be intentional T3 design, but T2 should state whether variant deletion is restricted by mapping rows or handled in service cleanup.

## P3 findings (nice-to-have polish)

### P3-1 — Daily expiry job name is wrong

Spec §7.3 calls the scheduled job `UpdateBatchExpiryStatusJob`. The codebase has `apps/api/app/Modules/BatchExpiry/Jobs/DailyExpiryCheck.php`. Use the real name to avoid grep misses.

### P3-2 — StockMovementRecorded version naming is inconsistent

Spec §6.4 correctly says `StockMovementRecordedV2`, but §5.8 says `StockMovementRecorded` carries `variantId` and parenthetically calls it a "V3 event" (`spec:376`). Use one version name consistently.

### P3-3 — Automotive zero-variant UI acceptance should be explicit

Spec §10.7 says automotive product without variants works, and §11 says automotive fitment is not T2 variants. Add a frontend assertion that the matrix editor is not mounted unless the operator enables "has variants"; otherwise an automotive product form can still present a parapharmacy-centric editor.

## Things I verified and agree with

- DB-per-tenant flip context is real: recent git log includes PRs #142-#146 and T6 database-per-tenant work; `docs/superpowers/coordination/2026-05-24-t6-phase0-gate-complete.md` confirms tenant migrations now live under `database/migrations/tenant/`.
- `inventory_counting_items.variant_id` is already present and nullable in `2025_12_02_070002_create_inventory_counting_items_table.php`.
- Existing BatchExpiry has product-grain `product_batches.product_id`, `inventory_batch_stock`, generated `available_quantity`, and FEFO expiry ordering.
- `ReceiptCreationService::decrementStock()` really bypasses `StockAdjustmentService` and writes `StockMovement` directly.
- `SalesOrderConfirmed` serializes product line data, so event immutability applies.
- T11 impl-B has a `variantId` field on `ResolveContext`, but current parity delegation does not pass it to `PricingService`.
- Existing Product uses soft deletes; v2's soft-delete-aware partial unique for variant SKU/barcode/default is the right direction.
- Product-level WAC is the current ledger behavior: COGS posting reads `Product::cost_price`, and WAC services update product cost, not variant cost.

## Files I read end-to-end

- `docs/superpowers/specs/2026-05-28-t2-product-variants.md`
- `docs/superpowers/plans/2026-05-28-t2-product-variants-impl-plan.md`
- `docs/superpowers/reviews/2026-05-28-t2-variants-opus-r1.md`
- `docs/superpowers/reviews/2026-05-28-t2-variants-opus-r2.md`
- `docs/superpowers/specs/2026-05-24-t2-variants.md`
- `CLAUDE.md`
- `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php`
- `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php`
- `apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php`
- `apps/api/app/Modules/Catalog/Application/Services/CompositeItemAvailabilityService.php`
- `apps/api/app/Modules/Pricing/Domain/Services/PricingService.php`
- `apps/api/app/Modules/Catalog/Domain/Entities/Recipe.php`
- `apps/api/app/Modules/Catalog/Domain/Entities/RecipeLine.php`
- `apps/api/app/Modules/Catalog/Domain/Enums/ComponentType.php`

## Things you missed (if relevant)

- There is no `apps/api/app/Modules/Order` module in this checkout; the order-like surfaces are POS order lines, Document sales orders, and `PurchaseHub`.
- The prompt's module list did not include `Menu`, `PurchaseHub`, `Import`, or `Service`; I did not fully audit those for `product_id` semantics.
- I did not run the test suite; this was a source review only.
- I did not verify every frontend product form path end-to-end. The UI comments above are based on code search and the plan/spec, not browser execution.
