# Wave-2 PO scenario matrix — adversarial spec gate **r2**, inventory / WAC / batch / receipt lens

**Under review:** `docs/superpowers/audits/2026-09-01-wave2-po-flow/02-scenario-matrix.md` (rev 2, 151 scenarios / 21 classes) + `01-research.md` (rev 2, 36 findings) + `02-gate-r1-response.md`.
**Answering:** my r1 review `…/reviews/2026-09-01-wave2-po-spec-gate-r1-costing.md` (G1-C1-01..24 + M1–M14).
**Worktree read:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/L-po-flow` @ `85ad66bd7` (dev `62964e5cc` + the r2 spec commit). Read-only. No suites, no servers.
**Method:** every disposition re-opened against the file it cites; every `[r2]` figure re-derived by hand. Nothing below is asserted from memory.

---

## VERDICT

**CHANGES-REQUIRED** — but a *narrow* round 3: seven row edits and one fixture-table expansion, not a rewrite.

The r2 response is honest and mostly exact. **All four r1 BLOCKERs are genuinely fixed** and I re-verified each against code: `canModifyCosts()` has exactly one grep hit (its own declaration) and `DocumentAdditionalCostController::store` (`:41-73`) has no receipt-state check; `product_batches` / `inventory_batch_stock` are the real relations (`Batch.php:19`, `BatchStock.php:13`); `W2-LOC` now owns `PO-L`/`PO-L5`; THE COSTING RULE + 30 virgin SKUs is stated and the arithmetic both reviewers derived survives byte-identical. The **rebutted sub-claim of G1-C1-12 is UPHELD** — I re-derived the whole PO-E clearing entry from `consumeReceiptLines` and `GeneralLedgerService`: `inventoryPlug` is exactly `0`, 408 nets to zero, `134.000` balances. The FEFO oracle repointing is byte-exact (`FEFOInventoryService.php:95`/`:271` ordering, `:105-113`/`:270` exclusion). 12 of the 14 missing scenarios landed with correct mechanisms.

What blocks r3:

1. **A company-wide *policy* set by one class silently breaks two rows in a later class.** `W2-PRICE-6` turns `match_enforcement` to `block` and never restores it; `W2-LAND-3` and `W2-LAND-7` are both price-variance posts and will 422 instead of producing the asserted journal entries. The COSTING RULE fixed cross-class *fixtures*; cross-class *settings* are still unguarded.
2. **Two `PUT /procurement-policies` calls are written as partial bodies and will 422** — every raw field is `required_without:preset`.
3. **`W2-LOT-10`'s oracle is unreachable**: the aggregate guard in `recordSale` fires before FEFO, so the lot-negative guarantee the row exists to prove is still untested — the same shape as r1's G1-C1-08.
4. **~9 rows that post receipts or assert absolute figures still name no fixture product**, so THE COSTING RULE is declared but not enforced end-to-end; `W2-IDEM-6` additionally requires a `balance_due` that `W2-PART-8` zeroed eight classes earlier.

---

## Findings

### BLOCKER

**G2-C1-01 [BLOCKER] — `W2-PRICE-6` leaves `match_enforcement = 'block'` set company-wide; `W2-LAND-3` and `W2-LAND-7` then 422 instead of posting**

*Rows:* `W2-PRICE-6` (`02-scenario-matrix.md:278`), `W2-LAND-3` (`:288`), `W2-LAND-7` (`:293`), run order (`:463`).

*Code:*
- `apps/api/app/Modules/Procurement/Domain/ProcurementPolicy.php:119-135` — `firstOrCreateForCompany()` persists **one row per company**; the setting survives the class that set it.
- `.../ProcurementPolicyController.php:33-53` — `update()` `forceFill`s and `save()`s the row. Nothing resets it.
- `apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php:254-261` — `if ($hasPriceVariance && $enforcement === MatchEnforcement::Block) { throw … }`.
- Run order `02:463` puts `PRICE` **before** `LAND`.

*Why it matters:* both remaining LAND invoice rows are price-variance by construction — `W2-LAND-3` bills `150.000` against an accrual of `180.000`, `W2-LAND-7` bills `100.000` against `115.000` (I re-derived both; see the numbers table). Under `block` each is refused at post, so the asserted legs (`Cr 7585 30.000` / `Cr 7585 15.000`, `Dr 408 180.000` / `115.000`) never exist. `W2-LAND-7` is the row added specifically to close G1-C1-12 — it would be dead on arrival.

*Fix (one line + one clause):* append to `W2-PRICE-6`: *"…then restore the default with a full raw body `{preset:null, bill_control_mode:'received', match_mode:'three_way', match_enforcement:'warn', variance_tolerance_percent:'2.00', variance_tolerance_max_amount:'1.000', allow_receipt_first:false, allow_invoice_first:<current>, invoice_first_requires_approval:true}` before the class ends"*, and add to the run plan's load-bearing-ordering list: *"`match_enforcement` is company-wide and persists across classes; any class that changes it restores it."*

---

### MAJORs

**G2-C1-02 [MAJOR] — both `PUT /procurement-policies` payloads in the matrix are partial and will 422**

*Rows:* `W2-PRICE-6` (`:278`, `{match_enforcement:'block'}`), `W2-IFIRST` class header (`:342`, `{allow_invoice_first: true}`).

*Code:* `apps/api/app/Modules/Procurement/Presentation/Requests/UpdateProcurementPolicyRequest.php:27-46` — `bill_control_mode`, `match_mode`, `match_enforcement`, `variance_tolerance_percent`, `variance_tolerance_max_amount`, `allow_receipt_first`, `allow_invoice_first`, `invoice_first_requires_approval` are **each** `required_without:preset`. `ProcurementPolicyController.php:44-53` then `forceFill`s all eight.

*Why it matters:* `W2-IFIRST-1..5` (5 rows) and `W2-PRICE-6` all begin with a call that returns 422 as written; the whole invoice-first class is blocked on step 0.

*Fix:* state in both places that the PUT carries the **complete raw body** (or `{preset:'…'}`), and cite `UpdateProcurementPolicyRequest.php:27-46`.

---

**G2-C1-03 [MAJOR] — `W2-LOT-10` cannot reach the lot guard it exists to prove; the aggregate guard fires first**

*Row:* `W2-LOT-10` (`:204`) — "attempt to consume `6.0000` … refusal; no negative `inventory_batch_stock.quantity` row", oracle `FEFOInventoryService::consumeBatchesAtomically (:265-272)`.

*Code:*
- `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:316-322` — `recordSale()` runs **before** the batch draw.
- `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:447-467` — `$newQty = bcsub($currentQty, $quantityStr, 4); if (bccomp($newQty,'0',4) < 0) throw new InsufficientStockForFulfilmentException(...)` — unconditional.
- Mapped at `DeliveryNoteController.php:581-594` → 422 `INSUFFICIENT_STOCK`.
- The lot-shortfall throw the row wants is `FEFOInventoryService.php:341` (`InsufficientBatchStockException`), reachable only when the **aggregate is sufficient but the lot ledger is not**.

*Why it matters:* after `W2-LOT-9`, `stock_levels` and `Σ inventory_batch_stock` are both `5.0000`, so asking for `6.0000` is an aggregate shortfall. The row measures the WAC guard and reports it as the batch guard — negative lot balance, the corruption mode of a batch system, stays unexercised.

*Fix:* re-shape `W2-LOT-10` onto a tuple where the aggregate is backed but the lots are not — the `W2-LOT-6` product (its only lot is expired, so `expiry_date >= today` at `FEFOInventoryService.php:270` excludes it while `stock_levels` still holds the received quantity) — and state the envelope: **422 `INVALID_STATUS_TRANSITION`**, message `Insufficient batch stock to fulfill atomic consume. Shortfall: <n>` (`InsufficientBatchStockException` extends `\DomainException`, caught at `DeliveryNoteController.php:595-596`), whole confirm rolled back, no negative `inventory_batch_stock` row. Keep an aggregate-shortfall arm if wanted, but label it 422 `INSUFFICIENT_STOCK` / `WeightedAverageCostService.php:456-467`.

---

**G2-C1-04 [MAJOR] — THE COSTING RULE is declared but not enforced: ~9 rows post receipts or assert absolute figures against unnamed fixtures**

*Rows without a `P-…` product or a named PO:* `W2-IDEM-2b` (`:360` — asserts the **absolute** `stock_levels 8.0000` of IDEM-2 on "a fresh confirmed PO"), `W2-IFIRST-3` (`:348` — "a posted receipt on a matching PO"), `W2-IFIRST-4` (`:349` — mints an auto-PO **and a posted GoodsReceipt**, then compares its net GL by `system_purpose` to `W2-PART-6`'s numbers), `W2-PRICE-6` (`:278` — "a fresh PO" that must be received and invoiced), `W2-REV-1`/`-5`/`-7` (`:257`, `:261`, `:263` — REV-7 posts a partial receipt), `W2-MATCH-2` (`:332`), `W2-EDGE-2` (`:446`), `W2-SEC-2` (`:374` — "the whole HP flow in c2" while `W2-SETUP-5` (`:92`) seeds only `P-SEC-7`, `P-LAND-6`, `P-LAND-9` in company 2).

*Code:* the rule the doc itself states — `WeightedAverageCostService.php:100-128` (company-wide denominator, verified: `->where('company_id', …)` at `:108`), blend `:241-253`, write-back `:291`. A second receipt on a silently-shared SKU moves `products.cost_price` for every later absolute assertion.

*Why it matters:* `02:463` claims *"every other class is order-independent **because each owns its own products and POs**"* — that claim is exactly as unearned as r1's second-of-everything blanket claim was, for these nine rows. `W2-IDEM-2b` is the sharpest: if the runner picks `P-IDEM-2`, `PO-X` already holds `8.0000` and the asserted figure is wrong by construction.

*Fix:* add `P-IDEM-2b`, `P-IFIRST-1`/`-2`, `P-PRICE-6`, `P-REV-5`, `P-MATCH-2`, `P-EDGE-2` and three c2 SKUs for `W2-SEC-2` to the fixture table with their own POs; for every row that genuinely asserts no absolute cost/stock figure (`REV-1/5`, `EDGE-2`), write *"asserts no absolute cost or stock figure — any product may be reused"* so the omission is deliberate rather than silent.

---

**G2-C1-05 [MAJOR] — `W2-IDEM-6`'s fixture was consumed by `W2-PART-8`**

*Rows:* `W2-IDEM-6` (`:364` — "a posted invoice with `balance_due 124.950`"), `W2-PART-8` (`:162` — "`balance_due = 0.000`; `status = paid`").

*Why it matters:* `124.950` is PO-C's supplier-invoice total and no other document in the matrix carries it. `IDEM` runs eight classes after `PART`. This is the r1 G1-C1-02 shape (a fixture closed by an earlier class) surviving into r2 in a class the costing rule's PO enumeration (`01-research.md:283`) does not cover for invoices.

*Fix:* give `W2-IDEM-6` its own chain — `PO-X2` / `P-IDEM-6 × 10.0000 @ 10.500`, received, invoiced, posted, **unpaid** — or state that the row creates its own invoice in-class and drop the copied `124.950`.

---

### MINORs

**G2-C1-06 [MINOR] — `W2-LOT-8`'s r2 re-citation inverts the 422/500 arms.** The row (`:202`) cites *"mapping `PurchaseOrderController.php:855-856` (422) vs `:857-864` (500, `\RuntimeException` only)"*. Verified: `:857` is `} catch (\DomainException $e) {`, `:858` the 422 `GOODS_RECEIPT_FAILED`, `:859` `} catch (\RuntimeException $e) {`, `:860-865` the 500 `CONFIGURATION_ERROR`. r1's citation was right and r2 moved it onto the wrong arm — while the row's whole determinism argument rests on which arm is which. **Fix:** `:857-858` (422) vs `:859-865` (500); apply the same correction to `W2-EDGE-11` (`:455`) and to the Tolerances note (`:472`).

**G2-C1-07 [MINOR] — three inherited citation drifts.** (a) `W2-LAND-8` (`:294`) cites `ReceiptBatchCostAllocator.php:57-58` for the override-weighted basis; the assignment is `:55-56` (`$unitBasis = (string) ($receivedUnitPrices[$lineId] ?? $line->unit_price); $receivedValue = bcmul($qty, $unitBasis, WORKING_SCALE);`) — `:57-58` are the `$lineIds[]` / `$receivedValues[]` pushes. (b) `W2-OVER-5` (`:176`) cites the "No items to receive" message at `GoodsReceiptService.php:720`; the throw the API path actually hits is `:193` in `createDraft`, because `receiveGoods()` (`:65-99`) is `createDraft` + `post` and the draft loop refuses first — the text is identical, the oracle is not. (c) `W2-LOC-5` (`:221`) cites the `(batch_id, location_id)` keying at `2026_01_05_150001_…:20-21`; the columns are `:21-22` and the unique index is `:36`.

**G2-C1-08 [MINOR] — `W2-LOT-6`'s FEFO probe outcome is stated as "draws nothing"; it is a whole-confirm refusal.** With the product's only lot expired, `recordSale` succeeds (aggregate is backed), `consumeBatchesAtomically` shortfalls and throws (`FEFOInventoryService.php:341`), and the transaction rolls the aggregate decrement back (`DeliveryNoteService.php:376-381`). **Fix:** state 422 + the shortfall message + "`stock_levels` unchanged", not "draws nothing".

**G2-C1-09 [MINOR] — `W2-LAND-7` hedges on an outcome that is statically excluded, and omits its `match_status`.** The row (`:293`) ends *"If the post instead refuses on the accrual assertion, that refusal is the finding"*. The only accrual assertion in `consumeReceiptLines` is the NULL-basis throw (`SupplierInvoicePostingService.php:488-495`), and both PO-E receipt lines carry a non-null `accrual_unit_cost` written at `GoodsReceiptService.php:695`. Per r2's own rule (`:34`, "neither is a row that declines to state a determinate expectation"), delete the hedge. Also state the expected `match_status = price_variance` (`SupplierInvoiceMatcher.php:248-252`) — otherwise the row reads as if a clean match were expected.

**G2-C1-10 [MINOR] — fixture-table drift.** `P-LAND-4` is listed as used by "LAND-4, LAND-7, **LAND-10**" (`:65`) but `W2-LAND-10` acts on `PO-D` / `P-LAND-1a/b` (`:296`). `P-OVER-1` is listed as "`PO-G` — OVER, UNDER-1/2" (`:53`) but `W2-UNDER-4`'s `PO-U2` also carries it (`:187`). `PO-H` is listed for EDGE-7 (`:74`) but `W2-OVER-3` (`:174`) receives against it first — harmless (a 422 rolls back) but it is an undeclared exception to the no-reuse rule. `01-research.md:283`'s PO enumeration omits `PO-U`, `PO-U2`, `PO-V`, `PO-DR1`, `PO-DR2`.

**G2-C1-11 [MINOR] — `W2-LOT-9/10` do not pin the delivery note's location.** `DeliveryNoteController::store` resolves it through `LocationContext::resolveLocationId($validated['location_id'] ?? null, …)` (`:444-446`), and `issueStock` **silently `continue`s** when no location resolves (`DeliveryNoteService.php:310-313`) — which would read as "FEFO drew nothing" rather than a fixture error. **Fix:** send `location_id = MAIN` explicitly and assert the resolved `documents.location_id` before confirming.

**G2-C1-12 [MINOR] — `W2-EDGE-12` needs a sales invoice that no class creates.** The row (`:456`) POSTs an additional cost to `{salesInvoiceId}`; the matrix imports 11 **suppliers** (`W2-SETUP-2`, `:89`) and never creates a customer document. `resolveDocument` (`DocumentAdditionalCostController.php:166-181`, verified: tenant + company + id, **no type filter**) will 404 on an id that does not exist, which is not the finding. **Fix:** drop the sales-invoice arm or name the document that supplies it (the `W2-LOT-9` delivery note is the only non-purchase document in the run).

**G2-C1-13 [MINOR] — company 2 is `non_registered` for the *whole* run, not just for `W2-LAND-6`.** `W2-SETUP-8` (`:95`) runs in SETUP, so every later c2 row (`SEC-1/2`, `SEC-7`, `LAND-9`, `MATCH-6`) executes with `isRecoverable = false` (`TaxCalculationService.php:266`), i.e. VAT folded into `landed_unit_cost` at confirm (`LandedCostService.php:194-224`) and stripped at post (`:265`). `W2-SEC-7`'s `cost_price = 20.000000` still holds (the strip restores `line_total / qty` — I re-derived it), but the doc should say so once so no c2 row is later read as asserting recoverable-VAT behaviour.

**G2-C1-14 [MINOR] — `W2-VAT-3` re-uses `PO-A` ambiguously.** Its action reads "**UI** PO-A (19 % + 7 % + 0 %), confirm" (`:306`) while `W2-HP-1/2` already created and confirmed `PO-A`. If it is a read-back, say "read back PO-A"; if it creates a second PO on `P-HP-1..3`, it is a second receipt on those SKUs and the fixture table's "used once" (`:49`) is violated.

---

## Disposition audit

| r1 finding | Author verdict | My verification |
|---|---|---|
| **G1-C1-01** running company-wide WAC × serial run | FIXED (option a) | **CONFIRMED-FIXED** — THE COSTING RULE (`02:39-43`) + 30-SKU table (`:45-77`) + `01:272-284`; the three code citations are byte-exact (`:100-128`, `:241-253`, `:291`). **Residue → G2-C1-04** (9 rows still unfixtured) |
| **G1-C1-02** `W2-LOC` on a closed `PO-C` | FIXED | **CONFIRMED-FIXED** — `PO-L` / `PO-L5`, class header states why (`:211-213`); refusals re-verified (`GoodsReceiptService.php:294-303`, `:313-319`) |
| **G1-C1-03** `canModifyCosts()` dead, Q-5 misframed | FIXED | **CONFIRMED-FIXED** — `grep -rn canModifyCosts app/` = **1 hit** (`LandedCostService.php:452`, the declaration); `DocumentAdditionalCostController::store` (`:41-73`) validates 4 fields and creates the row, no state check; Q-5 reframed (`:489`) |
| **G1-C1-04** batch table names | FIXED | **CONFIRMED-FIXED** — `Batch.php:19` `product_batches`, `BatchStock.php:13` `inventory_batch_stock`; renamed in HP-4, PART-4, LOT-2/4/6/7/12, LOC-5, WDIL-1. Column claims also hold: `goods_receipt_lines.received_qty/free_qty/movement_id/free_movement_id/quantity_invoiced` (`2026_07_04_100000_…:42-50`), all three cost columns nullable (`2026_07_06_110000_…:19-23`) — so `W2-UNDER-4`'s `landed_unit_cost IS NULL` is schema-legal |
| **G1-C1-05** `W2-PRICE-4` had no actor | FIXED | **CONFIRMED-FIXED** — `RoleController::store:187-198` (guard `sanctum`, `syncPermissions`) ✔, `AssignableRole:46-51` lets an admin assign it ✔, envelope exact: message `GoodsReceiptService.php:347`, code at `GoodsReceiptController.php:123-129` |
| **G1-C1-06** `W2-LAND-5` reversal not producible | FIXED (split 5a/5b) | **CONFIRMED-FIXED** — `DocumentAdditionalCostController` exposes only index/store/update/destroy/`landedCostBreakdown`; 5b correctly marked non-executable |
| **G1-C1-07** NON_REGISTERED c2 | FIXED | **CONFIRMED-FIXED** — `CompanyController.php:107` hardcodes REGISTERED, `:272-273` patch, `UpdateCompanyRequest.php:50`, `CompanyTaxStatusValidationService:30-45`. See MINOR G2-C1-13 |
| **G1-C1-08** FEFO never exercised / wrong oracle | FIXED | **PARTLY** — oracle repointing is **byte-exact** (`FEFOInventoryService.php:95` and `:271` `ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, created_at ASC`; exclusion `:105-113` and `:270`) and `W2-LOT-9` is sound. **`W2-LOT-10` NOT-FIXED → G2-C1-03** |
| **G1-C1-09** null expiry | FIXED | **CONFIRMED-FIXED** — `ReceiveGoodsRequest.php:33` is `['required_with:batches.*','date']` with no `nullable`; `2026_08_26_100000_…:34` makes the column nullable; `findOrCreateBatch(?string $expiryDate)` `:345` |
| **G1-C1-10** no second location for lots | FIXED | **CONFIRMED-FIXED** — `findOrCreateBatch` keys on `(company, product, batch_number, variant)` with no location (`BatchStockService.php:340-364`); `inventory_batch_stock` unique `(batch_id, location_id)` (`…150001:36`). The join query is valid for a non-variant product |
| **G1-C1-11** no second-company WAC isolation | FIXED | **CONFIRMED-FIXED** — `W2-SEC-7`; predicate `:108`, lock `:219-223`, write `:291` all verified |
| **G1-C1-12** LAND-4 stops at the receipt | FIXED + sub-claim rebutted | **CONFIRMED-FIXED / REBUTTAL-UPHELD** — re-derived from `consumeReceiptLines` (`SupplierInvoicePostingService.php:458-532`) and `GeneralLedgerService.php:2218-2226`: `drKnown = 134.000`, `plug = −15.000`, `priceDelta = −15.000`, **`inventoryPlug = 0`** ⇒ exactly Dr 408 115.000 / Dr 4456 19.000 / Cr 7585 15.000 / Cr 401 119.000, balanced at 134.000, and 408 nets to zero. My r1 worry was wrong. (Two residues: G2-C1-01 blocks the row, G2-C1-09 on its hedge) |
| **G1-C1-13** override × landed untested | FIXED | **CONFIRMED-FIXED** — every `W2-LAND-8` figure re-derived and matching (table below); citation nit in G2-C1-07(a) |
| **G1-C1-14** `S1` under-specified / orphan line | FIXED | **CONFIRMED-FIXED** — and the mechanism is now provable: `receiveGoods` = `createDraft` + `post` (`:65-99`), `createDraft` has only the `product_id === null` check (`:153`) and writes the line with NULL costs (`:168-188`), `processReceiptLines` skips non-physical at `:519-521` ⇒ orphan row exactly as `W2-UNDER-4` predicts |
| **G1-C1-15** 101–255-char `batch_number` | FIXED | **CONFIRMED-FIXED** — `ReceiveGoodsRequest.php:32` `max:255` vs `product_batches.batch_number varchar(100)` (`…150000:25`); 500 arm `:859-865` (citation nit, G2-C1-06) |
| **G1-C1-16** `allocated_costs` scale | FIXED | **CONFIRMED-FIXED** — `allocatePositiveShares` (`:303-316`) returns at `$scale` and `:264`/`:125`/`:222` assign it straight through ⇒ 3 dp |
| **G1-C1-17** LOT-8 hedge | FIXED | **CONFIRMED-FIXED** — `MissingVariantException extends \DomainException`, thrown at `BatchStockService.php:353` ⇒ 422 deterministic (citation nit, G2-C1-06) |
| **G1-C1-18** PRICE-3 needs `quantities` | FIXED | **CONFIRMED-FIXED** — `ReceiveGoodsRequest.php:27` `required_with:received_unit_prices`; `:36` regex has no `-?` and caps at 3 dp; service arm `:160-166`/`:532-541` |
| **G1-C1-19** negative quantities | FIXED | **CONFIRMED-FIXED** — `:28` and `:30` both admit `-?`, `:36` does not, `CreateDocumentRequest.php:120` uses `gt:0`; skip at `GoodsReceiptService.php:491-493`. Message oracle nit in G2-C1-07(b) |
| **G1-C1-20** draft persists an invalid destination | FIXED | **CONFIRMED-FIXED** — `createDraft:129` stores `location_id` verbatim; resolution only at `:222` / `:984-1008` |
| **G1-C1-21** quantity display precision | FIXED | **CONFIRMED-FIXED** — `ReceiveGoodsDialog.tsx:350-366` uses `<QuantityInput decimalPlaces={quantityScale(line)}>`, so the "renders `6`" expectation is determinate |
| **G1-C1-22** HP-4 oracle range | FIXED | **CONFIRMED-FIXED** — `:100-128` / `:241-253` / `:263-284` / `:291` all land exactly where claimed |
| **G1-C1-23** receipts carry no `MovementReason` | FIXED | **CONFIRMED-FIXED** — `recordPurchase` writes `movement_type` only (`:269`); `recordSale` sets `reason` at `:477` |
| **G1-C1-24** TOT-2 unit-price ambiguity | FIXED | **CONFIRMED-FIXED** — and the costing claim checks out: Total mode derives `unit_price = line_total/quantity` (`PurchaseOrderController.php:100-105`) and `landed_unit_cost = line_total/quantity` (`:124-126`, `LandedCostService.php:345-356`) |
| **M1** FEFO consumption | `W2-LOT-9` | **CONFIRMED** — driver chain verified end to end: routes `Document/Presentation/routes.php:330-336` (no `module:` gate, `deliveries.create`/`.confirm`, both held by admin), `DeliveryNoteService::issueStock:297` → `consumeBatchesAtomically:389`, FEFO ordering `:271` |
| **M2** over-consume a lot set | `W2-LOT-10` | **NOT-FIXED** → G2-C1-03 |
| **M3** null expiry | `W2-LOT-11` | **CONFIRMED** |
| **M4** same lot, two locations | `W2-LOC-5` | **CONFIRMED** |
| **M5** second-company WAC | `W2-SEC-7` | **CONFIRMED** |
| **M6** override + additional cost | `W2-LAND-8` | **CONFIRMED** (blocked by nothing; figures exact) |
| **M7** supplier invoice on `PO-E` | `W2-LAND-7` | **CONFIRMED** as written — but unexecutable under G2-C1-01 |
| **M8** `receiveAll` on a non-physical Product | `W2-UNDER-4` | **CONFIRMED** |
| **M9** over-long `batch_number` | `W2-EDGE-11` | **CONFIRMED** |
| **M10** draft + foreign location | `W2-LOC-4(c)` | **CONFIRMED** |
| **M11** negative `quantities` | `W2-OVER-5` | **CONFIRMED** (oracle nit) |
| **M12** stray `batches` key | `W2-LOT-12` | **CONFIRMED** — `$batchData[$line->id]` at `:523` and `:556`, extra keys unread |
| **M13** `manufacturing_date` > `expiry_date` | `W2-LOT-13` | **CONFIRMED** — `ReceiveGoodsRequest.php:31-34` has no cross-field rule |
| **M14** quantity display precision | `W2-HP-3` / `W2-LOT-1` | **CONFIRMED** |

---

## Numbers I re-derived (all `[r2]` unless noted)

| Row | Spec figure | My derivation | Verdict |
|---|---|---|---|
| `W2-LAND-7` | accrued `115.000`, billed `100.000`, Cr 7585 `15.000`, Dr 408 `115.000`, Dr 4456 `19.000`, Cr 401 `119.000`, Σ `134.000` | `consumeReceiptLines` sums slice × receipt `accrual_unit_cost` (`SupplierInvoicePostingService.php:488-500`) = `5×10.000000 + 5×13.000000 = 115.000`; `GeneralLedgerService.php:2218-2226`: `drKnown = 115+19 = 134.000`, `plug = 119−134 = −15.000`, `priceDelta = 100−115 = −15.000`, **`inventoryPlug = 0`** ⇒ no Inventory leg; PPV-income arm `:2303-2312` | ✅ exact — **408 nets to zero, rebuttal upheld** |
| `W2-LAND-8` | pool `30.000000`, base `11.000000`, receipt landed `14.000000`, `cost_price 14.000000`, GR-IR `140.000`, `old_basis 13.000000` | allocator `:48-51`: fraction `10/10`, linePool `30.000000`; `:55-56` receivedValue `10×11.000 = 110.000000`, single line ⇒ share `30.000000`; override branch `GoodsReceiptService.php:542-544` ⇒ base `11.000000`; `landedUnitCostForReceipt:859-869` ⇒ `(110+30)/10 = 14.000000`; GR-IR `bcround(bcmul(14.000000,10),3) = 140.000` (`GeneralLedgerService.php:2068`); `price_override_old_basis = $oldBasis` (`:706`, `:530`) = PO-line landed `13.000000` | ✅ all exact |
| `W2-LAND-4` GR-IR | `50.000` / `65.000` / Σ `115.000`; `15.000` of `30.000` lost | tranche 1: no pool ⇒ base `10.000000` ⇒ `5×10 = 50.000`; tranche 2: reallocate ⇒ `allocated_costs 30.000`, allocator fraction `5/10` ⇒ `15.000000`, freight-pool branch ⇒ base = raw `unit_price 10.000000` ⇒ `(50+15)/5 = 13.000000` ⇒ `65.000`; true cost `130.000` − `115.000` = `15.000` | ✅ exact |
| `W2-LAND-1` | `allocated_costs 20.000 / 10.000` (3 dp), landed `12.000000` both | `allocatePositiveShares:303-316` returns at `$this->scale()` (TND = 3), assigned at `:264`; `landedUnitCost:327-356` `(100+20)/10` and `(50+10)/5` at COST_SCALE 6 | ✅ the r2 scale fix is right |
| `W2-LAND-3` | Dr 408 `180.000`, Dr 4456 `28.500`, Cr 7585 `30.000`, Cr 401 `178.500`, Σ `208.500` | accrued `10×12 + 5×12 = 180.000`; `drKnown = 208.500`, `plug = −30.000`, `priceDelta = −30.000`, `inventoryPlug = 0` | ✅ exact |
| `W2-PRICE-5` | Dr 408 `110.000`, Dr 4456 `19.000`, Cr 7585 `10.000`, Cr 401 `119.000`, Σ `129.000` | accrual basis is the receipt line's `11.000000` (`GoodsReceiptService.php:695`); `plug = 119−129 = −10.000 = priceDelta` ⇒ `inventoryPlug = 0` | ✅ exact |
| `W2-EDGE-7` | free leg first at `'0'`, WAC `0.000000` → `8.333333`, one GR-IR `100.000`, `effective 8.333333` | free `recordPurchase(..., landedUnitCost:'0')` at `:569-573` precedes the paid leg; virgin ⇒ `0/2 = 0.000000`; then `(0 + 10×10.000000)/12 = 8.333333` (`bcformat` truncation, `:251-253`); GR-IR zero-skip `:2070-2072`; `effectiveUnitCost:839-851` `(10×10.000000)/12 = 8.333333` | ✅ exact |
| `W2-SEC-7` | c1 `10.000000` / `10.0000` byte-identical after the c2 receipt; c2 `20.000000` | denominator `->where('company_id', …)` `:108`; product lock re-scoped by tenant+company `:219-223`; write `:291`. Holds even with c2 `non_registered`, because `reallocateCosts` passes `'0'` for non-recoverable VAT at `:265` ⇒ landed back to `line_total/qty` | ✅ exact |
| `W2-LOC-6` | tranche 1 `0.000000 → 10.500000`, tranche 2 `10.500000 → 10.500000` | movement fields `avg_cost_before = currentCostPrice`, `avg_cost_after = newAvgCost` (`:263-284`); blend `(4×10.5 + 6×10.5)/10 = 10.500000` | ✅ exact |
| `W2-LOT-9` | `LOT-T1 4.0000 → 0.0000`, `LOT-T2 6.0000 → 5.0000`, `stock_levels 10 → 5`, Σ equal | `ORDER BY (b.expiry_date IS NULL) ASC, b.expiry_date ASC, b.created_at ASC` (`:271`) with `FOR UPDATE OF ibs SKIP LOCKED`; `recordSale` writes `quantity = −5.0000` and leaves `cost_price` untouched (`:477-482`), so the shared use of `P-PART-1` is safe | ✅ exact |
| `W2-TOT-4` | `unit_price 14.285`, post-confirm total `99.995` | `bcformatStrict(bcdiv('100.000','7.0000',4), 3)` = `14.2857 → 14.285` (truncating) at `PurchaseOrderController.php:103-104`; `7 × 14.285 = 99.995` | ✅ exact |
| `W2-VAT-4` | `0.064 × 3 = 0.192`, subtotal `1.005`, total `1.197` | `bcround(0.335×0.19, 3) = bcround(0.06365,3) = 0.064`; `3×0.335 = 1.005`; snapshot built from persisted line amounts (`SupplierInvoicePostingService.php:415-416`) | ✅ exact (r1 agreement) |

**Also re-verified, non-numeric:** `stock_levels.company_id` is NOT NULL (`2025_11_30_134000_…:75-77` ✔, the exact lines the doc cites); `product_batches` unique is company-scoped (`2026_06_02_100008_…:27-28`); `CreateDocumentRequest` line rules land exactly where r2's rebuttal of G1-S1-16 says (`:119` description, `:120` quantity `gt:0`, `:124` unit_price, `:125` line_total, `:126` price_entry_mode) — that rebuttal is correct; `reallocateCosts` has exactly one caller (`GoodsReceiptService.php:228`) and `allocateCostsAndTaxes` exactly one (`PurchaseOrderService.php:145`), so `W2-LAND-10`'s "no later `post()` to pick it up" is right.

---

## What to fix before merge

Restore `match_enforcement: 'warn'` at the end of `W2-PRICE-6` (and send both `PUT /procurement-policies` bodies complete — every field is `required_without:preset`); re-shape `W2-LOT-10` onto a lot-shortfall fixture so the negative-lot guarantee is actually exercised; name a virgin product and PO for the nine WAC-mutating rows that still have none (and give `W2-IDEM-6` its own unpaid invoice); then the four citation/hedge MINORs, and this is ACCEPT.
