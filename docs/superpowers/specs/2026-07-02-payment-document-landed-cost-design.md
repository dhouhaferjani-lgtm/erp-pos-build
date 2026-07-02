# Payment ↔ Document Linking with Landed-Cost Allocation — Design Spec

- **Date:** 2026-07-02
- **Status:** DESIGN — **Revision 2** (adversarial Codex review disposition folded in; see §16). Owner review pending; implementation post-demo.
- **Author:** Claude (spec-writing session; all cited behavior verified against code on `dev`)
- **Revision 2 note:** Six review blockers addressed — receipt boundary redesigned per-line (§4/D4), sold-portion COGS split added (§5.2), supplier-invoice-backed cost variant scoped (§2.5/§13), Phase-1 reversal specified (§4.6), multi-PO resolution corrected to one-PO-today (§3.2), `recordCostAdjustment` float→string elevated to hard prerequisite (§0/§10/§11). Split-method enum added (§5.2). Full disposition table in §16.
- **Owner requirement (verbatim intent):** When creating a payment, the user chooses whether it is (a) a GENERIC expense (utility bills, electricity, water, lease…) or (b) a SPECIFIC cost linked to a transaction (transfer fees, transport fees, any operation tied to a receipt or delivery). If specific: they pick the related invoice (sales or purchase). If that invoice is directly linked to exactly ONE receipt/delivery operation, the fee auto-links to it and impacts weighted average cost / landed cost of the items in THAT operation. If the invoice spans MULTIPLE delivery operations, the user gets a second selector to pick WHICH operation; costs impact only that operation's items. For sales-side (delivery/transport on outbound), the fee reduces profit rather than entering WAC.

---

## 0. Executive summary — the decisions

| # | Question | Decision |
|---|----------|----------|
| D1 | Which spine carries the "payment"? | **The Expense spine** (Document `type=expense` + ExpenseMetadata), extended with `expense_kind = generic \| linked_cost`. NOT a Treasury `Payment` — `payment_allocations` settle `balance_due` via a PG trigger and reject supplier invoices; a fee must never reduce the target invoice's balance. |
| D2 | How does the fee reach the costing engines? | A **`DocumentAdditionalCost` row on the resolved OPERATION document**, with the existing `expense_document_id` column pointing back at the Expense. One link, both engines read it. |
| D3 | What is an "operation"? | Purchase side: **the PurchaseOrder's receipt scope** (Phase 1 grain = the PO; a per-receipt-event grain is impossible today — goods receipts are not documents, only PO-line counters + StockMovements — deferred to Phase 3). Sales side: **the DeliveryNote document**. |
| D4 | Post-receipt cost impact (the common case)? | Fee allocated across the operation's **received** lines by a chosen **split method** (value default; §5.2), then each line-share is split **sold-portion → Dr COGS, on-hand-portion → `recordCostAdjustment` (WAC) → Dr Inventory**. The pre/post boundary is decided **per PO line** (a line's `accrual_unit_cost !== null` ⇔ that line has been received), NOT by the PO-level `goods_received_at` flag (D4a). **Never** re-run `LandedCostService` after receipt (B3 guard / 408 residue). |
| D4a | Pre/post-receipt boundary grain | **Per-line, receipt-basis.** `GoodsReceiptService` sets PO-level `payload['goods_received_at']` only on **FULL** receipt (`GoodsReceiptService.php:243`, `= $fullyReceived ? … : null`), but stamps per-line `accrual_unit_cost` on **any** (even partial) receipt (`:219-221`). Using `canModifyCosts()` (which reads only `goods_received_at`, `LandedCostService.php:519-525`) as the boundary would treat a partially-received PO as pre-receipt and let a landed-cost reallocation strand 408 residue (the B3 guard, `SupplierInvoicePostingService.php:134-151`). The resolver/applicator classify **each line** by `accrual_unit_cost` and route received-line shares to `wac_adjustment` and not-yet-received-line shares to `landed_cost`. |
| D5 | Pre-receipt cost impact? | Existing rail untouched: the `DocumentAdditionalCost` row flows through `LandedCostService::allocateCostsAndTaxes` at receipt into `landed_unit_cost` → `recordPurchase`. (Phase 2.) |
| D6 | Sales-side cost impact? | **No WAC, no inventory.** GL identical to a generic expense (Dr expense-category / Cr cash-bank); the link row exists purely for per-invoice/DN profitability reporting. |
| D7 | GL for purchase-side linked costs? | **Dr Inventory / Cr Cash-or-Bank** (new `createLinkedCostCapitalizationEntry`), with per-product zero-on-hand fallback to the expense-category account. This closes an existing GL-vs-WAC divergence (§5.4). |
| D8 | Multi-op selector | Second selector shown only when the resolver returns >1 operation; 0 operations → user chooses "keep as generic" or (purchase, not-yet-received) "attach to PO for landed cost". |
| D9 | Mutability | Costing applies at Expense **post**, is idempotent (`applied_at`), and is immutable afterward. |
| D9a | Reversal (Phase 1, NOT deferred) | A **contra endpoint** reverses a posted linked cost: mirror-image GL (`createLinkedCostCapitalizationReversalEntry`), a negated `recordCostAdjustment` WAC contra per product (formula at `:722` handles negative deltas), and a `DocumentAdditionalCost` reversal row (soft-delete + a reversing `-amount` row referencing the original). Cash side effects likewise reversed (repository inflow). WAC + GL + cash cannot be corrected by hand without divergence, so this ships in Phase 1 (§4.6). |
| D9b | Fee-carrying spine choice | Phase 1: **plain Expense** (lightweight, fee paid immediately, no recoverable TVA, no AP). Phase 2: **supplier-invoice-backed cost variant** — the transport fee is a real `SupplierInvoice` Document (401/AP lifecycle, recoverable TVA, timbre) linked into the operation via the SAME `document_additional_costs.expense_document_id` FK (it `belongsTo Document`, so it accepts a SupplierInvoice id). Tunisian fee reality (fee TVA recoverable/not, retenue à la source on freight, installments) needs the AP path (§2.5). |
| D10 | Phase 1 | Purchase-side, post-receipt, single-currency, PO-grain, **expense-paid fee** — the "transport invoice arrives after the goods and is paid on the spot" case, with reversal. |

### Hard prerequisites (blocking — must land before any money flows through this feature)

- **P0 — `WeightedAverageCostService::recordCostAdjustment` float→string.** The method still declares `float $additionalCost` (`WeightedAverageCostService.php:676`) and does `CurrencyScale::bcformat($additionalCost, …)` on that float (`:721`) — a precision-contract violation (rule 19: no float touches money). **No linked-cost money may pass through it until this signature is `string` (numeric-string).** This is a Phase-1 task-1 blocker, not a nice-to-have (§10, §11). Sole other caller family (`StockTransferService`) migrated in the same change.

---

## 1. Verified rails (what exists today, file:line)

All paths relative to `apps/api/` unless noted. Verified 2026-07-02.

### 1.1 Treasury payment spine

- `app/Modules/Treasury/Domain/Payment.php` — table `payments` (:71); fillable has **no `document_id`** (:73-105); `amount => decimal:3` (:110-124); document linkage only via `allocations()` HasMany PaymentAllocation (:185-188).
- `app/Modules/Treasury/Domain/PaymentAllocation.php` — table `payment_allocations` (:30); fillable `payment_id, document_id, amount, tolerance_writeoff` (:32-37); `payment_id` **nullable** for tolerance-only writeoffs (:17, :53-56; migration `database/migrations/tenant/2026_04_26_120000_make_payment_id_nullable_on_payment_allocations.php:26`).
- `app/Modules/Treasury/Application/Services/PaymentAllocationService.php` — **`balance_due` is updated by a PostgreSQL trigger when a PaymentAllocation row is created** (:201-202, :227-231); documents flip to `Paid` at zero balance (:232-248); **supplier invoices are rejected** with 422 `SUPPLIER_INVOICE_NOT_PAYABLE_HERE` (:182, :579-592). Allocation is sequential FIFO/due-date, no largest-remainder.
- Payment creation is inline in `app/Modules/Treasury/Presentation/Controllers/PaymentController.php::store` (:105-698, `Payment::create` :404-420); no FormRequest exists; request accepts `allocations[].document_id` but the payments row itself never references a document.
- GL for payments lives on `app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`: `createPaymentReceivedJournalEntry` (:615-678, Dr Bank-Cash / Cr AR), `createSupplierPaymentJournalEntry` (:548-606, Dr AP / Cr Bank-Cash), `createCustomerAdvanceJournalEntry` (:320-378), `createPaymentToleranceJournalEntry` (:691+).

