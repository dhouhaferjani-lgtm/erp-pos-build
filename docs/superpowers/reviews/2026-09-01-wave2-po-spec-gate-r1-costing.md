# Wave-2 PO scenario matrix — adversarial spec gate r1, **inventory / WAC / batch / receipt** lens

**Reviewer lens:** stock movements, weighted-average costing, batches-lots-expiry-FEFO, goods-receipt state machine, landed cost, free quantities, over/under receipt, second location.
**Under review:** `docs/superpowers/audits/2026-09-01-wave2-po-flow/02-scenario-matrix.md` (rev 1, 130 scenarios / 21 classes) + `01-research.md`, `01a`–`01d`.
**Worktree read:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/L-po-flow` @ `c130ba8d2` (== dev `62964e5cc` + research commit). Read-only. No suites, no servers.
**Method:** every figure re-derived from the cited source files, opened directly. Where the spec's own citation was wrong I say so. Nothing below is asserted from memory.

---

## VERDICT

**CHANGES-REQUIRED**

The matrix is unusually strong on *shape* — the receipt state machine, over/under receipt, the F-SOE-2 message, the draft/post split, the LOT-4 silent-drop, the LOT-7 first-expiry-wins, the LOT-8 variant throw, the LAND-1 20/10 split and the EDGE-7 free-leg-first ordering are all **correct against the code**, and the five test pins I spot-checked (`GoodsReceiptLedgerWriteTest:170`/`:361`, `GoodsReceiptPriceOverrideTest:196-206`, `PurchaseBonusGoodsReceiptTest:182`, `GoodsReceiptDestinationTest:183`/`:206-212`, `LandedCostBcmathTest:302`/`:298-300`) all land on the method they claim.

But it is not gate-ready, for three structural reasons and a producibility tail:

1. **Every `products.cost_price` and every absolute `stock_levels` figure after the first receipt of a given product is wrong**, because the matrix hand-computes each class against a virgin product while the run plan reuses `P1` and `P4` across six classes inside **one** tenant, serially. WAC is a *running, company-wide* average.
2. **`W2-LOC-1..3` cannot run at all** — their fixture `PO-C` is fully received two classes earlier.
3. **`W2-LAND-4` states a mechanism the code does not have** — `canModifyCosts()` has zero callers — and that wrong mechanism is what owner question **Q-5** is framed on.

Plus five rows whose fixture or action is **not producible** on a fresh tenant through the documented surface (`W2-PRICE-4`, `W2-LAND-5`, `W2-LAND-6`), and a batch/expiry story that is missing the parapharmacy-critical arms (FEFO consumption, null expiry, same lot at a second location, second-company WAC isolation).

Fix the four BLOCKERs and the producibility MAJORs, then this can go straight to ACCEPT — the analysis underneath is good.

---

## Findings

### BLOCKERs

---

**G1-C1-01 [BLOCKER] — Serial single-tenant run plan × running company-wide WAC: every cost and most quantity figures after the first receipt per product are wrong**

*Rows:* `W2-TOT-3`, `W2-PART-2`, `W2-PART-4`, `W2-LOT-2`, `W2-LOC-2`, `W2-PRICE-1`, `W2-LAND-2`, `W2-LAND-4`, `W2-EDGE-7` — and every absolute `stock_levels` assertion in those rows. (`02-scenario-matrix.md:91, 104, 106, 142, 157, 198, 212, 214, 345`)

*Code:*
- `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:100-128` — `companyOwnedQuantity()` sums **every** `stock_level` row for the product in the company (all locations, all variants) plus in-transit. Not per-PO, not per-location, not per-run.
- `:236-253` — `$currentValue = bcmul($companyQty, $currentCostPrice, …)`; `$newAvgCost = bcdiv($newValue, $newCompanyQty, …)`.
- `:291` — `$product->cost_price = $newAvgCost;` — the running average is **persisted on the product** and is the input to the next receipt.

*Why it matters:* the run plan (`02-scenario-matrix.md:354-355`) is explicit — one shared page, one fresh tenant per full run, order `SETUP → HP → TOT → PART → OVER → UNDER → LOT → LOC → DRAFT → REV → PRICE → LAND → VAT → DISC → MATCH → IFIRST → IDEM → SEC → PERM → WDIL → EDGE`. `P1` is received in HP (6), TOT (10), PART (4+6), LOT, LOC and DRAFT; `P4` in UNDER-1 (6), UNDER-3 (5), PRICE-1 (10), LAND-2 (10), LAND-4 (5+5), EDGE-7 (10+2 free). Each figure in the matrix was computed as if the product started at zero. Worked examples:

| Row | Matrix says | Actually, given the serial order |
|---|---|---|
| `W2-TOT-3` | `cost_price(P1) = 11.900000` | prior 6 @ `10.500000` ⇒ `(6×10.5 + 10×11.9)/16 = 11.375000` |
| `W2-PART-2` | `cost_price 10.500000`, `stock 4.0000` | prior 16 @ `11.375000` ⇒ `224/20 = 11.200000`; `stock_levels(P1,MAIN) = 20.0000` |
| `W2-PRICE-1` | `cost_price = 11.000000` | prior 11 @ `10.000000` ⇒ `220/21 = 10.476190` |
| `W2-LAND-2` | `cost_price(P4) = 12.000000` | prior 21 @ `10.476190` ⇒ `339.99999/31 = 10.967741` |
| `W2-LAND-4` | `cost_price = 11.500000` | prior 31 ⇒ `≈ 11.097560` (see table at the end) |
| `W2-EDGE-7` | `0.000000` then `8.333333` | prior 41 units ⇒ neither figure is reachable |

Note this is **not only a costing problem** — `W2-LOT-2` asserts "aggregate `stock_levels` also `6.0000`" for `P1` at `MAIN`, which by that point in the run holds ~26 units.

*Fix (either, stated explicitly in the doc):*
(a) **Give every WAC-asserting class its own product** — `P4-OVER`, `P4-PRICE`, `P4-LAND1`, `P4-LAND4`, `P4-EDGE7`, `P1-TOT`, `P1-PART`, `P1-LOT`, `P1-LOC` — created in `W2-SETUP-3`, so each hand-derivation is against a virgin `cost_price = 0` / no `stock_levels` row; **or**
(b) restate every cost/stock expectation as a **delta**: read `products.cost_price` + `Σ stock_levels.quantity` immediately before the step, and assert `new_avg == bcdiv(prior_qty × prior_cost + recv_qty × recv_cost, prior_qty + recv_qty)` at 6 dp truncated, using the *measured* prior. Option (a) is far cheaper for a hand-computed matrix and preserves the "[derived] → [measured]" contract.

Whichever is chosen, add a standing line to §Reading the matrix: *"WAC is a running company-wide average persisted on `products.cost_price`; no absolute cost or stock figure is valid unless its product is used exactly once in the run."*

---

**G1-C1-02 [BLOCKER] — `W2-LOC-1..3` (and therefore `W2-SEC-3`) cannot execute: `PO-C` is already fully received**

*Rows:* `W2-LOC-1`, `W2-LOC-2`, `W2-LOC-3`, `W2-SEC-3` (`02-scenario-matrix.md:156-158, 289`)

*Code / spec conflict:* `W2-PART-2` receives tranche 1 (`4.0000`) and `W2-PART-4` receives tranche 2 (`6.0000`) of `PO-C`'s single `P1 × 10.0000` line (`:104, :106`), and PART-4 asserts `status = received`. The run order (`:354`) puts `PART` five classes before `LOC`. `W2-LOC-1` then says "PO-C confirmed at MAIN … receive tranche 1 `4.0000`".
- `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:300-302` — `assertReceivablePurchaseOrder()` refuses any PO whose status is not `Confirmed` ⇒ 422 `Purchase order must be confirmed before receiving goods` (this is exactly what `W2-EDGE-10` predicts).
- Even if the status were still `Confirmed`, `:313-319` — `remaining = 10.0000 − 10.0000 = 0` ⇒ over-receipt refusal.

*Why it matters:* the second-location arm is the convention-09 requirement for the whole receipt class. `W2-SEC-3` delegates to it wholesale, so the matrix's claim at `:25` ("every mutating class carries … a second-location arm") currently rests on three rows that 422 on step one.

*Fix:* give `LOC` its own PO (`PO-C2`, `P1-LOC × 10.0000 @ 10.500`, confirmed, untouched by PART), and say so in the class header. Keep the `MAIN`/`WH` split unchanged — the rest of the class (including the `location_id` rewrite at `GoodsReceiptService.php:677-678`, which I verified) is correct.

---

**G1-C1-03 [BLOCKER] — `W2-LAND-4` asserts a guard that has no callers; the owner ruling Q-5 is framed on it**

*Row:* `W2-LAND-4` (`02-scenario-matrix.md:214`), open question **Q-5** (`:377`)

*Spec claim:* "the cost is accepted after a partial receipt (`payload.goods_received_at` is written **`null`**, so `isset()` is false)", oracle `LandedCostService.php:452-458`.

*Code:*
- `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php:452-458` — `canModifyCosts()` does return `! isset($payload['goods_received_at'])`, and the `isset()`-on-null reading is right in isolation.
- **But `grep -rn "canModifyCosts" app/` returns exactly one hit — the declaration itself.** The method is dead code.
- `app/Http/Controllers/Api/DocumentAdditionalCostController.php:41-74` (`store`) validates `cost_type` / `description` / `amount` / `expense_document_id` and creates the row. There is **no** receipt-state check of any kind, and no re-allocation trigger.

*Why it matters:* the row tells the reader (and the owner, via Q-5) that a guard *exists and evaluates to permissive*. It does not exist. Q-5 asks whether "enter freight before the first receipt" is an acceptable operating rule — the answer space changes materially between "there is a guard whose `isset()` is wrong, fix the guard" (a one-line fix) and "there is no guard anywhere, and `canModifyCosts()` is dead" (a lane).

*Fix:* rewrite the `W2-LAND-4` expectation as *"the cost is accepted after a partial receipt because **nothing checks** — `DocumentAdditionalCostController::store` (`:41-74`) has no receipt-state guard, and `LandedCostService::canModifyCosts()` (`:452-458`) has zero callers"*; add the `canModifyCosts()` dead-code fact to `01-research.md` §5 as its own finding; and re-word Q-5 to say the guard is absent, not permissive. The **numbers** in the row (`allocated_costs 30.000`, tranche-2 `landed_unit_cost 13.000000`, `accrual_unit_cost` frozen at `10.000000`, 15.000 of 30.000 lost) are all correct — see the derivation table.

---

**G1-C1-04 [BLOCKER] — every batch SQL step names tables that do not exist**

*Rows:* `W2-HP-4`, `W2-PART-4`, `W2-LOT-2`, `W2-LOT-4`, `W2-LOT-7`, `W2-WDIL-1` (`02-scenario-matrix.md:75, 106, 142, 144, 147, 327`)

*Spec:* "`batches` row created", "3 `batch_stocks` rows", "SQL on `batches`/`batch_stocks`/`stock_levels`", "Σ `batch_stocks` delta".

*Code:*
- `apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:13` — `Schema::create('product_batches', …)`.
- `apps/api/database/migrations/tenant/2026_01_05_150001_create_inventory_batch_stock_table.php:14` — `Schema::create('inventory_batch_stock', …)` (`id`, `tenant_id`, `batch_id`, `location_id`, `quantity`, `reserved_quantity`, generated `available_quantity`; **no `company_id`**).

*Why it matters:* these rows are `SQL`-drive against `tenant_<uuid>` via psql. A wrong relation name is a hard `psql` error, and under the evidence protocol (`:358`) it becomes a `BLOCKED` verdict on the whole lot story rather than a measurement. The convention-09-relevant detail also changes: the uniqueness key that matters is `product_batches (company_id, product_id, batch_number) WHERE variant_id IS NULL` (`2026_06_02_100008_add_variant_id_to_product_batches.php:28`) — company-scoped, i.e. clean, which is worth stating.

*Fix:* global replace `batches` → `product_batches`, `batch_stocks` → `inventory_batch_stock`, and note in `W2-WDIL-1` that `inventory_batch_stock` is keyed on `(batch_id, location_id)` with no `company_id`, so the reconciliation must join `product_batches` to scope by company.

---

### MAJORs

---

**G1-C1-05 [MAJOR] — `W2-PRICE-4` has no producible actor: `manager` holds `goods-receipt.edit-price`, and `cashier` cannot reach the endpoint**

*Row:* `W2-PRICE-4` (`:201`) — "post it as a **manager without** `goods-receipt.edit-price`".

*Code:*
- `apps/api/database/seeders/RolesAndPermissionsSeeder.php:571` — the seeded `manager` role holds `'purchase-orders.receive', 'goods-receipt.edit-price', 'goods-receipt.create-standalone'` **on the same line**. There is no seeded role that has `purchase-orders.receive` without `goods-receipt.edit-price`.
- `apps/api/app/Modules/Inventory/Presentation/routes.php:169-172` — `POST /goods-receipts/{receipt}/post` is `can:purchase-orders.receive`. The `W2-SETUP-6` cashier does not hold it (`W2-PERM-4` correctly predicts 403), so the cashier cannot reach the 422 either.

*Why it matters:* the row is the only probe of `assertCanApplyDraftPriceOverrides()` (`GoodsReceiptService.php:334-349`) — the re-check at post, which is a real and valuable guarantee. As written it will produce a 403 (wrong actor) or be silently skipped.

*Fix:* add a `W2-SETUP-7` that creates a custom role holding `purchase-orders.receive` + `documents.view` + `inventory.view` and **not** `goods-receipt.edit-price`, assigns it to a third user, and logs that user in; point `W2-PRICE-4` at it. Also assert the exact envelope: 422 `GOODS_RECEIPT_POST_FAILED` with message `User is not allowed to apply goods receipt price overrides.` (`GoodsReceiptService.php:347`, mapped at `GoodsReceiptController.php:101-131`).

---

**G1-C1-06 [MAJOR] — `W2-LAND-5`'s "add a second cost and reverse it" is not producible: no reversal surface exists on document additional costs**

*Row:* `W2-LAND-5` (`:215`)

*Code:*
- `apps/api/app/Modules/Document/Presentation/routes.php:369-383` — the additional-cost surface is `GET index` / `POST store` / `PATCH update` / `DELETE destroy` only. No reverse route.
- The only writer of `document_additional_costs.reversed_at` in the whole app is `app/Modules/Expense/Application/Services/ExpenseService.php:987`; the only writer of a non-`LandedCost` `application_path` is `ExpenseService.php:176` (`CostApplicationPath::WacAdjustment`).
- The filter the breakdown endpoint is missing lives at `app/Modules/Inventory/Application/Services/LandedCostService.php:86-96` (`whereNull('reversed_at')` + `application_path` null-or-`LandedCost`).

*Why it matters:* the *finding* F-W2-16 is real and the citations are exact — I verified `DocumentAdditionalCostController.php:116` (`landedCostBreakdown`), `:121` (`(float) …->sum('amount')`, unfiltered), `:138` (`round(…, 2)`) and the `(float)` casts on `line_total` / `quantity` / `unit_price`. But the *action* that is supposed to widen the divergence cannot be performed through the PO flow. Adding a second plain cost widens only the `round(…,2)` vs 6-dp gap, not the filter gap.

*Fix:* split the row. `W2-LAND-5a` = compare the preview against `document_lines.landed_unit_cost` / `allocated_costs` after a plain second cost (the rounding + float divergence — producible). `W2-LAND-5b` = record that the `reversed_at` / `application_path` divergence is **only reachable via the Expense module** (`ExpenseService.php:176`, `:987`), i.e. out of wave-2 scope, and keep it as a stated code-truth in `01-research.md` rather than an executable row. Keep the "never an oracle" tolerance at `:366` as-is — it is correct.

---

**G1-C1-07 [MAJOR] — `W2-LAND-6`'s NON_REGISTERED company is not produced by `W2-SETUP-5`**

*Rows:* `W2-LAND-6` (`:216`), `W2-SETUP-5` (`:60`)

*Code:*
- `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:107` — company creation hardcodes `'tax_status' => CompanyTaxStatus::REGISTERED`. `W2-SETUP-5` creates company 2 "through the real company-creation path" and never changes it.
- Changing it requires `PATCH /companies/{id}` with `tax_status` (`UpdateCompanyRequest.php:50`, handled `CompanyController.php:272-273`) and passes only while `CompanyTaxStatusValidationService::validateTaxStatusChange()` (`:30-45`) finds no posted fiscal documents — so it must happen **before** any `W2-SEC-2` posting in company 2.

*The finding itself is correct* — I verified both halves: `TaxCalculationService.php:266` (`app/Modules/Taxation/Domain/Services/`) sets `isRecoverable: $company->tax_status !== CompanyTaxStatus::NON_REGISTERED`; `LandedCostService.php:194-215` folds the line-level non-recoverable VAT into `landed_unit_cost` at confirm; and `LandedCostService.php:265` passes literal `'0'` for that term at reallocation, which `GoodsReceiptService.php:227-231` runs on **every** post because `hasAllocatedCosts()` (`:442-447`) is true after any confirm. F-W2-17 is real and earns its severity.

*Fix:* add an explicit `W2-SETUP-5b` step — `PATCH /companies/{c2} {tax_status:'non_registered'}` executed **before** any company-2 document is posted — and add the ordering constraint to the run plan (`W2-LAND-6` currently sits after `W2-SEC` in dependency but before it in run order; state which company-2 documents may exist first).

---

**G1-C1-08 [MAJOR] — FEFO consumption is never exercised, and `W2-LOT-6`'s FEFO oracle points at the wrong code**

*Row:* `W2-LOT-6` (`:146`) — "Then assert FEFO excludes it ⇒ stock visible and unsellable", oracle `BatchStockService.php:378`, note `:416-422`.

*Code:*
- `BatchStockService.php:378` is `'is_expired' => false` in the create payload — correct for the *first* half of the claim (an expired lot is written with `is_expired = false`).
- `:415-435` is the **docblock** of `issueBatchStock()`, i.e. prose, not the FEFO filter.
- The actual FEFO behaviour lives in `app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`: ordering `(expiry_date IS NULL) ASC, expiry_date ASC, created_at ASC` at `:95` and `:271`; exclusion at `:108-111` and `:270` — and it filters on **`expiry_date >= now()->startOfDay()`**, *not* on `is_expired`. The conclusion ("visible but unsellable") happens to be right; the stated mechanism and oracle are not.

*Why it matters (parapharmacy):* `verticals.php:342` defaults **every** product to `requires_batch_tracking`, so FEFO is the consumption path for the entire vertical. The matrix builds two perfect FEFO fixtures — `W2-PART-4` leaves `LOT-T1 (2027-12-31) = 4.0000` and `LOT-T2 (2028-06-30) = 6.0000` on the same product/location — and then never consumes from them. There is no scenario proving the earlier-expiry lot is drawn first, no scenario proving a lot cannot go negative, and no scenario proving a consumption spanning two lots allocates per-lot.

*Fix:* (a) repoint the `W2-LOT-6` FEFO oracle to `FEFOInventoryService.php:108-111` / `:270`; (b) add **`W2-LOT-9`** — after `W2-PART-4`, consume `5.0000` of that product through the FEFO path and assert `LOT-T1` drains to `0.0000` and `LOT-T2` to `5.0000` (per-lot allocation, earlier expiry first), plus `Σ inventory_batch_stock.quantity == stock_levels.quantity`; (c) add **`W2-LOT-10`** — attempt to consume more than the sum of the lots and assert a refusal with **no** negative `inventory_batch_stock.quantity` row. Both are cheap on fixtures that already exist.

---

**G1-C1-09 [MAJOR] — a lot with genuinely unknown expiry cannot be received through the PO path, and no row records it**

*Code:*
- `apps/api/app/Modules/Document/Presentation/Requests/ReceiveGoodsRequest.php:33` — `'batches.*.expiry_date' => ['required_with:batches.*', 'date']`. There is no `nullable`, so a lot without a known expiry cannot be sent.
- This contradicts a deliberate design decision: `database/migrations/tenant/2026_08_26_100000_make_batch_expiry_date_nullable.php:34` made `product_batches.expiry_date` nullable precisely so an unknown expiry is a **fact**, and `BatchStockService.php:330-332` + `FEFOInventoryService.php:105-111` document and implement "undated lots rank last, never excluded".
- `findOrCreateBatch()` accepts `?string $expiryDate` (`BatchStockService.php:345`) — the service is ready; only the HTTP contract refuses.

*Why it matters:* it is a receipt-path gap in exactly the vertical where lots matter, and it is invisible unless probed. `W2-LOT-3` already measures the *absence of a default* shelf-life prefill (correctly — `ParapharmacySeeder` carries `default_shelf_life_days` and the dialog ignores it); the null case is the other half of the same B24 story.

*Fix:* add **`W2-LOT-11`** — API `POST …/receive` with `batches:{line:{batch_number:'LOT-NOEXP'}}` (no `expiry_date`) and with `expiry_date: null`; expect 422 on both, record it as a finding against the nullable-by-design contract, and cite `ReceiveGoodsRequest.php:33` vs `2026_08_26_100000_make_batch_expiry_date_nullable.php:34`.

---

**G1-C1-10 [MAJOR] — no second-location arm for lots, though `inventory_batch_stock` is keyed per location**

*Code:* `inventory_batch_stock` is `(batch_id, location_id)` (`2026_01_05_150001_…:20-21`), and `GoodsReceiptService.php:604-611` / `:653-660` call `receiveBatchStock(..., locationId: $location->id, ...)` per receipt. `findOrCreateBatch` (`BatchStockService.php:359-368`) is keyed on `(company, product, batch_number, variant)` with **no** location, so the same lot legitimately spans locations.

*Why it matters:* `02-scenario-matrix.md:25` claims every mutating class carries a second-location arm; `W2-SEC-3` delegates all of it to `W2-LOC` (which is broken — G1-C1-02) and `W2-LOC` uses an **untracked** product story. Nothing in the matrix receives the same `batch_number` into two locations, which is the single most likely lot bug shape (one `product_batches` row, two `inventory_batch_stock` rows, one aggregate `stock_levels` per location).

*Fix:* add **`W2-LOC-5`** — receive `batch_number = 'LOT-SPLIT'` `4.0000` at `WH` then `6.0000` at `MAIN` on a batch-tracked product; assert **one** `product_batches` row, **two** `inventory_batch_stock` rows (`4.0000` / `6.0000`), two `stock_levels` rows, and `Σ inventory_batch_stock == Σ stock_levels` for that product.

---

**G1-C1-11 [MAJOR] — no second-company WAC isolation assertion**

*Code:* `WeightedAverageCostService::companyOwnedQuantity()` filters `->where('company_id', $companyId)` (`:107-108`), and `recordPurchase` re-scopes the product lock by tenant+company (`:219-223`) before writing `cost_price` (`:291`). Company isolation of the running average is therefore a real, load-bearing invariant.

*Why it matters:* `W2-SEC-2` runs "the whole HP flow" in company 2 and asserts numbering and journal-entry minting — never costing. Convention 09's point is precisely that a second company must not perturb the first. This is the cheapest high-value second-company assertion in the whole matrix and it is absent.

*Fix:* extend `W2-SEC-2` (or add `W2-SEC-7`): capture `products.cost_price` and `Σ stock_levels.quantity` for company 1's `P1` **before** the company-2 receipt, and assert both are byte-identical afterwards; and assert company 2's `P1'` blended only against company-2 stock. Pair it with G1-C1-01's per-class products so the figures are computable.

