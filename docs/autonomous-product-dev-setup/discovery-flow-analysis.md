# AutoERP Business Flow Completeness Analysis

Generated: 2026-06-15

---

## 1. PURCHASE FLOW

**Expected:** Purchase Order → Goods Receipt → Inventory Update → Supplier Invoice → Payment → GL Posting

### Step-by-step trace

| Step | Status | Code Location |
|------|--------|---------------|
| **Create PO (draft)** | IMPLEMENTED | `Document/Presentation/Controllers/PurchaseOrderController.php` via `DraftPersistenceService` |
| **Confirm PO** | IMPLEMENTED | `Document/Domain/Services/PurchaseOrderService::confirm()` — transitions draft→confirmed, calculates taxes via `TaxCalculationService`, allocates landed costs + non-recoverable taxes via `LandedCostService::allocateCostsAndTaxes()` |
| **Goods Receipt** | IMPLEMENTED | `Inventory/Application/Services/GoodsReceiptService::receiveGoods()` — supports partial receipt, validates against ordered quantities |
| **Inventory Update (stock level)** | IMPLEMENTED | `GoodsReceiptService` calls `WeightedAverageCostService::recordPurchase()` — pessimistic-locked stock level update, creates `StockMovement` audit record |
| **WAC Recalculation** | IMPLEMENTED | `WeightedAverageCostService::recordPurchase()` — recalculates WAC with landed unit cost, auto-updates `Product.cost_price` and auto-triggers `MarginService::updateSalePrice()` |
| **Batch Tracking** | IMPLEMENTED | `GoodsReceiptService` calls `BatchStockService::findOrCreateBatch()` + `receiveBatchStock()` |
| **Supplier Invoice** | PARTIAL | GL method exists: `GeneralLedgerService::createSupplierInvoiceJournalEntry()` (Dr. Expense/Asset + Dr. VAT Deductible, Cr. Accounts Payable). However, there is **no dedicated Supplier Invoice document type** — the `DocumentType` enum has no `SupplierInvoice` or `PurchaseInvoice` case. The `Expense` module exists but is a simple expense tracker, not a full supplier invoice flow. |
| **Supplier Payment** | PARTIAL | GL method exists: `GeneralLedgerService::createSupplierPaymentJournalEntry()` (Dr. Accounts Payable, Cr. Bank/Cash). `Treasury/Domain/Services/VendorRefundService.php` exists. But there is **no automated PO→supplier invoice→payment pipeline** — supplier invoice and payment are manual GL operations. |
| **GL Posting (PO confirm)** | MISSING | No event listener on `PurchaseOrderConfirmed` creates GL entries. PO confirmation does not touch the GL. |

### Events connecting steps

- `PurchaseOrderConfirmed` — dispatched on confirm, but **no listener** registered in `EventServiceProvider` or `InventoryServiceProvider`
- `DocumentConverted` — dispatched by `PurchaseOrderToGoodsReceiptConverter` on goods receipt
- `StockMovementRecorded` — dispatched by `WeightedAverageCostService::recordPurchase()` after stock update
- `ProductCostPriceUpdated` — dispatched when WAC changes the cost price

### Gaps

1. **No `SupplierInvoice` document type.** PO receipt updates inventory, but there is no structured supplier invoice document that tracks the supplier's bill against the PO. The GL method `createSupplierInvoiceJournalEntry` exists but must be called manually.
2. **No automated GL posting on PO confirm or goods receipt.** Unlike the sales flow where `InvoicePosted` triggers `InvoicePostedListener` → GL entries, the purchase flow has no such automation.
3. **No PO → supplier invoice conversion.** The `DocumentConverterRegistry` has `PurchaseOrderToGoodsReceiptConverter` but no `PurchaseOrderToSupplierInvoiceConverter`.

---

## 2. SALES FLOW

**Expected:** Quote → Sales Order → Delivery → Invoice → Payment → GL Posting

### Step-by-step trace

