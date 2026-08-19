Adversarial merge-gate review — **Wave 3 / 3C, milestone M3, round 1**

Scope held to the brief's M3 section (`docs/handoff/CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md:439-483`) + the M3 evidence-contract row (`:542`). Amending authority: none. Diff reviewed: `7edf6c733..HEAD` (M3's three commits) against the wave base. Lenses: **inventory-costing** and **fiscal-pos** both apply and were exercised.

Verified green locally by path: `CheckCogsCoverageCommandTest` 19/19 (42 assertions), `ReverseInventoryMovementEntriesCommandTest` 3/3 (27 assertions).

---

## Register

**1. P1 — CONFIRMED — `apps/api/app/Modules/Accounting/Presentation/Console/CheckCogsCoverageCommand.php:279-325` — D-f's POS arm reports two by-design no-movement populations, so it fires permanently.**
The POS anti-join filters only `receipts.company_id`, `receipts.created_at >= cutover`, `products.is_physical`, `lines.quantity > 0`. It carries no `receipt_type` awareness and no disposition awareness. `PosCoreReceiptProjection::restockForLines` (`:2062-2065`, `:2087-2096`) deliberately writes **no** `stock_movements` row for two dispositions, both pinned as correct by shipped tests (`tests/Feature/Fiscal/PosCoreReceiptProjectionRefundDispositionStockTest.php:354` `not_received must NOT restore stock`; `:371` regulated never-restock). Meanwhile `writeLines` (`:1238`) writes a `pos_receipt_lines` row with the positive canonical quantity and a resolved physical `product_id` for those same lines.
*Failure scenario:* a customer disputes a delivery and the refund is booked `disposition = not_received`; or a pharmacy refunds any `RestockPolicy::Never` product (the launch tenant is a parapharmacy). The refund receipt is above the watermark, its line is physical with `quantity > 0`, and no `('pos_receipt', receipt_id, product_id)` movement exists — because none should. D-f logs a `Log::warning` and the command returns `FAILURE`, tripping the scheduler's `onFailure()` hook **every night, forever**. The M3 evidence contract requires each check "stays silent on a constructed negative"; no such negative was constructed for either disposition. This is the exact "a detector that always fires is not a detector" failure the brief invokes to justify R-10's WO stub (`:249-256`).

**2. P1 — CONFIRMED — `CheckCogsCoverageCommand.php:224-238` — D-e reports every inventory-counting correction; the stock-adjustment exclusion does not cover that lane.**
`$nonCogsGlReasons` (`:174-181`) resolves to `{GoodsReceipt, SupplierReturn, AdjustmentPositive, AdjustmentNegative, CountCorrection}`. `MovementReason::CountCorrection` movements are written by both counting paths with `reference_type = StockMovementReferenceType::InventoryCounting` (`'inventory_counting'`) — `ApplyStockAdjustmentsOnCountingCompleted.php:204-206` and `:269`, via `StockAdjustmentService.php:1352`. They are therefore **not** caught by D-e's `reference_type != 'stock_adjustment'` exclusion. No production site enqueues `MovementGlKind::CountCorrection`: a full sweep of `MovementGlKind::` call sites yields only `DeliveryNoteService:300` (Exit), `ReturnNoteService:735` (Entry), `PosCoreReceiptProjection:1989/2373`, `ReceiptReturnService:1364`, `ReturnScrapWriteOffService:170` — plus the buffer's own dispatch (`InventoryGlPostingBuffer.php:79`). Posting that kind is **T21, a 3D/M5 deliverable** (brief `:503-528`), strictly after M3.
*Failure scenario:* a tenant completes any inventory counting with a variance after the cutover. Each resulting `CountCorrection` movement has `requiresGLEntry() = true`, no `InventoryGlSourceTypes::ALL` entry, and a non-excluded `reference_type` → D-e fires and the command exits non-zero on every subsequent nightly run until 3D/M5 merges. The brief made the D-e/adjustment exclusion an explicit reviewer-confirmation item precisely so "D-20's decision reads as a permanent detector fire" could not happen (`:476-483`); the identical hazard on the counting lane was not carried across. *(Closable by an orchestrator ruling that accepts the fire for the 3C→3D window, but it is not closable by the code as written.)*

**3. P2 — CONFIRMED — `CheckCogsCoverageCommand.php:206-219` — D-b has no `is_historical` exclusion, so refunds of pre-cutover sales fire permanently.**
D-a excludes `is_historical = false` (`:193`); D-b does not. `PosCoreReceiptProjection::originalPosSaleBasis` (`:2382-2435`) returns `['unit_cost' => null, 'is_historical' => true]` for a refund whose original sale predates the watermark, and `restockStock` persists that movement with `unit_cost = NULL`, `reason = POSReturn` (affectsCOGS), `reference_type = 'pos_receipt'`, `created_at` above the watermark. `PosCoreReceiptProjectionRefundDispositionStockTest.php:308-350` pins that shape as the *intended* outcome ("does not book a one-sided inventory entry").
*Failure scenario:* every refund of a pre-cutover sale during the post-cutover tail is reported by D-b as "COGS-bearing inventory movement has no usable cost", though the null cost is the deliberate design. The `is_historical` flag is the existing marker for "deliberately outside the seam" and D-b ignores it.