---

**G1-C1-12 [MAJOR] — `W2-LAND-4` stops at the receipt: the 408 residue guard it sets up is never fired, and its GR-IR figures are missing**

*Row:* `W2-LAND-4` (`:214`)

*Code:* `GoodsReceiptService.php:663-668` — `accrual_unit_cost` is written **only when null**, with the comment "SupplierInvoicePostingService::post() asserts against this to detect post-receipt landed-cost reallocations that would leave a 408 residue". `W2-LAND-4` deliberately creates exactly that state: `document_lines.accrual_unit_cost = 10.000000` frozen at tranche 1, `document_lines.landed_unit_cost` rewritten to `13.000000` by `LandedCostService.php:265` at tranche 2, and receipt-line accruals of `10.000000` and `13.000000`.

*Why it matters:* the row identifies the divergence and then walks away from the only place the system reacts to it. The GR-IR consequence is also unstated: tranche 1 posts `5 × 10.000000 = 50.000`, tranche 2 `5 × 13.000000 = 65.000` ⇒ **Σ 408 = 115.000** against a PO subtotal of `100.000` and `130.000` of true cost — which `W2-WDIL-3` ("408 nets to zero for a fully received + fully invoiced PO") will then trip over without an explanation.

*Fix:* add **`W2-LAND-7`** — create + post a supplier invoice for `PO-E` at PO prices and record what `SupplierInvoicePostingService` does with the `10.000000` / `13.000000` split (posts with a PPV leg? refuses on the accrual assertion? leaves a 408 residue?). Add the `50.000` / `65.000` / `Σ 115.000` GR-IR figures to `W2-LAND-4`'s expected column, and cross-reference the row from `W2-WDIL-3`.

