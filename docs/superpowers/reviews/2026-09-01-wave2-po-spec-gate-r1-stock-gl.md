# Wave-2 PO scenario spec — adversarial gate r1 (lens: stock↔GL seam)

**Reviewer:** `stock-gl-interaction-reviewer` (Opus) · **Date:** 2026-09-01 · **Worktree read:** `.worktrees/L-po-flow` @ `c130ba8d2` (dev `62964e5cc` + research appendices)
**Under review:** `docs/superpowers/audits/2026-09-01-wave2-po-flow/02-scenario-matrix.md` (rev 1) with `01-research.md` + `01a`–`01d`.
**Method:** every figure and every oracle below was re-derived by opening the cited file at the cited line in this worktree. I did not accept a single spec citation on its own authority.

## VERDICT: CHANGES-REQUIRED

The arithmetic is, with two exceptions, **excellent** — I re-derived 14 money expectations from `DocumentTotalsCalculator`/`normalizePurchaseLine`, `TaxCalculationService`, `LandedCostService`, `ReceiptBatchCostAllocator`, `WeightedAverageCostService` and `GeneralLedgerService` and 12 came out byte-identical (table at the end). What blocks the gate is not the numbers: it is that **four of the spec's "where did it land" assertions cannot execute or cannot fail**, and they are exactly the four that guard the stock↔GL seam. A spec whose flagship detector is decorative gates nothing.

---

## Findings

### G1-S1-01 [BLOCKER] — W2-WDIL-4's detector cannot detect the defect it was chosen for

**Spec row:** `W2-WDIL-4` — "run `php artisan accounting:check-cogs-coverage` and require exit 0. ⚠ Because the GR-IR listener swallows every throwable (F-W2-03), a missing entry is silent at HTTP level — **this detector is the only signal**".

