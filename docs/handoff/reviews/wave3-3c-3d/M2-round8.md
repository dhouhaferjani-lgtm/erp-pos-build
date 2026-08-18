Read-only review complete. Tree untouched (`git status` clean at `c15794eab`); the scratch PostgreSQL DB I created (`autoerp_m2r8_review`) was dropped.

# M2 adversarial merge-gate review — round 8 (STOP-A scoped)

**Milestone:** M2 — THE CUTOVER COMMIT (T14+T15+T16+T16b+T16d+T16e+T17)
**Reviewed:** `git diff 26b63f0ff..HEAD`. Production is now ONE commit, `2bd9595d9`; `fda47ef4a`/`7d0ab538c`/`9f90f33de`/`c15794eab` are docs-only (verified: `git diff 2bd9595d9 HEAD --stat` touches nothing under `apps/api/`).
**Amending authority applied:** `ORCHESTRATOR-RULING-2026-08-18-m2-stop-a.md` — round 8 scoped to rulings 1–5; it supersedes conflicting brief wording.
**Lenses:** inventory-costing — applies. fiscal-pos — applies (T16d/T16e byte identity, sealed-bytes surface; round 8 touches neither). tenancy-authz / treasury — not named for M2; applied only as standing checks.
**Environment:** local PostgreSQL 5432, isolated scratch DB, `phpunit-pgsql.xml`. Four suites executed by me (below).

## Ruled scope — disposition