---

**G1-C1-13 [MAJOR] — the price-override × landed-cost branch is untested**

*Code:* `GoodsReceiptService.php:542-546` is a three-way basis selection that no scenario exercises in combination:
```php
$baseUnitCost = $hasReceivedPriceOverride
    ? CurrencyScale::bcround((string) $receivedUnitPrice, self::COST_SCALE)   // override wins outright
    : ($hasBatchFreightPool
        ? CurrencyScale::bcround((string) $line->unit_price, self::COST_SCALE) // freight present -> RAW unit_price
        : $oldBasis);                                                          // otherwise landed_unit_cost
```
with `$landedUnitCost = landedUnitCostForReceipt($qty, $baseUnitCost, $batchFreightShare)` (`:547`, `:859-869`), and the freight share itself computed from the *override* basis when present (`ReceiptBatchCostAllocator.php:57-58` — `$unitBasis = $receivedUnitPrices[$lineId] ?? $line->unit_price`).

*Why it matters:* `W2-PRICE-*` uses a PO with **no** additional cost (`PO-I`), and `W2-LAND-*` uses receipts with **no** override. The middle branch (`$hasBatchFreightPool` ⇒ base is raw `unit_price`, deliberately *not* `landed_unit_cost`, to avoid double-counting freight) is the subtlest line in the whole costing path and is only reached by `W2-LAND-4` tranche 2 incidentally. The override-plus-freight combination — a delivered price that differs from the PO on a freighted PO, which is the ordinary parapharmacy import case — is never valued.

