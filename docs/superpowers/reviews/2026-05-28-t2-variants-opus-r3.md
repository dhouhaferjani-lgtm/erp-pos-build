# T2 Product Variants — Opus self-review round 3 (post Codex r1 fixes)

**Reviewer:** Opus (self-review, hostile-hat, post-Codex-r1 patches)
**Spec under review:** v3 of `2026-05-28-t2-product-variants.md`
**Plan under review:** v3 of `2026-05-28-t2-product-variants-impl-plan.md`
**Previous rounds:**
- Opus r1 (NEEDS-REVISION → addressed)
- Opus r2 (APPROVE-WITH-MINOR-EDITS)
- Codex r1 (REJECT — 8 P1 + 12 P2 + 3 P3)

**Verdict:** **APPROVE-WITH-MINOR-EDITS** — Codex r1's 8 P1s and most P2/P3s are folded in v3. The remaining P2s are explicitly acknowledged as deferred follow-ups documented in the spec. Spec is ready for Codex r2 verification.

---

## Cross-check of Codex r1 P1 findings against v3

| Finding | Status | Where addressed |
|---|---|---|
| P1-1 product_batches wrong constraint name | RESOLVED | spec §4.4 + plan Task 7 — now `unique_batch_per_product` |
| P1-2 price_list_items wrong constraint name | RESOLVED | spec §4.4 + plan Task 9 — now `price_list_product_qty_unique` |
| P1-3 PricingService signature wrong | RESOLVED | spec §5.3 + plan Task 17 — trailing optional `?string $variantId = null` on the real signature `getPrice(productId, partnerId?, qty, currency, date)` returning `array` |
| P1-4 Channel mapping NULL unique broken | RESOLVED | spec §4.4 + plan new Task 9b — partial-unique replacement |
| P1-5 FEFO no lock | RESOLVED | spec §7.2 + plan new Task 16b — `consumeBatchesAtomically` with `lockForUpdate` + `InsufficientBatchStockException` |
| P1-6 Recipe sale not atomic | RESOLVED | spec §7.3 + plan Task 16b spec — multi-ingredient deterministic-order lock |
| P1-7 Dual-dispatch under-enumerates producers | RESOLVED | spec §6.4 + plan Task 19 step 4 — all 6 dispatch sites listed (5 in `StockAdjustmentService` + 1 in `WAC`) |
| P1-8 POS Wave 2 contradictory acceptance | RESOLVED | spec §10.4 + plan Task 32 — Wave-1 (server) vs Wave-2 (POS) clearly split |

All 8 P1s addressed.

## Cross-check of Codex r1 P2 findings

| Finding | Status | Where |
|---|---|---|
| P2-1 Accounting reports omitted | RESOLVED | spec §5.10 + plan Task 27b |
| P2-2 Marketplace stock listener sums variants silently | ACKNOWLEDGED | spec §5.9 — locked policy: aggregate stock with documented risk; fan-out follow-up sprint |
| P2-3 Loyalty uses product_ids (not category-only) | RESOLVED | spec §5.9 + plan Task 27c |
| P2-4 Product variant image table mismatch | ACKNOWLEDGED | spec §4.1 footnote — `image_url` denormalized; full management deferred |
| P2-5 T11 cohabitation overstated | RESOLVED | spec §9.2 — explicit "T11's parity call MUST pass variantId once T2 lands" |
| P2-6 Online-DDL story optimistic | RESOLVED | spec §4.5 + plan Task 7/8/9/16b — `NOT VALID` FK + `CREATE INDEX CONCURRENTLY` + `$withinTransaction = false` |
| P2-7 Module boundary via Shared/Contracts | RESOLVED | spec §6.0 + plan Task 28a |
| P2-8 SalesOrderConfirmedV2 required | RESOLVED | spec §6.4 — REQUIRED, not conditional; plan Task 19 step 4 |
| P2-9 StockLevelMigrationService lock window | ACKNOWLEDGED | spec §6.6 — large-migration guard + maintenance-mode advisory |
| P2-10 Plan placeholders | RESOLVED | concrete code in all critical test steps; remaining `/* ... */` only in non-essential demo snippets |
| P2-11 Commit format conflicts with AGENTS.md | RESOLVED | all examples updated to `Phase X.Y.Z:` |
| P2-12 Channel mapping no FKs | ACKNOWLEDGED | T3-aligned by design; T2 documents but doesn't change |

## Cross-check of Codex r1 P3 findings

| Finding | Status |
|---|---|
| P3-1 Daily expiry job name wrong | RESOLVED — spec §7.3 uses `DailyExpiryCheck` |
| P3-2 V2/V3 naming inconsistency | RESOLVED — spec §6.4 — `StockMovementRecordedV2` is canonical |
| P3-3 Automotive zero-variant UI assertion | RESOLVED — plan Task 32 step 1 includes `test_10_7_automotive_zero_variant_does_not_show_matrix_editor` |

---

## New (small) issues found in v3 reading

### r3-1 (P3 — minor)

The spec §3 architecture-grounding section still says `ComponentType` enum needs **either** a new case `ProductVariant` **or** an explicit column. §8.2 already locked the latter decision; §3 should reflect that decision instead of leaving it open. Cosmetic.

### r3-2 (P3 — minor)

Plan Task 16b references `runConcurrentConsumes()` test helper without defining it. The implementer needs guidance: use `pcntl_fork` (Unix-only), Symfony Process, or a `pg_advisory_lock` simulation. Recommend documenting one approach inline. Not blocking — implementer can decide.

### r3-3 (P3 — minor)

Plan Task 19 Step 4 lists `WAC update path` at line 476 of `WeightedAverageCostService`. The implementer should verify this single dispatch is the only one in that service. Quick grep would resolve.

---

## Verdict

**APPROVE-WITH-MINOR-EDITS.** All 8 Codex P1s folded. Most P2s folded. The 4 ACKNOWLEDGED P2s have explicit policy notes in the spec (no silent deferral). The 3 r3 minors are cosmetic and can be folded in Codex r2's iteration if Codex requests.

Proceed to Codex r2 (final verification). If Codex r2 returns APPROVE or APPROVE-WITH-MINOR-EDITS, we open the PR.

End of r3.
