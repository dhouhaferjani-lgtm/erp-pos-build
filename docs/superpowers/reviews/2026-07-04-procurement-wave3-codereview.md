# Wave 3 (Goods-Receipt Ledger Foundation, Tasks 1–6) — Adversarial Code Review

**Date:** 2026-07-04
**Scope:** Uncommitted working-tree diff in `apps/erp.procurement-v2` (branch `feat/procurement-completeness`), implementing Wave 3 of `docs/superpowers/plans/2026-07-03-procurement-completeness-wave3-6-receipt-ledger-plan.md` against spec Gap 2 §2.1–§2.3.2 of `docs/superpowers/specs/2026-07-03-procurement-completeness-design.md`.
**Reviewer:** Claude (adversarial pass; all findings verified in code / by running gates)

## Verdict: **NEEDS-REVISION**

The live write path (Tasks 1–4) is solid: schema is §2.3-exact, header+lines are written inside the existing transaction/lock structure, PO counters remain byte-identical derived duplicates, movement links are captured from the correct `recordPurchase` calls with free-first/paid-last order preserved, and every call site of the return-type break is handled. Tests 16/16 new + 28/28 key regressions re-verified green, pint pass.

But the **backfill command (Task 5) is a production no-op**: its eligibility filter requires `stock_movements.reason = 'goods_receipt'`, a value **no code path has ever written** — the test only passes because it fabricates movements with that reason. Additionally the backfill can mint duplicate empty GRN headers on every re-run for unmappable movements, PHPStan level 8 fails with 12 errors on the command (the plan's Task-6 sweep scope masked it), and the `GoodsReceiptResult` DTO carries `mixed` magic forwarders that hide the API break.

---

## Findings

### W3-1 — CRITICAL — Backfill matches ZERO real historical movements (reason filter on a never-written value)

`apps/api/app/Console/Commands/BackfillGoodsReceiptsCommand.php:104` filters source rows with:

```php
->where('reason', MovementReason::GoodsReceipt)
```

but `stock_movements.reason` is **nullable** (`2025_12_24_133827_extend_stock_movements_table.php:16`) and `WeightedAverageCostService::recordPurchase()` — the only writer of goods-receipt movements, historical and current — **never sets `reason`** (`WeightedAverageCostService.php:258-278`: the `StockMovement::create` block has no `reason` key). `rg "MovementReason::GoodsReceipt|'goods_receipt'"` over `app/` confirms nothing else writes it either. Every real goods-receipt movement in every tenant DB has `reason = NULL`, so in production the command prints `Eligible movements: 0` and synthesizes nothing — the §2.3.2 exit criterion ("backfill reproduces historical single-basis behavior") is unmet and the Wave-5 matcher would silently run on the aggregate fallback for ALL history.

The green test masks this: `GoodsReceiptBackfillTest.php:253` hand-crafts fixtures with `'reason' => MovementReason::GoodsReceipt` — a field value the real write path never produced. The plan (Task 5) said fixtures should mirror "the pre-Task-3 write path"; these don't.

**Fix:** drop the `reason` predicate and discriminate the way the spec §2.3.2 defines: `movement_type = Receipt` + `reference_type = 'Document'` + `reference_id` resolving to a `type = purchase_order` document (resolve the PO **before** creating any header — see W3-2; note `recordReturn` also writes `MovementType::Receipt` movements referencing non-PO documents, `WeightedAverageCostService.php:556-573`, so the PO-type check is the real gate). Change the test fixtures to `reason => null` so they match reality, and add a fixture asserting a `recordReturn`-shaped movement (Receipt type, non-PO reference) is NOT swept.

### W3-2 — MAJOR — Backfill creates a new empty GRN header on EVERY run for unmappable groups (idempotency broken)

`BackfillGoodsReceiptsCommand.php:144-157`: the `goods_receipts` header is created (and a GRN number drawn) **before** line resolution, and `[1, $linesCreated]` is returned even when `$linesCreated === 0`. If every movement in a group fails `resolvePoLine()` (`:207-223` — product/variant no longer matches any PO line, e.g. deleted line, variant drift), the run commits an **empty header**, the movements remain unclaimed (no line carries their id), and the **next run synthesizes another empty header for the same group** — unbounded junk headers, each consuming a GRN number and inflating "Created receipts". The "re-run is a no-op" test (`GoodsReceiptBackfillTest.php:107-111`) only covers fully-claimed groups.

**Fix:** resolve lines first (or dry-resolve), and only create the header if at least one line will be written; log-and-skip groups that resolve to zero lines. Return `[0, 0]` for skipped groups.

### W3-3 — MAJOR — PHPStan level 8 FAILS: 12 errors in the backfill command (sweep scope masked it)

`vendor/bin/phpstan analyse app/Console/Commands/BackfillGoodsReceiptsCommand.php …` → **12 errors**: `argument.type` (bcmath `numeric-string` vs `string`) at `:176, :180, :263, :264, :267, :291, :293, :297` and `parameterByRef.type` at `:233` (`shiftMatchingFreeMovement` re-indexes the by-ref list into `array<int<0,max>,…>`). `phpstan.neon` analyzes all of `app/` and none of these are baselined, so **preflight/CI will fail**. The task log's Task-6 command (`analyse app/Modules/Inventory app/Modules/Document`) followed the plan's too-narrow scope and never analyzed `app/Console/Commands`.

**Fix:** add `@var numeric-string` narrowing (or `CurrencyScale::bcformatStrict` at ingest) for the movement/line decimal reads, and `@param-out list<StockMovement>` on `shiftMatchingFreeMovement`. Re-run PHPStan on `app/Console/Commands` as part of the Wave-3 exit.

### W3-4 — MAJOR — Backfill idempotency is read-then-write only; no DB-level claim key (concurrent double-run duplicates ledger rows)

The idempotency "key on movement_id" (§2.3.2) is implemented purely as a pre-read (`BackfillGoodsReceiptsCommand.php:86-111`: pluck claimed ids, `whereNotIn`). Two concurrent invocations (two shells, scheduler overlap during a fleet rollout) both see the same unclaimed movements and both insert — **duplicate `goods_receipt_lines` double-counting received quantities in the ledger** that Wave 5's matcher will consume. The migration has **no unique index on `movement_id` / `free_movement_id`** (`2026_07_04_100000_create_goods_receipts_tables.php:29-54`), so nothing at the DB layer stops it.

**Fix:** add partial unique indexes `goods_receipt_lines (movement_id) WHERE movement_id IS NOT NULL` and `(free_movement_id) WHERE free_movement_id IS NOT NULL` in the migration; the second runner's insert then fails its group transaction and rolls back cleanly. (Also belt-and-braces protection for any future live-path bug.)

### W3-5 — MAJOR (type-safety/design) — `GoodsReceiptResult` magic `__get`/`__call` forwarders hide the API break behind `mixed`

`apps/api/app/Modules/Inventory/Application/DTOs/GoodsReceiptResult.php:17-28`: the "small readonly DTO {purchaseOrder, receipt}" the plan specified (Task 3) grew `__get(string): mixed` and `__call(...): mixed` forwarding to the purchase order. Consequences, all live today:

- **Ambiguity on the worst possible field:** `$result->status` returns the PO's `DocumentStatus` while `$result->receipt->status` is `GoodsReceiptStatus` — on an object named *GoodsReceiptResult*. `GoodsReceiptTest.php:325,333` and `GoodsReceiptServiceVariantTest.php:246,311,379` (`$result->status`, `$result->lines`, `$result->payload[...]`) now pass **through the shim**, contradicting the plan's "fix call sites, not tests' semantics" — only `GoodsReceiptTest` had 7 call sites updated; `GoodsReceiptServiceVariantTest`, `BatchChainE2ETest`, `GoodsReceiptGlTest`, `LinkedCostExpenseTest`, `SupplierInvoiceApiTest` were left leaning on it.
- `mixed` returns violate the strict-typing rule (repo rule 3) and neuter PHPStan property checking on every access through the result.
- It masks missed call sites in FUTURE code: property access on the result silently "works" against the wrong object.

**Fix:** delete both magic methods; update the remaining test call sites to `->purchaseOrder` explicitly (mechanical, ~15 sites).

### W3-6 — MINOR — `cascadeOnDelete` on `goods_receipt_lines.goods_receipt_id` deviates from §2.3

`2026_07_04_100000_create_goods_receipts_tables.php:33` uses `->constrained('goods_receipts')->cascadeOnDelete()`; spec §2.3 (:278) specifies a plain `REFERENCES goods_receipts(id)`. On a costing **ledger**, cascade means deleting a header silently destroys lines carrying `movement_id` claims and `quantity_invoiced` state (which would also *unclaim* movements for the backfill). No code path deletes headers today, but the default for accounting-grain rows should be restrictive. **Fix:** `restrictOnDelete()` (or drop the modifier for the DB default NO ACTION).

### W3-7 — MINOR — Backfill batch grouping requires exact per-second `created_at` equality

`BackfillGoodsReceiptsCommand.php:126` groups by `reference_id | created_at->toIso8601String()`. Laravel stamps `created_at` **per model save** (`freshTimestamp()` per row), so a multi-line `receiveGoods` transaction that straddles a second boundary fragments into two synthesized receipts; conversely two distinct receipts on the same PO within the same second merge, which can pair a `free_movement_id` with a paid movement from the *other* receipt (`shiftMatchingFreeMovement :228-241` matches on product/variant only). Clearing behavior is preserved (basis is per-PO-line and `$invoicedRemaining` spans groups), so impact is header-attribution quality, not money. Untested either way. **Fix:** group with a small tolerance window (e.g. bucket movements whose timestamps are within a few seconds per PO) or document the per-second grouping as an accepted limitation in the command docblock + a test pinning the fragmentation behavior.

### W3-8 — MINOR — Zero-cost PAID movements are classified as free lines in the backfill

`BackfillGoodsReceiptsCommand.php:174-181` splits paid/free by `unit_cost > 0`. A historical PO line legitimately priced at 0 (gratis line received as *paid* quantity — `recordPurchase` was called with `landedUnitCost = '0'` from `unit_price = 0`) produces a movement indistinguishable from a bonus movement → synthesized as `received_qty = 0 / free_qty = qty`, so `Σ received_qty` diverges from the PO's `quantity_received` for that line (breaks the §2.3.1 invariant for that PO). Spec §2.3.2 endorsed the zero-cost heuristic, so this is inherent — but it should be bounded: **Fix:** after synthesis, cross-check `Σ received_qty` vs `quantity_received` per PO line and log a reconciliation warning when they diverge (gives ops a list instead of silence).

### W3-9 — MINOR — Conversion path drops the actor (`received_by = NULL`)

`PurchaseOrderToGoodsReceiptConverter.php:136,139` calls `receiveGoods`/`receiveAll` without an `actorId`, so receipts created via the document-conversion API record `received_by = NULL` while the direct `receive` endpoint stamps `$user->id` (`PurchaseOrderController.php:691,695`). M10's audit intent (spec §2.7) leaks on this path. Plan Task 3 only required the controller, so this is a gap, not a contract breach. **Fix:** accept an actor in the converter `$options` and thread it (or route the converter's caller through the same request user).

