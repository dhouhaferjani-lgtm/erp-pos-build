# AUDIT — state-machine sweep, LOCAL tree (the complement to the VPS 21)

> **Date:** 2026-08-23 · Local dev tip `bdf9ce5e9` · Read-only. No production file was modified by this session; the only write is this document.
>
> **Companions:** `docs/handoff/AUDIT-state-machines-vps-2026-08-23.md` (the 21 confirmed findings) and `docs/handoff/TRIAGE-state-machine-audit-2026-08-23.md` (their re-verification + charter sketch).
>
> **This document deliberately re-reports NONE of the 21.** Its whole job is #22+: the same defect class, current tree, in the surfaces the VPS session under-covered or never opened — Treasury, Fiscal/device, Inventory, Import/opening-balances, Vouchers, Loyalty, tenancy.
>
> **Tenant #1 = parapharmacy on the IziPOS retail till.** Severity is graded against that, not against the codebase in the abstract.

---

## 0. Headline

Two things, and they point in opposite directions.

**The bad news is nine new findings, two of them HIGH, and both HIGH ones sit directly on the tenant-#1 path** — one on the go-live *onboarding* path (import double-apply) and one on a retail money surface (voucher void without a GL entry).

**The good news is bigger, and it should shrink the program the triage is chartering.** The sweep expected to find the POS-Orders anti-pattern replicated across Treasury, Inventory and Fiscal. **It is not there.** Those modules are, at the application layer, *well* governed — row locks, status preconditions, typed exceptions, idempotent short-circuits, append-only event tables. What they lack is uniformly the **database** half: 76 of 90 status columns carry no CHECK constraint, and only three surfaces in the whole tree persist a transition trail.

So the correct shape of the program is not "propagate the Workshop pattern to 85 enums." It is: **a large, cheap, mostly-mechanical DB-constraint slice, plus a small number of genuinely ungoverned nouns.** That is a materially smaller and safer program than the surface count suggests.

### The census behind that claim

Every number below is machine-derived, not estimated. Scripts are in this session's scratchpad; the commands are reproducible from the citations.

| Measure | Value |
|---|---|
| Status/type enums under `app/**/Domain/Enums/*Status*.php` | **85** |
| Status/state columns declared across all migrations | **90** |
| …of those, columns with a DB CHECK (or inline PG enum) | **14** |
| …**of those, columns with NO DB backstop** | **76** |
| Enums with a real adjacency map | **4** |
| Enums with any `isTerminal()` | **12** |
| Status-transition audit surfaces | **3** |
| Registered PHPStan rules enforcing a single status write path | **1** (covering **1** enum) |
| Raw status write sites (non-test, `app/Modules/**`) | **256 across 113 files in 33 modules** |

The four adjacency maps: `Workshop/WorkOrder/Domain/Services/StatusMachine.php`, `Scheduling/Domain/Services/AppointmentStatusMachine.php`, `Inventory/Domain/Enums/StockAdjustmentStatus.php`, `Inventory/Domain/Enums/CountingStatus.php`.

The three transition surfaces: `workshop_work_order_status_transitions`, `scheduling_appointment_status_transitions`, and — **the one both prior documents missed** — `instrument_events` (`database/migrations/tenant/2026_07_12_100200_create_instrument_events.php:17-36`), which carries `from_status` / `to_status` / `journal_entry_id` / `movement_id` and is a genuine third instance of the pattern.

---

## 1. (i) New findings, ranked

Numbering continues the VPS register (which ended at 21).

---

### **#22 · [HIGH] · Manual voucher void extinguishes a liability without posting to the GL — while both automated void paths do post**

**Where:** `apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:346`
(contrast `apps/api/app/Modules/Voucher/Application/Services/VoucherLookupService.php:272` and `apps/api/app/Modules/Voucher/Application/Services/VoucherCascadeService.php:131`)

**Problem.** There are three paths that set a voucher to `Voided`, and they are asymmetric in exactly the way that matters.

- `VoucherCascadeService.php:131` (`policy_trigger = 'cascade_credit_note_void'`) calls `$this->generalLedger->createVoucherLedgerEntry($unsavedVoided, $voucher)` and stores the result: `'gl_journal_entry_id' => $glEntry->id` (`:146`).
- `VoucherLookupService.php:272` (`policy_trigger = 'auto_fraud_void'`) does the same, guarded by `bccomp($voidedBalance,'0',5) > 0` (`:253`) — a GL reversal only when there is something to reverse.
- `VoucherController::void` (`:332-360`, `policy_trigger = 'manual_void'`) writes the `VoucherLedger` row with **`'gl_journal_entry_id' => null` hardcoded** (`:346`), then `$voucher->status = VoucherStatus::Voided; $voucher->current_balance = '0.00000';` (`:358-359`). The controller does not inject a general-ledger port at all — `grep "generalLedger" VoucherController.php` returns only the `__construct` line (`:41`).

