# Multi-branch parapharmacy remediation design

**Status:** proposed, awaiting adversarial review and owner decisions. **Implementation is not authorized.**
**Date / source:** 2026-09-05 / `b9a5565aa`. **Target:** Tunisia; proposed one legal company with multiple locations. Legal-company structure and the first client's checkout surfaces are not yet confirmed.
**Requested reviewer:** Claude Fable 5.1 (`claude-fable-5-1`), one adversarial design review, then stop. A favorable review does not authorize implementation or launch.

**Summary.** Preserve offline fiscal POS and web B2B as different sales workflows. Repair shared access and tender controls, make B2B accounting atomic and POS financial projections durable, add reconciliation of device closes, and make lot evidence honest. Use lot-grain counting for tracked stock. Do not rebuild the ERP or make live accounting optional. This is a program-level spec with ordered work packages; a task-level implementation plan follows only after review and explicit authorization.

## 1. Industry baseline (benchmark-first — convention 10)

Reference systems: Odoo 19.0 documentation; ERPNext current unversioned documentation (version not asserted); Dolibarr current unversioned official wiki/search extracts. Accessed 2026-09-05. These are workflow references, not evidence of NF525/NACEF compliance or a claim that all three products implement our exact offline guarantees. **NV** means the specific guarantee was not verified in that system, rather than presumed present.

| ID | User guarantee | Odoo | ERPNext | Dolibarr | AutoERP today (repository-relative path:line) | Decision |
|---|---|---|---|---|---|---|
| B1 | A stock movement for a tracked product records its lot | Lot-grain inventory [S1] | Optional batch dimension in stock ledger [S2] | Tracked movements require lot/serial [S3] | `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:2052` chooses FEFO after sale | MATCH captured-lot capability in W6; DIVERGE only under explicit shelf-control profile D3 |
| B2 | A physical count can distinguish two lots of one SKU | Separate lot lines [S1] | Batch reconciliation supported [S4] | Lot-specific count behavior NV | `apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php:79` lacks lot dimension | MATCH W5 |
| B3 | Payment-terminal takings use a bank/settlement destination | Bank journal required for terminal [S5] | Exact terminal restriction NV | Exact terminal restriction NV | `apps/api/app/Modules/Treasury/Application/Services/TenderRepositoryResolver.php:157` allows mapped drawer | MATCH W2 |
| B4 | Stock mutation requires an action permission | Exact recall permission NV | Exact recall permission NV | Inventory requires stock-movement permission [S6] | `apps/api/app/Modules/BatchExpiry/Presentation/routes.php:29` lacks recall permission | MATCH W1 |
| B5 | Accounting remains linked to its business source | Exact callback durability NV | Source voucher on ledger [S7] | Exact callback durability NV | `apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:723` uses after-commit callback | MATCH source completeness, W3/W4; implementation mechanism is our design |
| B6 | Cancelling posted work preserves original evidence | Exact mode NV | Reversals preserve history [S8] | Exact mode NV | `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:143` records reversal linkage | ALREADY preserve reversal design; extend same discipline to repairs |
| B7 | Changing lot tracking does not silently reinterpret stock history | Explicit adjustment procedure [S9] | Exact edit-after-use rule NV | Exact edit-after-use rule NV | `apps/api/app/Modules/BatchExpiry/Presentation/Requests/UpdateBatchRequest.php:22` permits identity/expiry edits | MATCH controlled correction, W5 |
| B8 | Staff branch scope survives alternate endpoints; replays do not double effects | Exact API scope/replay NV | Exact API scope/replay NV | Exact API scope/replay NV | `apps/api/app/Modules/Treasury/Presentation/Controllers/CashPositionController.php:75` scopes locations; repository list `PaymentRepositoryController.php:39` does not | MATCH project guarantees, W1/W2/W4; no unsupported competitor-equivalence claim |

