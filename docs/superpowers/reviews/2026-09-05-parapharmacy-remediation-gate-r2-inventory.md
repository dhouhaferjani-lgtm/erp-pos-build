# Gate r2 — parapharmacy remediation spec v2, inventory / lot lens

Date: 2026-09-05. Reviewer: inventory-costing adversarial reviewer (Opus). Repo HEAD `f75aa5023`
(source identical to `b9a5565aa`; only docs differ). **Read-only: no code edited, no tests run, no commits.**

Input reviewed: spec v2 (uncommitted, `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md`,
349 lines), gate r1 review, owner handover R1–R4, Codex fix-round notes, root-cause archaeology,
tickets T22 and F7-residuals, `docs/glossary.md` diff (new **Lot evidence** row).

Scope of this gate: **W-LOT gaps L1–L8 and the W5/W6 content folded into it.** W2/W3/W4/W7 are reviewed
only where they touch stock, lots or quantity precision. Every claim below was checked against source at
HEAD; `file:line` are lines I opened.

---

## Verdict summary

Spec v2 does close the gaps the handover R1 enumerates — **all seven handover bullets map to an ordered
gap L1–L8 and every L-row has at least one acceptance row** (spec:262–271) — and its 30-odd citations of
current code in the lot lens are **accurate; I found no false claim about HEAD in this lens**. It is
nonetheless not yet acceptable: one launch-critical lot operation is missing entirely (BLOCKER), the
provenance vocabulary is registered for one of three allocation surfaces (MAJOR), and five design
decisions that determine whether the lane produces correct quantities/GL are left to the implementer.

---

## BLOCKER

### BL-1 — W-LOT has no DEFAULT-lot identification/split operation, and L2 removes the only existing one. Blocks spec. CONFIRMED (code) + CONFIRMED (spec's own rule).