### W3-10 — NIT — `effectiveUnitCost` input truncates while stored landed/accrual round

`GoodsReceiptService.php:329-333` stores `landed_unit_cost`/`accrual_unit_cost` via `CurrencyScale::bcround(..., 6)` but feeds `effectiveUnitCost()` the `bcformatStrict(...)` (truncating) rendering of the same value. Today both inputs come from ≤6-dp DB columns so the paths agree, but Wave 4 will pass a **computed** working-scale landed cost, at which point a paid-only receipt could store `effective != landed` by one ulp when the 7th digit ≥ 5 (violating §2.3's "paid-only ⇒ effective = landed"). **Fix now (one line):** pass the same `bcround`ed value to `effectiveUnitCost()` that is stored.

### W3-11 — NIT — Duplicate-product "FIFO fallback" is always-first-line; unmatched movements inflate counts forever

`BackfillGoodsReceiptsCommand.php:207-223`: with duplicate product PO lines every movement maps to the FIRST matching line regardless of its remaining capacity (not a capacity-aware FIFO fill), and the warning fires once per movement. Also movements whose `reference_id` is not a PO (or which never resolve a line) stay eligible forever, so `--dry-run`/"Eligible movements" over-report on every run. Behavior matches the letter of the test and owner resolution A2 (warn + fallback), so NIT — but note the first PO line silently absorbs quantities that may exceed its ordered qty.

### W3-12 — OBSERVATION (sound choice, document it) — GRN drawn INSIDE the receive transaction

`GoodsReceiptService.php:102-116` draws the GRN via `generateForKey` inside the outer `DB::transaction` + `ProductCostLock` callback; the nested `DB::transaction` in `DocumentNumberingService::generateForKeyOnce` (`:43-63`) becomes a savepoint, so the `document_sequences` row lock is held until the **outer** commit. Consequences: **no GRN gaps on rollback** (verified: rollback test + `GRN-…-0002` on the second receipt), at the cost of serializing concurrent receipts per company on the sequence row for the full receipt duration (WAC + batch work included). Lock order is consistent everywhere (products → sequence; only `receiveGoods` touches the `goods_receipt` sequence row) so no deadlock cycle exists. The first-sequence create race is handled by a single retry on unique violation (`:30-41`, backed by the real `UNIQUE (company_id, type, year)` from `2025_11_30_140002:27`), and savepoint rollback makes the retry valid on Postgres. `generateNumber()` now delegates (`:19-22`) with identical format/lock/increment semantics; the added retry is a strict improvement. `isUniqueViolation` accepting SQLSTATE `23000` (generic, MySQL-style) alongside `23505` is broader than ideal but harmless — a non-unique integrity error rethrows unchanged on the retry.

### W3-13 — OBSERVATION — Schema fidelity is asserted on SQLite; PG shape only via migration string-match

`GoodsReceiptLedgerSchemaTest.php:126-151` uses SQLite `PRAGMA` (the suite runs `DB_CONNECTION=sqlite`, `phpunit.xml:41`), so decimal precision/scale and `jsonb`/`timestamptz` are only pinned by string-matching the migration source (`:82-91`). Acceptable for this codebase's test reality; noted so nobody mistakes it for a live-PG schema check.

---

## Attack-surface verification detail (what was checked and PASSED)

1. **Schema fidelity (§2.3):** every column/type/scale/nullability/default matches — `goods_receipts` (`migration :13-27`): `purchase_order_id` **NOT NULL** per owner S5, `receipt_number varchar(30)`, `status varchar(20)`, `received_at timestamptz NOT NULL`, `received_by/notes/payload` nullable, `UNIQUE (tenant_id, receipt_number)`; `goods_receipt_lines` (`:29-54`): qty `decimal(15,4)` (free/invoiced `DEFAULT 0`), `received_unit_price decimal(15,3) NULL`, `landed/effective decimal(19,6)`, `accrual decimal(15,6)`, all four `price_override_*` audit columns, both indexes. `down()` drops lines before headers (`:57-61`). Casts complete on both models (`GoodsReceiptLine.php:83-96`: qty `decimal:4`, price `decimal:3`, costs `decimal:6`; `GoodsReceipt.php:62-69`: status enum, payload array, received_at datetime). Deviations: only W3-6 (cascade) and the missing movement-claim uniques (W3-4).
2. **Transaction integrity:** header+lines created inside the EXISTING `DB::transaction` → `costLock->withLocks` callback (`GoodsReceiptService.php:70,97-117`); locking not restructured. All `DomainException` throws occur inside the transaction (or before the header exists); rollback verified (`GoodsReceiptLedgerWriteTest.php:216-231`, asserts zero headers AND zero lines). `receiveAll` writes a header (`:202-213`). `movement_id` captured from the paid `recordPurchase` (`:290`), `free_movement_id` from the free one (`:251`); free block (`:228`) still precedes paid block (`:266`) so `last_purchase_cost` = paid price is preserved.
3. **Derived-counter invariant (§2.3.1):** PO-line writes are byte-identical to before — `free_quantity_received :263`, legacy `accrual_unit_cost` first-paid-receipt snapshot `:305-307`, `quantity_received :309` (only literal `4` → `self::QUANTITY_SCALE`). Invariant helper exists as `assertLedgerCountersMatchPo` (`GoodsReceiptLedgerWriteTest.php:315-327`) and is exercised after single, partial-multi, and free-only receipts. It is test-local to one class (fine for Wave 3) and doesn't cover `quantity_invoiced` (always 0 until Wave 5 — acceptable).
4. **Numbering:** see W3-12 — core shared, per-company isolation + PO-sequence isolation + existing-prefix regression all tested (`GoodsReceiptNumberingTest.php`); `strlen('goods_receipt')=13 ≤ 20` asserted (`GoodsReceiptLedgerSchemaTest.php:23`). The first-sequence race retry is implemented but untestable on SQLite (accepted).
5. **Backfill:** W3-1/2/3/4/7/8/11 above. Verified good: FIFO invoiced apportionment math is bcmath at scale 4 with per-PO-line remaining carried across groups (`:258-268`, test asserts 7 → 4+3); free-movement pairing by product/variant (`:228-241`, tested); `--dry-run` writes nothing (`:67`, tested); `--company` scoping tested; effective-cost recompute correct (`4.285714` asserted); no scale-resolver/CompanyContext dependency (fixed COST_SCALE 6 / qty 4 — correct for a currency-independent cost ledger); fleet iteration mirrors `BackfillFiscalYears` (Company iteration + `@cross-tenant-by-design`).
6. **Return-type break:** `rg "receiveGoods\(|receiveAll\("` — all non-test call sites handled: controller (`PurchaseOrderController.php:686-706`, uses `->purchaseOrder` for status/data and `->receipt` for meta), converter (`:136,139` — docblock contract intact: still creates NO new *document*; the ledger header is a non-document table per spec §0), `DemoPharmacySeeder.php:933,942` (return values unused — safe). `meta.goods_receipt` emits the full header DTO (`withLines: false`) — superset of the plan's `{id, receipt_number}`, endpoint-tested. Actor threaded on both controller paths (`$user->id`). Residual risk is W3-5's shim.
7. **Precision:** all new math is bcmath-on-strings with one boundary `bcround` to scale 6 (`GoodsReceiptService.php:329-333, :404-417`); no float casts on decimal properties anywhere in the new code (array paths in the backfill included — they're `(string)` casts, flagged only as PHPStan numeric-string narrowing, W3-3). W3-10 is the one latent mix.
8. **Out-of-scope edits:** `StockReservationService.php` (two `bcadd` literals → `QUANTITY_SCALE` const) and `InventoryServiceProvider.php` (import order) are behavior-identical, forced by the Wave-3 phpstan/pint gates on `app/Modules/Inventory`, and honestly declared in the task log. Nothing else snuck in.
9. **generated.d.ts:** the +38-line hunk is transform-shaped (namespace placement, `Array<any>` for the jsonb payload matching existing emissions, alphabetical enum insertion); task log records the `typescript:transform` run. No hand-edits detected.
10. **Plan coverage:** every test contract in Tasks 1–5 is present (schema/types/enum/strlen; GRN sequential/isolated/per-company/regression; header+lines/partial/free-only/invariant/receiveAll/rollback/meta; DTO strings round-trip + omit-lines; backfill two-partial/FIFO-invoiced/free-pairing/re-run-noop/company-scope/duplicate-warning). Nothing silently skipped. Task 6's sweep ran the full regression path list (137/827 green — independently re-verified for the two highest-risk suites) but its PHPStan scope followed the plan and missed `app/Console/Commands` (W3-3).

## Required before merge

1. W3-1: fix the eligibility filter + realistic (reason-NULL) fixtures + a recordReturn-exclusion test.
2. W3-2: no header creation for zero-line groups.
3. W3-3: PHPStan clean on `app/Console/Commands` (and add it to the Wave-3 exit sweep).
4. W3-4: partial unique indexes on `movement_id`/`free_movement_id`.
5. W3-5: delete the magic forwarders; update remaining test call sites.

W3-6..W3-11 are strongly recommended in the same fix round (W3-6 and W3-10 are one-liners).
