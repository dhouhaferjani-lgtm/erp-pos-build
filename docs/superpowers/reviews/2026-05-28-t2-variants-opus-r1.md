# T2 Product Variants — Opus self-adversarial review round 1

**Reviewer:** Opus (self-review, hostile-hat) — same author as spec/plan, fresh-eyes read
**Spec under review:** `apps/erp/docs/superpowers/specs/2026-05-28-t2-product-variants.md` (v1, 2026-05-28)
**Plan under review:** `apps/erp/docs/superpowers/plans/2026-05-28-t2-product-variants-impl-plan.md` (v1, 2026-05-28)
**Baseline:** `2026-05-24-t2-variants.md` (v2 post Codex r1) — partly carried over
**Verdict:** **NEEDS-REVISION** — 4 P1 + 6 P2 + 4 P3 findings. Three P1s are correctness regressions that an implementer would hit immediately; one is a backward-compat break I caught during cross-reference reading. None are catastrophic but all need addressing before Codex sees the spec.

---

## Summary

The owner's three new requirements (recipe ingredients at variant grain, B2B surfacing, ecommerce surfacing) are covered in §8 and §9. The cross-cutting impact map in §5 is reasonable but has at least one missed reference site (WAC + Channel listeners on `StockMovementRecorded`). Event-immutability handling in §6.4 is technically correct (new V's) but the dispatch-cutover story has a subscriber-disconnect bug that would silently break WAC and channel sync in production. Soft-delete interaction with partial unique indexes was overlooked in two places. The plan's TDD discipline is good but the Task 15 callsite-sweep step uses a grep pattern that returns zero matches against the real DI usage (instance call, not static).

If those four P1s are addressed, the spec is APPROVE-WITH-MINOR-EDITS. The recipe pre-existing-mixed-mode trap is real but probably acceptable as a documented operator-action requirement rather than a code-fix.

---

## P1 findings (must-fix before implementation)

### P1-1 — Event V2 cutover silently breaks `DispatchStockChangeToChannels` listener

**Where:** spec §6.4 + plan Task 19 step 4.

**The bug:** Plan Task 19 says "Update `StockAdjustmentService` to dispatch V2 instead of V1 (V1 stays in code but is no longer dispatched on writes)." The Channel module subscribes to `StockMovementRecorded` (V1) in `apps/api/app/Modules/Channel/Providers/ChannelServiceProvider.php`:

```php
Event::listen(StockMovementRecorded::class, DispatchStockChangeToChannels::class);
```

