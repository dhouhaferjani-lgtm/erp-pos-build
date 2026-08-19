Adversarial merge-gate review — **Wave 3 / 3C, milestone M3, round 7**

Scope: brief `docs/handoff/CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md:439-483` + the M3 evidence-contract row (`:542`). Amending authority: **none**. Diff reviewed: `26b63f0ff..6f8a47c55`, with round-7 attention on the round-5 remediation commit `d07868cca` (the only code commit after the round-5 verdict; `c971dbf21`/`6f8a47c55` are documentation). Lenses: **inventory-costing** and **fiscal-pos** — both apply, both exercised. Worktree untouched (`docs/handoff/progress/wave3-3c-3d.progress.yaml` was already dirty at entry and I did not touch it); the scratch PostgreSQL database I created was dropped.

Verified locally on PostgreSQL (127.0.0.1:5433, fresh DB, per-file runs — not taken from the report):

| Suite | Result |
|---|---|
| `CheckCogsCoverageCommandTest` | OK 25 / 50 |
| `ReverseInventoryMovementEntriesCommandTest` | OK 3 / 27 |
| `PosCoreReceiptProjectionRefundDispositionStockTest` | OK 16 / 77 |
| `PosCoreReceiptProjectionVariantStockTest` | OK 5 / 26 |
| `GLIntegrationTest` | OK 29 / 120 |
| `StockMovementGLIntegrationTest` | OK 13 / 38 |
| `CompleteSalesCycleWithReturnTest` | OK 1 / 55 |
| `RefundResidualTenantIsolationTest` | OK 18 / 57 |
| `CogsRelocationCharacterisationTest` | OK 15 / 54 |
| PHPStan level 8 (6 M3-touched production files) | `[OK] No errors` |

**All five round-5 findings are closed and independently re-verified**, not taken from `M3-evidence.md`:
- (P2) the anti-join now carries a null-safe variant grain (`CheckCogsCoverageCommand.php:304-312`). I dumped the *generated* SQL rather than trusting the builder: `... and ("stock_movements"."variant_id" = "lines"."variant_id" or ("stock_movements"."variant_id" is null and "lines"."variant_id" is null)) ...` — correctly parenthesised, textbook null-safe. A same-product sibling movement can no longer mask a missing variant movement.
- (2) `default => $stockTrackingExpected` (`PosCoreReceiptProjection.php:1273`) restores the anomaly signal for an unparseable disposition.
- (3) the archived-product scrap refund emits a dedicated warning (`:2130-2142`).
- (4) the DN arm's cutover parameter is pinned on both sides (`CheckCogsCoverageCommandTest.php:177-199`).
- (5) both scanners guard an unresolvable company (`UndeliveredGoodsLineScanner.php:59-62`, `InvoicedBeforeDeliveryScanner.php:89-92`) — unreachable from the command (rows come from a live query) and from `ReportsController` (`findOrFail`), so this is defence-in-depth, correctly pinned.

---

## Register

**1. P3 — CONFIRMED — `apps/api/tests/Feature/Accounting/CheckCogsCoverageCommandTest.php:464-510` — the new variant-grain test pins only the positive; the equality half of the null-safe predicate is mutation-invisible.**
The test's entire assertion set is `assertExitCode(1)` (`:504`) plus a `Log::shouldHaveReceived('warning')` matching `$missingLine` (`:505-510`). It never asserts that the **covered** variant line stays silent, nor the finding count. Delete `->whereColumn('stock_movements.variant_id', 'lines.variant_id')` (`CheckCogsCoverageCommand.php:306`) leaving only the both-null branch and: the covered line no longer matches, so it is reported too — exit is still 1 and the missing-line warning is still emitted, so this test stays green. The three other D-f POS tests (`:367`, `:391`, `:436`) all use product-level lines with `variant_id IS NULL` on both sides, which the surviving both-null branch still satisfies, so they stay green as well.
*Failure scenario:* a future edit to the variant clause turns every variant-scoped POS line into a nightly D-f finding — a detector that always fires — and the full M3 regression set reports green.
*Weight:* the shipped behaviour is **correct** (verified against the generated SQL, and the round-5 revert-replay recorded exit 0 pre-fix, which is the red-before half). This is a coverage gap on a predicate that has now driven three blocking rounds, against an evidence-contract row (`brief:542`) that names "stays silent on a constructed negative" per check. One added assertion in the same test closes it; it does not warrant holding the milestone.

*No P1 or P2 findings survived verification.*

## Items verified and PASSING (not findings)