| Step | Status | Code Location |
|------|--------|---------------|
| **Create Quote (draft)** | IMPLEMENTED | `Document/Presentation/Controllers/QuoteController.php` via `DraftPersistenceService` |
| **Quote → Sales Order** | IMPLEMENTED | `Document/Domain/Services/Conversion/Converters/QuoteToSalesOrderConverter.php` |
| **Confirm Sales Order** | IMPLEMENTED | `Document/Domain/Services/SalesOrderService::confirm()` — transitions draft→confirmed, reserves stock via `StockReservationService`, calculates + snapshots taxes |
| **Sales Order → Delivery Note** | IMPLEMENTED | `Document/Domain/Services/Conversion/Converters/SalesOrderToDeliveryNoteConverter.php` |
| **Confirm Delivery Note** | IMPLEMENTED | `Document/Domain/Services/DeliveryNoteService::confirm()` — issues stock via `WeightedAverageCostService::recordSale()`, releases reservations from source SO, adds to fiscal hash chain, sets `quantity_delivered` |
| **Sales Order → Invoice** | IMPLEMENTED | `Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php` |
| **Delivery Note → Invoice** | IMPLEMENTED | `Document/Domain/Services/Conversion/Converters/DeliveryNoteToInvoiceConverter.php` |
| **Post Invoice** | IMPLEMENTED | `Document/Domain/Services/DocumentPostingService::post()` — transitions confirmed→posted, validates delivery compliance for physical products, adds to fiscal hash chain with SHA-256 |
| **Invoice → GL Entries** | IMPLEMENTED | `InvoicePosted` event → `InvoicePostedListener` → `AccountingService::createInvoiceGLEntries()` — Dr. AR, Cr. Revenue (per line), Cr. VAT Collected (by rate). Journal entries include hash chain. |
| **COGS Posting** | IMPLEMENTED | `InvoicePosted` event → `PostCOGSOnInvoice` listener → `GeneralLedgerService::createCOGSEntry()` — Dr. COGS (601), Cr. Inventory (37). Uses WAC from `Product.cost_price`. |
| **Record Payment** | IMPLEMENTED | `Treasury/Presentation/Controllers/PaymentController::store()` — creates `Payment`, `PaymentAllocation`, updates `Document.balance_due`, transitions to `Paid` status when fully paid |
| **Payment → GL Posting** | IMPLEMENTED | `PaymentController::store()` calls `GeneralLedgerService::createPaymentReceivedJournalEntry()` — Dr. Bank/Cash (from repository), Cr. AR. Also handles customer advances. |
| **Invoice → Credit Note** | IMPLEMENTED | `Document/Domain/Services/Conversion/Converters/InvoiceToCreditNoteConverter.php` |
| **Credit Note GL** | IMPLEMENTED | `AccountingService::createCreditNoteGLEntries()` — reversal entries (Dr. Revenue, Dr. VAT, Cr. AR) |
| **Cancel Sales Order** | IMPLEMENTED | `SalesOrderService::cancel()` — releases stock reservations, dispatches `SalesOrderCancelled` |
| **Cancel Invoice** | IMPLEMENTED | `DocumentPostingService::cancel()` — sets `fiscal_status = Voided`, dispatches `InvoiceCancelled` |

### Events connecting steps

- `SalesOrderConfirmed` → no downstream listeners (audit trail only)
- `DeliveryNoteConfirmed` → no downstream listeners (audit trail only)
- `InvoicePosted` → `InvoicePostedListener` (GL entries) + `PostCOGSOnInvoice` (COGS) + `WriteDocumentVehicleContextForWorkOrderInvoice` (workshop)
- `DocumentFullyPaid` → `DomainEventSubscriber::handleDocumentFullyPaid()` (compliance audit log)
- `PaymentRecorded` → `DomainEventSubscriber::handlePaymentRecorded()` (compliance audit log)
- `DocumentConverted` → dispatched on each conversion, no listener (audit trail only)

### Gaps

1. **Stock deduction timing ambiguity.** Stock is deducted on delivery note confirmation (correct), but invoices can also be created directly from sales orders via `SalesOrderToInvoiceConverter` — in that path, the `DocumentPostingService::post()` validates delivery compliance (physical products must have delivery notes), which closes the gap. However, if `is_physical` is not correctly set on products, physical stock could bypass delivery check.
2. **No automated email/notification on invoice posting or payment receipt.** Events exist but no notification listeners are registered.

### Verdict: **FULLY IMPLEMENTED** (most complete flow in the system)

---

## 3. POS FLOW