**Why it matters.** An outstanding voucher is a liability. The manual void is the *only* one of the three a human operator triggers (route `POST /api/v1/vouchers/{id}/void`, `apps/api/app/Modules/Voucher/Presentation/routes.php:32-34`, behind `can:pos.void_voucher`). When a manager voids a voucher with a positive balance, the balance is zeroed and a `Voided` ledger event is written, but **nothing reaches the general ledger** — the voucher-liability account stays overstated by that amount permanently, and `voucher_ledgers` and the GL now disagree about the same event. Because the two automated paths *do* post, the discrepancy is silent and selective: it appears only for operator-initiated voids, which is precisely the population nobody reconciles.

**Fix shape.** Move the void out of the controller into a `VoucherVoidService` that mirrors `VoucherCascadeService::…` — inject the GL port, create the reversal entry when `current_balance > 0`, and store `gl_journal_entry_id`. This is a **document-per-action** defect first and a state-machine defect second: route it to the `project_document_per_action_remediation` program, not to the state-machine charter.

---

### **#23 · [HIGH] · An import job can be re-executed after completion and re-applies every valid row**

**Where:** `apps/api/app/Modules/Import/Presentation/Controllers/ImportController.php:456` · `apps/api/app/Modules/Import/Application/Jobs/ProcessImportJob.php:114-118` · `apps/api/app/Modules/Import/Services/ImportService.php:276-282`

**Problem.** Three independent guards that should exist, do not.

1. `ImportJob::canBeExecuted()` (`apps/api/app/Modules/Import/Domain/ImportJob.php:127`) is exactly the right guard — `return $this->status->canStartImport() && $this->getValidRowsCount() > 0;`, where `ImportStatus::canStartImport()` (`Domain/Enums/ImportStatus.php:16-20`) allows only `Validated` or `Pending`. **`canBeExecuted` has ZERO callers.** `grep -rn "canBeExecuted" app/` returns nothing. `canStartImport` has exactly one caller: `canBeExecuted` itself.
2. `ImportController::execute()` (`:456`) loads the job (`:462-464`), checks the type is not retired (`:474`), and dispatches — with **no status precondition anywhere in the method**. It then sets `$job->update(['status' => ImportStatus::Pending]);` (`:539`) *after* `ProcessImportJob::dispatch(...)` (`:536`).
3. `ProcessImportJob::processImport()` unconditionally writes `'status' => ImportStatus::Importing` (`:114-118`) and proceeds. No lock, no precondition.
4. The row filter does not save it: `ImportService::getValidRows()` (`:276-282`) filters `where('is_valid', true)` only. `is_imported` is *written* (`ProcessImportJob.php:138`) and read for tallies (`:167-168`), but is **never used to exclude already-imported rows** from a re-run.

**Why it matters.** `POST /imports/{id}/execute` on a job already in `Completed` is accepted and re-imports the entire file. This is the tenant-#1 go-live path — the parapharmacy's product catalogue and opening stock arrive through exactly this endpoint. A double-click, an impatient retry after a slow response, or a queue redelivery duplicates the whole import. Separately, the dispatch-then-mark ordering at `:536`/`:539` is a lost-update race in its own right: a fast worker sets `Importing` (`ProcessImportJob.php:114`) and the controller then overwrites it back to `Pending`, so the job's own status misreports what is happening to it.

**Fix shape.** FOLD-NOW, small. (a) Call the guard that already exists — `if (! $job->canBeExecuted()) { return 422; }` at the top of `execute()`. (b) Add the same precondition inside `ProcessImportJob::processImport()` under a `lockForUpdate()`, so a redelivered job fails closed rather than re-running. (c) Filter `getValidRows()` on `->where('is_imported', false)` so even a successful re-entry is idempotent. (d) Move the `Pending` write *before* the dispatch. No migration.

---

### **#24 · [MEDIUM] · Manual voucher void has no status precondition — every terminal state has a live outgoing edge**

**Where:** `apps/api/app/Modules/Voucher/Presentation/Controllers/VoucherController.php:332-360`

**Problem.** The void path performs no check on `$voucher->status` before writing `VoucherStatus::Voided` (`:358`). `VoucherStatus` (`Domain/Enums/VoucherStatus.php`) is a bare 5-case enum — `Issued`, `PartiallyRedeemed`, `FullyRedeemed`, `Expired`, `Voided` — with **no adjacency map, no `isTerminal()`, and no methods at all**. So `FullyRedeemed → Voided`, `Expired → Voided` and `Voided → Voided` are all reachable, each writing a fresh `VoucherLedger` `Voided` event.

The contrast is inside the same module and is stark: `VoucherRedemptionService::redeem()` (`Application/Services/VoucherRedemptionService.php:77-101`) opens a transaction (`:81`), takes `lockForUpdate()` (`:88`), and enforces a strict allow-list — `if (! in_array($voucher->status, [VoucherStatus::Issued, VoucherStatus::PartiallyRedeemed], true)) { throw VoucherInvalidStatusException::invalidStatus(...); }` (`:100-101`). One service in the module knows how to do this correctly; the controller does not.