- **The brief's named M3 derived obligation — explicit reviewer confirmation.** D-e carries the `reference_type != 'stock_adjustment'` exclusion matching D-b (`CheckCogsCoverageCommand.php:229-231`). **CONFIRMED PRESENT.** I also verified the exclusion actually covers the population rather than merely existing: the only writers of `AdjustmentPositive`/`AdjustmentNegative` movements are `StockAdjustmentDocumentService` (`:260-261` and the contra path via the same `writeLines`), which always stamps `StockMovementReferenceType::StockAdjustment` — so no NULL-`reference_type` adjustment slips past the `whereNull OR !=` shape into a permanent fire. Same check for the T21 counting exclusion (`:235`): both `ApplyStockAdjustmentsOnCountingCompleted` call sites (`:205`, `:269`) pass `InventoryCounting`.
- **D-e cannot fire permanently on the rest of its population.** `nonCogsGlReasons` = {GoodsReceipt, SupplierReturn, AdjustmentPositive, AdjustmentNegative, CountCorrection}. `WeightedAverageCostService::recordPurchase` (`:260-281`) writes **no** `reason`, so real inbound GR rows are outside every reason-keyed check — honestly ticketed (`2026-08-18-goods-receipt-movement-reason-detector-gap.md`), not papered over. `SupplierReturn` has no production writer. `POSReturn` is `affectsCOGS() = true` (`MovementReason.php:71`), so restocks land in D-a/D-b (both `is_historical`-filtered) and not in un-filtered D-e — the historical-refund false-positive I went looking for does not exist.
- **GR arm.** `goods_receipts.purchase_order_id` is **NOT NULL** (`2026_07_04_100000_create_goods_receipts_tables.php:18`), so the `paid_movement.reference_id = receipts.purchase_order_id` join can never degrade to a NULL comparison that fires on every PO-less receipt. `recordPurchase` creates the `stock_levels` row when absent, so a missing grain cannot produce a movementless received line.
- **Watermark column is safe unattended.** `inventory_gl_cutover_at` is NOT NULL with `useCurrent()`, cast `immutable_datetime`, fillable, and initialised in the `creating` hook (`Company.php:150`, `:281`, `:335`) — so `scanMovementChecks(\DateTimeInterface $cutoverAt)` cannot receive null. `pos_receipts.posted_at` is NOT NULL, so the POS arm's `Carbon::parse((string) $row->document_date)` has no empty-string path.
- **Rule 19 / GL boundary.** No float touches money or quantity in the M3 diff. `reverseInventoryMovementEntry` (`GeneralLedgerService.php:4661-4718`) swaps `decimal:3`-cast strings (`JournalLine.php:54-55`) and passes an explicit `currencyCodeForCompany(...)` into `postEntryNow` — no bare `getScale()`. `age_days` is a non-monetary int; `amount` is `null` on all three D-f arms, never `0`.
- **Fiscal-pos / sealed bytes.** Nothing that hashes changed. `stock_movement_expected` and `disposition` are projection columns; I grepped every `disposition` consumer in `app/` and found no production query that filters on `pos_receipt_lines.disposition`, so newly populating it changes no existing result set. R-14 holds — `writeLines`' new probes (`stockGrainExists`, `RestockPolicyResolver::resolve`, `Product::exists`) are all unlocked reads, so no product lock enters the projection and no I-1 lock-order edge is created.
- **T18 / T19 / T19b / C-5 / R-5 / WO stub** re-verified: `createCOGSEntry` deleted with zero references; the seven release-note sections present including 4a/4b/4c and the four tickets; `accounting:reverse-inventory-movement-entries` gated on `--from` + `--confirm`, idempotent under the original's `lockForUpdate`, `inventory_movement_reversal` correctly **absent** from `InventoryGlSourceTypes::ALL`; the C-5 flush tail live at `InvoiceController.php:1028`; R-5's SQL counterpart documented (`PhysicalLinePredicate.php:51-66`) and pinned non-vacuously by a cross-tenant negative (`CheckCogsCoverageCommandTest.php:215-261`); the WO arm a named no-op (`:156-158`) with its not-reported test.
- **Migrations additive/unattended-safe**; no new named queue, so no Horizon coverage owed. `ParapharmacySeeder` changes are PHPStan hygiene plus removal of a duplicate `is_physical` key — behaviour-identical, and the `$metadata?->category` match has a `default` arm.

## Bypasses attempted that FAILED (recorded)

1. *The variant predicate false-fires when `pos_receipt_lines.variant_id` and `stock_movements.variant_id` diverge* — the line stores `resolveVariantFk` (`:1366-1389`, null unless the variant exists for that tenant+product) while the movement stores the raw canonical `$line->variantId` (`:2017`). They can only diverge if a movement carries a variant the line resolver rejected; `stock_movements.variant_id` has an `ON DELETE RESTRICT` FK to `product_variants` (`2026_06_02_100006_...:26-27`), and the only remaining divergence needs a `stock_levels` row mis-seeded to a variant of a *different* product. Not a live population. Failed.
2. *A soft-deleted product's sale writes a movement while the line is excluded, so D-f fires* — `productFk` is null, so the inner `join('products', …, 'lines.product_id')` drops the row entirely. Failed.
3. *D-e fires forever on historical POS restocks (it has no `is_historical` filter, unlike D-a/D-b)* — `POSReturn` is `affectsCOGS() = true`, so it is never in D-e's reason set. Failed.
4. *A PO-less / direct goods receipt makes the GR join NULL-compare and fire on every line* — `purchase_order_id` is NOT NULL. Failed.
5. *An adjustment or counting movement with NULL `reference_type` escapes D-e's `whereNull OR !=` exclusions* — every writer of those reasons stamps the reference type; verified at each call site. Failed.
6. *The `default => $stockTrackingExpected` change makes a `not_received` line movement-expected* — `NotReceived` is matched before `default` and pinned to `false` (`:1264`). Failed.
7. *M3 regressed a neighbouring suite* — nine touched suites, 120 tests, all green on real PostgreSQL. Failed.

**Gate rationale.** Round 5's blocking item is genuinely closed at the mechanism level, not the wording level: the anti-join now discriminates the variant grain, I confirmed that from the emitted SQL rather than the builder, and the two overclaiming artefact sentences are now true statements about the code. The four P3s are closed with real pins. My own adversarial pass went after the populations that would make this detector fire permanently in production — D-e's reason set, the GR join's nullable key, the historical-refund path, the watermark column's nullability — and every one of them held. What is left is a single missing negative assertion on a predicate that is demonstrably correct today: a coverage note, not a defect, and not grounds to spend a seventh blocking round on a milestone whose behaviour is now verified end to end.

VERDICT: ACCEPT
