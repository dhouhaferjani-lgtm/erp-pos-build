# Procurement Completeness — Waves 3–6 (Gap 2: Goods-Receipt Ledger) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **OWNER RESOLUTIONS (2026-07-03, session owner — resolves the draft's A1–A7; these are binding):**
> - **A1 ACCEPTED:** extend `DocumentNumberingService` with string-keyed `generateForKey` (one numbering engine; no separate Inventory service).
> - **A2 ACCEPTED:** backfill groups receipt batches by `(PO id, created_at)`, maps lines by `(product_id, variant_id)` with FIFO fallback + warning log for duplicate-product PO lines.
> - **A3 ACCEPTED:** Waves 4 and 5 merge to `post-demo` TOGETHER (interim single-basis clearing window must not ship alone).
> - **A4 ACCEPTED:** receipt-batch freight pool = PO `allocated_costs` prorated by received fraction, reallocated across the batch by received value.
> - **A5 ACCEPTED:** two snapshot columns, NO side table in v1 (weighted `price_match_basis`, first-slice `matched_receipt_line_id`); exactness lives in the FIFO walk.
> - **A6 RESOLVED:** PPV placeholder codes **6585 (expense) / 7585 (income)**, name "Écart sur prix d'achat"; 6588 is already taken — implementer must verify no collision per seeder and bump within the 658x/758x block if needed; expert-comptable countersign pending (OQ3, non-blocking).
> - **A7 ACCEPTED:** no `GET /goods-receipts` list endpoint in Waves 3–6; GRN surfaces via receive-response `meta` toast; the receipts-list rework belongs to Gap 3 (Wave 7).

**Goal:** Ship the first-class goods-receipt ledger (`goods_receipts` + `goods_receipt_lines`), per-line received-price capture with permission-gated override + audit, receipt-batch freight allocation, the Purchase Price Variance GL split (WAC==GL invariant), the receipt-line-basis supplier-invoice matcher with creation-time snapshot, and the receive-dialog UI — spec Rev 3.1 Gap 2 (§2.1–§2.11).

**Architecture:** Receipts become NON-document ledger tables (a receipt is an inventory/costing event, not a fiscal document — §0). `GoodsReceiptService` writes one header + N lines per receive call inside its existing transaction; PO-line counters (`quantity_received`/`quantity_invoiced`/`accrual_unit_cost`) are demoted to derived/legacy aggregates (§2.3.1). GR-IR accrual stays event-driven per movement; the invoice-vs-accrual price delta is split out of the Inventory plug into two new PPV account purposes. Matching/clearing move to receipt-line grain (FIFO) in Wave 5 with a creation-time basis snapshot.

**Tech Stack:** Laravel 12 / PHP 8.2 strict (PHPUnit, PHPStan L8), React 19 + TanStack Query 5 (Vitest, Playwright), react-i18next FR/EN/AR, bcmath everywhere on money paths.

**Spec:** `docs/superpowers/specs/2026-07-03-procurement-completeness-design.md` — Gap 2 (§2.1–§2.11), §0 shared context, Rev 2 dispositions C1/C2/H3/H4/H5/M10/L11, Rev 3 S2/S3/S9, wave definitions (Waves 3–6). Read Gap 2 + §0 in full before Task 1.

**Worktree drift note (verified — the spec cites `dev`, this worktree is newer):** the bonus-quantity spec has LANDED here. `GoodsReceiptService::receiveGoods` already takes `array $freeQuantities` and writes the free movement FIRST (`GoodsReceiptService.php:193-228`) and the paid movement SECOND (`:230-273`) — so "paid movement written LAST" (§2.6) is already true and gets PINNED by test, not implemented. The accrual stamp is at `:268-270` (spec cites :216-221); the cost-basis read `landed_unit_cost ?? unit_price` is at `:172`. `ReceiveGoodsDialog.tsx` already has free-quantity cells (`:255-267`). Tasks below cite the worktree lines.

## Program roadmap (context — NOT this plan's scope)

