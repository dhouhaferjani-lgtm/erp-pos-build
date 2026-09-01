# Wave-2 PO scenario spec — adversarial gate r2 (lens: stock↔GL seam)

**Reviewer:** `stock-gl-interaction-reviewer` (Opus) · **Date:** 2026-09-01 · **Worktree read:** `.worktrees/L-po-flow` (dev `62964e5cc`)
**Under review:** `02-scenario-matrix.md` rev 2 (151 scenarios / 21 classes) + `01-research.md` rev 2 (F-W2-01..36), answering `…-gate-r1-stock-gl.md` (G1-S1-01..17) and `…-gate-r1-costing.md`.
**Method:** every disposition below was checked by opening the cited file at the cited line in this worktree. I re-derived ten money figures from source, including eight I did not check in r1. No spec citation was accepted on its own authority — including my predecessor's.

## VERDICT: ACCEPT-WITH-CONDITIONS

r2 is a substantially better document than r1. All four r1 BLOCKERs are genuinely closed, not reworded: the batch tables are the real ones, W2-HP-5 has its own executable query joined through `goods_receipt_lines.movement_id`, W2-WDIL-4 is a direct orphan query with the reason `check-cogs-coverage` cannot serve, and W2-VAT-4 is a positive assertion whose mechanism I re-derived end to end and found **correct**. The author also caught two of *my predecessor's* errors and is right about both (see the disposition audit).

What remains is a set of ten one-line edits. Six of them are seam-relevant: the two GR-IR queries can pass on an entry that was created but never posted; the universal orphan detector was narrowed so it can no longer see a receipt line with no movement; the FEFO leg does not pin the three fixture facts that decide whether FEFO runs at all; and the run's only stock **exit** has its GL explicitly unasserted while the wave's own P0 is "stock moved, GL swallowed". None of these is a BLOCKER — each is a predicate, a sentence or a figure the orchestrator can paste without re-gating.

---

## Findings

### G2-S1-01 [MAJOR] — both GR-IR queries pass on an entry that was created but never posted

**Rows:** `W2-HP-5` (the SQL block after it) and `W2-WDIL-4` part (a).

**Code.** `GeneralLedgerService::createGoodsReceiptGrIrEntry` creates the entry with `'status' => JournalEntryStatus::Draft` **inside** an inner `DB::transaction` that commits (`GeneralLedgerService.php:2093-2103`, returned at `:2127`), and only then calls `postSystemGeneratedEntryAndDispatchPostedEventAfterCommit($entry, …)` **outside** that transaction (`:2134`). If the post step throws — a closed period, a hash-chain failure, an account resolution problem — the Draft row is already committed and `PostGrIrOnGoodsReceipt::handle` catches `\Throwable` and only logs (`PostGrIrOnGoodsReceipt.php:41-52`, and the PO path never passes `failClosedGrir`: `GoodsReceiptService.php:203` defaults `false`).

Neither query filters `je.status`. `JournalEntryStatus::Posted = 'posted'` (`JournalEntryStatus.php:10`). A receipt whose GR-IR entry exists as a **Draft** therefore returns 0 orphan rows and 3 grouped rows — the detector reports green while nothing is in the ledger. That is the precise failure mode F-W2-03 was re-graded P0 for.

**Other side of the seam, verified:** the stock side is unaffected — `recordPurchase` writes the movement, the `stock_levels` delta and `products.cost_price` inside one transaction (`WeightedAverageCostService.php:263-296`) before the GL listener ever runs.

**Fix (one line, twice):** add `AND je.status = 'posted'` to the `LEFT JOIN` predicate in W2-WDIL-4 part (a) and to the `JOIN journal_entries` in the W2-HP-5 block.

---

### G2-S1-02 [MAJOR] — W2-WDIL-4's new `movement_id IS NOT NULL` guard blinds the universal detector to "received, never moved"