**Code:** `apps/api/app/Console/Commands/CheckCogsCoverageCommand.php`
- The goods-receipt arm (`:431-473`) LEFT JOINs `stock_movements` twice (`paid_movement`, `free_movement`) and reports D-f when a receipt line produced **no stock movement**. It never touches `journal_entries`.
- The only arm that reads `journal_entries` is D-a (`:192-213`), and it is filtered to `whereIn('reason', $costedExitReasons)` where `$costedExitReasons` is built from `MovementReason` cases whose `glCounterFamily()` is `Cogs` or `Shrinkage` (`:169-179`). A purchase receipt movement is written by `WeightedAverageCostService::recordPurchase` with **no `reason` key at all** (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:270-286`) — it is an inbound, not a costed exit. It can never enter D-a.
- D-a additionally excludes anything younger than two hours (`:203` `->where('occurred_at','<=', now()->subHours(2))`), so a same-run receipt is out of scope even if the reason matched.

**Therefore:** there is **no detector anywhere** for a goods receipt whose GR-IR entry is missing. `PostGrIrOnGoodsReceipt::handle` catches `\Throwable` and only logs (`apps/api/app/Modules/Accounting/Listeners/PostGrIrOnGoodsReceipt.php:41-52`, verified verbatim), the PO-receive path never passes `failClosedGrir` (`GoodsReceiptService.php:203`, default `false`; only `StandaloneReceiptService.php:134` passes `true`), and the HTTP response is a clean 200. **Stock lands on the balance sheet with no 408 accrual and nothing on earth notices.**

**Other side of the seam, verified:** the stock side is fine — `recordPurchase` writes the `stock_movements` row, the `stock_levels` delta and the `products.cost_price` blend inside one transaction with the product row locked LAST (`:200-300`), and `goods_receipt_lines.movement_id` is written non-null (`GoodsReceiptService.php:700-702`). It is only the GL consequence that can silently vanish.

**Fix wording:**
1. Replace the `check-cogs-coverage` assertion in W2-WDIL-4 with an explicit orphan query, run after every receipt:
```sql
SELECT l.id AS receipt_line_id, l.movement_id
FROM goods_receipt_lines l
JOIN goods_receipts r ON r.id = l.goods_receipt_id
LEFT JOIN journal_entries je
       ON je.source_type = 'goods_receipt' AND je.source_id = l.movement_id
WHERE r.company_id = :company AND r.status = 'posted'
  AND l.received_qty > 0 AND je.id IS NULL;   -- must return 0 rows
```
   plus the same for `free_movement_id` **inverted** (a free leg posts `unitCost = '0'`, and `createGoodsReceiptGrIrEntry` returns null when `amount <= 0` — `GeneralLedgerService.php:2069-2072` — so a free leg legitimately has NO entry; assert that absence rather than leaving it ambiguous).
2. Raise **F-W2-03 from P1 to P0** and say so in §5: the register's own severity rubric ("P0 = money/stock corruption reachable by an ordinary operator") is met — a transient closed-period or account-resolution failure inside `Account::findByPurposeOrFail` (`GeneralLedgerService.php:2074-2075`) capitalises inventory with no liability, and the register currently mitigates the severity with a detector that does not exist.
3. Delete the sentence "this detector is the only signal" — it is false and it is load-bearing for the register's severity.

---

### G1-S1-02 [BLOCKER] — every batch/lot SQL assertion names tables that do not exist

**Spec rows:** `W2-HP-4` ("3 `batch_stocks` rows"), `W2-PART-4` ("SQL on `batch_stocks`"), `W2-LOT-2` ("`batches` row created … `batch_stocks` 6.0000 … SQL on `batches`/`batch_stocks`/`stock_levels`"), `W2-LOT-4`, `W2-LOT-6` ("SQL on `batches.is_expired`"), `W2-LOT-7` ("SQL on `batches` (one row)"), `W2-WDIL-1` ("Σ `batch_stocks` delta").

**Code:** the tables are `product_batches` (`apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:13`; model `apps/api/app/Modules/BatchExpiry/Domain/Entities/Batch.php:19` `protected $table = 'product_batches'`) and `inventory_batch_stock` (`…/2026_01_05_150001_create_inventory_batch_stock_table.php:14`; model `BatchStock.php:13`). There is no `batches` table and no `batch_stocks` table in `database/migrations/tenant/**`.

Worse for the invariant: `inventory_batch_stock` has **no `company_id`** — only `tenant_id`, `batch_id`, `location_id`, `quantity` (`:17-27`). Any per-company batch-invariant query must join `product_batches` to scope by company.

**Why this is a blocker, not a typo:** the batch invariant is the *only* assertion in the whole spec that tests the two independent writers against each other. `WeightedAverageCostService` writes `stock_levels` (`:290-291`); `BatchStockService::receiveBatchStock` writes `inventory_batch_stock` + `inventory_batch_movements` and its docblock states outright "**Does NOT touch aggregate stock levels** — that's handled by WAC service" (`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:389-392`). Two writers, one physical truth, and the reconciliation query as written errors out.

**Fix wording:** rename throughout, and give W2-WDIL-1 the real query:
```sql
SELECT ibs.location_id,
       SUM(ibs.quantity)  AS batch_qty,
       sl.quantity        AS level_qty
FROM inventory_batch_stock ibs
JOIN product_batches pb ON pb.id = ibs.batch_id
JOIN stock_levels sl    ON sl.product_id = pb.product_id
                       AND sl.location_id = ibs.location_id
                       AND sl.company_id  = pb.company_id
WHERE pb.company_id = :company AND pb.product_id = :product
GROUP BY ibs.location_id, sl.quantity;   -- batch_qty must equal level_qty per location
```
Run it after **both** tranches of W2-LOC-1/LOC-2 (two locations, two lots) — that is the shape that catches a divergence.

---

### G1-S1-03 [BLOCKER] — W2-HP-5's evidence query returns zero rows and the row passes vacuously

**Spec row:** `W2-HP-5` — Evidence column: "SQL query 4 of `01c §8` adapted to `goods_receipt`".

**Code:** query 4 (`01c-research-supplier-invoice-payment.md:451-463`) joins `journal_entries je ON je.source_type='supplier_invoice' AND je.source_id = d.id`. For a goods receipt, `source_id` is the **stock movement id**, never a document id — `GeneralLedgerService::createGoodsReceiptGrIrEntry` is called with `movementId` and writes `'source_id' => $movementId` (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2044-2050`, idempotency probe `:2052`, insert `:2101`). "Adapting" query 4 by swapping the `source_type` literal produces an empty join, and every aggregate assertion over it ("each Dr Inventory / Cr 408", "Σ 408 credit = 96.000", "every entry balances") is vacuously true on an empty set.

**Fix wording:** give W2-HP-5 its own query, joined through the receipt lines:
```sql
SELECT l.po_line_id, je.entry_number,
       SUM(CASE WHEN a.system_purpose='inventory' THEN jl.debit ELSE 0 END)                    AS dr_37,
       SUM(CASE WHEN a.system_purpose='goods_received_not_invoiced' THEN jl.credit ELSE 0 END) AS cr_408,
       SUM(jl.debit) - SUM(jl.credit) AS imbalance,       -- must be 0
       BOOL_AND(jl.partner_id IS NULL) AS partner_always_null   -- must be true
FROM goods_receipt_lines l
JOIN goods_receipts r  ON r.id = l.goods_receipt_id
JOIN journal_entries je ON je.source_type='goods_receipt' AND je.source_id = l.movement_id
JOIN journal_lines jl   ON jl.journal_entry_id = je.id
JOIN accounts a         ON a.id = jl.account_id
WHERE r.company_id = :company AND r.id = :receipt
GROUP BY l.po_line_id, je.entry_number;
```
Assert `COUNT(*) = 3` explicitly — an empty result must FAIL the row, not pass it. (I verified the expected shape at `GeneralLedgerService.php:2074-2075` accounts, `:2107-2125` legs, `partner_id => null` on both legs, and no VAT leg anywhere in `2044-2134`.)

---

### G1-S1-04 [MAJOR] — W2-LAND-4's oracle is dead code; the real gap is wider than the spec says

**Spec row:** `W2-LAND-4` — Oracle cites `LandedCostService.php:452-458`; Expected says "the cost is accepted after a partial receipt (`payload.goods_received_at` is written **`null`**, so `isset()` is false)".

**Code:** `LandedCostService::canModifyCosts` (`apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:452-458`) is real and does check `! isset($payload['goods_received_at'])` — but `grep -rn "canModifyCosts" app/` returns **exactly one hit: its own declaration**. It has zero callers. `DocumentAdditionalCostController::store` (`apps/api/app/Http/Controllers/Api/DocumentAdditionalCostController.php:41-73`) validates `cost_type`/`amount`/`expense_document_id` and inserts. There is **no lifecycle guard of any kind** on adding a landed cost.

So the *observation* (cost accepted after a partial receipt) is right and the *causal claim* is fabricated. Two consequences the spec misses:
1. A cost added after the PO reaches **`Received`** is also accepted — and `reallocateCosts` only ever runs inside `GoodsReceiptService::post` (`:227-231`), so there is no later post to pick it up. The cost sits in `document_additional_costs`, never reaches `landed_unit_cost`, never reaches WAC, never reaches 408, and `GET …/landed-cost-breakdown` (which sums unfiltered, `DocumentAdditionalCostController.php:121`) will happily show it. **Missing scenario M2.**
2. §1 rows 7-8 of `01-research` imply the controller lives in the Document module; it is at `app/Http/Controllers/Api/`, outside the hexagon. Worth a line in §5.

**Fix wording:** re-cite the oracle as `DocumentAdditionalCostController.php:41-73` (no guard) and add the post-`Received` arm as `W2-LAND-7`. Record `canModifyCosts` as dead code in §5 (P3) so a fix lane does not "wire up the existing guard" and silently change LAND-4's behaviour under the running suite.

---

### G1-S1-05 [MAJOR] — W2-VAT-4's numbers and its oracle both describe a code path that was replaced; F-W2-20 is not reproducible

**Spec row:** `W2-VAT-4` — "per line `bcround(0.335 × 0.19, 3) = 0.064` ⇒ Σ tax `0.192` … the per-rate bucket view gives `1.005 × 0.19 = 0.19095 → 0.191` — a **`0.001` drift**". Oracle `SupplierInvoicePostingService.php:415-425`.

**What is actually at that line.** `SupplierInvoicePostingService.php:415-416` is
```php
$derived     = $this->postedLineTaxSnapshotBuilder->build($supplierInvoice);
$divergences = $this->postedLineTaxSnapshotBuilder->divergences($supplierInvoice, $derived);
```
and the comment block immediately above it (`:381-392`) says in terms: *"the snapshot is DERIVED FROM THE PERSISTED LINE AMOUNTS (`PostedLineTaxSnapshotBuilder`), never recomputed through `TaxCalculationService::calculateDocumentTaxes()` … the engine truncates once per rate bucket, so the two disagree … the ledger wins."* `PostedLineTaxSnapshotBuilder::build` (`apps/api/app/Modules/Taxation/Domain/Services/PostedLineTaxSnapshotBuilder.php:148-182`) sets bucket base = Σ `line_total` and bucket VAT = Σ `recoverable_tax_amount`. The exact drift the spec is hunting was engineered out; the cited line is the fix, not the bug.

**Re-derivation.** `CreateSupplierInvoiceService` (`apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:88-101`) computes at working scale 7 then `CurrencyScale::bcround` (round-half-away-from-zero, `apps/api/app/Shared/Domain/CurrencyScale.php:172-198`): `lineSubtotalHp = 0.3350000` → `0.335`; `lineTaxHp = 0.0636500` → **`0.064`**. Σ = subtotal `1.005`, `line_tax_amount` `0.192`, total `1.197`. `divergences()` checks (`PostedLineTaxSnapshotBuilder.php:285-310`): (1) `line_tax_amount + stamp == tax_amount` → `0.192 + 0.000 == 0.192` ✅; (2) Σ `line_total` == `subtotal` → `1.005 == 1.005` ✅; (3) Σ `recoverable_tax_amount` == line VAT ✅. **It posts, and Σ `document_tax_details.tax_amount` == `documents.tax_amount` == `0.192` exactly.** Outcome (a), deterministically.

The spec's alternative figure is also wrong for the engine it attributes it to: `TaxCalculationService`'s no-discount branch does NOT multiply the summed base by the rate — it uses the per-line scale+1 accumulator rounded once (`apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:151-160`, `:206-209`), and `CurrencyScale::bcformat` **truncates** (`CurrencyScale.php:107-108` — `bcadd($str,'0',$scale)`). 3 × `bcmul(0.3350, 0.190000, 4)` = 3 × `0.0636` = `0.1908` → `0.190`, not `0.191`.

**Fix wording:** rewrite W2-VAT-4 as a positive assertion — "posts; Σ `document_tax_details.tax_amount` == `documents.tax_amount` == `documents.line_tax_amount` == `0.192` at scale 3; `Dr 4456` on the clearing entry == `0.192`". Downgrade **F-W2-20 to P3 / NOT-REPRODUCIBLE-VIA-HTTP** unless the register can name a write path that mutates `line_total` or `recoverable_tax_amount` after creation (I found none: `link-receipts` rewrites `source_line_id` + snapshot columns only, `match` writes `match_status` only).

---

### G1-S1-06 [MAJOR] — three SQL column names in the spec do not exist

1. `document_tax_details.amount` (W2-VAT-4 Expected, W2-WDIL-2 "Σ document_tax_details.amount") → columns are **`tax_base`** and **`tax_amount`** (`database/migrations/tenant/2025_12_30_102000_create_document_tax_details_table.php:21-23`, widened to `(15,3)` by `2026_03_23_100000_widen_missed_monetary_columns_to_scale_3.php:22-25`).
2. `repository_movements.repository_id` (inherited from `01c §8` query 5, used by W2-PART-7 and W2-WDIL-5) → the column is **`payment_repository_id`** (`database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:18`). `01c:476` marks this UNVERIFIED and the matrix (`W2-WDIL` preamble) defers verification "before the first run". **It is statically verifiable now** and a gate is the place to fix it, not the browser leg. For the record, the rest of query 5 is correct: `direction` is `char(3)` in/out (`:19`), `amount` `decimal(15,3)` (`:20`), `balance_after` (`:22`).
3. `products.selling_price` (W2-HP-6, F-W2-25) → the column is **`sale_price`** (`WeightedAverageCostService.php:302` reads `$product->sale_price`).

I re-verified the ones the spec got right so the reviewer's word is worth something: `journal_entries.{source_type,source_id,company_id,status}`, `journal_lines.{account_id,partner_id,debit,credit}`, `accounts.system_purpose` with literals `inventory` / `supplier_payable` / `goods_received_not_invoiced` / `vat_deductible` / `purchase_stamp_duty` / `purchase_price_variance_income` (`SystemAccountPurpose.php:52,56,59,85-86,115,123`), `JournalEntryStatus::Posted = 'posted'` (`JournalEntryStatus.php:10`), `DocumentType::PurchaseOrder='purchase_order'` / `SupplierInvoice='supplier_invoice'` (`DocumentType.php:11,17`), `goods_receipts.receipt_number` nullable + partial unique (`2026_07_06_110000_goods_receipt_draft_columns.php:15-28`), `goods_receipts.location_id` exists (`2026_07_16_120000_add_location_id_to_goods_receipts.php:15`), `UserStatus::Active='active'` (`UserStatus.php:12`).

---

### G1-S1-07 [MAJOR] — W2-REV-6's assertion is wrong in shape: PATCH nulls `landed_unit_cost`, it does not stale it

**Spec row:** `W2-REV-6` — "**`document_tax_details` and `document_lines.landed_unit_cost` are STALE** … Compare `landed_unit_cost` against the new `line_total` — they must disagree".

**Code:** `PurchaseOrderController::update` deletes every line (`:559-563`) and recreates them with `'landed_unit_cost' => $lineData['landed_unit_cost'] ?? null` (`:611`). `normalizePurchaseLine` only populates that key `if ($mode === PriceEntryMode::Total || bccomp($freeQuantity,'0',4) > 0)` (`:125-127`). **In Unit mode the recreated lines carry `landed_unit_cost = NULL`**, so the proposed comparison is against NULL and will be reported as "disagree" for the wrong reason. (At the next receipt `processReceiptLines` falls back to `$line->landed_unit_cost ?? $line->unit_price` — `GoodsReceiptService.php:526` — and `reallocateCosts` re-derives it anyway because `hasAllocatedCosts` is still true, `:227-231` + `LandedCostService.php:442-447`.)

The genuinely stale artefacts after a PATCH on a Confirmed PO are: `document_tax_details` (nothing deletes it — only `snapshotTaxDetails` does, `TaxCalculationService.php:509-512`, and PATCH never calls it) and `documents.payload.{costs_allocated_at, costs_allocated_total, non_recoverable_tax_total}` (`LandedCostService.php:230-236`).

**Fix wording:** assert `landed_unit_cost IS NULL` on every recreated Unit-mode line, and assert `document_tax_details` still carries the **pre-PATCH** bases while `documents.subtotal/tax_amount/total` carry the post-PATCH ones — that is the visible corruption. F-W2-06's P1 stands on the tax-snapshot half alone.

---

### G1-S1-08 [MAJOR] — second-of-everything is decorative for every money class

`01-research §3` closing paragraph and the matrix preamble both claim "every mutating class carries a second-company arm, a second-location arm and a re-run arm". The matrix delivers 6 rows in `W2-SEC` against 21 classes, and the cross-reference it promises ("enumerated in class W2-SEC and cross-referenced from each class's own rows") does not exist — I grepped every class table and no LAND / PRICE / LOT / DRAFT / MATCH / VAT / TOT / DISC row carries a company-2 or location-2 arm.

The three that matter for this seam and are absent:
- **Second-company landed cost.** `LandedCostService` splits through `ProportionalMoneyAllocator` and the GL resolves `Account::findByPurposeOrFail($companyId, …)` per company (`GeneralLedgerService.php:2074-2075`, `:2228-2234`). No scenario books a landed-cost PO in company 2, so a per-company chart-of-accounts gap is invisible.
- **Second-location batch invariant.** `inventory_batch_stock` is keyed `(batch_id, location_id)` with **no `company_id`** (`2026_01_05_150001…:17-24`) — precisely the shape convention 09 exists to catch. W2-LOC-1/2 split a receipt across two locations but never reconcile `inventory_batch_stock` per location against `stock_levels`.
- **Re-run of supplier-invoice create.** W2-IDEM covers PO create, receive, confirm, invoice **post** and payment — but not `POST /supplier-invoices` twice with the same body. `CreateSupplierInvoiceService:70` mints `SI-YYYY-NNNN` unconditionally at creation, so two identical drafts burn two numbers; W2-MATCH-5 gets there sideways but only for the same-receipt-slice case.

**Fix wording:** either add the three arms above as explicit rows, or drop the "every mutating class" claim from both documents and state the actual coverage. As written the convention-09 assertion in §3 is unearned.

---

### G1-S1-09 [MAJOR] — F-W2-01 (P0) is only probed by API; the operator-reachable vector has no scenario

The finding is **earned** — I verified it end to end: `ReceiveGoodsRequest` has no idempotency key of any kind (`apps/api/app/Modules/Document/Presentation/Requests/ReceiveGoodsRequest.php:26-39`); `PurchaseOrderController::receive` (`:766-867`) has no dedupe; the only ceiling is `assertQuantitiesWithinRemaining` (`GoodsReceiptService.php:309-332`); and the GL idempotency key is the **movement** id (`GeneralLedgerService.php:2052`), which differs per attempt. Two POSTs of `{quantities:{line:'4.0000'}}` against a remaining of 10 give 2 receipts, 2 movements, 2 WAC blends and 2 GR-IR entries. Both sides of the seam duplicate together, which is at least internally consistent — but the 408 accrual and the inventory value are both double what the supplier shipped.

**What is missing:** `W2-IDEM-2` is `**API**`-driven only. A P0 whose severity rests on "an ordinary operator can reach it" must demonstrate the operator's vector: a double-click / network retry on the receive dialog's Save button. Add `W2-IDEM-2b` (**UI**): submit the dialog, and while the request is in flight fire a second identical POST captured from `page.on('request')`; assert the resulting `stock_levels`, `stock_movements`, `goods_receipts` and `journal_entries` counts. If the dialog's disabled-while-submitting state makes it unreachable in the browser, say so and re-grade F-W2-01 to P1 with the reason — do not leave the P0 asserted.

---

### G1-S1-10 [MAJOR] — a validation hole on the receive path that the register does not carry

`ReceiveGoodsRequest.php:28`:
```php
'quantities.*' => ['numeric', 'regex:/^-?\d+(\.\d{1,4})?$/'],
'free_quantities.*' => ['numeric', 'regex:/^-?\d+(\.\d{1,4})?$/'],
```
Both admit a **leading minus**. Contrast `received_unit_prices.*` at `:36` (`/^\d+(\.\d{1,3})?$/`, correctly no `-?`) and `CreateDocumentRequest.php:119` (`gt:0` + no `-?`). A negative receive quantity therefore passes validation and is then silently swallowed by `if (bccomp($qtyToReceive,'0',4) <= 0 && bccomp($freeQtyToReceive,'0',4) <= 0) continue;` (`GoodsReceiptService.php:490-492`); an all-negative payload 422s with the misleading "No items to receive. Please specify quantities to receive." (`:720`).

No scenario covers it (W2-EDGE-3 tests negatives on the **create** path only; W2-OVER-4 tests 5 dp). Add it as `W2-OVER-5` and add the finding to §5 (P2 — silent no-op on a mutating money endpoint, and a regex that diverges from its sibling three lines away).

---

### G1-S1-11 [MAJOR] — W2-DISC-2 / W2-DISC-3 cite an oracle that is a docblock

Both rows cite `PurchaseOrderController.php:743-744` for "both discounts persisted". Lines 740-765 of that file are the closing of `confirm()` and the `receive()` docblock. The actual writes are `:465-466` (`store`) and `:607-608` (`update`). Re-cite.

While there: the matrix's premise for DISC-2 is otherwise correct and I verified it — `DocumentLine::computeLineTotal` applies percent and ignores amount (`apps/api/app/Modules/Document/Domain/DocumentLine.php:290-295`), nothing forbids both, and `DiscountPolicyDocumentValidator` does **not** apply to purchase orders (its `POLICY_ROUTES` allowlist is `invoices.store|update`, `orders.store|update` — `apps/api/app/Modules/Document/Presentation/Validation/DiscountPolicyDocumentValidator.php:23-28`, early-return at `:37-39`). So DISC-1/DISC-4's discount figures are safe from a discount-policy refusal; worth stating in the fixture notes so nobody chases a phantom 422.

---

### G1-S1-12 [MAJOR] — W2-PERM's cashier fixture is under-specified in the one way that will sink the class

`W2-SETUP-6` creates the cashier via `POST /users {role:'cashier'}` then patches password + status in SQL. I verified the moving parts: `UserController::store` mints a random 32-char password and `UserStatus::PendingVerification` (`:218-229`), assigns the role under `setPermissionsTeamId($currentUser->tenant_id)` (`:232-234`), and creates a `UserCompanyMembership` for **the currently active company only** (`:237-241`). `UserStatus::Active = 'active'` (`UserStatus.php:12`), so the SQL literal is right.

Two gaps:
1. The membership is single-company. Any W2-PERM row that needs the cashier in **company 2** (none are written, but W2-SEC-2 runs the whole flow there) will 403 for a membership reason, not a permission reason — and the evidence would be misattributed. State the company the cashier belongs to.
2. The run plan mandates "serial, ONE shared page" while W2-PERM mandates a "second browser context". Those are in direct conflict as written. Name the mechanism (a second `browser.newContext()` with its own storage state, admin page untouched) or the class will silently run as admin and every "403" expectation will be measured against the wrong principal.

---

### G1-S1-13 [MINOR] — W2-VAT-2's "B13 measurement" is already answered by code, and the expected `tax_code` is wrong

The TN VAT rows list `applicable_document_types = ['TAX_INVOICE','FISCAL_RECEIPT','CREDIT_NOTE','DELIVERY_NOTE']` (`database/seeders/TunisiaTaxConfigurationSeeder.php:69-74`), `scopeForDocumentType` matches on `whereJsonContains` or an empty array (`apps/api/app/Modules/Taxation/Domain/Entities/TaxConfiguration.php:122-128`), and `calculateDocumentTaxes` resolves the document type from `fiscal_category` first (`TaxCalculationService.php:62`) — which for a PO and a supplier invoice is `NonFiscal`. So `$applicableTaxes` is **empty** for both, every rate bucket takes the UNCONFIGURED branch (`:246-268`), and that branch's own comment states the V3 ruling: *"This also covers an explicit 0% rate with no matching TVA_EXEMPT-style config: the base-only row is still emitted, at zero tax."*

Two consequences: (a) B13 is **ALREADY**, not PARTIAL/DEFER — W2-VAT-2 should assert the 0% row exists with `tax_base = 20.000, tax_amount = 0.000`, not "record whichever"; (b) the persisted rows carry `tax_code = 'UNCONFIGURED'` and `tax_name = 'VAT 19%'`, **not** `TVA_19` — a query keyed on the seeded code returns nothing. Same emptiness is what makes the expected **timbre `0.000`** correct on both documents (verified: the TN stamp rows list only `TAX_INVOICE` / `FISCAL_RECEIPT` / `CREDIT_NOTE`, `TunisiaTaxConfigurationSeeder.php:88-125`), so that half of the fixture note is sound.

---

### G1-S1-14 [MINOR] — W2-LOT-8's outcome is determinable now; leaving it open lets the row pass either way

"a `\DomainException` maps to 422, any other exception class surfaces as **500**". `MissingVariantException extends \DomainException` (`apps/api/app/Modules/BatchExpiry/Domain/Exceptions/MissingVariantException.php:16`) and `receive()` catches `\DomainException` → `validationErrorResponse('GOODS_RECEIPT_FAILED', …)` = 422 (`PurchaseOrderController.php:855-856`); the 500 arm is the `\RuntimeException` catch (`:857-864`). Assert **422** with the message. A row that accepts either outcome cannot fail — and the run plan's "zero 5xx" tolerance would contradict itself if 500 were genuinely expected.

---

### G1-S1-15 [MINOR] — W2-SEC-5 will read NULL, not a location

`CreateSupplierInvoiceService::create` never writes `location_id` on the supplier-invoice `Document::create` (`:118-146` — the attribute is simply absent). `PaymentController` derives `attributionLocationId` from the first allocated document's `location_id` (`:645-654`), so for a supplier payment it resolves to **NULL every time**. F-W2-22's mechanism is real (the repository's location is never compared to anything) but its evidence shape is not "payments.location_id vs the repository" — it is "payments.location_id IS NULL and no comparison exists". Restate both the finding and the row.

---

### G1-S1-16 [MINOR] — citation drift in the matrix's own oracles

Spot-checked; the following are off by enough to send a reader to the wrong statement:
- `W2-HP-1` cites `PurchaseOrderController.php:414` for `currency` → it is `:431`; `01-research §1` row 1's "`location_id` via `LocationContext::resolveLocationId` `:411-414`" → `:415-418`.
- `W2-EDGE-3` cites `CreateDocumentRequest.php:120` for the quantity regex → quantity is `:119`, `free_quantity` `:120-122`, `unit_price` `:123`, `line_total` `:124`. `W2-TOT-5` cites `:125` as the 3-dp `line_total` ceiling → `:125` is `price_entry_mode`; `line_total` is `:124`.

Everything else I sampled landed exactly, and this deserves saying: `PurchaseOrderController.php:100-105,122`; `:398-412` (loop `:401-410`, `$total` `:412`); `PurchaseOrderService.php:127`, `:136-139`, `:142`, `:145`, `:90-109`; `GoodsReceiptService.php:309-332`, `:523-525`, `:556-567`, `:574`, `:666-668`, `:670`, `:677-678`, `:727-734`, `:795`, `:876-923`, `:928-942`, `:984-1008`, `:212-214`, `:254-263`; `LandedCostService.php:86-96`, `:230-236`, `:245-277` (the `'0'` at `:265`), `:345-351`, `:442-447`; `GeneralLedgerService.php:2044-2134`, `:2210-2216`, `:2253-2263`, `:2266-2276`, `:2279-2289`, `:2292-2313`, `:2316-2337`, `:2340-2348`, `:2352-2365`; `SupplierInvoiceMatcher.php:206-231`, `:255-261`, `:505-533`, `:541-576`; `PaymentController.php:645-654`, `:1078-1083`, `:1090-1092`, `:1098-1104`, `:1184-1195`; `ReceiveGoodsRequest.php:28`, `:33`, `:36`, `:39`; `DocumentStatus.php:19-25`, `:30-36`; `CreateDocumentRequest.php:211-213`. That is a high hit rate for a document this size.

---

### G1-S1-17 [MINOR] — the movement→GRN link is never asserted, and it is the only one there is

`stock_movements` carries no `goods_receipt_id`. `recordPurchase` writes `reference = $purchaseOrder->document_number`, `reference_type = 'Document'`, `reference_id = $purchaseOrder->id` (`WeightedAverageCostService.php:281-284` via the call sites at `GoodsReceiptService.php:568-580` and `:611-623`). W2-HP-4 asserts that correctly. But after two tranches both movements point at the *same* PO, and the **only** thing that ties a movement to its GRN is `goods_receipt_lines.movement_id` / `free_movement_id` (`GoodsReceiptService.php:700-702`). Add to W2-WDIL-1: every posted receipt line has non-null `movement_id` when `received_qty > 0`, every `movement_id` is distinct across the tenant, and every one resolves to a `stock_movements` row of type `Receipt`. Without it, a tranche can lose its audit trail and no row notices.

---

## Owner questions Q-1..Q-12 — genuine ruling vs already answered

| # | Verdict | Basis |
|---|---|---|
| **Q-1** short-close | **GENUINE** | B7 MISSING is code-confirmed: `Received` only on a full receipt (`GoodsReceiptService.php:727-734`), revert refused (`DocumentPostingService.php:605-607`), delete refused (`DocumentStatus.php:30-36`). No prior ruling found in `docs/superpowers/reviews/2026-08-3*` or the K-1 gate §E. |
| **Q-2** over-receipt policy | **GENUINE** (policy, not defect) | Hard block confirmed `GoodsReceiptService.php:309-332`. Framing as a deliberate-divergence candidate is correct. |
| **Q-3** bonus-unit costing | **GENUINE** | Free leg at `'0'` (`GoodsReceiptService.php:574`); `effective_unit_cost` computed and persisted reporting-only (`:696-700`, helper `:859-869`). WAC 8.333333 re-derived and confirmed. |
| **Q-4** match strictness | **NARROW IT** | The hard-qty / advisory-price split is already a deliberate, commented design (`SupplierInvoiceMatcher.php:206-231` vs `:255-261`) — do not re-ask whether it is intended. What *is* a ruling: the `2.00 %` **AND** `1.000` TND pair (`ProcurementPolicy::defaultForVertical:104-105`), which is an AND of both arms, so the 1.000 TND cap dominates on any line above 50 TND. Ask only that. |
| **Q-5** landed cost after partial | **GENUINE** | Re-derived: only `15.000` of `30.000` reaches inventory (see numbers table). |
| **Q-6** freight has no credit side | **GENUINE, but reframe** | `expense_document_id` is optional (`DocumentAdditionalCostController.php:44-56`) *and* there is no guard on **when** a cost may be added (G1-S1-04). The ruling must cover both or a fix lane will close half the hole. |
| **Q-7** supplier-invoice authorisation | **NOT A RULING — a confirmed defect** | `Procurement/Presentation/routes.php:99-120` gate store/match/post on `documents.update`; `Document/Presentation/routes.php:84-87` gates revert the same way; cashier holds `documents.update` (`RolesAndPermissionsSeeder.php:671`) and `payments.create` (`:682`); the permission's own seeder comment scopes it to "Media attachments + the coarse gate on /documents/auto-save" (`:121`). Ask only for the replacement permission names, not for confirmation that it is unintended. |
| **Q-8** receipt reversal | **GENUINE** (priority) | No cancel/reverse route (`Inventory/Presentation/routes.php:156-177`); draft hard-deleted (`GoodsReceiptController.php:139-158`). |
| **Q-9** supplier credit note | **GENUINE** (scheduling) | Service exists, no route — confirmed by `01c §9` and no controller reference. |
| **Q-10** supplier-payment refund | **BLOCKED ON EVIDENCE, not on the owner** | W2-EDGE-8 must run first. Move it out of the owner queue until measured; asking now invites a ruling on an unknown. |
| **Q-11** supplier defaults | **GENUINE** | B16/B39 MISSING confirmed. |
| **Q-12** confirmed-PO editability | **GENUINE** | `DocumentStatus::isEditable` returns true for `Confirmed` (`:19-25`). |

**Double-fix check.** F-W2-07 is correctly routed to K-1 task 4.2 (evidence only) ✓. F-SOE-2 / F-W2-02 was explicitly handed to wave 2 by the brief ✓. **One collision to flag:** `F-W2-08` (Total mode discards discounts) proposes lane `L-4`, and K-1 task 4.2 rewrites the same `normalizePurchaseLine` Total branch (`PurchaseOrderController.php:100-105`). Two lanes editing one 6-line block is exactly the double-fix the brief forbids. Fold L-4 into K-1 or make L-4 explicitly downstream of K-1's merge, and say so in §4.

---

## Missing scenarios

| id | Scenario | Why it belongs | Anchor |
|---|---|---|---|
| **M1** | After every posted receipt: SQL orphan check `goods_receipt_lines.movement_id` LEFT JOIN `journal_entries(source_type='goods_receipt')` — must be 0 rows for paid legs, and must be 1 row **absent** for every free leg | The only executable detector for F-W2-03. `check-cogs-coverage` does not cover it (G1-S1-01) | `PostGrIrOnGoodsReceipt.php:41-52`; `GeneralLedgerService.php:2069-2072` |
| **M2** | Add a landed cost **after** the PO reaches `Received`; assert it is accepted, `landed_unit_cost`/`allocated_costs` never change, WAC never changes, no 408 movement — and `GET …/landed-cost-breakdown` still shows it | No guard exists; `reallocateCosts` has no later post to run in | `DocumentAdditionalCostController.php:41-73`; `GoodsReceiptService.php:227-231` |
| **M3** | UI double-submit / in-flight retry of the receive dialog | The operator-reachable vector for the P0 F-W2-01 | `ReceiveGoodsRequest.php:26-39` |
| **M4** | `POST …/receive {quantities:{line:'-5.0000'}}` | Passes validation, silently skipped, misleading 422 on an all-negative payload | `ReceiveGoodsRequest.php:28`; `GoodsReceiptService.php:490-492` |
| **M5** | Per-location batch invariant after a 2-location, 2-tranche, batch-tracked receipt: `SUM(inventory_batch_stock.quantity) GROUP BY location_id == stock_levels.quantity` | The only test of the two independent physical writers | `WeightedAverageCostService.php:290-291` vs `BatchStockService.php:389-392` |
| **M6** | Second-company arm on **W2-LAND** (landed-cost PO confirmed + received in company 2), asserting the GR-IR entry resolves **company 2's** `inventory`/`goods_received_not_invoiced` account ids | Per-company account resolution is the C-27 shape; `Account::findByPurposeOrFail($companyId, …)` | `GeneralLedgerService.php:2074-2075`, `:2228-2234` |
| **M7** | Re-POST an identical `POST /supplier-invoices` body | Burns a second `SI-` number at creation; W2-IDEM covers PO/receive/confirm/post/payment but not this | `CreateSupplierInvoiceService.php:70` |
| **M8** | Assert `stock_movements.avg_cost_before` / `avg_cost_after` per movement across the W2-LOC-2 blend | The only per-movement audit of the WAC blend; W2-LOC-2 asserts the final `products.cost_price` but never the ledgered path to it | `WeightedAverageCostService.php:275-279` |
| **M9** | `POST /documents/{id}/additional-costs` against a **supplier invoice** or a sales-document id (verify `resolveDocument` type-scoping) | If it is type-blind, a landed cost can attach to a document nothing ever allocates | `DocumentAdditionalCostController.php:165+` (`resolveDocument`) — **I could not confirm the type filter; the spec should probe it** |

---

## Numbers I re-derived

Every figure below was recomputed from source. `bcmul`/`bcdiv`/`bcadd` truncate (`CurrencyScale::bcformat` → `bcadd($v,'0',$scale)`, `CurrencyScale.php:107-108`); `CurrencyScale::bcround` is round-half-away-from-zero (`:172-198`). TND scale 3, quantity scale 4, cost scale 6.

| Spec row | Spec figure | My re-derivation | Verdict |
|---|---|---|---|
| W2-HP-1/2/3 | subtotal `96.000`, tax `12.880`, total `108.880`, timbre `0.000` | store: 63.000+13.000+20.000; tax `bcmul(63.000,0.1900,3)=11.970` + `0.910` + `0` (`PurchaseOrderController.php:398-412`). confirm: `calculateTotal(4)` per line → 11.9700+0.9100 → `bcformat(…,3)` = 12.880; `applicableTaxes` empty ⇒ timbre 0 | **CONFIRMED** |
| W2-HP-4 | `cost_price` 10.500000 / 3.250000 / 4.000000 | zero prior stock ⇒ `newAvgCost = landedUnitCost` (`WeightedAverageCostService.php:236-253`) | **CONFIRMED** |
| W2-TOT-2 | stored `line_total 119.000`, `unit_price 11.900`, `landed_unit_cost 11.900000`, header `119.000 / 22.610 / 141.610` | FE `calculateTotalFromNetAmount(100.000,19)=119.000` (`DocumentLineEditor.tsx:152-155`, `:590-591`) → payload (`linePayload.ts:106`) → `bcdiv(119.000,10.0000,4)=11.9000`→`11.900` (`PurchaseOrderController.php:102-105`); `:126` writes landed `11.900000`; tax `bcmul(119.000,0.1900,3)=22.610` | **CONFIRMED** |
| W2-TOT-3 | after confirm `119.000/22.610/141.610`; `cost_price 11.900000`; GR-IR `119.000` | `calculateSubtotal`: `10×11.900=119.0000`; landed `(119.000+0+0)/10=11.900000` (`LandedCostService.php:333-347`); GR-IR `bcround(11.900000×10,3)=119.000` | **CONFIRMED** |
| W2-TOT-4 | `unit_price 14.285`; after confirm total `99.995` vs subtotal `100.000` | `bcdiv(100.000,7.0000,4)=14.2857`→`14.285`; `bcmul(7.0000,14.285,4)=99.9950`→`99.995` | **CONFIRMED** |
| W2-PART-6 | Dr408 `105.000`, Dr4456 `19.950`, Cr401 `124.950`, no PPV, no plug | accrual from `goods_receipt_lines.accrual_unit_cost` 10.500000 × (4+6) (`SupplierInvoicePostingService.php:490-501`); priceDelta 0, plug 0 (`GeneralLedgerService.php:2219-2225`) | **CONFIRMED** |
| W2-PRICE-5 | thresholds `2.200` / `1.000`; Dr408 `110.000`, Dr4456 `19.000`, Cr7585 `10.000`, Cr401 `119.000`, Σ `129.000` | basis 11.000000 ⇒ extendedVariance `1.000×10=10.000`; pctThreshold `110.000×0.020000=2.200`; both arms fail (`SupplierInvoiceMatcher.php:517-533`). priceDelta `−10.000` ⇒ PPV income; plug `119.000−129.000=−10.000`; inventoryPlug `0` | **CONFIRMED** |
| W2-LAND-1 | allocations `20.000000` / `10.000000`; landed `12.000000` both | `allocatePositiveShares(30.000,[100,50])`; `(100.000+20.000000)/10`, `(50.000+10.000000)/5` (`LandedCostService.php:333-347`) | **CONFIRMED** |
| W2-LAND-2 | `cost_price` 12.000000 both; GR-IR `120.000` + `60.000`; Σ408 `180.000` vs subtotal `150.000` | freight pool 30.000 ⇒ `hasBatchFreightPool` true ⇒ base = `unit_price` 10.000000, `landedUnitCostForReceipt(10,10.000000,20.000000)=12.000000` (`GoodsReceiptService.php:859-869`, `:539-545`) — same answer as the landed path when fully received | **CONFIRMED** |
| W2-LAND-3 | Dr408 `180.000`, Dr4456 `28.500`, Cr7585 `30.000`, Cr401 `178.500`, balanced at `208.500` | accruedHt 180.000, billedHt 150.000, priceDelta `−30.000`, plug `178.500−208.500=−30.000`, inventoryPlug `0` | **CONFIRMED** |
| W2-LAND-4 | allocated `30.000000`, landed `13.000000`, freight share `15.000`, tranche-2 cost `13.000000`, `cost_price 11.500000`, PO-line `accrual_unit_cost` stays `10.000000` | tranche 2: `fraction=0.5` ⇒ linePool `15.000000` (`ReceiptBatchCostAllocator.php:47-52`); base `unit_price 10.000000` ⇒ `(50+15)/5=13.000000`; WAC `(5×10+5×13)/10=11.500000`; `accrual_unit_cost` set once (`GoodsReceiptService.php:665-668`) | **CONFIRMED** |
| W2-EDGE-7 | WAC `0.000000` after free leg then `8.333333`; stock `12.0000`; ONE GR-IR (`100.000`) | free leg first (`:568-580`) at cost `'0'`; paid leg `(2×0+10×10)/12=8.333333`; free entry suppressed by `amount <= 0` (`GeneralLedgerService.php:2069-2072`) | **CONFIRMED** |
| W2-DISC-1 | `94.500 / 17.955 / 112.455` | `computeLineTotal(10.0000,10.500,'10.00',null,3)=94.500` (`DocumentLine.php:287-295`); confirm recompute agrees at scale 4 | **CONFIRMED** |
| W2-DISC-3 | FE unit `11.111`, BE `10.000`, header `100.000` → after confirm total `90.000` vs subtotal `100.000` | FE `bcdiv(100.000, 10.0000×0.9000, 3)=11.111` (`DocumentLineEditor.tsx:351-361`); BE Total branch ignores discount → `bcdiv(100.000,10.0000,4)=10.000`; confirm applies it → `90.000` | **CONFIRMED** |
| **W2-VAT-4** | "bucket view `1.005×0.19=0.19095→0.191` — a `0.001` drift"; two acceptable outcomes | Ledger: `bcround(0.0636500,3)=0.064` ×3 = `0.192`, subtotal `1.005`, total `1.197`. Snapshot is built from the **lines**, not the engine (`SupplierInvoicePostingService.php:415-416`; `PostedLineTaxSnapshotBuilder.php:148-182`) ⇒ ties exactly, outcome (a) deterministically. The engine, if it were used, gives `3×0.0636=0.1908` → **truncated to `0.190`**, not `0.191` | **WRONG** — both the figure and the mechanism (G1-S1-05) |
| **W2-VAT-2** | 19 % `63.000/11.970`, 7 % `13.000/0.910`, 0 % `20.000/0.000` | bases and amounts **CONFIRMED**; but every row is written with `tax_code='UNCONFIGURED'`, `tax_name='VAT 19%'` (`TaxCalculationService.php:253-268`) because the TN rate rows never list a NonFiscal token — the spec never says this | **INCOMPLETE** (G1-S1-13) |

---

## What to fix before merge

Rewrite the four seam assertions that cannot fail — W2-WDIL-4's non-existent GR-IR detector, W2-HP-5's vacuous journal query, the `batches`/`batch_stocks` table names in every lot row, and W2-VAT-4's stale drift premise — then re-cite W2-LAND-4's dead oracle, restate W2-REV-6 as `landed_unit_cost IS NULL`, add the nine missing scenarios (M1-M9), give the money classes real second-company/second-location arms, and re-grade F-W2-03 → P0 / F-W2-20 → P3 before any of this is scripted.