*Fix:* add **`W2-LAND-8`** — `PO-F`: one line `P4-LAND8 × 10.0000 @ 10.000` with a `transport` cost of `30.000` added and confirmed; receive all 10 with `received_unit_price = 11.000`. Derived: `allocated_costs = 30.000`, `landed_unit_cost(PO line) = 13.000000`, allocator pool `= 30.000000`, `receivedValue = 10 × 11.000 = 110`, share `= 30.000000`, `baseUnitCost = 11.000000`, receipt `landed_unit_cost = (10×11.000000 + 30.000000)/10 = 14.000000`; `cost_price = 14.000000`; GR-IR `140.000`; `price_override_old_basis = 13.000000` (it is `$oldBasis`, i.e. the **landed** cost, not `unit_price` — `:530`, `:706`).

---

**G1-C1-14 [MAJOR] — the `S1` service fixture is under-specified, and one of its two shapes creates an orphan receipt line**

*Rows:* fixture table (`:46`), `W2-UNDER-3` (`:133`)

*Code:* there are two different skips, and they are not equivalent:
- `GoodsReceiptService.php:503-505` — `if ($line->product_id === null) { continue; }` in `processReceiptLines`.
- `:519-521` — `if ($product === null || ! $product->isPhysical()) { continue; }` — the non-physical **Product** case.
- But `createDraft` only has the **first** check (`:153-155`). It has **no** `isPhysical()` filter.