**Row:** `W2-WDIL-4` part (a): `… AND l.received_qty > 0 AND l.movement_id IS NOT NULL AND je.id IS NULL`.

r1's fix wording carried no `IS NOT NULL` guard. Adding it means the one query that runs after **every** receipt can no longer see a posted receipt line that claims `received_qty > 0` and has **no stock movement at all** — which is exactly the shape r2 itself discovered and registered as F-W2-34 (`GoodsReceiptService.php:153-155` writes the draft line with `'movement_id' => null` at `:181` and has no `isPhysical()` filter, while `processReceiptLines` has both `:503-505` and `:519-521`), and also the shape a silent `recordPurchase` failure would leave. `goods_receipt_lines.movement_id` is `uuid` nullable (`2026_07_04_100000_create_goods_receipts_tables.php:48`).

**Fix (one added arm):** keep part (a) as written and add part (c):
```sql
-- (c) a posted line that claims stock but has no movement: only the known
--     P-UNDER-4 line of W2-UNDER-4 may appear here; any other row is a finding
SELECT l.id, l.product_id, l.received_qty
FROM goods_receipt_lines l JOIN goods_receipts r ON r.id = l.goods_receipt_id
WHERE r.company_id = :company AND r.status = 'posted'
  AND l.received_qty > 0 AND l.movement_id IS NULL;
```

---

### G2-S1-03 [MAJOR] — `allocated_costs` is stated at the wrong scale, and the justification cites a docblock instead of the assertion beneath it

**Rows:** `W2-HP-2` (`allocated_costs = 0.000`), `W2-LAND-1` (`20.000 / 10.000`, with the parenthetical "persisted at the **currency scale (3)**, not 6"), `W2-LAND-4` (`30.000`), `W2-LAND-8` (`30.000`).

**Code.** The column is `decimal(19,6)`: `2026_05_30_000000_widen_wac_cost_columns_to_scale_6.php:60-63` (`'document_lines' => [['allocated_costs', 19, 6], ['landed_unit_cost', 19, 6]]`, applied by the `ALTER COLUMN … TYPE` at `:79-82`), and the Eloquent cast is `'allocated_costs' => 'decimal:6'` (`DocumentLine.php:156`). Postgres pads: the migration's own docblock says so at `:30`.

The pin the author cites for "20.000 / 10.000" is `LandedCostBcmathTest.php:298-300` — that is the **docblock** of the test. The assertions are at `:330-331`:
```php
$this->assertSame('20.000000', (string) $reloadedA->allocated_costs);
$this->assertSame('10.000000', (string) $reloadedB->allocated_costs);
```
So the pinned truth is the opposite of what r2 records. The *value* is right (20.000 == 20.000000); the *representation* read back from Postgres or the API is 6 dp. Under the matrix's own tolerance ("6-dp costs compare as exact strings read from Postgres"), a literal comparison fails on correct code and the tester logs a phantom defect in the highest-value money class. `normalizeMoney` (`apps/web/e2e/campaign/journey.ts:183-195`) would absorb it, but only if the runner is told to use `assertMoneyEqual` here.

**Fix:** restate the four figures as `0.000000` / `20.000000` / `10.000000` / `30.000000`, cite `2026_05_30_000000_…:60-63` + `DocumentLine.php:156` + `LandedCostBcmathTest.php:330-331`, and say they are compared with `assertMoneyEqual`, never string equality. (`landed_unit_cost` at 6 dp was already correct throughout.)

---

### G2-S1-04 [MAJOR] — the FEFO leg does not pin the three fixture facts that decide whether FEFO runs at all

**Rows:** `W2-LOT-9`, `W2-LOT-10`, and `01-research §6.7`. The action is stated as "`POST /delivery-notes` then `POST /delivery-notes/{id}/confirm`" with no header or line shape.