### 1.2 Expense spine (separate from payments — confirmed)

- `app/Modules/Expense/Application/Services/ExpenseService.php` — `create()` builds a `Document` with `type = DocumentType::Expense` + an `ExpenseMetadata` row inside one transaction (:33-77, doc :50-60, metadata :63-73). **No Payment row is created; inventory is never touched.** `post()` (:122-158) numbers the doc, posts GL via `GeneralLedgerService::createFromExpense` (:135), and if `is_paid && payment_repository_id` decrements the cash repository via `RepositoryOutflowInterface::applyOutflow` (:141-149).
- `app/Modules/Expense/Domain/ExpenseMetadata.php` — table `expense_metadata` (:42); fillable `document_id, expense_category_id, payment_method_id, payment_repository_id, payment_date, is_paid, receipt_number, vendor_name, idempotency_key` (:47-57).
- `app/Modules/Expense/Presentation/Requests/ExpenseRequest.php` (:46-75) — `total` required with ≤3-decimal regex; `idempotency_key` supported.
- `GeneralLedgerService::createFromExpense` (:2047-2126) — **Dr expense-category account** (category `account_id` or `SystemAccountPurpose::GeneralExpense`, :2054-2069) / **Cr Cash or Bank** by repository type (:2072-2077); `source_type='expense'`, `source_id=$expense->id` (:2089-2090).

### 1.3 Landed cost + WAC rails

- `app/Modules/Document/Domain/DocumentAdditionalCost.php` — table `document_additional_costs` (:30); fillable `document_id, cost_type, description, amount, expense_document_id` (:32-38); `amount => decimal:3` (:46); **`expense_document_id` nullable, belongsTo Document** (:20, :65-68) — the payment↔cost linkage column already exists. `cost_type` is a free string (docblock :17); allowed set enforced only in controller validation.
- `app/Http/Controllers/Api/DocumentAdditionalCostController.php` — `POST /api/v1/documents/{document}/additional-costs`, gated `can:purchase-orders.update` (`app/Modules/Document/Presentation/routes.php:295-297`); validates `cost_type in:transport,shipping,insurance,customs,handling,other`, `amount` ≤3 decimals, `expense_document_id` tenant+company-scoped exists (:46-61).
- `app/Modules/Inventory/Application/Services/LandedCostService.php` — `allocateCosts` (:83-133), `allocateCostsAndTaxes` (:147-244), `reallocateCosts` (:251-299). Largest-remainder: `absorberIndex` picks the LAST line with `line_total > 0` (:335-348); `allocateShare` gives the absorber the exact running remainder, others `bcmul` truncated at currency scale (:365-384). `landed_unit_cost` persisted at `COST_SCALE = 6` with no currency truncation (:46, :395-420). **`canModifyCosts` returns false once `payload['goods_received_at']` is set** (:519-525).
- `app/Modules/Inventory/Application/Services/GoodsReceiptService.php` — per-line unit cost = `landed_unit_cost ?? unit_price` as numeric-string (:154-157) → `recordPurchase` (:167-176); emits `GoodsReceived` with the landed unit cost (:181-191). **Receiving does NOT create a document** — it mutates PO-line `quantity_received` counters and PO status (`processReceiptLines` :224-225, :238-245; `PurchaseOrderToGoodsReceiptConverter` docblock :16-38).
- `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` — `recordPurchase(Product, Location, string $quantity, string $landedUnitCost, …)` (:144-153). **`recordCostAdjustment(Product $product, float $additionalCost, string $reason, string $tenantId, string $companyId, ?string $reference, ?string $referenceType, ?string $referenceId): ?StockMovement`** (:674-683): `new_avg = current_avg + additional_cost / company_owned_qty` (:652, :722-723); **no-op returning `null` when owned qty ≤ 0** (:702-705); creates a **quantity-`'0'` Adjustment StockMovement** with `avg_cost_before/after` audit fields (:741-759); **posts NO journal entry itself** — only mutates `product.cost_price`. ⚠️ Takes `float` — precision-contract violation for new callers (§10).

### 1.4 Invoice ↔ operation relationships

- `app/Modules/Document/Domain/Document.php` — `sourceDocument()` BelongsTo self via `source_document_id` (:266-269); `childDocuments()` (:349-352); `getDocumentChain()` walks ancestors/descendants/siblings (:364-392). Sales invoice → delivery notes resolved via `childDocuments()->where('type', DocumentType::DeliveryNote)` filtered confirmed/posted (:699-703) inside `getFulfillmentStatus` (:692+), matched by `product_id` (:731-733). **No dedicated invoice↔DN FK.**
- `app/Modules/Document/Domain/Enums/DocumentType.php` (:7-18) — `Quote, SalesOrder, PurchaseOrder, Invoice, CreditNote, DeliveryNote, ReturnNote, Expense, SupplierInvoice, SupplierCreditNote`. No `GoodsReceipt` case exists.
- `app/Modules/Procurement/Application/SupplierInvoiceMatcher.php` — 3-way matching at **PO-line grain** via counters: `matchableQty = quantity_received − quantity_invoiced` (:141-149); invoice lines point at PO lines via `source_line_id`; qty grouping validates the referenced parent is a PurchaseOrder of the same company (:304-313) — **not necessarily the same PO**. **There is no invoice↔receipt link table.**
- `app/Modules/Procurement/Application/SupplierInvoicePostingService.php` — clears GR-IR at the SAME basis accrued on receipt, `accrual_unit_cost = landed_unit_cost ?? unit_price` (:127-132); **B3 guard throws if the clearing basis diverges from the immutable receipt-accrual basis** — "landed_unit_cost was reallocated after receipt … irreconcilable residue on account 408" (:134-151); increments `quantity_invoiced` (:157, :173).
- GR-IR GL: `app/Modules/Accounting/Listeners/PostGrIrOnGoodsReceipt.php` (:31-53) → `GeneralLedgerService::createGoodsReceiptGrIrEntry` (:1051-1143, Dr Inventory / Cr 408 at landed basis, idempotent on movement id); `createSupplierInvoiceGrIrClearingEntry` (:1174-1330) — Dr 408 (accrued HT) + Dr VAT + Dr timbre / Cr 401 (invoice total), with an **Inventory plug** = Cr401 − (Dr408+DrVAT+Dr timbre) absorbing variance and rounding (:1161-1163, :1225-1229, :1291-1310).

### 1.5 Treasury audit warnings (touch, don't deepen)

- `payment_repositories` **`account_id` vs `gl_account_id` split-brain**: GL services read `gl_account_id` (`PaymentAllocationService` :253/:282/:303; `PaymentController` :253/:604/:634); fiscal projection bridges and `VendorRefundService` read `account_id` (`TreasuryAccountPaymentBridge.php` :216-224, `VendorRefundService.php` :154-155); the repository CRUD API writes only `account_id` (`PaymentRepositoryController.php` :79/:96/:137). **This design reads neither column directly** — it reuses `createFromExpense`'s purpose-based Cash/Bank resolution (:2072-2077) and adds nothing to the split.
- Repository balance is a **mutable snapshot with no movement ledger**; `RepositoryOutflowService::applyOutflow` only ever subtracts (:34-55) and its sole caller is `ExpenseService.php:142`; PaymentController mutates balances inline (:572-575, :930). **This design reuses the existing `applyOutflow` call unchanged** (the Expense spine already does it) and introduces no new balance writers. The ledger redesign remains a non-goal (§14).

---

## 2. D1 — Classification and spine mapping (one coherent rule)

### The rule