**Expected:** Cart → Payment → Receipt → Inventory Deduction → Cash Register → Z-Report

### Step-by-step trace

| Step | Status | Code Location |
|------|--------|---------------|
| **Open Shift** | IMPLEMENTED | `POS/Domain/Services/ShiftManagementService::openShift()` — creates `Shift`, records opening `CashDrawerOperation`, enforces one-open-shift-per-terminal |
| **Create Receipt (cart → receipt)** | IMPLEMENTED | `POS/Application/Services/ReceiptCreationService::createReceipt()` — validates shift, calculates VAT (including fixed-bundle decomposition), generates receipt number (`{location}-{terminal}-{year}-{seq}`), creates Receipt + ReceiptLines + ReceiptVatDetails, fiscal hash chain |
| **Stock Deduction** | IMPLEMENTED | `ReceiptCreationService::decrementStock()` — pessimistic-locked `StockLevel` update, creates `StockMovement` audit record with `MovementReason::POSSale`. Also handles composite items via `deductCompositeItemStock()` (recursive recipe explosion). |
| **Batch Allocation (FEFO)** | IMPLEMENTED | `ReceiptCreationService::allocateBatches()` — uses `FEFOInventoryService::suggestBatchesForSale()`, creates `ReceiptLineBatchAllocation` records, deducts batch-level stock |
| **Receipt Payment** | IMPLEMENTED | `POS/Application/Services/ReceiptPaymentService::processReceiptPayments()` — supports split payments, creates Treasury `Payment` records, links `ReceiptPayment` to Treasury, handles overpayment (change due) and underpayment (tolerance writeoff with GL entry) |
| **POS Payment → GL Posting** | IMPLEMENTED | `ReceiptPaymentService` calls `GeneralLedgerService::createPOSPaymentEntry()` — Dr. Cash/Bank (from repository), Cr. Revenue + Cr. VAT. Direct-to-revenue (no AR intermediary). GL entries immediately posted. |
| **Cash Drawer Operations** | IMPLEMENTED | `POS/Domain/Services/CashDrawerService` — tracks deposits, payouts, opening, closing. `CashDrawerController` for manual operations. |
| **Discounts** | IMPLEMENTED | `DiscountCalculationService`, `DiscountOrchestratorService`, `DiscountStackingService` — line discounts, transaction discounts, percentage and fixed, cashier limits, manager override, promotion engine integration |
| **Hold/Resume Orders** | IMPLEMENTED | `POS/Application/Services/HeldOrderService`, `HeldOrderController`, `ExpireHeldOrdersCommand` |
| **Void/Return** | IMPLEMENTED | `ReceiptVoidService`, `ReceiptReturnService`, `VoidReturnModal` (POS frontend) |
| **X Report** | IMPLEMENTED | `POS/Application/Services/ReportGenerationService::generateXReport()` — mid-shift snapshot, non-destructive |
| **Z Report** | IMPLEMENTED | `ReportGenerationService::generateZReport()` — closes shift, records grand totals, fiscal hash chain on Z report, supports cash counting with variance calculation |
| **Receipt Sync (offline POS)** | IMPLEMENTED | `POS/Application/Services/ReceiptSyncService`, `SyncController` — offline Tauri POS syncs receipts to server |
| **Receipt PDF** | IMPLEMENTED | `POS/Application/Services/ReceiptPdfService` |
| **Fiscal Compliance (NF525)** | IMPLEMENTED | Receipt hash chain (`ReceiptHashService`), Z report hash chain (`ZReportHashService`), grand total events (`GrandtotalService`), receipt immutability trigger, training mode isolation |
| **Cash Count Validation** | IMPLEMENTED | `CashCountValidationService` — per-tender denomination counting, variance severity classification |
| **Kitchen Display (F&B)** | IMPLEMENTED | `KitchenDisplayController`, order line status tracking, broadcasting events |
| **Table Management (F&B)** | IMPLEMENTED | `TableManagementService`, floors, tables, status tracking |
| **Fraud Detection** | IMPLEMENTED | `FraudSettingsResolver`, discount limits, manager PIN verification |

### Events connecting steps