**Why it matters.** Voiding an already-`FullyRedeemed` voucher silently rewrites history: the voucher now reads as voided, so redemption statistics, outstanding-liability reports and any query that buckets by status all move an amount from "redeemed" to "voided" long after the redemption was recognised. Combined with #22 the two compound — a redeemed voucher can be flipped to voided with no GL entry and no balance change to make the flip visible.

**Fix shape.** Give `VoucherStatus` an adjacency map with `FullyRedeemed`/`Expired`/`Voided` as terminal, and route all three void paths through one `VoucherVoidService` that asserts it — the same service that #22 needs. Add a CHECK on `vouchers.status` (see #26).

---

### **#25 · [MEDIUM] · `pos_terminals` — a fiscal device — has an unconstrained lifecycle and no unique hardware identity, while its same-day sibling `pos_shifts` is fully constrained**

**Where:** `apps/api/database/migrations/tenant/2026_01_08_190429_create_pos_terminals_table.php:45-51,65`

**Problem.** `pos_terminals` carries the NF525 hash-chain head (`genesis_seed:39`, `current_sequence:40`, `current_year:41`, `last_hash:42`) and a device lifecycle (`is_active:45`, `activated_at:46`, `deactivated_at:47`, `deactivation_reason:48`). Three gaps:

- `is_active` is a bare `boolean` with **no state-invariant CHECK** tying it to `deactivated_at` / `deactivation_reason`. Its sibling `pos_shifts` — created the same day, `2026_01_08_190641_create_pos_shifts_table.php` — has exactly that: `pos_shifts_closed_logic` (`:84`) ties `status` to `closed_at`/`closed_by`, alongside `pos_shifts_status` (`:81`), `pos_shifts_positive_amounts` (`:72`), `pos_shifts_variance_calc` (`:77`) and a partial unique index for one open shift per terminal (`:69`). A terminal can therefore persist `is_active = true` with `deactivated_at` set, or the reverse.
- `hardware_identifier` (`:51`) is `string(100)` nullable with **no unique index**. The only unique constraint on the table is `(tenant_id, company_id, location_id, code)` (`:65`). Nothing prevents two physical devices registering the same hardware identifier against different terminal rows, or one device against two.
- There is no activate/deactivate audit row. Deactivating a fiscal device is a compliance-relevant act and leaves only `deactivation_reason` free text.

**Why it matters.** The terminal row *is* the fiscal chain identity. `is_active` gates which terminals appear in fiscal reporting and device registries (`TerminalRegistrySnapshotService`). An impossible `is_active`/`deactivated_at` combination is exactly the class of state the shift table was hardened against, and the hardware-identity gap means a device swap or re-registration cannot be proven from the schema. This is graded MEDIUM rather than HIGH because no *current* code path writes an inconsistent pair — but there is no backstop, and the shift table's authors clearly judged this class worth constraining.

**Fix shape.** Mirror the `pos_shifts` idiom on `pos_terminals`: a state-invariant CHECK on `is_active`/`deactivated_at`/`deactivation_reason`, and a partial unique index on `hardware_identifier` where it is non-null. **Ride the B-3 lane's migration** rather than opening a second one on the same table (see §4).

---

### **#26 · [MEDIUM, systemic] · 76 of 90 status columns have no DB CHECK — the full register**

**Where:** whole-tree census; see the money/fiscal subset below.

**Problem.** This **generalises VPS finding DS-2 rather than repeating it.** DS-2 named five columns (`composite_items.vertical_type`/`production_type`, `documents.type`/`status`, `products.type`) and the triage scoped charter slice D to seven. The actual population is **76 of 90**. Fourteen columns are constrained: `bank_reconciliations.status`, `bank_statement_lines.match_status`, `bank_statements.status`, `device_loss_incidents.recovery_status`, `documents.fiscal_status`, `inventory_counting_assignments.status`, `inventory_countings.status`, `partners.account_status`, `pos_shifts.status`, `scheduling_appointment_reminders.delivery_status`, `scheduling_appointments.status`, `workshop_technician_profiles.employment_status`, `workshop_work_order_lines.core_deposit_status`, `workshop_work_orders.status`.

**The money/fiscal subset of the 76 — this is the part that should drive slice D's priority order:**

