# Gate r3 — parapharmacy remediation spec v3, inventory / lot lens

Date: 2026-09-05. Reviewer: inventory-costing adversarial reviewer (Opus). Repo HEAD `fa000edc3`
(the two commits after `b9a5565aa`/`f75aa5023` are CI-gate reconciliation and a frontend permission-map
regeneration; every source file cited below was opened at `fa000edc3`). **Read-only: no code edited, no
tests run, no commits.**

Input: spec v3 (uncommitted working tree, 455 lines), `docs/glossary.md` diff, r2 inventory lens,
r2 synthesis (B1–B6 + majors), round-2 handover, Codex fix-round-2 notes.

Scope of this gate, per the orchestrator: **re-verify B4, B5 (inventory side), M07, M08, M09, M10, M11,
the T22 supersession statement, second-of-everything for L9, and any NEW false claim or silently decided
D3/D4/D9/RD2.** Everything else in the spec was reviewed at r2 and is not re-litigated here.

---

## Closure table

| Item | Status | Spec line(s) | Verification at `fa000edc3` |
|---|---|---|---|
| **B4** — L9 DEFAULT/unknown lot identification + split | **CLOSED** | 273 (gap row, ordered between L3 and L4), 304–312 (full disposition), 368 (own acceptance row), 425 (dependency: "L9 before L4 and alongside L2 freeze"), glossary **Lot identification** row | Ordered before L4 ✔; accompanies L2 freeze — 312 "L2's freeze must not ship without this replacement operator path" ✔; one existing surface — 306 "Add Lot identification to existing batch detail … reachable from the count's unknown-lot prompt by **linking to that same action**" ✔; scoped permissioned document — 306 `batches.identify`, `lot_identifications`, company/location/custody gates ✔; flat justification with conserved legs — 310 ✔; no aggregate/WAC/GL change ✔ (lots carry no cost: `inventory_batch_stock` has only `quantity`/`reserved_quantity`/generated `available_quantity`, migration `…create_inventory_batch_stock_table.php:25–32`, so value conservation is structural); no invented expiry / no used-history merge — 308 ✔; idempotent — 312 ✔. Source claims true: `ProductOpeningStockPhase.php:74–77` (comment verbatim), `BatchStockService.php:31` (`DEFAULT_BATCH_NUMBER = 'DEFAULT'`) and `:104` (`batchNumber: self::DEFAULT_BATCH_NUMBER`) ✔ |
| **B5 (inventory side)** — census entitlement filter + retail fixtures | **CLOSED** | 254, 256, 258, 262, 365, 372 | `LotLedgerDriftCensus.php:81` is exactly `->where('p.requires_batch_tracking', true)` and the file carries no module predicate ✔; spec 262 requires the fix "in the shared service as well as command/scheduler" and a separate **non-alerting** "not entitled" cohort ✔; retail flagged-product fixture anchor `tests/Feature/Security/BatchExpiryModuleAccessControlTest.php:23–29` exists and its docblock says exactly what 260 quotes ✔; `PosCoreReceiptProjection::requiresModule()` returns `null` at `:221–224` ✔ and the lot arm gates on the product flag at `:2026` ✔; `CreateProductRequest.php:226` accepts the flag ✔; `ProductForm.tsx:173` = `hasModule('BatchExpiry') \|\| hasModule('Inventory')` ✔ |
| **M07** — L5 three provenance producers | **CLOSED** | 275, 302, 371, glossary **Lot evidence** row (now declares all three tables + writers) | `BatchTraceabilityController.php:60` = `// Document sales (invoices, delivery notes)` and `:76` = `// POS sales` — the spec's `:60–74` / `:80–88` split is correct ✔; `StockTransferService.php:789` is the auto-FEFO docblock and `:947–989` is `assertAllocationsFollowFefo()` ending in "Batch allocations must follow FEFO (earliest expiry first)." ✔; converter/reservation producers named at 302 ✔ |
| **M08** — L3 full float census | **CLOSED** | 272, 298, 300, 367 | The census is **complete**: `grep -rn "): float\|(float)\|float \$" apps/api/app/Modules/BatchExpiry` returns exactly `FEFOInventoryService.php:940,957`, `Batch.php:143,145,148,150`, `BatchStock.php:44` (+ a comment at `BatchController.php:311`). All are named by 298. `BatchStock.php:44–84` verified to contain the accessor, `hasAvailableStock`, `reserve(float)`, `releaseReservation(float)` and `adjustQuantity(float)` whose `$this->update(['quantity' => $newQuantity])` persists a float-derived value — 298 calls that out in bold ✔. Guard + PG discrimination required at 300 ✔. Residual: web consumers not anchored → **N-3 (minor)** |
| **M09** — W5 reattribution = one flat movement, zero journals | **CLOSED** | 318 (whole paragraph), 310 (L9 uses the same shape), 369 | Verified end-to-end: `StockMovement::directionForRow()` at **`:183–192`** returns `'flat'` when `quantity_after == quantity_before` ✔; `InventoryGlPostingService::postForCountCorrection()` at `:35–52` returns `null` on `flat` **before** any account/valuation work ✔; the buffer is the only dispatcher and routes `MovementGlKind::CountCorrection` there alone (`InventoryGlPostingBuffer.php:77–80`) — no second GL sink can fire on the row ✔; the no-op guard is real and correctly quoted — `postCountCorrection()` at `:1390`, comment `:1408–1413`, `if (bccomp($adjustment,'0',self::SCALE) === 0) { return; }` at `:1414–1415` ✔, and 318 explicitly **keeps** it and adds a separate nonzero-lot-delta branch ✔; `COUNT_REPLAY` at `:69` ✔; `ApplyStockAdjustmentsOnCountingCompleted.php:257` (CountCorrection + InventoryCounting) and `:376` ✔; `RepairPhantomDefaultBatchesCommand.php:51–60` precedent verbatim ✔. New reason values are cheap and safe: `MovementReason::requiresGLEntry()` is `default => false` (`MovementReason.php:96–113`) and `stock_movements.reason` is a plain `string(50)` (`2025_12_24_133827_extend_stock_movements_table.php:16`) — no migration, no accidental GL. **Gap found in the same area → N-1 (major)** |
| **M10** — W5 lock census vs WAC order and `SKIP LOCKED` | **CLOSED (spec grain)** | 322, 310, 370 | Canonical order comment verified at `WeightedAverageCostService.php:92–96` (spec cites `:87–95`, overlapping — see N-6); `FEFOInventoryService.php:262–272` is the `FOR UPDATE OF ibs SKIP LOCKED` query ✔; 322 requires a full writer census, WAC-compatible integration, an explicit serialization-before-FEFO outcome, the provisional-count fallback, and a real PG leg ✔. Execution note (not a spec defect): the POS lot arm takes **no** product/advisory lock today — `consumeLotsForSaleLine()` at `:2016–2085` goes straight from `productRequiresBatchTracking()` into `containLotWork()` → `consumeBatchesAtomically()`. "Serialize through their common orchestration lock" therefore means *introducing* a lock that a sealed-event projection must **wait** on; the plan must state where it is taken relative to the aggregate arm's `advisory → stock_level → product` sequence, or it will invert against WAC |
| **M11** — R3 device cache | **CLOSED** | 336, 338, 340, 342, 346, 356, 373 | Every device citation is true: `stockDistribution.ts:1–13` ("Numeric values are decimal strings at quantity scale 4") ✔; `gridStock.ts:6–9` (snapshot omits pending receipts/cart) and `:31–38` (`LocationStockDisplay.available: string`, "Decimal strings — never floats") ✔; `locationStockRepository.ts` exists ✔; `useStockDisplay.ts:57–71` (bccomp/formatAvailableQty) ✔; `stockGate.ts` `gateStockForAdd` and its `getEffectiveAvailable(db, product, variantId, cartLines)` call ✔; `types/product.ts:47,56` `stock_quantity: number` and `migrations.ts:29` `stock_quantity INTEGER` correctly demoted to "evidence of today's contract gap, **not the precision precedent**" ✔; `quantity_decimals` at `types/product.ts:112–119` ✔ (closes r2 MN-3). 336 requires SQLite TEXT + scale-4 decimal strings + no `Number()`/`parseFloat` ✔; 336 requires "available quantity **net of server reservations**" ✔; 338 requires netting local pending sales + cart through the stockGate path, with snapshot-coverage (not ACK) as the un-subtract trigger — stricter than today's `availability.ts:216–226`, which uses outbox membership; the spec anticipates the gap ("Unknown coverage means unavailable/uncertain guidance, not inventing a reconciliation watermark") ✔. Next-add vs cart-line semantics stated with a worked example at 340 ✔. NearExpirySlot mounts verified exactly: `ProductCard.tsx:566–567`, `ProductListRow.tsx:116–117`, `ProductTable.tsx:257–258`, slot body `NearExpirySlot.tsx:1–27` ✔ |
| **T22 supersession statement** | **CLOSED** | 274, 324, 376 | 324 preserves the counterevidence: `drawLotsDownForCountShortage()` is live at `StockAdjustmentService.php:1530–1594` ✔, and forbids claiming the negative leg as newly delivered; 376 says T22/OQ-7 is "superseded **in design**", not implemented ✔. The demoted pin is real: `InventoryCountingDefaultBatchTest.php:130–131` asserts `stock_levels = 7.0000` and DEFAULT batch stock `== 7.0000` ✔ (a no-real-lot fixture, so the pin is consistent with today's remainder-bounded top-up — see N-1) |
| **Second-of-everything for L9** | **CLOSED (one minor)** | 360 (matrix preamble: two companies through real provisioning, two locations, two real lots, re-run idempotency), 368 (L9 row: two companies/locations, duplicate + conflicting operation UUID, "repeated operation adds nothing"), 404 (evidence-table classification incl. lot-identification documents) | Catalogue keys preserved, not changed: `product_batches` company-scoped uniques verified at `2026_06_02_100008_add_variant_id_to_product_batches.php:27–31` ✔. Missing: the explicit unique/scope key for the new `lot_identifications` table, which W5's child table *does* state → **N-4 (minor)** |
| **Silently decided D3/D4/D9/RD2** | **NONE — all OPEN with consequences** | 61 (D3), 62 (D4), 67 (D9), 68 (RD2); body defers at 344/348 (D3), 346 ("While D4 is open, no approved freshness cutoff or extra checkout refusal is implied"), 256 (D9), 270/282 (RD2) | No inventory-lens decision is resolved in prose while the register says OPEN ✔ |
| **New false claim about HEAD (lot lens)** | **NONE FOUND** | — | Additionally opened and confirmed: `FEFOInventoryService.php:95` tie-break includes `created_at` ✔ (301's "do not re-open" is right); `BatchController::expiring()` `:215–236` reads `location_id` raw with **no** `locationScopeResolver` while `expired()` `:252` resolves it ✔; `RolesAndPermissionsSeeder` grants as tabled at 286–294 (spot-checked `:547`, `:631–632`, `:692`) ✔; `Batch.php:143–151` + `BatchResource.php:40,59` ✔; `Sidebar.tsx:222–223` ✔ |

**No r2 blocker or major remains open in this lens.**

---

## NEW findings

### N-1 — MAJOR. W5/L4 names the negative lot arm but never names the **positive** one, which auto-credits the DEFAULT lot inside the very method the flat reattribution branch is being added to.

- Spec: 274 (L4 gap row cites only `InventoryCountingItem:79–105`, the pin, and the *shortage* heuristic
  `:1551–1594`), 318 (W5 mechanics), 324 (preserves the negative-arm counterevidence only), 369.
- Source: `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:1446–1449`
  — inside `postCountCorrection()`, immediately after the movement is recorded and the GL context enqueued:
  `if (bccomp($adjustment,'0',self::SCALE) > 0) { $this->ensureDefaultBatchForImplicitPositiveStock(...); }
  else { $this->drawLotsDownForCountShortage(...); }`. The positive arm's semantics are documented at
  `:1472–1475` — "since W2-7 tops the DEFAULT lot up only to the **UNTRACKED REMAINDER** and therefore
  never re-mints a phantom lot on top of real ones" — and the remainder is computed live by
  `BatchStockService::ensureDefaultBatchForUntrackedRemainder()` (`:255–300`, remainder = aggregate − Σ lots
  at call time, only ever tops **up**).
- Failure scenario (ordering, not sign): W5's lot-grain count writes the observed +5 to an identified lot
  through the adjustment service, and the aggregate arm still runs `ensureDefaultBatchForImplicitPositiveStock`.
  If the DEFAULT top-up executes **before** the lot-specific credit is persisted (it is called from inside
  `postCountCorrection`, which today runs before any new child-observation application), the remainder is
  still +5, so DEFAULT gains 5 and the identified lot then gains 5 → `Σ lots = aggregate + 5`. On the launch
  tenant that is a fresh phantom DEFAULT lot on top of exactly the lots L9 just identified, i.e. the L9 work
  undone by the first count. The spec's own absolute Σ-lots finalization check would surface it, but as a
  blocked/failed finalize with no stated cause, and the spec gives the implementer no instruction about this
  call site at all. Second, smaller consequence: a lane reading 274 may re-implement a remainder bound that
  W2-7 already shipped and report it as newly delivered — the exact hygiene 324 enforces for the negative arm.
- Minimum correction: in W5 (318) name **both** lot arms of `postCountCorrection` as the code a lot-grain
  count replaces — `StockAdjustmentService.php:1446–1447` (`ensureDefaultBatchForImplicitPositiveStock`) and
  `:1448–1449` (`drawLotsDownForCountShortage`) — state that on a tracked lot-grain count neither heuristic
  may run (or must run only on an explicitly unidentified residual, after the observed lot legs are
  persisted), and add the W2-7 counterevidence sentence mirroring 324: today's positive top-up is already
  remainder-bounded (`:1472–1475`), so the bound is preserved, not newly earned.

### N-2 — MAJOR (plan). L6 promotes to a daily `--fail-on-drift` alert a census query that no test exercises and that cannot run on the default test driver.

- Spec: 276 (L6 disposition — fix the filter, then schedule with `--fail-on-drift`), 372 (acceptance row:
  entitled drift/clean cohort, unentitled cohort, missed run, duplicate scheduler…), 443 (evidence row cites
  "existing drift and late-sync **detectors**" — code, not tests), 449 (global "SQLite-only passing results
  cannot establish PostgreSQL … guarantees").
- Source: `LotLedgerDriftCensus::batchTrackedTuples()` uses PostgreSQL-only
  `whereRaw('pb.variant_id IS NOT DISTINCT FROM sl.variant_id')` (`:72`) plus `COALESCE(SUM(ibs.quantity),0)`
  grouped on `sl.quantity` (`:75–91`). `grep -rln "LotLedgerDriftCensus\|lot-drift-census" apps/api/tests
  apps/api/routes` returns exactly **one** file, `tests/Feature/Fiscal/PosCoreReceiptProjectionBatchLotTest.php`
  — which skips the whole lot-draw family off PostgreSQL (`:883–886`) and whose own docblock records that
  "the pgsql `--filter` allowlist is the ONLY CI job that runs this class while `feature-lane-fiscal-finance`
  is **parked**" (`:405–408`). The census service therefore has zero dedicated coverage today.
- Failure scenario: the entitlement predicate is added (a join or subquery over module activation); on the
  default SQLite lane nothing runs, and the PG lane that would run it is parked. A predicate that resolves to
  an empty cohort ships green and the scheduled census reports **clean** every night — precisely the "false
  clean status" 276 forbids — while real Σ-lots drift accumulates unseen on the launch tenant.
- Minimum correction: L6's acceptance row must require the cohort/entitlement legs (drifted, clean,
  unentitled, missed run) to execute **on PostgreSQL in a lane that is currently live**, name the parked-lane
  hazard, and state that the census gains its own test class rather than being covered incidentally by the
  projection lot test.

### N-3 — MINOR. L3's "web batch types" is the only unanchored member of an otherwise complete float census; the live web consumers sum quantities as floats.

- Spec: 300 ("Census … web batch types, generated DTOs and device sync; migrate number→string deliberately
  end-to-end"), 367.
- Source: `apps/web/src/features/batches/pages/BatchDetailPage.tsx:271,274,277` render
  `parseFloat(level.quantity).toFixed(decimals)` and `:289,294,299` compute the table **totals** with
  `stockLevels.reduce((sum, level) => sum + parseFloat(level.quantity), 0)` — float addition of quantities on
  an operator-facing total. `apps/web/src/features/batches/types.ts:38–39` and `:281` declare
  `total_quantity: number` / `available_quantity: number`, the FE mirror of the PHP float accessors L3 retires.
- Why it matters: once the API emits strings, these lines still compile and still render — the defect becomes
  invisible instead of fixed, and the "end-to-end" migration stops at the API boundary.
- Minimum correction: anchor them in 300 (`BatchDetailPage.tsx:271–299`, `types.ts:38–39,281`) and require the
  ESLint guard (`no-parsefloat-on-money`) to cover this surface, alongside the PHPStan guard already required.

### N-4 — MINOR. `lot_identifications` has no stated uniqueness/scope key, while W5's child table does.

- Spec: 306 ("stable tenant/company operation UUID"), 312 (re-run returns the original result), 404
  (evidence-table classification) — versus 316, which states `(tenant_id, company_id, counting_item_id,
  batch_id)` and "tenant-only uniqueness is forbidden".
- Minimum correction: state the L9 key in the same form — expected `(tenant_id, company_id, operation_uuid)`
  with `company_id`/`location_id` persisted — so the convention-09 ratchet outcome is decided in the spec
  rather than discovered in code review.

### N-5 — MINOR. The new flat, zero-quantity movements have an unnamed downstream consumer.

- `StockAdjustmentService::dispatchCountMovementEvents()` (`:1756–1805`) emits `StockMovementRecorded`/`V2`
  after commit for every count-correction movement, and `ChannelServiceProvider.php:29` binds
  `DispatchStockChangeToChannels`, which pushes `newStockLevel` to published channel mappings. For a W5
  reattribution or an L9 identification that level is unchanged, so the push is a debounced no-op — but the
  spec should say so, so a lane neither suppresses the event (breaking audit symmetry) nor treats the push as
  a stock change.

### N-6 — MINOR (citation). The lock-order anchor is one comment block off.

- 322 cites `WeightedAverageCostService.php:87–95`; the "Concurrency (canonical lock order) … advisory ->
  stock_level rows -> product row LAST" sentence is at **`:92–96`** (`:87–91` is the in-transit denominator
  prose). Repoint before the plan quotes it — the whole inventory lock census hangs on this anchor.

---

## Rejected false positives — preserve these in v3 and in any later round

- **"The flat reattribution row will still post a journal."** No. Verified twice over:
  `InventoryGlPostingBuffer.php:77–80` is the only dispatcher and sends `CountCorrection` contexts to
  `postForCountCorrection()`, which returns `null` on `flat` (`:42–46`) before touching valuation or accounts.
  Spec 318 is correct as written.
- **"Removing the zero-adjustment guard is needed to write the flat row."** No — 318 explicitly keeps the
  guard for a true no-op and adds a separate nonzero-lot-delta branch. Both behaviours are required by 369
  ("true no-op still writes no row"). Do not let a later round trade one for the other.
- **"A new `LotIdentification` movement reason needs a migration / risks a GL leg."** No.
  `stock_movements.reason` is `string(50)` (`2025_12_24_133827_extend_stock_movements_table.php:16`) and
  `MovementReason::requiresGLEntry()` is `default => false` (`:96–113`), so 310's "explicitly classified as
  no-GL" is achievable in code only. (Plan-level: check the exhaustive `label()`/`glCounterFamily()` matches.)
- **"L9 must carry a lot cost basis."** No. `inventory_batch_stock` stores only `quantity`,
  `reserved_quantity` and the generated `available_quantity` — cost is product-level WAC — so 310's
  "unchanged WAC" is structural, not an assertion to be re-argued.
- **"The census must also filter reserved/expired lots to match FEFO."** No — the census is deliberately
  state-based and its docblock (`LotLedgerDriftCensus.php:25–44`) explains why the FEFO predicate being
  narrower is the *reported* condition, not a bug to fix. 276's "no automatic repair" is right.
- **"today's positive count arm inflates DEFAULT to the whole aggregate."** Not true at HEAD — it tops up the
  untracked remainder only (`:1472–1475`). The spec never claims otherwise; N-1 asks only that the spec say so.
- **Sound parts worth keeping unchanged:** the L9 paragraph's refusal set (reserved/consumed/overdrawn source,
  target number with used history, zero-quantity split, Σ lots ≠ aggregate ⇒ block) at 308–310; the
  "one existing batch-detail action, counting links to it" surface discipline at 306 (convention 11);
  the demotion of `types/product.ts` from precedent to gap evidence at 336; the next-add-versus-cart-line
  worked example at 340; the ACK-is-not-inclusion rule at 338; 376's "balanced lot ledger is quantity
  consistency, not proof of physical lot capture".

---

## What to fix before merge

Fold N-1 (name both lot arms of `postCountCorrection` and the ordering rule, plus the W2-7 counterevidence)
and N-2 (L6 needs a live PostgreSQL census leg) into the execution plan; N-3 to N-6 are one-line spec edits.
Nothing here blocks owner review.

VERDICT: ACCEPT-FOR-OWNER-REVIEW