| Waves | Content | Plan doc |
|---|---|---|
| 1–2 | RFQ groups BE + FE | `2026-07-03-procurement-completeness-wave1-2-rfq-plan.md` |
| **3–6 (THIS PLAN)** | Gap 2 receipt ledger — critical path, strict order 3→4→5→6 | this file |
| 7–8 | Gap 3 SI creation UI, then multi-PO relaxation (needs Wave 5) | written at dispatch time |
| 9 | Presets + two_way wiring (needs Wave 5's matcher) | written at dispatch time |

**Hard sequencing:** Waves 4 and 5 must promote TOGETHER: between them, a second receipt at a different price accrues 408 at its own basis but posting still clears at the PO-line single basis (interim residue window). No 422 is added — Wave 5 closes the window.

## Global Constraints

- Branch: `feat/procurement-completeness`, worktree `/Users/houssamr/Projects/syneriva/apps/erp.procurement-v2`. Merge target: `post-demo`. NEVER push `dev`/`origin/dev`.
- TDD non-negotiable: failing test FIRST, then minimal code. Run PHPUnit **by path only** (`vendor/bin/phpunit tests/Feature/Inventory/...`) — NEVER the full suite.
- Money/qty: **strings end-to-end**; FormRequest regex ceilings `unit_price`/`received_unit_prices.*` `/^-?\d+(\.\d{1,3})?$/`, `quantities.*`/`free_quantities.*` `/^-?\d+(\.\d{1,4})?$/`; FE `<MoneyInput>`/`<QuantityInput>`, no `parseFloat`/`Number` on money.
- Precision: `CurrencyScale`/`QuantityScale` helpers + bcmath at working scale (`workingScale() = max(scale+4, COST_SCALE+1)` — `WeightedAverageCostService.php:74`) with **ONE boundary round** (`bcround`/`bcformat`) at persist/post time. Cost columns at `COST_SCALE = 6`.
- Queue/console contexts (GR-IR listener, backfill command, rematch command) pass **explicit currency to scale resolvers** — projections/commands run with no `CompanyContext`.
- **No `app()` helper** — constructor injection with `private readonly` only. Enums for every status (`GoodsReceiptStatus`); DTO for every payload; after PHP DTO changes run `php artisan typescript:transform` (with `CACHE_STORE=array` if cache errors) — never hand-edit `packages/shared/types/`.
- Routes middleware `['api','auth:sanctum',SetPermissionsTeam::class]`; per-route `can:`.
- FE: all strings via `t()`; design tokens; `tenantScopedKey([...])` query keys; `apiGet`/`apiPost` already unwrap.
- Regression gate for EVERY backend task: the pre-existing receipt/GR-IR/posting suites must stay green by path — `tests/Feature/Inventory/{GoodsReceiptTest,GoodsReceiptServiceVariantTest,PurchaseBonusGoodsReceiptTest,IngressPrecisionTest,WacBcmathTest,LandedCostBcmathTest}.php`, `tests/Feature/Accounting/{SupplierInvoiceGlTest,GrIrChartSeedTest}.php`, `tests/Feature/Procurement/{SupplierInvoiceMatcherTest,SupplierInvoiceApiTest,PurchaseBonusQuantityEntryTest}.php`.

---

## File structure (Waves 3–6)

```
apps/api/database/migrations/tenant/2026_07_04_100000_create_goods_receipts_tables.php        (create: both tables, §2.3 exact)
apps/api/database/migrations/tenant/2026_07_04_110000_add_match_snapshot_to_document_lines.php (create: Wave 5 snapshot cols)
apps/api/app/Modules/Inventory/Domain/Enums/GoodsReceiptStatus.php                             (create)
apps/api/app/Modules/Inventory/Domain/GoodsReceipt.php                                         (create: model)
apps/api/app/Modules/Inventory/Domain/GoodsReceiptLine.php                                     (create: model)
apps/api/app/Modules/Inventory/Application/DTOs/GoodsReceiptData.php / GoodsReceiptLineData.php (create: #[TypeScript] DTOs, mirror StockLevelData.php)
apps/api/app/Modules/Document/Domain/Services/DocumentNumberingService.php                     (modify: string-keyed generateForKey)
apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php                    (modify: header+lines, price flow, actor)
apps/api/app/Modules/Inventory/Application/Services/ReceiptBatchCostAllocator.php              (create: §2.4.1 received-value allocation)
apps/api/app/Console/Commands/BackfillGoodsReceiptsCommand.php                                 (create: procurement:backfill-goods-receipts)
apps/api/app/Console/Commands/RematchDraftSupplierInvoicesCommand.php                          (create: procurement:rematch-drafts)
apps/api/app/Modules/Document/Presentation/Requests/ReceiveGoodsRequest.php                    (create: Laravel FormRequest)
apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php             (modify: receive() uses FormRequest + meta.goods_receipt)
apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php                          (modify: PPV pair + label/type arms)
apps/api/database/seeders/{TunisiaChartOfAccountsSeeder,FranceChartOfAccountsSeeder,GenericChartOfAccountsSeeder}.php (modify: PPV accounts)
apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php                       (modify: plug split → PPV)
apps/api/app/Modules/Procurement/Application/SupplierInvoiceMatcher.php                        (modify: receipt-line basis + FIFO + fallback)
apps/api/app/Modules/Procurement/Application/ReceiptLineConsumptionPlanner.php                 (create: pure FIFO planner)
apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php                 (modify: receipt-line clearing)
apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php                  (modify: creation snapshot)
apps/api/database/seeders/RolesAndPermissionsSeeder.php                                        (modify: goods-receipt.edit-price)
apps/web/src/features/purchases/components/ReceiveGoodsDialog.tsx                              (modify: price cell + variance chip)
apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx                    (modify: pass permission + GRN toast)
apps/web/src/features/purchases/GoodsReceiptListPage.tsx                                       (modify: payload passthrough + GRN toast)
apps/web/src/locales/(fr|en|ar)/…purchases/sales namespaces                                    (modify: receive.* keys)
```

---

## WAVE 3 — Receipt-ledger foundation

### Task 1: Migrations + models + `GoodsReceiptStatus` enum

**Files:** Create migration `2026_07_04_100000_create_goods_receipts_tables.php`, `GoodsReceiptStatus.php`, `GoodsReceipt.php`, `GoodsReceiptLine.php`; Test `tests/Feature/Inventory/GoodsReceiptLedgerSchemaTest.php`.
**Produces:** the two tables EXACTLY per §2.3 (including audit columns):

- `goods_receipts`: `id uuid PK, tenant_id uuid NOT NULL, company_id uuid NOT NULL, purchase_order_id uuid NOT NULL` (documents.id, NOT NULL in v1 — S5), `receipt_number varchar(30) NOT NULL, status varchar(20) NOT NULL, received_at timestamptz NOT NULL, received_by uuid NULL, notes text NULL, payload jsonb NULL, timestamps, UNIQUE (tenant_id, receipt_number)`.
- `goods_receipt_lines`: `id, tenant_id, company_id, goods_receipt_id FK→goods_receipts, po_line_id uuid NOT NULL, product_id uuid NOT NULL, variant_id uuid NULL, received_qty decimal(15,4) NOT NULL, free_qty decimal(15,4) NOT NULL DEFAULT 0, received_unit_price decimal(15,3) NULL, landed_unit_cost decimal(19,6) NOT NULL, accrual_unit_cost decimal(15,6) NOT NULL, effective_unit_cost decimal(19,6) NOT NULL, movement_id uuid NULL, free_movement_id uuid NULL, quantity_invoiced decimal(15,4) NOT NULL DEFAULT 0, price_override_by uuid NULL, price_override_at timestamptz NULL, price_override_old_basis decimal(15,6) NULL, price_override_reason varchar(255) NULL, timestamps`; indexes `(tenant_id, po_line_id)` and `(tenant_id, goods_receipt_id)`.
- `GoodsReceiptStatus: string {Draft='draft', Posted='posted', Cancelled='cancelled'}`. Model casts: qty `decimal:4`, `received_unit_price decimal:3`, cost columns `decimal:6`, `status => GoodsReceiptStatus::class`.

- [ ] Failing schema test: tables + all columns exist with the right types/scales; unique + indexes present; enum has exactly the 3 cases; `strlen('goods_receipt') <= 20` (sequence-type fit assertion for Task 2).
- [ ] Implement → PASS. Run migration on tenant test DB.
- [ ] Commit: `feat(inventory): goods_receipts + goods_receipt_lines ledger schema + GoodsReceiptStatus`

### Task 2: GRN numbering via `document_sequences` (type `goods_receipt`, prefix `GRN`)

**Files:** Modify `DocumentNumberingService.php`; Test `tests/Unit/Document/GoodsReceiptNumberingTest.php`.
**Interfaces — Produces:** a string-keyed variant so a NON-document sequence can draw numbers (the existing `generateNumber(:18)` is typed to `DocumentType` and a receipt is deliberately not a `DocumentType` — §0):

```php
/** Same row-locked increment as generateNumber(), keyed by a raw sequence type + explicit prefix. */
public function generateForKey(string $tenantId, string $companyId, string $sequenceType, string $prefix): string; // GRN-2026-0001
```

Refactor the shared lock/create/increment core private; `generateNumber()` delegates. `document_sequences.type` is `string(20)` — `goods_receipt` = 13 chars fits. Handle the first-sequence create race the same way the RFQ plan noted (unique-violation retry under the lock txn).

- [ ] Failing test: two sequential calls → `GRN-<year>-0001/0002`; isolated from `purchase_order` sequence; per-company isolation; existing `generateNumber` behavior unchanged (regression case).
- [ ] Implement → PASS → Commit: `feat(document): string-keyed sequence generation for the goods-receipt ledger`

### Task 3: `GoodsReceiptService` writes header + lines; PO counters become derived aggregates

**Files:** Modify `GoodsReceiptService.php`, `PurchaseOrderController.php` (receive response meta); Test `tests/Feature/Inventory/GoodsReceiptLedgerWriteTest.php`.
**Interfaces — Produces:**

```php
public function receiveGoods(
    Document $purchaseOrder,
    array $receivedQuantities,
    array $batchData = [],
    array $freeQuantities = [],
    ?string $actorId = null,            // M10 — received_by (price-audit params come in Wave 4)
): GoodsReceiptResult;                  // small readonly DTO: {Document $purchaseOrder, GoodsReceipt $receipt}
```

Inside the EXISTING transaction + sorted `ProductCostLock` (`:53-88` — do not restructure locking): create ONE `goods_receipts` header per call (`status=Posted`, `received_at=now()`, `received_by=$actorId`, number from Task 2) before the line loop; per processed line create a `goods_receipt_lines` row: `received_qty`/`free_qty` as validated, `received_unit_price=NULL` (Wave 4), `landed_unit_cost = accrual_unit_cost = ` the current basis read `landed_unit_cost ?? unit_price` (`:172`), `effective_unit_cost = bcdiv(received_qty × landed, received_qty + free_qty, working)` one-rounded to 6 (paid-only receipt ⇒ = landed), `movement_id`/`free_movement_id` from the two `recordPurchase` calls (`:193-204`, `:232-241`), `quantity_invoiced='0'`. PO-line counters (`quantity_received :272`, `free_quantity_received :227`, legacy `accrual_unit_cost :268-270`) keep being written exactly as today — they are now DERIVED duplicates of the ledger (§2.3.1); add an invariant assertion helper used by tests: `Σ receipt lines == PO counters`. All `receiveGoods` callers (`receiveAll :313-338`, controller `:619/:627`) updated; controller adds `meta.goods_receipt = {id, receipt_number}` to the receive response (Wave 6 GRN surfacing) and passes `$user->id` as actor.

- [ ] Failing tests: single receive → 1 header (Posted, GRN number, received_by) + N lines with correct qty/cost/movement ids; partial then second receive → 2 headers; free-only line → line with `received_qty=0, free_qty>0, free_movement_id` set, `movement_id NULL`; PO counters == ledger sums (invariant helper); `receiveAll` writes a header; service-level `DomainException` paths create NO header (rollback).
- [ ] Implement → PASS.
- [ ] Regression by path: `vendor/bin/phpunit tests/Feature/Inventory/GoodsReceiptTest.php tests/Feature/Inventory/PurchaseBonusGoodsReceiptTest.php tests/Feature/Inventory/GoodsReceiptServiceVariantTest.php tests/Feature/Accounting/SupplierInvoiceGlTest.php` → green (the return-type change is the only API break; fix call sites, not tests' semantics).
- [ ] Commit: `feat(inventory): receipts write goods_receipts header + lines; PO counters demoted to derived aggregates`

### Task 4: DTOs + `typescript:transform`

**Files:** Create `GoodsReceiptData.php`, `GoodsReceiptLineData.php` (mirror `Inventory/Application/DTOs/StockLevelData.php` `#[TypeScript]` pattern); Test `tests/Unit/Inventory/GoodsReceiptDataTest.php`.
**Produces:** `GoodsReceiptData::fromModel(GoodsReceipt $r, bool $withLines = true): self` — all money/qty fields as `string`; used by the controller `meta.goods_receipt` and by Gap 3's Wave-7 read endpoints later.

- [ ] Failing DTO test (round-trip from a factory model; strings, never floats) → implement → PASS.
- [ ] `php artisan typescript:transform` (CACHE_STORE=array) → `packages/shared/types/` gains both types; commit generated output.
- [ ] Commit: `feat(inventory): goods-receipt DTOs + generated TS types`

### Task 5: Idempotent per-tenant backfill — `procurement:backfill-goods-receipts` (§2.3.2)

**Files:** Create `BackfillGoodsReceiptsCommand.php`; Test `tests/Feature/Inventory/GoodsReceiptBackfillTest.php`.
**Produces:** `procurement:backfill-goods-receipts {--company=} {--dry-run}` — mirrors the `BackfillFiscalYears` fleet-iteration pattern (db-per-tenant; explicit currency passed to any scale resolution — no CompanyContext). Synthesis per §2.3.2:

- Source rows: purchase `StockMovement`s with `reference_type='Document'` + `reference_id` = a `PurchaseOrder` id, not already claimed (`goods_receipt_lines.movement_id`/`free_movement_id` — the idempotency key).
- **Batch grouping:** movements of one PO sharing one commit instant (`created_at`) = one synthesized receipt (all lines of one `receiveGoods` call commit in one transaction). Header: `received_at = created_at`, `received_by = NULL`, GRN number drawn from the Task-2 sequence, `payload->backfilled_at` stamped.
- **Line reconstruction:** map movement → PO line by `(product_id, variant_id)`; zero-cost movements pair as `free_movement_id` of the sibling paid line (same product/variant/batch) else a free-only line. `received_unit_price = NULL`; `landed_unit_cost = accrual_unit_cost =` the PO line's existing `accrual_unit_cost ?? landed_unit_cost ?? unit_price` (preserves 408 reconciliation exactly); `effective_unit_cost` recomputed; `quantity_invoiced` seeded from the PO line's `quantity_invoiced`, apportioned FIFO (oldest receipt line first) across the synthesized lines.
- Idempotent: re-run is a no-op (movement already claimed); `--dry-run` prints counts only. POs left with ZERO receipt lines are legal → the Wave-5 matcher fallback covers them.

- [ ] Failing tests: a historical two-partial-receipt PO (built through the pre-Task-3 write path via direct movement/counter fixtures) → 2 headers/lines reproducing the single basis; invoiced-qty FIFO apportionment; free-movement pairing; re-run no-op; `--company` scoping; duplicate-product-line PO logs a warning and falls back to FIFO line assignment (owner resolution A2).
- [ ] Implement → PASS → Commit: `feat(procurement): idempotent goods-receipt backfill (headers-from-movements)`

### Task 6: Wave-3 exit regression sweep

- [ ] Run, by path, the full Global-Constraints regression list + all new Wave-3 suites; `vendor/bin/phpstan analyse app/Modules/Inventory app/Modules/Document` + `vendor/bin/pint --test` on touched dirs.
- [ ] Commit: `test(inventory): wave-3 receipt-ledger regression sweep`

---

## WAVE 4 — Received price, audit, PPV

### Task 7: `ReceiveGoodsRequest` FormRequest + `goods-receipt.edit-price` permission

**Files:** Create `Document/Presentation/Requests/ReceiveGoodsRequest.php`; modify `PurchaseOrderController::receive` (`:589` — currently raw `$request->input`, the precision outlier §2.8) and `RolesAndPermissionsSeeder` (permission to owner/manager only — OQ4); Test `tests/Feature/Procurement/ReceiveGoodsRequestTest.php`.
**Produces rules:** `quantities` `sometimes|array`, `quantities.*` `['numeric','regex:/^-?\d+(\.\d{1,4})?$/']`; `free_quantities.*` same; `batches.*.batch_number|expiry_date` as today; `received_unit_prices` `sometimes|array`, `received_unit_prices.*` `['numeric','regex:/^-?\d+(\.\d{1,3})?$/']` **and `prohibited` unless `$this->user()->can('goods-receipt.edit-price')`** (both-layers rule §2.7); `price_override_reason` `nullable|string|max:255`. Keep the `PurchaseBonusGate` check (`:613`) intact.

- [ ] Failing tests: malformed precision → 422; `received_unit_prices` without the permission → 422 `prohibited`; with permission → passes; permission seeded to owner/manager, NOT the receiving-clerk role; existing no-price receive payloads unchanged (regression).
- [ ] Implement → PASS → Commit: `feat(procurement): ReceiveGoodsRequest FormRequest + goods-receipt.edit-price permission`

### Task 8: `PurchasePriceVariance{Expense,Income}` account purposes + COA seeding

**Files:** Modify `SystemAccountPurpose.php` (+`label()`, `expectedAccountType()`: Expense/Revenue arms) and the three COA seeders (Tunisia/France/Generic — mirror the 408 rows at `TunisiaChartOfAccountsSeeder.php:141`, `FranceChartOfAccountsSeeder.php:136`, `GenericChartOfAccountsSeeder.php:97` and the 658/758 tolerance pair); Test `tests/Feature/Accounting/PpvChartSeedTest.php` (mirror `GrIrChartSeedTest.php`).
**Produces:** `case PurchasePriceVarianceExpense = 'purchase_price_variance_expense'` (60x-side) / `PurchasePriceVarianceIncome = 'purchase_price_variance_income'` (7x-side). Seeder ships **placeholder codes 6585 / 7585, name "Écart sur prix d'achat"** (owner resolution A6 — verify per-seeder no collision, bump within the 658x/758x block if needed; numbers pending expert-comptable countersign — OQ3, does not block).

- [ ] Failing seed test per seeder: both purposes resolvable via `Account::findByPurposeOrFail`, correct `AccountType`, `is_system` true → implement → PASS.
- [ ] Commit: `feat(accounting): PurchasePriceVariance expense/income account purposes + COA seeds`

### Task 9: Received-price cost flow — per-line landed/accrual/effective + audit + paid-last pin (§2.4, §2.6, §2.7)

**Files:** Modify `GoodsReceiptService.php`; Test `tests/Feature/Inventory/GoodsReceiptPriceOverrideTest.php`.
**Interfaces — Produces:** `receiveGoods(..., array $receivedUnitPrices = [], ?string $priceOverrideReason = null, ?string $actorId = null)`. Per line with an override price:

1. Basis becomes `received_unit_price`; `landed_unit_cost = bcdiv(received_qty × received_unit_price + batch_freight_share + non_rec_tax_share, received_qty, working)` one-rounded to `COST_SCALE=6` (reuse the `LandedCostService::landedUnitCost` shape `:323-348`; freight share from Task 10).
2. `accrual_unit_cost = landed_unit_cost` snapshotted on the RECEIPT line (immutable, per line — never re-written).
3. WAC: paid `recordPurchase(received_qty, landed_unit_cost)`, free `recordPurchase(free_qty, '0')` — **pin the existing free-first/paid-second order (`:193` before `:230`) with a test** so `last_purchase_cost` (`WeightedAverageCostService.php:285-287`) = the PAID delivered price (H3).
4. `GoodsReceived` events carry the receipt-line cost (`unitCost` param — no event shape change; the zero-cost free movement is a GR-IR no-op via the `amount <= 0` early return, `GeneralLedgerService.php:1078-1080`).
5. Audit: stamp `price_override_by = $actorId`, `_at = now()`, `_old_basis =` the PO/landed basis the override replaced (`:172` read), `_reason` (M10).
6. **PO-line legacy compat (§2.3.1) + interim single-basis clearing:** on the FIRST paid receipt of a line, keep writing `document_lines.accrual_unit_cost` = the receipt-line basis (override included); change `SupplierInvoicePostingService.php:133` basis to `accrual_unit_cost ?? landed_unit_cost ?? unit_price` so a Wave-4 override clears 408 cleanly (B3 guard `:140-152` stays; Wave 5 rebases everything to receipt lines). Do NOT add any 422 for a second different-priced receipt.

- [ ] Failing tests: override 5.200 vs PO 5.000 → receipt line landed/accrual 5.200, WAC 5.200, GR-IR accrues 520.000, audit columns stamped; no-override line untouched (NULL price, PO basis); bonus composition — paid 100@5.200 + free 10 → `last_purchase_cost = 5.200` (not 0, not blended), `effective_unit_cost = 520/110` at scale 6, blended on-hand WAC correct; `price_entry_mode='total'` PO line still takes a PER-UNIT override (§2.6); invoice posting after an overridden single receipt clears 408 to 0 (interim basis fix).
- [ ] Implement → PASS → regression list by path → Commit: `feat(inventory): per-receipt-line received price → landed/accrual/effective cost + audit trail`

### Task 10: Receipt-batch freight allocation on received value (§2.4.1, H4)

**Files:** Create `ReceiptBatchCostAllocator.php` (constructor-injected `MoneyAllocator` — the largest-remainder engine already behind `LandedCostService::allocatePositiveShares :299-312`); modify `GoodsReceiptService` to consume it; Test `tests/Feature/Inventory/ReceiptBatchAllocationTest.php`.
**Produces:** for ONE receive call (the batch): batch freight pool = Σ over batch lines of the PO line's `allocated_costs` × (batch received_qty ÷ PO line qty) at working scale (single-receipt-batch case; owner resolution A4); reallocated across the batch's lines by **received value** = `received_qty × (received_unit_price ?? PO unit_price)`, largest-remainder absorber; free lines allocate on PAID value only; zero-priced lines get zero share; result feeds Task 9's `landed_unit_cost`.

- [ ] Failing tests: partial receipt (freight share ∝ received value, not PO line_total); override shifts shares; free line excluded from denominator; zero-price line; Σ shares == pool exactly (absorber).
- [ ] Implement → PASS → Commit: `feat(inventory): receipt-batch freight allocation on received value`

### Task 11: GL plug split — price delta → PPV; Cases A/B/C; WAC==GL invariant (§2.4.2, C2)

**Files:** Modify `GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry` (`:1175-1347`); Test `tests/Feature/Accounting/SupplierInvoicePpvGlTest.php`.
**Produces:** the plug (`:1226-1230` `plug = totalR − drKnown`; legs `:1292-1314`) is split: `priceDelta = billedHtR − accruedHtR` routes to `PurchasePriceVarianceExpense` (Dr, unfavorable) or `PurchasePriceVarianceIncome` (Cr, favorable); the remainder `plug − priceDelta` (non-recoverable VAT + sub-minor rounding) stays on Inventory. Zero legs omitted; the debits==credits assert (`:1329-1342`) unchanged. Inventory is never touched by the price delta ⇒ **WAC == GL inventory permanently**.

- [ ] Failing tests — the spec's walk-through as LITERAL cases (PO 100 @5.000, received @5.200, VAT 19%, scale 3):
  - **Case A** bill @5.200 → Dr 408 520.000 / Dr VAT 98.800 / Cr 401 618.800; no PPV leg; 408 nets 0.
  - **Case B** bill @5.000 → Dr 408 520.000 / Dr VAT 95.000 / **Cr PPV-Income 20.000** / Cr 401 595.000; balanced 615.000.
  - **Case C** bill @5.400 → Dr 408 520.000 / Dr VAT 102.600 / **Dr PPV-Expense 20.000** / Cr 401 642.600.
  - Non-recoverable-VAT case: VAT portion still capitalizes to Inventory, only the price delta diverts.
  - **WAC==GL invariant asserted in every case:** `product.cost_price × on-hand == Σ Inventory GL` after posting.
- [ ] Implement → PASS → regression `tests/Feature/Accounting/SupplierInvoiceGlTest.php` by path (existing plug tests updated ONLY where they asserted the price delta on Inventory — that behavior is the C2 bug being fixed; document each assertion change in the commit body).
- [ ] Commit: `feat(accounting): route invoice price variance to PPV; WAC==GL invariant`

---

## WAVE 5 — Matcher + posting on receipt-line basis

### Task 12: Snapshot columns migration + casts

**Files:** Create `2026_07_04_110000_add_match_snapshot_to_document_lines.php`; modify `DocumentLine.php` casts; Test in `tests/Feature/Procurement/SupplierInvoiceSnapshotTest.php` (schema part).
**Produces:** `document_lines.price_match_basis decimal(15,6) NULL` + `matched_receipt_line_id uuid NULL` (no FK per topology). Cast `price_match_basis => decimal:6`. `typescript:transform` after.

- [ ] Failing schema test → implement → PASS → Commit: `feat(procurement): invoice-line match-basis snapshot columns`

### Task 13: FIFO consumption planner + matcher rebase (§2.5)

**Files:** Create `ReceiptLineConsumptionPlanner.php`; modify `SupplierInvoiceMatcher.php`; Test `tests/Feature/Procurement/SupplierInvoiceMatcherReceiptBasisTest.php`.
**Interfaces — Produces:**

```php
/** Pure: no writes. Slices ordered by receipt-line created_at, id (FIFO). */
final class ReceiptLineConsumptionPlanner {
    /** @return list<array{receipt_line_id: string, qty: numeric-string, basis: numeric-string}> */
    public function plan(string $poLineId, string $qtyToConsume): array;
    /** matchableQty per receipt line = received_qty + free_qty − quantity_invoiced (scale 4) */
    public function matchableQty(GoodsReceiptLine $line): string;
}
```

Matcher changes: aggregate matchable per PO line = Σ planner `matchableQty` over that PO line's receipt lines (**compat fallback:** when a PO line has ZERO receipt lines, keep today's PO-line aggregate `matchableQty :145-153` — Codex rec #2); `computePriceStatus` (`:460-502`) compares the invoice `unit_price` against **the snapshot `price_match_basis` when set, else the qty-weighted FIFO-planned receipt-line `accrual_unit_cost`**, else (fallback POs) the PO `unit_price` as today. Dual-threshold tolerance math (`:488-495`) untouched.

- [ ] Failing tests: two receipts 60@5.200 + 40@5.400, invoice 100@5.200 → PriceVariance only if outside tolerance vs weighted basis 5.280; matchable honors per-receipt-line `quantity_invoiced`; zero-receipt-line PO → legacy basis (fallback); bonus lines still checked against free windows (`:369-428` behavior preserved).
- [ ] Implement → PASS → Commit: `feat(procurement): matcher consumes receipt-line accrual basis FIFO with PO-line fallback`

### Task 14: Creation-time snapshot stamping (H5)

**Files:** Modify `CreateSupplierInvoiceService.php` (line creation); Test extends `SupplierInvoiceSnapshotTest.php`.
**Produces:** at invoice-line creation, run the planner for the line's qty and stamp `price_match_basis` = qty-weighted basis of the planned slices, `matched_receipt_line_id` = first planned slice's receipt line (deterministic; multi-slice truth stays derivable from FIFO order — owner resolution A5). Fallback POs stamp `price_match_basis` = legacy basis, `matched_receipt_line_id = NULL`.

- [ ] Failing tests: creation stamps both columns; **no-reclassify test** — draft created + matched today, a NEW receipt at a different price is entered, `match()` and posting still evaluate against the SNAPSHOT (status unchanged); rematch endpoint (`useRematchSupplierInvoice` path) refreshes the snapshot explicitly.
- [ ] Implement → PASS → Commit: `feat(procurement): snapshot match basis at invoice creation`

### Task 15: Posting rebased to receipt-line clearing

**Files:** Modify `SupplierInvoicePostingService.php`; Test `tests/Feature/Procurement/SupplierInvoiceReceiptClearingTest.php`.
**Produces (inside the one transaction, replacing steps 2/6 of `:52-243`):** lock the consumed `goods_receipt_lines` `lockForUpdate()->orderBy('id')` (deterministic order — mirror the R3D-11 rule) IN ADDITION to the PO-line lock (`:70-75`, add `orderBy('id')` there too); FIFO-walk each PO-line group via the planner ON LOCKED STATE, increment `goods_receipt_lines.quantity_invoiced` per slice, over-clear guard per receipt line (replaces the PO-line guard `:163-172`, which stays as a derived-counter double-check); `accruedHt = Σ slice_qty × slice.accrual_unit_cost` at working scale (replaces `:133`; the B3 guard `:140-152` is retired — each slice clears at its own immutable stored basis BY CONSTRUCTION; keep a defensive assert that the receipt-line basis is non-null); PO-line counters re-derived from ledger sums after increments; posting re-runs the matcher against the snapshot (Task 13/14) — `assertPostable` semantics (hard qty/exception always throw; price advisory under Warn/Block) unchanged.

- [ ] Failing tests: **multi-price two-receipt clearing (the case Rev 1's 422 forbade):** 60@5.200 + 40@5.400 received, one invoice for 100 posts, accruedHt = 528.000, 408 nets EXACTLY 0, both receipt lines fully invoiced, any billing delta → PPV (Wave 4), WAC==GL; partial invoice 50 consumes FIFO only the first receipt; over-clear vs receipt line → throw; concurrent posts on a shared receipt line serialize (lock test per the credit-note D1 precedent); zero-receipt-line PO posts via fallback basis identically to today; idempotent re-post no-op (`:77-85`) preserved.
- [ ] Implement → PASS → regression list by path → Commit: `feat(procurement): posting clears receipt lines FIFO at per-line accrual basis`

### Task 16: `procurement:rematch-drafts` command

**Files:** Create `RematchDraftSupplierInvoicesCommand.php`; Test `tests/Feature/Procurement/RematchDraftsCommandTest.php`.
**Produces:** `procurement:rematch-drafts {--company=} {--dry-run}` — iterates Draft supplier invoices (fleet or one company), re-runs the planner + matcher, REFRESHES snapshots + `match_status`, reports transitions (esp. anything that would now Block). Explicit currency → scale resolution (console context). Release-note line for `match_enforcement=block` tenants added to the plan's PR body.

- [ ] Failing tests: pre-ledger draft gets snapshot stamped; status transitions reported; `--dry-run` writes nothing; idempotent.
- [ ] Implement → PASS → Commit: `feat(procurement): rematch-drafts transition command`

---

## WAVE 6 — Receive-dialog frontend

### Task 17: Payload/types plumbing — `received_unit_prices` + `meta.goods_receipt`

**Files:** Modify `ReceiveGoodsDialog.tsx` types (`ReceiveGoodsRequest :15-19` gains `received_unit_prices?: Record<string,string>` and `price_override_reason?: string`), `PurchaseOrderDetailPage.tsx` + `GoodsReceiptListPage.tsx` (`:144` apiPost passthrough + typed receive response `meta.goods_receipt`); Test colocated Vitest.
**Produces:** string-only payloads; the receive mutation surfaces `meta.goods_receipt.receipt_number`.

- [ ] Failing Vitest: payload includes prices only for edited lines, all strings; response meta typed → implement → PASS → Commit.

### Task 18: Gated price cell + variance chip + bonus cells (§2.9)

**Files:** Modify `ReceiveGoodsDialog.tsx`; Test `ReceiveGoodsDialog.test.tsx`.
**Produces:** per line — "PU commande" (read-only, PO `unit_price` threaded via `ReceivableLine`), "PU livré" `<MoneyInput>` rendered editable ONLY when `hasPermission('goods-receipt.edit-price')` (from `usePermissions`), empty ⇒ PO price (helper text per the sketch); "Écart" chip = signed % via the existing `bccomp`/`bcsub` decimal helpers (`:7` — **never** `parseFloat`; note the sibling `PurchaseOrderDetailPage` parseFloat offender is NOT copied); existing free-quantity cells (`:255-267`) kept and shown alongside per the §2.9 layout; optional override-reason input appears when any price is edited.

- [ ] Failing Vitest: cell read-only without permission; chip math (5.000→5.200 = `+4.0%`, equal = `—`); empty price omitted from payload; bonus cell regression; i18n keys exist in FR/EN/AR.
- [ ] Implement → PASS → Commit: `feat(purchases): permission-gated delivered-price cell + variance chip in receive dialog`

### Task 19: GRN surfacing + i18n

**Files:** Modify the two receive call sites (success toast "Réception GRN-2026-0031 enregistrée" from `meta.goods_receipt`), locales `(fr|en|ar)` receive.* keys.
- [ ] Vitest: toast renders GRN; keys present in all 3 locales → Commit.

### Task 20: Playwright receive-at-variance pass

- [ ] One E2E: confirmed PO @5.000 → open receive dialog as a permission-holding user → set PU livré 5.200 → submit → GRN toast shown → receive again (remainder) at 5.400 → second GRN, **no blocking error** (two-price-two-receipt) → PO detail shows received. Run against the local dev stack.
- [ ] `pnpm test` (scoped paths), `pnpm typecheck`, `pnpm lint` clean → Commit: `test(purchases): receive-at-variance e2e`

---

## Execution protocol (Codex waves)

1. Per wave: session owner (Claude) writes `docs/sessions/CODEX-TASK-<wave>.md` INTO the worktree = this plan's wave section + spec §refs inlined + the Global Constraints block.
2. Dispatch: `cd /Users/houssamr/Projects/syneriva/apps/erp.procurement-v2 && node ~/.claude/plugins/cache/openai-codex/codex/<ver>/scripts/codex-companion.mjs task --write --background --fresh "Read docs/sessions/CODEX-TASK-<wave>.md and execute it fully."` (flags as separate argv tokens BEFORE the prompt — apostrophe trap).
3. Codex CANNOT git-commit in the worktree → it maintains `docs/sessions/TASK-LOG-<wave>.md`; Claude reviews the diff, runs scoped verification, commits.
4. Review gate per wave: adversarial review (treasury-reviewer agent MANDATORY for Waves 4–5 — GL/WAC surface) → findings fixed → commit batch → merge `feat/procurement-completeness` → local `post-demo`. **Waves 4 and 5 merge to `post-demo` together** (interim single-basis window).
5. Promotion to demo/`origin/dev`: OWNER decision only, after stability.

## Self-review (done)

- Spec coverage: §2.3 schema→T1, GRN numbering→T2, §2.3.1 derived counters→T3, DTOs→T4, §2.3.2 backfill + fallback→T5/T13, §2.8 FormRequest→T7, §2.7 permission+audit→T7/T9, PPV purposes + §2.4.2 Cases A/B/C + WAC==GL→T8/T11, §2.4 cost flow + §2.6 paid-last/bonus→T9, §2.4.1 allocation→T10, §2.5 matcher/snapshot/posting/rematch→T12–T16, §2.6 multi-price no-422 clearing→T15, §2.9 UI→T17–T20. Non-goals honored (no PO `unit_price` mutation, no credit-note true-up, `purchase_order_id` NOT NULL).
- Grounding deltas from spec citations are declared up front (worktree already has bonus flow; paid-last already true → pinned, not built).
- Interim Wave-4/5 basis window explicitly managed (T9 step 6 + merge rule).
- Ambiguities A1–A7 resolved by the session owner (see OWNER RESOLUTIONS block at top).