| Column | Declared in |
|---|---|
| `payments.status` | `tenant/2025_11_30_120000_create_treasury_tables.php` |
| `payment_instruments.status` | `tenant/2025_11_30_120000_create_treasury_tables.php` |
| `instrument_remittances.status`, `instrument_remittance_lines.line_status` | `tenant/2026_07_12_100400_create_instrument_remittances.php` |
| `instrument_events.from_status`, `.to_status` | `tenant/2026_07_12_100200_create_instrument_events.php` |
| `journal_entries.status` | `tenant/2025_11_30_100000_create_journal_entries_table.php` |
| `fiscal_events.integrity_status`, `.signature_status`, `.payload_parse_status` | `tenant/2026_05_14_100001_create_fiscal_events_table.php` |
| `fiscal_event_projections.projection_status` | `tenant/2026_05_14_100003_…` |
| `fiscal_event_quarantine.payload_parse_status` | `tenant/2026_05_14_100004_…` |
| `pos_receipts.fiscal_status` | `tenant/2026_04_18_211150_add_offline_sync_fields_to_pos_receipts.php` |
| `fiscal_periods.status` | `tenant/2025_11_30_132000_create_compliance_tables.php` |
| `vat_periods.status` | `tenant/2026_03_23_200000_create_vat_periods_table.php` |
| `opening_balance_batches.status`, `opening_balance_import_rows.status` | `tenant/2025_12_11_100000_create_opening_balance_tables.php` |
| `import_jobs.status` | `tenant/2025_11_30_150000_create_import_tables.php` |
| `vouchers.status` | `tenant/2026_05_02_000001_create_vouchers_table.php` |
| `goods_receipts.status` | `tenant/2026_07_04_100000_create_goods_receipts_tables.php` |
| `stock_transfers.status` | `tenant/2026_05_28_120000_create_stock_transfers_table.php` |
| `stock_adjustments.status` | `tenant/2026_08_08_120000_create_stock_adjustments_tables.php` |
| `supplier_goods_return_notes.status` | `tenant/2026_08_08_160000_create_supplier_goods_return_notes_tables.php` |
| `withholding_certificates.status` | `tenant/2026_01_08_172147_…` |
| `loyalty_members.status`, `loyalty_programs.status`, `loyalty_enrollments.status` | `tenant/2026_01_10_1000{01,00,03}_…` |
| `impersonation_grants.status`, `impersonation_elevations.status` | `2026_08_06_230000_create_impersonation_access_tables.php` |

Note three of these are *themselves* the transition-audit tables (`instrument_events.from_status`/`to_status`, the two `*_status_transitions` tables) — the audit trail can record a state that the enum cannot hydrate.

**Why it matters.** Same mechanism DS-2 describes, at 15× the scope, and now with the money-bearing columns identified. `journal_entries.status`, `import_jobs.status` and `opening_balance_batches.status` have **zero CHECK constraints of any kind** on their tables (verified by grepping the full migration tree for each table name).

**Fix shape.** This is the register charter slice D should consume. Keep the triage's three preconditions verbatim — per-tenant distinct-value census first (S-16 pattern), `NOT VALID` + separate `VALIDATE CONSTRAINT`, and the `pg_constraint` parity test — and sequence by the money/fiscal table above rather than by the seven columns currently scoped.

---

### **#27 · [MEDIUM] · The reference pattern's own enforcement is bypassable, and the second precedent has no enforcement at all**

**Where:** `apps/api/app/PHPStan/Rules/WorkOrderStatusWriteOnlyViaTransitionService.php:44-65` · `apps/api/app/Modules/Workshop/WorkOrder/Domain/WorkOrder.php:117` · `apps/api/app/Modules/Scheduling/Domain/Appointment.php:111`

**Problem.** The triage's §6 item 13 is right that the PHPStan rule — not the adjacency map — is what makes the Workshop pattern hold. But the rule as written is narrower than its own docblock claims.

- `processNode` returns early unless the node is an `Assign` whose `var` is a `PropertyFetch` named `status` (`:46-56`). It therefore catches `$workOrder->status = X` and **nothing else**. `$workOrder->update(['status' => X])`, `->fill([...])`, `->forceFill([...])` and `WorkOrder::query()->update([...])` are all invisible to it.
- `status` is in `$fillable` on **both** flagship aggregates: `WorkOrder.php:117` and `Appointment.php:111`. So the bypass is not hypothetical plumbing — it is one `->update()` away, and mass-assignment is the dominant idiom in this codebase (of the 256 raw status writes counted, the majority are `update([...])` form).
- Only one such rule is registered (`apps/api/phpstan.neon:35`). **Scheduling has the full pattern — `AppointmentStatusMachine`, `AppointmentTransitionService`, `InvalidAppointmentTransitionException`, `scheduling_appointment_status_transitions` — and no PHPStan rule whatsoever.**

Workshop is *currently* clean (`grep` for `update([...'status'...])` under `app/Modules/Workshop/` returns nothing), so this is a latent hole, not a live corruption. It is listed because of its leverage on the charter.

**Why it matters.** Charter step §4 says "the PHPStan rule lands in the same milestone as the service or the service is decorative." That is correct but insufficient: **propagating this rule as written to 85 enums would propagate a guard that the codebase's most common write idiom walks straight past.** The program would ship the appearance of enforcement.

**Fix shape.** Before propagating: (a) extend the rule to match `MethodCall` on `update`/`fill`/`forceFill` with a literal `status` array key, plus static-query builder forms; (b) remove `status` from `$fillable` on governed aggregates so mass-assignment cannot reach it at runtime either; (c) add the Scheduling counterpart. Then generalise it into one parameterised rule (aggregate FQCN → allowed writer FQCN) instead of one bespoke class per noun — otherwise the program ships 20 near-identical rule classes.