**Code.** `DeliveryNoteService::issueStock` (`:297`):
- `:309-313` — `$location = $line->location ?? $deliveryNote->location;` and `if ($location === null) { continue; }`. `location_id` is **nullable** on the create request (`CreateDocumentRequest.php:93-99`). Omit it and the line is skipped: no `recordSale`, no lot draw, no `stock_levels` change — the row fails for a fixture reason that reads like a FEFO defect.
- `:380-388` — FEFO is the **`elseif`**: `if ($line->batch_id !== null) { issueBatchStock(…) } elseif ($product->requires_batch_tracking) { consumeBatchesAtomically(…) }`. A line carrying any `batch_id` tests the named-lot path and proves nothing about selection.
- `partner_id` is `required` on the same request (`CreateDocumentRequest.php:66-70`) with no customer-type filter, so `SUP-A` is usable — but the spec never names a partner.

**Fix (one precondition line on W2-LOT-9):** "delivery note header `location_id = MAIN`, `partner_id = SUP-A`; the line carries a `product_id` and **no `batch_id`** — FEFO is the `elseif` branch at `DeliveryNoteService.php:388`."

---

### G2-S1-05 [MAJOR] — W2-LOT-6's exclusion arm and W2-LOT-10 leave undetermined an outcome the code determines

`W2-LOT-6`: "Prove the exclusion by running the LOT-9 driver against this product and asserting **it draws nothing**." `W2-LOT-10`: "**refusal**".

**Code.** Strict fulfilment throws `InsufficientBatchStockException` (`FEFOInventoryService.php:341`), which `extends \DomainException` (`app/Modules/BatchExpiry/Domain/Exceptions/InsufficientBatchStockException.php:17`). `DeliveryNoteController::confirm` catches `\DomainException` at `:595` → 422 (the `InsufficientStockForFulfilmentException` arm at `:581-594` is a different class). So the observable is **422, never a 5xx and never a silent zero draw**. And because `recordSale` has already decremented the aggregate for the line before the draw (`DeliveryNoteService.php:316-323`; the atomicity contract is commented at `:376-379`), the whole confirm rolls back — `stock_levels` must read unchanged afterwards.

This is exactly the r1 G1-S1-14 principle the author accepted for W2-LOT-8. A row that accepts either outcome cannot fail.

**Fix:** W2-LOT-6 → "422; zero lots drawn; `stock_levels` unchanged (the confirm rolls back)". W2-LOT-10 → "422; no negative `inventory_batch_stock.quantity`; `stock_levels` still `5.0000`".

---

### G2-S1-06 [MAJOR] — the run's only stock EXIT has its GL consequence explicitly unasserted

`01-research §6.7` scope note: "The delivery note's own COGS movement, GL entries and document lifecycle are **recorded, not asserted**."

**Code.** The FEFO driver is not GL-free. `issueStock` calls `recordSale` (`DeliveryNoteService.php:316-323`), which writes an `Issue` movement with `reason = MovementReason::Delivery` and `quantity` **negative** (`WeightedAverageCostService.php:470-489`) — I checked the sign specifically, because W2-WDIL-1(a) asserts `stock_levels` delta == Σ `stock_movements.quantity`; it holds. It then enqueues a `MovementGlContext` on the `InventoryGlPostingBuffer` (`DeliveryNoteService.php:399-401`), i.e. a COGS booking.

So wave 2 moves ~52.500 TND of stock out with a value consequence and declines to look at it — in a wave whose P0 (F-W2-03) is precisely "stock moved, GL swallowed". Declining to assert the delivery note's *lifecycle* is right; declining to assert that a stock exit produced **any** journal entry is the one exception the seam cannot afford, and it costs one query.

