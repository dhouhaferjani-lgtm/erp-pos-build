# Wave-2 PO scenario matrix — adversarial spec gate **r3** (narrow), inventory / WAC / batch / receipt lens

**Under review:** `docs/superpowers/audits/2026-09-01-wave2-po-flow/02-scenario-matrix.md` (rev 3, 154 scenarios / 21 classes) + `01-research.md` (rev 3) + `02-gate-r2-response.md`.
**Answering:** my r2 review `…/reviews/2026-09-01-wave2-po-spec-gate-r2-costing.md` (G2-C1-01 BLOCKER, 02-05 MAJOR, 06-14 MINOR).
**Worktree read:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/L-po-flow` @ `f159192a7` (dev `62964e5cc` + the r3 spec commit). Read-only. No suites, no servers.
**Method:** every r2 disposition re-opened against the file it cites; every `[r3]` figure re-derived; a fresh adversarial pass restricted to rows added or changed in r3 (`W2-PRICE-7`, `W2-IFIRST-6`, `W2-LOT-10`, `W2-LOT-10b`, `W2-LOT-6`, `W2-IDEM-6`, `W2-IFIRST-3/4`, `W2-EDGE-11/12`, the WDIL-4 detector arms, the fixture table's r3 rows and its new reuse list). Nothing below is asserted from memory.

---

## VERDICT

**CHANGES-REQUIRED** — round 4 is narrow: **one BLOCKER that is a single word in four payloads**, four MAJORs that are each one clause, five MINORs.

The r3 round is substantively good. **11 of the 14 r2 findings are genuinely fixed and I re-verified each against code**: the 422/500 arms (`PurchaseOrderController.php:857-858` `\DomainException` → 422 vs `:859-865` `\RuntimeException` → 500) are now right in all three places; the three citation drifts land byte-exact (`ReceiptBatchCostAllocator.php:55-56`, `GoodsReceiptService.php:193`, `inventory_batch_stock` columns `:21-22` / unique `:36`); the `W2-LOT-10` reshape does now reach the batch guard the row exists to prove; the GR-IR `je.status='posted'` filter is well-founded (`GeneralLedgerService.php:2099` Draft, `:2127` return, `:2134` post-outside); the `allocated_costs` reviewer conflict is resolved **against me** and correctly (ruling below).

What blocks r4:

1. **The fix for the r2 BLOCKER 422s.** Every restored policy PUT carries `preset: null` alongside the eight raw fields. `Request::has('preset')` is TRUE for a null value, so `UpdateProcurementPolicyRequest::after()` (`:59-76`) fires "Preset updates cannot be mixed with raw policy fields", *and* `'preset' => ['sometimes','required',…]` (`:26`) fails on null. All four policy calls 422 — so `match_enforcement` is never restored and `W2-LAND-3` / `W2-LAND-7` die exactly as in r2.
2. **The new "any product may be reused" list conflates *asserts nothing* with *mutates nothing*.** Three of the rows it licenses perform a successful, state-moving write on an unnamed fixture.
3. **`W2-EDGE-11` measures nothing on an untracked product** — the 500 it exists to record is only reachable on a batch-tracked line, and the row names no PO at all.
4. **Two r3 rows are indeterminate in the arithmetic sense** (`W2-IDEM-6`'s first payment amount, `W2-IFIRST-4`'s comparison numbers), each able to fail for a fixture reason and report it as a product defect.

---

## Findings

### BLOCKER

**G3-C1-01 [BLOCKER] — every `PUT /procurement-policies` in r3 sends `preset: null`, which 422s on two independent rules; the r2 BLOCKER is therefore not cleared**

*Rows:* THE SETTINGS RULE (`02-scenario-matrix.md:52`), `W2-PRICE-6` (`:321`), **`W2-PRICE-7 (restore)`** (`:322`), the `W2-IFIRST` class header (`:386`), **`W2-IFIRST-6 (restore)`** (`:395`), run plan step 7 (`:544`).

*Code (read in this worktree):*
- `apps/api/app/Modules/Procurement/Presentation/Requests/UpdateProcurementPolicyRequest.php:26` — `'preset' => ['sometimes', 'required', Rule::enum(ProcurementPreset::class)]`. `sometimes` is satisfied by key presence (`Validator::passesOptionalCheck` → `array_key_exists`), so a **null** `preset` is validated and fails `required`.
- `…/UpdateProcurementPolicyRequest.php:59-76` — `if ($this->has('preset')) { foreach ([...8 raw fields...]) if ($this->has($rawField)) { $validator->errors()->add('preset', 'Preset updates cannot be mixed with raw policy fields.'); return; } }`.
- `vendor/laravel/framework/src/Illuminate/Support/Traits/InteractsWithData.php:51-64` — `has()` → `Arr::has()` → `Arr::exists()` → `array_key_exists` (`Collections/Arr.php:263-278`). **A null value is "present".**
- `ProcurementPolicyController.php:38-53` — the raw branch already writes `'preset' => null` itself (`:42`); the client must not send the key at all.

*Why it matters:* this is the r2 BLOCKER unfixed in substance. `W2-PRICE-7` 422s ⇒ `match_enforcement` stays `block` ⇒ `W2-LAND-3` and `W2-LAND-7` are refused at post by `SupplierInvoiceMatcher::assertPostable` (`:255-261`, called at post time — `SupplierInvoicePostingService.php:180`) and the legs they assert never exist. `W2-IFIRST-2`'s enabling PUT 422s ⇒ the whole invoice-first class is blocked on step 0 again, and `W2-IFIRST-6` cannot restore `allow_invoice_first`.

*Fix (one word, four places):* **delete the `preset:null` key** from all four bodies — send only the eight raw fields `{bill_control_mode:'received', match_mode:'three_way', match_enforcement:'warn'|'block', variance_tolerance_percent:'2.00', variance_tolerance_max_amount:'1.000', allow_receipt_first:false, allow_invoice_first:<v>, invoice_first_requires_approval:true}` — and cite `UpdateProcurementPolicyRequest.php:26` + `:59-76` beside the existing `required_without` citation. (Verified: with the key absent, `sometimes` skips the rule, `required_without:preset` is satisfied by the eight fields, `after()` does not fire, and the controller nulls `preset` itself.)

---

### MAJORs

**G3-C1-02 [MAJOR] — the r3 "any product may be reused" list licenses three rows that *mutate* stock/WAC/AP state on an unnamed fixture**

*Rows:* the list at `02:109`; `W2-OVER-5` (`:210`), `W2-SEC-5` (`:422`), `W2-IDEM-7` (`:410`).

*Code / arithmetic:*
- `W2-OVER-5` arm (b) is a **200 receipt**: "`lineA` receives `4.0000`" against "a 2-line PO" that no fixture names. The only 2-line POs in the run are `PO-D` (LAND-1's `P-LAND-1a/b`) and `PO-U2` (UNDER-4), **both used by later classes**. `OVER` runs before `LAND` (`:534`). A 4.0000 tranche on `P-LAND-1a` before `W2-LAND-1` adds its freight destroys the whole LAND derivation (`allocated_costs 20.000000/10.000000`, `landed 12.000000`, `cost_price 12.000000`, GR-IR `120.000/60.000`, `W2-LAND-3`'s `208.500` entry) via the company-wide denominator at `WeightedAverageCostService.php:100-128` and the write-back at `:291`. That is exactly the corruption THE COSTING RULE exists to prevent.
- `W2-SEC-5` **posts a payment** ("pay it from that repository") against "a posted invoice" that no fixture names, and needs a **positive** `balance_due`: the locked-row guard refuses `allocationAmount > currentBalance` with 422 `SUPPLIER_PAYMENT_EXCEEDS_PAYABLE` (`PaymentController.php:1049-1064`). `PO-C`'s invoice is zeroed by `W2-PART-8` (`:196`); `PO-X6`'s remaining balance depends on `W2-IDEM-6` (see G3-C1-04).
- `W2-IDEM-7` **creates two supplier invoices** on "a received PO" — harmless for numbering (SI sequence is company-scoped and no later row asserts a c1 SI number), but it consumes uninvoiced receipt-line capacity on whichever PO it picks.

*Why it matters:* the list's stated criterion is "asserts no absolute cost or stock figure". The hazard THE COSTING RULE addresses runs the other way — a row that *writes* invalidates other rows' figures. As written, `02:539` ("every other class is order-independent because each owns its own products and POs") is again unearned for these three.

*Fix:* re-word the list header to **"rows that assert no absolute figure **and write no stock, WAC or AP state**"**, and give the three writers their own fixtures: `PO-OV5` (`P-OVER-5a` + `P-OVER-5b`, 2 lines, confirmed) for `W2-OVER-5(b)`; name `PO-X6`'s invoice with its stated residual balance for `W2-SEC-5`; name a received PO (e.g. `PO-M`, fully received, already invoiced only by `W2-MATCH-*` drafts) for `W2-IDEM-7`.

---

**G3-C1-03 [MAJOR] — `W2-EDGE-11` names no PO, and on an untracked product it returns 200 instead of the 500 it exists to record**

*Row:* `W2-EDGE-11` (`02:526`) — "receive with a 150-character `batch_number`" ⇒ 500 `CONFIGURATION_ERROR`.

*Code:* `GoodsReceiptService.php:556-567` — `if (isset($batchData[$line->id]) && ($product->requires_batch_tracking ?? false))`. On an **untracked** product the batch payload is silently dropped (this is `W2-LOT-4`'s own finding, `02:232`), `findOrCreateBatch` is never called, `product_batches.batch_number varchar(100)` (`2026_01_05_150000_…:25`) is never touched, and the receive returns **200**. The `QueryException` → `\RuntimeException` → 500 arm (`PurchaseOrderController.php:859-865`) is unreachable.

*Why it matters:* `W2-EDGE-11` is the evidence row for F-W2-30 **and** for the tolerance clause at `02:549` (the receive path renders every DB failure as 5xx). Run against `PO-G`/`P-OVER-1` — the obvious leftover with a remainder — it measures nothing and silently reports green.

*Fix:* name the fixture in the row: **`PO-T11` / `P-LOT-11`** (batch-tracked; both `W2-LOT-11` arms are 422 at validation, so PO-T11 is still `Confirmed` with a full remainder at EDGE time — `02:240`), state the requirement inline ("the line must be batch-tracked or the batch payload is dropped at `GoodsReceiptService.php:557` and the row returns 200"), and add "assert no `goods_receipts` row and no `stock_movements` row survive the 500".

---

**G3-C1-04 [MAJOR] — `W2-IDEM-6`'s first payment amount is unstated, and the concurrent arm only works when it is ≤ `24.950`**

*Row:* `W2-IDEM-6` (`02:409`) — own chain `PO-X6` (`P-IDEM-6 × 10.0000 @ 10.500`) ⇒ `balance_due 124.950`; "two payments with the same `Idempotency-Key`; then two **concurrent** payments of `100.000`" ⇒ "exactly one succeeds; the other 422s `SUPPLIER_PAYMENT_EXCEEDS_PAYABLE`".

*Code / arithmetic:* the locked-row re-check refuses when `allocationAmount > currentBalance` (`PaymentController.php:1049-1064`, code at `:1055`). Balance after arm 1 = `124.950 − X`. For **exactly one** concurrent `100.000` to succeed, `124.950 − X ≥ 100.000` ⇒ **`X ≤ 24.950`**. A runner that reuses `100.000` for arm 1 leaves `24.950` and **both** concurrent payments 422 — the row then reports a false idempotency defect. (I re-derived `124.950` = `10 × 10.500 = 105.000` + 19 % `19.950`; correct.)

*Fix:* state the amount in the row: *"arm 1 pays `20.000` (leaving `104.950`), so the concurrent pair of `100.000` has exactly one winner."*

---

**G3-C1-05 [MAJOR] — `W2-IFIRST-4`'s order-independence comparison cannot hold at `P-IFIRST-2`'s fixture price**

*Rows:* `W2-IFIRST-4` (`02:393`) — "assert order-independence: the net GL by `system_purpose` equals that of the receipt-then-invoice path (**W2-PART-6**) for the same numbers"; fixture `P-IFIRST-2 @ 10.000` (`02:99`); `W2-PART-6` is `PO-C` = `P-PART-1 × 10.0000 @ 10.500` ⇒ Dr 408 `105.000` / Dr 4456 `19.950` / Cr 401 `124.950` (`02:194`).

*Why it matters:* the row states no line for its own invoice. At the fixture price the invoice-first net is `100.000 / 19.000 / 119.000`, which is **not** equal to `W2-PART-6`'s net, so the assertion fails on arithmetic and would be recorded as an order-dependence defect. "for the same numbers" is a proviso the fixture table contradicts.

*Fix:* state the line at the row — *"`P-IFIRST-2 × 10.0000 @ 10.500`, 19 %, so the net GL is comparable leg-for-leg with W2-PART-6 (`105.000 / 19.950 / 124.950`)"* — and change `P-IFIRST-2`'s price in the fixture table to `10.500`.

---

### MINORs

**G3-C1-06 [MINOR] — `W2-LOT-10`(a) is now an exact duplicate of `W2-LOT-6`'s r3 probe, and `W2-LOT-6` does not pin the header facts `W2-LOT-10` pins.** `W2-LOT-6` (`02:234`) now says "run the LOT-9 driver against this product for the full received quantity" ⇒ 422 `INVALID_STATUS_TRANSITION` + rollback; `W2-LOT-10` (`:238`) does the same thing on the same product with the same oracle. Two of the 154 scenarios measure one behaviour. Worse, `W2-LOT-6` does **not** repeat the pinned `location_id = MAIN` / `partner_id = SUP-A` / no `batch_id` — with a null location `issueStock` silently `continue`s (`DeliveryNoteService.php:309-313`, verified) and the confirm returns 200, which reads as "the expired lot was consumable". **Fix:** keep the consumption in `W2-LOT-10` only; reduce `W2-LOT-6` to the receipt facts (200, `is_expired = false`) and cross-reference `W2-LOT-10` for the exclusion proof — or, if both stay, repeat the three pinned header facts in `W2-LOT-6`.

**G3-C1-07 [MINOR] — `W2-LOT-10b` does not pin its header.** `W2-LOT-10b` (`02:239`) says only "attempt to consume `6.0000`". Same mechanism as above: no `location_id` ⇒ `issueStock` skips the line ⇒ `recordSale` never runs ⇒ no `InsufficientStockForFulfilmentException` (`WeightedAverageCostService.php:457-467`, verified exact) ⇒ 200, not the asserted 422 `INSUFFICIENT_STOCK` (`ERROR_CODE = 'INSUFFICIENT_STOCK'`, `InsufficientStockForFulfilmentException.php:60`; first catch arm `DeliveryNoteController.php:581`, verified). **Fix:** append "same pinned header as `W2-LOT-9` (`location_id = MAIN`, `partner_id = SUP-A`, no `batch_id`)".

**G3-C1-08 [MINOR] — the rollback citation is a comment, not the mechanism.** `W2-LOT-6` and `W2-LOT-10` cite `DeliveryNoteService.php:376-379` for the rollback contract; `:370-379` is prose inside the `issueStock` comment block. The mechanism is `DB::transaction(...)` at **`DeliveryNoteService.php:95`** wrapping `confirm()` (`:75`, `issueStock` called at `:150`). **Fix:** cite `:95` as the mechanism and keep `:376-379` as the stated contract.

**G3-C1-09 [MINOR] — the Settings ledger's completeness sentence covers only *company-wide* settings, and S-3 leaves one residue.** (a) `02:65` ("Nothing else in this matrix writes a company-wide setting") does not cover **principal-scoped** state, and `W2-LOC-4` (`:255`) contrasts "the 403 `LOCATION_FORBIDDEN` when `allowed_location_ids` is narrowed" — if that narrowing is executed on the shared admin, every later receiving class 403s; if it is only code truth read off `GoodsReceiptDestinationTest.php:183`, say so. (b) S-3 (`:60`) says the restore returns the row's *values*, but the raw branch writes `'preset' => null` (`ProcurementPolicyController.php:42`) where `firstOrCreateForCompany` seeded `standard` (`ProcurementPolicy.php:124`); behaviourally inert — `ProcurementPolicyResolver::forCompany` reads only the raw columns (`:24-50`, verified) — but the ledger should say so rather than leave a reader to discover it. **Fix:** one clause in `02:65` ("no row narrows a principal's `allowed_location_ids`; `W2-LOC-4`'s 403 is code truth, not executed") and one in S-3.

**G3-C1-10 [MINOR] — two residual fixture-name drifts.** (a) `01-research.md:293` enumerates **`PO-c2L6`** for company 2 while the matrix's `W2-LAND-6` uses **`PO-G2`** (`02:336`) — same PO, two names. (b) `02:99` lists **`PO-IF2`** as a fixture SKU↔PO pair for `P-IFIRST-2`, but `W2-IFIRST-4`'s PO is **minted by `InvoiceFirstOrchestrator`** (`:22-87`), so nothing in SETUP creates a `PO-IF2`. **Fix:** pick one name for the c2 LAND-6 PO in both documents; annotate `PO-IF2` as "auto-generated by the orchestrator, not seeded".

---

## Disposition audit — my r2 findings

| r2 finding | Author verdict | My verification (r3) |
|---|---|---|
| **G2-C1-01** [BLOCKER] `match_enforcement` left at `block` | FIXED | **NOT-FIXED in effect** — the mechanism, the ledger (S-1..S-6) and the ordering constraint (iii) at `02:537` are all correct, but `W2-PRICE-7`'s payload 422s (**G3-C1-01**), so `warn` is never restored. Re-verified the dependency: `assertPostable` reads enforcement at post time (`SupplierInvoicePostingService.php:180` → `SupplierInvoiceMatcher.php:255-261`) |
| **G2-C1-02** [MAJOR] partial policy bodies 422 | FIXED | **NOT-FIXED** — the eight raw fields are now complete (`UpdateProcurementPolicyRequest.php:27-46` ✔ exactly eight `required_without:preset` rules), but the added `preset:null` re-breaks all four calls (**G3-C1-01**) |
| **G2-C1-03** [MAJOR] `W2-LOT-10` cannot reach the lot guard | FIXED | **CONFIRMED-FIXED** — the `P-LOT-6` tuple does reach it: aggregate backed ⇒ `recordSale`'s guard passes (`WeightedAverageCostService.php:457`, `newQty = 0` is not `< 0`), FEFO's predicate excludes the lot (`FEFOInventoryService.php:270`, `expiry_date >= ?` bound to `now()->toDateString()` at `:258`), shortfall throws at `:341`, `InsufficientBatchStockException extends \DomainException` (`:16`) message `:28`, mapped 422 `INVALID_STATUS_TRANSITION` at `DeliveryNoteController.php:595-596`. `W2-LOT-10b` correctly labelled: `:457-467` → first arm `DeliveryNoteController.php:581`, `ERROR_CODE = 'INSUFFICIENT_STOCK'`. Residues → G3-C1-06/07 |
| **G2-C1-04** [MAJOR] ~9 unfixtured rows | FIXED | **PARTLY** — 11 SKUs + POs landed and every row I named in r2 now carries one (`W2-IDEM-2b`/`PO-X2`, `W2-IFIRST-3`/`PO-IF1`, `W2-PRICE-6`/`PO-P6`, `W2-MATCH-2`/`PO-M2`, `W2-REV-7`/`PO-R5`, `W2-EDGE-2`/`PO-E2`, `W2-SEC-2`/`PO-c2A`+`P-c2-HP1/2/3`, `W2-LAND-9`/`PO-c2L9`). **But** the new reuse list admits three mutating rows (**G3-C1-02**) and `W2-EDGE-11` is still unfixtured (**G3-C1-03**) |
| **G2-C1-05** [MAJOR] `W2-IDEM-6`'s fixture consumed by `W2-PART-8` | FIXED | **CONFIRMED-FIXED** for the fixture (`PO-X6` own chain, `124.950` re-derived ✔). New gap on the payment amount → **G3-C1-04** |
| **G2-C1-06** [MINOR] 422/500 arms inverted | FIXED | **CONFIRMED-FIXED** — `PurchaseOrderController.php:857` `catch (\DomainException)`, `:858` 422 `GOODS_RECEIPT_FAILED`, `:859` `catch (\RuntimeException)`, `:860-865` 500 `CONFIGURATION_ERROR`. Corrected in `W2-LOT-8` (`02:236`), `W2-EDGE-11` (`:526`) and Tolerances (`:549`) |
| **G2-C1-07** [MINOR] three citation drifts | FIXED | **CONFIRMED-FIXED** — (a) `ReceiptBatchCostAllocator.php:55-56` is `$unitBasis`/`$receivedValue`, `:58-59` the pushes ✔; (b) `GoodsReceiptService.php:193` is the `No items to receive.` throw in `createDraft` ✔; (c) `inventory_batch_stock` `batch_id`/`location_id` at `:21-22`, `unique_batch_per_location` at `:36` ✔ |
| **G2-C1-08** [MINOR] LOT-6 "draws nothing" | FIXED | **CONFIRMED-FIXED** as an outcome; see G3-C1-06 for the duplication and the unpinned header |
| **G2-C1-09** [MINOR] LAND-7 hedge + `match_status` | FIXED | **CONFIRMED-FIXED** — hedge deleted, `match_status = price_variance` added (`02:337`); the two refusal conditions cited (`SupplierInvoicePostingService.php:488-495`, `:505-514`) are the right pair |
| **G2-C1-10** [MINOR] fixture-table drift | FIXED | **CONFIRMED-FIXED** (`P-LAND-4` no longer claims LAND-10; `P-OVER-1` names `PO-U2`; `PO-H` declared as an exception; `01:293` enumeration completed). Residue → **G3-C1-10** |
| **G2-C1-11** [MINOR] LOT-9/10 location unpinned | FIXED | **CONFIRMED-FIXED for LOT-9 and LOT-10**; not applied to `W2-LOT-6` or `W2-LOT-10b` → **G3-C1-06/07**. Mechanism re-verified: `DeliveryNoteService.php:309-313` silent `continue`, FEFO is the `elseif` at `:380-388`, `partner_id` required `CreateDocumentRequest.php:66-70` (✔ as cited) (no partner-**type** constraint anywhere on the store path, so `SUP-A` is producible — verified `DeliveryNoteController::store:395`) |
| **G2-C1-12** [MINOR] EDGE-12's sales invoice | FIXED | **CONFIRMED-FIXED** — the LOT-9 delivery note replaces it (`02:527`) |
| **G2-C1-13** [MINOR] c2 `non_registered` for the whole run | FIXED | **CONFIRMED-FIXED** — S-4 (`02:61`) + ordering constraint (i) (`:535`); no c2 row asserts a recoverable-VAT figure |
| **G2-C1-14** [MINOR] VAT-3 re-uses PO-A ambiguously | FIXED | **CONFIRMED-FIXED** — restated as "read back `PO-A`" (`02:350`) |

## Reviewer conflict — `document_lines.allocated_costs`: **ruling for the author (and against my own r1 G1-C1-16)**

Opened the migration, the cast and the pin:

- `database/migrations/tenant/2026_05_30_000000_widen_wac_cost_columns_to_scale_6.php:60-63` — `'document_lines' => [['allocated_costs', 19, 6], ['landed_unit_cost', 19, 6]]`, applied by the `ALTER COLUMN … TYPE decimal(19, 6)` loop at `:75-82` (SQLite no-ops at `:70-73`).
- `apps/api/app/Modules/Document/Domain/DocumentLine.php:156` — `'allocated_costs' => 'decimal:6'`.
- `tests/Feature/Inventory/LandedCostBcmathTest.php:330-331` — `assertSame('20.000000', (string) $reloadedA->allocated_costs);` / `assertSame('10.000000', …)`. My r1 quoted `:298-300`, which is that test's **docblock**.
- `LandedCostService::allocatePositiveShares:303-316` does compute the share at `$scale` (TND ⇒ 3) and `:264` assigns it straight through — both halves are true; the read-back is the 6-dp one, from the column and from the cast alike. Column default is `0` NOT NULL (`2025_12_02_064936_…:15`), so `W2-HP-2`'s `0.000000` is also right.

**Ruling: `20.000000` / `10.000000` / `30.000000` / `0.000000` (6 dp) is correct. My r1 G1-C1-16 is WITHDRAWN and my r2 "CONFIRMED-FIXED" of the 3-dp figure was an error.** The r3 text — stating both facts together and comparing via `assertMoneyEqual` — is the right resolution.

## Numbers re-derived (all `[r3]`)

| Item | Spec figure | My derivation | Verdict |
|---|---|---|---|
| `allocated_costs` (HP-2, LAND-1, LAND-4, LAND-8) | `0.000000` / `20.000000`+`10.000000` / `30.000000` / `30.000000` | computed at scale 3 (`LandedCostService.php:303-316`), stored in `decimal(19,6)` (`…scale_6.php:60-63`), cast `decimal:6` (`DocumentLine.php:156`), pinned `LandedCostBcmathTest.php:330-331`; default `0` NOT NULL | ✅ exact |
| `W2-IDEM-6` `balance_due 124.950` | `124.950` | `10.0000 × 10.500 = 105.000`; 19 % ⇒ `19.950`; total `124.950` — identical shape to `PO-C` | ✅ exact (but see G3-C1-04) |
| `W2-LOT-10b` refusal | 422 `INSUFFICIENT_STOCK` | `5.0000 − 6.0000 = −1.0000 < 0` ⇒ `WeightedAverageCostService.php:457` throws `InsufficientStockForFulfilmentException` (`ERROR_CODE` `:60`) ⇒ first catch arm `DeliveryNoteController.php:581` | ✅ exact |
| `W2-LOT-10`(a) refusal | 422 `INVALID_STATUS_TRANSITION`, `Insufficient batch stock to fulfill atomic consume. Shortfall: <n>` | aggregate `newQty = 0` passes `:457`; FEFO candidate predicate `(b.expiry_date IS NULL OR b.expiry_date >= ?)` (`FEFOInventoryService.php:270`) excludes the 2020 lot ⇒ `shortfall = full qty` ⇒ throw `:341`, message `InsufficientBatchStockException.php:28`, `\DomainException` arm `DeliveryNoteController.php:595-596`; rollback by `DB::transaction` `DeliveryNoteService.php:95` | ✅ exact |
| Policy defaults vs the r3 raw body | `received / three_way / warn / 2.00 / 1.000 / false / false / true` | `ProcurementPolicy::defaultForVertical:99-117` — byte-identical, so the restore genuinely returns the vertical default values (only `preset` differs, see G3-C1-09b) | ✅ exact |
| Scenario count | 154 / 21 classes | 8+6+5+8+5+4+14+6+6+7+7+11+5+4+6+6+8+7+14+5+12 = **154**; per-class rows match each header (LOT = 13 + `10b` = 14; PRICE = 7; IFIRST = 6; IDEM = 8) | ✅ exact |
| GR-IR `posted` filter premise | Draft committed, posted outside | `GeneralLedgerService.php:2099` `'status' => JournalEntryStatus::Draft`, `:2127` `return $entry->load('lines')` inside the inner transaction, `:2134` post afterwards; `JournalEntryStatus::Posted = 'posted'` (`:10`) | ✅ exact |
| WDIL-4 arm (c) premise | `movement_id` nullable | `2026_07_04_100000_…:48` `uuid('movement_id')->nullable()`, `:49` `free_movement_id` nullable, partial uniques `:61-70`; `uniq_je_source_procurement` covers only `supplier_invoice`/`supplier_credit_note` (`2026_06_26_120000_…:45-47`) — so the r3 caveats on WDIL-1(c)/WDIL-2/IDEM-5 are correct | ✅ exact |

**Also re-verified, non-numeric:** `POST /roles` is `Identity/routes.php:60` ✔ and the membership block is `UserController.php:241-249` with `'company_id' => $companyId` at `:243` ✔; the invoice-first approval gate passes for the admin (`SupplierInvoicePostingService::assertInvoiceFirstApproval:673-698` requires `supplier-invoices.approve-invoice-first`, which `admin` holds via `Permission::all()` at `RolesAndPermissionsSeeder.php:545`), so `W2-IFIRST-4`'s "post as admin succeeds" holds under `invoice_first_requires_approval: true`; `stock_levels` partial uniques `stock_levels_non_variant` / `stock_levels_with_variant` at `2026_06_02_100005_…:52-57` ✔ (the r3 variant predicates in the LOC-5 query are correct).

---

## What to fix before merge

Drop `preset:null` from all four `PUT /procurement-policies` bodies (G3-C1-01 — the r2 BLOCKER is otherwise still live); re-word the reuse list to exclude *writers* and fixture `W2-OVER-5(b)`, `W2-SEC-5`, `W2-IDEM-7`; name a **batch-tracked** PO for `W2-EDGE-11`; state `W2-IDEM-6`'s first payment amount (≤ `24.950`) and `W2-IFIRST-4`'s invoice line (`10.0000 @ 10.500`); then the five MINORs, and this is ACCEPT.