**4. P2 — CONFIRMED (trigger-conditional) — `CheckCogsCoverageCommand.php:189-204` — D-a carries no `stock_adjustment` exclusion, and the brief's own M5 ruling arms it.**
D-a's `$cogsReasons` includes `Damage`, `WriteOff`, `Expiry`. `StockAdjustmentDocumentService.php:261` routes non-batch document lines through `adjustByDelta` with `referenceType: StockAdjustment` and those reasons. It is inert **today only** because `adjustByDelta` leaves `unit_cost` NULL and D-a has `whereNotNull('unit_cost')`. The brief's M5 ruling (`:524-528`) and the M3-authored ticket `docs/superpowers/tickets/2026-08-18-stock-adjustment-document-gl-leg.md` both state 3D **threads `unit_cost` onto exactly that movement**.
*Failure scenario:* 3D lands. Every posted non-batch damage/write-off stock-adjustment line now has a costed, GL-less movement above the watermark → D-a fires on the whole D-20 population. The ticket says "D-b and D-e therefore exclude `reference_type = 'stock_adjustment'`" — D-a needed the same clause and did not get it.

**5. P3 — CONFIRMED — `tests/Feature/Accounting/CheckCogsCoverageCommandTest.php:340-380` — the GR-arm negative asserts against a GL shape production never writes, and masks a real blind spot.**
The test manufactures `JournalEntry(source_type: 'inventory_entry', source_id: $movement->id)` on a `MovementReason::GoodsReceipt` movement to reach exit 0. Production never writes that: `GoodsReceiptService` enqueues nothing into the inventory-GL buffer, and `PostGrIrOnGoodsReceipt` → `GeneralLedgerService::createGoodsReceiptGrIrEntry` writes `source_type = 'goods_receipt'` (`GeneralLedgerService.php:1875,1923`), which is absent from `InventoryGlSourceTypes::ALL`. Separately, `WeightedAverageCostService::recordPurchase` (`:260-281`) never sets `reason`, so real inbound movements carry `reason = NULL` and fall outside D-a/D-b/D-e's `whereIn` entirely. Net: the whole inbound lane is invisible to the movement-keyed checks, and no test can detect that — the M3 evidence's claim that "D-e reports non-COGS `requiresGLEntry()` movements without an entry" overstates live coverage.

**6. P3 — CONFIRMED — `CheckCogsCoverageCommandTest.php:265, 301, 342` — three negative assertions are vacuous by arity.**
`Log::shouldNotHaveReceived('warning', [\Mockery::pattern('/\[D-a\]/')])` compiles to `shouldHaveReceived('warning')->with($pattern)->never()` — a **one-argument** expectation. Every production call is `Log::warning($message, $context)` (two args), so the expectation can never match and the assertion can never fail. The co-located `assertExitCode(0)` is what actually carries the signal; the Mockery lines should be dropped or rewritten with `withArgs()`.

**7. P3 — CONFIRMED — `CheckCogsCoverageCommand.php:288, 350` — R-6's mutable-flag exposure is newly extended to the two highest-volume tables.**
Both new arms key population on the live `products.is_physical` flag. R-6 (brief `:207-214`) states the architectural fix is movement-keyed reading and that "3C must **not** reintroduce flag-keyed reasoning in the detector checks." D-f is structurally flag-keyed (it has no movement to key on) and the DN arm shipped this way in 3E, so this is an inherited shape rather than a regression — but flipping a product `is_physical` false→true now retroactively pulls historical POS/GR lines into the fired population, and nothing records that trade-off at the new call sites.

**8. P3 — CONFIRMED — `CheckCogsCoverageCommand.php:109, 142, 148` vs `app/Modules/Company/Domain/Company.php:113` — dead null-guards contradicted by the schema.**
`companies.inventory_gl_cutover_at` is created NOT NULL with `useCurrent()` and backfilled (`database/migrations/tenant/…_add_inventory_gl_cutover_at_to_companies.php:21-29`), and `Company::booted()` sets it on `creating` (`:150`). The three `if ($cutoverAt !== null)` branches are unreachable-false, and the property docblock ("null until the cutover deploy step") contradicts the column. If the invariant ever slipped, the guards would silently disable the *entire* detector suite — including the DN arm that shipped unconditionally in 3E — rather than fail loud.

**9. P3 — `apps/api/database/seeders/ParapharmacySeeder.php:975` — out-of-scope edit.**
Removing the explicit `'is_physical' => true` is outside T18/T19/T19b/detectors/C-5/R-5. Behaviourally inert (`Product::$attributes['is_physical'] = true`, `Product.php:138`), but it is an unflagged scope excursion in the same commit as the detector work.

---

## Items verified and PASSING (not findings)