So if `S1` is a `Product` with `is_physical = false` (which `Product::booted()` at `:186-212` explicitly anticipates — it forces `requires_batch_tracking = false` for non-physical products), and a quantity is supplied for its line, `createDraft` **creates a `GoodsReceiptLine`** for it (`:168-188`) and `post()` then skips it (`:519-521`), leaving a receipt line with `movement_id = NULL`, `landed_unit_cost = NULL`, `quantity_invoiced = '0.0000'`. `receiveAll()` (`:775-782`) puts **every** line with a remainder into `receivedQuantities` with no product filter, so the receive-all path hits this by default.

*Why it matters:* `W2-UNDER-3`'s conclusion (a goods+service PO can never reach `received`) is **correct** for both shapes — I verified `isFullyReceived()` (`:928-942`) demands `quantity_received >= quantity` on every line including the service one. But the orphan-receipt-line shape is a separate, unrecorded defect that the matrix's own `receiveAll` usage will produce.

*Fix:* pin `S1`'s shape in the fixture table (I recommend **both**: `S1a` = line with `product_id = NULL`, `S1b` = a non-physical `Product`), and add **`W2-UNDER-4`** — `receiveAll` (empty-body `POST …/receive`) on a `P4 + S1b` PO; assert whether a `goods_receipt_lines` row exists for `S1b` with `movement_id IS NULL`, and whether it is later visible to the matcher.

---

**G1-C1-15 [MAJOR] — a 101–255 character `batch_number` produces a 500, and the zero-5xx tolerance has no probe for it**

*Code:*
- `ReceiveGoodsRequest.php:32` — `'batches.*.batch_number' => ['required_with:batches.*', 'string', 'max:255']`.
- `2026_01_05_150000_create_product_batches_table.php:25` — `$table->string('batch_number', 100)`.
- `PurchaseOrderController.php:857-865` catches `\DomainException` (422) and `\RuntimeException` (**500 `CONFIGURATION_ERROR`**). `Illuminate\Database\QueryException` extends `PDOException` extends `RuntimeException`, so the PG `value too long for type character varying(100)` lands on the 500 arm.

*Why it matters:* `§Tolerances` (`:363`) declares **zero 5xx**, with `W2-EDGE-1` as the single named exception. This is a second, cheaply reachable 5xx on the receipt happy path's own input surface — and more broadly, the `RuntimeException` catch means *any* DB-level failure during a receipt is rendered as a 500 rather than a 4xx.

*Fix:* add **`W2-EDGE-11`** — receive with a 150-character `batch_number`; expect 422 (the fix) and record a 500 as the finding. Add a one-line note under §Tolerances that `PurchaseOrderController.php:859-865` converts every `QueryException` in the receive path into a 500.

---

### MINORs

---

**G1-C1-16 [MINOR] — `W2-LAND-1` states `allocated_costs` at 6 dp; it is persisted at currency scale (3)**

`LandedCostService::allocatePositiveShares()` (`:303-315`) returns shares at `$scale = $this->scale()` (currency scale = 3 for TND) and `:264` assigns them straight to `$line->allocated_costs`. `W2-LAND-1` (`:211`) writes `20.000000` / `10.000000`; `W2-HP-2` (`:73`) writes `0.000` for the same column. The existing pin agrees with 3 dp: `LandedCostBcmathTest.php:298-300` — *"The exact proportional split is L1 = 20.000, L2 = 10.000, giving landed_unit_cost 12.000000 on BOTH lines."* **Fix:** `allocated_costs 20.000 / 10.000`; keep `landed_unit_cost` at 6 dp.

---

**G1-C1-17 [MINOR] — `W2-LOT-8` hedges on a statically determinable status**

`W2-LOT-8` (`:148`) says "a `\DomainException` maps to 422, any other exception class surfaces as **500**". `App\Shared\Domain\Exceptions\MissingVariantException` **extends `\DomainException`**, so the outcome is fixed: **422 `GOODS_RECEIPT_FAILED`** with message `Product '<uuid>' has active variants; batches must be variant-scoped — a variant_id is required.` (`MissingVariantException::forProduct`, mapped at `PurchaseOrderController.php:857-858`). Per §Reading the matrix ("a row with no oracle is not gate-ready"), a row that declines to state its own expectation is equally not gate-ready. **Fix:** assert the 422 and the exact message; keep the finding about the raw UUID in operator-facing copy (same class as F-W2-27).

---

**G1-C1-18 [MINOR] — `W2-PRICE-3`'s payloads must also carry `quantities`, or they 422 for the wrong reason**

`ReceiveGoodsRequest.php:27` — `'quantities' => ['required_with:received_unit_prices', 'array']`. A probe sending only `received_unit_prices` fails on the missing `quantities` key, not on the price rule. **Fix:** state that each `W2-PRICE-3` payload carries a valid `quantities` entry for the same line, and name which of the three 422s is a **validation** failure (`-1.000`, `11.0001` — `:36` regex) versus a **service** failure (`'0'` — `GoodsReceiptService.php:160-166` / `:535-541`, code `GOODS_RECEIPT_FAILED`).

---

**G1-C1-19 [MINOR] — negative received quantities are accepted by validation and silently swallowed; no scenario**

`ReceiveGoodsRequest.php:28` regex is `/^-?\d+(\.\d{1,4})?$/` — it **permits a leading minus** (unlike `received_unit_prices.*` at `:36`, which does not). `GoodsReceiptService.php:149-151` then skips any line whose paid and free quantities are both `<= 0`, so a negative-only payload reaches `throw new \DomainException('No items to receive…')` (`:192-194`) — safe today, but the safety is incidental. **Fix:** add a row asserting `quantities:{line:'-5.0000'}` → 422 `No items to receive. Please specify quantities to receive.`, and a mixed payload (`lineA: '4.0000'`, `lineB: '-2.0000'`) asserting `lineB` produced **no** movement and **no** `stock_levels` change.

---

**G1-C1-20 [MINOR] — a draft receipt can persist an invalid destination; only `post()` validates it**

`createDraft` stores `'location_id' => $destinationLocationId` verbatim (`GoodsReceiptService.php:129`) and never calls `resolveDestinationLocation()`; that only happens in `post()` (`:222`, resolver at `:984-1008`). `W2-LOC-4` probes the foreign/inactive location on the *receive* path only. **Fix:** add a `save_as_draft: true` arm to `W2-LOC-4` — assert 201 with a foreign-company `location_id` written to `goods_receipts.location_id`, then 422 `Receiving destination is not available for this company.` at post, which pairs neatly with `W2-DRAFT-4`'s uncorrectable-draft finding.