**Fix (one line on W2-LOT-9's Expected):** "record the `journal_entries` rows the confirm produces and assert at least one COGS entry exists for the issue movement; its amount and lifecycle are recorded, not asserted."

---

### G2-S1-07 [MINOR] — W2-LAND-7 keeps a two-outcome hedge that the code decides

"**If the post instead refuses on the accrual assertion, that refusal is the finding.**"

There is no such assertion. With receipt lines present, `SupplierInvoicePostingService::post` takes the `consumeReceiptLines` branch (`:222-231`), and that method refuses on exactly two conditions: a NULL `accrual_unit_cost` on a receipt line (`:488-495`) and an over-clear (`:505-514`). PO-E has neither — both receipt lines carry `accrual_unit_cost` (written at `GoodsReceiptService.php:695`, `10.000000` and `13.000000`) and 10 of 10 received units are invoiced once. The PO-line comment at `GoodsReceiptService.php:663-665` describes the basis, not a guard. The post is deterministic.

**Fix:** delete the hedge sentence; keep the derived figures (I re-derived all of them — see below).

---

### G2-S1-08 [MINOR] — three assertions that cannot fail because the database enforces them

- W2-WDIL-1(c) "every `movement_id` … **tenant-distinct**" — enforced by the partial unique index `goods_receipt_lines_movement_id_unique` (`2026_07_04_100000_create_goods_receipts_tables.php:61-65`, twin for `free_movement_id` at `:66-70`).
- W2-WDIL-2 "exactly **one** entry `source_type='supplier_invoice'`" and W2-IDEM-5 "`journal_entries` count stays **1**" — enforced by `uniq_je_source_procurement` (`2026_06_26_120000_unique_journal_entries_source_procurement.php:45-47`, `WHERE source_type IN ('supplier_invoice','supplier_credit_note')`).

Keep them, but add "(DB-enforced — a green result is not evidence of application-level idempotency; the informative assertions are the leg amounts and the imbalance)". Otherwise a reader will bank idempotency confidence the rows do not earn. Note the same index does **not** cover `source_type='goods_receipt'`, which is why W2-IDEM-2's "two GR-IR entries" is genuinely reachable.

---

### G2-S1-09 [MINOR] — residual citation drift carried over from r1 unchanged

- Receive-path exception mapping: `PurchaseOrderController.php:857-858` is the `\DomainException` → 422 arm and `:859-865` the `\RuntimeException` → 500 `CONFIGURATION_ERROR` arm. r1 said `:855-856` / `:857-864` and r2 adopted both verbatim (W2-LOT-8 oracle, W2-EDGE-11 oracle, Tolerances). Off by two.
- `POST /roles` lives at `app/Modules/Identity/routes.php:60`; the matrix's `Identity/routes.php:66-68` (used for `POST /users`) is in the same file, not `Identity/Presentation/routes.php`.
- `UserController`'s single-company membership block is `:241-249` (`'company_id' => $companyId` at `:243`), cited as `:241-243`.

---

### G2-S1-10 [MINOR] — the per-location batch-invariant query is variant-blind

The W2-LOC-5 / W2-WDIL-1(b) query joins `stock_levels` on `(product_id, location_id, company_id)` only. `stock_levels` is **variant-grain** — `variant_id` plus two partial uniques, `stock_levels_non_variant` and `stock_levels_with_variant` (`2026_06_02_100005_add_variant_id_to_stock_levels.php:52-57`) — and `product_batches` carries `variant_id` too (`2026_06_02_100008_…`). On a product with variants one batch row joins several level rows and `batch_qty == level_qty` becomes meaningless.

Harmless for the r2 fixtures (only `P-LOT-8` has a variant and it never receives — W2-LOT-8 is a 422), but the query is written as a general invariant. **Fix:** add `AND sl.variant_id IS NULL` and a note that the variant-grain form needs `ibs`⋈`pb.variant_id`.

---

## Disposition audit

| r1 finding | Author verdict | My verification |
|---|---|---|
| **G1-S1-01** `check-cogs-coverage` cannot detect a missing GR-IR entry | FIXED | **CONFIRMED-FIXED** — W2-WDIL-4 is now a direct two-part orphan query, "the only signal" is deleted, F-W2-03 is P0 in `01` §5. Residue: G2-S1-01 (no `status='posted'`) and G2-S1-02 (the `IS NOT NULL` narrowing) |
| **G1-S1-02** batch tables do not exist | FIXED | **CONFIRMED-FIXED** — `product_batches` (`2026_01_05_150000_…:14`, `id` bigint, `company_id`/`product_id` uuid, `batch_number` varchar(100) at `:25`) and `inventory_batch_stock` (`2026_01_05_150001_…:14-42`, `batch_id` bigint FK, `location_id` uuid, `quantity` decimal(15,4), unique `(batch_id, location_id)` at `:36`, **no `company_id`**) — renamed everywhere I checked (HP-4, PART-4, LOT-2/4/6/7/12, LOC-5, WDIL-1). `stock_levels.company_id` verified at `2025_11_30_134000_…:75-77`; both quantity columns are 4 dp so the comparison is scale-clean |
| **G1-S1-03** W2-HP-5's query is vacuous | FIXED | **CONFIRMED-FIXED** — its own query, joined `je.source_id = l.movement_id`; `journal_entries.source_id` is `uuid` (`2025_11_30_100000_…:23`) and `stock_movements.id` is `uuid` (`2025_11_30_110000_…:33`), so the join is type-valid; `journal_lines.partner_id` exists (`2025_12_06_100000_add_partner_id_to_journal_lines.php:13`) and `debit`/`credit` are `(15,3)` (`2026_03_11_200000_…:27-30`); `COUNT(*) = 3` and "an EMPTY result FAILS the row" are both written in |
| **G1-S1-04** LAND-4's oracle is dead code | FIXED | **CONFIRMED-FIXED** — `DocumentAdditionalCostController::store:42-75` has no lifecycle guard (read verbatim), `canModifyCosts` is recorded dead (F-W2-33), W2-LAND-10 added for the post-`Received` arm, F-W2-35 for the hexagon breach |
| **G1-S1-05** VAT-4's premise describes a replaced path | FIXED | **CONFIRMED-FIXED, and I re-derived the whole chain.** `SupplierInvoicePostingService:415-416` builds and diverges, `:425` writes with `snapshotTaxDetails($supplierInvoice, $derived)` — the WRITER is `TaxCalculationService`, the DATA is the builder's, whose bucket base is Σ `line_total` and bucket VAT is Σ `recoverable_tax_amount` (`PostedLineTaxSnapshotBuilder.php:158-182`) and whose code/name are `'UNCONFIGURED'` / `"VAT {rate}%"` (`:205-206`). So `Σ document_tax_details.tax_amount == 0.192` is **right**, not merely asserted |
| **G1-S1-06** three column names do not exist | FIXED | **CONFIRMED-FIXED** — `document_tax_details.tax_base`/`tax_amount`; `repository_movements.payment_repository_id` (`2026_07_08_100100_…:18`, `direction` char(3) `:19`, `amount` (15,3) `:20`, `balance_after` `:22`); `products.sale_price` (`WeightedAverageCostService.php:299`). The `01c:476` UNVERIFIED marker is legitimately retired |
| **G1-S1-07** REV-6 asserts stale `landed_unit_cost` | FIXED | **CONFIRMED-FIXED** — `PurchaseOrderController.php:611` `'landed_unit_cost' => $lineData['landed_unit_cost'] ?? null` and `:125-127` populate the key only in Total mode or with a free quantity, so a Unit-mode recreate is NULL. W2-REV-6 now asserts NULL plus the genuinely stale `document_tax_details` |
| **G1-S1-08** second-of-everything is decorative | FIXED | **CONFIRMED-FIXED** — the blanket claim is withdrawn; `01` §3 carries a per-arm table of real row ids and names the classes with no arm and why. I checked that every row id it names exists in the matrix: SEC-7, LAND-9, LAND-6, LOC-5, LOC-6, IDEM-7, IDEM-2b, LOC-4(c). W2-SEC-7's mechanism is real — the WAC denominator is `->where('company_id', $companyId)` (`WeightedAverageCostService.php:108`) and the product lock re-scopes by company (`:219-223`) |
| **G1-S1-09** F-W2-01 has no operator vector | FIXED | **CONFIRMED-FIXED** — W2-IDEM-2b added with the explicit "if the UI cannot reach it, say so and re-grade to P1" instruction |
| **G1-S1-10** negative receive quantity | FIXED | **CONFIRMED-FIXED** — `ReceiveGoodsRequest.php:28` and `:30` carry `/^-?\d+…/`, `:36` correctly does not; the skip is `GoodsReceiptService.php:491-492` (before `assertQuantitiesWithinRemaining` at `:496`), message at `:720`. W2-OVER-5 covers both arms; F-W2-29 registered |
| **G1-S1-11** DISC-2/3 cite a docblock | FIXED | **CONFIRMED-FIXED** — `PurchaseOrderController.php:465-466` (store) and `:607-608` (update) are the discount writes, read verbatim |
| **G1-S1-12** cashier fixture under-specified | FIXED | **CONFIRMED-FIXED** — membership is single-company (`UserController.php:241-249`, `'company_id' => $companyId`), every W2-PERM row is pinned to company 1, and the three-context mechanism resolves the r1 contradiction. Bonus verification the author did not claim: the new `wave2-receiver` role **is** assignable — `AssignableRole` only requires the role's permissions to be a subset of the actor's (`AssignableRole.php:47-51`) and `'role' => [… 'exists:roles,name', new AssignableRole]` (`CreateUserRequest.php:50`) has no seeded-name allowlist, so W2-SETUP-7 is producible |
| **G1-S1-13** VAT-2's B13 / `tax_code` | FIXED | **CONFIRMED-FIXED** — B13 re-decided ALREADY; the PO's snapshot comes from the engine's UNCONFIGURED branch (`PurchaseOrderService.php:142`) and the invoice's from the builder, and **both** emit `code: 'UNCONFIGURED'`, `name: "VAT {rate}%"` (`PostedLineTaxSnapshotBuilder.php:205-206`, with a comment saying it deliberately matches the engine's shape) |
| **G1-S1-14** LOT-8 hedges on a determinable status | FIXED | **CONFIRMED-FIXED — and r2 corrected r1.** The class is `app/Shared/Domain/Exceptions/MissingVariantException.php`, not the `Modules/BatchExpiry/…` path r1 gave. It extends `\DomainException`, so `PurchaseOrderController`'s DomainException arm gives 422 |
| **G1-S1-15** SEC-5 will read NULL | FIXED | **CONFIRMED-FIXED** — `CreateSupplierInvoiceService::create` writes no `location_id` (`:120-147`, read in full); `payments.location_id` exists and is nullable (`2026_07_16_110000_add_location_id_to_payments_and_instruments.php`), so `IS NULL` is executable |
| **G1-S1-16** citation drift (`CreateDocumentRequest`) | FIXED in part · **REBUTTED** in part | **REBUTTAL-UPHELD — the author is right and my predecessor was wrong.** Read verbatim: `:119` `description`, **`:120` `quantity`** (`['required','numeric','gt:0','regex:/^\d+(\.\d{1,4})?$/']`), `:121-123` `free_quantity`, **`:124` `unit_price`**, **`:125` `line_total`**, `:126` `price_entry_mode`. W2-TOT-5's `:125` and W2-EDGE-3's `:120`/`:124` stand as written; the messages at `:166`/`:168` also check out |
| **G1-S1-17** the movement→GRN link is never asserted | FIXED | **CONFIRMED-FIXED** — W2-WDIL-1(c) + W2-LOC-6 added; see G2-S1-08 on the distinctness half being DB-enforced |
| **G1-C1-12** (costing) "W2-WDIL-3 will trip over the 115.000 vs 100.000 gap" | REBUTTED by author | **REBUTTAL-UPHELD.** Re-derived from source: `consumeReceiptLines` accrues Σ `sliceQty × goods_receipt_lines.accrual_unit_cost` (`SupplierInvoicePostingService.php:486-499`), and that column is the **receipt's own** landed cost (`GoodsReceiptService.php:695`) — 10.000000 and 13.000000 — so accruedHt = 5×10 + 5×13 = **115.000**, exactly the 50.000 + 65.000 the two receipts credited (`GeneralLedgerService.php:2068` `bcround(unitCost × qty, scale)`). **408 nets to zero.** ⚠ One nuance to add in a clause: the 15.000 that *did* capitalise is offset by a **phantom PPV income** of 15.000, not by a freight liability — so the P&L is credited for freight that was actually incurred. W2-WDIL-3 says "record the offsetting leg"; say *phantom* |

---

## Numbers I re-derived

`bcmul`/`bcdiv`/`bcadd` truncate; `CurrencyScale::bcround` is half-away-from-zero. TND scale 3, quantity 4, cost 6. Eight of these ten I did not check in r1; rows marked **[r2]** are new content.

| Row | Spec figure | My re-derivation | Verdict |
|---|---|---|---|
| **W2-LAND-7** [r2] | accruedHt `115.000`; Dr408 `115.000`, Dr4456 `19.000`, Cr7585 `15.000`, Cr401 `119.000`; balance `134.000` | accrual from receipt lines 5×10.000000 + 5×13.000000 = 115.000 (`SupplierInvoicePostingService.php:486-499`); expectedTotal 100+19 = 119.000 == total (`GeneralLedgerService.php:2210-2216`); drKnown 134.000; plug −15.000; priceDelta 100−115 = −15.000 ⇒ PPV **income** 15.000 (`:2303-2313`); inventoryPlug 0 ⇒ no inventory leg | **CONFIRMED** |
| **W2-WDIL-3 / PO-E netting** [r2] | 408 nets to zero | Cr 408 at receipts `bcround(10.000000×5,3)=50.000` + `bcround(13.000000×5,3)=65.000` = 115.000; Dr 408 at invoice 115.000 | **CONFIRMED** |
| **W2-LAND-8** [r2] | pool `30.000000`, base `11.000000`, receipt landed `14.000000`, `cost_price 14.000000`, GR-IR `140.000`, `price_override_old_basis 13.000000` | allocator: fraction 10/10 ⇒ linePool 30.000000, single share 30.000000 (`ReceiptBatchCostAllocator.php:48-51`, `:67`); override wins the base (`GoodsReceiptService.php:542-546`); `landedUnitCostForReceipt(10, 11.000000, 30.000000) = (110+30)/10 = 14.000000` (`:859-869`); GR-IR `bcround(14.000000×10,3)` = 140.000; `$oldBasis = bcround(landed_unit_cost, 6) = 13.000000` (`:530`, `:706`) | **CONFIRMED** |
| **W2-LAND-4** GR-IR [r2] | tranche 1 `50.000`, tranche 2 `65.000`, Σ408 `115.000`, WAC `11.500000` | t1: no cost yet ⇒ share 0, base = oldBasis 10.000000 ⇒ 50.000. t2: allocated 30.000000, fraction 5/10 ⇒ linePool 15.000000, freight-pool branch ⇒ base = `unit_price` 10.000000, `(5×10+15)/5 = 13.000000` ⇒ 65.000. WAC `(5×10.000000+5×13.000000)/10 = 11.500000` | **CONFIRMED** |
| **W2-LAND-3** | Dr408 `180.000`, Dr4456 `28.500`, Cr7585 `30.000`, Cr401 `178.500`, balance `208.500` | accrued 120.000+60.000; billed 150.000; plug 178.500−208.500 = −30.000; priceDelta −30.000 ⇒ PPV income; inventoryPlug 0 | **CONFIRMED** |
| **W2-PRICE-5** | Dr408 `110.000`, Dr4456 `19.000`, Cr7585 `10.000`, Cr401 `119.000`, Σ `129.000` | accrued 10×11.000000; plug 119−129 = −10.000; priceDelta −10.000; inventoryPlug 0 | **CONFIRMED** |
| **W2-PART-6** | Dr408 `105.000`, Dr4456 `19.950`, Cr401 `124.950`, no PPV, no plug | accrued 10.500000×(4+6); priceDelta 0; plug 124.950−124.950 = 0 | **CONFIRMED** |
| **W2-VAT-4** [r2 rewritten] | per line `0.064`; Σ `0.192`; subtotal `1.005`; total `1.197`; `Σ document_tax_details.tax_amount == documents.tax_amount == documents.line_tax_amount == 0.192`; Dr4456 `0.192` | `lineSubtotalHp = bcmul('1.0000','0.335',7) = 0.3350000` → `0.335`; `rateFraction = bcdiv('19','100',6) = 0.190000`; `lineTaxHp = 0.0636500` → `bcround(…,3) = 0.064` (`CreateSupplierInvoiceService.php:90-101`); header written at `:133-137`; the snapshot is the builder's (`SupplierInvoicePostingService.php:425`) ⇒ bucket base 1.005 / tax 0.192. `documents.line_tax_amount` exists at `(15,3)` (`2026_03_24_300000_…:14`) | **CONFIRMED** |
| **W2-LOC-6** [r2] | t1 `avg_cost_before 0.000000 → after 10.500000`; t2 `10.500000 → 10.500000` | `avg_cost_before = bcformat(currentCostPrice, 6)`, `avg_cost_after = newAvgCost` (`WeightedAverageCostService.php:278-279`); virgin product ⇒ 0.000000 | **CONFIRMED** |
| **W2-LAND-1 / HP-2 / LAND-4 / LAND-8** `allocated_costs` | `20.000 / 10.000 / 0.000 / 30.000`, "currency scale (3), not 6" | column `decimal(19,6)` (`2026_05_30_000000_…:60-63`), cast `decimal:6` (`DocumentLine.php:156`), pinned `assertSame('20.000000', …)` (`LandedCostBcmathTest.php:330-331`) — the cited `:298-300` is that test's **docblock** | **WRONG representation** (G2-S1-03) |

Also re-verified while in the files, because the run depends on them: `stock_movements.quantity` is **negative** on an issue (`WeightedAverageCostService.php:478`), so W2-WDIL-1(a)'s Σ-vs-delta identity holds once the FEFO leg runs; `goods_receipt_lines` carries `received_qty` / `free_qty` / `movement_id` / `free_movement_id` exactly as the WDIL-4 query names them (`2026_07_04_100000_…:42-49`); `GoodsReceiptStatus::Posted = 'posted'` and `MovementType::Receipt = 'receipt'`; `product_batches.batch_number` is `varchar(100)` against the request's `max:255`, so F-W2-30's 500 is real.

---

## What to fix before merge

Ten one-line edits, none requiring a re-gate: add `AND je.status = 'posted'` to both GR-IR queries; add the "received but never moved" arm to W2-WDIL-4; restate `allocated_costs` as 6 dp with the real pin; pin W2-LOT-9's `location_id` / `partner_id` / no-`batch_id`; make W2-LOT-6 and W2-LOT-10 assert 422 + rollback; ask W2-LOT-9 to record the delivery note's COGS entry; drop W2-LAND-7's refusal hedge; mark the three DB-enforced assertions as such; fix the three drifted citations; add `sl.variant_id IS NULL` to the batch-invariant query.