If the producer switches to dispatching V2, this listener stops firing — channel stock sync silently goes dark. The producer is `WeightedAverageCostService` (it's the actual dispatcher of `StockMovementRecorded` via `DB::afterCommit`), so the cutover happens there, not in `StockAdjustmentService`.

**Why I missed it:** I correctly identified events as immutable but didn't trace existing listeners.

**Fix options (pick one in v2 spec):**
- **A. Dispatch BOTH V1 and V2** during the transition; existing V1 listeners keep working; new V2 listeners can subscribe. Less code touched; mildly more event volume.
- **B. Cut listeners over to V2** as part of T2 (update `DispatchStockChangeToChannels` to subscribe to V2; verify any other subscribers in the same PR).
- **C. Hybrid** — dispatch V2 only; build a tiny adapter listener that converts V2 → V1 dispatch internally for any subscriber that hasn't migrated.

Recommend **A** as the safer choice for T2 scope; T2 has too much surface area to also force-migrate every listener. Add explicit plan task for the dual-dispatch implementation and an inventory of all V1 subscribers (use `grep -rn "StockMovementRecorded\\b" apps/api/app` to enumerate).

**Same issue applies to:**
- `DraftLineAddedV2` → V3 (subscribers? check `apps/api/app/Modules/*/Application/Listeners/`).
- `ReservationCreated`/`Expired`/`Released` V1 → V2.

Each of these needs the same dual-dispatch decision documented per event.

### P1-2 — Plan Task 15 callsite sweep grep pattern returns zero matches

**Where:** plan Task 15 step 3.

The plan says:
```bash
grep -rn 'StockAdjustmentService' apps/api/app apps/api/tests | grep -v '\.test\.'
```

I tested the more specific pattern `StockAdjustmentService::receive\|StockAdjustmentService::issue\|StockAdjustmentService::transfer\|StockAdjustmentService->receive` and it returns **zero** matches. The reason is dependency injection — callers don't use static-call syntax; they inject `StockAdjustmentService` via constructor and call `$this->stockAdjustmentService->receive(...)`. The bare-class grep `grep -rn 'StockAdjustmentService'` returns hits (constructor injection points), but those aren't "callsites" in the sense the plan needs.

**Fix:** rewrite Task 15 step 3 to grep for `->receive(`, `->issue(`, `->transfer(`, `->reserve(`, `->adjust(` **after** the implementer has identified the DI variable names in each consumer. Better yet, ask the implementer to inspect each consumer of `StockAdjustmentService` (find them via constructor-parameter type-hint grep: `grep -rn "StockAdjustmentService \$" apps/api/app`).

This is a plan-quality issue more than a spec issue — but it'll waste implementer time at execution.

### P1-3 — Soft-delete trap on `product_variants.is_default` partial unique

**Where:** spec §4.1 and plan Task 3 step 3.

I defined:
```sql
CREATE UNIQUE INDEX product_variants_default_unique
  ON product_variants (product_id)
  WHERE is_default = true;
```

But `product_variants` has `softDeletes()` (per Task 3 entity). A soft-deleted variant retains `is_default=true` and `deleted_at` NOT NULL. If the operator deactivates the current default and creates a new default, the partial-unique fires against the soft-deleted row and rejects the new default with a duplicate-key error.

**Fix:** include `AND deleted_at IS NULL` in both partial unique indexes that involve `product_variants`:
```sql
CREATE UNIQUE INDEX product_variants_default_unique
  ON product_variants (product_id)
  WHERE is_default = true AND deleted_at IS NULL;

-- And the barcode partial unique:
CREATE UNIQUE INDEX product_variants_tenant_barcode_unique
  ON product_variants (tenant_id, barcode)
  WHERE barcode IS NOT NULL AND deleted_at IS NULL;
```

Same issue on the **plain** unique `(tenant_id, sku)` — soft-deleted variant SKU is unfree. Either:
- Convert that to a partial unique `WHERE deleted_at IS NULL`, OR
- Document that SKU recycling requires hard-deleting (less ergonomic).

Recommend partial-unique with `deleted_at IS NULL` for consistency.

The same soft-delete consideration applies to `product_variant_attribute_values` if we soft-delete it (we don't per spec — fine).

### P1-4 — WAC computation grain vs. recipe variant `cost_override` produces silent COGS drift

**Where:** spec §5.3, §8.3, §6.7.

I committed to two positions that interact badly:
1. §6.7: "WAC stays company-wide per product. Variant `cost_override` is a display/pricing fallback only."
2. §8.3: `RecipeCostCalculationService` uses variant `cost_override` when present (the recipe cost reflects variant cost).

So a sale that consumes variant ingredients in a recipe gets COGS reported by the recipe at variant-cost, but the inventory WAC adjusts at product-cost. Reports that compare "recipe COGS" against "inventory WAC depletion" will drift. The accounting GL entries may post product-cost while the operational P&L shows variant-cost.

**Fix options (pick one, document explicitly in v2):**
- **A.** Keep WAC product-grain and **make RecipeCostCalculationService also use product cost** (ignore variant `cost_override`). Variant `cost_override` becomes a **pricing-only** display value. Simpler; matches inventory accounting. Loses the variant-cost feature for recipes.
- **B.** Promote WAC to variant-grain (proper) — significant scope creep, breaks the "variants don't introduce per-variant cost ledger" stance from memory `project_inventory_costing`.
- **C.** Hybrid — variant `cost_override` participates in recipe cost (for menu engineering / margin analysis) but does NOT affect GL postings. GL stays at product WAC. The drift is a documented reporting view, not a correctness bug.

Recommend **C** with explicit documentation that recipe cost figures are advisory (for pricing decisions) and reconciliation reports compare against product-grain WAC. Add this to spec §6.7 + acceptance criterion 10.5.

---

## P2 findings (should-fix in v2; not blocking)

### P2-1 — Recipe pre-existing mixed-mode trap

**Where:** spec §8 + §6.5.

Scenario: a recipe was created in 2026-Q1 referencing product P (no variants). RecipeLine has `component_variant_id = NULL`. In 2026-Q3 the operator adds variants to product P. Now selling the recipe (FEFO for ingredient P) hits the §6.5 invariant — `FEFOInventoryService::suggestBatchesForSale($productId=P, $variantId=null)` against a variant-bearing product raises `VariantRequiredException` (per Task 16 implementation).

The spec doesn't address this. Three remediation options:
- **A.** Extend `StockLevelMigrationService` to also rewrite `recipe_lines.component_variant_id = $defaultVariantId WHERE component_id = $productId AND component_variant_id IS NULL`. Atomic, but cross-module touch in the same transaction.
- **B.** Make the FEFO query degrade gracefully — when called with `variantId=null` on a variant-bearing product, fall back to the default variant. Implicit, surprising — bad pattern.
- **C.** Operator-facing dashboard surfacing "recipes with stale ingredient references" and requiring manual re-pointing.

Recommend **A** for safety: extend `StockLevelMigrationService` to handle the recipe case. Single atomic operation, no operator action needed. Risk: if there are many recipes per product, the transaction grows — but in practice products-with-many-recipes is rare. Acceptance criterion in §10.5: introduce variants to a product that has an existing recipe pointing at it; assert the recipe still sells correctly post-introduction.

### P2-2 — `StockLevelMigrationService` long-lock concern

**Where:** spec §6.6, plan Task 14.

The service atomically rewrites all open state (`stock_levels`, `stock_reservations`, `product_batches`) for a product in one `DB::transaction`. On a multi-warehouse parapharmacy company with N locations × M batches per location, the UPDATE row count grows roughly N×M. For a single product with N=20 locations and M=50 active batches per location, that's 1000 rows of stock_levels + batches under row-level lock for the duration of the transaction.

In practice this is probably fine — it's per-product, and creating the first variant is rare. But the spec should:
- Document the lock-duration trade-off.
- Add a guard: if the migration would touch > 5,000 rows, raise a `LargeMigrationRefusalException` and require an admin flag to override.
- Acceptance criterion confirming concurrent sale during migration doesn't deadlock.

### P2-3 — Coupon semantics on variant-bearing products undefined

**Where:** spec §5.9, §6.7.

"Coupons stay product-grain" — but at POS, if the customer presents a "20% off Product X" coupon and X has variants, what's the behavior?

- (a) Coupon applies to ALL variants of X at any size/colour (likely intent — operator wrote the coupon at product grain expecting it to cover all variants).
- (b) Coupon applies only when scanning the parent product barcode (not the variant barcode) — confusing, breaks ergonomics.

Add an explicit decision in v2 §5.9: **option (a) is the default**. Implementation: `PromotionEvaluationService.evaluate(cart_item)` resolves variant lines against `qualifying_product_ids` by product_id (variant's parent), not variant_id. No schema change needed.

Add acceptance criterion: 20% off coupon on variant-bearing product, cart contains two variants — coupon applies to both.

### P2-4 — Variant images: schema mismatch with existing `product_images` table

**Where:** spec §4.1 (`product_variants.image_url`).

I made variant images a single `image_url` column on the variant row. But `Product` uses a separate `product_images` table with `sort_order`, alt text, primary-flag, multiple images per product, etc. (see migration `2025_12_29_155412_create_product_images_table.php`).

Going with a single `image_url` per variant:
- ✓ Simpler, no extra table.
- ✗ Loses image management (multiple variant images, primary-flag, sort order, alt text).
- ✗ Inconsistent with product image pattern.

**Fix:** either add `product_variant_id` (nullable) to the existing `product_images` table and store variant images there, OR document that "variant has one canonical image; product has multiple". Recommend the former for consistency. Schema impact: `product_images.product_variant_id` (nullable, FK to product_variants, ON DELETE CASCADE) + an index. The spec's `product_variants.image_url` can stay as a denormalized convenience (the URL of the variant's primary image), or be dropped in favor of computed read from `product_images`.

### P2-5 — Migration timestamp collision risk with other Wave 1 tracks

**Where:** plan Task 1–11 use `2026_06_02_*` timestamps.

Other Wave 1 tracks (T1 stock transfer, T3 sync-hub, T4 order routing) also need migrations and may land in the same week. If T2 ships first, T1 collides on `2026_06_02_*` is unlikely (different second-of-day) but possible. The plan should:
- Pick timestamps strictly later than other tracks (e.g., `2026_06_15_*` if T2 lands last).
- Or coordinate via the productization-sprint coordination log.

Low risk; mention in v2 spec §15.

### P2-6 — `pos_order_lines` table assumed to exist; verify

**Where:** plan Task 8, spec §4.2.

I add `variant_id` to `pos_order_lines` but didn't verify the table exists in the current tenant migration set. Quick check via `find apps/api/database/migrations -name "*pos_order_lines*"` — yes it exists at `tenant/2026_03_11_400001_create_pos_order_lines_table.php`. ✓ Verified now but the spec should reference the exact migration file path.

---

## P3 findings (nice-to-have polish)

### P3-1 — `product_variants.sku` length mismatch with `products.sku`

**Where:** spec §4.1.

`products.sku` is `varchar(100)`. I made `product_variants.sku` `varchar(64)`. For a product SKU like `BRAKE-PAD-OEM-PEUGEOT-308-2018-1.6HDI` (39 chars) the variant SKU would extend it: `BRAKE-PAD-OEM-PEUGEOT-308-2018-1.6HDI-VAR-LEFT` (44 chars) — fits in 64 but cuts it close. Recommend `varchar(100)` to match.

Same for `variant_code` (currently varchar(64)) and `barcode` (currently varchar(64)) — verify against product barcode length conventions.

### P3-2 — Documentation gap on variant-aware permission scoping

**Where:** plan Task 28 step 3.

The plan adds new permissions `catalog.attributes.{create,update,delete,view}` and `catalog.variants.{create,update,delete,view}`. But: do these inherit the existing product permissions? E.g., can a user without `catalog.products.update` modify variants of a product they otherwise cannot edit?

Recommend explicit policy: variant CRUD requires the parent product's update permission, in addition to the new variant permission. Spec §10 acceptance criterion already mentions this; plan Task 28 step 3 should make it explicit.

### P3-3 — Missing acceptance criterion: variant-aware document line ledger

**Where:** spec §10.

I have §10.6 covering "cart → quote → sales order → invoice promotion preserves variant_id". But I don't have an acceptance criterion verifying that a credit-note (refund) against a variant-line correctly returns stock to the same variant row. Add §10.3 supplement.

### P3-4 — Visibility of recipe expiry on POS / catalog

**Where:** spec §8.5.

I say recipe earliest-expiry is "computed on read, no DB column." But how is it surfaced? POS doesn't need it (cashier doesn't ask "what's the expiry of this cappuccino?"). Catalog ecommerce *might* want it ("ships fresh until [date]"). Operator dashboard certainly wants it.

Spec doesn't specify any UI consumer beyond the §10.5 acceptance test. Recommend a brief note: "Surface in the operator's CompositeItem detail page; not surfaced in POS or B2C catalog by default."

---

## Verdict

**NEEDS-REVISION.** Address the four P1 findings (event dispatch cutover, callsite sweep grep, soft-delete partial unique, WAC + recipe cost reconciliation) before sending to Codex. P2/P3 can fold into the same v2 revision if convenient or land as plan-task adjustments later.

Once P1s are fixed, the spec is APPROVE-WITH-MINOR-EDITS quality.

---

## Things I'd defend if challenged

- **No vertical-modularity violations.** §11 explicitly excludes automotive fitment from T2 and addresses parapharmacy / retail / F&B / future-pharmacy.
- **Owner non-negotiables all mapped** to sections in §2.
- **Backward compat** is structurally sound — every modified service signature is trailing-optional; every column addition is nullable; partial-index strategy preserves pre-T2 unique semantics.
- **Migration topology contract** honored — every new migration in `tenant/`; no cross-DB FKs.
- **Recipe + variant + expiry inheritance** is correct algorithmically (FEFO `ORDER BY expiry_date ASC` returns the earliest; per-line `expiry_date < earliest` updates the rolling min). P2-1 (pre-existing mixed-mode recipes) is a remediation concern but not an algorithm bug.

End of review.