- `ReceiptCreated` → dispatched after receipt creation (no listener, audit trail)
- `ReceiptCompleted` → `EarnPointsOnReceiptCompleted` (loyalty module)
- `ShiftOpened` / `ShiftClosed` → no downstream listeners
- `ZReportGenerated` → no downstream listeners
- `ReceiptVoided` → no downstream listeners
- `CashCountRecorded` → no downstream listeners

### Gaps

1. **No COGS posting for POS sales.** The `PostCOGSOnInvoice` listener only fires on `InvoicePosted`. POS receipts bypass the invoice flow entirely, so **COGS is never posted for POS sales**. The GL entry from `createPOSPaymentEntry` records revenue but not COGS. This is a significant accounting gap for businesses that need accurate gross margin reporting.
2. **No automatic invoice generation from POS receipts.** Some jurisdictions require formal invoices for B2B POS sales. There is no receipt→invoice converter.

### Verdict: **IMPLEMENTED** (very comprehensive POS, but COGS gap is material)

---

## 4. INVENTORY FLOW

**Expected:** Stock Receipt → Movements → Valuation (WAC) → Adjustments → Counting → Write-off

### Step-by-step trace

| Step | Status | Code Location |
|------|--------|---------------|
| **Stock Receipt** | IMPLEMENTED | `Inventory/Domain/Services/StockAdjustmentService::receive()` — creates `StockMovement`, updates `StockLevel`. Also via `WeightedAverageCostService::recordPurchase()` with WAC. |
| **Stock Issue** | IMPLEMENTED | `StockAdjustmentService::issue()` — validates available qty (incl. reservations), creates movement, updates level. Also via `WeightedAverageCostService::recordSale()`. |
| **Stock Transfer** | IMPLEMENTED | `StockAdjustmentService::transfer()` — deducts from source, adds to destination, creates paired `TransferOut`/`TransferIn` movements. Pessimistic locking on both locations. |
| **Stock Reservation** | IMPLEMENTED | `Inventory/Application/Services/StockReservationService` — creates `StockReservation` records with expiry, linked to `SalesOrder` source. `ExpireReservationsJob` for automated cleanup. Also `StockAdjustmentService::reserve()` / `releaseReservation()`. |
| **WAC Valuation** | IMPLEMENTED | `Inventory/Application/Services/WeightedAverageCostService` — `recordPurchase()` (recalculates WAC with new cost), `recordSale()` (issues at current WAC, no WAC change), `recordReturn()` (recalculates WAC with return cost). Full audit trail via `StockMovement` with `avg_cost_before` / `avg_cost_after`. |
| **Landed Cost Allocation** | IMPLEMENTED | `Inventory/Application/Services/LandedCostService` — allocates additional costs (freight, insurance) proportionally across PO lines, feeds into WAC via `landed_unit_cost`. |
| **Stock Adjustment** | IMPLEMENTED | `StockAdjustmentService::adjust()` — sets stock to specific quantity, creates `Adjustment` movement with difference. |
| **Inventory Counting** | IMPLEMENTED | `Inventory/Application/Services/InventoryCountingService` — full lifecycle: create→activate→count→reconcile→finalize. Supports draft/active/counting/finalized statuses, counter assignment, manual override, scope types (full/partial/category). |
| **Counting → Auto-Adjustment** | IMPLEMENTED | `InventoryCountingCompleted` event → `ApplyStockAdjustmentsOnCountingCompleted` listener — auto-applies adjustments from finalized counting using `CountingReconciliationService`. |
| **Fraud-Triggered Counting** | IMPLEMENTED | `Inventory/Application/Services/FraudTriggeredCountingService` |
| **Opening Balances** | IMPLEMENTED | `Inventory/Application/Services/InventoryOpeningService` |
| **Batch/Expiry Tracking** | IMPLEMENTED | `Modules/BatchExpiry/` — `BatchStockService`, `FEFOInventoryService` (First Expired First Out), `DailyExpiryCheck` job, batch movements, batch stock levels per location. |

### Movement Types (from `MovementType` enum)

- `Receipt`, `Issue`, `Adjustment`, `TransferIn`, `TransferOut` — all IMPLEMENTED in `StockAdjustmentService`

### Events