- Spec lines: 208 (L2 disposition: "Freeze ordinary identity/expiry edits after first movement"), 224
  (W5: "Unknown physical lots require a recorded identification decision before finalization; do not
  invent expiry or silently assign DEFAULT"), 226 ("an explicit observed DEFAULT/unidentified opening
  cohort is not permission to top it up"), 230 ("Identity change must not merge two lots with existing
  histories"), 302 (W8: census of "DEFAULT/unknown lots" — census only).
- Source:
  - `apps/api/app/Modules/Import/Services/ProductOpeningStockPhase.php:71-74` — "Batch-tracked products
    are handled by the posting service itself (it backs the opened quantity with a **DEFAULT** lot) —
    parapharmacy verticals default every product to batch tracking"; the posted `OpeningBalanceLine`
    (`:103-116`) carries an optional expiry but **no batch number**.
  - `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:31,58,104` — the minted
    lot is literally `batch_number = 'DEFAULT'`.
  - `apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:46` —
    `unique(['company_id','product_id','batch_number'])`: one DEFAULT lot per product/company.
  - `apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php:20-24` +
    `Controllers/BatchController.php:158-171` + `apps/web/src/features/batches/pages/EditBatchPage.tsx`
    — the ONLY surface today that can give an existing lot a real batch number/expiry. L2 freezes it
    once the lot has movements, which every opening lot has by construction.
  - No split/reassign capability exists: `grep -rn "splitBatch|reassign|identifyLot|renameBatch"
    apps/api/app/Modules/BatchExpiry` returns nothing; `apps/web/src/features/batches/pages/` holds
    only List/Detail/Create/Edit/ExpiryWriteOff.
- Failure scenario (day one, the pilot's actual state): the parapharmacy imports opening stock; every
  product's entire on-hand sits in one `DEFAULT` lot. The shelf physically holds two batches. Under W5
  the counter records two lot observations; one is an unknown physical lot, so finalization demands a
  "recorded identification decision" — and there is no operation to (a) rename the DEFAULT lot, (b) split
  its holding into two identified lots preserving quantity and cost basis, or (c) transfer stock from
  DEFAULT into a newly created lot. The count either cannot finalize, or the operator collapses reality
  back into DEFAULT — which is precisely the state W-LOT exists to end. Iteration-1 shelf guidance is
  equally void: every suggestion reads "DEFAULT".
- Note the benchmark table already carries the counter-evidence: B7's Odoo source [S9] is the
  *"reassignment of lot/serial numbers"* page (spec:28). The baseline row is present; the disposition
  ("MATCH controlled correction") silently drops the reassignment/split capability that source describes
  — convention 10 class ("a baseline guarantee the flow silently lacks").
- Minimum correction: add an ordered gap **L9 — DEFAULT/unidentified lot identification and split**, with
  disposition (permissioned operation; splits a lot's holding at one location into ≥2 identified lots;
  quantity-conserving; cost basis unchanged; its own justifying movement/document per the
  document-per-action rule; append-only history; never merges two lots with histories) and its own
  acceptance row (two companies, two locations, DEFAULT→2 identified lots, Σ lots == aggregate before and
  after, re-run produces no second movement, WAC unit cost unchanged). If the owner defers it, it must
  become a decision row with the consequence stated: **iteration 1 ships with no usable lot identity for
  imported openings.**

---

## MAJOR

### MJ-1 — `system_fefo_estimate` is scoped to the POS allocation table; two other FEFO-inferred lot-identity surfaces are unnamed, one of them inside the very endpoint L5 cites. Blocks spec. CONFIRMED.

- Spec lines: 211 (L5 gap row cites only `PosCoreReceiptProjection.php:2052-2081,2104-2114` and
  `BatchTraceabilityController.php:80-88`), 220 (L5 disposition — inventory "receipt detail, forward/
  backward customer trace, exports, returns and any cached/web/device copies"), 268 (acceptance row L5),
  and `docs/glossary.md` new **Lot evidence** row, whose Table/module cell reads
  "Existing `pos_receipt_line_batch_allocations` / POS with BatchExpiry".
- Source:
  - `apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchTraceabilityController.php:60-74` —
    the **`document_sales`** branch of the SAME endpoint, reading `DocumentLine.batch_id` for invoices
    and delivery notes. Spec's citation `:80-88` is exactly the `pos_sales` branch; `:60-74` is excluded.
  - Those `document_lines.batch_id` values are **server FEFO**, not operator capture:
    `Document/Domain/Services/Conversion/Converters/SalesOrderToDeliveryNoteConverter.php:346,477` and
    `Document/Domain/Services/DeliveryNoteFromDocumentFactory.php:123` call
    `FEFOInventoryService::suggestBatchesForSale()`; so does
    `Inventory/Application/Services/StockReservationService.php:410-414,751`.
  - `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:789` — "Auto-fill FEFO
    batch allocations for every batch-tracked line", enforced at `:947-989` ("Batch allocations must
    follow FEFO"), persisted in `stock_transfer_line_batch_allocations`
    (migration `2026_06_05_121000_create_stock_transfer_line_batch_allocations_table.php`) and rendered
    to operators at `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx`.
- Failure scenario: W-LOT ships, the POS receipt trace is honestly labelled `system_fefo_estimate`, and a
  recall investigator opens the same `/batches/{uuid}/traceability` response and reads `document_sales`
  — a machine-chosen lot presented as the batch delivered to a named partner, unlabelled. Same for the
  transfer detail page. The spec's own W0/G4 requirement ("reject any surface presenting estimated FEFO
  as captured", spec:110) is therefore not met by the gap list that implements it.
- Minimum correction: (a) L5's gap row and acceptance row must enumerate the three producers —
  `pos_receipt_line_batch_allocations`, `document_lines.batch_id` (converters + reservations) and
  `stock_transfer_line_batch_allocations` — and the `document_sales` branch at
  `BatchTraceabilityController.php:60-74`; (b) the glossary **Lot evidence** row must declare all three
  tables and both write paths, not one, or explicitly declare the others out of the concept with a
  reason (convention 11: synonyms are declared, never discovered).

### MJ-2 — L3 closes the two guard call sites but leaves the float accessor and its other consumers in place, so the defect returns with the next caller. Blocks plan. CONFIRMED.

- Spec line 209 (L3 disposition): "…using validated exact raw decimal/numeric-string availability **for
  both sufficiency guards**"; spec:218 defers the remaining F7 residuals to a census.
- Source: `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchStock.php:44-46` —
  `getAvailableQuantityAttribute(): float { return $this->quantity - $this->reserved_quantity; }` (both
  operands are `decimal:4` casts, i.e. strings, coerced to float by `-`). Same entity, same class of
  defect, untouched by L3: `:49-52 hasAvailableStock()`, `:54-60 reserve(float $quantity)`,
  `:63-70 releaseReservation(float)`, and `:72-84 adjustQuantity(float $delta)` which computes
  `$this->quantity + $delta` in float and **persists it** (`$this->update(['quantity' => $newQuantity])`)
  — a float-derived quantity at rest. Also `Domain/Entities/Batch.php:143-151`
  (`(float) $this->batchStock()->sum(...)`) feeding `Presentation/Resources/BatchResource.php:40,59` on
  the batch list/show/create/patch, `/expired`, `/expiring`, `/recall`, `/transfer`, `/write-off` and
  `/products/{id}/batch-stock` responses, and `Domain/Services/FEFOInventoryService.php:957`
  (`getTotalAvailableQuantity(): float`).
- Failure scenario: the lane fixes `BatchStockService.php:471,515-516`, the acceptance row L3 passes, and
  the next lot-touching lane writes `if ($stock->available_quantity >= $qty)` against the still-float
  accessor — the same off-by-epsilon at the sufficiency boundary, plus the scientific-notation
  `bccomp`/`ValueError` trap the F7 ticket documents at residual (e). Nothing in the spec forbids it.
- Minimum correction: L3's disposition must require **retiring or converting the accessor itself**
  (`numeric-string` availability, or removal in favour of the raw generated column) and migrating every
  consumer, with a guard (PHPStan `ForbidFloatCastOnDecimalProperty` extension or an explicit test) so a
  new float consumer fails CI. Name `BatchStock::adjustQuantity()` explicitly: it writes a float-derived
  quantity at rest and is the one member of this set that can corrupt data, not merely a comparison.

### MJ-3 — W5 mandates offsetting lot corrections with "no net aggregate/GL quantity gain" but never states the movement/document shape, and the existing GL seam posts on any non-flat row. Blocks plan. CONFIRMED (seam behaviour) / PLAUSIBLE (implementer choice).

- Spec line 226: "booked 10+10 versus observed 15+5 produces +5/−5 lot movements and no net aggregate/GL
  quantity gain"; spec:226 also requires "Sum of lot movements must match the aggregate adjustment".
- Source:
  - `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:37-52` —
    `postForCountCorrection()` returns `null` **only** when `directionForRow() === 'flat'`; otherwise it
    posts `InventoryGainIncome` (in) or `InventoryShrinkageExpense` (out).
  - `apps/api/app/Modules/Inventory/Domain/StockMovement.php:183-192` — `'flat'` is defined as
    `quantity_after == quantity_before`.
  - Precedent for a lot-only correction that must not hit GL:
    `apps/api/app/Console/Commands/RepairPhantomDefaultBatchesCommand.php:51-60` — "a `stock_movements`
    row … Its `quantity` is **0** and `quantity_before === quantity_after`, because the AGGREGATE on-hand
    quantity is not changing".
- Failure scenario: an implementer reads "+5/−5 lot movements" and writes two aggregate
  `stock_movements` rows (+5 then −5). Both are non-flat, so the count-correction listener posts **two
  journal entries** — inventory gain income and inventory shrinkage expense, each 5 × unit cost — for an
  event where nothing physically or economically changed, inflating both P&L lines every time a
  pharmacist reattributes shelf stock between batches. It also re-enters the WAC path for a purely
  bookkeeping reattribution.
- Minimum correction: W5 must state the required shape: lot reattribution with zero aggregate delta
  writes ONE justifying `stock_movements` row with `quantity_before === quantity_after` (direction
  `flat`, therefore no GL), citing `StockMovement::directionForRow():183-192`,
  `InventoryGlPostingService.php:43-46` and the repair-command precedent, plus the `MovementReason` /
  `reference_type` it uses. Add the acceptance outcome "zero-net lot reattribution produces exactly one
  movement row and **zero** journal entries; product WAC unit cost is unchanged".

### MJ-4 — W5 asks for "consistent" locking without a lock-order census, and the POS lot consumer uses `SKIP LOCKED`, so a count finalize silently diverts concurrent sealed sales. Blocks plan. CONFIRMED (both lock strategies) / PLAUSIBLE (deadlock).

- Spec line 226: "Retain current aggregate cost/opening/replay rules, **locking the product cost basis
  and relevant lot/stock rows consistently**"; acceptance row L4-drift (spec:267) lists the scenario
  "sale/transfer during count" but states no required outcome about lock behaviour.
- Source:
  - Canonical order, documented:
    `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:87-95` —
    "Concurrency (canonical lock order): this row-locks each `stock_level` row … MUST be called INSIDE
    the per-product advisory lock and BEFORE the product row is locked, so the order stays
    advisory -> stock_level rows -> product row LAST."
  - Counting already takes lot locks: `Inventory/Domain/Services/StockAdjustmentService.php:1551-1570`
    (`drawLotsDownForCountShortage`, `->lockForUpdate()` over `inventory_batch_stock` joined to
    `product_batches`).
  - The POS sale consumer does NOT wait: `BatchExpiry/Domain/Services/FEFOInventoryService.php:264-272`
    — `FOR UPDATE OF ibs **SKIP LOCKED**`.
- Failure scenario: a count finalize holds `FOR UPDATE` on a product's earliest-expiry lot rows; a sealed
  POS sale projects concurrently, its FEFO query **skips** those rows, and it either draws a
  later-expiry lot (contradicting FEFO, and contradicting the very lot the R3 tile suggested to the
  cashier) or records a shortfall and leaves the tuple drifted
  (`PosCoreReceiptProjection.php:2073`) — while the operator's count reports "reconciled final stock".
  Separately, a new child-observation table plus lot rows locked in a different order than
  advisory → stock_level → product invites a deadlock against the WAC path.
- Minimum correction: W5 must require (a) a lock-order census of every writer that touches
  `inventory_batch_stock` + `stock_levels` + the product row, and adoption of one order compatible with
  `WeightedAverageCostService:87-95` — the same instruction the spec already gives W3 for GL (spec:163);
  (b) an explicit statement of the `SKIP LOCKED` interaction and a required outcome for the
  "sale during count" acceptance row (either the sale is serialized behind the count, or the count is
  provisional and the diverted allocation is a visible reconciliation exception — never a silent
  different lot); (c) a real PostgreSQL concurrency leg, not SQLite.

### MJ-5 — L6 schedules the drift census over a cohort selected by `requires_batch_tracking` with no module filter, so R2 turns module-off tenants into permanent daily false alerts. Blocks plan. CONFIRMED.

- Spec lines: 197 (R2: "A tenant without `BatchExpiry` sees no lot UI and **receives no new lot
  projection legs**"), 212 (L6: "Schedule existing census per **enabled** tenant/company with
  `--fail-on-drift` … Show coverage, last success and unresolved drift, not a false clean status").
- Source:
  - `apps/api/app/Modules/BatchExpiry/Application/Services/LotLedgerDriftCensus.php:81` —
    `->where('p.requires_batch_tracking', true)`; the census has **no** module-entitlement predicate
    (`grep -n "module|BatchExpiry" LotLedgerDriftCensus.php` returns only the namespace line).
  - `apps/api/app/Console/Commands/LotLedgerDriftCensusCommand.php:52-56` — flags are
    `--tenant/--all-tenants/--company/--fail-on-drift`; no module scoping. Confirmed **unscheduled**:
    no `inventory:lot-drift-census` occurrence in `apps/api/routes/console.php`.
  - `requires_batch_tracking` is a product flag independent of the module
    (`FEFOInventoryService.php:930-934`), and parapharmacy defaults every product to it
    (`ProductOpeningStockPhase.php:71-74`).
- Failure scenario: a tenant has batch-tracked products but no `BatchExpiry` entitlement. Under R2 its
  sales write no lot legs by design, so `Σ lots` falls permanently below `stock_levels`. The newly
  scheduled census flags every tuple with `--fail-on-drift` and pages daily; real drift on the entitled
  tenant is buried in the noise, or the alert gets muted and L6 delivers nothing.
- Minimum correction: L6 must define the cohort as **module-entitled company × batch-tracked product**,
  and require non-entitled tuples to be reported (if at all) as a distinct, non-alerting "not entitled"
  cohort. State the R2 corollary explicitly: revoking the module makes the ledger drift by design, so
  either `requires_batch_tracking` is not settable without entitlement or the drift is classified, never
  alerted. "Per enabled tenant/company" is ambiguous today and reads as "per active tenant".

### MJ-6 — The iteration-1 device lot cache is specified against the one device surface that stores quantities as numbers, and ignores the existing decimal-string availability surface. Blocks plan. CONFIRMED.

- Spec lines: 213 (L7: "F1 device product contract lacks lot state at `apps/pos/src/types/product.ts:50-119`"),
  240 ("Extend the existing product sync/cache with branch/product/variant lot eligibility: identifier,
  batch number where available, expiry …, **exact available quantity** and snapshot time/revision"),
  254 (seams: "`apps/pos/src/types/product.ts` plus server DTO generation/product sync/cache"; "Iteration 1
  may need an additive cache schema").
- Source:
  - The cited file is the **float/int** precedent: `apps/pos/src/types/product.ts:47,56` —
    `stock_quantity: number`; the local table column is `stock_quantity INTEGER NOT NULL DEFAULT 0`
    (`apps/pos/src/lib/db/migrations.ts:29`).
  - The correct precedent is a different, unnamed surface:
    `apps/pos/src/types/stockDistribution.ts:1-13` — "Numeric values are decimal strings at quantity
    scale 4"; `apps/pos/src/lib/stock/gridStock.ts:31-38` — "Decimal strings — never floats"
    (`LocationStockDisplay.available: string`), backed by
    `apps/pos/src/lib/db/repositories/locationStockRepository.ts` and rendered through
    `apps/pos/src/components/organisms/ProductGrid/useStockDisplay.ts:57-71` with `bccomp` /
    `formatAvailableQty`.
- Failure scenario: an implementer follows the file the spec names and adds
  `suggested_lot_available: number` / a `REAL` SQLite column. A 0.250 kg lot renders and compares as a
  float; the "quantity spans multiple lots" comparison the spec requires (spec:244) becomes a float
  comparison — rule-19 violation on a brand-new surface, on the vertical (parapharmacy) most likely to
  sell fractional units.
- Minimum correction: L7/W6 must name `lib/stock/gridStock.ts` + `locationStockRepository` +
  `types/stockDistribution.ts` as the surface being extended, require lot quantities as **decimal strings
  at quantity scale 4** end-to-end (SQLite TEXT), and forbid `Number()`/`parseFloat` on them. Replace
  "may need an additive cache schema" with a firm requirement: a new local migration is required (the
  device schema is columnar — see migrations v51/v62 precedents cited in `types/product.ts:105,118`).

### MJ-7 — The iteration-1 suggestion is defined as "recompute from the same snapshot", which ignores reservations and the device's own sales/cart; the existing stock gate already nets them. Blocks plan. CONFIRMED.

- Spec lines: 240 ("Derive the same deterministic FEFO suggestion for tile and cart **from the same cache
  snapshot**"), 244 ("Cache refresh, cart quantity/variant changes, date rollover and known recall changes
  recompute guidance **from the same snapshot**"), 246 ("Server FEFO remains the authoritative computed
  allocation … Offline sales/order/timing may make the allocation differ").
- Source:
  - Server FEFO consumes on availability **net of reservations**:
    `FEFOInventoryService.php:90` (`available_quantity > 0`) and `:268` in the `FOR UPDATE` query;
    `available_quantity` is `quantity − reserved_quantity` (`BatchStock.php:44-46`,
    and the generated column the census comments describe at `LotLedgerDriftCensus.php:32`).
    The spec's cache field is described only as "exact available quantity" — gross or net is unstated.
  - The device already has the correct pattern for "snapshot minus what this device has done":
    `apps/pos/src/lib/stock/gridStock.ts:6-9` — "Display-only — enforcement is Task 11's `stockGate`
    (which re-derives effective availability per add, **including pending receipts and the cart**). This
    map is the SERVER snapshot only: a tile can read '3 in stock' while the gate blocks the 4th add".
- Failure scenario: lot L1 holds 2 units. The cashier sells 2 (device does not decrement local stock —
  availability is a read-time calc). The next customer's tile still suggests L1, which is empty; the
  cashier pulls from a batch the server will never allocate, and the shelf/system divergence the feature
  exists to remove is created **by the feature**. Second scenario: a B2B sales order has reserved the
  earliest-expiry lot; the tile suggests it anyway because the cache carried gross quantity, and the
  server FEFO skips it.
- Minimum correction: state (a) the cached quantity is availability **net of server-side reservations**,
  named as such; (b) the suggestion must net this device's cart and its own completed-but-unsynced sales
  through the existing `stockGate`/availability path, or — if the owner accepts a purely static
  suggestion — the spec must say so and require the UI to label it as an unreconciled snapshot, since
  "recompute from the same snapshot" as written silently means "wrong after the first sale".

---

## MINOR

- **MN-1 (citation).** Spec:228 cites `StockAdjustmentService.php:65–75` as "the existing count-as-of and
  movement replay guards". Those lines are the `COUNT_REPLAY_REFERENCE` constant docblock (`:64-69`) and
  the head of the constructor; the actual collaborators are `MovementReplayService` (`:75`) and
  `CountingReplayGuardEvaluator` (`:78`), and `postCountCorrection()` lives at `:1390`. Repoint before the
  plan quotes it.
- **MN-2 (convention 09).** W5's seam list (spec:232) says only "additive tenant child table". It does not
  require `company_id` / `location_id` scoping or state the uniqueness key (expected:
  `(tenant_id, company_id, counting_item_id, batch_id)`). Given the tenant-only-unique ratchet history,
  state it in the spec rather than discovering it in review.
- **MN-3 (display precision).** Iteration 1 shows a lot and, per spec:244, whether it "does not cover the
  full line" — quantities surfaced to a human must render at the unit's `decimal_places`. The device
  already carries `quantity_decimals` (`apps/pos/src/types/product.ts:112-119`) and
  `lib/quantity formatQuantity`. Neither is named in W6.
- **MN-4 (ticket hygiene).** Spec:210 supersedes T22 in design. T22's acceptance item 4 ("a negative
  counting correction moves no lot at all") is **already closed** at HEAD by
  `StockAdjustmentService.php:1530-1594` (`drawLotsDownForCountShortage`, campaign W4-6). The spec's own
  L4 row cites that code correctly, but the later handback should record which T22 items were already
  satisfied so nothing is re-implemented or claimed as newly earned.
- **MN-5 (surface enumeration).** Besides the canonical tile/cart the spec names
  (`molecules/ProductCard/ProductCard.tsx`, `molecules/CartLineItem/CartLineItem.tsx`), the device still
  contains alternates that render stock: `apps/pos/src/components/pos/ProductCard.tsx:22-23`,
  `components/pos/ProductGrid.tsx:54`, `organisms/ProductGrid/ProductTable.tsx:66`,
  `molecules/BarcodeChooserModal/BarcodeChooserModal.tsx:151`. Spec:254 hedges with "alternate existing
  list/table renderers for consistent module hiding" — adequate at spec level; enumerate at plan level so
  module-off hiding is not partial.

---

## Answers to the gate questions

1. **Does W-LOT close every gap R1 lists, with an acceptance row per gap?** Yes for the seven handover
   bullets: F2 → L1 (spec:207, row spec:262), F7 lot-grain counting/T22 → L4 + W5 (spec:210, 222-228,
   rows 266-267), F8 → L2 (spec:208, row 264), census scheduling → L6 (spec:212, row 269),
   `BatchStockService` float accessor → L3 (spec:209, row 265), DEFAULT-lot inflation → L4, provenance
   labelling → L5 (spec:211, row 268); plus R2 (row 263) and R3 → L7 (spec:213, row 270). **One gap is
   softened** (L3, see MJ-2: two call sites, not the accessor) and **one launch-critical gap is absent**
   (BL-1). No gap lacks an acceptance row.
2. **Is T22/OQ-7 resolved by design or hidden?** Resolved, and honestly. W5 (spec:224-226) states the
   product decision the ticket demanded: actual lot observations drive gain and loss; the parent total is
   derived from accepted child observations; `Σ lots` is checked against the aggregate at finalization in
   absolute terms, not only as a delta; the 10+10 vs 15+5 case is spelled out; the pin at
   `InventoryCountingDefaultBatchTest.php:130-131` (verified at those exact lines) is correctly demoted to
   a limitation and required to be re-derived, not deleted. That satisfies T22 acceptance items 1–3.
   Item 5 (run on PostgreSQL) is covered by spec:343. What is missing is the *mechanics* — MJ-3 (movement
   shape / GL) and MJ-4 (locking) — and the operator path in BL-1.
3. **Is `system_fefo_estimate` applied to every allocation surface?** **No** — see MJ-1. Three producers
   exist; one is labelled.
4. **Second-of-everything?** Yes and specifically: spec:32 (second company through the real provisioning
   path, second POS-enabled location, repeated mutation with unchanged quantities/balances; two lots and
   two terminals as mandatory fixtures) and spec:258 (all applicable W-LOT rows need two companies, two
   locations, two real lots, re-run idempotency, two terminals for POS rows, cross-tenant denial fixtures,
   mandatory module-off/missing-config fixtures). Re-run outcomes appear in the rows themselves
   (spec:267 "repeat finalize has no extra movement/GL"). Only gap: MN-2.
5. **Any false claim about current code at HEAD (lot lens)?** **None found.** I opened every lot-lens
   citation: `BatchExpiry/Presentation/routes.php:12,14-48` (module middleware present; recall `:29` and
   destroy `:26` carry no permission, while `write-off-grouped :18-19` and `reverse-write-off :34-35` do);
   `RolesAndPermissionsSeeder.php:407` (seeds `batches.recall`) and `:632` (manager holds it);
   `CreateBatchRequest:22` / `UpdateBatchRequest:13` / `TransferBatchStockRequest:21` (existing checks —
   the spec's counter-evidence at spec:216 is correct); `UpdateBatchRequest:20-24`; `BatchController:165`;
   `BatchStockService:471,515-516` + `BatchStock:44-46`; `InventoryCountingItem:79-105` (fillable, no lot
   dimension); `InventoryCountingDefaultBatchTest:130-131`; `StockAdjustmentService:1551-1594`;
   `LateSyncResidualDetector:16-24`; `LotLedgerDriftCensusCommand:26-35,52` (safe-to-schedule docblock,
   `--fail-on-drift`) and its absence from `routes/console.php`; `PosCoreReceiptProjection:2026,2052-2081,
   2104-2114`; `FEFOInventoryService:930-934` and `:95` (tie-break now includes `created_at`);
   `BatchTraceabilityController:80-88`; `apps/pos/src/types/product.ts:50-119`; `productStore.ts:116` and
   its test `:5-30`; `ProductGrid.tsx:760`; `TransactionCart.tsx:282`; `FiscalEventEngine.ts:10-11`.
   All accurate as described.
6. **Is an estimate still presented as captured evidence anywhere?** Yes — MJ-1 (document lines and
   transfer allocations). The spec's own POS-side handling is honest, including the refusal to backfill
   `operator_captured` from estimated allocations (spec:211) and the "shelf suggestion is guidance, a
   server allocation is an estimate" sentence (spec:220), which are among the strongest parts of v2.
7. **Does R3 iteration 1 need data the device cannot have, and is that stated?** It needs branch lot
   eligibility that the device demonstrably does not hold today (`types/product.ts:50-119` has no lot
   field; the only lot endpoint, `/api/v1/pos/products/{productId}/batches`, sits at
   `BatchExpiry/Presentation/routes.php:48` behind `module:BatchExpiry` and has no device consumer). The
   spec **does** state the cache extension, the module gate and the D4 freshness policy — that part is
   sound. What it does not state is the **precision contract** for the new fields (MJ-6) and the fact that
   a static snapshot is stale from the first sale and ignores reservations (MJ-7).

---

## Rejected false positives — preserve these in v3

- **"R2 is already enforced in the projection."** Not claimed by the spec, and the spec's qualification is
  correct: `PosCoreReceiptProjection.php:2026` calls `FEFOInventoryService::productRequiresBatchTracking()`
  at `:930-934`, which reads only `products.requires_batch_tracking` — no module resolution. Spec:199
  states exactly this and refuses to claim entitlement enforcement. **Preserve verbatim.**
- **"The DEFAULT top-up pin must be honoured."** No. T22's own ruling (ticket §"The ruling", reason 1)
  says the invariant needs its own spec and is a product decision. The spec's demotion of
  `InventoryCountingDefaultBatchTest:130-131` to a limitation (spec:210) is correct.
- **"The counting path moves no lot on a negative correction."** Stale (T22 item 4). The spec does not
  repeat it; it cites the live heuristic at `StockAdjustmentService.php:1551-1594`. Correct.
- **"F7 residual (f), FEFO tie-breaking, is still open."** The spec's note at spec:218 is right:
  `FEFOInventoryService.php:95` now orders by
  `(expiry_date IS NULL) ASC, expiry_date ASC, created_at ASC`, matching `consumeBatchesAtomically`
  (`:271`). Do not let a later round re-open it.
- **"W-LOT should also fix the batch CRUD gating."** No: create/update/transfer/write-off are gated in
  FormRequests (`CreateBatchRequest:22`, `UpdateBatchRequest:13`, `TransferBatchStockRequest:21`) and
  `write-off-grouped`/`reverse-write-off` carry `can:batches.write-off` middleware. The spec's narrowing
  to recall/destroy/reads (spec:207,216) is correct and should not be widened.
- **Sound parts worth keeping unchanged:** the ordered-gap table's discipline of re-anchoring every claim
  at the snapshot instead of reusing ticket line numbers (spec:203); W5's "missing row is not zero"
  distinction and the absolute `Σ lots` check at finalization (spec:224,226); the reuse of
  `LateSyncResidualDetector` rather than a second detector (spec:228); the refusal to promote a device
  suggestion into `operator_captured` (spec:246); the W-LOT handback rule that iteration-1 completion is
  never reported as captured-lot completion (spec:195,273).

---

## What to fix before merge

Add the missing DEFAULT-lot identification/split gap (BL-1); extend `system_fefo_estimate` and the
glossary **Lot evidence** row to the document-line and stock-transfer allocation surfaces (MJ-1); widen
L3 to retire the float availability accessor itself (MJ-2); and pin the four unstated mechanics — flat
zero-GL movement shape for lot reattribution (MJ-3), lock order plus the `SKIP LOCKED` interaction with a
PostgreSQL leg (MJ-4), module-entitled census cohort (MJ-5), decimal-string device lot cache netted of
reservations and the device's own cart/sales (MJ-6, MJ-7).

VERDICT: CHANGES-REQUIRED