| Ruling | Item | Status | Evidence I verified myself |
|---|---|---|---|
| **1** | P2-1: R-1 option (a) ported to the interactive path | **CLOSED** | `ReceiptReturnService.php:1545-1576` `originalSaleMovementUnitCost()` mirrors `PosCoreReceiptProjection::originalPosSaleBasis()` (`:2382-2407`) at receipt+product+variant grain; call site `:442-451` prefers it, `receiptLineUnitCost()` only when the movement is absent. Distinguishing fixture `ReceiptReturnFlowTest.php:953-1028` (`1.234568` vs the line's `1.2346`) — **executed, 1 passed / 4 assertions on PG**. Would fail under option (b) since the receipt-line value is `1.2346`. |
| **1 (collateral)** | scale-6 migration docblock | **CLOSED** | `2026_05_30_000000_widen_wac_cost_columns_to_scale_6.php:24-28` now names `stock_movements.unit_cost` authoritative and the receipt line a fallback; `stock_movements.unit_cost` is in fact widened at `:50`. |
| **2** | P2-2: `transactionLevel() === 0` gate, opt-in deleted | **CLOSED** | `InventoryGlPostingBoundaryGuard.php:26-30`; `enableTestBoundaryGuard`/`isTestBoundaryGuardEnabled` return **zero** hits across `app/` + `tests/`; flag moved to constructor via `InventoryServiceProvider.php:40-44`. **Executed:** `InventoryGlPostingSeamTest` 25 passed / 100 assertions (incl. the three request/job/nested boundary throws at `:317`, `:372`, `:383`). |
| **3** | P3-3 ratchet | **CLOSED** | `PosCoreReceiptProjectionRefundDispositionStockTest.php:129-157` — **executed, 1 passed / 2 assertions**; non-vacuous (watermark stamped at `Company.php:150`, population asserted = 2 covering both `POSSale` and `POSReturn`). |
| **3** | P3-4 M2 report section + deptrac | **CLOSED** | `docs/sessions/codex-dpa-wave3-3c-3d-report.md:194-237` — T14–T17, actual outputs, deptrac 127 reconciled to M1's 116 and baseline 99 with the +11 attributed per file. |
| **3** | P3-5 squash | **CLOSED** | `2bd9595d9` is the sole production commit; `f848dab39`/`28a2d854b`/`55e03c025` are no longer ancestors. |
| **4** | T11c compositional register | **SATISFIED** | All six cited trace methods exist (`CogsRelocationCharacterisationTest.php:217/227/237`, `PosReturnScrapWriteOffTest.php:478`, `InventoryGlVoucherLockOrderTraceTest.php:133`, `InventoryGlCompositeRootTest.php:144`, `GoodsReceiptGlPostingOrderTest.php:385/448`); instrument at `CogsRelocationCharacterisationTest.php:442-469` is a real query-order assertion, not a salt. Pair 4's counting writer has no trace because it takes **no** company-grain GL advisory — I confirmed `StockAdjustmentService` holds only product-grain `ProductCostLock` advisories (`:111`, `:700`, `:793`, `:1261`), so no AB-BA edge is constructible. |
| **5** | ship-with-ticket P3-6/8/9/10 | **CLOSED** | Four ticket files present under `docs/superpowers/tickets/2026-08-18-*`; P3-8 (`PosCoreReceiptProjection.php:2415`) and P3-9 (`ReceiptReturnService.php:1590`) remain open exactly as ticketed, not silently altered. |

**Nothing inside the ruled scope is CHANGES-REQUIRED.** The findings below are all P3.

## Register

**1 — P3 — CONFIRMED — `app/Modules/POS/Application/Services/ReceiptReturnService.php:1545-1576` (with `:442-451`, `:1582`)**
`originalSaleMovementUnitCost()` returns `found: true, unit_cost: null` when the sale movement exists but carries no cost, and the call site branches on `found`, not on the cost. So a null-cost sale movement now skips `receiptLineUnitCost()` entirely and drops to `resolveReturnUnitCost()`'s **live product WAC**.
*Failure scenario:* a receipt authored by the retired `ReceiptCreationService::createReceipt` path — its `decrementStock` (`:966-978`) writes `reason=POSSale`, `reference_type='pos_receipt'` with **no** `unit_cost`, while its receipt line **does** carry one (`:342`, cast `decimal:4` at `ReceiptLine.php:115`). Returning such a receipt today stamps the `POSReturn` movement with today's WAC instead of the sale-time snapshot round 7 was reading. **Money-inert**: those receipts predate the watermark, so `originalReceiptPredatesInventoryGlCutover()` (`:1606-1615`) marks the context historical and `InventoryGlPostingService.php:90` suppresses the entry — the damage is confined to the movement's cost stamp and anything reading it downstream. This is also literally what ruling 1 prescribes ("fallback only when the sale movement is absent"), so it is a note, not a defect against the gate. A shared helper (the P3-8 ticket's natural home) that treats `found && cost === null` as "not found" closes both at once.

**2 — P3 — CONFIRMED — `database/migrations/tenant/2026_05_30_000000_widen_wac_cost_columns_to_scale_6.php:82`, `:102`**
Round 8 removed the `if ($alterParts !== [])` guard from `up()` and `down()` of an already-shipped migration. Ruling 1 authorized the **docblock** only; this is unrequested collateral inside `2bd9595d9`. It is **behaviourally inert** — `self::COLUMNS` is a non-empty constant for all three tables, so `$alterParts` can never be empty and the emitted SQL is unchanged. Recording it because "edit a shipped migration body" is a class of change that should never ride along silently on a docblock fix.

**3 — P3 — CONFIRMED — `tests/Feature/Fiscal/PosCoreReceiptProjectionRefundDispositionStockTest.php:129-157`**
The D-13 ADDITION-4 ratchet builds its population exclusively from the **projection** writer (`v4SaleEvent` + `v4RefundEvent`). The live interactive `POSReturn` writer (`ReceiptReturnService::restoreStock`, `:1332-1346`) never enters the counted set. It is safe today — `CurrencyScale::bcformat($resolvedUnitCost, 6)` cannot yield null and `resolveReturnUnitCost` throws on a non-numeric — so the ratchet is narrow rather than wrong. A company-wide `whereIn(reason)` count driven through **both** writers would make it a true population ratchet.

**4 — P3 — CONFIRMED — evidence gap, `docs/handoff/reviews/wave3-3c-3d/M2-evidence.md:196-240`**
Making Guard 4 unconditional changes behaviour for **every** test in the repo, but round 8's regression evidence covers only the ruling's ten named classes. The last directory-wide `tests/Feature/Inventory` run (817 tests) was round 7, under the opt-in guard. The ruling did name that regression set, so this does not block. I bounded the blast radius myself: only 11 classes opt out of the wrapper transaction, and of the five outside the ruled set (`DocumentCancellationGlReversalTest` + four Treasury concurrency classes) none issues an HTTP request or processes a job, so `assertEmpty` is never reached there. Executed as a spot check: `ReceiptReturnFlowTest` (wrapper) **21 passed / 119 assertions**, `PosCoreReceiptProjectionRefundDispositionStockTest` (non-wrapper) **14 passed / 65 assertions**.

**5 — P3 — NOTE — house rule vs. `deptrac.baseline.json`**
The brief's house rule says deptrac "must not regress baseline 111"; the checked-in baseline is 99, M1 measured 116 and M2 measures 127. Ruling 3 asked only for "a deptrac number reconciled against the baseline drift recorded at M1", which the report does (`:230-235`, +11 all Domain→Inventory-Application buffer edges). The house-rule number itself is stale and should be re-pinned at the whole-branch M5 gate rather than carried forward as three conflicting figures.

## Bypasses attempted that FAILED (the implementation survived)

- **"P2-1 is green-by-fixture — the interactive sale writer doesn't produce the movements this lookup queries."** Failed: both live-and-legacy sale writers write `reason=POSSale` / `reference_type='pos_receipt'` (`ReceiptCreationService.php:966-978`, `PosCoreReceiptProjection.php:2365`). The lookup shape matches production, not just the fixture.
- **"`createReceipt` is a third live `POSSale` writer emitting NULL-cost movements above the watermark → ADDITION-4 breach."** Failed: all three call sites are retired/inert — `POST /pos/receipts` 410 (`ReceiptController.php:392-407`), `POST /pos/orders/{id}/close` 410 (`routes_orders.php:44-51`), `ExchangeService` `live: false` per the §14.3 chokepoint manifest (`ExchangeService.php:57-69`).
- **"The new R-1 test is PG-only and reds or vacuously passes on SQLite."** Failed: the `1.2346` assertion rides the model cast (`ReceiptLine.php:115`), not PG coercion — green on **both** PG and the default SQLite config.
- **"The ratchet is vacuous — no watermark on the test company, so the `>=` predicate matches nothing."** Failed: `Company.php:150` stamps `inventory_gl_cutover_at` on create; test green with a real 2-row above-watermark population.
- **"Guard 4 going global reds untouched suites."** Failed within reach — see finding 4.
- **"The squash is claimed, not performed."** Failed: single production commit; later commits docs-only.
- **"Round 8 introduced float/`app()`/queue/migration drift."** Failed: the production delta is 3 Inventory files + `ReceiptReturnService` + one docblock/dead-guard; no float, no `app()`, no `onQueue`, no schema change. I re-ran **Pint** (`{"result":"pass"}`) and **PHPStan level 8** on all four changed production files (`[OK] No errors`).
- **"P3-8/P3-9 were quietly closed or quietly widened."** Failed: both unchanged and ticketed, exactly as ruling 5 permits.
- **Not independently re-run:** the T16d/T16e byte-identity characterisations, the contained-failure matrix, the ten-pair harness, and deptrac — all unchanged by round 8 and confirmed at round 7; I verified the cited trace methods exist and that the instrument is a real ordering assertion.

VERDICT: ACCEPT