- `StockMovementRecorded` — dispatched on every movement (receipt, issue, transfer, adjustment, POS sale, purchase receipt)
- `ReservationCreated` / `ReservationReleased` / `ReservationExpired` — reservation lifecycle
- `InventoryCountingCompleted` → `ApplyStockAdjustmentsOnCountingCompleted` (auto-adjust)

### Gaps

1. **No explicit write-off movement type.** Write-offs are handled via `Adjustment` type with a negative difference, but there is no dedicated `WriteOff` movement type or GL integration for write-off losses. An inventory write-off should create a GL entry (Dr. Loss/Expense, Cr. Inventory) — this is **not automated**.
2. **No GL integration for any inventory movement.** Stock adjustments, transfers, and write-offs do not create GL entries. Only the sales invoice flow (via `PostCOGSOnInvoice`) and POS flow touch inventory-related GL accounts.

### Verdict: **IMPLEMENTED** (comprehensive inventory, but GL integration gap for non-sale movements)

---

## 5. ACCOUNTING FLOW

**Expected:** Journal Entries → GL → Trial Balance → P&L → Balance Sheet

### Step-by-step trace

| Step | Status | Code Location |
|------|--------|---------------|
| **Chart of Accounts** | IMPLEMENTED | `Accounting/Application/Services/ChartOfAccountsService` — country-specific templates (Tunisia PCG), hierarchical accounts with parent-child, system account purposes (`SystemAccountPurpose` enum with 16 purposes). |
| **Journal Entry Creation** | IMPLEMENTED | `Accounting/Domain/JournalEntry` + `JournalLine` models. `JournalEntryBuilder` for fluent construction. Statuses: `Draft`, `Posted`. |
| **Double-Entry Validation** | IMPLEMENTED | `Accounting/Domain/Services/DoubleEntryValidator` — validates total debits = total credits. |
| **Manual Journal Entries** | IMPLEMENTED | `Accounting/Presentation/Controllers/JournalEntryController` — CRUD for manual entries. |
| **Journal Entry Hash Chain** | IMPLEMENTED | `Accounting/Application/Services/GeneralLedgerHashService` — SHA-256 chain on journal entries for tamper-proof GL. `JournalEntryObserver` + `JournalLineObserver` for integrity. |
| **Fiscal Period Resolution** | IMPLEMENTED | `Accounting/Application/Services/FiscalPeriodResolverService` |
| **General Ledger Report** | IMPLEMENTED | `Accounting/Application/Services/Reports/GeneralLedgerReportService` — account-level transaction detail, date-filtered, hierarchical. |
| **Trial Balance** | IMPLEMENTED | `Accounting/Application/Services/Reports/TrialBalanceService` — aggregates debits/credits by account, validates fundamental equation, supports hierarchy + zero-balance filtering. |
| **Profit & Loss** | IMPLEMENTED | `Accounting/Application/Services/Reports/ProfitLossService` — revenue vs. expense classification, period-based, hierarchical with subtotals, net income calculation. |
| **Balance Sheet** | IMPLEMENTED | `Accounting/Application/Services/Reports/BalanceSheetService` — assets = liabilities + equity validation, point-in-time, hierarchical. |
| **Aged Receivables** | IMPLEMENTED | `Accounting/Application/Services/Reports/AgedReceivablesService` + `Document/Application/Services/AgedReceivablesService` — aging buckets (0-30, 31-60, 61-90, 90+). |
| **Aged Payables** | IMPLEMENTED | `Accounting/Application/Services/Reports/AgedPayablesService` — supplier aging buckets. |
| **Opening Balances** | IMPLEMENTED | `Accounting/Application/Services/OpeningBalanceBatchService`, `AccountingOpeningService` — batch import of opening balances, AR/AP opening via `ArApOpeningService`. |
| **Partner Balance** | IMPLEMENTED | `Accounting/Application/Services/PartnerBalanceService` — cached per-partner AR/AP balances from GL, auto-refreshed on GL writes. |
| **Account Hierarchy** | IMPLEMENTED | `Accounting/Domain/Services/AccountHierarchyService` — tree structure with subtotal rollup. |

### Auto-generated GL entries (event-driven)