---

**G1-C1-21 [MINOR] — no quantity-display-precision assertion, despite a 0-dp unit fixture**

The fixture pins `pc` at `decimal_places = 0` (`:41-46`) and `W2-SETUP-1` asserts it, but no row checks that the receive dialog / PO editor / receipt list render quantities at the unit's precision rather than raw scale-4 (house rule 19 "Display/emission", guarded by `audit-quantity-display.mjs` and the two PHPStan rules). **Fix:** add one assertion to `W2-LOT-1` or `W2-HP-3` that the dialog's prefilled remainder renders as `6` (not `6.0000`) for a 0-dp unit.

---

**G1-C1-22 [MINOR] — `W2-HP-4`'s WAC oracle range misses the two lines that actually produce the asserted values**

`W2-HP-4` (`:75`) cites `WeightedAverageCostService.php:236-284`. The blend that yields `products.cost_price` is `:251-253` and the write is `:291`; `:236-284` covers `$currentValue` through the end of the `StockMovement::create`. **Fix:** cite `:100-128` (denominator), `:241-253` (blend), `:263-284` (movement), `:291` (cost write).

---

**G1-C1-23 [MINOR] — purchase receipts carry no `MovementReason`; `W2-WDIL-4` should say which column it reads**

`recordPurchase` writes `'movement_type' => MovementType::Receipt` and **no `reason`** (`:263-284`), while `recordSale` sets `MovementReason::Delivery` (`:477`) and `recordReturn` sets `CustomerReturn` (`:630`). `W2-HP-4`'s "type `Receipt`" is therefore correct as written, but `W2-WDIL-4`'s COGS-coverage reconciliation should state that goods-receipt movements are identified by `movement_type`, not `reason`, or a `reason`-keyed query returns nothing.

---

**G1-C1-24 [MINOR] — `W2-TOT-2` is internally inconsistent about the unit price**

`W2-TOT-1` (`:89`) says the editor shows unit `10.000`; `W2-TOT-2` (`:90`) says the row is stored with `unit_price = 11.900`. Both may be true (FE displays a net-derived unit, payload carries `line_total/qty`), but as written a reader cannot tell which is the expectation. This is the K-1 money lane, correctly flagged as evidence-only — but the *costing* consequence flows from `line_total` alone (`landed_unit_cost = line_total/quantity`, `LandedCostService.php:327-357`), so state that explicitly: `cost_price = 11.900000` follows from `line_total = 119.000` regardless of what `unit_price` holds.

---

## Cross-cutting checks

- **Second-of-everything (convention 09).** The claim at `:25` is **decorative for the costing/receipt classes**. `W2-SEC-3` delegates the entire second-location arm to `W2-LOC`, which cannot run (G1-C1-02) and uses an untracked product; there is no second-location arm for `LOT` (G1-C1-10), `DRAFT`, `LAND` or `PRICE`, and no second-company arm for WAC (G1-C1-11). The re-run arm (`W2-IDEM-2`, `:275`) **is** real and correctly derived — no idempotency key exists on `ReceiveGoodsRequest`, and `createGoodsReceiptGrIrEntry` keys on `movementId` (`GeneralLedgerService.php:2052`, `:2086`), so a second receive genuinely produces a second entry.
- **One surface per concept (convention 11).** The matrix introduces no new noun and says so (`:25`); correct — nothing in it creates a table, import type or operator surface. It does surface a genuine second-surface fact worth stating in `01-research.md`: `GET /documents/{id}/landed-cost-breakdown` (`DocumentAdditionalController.php:116-164`) is a **second, float-based reader** of the same concept `LandedCostService` persists at 6 dp — the matrix quarantines it correctly (`:366`) but does not name it as a convention-11 duplicate reader.
- **Benchmark-first (convention 10).** Present, with `B7 / B21 / B26 / B37 / B48` inline and the full 50-row table referenced. The three costing-relevant rows I can verify are accurate: `B21`'s "hard refusal at scale 4, no tolerance, enforced twice (`:157`, `:496`)" ✓ (`assertQuantitiesWithinRemaining` is called from both `createDraft:157` and `processReceiptLines:496`); `B26`'s "free units always blend at `'0'` (`:574`)" ✓; `B7`'s "`Received` written only on a full receipt (`:727-734`)" ✓.
- **Data-meaning tests.** Good — the matrix consistently asserts balances, stock rows and per-lot quantities rather than status codes. The exceptions are the rows above where the *figure* is wrong (G1-C1-01) or the row asserts nothing determinate (G1-C1-17).
- **Findings hygiene (mission item 7).** No double-fixing: `W2-LOT-5` is explicitly tagged `[F-SOE-2]` and only measures; `W2-TOT-*` explicitly defers the VAT-on-VAT fix to lane K-1 task 4.2 (`:83`). Correct posture. Severities I can adjudicate in my lens: **F-W2-01** (no receipt idempotency) — earned, verified; **F-W2-16** (float breakdown) — earned, exact citations; **F-W2-17** (non-recoverable VAT stripped at post) — earned, verified at `LandedCostService.php:265`; **F-W2-12** (variant batch throw) — earned but over-stated as a possible 500 (G1-C1-17); **F-W2-11** (`bccomp` scale-4 truncation window) — the "not reachable over HTTP" disposition is **correct**: `ReceiveGoodsRequest.php:28` caps at 4 dp, `receiveAll` derives remainders with `bcsub(…, 4)` (`:777`, `:788`), and neither `StandaloneReceiptService` nor `InvoiceFirstOrchestrator` introduces a 5-dp source.
- **Precision contract.** No new float is introduced by the spec. One standing note for whichever lane follows: `LandedCostService::scale()` (`:58-61`) and `WeightedAverageCostService::scale()` (`:55-58`) both call `getScale()` **with no argument**, which throws outside HTTP-request context; `post()` is reachable from `StandaloneReceiptService` and `InvoiceFirstOrchestrator` today, both HTTP, so it is safe now — but any future queued/console receipt path breaks it. The matrix has no scenario that exercises costing from a non-HTTP context, which is fine for wave 2 but should be stated as a known non-coverage.

---

## Missing scenarios