---

### **#28 · [LOW] · Dead terminal guards in Treasury**

**Where:** `apps/api/app/Modules/Treasury/Domain/Enums/InstrumentStatus.php:58` · `apps/api/app/Modules/Treasury/Domain/Enums/PaymentStatus.php:25`

**Problem.** Both enums define `isTerminal()`. `grep -rn "isTerminal()" app/Modules/Treasury/` returns **only the two definitions** — zero call sites. `InstrumentStatus::isTerminal()` covers `Expired`, `Cancelled`, `Collected`; the enum's own docblocks mark `Expired` and `Collected` as *"Reserved-dormant: no Phase 2 transition produces this status"*, so the method's only reachable case is `Cancelled` — which the lifecycle guards handle by other means (`InstrumentLifecycleService.php:756`).

**Why it matters.** Low, and honestly reported as such: the instrument lifecycle is genuinely well guarded (see §2), so nothing is currently unprotected. The risk is a reader — human or agent — seeing `isTerminal()` and assuming terminality is enforced somewhere. It is a false affordance of exactly the kind #27 describes.

**Fix shape.** Either wire it into the guards or delete it. If the charter builds a Treasury adjacency map, it subsumes both.

---

### **#29 · [LOW] · Two typed-exception dialects for the same refusal inside one module**

**Where:** `apps/api/app/Modules/Treasury/Application/Services/OutboundInstrumentService.php:101,252,412,561` vs `apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:146,148-149,213-214,338-339`

**Problem.** `InvalidInstrumentTransitionException` (`Domain/Exceptions/InvalidInstrumentTransitionException.php:10`) exists and is thrown at four sites — all inside `OutboundInstrumentService`. The inbound lifecycle refuses the identical class of transition with a bare `DomainException` carrying a prose message: `'Instrument cannot clear in its current status.'` (`:214`), `'Instrument cannot bounce in its current status.'` (`:339`), `'Instrument status does not allow custody transfer.'` (`:149`).

**Why it matters.** Both services guard correctly, so there is no corruption path — this is an API-surface and observability defect. Callers cannot catch inbound transition refusals distinctly from any other domain error, and the HTTP status mapping differs between two halves of one lifecycle.

**Fix shape.** Use `InvalidInstrumentTransitionException` on both sides. Trivial, and a natural warm-up if a Treasury lane opens.

---

### **#30 · [LOW] · `pos_receipts.is_voided` is written only as `false` — a live fiscal filter that nothing sets**

**Where:** `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:603` · `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:820` · `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:399`

**Problem.** All three writers of `is_voided` set it to `false`, at creation. A whole-tree grep finds **no code path that ever sets it to `true`** — consistent with the void-sunset work (`fix/dpa-v9-void-sunset`), where receipt voiding was replaced by returns/credit notes. Yet the column is still a live query predicate: `BackfillSealedHashAlgorithmCommand.php:42` documents its own scan as `is_voided=false, is_training=false`-filtered, and `IndexReceiptsRequest.php:37` accepts it as a client filter.

**Why it matters.** Low. The risk is not corruption but a stale contract: a fiscal-relevant column that reads as a live lifecycle flag, is exposed through the API's filter surface, and is in fact vestigial. Any future code that assumes voiding works through this flag will silently do nothing.

**Fix shape.** Decide and document: either drop the column and its filter, or record it as a retired flag retained for historical rows. Belongs with the retro's dead-column proposal (G3), not with the state-machine charter.

---

## 2. (ii) The clean list — surfaces checked and found well-governed

This section is the point of the exercise as much as §1. Each entry was verified by reading the write sites, not inferred.

**1 · POS receipts and the fiscal chain head — exemplary; the best-defended surface in the repo.**
`ReceiptFinalizationService.php:84-124` opens a transaction and takes `Terminal::lockForUpdate()->findOrFail(...)` (`:86`) before reading `last_hash`/`current_sequence` (`:95-96`), computing the seal, and writing both back (`:121-122`). `ReceiptCreationService.php:140-142` independently locks the terminal before allocating the receipt number. The DB backs all of it: `receipt_number` UNIQUE (`create_pos_receipts_table.php:37`), `(terminal_id, receipt_year, chain_sequence)` UNIQUE (`:97`), `idempotency_key` UNIQUE (`add_idempotency_key_to_pos_receipts.php:20`), and a partial unique index on `(company_id, refund_request_id)` (`add_refund_audit_fields_to_pos_receipts.php:47`). **The launch-critical surface is the one that needs least work** — a genuinely reassuring result for tenant #1.