| Trigger | GL Entry | Status |
|---------|----------|--------|
| Invoice posted | Dr. AR, Cr. Revenue, Cr. VAT | IMPLEMENTED (`InvoicePostedListener`) |
| Credit note posted | Dr. Revenue, Dr. VAT, Cr. AR | IMPLEMENTED (`InvoicePostedListener`) |
| Invoice COGS | Dr. COGS, Cr. Inventory | IMPLEMENTED (`PostCOGSOnInvoice`) |
| Customer payment | Dr. Bank/Cash, Cr. AR | IMPLEMENTED (`PaymentController::store()`) |
| Customer advance | Dr. Bank/Cash, Cr. Customer Advance | IMPLEMENTED (`GeneralLedgerService::createCustomerAdvanceJournalEntry()`) |
| POS payment | Dr. Cash/Bank, Cr. Revenue, Cr. VAT | IMPLEMENTED (`GeneralLedgerService::createPOSPaymentEntry()`) |
| POS tolerance writeoff | Dr. Cash Shortage, Cr. Revenue | IMPLEMENTED (`GeneralLedgerService::createPOSPaymentToleranceEntry()`) |
| Supplier invoice | Dr. Expense, Dr. VAT Deductible, Cr. AP | IMPLEMENTED (method exists, **manual trigger only**) |
| Supplier payment | Dr. AP, Cr. Bank/Cash | IMPLEMENTED (method exists, **manual trigger only**) |
| Inventory adjustment | No GL entry | MISSING |
| Inventory write-off | No GL entry | MISSING |

### TODOs found

- `ReportsController.php:143,298,449` — `// TODO: Fix hierarchy balance calculation` (3 occurrences, all on balance sheet and P&L hierarchy mode)

### Gaps

1. **Hierarchy balance calculation is broken** (flagged by 3 TODO comments). The `include_hierarchy` flag is available but the balance rollup has a known bug.
2. **No automated GL entries for inventory adjustments, write-offs, or inter-location transfers.** These movements update stock levels but not the GL.
3. **Supplier invoice and payment GL entries require manual creation** — no event-driven automation like the sales side.

### Verdict: **IMPLEMENTED** (solid double-entry GL with reports, but supplier-side and inventory GL automation gaps)

---

## 6. TREASURY FLOW

**Expected:** Bank Accounts → Payments → Instruments → Reconciliation → Cash Flow

### Step-by-step trace

| Step | Status | Code Location |
|------|--------|---------------|
| **Payment Repositories (Bank/Cash)** | IMPLEMENTED | `Treasury/Domain/PaymentRepository` — models bank accounts and cash registers. Has `balance`, `last_reconciled_balance`, `gl_account_id` / `account_id` linkage, `RepositoryType` enum (Bank, Cash, POS). Controller: `PaymentRepositoryController`. |
| **Payment Methods** | IMPLEMENTED | `Treasury/Domain/PaymentMethod` — configurable payment methods. `PaymentMethodRepository` for data access. `CountryPaymentSettings` for country-specific defaults. |
| **Record Payment** | IMPLEMENTED | `Treasury/Presentation/Controllers/PaymentController::store()` — creates `Payment` with allocation to documents, updates `PaymentRepository.balance`, creates GL entry, dispatches `PaymentRecorded` event. Supports multi-document allocation. |
| **Payment Allocation** | IMPLEMENTED | `Treasury/Application/Services/PaymentAllocationService` — allocates payments to documents using `AllocationMethod` (manual, auto-oldest-first). Creates `PaymentAllocation` records. Auto-transitions documents to `Paid` status. |
| **Multi-Payment** | IMPLEMENTED | `Treasury/Domain/Services/MultiPaymentService`, `MultiPaymentController` — batch payment processing for multiple documents. |
| **Payment Instruments (Checks, etc.)** | IMPLEMENTED | `Treasury/Domain/PaymentInstrument` — models checks, drafts, promissory notes. Status lifecycle via `InstrumentStatus` enum. Events: `InstrumentDeposited`, `InstrumentCleared`, `InstrumentBounced`, `InstrumentTransferred`. Controller: `PaymentInstrumentController`. |
| **Payment Tolerance** | IMPLEMENTED | `Treasury/Application/Services/PaymentToleranceService` — handles small payment differences (underpayment/overpayment within threshold). `CloseInvoiceWithToleranceService` — closes invoices with minor shortfalls. Country-specific tolerance boundaries via `DiscountToleranceBoundary`. |
| **Payment Refund** | IMPLEMENTED | `Treasury/Domain/Services/PaymentRefundService` — refunds customer payments. `VendorRefundService` — processes vendor refunds. Controller: `PaymentRefundController`. |
| **Bank Reconciliation** | IMPLEMENTED | `Treasury/Application/Services/BankReconciliationService` — `startReconciliation()` loads unreconciled payments, creates `BankReconciliation` + `BankReconciliationItem` records. Status lifecycle: `Draft` → `InProgress` → `Completed`. Calculates `difference` (statement vs. book). Dispatches `ReconciliationCompleted` event. Controller: `BankReconciliationController`. |
| **Smart Payment** | IMPLEMENTED | `Treasury/Presentation/Controllers/SmartPaymentController` — AI-assisted payment matching. |
| **Repository Balance Tracking** | IMPLEMENTED | `RepositoryBalanceChanged` event dispatched on every balance mutation. |

