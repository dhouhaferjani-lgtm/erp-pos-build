# T2 Product Variants — Opus self-adversarial review round 2

**Reviewer:** Opus (self-review, hostile-hat)
**Spec under review:** `2026-05-28-t2-product-variants.md` v2 (post r1 revision)
**Plan under review:** `2026-05-28-t2-product-variants-impl-plan.md` v2 (post r1 revision)
**Previous round:** `reviews/2026-05-28-t2-variants-opus-r1.md` (verdict NEEDS-REVISION; 4 P1 + 6 P2 + 4 P3)
**Verdict:** **APPROVE-WITH-MINOR-EDITS** — 4 P1 fully addressed; 3 of 6 P2 folded in; the rest acknowledged as non-blocking.

---

## Cross-check of r1 findings against v2

### P1-1 — Event V2 cutover silently breaks `DispatchStockChangeToChannels`

**Status:** RESOLVED.
- Spec §6.4 now mandates **dual-dispatch** (V1 + V2 both fire from the producer); calls out V1-subscriber enumeration as a pre-merge gate; explicitly references the known `DispatchStockChangeToChannels` subscription line in `ChannelServiceProvider.php`.
- Plan Task 19 rewritten end-to-end: Step 1 enumerates subscribers; Step 2 has both dual-dispatch tests (variant-aware + non-variant); Step 4 dispatches **both** V1 and V2 from the producer.
- Out-of-T2 follow-up (subscriber migration) explicitly tracked.
- Acceptance criterion §10.6 dual-dispatch test added.

### P1-2 — Plan Task 15 callsite sweep grep pattern returns zero matches

**Status:** RESOLVED. Task 15 step 3 rewritten with multi-stage sweep (`grep StockAdjustmentService [\$]` for consumer files, then `->receive(` / `->issue(` / etc. for invocations). Acceptance gate requires inventory of every consumer.

### P1-3 — Soft-delete trap on partial uniques

**Status:** RESOLVED.
- Spec §4.1 every `product_variants` partial unique now adds `WHERE ... AND deleted_at IS NULL`.
- The `(tenant_id, sku)` constraint converted from full-table unique to partial unique (`WHERE deleted_at IS NULL`).
- Plan Task 3 step 3 migrations updated.
- Plan Task 3 step 3 includes the new regression tests (`test_sku_reusable_after_soft_delete`, `test_default_reusable_after_soft_delete_of_default`).

### P1-4 — WAC vs recipe variant cost_override drift

**Status:** RESOLVED.
- Spec §6.7 explicitly locks Option C (recipe cost is advisory; WAC stays product-grain). Trade-off documented.
- Plan Task 22 adds the `test_recipe_cost_does_not_affect_inventory_wac` test + a doc-block on `RecipeCostCalculationService`.
- Acceptance criterion §10.5 P1-4 regression test added.

---

## Cross-check of P2 findings

| Finding | Folded in v2? | Notes |
|---|---|---|
| P2-1 Recipe pre-existing mixed-mode | YES | `StockLevelMigrationService` now rewrites `recipe_lines.component_variant_id`. Plan Task 14 has the test. Spec §6.6 documents. |
| P2-2 `StockLevelMigrationService` long-lock | YES | `LargeMigrationRefusalException` + `$allowLargeMigration` flag + threshold 5000. Spec §6.6 + plan Task 14. |
| P2-3 Coupon semantics on variant-bearing products | YES | Spec §6.7 locks Option A (variant inherits product-grain coupon). Acceptance criterion §10.6 P2-3 test added. |
| P2-4 Variant image storage mismatch with `product_images` | DEFERRED | Acknowledged. Spec §4.1 keeps `product_variants.image_url` as denormalized convenience; full image management via extending `product_images` is a follow-up PR. Not blocking. |
| P2-5 Migration timestamp collision | ACKNOWLEDGED | Spec §15 should coordinate via productization-sprint log. Plan timestamps are `2026_06_02_*` — adequate for the current cadence. |
| P2-6 Verify `pos_order_lines` exists | RESOLVED | Verified via `find`. Migration path confirmed in spec §3 path 17/18. |

---

## Cross-check of P3 findings

| Finding | Folded in v2? | Notes |
|---|---|---|
| P3-1 SKU length mismatch | YES | spec §4.1 + plan Task 3 — `varchar(100)` matches `products.sku`. |
| P3-2 Permission scoping documentation | DEFERRED | Acknowledged. Spec §10 already requires variant CRUD to honor product permissions; plan Task 28 step 3 should make it explicit at impl time. |
| P3-3 Refund returns to variant row | YES | Acceptance criterion §10.6 explicit "Refund / credit-note against a variant-line returns stock to the same variant row." |
| P3-4 Recipe expiry surface | DEFERRED | Spec §8.5 already says computed-on-read, surfaced in operator CompositeItem detail page only. Polish; not blocking. |

---

## New issues found in r2 reading

### r2-1 (P3 — minor)

Plan Task 19 Step 1 (subscriber enumeration) uses grep patterns. These should be expanded to catch `Event::subscribe` (subscriber-class pattern) in addition to `Event::listen`. Add to the grep command:

```bash
grep -rn 'Event::subscribe' apps/api/app
grep -rn 'protected $listen' apps/api/app/Providers
```

This won't change v2's verdict but should be folded into Codex's instructions or into a Task 19 step polish.

### r2-2 (P3 — minor)

Acceptance criterion §10.6 (dual-dispatch test) is in the spec but the plan Task 32 (full acceptance suite) doesn't explicitly enumerate the dual-dispatch test method. Plan §32 step 1 references "all 10.1–10.7 acceptance scenarios end-to-end" which transitively covers it. Acceptable.

---

## Verdict

**APPROVE-WITH-MINOR-EDITS.** All four r1 P1 findings are fully addressed in v2; three of six P2 findings folded in (the rest acknowledged as non-blocking deferrals); P3-1 + P3-3 folded; P3-2 + P3-4 acknowledged as plan polish.

The spec + plan are ready for Codex headless adversarial review. Codex will likely surface:
- Cross-cutting reference sites this map missed (the §5 enumeration is thorough but Codex is good at finding the last 10%).
- Concurrency bugs at the FEFO + variant level (the lock-ordering story is implicit; Codex may want it explicit).
- Migration ordering bugs across the 15 migrations.
- Vertical-specific edge cases (probably none — §11 is exhaustive).
- Possibly the `product_images.product_variant_id` deferral (P2-4) — depending on how strict Codex is on consistency.

Proceed to Codex.

End of r2.