| # | Missing scenario | Class it belongs in | Why it matters | Severity |
|---|---|---|---|---|
| M1 | FEFO consumption across `LOT-T1 (2027-12-31)` / `LOT-T2 (2028-06-30)` — earlier expiry drained first, per-lot allocation | `W2-LOT-9` | parapharmacy defaults every product to lots (`verticals.php:342`); FEFO is the only consumption path and is never proven (`FEFOInventoryService.php:95`, `:108-111`) | MAJOR |
| M2 | Over-consume a lot set — refusal with no negative `inventory_batch_stock.quantity` | `W2-LOT-10` | negative lot balance is the corruption mode of a batch system | MAJOR |
| M3 | Lot with **null / omitted** `expiry_date` | `W2-LOT-11` | `ReceiveGoodsRequest.php:33` blocks a shape the schema and FEFO deliberately support (`2026_08_26_100000_…:34`) | MAJOR |
| M4 | Same `batch_number` received at **two locations** | `W2-LOC-5` | `inventory_batch_stock` is `(batch_id, location_id)`; one `product_batches` row must back two stock rows | MAJOR |
| M5 | Second-company WAC isolation — company-2 receipt must not move company-1 `cost_price` / `stock_levels` | `W2-SEC-7` | the invariant `companyOwnedQuantity()` `:107-108` exists to protect; convention 09's core ask | MAJOR |
| M6 | Price override **combined with** an additional cost | `W2-LAND-8` | the three-way basis branch `GoodsReceiptService.php:542-546` is otherwise untested | MAJOR |
| M7 | Supplier invoice on `PO-E` after the split-tranche freight (`accrual 10.000000` vs `landed 13.000000`) | `W2-LAND-7` | the 408 residue guard the receipt code exists to feed is never fired | MAJOR |
| M8 | `receiveAll` on a PO carrying a **non-physical Product** line | `W2-UNDER-4` | `createDraft:153-155` lacks the `isPhysical()` filter that `:519-521` has ⇒ orphan `goods_receipt_lines` row | MAJOR |
| M9 | `batch_number` of 101–255 chars | `W2-EDGE-11` | `max:255` vs `varchar(100)` ⇒ `QueryException` → 500 through the `RuntimeException` arm (`PurchaseOrderController.php:859-865`) | MAJOR |
| M10 | `save_as_draft` with a foreign-company / inactive `location_id` | `W2-LOC-4` arm | `createDraft:129` persists it unvalidated; only `post()` resolves (`:222`, `:984-1008`) | MINOR |
| M11 | Negative `quantities` entry (validation permits `-`) | `W2-OVER-5` | `ReceiveGoodsRequest.php:28` regex has `-?`; the swallow at `:149-151` is incidental safety | MINOR |
| M12 | `batches` keyed by a line id **not in** the receipt / belonging to another PO | `W2-LOT-12` | `$batchData[$line->id]` (`:523`, `:557`) ignores extra keys silently | MINOR |
| M13 | `manufacturing_date` later than `expiry_date` | `W2-LOT-13` | no cross-field rule (`ReceiveGoodsRequest.php:31-34`); a nonsense lot is storable | MINOR |
| M14 | Quantity **display** precision for the 0-dp `pc` unit in the receive dialog | `W2-LOT-1` arm | house rule 19 display clause; the fixture already pins `decimal_places = 0` | MINOR |

---

## Numbers I re-derived

Every figure below was computed by hand from the cited code, independently of the matrix.