### Events connecting steps

- `PaymentRecorded` → `DomainEventSubscriber::handlePaymentRecorded()` (compliance audit)
- `PaymentAllocated` → no listener (audit trail)
- `PaymentRefunded` / `PaymentReversed` → no listener (audit trail)
- `InstrumentDeposited` / `InstrumentCleared` / `InstrumentBounced` / `InstrumentTransferred` → no listener
- `ReconciliationCompleted` → no listener
- `RepositoryBalanceChanged` → no listener
- `DocumentFullyPaid` → `DomainEventSubscriber::handleDocumentFullyPaid()` (compliance audit)
- `InvoiceClosedWithTolerance` → no listener

### Gaps

1. **No cash flow report.** There is no `CashFlowService` or cash flow statement generation. Treasury has repositories and payments but no cash flow projection or historical cash flow report.
2. **Instrument lifecycle has no GL integration.** Check deposit, clearance, and bounce events do not create GL entries. For example, depositing a check should create a GL entry (Dr. Bank-in-clearing, Cr. Checks-received), and clearance should move it (Dr. Bank, Cr. Bank-in-clearing). This is entirely manual.
3. **Bank reconciliation does not auto-match.** `startReconciliation()` loads unreconciled payments, but there is no bank statement import or auto-matching algorithm. Reconciliation items must be manually toggled.

### Verdict: **IMPLEMENTED** (solid payment and reconciliation foundation, but missing cash flow reporting and instrument GL automation)

---

## Summary Matrix

| Flow | Completeness | Critical Gap |
|------|-------------|--------------|
| **Purchase** | 70% | No supplier invoice document type; no automated GL on PO/receipt |
| **Sales** | 95% | Minor: no automated notifications |
| **POS** | 90% | COGS not posted for POS sales |
| **Inventory** | 85% | No GL entries for adjustments/write-offs/transfers |
| **Accounting** | 90% | Hierarchy balance calculation TODO; supplier-side GL is manual |
| **Treasury** | 80% | No cash flow report; no instrument GL automation |

## Top 5 Actionable Gaps (by business impact)

1. **POS COGS gap** — POS sales deduct inventory but never post COGS to the GL. Gross margin reports will be wrong for any business using POS. Fix: add a listener on `ReceiptCreated` or `ReceiptCompleted` that mirrors `PostCOGSOnInvoice` logic.

2. **No supplier invoice document type** — The purchase flow ends at goods receipt. There is no way to record the supplier's bill as a document, track AP aging properly, or automate the PO→supplier invoice→payment pipeline. Fix: add `SupplierInvoice` to `DocumentType`, create a converter `PurchaseOrderToSupplierInvoiceConverter`, and wire `InvoicePostedListener`-equivalent for AP.

3. **Inventory movements missing GL integration** — Stock adjustments, write-offs, and inter-location transfers have no GL impact. This means the inventory account in the GL drifts from actual inventory value over time. Fix: add GL entry creation in `StockAdjustmentService::adjust()` and `transfer()`.

4. **No cash flow report** — Treasury has all the data (payments, instruments, repositories) but no cash flow statement. This is a standard financial report that auditors and management expect.

5. **Hierarchy balance calculation broken** — Three TODO comments flag a known bug in hierarchical P&L and balance sheet reports. This affects multi-level chart of accounts display.