**2 · Voucher redemption.** `VoucherRedemptionService::redeem()` — transaction (`:81`), `lockForUpdate()` (`:88`), strict status allow-list with a typed `VoucherInvalidStatusException` (`:100-101`), plus expiry (`:106`), terminal-binding (`:111`), currency (`:120`), customer (`:130`), duplicate-in-transaction (`:146`) and balance (`:159`) guards, writing to an append-only `voucher_ledgers` event stream. Best-governed money lifecycle outside Workshop. **The redemption path is clean; only the void path (#22/#24) is not.**

**3 · Treasury payment instruments — the third, unrecognised precedent.** `InstrumentLifecycleService` locks every aggregate it touches (`:144, :209, :223, :228, :334, :349, :354, :363, :382, :731`), guards each edge with the enum's predicates (`canTransfer():148`, `canClear():213`, `canBounce():338`, and an explicit `status !== Received` refusal on the reversal path `:751`), asserts it was called inside an open transaction, and writes a `from_status`/`to_status` row to `instrument_events` on every edge (`:122, :308-309, :862`). With `InvalidInstrumentTransitionException` on the outbound side, **Treasury already has all four elements of the Workshop pattern.** It should be recognised as a precedent, not treated as a remediation target.

**4 · Inventory counting.** `CountingStatus` (`Domain/Enums/CountingStatus.php:38-53`) is a *total* adjacency map over 11 states with `Finalized` and `Cancelled` correctly terminal (empty outgoing lists). `InventoryCounting::transitionTo()` (`Domain/InventoryCounting.php:231-249`) is the single write path, refuses illegal edges, and sets the paired timestamp per target. The two apparent bypasses in `InventoryCountingController.php:687,1050` are both on `new InventoryCounting` — legitimate initial-state assignment, not transitions. And `inventory_countings.status` is one of the 14 columns that **does** carry a DB CHECK. This is the closest thing to a complete implementation of the target pattern outside Workshop.

**5 · Stock transfers.** `TransferStatus` carries `isTerminal()` plus `canBeInitiated()`/`canBeCompleted()`/`canBeCancelled()`. All three transitions in `StockTransferService` run inside `DB::transaction`, load through a `lockTransfer($transferId)` helper (`:276, :388, :472`), assert the predicate, and throw a **typed `TransferStateException`** (`:279, :391, :475`), with an explicit multi-product advisory-lock deadlock defence. No re-completion race.

**6 · Goods receipts.** `GoodsReceiptService` posting takes `lockForUpdate()` then refuses anything but Draft — `if ($lockedReceipt->status !== GoodsReceiptStatus::Draft) throw new \DomainException(...)` — before writing `Posted` (`:254`).

**7 · Supplier goods return notes (dpa-v8) — the brief asked explicitly, and the answer is yes, it is governed.** `SupplierGoodsReturnNoteService` (`:462` region) takes `lockForUpdate()`, short-circuits idempotently when already `Confirmed`, and asserts `canBeConfirmed()` before writing, throwing `\DomainException` with the offending status interpolated. The newest lifecycle in the tree is one of the better ones. Its only gap is the missing DB CHECK (#26).

**8 · Stock adjustments.** `StockAdjustmentStatus` has a total `allowedTransitions()` map with `isTerminal()` derived from it (`:29-45`) — its docblock explicitly says it follows `CountingStatus`, which is the pattern propagating on its own. `StockAdjustmentDocumentService::post()` guards with `assertStatus($adjustment, StockAdjustmentStatus::Draft, 'post')`.

**9 · Opening-balance batches — guarded, with caveats.** `OpeningBalanceBatchService::markBatchValidated()` (`:323`) and `lockBatch()` (`:364`) both refuse illegal preconditions before writing (`:326, :336, :342, :348, :367`), and no reverse edge out of `Locked` exists in the service. Caveats: generic `RuntimeException` rather than a typed exception, no `lockForUpdate` on the batch row, and no DB CHECK. Adequate today; the weakest of the clean entries.

**10 · Workshop and Scheduling — the precedents, with one correction.** Both are as described in the triage. One nuance worth recording: Scheduling has **two** write paths, not one — `AppointmentTransitionService.php:98` and `AppointmentAuthoringService.php:99,224,254,284`. This is *not* a bypass: every authoring-service write is preceded by `findForUpdate()` + `$this->statusMachine->assertAllowed($from, $to)` + `writeTransition(...)` + event dispatch, all inside `DB::transaction` (read `:216-245` for the cancel edge). The guard is duplicated rather than centralised, which is a drift risk, not a hole — and with no PHPStan rule (#27) nothing prevents a third path appearing.

**11 · `pos_shifts`.** Re-checked for gaps in its CHECK constraints, as the brief asked. None found: `pos_shifts_status` (`:81`), `pos_shifts_closed_logic` tying status to `closed_at`/`closed_by` (`:84`), `pos_shifts_positive_amounts` (`:72`), `pos_shifts_variance_calc` (`:77`), and the one-open-shift-per-terminal partial unique index (`:69`). The praise in the VPS audit is deserved and the constraint set is complete.

---

## 3. (iii) How this folds into the one program

These findings do **not** justify a second program. They fold into the charter sketched in `TRIAGE-state-machine-audit-2026-08-23.md` §4, with four amendments.

**Amendment 1 — the program is smaller than it looks; re-scope it around the evidence.**
The charter's implicit premise is that the Workshop pattern must be propagated broadly. The census says otherwise: Treasury instruments, Inventory counting, stock transfers, goods receipts, supplier return notes, stock adjustments and voucher redemption are **already guarded at the application layer**, several with typed exceptions and one with a full transition table. The genuinely ungoverned nouns are the ones the VPS audit already named (POS Orders, POS OrderLines, Documents) plus **vouchers** (#24) and **import jobs** (#23). Workstreams A, B and C stand. **No Treasury or Inventory workstream is warranted** — those modules need slice D and transition tables, not rewrites. Say so explicitly in the spec so a future lane does not open one.

**Amendment 2 — slice D gets the real register.**
Replace slice D's seven-column scope with the 76-column register in #26, sequenced by the money/fiscal table. Keep the triage's three preconditions unchanged (per-tenant distinct-value census on the S-16 pattern; `NOT VALID` + separate `VALIDATE CONSTRAINT`; the `pg_constraint` enum↔CHECK parity test). Slice D remains the highest-value, earliest-shippable slice — that judgement is reinforced, not changed. Add one item: three of the uncovered columns are on the *transition-audit* tables themselves, so the parity test must cover `from_status`/`to_status` columns too.

**Amendment 3 — fix the guard before propagating it (#27).**
Charter §4 step 4 must be strengthened. The rule as written catches only `$x->status = …`; the codebase's dominant idiom is `->update(['status' => …])`, and `status` sits in `$fillable` on both flagship aggregates. Add to step 4: extend the rule to mass-assignment forms, remove `status` from `$fillable` on every governed aggregate, backfill the missing Scheduling rule, and **parameterise the rule** (aggregate FQCN → allowed writer FQCN) rather than shipping one bespoke class per noun. Without this the program ships the appearance of enforcement — which is a worse outcome than shipping nothing, because it stops the next reviewer from looking.

**Amendment 4 — two findings leave the charter entirely.**
- **#22 (voucher void without GL) belongs to `project_document_per_action_remediation`**, not here. It is a missing-justifying-document defect that this lens happened to surface; the state-machine half (#24) is the smaller part.
- **#23 (import re-execution) belongs in the FOLD-NOW queue**, alongside the triage's F1–F6. It is a three-guard fix with no migration, it is on the tenant-#1 onboarding path, and it should not wait for a spec. Suggested slot: **F7**, ahead of F1, because onboarding precedes operation.

**Two additions to the FOLD-NOW queue, then:**

| # | Scope | Size | Gate lens | Launch-relevant | Migration |
|---|---|---|---|---|---|
| **F7** | **Import re-execution idempotency.** Call the existing `ImportJob::canBeExecuted()` in `ImportController::execute()` (`:456`); add the same precondition under `lockForUpdate()` inside `ProcessImportJob::processImport()` (`:114`); filter `getValidRows()` on `is_imported = false` (`ImportService.php:276`); move the `Pending` write before the dispatch (`:536`/`:539`). Red-first test: execute a Completed job twice, assert one application. | **S** | `imports-reviewer` | **Yes — onboarding path** | No |
| **F8** | **Voucher void consolidation.** One `VoucherVoidService` used by controller, cascade and fraud paths: inject the GL port and post a reversal when `current_balance > 0` (#22), and assert a status allow-list with `FullyRedeemed`/`Expired`/`Voided` terminal (#24). | **M** | `treasury-reviewer` + `fiscal-pos-reviewer` | **Yes** | No (CHECK rides slice D) |

---

## 4. (iv) For the merge sequencer — in-flight lanes touching these surfaces

Four lanes of this session sit on surfaces named above. Flagged in priority order.

**B-3 terminals (`fix/b3-pos-enabled-claim-enforcement`, worktree `.worktrees/b3-pos-enabled`) — DIRECT COLLISION with #25.**
The triage recorded that "B-3 `pos_enabled` does not intersect any of the 21." True for the 21 — **not true for this sweep.** B-3 touches `POS/Presentation/Controllers/TerminalController.php`, `Tenant/Application/Services/TenantProvisioningService.php`, and ships a migration `tenant/2026_08_23_120000_backfill_location_pos_enabled_b3.php`. Finding #25 wants a state-invariant CHECK and a partial unique index on `pos_terminals`. **Sequencer action:** if #25 is accepted, it should ride B-3's existing migration rather than open a second migration against the same table in the same window — two unattended `tenants:migrate` passes on one fiscal table is avoidable risk. Decide before B-3 merges; after that, #25 needs its own gated lane.

**P1 auto-save (`fix/p1-autosave-route-hardening`, worktree `.worktrees/p1-autosave`) — CHARTER DEPENDENCY, not a collision.**
This lane introduces `Document/Domain/Exceptions/DraftNotEditableException.php` and guards `DraftPersistenceService` with `statusIsNotDraft($document->status)` (`:175`) and `fiscallySealed()` (`:171`). That is **the first typed transition exception in the Document module** — the artefact charter workstream B is chartered to create. **Sequencer action:** land P1 first (the triage already rules it proceeds independently), and make workstream B's spec *build on* `DraftNotEditableException` rather than introduce a parallel `InvalidDocumentTransitionException`. Two dialects in Document would repeat #29's mistake in the module that can least afford it.

**R-8 shift variance (`fix/r8-shift-variance-queue`, worktree `.worktrees/r8-sv-queue`) — owns the shift third, as already routed.**
Touches `POS/Application/Services/CashCountDispatcher.php`, `ReportGenerationService.php`, `ZReportSyncController.php`, `Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php`. The triage routes SM-5's shift third here; nothing in this sweep changes that. `pos_shifts` itself is clean (§2 entry 11), so R-8 inherits no new constraint work. **Sequencer action:** any shift-transition-audit work must enter through R-8, not through charter workstream A.

**B-6ii X/Z refund VAT (`fix/b6ii-xz-refund-vat-display`, worktree `.worktrees/b6ii-xz-refund-vat`) — textual conflict with R-8, not a state-machine issue.**
Both lanes edit `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php` and both edit `tests/Unit/POS/ReportGenerationServiceTest.php`. B-6ii is display-only over report output and raises no findings here. **Sequencer action:** merge-order these two deliberately; the conflict is mechanical but certain.

**One lane to check before opening F7.** Two dormant worktrees sit on the import surface — `apps/erp.dpa-v2` (`fix/dpa-v2-opening-balance-import`) and `apps/erp.dpa-v6` (`fix/dpa-v6-import-deprecation`). Neither is in this session's active set, but F7 touches `ImportController::execute()` and `ImportService::getValidRows()`. Confirm neither branch already modifies those methods before dispatching F7.

---

## 5. Method, coverage and honest limits

**Method per surface.** Locate the enum → read it for an adjacency map / `isTerminal()` → enumerate *every* write site of the status column with a multi-line-aware AST-ish scan (mass-assign `update`/`fill`/`forceFill` blocks, property assignment, and `DB::table(...)->update`) rather than a single-line grep → classify governed vs scattered → open the migration for a CHECK → test terminal-state reachability at the call sites → check for a transitions table → check numbering/redemption races for `lockForUpdate` or a unique index.

**A correction worth recording for anyone repeating this.** My first CHECK-constraint census was **wrong** — a `grep -qiE "check *\("` pattern missed every constraint written as `'ALTER TABLE x ADD CONSTRAINT y CHECK '.` (string concatenation, no adjacent paren), which is the dominant idiom here. It reported `workshop_work_orders` as unconstrained when it has four CHECKs (`2026_04_19_130001:105-118`). The numbers in §0 come from the corrected scan, which resolves table names from the enclosing `Schema::create`/`Schema::table` block and searches the whole migration tree for constraints added later in separate migrations. This is the same failure mode the triage flagged as the VPS session's error class (a) — *arithmetic asserted rather than measured* — and it caught me too. Derive counts with a script whose output you paste.

**Coverage limits, stated plainly.**
- Six parallel sub-sweeps were dispatched for Treasury, Fiscal/device, Inventory, Import, POS-till and tenancy/document-adjacent. **All six were terminated by a session usage limit and none returned a report**, including after being resumed. Everything in this document is my own direct verification; every citation was read.
- Consequently the coverage is **deep where it is deep and thin where it is thin**. Well covered: vouchers, Treasury instruments, Inventory (all six lifecycles), Import, opening-balance batches, POS receipts/terminals/fiscal chain, Workshop, Scheduling, the two whole-tree censuses.
- **Not covered, and still open:** Loyalty (`MemberStatus`/`ProgramStatus`/`EnrollmentStatus` — all three columns are in the #26 uncovered register, and points accrual/redemption idempotency was never examined); the Fiscal quarantine and outbox/ingestion processing states; `documents`' sibling status columns (`payment_status`/`delivery_status`/`fulfillment_status`/`match_status`) and whether any invariant ties them to `status`; SupportAccess `GrantStatus`/`ElevationStatus` revocation reachability; device SQLite mirrors of server state in `apps/pos/src/lib/db/`; and the r2f4 correcting-document and credit-note refund-disposition states. **The cross-column divergence question on `documents` is the highest-value item left unopened** — it was the one I most wanted an agent to answer.
- No claim here rests on an empty grep alone; where absence is asserted (`canBeExecuted` has zero callers, `isTerminal()` has zero callers, nothing sets `is_voided = true`) the search was run in more than one form.

---

*Prepared 2026-08-23 against local dev `bdf9ce5e9`. Read-only: no production file was modified. All findings are proposals — none is authorised to execute without normal lane/gate entry.*