Sources: [S1 Odoo inventory adjustments](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/warehouses_storage/inventory_management/count_products.html); [S2 ERPNext stock specification](https://github.com/frappe/erpnext/blob/develop/erpnext/stock/spec/README.md); [S3 Dolibarr lot/serial module](https://wiki.dolibarr.org/index.php/Module_Lot_/_Serial); [S4 ERPNext stock reconciliation](https://docs.frappe.io/erpnext/stock-reconciliation); [S5 Odoo POS payment methods](https://www.odoo.com/documentation/19.0/applications/sales/point_of_sale/payment_methods.html); [S6 Dolibarr inventories](https://wiki.dolibarr.org/index.php/Inventories); [S7 ERPNext general ledger](https://docs.frappe.io/erpnext/general-ledger); [S8 ERPNext immutable ledger](https://docs.frappe.io/erpnext/immutable-ledger-in-erpnext); [S9 Odoo reassignment of lot/serial numbers](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/product_management/product_tracking/reassign.html).

Second-of-everything: each affected catalogue lane must exercise second-company creation through the real provisioning path, a second POS-enabled location, and a repeated mutation with unchanged quantities/balances. Two lots and two terminals are additional mandatory fixtures here. Cross-tenant negative cases are separate from second-company cases.

## 2. Scope, alternatives and decisions

### 2.1 Confirmed owner intent

- POS is intentionally offline-first, oriented toward fiscal-register workflows. Checkout must not acquire a new synchronous server dependency.
- Web sales documents support B2B. Offline B2B is a possible future product, excluded from this release.
- Propose fixes and request Fable 5.1 adversarial review; do not implement.
- Accounting account numbers should be configurable through existing purpose mappings; inventory and treasury remain operational concerns.

### 2.2 Options evaluated

1. **Recommended: targeted corrections to existing boundaries.** Shared access/routing fixes, synchronous transactional B2B posting, durable POS projections, independent reconciliation and explicit lot evidence. Lowest migration risk while retaining existing fiscal history and operational services.
2. **Containment-only pilot.** Company-wide trusted staff, verified cash-only tender, controlled shelves and manual reconciliation. Can reduce exposure but cannot repair absent accounting effects or authorize falsely advertised lot provenance. Only a dated owner-approved pilot restriction, not a complete fix.
3. **Rewrite into a unified event-sourced accounting/inventory platform.** Reject for this release: would replace working mechanisms, broaden fiscal migration risk and delay practical controls. New general posting DSL, microservices, replacement chart engine and a unified POS/B2B lifecycle are excluded.

### 2.3 Decisions required before an execution plan is approved

These are explicit product decisions, not unspecified implementation details. The recommendations below are proposals, not owner rulings.

| ID | Decision | Proposed choice | Effect if different / execution gate |
|---|---|---|---|
| D1 | One legal company or separate legal entities? | Branches are locations of one company | Separate entities require an intercompany spec; do not treat cross-company stock as an internal transfer |
| D2 | First-client sales surfaces and tenders | Validate both potential surfaces in this spec; enable only those selected for launch | Record POS/B2B usage and enabled tender list before release acceptance; unused paths are excluded explicitly, not marked tested |
| D3 | Required retail lot evidence | Captured-lot checkout for tracked products (W6) | Shelf-control pilot is possible only with written expiry/recall procedure, no software lot-enforcement claim, estimated-provenance labels and a dated revisit milestone |
| D4 | Offline freshness policy for captured-lot mode | Refresh before opening a selling session; authorize cached eligibility for that session, warn on staleness, require refresh before the next session | Owner chooses operational tolerance; new remote recalls remain unknowable offline. No silent expiry default or unapproved hard cutoff |
| D5 | Staff treasury reach | Managers operate only their allowed branch custody; central treasury has an explicit bypass | No inference of company-wide custody from a generic manager role |
| D6 | Cross-branch returns | Exclude until explicitly enabled and tested for receiving-branch stock, source-lot provenance and payout custody | This spec does not silently introduce a new return policy |

NF525/NACEF names describe intended product use. This spec makes no certification claim and does not invent Tunisia tax, retention or receipt-format requirements. Those require review of the actual deployment/configuration under the existing owner gate.

## 3. Architecture and invariants

### 3.1 Ownership

| Owner | Authority | Must not do |
|---|---|---|
| Fiscal POS | Local immutable receipt/session authoring and durable synchronization | Depend on server GL availability to complete offline checkout; rewrite sealed bytes |
| Web B2B | Document transitions, deliveries, invoices, credit notes and terms | Duplicate a POS sale as another revenue-producing invoice automatically |
| Inventory | Movement, custody, lot allocation, count discrepancy and valuation inputs | Claim a computed FEFO allocation was physically scanned; derive missing lot identity from aggregate counts |
| Treasury | Payments, allocations, custody, instruments and settlement | Route electronic money to physical cash merely to avoid an error |
| Accounting | Journal interpretation of operational facts, account-purpose resolution and reversals | Make old posted entries change when configuration changes |

Existing `PartnerBalanceService` uses posted journal lines for balances, and `TreasuryMovementService` coordinates with GL inside a transaction. Preserve that behavior in this program. “Derived accounting” does not mean an optional or disposable ledger in the current system.

### 3.2 Global requirements

- Existing sealed fiscal bytes, classes, version schemas and posted journal amounts are immutable. New protocol capabilities use explicit new versions or separate evidence records; old readers/verifiers remain supported.
- Every operational effect has a stable source identity. Re-delivery yields the prior semantic result; conflicting content under the same identity is a visible conflict, never silently overwritten.
- Acknowledging a POS fiscal event means its event and mandatory projection obligations are durable, not that all projections have completed. UI distinguishes received, pending, blocked and applied.
- Tenant DB, company, terminal and location provenance must be explicit in workers. Never use HTTP-only scope resolution inside projection workers.
- Strings/BCMath for monetary values and quantities. Currency resolution takes the entity currency; TND uses its configured scale, quantities use the existing quantity precision contract. Preserve TTC POS versus HT B2B semantics.
- Use existing movement ports, fiscal projections, permissions and operator surfaces. Extend these rather than create a second source of inventory, money or outstanding balances.
- Restrict only new commands when policy changes; preserve already sealed offline facts. Revoked staff/configuration on synchronization is an exception requiring attribution and review, not permission to delete history.
- Accounting failure, incomplete synchronization and unknown lot allocation must never render as a clean zero balance or a reconciled close.

## 4. Work packages and fixing measures

Paths are relative to the repository. Named new components below are proposed additions, not existing code. File maps identify seams for the later task-level plan; they are not authorization to edit.

### W1 — Shared action authorization and treasury custody

**Findings:** audit F2/F3; B4/B8. **Always required for branch-restricted operation.**

Enforce existing batch permissions for create/update/delete/recall/traceability at the route/request boundary. Recall is company-wide by design and requires `batches.recall`; possessing one branch membership does not itself confer recall authority. Delete means deactivation, not evidence deletion. Keep module and company checks in addition to action permissions. Inventory write-off and transfer must use their existing stock permissions and location rules.

For repository list/detail/balance/transactions/movements, apply `LocationScopeResolver` and `LocationScopeBoundary`. A restricted user sees repositories in allowed locations. Locationless central repositories are not exposed merely because location is null. Add an explicit `treasury.manage_all_locations` bypass, granted by default only to owner/admin and managed through the existing role surface. Generic `repositories.view`, `treasury.transfer` and manager role do not bypass scope.

Transfer authorization uses persisted repository identities, not caller-supplied location labels. Require permission over source custody. A branch manager may transfer to an allowed branch repository or to an explicitly configured company central collection destination; reverse outflow from that central destination still requires central authority. Model destination eligibility as a repository setting on the existing repository surface, not a UI-only filter. For another branch destination, require that location in the user's allowed set or the explicit bypass. Company-global bank identifiers/balances must not leak through destination pickers; expose only permitted destination names/IDs.

No side effects on denial. API detail denial uses the established scoped-not-found convention; rejected explicit out-of-scope list selections use the existing forbidden envelope. Reauthorize inside mutation orchestration against current persisted source custody, so metadata changes cannot bypass a stale precheck.

**Seams:** `BatchExpiry/Presentation/routes.php`, batch requests/controllers; `Treasury/Presentation/Controllers/PaymentRepositoryController.php`, `RepositoryMovementController.php`, `RepositoryTransferController.php`; `Treasury/Application/Services/RepositoryTransferService.php`; `Company/Services/LocationScopeResolver.php`, `LocationScopeBoundary.php`; permission seeder; repository forms. All module paths are beneath `apps/api/app/Modules/`.

**Acceptance:** cashier recall/delete denied; authorized company recall succeeds across branches; A-only manager cannot list/read B drawer by any endpoint or transfer from it; allowed transfer A→central succeeds while central→A is denied; cross-company/cross-tenant repository IDs fail; deny paths leave movement/GL snapshots unchanged; permission cache refresh changes access correctly.

### W2 — Tender routing that preserves economic meaning

**Finding:** F4; B3/B8. **Required before each electronic tender is enabled.**

Extend the existing payment-method configuration with an explicit settlement classification, rather than inferring electronics from display names/codes or treating every noncash method as a card. Proposed enum: `cash`, `electronic`, `maturity_instrument`, `voucher`, `customer_account`. Existing instrument and voucher/charge paths remain authoritative; classification validates routing, not a replacement payment engine. Conflicting flags/classification refuse activation with a guided configuration error.

Cash resolves only to permitted physical cash custody for that terminal location. Electronic settlement resolves to an explicitly mapped active bank/virtual clearing repository with a compatible GL account and currency; no physical-drawer fallback. A clearing balance means funds awaiting settlement, not necessarily cleared bank cash. An unmapped electronic method is unavailable for new checkout, while cash can remain available. Do not auto-create fictional bank balances or guess a destination.

Publish a versioned terminal tender-policy snapshot through the existing terminal policy sync. Record method, destination and policy version with the authored tender evidence; use a new compatible event/evidence version if the current sealed schema cannot express it. Server replay uses that recorded binding, not today's mutable default mapping. Preserve instrument custody, mixed tenders, change netting and cash rounding.

For historical events already applied, retain their original effect. For unprojected legacy events without a reliable destination snapshot, use a tenant-scoped cutover mapping reviewed by the operator; ambiguous cases persist a blocked projection with a configuration reason. Never reroute them to an arbitrary till. If an old binding points to a frozen/deactivated repository, preserve the sale and record a reviewable routing exception using existing movement policies; do not mutate the sealed tender. Reclassifying a historic erroneous payment is an explicit compensating operation, not part of the schema backfill.

**Seams:** `Treasury/Domain/PaymentMethod.php`; `TenderRepositoryResolver.php`, `PaymentMethodController.php`, `TreasuryReceiptBridge.php`; terminal policy DTO/source and `apps/pos` policy cache/checkout; existing repository setting UI; tenant additive migration and company census. Default seeding cannot enable an electronic method with no valid settlement binding.

**Acceptance:** 100.000 TND card at A and B changes clearing/bank by 100.000 and cash by 0.000; cash lands in each branch drawer; card→drawer configuration refused even if a bank exists; absent electronic destination blocks only new use of that tender; cheque/voucher/account paths retain their distinct effects; duplicate sync and remapping after authoring do not double or move the original payment; legacy ambiguity is visibly blocked.

### W3 — Atomic web B2B invoice accounting

**Finding:** F5 first case; B5/B6. **Required if B2B invoices/credit notes are used.**

Choose synchronous posting inside the document transaction for this online workflow. The legal document seal, its document-source GL, advance clearing and paid-state transition must either commit together or all roll back. Do not merely add more preflight checks before the current after-commit callback.

Expose a narrow shared accounting contract for idempotent document posting, returning the existing journal identity on retry. `DocumentPostingService` calls it inside its existing transaction; the accounting implementation validates source identity, period and account purposes and posts balanced legs using one company-chain locking convention shared with other GL writers. Acquire locks consistently: company GL/numbering serialization before source-document and downstream monetary locks; the execution plan must check existing writers before adopting this order. A unique source constraint prevents duplicate journal effects; a conflicting existing entry is refused rather than reused blindly.

Retire `InvoicePostedListener` as a second money writer for the new path. Preserve the existing `InvoicePosted` event class and other registered consumers. Persist any mandatory audit-event delivery obligation transactionally before deferring notification work; nonfinancial after-commit consumers may remain asynchronous. The later plan must account for all current event consumers, rather than remove the event or dispatch duplicate source events.

Prior sealed invoices missing GL need a read-only census. Recovery is explicit per source, idempotent, and blocked on ambiguity or closed/filed period rules. Do not silently book old invoices into today's period, modify their seal, or bulk replay already-booked events. Manual recovery requires the source amount/account snapshot and an accountant-compatible period decision where necessary.

**Seams:** `Document/Domain/Services/DocumentPostingService.php`; `Accounting/Application/Services/AccountingService.php`; `Accounting/Listeners/InvoicePostedListener.php`; `app/Providers/EventServiceProvider.php`; shared accounting contracts; journal source uniqueness/chain locks. No POS checkout code belongs in this package.

**Acceptance:** inject failure during account resolution, journal insert, advance clearing and final document transition: no partial seal/journal survives; retry after a lost HTTP response yields one seal and one journal; concurrent invoices in one company cannot collide/fork the journal chain; second-company chains remain independent; fully prepaid and partial-advance invoices, credit notes and closed periods retain correct semantics.

### W4 — Durable POS projection obligations and recovery

**Finding:** F5 second case; B5/B8. **Required for POS use.**

Keep local sealing and server projection asynchronous. For accepted operational receipts in this deployment, POS-core and Treasury are mandatory effect obligations. Seed their existing `fiscal_event_projections` rows in the same transaction as the event. A transient module/configuration lookup failure cannot remove a mandatory row. Distinguish a durable activation decision from an inability to resolve it; configuration failure leaves work pending/blocked with a reason. Optional projectors may be omitted only by an explicit recorded activation policy, not an exception handler.

Dispatch to Redis after commit as today, but add a tenant-aware scheduled dispatcher for persisted pending work and abandoned running rows. Queue publication failure or worker death must recover without an operator guessing which event to replay. Reuse existing `ApplyFiscalEventProjectionJob`, overlap/advisory locks, dependency checks, retry ceilings and dead-letter handling; do not reset active jobs indiscriminately. Redis ordering is not a dependency guarantee.

Existing event re-ingestion must re-drive missing required obligations using the event's recorded/cutover policy. Add a completeness census comparing accepted source events to expected projector names and results; treasury self-balance alone is insufficient when both a payment and its journal are absent. Preserve parse/integrity quarantine suppression rules: recovery must not accidentally apply quarantined invalid events. Training receipts must remain contained.

Existing dead-letter UI is the primary resolution surface. Show fiscal accepted plus financial pending/blocked status, age and reason; emit actionable alerts after the configured retry ceiling. Enumerate exception cohorts for operator-led backfill, never call current module configuration historical truth.

**Seams:** `FiscalEventProjectionRegistry.php`, `OutboxIngestor.php`, `FiscalEventProjectionDispatcher.php`, `ApplyFiscalEventProjectionJob.php`, retry/enqueue commands; fiscal projection schema if a policy snapshot is required; `routes/console.php`, `config/horizon.php`; existing projection-status UI.

**Acceptance:** central config lookup failure at ingest; Redis unavailable after commit; worker killed after effect commit but before status update; duplicate delivery; treasury runs before receipt dependency; wrong tenant worker context; quarantined/training event; module change between ingest and replay. Every eligible case converges to one complete set of effects or a visible blocked state, never missing work reported as complete.

### W5 — Lot-grain counts and controlled lot correction

**Findings:** F7/F8; B2/B7. **Required before claiming accurate lot balances.**

Keep `InventoryCountingItem` as the product/variant/location parent and add child lot-count observations rather than duplicate product-level totals or change every existing item key. For tracked products, each finalized observation carries batch identity, observed quantity, count time/movement marker and operator. The parent total is derived from accepted child observations. Missing row is not zero; an explicit zero means physically absent. Unknown physical lots require a recorded identification decision before finalization; do not invent expiry or silently assign DEFAULT.

Apply discrepancies to their actual lot through the stock adjustment service. Retain current aggregate cost/opening/replay rules, locking the product cost basis and relevant lot/stock rows consistently. Remove FEFO as a substitute for lot identity on new tracked counts. Sum of lot movements must match the aggregate adjustment for each product/variant/location. Re-run produces no additional movement or GL.

Reuse the existing count-as-of and movement replay guards. If offline receipts can still alter the counted interval, finalization must require synchronized participating terminals or an explicitly provisional count that cannot claim reconciled final stock. A late pre-count movement reopens a reconciliation exception; do not silently change a finalized operator observation or apply its effect twice. Untracked products keep the existing counting flow. In-progress legacy tracked counts require completion under documented legacy semantics or cancellation/restart into lot mode; migration cannot fabricate observations.

After a lot has stock movements, ordinary update cannot change its identity or expiry. Add a permissioned correction action on its existing detail screen recording old/new values, reason, actor, timestamp and evidence reference. Use optimistic version checking to reject stale corrections. Corrections append history; original receipt/sale snapshots remain unchanged. Identity change must not merge two lots with existing histories. Deactivation of a lot with stock requires explicit disposition, not hidden removal from eligibility. Recall is a separate action, not an editable boolean.

**Seams:** `InventoryCountingItem.php`, count submit/review DTOs and services, `StockAdjustmentService.php`; additive tenant child table; `BatchExpiry/Presentation/Requests/UpdateBatchRequest.php`, `BatchController.php`, batch repository; existing `apps/web/src/features/inventory-counting` and `features/batches` surfaces.

**Acceptance:** 10 short-dated + 10 long-dated booked, actual 10 + 5: only long-dated lot decreases by 5; explicit zero versus omitted lot; surplus unknown lot; variant; count interrupted by sale/transfer; late sync; repeated finalize; active legacy count; concurrent correction; unauthorized expiry extension; two-company/two-location stock and GL reconcile.

### W6 — Offline POS inventory evidence, conditional on D3

**Finding:** F1; B1. **Captured mode is proposed, not an asserted fiscal requirement.**

Two explicit capability profiles:

- `captured_lot`: checkout records scanned or operator-selected lot and quantity, using locally cached branch eligibility and the D4 policy. FEFO is a suggestion requiring confirmation; it is not an assertion about the physical item. Unknown or locally recalled/expired lots cannot be sold in this mode without a separately approved override policy; no override is introduced by this spec.
- `shelf_control`: SKU-only checkout, external shelf/recall procedures and estimated server FEFO. Traceability displays `system_fefo_estimate` or `unknown`; never relabel it as operator-captured evidence. This is conditional containment, not an automatic launch permission.

For captured mode, add a separate versioned inventory-evidence record/outbox item linked to the fiscal receipt event ID and hash. It carries tenant/company/terminal/location, receipt line identity, product/variant/lot IDs, quantity strings, capture method, local timestamp and cached policy revision. Its provenance values are `operator_captured`, `system_fefo_estimate`, `unknown`; none claims independent proof of physical reality. Reuse existing terminal authentication/trust boundaries; do not introduce a new PKI or edit an old fiscal payload shape.

The local receipt plus its required inventory evidence must commit atomically in SQLite. Failure to persist evidence in captured mode rolls back the local authoring transaction before returning success. Synchronization accepts evidence before or after its fiscal event but keeps it pending until the verified linked event exists. Validate terminal/company ownership, line mapping, quantities and event hash. Same evidence ID with changed content is a conflict. No second inventory movement is authored by the evidence endpoint.

The existing receipt projection remains the only owner of sale stock effects. In captured mode it consumes the specified lots when valid, not a later FEFO substitution. Missing/conflicting evidence or unavailable lot balance leaves a durable inventory reconciliation obligation, while preserving the fiscal sale and avoiding duplicate aggregate decrement. A non-applied lot effect must not be represented as reconciled. A separate operator resolution appends the reason and resulting movement links; never alter the source receipt. For delayed receipts, compare sale-time evidence with historical eligibility where known; a newly issued recall does not retroactively invalidate the earlier physical transaction.

Global no-oversell is not promised while multiple terminals are disconnected. If the owner later demands that guarantee, device stock budgets/reservations require a separate design. This spec records conflicts and resolves them explicitly. Refund inventory uses original captured allocations where available and the actual returned lot/disposition; unknown legacy provenance remains unknown, not invented.

**Seams:** `apps/pos/src/types/product.ts`, product repository/cache, checkout/receipt transaction and outbox; additive SQLite migration; server inventory-evidence DTO/ingress/storage; `PosCoreReceiptProjection.php`; FEFO service; receipt-line allocation/traceability DTOs and existing receipt/lot screens. Mixed-version rollout is capability-gated per terminal.

**Acceptance:** two lots of one SKU, manual selection versus scan, local expired/recalled lot, unknown expiry, no connection, crash at each local commit boundary, evidence/event reordered or duplicated, different terminal forged reference, policy stale between sessions, delayed sale crossing expiry date, post-sale recall, two disconnected terminals consuming the same remaining lot, original-lot refund and legacy unknown provenance. Fiscal bytes survive every reconciliation path unchanged.

### W7 — Reconcile device close against complete operational evidence

**Finding:** F6. **Required for a trusted POS branch close.**

Retain signed device close/Z records as authored facts. Add a separate server reconciliation result keyed to terminal/session/Z event, with states `pending_sync`, `blocked`, `mismatch`, `matched` and a recorded input fingerprint. Only `matched` can display a reconciled badge; device-closed and server-reconciled are different statuses.

First reuse and validate the existing Z `operational_event_range` (`apps/pos/src/lib/offline/zReportService.ts:748`; `ZReportPayload.php:21`). It currently names receipt bounds/count, not necessarily every account-payment/cash event. Establish whether those bounds are legacy receipt sequences or canonical operational sequences; do not mix sequence spaces. Require hash/ID/count membership checks, not timestamp inference. For new-capability terminals add a versioned close manifest covering every contributing operational stream and the contributing repository-movement references visible to the device. Historical closes lacking sufficient bounds remain explicitly limited/pending, never upgraded to fully matched by guesswork.

Recompute sales and refund VAT separately by rate/category from verified canonical receipts, retained tender amounts after change, account collections, opening float and cash movements. Compare each semantic total to the corresponding device value; do not equate net-of-refund VAT with sale-only headers. Reuse existing cash-rounding/discount/quantity contracts. Never repair the refund-bearing validator by enabling an invalid arithmetic identity.

Expected physical cash is opening float plus retained cash sales plus cash account collections plus cash-in minus cash refunds minus cash-out, with each source counted once. Repository movements from the back office affecting the same drawer during the session require inclusion or a declared discrepancy; they cannot silently disappear because the device was offline. Shared drawers across simultaneous terminals need repository-level reconciliation plus attribution, not summing two opening floats into company cash. Terminal session evidence and company bank settlement remain separate.

Late arrivals inside the declared close membership trigger recomputation from a new input fingerprint. A receipt outside the declared membership but naming a closed session creates an exception; do not drop the financial fact or silently rewrite the Z. Existing mismatch resolutions append evidence and may produce compensating operational entries; they do not change the signed report. Missing projections or lot obligations keep the corresponding close checks pending/blocked.

**Seams:** `ZSessionLifecycleProjection.php`, `ZReportProjection.php`, `FiscalPayloadConstraintValidator.php`, `OutboxIngestor.php`; existing receipt lookup/shift expected cash services; proposed `PosSessionReconciliationService` and additive reconciliation table; existing shift/Z detail UI; `apps/pos` close manifest/version capability. No replacement Z authoring service.

**Acceptance:** taxed sale plus partial refund, multiple rates, rounded cash and card, account collection during shift, back-office drawer movement, two terminals sharing custody, missing receipt/projection, complete late arrival, outside-manifest receipt, negative refund-only bucket, duplicate Z, legacy insufficient bounds. Matching hashes with wrong economic totals must still produce mismatch.

### W8 — Client-specific release proof and migration discipline

**Scope:** integrate W1–W7 without expanding into a new product. Run against the chosen release artifact, PostgreSQL tenant topology and actual terminal build.

- Additive tenant migrations first, then compatible server readers, then explicit per-company/terminal capability enablement. Keep old fiscal readers. Do not enable new tender/lot/close authoring before its server acceptance and recovery paths exist.
- Census existing repository routing, sealed documents without GL, missing projections, DEFAULT/unknown lots, in-progress counts and unresolved closes. Backfills may annotate provenance/capabilities; financial corrections require explicit compensating operations. Never fabricate captured lots or approvals.
- Back up and rehearse restore before cutover. Rollback disables new authoring capability while retaining ingestion/readers for already emitted new versions and all outbox data. No destructive migration rollback over financial history.
- Extend the existing onboarding campaign with two-branch/two-terminal journeys and real device smoke. Synthetic fiscal vectors are useful but do not replace Tauri/SQLite/printer/offline/crash testing.
- Gate on every required leg's result and candidate revision, not an empty findings list or process exit alone. Any required skipped/blocked leg means incomplete evidence.
- Record the Tunisia accounting/configuration review and existing owner launch gates on the actual release. Do not copy an old blank checklist as fresh approval.

## 5. Order and review boundaries

| Order | Package | Dependencies | Deliverable for later execution planning |
|---|---|---|---|
| 0 | Owner profile D1–D6 and fresh source census | Fable review of this spec | Approved target, scope exclusions and updated finding baseline |
| 1 | W1 access; W2 tender safety | D5; D2 for enabled tenders | Independently testable shared controls and migration/seed behavior |
| 2 | W3 B2B atomicity; W4 POS projection durability | Separate sales ownership retained | No B2B partial seal/GL; no accepted POS event missing mandatory work |
| 3 | W5 lot counting/correction | W1 permissions | Actual lot-level count adjustments and preserved corrections |
| 4 | W6 captured-lot evidence or D3 shelf-control labeling | D3/D4; W4; W5 for trustworthy balances | Capability-gated retail inventory behavior and recovery |
| 5 | W7 close reconciliation | W2/W4; selected W6 profile | Honest pending/mismatch/matched results over complete input membership |
| 6 | W8 release campaign | All launch-selected packages | Same-candidate two-branch evidence and owner acceptance |

W1/W2/W3/W4 may be planned as separate bounded lanes but must reconcile shared permission, schema and GL lock changes before integration. No executable task plan should be inferred from this table. Each later plan must name files, contract signatures, red-first tests, migration/backfill order, compatibility and reviewer gates.

## 6. Validation matrix for the later plans

| Requirement | Required evidence | Existing anchors to extend |
|---|---|---|
| W1 | Real HTTP permission denials and unchanged money/stock snapshots | `tests/Feature/Identity/UserManagement/UserLocationAccessTest.php`, `tests/Feature/Treasury/RepositoryTransferEndpointTest.php`, `tests/Feature/Security/BatchExpiryModuleAccessControlTest.php` |
| W2 | Branch cash/card/instrument effects; remap and replay | `tests/Feature/Treasury/BranchCashRepositoryRoutingTest.php`, `TenderRepositoryResolverTest.php`, `PaymentRepositoryCompanyScopeTest.php` |
| W3 | Fault injection around commit; concurrent same-company chain and unique source | `tests/Feature/Accounting/DocumentGlPreflightTest.php`, `InvoicePostedListenerTest.php`; new PostgreSQL concurrency tests |
| W4 | Publication/worker/config failure, missing-row recovery, quarantine containment | `tests/Feature/Fiscal/FiscalEventProjectionRegistryTest.php`, `ApplyFiscalEventProjectionJobTest.php`, `FiscalEventProjectionDispatcherTest.php` |
| W5 | Two-lot discrepancy meaning, count replay and protected edits | `tests/Feature/Inventory/InventoryCountingDefaultBatchTest.php`, `apps/web/src/features/inventory-counting/__tests__/ReviewReplayColumns.test.tsx`; new lot-grain tests |
| W6 | Device-local atomicity and bidirectional evidence/event delivery order | `tests/Feature/Fiscal/PosCoreReceiptProjectionBatchLotTest.php`; new `apps/pos` SQLite integration and device tests |
| W7 | Membership completeness and independent semantic arithmetic | `tests/Feature/Fiscal/ZReportProjectionTest.php`, `FiscalPayloadConstraintValidatorTest.php`, `tests/Feature/POS/ZReportRefundVatDisclosureTest.php`; new reconciliation tests |
| W8 | Two-branch business journey and recovery on actual candidate | `docs/qa/ONBOARDING-CAMPAIGN.md`, `scripts/campaign-onboarding.sh`, existing real-device smoke/restore runbooks |

Backend test paths above are under `apps/api/` unless otherwise prefixed. SQLite-only passing tests do not satisfy row-lock, uniqueness, trigger or tenant-topology guarantees. During future implementation use focused tests first and repository preflight/CI checks before promotion, including React diagnostics for changed React code. No tests are claimed executed for these proposed changes.

## 7. Adversarial review contract and stop condition

Fable 5.1 must challenge both the audit premises and this proposed remedy, using source evidence rather than accepting prior findings. It should distinguish confirmed defects, policy choices and overengineering; assess whether any package moves the failure rather than removes it; and identify which owner decisions prevent an executable plan.

Mandatory lenses: offline fiscal authority and immutable history; actual versus estimated lot evidence; mixed-version cutover; tenant/company/location authorization; cash versus instrument/settlement economics; B2B transaction and GL lock ordering; durable projection recovery/quarantine; independent close membership and arithmetic; operator usability; migration/restore; test meaning and realistic effort/dependency boundaries.

Required reviewer output: severity, exact spec/source citation, concrete failure scenario, minimum correction, and whether it blocks spec approval or only a later execution plan. Include counterevidence and rejected false positives. Final verdict must be `ACCEPT-FOR-OWNER-REVIEW` or `CHANGES-REQUIRED`; neither is launch approval.

**Stop after the first adversarial review is recorded. Do not implement, commit product changes, deploy, run a fixing loop, or silently incorporate reviewer recommendations. Report the spec and review paths and any blockers to the owner.**
