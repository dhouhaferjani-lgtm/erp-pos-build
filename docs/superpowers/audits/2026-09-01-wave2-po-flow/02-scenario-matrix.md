# Wave 2 — Purchase-to-Pay scenario matrix (spec for the Playwright suite)

**Session:** L · **Date:** 2026-09-01 · **Base commit:** `62964e5cc` · **Status:** rev 1, awaiting adversarial spec gate (this document is gated BEFORE any scripting).
**Target suite:** `apps/web/e2e-local/wave2-po.spec.ts` — serial, ONE shared page, evidence note + screenshot per step.
**Env:** vite `http://localhost:5178` → API `:8015`, fresh TN parapharmacy tenant per run (TND scale **3**, quantity scale **4**).

**130 scenarios across 21 classes.** Code truth, the flow map and the state machines are in [`01-research.md`](./01-research.md); appendices `01a`–`01d` hold the `path:line` detail.

---

## Industry baseline (benchmark-first — convention 10)

The full 50-row baseline table lives in **[`01-research.md` §3](./01-research.md#3-industry-baseline-benchmark-first--convention-10)** — reference rows here by id (`B7`, `B21`, …); it is not duplicated. Reference systems and their sources (S1–S9, including which cells are "from memory") are in [`01d-research-industry-baseline.md`](./01d-research-industry-baseline.md).

The five rows that most shape this matrix, inline:

| # | Guarantee | Odoo | ERPNext | Dolibarr | AutoERP today (`path:line`) | Gap | Decision |
|---|---|---|---|---|---|---|---|
| **B7** | **Short-close**: a partially received PO can be declared finished so it stops showing as outstanding, without phantom receipts | ✅ Close Order | ✅ Close/Re-open | ✅ classify | nothing — `Received` is written only on a full receipt (`GoodsReceiptService.php:727-734`); with a remainder the PO is `Confirmed` forever and can be neither reverted (`DocumentPostingService.php:605-607`) nor deleted (`DocumentStatus.php:30-36`) | MISSING | MATCH — lane `L-2`. **OWNER-RULING NEEDED: does a short-close write `Received`, a new `Closed` status, or a payload flag?** |
| **B21** | **Over-receipt has an explicit policy** — blocked, or a tolerance %, or a privileged role; never a silent accept | ⚠ warns and allows | ✅ % + role override | ⚠ allows | hard refusal at scale 4, no tolerance, no role, no setting (`GoodsReceiptService.php:309-332`), enforced twice (`:157`, `:496`) | PARTIAL | **OWNER-RULING NEEDED: adopt ERPNext's tolerance % + over-receive role, or keep the hard block?** (the hard block is *safer* than the baseline — a deliberate-divergence candidate, not a defect) |
| **B26** | **Free/bonus quantity** enters stock with a controllable valuation rate, so free stock does not dilute the moving average toward zero | ✅ | ✅ valuation rate | ✅ | live by default for TN parapharmacy (`verticals.php:360`); free units always blend at `'0'` (`GoodsReceiptService.php:574`) ⇒ WAC is dragged down; the true blended figure exists but is reporting-only (`:839-851`, stored `:696-700`) | PARTIAL | **OWNER-RULING NEEDED: is WAC dilution by bonus units the intended parapharmacy costing, or should `effective_unit_cost` become the WAC input?** |
| **B37** | **3-way match** surfaces qty and price mismatch with a filterable status | ⚠ advisory, does not block | ✅ blocking over-billing % | ⚠ manual | qty variance/exception **block regardless of `match_enforcement`** (`SupplierInvoiceMatcher.php:207-231`); price variance advisory under `Warn` (`:255-261`), dual AND threshold (`:505-533`) | OK | **OWNER-RULING NEEDED: keep ERPNext-style hard qty blocking, and is 2 % / 1.000 TND (`ProcurementPolicy.php:107-108`) the right default for TN parapharmacy?** |
| **B48** | **Role permissions**: a receiver/cashier can receive goods but cannot confirm a PO or post a bill — **submit ≠ create** | ✅ | ✅ | ✅ | PO routes split correctly (`Document/Presentation/routes.php:287-305`), **but the whole supplier-invoice write surface is gated on the generic `documents.update`** (`Procurement/Presentation/routes.php:100-120`) which `cashier` holds (`RolesAndPermissionsSeeder.php:671`), as is `POST /documents/{id}/revert` (`routes.php:84-87`) and `payments.create` (`:682`) | WRONG | MATCH — lane `L-14` (**P1**) |

**Second-of-everything (convention 09):** every mutating class carries a second-company arm, a second-location arm and a re-run arm — enumerated in class **W2-SEC** and cross-referenced from each class's own rows. **Concepts (convention 11):** wave 2 introduces **no new noun** — no table, no import type, no operator surface; it only observes. Any fix lane spawned from it runs its own convention-11 check.

---

## Reading the matrix

- **Drive** — `UI` = through the browser (**mandatory for the happy path of every class**), `API` = `apiRequest(page, …)` shape probe, `SQL` = `psql` against `tenant_<uuid>`.
- **Oracle** — the `path:line` that *defines* the expectation. A row with no oracle is not gate-ready.
- Every expected figure is computed by hand below and marked **[derived]** — no figure in this document has been executed. The run turns each into **[measured]** in the evidence doc.
- A figure that depends on an unresolved owner ruling is marked **[RULING]** and must not be asserted as a hard expectation until Q-n is answered.
- **Every scenario** additionally asserts: zero `5xx` on `page.on('response')` and zero console errors except the single tolerated `/auth/me` 401 on `/login` (until lane K-10 merges) — named explicitly in the evidence line whenever it is tolerated.

### Fixture money (all classes)

| id | Product | batch-tracked | unit | purchase price | VAT |
|---|---|---|---|---|---|
| **P1** | vertical default (tracked) | ✅ | `pc` (0 dp) | `10.500` | 19 % |
| **P2** | vertical default | ✅ | `pc` | `3.250` | 7 % |
| **P3** | vertical default | ✅ | `pc` | `4.000` | **0 % (Exonéré)** |
| **P4** / **P4b** | explicit `requires_batch_tracking: false` | ❌ | `pc` | `10.000` | 19 % |
| **P5** | tracked **+ one active variant** | ✅ | `pc` | `5.000` | 19 % |
| **S1** | a `Service` (no `product_id` on the line) | n/a | — | `25.000` | 19 % |

Rates come from the seeded TN set (`TunisiaTaxConfigurationSeeder.php:22-46`) resolved by `tax_configuration_id`, never by a typed `tax_rate`, so the resolver's precedence (`DocumentLineTaxResolver.php:61-97`) is exercised as the operator's UI does it. **Expected timbre on every PO and supplier invoice = `0.000`** — both are `fiscal_category = NonFiscal` (`FiscalCategory.php:38-47`) and the active TN stamp rows list only `TAX_INVOICE` / `CREDIT_NOTE` (`TunisiaTaxConfigurationSeeder.php:84-146`). Assert it; do not assume it.

---

## Class W2-SETUP — fixture bootstrap (6)

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-SETUP-1** | none | **UI** `registerFreshTenant(page)` | 201, tenant provisioned, shell reachable; `units` non-empty with `pc` at `decimal_places = 0`; TN VAT set = {19 default, 13, 7, 0}; chart of accounts resolves all 7 purposes (37/401/408/4456/6354/6585/7585) by `system_purpose` | screenshot `setup-01-dashboard.png`; `GET /units`, `GET /tax-configurations`; SQL `SELECT system_purpose, code FROM accounts WHERE company_id=…` | `journey.ts:282-388`; `TenantInitializationService.php:213-320`; `TunisiaTaxConfigurationSeeder.php:22-46`; `ChartOfAccountsService.php:49-90` | B46 |
| **W2-SETUP-2** | SETUP-1 | **UI** `runImportWizard(page,'parties', e2e-local/real-fournisseurs.xlsx)` | 11 suppliers imported, 0 failures; pick `SUP-A` (company 1) and `SUP-B` (company 2) | wizard complete screenshot; `GET /partners?type=supplier` count = 11 | `journey.ts:514-561` | B46 |
| **W2-SETUP-3** | SETUP-1 | **API** create P1..P5 + S1 per the fixture table; **P4/P4b MUST send `requires_batch_tracking: false`** | products created; `GET /products/{P1}` → `requires_batch_tracking = true` (vertical default, not sent); `{P4}` → `false` | `GET /products/{id}` bodies | `verticals.php:342`; `Product.php:186-212`; the same workaround at `w4-support.ts:150-173` | B23 |
| **W2-SETUP-4** | SETUP-1 | **API** create a **second location** `WH` in company 1 (`is_active: true`, not default) | 2 active locations; `MAIN` is `is_default` | `GET /locations` | `CreateDocumentRequest.php:93-99`; `GoodsReceiptService.php:966-1008` | B27 |
| **W2-SETUP-5** | SETUP-1 | **UI** create a **second company** through the real company-creation path; then **API** create its own location + P1'/P4' + import `SUP-B` | company 2 exists with its own seeded units, payment methods, repositories and chart of accounts | `GET /units`, `GET /payment-repositories`, `SELECT … FROM accounts` with `X-Company-Id = c2` | convention 09; `HandlesDocuments.php:44-49` | B46 |
| **W2-SETUP-6** | SETUP-1 | **API** `POST /users {name, email, role:'cashier'}`, then **SQL** `UPDATE users SET password='<bcrypt>', status='active' WHERE email=…`, then log in in a **second browser context** | cashier session established; `GET /auth/me` shows role `cashier` | the exact SQL, verbatim, in the evidence doc | `Identity/routes.php:66-68`; `UserController.php:217-230` (random password + `PendingVerification`), `:757-802` (reset only emails); `CreateUserRequest.php:45-50` (no password field) | B48 |

---

## Class W2-HP — happy path, **Unit** price mode, UI (6)

**PO-A** (company 1, location `MAIN`, supplier `SUP-A`): L1 `P1 × 6.0000 @ 10.500` 19 % · L2 `P2 × 4.0000 @ 3.250` 7 % · L3 `P3 × 5.0000 @ 4.000` 0 %.
Hand arithmetic **[derived]**: nets `63.000 / 13.000 / 20.000` → **subtotal `96.000`**, taxes `11.970 / 0.910 / 0.000` → **tax_amount `12.880`**, timbre `0.000`, **total `108.880`**.

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-HP-1** | SETUP-1..4 | **UI** `/purchases/orders/new` — pick `SUP-A`, add the 3 lines via the line editor, Save | 201 → redirect to `…/edit`; `status = draft`; **`document_number` is NULL**; `subtotal 96.000`, `tax_amount 12.880`, `total 108.880`; `currency TND` | screenshot `hp-01-po-draft.png`; `GET /purchase-orders/{id}` body | `PurchaseOrderController.php:387-389` (number NULL), `:398-412` (header totals), `:414` (currency); redirect `DocumentForm.tsx:405-410` | B1, B10 |
| **W2-HP-2** | HP-1 | **UI** Confirm on the detail page | 200; `status = confirmed`; `document_number` matches `/^PO-2026-0001$/`; `confirmed_at`/`confirmed_by` set; `tax_amount 12.880`, `total 108.880` unchanged; **`subtotal` still `96.000`** (confirm never rewrites it); `payload.costs_allocated_at` present; `document_lines.landed_unit_cost` = `10.500000 / 3.250000 / 4.000000`, `allocated_costs = 0.000` | screenshot `hp-02-po-confirmed.png`; SQL on `documents` + `document_lines` | `PurchaseOrderService.php:127` (transition+number), `:136-139` (subtotal NOT rewritten), `:145`; `DocumentNumberingService.php:44-66`; `LandedCostService.php:230-236`, `:345-351` | B1, B8 |
| **W2-HP-3** | HP-2 | **UI** Receive Goods → prefilled full remainder, fill batch number + expiry per line (`LOT-A1/A2/A3`, `2027-12-31`), Save and post | 200; PO `status = received`; `payload.fully_received = true`, `goods_received_at` stamped; GRN `/^GRN-2026-0001$/`; `quantity_received` = ordered on all 3 lines | screenshot `hp-03-receive-dialog.png` + `hp-04-po-received.png`; `meta.goods_receipt` from the response | `GoodsReceiptService.php:254-263`, `:670`, `:727-734`, `:928-942`; dialog `ReceiveGoodsDialog.tsx:207-250` | B17, B19, B20, B23 |
| **W2-HP-4** | HP-3 | **SQL/API** stock + WAC | `stock_levels` at `MAIN`: P1 `6.0000`, P2 `4.0000`, P3 `5.0000`; `products.cost_price` = `10.500000 / 3.250000 / 4.000000`; exactly **3** `stock_movements` of type `Receipt` with `reference = 'PO-2026-0001'`, `reference_id = po.id`; 3 `batch_stocks` rows | `GET /inventory/stock-matrix`; `GET /products/{id}`; SQL | `WeightedAverageCostService.php:236-284`; `BatchStockService.php:397-413` | B32 |
| **W2-HP-5** | HP-3 | **SQL** GR-IR ledger | **3** `journal_entries` with `source_type='goods_receipt'`, one per movement id; each Dr `Inventory` / Cr `GoodsReceivedNotInvoiced`, amounts `63.000 / 13.000 / 20.000`; `partner_id` NULL on every leg; **no VAT leg**; Σ 408 credit = `96.000`; every entry balances | SQL query 4 of `01c §8` adapted to `goods_receipt` | `GeneralLedgerService.php:2044-2134`, `:2074-2075`, `:2098-2102` | B32 |
| **W2-HP-6** | HP-3 | **API** re-read P1's sale price before and after the receipt | record whether `products.selling_price` changed as a side effect of the receipt (F-W2-25). **No hard expectation** — this row *measures* B26-adjacent behaviour | before/after `GET /products/{P1}` | `WeightedAverageCostService.php:300`, `:316-337` | — |

---

## Class W2-TOT — **Total** price mode, UI (5) — K1-S1-12 evidence

`PriceEntryMode::Total` is **UI-reachable on a fresh TN parapharmacy tenant**: `PurchaseBonus` is in `default_modules` (`verticals.php:360`) and the toggle renders when `hasModule('PurchaseBonus') && companyConfig.purchase_bonus_enabled` (`DocumentLineEditor.tsx:334-337`, gate served by `CompanyConfigController.php:80`). **The fix belongs to lane K-1 task 4.2 — this class produces evidence only.**

**PO-B**: single line `P1 × 10.0000`, 19 %, operator types **`100.000`** into the Total cell.

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-TOT-1** | SETUP-1..3 | **UI** `/purchases/orders/new`, toggle the mode button to Total (`aria-pressed=true`), type total `100.000` on a `P1 × 10` 19 % line | the toggle **is visible** (proves the module gate is on for tenant #1); the editor shows unit `10.000` and a line total of `119.000` | screenshot `tot-01-editor.png` showing both figures | `DocumentLineEditor.tsx:334-337`, `:848-866`, `:339-368`, `:142-151`, `:590` | B10 |
| **W2-TOT-2** | TOT-1 | **UI** Save, then read the row back | **[derived, the bug]** `document_lines.line_total = 119.000` (a GROSS value in the NET column), `unit_price = 11.900`, `landed_unit_cost = 11.900000`; header `subtotal 119.000`, `tax_amount 22.610`, `total 141.610`. **Correct values are `100.000 / 10.000 / 10.000000 / 100.000 / 19.000 / 119.000`** — a `19.000` VAT inflation of the net base and `3.610` of VAT-on-VAT | SQL on `documents` + `document_lines`; the payload captured from `page.on('request')` | `PurchaseOrderController.php:100-105` (trusted client value), `:122` (written back), `:398-412` (header); `linePayload.ts:106` (ships it verbatim) | B10 |
| **W2-TOT-3** | TOT-2 | **UI** Confirm, then **UI** receive all 10 with a lot | **[derived]** confirm leaves `subtotal 119.000` / `tax 22.610` / `total 141.610` — **internally self-consistent**, so the defect is invisible to a self-check and is only visible against the `100.000` the operator typed (record that explicitly). Receipt: `products.cost_price = 11.900000` (should be `10.000000`); GR-IR entry `119.000` (should be `100.000`) ⇒ **19.000 TND of VAT capitalised into inventory and into the 408 accrual** | screenshots; SQL on `products`, `journal_lines` | `TaxCalculationService.php:348-376`; `GoodsReceiptService.php:529`, `:623`; `GeneralLedgerService.php:2064-2068` | B10, B32 |
| **W2-TOT-4** | SETUP-1..3 | **UI** a second Total-mode PO on **P3 (0 % VAT)**, `qty 7.0000`, typed total `100.000` — isolates the header-honesty defect from the gross defect (at 0 % VAT gross == net) | **[derived]** stored `line_total 100.000`, `unit_price = trunc(100.000/7) = 14.285`; store header `subtotal 100.000`, `total 100.000`; **after confirm** `TaxCalculationService::calculateSubtotal` re-derives `7 × 14.285 = 99.995` ⇒ `documents.total = 99.995` while `documents.subtotal` stays `100.000`. **The header does not add up with zero tax.** | SQL before and after confirm | `PurchaseOrderController.php:100-105`; `PurchaseOrderService.php:136-139`; `TaxCalculationService.php:348-376` | B15 |
| **W2-TOT-5** | SETUP-1..3 | **API** POST a line with `price_entry_mode:'total'` and `line_total:'100.0001'` (4 dp); and a second POST with `price_entry_mode:'total'` and **no** `line_total` | both 422 with the per-field message; nothing persisted | response bodies | `CreateDocumentRequest.php:125` (3-dp ceiling), `:211-213` (required-when-total); pinned by `PurchaseBonusQuantityEntryTest.php:217-256` | B15 |

---

## Class W2-PART — partial receipt in 2 tranches → invoice via `source_document_ids` → payment (8)

**PO-C**: single line `P1 × 10.0000 @ 10.500`, 19 %. **[derived]** subtotal `105.000`, tax `19.950`, total `124.950`.

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-PART-1** | SETUP-1..4 | **UI** create + confirm PO-C | `PO-2026-000n`; `landed_unit_cost 10.500000` | screenshot; SQL | `PurchaseOrderService.php:127`, `:145` | B1 |
| **W2-PART-2** | PART-1 | **UI** receive tranche 1 = `4.0000`, lot `LOT-T1` exp `2027-12-31` | **[derived]** `quantity_received 4.0000`; PO `status` stays **`confirmed`** (there is no `partially_received` status); `payload.fully_received = false`, `payload.goods_received_at = null`; stock `4.0000`; `cost_price 10.500000`; one GR-IR entry `42.000`; `accrual_unit_cost = 10.500000` set for the first time | screenshot `part-02.png`; SQL | `GoodsReceiptService.php:670`, `:727-734`, `:666-668` | B19, B20 |
| **W2-PART-3** | PART-2 | **API** `GET /purchase-orders/{C}/receipt-status` | `status = 'partially_received'`, received 4, ordered 10, percentage 40 | response body | `GoodsReceiptService.php:876-923` | B20 |
| **W2-PART-4** | PART-2 | **UI** receive tranche 2 = `6.0000`, lot `LOT-T2` exp `2028-06-30` | **[derived]** PO `status = received`, `fully_received = true`, `goods_received_at` stamped; stock `10.0000`; `cost_price 10.500000`; a second GR-IR entry `63.000` ⇒ Σ408 `105.000`; **two lots**: `LOT-T1 = 4.0000`, `LOT-T2 = 6.0000` | screenshot; SQL on `batch_stocks` | `:727-734`, `:928-942`; `BatchStockService.php:359-368` | B19, B24 |
| **W2-PART-5** | PART-4 | **UI** `/purchases/supplier-invoices/new` → pick `SUP-A`, select PO-C, prefill from receipt lines, submit | 201; `SI-2026-0001`; one line `qty 10.0000 @ 10.500 vat 19`; **`line_total = 105.000` is NET** (VAT sits in `tax_amount`); subtotal `105.000`, tax `19.950`, stamp `0.000`, total `124.950`; `match_status = matched`; `price_match_basis` + `matched_receipt_line_id` stamped | screenshot `part-05-invoice.png`; SQL | `CreateSupplierInvoiceService.php:185` (NET), `:181-183`, `:70`, `:219-221`, `:159-166` | B35, B37 |
| **W2-PART-6** | PART-5 | **UI** `btn-post` | **[derived]** 200; `status = posted`; `balance_due = 124.950`; ONE journal entry `source_type='supplier_invoice'`: **Dr 408 `105.000`** (accrued = 4×10.500000 + 6×10.500000), **Dr 4456 `19.950`**, **Cr 401 `124.950` partner-tagged**; **no PPV leg** (priceDelta 0), **no Inventory plug** (plug 0); debits == credits; `goods_receipt_lines.quantity_invoiced` = `4.0000` and `6.0000` | screenshot; SQL query 4 of `01c §8` | `SupplierInvoicePostingService.php:343-346`, `:458-532`; `GeneralLedgerService.php:2253-2348`, `:2352-2365` | B33, B39 |
| **W2-PART-7** | PART-6 | **UI** `btn-record-payment`, amount `50.000`, a ledgered repository | **[derived]** 201; `balance_due = 74.950`; `status` stays `posted`; JE `source_type='supplier_payment'` **Dr 401 `50.000` / Cr repository**; `repository_movements` one `out` row `50.000`; `partners.payable_balance = 74.950` (refreshed asynchronously — poll) | screenshot; SQL queries 1, 2, 3, 5 of `01c §8` | `PaymentController.php:1078-1092`, `:1184-1195`, `:1347-1367`; `GeneralLedgerService.php:994-1062` | B40 |
| **W2-PART-8** | PART-7 | **UI** second payment `74.950` | **[derived]** `balance_due = 0.000`; `status = paid`; `DocumentFullyPaid` fired; Σ Dr 401 for this partner = `124.950` = Σ Cr 401; `partners.payable_balance = 0.000` | screenshot; the three-way SQL | `PaymentController.php:1098-1128`; `DocumentType.php:123-129` | B40 |

---

## Class W2-OVER — over-receipt refusal (4)

**PO-G**: single line `P4 × 10.0000 @ 10.000`, 19 %, confirmed, nothing received.

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-OVER-1** | PO-G | **API** `POST …/receive {quantities:{line:'10.0001'}}` | 422 `GOODS_RECEIPT_FAILED`, message matching `/^Cannot receive more than ordered for line [0-9a-f-]{36}\./` — **the PO line UUID is leaked to the operator** (F-W2-27); the whole receipt rolls back: `goods_receipts` count unchanged, `stock_levels` unchanged, zero new `journal_entries` | response body; before/after counts | `GoodsReceiptService.php:309-332`; rollback pinned by `GoodsReceiptLedgerWriteTest.php:361` | **B21** |
| **W2-OVER-2** | PO-G | **UI** open the receive dialog and type `10.0001` | both footer buttons **disabled**; no request leaves the browser | screenshot `over-02.png`; `page.on('request')` shows no POST | `ReceiveGoodsDialog.tsx:160-171`, `:184-189`, `:449`, `:454` | B21 |
| **W2-OVER-3** | PO-H (free qty, below) | **API** receive `free_quantities` above `free_quantity` | 422 with the free-specific message; free ceiling is **independent** of the paid one | response body | `GoodsReceiptService.php:324-331`; pinned `PurchaseBonusGoodsReceiptTest.php:182` | B21, B26 |
| **W2-OVER-4** | PO-G | **API** `quantities:{line:'10.00005'}` (5 dp) | **422 at validation** (quantity regex caps at 4 dp) — the `bccomp(…,4)` truncation window (F-W2-11) is therefore **NOT reachable over HTTP**; record that as the finding's disposition | response body | `ReceiveGoodsRequest.php:28`; `GoodsReceiptService.php:315` | B21 |

---

## Class W2-UNDER — under-receipt and the missing close (3)

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-UNDER-1** | PO-G confirmed | **UI** receive `6.0000` of `10.0000` | PO stays `confirmed`; `receipt-status` = `partially_received`, remaining `4.0000`; stock `6.0000` | screenshot; response | `GoodsReceiptService.php:727-734`, `:876-923` | B22 |
| **W2-UNDER-2** | UNDER-1 | **UI** hunt the detail page for any close / cancel / short-close affordance, then **API** `POST /documents/{G}/revert` and `DELETE /purchase-orders/{G}` | **no such control exists** in the UI; revert → 422 `PURCHASE_ORDER_HAS_RECEIPTS`; delete → 422 `DOCUMENT_NOT_DELETABLE`. **The PO is permanently outstanding (F-W2-05).** Capture the full detail-page action bar as the evidence that nothing is offered | screenshot `under-02-no-close.png`; both response bodies | `DocumentPostingService.php:605-607`; `DocumentStatus.php:30-36`; `PurchaseOrderDetailPage.tsx` (grep `delete` = 0) | **B7**, B22 |
| **W2-UNDER-3** | new PO: `P4 × 5.0000` **plus** a service line `S1 × 1.0000` | **API** confirm, then **UI** receive the full `5.0000` of the goods line | **[derived]** the goods line is fully received, the service line is skipped by the receipt loop, and `isFullyReceived()` still demands `quantity_received >= quantity` for it ⇒ **the PO can never reach `received`** and, per UNDER-2, can never be closed either (F-W2-04) | screenshot showing `confirmed` with 100 % of goods in; SQL on `document_lines` | skip `GoodsReceiptService.php:503-505`, `:519-521`; demand `:928-942` | **B7**, B20 |

---

## Class W2-LOT — batch lots, expiry, and the F-SOE-2 shape (8)

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-LOT-1** | PO on `P1 × 6.0000`, confirmed | **UI** open the receive dialog | batch-number and expiry inputs **render** for the tracked line (`aria-label`-addressed); both buttons disabled while either is blank | screenshot `lot-01.png` | `ReceiveGoodsDialog.tsx:77-79`, `:404-422`, `:173-189` | **B23** |
| **W2-LOT-2** | LOT-1 | **UI** fill `LOT-1` / `2027-06-30`, Save and post | 200; `batches` row created with that number and expiry; `batch_stocks` `6.0000` at `MAIN`; `document_lines.batch_id` set; aggregate `stock_levels` also `6.0000` | screenshot; SQL on `batches`/`batch_stocks`/`stock_levels` | `GoodsReceiptService.php:556-567`, `:653-661`, `:673-675`; `BatchStockService.php:340-413` | B23, B24 |
| **W2-LOT-3** | SETUP-3 | **UI** inspect the expiry input on a fresh receive dialog | **no default is offered** even though the product carries `default_shelf_life_days` — the operator must type it (B24 gap). Record the field's initial value | screenshot | `ReceiveGoodsDialog.tsx:414-422`; the unused default at `ParapharmacySeeder.php:1343-1347` | **B24** |
| **W2-LOT-4** | PO on `P4` (untracked) | **API** receive with a `batches` entry for that line | 200, and **the batch is silently dropped** — no `batches` row, no `batch_stocks` row, no warning. Assert the silence explicitly | response + SQL showing zero lot rows | `GoodsReceiptService.php:557` (the `&& requires_batch_tracking` conjunction) | B23 |
| **W2-LOT-5** | PO on `P1`, confirmed | **API** `POST …/receive` with an **empty body `{}`** | **[F-SOE-2]** 422 `GOODS_RECEIPT_FAILED`, message exactly `Batch data is required for batch-tracked product <uuid>` — a raw product UUID with no code or designation. Then **UI**: capture `page.on('request')` across a full dialog submit and prove `quantities` is always present ⇒ **the defect is API-only** | response body; the captured UI request payload | `GoodsReceiptService.php:795` (`$batchData = []`), `:523-525`; branch `PurchaseOrderController.php:841-843`; UI proof `ReceiveGoodsDialog.tsx:239-249` + the OR at `PurchaseOrderController.php:829` | **B23** |
| **W2-LOT-6** | PO on `P1`, confirmed | **API** receive with `expiry_date: '2020-01-01'` | **[derived]** 200 — an already-expired lot is accepted (`is_expired` is written `false` unconditionally). Then assert FEFO excludes it ⇒ stock visible and unsellable (F-W2-09) | response; SQL on `batches.is_expired`; a FEFO read | `ReceiveGoodsRequest.php:31-34` (no `after:today`); `BatchStockService.php:378`, note `:416-422` | **B24**, B25 |
| **W2-LOT-7** | LOT-2 done | **API** receive a second tranche with **the same `batch_number` and a different expiry** | **[derived]** 200; **one** lot, quantity topped up, and **the first expiry silently kept** (F-W2-10) | SQL on `batches` (one row) | `BatchStockService.php:359-368` | B24 |
| **W2-LOT-8** | PO on **P5** (tracked + active variant), confirmed | **API** receive with a valid batch | **[derived]** hard failure — `findOrCreateBatch` is called without `variantId` and throws `MissingVariantException` (F-W2-12). Record the exact HTTP status and message: a `\DomainException` maps to 422, any other exception class surfaces as **500** | response body incl. status | call site `GoodsReceiptService.php:556-567`; throw `BatchStockService.php:349-355`; mapping `PurchaseOrderController.php:857-865` | B23 |

---

## Class W2-LOC — receiving to a second location (4)

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-LOC-1** | PO-C confirmed at `MAIN`, SETUP-4 | **UI** receive tranche 1 `4.0000` with the destination selector set to **`WH`** | stock lands at **`WH`**, `MAIN` unchanged; `goods_receipts.location_id = WH` | screenshot `loc-01-destination.png`; per-location `GET /inventory/stock-matrix` | `ReceiveGoodsDialog.tsx:290-302`; `GoodsReceiptService.php:222-224`, `:984-1008` | **B27** |
| **W2-LOC-2** | LOC-1 | **UI** receive tranche 2 `6.0000` back at `MAIN` | stock splits `WH 4.0000` / `MAIN 6.0000`; **`products.cost_price` is company-wide**, blended across both locations, not per location | stock matrix both locations; `GET /products/{P1}` | `WeightedAverageCostService.php:100-127` (company-wide denominator) | B27, B47 |
| **W2-LOC-3** | LOC-2 | **SQL** read the PO line's `location_id` | **[derived]** it now points at **`MAIN`** — the **last** destination, silently rewritten, so the (now zero) remainder is "expected at" wherever the last tranche went (B27 gap) | SQL on `document_lines.location_id` before and after each tranche | `GoodsReceiptService.php:677-678` | **B27** |
| **W2-LOC-4** | company 2 exists (SETUP-5) | **API** receive a company-1 PO passing **company 2's** location id; then a company-1 **inactive** location id | foreign company → **422** `Receiving destination is not available for this company.` (the HTTP membership layer passes it because `allowed_location_ids` is null — it checks membership, not ownership); inactive → **422**. Contrast the **403** `LOCATION_FORBIDDEN` produced when `allowed_location_ids` is narrowed | both response bodies with their status codes | layer 1 `PurchaseOrderController.php:800-806` + `LocationContext.php:224-239`; layer 2 `GoodsReceiptService.php:990-1002`; 403 pinned by `GoodsReceiptDestinationTest.php:183`, `:206-212` | B46, B27 |

---

## Class W2-DRAFT — draft receipt, then post (6)

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-DRAFT-1** | PO on `P1 × 6.0000`, confirmed | **UI** receive dialog → fill qty + batch → **"Save draft"** | 201; `goods_receipts.status = draft`, **`receipt_number` NULL**; **zero** `stock_movements`, zero `journal_entries`; the Drafts tab count is 1 | screenshot `draft-01-tab.png`; SQL | `GoodsReceiptService.php:130`, `:107-201`; `ReceiveGoodsDialog.tsx:446-453`; drafts tab `GoodsReceiptListPage.tsx:548-567`; pinned `GoodsReceiptLedgerWriteTest.php:170` | **B18** |
| **W2-DRAFT-2** | DRAFT-1 | **UI** Drafts tab → Post (confirm the `window.confirm`) | 200; `status = posted`, `receipt_number` `/^GRN-2026-\d{4}$/`; stock and the GR-IR entry land now | screenshot; SQL | `GoodsReceiptService.php:203-292`, `:254-263`; `GoodsReceiptListPage.tsx:645-654` | B18 |
| **W2-DRAFT-3** | DRAFT-2 | **API** `POST /goods-receipts/{id}/post` again | 422 — "must be Draft before posting"; no second movement, no second entry | response; counts | `GoodsReceiptService.php:212-214` | B8 |
| **W2-DRAFT-4** | PO on `P1`, confirmed | **API** `POST …/receive {save_as_draft:true, quantities:{…}}` with **no `batches`** | **[derived]** 201 — `createDraft` performs no batch check. Then `POST /goods-receipts/{id}/post` → **422 forever**; `GET`/`PATCH` route inventory shows **no PATCH/PUT** exists, so the draft is uncorrectable (F-W2-19) | both responses; a route dump proving no PATCH | `GoodsReceiptService.php:107-201` vs `:523-525`; `Inventory/Presentation/routes.php:156-177` | **B18** |
| **W2-DRAFT-5** | DRAFT-4 | **API** `PATCH /purchase-orders/{id}` with a new `lines` array while the stale draft exists | 422 `PO_LINES_LOCKED_BY_RECEIPTS` — a **draft** receipt already locks the PO lines (`poLineIdsWithReceipts` has no status filter) | response body | `GoodsReceiptService.php:802-818`; `PurchaseOrderController.php:539-554` | B3, B18 |
| **W2-DRAFT-6** | DRAFT-5 | **UI** Drafts tab → Delete, then re-`PATCH` the PO | 204, the draft is **hard-deleted with no audit row and no event**; the PO-line lock is released and the PATCH now succeeds; the GRN sequence does **not** skip a number (it is minted at post, not at draft) | screenshot; SQL showing no tombstone; the next GRN number | `GoodsReceiptController.php:139-158`; `GoodsReceiptService.php:254-260` | **B28**, **B49** |

---

## Class W2-REV — revert / delete at each state (7)

There is **no PO cancel endpoint** — `DocumentStatus::Cancelled` is never written for a purchase order. Every row here reflects that.

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-REV-1** | a Draft PO | **API** `DELETE /purchase-orders/{id}` | 204; soft-deleted; absent from the list | response; `GET /purchase-orders` | `PurchaseOrderController.php:642-670`; `DocumentStatus.php:30-36` | **B4** |
| **W2-REV-2** | a Draft PO | **API** `POST /documents/{id}/revert` | 200, **silent no-op**, still Draft | response | `DocumentPostingService.php:520-523` | B8 |
| **W2-REV-3** | a Confirmed PO, no receipts | **UI** Revert on the detail page | 200; `status = draft`; `confirmed_at`/`confirmed_by` NULL; **`document_number` is KEPT** | screenshot `rev-03.png`; SQL | `DocumentPostingService.php:635-638`; `DocumentStatusService.php:206-211` | **B5** |
| **W2-REV-4** | REV-3 | **UI** Confirm again | 200; **the same `document_number`** — no second number burned | SQL comparing before/after | `DocumentStatusService.php:186-220` (conditional update requires `document_number IS NULL`) | B8 |
| **W2-REV-5** | a Confirmed PO | **API** `DELETE /purchase-orders/{id}` | 422 `DOCUMENT_NOT_DELETABLE` | response | `PurchaseOrderController.php:663-665`; `DocumentStatus.php:30-36` | B5 |
| **W2-REV-6** | a Confirmed, numbered, cost-allocated PO with **no receipts** | **API** `PATCH /purchase-orders/{id}` changing qty, price and the partner | **[F-W2-06]** 200 — a confirmed PO is editable. Assert: every line id **changed** (delete+recreate); `subtotal/tax_amount/total` rewritten; and **`document_tax_details` and `document_lines.landed_unit_cost` are STALE** (no re-snapshot, no re-allocation). Compare `landed_unit_cost` against the new `line_total` — they must disagree | SQL on `document_lines` (ids + landed cost) and `document_tax_details` before/after | `DocumentStatus.php:19-25`; `PurchaseOrderController.php:514-516`, `:559-563`, `:575-618`; nothing re-calls `PurchaseOrderService.php:142`/`:145` | **B3**, B49 |
| **W2-REV-7** | a Confirmed PO with **one partial receipt** | **API** revert; **API** delete | revert 422 `PURCHASE_ORDER_HAS_RECEIPTS`; delete 422 `DOCUMENT_NOT_DELETABLE`; **received stock is untouched by both** (assert `stock_levels` before/after) | both responses; stock before/after | `DocumentPostingService.php:605-607`; `DocumentStatus.php:30-36` | **B6**, B7 |

---

## Class W2-PRICE — price override at receipt (6)

**PO-I**: single line `P4 × 10.0000 @ 10.000`, 19 %, confirmed.

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-PRICE-1** | PO-I, admin (holds `goods-receipt.edit-price`) | **UI** receive 10 with delivered price `11.000` + reason "Delivery note price" | **[derived]** 200; `cost_price = 11.000000`; `goods_receipt_lines.received_unit_price = 11.000`, `price_override_old_basis = 10.000000`, `price_override_by`/`_at` set, reason stored; **`document_lines.unit_price` stays `10.000`** — the PO price never moves; GR-IR entry `110.000`; `accrual_unit_cost = 11.000000` | screenshot `price-01.png` (incl. the variance badge); SQL | `GoodsReceiptService.php:542-547`, `:704-707`, `:666-668`; UI `ReceiveGoodsDialog.tsx:378-396`, `:430-440` | **B29** |
| **W2-PRICE-2** | cashier session (SETUP-6) | **UI** open the receive dialog **as an admin-created PO but a cashier viewer** — and **API** POST `received_unit_prices` as a user without `goods-receipt.edit-price` | UI shows the price **read-only** with `priceEditReadOnly` helper text; the API returns **422** (Laravel `prohibited`), **not 403 and not a silent ignore** | screenshot; response body + status | `ReceiveGoodsDialog.tsx:126-128`, `:390-396`; `ReceiveGoodsRequest.php:22-36` | B48 |
| **W2-PRICE-3** | PO-I | **API** override to `'0'`, to `'-1.000'`, and to `'11.0001'` (4 dp) | `0` → 422 `received_unit_price must be greater than zero for line …`; negative → 422 (regex has no `-?`); 4 dp → 422 | three response bodies | `GoodsReceiptService.php:160-166`, `:532-541`; `ReceiveGoodsRequest.php:36` | B29 |
| **W2-PRICE-4** | a draft receipt created **by an admin** carrying an override | **API** post it as a **manager without** `goods-receipt.edit-price` | 422 `GOODS_RECEIPT_POST_FAILED` / "User is not allowed to apply goods receipt price overrides." — the permission is re-checked at post, not only at create | response | `GoodsReceiptService.php:334-349`; `GoodsReceiptController.php:125-131` | B48 |
| **W2-PRICE-5** | PRICE-1 | **UI** create + post a supplier invoice for PO-I at the **PO** price `10.000` | **[derived]** `match_status = price_variance` (basis is the **receipt accrual** `11.000000`, not the PO price: extendedVariance `10.000` fails both the 2 % arm `2.200` and the `1.000` max arm); under default `Warn` it still **posts**, and the API returns `warning: 'Price variance detected…'` which **the FE never renders** — the operator sees only the match badge. GL: **Dr 408 `110.000`, Dr 4456 `19.000`, Cr 7585 (PPV Income) `10.000`, Cr 401 `119.000`**; debits `129.000` == credits | screenshot of the match table + badge; SQL on `journal_lines` | basis `SupplierInvoiceMatcher.php:541-576`; thresholds `:505-533`; warn `:255-261`; unrendered warning `types.ts:136-137` vs `SupplierInvoiceDetailPage.tsx`; legs `GeneralLedgerService.php:2292-2313` | **B37**, B29 |
| **W2-PRICE-6** | PRICE-5 | **API** `PUT /procurement-policies {match_enforcement:'block'}`, then repeat PRICE-5 on a fresh PO | post → **422 `POSTING_BLOCKED`**; `post-block-reason` visible and `btn-post` disabled in the UI | response; screenshot | `SupplierInvoiceMatcher.php:255-261`; FE `SupplierInvoiceDetailPage.tsx:127`, `:403` | B37 |

---

## Class W2-LAND — landed / additional costs → WAC (6)

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-LAND-1** | **PO-D**: L1 `P4 × 10.0000 @ 10.000`, L2 `P4b × 5.0000 @ 10.000`, both 19 % | **UI** on the PO **edit** page add a `transport` cost of `30.000` **before** confirming, then confirm | **[derived]** allocation **by value**: L1 `100/150 × 30 = 20.000000`, L2 `10.000000`; `landed_unit_cost` = `(100.000+20.000000)/10 = 12.000000` and `(50.000+10.000000)/5 = 12.000000` | screenshot `land-01-costs-form.png`; SQL on `document_lines` | `LandedCostService.php:283-316`, `:345-351`; form `AdditionalCostsForm.tsx` mounted from `DocumentForm.tsx:718`; pinned `LandedCostBcmathTest.php:302,349-354` | **B30** |
| **W2-LAND-2** | LAND-1 | **UI** receive both lines in full | **[derived]** `cost_price(P4) = 12.000000`, `cost_price(P4b) = 12.000000`; two GR-IR entries `120.000` and `60.000` ⇒ **Σ408 = `180.000` while the PO subtotal is `150.000`** | SQL on `products` + `journal_lines` | `WeightedAverageCostService.php:236-253`; `GeneralLedgerService.php:2064-2068` | B30, B32 |
| **W2-LAND-3** | LAND-2 | **UI** create + post a supplier invoice for PO-D at PO prices | **[derived, F-W2-18]** billedHt `150.000` vs accruedHt `180.000` ⇒ priceDelta `−30.000` → **Cr `PurchasePriceVarianceIncome` (7585) `30.000`**; Dr 408 `180.000`, Dr 4456 `28.500`, Cr 401 `178.500`; debits `208.500` == credits. **The freight is capitalised into inventory and offset by a PPV income — there is no liability for it anywhere.** Record it as the measured shape of the finding | SQL on `journal_lines` grouped by `system_purpose`; `document_additional_costs.expense_document_id` = NULL | `GeneralLedgerService.php:2292-2313`, `:2220-2226`; `DocumentAdditionalCostController.php:46-61` | B30, B33 |
| **W2-LAND-4** | **PO-E**: single line `P4 × 10.0000 @ 10.000`, 19 %, confirmed with **no** cost | **UI** receive `5.0000` → **UI** add a `transport` cost `30.000` → **UI** receive the remaining `5.0000` | **[derived]** the cost is accepted after a partial receipt (`payload.goods_received_at` is written **`null`**, so `isset()` is false). At tranche 2, `post()` re-allocates because `payload.costs_allocated_at` is set by **every** confirm: `allocated_costs = 30.000`, `landed_unit_cost = 13.000000`; `ReceiptBatchCostAllocator` prorates `30.000 × 5/10 = 15.000` into this receipt ⇒ tranche 2 lands at `(5×10.000000 + 15.000)/5 = 13.000000`. **`cost_price = (5×10.000000 + 5×13.000000)/10 = 11.500000`**; `document_lines.accrual_unit_cost` **stays `10.000000`** (tranche 1's) while the two receipt lines carry `10.000000` and `13.000000`. **Only `15.000` of the `30.000` freight ever reaches inventory — tranche 1's half is lost (B31).** | screenshots at each step; SQL on `document_lines`, `goods_receipt_lines`, `products` | `LandedCostService.php:452-458`, `:442-447`, `:230-236`, `:245-277`; `GoodsReceiptService.php:227-231`, `:666-668`; `ReceiptBatchCostAllocator.php:48-52` | **B31** |
| **W2-LAND-5** | LAND-4 | **API** `GET /documents/{E}/landed-cost-breakdown` and compare with the persisted values | **[derived]** the preview disagrees — float math, `round(…,2)` against a persisted 6-dp figure, and it sums `additionalCosts()->sum('amount')` with **no `reversed_at` / `application_path` filter** (F-W2-16). Add a second cost and reverse it to widen the gap. **Assert the divergence, and record that this endpoint is never an oracle** | the two figures side by side | `DocumentAdditionalCostController.php:116-164`, `:121-129`, `:138`; contrast `LandedCostService.php:86-96`, `:351` | B30 |
| **W2-LAND-6** | a **second company** whose `tax_status = NON_REGISTERED` | **API** confirm a PO with a 19 % line, then receive it | **[derived, F-W2-17]** at confirm `allocateCostsAndTaxes` capitalises the non-recoverable VAT into `landed_unit_cost`; at the first `post()` `reallocateCosts` runs and passes `'0'` for that term ⇒ **the non-recoverable VAT is silently stripped back out** before any stock is valued. Assert `landed_unit_cost` immediately after confirm vs immediately after post | SQL on `document_lines.landed_unit_cost` at both moments | `LandedCostService.php:152-238` vs `:245-277` (the `'0'` at `:265`); recoverability `TaxCalculationService.php:266` | B31 |

---

## Class W2-VAT — 0 %, mixed rates, and rounding honesty (5)

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-VAT-1** | SETUP-1 | **API** `GET /tax-configurations` | the seeded TN set: 19 % (`is_default`), 13 %, 7 %, 0 % "Exonéré TVA", all `LINE_ITEMS`; stamp rows `STAMP_TAX_INVOICE` (active, `['TAX_INVOICE']`), `STAMP_FISCAL_RECEIPT` (**inactive**), `STAMP_CREDIT_NOTE` | response body | `TunisiaTaxConfigurationSeeder.php:22-46`, `:84-146` | B12, B13 |
| **W2-VAT-2** | PO-A posted as a supplier invoice (HP + a PART-style invoice) | **SQL** read `document_tax_details` for the invoice and the PO | **[derived]** three rate buckets: 19 % base `63.000` tax `11.970`; 7 % base `13.000` tax `0.910`; **0 % base `20.000` tax `0.000`**. **B13 measurement: does a 0 % row exist with a non-zero base, or is it omitted?** Record whichever; an omitted 0 % base is the finding. Also assert `stamp_duty_amount = 0.000` on both documents | SQL on `document_tax_details` | `PurchaseOrderService.php:142`; `TaxCalculationService.php:62`, `:276-320`; `FiscalCategory.php:38-47` | **B13**, B12 |
| **W2-VAT-3** | SETUP-3 | **UI** one PO with 19 % + 7 % + 0 % lines, confirm | **[derived, = PO-A]** `subtotal 96.000`, `tax_amount 12.880`, `total 108.880`; the GL omits the zero leg (no GR-IR entry would be skipped here since every line's *cost* is > 0 — assert 3 entries, one per line) | screenshot of the totals block; SQL | `PurchaseOrderController.php:398-412`; `GeneralLedgerService.php:2070-2072` | B12 |
| **W2-VAT-4** | engineered drift | **API** create a supplier invoice with **3 lines each `qty 1.0000 @ 0.335`, 19 %**, against a matching received PO; then post | **[derived]** per line `bcround(0.335 × 0.19, 3) = 0.064` ⇒ Σ tax `0.192`, subtotal `1.005`, total `1.197`; the per-rate bucket view gives `1.005 × 0.19 = 0.19095 → 0.191` — a **`0.001` drift**. **Two acceptable outcomes:** (a) it posts AND `Σ document_tax_details.amount == documents.tax_amount == 0.192` exactly, or (b) it 422s with "…its deductible VAT is not declarable" and rolls back with **zero** `journal_entries`. **A third outcome — posts with a snapshot that disagrees with `tax_amount` — is a P0** (F-W2-20) | response; SQL on `document_tax_details` and `journal_entries` count | `CreateSupplierInvoiceService.php:96-101`; `SupplierInvoicePostingService.php:415-425`, drift note `:385-389` | **B15**, B12 |
| **W2-VAT-5** | VAT-3 | **SQL** reconcile the PO header | `Σ document_lines.line_total == documents.subtotal` **and** `documents.total == documents.subtotal + documents.tax_amount + documents.stamp_duty_amount`, exactly, at scale 3 — no rounding-adjustment field exists to absorb a residue | SQL | `PurchaseOrderController.php:398-412`; `TaxCalculationService.php:334-335` | **B15** |

---

## Class W2-DISC — line discount and header honesty (4)

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-DISC-1** | SETUP-3 | **UI** PO with `P1 × 10.0000 @ 10.500`, `discount_percent 10.00`, 19 %; save + confirm | **[derived]** `line_total = 105.000 − 10.500 = 94.500`; `subtotal 94.500`, `tax_amount 17.955`, `total 112.455`; **after confirm the recomputation agrees** (`calculateSubtotal` also yields `94.500`) | screenshot; SQL before/after confirm | `DocumentLine::computeLineTotal:280-302`; `TaxCalculationService.php:348-376` | **B11** |
| **W2-DISC-2** | SETUP-3 | **API** one line carrying **both** `discount_percent 10.00` and `discount_amount 5.000` | 201 — nothing forbids it; `computeLineTotal` applies the **percent** and **ignores the amount**, yet **both are persisted**, so the FE hint and the stored number disagree | response; SQL on `document_lines` | `CreateDocumentRequest.php:132-142`; `DocumentLine.php:290-295`; persisted `PurchaseOrderController.php:743-744` | B11 |
| **W2-DISC-3** | SETUP-3, PurchaseBonus on | **UI** **Total** mode on a `P3 (0 % VAT) × 10.0000` line: type total `100.000` **and** `discount_percent 10.00` | **[derived, F-W2-08]** the editor derives unit `= 100.000/(10 × 0.9) = 11.111` and shows a line total of `100.000`; the backend stores `line_total 100.000` and `unit_price 10.000`, **persists the 10 % discount and never applies it**. Header at save: `subtotal 100.000`, `total 100.000`. **After confirm** the recomputation applies the discount ⇒ `documents.total = 90.000` while `documents.subtotal` stays `100.000` — a **`10.000` divergence** and a unit price the operator never saw | screenshots of the editor and of the confirmed totals; SQL | `DocumentLineEditor.tsx:351-365`; `PurchaseOrderController.php:100-105`, `:743-744`; `PurchaseOrderService.php:136-139`; `TaxCalculationService.php:348-376` | **B10**, B11, B15 |
| **W2-DISC-4** | SETUP-3 | **API** `discount_amount` exactly equal to gross, then strictly greater; then `discount_percent: 100` | equal → accepted, `line_total 0.000`; greater → 422 (`LineDiscountAmountWithinGross` fails only on **strictly** greater); `100 %` → accepted, `line_total 0.000` and **confirm still passes** because the guard checks `unit_price > 0`, not the line total | three responses; SQL | `LineDiscountAmountWithinGross.php:87-90`; `DocumentLine.php:290-299`; `PurchaseOrderService.php:90-109` | B11 |

---

## Class W2-MATCH — supplier-invoice mismatch (6)

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-MATCH-1** | PO-C fully received (10) | **UI** create an invoice for **`12.0000`** | 201 with `match_status = quantity_variance`; `btn-post` **disabled** with `post-block-reason` visible; **API** POST → 422 `POSTING_BLOCKED` **under `Warn`** — qty variance blocks regardless of enforcement | screenshot `match-01.png`; response | `SupplierInvoiceMatcher.php:377-382`, `:217-231`; FE `SupplierInvoiceDetailPage.tsx:127`, `:403` | **B37** |
| **W2-MATCH-2** | a Confirmed PO with **nothing received** | **UI** create an invoice for `5.0000` | `match_status = exception` (a **different** status from over-clear: `matchable <= 0` while `totalQty > 0`); post → 422. Assert the `post-block-reason` copy is honest for the `exception` case, not only for over-invoicing | screenshot; response | `SupplierInvoiceMatcher.php:367-374` | B35, B37 |
| **W2-MATCH-3** | PO-C received 10 | **API** create an invoice with **two lines** against the same PO line, each `6.0000` (individually within bounds, summing to 12) | aggregate per `source_line_id` ⇒ `quantity_variance`; post refused | response; the match block | `SupplierInvoiceMatcher.php:325-333`; pinned `SupplierInvoiceMatcherTest.php:471-527` | B37 |
| **W2-MATCH-4** | a posted invoice | **API** `POST /supplier-invoices/{id}/match` | 422 `MATCH_NOT_ALLOWED` (the sb-q11 guard); the FE hides `btn-rematch` once posted, so this is API-only | response; screenshot showing the button absent | `SupplierInvoiceController.php:275-286`; `SupplierInvoiceDetailPage.tsx:371` | B37 |
| **W2-MATCH-5** | PO-C received 10 | **API** create **two Draft invoices** each claiming the same `10.0000`, then post both | **[derived, F-W2-21]** both drafts are created (each burning an `SI-` number) because `qtyAlreadyPlanned` de-duplicates only *within* one invoice; the **first** post succeeds, the **second** fails (`matchable = 0` ⇒ exception/quantity_variance) | both create responses (two numbers); both post responses | `CreateSupplierInvoiceService.php:161-165`; `ReceiptLineConsumptionPlanner.php:78-84`; increment `SupplierInvoicePostingService.php:516-517` | B8, B37 |
| **W2-MATCH-6** | company 2 | **API** create an invoice in company 1 referencing a **company-2** PO id; and one whose `partner_id` differs from the PO's; and one whose `currency` differs | all three 422 at validation with their specific messages ("The source document must be a purchase order." / partner / "The invoice currency must match all purchase order currencies.") | three responses | `CreateSupplierInvoiceRequest.php:247-255`, `:273-282`, `:284-295` | **B46** |

---

## Class W2-IFIRST — invoice-first (5)

Both modes require `PUT /procurement-policies {allow_invoice_first: true}` first — the vertical default is **false** (`ProcurementPolicy.php:113`).

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-IFIRST-1** | policy default (false) | **API** create with `pending_receipt: true` | 422 "Invoice-first supplier invoices are disabled for this company." on the `pending_receipt` key — a fail-closed default | response | `CreateSupplierInvoiceRequest.php:222-229`; defensively again `CreateSupplierInvoiceService.php:65-67` | B38 |
| **W2-IFIRST-2** | policy enabled | **UI** `invoice-first-pending` mode → create an invoice with no PO | 201; `payload…pending_receipt = true`, `source_document_id` NULL, `match_status = unmatched`; `pending-receipt-banner` visible; **`btn-post` disabled**; **API** post → 422 `PENDING_RECEIPT_UNLINKED`; **zero `journal_entries`** — nothing is booked at creation | screenshot `ifirst-02.png`; response; JE count | `CreateSupplierInvoiceService.php:142-143`, `:219`; `SupplierInvoicePostingService.php:77-79` | **B38** |
| **W2-IFIRST-3** | IFIRST-2 + a posted receipt on a matching PO | **UI** `link-receipts` panel → link **one of two** lines, then the second | after the first link `pending_receipt` stays **true** and post is still blocked; after the second it flips **false**, `match_status` recomputes, and post succeeds | two screenshots; two responses | `SupplierInvoiceReceiptLinkingService.php:145-152`, `:107`, `:134-142` | B38 |
| **W2-IFIRST-4** | policy enabled, `goods-receipt.create-standalone` held | **UI** `invoice-first-delivered` with `location_id` + `idempotency_key` + `lines[].product_id` | 201; an **auto-PO** with `payload.auto_generated.source = 'invoice_first'` and a **posted** GoodsReceipt exist; post as **admin** (who holds `supplier-invoices.approve-invoice-first`) succeeds. **Then assert order-independence: the net GL of this path equals the net GL of the receipt-then-invoice path (W2-PART-6) for the same numbers.** | screenshots; SQL comparing the two GL nets by `system_purpose` | `InvoiceFirstOrchestrator.php:22-87`; approval gate `SupplierInvoicePostingService.php:675-700`, `:702-739`; grants `RolesAndPermissionsSeeder.php:149-152` | **B38** |
| **W2-IFIRST-5** | IFIRST-4 | **API** re-POST with the **same `idempotency_key`** | the **same** invoice id is returned; no second auto-PO, no second receipt, no second JE | response; counts before/after | `InvoiceFirstOrchestrator.php:40-51`, `:78-84` | **B8**, B42 |

---

## Class W2-IDEM — duplicate submission (6)

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-IDEM-1** | SETUP-3 | **API** POST the **identical** PO body twice | **[F-W2-23]** two distinct draft ids, both `document_number = NULL`; confirming both burns **two** `PO-` numbers. No dedupe of any kind exists | both ids; both numbers | 01a §5; contrast `CreateStandaloneReceiptRequest.php:24` | **B8** |
| **W2-IDEM-2** | a confirmed PO with `qty 10.0000`, nothing received | **API** POST the **same** `{quantities:{line:'4.0000'}}` payload **twice** | **[derived, F-W2-01 — the headline]** both succeed ⇒ **stock `8.0000`**, **two** `goods_receipts`, **two** `stock_movements`, **two** WAC blends, **two** GR-IR journal entries (the GL keys on the *movement* id, which differs). Assert every one of those counts | before/after counts on all four tables | `ReceiveGoodsRequest.php:26-40`; `PurchaseOrderController.php:766-867`; `GeneralLedgerService.php:2052` | **B8** |
| **W2-IDEM-3** | IDEM-2 continued | **API** POST it a third time once the line is full | 422 `Cannot receive more than ordered…` — the quantity ceiling is the **only** protection | response | `GoodsReceiptService.php:315-319` | B8, B21 |
| **W2-IDEM-4** | a confirmed PO | **API** `POST …/confirm` twice, then two **concurrent** confirms (`raceTwo`) | both return **200 with the same document**; exactly **one** `document_number`, one `PurchaseOrderConfirmed` event, no second landed-cost allocation | responses; SQL; event count | `PurchaseOrderController.php:713-720`; `DocumentStatusService.php:186-220`; concurrency helper `money-campaign/helpers.ts:224-231` | **B8** |
| **W2-IDEM-5** | a posted supplier invoice | **API** `POST …/post` again | **200, silent no-op** (not an error); `journal_entries` count stays **1**; `quantity_invoiced` does not double | response; counts | `SupplierInvoicePostingService.php:115-117`, `:172-174`; index `2026_06_26_120000_…:44-48`; controller comment `:347-351` | B8 |
| **W2-IDEM-6** | a posted invoice with `balance_due 124.950` | **API** two payments with the **same `Idempotency-Key`**; then two **concurrent** payments of `100.000` each (`raceTwo`) | same key → the original payment returned with 200, one allocation. Concurrent: exactly **one** succeeds; the other 422s `SUPPLIER_PAYMENT_EXCEEDS_PAYABLE` from the **locked-row** re-check; `balance_due` never goes negative | responses; SQL query 1 of `01c §8` | `PaymentController.php:358-366`, `:1372-1386`, `:1049-1064` | **B42** |

---

## Class W2-SEC — second company / second location / re-run (6)

| ID | Preconditions | Action | Expected | Evidence | Oracle | Baseline |
|---|---|---|---|---|---|---|
| **W2-SEC-1** | SETUP-5 | **UI** switch the company header to company 2, **refresh the page**, then create + confirm a PO | the switch **survives the refresh**; company 2's first PO is also **`PO-2026-0001`** — numbering is per company; company 1's list never shows it and vice versa | screenshots before/after refresh; both `GET /purchase-orders` with explicit `X-Company-Id` | `DocumentNumberingService.php:47-66`; `2026_08_30_100500_enforce_company_scoped_document_numbers.php:50-60`; `HandlesDocuments.php:44-49` | **B46** |
| **W2-SEC-2** | SEC-1 | **UI** run the whole HP flow (create → confirm → receive → invoice → pay) in **company 2** | every step succeeds; `SI-2026-0001` also exists twice in the tenant; the **journal-entry** minting works for company 2 (the C-27 regression) | screenshots per step; SQL on `journal_entries` for both companies | company-scoped numbering above; C-27 in `docs/handoff/HANDOVER-session-B2-2026-08-25.md:17` | **B46** |
| **W2-SEC-3** | SETUP-4 | second-location arm of **W2-LOC-1..3** | stock lands on the **selected** location, never the default | see W2-LOC | `GoodsReceiptService.php:984-1008` | B27, B46 |
| **W2-SEC-4** | both companies | **API** cross-company probes: company 1 PO referencing a company-2 partner / product / location | all 422 at validation (`ScopedExists::tenantAndCompany`), **not** 404 | three responses | `CreateDocumentRequest.php:66-70`, `:101-106`, `:93-99` | B46 |
| **W2-SEC-5** | company 1, two locations, a posted invoice from `MAIN` | **API** pay it from a repository bound to `WH` | **[derived, F-W2-22]** accepted — `payments.location_id` is inherited from the first allocated document and the repository's location is never compared. Record the accepted cross-location payment | response; SQL on `payments.location_id` vs the repository | `PaymentController.php:645-654`, `:395-399` | B46, B47 |
| **W2-SEC-6** | company 2 | **API** create an invoice-first line carrying a **company-1** `variant_id` | **[derived, F-W2-28]** not refused at validation — `lines.*.variant_id` has `uuid` only, no `ScopedExists`, unlike `product_id` | response | `CreateSupplierInvoiceRequest.php:120` vs `:115-119` | **B46** |

Re-run arms are **W2-IDEM-1..6**; each mutating class references them rather than duplicating.

---

## Class W2-PERM — permissions matrix, cashier session (14)

All rows run in the **second browser context** from W2-SETUP-6. `cashier` holds `documents.view`, `documents.update`, `inventory.view`, `uom.view`, `batches.view`, `products.view`, `partners.view/create`, `payments.view/create` (`RolesAndPermissionsSeeder.php:667-700`) and **no** `purchase-orders.*`.

| ID | Endpoint | Expected | Oracle | Verdict shape |
|---|---|---|---|---|
| **W2-PERM-1** | `GET /purchase-orders` | **403** | `Document/Presentation/routes.php:274-276` (`purchase-orders.view`) | correct |
| **W2-PERM-2** | `POST /purchase-orders` | **403** | `:287-289` | correct |
| **W2-PERM-3** | `POST /purchase-orders/{id}/confirm` | **403** | `:299-301` | correct |
| **W2-PERM-4** | `POST /purchase-orders/{id}/receive` · `POST /goods-receipts/{id}/post` · `DELETE /goods-receipts/{id}` | **403** ×3 | `:303-305`; `Inventory/routes.php:169-177` | correct |
| **W2-PERM-5** | `GET /goods-receipts` and `GET /goods-receipts/{id}` | **200** (by design — `inventory.view`) | `Inventory/routes.php:156-162`; grant `:679` | by design |
| **W2-PERM-6** | `GET /purchase-orders/{id}/receipt-lines` | **200** — gated on `documents.view`, not `purchase-orders.view`, while `GET …/receipt-status` next door is **403** | `:282-285` vs `:307-309` | ⚠ inconsistent |
| **W2-PERM-7** | `POST /documents/{id}/revert` on a **confirmed PO** | **[derived, F-W2-14]** **allowed** — `documents.update` — a cashier can un-confirm a purchase order | `routes.php:84-87`; grant `:671` | **P1 hole** |
| **W2-PERM-8** | `POST /supplier-invoices` | **[derived]** **allowed** — `documents.update` | `Procurement/routes.php:100-102`; grant `:671` | **P1 hole** |
| **W2-PERM-9** | `POST /supplier-invoices/{id}/match` | **[derived]** **allowed** | `:105-108` | **P1 hole** |
| **W2-PERM-10** | `POST /supplier-invoices/{id}/post` | **[derived]** **allowed** — a cashier books a payable and a journal entry. Assert the JE exists and is attributed to the cashier | `:117-120`; the permission's own scoping comment `RolesAndPermissionsSeeder.php:121` | **P1 hole — B48** |
| **W2-PERM-11** | `POST /payments` with a supplier allocation | **[derived]** **allowed** — `payments.create` (`:682`) — a cashier pays a supplier and moves cash out of a repository | `Treasury/routes.php:189-191` | **P1 hole** |
| **W2-PERM-12** | `POST /supplier-invoices/{id}/link-receipts` | **403** — `supplier-invoices.link-receipts` is granted only to manager/accountant | `:111-114`; grants `:571-572`, `:582` | correct |
| **W2-PERM-13** | `POST /documents/{id}/additional-costs` | **403** — `purchase-orders.update` | `Document/routes.php:373-375` | correct |
| **W2-PERM-14** | **UI** as cashier: navigate to `/purchases/orders/new` | redirected to `/dashboard` with `permissionDenied` — the FE alias `purchases.create` maps to roles `admin`/`purchases`/`manager` only. Separately (**F-W2-15**) record that this alias is **not** the backend permission, so a custom role holding `purchase-orders.create` without one of those three roles is wrongly blocked, and a `manager` lacking it reaches the form and 403s on submit | `uiAliasPermissions.ts:7`; `routes/index.tsx:937-945`; `RequirePermission.tsx:64-79` | ⚠ F-W2-15 |

---

## Class W2-WDIL — "where did it land": the three-way agreement (5)

Run after **every** money step via `whereDidItLand(leg, label, assertions)` (`journey.ts:582-592`); these five rows are the explicit reconciliations. SQL is **01c §8** queries 1–6 (⚠ verify the `repository_movements` table/column names with `\d` before the first run — 01c marks them UNVERIFIED — and correct 01c in the same commit).

| ID | After | Assertion | Oracle |
|---|---|---|---|
| **W2-WDIL-1** | every **receipt** | `stock_levels` delta == Σ `stock_movements.quantity` for the run == Σ `batch_stocks` delta for tracked lines; and `Σ Cr 408 == Σ (movement.unit_cost × qty)` rounded at scale 3 | `WeightedAverageCostService.php:263-288`; `GeneralLedgerService.php:2064-2068` |
| **W2-WDIL-2** | every **invoice post** | exactly **one** `journal_entries` row with `source_type='supplier_invoice'`; `entry_imbalance = 0`; `Cr 401 == documents.total`; `Dr 408 == Σ(consumed slice × receipt accrual)`; and the fail-loud invariant `total == subtotal + Σ recoverable_vat + non_recoverable_vat + stamp_duty` | `01c §8` query 4; `GeneralLedgerService.php:2210-2216`, `:2352-2365` |
| **W2-WDIL-3** | a **fully received + fully invoiced** PO | **408 nets to zero** for that PO. ⚠ For PO-D (freight added before confirm) it nets to zero only because a `30.000` PPV **income** offsets the capitalised freight — assert the netting AND record the offsetting leg (F-W2-18) | `01c §8` query 4; PPV `GeneralLedgerService.php:2292-2313` |
| **W2-WDIL-4** | every **receipt** | **every** posted receipt line has a `stock_movements` row **and** a GR-IR entry. Then run `php artisan accounting:check-cogs-coverage` and require **exit 0**. ⚠ Because the GR-IR listener swallows every throwable (F-W2-03), a missing entry is silent at HTTP level — this detector is the only signal | `CheckCogsCoverageCommand.php:38-62`, D-f arm `:50-55`, `:431-447`; `PostGrIrOnGoodsReceipt.php:41-52` |
| **W2-WDIL-5** | every **payment** | `Σ documents.balance_due` (posted/paid SI for the partner) == `Σ Cr 401 − Σ Dr 401` tagged to that partner == `partners.payable_balance`; and `payment_repositories.balance` == Σ signed `repository_movements`. The partner cache is refreshed **asynchronously** — poll with `pollUntil` (`journey.ts:563-580`), never assert immediately | `01c §8` queries 1, 2, 3, 5, 6; `PaymentController.php:1180-1183`; `PartnerBalanceService.php:333-357` |

---

## Class W2-EDGE — residual probes (10)

| ID | Action | Expected | Oracle |
|---|---|---|---|
| **W2-EDGE-1** | **API** `GET /api/v1/purchase-orders/not-a-uuid` and `POST /api/v1/purchase-orders//confirm` | **[F-W2-26]** these routes carry no `whereUuid`, so a non-UUID reaches `find()` against a PG `uuid` column — the K-6 500 shape. Assert the status; a **5xx is a finding**, a 404 is the fix | `Document/routes.php:274-309`, `whereUuid` only at `:284` |
| **W2-EDGE-2** | **API** confirm a PO whose line has `unit_price: '0'`, and one where only a `is_bonus_line` line is at zero | first → 422 `UnpricedPurchaseOrderLineException` (**not** `INVALID_STATUS_TRANSITION`); second → confirms | `PurchaseOrderService.php:90-109`; `PurchaseOrderController.php:726-738` |
| **W2-EDGE-3** | **API** POST `quantity: '1.00001'` (5 dp), `'0'`, `'-1'`; and `unit_price: '10.0001'` (4 dp) | all 422 with the per-field precision message | `CreateDocumentRequest.php:120`, `:124`, `:166`, `:168` |
| **W2-EDGE-4** | **UI** type into the PO editor to trigger autosave, then **SQL** read the draft | **[derived]** the autosaved line total is `qty × unit_price` with **no discount and no tax**, and `price_entry_mode` / `free_quantity` / discounts are **dropped** — the real numbers only exist after an explicit Save | `AutoSaveDraftRequest.php:39-56`; `DraftPersistenceService.php:558-561`, `:756` |
| **W2-EDGE-5** | **API** autosave against a **Confirmed** PO | refused (autosave demands Draft) while `PATCH` on the same document succeeds (W2-REV-6) — two write paths, two rules | `DraftPersistenceService.php:205-215` vs `DocumentStatus.php:19-25` |
| **W2-EDGE-6** | **UI** submit a PO with `external_document_number` + `external_document_date` filled, then **SQL** read them back | **[derived, B9]** both are sent by the FE and **silently dropped** by `validated()` — the supplier's own reference does not persist | `DocumentForm.tsx:481-483`; `CreateDocumentRequest.php:65-153` (never declared) |
| **W2-EDGE-7** | **API** free quantities: a free-only receipt on **PO-H** (`P4 × 10.0000 @ 10.000` + `free_quantity 2.0000`), receiving paid 10 + free 2 | **[derived]** the **free leg runs first** at cost `'0'` ⇒ WAC after the free leg `0.000000`, after the paid leg `100.000/12 = 8.333333`; stock `12.0000`; **one** GR-IR entry (`100.000`) — the free leg's zero amount produces none; `goods_receipt_lines.effective_unit_cost = 8.333333` (reporting only). This is the **B26 dilution measurement** | order pinned `GoodsReceiptPriceOverrideTest.php:196-206`; `GoodsReceiptService.php:574`, `:839-851`; `GeneralLedgerService.php:2070-2072`; pinned PUR-14 `purchasing-landed-cost.spec.ts:52` |
| **W2-EDGE-8** | **API** on a completed supplier payment: `POST /payments/{id}/reverse`, then `POST /payments/{id}/refund` | reverse → **422** (`Unsupported`). Refund → **[F-W2-13] reachable and not supplier-aware** — capture the status, the response, **and every `journal_lines` row it writes**, plus `documents.balance_due` and `partners.payable_balance` before/after. If it produces an incoherent AP position this is a **P0**, not a P1 | `PaymentType.php:231`; `PaymentRefundService.php:1220-1223`, `:1072-1085` |
| **W2-EDGE-9** | **API** RFQ smoke: `purchase_quote_requests` → `convert-to-po`, then `POST /documents/{po}/revert` | the awarded PO carries `source_document_id` pointing at the RFQ ⇒ revert 422 `PURCHASE_ORDER_FROM_RFQ`, surfaced with **code == message** and rendered raw by the FE (no `onError` on `useRevertDocument`) | `Procurement/routes.php:68-71`; `DocumentPostingService.php:609-620`; `DocumentController.php:231-238`; `useRevertDocument.ts:30` |
| **W2-EDGE-10** | **API** receive against a PO in each non-Confirmed state (Draft, Received) | both 422 `Purchase order must be confirmed before receiving goods` — note the message says *draft* even for a `Received` PO. A fully-received PO is closed to further receipts by the **status guard**, not the quantity ceiling | `GoodsReceiptService.php:294-303` |

---

## Run plan

1. **Local first** (`:5178` → `:8015`), serial, one shared page, `QUEUE_CONNECTION=sync`. Order: `SETUP → HP → TOT → PART → OVER → UNDER → LOT → LOC → DRAFT → REV → PRICE → LAND → VAT → DISC → MATCH → IFIRST → IDEM → SEC → PERM → WDIL → EDGE`. `WDIL` assertions are interleaved via `whereDidItLand` rather than deferred.
2. **Registration throttle**: 5 per 15 min per IP, shared across every local stack via the Redis on `:6380`. Budget **one** fresh tenant per full run; `php artisan cache:clear` in the L worktree between bursts. `SETUP-5` creates the second company inside the same tenant — never by registering again.
3. **Never run a browser leg while a Codex lane edits this worktree** — vite HMR resets the SPA (proven).
4. **Staging after local** (`https://erp.otospex.dev` / `https://api.erp.otospex.dev`), same throttle, **no pushes during a test window**.
5. **Evidence doc:** `docs/superpowers/reviews/2026-09-01-wave2-po-evidence.md`, committed after **every** round with path-scoped commits (`git commit -- docs/superpowers/...`; `docs/sessions/` is gitignored, `docs/superpowers/` is not). One section per class; each row records **[measured]** figures beside the **[derived]** expectations from this document, the screenshot filenames, the raw SQL and its output, and a PASS / FAIL / BLOCKED verdict. A class is not "confirmed working" until every one of its rows has committed evidence.
6. **Findings** promote from `01-research.md` §5 (`RESEARCH`) to `MEASURED` in the evidence doc, then into gated `LANE-L*-BRIEF.md` files. **No un-gated fixes.** Cross-wave findings (K-1 money, imports, treasury) are **flagged**, never fixed here.

## Tolerances

- **Zero 5xx.** Every browser probe registers `page.on('response')` and fails the leg on any `status >= 500`. `W2-EDGE-1` is the one row where a 5xx is a *recorded finding* rather than a harness failure — and it still fails the leg.
- **Zero console errors**, with exactly one exception until lane **K-10** merges: the `/auth/me` **401 on `/login`**. Every tolerance must be named in the evidence line; any other console error fails the leg.
- **No `toBeCloseTo`, ever.** Money and quantity compare as strings via `assertMoneyEqual` (`journey.ts:200`); 6-dp costs compare as exact strings read from Postgres.
- **`GET /documents/{id}/landed-cost-breakdown` is never an oracle** (F-W2-16) — read `document_lines.landed_unit_cost` / `allocated_costs` from Postgres.
- A **[RULING]**-marked figure is recorded, not asserted, until its question is answered.

## OPEN QUESTIONS FOR THE OWNER

| # | Question | Blocks | Baseline |
|---|---|---|---|
| **Q-1** | **Short-close.** A partially received PO today can never be closed, reverted or deleted — it is outstanding forever, and a PO mixing a goods line with a service line can never even reach `received`. Should a short-close write `Received`, introduce a new `Closed` status, or set a payload flag? | lane `L-2`; scenarios W2-UNDER-2/3 | **B7**, B22 |
| **Q-2** | **Over-receipt policy.** We hard-block with no tolerance and no role override — *stricter* than all three reference systems. Adopt ERPNext's tolerance % + "role allowed to over-receive", or keep the hard block for tenant #1 and record it as a deliberate divergence? | W2-OVER-1; the `MISSING`/`DIVERGE` verdict on B21 | **B21** |
| **Q-3** | **Bonus-unit costing.** Free units blend into the WAC at cost `0`, dragging the average down (10 @ 10.000 + 2 free ⇒ `8.333333`). Is that the intended parapharmacy costing, or should the paid units absorb the bonus (i.e. `effective_unit_cost`, which is already computed, becomes the WAC input)? | lane scope for B26; W2-EDGE-7 | **B26** |
| **Q-4** | **3-way match strictness.** Quantity variance blocks posting regardless of `match_enforcement` (ERPNext-style), while price variance is advisory under `Warn` (Odoo-style). Is that split intended, and is the default tolerance `2.00 %` **AND** `1.000` TND (both must pass) right for a TN parapharmacy? | W2-MATCH-1/2, W2-PRICE-5/6 | **B37** |
| **Q-5** | **Landed cost after a partial receipt.** Freight added between tranches capitalises only into the *later* tranche — half the cost never reaches inventory, and there is no repost. Is "enter freight before the first receipt" an acceptable operating rule for tenant #1, or does wave 2 spawn a repost lane? | W2-LAND-4 | **B31** |
| **Q-6** | **Freight has no credit side.** A `document_additional_costs` row with a null `expense_document_id` raises inventory value and the 408 accrual, and the invoice post gives it back as a PPV *income* — no liability is ever booked for the freight. Is the intent that every additional cost must carry an `expense_document_id`, and should that become required? | W2-LAND-3, W2-WDIL-3 | B30, B33 |
| **Q-7** | **Supplier-invoice authorisation.** `POST /supplier-invoices`, `…/match` and `…/post` are gated on the generic `documents.update`, which `cashier` holds — as is `POST /documents/{id}/revert`, and `payments.create`. A cashier can therefore book a payable, post its journal entry, pay a supplier, and un-confirm a purchase order. Confirm this is unintended and rule on the replacement permissions (`supplier-invoices.create` / `.post`? re-scope `documents.update`?). | lane `L-14` (P1); W2-PERM-7..11 | **B48** |
| **Q-8** | **Receipt reversal.** A posted goods receipt cannot be cancelled or reversed by any route, and a draft is hard-deleted with no audit row. A mis-received quantity is uncorrectable today. Priority for a reversing-document lane? | lane `L-10` (P1); W2-DRAFT-6 | **B28**, B49 |
| **Q-9** | **Supplier-invoice reversal.** `SupplierCreditNotePostingService` exists (1095 lines, with its GL mirror) and **no HTTP route reaches it**. A wrong supplier invoice is uncorrectable — no edit, no cancel, no credit note. Expose the credit-note route in wave 2, or defer? | lane `L-12` (P1) | **B44** |
| **Q-10** | **Supplier-payment refund.** Reversal is correctly refused, but `POST /payments/{id}/refund` is reachable on a supplier payment and the refund path is not supplier-aware. W2-EDGE-8 will measure what it writes; if it produces an incoherent AP position, does it get blocked immediately as a hotfix rather than a gated lane? | lane `L-13`; W2-EDGE-8 | **B45** |
| **Q-11** | **Supplier defaults.** Nothing on the PO create path reads the supplier beyond the FK — no payment terms, no due-date derivation, no supplier price list, no tiered price. Which of these does tenant #1 need on day one? | lane `L-6`; B16/B39 | **B16**, B39 |
| **Q-12** | **Confirmed-PO editability.** A confirmed, numbered, tax-snapshotted, cost-allocated PO is PATCH-editable and the edit re-snapshots nothing. Lock it (Odoo's "Lock Confirmed Orders"), require a revert first, or re-run the snapshot and allocation inside the PATCH? | lane `L-1`; W2-REV-6 | **B3** |
