# **ERP (IziPOS) — State-machine & data-structure review**

---

**Date:** 2026-08-23 · Prepared by Adam for Houssam · Multi-agent review (Map → Review → Verify) of otospexsolutions/erp. **Result:** 34 findings raised → **21 confirmed** after adversarial verification (8 high · 9 medium · 4 low). State machines mapped: 13\.

## **The one-line takeaway**

---

Your **Workshop** module already implements state machines the right way — a StatusMachine adjacency map \+ a single TransitionService write path \+ a typed InvalidTransition exception \+ a transition/audit table. **POS Orders and Documents do not** — they use scattered raw-string status writes with no guard type, no DB CHECK, and no audit. **The highest-leverage fix is to propagate the Workshop pattern to POS Orders and Documents.** Plus one real bug to fix now (\#1).

## **1\. State machines**

### ---

**\[HIGH\] Closed/cancelled POS order can be silently reopened to Ready via line-status update**

**Where:** apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:542  
**Problem:** updateLineStatus() loads the order, loads the line, and calls validateLineStatusTransition() on the LINE only — it never checks the parent order's status. After the line update it calls checkAndTransitionOrderToReady() (lines 713-735), which sets the order to Ready whenever all non-cancelled lines are Ready and status \!== Ready. Because closeOrder() (line 433\) allows closing from SentToKitchen with lines still in 'sent' state, a closed order can retain 'sent' lines. Calling updateLineStatus on such a line (sent-\>ready is allowed by the private guard) then triggers checkAndTransitionOrderToReady, which flips Closed-\>Ready (Closed \!== Ready is true), sets ready\_at, and dispatches OrderReady. Cancelled orders are equally unprotected.  
**Why it matters:** A terminal, receipt-backed/fiscally-closed order being reopened to Ready corrupts the kitchen/service workflow and emits a spurious OrderReady event; terminal states must be immutable.  
**Fix:** Guard updateLineStatus/bumpOrder/markOrderServed with an order-level active check (reuse OrderStatus::isActive()) and make checkAndTransitionOrderToReady refuse to transition when the order is in a terminal state (Closed/Cancelled). Better: introduce an OrderStatus adjacency map (like Workshop's StatusMachine) that has no outgoing edges from Closed/Cancelled and route every \-\>update(\['status'=\>...\]) through it.

### **\[HIGH\] POS Order transitions are scattered and ungoverned — no state machine, no guard type, no DB constraint**

**Where:** apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:160  
**Problem:** OrderStatus (open/sent\_to\_kitchen/ready/closed/cancelled) has only label()/isActive() — no adjacency map. Legal transitions are enforced inline at \~7 write sites via ad-hoc predicates on the Order model (canBeSentToKitchen L380, canBeClosed L433, canBeCancelled L492, canBeServed L605, and a raw in\_array() at L652) each followed by a bare $order-\>update(\['status'=\>OrderStatus::X\]) (L160,387,440,497,669,727). There is no single write path, no InvalidTransition exception type (all throw generic \\RuntimeException), and the DB column is string(20) default 'open' with NO CHECK constraint (create\_pos\_orders\_table.php L25). This is the exact anti-pattern the Workshop module already solved with StatusMachine \+ WorkOrderTransitionService \+ WorkOrderTransitionException.  
**Why it matters:** With guards duplicated across sites and no DB backstop, any new caller (queue retry, backfill, a future controller) that forgets a guard can write an illegal status; the enum values are effectively magic strings with no enforcement layer.  
**Fix:** Adopt the Workshop pattern for POS: a pure OrderStatusMachine encoding allowedTargetsOf()/isAllowed() (forbidding self-loops and edges out of terminal states), a single OrderTransitionService as the only write path, and a typed InvalidOrderTransitionException (422). Add a DB CHECK constraint on pos\_orders.status like the one pos\_shifts already has.

### **\[HIGH\] Document lifecycle has no state machine — 37 files mutate DocumentStatus, DB trigger only guards the fiscal seal**

**Where:** apps/api/app/Modules/Document/Domain/Enums/DocumentStatus.php:7  
**Problem:** DocumentStatus (draft/confirmed/posted/paid/received/cancelled) exposes only isEditable/isDeletable/label — no adjacency map and no isTerminal. Status is written from \~37 files (controllers, converters, DocumentPostingService, CreditNoteService, PaymentAllocationService, procurement services, etc.) with no single write path and no transition guard (grep for StatusMachine/InvalidTransition/assertAllowed in Modules/Document returns nothing). The only backstop is the Postgres trigger enforce\_document\_immutability(), which by design fires ONLY when fiscal\_status='SEALED' and explicitly '-- Allow operational status updates (e.g., posted \-\> paid)'. So for any non-sealed document, illegal transitions (posted-\>draft, cancelled-\>posted, paid-\>confirmed) are unprevented at both app and DB layers.  
**Why it matters:** Invoices/orders are the accounting core; an out-of-order status write (e.g., re-posting a cancelled invoice, un-posting a posted one) can desynchronize GL postings, payment allocation and fiscal state with no guard to stop it.  
**Fix:** Introduce a DocumentStatusMachine (adjacency map \+ isTerminal for cancelled/paid where appropriate) and a single DocumentTransitionService that all 37 call sites route through, raising a typed 422 exception. Keep the DB trigger as the seal backstop but add an app-level graph so operational transitions are also validated.

### **\[MEDIUM\] POS OrderLine transition guard is a private service method, inconsistently applied and bypassed by bulk updates**

**Where:** apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:692  
**Problem:** The only OrderLine transition guard is the private validateLineStatusTransition() (L692-708), a local match/allow-list throwing generic \\RuntimeException — not reusable and not on the OrderLineStatus enum (which has only label()). It is only consulted by the single-line updateLineStatus path. The bulk transitions bypass it entirely: sendToKitchen mass-updates Pending-\>Sent (L392-397), bumpOrder mass-updates Sent/Preparing-\>Ready (L659-664), markOrderServed mass-updates Ready-\>Served (L616-620). So the 'machine' is only partially in force, and the served/pending edges (pending-\>sent, ready-\>served) aren't even in the allow-list — they only ever happen through the unguarded bulk paths.  
**Why it matters:** Split enforcement means the documented transition rules are only true for one of four code paths; the graph the domain actually permits is implicit and drifts from the stated one.  
**Fix:** Move the line adjacency map onto OrderLineStatus (or a shared OrderLineStatusMachine) covering the full graph including pending-\>sent and ready-\>served, and have every mutation (single and bulk) validate through it, throwing a typed exception. Column pos\_order\_lines.status is also an unconstrained string(20) — add a CHECK constraint.

### **\[MEDIUM\] No transition/audit log for POS order, line, or shift status changes**

**Where:** apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:387  
**Problem:** POS status changes emit runtime events (OrderSentToKitchen, OrderReady, OrderClosed, OrderLineStatusChanged) but persist NO transition record. There is no pos\_order\_status\_transitions table (grep confirms only workshop\_work\_order\_status\_transitions exists). Workshop records to work\_order\_status\_transitions and Scheduling to appointment\_status\_transitions; POS, Document, Treasury and Inventory have no equivalent.  
**Why it matters:** Without a persisted transition trail, disputes over when an order was cancelled/closed or a line voided cannot be reconstructed; events are ephemeral and lost if a listener fails.  
**Fix:** For a fiscal/NF525 POS, persist an append-only status\_transitions row (from, to, actor, timestamp, reason) inside the same DB transaction as each status write, mirroring the Workshop table. This is also the natural home for the cancellation reason currently appended to free-text notes (L502-505).

### **\[MEDIUM\] pos\_orders and pos\_order\_lines status columns are unconstrained strings while pos\_shifts is properly constrained**

**Where:** apps/api/database/migrations/tenant/2026\_03\_11\_400000\_create\_pos\_orders\_table.php:25  
**Problem:** pos\_orders.status is string(20) default 'open' and pos\_order\_lines.status is string(20) default 'pending', both with NO CHECK constraint. By contrast pos\_shifts (create\_pos\_shifts\_table.php) has a CHECK constraint (status IN ('OPEN','CLOSED')), a state-invariant CHECK tying status to closed\_at/closed\_by, AND a partial unique index enforcing one open shift per terminal. The order tables get none of that backstop, so any raw DB write (analytics jobs use DB::table('pos\_orders'), backfills, manual fixes) can persist an out-of-enum status value with no rejection.  
**Why it matters:** The shift table proves the team knows how to enforce status at the DB; the order tables omitting it means enum drift and impossible states can be written directly, and the app-layer enum cast will then throw on read.  
**Fix:** Add CHECK constraints on pos\_orders.status and pos\_order\_lines.status mirroring the enum cases (as pos\_shifts already does). Consider Postgres enum types or the same partial-index technique to encode invariants (e.g., closed\_at NOT NULL when status='closed').

### **\[LOW\] POS order\_number generation is a check-then-set race with no unique constraint**

**Where:** apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:104  
**Problem:** createOrder() computes the next order number via Order::where('terminal\_id',...)-\>whereDate('opened\_at',today)-\>max(CAST(order\_number)) \+ 1 (L104-109) with no row lock on the sequence source and no unique constraint on (terminal\_id, order\_number) in the migration. Two concurrent createOrder calls on the same terminal/day read the same max and produce duplicate order numbers.  
**Why it matters:** Duplicate daily order numbers on a terminal break receipt traceability and any downstream lookup that assumes order\_number is unique per terminal per day.  
**Fix:** Enforce uniqueness at the DB level (unique index on terminal\_id \+ day-bucket \+ order\_number) and/or allocate the sequence via an atomic counter row locked with lockForUpdate, so concurrent order creation cannot collide.

## **2\. Data structures / schema**

### ---

**\[HIGH\] Treasury foreign keys stored as plain uuid columns with no FK constraint — orphaned payment/instrument/account references possible**

**Where:** apps/api/database/migrations/tenant/2025\_11\_30\_120000\_create\_treasury\_tables.php:144  
**Problem:** payments.instrument\_id (144), payments.repository\_id (145), payments.journal\_entry\_id (160), payment\_methods.default\_journal\_id/default\_account\_id/fee\_account\_id (75-77), and payment\_repositories.location\_id/responsible\_user\_id/account\_id (34-38) are declared as bare $table-\>uuid(...)-\>nullable() with no \-\>constrained(). A code comment explicitly says 'no FK constraint for flexibility'. These link payments to GL journal entries and accounts — the core money-movement integrity edges.  
**Why it matters:** A payment can point at a deleted/nonexistent journal entry, account, or instrument with nothing stopping it. In a fiscal/accounting system that means untraceable cash movements, payments that never post to a real GL account, and reconciliation that cannot be trusted — exactly the integrity the immutability triggers are trying to guarantee elsewhere.  
**Fix:** Add foreignUuid(...)-\>constrained(...) with an explicit restrictOnDelete (or nullOnDelete for optional links) for each of these references. Where cross-database boundaries prevent an FK, add an application-level integrity check plus a covering index and a periodic orphan-detection job.

### **\[MEDIUM\] Enum-typed columns stored as unconstrained strings with no DB CHECK — column values can drift from the PHP enum**

**Where:** apps/api/database/migrations/tenant/2026\_02\_19\_100001\_create\_composite\_items\_table.php:20  
**Problem:** Many status/type columns backed by PHP enums are plain strings with no DB CHECK: composite\_items.vertical\_type string(50) default 'generic' and production\_type string(50) default 'made\_to\_order' (lines 20-22), documents.type/status string(20) (create migration), products.type string(20). Only some tables (fiscal\_category, and \~a subset of the 145 CHECK usages) enforce the allowed set at the DB. Enforcement is therefore inconsistent — present on some enum columns, absent on others.  
**Why it matters:** Without a DB CHECK, a bad write (import, raw SQL, a renamed enum case) stores a value the PHP enum can't hydrate, causing ValueError at read time or silently mis-bucketed rows. Inconsistent enforcement means the guarantee depends on which table you happen to be writing.  
**Fix:** Either promote these to backed PHP enums cast in the model AND add matching DB CHECK constraints, or standardize on DB CHECKs generated from the enum cases. Add a test that every column with a corresponding Domain enum has a CHECK covering exactly that enum's values.

### **\[MEDIUM\] UUID vs bigint primary-key inconsistency — categories and product\_batches are auto-increment bigint amid an otherwise all-UUID schema**

**Where:** apps/api/database/migrations/tenant/2026\_02\_19\_100001\_create\_composite\_items\_table.php:19  
**Problem:** Nearly every table uses uuid primary keys and foreignUuid relations. categories (2025\_12\_26\_194624) and product\_batches use auto-increment bigint ids, so composite\_items.category\_id is foreignId (bigint) (line 19), and batch\_id references (document\_lines, stock\_reservations, inventory\_batch\_stock/movements, stock\_transfer\_line\_batch\_allocations) are foreignId (bigint). This creates two id regimes in one schema.  
**Why it matters:** Mixed key types complicate generic tooling (polymorphic relations, sync, export, sharding), make it easy to declare the wrong FK type, and expose sequential bigint ids for catalog data in a multi-tenant system where UUIDs were presumably chosen to avoid enumeration and cross-tenant id collisions.  
**Fix:** Decide on one PK strategy. If UUID is the standard (it is, for 470+ tables), migrate categories and product\_batches to uuid PKs and their FKs to foreignUuid; otherwise document the bigint exceptions and why. At minimum keep it out of new tables.

### **\[MEDIUM\] Products are soft-deleted but stock\_levels/stock\_movements FK to product uses cascadeOnDelete**

**Where:** apps/api/database/migrations/tenant/2025\_11\_30\_110000\_create\_inventory\_tables.php:19  
**Problem:** stock\_levels.product\_id (line 19\) and stock\_movements.product\_id (line 35\) are constrained with cascadeOnDelete. products uses softDeletes, so normal deletes are soft — but any force-delete or raw DELETE of a product will cascade-delete all stock movement history for that product.  
**Why it matters:** stock\_movements is the audit ledger of inventory. A single force-delete silently erases the movement history and any cost/quantity trail behind current stock, which is both an audit-integrity loss and a source of unexplained stock discrepancies.  
**Fix:** Change these to restrictOnDelete (or nullOnDelete for stock\_movements history) so stock history survives product removal, consistent with the fiscal/audit immutability posture used elsewhere. If products should never be hard-deleted, enforce that explicitly.

### **\[LOW\] documents.source\_document\_id has no self-referencing FK or delete policy**

**Where:** apps/api/database/migrations/tenant/2025\_11\_30\_080000\_create\_documents\_table.php:33  
**Problem:** source\_document\_id is a bare uuid nullable column (line 33\) linking a document to its origin (e.g. invoice → credit note, order → invoice), but unlike partner\_id/vehicle\_id it has no \-\>constrained() and no ON DELETE behavior, and no index.  
**Why it matters:** Document lineage can point at a missing row, breaking credit-note→invoice traceability that tax audits require, and the missing index makes 'find everything derived from this document' a full scan.  
**Fix:** Add foreignUuid('source\_document\_id')-\>nullable()-\>constrained('documents')-\>restrictOnDelete() (restrict fits the fiscal-immutability posture) plus a supporting index for lineage lookups.

### **\[LOW\] Leftover .bak migration files committed in the central migrations directory**

**Where:** apps/api/database/migrations/2026\_01\_05\_150000\_create\_product\_batches\_table.php.bak:1  
**Problem:** Five batch-tracking migrations exist as .bak files in the central migrations dir (product\_batches, inventory\_batch\_stock, inventory\_batch\_movements, add\_batch\_id\_to\_document\_lines, add\_batch\_id\_to\_stock\_reservations) after being re-created under tenant/. They are committed to the repo.  
**Why it matters:** Stray .bak files in a migrations directory are migration-hygiene debt: they confuse readers about the source of truth, risk being picked up by ad-hoc tooling/globs, and signal an incomplete central→tenant move that should be closed out.  
**Fix:** Delete the .bak files from version control. If kept for reference, move them out of any migrations path entirely so no tool ever scans them.

## **3\. Domain-model / anti-patterns**

### ---

**\[HIGH\] Anemic domain models: all business logic lives in god-services**

**Where:** apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:35  
**Problem:** The 'Domain' models (POS/Domain/Order.php, OrderLine.php, Receipt.php) are pure Eloquent data bags: they hold fillable/casts/relations plus a handful of read-only predicate methods (isOpen(), canBeClosed(), canBeSentToKitchen(), hasDiscount()). Every actual behavior — creating an order, adding/modifying/removing lines, recalculating totals, computing line VAT, validating and applying status transitions, converting to a receipt — lives in OrderManagementService (823 lines) and ReceiptCreationService (1422 lines; createReceipt spans lines 117-1259, a single \~1100-line method that also injects FEFOInventoryService, DiscountCalculationService, DiscountOrchestratorService, ReceiptHashService). The model can express whether a transition is allowed (canBeSentToKitchen) but cannot perform it; the service reads the predicate then does the mutation itself (OrderManagementService.php:380-397).  
**Why it matters:** This is textbook anemic-domain-model / transaction-script. Business rules are not discoverable on the type that owns the data, so every new caller (held orders, sync replay, imports, queue retries) must re-implement the same guard-then-mutate dance, and the guards drift. The god-services become change bottlenecks and are effectively untestable without a database.  
**Fix:** Move state-transition behavior onto the aggregate: Order::sendToKitchen(), close(Receipt), cancel(reason), addLine(...) that mutate the model and enforce their own preconditions (throwing domain exceptions), returning recorded domain events. Reduce the services to thin orchestrators that load the aggregate, call one intention-revealing method, and persist. Split ReceiptCreationService's mega-method into collaborators (pricing, VAT, fiscal-chain, inventory) coordinated by the aggregate.

### **\[HIGH\] Money value object exists but is quarantined in Billing; rest of ERP uses raw numeric-strings**

**Where:** apps/api/app/Modules/Billing/Domain/ValueObjects/Money.php:18  
**Problem:** A well-built immutable Money VO exists (bcmath-only, currency-checked, no float) — but grep shows it is referenced by exactly 10 files, all inside the Billing module. Every other money-bearing field in the system is a raw numeric-string/decimal:N: Order.subtotal/tax\_amount/discount\_amount/total and currency (Order.php:42-46), OrderLine.unit\_price/tax\_amount/line\_total (OrderLine.php:29-33), Invoice money fields (Invoice.php:26-35). Currency is a free-form string ('TND' default hard-coded in OrderManagementService.php:130). Amount and currency travel as two separate primitives that must be re-paired at every call site.  
**Why it matters:** Primitive obsession: nothing stops adding two different-currency totals, storing a negative total, or passing a bare string where a currency-scoped amount is required. The one abstraction that would prevent this is proven and available, but 95% of the codebase bypasses it, so the safety is illusory outside Billing.  
**Fix:** Promote Money to App\\Shared\\Domain\\ValueObjects\\Money and adopt it across POS/Inventory/Catalog as the type for prices and totals (Eloquent custom cast so persistence stays transparent). At minimum, make services accept/return Money instead of (string $amount, string $currency) pairs so currency mismatch and negativity become unrepresentable.

### **\[HIGH\] Unclear aggregate boundary: StockLevel quantity/reserved mutated by 10+ services across modules**

**Where:** apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:161  
**Problem:** StockLevel is an entity that clearly should be the consistency boundary for on-hand and reserved quantities, yet its fields are written directly from all over the codebase: WeightedAverageCostService (StockLevel.php quantity assigned at :282,:433,:579), StockReservationService (:161-163 reserve, :283/:404 release), OpeningBalancePostingService, InventoryService, ResetOpeningBalanceService, StockAdjustmentService, plus Procurement (SupplierCreditNotePostingService.php:665) and Marketplace services. Each site does its own bcadd/bcsub or increment/decrement and re-checks 'available \= quantity \- reserved' inline (e.g. StockReservationService.php:107-127).  
**Why it matters:** There is no single owner of the stock invariants, so 'quantity \>= 0' and 'reserved \<= quantity' are enforced (or not) independently at every call site. getAvailableQuantity() (StockLevel.php:102-105) can return a negative number and nothing prevents persisting it. A new writer that forgets the guard silently corrupts stock. Entities mutated outside their owning service is exactly the boundary violation DDD aggregates are meant to stop.  
**Fix:** Make StockLevel the aggregate root for its quantities: expose reserve(Quantity), release(Quantity), receive(Quantity), consume(Quantity) methods that enforce non-negativity and reserved\<=quantity internally and are the only way to change those fields. Route Procurement/Marketplace/costing through those methods (or through a single InventoryService facade) instead of assigning \-\>quantity.

### **\[HIGH\] Leaky layering: the 'Domain' layer is Eloquent Active Record; DDD base classes are dead code**

**Where:** apps/api/app/Modules/Inventory/Domain/StockLevel.php:180  
**Problem:** The project ships App\\Shared\\Domain\\AggregateRoot, Entity, and ValueObject base classes, but grep finds zero classes extending them outside their own definitions. Instead \~195 files under */Domain extend Illuminate\\Database\\Eloquent\\Model and \~236 Domain files import Eloquent or the DB facade. Domain objects run persistence and queries directly: StockLevel::getReservationBreakdown() issues a query (StockLevel.php:180-188) and recalculateReserved() runs a SUM query and calls save() (:194-204); Order/Invoice call $this-\>update(...) inside domain methods; Invoice imports Stancl\\Tenancy CentralConnection (Invoice.php:17). Query scopes, table names, and JSON casts sit in the same classes labelled Domain.*  
*Why it matters: The Domain/Application/Infrastructure layering the repo advertises is nominal: Infrastructure (ORM, DB, tenancy) concerns are pervasive in Domain, so the domain cannot be reasoned about or tested without a database and the dependency arrows point the wrong way. The purpose-built pure-domain base classes being 100% unused shows the intended model was abandoned.*  
*Fix:*\* Either (a) commit to Active Record and drop the misleading Domain/AggregateRoot/Entity/ValueObject scaffolding and the 'clean layering' claim, or (b) reinstate pure domain objects behind repository interfaces and keep Eloquent models in Infrastructure. Whichever is chosen, stop issuing queries from within entity methods (move getReservationBreakdown/recalculateReserved to a repository/service).

### **\[MEDIUM\] composite\_items.tax\_rate stored as string(10) instead of a decimal**

**Where:** apps/api/database/migrations/tenant/2026\_02\_19\_100001\_create\_composite\_items\_table.php:23  
**Problem:** tax\_rate is declared $table-\>string('tax\_rate', 10)-\>nullable(). Every other tax\_rate in the schema is decimal (10× decimal(5,2), 2× decimal(6,3)). A string column accepts '19', '19%', 'nineteen', or '' with no numeric or range validation, and cannot participate in SQL arithmetic.  
**Why it matters:** Tax computed off a composite/menu item reads a free-text field; a malformed or percent-suffixed value produces wrong tax or a runtime cast error, and tax cannot be aggregated in SQL. On fiscal documents this is a compliance-grade defect.  
**Fix:** Change composite\_items.tax\_rate to decimal(6,3) (or the canonical rate scale) to match the rest of the schema, backfilling/validating existing string values during the migration.

### **\[MEDIUM\] Float reintroduced on the money/quantity path, contradicting the bcmath contract**

**Where:** apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:283  
**Problem:** Reservation *creation* updates the reserved field with bcadd at QUANTITY\_SCALE (StockReservationService.php:157,162), but reservation *release* casts the numeric-string quantity to a PHP float: $stockLevel-\>decrement('reserved', (float) $reservation-\>quantity) (:283, and again for batches at :275 and at :404). The same file elsewhere is careful to stay in bcmath. Separately, RoundsVat quantizes VAT via round((float)$raw, $scale) on the money path.  
**Why it matters:** Casting a decimal quantity to IEEE-754 float for the decrement can round-trip to a value that doesn't exactly reverse the bcadd used on reserve, so the reserved field drifts over many reserve/release cycles and eventually diverges from the sum of active reservations (the very drift recalculateReserved() has to exist to repair). It defeats the whole reason the codebase uses numeric-strings.  
**Fix:** Release with bcsub at QUANTITY\_SCALE and update() the field (mirroring the reserve path) instead of decrement((float)...). Audit for other (float) casts on money/quantity columns. If a Quantity VO is introduced, have it own add/subtract so this cannot recur.

### **\[MEDIUM\] Order/Receipt/Invoice each own a separate totals-recalculation engine**

**Where:** apps/api/app/Modules/POS/Application/Services/OrderManagementService.php:740  
**Problem:** The 'sum lines into subtotal/tax/discount/total' rule is implemented three times with subtly different assumptions: OrderManagementService::recalculateOrderTotals (:740-767, subtotal \= line\_total \- tax\_amount, tax-inclusive), Invoice::recalculateTotals (Invoice.php:230-266, total \= subtotal \+ tax \- discount), and again inside ReceiptCreationService during order-\>receipt conversion. Each re-derives the aggregate totals from lines independently.  
**Why it matters:** Duplicated aggregation logic with different sign/inclusive conventions means an order's displayed total and the receipt/invoice total it becomes are produced by different code, inviting off-by-rounding mismatches and making a change to discount or tax semantics a three-place edit that will be done in two.  
**Fix:** Introduce one totals calculator (a domain service or a method on an OrderTotals/InvoiceTotals value object) that takes lines and returns subtotal/tax/discount/total, and have Order, Receipt conversion, and Invoice all use it. Consider computing totals in the aggregate whenever a line changes rather than via an external recalculate call.

### **\[LOW\] Menu availability rules live in a resolution service, not on the Menu entity**

**Where:** apps/api/app/Modules/Menu/Application/Services/MenuResolutionService.php:49  
**Problem:** Menu carries active\_from/active\_until, start\_date/end\_date and available\_days (Menu.php:26-30) — the data that defines when a menu is valid — but has no behavior. The decision 'is this menu active at datetime X' is implemented in MenuResolutionService::matchesRules (:49-75), which reaches into the menu's raw fields and does string date/time comparisons (with a noted MVP gap: no overnight time ranges).  
**Why it matters:** The rule that most naturally belongs to a Menu (its own availability window) is separated from the data it operates on, so any other consumer that needs 'is this menu available now' must duplicate the comparison logic, and the overnight-range edge case has to be fixed in every copy.  
**Fix:** Add Menu::isAvailableAt(CarbonInterface $at): bool encapsulating the date/time/day-of-week window (ideally backed by a small AvailabilityWindow value object), and have MenuResolutionService just filter candidates via that method. This also gives the overnight-range fix a single home.