> **Every user-initiated outflow of this kind is an Expense** (Document `type=expense` + ExpenseMetadata — the spine that already carries payment method, repository, paid-date, vendor). The classification `expense_kind` decides whether the Expense additionally materializes **exactly one `DocumentAdditionalCost` row on an operation document**, with `expense_document_id` pointing back. The costing engines (`LandedCostService` pre-receipt, `recordCostAdjustment` post-receipt) and profitability reporting all consume that single row. **No Treasury `Payment` row is ever created by this flow.**

So the three "spines" collapse into one flow:

| `expense_kind` | Expense row | DocumentAdditionalCost | Costing | GL |
|---|---|---|---|---|
| `generic` | yes (unchanged today) | no | none | Dr expense-category / Cr cash-bank (unchanged, :2047-2126) |
| `linked_cost`, purchase side, PO lines received (per-line, §4.1) | yes | yes, on the **PO**, `application_path=wac_adjustment` | `recordCostAdjustment` for the **on-hand fraction** per product at post; **sold fraction → COGS** (§5.2) | **Dr Inventory** (on-hand) **+ Dr COGS** (sold) / Cr cash-bank (§5.2) |
| `linked_cost`, purchase side, PO line not yet received | yes | yes, on the **PO**, `application_path=landed_cost` | existing `allocateCostsAndTaxes` at receipt (no new costing code) | **Dr Inventory** / Cr cash-bank (§5.3) |
| `linked_cost`, sales side | yes | yes, on the **DeliveryNote** (or the invoice when no DN), `application_path=profit_only` | none | Dr expense-category / Cr cash-bank (identical to generic; link row is reporting-only, §5.5) |

### Why NOT Payment + PaymentAllocation (alternative rejected)

1. **`payment_allocations` rows settle debt**: a PostgreSQL trigger recomputes the target document's `balance_due` on every allocation insert (`PaymentAllocationService.php:201-202`), and the document flips to `Paid` at zero (:232-248). A 50 TND transport fee "allocated" against a 1 000 TND customer invoice would wrongly reduce that invoice's receivable. The semantics are irreconcilable.
2. Supplier invoices are **hard-rejected** by the allocation service (:579-592) — half the owner's use case (fees on purchase invoices) cannot even enter that spine.
3. The Payment GL wiring (Dr/Cr AR/AP, :548-678) is settlement wiring; a linked cost needs Inventory/expense legs. Reusing it means forking every method.
4. `Payment` gates GL on `repository->gl_account_id` while projection bridges read `account_id` (§1.5) — building on Payment deepens the split-brain; building on Expense (purpose-based Cash/Bank accounts) does not.

**Trade-off acknowledged:** the owner says "payment"; the UI will present this as recording a payment/décaissement (§8), but the persisted artifact is an Expense. If the money-movement spine is ever built (treasury audit follow-up), Expenses and Payments unify under it — this design adds no obstacle (non-goal §14).

### Why DocumentAdditionalCost as the link (alternative rejected: new `expense_document_links` table)

`document_additional_costs.expense_document_id` was **built for exactly this** (:20, :65-68, controller validation :50-58) but nothing populates it today from the expense side. Reusing it means: pre-receipt costing needs **zero** new code (the row is already what `allocateCostsAndTaxes` sums), the receipt-time GR-IR accrual already includes it (landed basis, :1075), and the audit trail is one row. A parallel link table would force every costing engine to read two sources.

### 2.5 Fee-carrying spine — the Expense path is Phase-1-only; the supplier-invoice-backed variant is Phase 2 (finding #3)

The plain-Expense spine is deliberately lightweight and **cannot represent the full Tunisian supplier-fee reality**. Verified gaps:

- **No partner/AP.** `expense_metadata` carries only a free-text `vendor_name` (`ExpenseMetadata.php:47-57`) — there is no `partner_id`, no 401 accounts-payable balance, no open-item to age. A `createFromExpense` posting credits Cash/Bank directly (`GeneralLedgerService.php:2072-2077`); it never books a payable.
- **No fee TVA split (recoverable vs not).** The Expense GL is a single Dr expense-category / Cr cash pair — no VAT leg. A transport fee with recoverable Tunisian TVA (deductible 19%) cannot be split into a récupérable-VAT asset via this path.
- **No retenue à la source.** Withholding exists as its own module (`app/Modules/Taxation/**` — `WithholdingCertificateService`, `SalesWithholdingTrackingData`, TEJ export) but is **not wired into the Expense flow**; freight retenue cannot be captured or certified from an Expense.
- **No installments.** No `payment_schedule`/échéancier concept exists anywhere; the Expense is paid-or-not (`is_paid`).

By contrast, the **SupplierInvoice** Document already has the full AP lifecycle: `partner_id`, `balance_due` = total set at post so the payment path can settle it (`SupplierInvoicePostingService.php:207-209`), and the GR-IR clearing entry books **Dr 408 (HT) + Dr VAT + Dr timbre / Cr 401** (`GeneralLedgerService.php:1174-1330`).

**Decision (D9b):**
- **Phase 1 = expense-paid fees only.** Scope: the fee is a plain Expense, paid immediately, no recoverable-TVA split, no retenue, no installments. This is the owner's stated common case ("transfer fees, transport fees … when creating a payment") and is honest about its limits. If the user needs TVA/retenue/AP on the fee, Phase 1 directs them to record it as a supplier invoice (existing flow) — the linked-cost capitalization for that invoice is Phase 2.
- **Phase 2 = supplier-invoice-backed cost variant.** A transport/freight invoice is created as a **real `SupplierInvoice` Document** (its own AP lifecycle, VAT, timbre, retenue via the Taxation module) and then **linked as a cost document** onto the operation. The link reuses the **same `document_additional_costs.expense_document_id` FK** — verified `belongsTo(Document::class, 'expense_document_id')` (`DocumentAdditionalCost.php:64-68`), so it accepts a SupplierInvoice id as readily as an Expense id (the column name is legacy; semantically it is "the cost-bearing document"). Capitalization into WAC/landed cost is identical (§5); the difference is purely upstream (which document funds the fee and how its VAT/AP settle). **Industry parity:** Business Central's "item charge on a separate invoice linked to a posted receipt line" is exactly this variant (review-verified). Phase-2 shape sketch:
  - Create SupplierInvoice (existing `POST /supplier-invoices`) OR flag an existing one as `is_cost_document=true`.
  - Link: `DocumentAdditionalCost{document_id: operation, expense_document_id: supplierInvoice.id, cost_type, amount: invoice HT (VAT excluded — VAT is recoverable, not capitalized), application_path}`.
  - Capitalization uses the **HT** amount (VAT récupérable never enters cost); retenue reduces the cash paid to the supplier, not the capitalized base.
  - GL for capitalization stays §5.2/§5.3 (Dr Inventory) but is **decoupled** from the SupplierInvoice's own AP/VAT GL (that posts via the existing supplier-invoice path). One caveat to design in Phase 2: avoid double-counting Inventory — if the fee flows through GR-IR as part of `landed_unit_cost`, the capitalization entry must not also Dr Inventory. Phase 2 spec resolves this (likely: supplier-invoice-backed = `landed_cost` path only, consumed at receipt, no separate capitalization JE).

---

## 3. D3 — "Operation": precise definition and resolution logic

### 3.1 Definition

- **Purchase operation** = a **PurchaseOrder's receipt scope**. Verified constraint: receiving goods does NOT create a document — `PurchaseOrderToGoodsReceiptConverter` mutates `quantity_received` counters on PO lines in place (docblock :16-38; `GoodsReceiptService.php:224-245`); the only per-receipt artifacts are `StockMovement` rows and `GoodsReceived` events keyed by `poLineId`/`movementId`. There is **no receipt header entity** to select. Therefore Phase 1 operation grain = **the PO**. A per-receipt-event grain (PO received in 3 partial deliveries, fee on delivery #2) requires a `goods_receipts` header table — **deferred to Phase 3** (§13). This is an honest limitation, and it is mild: post-receipt WAC adjustment spreads over company-owned quantity regardless (§5.2), so the receipt-event grain would change only allocation weights and audit display, not the accounting mechanism.
- **Sales operation** = a **DeliveryNote document** (`DocumentType::DeliveryNote`).
- **Direct product entry/exit** (ad-hoc StockMovements without documents) — out of scope for Phases 1–2; the selector never offers them.