- **T18** — `createCOGSEntry` deleted (`GeneralLedgerService.php`, −102 lines); zero remaining references under `app/` or `tests/`. The no-`CompanyContext` chain-sequence case was **moved, not lost** — `GLIntegrationTest::test_inventory_movement_posting_without_company_context_assigns_verifiable_chain_sequence` clears `CompanyContext` and drives `createInventoryMovementEntry`.
- **T19** — `scripts/preflight-wave3-inventory-gl-cutover.sql` is `ON_ERROR_STOP`, read-only + fail-closed, three `DO` blocks. Release note has all seven sections incl. 4a/4b/4c and the four ticket cross-references. R-11's probe returned 0 → self-closing, no remediation section owed.
- **T19b** — `accounting:reverse-inventory-movement-entries` requires `--from` + `--confirm`, is idempotent under `lockForUpdate` on the original (`GeneralLedgerService.php:4667-4720`), inverts every leg exactly, never edits the original, has no application-path caller. `JournalCode::fromSourceType('inventory_movement_reversal') → Misc` added.
- **D-f field contract** — `arm`, `line_id`, `product_id`, source id, `document_number`, `document_date`, `age_days` present on all three arms; **`amount => null`, never `0`**, on all three (`:305-317`, `:334-345`, `:369-380`). No grace window on the POS arm.
- **WO stub** — named no-op citing `2026-08-10-workshop-parts-goods-lane-gap.md` (`:156-165`); the negative test is **not** vacuous (the watermark is always set, so the D-f arms genuinely run).
- **D-e adjustment exclusion (the flagged derived obligation)** — **confirmed present** for the `reference_type = 'stock_adjustment'` lane specifically (`:229-232`), matching D-b. The gap is the counting lane (finding 2), not the adjustment lane.
- **R-5** — documented SQL counterpart in `PhysicalLinePredicate`'s docblock, both scanners now carry the `tenant_id` predicate and a comment naming the other, pinned by `test_scanner_sql_physical_predicates_match_the_scoped_row_predicate` (red-first evidence recorded: the forged sibling-tenant line was reported before the fix).
- **C-5** — pulled forward into M2; live at `InvoiceController.php:1028` with `flushIfOutermost()`, covered by `InventoryGlCompositeRootTest`. Acknowledged in M3-evidence.
- **R-12 exemption ladder** — untouched by M3; `InvoicedBeforeDeliveryScanner`'s only change is the tenant predicate.
- **R-14** — no product lock added to the projection; M3 does not touch `PosCoreReceiptProjection`.
- **Rule 19** — no float on money/quantity. Costs surface as strings (`total_cost`, payload `unit_cost`); `age_days` is a non-monetary int. No `bcformat($float, …)`, no bare `getScale()`.
- **Standing checks** — constructor injection only in production (`app()` confined to tests); no new migrations, queues, or user-facing strings in M3; no `.github/workflows` change; per-tenant iteration via `forEachTenant` preserved.

## Bypasses attempted that FAILED (recorded)

1. *D-f under-filters "stock-tracked" products* — no `track_inventory`/`is_stock_tracked` column exists anywhere in `database/migrations/` or `Product`; `is_physical` **is** the whole predicate. Failed.
2. *D-f GR arm false-fires on PO-less standalone receipts* — `goods_receipts.purchase_order_id` is NOT NULL (`…_create_goods_receipts_tables.php:18`), so `reference_id = receipts.purchase_order_id` can never NULL-out the join. Failed.
3. *D-f GR arm false-fires on draft-then-posted receipts whose lines keep `movement_id = NULL`* — the posting path `forceFill`s the existing receipt line with `movement_id` (`GoodsReceiptService.php:701,712`). Failed.
4. *D-e false-fires on every goods receipt* (`MovementReason::GoodsReceipt` requiresGLEntry + GR-IR writes a non-`ALL` source type) — `recordPurchase` never sets `reason`, so inbound movements carry `reason = NULL` and fall outside the `whereIn`. Failed as a P1; survives as finding 5.
5. *The WO negative test is vacuous via a null watermark* — `Company::booted()` plus a NOT NULL `useCurrent()` column mean the watermark is always set. Failed.
6. *Training-mode POS receipts false-fire D-f* — `applyStockMovementForLines` is not gated on `training_flag` (`:476`), so training receipts do move stock. Failed.
7. *The `ParapharmacySeeder` `is_physical` removal flips seeded products non-physical* — `Product::$attributes['is_physical'] = true`. Failed as a defect; survives as finding 9.

---

**Gate rationale.** T18, T19, T19b, C-5, R-5 and the D-f field/grace contracts are delivered and honestly evidenced. The blocker is the detector semantics: M3's stated code evidence is "each detector check fires on a constructed positive **and stays silent on a constructed negative**," and two checks (D-f POS, D-e) have by-design negative populations in live code that were never constructed and are not silent. Both would fire on the first night after the cutover deploy, for the launch tenant's ordinary operations.

VERDICT: CHANGES-REQUIRED