| Row | Matrix figure | My derivation | Verdict |
|---|---|---|---|
| `W2-HP-1` | subtotal `96.000`, tax `12.880`, total `108.880` | `6×10.500=63.000`, `4×3.250=13.000`, `5×4.000=20.000` ⇒ `96.000`; `63×.19=11.970`, `13×.07=0.910`, `20×0=0` ⇒ `12.880`; `+0.000` timbre ⇒ `108.880` | ✅ correct |
| `W2-HP-2` | `landed_unit_cost 10.500000 / 3.250000 / 4.000000`, `allocated_costs 0.000` | `LandedCostService::landedUnitCost` `:327-357` = `line_total/qty` with zero cost and zero non-recoverable tax ⇒ `63/6`, `13/4`, `20/5`; `allocatePositiveShares` `:303-315` returns `bcformatStrict('0', 3)` | ✅ correct |
| `W2-HP-4` | `cost_price 10.500000 / 3.250000 / 4.000000`, 3 `Receipt` movements | first receipt of each product, `companyQty = 0` ⇒ `newAvg = landedUnitCost`; `movement_type = MovementType::Receipt` `:270`, `reference = document_number` `:575`, `reference_id = po.id` `:577` | ✅ correct (only because HP is first — see G1-C1-01) |
| `W2-TOT-3` | `cost_price(P1) = 11.900000` | `landed = 119.000/10 = 11.900000` ✅, but blend vs prior HP stock `6 @ 10.500000` ⇒ `(63+119)/16 = 11.375000` | ❌ **wrong** (G1-C1-01) |
| `W2-PART-2` | `cost_price 10.500000`, stock `4.0000` | receipt cost `10.500000` ✅; blended against prior 16 units ⇒ `224/20 = 11.200000`; `stock_levels(P1,MAIN) = 20.0000` | ❌ **wrong** (G1-C1-01) |
| `W2-PART-2` | PO stays `confirmed`, `fully_received=false`, `goods_received_at=null`, `accrual_unit_cost` set once | `:727-734` writes `Received` only when `isFullyReceived()`; `:732` writes literal `null`; `:666-668` sets accrual only when null | ✅ correct |
| `W2-PART-4` | two lots `LOT-T1 4.0000` / `LOT-T2 6.0000` | `findOrCreateBatch` `:359-368` keys on `(company, product, batch_number, variant)` ⇒ distinct numbers ⇒ two `product_batches` rows, two `inventory_batch_stock` rows | ✅ correct (table names wrong — G1-C1-04) |
| `W2-OVER-1` | 422 `GOODS_RECEIPT_FAILED`, `/^Cannot receive more than ordered for line [0-9a-f-]{36}\./` | `:315-319` message is `"Cannot receive more than ordered for line {$line->id}. Ordered: …"`; `10.0001 > 10.0000` at scale 4; mapped `PurchaseOrderController.php:857-858` | ✅ correct |
| `W2-OVER-3` | free ceiling independent | `:322-331` uses `free_quantity` / `free_quantity_received`, a separate comparison | ✅ correct |
| `W2-OVER-4` | `10.00005` 422 **at validation**; `bccomp` window unreachable | `ReceiveGoodsRequest.php:28` regex `\d{1,4}`; no 5-dp source anywhere in the receive path | ✅ correct |
| `W2-UNDER-3` | goods+service PO can never reach `received` | service line skipped `:503-505` / `:519-521`; `isFullyReceived()` `:930-937` still requires `received >= quantity` on it ⇒ always false | ✅ correct (shape ambiguity — G1-C1-14) |
| `W2-LOT-4` | untracked line's batch silently dropped | `:557` conjunction `isset($batchData[...]) && requires_batch_tracking` ⇒ `$batch` stays null ⇒ no `receiveBatchStock`, no `batch_id` write `:673-675` | ✅ correct |
| `W2-LOT-5` | 422, message `Batch data is required for batch-tracked product <uuid>` | `:523-525` verbatim; empty body → `quantities` null → `receiveAll` (`PurchaseOrderController.php:841-843`) → `batchData = []` (`GoodsReceiptService.php:795`) | ✅ correct |
| `W2-LOT-6` | expired lot accepted, `is_expired=false`, FEFO excludes it | `:378` writes `false`; `ReceiveGoodsRequest.php:31-34` has no `after:today`. FEFO exclusion is real but via `expiry_date >= today` (`FEFOInventoryService.php:108-111`, `:270`), **not** `is_expired` | ✅ outcome / ❌ oracle (G1-C1-08) |
| `W2-LOT-7` | one lot, topped up, first expiry kept | `:359-368` returns `$existing` before the create payload with the new expiry is built | ✅ correct |
| `W2-LOT-8` | `MissingVariantException`, "422 or 500" | extends `\DomainException` ⇒ deterministic 422 `GOODS_RECEIPT_FAILED`; thrown at `BatchStockService.php:350-355` before any movement ⇒ full rollback | ✅ finding / ❌ hedged (G1-C1-17) |
| `W2-LOC-3` | PO line `location_id` rewritten to the last destination | `:677-678` unconditional `$line->location_id = $location->id` | ✅ correct |
| `W2-LOC-4` | foreign company → 422, inactive → 422, narrowed `allowed_location_ids` → 403 | `:990-1002` (`company_id` + tenant `whereExists` + `is_active`) ⇒ `DomainException` ⇒ 422; 403 pinned by `GoodsReceiptDestinationTest.php:183`/`:206-212` | ✅ correct |
| `W2-DRAFT-1/3/4/5/6` | draft has NULL `receipt_number`, no movements; re-post 422; draft w/o batch unpostable; draft locks PO lines; delete is a hard delete | `:130`, `:212-214`, `:107-201` vs `:523-525`, `:802-818` (no status filter), `GoodsReceiptController::destroy` (hard `delete()` on lines + header), GRN minted at `:254-260` | ✅ all correct |
| `W2-PRICE-1` | `cost_price 11.000000`, GR-IR `110.000`, `old_basis 10.000000`, `accrual 11.000000` | override branch `:542-543` ⇒ `baseUnitCost = 11.000000`; share 0 ⇒ `landed = 11.000000`; GR-IR `10 × 11.000000 = 110.000`; `old_basis` `:706` = `$oldBasis` `:530`. But `cost_price` blends against prior `P4` stock ⇒ `220/21 = 10.476190` | ⚠ mechanism ✅ / `cost_price` ❌ (G1-C1-01) |
| `W2-PRICE-2` | 422 `prohibited`, not 403 | `ReceiveGoodsRequest.php:35` — `$canEditPrice ? ['sometimes','array'] : ['prohibited']` | ✅ correct |
| `W2-PRICE-3` | `'0'`→422, `-1.000`→422, `11.0001`→422 | `:162-163`/`:537-538` (service, `GOODS_RECEIPT_FAILED`); `ReceiveGoodsRequest.php:36` regex has no `-?` and caps at 3 dp | ✅ correct (payload caveat — G1-C1-18) |
| `W2-LAND-1` | L1 `20.000000`, L2 `10.000000`; both `landed 12.000000` | `30 × 100/150 = 20`, `30 × 50/150 = 10`; `(100+20)/10 = 12.000000`, `(50+10)/5 = 12.000000`. Pinned verbatim by `LandedCostBcmathTest.php:298-300` | ✅ values correct, scale wrong (G1-C1-16) |
| `W2-LAND-2` | `cost_price(P4)=12.000000`, GR-IR `120.000` + `60.000`, Σ408 `180.000` | GR-IR figures ✅ (`10 × 12.000000`, `5 × 12.000000`); `cost_price` blends against prior `P4` stock ⇒ `≈ 10.967741` | ⚠ GL ✅ / WAC ❌ (G1-C1-01) |
| `W2-LAND-4` | `allocated_costs 30.000`, `landed 13.000000`, share `15.000`, tranche-2 receipt cost `13.000000`, `accrual` frozen `10.000000`, `15.000` of freight lost | `ReceiptBatchCostAllocator.php:47-52`: `fraction = 5/10`, `linePool = 30.000000 × 0.5 = 15.000000`; `hasPositiveFreightPool` ⇒ `baseUnitCost = unit_price = 10.000000`; `landedUnitCostForReceipt(5, 10.000000, 15.000000) = 65/5 = 13.000000` `:859-869`; `accrual` `:666-668`; value in = `50 + 65 = 115` vs `130` true ⇒ `15.000` lost | ✅ **all correct**; `cost_price 11.500000` ❌ (G1-C1-01); mechanism claim ❌ (G1-C1-03) |
| `W2-LAND-6` | non-recoverable VAT capitalised at confirm, stripped at post | `LandedCostService.php:194-215` (line VAT into `landed_unit_cost`) vs `:265` (`'0'` at reallocation); `reallocateCosts` runs on every post because `hasAllocatedCosts()` `:442-447` is true after any confirm (`GoodsReceiptService.php:227-231`); recoverability `Taxation/…/TaxCalculationService.php:266` | ✅ correct; fixture not producible (G1-C1-07) |
| `W2-EDGE-7` | free leg first at `'0'`, WAC `0.000000` → `8.333333`, one GR-IR `100.000`, `effective_unit_cost 8.333333` | ordering `:569` before `:617` ✅ (pinned `GoodsReceiptPriceOverrideTest.php:196-206`); GR-IR zero-skip `GeneralLedgerService.php:2070-2072` (`bccomp($amount,'0',$scale) <= 0 ⇒ return null`) ✅; `effectiveUnitCost` `:839-851` = `(10 × 10.000000)/12 = 8.333333` ✅. WAC figures assume a virgin `P4` | ⚠ mechanism ✅ / WAC ❌ (G1-C1-01) |
| `W2-EDGE-10` | receive against Draft **and** Received → 422, same "must be confirmed" copy | `:294-303` single status check ⇒ identical message for both | ✅ correct |
| `W2-IDEM-2` | double receive ⇒ stock `8.0000`, 2 receipts, 2 movements, 2 GR-IR entries | no idempotency key on `ReceiveGoodsRequest`; GR-IR dedupe keys on `source_id = movementId` (`GeneralLedgerService.php:2052`, `:2086`) which differs per receipt | ✅ correct |
| `W2-VAT-4` | `0.064 × 3 = 0.192`, bucket `0.191`, drift `0.001` | `0.335 × 0.19 = 0.06365` → half-up 3 dp `0.064`; `1.005 × 0.19 = 0.19095` → `0.191` | ✅ correct |
| `W2-DISC-1` | `94.500 / 17.955 / 112.455` | `105.000 − 10.500 = 94.500`; `94.5 × .19 = 17.955` | ✅ correct |

---

## What to fix before merge

Fix the four BLOCKERs — give every WAC-asserting class its own product (or restate costs as measured deltas), re-fixture `W2-LOC` off a PO that PART has not consumed, correct `W2-LAND-4`'s mechanism to "no guard exists, `canModifyCosts()` is dead" and re-word Q-5 accordingly, and rename `batches`/`batch_stocks` to `product_batches`/`inventory_batch_stock`; then make `W2-PRICE-4`, `W2-LAND-5` and `W2-LAND-6` producible and add the six MAJOR missing lot/second-of-everything rows (M1–M8).