### 3.2 Resolver contract (`OperationResolver`)

New Document-module application service, exposed via `Shared/Contracts/Document/OperationResolverInterface`:

```php
resolve(Document $invoice): OperationResolution
// OperationResolution DTO:
//   side: 'purchase' | 'sales'
//   operations: list<OperationRef{document_id, kind, number, date, status,
//                                 received_at?, line_count, total, currency}>
//   auto_selected_id: ?string   // set iff count === 1
```

**Purchase resolution** (invoice type `SupplierInvoice` or `SupplierCreditNote`) — **one invoice → one PO today** (finding #5, corrected):
the operation is the invoice's single `source_document_id` PO. `CreateSupplierInvoiceRequest` **requires a single `source_document_id`** (a PurchaseOrder for this company, `:55-59`, `:113-123`) and validates that **every** `lines.*.source_line_id` belongs to **that** PO (`:143-162`) — a supplier invoice cannot span multiple POs at creation. So the resolver returns exactly one operation (the `source_document_id` PO) for a well-formed supplier invoice; `auto_selected_id` is always set. The earlier claim that the matcher's per-line PO validation (`SupplierInvoiceMatcher.php:304-313` — validates each line's parent is *a* PO of the same company) permits genuine multi-PO invoices was **overstated**: the matcher is a downstream 3-way-match helper, not the creation gate, and the creation request forbids it. **Multi-PO invoices are a future capability** (Phase 3+, would require relaxing `CreateSupplierInvoiceRequest` first); the multi-operation selector below is therefore **dead in Phase 1 for purchase** and exists only for the sales side (where one invoice legitimately pulls several delivery notes).

**Sales resolution** (invoice type `Invoice` or `CreditNote`):
delivery notes = `childDocuments()` where `type=DeliveryNote` and status confirmed/posted (`Document.php:699-703`) **union** any DeliveryNote in the invoice's ancestor chain (`getDocumentChain()` :364-392 — covers "many delivery notes pulled into one invoice" where DNs precede the invoice). De-duplicated by id.

### 3.3 Single / multi / zero rules

- **Exactly 1 operation** → auto-link. The UI shows a read-only chip ("Linked to BC-2026-0042 — received 2026-06-30"); no second selector. The API still records the resolved `linked_operation_id` explicitly (no hidden magic in the write path). **This is always the purchase case in Phase 1** (one supplier invoice → one PO, §3.2).
- **>1 operation (sales side only in Phase 1)** → the second selector is mandatory; the API rejects a missing `linked_operation_id` with 422 `LINKED_OPERATION_REQUIRED` and returns the candidate list. Exactly **one** operation per expense (owner: "costs impact only that operation's items"). A fee genuinely spanning operations = several expenses (or one generic). Purchase-side never reaches this branch until multi-PO invoices exist (Phase 3+).
- **Purchase PO exists but is not (fully) received** — the operation resolves (the PO is always the `source_document_id`), but line-level receipt state (§4.1) decides Phase-1 admissibility:
  - **No line received** → pure pre-receipt (`application_path=landed_cost`, §5.3). Phase 1 (post-receipt only) **blocks** with 422 `OPERATION_NOT_RECEIVED`, directing the user to the existing PO additional-costs screen. Phase 2 lifts this.
  - **Some but not all targeted lines received** → Phase 1 **blocks** with 422 `OPERATION_PARTIALLY_RECEIVED` (§4.1 — Phase 1 refuses to mix `wac_adjustment` and `landed_cost` paths within one expense). Phase 2 handles the mixed case per line.
  - Sales invoice with no delivery note, or supplier invoice with no PO (service invoice) → dialog: **"No linked operation found — record as a general expense instead?"** Accepting downgrades `expense_kind` to `generic` (sales-side may still tag the invoice itself with a `profit_only` row for margin reporting — offered as a checkbox, Phase 2).

---

## 4. D9 — Timing and mutability

The **common case is post-receipt** (transport invoice arrives after the goods). The existing rails are hostile to post-receipt landed-cost changes, deliberately:

- `LandedCostService::canModifyCosts` returns false once `payload['goods_received_at']` exists (`:519-525`) — **but that flag is set only on FULL receipt** (`GoodsReceiptService.php:243`).
- `SupplierInvoicePostingService` B3 guard **throws at posting** if the accrual basis moved after receipt (`:134-151`) — reallocating landed cost post-receipt strands residue on account 408. The guard is keyed off the **per-line** `accrual_unit_cost`, which is stamped on **any** (even partial) receipt (`GoodsReceiptService.php:219-221`).

### 4.1 The boundary is per-line, not per-PO (finding #1 — corrected)

**Revision-1 error:** using `canModifyCosts($po)` as the single pre/post switch is unsound. A PO received in two partial deliveries has `goods_received_at = null` (not fully received) yet has already-received lines with a stamped `accrual_unit_cost`. `canModifyCosts` would return `true` → the whole fee would route to the `landed_cost` path → `LandedCostService` reallocation → the received line's `landed_unit_cost` shifts away from its frozen `accrual_unit_cost` → the B3 guard throws at supplier-invoice posting (408 residue). This is exactly the incident the guard was built to catch.

**Correct rule: classify each PO line by its own receipt state.**

- A line is **received** iff `accrual_unit_cost !== null` (equivalently `quantity_received > 0` for its first receipt) → its fee share takes the **`wac_adjustment`** path (post-receipt; never re-enters `LandedCostService`).
- A line is **not-yet-received** iff `accrual_unit_cost === null` → its fee share takes the **`landed_cost`** path (pre-receipt; flows through `allocateCostsAndTaxes` at that line's future receipt). Phase 1 blocks this branch (422 `OPERATION_NOT_RECEIVED`, §3.3) since Phase 1 is post-receipt-only; a fully-not-received PO is the pure Phase-2 case.
- A **partially-received PO** therefore produces **both** kinds of share within one expense: the applicator splits the fee first across lines (by split method, §5.2), then routes each line-share by that line's receipt state. Phase 1 (post-receipt only) requires **all fee-bearing lines to be received**; if any targeted line is not-yet-received, Phase 1 returns 422 `OPERATION_PARTIALLY_RECEIVED` rather than silently mixing paths. (Phase 2 lifts this and handles the mixed case.)

`canModifyCosts` is **not** deleted (other callers rely on it); this feature simply does not use it as its boundary — it reads `accrual_unit_cost` per line directly.

**This design never fights the B3 guard.** Rules:

1. `application_path` is decided **per line** at Expense post time from that line's `accrual_unit_cost` (received → `wac_adjustment`, unreceived → `landed_cost`). The path never changes afterward. The `DocumentAdditionalCost` row's stored `application_path` records the dominant path for audit; when a PO's targeted lines are all received (the Phase-1 invariant) it is uniformly `wac_adjustment`.
2. `wac_adjustment` rows **must be invisible to `LandedCostService`**: its cost summation (`allocateCosts` :93-96, `allocateCostsAndTaxes` :161-173, `reallocateCosts`) gains an additive filter `application_path IS NULL OR application_path = 'landed_cost'` (existing rows backfilled/default `landed_cost` — they are all pre-receipt semantics today). This is the ONE modification inside an existing engine, and it is a narrowing filter, not a behavior change for existing data.
3. Costing side effects run at Expense **post** (`ExpenseService::post`, :122-158), never at create — draft expenses must not move WAC. Application is transactional with the GL posting and idempotent via `applied_at` (§6) + JE `source_type/source_id` dedup (note: `journal_entries(source_type,source_id)` is NOT globally unique — use a source-type-scoped partial unique index, matching the existing convention).
4. **After application, the expense is immutable** (amount/link fields locked; same posture as posted documents). Corrections go through the **Phase-1 reversal endpoint** (§4.6), not manual journals. Deleting a posted linked expense is forbidden (422).
5. Race: a line is received **between** expense create (preview says pre-receipt) and post → `post()` re-reads each line's `accrual_unit_cost` inside the transaction and routes on the current state; the preview is advisory.

### 4.6 Reversal (Phase 1 — NOT deferred, finding #4)

WAC + GL + cash side effects of a posted linked cost cannot be unwound by a hand-written accountant journal without divergence (the journal would fix GL but leave `product.cost_price`/WAC and the repository balance wrong). Phase 1 therefore ships a **first-class reversal**:

`POST /api/v1/expenses/{id}/reverse` (`expenses.post` + the same side-permission as the original), transactional, idempotent:

1. **WAC contra** — for each product the original adjusted, call `recordCostAdjustment(product, "-share", reason: "reversal of {expense_number}", referenceType: DocumentAdditionalCost::class, referenceId: reversalRow.id)`. The formula (`WeightedAverageCostService.php:722`, `bcadd(current, delta)`) handles negative deltas; a negative share lowers WAC by the same amount it was raised — **but note this contra spreads over the CURRENT owned quantity, which may differ from application time** (units sold/received since). This is the inherent WAC limitation; the reversal restores GL exactly and WAC approximately, and the residual (if quantity changed) lands in the same COGS-vs-inventory split logic as §5.2 applied to the reversal. Documented as an accepted limitation, surfaced in the reversal response.
2. **GL contra** — `createLinkedCostCapitalizationReversalEntry` (`source_type='linked_cost_capitalization_reversal'`, `source_id = reversal DocumentAdditionalCost id`, own partial-unique index): mirror image of the original (Cr Inventory / Cr expense-category / Dr Cash-Bank).
3. **`DocumentAdditionalCost` reversal** — soft-delete the original row AND insert a reversing row (`amount = -original`, `application_path` copied, `expense_document_id` → the reversal expense) so the audit trail is a pair, and `LandedCostService`/reporting sums net to zero. (Chosen over hard-delete so the history is auditable and idempotency markers survive.)
4. **Cash reversal** — repository inflow of `expense.total` (mirror of the `applyOutflow` decrement) if the original was paid.
5. The reversal itself is a posted, immutable record; reversing a reversal is forbidden (422 `ALREADY_REVERSED`).

---

## 5. Cost impact paths and GL postings

Accounts via `getAccountByPurpose` (`SystemAccountPurpose::Inventory`, `::GoodsReceivedNotInvoiced`, `::Cash`/`::Bank`, category account or `::GeneralExpense`) — all existing wiring; **no new SystemAccountPurpose in Phase 1**.

### 5.1 Generic (`expense_kind=generic`) — unchanged

Dr expense-category / Cr Cash-Bank (`createFromExpense` :2047-2126) + optional `applyOutflow` (:141-149). Zero delta.

### 5.2 Purchase-side, POST-receipt (Phase 1 core)

**Costing.** New Inventory-module service `LinkedCostApplicationService` (exposed as `Shared/Contracts/Inventory/LinkedCostApplicatorInterface`, mirroring the `RepositoryOutflowInterface` precedent):

**Step A — split the fee across PO lines by the chosen split method** (finding: Odoo uses equal/quantity/current-cost/weight/volume; §5.2a). Default `by_value`. Base per line for `by_value` = **received value** = `bcmul(quantity_received, unit_price)`; for `by_quantity` = `quantity_received`; lines with `quantity_received > 0` only. Allocate with the **largest-remainder absorber pattern** (extract `absorberIndex`/`allocateShare` from `LandedCostService` :335-384 into a shared `App\Shared\Domain\ProportionalMoneyAllocator` used by both — behavior-preserving refactor; two copy-pasted allocators would drift). Σ line-shares === fee exactly at currency scale.

**Step B — split EACH line-share into a sold portion and an on-hand portion (finding #2).** Revision-1 pushed the *whole* fee into remaining inventory via `recordCostAdjustment`, over-capitalizing when part of the receipt was already sold. `recordCostAdjustment` spreads over **current company-owned quantity** (`:652`, `:700`); it does not know how much of *this receipt* already left as COGS. Correct treatment (industry standard — the late landed cost of already-sold units is a period cost, not an asset):

- For each received line, compute **`sold_since_receipt`** = Σ quantity of outbound `StockMovement`s (`MovementType::Issue` — the sale/consumption outflow; verified enum has no `Sale` case, `Issue` is the outbound-sale type, `TransferOut` is excluded as it stays within the company) for that `product_id` (+ `variant_id` where set) with `created_at >= ` the line's receipt movement timestamp, **capped at `quantity_received`**. This is a documented **approximation**, not exact attribution: under WAC units are fungible, so we cannot know that the specific received units were the ones sold. The cap + same-product + since-receipt window is the industry-accepted proxy (Odoo/BC do not attempt exact lot attribution for average-cost items either). Where per-line receipt timestamps are ambiguous (multiple partial receipts of one line), use the earliest receipt movement for that line — conservative (attributes more to COGS).
- `sold_ratio = sold_since_receipt / quantity_received` (bcmath, clamped 0..1).
- `cogs_portion = bcmul(line_share, sold_ratio)`; `inventory_portion = line_share − cogs_portion` (exact complement, no double rounding).

**Step C — apply each portion:**
- **Inventory portions**, grouped by `product_id`, → `recordCostAdjustment(product, inventory_portion, reason: "linked cost {expense_number}", referenceType: DocumentAdditionalCost::class, referenceId: $cost->id)` (existing reference params `:680-682`; zero-qty StockMovement `:741-759` is the inventory-side evidence). A `null` return (owned qty ≤ 0, `:702-705` — the remainder was also sold between the count and the lock) → that portion **also rolls into COGS** (the goods are gone).
- **COGS portions** are summed into `cogs_total` and posted directly to the COGS account — they never touch WAC.

`recordCostAdjustment` spreading the inventory portion over **all** company-owned units of the product (not only this PO's) is the correct WAC treatment (units are fungible); "impacts the items in THAT operation" is honored at Step A (which operation's lines split the fee). Step B ensures only the *still-on-hand* fraction is capitalized.

**GL.** New `GeneralLedgerService::createLinkedCostCapitalizationEntry` (`source_type='linked_cost_capitalization'`, `source_id = DocumentAdditionalCost id`, idempotent):

```
Dr Inventory                     inventory_total   (Σ inventory portions actually absorbed by WAC)
Dr COGS account                  cogs_total        (sold portions + null-return remainders; only if > 0)
    Cr Cash / Bank                        expense.total
```

COGS account via `getAccountByPurpose(SystemAccountPurpose::CostOfGoodsSold)` — verify this purpose exists at implementation; if absent it is a small seeder addition (the ONE possible new SystemAccountPurpose — noted as an owner/expert-comptable question §15). Replaces `createFromExpense` for this kind (ExpenseService::post branches on `expense_kind` + path). `applyOutflow` cash-decrement unchanged. GL inventory moves in lockstep with the WAC increase (§5.4); COGS captures the already-sold fraction, so the period's margin is correct.

### 5.2a Split-method enum (finding: Odoo parity)

Revision 1 was value-only. Odoo's `stock_landed_cost` splits by **equal / by_quantity / by_current_cost / by_weight / by_volume** (review-verified source). Phase 1 ships `by_value` (default) and `by_quantity` behind a `LandedCostSplitMethod` enum on the `DocumentAdditionalCost` row (`split_method varchar(20) NOT NULL DEFAULT 'by_value'`, §6). `by_weight`/`by_volume` need product weight/volume attributes (not universally populated — deferred, enum-reserved); `by_current_cost` is a Phase-2 add. Value-default is justified because freight/insurance/customs correlate with declared value far more than count for a parapharmacy catalogue; the enum makes the others additive without a schema change.

### 5.3 Purchase-side, PRE-receipt (Phase 2)

**Costing:** nothing new — the `DocumentAdditionalCost` row (`application_path=landed_cost`) is summed by `allocateCostsAndTaxes` at receipt (:147-244), lands in `landed_unit_cost` (scale 6, :395-420), feeds `recordPurchase` (`GoodsReceiptService.php:154-176`). Existing, tested rail.

**GL:** the fully-pre-receipt case has **nothing sold yet**, so §5.2's sold/on-hand split degenerates to 100% inventory — the entry is simply **Dr Inventory F / Cr Cash** at expense post (no COGS leg). Proof it reconciles end-to-end with the GR-IR machinery (goods HT = G, freight = F):

| Event | Entry | Inventory | 408 |
|---|---|---|---|
| Expense post | Dr Inv F / Cr Cash F | +F | — |
| Goods receipt (:1051-1143, landed basis) | Dr Inv (G+F) / Cr 408 (G+F) | +G+F | −(G+F) |
| Supplier-invoice clearing (:1174-1330): Dr 408 (G+F, same basis per :127-132) + Dr VAT / Cr 401 (G+VAT), **plug = Cr Inventory F** (:1225-1229) | | −F | +(G+F) |
| **Net** | | **+G+F ✓ (= WAC valuation)** | **0 ✓ (no residue)** |

Timing wart: Inventory transiently carries F before the goods arrive. Acceptable for Phase 2; a dedicated `LandedCostAccrual` purpose account (Dr accrual at expense, reclass at receipt) is the refinement if the accountant objects — flagged as an owner/expert-comptable question, not built now.

### 5.4 Existing GL-vs-WAC divergence this fixes (finding)

Today, a pre-receipt `DocumentAdditionalCost` paid via an ordinary Expense posts **Dr expense-category** (:2100-2109) while WAC capitalizes F — and the clearing plug credits Inventory by F (table above, row 3, without row 1's Dr Inv). Net GL inventory = G while perpetual WAC values it at G+F: **the ledger understates inventory by every landed-cost franc paid through Expenses**. Routing purchase-linked expenses to Dr Inventory (5.2/5.3) closes this for the new flow. Existing unlinked rows are left alone (non-goal: retroactive re-posting).

### 5.5 Sales-side (`profit_only`, Phase 2)

**No WAC, no inventory, no 408.** GL is mechanically identical to generic (Dr expense-category — recommend a seeded "Transport sur ventes / Freight-out" ExpenseCategory with its own account — / Cr Cash-Bank, :2047-2126). The `DocumentAdditionalCost` row on the DN (or invoice) exists solely so profitability views can compute `invoice margin = revenue − COGS − Σ linked profit_only costs` across the invoice's chain. Reporting surface itself (a margin column/endpoint) is a small Phase 2 deliverable; the data contract is this row.

---

## 6. Schema deltas (minimal, additive)

```sql
-- 1. expense_metadata
ALTER TABLE expense_metadata
  ADD COLUMN expense_kind varchar(20) NOT NULL DEFAULT 'generic';  -- enum ExpenseKind: generic|linked_cost
  -- no linked-document column here: the link lives on document_additional_costs.expense_document_id

-- 2. document_additional_costs
ALTER TABLE document_additional_costs
  ADD COLUMN application_path varchar(20) NOT NULL DEFAULT 'landed_cost',
      -- enum CostApplicationPath: landed_cost | wac_adjustment | profit_only
      -- default 'landed_cost' doubles as backfill: every existing row has pre-receipt semantics
  ADD COLUMN split_method varchar(20) NOT NULL DEFAULT 'by_value',
      -- enum LandedCostSplitMethod: by_value | by_quantity (| by_current_cost | by_weight | by_volume reserved)
  ADD COLUMN reversed_at timestamptz NULL,        -- set on the original when reversed (§4.6)
  ADD COLUMN reverses_cost_id uuid NULL,          -- reversing row → original row (§4.6)
  ADD COLUMN applied_at timestamptz NULL;   -- idempotency marker for wac_adjustment application
CREATE INDEX ON document_additional_costs (expense_document_id);  -- reverse lookup expense→cost (base migration indexed document_id only)

-- 3. journal entry idempotency (existing convention: source-type-scoped partial unique indexes)
CREATE UNIQUE INDEX uniq_je_linked_cost ON journal_entries (source_type, source_id)
  WHERE source_type = 'linked_cost_capitalization';
CREATE UNIQUE INDEX uniq_je_linked_cost_rev ON journal_entries (source_type, source_id)
  WHERE source_type = 'linked_cost_capitalization_reversal';
```

New PHP enums (rule 9): `ExpenseKind`, `CostApplicationPath`, `LandedCostSplitMethod`, and — opportunistic but in-scope since we touch the model — `AdditionalCostType` backing the currently free-string `cost_type` (values unchanged: transport/shipping/insurance/customs/handling/other; cast + FormRequest `Rule::enum`, no data migration needed). All money columns already `decimal(N,3)`; nothing widens. The `DocumentAdditionalCost` soft-delete for reversal uses `reversed_at` (rows are never physically deleted, §4.6) — the cost-summation filters (§4 rule 2) additionally exclude `reversed_at IS NOT NULL` originals so a reversed pair nets to zero.

DTO/type flow: new/changed DTOs → `php artisan typescript:transform` (rule 7).

---

## 7. API contract

### 7.1 Resolver (new)

```
GET /api/v1/expenses/linkable-operations?invoice_id={uuid}
permissions: expenses.create + documents.view
200 → { data: { side, invoice: {...}, operations: [OperationRef...], auto_selected_id } }
422  INVOICE_NOT_LINKABLE (wrong doc type)
```

### 7.2 Expense create/update (extended `ExpenseRequest`)

```
expense_kind          sometimes|in:generic,linked_cost        (default generic)
linked_invoice_id     required_if:expense_kind,linked_cost | uuid | scoped-exists documents (tenant+company, type in [invoice, credit_note, supplier_invoice, supplier_credit_note])
linked_operation_id   uuid | scoped-exists documents — required when resolver yields >1 (sales side only in Phase 1); must be in the resolved set; ignored+validated-equal when exactly 1
cost_type             required_if:expense_kind,linked_cost | Rule::enum(AdditionalCostType)
split_method          sometimes | Rule::enum(LandedCostSplitMethod) (default by_value; Phase 1 accepts by_value|by_quantity)
total                 (existing) regex:/^\d+(\.\d{1,3})?$/    (money string, scale-3 ceiling — unchanged)
```

Server re-runs the resolver on write (never trusts a client-supplied operation outside the resolved set). Currency guard: `expense.currency === operation.currency` else 422 `LINKED_COST_CURRENCY_MISMATCH` (multi-currency deferred, §14). Errors: `LINKED_OPERATION_REQUIRED` (multi, sales only, none given), `OPERATION_NOT_RECEIVED` (Phase 1 pre-receipt block, no line received), `OPERATION_PARTIALLY_RECEIVED` (Phase 1 block when some targeted lines unreceived, §4.1), `LINKED_COST_ALREADY_APPLIED` (mutation after post).

Create (transaction): Expense Document + ExpenseMetadata (existing :50-73) **+** `DocumentAdditionalCost{document_id: operation, cost_type, amount: total, split_method, expense_document_id: expense.id, application_path: resolved}`.

Post (`POST /expenses/{id}/post`, existing, `expenses.post`): branches per §5; response gains an `application` block `{path, split_method, inventory_total, cogs_total, adjustments: [{product_id, inventory_portion, cogs_portion, stock_movement_id|null}]}` (money as strings).

Reverse (`POST /expenses/{id}/reverse`, new, `expenses.post` + side-permission): §4.6; response `{reversal_expense_id, gl_entry_id, wac_contras: [{product_id, delta, stock_movement_id|null}], cash_reversed}`. Errors: `NOT_POSTED` (nothing to reverse), `ALREADY_REVERSED`.

### 7.3 Explicitly NOT changed

`PaymentController` and the payments API are untouched. The existing `POST /documents/{id}/additional-costs` (manual PO costs, `purchase-orders.update`, routes.php:295-297) remains; rows it creates keep default `application_path='landed_cost'` and behave exactly as today. (Its `landed-cost-breakdown` sibling endpoint computes with float `round(…,2)` (controller :131-139) — pre-existing precision drift, flagged for the precision-remediation backlog, not this project.)

## 8. UI flow sketch (apps/web, `features/expenses`)

Entry: existing Expense form (owner-facing label may say "Paiement / Décaissement"; a "Record a linked cost" shortcut can be cross-linked from the payments screen later). All strings via `t()` (rule 11); money inputs via `<MoneyInput>` emitting strings (rule 19); new components use design tokens (rule 18).

1. **Kind toggle** (radio): "Dépense générale" / "Coût lié à une opération". Generic → form unchanged.
2. **Invoice picker** (linked only): searchable select over sales + supplier invoices (number, partner, date, total; badge for side).
3. **Operation resolution** (auto, on invoice pick, via 7.1):
   - 1 op → read-only chip "Lié à: BC-2026-0042 · reçu le 30/06" + impact hint ("Augmentera le CMP de N produits · part déjà vendue → charges" / "Sera incorporé au coût d'entrée à la réception" / "Réduira la marge de la facture"). Always the purchase case in Phase 1.
   - >1 ops (sales side only) → second select listing operations (number, date, status, received-at, total).
   - 0 ops → inline dialog per §3.3.
4. `cost_type` select (transport/shipping/insurance/customs/handling/other) + `split_method` select (Valeur / Quantité) — linked only.
5. Save (draft) → **Post** shows a confirm summarizing the costing effect (inventory vs COGS split); after post the link block is locked with the applied breakdown, and a **Reverse** action (§4.6) is available on the posted expense.

## 9. Permissions

| Action | Permission (all keys verified in `RolesAndPermissionsSeeder.php`) |
|---|---|
| Create/post any expense | `expenses.create` / `expenses.post` (:155-159) — unchanged |
| Linked cost, purchase side (writes a cost row onto a PO) | additionally `purchase-orders.update` — parity with `DocumentAdditionalCostController` (routes.php:296) |
| Linked cost, sales side (writes onto DN/invoice) | additionally `invoices.update` (:120) |
| Resolver endpoint | `expenses.create` + `documents.view` (:406) |

Route registration follows rule 12 (`['api','auth:sanctum',SetPermissionsTeam::class]`, Expense `routes.php`). No vertical gating: expenses and procurement are core modules in both apps (confirm against `config/verticals.php` at implementation).

## 10. Precision-contract compliance

- All amounts travel as **numeric strings, scale-3 regex ceilings** in FormRequests; columns stay `decimal(N,3)`; `landed_unit_cost` stays scale 6 (:46).
- Allocation math: bcmath at `workingScale()` (currency+4), **one truncation at the boundary** via the extracted `ProportionalMoneyAllocator` (largest-remainder absorber, byte-compatible with `LandedCostService` :335-384). Property test: Σ shares === fee at currency scale for randomized line sets.
- **HARD PRE-REQ (P0, blocking — see §0):** `recordCostAdjustment(… float $additionalCost …)` (`:676`) violates the no-float rule for money and does `CurrencyScale::bcformat($float, …)` at `:721`. **No linked-cost money may flow through it until the parameter is `string`** (numeric-string, phpdoc'd); update the sole other caller family (`StockTransferService` :65, :197, :541) — small, mechanical, removes the float bcformat. This is Phase-1 task 1, not a fast-follow. Alternative (parallel `recordCostAdjustmentExact`) rejected: two entry points to the same lock-ordering-critical code.
- Scale resolution: constructor-injected `CurrencyScaleResolverInterface`; **`getScale($expense->currency)` with the explicit entity currency** — application can run from a posting flow without ambient CompanyContext (rule 19 queue-safety).
- Frontend: `<MoneyInput>`, string payloads, `formatCurrency` display; no `parseFloat` (ESLint `no-parsefloat-on-money`).

## 11. Migration plan

1. **`recordCostAdjustment` float→string signature change + `StockTransferService` callers (P0 hard prerequisite — MUST land first, §0/§10).**
2. Migrations (§6): additive columns (`expense_kind`, `application_path`, `split_method`, `applied_at`, `reversed_at`, `reverses_cost_id`) + indexes; defaults double as backfill (`application_path='landed_cost'`, `split_method='by_value'`); no data rewrite; PG-safe (adds only).
3. Enums (`ExpenseKind`, `CostApplicationPath`, `LandedCostSplitMethod`, `AdditionalCostType`) + DTOs → `typescript:transform`.
4. `ProportionalMoneyAllocator` extraction (behavior-locked by porting the existing `LandedCostServiceTest` expectations).
5. LandedCostService cost-sum filter (`application_path` ∈ {NULL, landed_cost} AND `reversed_at IS NULL`).
6. New code: resolver (per-line receipt classification, §4.1), applicator (split-method + sold/on-hand split, §5.2), GL capitalization + reversal methods, ExpenseService post/reverse branching, FormRequest fields, routes, FE.
7. No deploy coupling: features are inert until the FE sends `expense_kind=linked_cost`; old clients keep sending nothing → `generic` default.

## 12. Test plan (TDD — tests first, per task)

**Unit** — `OperationResolver`: supplier invoice → the single `source_document_id` PO (auto, always 1 in Phase 1, finding #5); sales invoice → child DNs, ancestor DNs, dedup, none. Per-line receipt classification (§4.1): fully-received PO → all `wac_adjustment`; partially-received PO → `OPERATION_PARTIALLY_RECEIVED`; unreceived → `OPERATION_NOT_RECEIVED`. `ProportionalMoneyAllocator`: exact-sum property, absorber-on-last-positive-line, zero-base lines, single line. `LinkedCostApplicationService`: split by value AND by quantity (finding: split-method); **sold/on-hand split (finding #2)** — 0% sold → all inventory, 100% sold → all COGS, 60% sold → 60/40 split, zero-on-hand → all COGS, `null` recordCostAdjustment return → rolls to COGS; grouping by product. `recordCostAdjustment` **string** signature (finding #6): WAC delta exact vs bcmath oracle, no-op null path, negative (contra) delta lowers WAC, StockMovement reference fields.

**Feature (RefreshDatabase + RolesAndPermissionsSeeder + real models, valid UUIDs)** — expense create with each kind writes/withholds the cost row; post (post-receipt, all sold-fractions) → WAC raised by inventory portion only, COGS Dr = sold portion, zero-qty movements, balanced JE `Dr Inv/Dr COGS/Cr Cash`, `applied_at` set, second post = no-op (idempotent); **reversal (finding #4)** → mirror JE, WAC contra, `reversed_at` set on original + reversing row inserted, cash reversed, double-reverse 422; partially-received PO → 422 `OPERATION_PARTIALLY_RECEIVED` (finding #1 regression: never mixes paths silently); pre-receipt row consumed by receipt-time landed cost and **supplier-invoice posting still passes the B3 guard** (regression: `wac_adjustment` rows never shift `landed_unit_cost` / `accrual_unit_cost`); Phase-1 pre-receipt block 422; currency mismatch 422; operation-outside-resolved-set 422; tenant isolation (cross-tenant invoice/operation ids 404); permission matrix (§9); generic-expense regression suite untouched-green.

**GL invariants** — debits==credits on every new entry (capitalization AND reversal); Inventory + COGS legs sum to `expense.total`; 408 nets to zero across receipt+clearing with a pre-receipt linked cost (the §5.3 table as a test); no `linked_cost_capitalization`/`…_reversal` duplicate per cost row (partial indexes + service check); reversal restores GL exactly.

**FE (Vitest)** — kind toggle, picker flow (single/multi/zero branches), string money payloads, i18n keys present. One Playwright pass over the Phase-1 happy path.

Run scoped suites by path (never the full PHPUnit suite); `./scripts/preflight.sh` before completion.

## 13. Phased delivery

- **Phase 1 — purchase-side, post-receipt happy path, expense-paid fee, WITH reversal** (the owner's common case): `recordCostAdjustment` string signature (P0 first), schema+enums, resolver (purchase, single-PO, per-line receipt classification §4.1), allocator extraction, split-method (by_value/by_quantity), applicator with **sold/on-hand COGS split (§5.2)**, `createLinkedCostCapitalizationEntry` (Dr Inv/Dr COGS/Cr Cash), **`createLinkedCostCapitalizationReversalEntry` + reverse endpoint (§4.6)**, ExpenseService post/reverse branch, API + FE toggle/pickers, pre-receipt & partially-received 422 blocks. Exit: pay a transport invoice against a fully-received PO where part is already sold → on-hand WAC rises, sold fraction Dr COGS, JE balanced, 408 untouched; reverse restores GL. **Scope excludes** fee TVA/AP/retenue (that's the supplier-invoice variant, Phase 2).
- **Phase 2 — pre-receipt + sales-side + supplier-invoice-backed variant + partial-receipt mixed paths**: lift the pre-receipt block (`landed_cost` path + §5.3 GL), the **supplier-invoice-backed cost variant** (§2.5 — real SupplierInvoice with TVA récupérable/timbre/retenue linked via `expense_document_id`, HT-only capitalization), sales resolver + `profit_only` rows + margin surfacing, mixed partial-receipt handling (per-line path routing without the Phase-1 422), "no operation → generic" downgrade dialog polish.
- **Phase 3 — receipt-event grain + multi-PO invoices (deferred, needs owner sign-off)**: first-class `goods_receipts` header grouping StockMovements per receipt event, selector granularity "receipt #2 of PO X", allocation weights per receipt; relax `CreateSupplierInvoiceRequest` to allow multi-PO invoices (finding #5) which activates the purchase-side multi-operation selector. Independent of Phases 1–2.

## 14. Non-goals (explicit)

- **No money-movement ledger / repository-balance redesign** — reuse `applyOutflow` as-is; no new balance writers (treasury audit item stays open, untouched).
- **No fix for `account_id` vs `gl_account_id`** split-brain — this flow reads neither.
- **No Expense→Payment unification**; payments API untouched.
- **No reallocation of landed cost after receipt** — B3 guard stays law.
- **No retroactive re-posting** of historical expense-paid landed costs (§5.4 divergence fixed forward-only).
- **Phase 1 does NOT handle fee TVA (recoverable/not), retenue à la source, AP/partner open-items, or installments on the fee** — those require the supplier-invoice-backed variant (Phase 2, §2.5). Phase 1 fees are expense-paid, immediate, VAT-inclusive-as-cost.
- **No multi-PO supplier invoice** (one invoice → one PO today, finding #5; Phase 3), no multi-currency linked costs (422), no multi-operation split of one expense, no ad-hoc StockMovement operations, no fix of the float `landed-cost-breakdown` preview endpoint (logged for precision backlog).
- **Exact lot-level attribution of the sold vs on-hand fraction** is out of scope — WAC fungibility makes it impossible; §5.2 uses the since-receipt sold-quantity approximation (industry standard).

## 15. Open questions for owner / expert-comptable

1. Pre-receipt Dr Inventory (transient inventory before goods arrive) vs a dedicated landed-cost accrual account (§5.3)?
2. Sold-fraction destination account: confirm `SystemAccountPurpose::CostOfGoodsSold` exists / is the right target for the already-sold portion of a late landed cost (§5.2) — this is the one possible new SystemAccountPurpose. Expert-comptable: is Dr COGS at fee-arrival (vs an inventory write-off account) the correct Tunisian treatment?
3. Sales-side: is a seeded "Transport sur ventes" category + account acceptable, and which margin views must show linked costs first?
4. Sold-fraction approximation (§5.2): is the "since-receipt outbound quantity capped at received qty" proxy acceptable, or does the accountant require a stricter FIFO-lot attribution (would need lot tracking on all products)?
5. Supplier-invoice-backed variant (§2.5, Phase 2): confirm HT-only capitalization (VAT récupérable excluded) and that retenue reduces cash-to-supplier not the capitalized base.
6. Phase 3: receipt-event grain (`goods_receipts` header) and multi-PO invoices — worth building, or is PO/single-PO grain sufficient long-term?

---

## 16. Revision 2 — Codex review disposition

Review: `docs/superpowers/audits/2026-07-02-payment-landed-cost-codex-review.md` — verdict **REVISE**, 6 blockers + 2 verified industry facts. Each finding re-verified against code before acceptance (file:line cited in the referenced section).

| # | Finding | Verified against code | Disposition | Change made |
|---|---------|-----------------------|-------------|-------------|
| 1 | `canModifyCosts()` only checks `goods_received_at`, set only on FULL receipt → a partially-received PO is mis-treated as pre-receipt, enabling post-receipt reallocation + 408 divergence | **Confirmed.** `GoodsReceiptService.php:243` sets `goods_received_at = $fullyReceived ? … : null`; per-line `accrual_unit_cost` stamped on any receipt at `:219-221`; `canModifyCosts` reads only `goods_received_at` (`LandedCostService.php:519-525`); B3 guard keyed on per-line `accrual_unit_cost` (`SupplierInvoicePostingService.php:134-151`) | **ACCEPTED** | Boundary redesigned **per-line** (§4.1, D4a): a line is received iff `accrual_unit_cost !== null`; per-line path routing; Phase 1 blocks partially-received PO with 422 `OPERATION_PARTIALLY_RECEIVED`; `canModifyCosts` no longer used as the boundary |
| 2 | Sold-before-fee: `recordCostAdjustment` pushes the whole fee into remaining inventory even when part of the receipt was already sold | **Confirmed.** `recordCostAdjustment` spreads over `companyOwnedQuantity` (`WeightedAverageCostService.php:652,:700`); no sold-since-receipt awareness | **ACCEPTED** | §5.2 Step B: split each line-share by `sold_since_receipt` (Σ outbound StockMovements since the line's receipt, capped at received qty — documented approximation, WAC-fungibility justified) into `cogs_portion` (Dr COGS) and `inventory_portion` (WAC). GL now `Dr Inv / Dr COGS / Cr Cash` |
| 3 | Expense spine insufficient for Tunisian supplier fees (no AP/partner invoice, no installments, no fee TVA recoverable/not, no retenue à la source) | **Confirmed.** `expense_metadata` has only `vendor_name`, no `partner_id`/AP/VAT (`ExpenseMetadata.php:47-57`); withholding lives in a separate `Taxation` module not wired to Expense; SupplierInvoice has full AP (`partner_id`, `balance_due`, Dr408+DrVAT+Dr timbre/Cr401, `SupplierInvoicePostingService.php:207-209`, `GeneralLedgerService.php:1174-1330`) | **ACCEPTED (option b — scope + sketch)** | §2.5 + D9b: Phase 1 explicitly scoped to expense-paid fees (lightweight); **supplier-invoice-backed cost variant is Phase 2**, shape sketched — a real SupplierInvoice linked via the same `expense_document_id` FK (verified `belongsTo Document`, `DocumentAdditionalCost.php:64-68`), HT-only capitalization, VAT/AP/retenue handled by the existing supplier-invoice path |
| 4 | Reversal cannot be deferred — WAC+GL+cash can't be corrected manually without divergence | **Confirmed.** Reversal formula supported (`:722` handles negative delta) but no endpoint; manual journal can't fix `cost_price`/repository balance | **ACCEPTED** | §4.6 + D9a: Phase-1 `POST /expenses/{id}/reverse` — mirror GL (`createLinkedCostCapitalizationReversalEntry`), negated WAC contra, `DocumentAdditionalCost` soft-delete (`reversed_at`) + reversing row, cash inflow reversal; own partial-unique JE index |
| 5 | Multi-PO invoice resolution overstated — `CreateSupplierInvoiceRequest` requires all source lines from ONE PO | **Confirmed.** Single `source_document_id` required (`:55-59`), every `source_line_id` must belong to that PO (`:143-162`) | **ACCEPTED** | §3.2 corrected to one-invoice→one-PO; resolver returns exactly one operation for purchase; purchase-side multi-operation selector is dead in Phase 1; multi-PO moved to Phase 3 (needs relaxing the request) |
| 6 | `recordCostAdjustment` still takes `float $additionalCost` — hard prerequisite | **Confirmed.** `float $additionalCost` at `:676`, `CurrencyScale::bcformat($float,…)` at `:721` | **ACCEPTED (elevated)** | Elevated to §0 "Hard prerequisites" (P0), §10, and §11 step 1 — must land before any money flows; blocking Phase-1 task 1 |
| — | Industry fact: Odoo splits by equal/quantity/current-cost/weight/volume (spec was value-only) | Review-verified (odoo 19.0 `stock_landed_cost`) | **ACCEPTED** | §5.2a: `LandedCostSplitMethod` enum, `by_value` default + `by_quantity` in Phase 1; others reserved; value-default justified for parapharmacy |
| — | Industry fact: Business Central links separate-invoice item charges to posted receipt lines | Review-verified (MS Learn) | **ACCEPTED (as parity note)** | Cited in §2.5 as the model for the Phase-2 supplier-invoice-backed variant |
