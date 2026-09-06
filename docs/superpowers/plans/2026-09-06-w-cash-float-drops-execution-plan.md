<!-- Rev 4, authored by Codex CLI (gpt-5.6-sol, high, read-only) on 2026-09-06 from rev 3 + plan gate r4; filed verbatim by the orchestrator. Status: awaiting plan gate r5. Rev 1 = 67c0805d4, rev 2 = 2d5268890, rev 3 = 3f32ffdd8. -->
# W-CASH float/drop execution plan — revision 4

**Plan date:** 2026-09-06  
**Repository:** `/Users/houssamr/Projects/syneriva/apps/erp`  
**Inspected branch:** `dev`  
**Inspected HEAD:** `7e14006182a1d7e281bbdc4660af85a80a2ee9fd`  
**Inspection mode:** read-only; no files, commits, branches, schema, or remote state changed.  
**Worktree preserved:** the pre-existing untracked files `apps/api/docs/sessions/2026-08-04-cross-tenant-scheduled-jobs-plan.md` and `docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md` remain outside W-CASH scope.

All repository `path:line` citations below refer to the inspected SHA.

Vocabulary: `Fiscal projection policy record` (NEW — glossary row added in T1), `Fiscal projection cutover` (NEW — glossary row added in T1), `Cash repository configuration` (NEW — glossary row added in T1), `Repository transfer document` (NEW — glossary row added in T1), `Shift cash booking obligation` (NEW — glossary row added in T1), `Z close manifest` (NEW — glossary row added in T1), `Cash-count delivery obligation` (NEW — glossary row added in T1), `Session reconciliation` (existing concept; canonical store remains `pos_session_reconciliations`), `Reconciliation run` (NEW — glossary row added in T1), `Reconciliation supersession` (NEW — glossary row added in T1), `Mutation outcome` (NEW — glossary row added in T1).

## 1. Revision-4 change log and gate disposition

Plan-line identifiers such as `PL-070` are immutable references within this document.

### 1.1 Preserved gate-r3 closure set

| # | Gate-r3 item | Revision-4 disposition |
|---:|---|---|
| 1 | B4 — v2/v3 fingerprint and cutover | **CLOSED** at PL-073 and PL-086: ordered half-open `(occurred_at, source_id)` bounds, overlap serialization, deterministic lookup, stable semantic fingerprint. |
| 2 | B5 — shared drawer/opening semantics | **CLOSED BY WITHHOLDING** at PL-021 and PL-114: no custody session, membership, join/refusal, float-owner, or close-owner branch. |
| 3 | B6 — W7 reconciliation | **CLOSED** at PL-079, PL-087, and PL-109: canonical head, immutable runs, one-current invariant, stable snapshots, and the complete spec-v4 matrix. |
| 4 | B7 — migration/deployment safety | **CLOSED** at PL-121–PL-129: executable fail-fast manifest, fleet backup IDs, per-tenant migration proof, candidate identity, Dokploy deployment, asset hash, fingerprint, and smoke. |
| 5 | M4 — convention-09 tests | **CLOSED** at PL-091–PL-112: every mutating lane has second-company, second-location, and explicit rerun outcome assertions. |
| 6 | M9 — local type census | **CLOSED** at PL-052 and PL-106: backend DTO is created, generated types are regenerated, web shadows are removed, and POS retains only a validated SQLite row projection. |
| 7 | No opening-interval algorithm | **REJECTED** at PL-021: implementing it would decide Q11. |
| 8 | Durable cutover/config revisions | **CLOSED** at PL-072–PL-074: composite ownership constraints, immutable revisions, ordered bounds, and overlap rejection. |
| 9 | Tasks lack dispatch contracts | **CLOSED** at PL-091–PL-112: exact files, signatures, tests, commands, lane, review, and rollback are specified. |
| 10 | CashCountDispatcher durability | **CLOSED** at PL-078, PL-085, and PL-108: all three obligations are inserted in both producer transactions before dispatch. |
| 11 | Manifest membership/atomicity | **CLOSED** at PL-077, PL-084, and PL-107: close/Z identities precede manifest construction in the same SQLite transaction. |
| 12 | Cross-rail fingerprint | **CLOSED** at PL-086. |
| 13 | POS/local type census | **CLOSED** at PL-052 and PL-106. |
| 14 | Provisioning hooks/atomicity | **CLOSED WITHOUT REGRESSION** at PL-083 and PL-103: the shipped best-effort/failure-contained availability contract is retained. |
| 15 | Promotion evidence | **CLOSED** at PL-121–PL-129; T12 lands in Push 3 and is first exercised by that promotion. |
| 16 | Tenant-only uniqueness ratchet | **CLOSED** at PL-081 and PL-101. |
| 17 | Convention 10 | **CLOSED** at PL-030: prescribed matrix shape, cited AutoERP cells, and valid decision vocabulary. |
| 18 | Premature Q11 branch | **CLOSED BY WITHHOLDING** at PL-021 and PL-114. |
| 19 | Task/schema contract | **CLOSED** at PL-070–PL-112. |
| 20 | Staging manifest | **CLOSED** at PL-121–PL-129. |
| 21 | Financial lock inversion | **CLOSED** at PL-060–PL-064. |
| 22 | Impossible manifest order | **CLOSED** at PL-084 and PL-107. |
| 23 | Stored-event consumer | **CLOSED** at PL-078, PL-085, and PL-108. |
| 24 | Provisioning contradiction | **CLOSED** by preserving the existing failure-contained interfaces and callers at PL-083 and PL-103. |
| 25 | Fingerprint custody boundary | **CLOSED** at PL-086: normalized shift/session identity is mandatory or explicitly absent. |
| 26 | POS generated type ownership | **CLOSED** at PL-106. |
| 27 | Explicit rerun outcomes | **CLOSED** at PL-080 and every task’s red-first contract. |
| 28 | Nonexistent paths | **CLOSED** at PL-091–PL-112: every existing production path was resolved at HEAD; all other paths are explicitly marked `CREATE`. |

### 1.2 Gate-r4 blockers

| Finding | Disposition |
|---|---|
| Blocker 1 — migration contract cannot execute against HEAD | **CLOSED** at PL-071 and PL-075–PL-079. The plan uses `projection_status`, extends the real `ProjectionStatus` enum, does not invent `fiscal_event_projections.company_id`, and keys cash-count obligations to `pos_z_reports.id`. |
| Blocker 2 — flag-true lost-obligation crash window | **CLOSED** at PL-085 and PL-108. `ReportGenerationService` and `ZReportSyncController` insert three obligations in the Z/count transaction; only delivery is queued after commit. |
| Blocker 3 — staging cannot prove the candidate | **CLOSED** at PL-121–PL-129. The exact candidate SHA, fleet backup IDs, migration transcript, deployment ID/status, API fingerprint, served asset URL/hash, and Playwright result are captured. |
| Blocker 4 — multiple current reconciliation runs | **CLOSED** at PL-079, PL-087, and PL-109. A partial unique index, locked head transition, dependency version, stable fingerprint, and ordered supersession transaction enforce one current run. |

### 1.3 Gate-r4 majors and minors

| Finding | Disposition |
|---|---|
| Major 1 — cutover ranges | **CLOSED** at PL-073 and PL-086. |
| Major 2 — money precision and enums | **CLOSED** at PL-070 and PL-072–PL-079. All new money uses `decimal(20,3)`; every state/type/code has a named backed enum and DB check. |
| Major 3 — convention 11/generated types | **CLOSED** at the Vocabulary line, PL-052, PL-081, and PL-106. |
| Major 4 — W7 matrix regression | **CLOSED** at PL-109. |
| Major 5 — provisioning regression | **CLOSED** at PL-083 and PL-103 by retaining failure containment. |
| Major 6 — entrypoint ownership | **CLOSED** at PL-111 and PL-120: one shared validator is invoked by API, worker, scheduler, and websocket before configuration caching. |
| Independent minor findings | **REJECTED:** gate r4 reported none (`docs/superpowers/reviews/2026-09-06-w-cash-plan-codex-gate-r4.md:143-145`). |

### 1.4 Rejected false positives retained

- **REJECTED:** the prior inspection-SHA difference represented planning-document drift, not implementation drift.
- **REJECTED:** Q10–Q13 were missing or paraphrased; they remain verbatim at PL-020.
- **REJECTED:** revision 4 implements Q11 join/refusal; it does not.
- **REJECTED:** `tenants:migrate-rolling --force` is invalid; HEAD defines it at `apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48-53`.
- **REJECTED:** the corrected financial lock order, close/Z order, and stored-event consumer are missing.
- **REJECTED:** a safe drop needs three repository movements; the contract remains exactly two movements and zero-or-one journal entry.
- **REJECTED:** expected cash needs a second opening-cash component; `ShiftExpectedCashService` already includes it (`apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:27-59`, `:252-299`).
- **REJECTED:** device migration order is ambiguous; v68 must finish before v69.
- **REJECTED:** historical backbooking or automatic variance activation is allowed; both remain prohibited.
- **REJECTED:** new queues are required; existing `default` and `fiscal-projections` queues suffice.

## 2. Authority, scope, and owner stops

**PL-010 — Repository contract.** Strict typing, no placeholders, JSONB through typed DTOs, generated cross-boundary types, and immutable events are mandatory (`CLAUDE.md:15-22`, `:30-40`). Money at rest is `decimal(N,3)`, never float, and transport amounts are strings (`CLAUDE.md:71-76`). POS SQLite timestamps, explicit queues, and company-context rules remain binding (`CLAUDE.md:81-87`).

**PL-011 — Governing specification.** W-CASH implements W7 from spec v4, including run history, stable membership, explicit incompleteness, independent expected totals, and the acceptance cases at `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:410-430`.

**PL-012 — Existing disabled variance path.** `PostShiftCashVarianceAdjustment` stays disabled; no push removes its guard or auto-books the disabled interval (`apps/api/app/Modules/Treasury/Application/Jobs/PostShiftCashVarianceAdjustment.php:50-56`).

**PL-013 — Default-off rule.** Every rollout flag defaults to the literal boolean `false`. No “truthy” parsing is permitted. Flags may expose dormant reads or workers but may not activate Q10–Q13 behavior.

**PL-014 — Push 5 stop.** Push 5 is prohibited until Q10–Q13 are explicitly ruled and a separately reviewed T11 supplement exists. W2 tender classification, W4 source completeness, W-LOT dependency evidence, T7 manifests, T8 durable delivery, and T9 reconciliation must also be accepted.

### Owner rulings — verbatim and OPEN

**PL-020 — The following rows are copied verbatim from `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:138-143`; every row remains OPEN.**

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

**PL-021 — Policy-neutrality rule.** Until T11 is authorized:

- No schema, enum, status, flag, endpoint, state transition, worker, UI, test fixture, seed, or command may represent or choose a recall-release outcome, drawer-session ownership, terminal join/refusal, opening-float ownership, typed `CASH_IN`/`CASH_OUT`/`DEPOSIT`/`PAYOUT` accounting destination, historical alignment, disabled-window closure, or variance activation.
- Generic evidence may record immutable raw source facts.
- A dependent reconciliation cell may say only `owner_ruling_required`.
- The sole permissible configuration lifecycle state is `inactive`.
- `SAFE_DROP` may be retained as an immutable source fact, but activation of financial interpretation remains behind Push 5/T11.
- No historical operation is backbooked.

## Industry baseline (benchmark-first — convention 10)

Flow: W-CASH float, drop, close-manifest, delivery, and session reconciliation. Reference systems: Odoo 19, ERPNext current documentation, Dolibarr TakePOS current documentation. Sources: [Odoo POS payments](https://www.odoo.com/documentation/19.0/applications/sales/point_of_sale/payment_methods.html), [Odoo register close](https://www.odoo.com/documentation/19.0/applications/sales/point_of_sale/use.html), [ERPNext POS workflows](https://docs.frappe.io/erpnext/pos-workflows), [ERPNext Mode of Payment](https://docs.frappe.io/erpnext/mode-of-payment), [Dolibarr TakePOS](https://wiki.dolibarr.org/index.php/Module_Point_of_sale_(TakePOS)).

**PL-030**

| ID | Guarantee | Odoo | ERPNext | Dolibarr/NV | AutoERP today path:line | Gap | Decision |
|---|---|---|---|---|---|---|---|
| B1 | A register close preserves the counted cash and expected/difference evidence | Register close records counted cash and payment-method differences | POS Closing Entry records closing values against opening entry | TakePOS close exists; authoritative per-tender durability not established | Z counts exist in `apps/api/database/migrations/tenant/2026_04_25_000001_create_pos_z_report_counts_table.php:14-27` | Delivery to every downstream consumer is not crash-safe | MATCH — lane T8 |
| B2 | Close evidence has stable, explicit membership rather than a time-window guess | Session close operates over the register’s session | Closing entry is tied to the opening/session workflow | NV — no authoritative immutable-member manifest found | Device close appends close/Z events in `apps/pos/src/services/zReportService.ts:541-576`, but no manifest exists | Missing immutable manifest | MATCH — lane T7 |
| B3 | Reconciliation keeps run history and exposes one current result | Register reports retain close history | Closing entries retain submitted results | NV | Spec requires canonical `pos_session_reconciliations`; no current store exists | Missing canonical head/run store | MATCH — lane T9 |
| B4 | Duplicate delivery and retry cannot double financial or compliance effects | Accounting entries are document-led and auditable | Payment and journal documents are named records | TakePOS movements are document-oriented | Treasury service has idempotency support in `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:225-268` | Cash-count fan-out can be lost between commit and dispatch | MATCH — lane T8 |
| B5 | Monetary transfer has an immutable document and balanced source/destination effects | Internal-transfer workflows preserve journal evidence | Internal Transfer uses paired accounts | NV | `RepositoryTransferService` creates GL then Treasury transfer at `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:33-101` | No canonical transfer document/group | MATCH — lane T4 |
| B6 | Concurrent corrected inputs cannot expose two current reconciliations | Register session is a single close context | Closing entry is a named current document | NV | No reconciliation head or partial uniqueness exists | Missing lock/version/current invariant | MATCH — lane T9 |
| B7 | A second company can use the same business code without data leakage | Companies are independent accounting contexts | Companies are independent accounting contexts | Multi-company capability varies | Catalogue ratchet exists at `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:52-58` | Every new editable business unique must include `company_id` | MATCH — lanes T1/T3/T4 |
| B8 | A second location binds terminals and repositories to the selected location | POS configuration is location/register specific | POS Profile and warehouse/location are explicit | TakePOS terminal setup is explicit | Terminals carry company/location at `apps/api/database/migrations/tenant/2026_01_08_190429_create_pos_terminals_table.php:20-65` | New configuration must enforce same-company ownership | MATCH — lane T3 |
| B9 | Re-running the same mutation reports an explicit semantic outcome | Document identity prevents silent duplication | Named entries preserve duplicate meaning | NV | Existing idempotency is uneven; convention 09 requires explicit outcome (`docs/conventions/09-SECOND-OF-EVERYTHING.md:37-50`) | Typed outcome missing | MATCH — all mutation lanes |
| B10 | Shared-drawer ownership is explicit before money is activated | One register/session per POS configuration | Opening entry is per user/profile | NV — no authoritative shared-drawer rule found | Shifts are terminal-bound at `apps/api/database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php:20-64` | Q11 remains OPEN | DEFER — ticket OWNER-Q11 |
| B11 | Cash-in/out accounting meaning is configured and auditable | Cash reason maps to accounting treatment | Petty cash/internal transfer uses explicit accounts | NV | Device operation type and free-text reason are stored at `apps/api/database/migrations/tenant/2026_01_08_190642_create_pos_cash_drawer_operations_table.php:20-69` | Q12 remains OPEN | DEFER — ticket OWNER-Q12 |
| B12 | Historical opening alignment is deliberate and non-retroactive | Last closing/opening and cash differences are explicit | Opening Entry/Journal Entry establishes opening balance | NV | No alignment mechanism; variance booking is disabled at `apps/api/app/Modules/Treasury/Application/Jobs/PostShiftCashVarianceAdjustment.php:50-56` | Q13 remains OPEN | DEFER — ticket OWNER-Q13 |
| B13 | Recall/hold interpretation cannot silently alter cash reconciliation | Quality status is explicitly lifted by authority | Quarantine/release is disposition-led | NV | W-CASH has no authorized recall lifecycle | Q10 remains OPEN | DEFER — ticket OWNER-Q10 |
| B14 | Unknown or reclassified tender evidence fails closed | Payment methods are explicitly configured | Mode of Payment is explicitly classified | TakePOS payment modes are configured | `ShiftExpectedCashService` throws `UnknownTenderClassificationException` at `apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:250` | Must remain part of W7 acceptance | ALREADY — retained and regression-tested in T9 |

Second-of-everything (convention 09): T1/T3/T4/T5/T7/T8/T9/T10 add real-path second-company, second-`pos_enabled`-location, and rerun/idempotency journeys. T2 adds second-company cutover isolation and concurrent duplicate/overlap tests. No raw inserts may substitute for company or location creation.

## 4. Verified HEAD census

**PL-050 — Existing durable facts.**

- `CashDrawerOperation` uses UUID identity and stores operation type, amount, user, reason, receipt, and creation time (`apps/api/app/Modules/POS/Domain/CashDrawerOperation.php:20-43`, `:112-140`).
- Its table is `pos_cash_drawer_operations`, not `cash_counts` (`apps/api/database/migrations/tenant/2026_01_08_190642_create_pos_cash_drawer_operations_table.php:20-69`).
- Cash counts are rows in `pos_z_report_counts`, keyed to `z_report_id` (`apps/api/database/migrations/tenant/2026_04_25_000001_create_pos_z_report_counts_table.php:14-27`).
- `CashCountRecorded` identifies the aggregate with `zReportId` (`apps/api/app/Modules/POS/Domain/Events/CashCountRecorded.php:13-37`).
- Fiscal projection columns are `fiscal_event_id`, `projector_name`, `projection_status`, `attempts`, error/timing fields, and timestamps; no `company_id` exists (`apps/api/database/migrations/tenant/2026_05_14_100003_create_fiscal_event_projections_table.php:30-63`).
- The current projection enum has `pending`, `running`, `applied`, and `dead_lettered` (`apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionStatus.php:7-12`).
- Server-generated Z/count facts are created inside `ReportGenerationService::generateZReport()` and currently dispatched after commit (`apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:188-205`, `:412-459`).
- Device-synced Z/count facts are committed by `ZReportSyncController` before its current dispatch (`apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:222-278`).

**PL-051 — Registration owners.** New Fiscal commands belong in `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php:47-76`; Treasury commands belong in `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:236-245`; POS commands belong in `apps/api/app/Modules/POS/Providers/POSServiceProvider.php:82-96`. No task references a nonexistent `app/Console/Kernel.php`.

**PL-052 — Cross-boundary type census.**

- Backend `PaymentRepositoryData` does not exist and must be created.
- Web shadows exist at `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts:7-20` and `apps/web/src/features/pos/api/paymentRepositoryApi.ts:9-20`.
- POS shadow exists at `apps/pos/src/types/payment.ts:27-37`.
- POS SQLite projection exists at `apps/pos/src/lib/db/repositories/paymentRepository.ts:24-35`; its unchecked conversion is at `:58-69`.
- Backend response shaping currently lives in `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:420-441`.
- Spatie TypeScript output is configured by `apps/api/config/typescript-transformer.php:17-20`, `:45-60`.
- Exact consumers in scope are:
  - `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts`
  - `apps/web/src/features/pos/api/paymentRepositoryApi.ts`
  - `apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx`
  - `apps/web/src/features/treasury/components/TransferCashModal.tsx`
  - `apps/web/src/features/treasury/RepositoryListPage.tsx`
  - `apps/web/src/features/treasury/RepositoryDetailPage.tsx`
  - `apps/web/src/features/treasury/PaymentForm.tsx`
  - `apps/web/src/features/treasury/SplitPaymentForm.tsx`
  - `apps/pos/src/types/payment.ts`
  - `apps/pos/src/lib/db/repositories/paymentRepository.ts`
  - `apps/pos/src/api/paymentApi.ts`
  - `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx`
  - `apps/pos/src/stores/paymentStore.ts`
  - `apps/pos/src/lib/sync/syncService.ts`
  - `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx`
  - `apps/pos/src/components/pos/PaymentSummary.tsx`
  - `apps/pos/src/pages/ThemePreviewPage.tsx`
  - `apps/pos/src/test/helpers.ts`

## 5. Complete writer and lock census

**PL-060 — Single Treasury write port.** All W-CASH repository balance mutations must end at `TreasuryMovementServiceInterface::record()` or `::transfer()` (`apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php:16-29`, `:57`, `:96`). The only physical movement insert and balance updates are in `TreasuryMovementService.php:554-610`, specifically the insert at `:582` and repository saves at `:606-608`.

**PL-061 — Complete known caller census.**

| Owner | Existing call |
|---|---|
| `IncomeService.php` | `record()` at line 175 |
| `ExpenseService.php` | `record()` at lines 452, 488, 816, 998 |
| `PaymentController.php` | `record()` at lines 1349, 2063 |
| `VendorRefundService.php` | `record()` at line 211 |
| `MultiPaymentService.php` | `record()` at line 602 |
| `PaymentRefundService.php` | `record()` at lines 729, 1087 |
| `OutboundInstrumentService.php` | `record()` at lines 140, 298, 452 |
| `InstrumentLifecycleService.php` | `record()` at lines 277, 495 |
| `RepositoryOpeningBalanceService.php` | `record()` at line 152 |
| `RepositoryTransferService.php` | `transfer()` at line 89 |
| `RepositoryAdjustmentService.php` | `record()` at line 234 |
| `RefundCompensationService.php` | `record()` at line 308 |
| `AcquirerFeeService.php` | `record()` at line 133 |
| `TreasuryReceiptBridge.php` | `record()` at line 1560 |
| `TreasuryDepositBridge.php` | `record()` at line 249 |
| `TreasuryAccountPaymentBridge.php` | `record()` at line 267 |

T0 must regenerate this census with `rg`; any additional production writer is a stop until added here and reviewed.

**PL-062 — Mandatory financial lock order.**

1. Tenant document/numbering lock.
2. Company GL chain lock.
3. Repository transfer document row.
4. Payment repositories in ascending UUID-byte order.
5. Movement/idempotency rows.

`GeneralLedgerService` acquires numbering/chain locks at `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3741`; `TreasuryMovementService` takes a company advisory lock and sorted repository locks at `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:225-268`.

**PL-063 — Transfer cardinality.** One transfer document/group produces exactly two repository movements and zero-or-one journal entry. It never produces a third “clearing repository” movement. Corrections are reversals linked to the original immutable document; posted documents cannot be edited or deleted.

**PL-064 — Inventory buffer ownership.** `InventoryGlPostingBuffer` belongs only to inventory posting. Its enqueue/flush behavior is at `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:13-17`, `:34`, `:56-92`. W-CASH must neither inject into it nor flush it. A regression test asserts that W-CASH transfer, delivery, and reconciliation do not call it.

## 6. Complete migration and DTO contract

**PL-070 — Universal DDL rules.**

- All migrations below are tenant migrations under `apps/api/database/migrations/tenant`.
- IDs are UUIDs, non-null primary keys with no DB-generated default; application code supplies UUIDv7.
- All `tenant_id`, `company_id`, terminal, location, shift, Z, repository, document, and parent-child identities are enforced by composite foreign keys where ownership matters.
- All FKs use `ON DELETE RESTRICT` unless an explicitly ephemeral child says otherwise.
- All timestamps are timezone-aware PostgreSQL `timestamptz`; Laravel `timestampsTz()` supplies non-null `created_at` and `updated_at`.
- All money columns are `decimal(20,3)`, non-null, and transported as strings.
- JSONB fields are non-null unless stated, use an explicit DTO, and default only to `'{}'::jsonb` or `'[]'::jsonb`.
- Every status/type/code column has a PHP string-backed enum, Eloquent cast, and named DB check.
- Every new catalogue/business unique includes `company_id`.
- T1’s PostgreSQL test introspects every column, nullability, default, FK target/delete rule, check, index, and partial predicate.

### PL-071 — Compatibility prelude

Migration `2026_09_06_090000_add_w_cash_ownership_keys.php` executes first:

- Add non-primary supporting unique indexes:
  - `pos_terminals(id, tenant_id, company_id)`
  - `pos_shifts(id, terminal_id)`
  - `pos_z_reports(id, terminal_id)`
  - `payment_repositories(id, company_id)`
  - `locations(id, company_id)`
- Add no duplicate column.
- Verify before creation that HEAD tables and columns exist; otherwise throw and abort migration.
- Add `blocked` to `ProjectionStatus`.
- Add named check `chk_fiscal_event_projections_projection_status` allowing exactly `pending`, `running`, `applied`, `dead_lettered`, `blocked`.
- Add to `fiscal_event_projections`:
  - `policy_record_id uuid NULL`
  - `claim_token uuid NULL`
  - `lease_expires_at timestamptz NULL`
  - `blocked_reason_code varchar(64) NULL`
  - `blocked_evidence jsonb NULL`, DTO `ProjectionBlockedEvidenceData`
- FK `policy_record_id → fiscal_projection_policy_records.id ON DELETE RESTRICT` is added after that table is created in the same migration.
- Checks:
  - running requires `claim_token` and `lease_expires_at`;
  - non-running requires both null;
  - blocked requires `blocked_reason_code`;
  - non-blocked requires blocked fields null.
- Index `idx_fep_delivery_scan(projection_status, lease_expires_at)` with predicate `projection_status IN ('pending','running','blocked')`.
- Existing unique `(fiscal_event_id, projector_name)` remains unchanged.

### PL-072 — Projection policy records

In `2026_09_06_090000_add_w_cash_ownership_keys.php`, create `fiscal_projection_policy_records`:

| Column | Contract |
|---|---|
| `id` | uuid PK, non-null |
| `tenant_id` | uuid, non-null |
| `company_id` | uuid, non-null, FK `companies.id`, RESTRICT |
| `projector_name` | varchar(64), non-null |
| `policy_code` | varchar(32), non-null, enum `FiscalProjectionPolicyCode`; allowed `observe_only`, `owner_ruling_required` |
| `effective_at` | timestamptz, non-null |
| `actor_id` | uuid, non-null, FK `users.id`, RESTRICT |
| `reason` | text, non-null |
| `evidence` | jsonb, non-null, default `{}`, DTO `FiscalProjectionPolicyEvidenceData` |
| `created_at`, `updated_at` | timestamptz, non-null |

Constraints/indexes:

- Check nonblank `projector_name` and `reason`.
- Check `policy_code`.
- Unique `(company_id, projector_name, effective_at, id)`.
- Index `(company_id, projector_name, effective_at DESC, id DESC)`.
- Rows are append-only; update/delete are rejected by service and trigger.

### PL-073 — Durable cutover ranges

Create `fiscal_projection_cutovers`:

| Column | Contract |
|---|---|
| `id` | uuid PK, non-null |
| `tenant_id` | uuid, non-null |
| `company_id` | uuid, non-null, FK `companies.id`, RESTRICT |
| `location_id` | uuid, non-null |
| `terminal_id` | uuid, non-null |
| `projector_name` | varchar(64), non-null |
| `source_rail` | varchar(8), non-null, enum `FiscalSourceRail`: `v2`, `v3` |
| `lower_occurred_at` | timestamptz, non-null |
| `lower_source_id` | uuid, non-null |
| `upper_occurred_at` | timestamptz, non-null |
| `upper_source_id` | uuid, non-null |
| `policy_record_id` | uuid, non-null, FK policy record, RESTRICT |
| `decision_code` | varchar(32), non-null, enum `FiscalCutoverDecisionCode`: `observe_only`, `owner_ruling_required` |
| `boundary_evidence` | jsonb, non-null, default `{}`, DTO `FiscalCutoverBoundaryEvidenceData` |
| `actor_id` | uuid, non-null, FK `users.id`, RESTRICT |
| `created_at`, `updated_at` | timestamptz, non-null |

Constraints/indexes:

- Composite FK `(terminal_id, tenant_id, company_id) → pos_terminals(id, tenant_id, company_id)`.
- Composite FK `(location_id, company_id) → locations(id, company_id)`.
- Check tuple `ROW(lower_occurred_at, lower_source_id) < ROW(upper_occurred_at, upper_source_id)`.
- Half-open membership is exactly `lower <= (occurred_at,id) < upper`.
- Unique `(company_id, location_id, terminal_id, projector_name, source_rail, lower_occurred_at, lower_source_id, upper_occurred_at, upper_source_id)`.
- Lookup index `(company_id, terminal_id, projector_name, source_rail, lower_occurred_at, lower_source_id, upper_occurred_at, upper_source_id)`.
- Creation takes a transaction-scoped advisory lock over tenant/company/location/terminal/projector/rail and rejects any row satisfying `existing.lower < requested.upper AND existing.upper > requested.lower`. A concurrent-overlap PG test is mandatory.
- v2 ordering uses `(CashDrawerOperation.created_at, CashDrawerOperation.id)`.
- v3 ordering uses `(FiscalEvent.event_time_device, FiscalEvent.id)`.
- Current time, current configuration, or ingestion order never reinterprets an existing source fact.

### PL-074 — Inactive cash repository configuration

Migration `2026_09_06_090100_create_cash_repository_configurations.php` creates:

`cash_repository_configurations`:

- `id uuid PK NOT NULL`
- `tenant_id uuid NOT NULL`
- `company_id uuid NOT NULL`, FK companies RESTRICT
- `location_id uuid NOT NULL`
- `drawer_repository_id uuid NOT NULL`
- `safe_repository_id uuid NOT NULL`
- `state varchar(16) NOT NULL DEFAULT 'inactive'`, enum `CashRepositoryConfigurationState`, only `inactive`
- `current_revision_id uuid NULL`
- `created_by uuid NOT NULL`, FK users RESTRICT
- `created_at`, `updated_at timestamptz NOT NULL`
- Composite FKs for location and both repositories enforce the same company.
- Check drawer and safe repository IDs differ.
- Check state equals `inactive`.
- Unique `(company_id, location_id)`.
- Index `(company_id, state)`.

`cash_repository_configuration_revisions`:

- `id uuid PK NOT NULL`
- `tenant_id uuid NOT NULL`
- `company_id uuid NOT NULL`
- `configuration_id uuid NOT NULL`
- `revision_number bigint NOT NULL`
- `drawer_repository_id uuid NOT NULL`
- `safe_repository_id uuid NOT NULL`
- `state varchar(16) NOT NULL DEFAULT 'inactive'`, same enum/check
- `evidence jsonb NOT NULL DEFAULT '{}'`, DTO `CashRepositoryConfigurationRevisionData`
- `created_by uuid NOT NULL`, FK users RESTRICT
- `created_at`, `updated_at timestamptz NOT NULL`
- Composite FK `(configuration_id, company_id) → cash_repository_configurations(id, company_id)`.
- Composite repository FKs enforce same company.
- Unique `(company_id, configuration_id, revision_number)`.
- Unique support `(id, company_id)`.
- Index `(company_id, configuration_id, created_at DESC)`.
- Revisions are append-only; configuration `current_revision_id` references a revision of the same configuration/company.
- No terminal membership, drawer session, opening ownership, or activation state exists.

### PL-075 — Repository transfer documents

Migration `2026_09_06_090200_create_repository_transfer_documents.php` creates `repository_transfer_documents`:

- `id uuid PK NOT NULL`
- `tenant_id uuid NOT NULL`
- `company_id uuid NOT NULL`, FK companies RESTRICT
- `location_id uuid NOT NULL`
- `source_repository_id uuid NOT NULL`
- `destination_repository_id uuid NOT NULL`
- `amount decimal(20,3) NOT NULL`
- `currency_code char(3) NOT NULL`
- `source_fact_type varchar(32) NOT NULL`, enum `RepositoryTransferSourceFactType`: `safe_drop`, `manual_transfer`, `reversal`
- `source_fact_id uuid NOT NULL`
- `effective_at timestamptz NOT NULL`
- `idempotency_key varchar(128) NOT NULL`
- `status varchar(16) NOT NULL DEFAULT 'draft'`, enum `RepositoryTransferStatus`: `draft`, `posted`, `reversed`, `conflict`
- `journal_entry_id uuid NULL`, FK journal entries RESTRICT
- `reverses_document_id uuid NULL`
- `posted_at timestamptz NULL`
- `reversed_at timestamptz NULL`
- `created_by uuid NOT NULL`, FK users RESTRICT
- `evidence jsonb NOT NULL DEFAULT '{}'`, DTO `RepositoryTransferEvidenceData`
- `created_at`, `updated_at timestamptz NOT NULL`

Constraints/indexes:

- Composite FKs enforce company ownership for location and both repositories.
- Composite self-FK `(reverses_document_id, company_id)`.
- Check source and destination differ, amount `> 0`, uppercase ISO currency.
- Draft requires no posted/reversed time; posted requires `posted_at`; reversed requires both times and reversal link.
- Unique `(company_id, idempotency_key)`.
- Unique `(company_id, source_fact_type, source_fact_id)`.
- Unique support `(id, company_id)`.
- Index `(company_id, status, effective_at, id)`.

Create `repository_transfer_alerts`:

- `id uuid PK NOT NULL`
- `tenant_id uuid NOT NULL`
- `company_id uuid NOT NULL`
- `transfer_document_id uuid NOT NULL`
- `alert_kind varchar(32) NOT NULL`, enum `RepositoryTransferAlertKind`: `configuration_missing`, `currency_mismatch`, `source_conflict`, `posting_failed`
- `state varchar(16) NOT NULL DEFAULT 'open'`, enum `RepositoryTransferAlertState`: `open`, `resolved`
- `evidence jsonb NOT NULL DEFAULT '{}'`, DTO `RepositoryTransferAlertEvidenceData`
- `resolved_by uuid NULL`, FK users RESTRICT
- `resolved_at timestamptz NULL`
- `created_at`, `updated_at timestamptz NOT NULL`
- Composite FK document/company.
- Resolution-state consistency check.
- Unique `(company_id, transfer_document_id, alert_kind)`.
- Index `(company_id, state, created_at)`.

Add to `repository_movements`:

- `transfer_document_id uuid NULL`
- `transfer_leg varchar(16) NULL`, enum `RepositoryTransferLeg`: `source`, `destination`
- Composite FK `(transfer_document_id, company_id)`.
- Check both transfer fields are null or both non-null.
- Unique partial `(company_id, transfer_document_id, transfer_leg) WHERE transfer_document_id IS NOT NULL`.

### PL-076 — Shift cash booking obligations

Migration `2026_09_06_090300_create_shift_cash_booking_obligations.php` creates:

- `id uuid PK NOT NULL`
- `tenant_id uuid NOT NULL`
- `company_id uuid NOT NULL`
- `location_id uuid NOT NULL`
- `terminal_id uuid NOT NULL`
- `shift_id uuid NOT NULL`
- `source_rail varchar(8) NOT NULL`, enum `FiscalSourceRail`
- `source_fact_id uuid NOT NULL`
- `source_occurred_at timestamptz NOT NULL`
- `source_kind varchar(32) NOT NULL`, enum `ShiftCashSourceKind`: existing raw `opening_float`, `cash_in`, `cash_out`, `safe_drop`, `deposit`, `payout`
- `amount decimal(20,3) NOT NULL`
- `currency_code char(3) NOT NULL`
- `semantic_fingerprint char(64) NOT NULL`
- `raw_evidence jsonb NOT NULL`, DTO `ShiftCashSourceEvidenceData`
- `configuration_revision_id uuid NULL`
- `cutover_id uuid NOT NULL`
- `state varchar(24) NOT NULL DEFAULT 'pending'`, enum `ShiftCashBookingState`: `pending`, `blocked`, `applied`, `already_exists`, `conflict`
- `block_reason_code varchar(64) NULL`, enum `ShiftCashBlockReasonCode`: `owner_ruling_required`, `configuration_unavailable`, `source_incomplete`
- `transfer_document_id uuid NULL`
- `attempts integer NOT NULL DEFAULT 0`
- `claim_token uuid NULL`
- `lease_expires_at timestamptz NULL`
- `last_error jsonb NULL`, DTO `ShiftCashBookingErrorData`
- `completed_at timestamptz NULL`
- `created_at`, `updated_at timestamptz NOT NULL`

Constraints/indexes:

- Composite terminal/company, shift/terminal, location/company, configuration/company, cutover/company, and transfer-document/company FKs.
- Amount is nonnegative; currency/fingerprint format checks.
- Unique `(company_id, source_rail, source_fact_id)`.
- Unique `(company_id, semantic_fingerprint)`.
- Index `(company_id, state, lease_expires_at)`.
- State/claim/block/result consistency checks.
- At this revision all Q11–Q13-dependent facts terminate as `blocked/owner_ruling_required`; no financial posting is activated.

### PL-077 — Z close manifests

Migration `2026_09_06_090400_create_pos_z_close_manifests.php` creates server `pos_z_close_manifests`:

- `id uuid PK NOT NULL`
- `tenant_id uuid NOT NULL`
- `company_id uuid NOT NULL`
- `terminal_id uuid NOT NULL`
- `shift_id uuid NOT NULL`
- `z_report_id uuid NOT NULL`
- `schema_version smallint NOT NULL DEFAULT 1`
- `close_event_id uuid NOT NULL`
- `close_event_hash char(64) NOT NULL`
- `z_event_id uuid NOT NULL`
- `z_event_hash char(64) NOT NULL`
- `lower_occurred_at timestamptz NOT NULL`
- `lower_member_id uuid NOT NULL`
- `upper_occurred_at timestamptz NOT NULL`
- `upper_member_id uuid NOT NULL`
- `member_count integer NOT NULL`
- `ordered_members jsonb NOT NULL`, DTO `ZCloseManifestMemberListData`
- `canonical_payload jsonb NOT NULL`, DTO `ZCloseManifestData`
- `manifest_hash char(64) NOT NULL`
- `ingestion_state varchar(16) NOT NULL DEFAULT 'accepted'`, enum `ZCloseManifestIngestionState`: `accepted`, `duplicate`, `conflict`
- `created_at`, `updated_at timestamptz NOT NULL`

Each ordered member contains exactly: `namespace`, `source_id`, `event_type`, `occurred_at`, `sequence`, `event_hash`, `economic_payload_hash`. Namespaces are `fiscal_event`, `receipt`, `payment`, `cash_operation`, `z_count`. Sorting is bytewise by namespace, occurred-at UTC string, sequence with explicit null sentinel, then UUID bytes. Hashing is SHA-256 over RFC 8785 canonical JSON, schema version included.

Constraints/indexes:

- Composite terminal/company, shift/terminal, and Z/terminal FKs.
- Half-open tuple bounds check.
- `member_count >= 2`.
- JSON arrays/objects and SHA-256 checks.
- Unique `(company_id, z_report_id)`.
- Unique `(company_id, manifest_hash)`.
- Index `(company_id, terminal_id, lower_occurred_at, upper_occurred_at)`.

SQLite v68 in `apps/pos/src/lib/db/migrations.ts` creates `z_close_manifests` with TEXT UUID/hash/time/JSON columns mirroring all server fields, INTEGER version/count, `sync_state TEXT NOT NULL DEFAULT 'pending' CHECK IN ('pending','synced','conflict')`, unique `z_report_id`, unique `manifest_hash`, and scan index `(sync_state, created_at)`.

SQLite v69 creates `z_close_manifest_outbox`:

- `id TEXT PRIMARY KEY NOT NULL`
- `manifest_id TEXT NOT NULL REFERENCES z_close_manifests(id) ON DELETE RESTRICT`
- `state TEXT NOT NULL DEFAULT 'pending' CHECK IN ('pending','sending','sent','failed')`
- `attempts INTEGER NOT NULL DEFAULT 0`
- `claim_token TEXT NULL`
- `lease_expires_at TEXT NULL`
- `last_error_json TEXT NULL`
- `created_at TEXT NOT NULL`
- `updated_at TEXT NOT NULL`
- Unique `manifest_id`; index `(state, lease_expires_at)`.

v68 must commit successfully before v69 runs.

### PL-078 — Cash-count delivery obligations

Migration `2026_09_06_090500_create_cash_count_delivery_obligations.php` creates:

- `id uuid PK NOT NULL`
- `tenant_id uuid NOT NULL`
- `company_id uuid NOT NULL`
- `terminal_id uuid NOT NULL`
- `z_report_id uuid NOT NULL`
- `consumer varchar(24) NOT NULL`, enum `CashCountConsumer`: `treasury`, `compliance`, `stored_event`
- `event_payload jsonb NOT NULL`, DTO `CashCountRecordedData`
- `state varchar(16) NOT NULL DEFAULT 'pending'`, enum `CashCountDeliveryState`: `pending`, `running`, `applied`, `already_exists`, `blocked`, `failed`
- `attempts integer NOT NULL DEFAULT 0`
- `claim_token uuid NULL`
- `lease_expires_at timestamptz NULL`
- `last_error jsonb NULL`, DTO `CashCountDeliveryErrorData`
- `outcome jsonb NULL`, DTO `CashCountConsumerOutcomeData`
- `completed_at timestamptz NULL`
- `created_at`, `updated_at timestamptz NOT NULL`

Constraints/indexes:

- Composite FK `(z_report_id, terminal_id) → pos_z_reports(id, terminal_id)`.
- Composite FK `(terminal_id, tenant_id, company_id)`.
- Unique `(company_id, z_report_id, consumer)`.
- Index `(company_id, consumer, state, lease_expires_at)`.
- Running requires claim/lease; completed states require typed outcome/completed time; pending/failed cannot claim to be completed.
- There is no `cash_count_id` and no reference to a nonexistent table.

### PL-079 — Canonical session reconciliation

Migration `2026_09_06_090600_create_pos_session_reconciliations.php` creates the canonical head `pos_session_reconciliations`:

- `id uuid PK NOT NULL`
- `tenant_id uuid NOT NULL`
- `company_id uuid NOT NULL`
- `terminal_id uuid NOT NULL`
- `shift_id uuid NOT NULL`
- `state varchar(20) NOT NULL DEFAULT 'pending'`, enum `SessionReconciliationState`: `pending`, `running`, `current`, `blocked`, `failed`
- `dependency_version bigint NOT NULL DEFAULT 0`
- `current_run_id uuid NULL`
- `claim_token uuid NULL`
- `lease_expires_at timestamptz NULL`
- `attempts integer NOT NULL DEFAULT 0`
- `block_reason_code varchar(64) NULL`, enum `SessionReconciliationBlockReason`: `owner_ruling_required`, `manifest_incomplete`, `projection_incomplete`, `dependency_unavailable`
- `last_error jsonb NULL`, DTO `SessionReconciliationErrorData`
- `created_at`, `updated_at timestamptz NOT NULL`
- Composite terminal/company and shift/terminal FKs.
- Unique `(company_id, shift_id)`.
- Unique support `(id, company_id)`.
- State/claim/block/current-run consistency checks.
- Index `(company_id, state, lease_expires_at)`.

Create `pos_session_reconciliation_runs`:

- `id uuid PK NOT NULL`
- `tenant_id uuid NOT NULL`
- `company_id uuid NOT NULL`
- `reconciliation_id uuid NOT NULL`
- `run_number bigint NOT NULL`
- `captured_dependency_version bigint NOT NULL`
- `input_fingerprint char(64) NOT NULL`
- `input_snapshot jsonb NOT NULL`, DTO `SessionReconciliationInputData`
- `result_code varchar(24) NOT NULL`, enum `SessionReconciliationResultCode`: `not_evaluated`, `incomplete`, `blocked`, `matched`, `mismatch`
- `result_cells jsonb NOT NULL`, DTO `SessionReconciliationResultData`
- `is_current boolean NOT NULL DEFAULT false`
- `created_at`, `updated_at timestamptz NOT NULL`
- Composite FK `(reconciliation_id, company_id)`.
- Unique `(company_id, reconciliation_id, run_number)`.
- Unique `(company_id, reconciliation_id, input_fingerprint)`.
- Unique partial `(company_id, reconciliation_id) WHERE is_current = true`.
- Result-code and JSON/hash checks.
- Index `(company_id, reconciliation_id, created_at DESC)`.

Create `pos_session_reconciliation_supersessions`:

- `id uuid PK NOT NULL`
- `tenant_id uuid NOT NULL`
- `company_id uuid NOT NULL`
- `reconciliation_id uuid NOT NULL`
- `superseded_run_id uuid NOT NULL`
- `replacement_run_id uuid NOT NULL`
- `reason_code varchar(32) NOT NULL`, enum `ReconciliationSupersessionReason`: `dependency_changed`, `corrected_input`, `late_member`
- `dependency_version bigint NOT NULL`
- `created_at`, `updated_at timestamptz NOT NULL`
- Composite FKs for reconciliation and both runs enforce the same company/parent.
- Check old and replacement differ.
- Unique `(company_id, superseded_run_id)`.
- Unique `(company_id, replacement_run_id)`.
- Index `(company_id, reconciliation_id, created_at)`.

Create `pos_session_reconciliation_dependency_changes`:

- `id uuid PK NOT NULL`
- `tenant_id uuid NOT NULL`
- `company_id uuid NOT NULL`
- `reconciliation_id uuid NOT NULL`
- `dependency_version bigint NOT NULL`
- `dependency_kind varchar(32) NOT NULL`, enum `ReconciliationDependencyKind`: `manifest`, `fiscal_projection`, `z_report`, `z_count`, `tender_classification`, `lot_evidence`, `repository_evidence`
- `dependency_identity varchar(160) NOT NULL`
- `dependency_hash char(64) NOT NULL`
- `occurred_at timestamptz NOT NULL`
- `created_at`, `updated_at timestamptz NOT NULL`
- Composite reconciliation/company FK.
- Unique `(company_id, reconciliation_id, dependency_version)`.
- Index `(company_id, reconciliation_id, occurred_at, id)`.

After both tables exist, `pos_session_reconciliations.current_run_id` receives a composite FK to a run of the same company and reconciliation.

## 7. State machines and service contracts

**PL-080 — Common mutation outcome.**

```php
enum MutationOutcome: string
{
    case Applied = 'applied';
    case AlreadyExists = 'already_exists';
    case Skipped = 'skipped';
    case Blocked = 'blocked';
    case Conflict = 'conflict';
}
```

```php
final readonly class MutationResultData extends Data
{
    public function __construct(
        public MutationOutcome $outcome,
        public string $aggregateType,
        public string $aggregateId,
        public ?string $reasonCode,
        public array $evidence,
    ) {}
}
```

Every mutator returns this DTO or a more specific DTO containing the same outcome. A rerun may never return silent success.

**PL-081 — Convention-11 ownership.**

- Glossary updates occur in `docs/glossary.md`.
- Each concept has one primary writer:
  - policy/cutover: `FiscalProjectionPolicyService`
  - inactive configuration: `CashRepositoryConfigurationService`
  - transfer document: `RepositoryTransferDocumentService`
  - booking obligation: `ShiftCashBookingService`
  - Z manifest: device `ZCloseManifestBuilder`, server `ZCloseManifestIngestor`
  - count delivery: `CashCountDispatcher`
  - reconciliation: `SessionCashReconciliationService`
- The canonical W7 surface/store is `pos_session_reconciliations`; runs, supersessions, and dependency changes are its append-only children.
- `PaymentRepositoryData` is the sole cross-boundary repository type.
- `TenantOnlyUniqueOnCatalogueTablesRatchetTest` and its scanner remain the mechanical company-scope guard.

**PL-082 — Policy/cutover state.** Policy records and cutovers are immutable. Exact match returns `already_exists`; same identity with different evidence returns `conflict`; overlapping range returns `conflict`. Recovery may retry only expired projection leases.

**PL-083 — Provisioning.** Existing availability behavior is preserved:

- `CompanyPaymentRepositoryProvisionerInterface::provisionForCompany(...): void` stays failure-contained (`apps/api/app/Shared/Contracts/Treasury/CompanyPaymentRepositoryProvisionerInterface.php:45-49`).
- `LocationCashRegisterProvisionerInterface` keeps its nullable result (`apps/api/app/Shared/Contracts/Treasury/LocationCashRegisterProvisionerInterface.php:49-63`).
- `PaymentRepositoryProvisioningService` continues to catch/log provisioning failures (`apps/api/app/Modules/Treasury/Application/Services/PaymentRepositoryProvisioningService.php:32-81`).
- `LocationController` remains best-effort (`apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php:227-250`).
- When repository provisioning succeeds, creating the inactive W-CASH configuration is attempted through a separate savepoint. Failure is logged with company/location IDs and does not abort company/location creation.
- No interface signature becomes fatal.

**PL-084 — Manifest state.** Within one SQLite transaction: write local Z/counts; append close and Z fiscal events; receive their real IDs/hashes; assemble ordered membership; persist manifest; persist manifest outbox; advance chain/totals; commit. Any fault rolls back all steps. Server ingest returns `applied`, `already_exists`, or `conflict`.

**PL-085 — Cash-count delivery.**

```php
public function persistObligations(CashCountRecorded $event): CashCountDispatchSeedResultData;
public function afterCommit(CashCountRecorded $event): CashCountDispatchResultData;
public function deliver(string $obligationId, string $claimToken): CashCountConsumerOutcomeData;
public function recoverExpired(int $limit, int $leaseSeconds): CashCountRecoveryResultData;
```

- With the flag false, `afterCommit()` retains existing `Event::dispatch()`.
- With the flag true, each producer calls `persistObligations()` inside its Z/count transaction. It inserts `treasury`, `compliance`, and `stored_event` rows before commit.
- `afterCommit()` only enqueues existing IDs; it never creates obligations.
- Delivery locks one obligation, sets a lease, invokes exactly one typed consumer, and records the typed outcome in the same transaction.
- Treasury and Compliance use their existing idempotent identities.
- `stored_event` invokes Spatie `EventSubscriber::storeEvent()` inside the obligation transaction; a crash rolls back both stored-event persistence and `applied`.
- A crash before commit leaves neither Z nor obligations; after commit leaves durable pending obligations; during delivery leaves rollback or a durable completed outcome.
- Redelivery returns `already_exists` without repeating side effects.

**PL-086 — Cross-rail fingerprint.** SHA-256 over RFC 8785 canonical JSON containing: schema version, tenant, company, location, terminal, normalized shift identity or explicit `absent`, normalized session identity or explicit `absent`, source rail, source ID, source occurred-at, raw source kind, amount string, currency, policy-record ID, cutover ID, configuration-revision ID or explicit `absent`. Raw evidence is stored separately and cannot be substituted for the semantic fingerprint.

**PL-087 — Reconciliation concurrency algorithm.**

1. Every dependency writer calls `ReconciliationDependencyVersionService::touch()` inside the same transaction as the dependency change.
2. `touch()` locks the head `FOR UPDATE`, increments `dependency_version`, and appends exactly one dependency-change row.
3. A worker locks the head `FOR UPDATE`, captures version `V`, and builds immutable input DTOs in deterministic order.
4. It independently derives expected tax/tender/cash values; it does not trust sealed headline totals.
5. Before publication it re-locks/re-reads the head. If the version differs from `V`, the attempt is discarded and retried.
6. It canonicalizes the input DTO and calculates `input_fingerprint`.
7. If that fingerprint already exists, return `already_exists` and make no new run.
8. In one transaction: set the former current run `is_current=false`; insert the replacement with `is_current=true`; append its supersession; update `current_run_id` and state.
9. The unique partial index makes a second current run impossible even if application locking regresses.
10. No anti-join defines “current.”
11. The snapshot includes manifest identity/hash/version and ordered members; fiscal member IDs/hashes/projection status; immutable Z/count identity and economic hashes; tender-classification revision or `dependency_unavailable`; lot-evidence hash/availability only; repository-evidence revision/availability only. It does not encode any Q10–Q13 decision.

## 8. Complete new CLI signatures

**PL-090**

```text
w-cash:audit
  {--company= : Optional company UUID}
  {--format=table : table|json}
  {--fail-on=error : none|warning|error}
```

```text
fiscal:projection-policy:record
  {company : Company UUID}
  {projector : Projector name}
  {policy : observe_only|owner_ruling_required}
  {--effective-at= : Required ISO-8601 timestamp}
  {--actor= : Required user UUID}
  {--reason= : Required append-only reason}
  {--evidence-json={} : Typed evidence JSON}
  {--format=table : table|json}
```

```text
fiscal:projection-cutover:add
  {company : Company UUID}
  {location : Location UUID}
  {terminal : Terminal UUID}
  {projector : Projector name}
  {rail : v2|v3}
  {--lower-occurred-at= : Required inclusive ISO-8601 lower timestamp}
  {--lower-source-id= : Required inclusive UUID lower tie-breaker}
  {--upper-occurred-at= : Required exclusive ISO-8601 upper timestamp}
  {--upper-source-id= : Required exclusive UUID upper tie-breaker}
  {--policy-record= : Required policy-record UUID}
  {--decision= : observe_only|owner_ruling_required}
  {--actor= : Required user UUID}
  {--evidence-json={} : Typed boundary evidence}
  {--format=table : table|json}
```

```text
treasury:cash-config:create
  {company : Company UUID}
  {location : Location UUID}
  {drawer-repository : Repository UUID}
  {safe-repository : Repository UUID}
  {--actor= : Required user UUID}
  {--evidence-json={} : Typed revision evidence}
  {--format=table : table|json}
```

This command can create only `inactive`.

```text
treasury:repository-transfer:replay
  {document : Transfer document UUID}
  {--actor= : Required user UUID}
  {--format=table : table|json}
```

```text
pos:cash-count-deliver
  {--obligation= : Optional obligation UUID}
  {--limit=100 : Maximum claims}
  {--lease-seconds=120 : Positive lease duration}
  {--format=table : table|json}
```

```text
pos:cash-reconcile
  {company : Company UUID}
  {shift : POS shift UUID}
  {--expected-dependency-version= : Optional optimistic version}
  {--format=table : table|json}
```

```text
w-cash:fingerprint
  {company : Company UUID}
  {shift : POS shift UUID}
  {--format=json : json only}
```

Existing backup command is extended compatibly to:

```text
tenant:backup
  {slug? : The tenant slug to back up; omit with --all}
  {--all : Back up every tenant}
  {--format=table : table|json}
```

JSON returns every `tenant_id`, `tenant_slug`, `backup_id`, `status`, `file_path`, `size_bytes`, and `sha256`. Existing table output remains the default.

## 9. Task dispatch contracts

### PL-091 — T0: capability-aware census

**Production files**

- MODIFY `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`
- CREATE `apps/api/app/Modules/Treasury/Presentation/Console/WCashAuditCommand.php`
- CREATE `apps/api/app/Modules/Treasury/Application/Services/WCashAuditService.php`
- CREATE `apps/api/app/Modules/Treasury/Application/Data/WCashAuditResultData.php`

**Signatures**

```php
public function audit(?string $companyId): WCashAuditResultData;
public function handle(WCashAuditService $audit): int;
```

Audit output includes actual tables/columns/enums/indexes, writer census, direct balance writes, lock sites, DTO shadows, provider owners, rollout flags, active tenants, companies, locations, terminals, and repository topology.

**Red first**

- File: `apps/api/tests/Feature/Treasury/WCashAuditCommandTest.php`
- `WCashAuditCommandTest::test_json_census_names_head_projection_and_z_count_contract`
- First failing assertion: `assertSame('projection_status', $json['projection']['state_column'])`
- `::test_second_company_and_location_are_reported_independently`
- First failing assertion: company B contains its own selected location/terminal and no company-A ID.
- `::test_rerun_returns_identical_fingerprint_and_explicit_already_exists`
- First failing assertion: outcome equals `already_exists`.
- Command/lane: `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Treasury/WCashAuditCommandTest.php` — **phpunit PG**.

**Reviewer gate:** Treasury + tenancy architecture.  
**Rollback:** remove command/service/provider registration; no data exists.

### PL-101 — T1: schema, glossary, and SQLite v68→v69

**Production files**

- CREATE all seven tenant migrations named in PL-071–PL-079.
- MODIFY `apps/pos/src/lib/db/migrations.ts`
- MODIFY `docs/glossary.md`
- MODIFY `apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionStatus.php`
- CREATE every enum and DTO named in PL-071–PL-079 under its owning module’s `Domain/Enums` or `Application/Data` directory.
- MODIFY `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php` only if the canonical catalogue table constant must include the new editable configuration.
- Existing scanner: `apps/api/tests/Architecture/Support/TenantOnlyUniqueIndexScanner.php`.

**Red first**

- `apps/api/tests/Feature/Treasury/WCashSchemaContractTest.php`
- `WCashSchemaContractTest::test_every_w_cash_column_fk_check_index_enum_and_delete_rule_matches_contract`
- First failing assertion: PostgreSQL catalog contains `fiscal_event_projections.projection_status` and does not contain proposed `status` or `company_id`.
- `::test_every_business_unique_contains_company_id`
- First failing assertion: scanner returns `[]`.
- `::test_second_company_and_second_location_accept_same_configuration_shape_without_cross_visibility`
- First failing assertion: company B query returns only B.
- `::test_migrations_rerun_with_explicit_already_exists_schema_outcome`
- First failing assertion: schema fingerprint before/after rerun is identical and result is `already_exists`.
- Command/lane: `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Treasury/WCashSchemaContractTest.php` — **phpunit PG**.

- `apps/pos/src/lib/db/__tests__/migrations.v68.test.ts`
- First method: `MigrationV68Test::creates_complete_z_close_manifest_contract`
- First failing assertion: `expect(columns).toContain('economic_payload_hash')`.
- `apps/pos/src/lib/db/__tests__/migrations.v69.test.ts`
- First method: `MigrationV69Test::requires_v68_and_is_idempotent`
- First failing assertion: v69 before v68 rejects; v68→v69 twice yields one table/index set.
- Command/lane: `pnpm --filter @autoerp/pos exec vitest run src/lib/db/__tests__/migrations.v68.test.ts src/lib/db/__tests__/migrations.v69.test.ts` — **vitest**.

**Reviewer gate:** database + Treasury + Fiscal/POS + convention-09/11.  
**Rollback:** flags remain false; reverse only empty/unreferenced tables. Once evidence rows exist, retain tables and deploy compatibility readers.

### PL-102 — T2: immutable event-time policy and cutovers

**Production files**

- MODIFY `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventProjectionRow.php`
- MODIFY `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php`
- MODIFY `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php`
- MODIFY `apps/api/app/Modules/Fiscal/Application/Services/FiscalProjectionDispatcher.php`
- MODIFY `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php`
- CREATE:
  - `apps/api/app/Modules/Fiscal/Domain/Models/FiscalProjectionPolicyRecord.php`
  - `apps/api/app/Modules/Fiscal/Domain/Models/FiscalProjectionCutover.php`
  - `apps/api/app/Modules/Fiscal/Application/Services/FiscalProjectionPolicyService.php`
  - `apps/api/app/Modules/Fiscal/Application/Services/FiscalCutoverRangeService.php`
  - `apps/api/app/Modules/Fiscal/Presentation/Console/RecordFiscalProjectionPolicyCommand.php`
  - `apps/api/app/Modules/Fiscal/Presentation/Console/AddFiscalProjectionCutoverCommand.php`
  - `apps/api/app/Modules/Fiscal/Presentation/Console/RecoverFiscalProjectionLeasesCommand.php`

**Signatures**

```php
public function record(FiscalProjectionPolicyInputData $input): MutationResultData;
public function add(FiscalCutoverInputData $input): MutationResultData;
public function resolve(FiscalSourceCoordinateData $coordinate): FiscalCutoverResolutionData;
public function recoverExpired(int $limit, int $leaseSeconds): FiscalProjectionRecoveryResultData;
```

**Red first**

- `apps/api/tests/Feature/Fiscal/FiscalCutoverRangeTest.php`
- `::test_uuid_sources_use_ordered_time_and_id_half_open_bounds`
- First failing assertion: upper-bound coordinate does not match the preceding cutover.
- `::test_concurrent_overlapping_cutovers_yield_one_applied_and_one_conflict`
- First failing assertion: exactly one row commits.
- `::test_second_company_and_location_do_not_share_cutovers`
- First failing assertion: B resolves only B’s row.
- `::test_exact_rerun_is_already_exists_but_changed_evidence_conflicts`
- First failing assertion: second outcome is `already_exists`.
- Command/lane: `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Fiscal/FiscalCutoverRangeTest.php` — **phpunit PG**.

**Reviewer gate:** Fiscal + PostgreSQL concurrency + tenancy.  
**Rollback:** disable flag; retain immutable policy/cutover evidence.

### PL-103 — T3: inactive configuration and failure-contained provisioning

**Production files**

- MODIFY `apps/api/app/Modules/Treasury/Application/Services/PaymentRepositoryProvisioningService.php`
- MODIFY `apps/api/app/Modules/Treasury/Application/Services/LocationCashRegisterProvisioner.php`
- MODIFY `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`
- CREATE:
  - `apps/api/app/Modules/Treasury/Domain/Models/CashRepositoryConfiguration.php`
  - `apps/api/app/Modules/Treasury/Domain/Models/CashRepositoryConfigurationRevision.php`
  - `apps/api/app/Modules/Treasury/Application/Services/CashRepositoryConfigurationService.php`
  - `apps/api/app/Modules/Treasury/Presentation/Console/CreateCashRepositoryConfigurationCommand.php`

No change to either provisioning interface signature.

**Signature**

```php
public function createInactive(CashRepositoryConfigurationInputData $input): MutationResultData;
```

**Red first**

- `apps/api/tests/Feature/Treasury/CashRepositoryConfigurationProvisioningTest.php`
- `::test_company_creation_survives_repository_or_inactive_configuration_failure`
- First failing assertion: company persists despite injected W-CASH failure.
- `::test_second_company_and_selected_second_location_receive_only_their_inactive_configuration`
- First failing assertion: B/location-2 lookup excludes A/location-1.
- `::test_rerun_returns_already_exists_without_duplicate_repositories_or_balance_change`
- First failing assertion: outcome `already_exists` and balances unchanged.
- `::test_only_inactive_state_is_accepted`
- First failing assertion: `active` throws before write.
- Command/lane: `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Treasury/CashRepositoryConfigurationProvisioningTest.php` — **phpunit PG**.

**Reviewer gate:** Treasury + company/location owners + availability-contract reviewer.  
**Rollback:** disable creation hook; retain inactive rows; company/location availability remains unchanged.

### PL-104 — T4: immutable transfer documents and lock order

**Production files**

- MODIFY `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php`
- MODIFY `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php`
- CREATE:
  - `apps/api/app/Modules/Treasury/Domain/Models/RepositoryTransferDocument.php`
  - `apps/api/app/Modules/Treasury/Domain/Models/RepositoryTransferAlert.php`
  - `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferDocumentService.php`
  - `apps/api/app/Modules/Treasury/Application/Data/RepositoryTransferInputData.php`
  - `apps/api/app/Modules/Treasury/Application/Data/RepositoryTransferResultData.php`
  - `apps/api/app/Modules/Treasury/Presentation/Console/ReplayRepositoryTransferCommand.php`

**Signatures**

```php
public function post(RepositoryTransferInputData $input): RepositoryTransferResultData;
public function reverse(string $documentId, string $actorId, string $reason): RepositoryTransferResultData;
public function replay(string $documentId, string $actorId): RepositoryTransferResultData;
```

**Red first**

- `apps/api/tests/Feature/Treasury/RepositoryTransferDocumentTest.php`
- `::test_post_creates_one_document_two_movements_and_zero_or_one_je`
- First failing assertion: movement legs equal exactly `['source','destination']`.
- `::test_opposite_direction_transfers_and_unrelated_je_do_not_deadlock`
- First failing assertion: both PG workers complete within timeout.
- `::test_posted_document_is_append_only_and_corrected_only_by_reversal`
- First failing assertion: edit/delete throws domain exception.
- `::test_second_company_and_second_location_are_isolated`
- First failing assertion: B document cannot reference A repository.
- `::test_rerun_returns_already_exists_without_balance_change`
- First failing assertion: second outcome `already_exists`.
- `::test_inventory_gl_posting_buffer_is_never_called`
- First failing assertion: buffer mock has zero interactions.
- Command/lane: `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Treasury/RepositoryTransferDocumentTest.php` — **phpunit PG**.

**Reviewer gate:** Treasury + Accounting/GL + concurrency.  
**Rollback:** turn off callers; retain/reverse posted documents, never delete them.

### PL-105 — T5: policy-neutral v2/v3 evidence bridge

**Production files**

- MODIFY `apps/api/app/Modules/POS/Domain/CashDrawerOperation.php`
- MODIFY `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php`
- MODIFY `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php`
- CREATE:
  - `apps/api/app/Modules/Treasury/Domain/Models/ShiftCashBookingObligation.php`
  - `apps/api/app/Modules/Treasury/Application/Services/ShiftCashBookingService.php`
  - `apps/api/app/Modules/Treasury/Application/Services/ShiftCashSemanticFingerprint.php`
  - `apps/api/app/Modules/Treasury/Application/Data/ShiftCashSourceFactData.php`
  - `apps/api/app/Modules/Treasury/Application/Jobs/ProcessShiftCashBookingObligation.php`

**Signatures**

```php
public function observeV2(CashDrawerOperation $operation): MutationResultData;
public function observeV3(FiscalEvent $event): MutationResultData;
public function fingerprint(ShiftCashSourceFactData $fact): string;
public function process(string $obligationId, string $claimToken): MutationResultData;
```

**Red first**

- `apps/api/tests/Feature/Treasury/ShiftCashEvidenceBridgeTest.php`
- `::test_same_semantic_fact_on_v2_and_v3_is_not_double_applied`
- First failing assertion: one outcome is `applied`, the other `already_exists`.
- `::test_cross_shift_reuse_is_conflict`
- First failing assertion: reused source identity produces `conflict`.
- `::test_q11_q12_q13_dependent_facts_are_owner_ruling_required_without_posting`
- First failing assertion: transfer-document count is zero.
- `::test_crash_at_every_claim_stage_is_idempotently_recoverable`
- First failing assertion: final obligation outcome is typed and balances remain unchanged.
- `::test_second_company_and_location_are_isolated`
- First failing assertion: B sees no A obligation.
- Command/lane: `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Treasury/ShiftCashEvidenceBridgeTest.php` — **phpunit PG**.

**Reviewer gate:** Treasury + Fiscal/POS + owner-policy guard.  
**Rollback:** disable observers/workers; retain raw obligations.

### PL-106 — T6: canonical repository DTO

**Production files**

- CREATE `apps/api/app/Modules/Treasury/Application/Data/PaymentRepositoryData.php`
- MODIFY `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php`
- MODIFY all exact web/POS consumers listed at PL-052.
- Regenerate `packages/shared/types/generated.ts` using the configured transformer.

**Signature**

```php
public static function fromModel(PaymentRepository $repository): self;
```

Fields are full backend repository identity, company/location, code/name/type, currency, balance as string, active state, and optional inactive W-CASH configuration evidence. No Q11 ownership field is added.

**Red first**

- `apps/api/tests/Feature/Treasury/PaymentRepositoryDataTest.php`
- `::test_controller_returns_generated_dto_shape_and_decimal_strings`
- First failing assertion: `balance` is the exact scale-3 string.
- Command/lane: `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Treasury/PaymentRepositoryDataTest.php` — **phpunit PG**.
- `apps/web/src/features/treasury/__tests__/paymentRepositoryGeneratedType.test.ts`
- First method: `PaymentRepositoryGeneratedTypeTest::has_no_web_shadow_and_preserves_company_location`
- First failing assertion: decoded B/location-2 repository cannot equal A.
- Command/lane: `pnpm --filter @autoerp/web exec vitest run src/features/treasury/__tests__/paymentRepositoryGeneratedType.test.ts` — **vitest**.
- `apps/pos/src/lib/db/__tests__/paymentRepositoryDecoder.test.ts`
- First method: `PaymentRepositoryDecoderTest::rejects_invalid_row_and_round_trips_generated_dto`
- First failing assertion: malformed balance rejects rather than casts.
- Command/lane: `pnpm --filter @autoerp/pos exec vitest run src/lib/db/__tests__/paymentRepositoryDecoder.test.ts` — **vitest**.

Production command: `cd apps/api && php artisan typescript:transform`.

**Reviewer gate:** backend DTO + web + POS + convention 11.  
**Rollback:** restore compatibility decoder behind false flag; do not reintroduce local cross-boundary interfaces.

### PL-107 — T7: atomic close manifest

**Production files**

- MODIFY `apps/pos/src/services/zReportService.ts`
- MODIFY `apps/pos/src/lib/fiscal/zSessionAuthoring.ts`
- MODIFY `apps/pos/src/lib/sync/syncService.ts`
- CREATE:
  - `apps/pos/src/lib/fiscal/ZCloseManifestBuilder.ts`
  - `apps/pos/src/lib/db/repositories/zCloseManifestRepository.ts`
  - `apps/api/app/Modules/POS/Domain/Models/PosZCloseManifest.php`
  - `apps/api/app/Modules/POS/Application/Data/ZCloseManifestData.php`
  - `apps/api/app/Modules/POS/Application/Data/ZCloseManifestMemberData.php`
  - `apps/api/app/Modules/POS/Application/Services/ZCloseManifestIngestor.php`
- MODIFY `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php`

**Signatures**

```ts
build(input: ZCloseManifestBuildInput): ZCloseManifestData;
hash(manifest: ZCloseManifestData): Promise<string>;
saveWithinTransaction(manifest: ZCloseManifestData): Promise<MutationResult>;
```

```php
public function ingest(ZCloseManifestData $manifest): MutationResultData;
```

**Red first**

- `apps/pos/src/lib/fiscal/__tests__/zCloseManifest.atomicity.test.ts`
- `ZCloseManifestAtomicityTest::rolls_back_z_close_events_manifest_and_outbox_at_each_fault_point`
- First failing assertion: after injected failure every involved table has zero new rows.
- `::test_close_and_z_identities_exist_before_manifest_hashing`
- First failing assertion: member payload contains the returned real event hashes.
- `::test_rerun_is_already_exists_and_changed_payload_conflicts`
- First failing assertion: second identical result `already_exists`.
- Command/lane: `pnpm --filter @autoerp/pos exec vitest run src/lib/fiscal/__tests__/zCloseManifest.atomicity.test.ts` — **vitest**.

- `apps/api/tests/Feature/POS/ZCloseManifestIngestorTest.php`
- `::test_second_company_location_and_terminal_are_isolated`
- First failing assertion: cross-company Z/terminal FK rejects.
- `::test_member_order_hash_and_schema_version_are_verified`
- First failing assertion: reordered members produce `conflict`.
- Command/lane: `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/ZCloseManifestIngestorTest.php` — **phpunit PG**.

**Reviewer gate:** POS SQLite/fiscal-chain + backend POS.  
**Rollback:** false flag stops new manifests; retain and continue syncing already-authored manifests.

### PL-108 — T8: transactionally seeded cash-count fan-out

**Production files**

- MODIFY `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php`
- MODIFY `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php`
- MODIFY `apps/api/app/Modules/POS/Application/Services/CashCountDispatcher.php`
- MODIFY `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`
- CREATE:
  - `apps/api/app/Modules/POS/Domain/Models/CashCountDeliveryObligation.php`
  - `apps/api/app/Modules/POS/Application/Data/CashCountRecordedData.php`
  - `apps/api/app/Modules/POS/Application/Data/CashCountDispatchSeedResultData.php`
  - `apps/api/app/Modules/POS/Application/Data/CashCountDispatchResultData.php`
  - `apps/api/app/Modules/POS/Application/Data/CashCountConsumerOutcomeData.php`
  - `apps/api/app/Modules/POS/Application/Jobs/DeliverCashCountObligation.php`
  - `apps/api/app/Modules/POS/Presentation/Console/DeliverCashCountObligationsCommand.php`

**Red first**

- `apps/api/tests/Feature/POS/CashCountFanOutDurabilityTest.php`
- `::test_server_generated_z_and_three_obligations_commit_atomically`
- First failing assertion: after a forced precommit failure, Z count and obligation counts are both zero.
- `::test_device_synced_z_and_three_obligations_commit_atomically`
- Same first assertion for the sync path.
- `::test_crash_after_commit_before_queue_still_leaves_three_pending_obligations`
- First failing assertion: consumers equal exactly Treasury, Compliance, stored event.
- `::test_each_consumer_records_typed_outcome_and_redelivery_is_idempotent`
- First failing assertion: each second delivery is `already_exists`.
- `::test_stored_event_and_obligation_state_commit_atomically`
- First failing assertion: fault injection leaves neither stored event nor applied state.
- `::test_second_company_location_terminal_are_isolated`
- First failing assertion: company B worker cannot claim A.
- `::test_flag_false_retains_legacy_event_dispatch`
- First failing assertion: one existing event is dispatched and no obligation is inserted.
- Command/lane: `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/CashCountFanOutDurabilityTest.php` — **phpunit PG**.

**Reviewer gate:** POS producer owners + Treasury + Compliance + event sourcing.  
**Rollback:** false flag returns new producers to existing dispatch; already-created obligations remain deliverable until drained.

### PL-109 — T9: canonical W7 reconciliation

**Production files**

- MODIFY:
  - `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php`
  - `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php`
  - `apps/api/app/Modules/Fiscal/Application/Projectors/ZReportProjection.php`
  - `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php`
  - `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php`
  - `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`
- CREATE:
  - `apps/api/app/Modules/POS/Domain/Models/PosSessionReconciliation.php`
  - `apps/api/app/Modules/POS/Domain/Models/PosSessionReconciliationRun.php`
  - `apps/api/app/Modules/POS/Domain/Models/PosSessionReconciliationSupersession.php`
  - `apps/api/app/Modules/POS/Domain/Models/PosSessionReconciliationDependencyChange.php`
  - `apps/api/app/Modules/POS/Application/Services/ReconciliationDependencyVersionService.php`
  - `apps/api/app/Modules/POS/Application/Services/SessionCashReconciliationService.php`
  - `apps/api/app/Modules/POS/Application/Services/SessionReconciliationFingerprint.php`
  - `apps/api/app/Modules/POS/Application/Jobs/ReconcilePosSession.php`
  - `apps/api/app/Modules/POS/Presentation/Console/ReconcilePosSessionCommand.php`

**Signatures**

```php
public function touch(
    string $companyId,
    string $shiftId,
    ReconciliationDependencyKind $kind,
    string $identity,
    string $hash,
    CarbonImmutable $occurredAt,
): ReconciliationDependencyVersionData;

public function reconcile(
    string $companyId,
    string $shiftId,
    ?int $expectedDependencyVersion = null,
): SessionReconciliationMutationResultData;
```

**Required red-first acceptance matrix**

File for all cases: `apps/api/tests/Feature/POS/SessionCashReconciliationAcceptanceTest.php`.  
Command/lane for all: `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/SessionCashReconciliationAcceptanceTest.php` — **phpunit PG**.

| Exact method | First failing assertion |
|---|---|
| `test_taxed_sale_and_partial_refund_match_independently_derived_totals` | result is `matched` with independently calculated net/tax/refund strings |
| `test_multiple_tax_rates_are_reconciled_per_rate` | rate cells equal independently expected scale-3 values |
| `test_rounded_cash_and_card_split_matches` | cash/card totals include rounding exactly once |
| `test_account_collection_is_included` | account collection cell equals source-derived value |
| `test_opening_float_and_fiscal_drops_are_explicit_owner_ruling_required_cells` | result is `blocked`, not inferred or posted |
| `test_sequence_gap_is_incomplete` | result code equals `incomplete` |
| `test_missing_projection_is_incomplete` | projection cell names the missing member |
| `test_late_valid_member_supersedes_the_current_run` | one old noncurrent run and one new current run exist |
| `test_out_of_manifest_receipt_does_not_silently_enter_the_run` | result names the excluded receipt |
| `test_refund_only_session_preserves_negative_vat` | VAT cell is a negative scale-3 string |
| `test_duplicate_z_report_is_conflict` | no second current run is published |
| `test_valid_hashes_with_wrong_economic_totals_are_mismatch` | result equals `mismatch` |
| `test_lot_dependency_is_explicit_without_deciding_q10` | cell is evidence hash or `owner_ruling_required` |
| `test_repository_dependency_unavailable_blocks_before_coverage` | result is `blocked` |
| `test_repository_evidence_after_coverage_does_not_double_count_two_terminals` | each manifest member contributes once |
| `test_unknown_tender_throws_unknown_tender_classification_exception` | exact exception class is thrown |
| `test_reclassified_tender_invalidates_and_supersedes_the_run` | dependency version increases and old run becomes noncurrent |
| `test_concurrent_corrected_inputs_leave_exactly_one_current_run` | SQL count where `is_current=true` equals one |
| `test_dependency_change_during_snapshot_discards_mixed_version_run` | no run with stale captured version becomes current |
| `test_same_fingerprint_returns_already_exists` | run count and current ID remain unchanged |
| `test_second_company_and_second_location_do_not_cross_reconcile` | company B result contains no A identity |
| `test_chain_verification_remains_complementary_not_replaced` | chain failure remains independently visible |

The concurrent tests must use two PostgreSQL connections and barriers, not SQLite.

**Reviewer gate:** POS/Fiscal + Treasury + Accounting + W-LOT dependency owner + PostgreSQL concurrency.  
**Rollback:** false flag stops new runs; retain immutable run/supersession history and dependency changes.

### PL-110 — T10: operator read surfaces

**Production files**

- MODIFY:
  - `apps/api/app/Modules/POS/routes.php`
  - `apps/web/src/features/treasury/RepositoryDetailPage.tsx`
  - `apps/web/src/features/treasury/RepositoryListPage.tsx`
  - `apps/web/src/features/treasury/components/TransferCashModal.tsx`
- CREATE:
  - `apps/api/app/Modules/POS/Presentation/Controllers/SessionCashReconciliationController.php`
  - `apps/api/app/Modules/POS/Presentation/Resources/SessionCashReconciliationResource.php`
  - `apps/web/src/features/treasury/api/sessionCashReconciliationApi.ts`
  - `apps/web/src/features/treasury/components/SessionCashReconciliationPanel.tsx`
  - `apps/web/src/features/treasury/components/CashDeliveryObligationsPanel.tsx`

**Signatures**

```php
public function show(Request $request, string $shift): JsonResponse;
public function retry(Request $request, string $shift): JsonResponse;
```

Retry only enqueues existing durable work; it does not reinterpret policy.

**Red first**

- `apps/api/tests/Feature/POS/SessionCashReconciliationControllerTest.php`
- `::test_company_and_location_scoped_authorized_read`
- First failing assertion: company-B user receives 404 for A shift.
- `::test_retry_returns_explicit_already_exists_for_same_current_fingerprint`
- First failing assertion: response outcome is `already_exists`.
- Command/lane: `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/POS/SessionCashReconciliationControllerTest.php` — **phpunit PG**.

- `apps/web/src/features/treasury/components/__tests__/SessionCashReconciliationPanel.test.tsx`
- `SessionCashReconciliationPanelTest::renders_current_run_history_and_owner_ruling_required_without_action`
- First failing assertion: Q-dependent cell has no activation or classification control.
- Command/lane: `pnpm --filter @autoerp/web exec vitest run src/features/treasury/components/__tests__/SessionCashReconciliationPanel.test.tsx` — **vitest**.

**Reviewer gate:** API authz + Treasury UI + design/a11y.  
**Rollback:** disable `VITE_W_CASH_READ_UI_ENABLED`; API/history remain read-only.

### PL-111 — T12: rollout validation, candidate fingerprint, and promotion evidence

**Production files**

- MODIFY:
  - `docker-compose.staging.yml`
  - `apps/api/docker/entrypoint.sh`
  - `apps/api/docker/entrypoint-worker.sh`
  - `apps/api/docker/entrypoint-scheduler.sh`
  - `apps/api/docker/entrypoint-websocket.sh`
  - `apps/api/app/Modules/Tenant/Application/Commands/BackupTenantCommand.php`
  - `apps/api/routes/api.php`
  - `apps/web/vite.config.ts`
- CREATE:
  - `apps/api/docker/verify-w-cash-flags.sh`
  - `apps/api/app/Shared/Presentation/Http/Controllers/FeatureFingerprintController.php`
  - `apps/api/app/Shared/Application/Data/FeatureFingerprintData.php`
  - `apps/web/src/lib/buildFingerprint.ts`
  - `apps/web/e2e/w-cash-staging-smoke.spec.ts`

**Signatures**

```php
public function __invoke(): JsonResponse;
public function handle(TenantBackupService $service): int;
```

Fingerprint response contains `build_sha`, migration set hash, feature-flag values, W-CASH schema version, generated-type hash, and web contract version. Vite emits `build-meta.json` containing the same candidate SHA and web asset manifest hash.

**Red first**

- `apps/api/tests/Feature/Shared/FeatureFingerprintControllerTest.php`
- `::test_fingerprint_is_candidate_specific_and_contains_literal_flags`
- First failing assertion: `build_sha` equals injected candidate SHA.
- `apps/api/tests/Feature/Tenant/BackupTenantCommandJsonTest.php`
- `::test_all_json_returns_a_completed_backup_id_for_every_active_tenant`
- First failing assertion: returned backup-ID count equals active tenant count.
- Command/lane: `cd apps/api && DB_CONNECTION=pgsql ./vendor/bin/phpunit tests/Feature/Shared/FeatureFingerprintControllerTest.php tests/Feature/Tenant/BackupTenantCommandJsonTest.php` — **phpunit PG**.

- Container test: `apps/api/tests/Container/WCashEntrypointFlagsTest.sh`
- First case: invalid value causes API, worker, scheduler, and websocket `--check-only` to exit nonzero before `config:cache`.
- Command/lane: `sh apps/api/tests/Container/WCashEntrypointFlagsTest.sh` — **container shell**.

- Playwright file above; first test asserts served `build-meta.json.build_sha`, API `build_sha`, and candidate SHA are equal.
- Command/lane: `BASE_URL="$WEB_URL" API_BASE_URL="$API_URL" EXPECTED_BUILD_SHA="$CANDIDATE_SHA" pnpm --filter @autoerp/web exec playwright test e2e/w-cash-staging-smoke.spec.ts` — **Playwright**.

**Reviewer gate:** platform/Dokploy + tenancy backup/migration + API + web release.  
**Rollback:** redeploy prior recorded deployment IDs and restore prior flags; backup evidence is retained.

### PL-112 — T11: owner supplement

T11 is **WITHHELD and non-dispatchable**. After explicit Q10–Q13 rulings, create a separate benchmarked and gated supplement defining only the chosen behavior, schemas, migrations, states, tasks, migration/backfill policy, tests, activation, and rollback. Nothing in this plan may be treated as that supplement.

## 10. Environment and entrypoint contract

**PL-120 — Flags and process ownership.**

Add to `docker-compose.staging.yml`’s shared `x-api-env` block, inherited by API, worker, scheduler, and websocket at `docker-compose.staging.yml:17-57`, `:178-186`, `:206-214`, `:227-235`, `:242-250`:

```yaml
DB_DIRECT_HOST: "${DB_DIRECT_HOST:-postgres}"
APP_BUILD_SHA: "${APP_BUILD_SHA:?APP_BUILD_SHA is required}"
W_CASH_EVENT_POLICY_ENABLED: "${W_CASH_EVENT_POLICY_ENABLED:-false}"
W_CASH_TRANSFER_DOCUMENTS_ENABLED: "${W_CASH_TRANSFER_DOCUMENTS_ENABLED:-false}"
W_CASH_BOOKING_BRIDGE_ENABLED: "${W_CASH_BOOKING_BRIDGE_ENABLED:-false}"
W_CASH_Z_MANIFEST_ENABLED: "${W_CASH_Z_MANIFEST_ENABLED:-false}"
W_CASH_COUNT_OBLIGATIONS_ENABLED: "${W_CASH_COUNT_OBLIGATIONS_ENABLED:-false}"
W_CASH_RECONCILIATION_ENABLED: "${W_CASH_RECONCILIATION_ENABLED:-false}"
```

`verify-w-cash-flags.sh` accepts only literal `true` or `false`, requires `APP_BUILD_SHA` to be a 40-character lowercase SHA, and exits nonzero otherwise. It is sourced before `config:cache` by:

- API `apps/api/docker/entrypoint.sh`, whose current direct-host conversion is at `:103-117`.
- Worker before `apps/api/docker/entrypoint-worker.sh:48-53`.
- Scheduler before `apps/api/docker/entrypoint-scheduler.sh:38-43`.
- Websocket before `apps/api/docker/entrypoint-websocket.sh:37-42`.

API startup must stop swallowing central or tenant migration failure: the branches at `apps/api/docker/entrypoint.sh:131-154` must exit nonzero.

Web build environment:

```text
VITE_W_CASH_READ_UI_ENABLED=false
VITE_APP_BUILD_SHA=<exact candidate SHA>
```

No Q10–Q13 branch flag exists.

## 11. Executable five-push staging manifest

**PL-121 — Shared preflight.**

Run from repository root before every push:

```bash
set -euo pipefail

test "$(git branch --show-current)" = "dev"
test -z "$(git status --porcelain --untracked-files=no)"
CANDIDATE_SHA="$(git rev-parse HEAD)"
test "${#CANDIDATE_SHA}" -eq 40

: "${DOKPLOY_URL:?}"
: "${DOKPLOY_API_KEY:?}"
: "${DOKPLOY_API_APPLICATION_ID:?}"
: "${STAGING_SSH:?}"
: "${WEB_URL:?}"
: "${API_URL:?}"

pnpm lint
pnpm typecheck
pnpm test
pnpm build
(
  cd apps/api
  composer test
  ./vendor/bin/phpstan analyse
)
```

Push only the reviewed candidate:

```bash
git push origin "${CANDIDATE_SHA}:refs/heads/dev"
REMOTE_SHA="$(git ls-remote origin refs/heads/dev | awk '{print $1}')"
test "$REMOTE_SHA" = "$CANDIDATE_SHA"
```

**PL-122 — Fleet backup and captured IDs before schema promotion.**

```bash
BACKUP_JSON="$(
  ssh "$STAGING_SSH" \
    "cd /var/www/html && DB_HOST=\${DB_DIRECT_HOST:-postgres} php artisan tenant:backup --all --format=json"
)"
printf '%s\n' "$BACKUP_JSON" | jq -e '.outcome == "applied" or .outcome == "already_exists"'
ACTIVE_TENANTS="$(printf '%s\n' "$BACKUP_JSON" | jq '.tenants | length')"
test "$ACTIVE_TENANTS" -gt 0
printf '%s\n' "$BACKUP_JSON" | jq -e '.tenants[] | select(.backup_id == null or .status != "completed" or .sha256 == null)' \
  | (! read -r)
printf '%s\n' "$BACKUP_JSON" | jq -r '.tenants[] | [.tenant_id,.backup_id,.sha256] | @tsv' \
  > "/tmp/w-cash-backups-${CANDIDATE_SHA}.tsv"
```

No migration begins until every active tenant has a captured completed backup ID and SHA-256.

**PL-123 — Dokploy deployment and candidate proof used for every push.**

```bash
DEPLOY_JSON="$(
  curl --fail-with-body --silent --show-error \
    -X POST "${DOKPLOY_URL}/api/application.redeploy" \
    -H "x-api-key: ${DOKPLOY_API_KEY}" \
    -H "content-type: application/json" \
    --data "$(jq -nc \
      --arg applicationId "$DOKPLOY_API_APPLICATION_ID" \
      '{applicationId:$applicationId}')"
)"
DEPLOYMENT_ID="$(printf '%s\n' "$DEPLOY_JSON" | jq -er '.deploymentId')"
test -n "$DEPLOYMENT_ID"
```

Poll Dokploy’s deployment status endpoint supported by the installed Dokploy version until that captured deployment ID reports `done/success`; `error/failed/cancelled`, missing ID, timeout, or a status belonging to another deployment aborts. T12’s preflight test must pin the endpoint and JSON path before the first push; operators may not guess them during rollout.

After success:

```bash
API_FINGERPRINT="$(curl --fail-with-body --silent --show-error "${API_URL}/api/feature-fingerprint")"
test "$(printf '%s\n' "$API_FINGERPRINT" | jq -r '.build_sha')" = "$CANDIDATE_SHA"

WEB_META="$(curl --fail-with-body --silent --show-error "${WEB_URL}/build-meta.json")"
test "$(printf '%s\n' "$WEB_META" | jq -r '.build_sha')" = "$CANDIDATE_SHA"

ASSET_PATH="$(printf '%s\n' "$WEB_META" | jq -er '.entry_asset')"
EXPECTED_ASSET_HASH="$(printf '%s\n' "$WEB_META" | jq -er '.entry_asset_sha256')"
SERVED_ASSET_HASH="$(
  curl --fail-with-body --silent --show-error "${WEB_URL}${ASSET_PATH}" | sha256sum | awk '{print $1}'
)"
test "$SERVED_ASSET_HASH" = "$EXPECTED_ASSET_HASH"

BASE_URL="$WEB_URL" \
API_BASE_URL="$API_URL" \
EXPECTED_BUILD_SHA="$CANDIDATE_SHA" \
pnpm --filter @autoerp/web exec playwright test e2e/w-cash-staging-smoke.spec.ts
```

This explicit web deployment/freshness sequence runs after **every** promotion, as required by `docs/factory/WORKFLOW.md:196-231`.

**PL-124 — Migration proof.**

```bash
set -o pipefail
MIGRATION_LOG="/tmp/w-cash-migrate-${CANDIDATE_SHA}.log"

ssh "$STAGING_SSH" \
  "cd /var/www/html && DB_HOST=\${DB_DIRECT_HOST:-postgres} php artisan migrate --force && DB_HOST=\${DB_DIRECT_HOST:-postgres} php artisan tenants:migrate-rolling --force" \
  | tee "$MIGRATION_LOG"

grep -F "0 failed" "$MIGRATION_LOG"
TENANT_LINES="$(grep -c '^→ ' "$MIGRATION_LOG")"
test "$TENANT_LINES" -eq "$ACTIVE_TENANTS"

ssh "$STAGING_SSH" \
  "cd /var/www/html && DB_HOST=\${DB_DIRECT_HOST:-postgres} php artisan w-cash:audit --format=json --fail-on=error" \
  > "/tmp/w-cash-audit-${CANDIDATE_SHA}.json"

jq -e '.outcome == "applied" or .outcome == "already_exists"' \
  "/tmp/w-cash-audit-${CANDIDATE_SHA}.json"
jq -e --argjson expected "$ACTIVE_TENANTS" '.tenant_count == $expected' \
  "/tmp/w-cash-audit-${CANDIDATE_SHA}.json"
```

The rolling command’s HEAD implementation prints each tenant and returns failure if any tenant fails (`apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:73-103`, `:158-174`).

### PL-125 — Push 1: T0 census

Content: T0 only.

```bash
git commit -m "Phase <phase>: Add W-CASH capability census"
```

Run PL-121, push, deploy via PL-123, and capture the audit JSON. No schema or flags change.

**Rollback point:** captured pre-Push-1 Dokploy deployment ID; redeploy it if command registration affects startup.

### PL-126 — Push 2: schemas and migrations

Content: T1 only; all flags false.

```bash
git commit -m "Phase <phase>: Add dormant W-CASH schemas"
```

Run PL-121, PL-122, push, PL-123, then PL-124. Re-run PL-124 once; the second result must be explicit `already_exists` with identical schema fingerprint.

**Rollback point:** pre-Push-2 deployment ID plus every backup ID from PL-122. Prefer forward compatibility. Restore only a tenant explicitly selected from its captured backup if forward repair is impossible.

### PL-127 — Push 3: dormant implementation and T12

Content: T2–T10 and T12, including backend, device, generated types, UI, entrypoints, backup JSON, and fingerprint surfaces. All flags false.

```bash
cd apps/api
php artisan typescript:transform
cd ../../
git diff --exit-code packages/shared/types/generated.ts
pnpm --filter @autoerp/pos tauri build
git commit -m "Phase <phase>: Implement dormant W-CASH workflows"
```

Run PL-121, push, PL-123, and PL-124. The asset hash must differ from Push 2 because web code changed. Execute physical-device smoke:

1. Online Z close.
2. Offline close.
3. Crash immediately before local manifest commit.
4. Crash immediately after commit and before sync.
5. Reconnect and verify one synced manifest.
6. Replay and verify `already_exists`.
7. Verify flag-false legacy cash-count dispatch.
8. Verify no Treasury balance or GL entry changes.
9. Verify two companies, two locations, and two terminals remain isolated.

**Rollback point:** Push-2 deployment ID. False flags permit redeploying it while retaining dormant evidence/schema.

### PL-128 — Push 4: inactive revisions, cutover evidence, and campaign

Content: no new production code; create only policy-neutral inactive records using already-promoted commands.

Capture IDs before use:

```bash
CONFIG_JSON="$(
  ssh "$STAGING_SSH" \
    "cd /var/www/html && php artisan treasury:cash-config:create '$COMPANY_ID' '$LOCATION_ID' '$DRAWER_REPOSITORY_ID' '$SAFE_REPOSITORY_ID' --actor='$ACTOR_ID' --format=json"
)"
CONFIGURATION_ID="$(printf '%s\n' "$CONFIG_JSON" | jq -er '.aggregate_id')"
CONFIGURATION_REVISION_ID="$(printf '%s\n' "$CONFIG_JSON" | jq -er '.revision_id')"

POLICY_JSON="$(
  ssh "$STAGING_SSH" \
    "cd /var/www/html && php artisan fiscal:projection-policy:record '$COMPANY_ID' '$PROJECTOR' owner_ruling_required --effective-at='$EFFECTIVE_AT' --actor='$ACTOR_ID' --reason='W-CASH observation only' --format=json"
)"
POLICY_RECORD_ID="$(printf '%s\n' "$POLICY_JSON" | jq -er '.aggregate_id')"

CUTOVER_JSON="$(
  ssh "$STAGING_SSH" \
    "cd /var/www/html && php artisan fiscal:projection-cutover:add '$COMPANY_ID' '$LOCATION_ID' '$TERMINAL_ID' '$PROJECTOR' '$RAIL' --lower-occurred-at='$LOWER_AT' --lower-source-id='$LOWER_ID' --upper-occurred-at='$UPPER_AT' --upper-source-id='$UPPER_ID' --policy-record='$POLICY_RECORD_ID' --decision=owner_ruling_required --actor='$ACTOR_ID' --format=json"
)"
CUTOVER_ID="$(printf '%s\n' "$CUTOVER_JSON" | jq -er '.aggregate_id')"

test -n "$CONFIGURATION_ID"
test -n "$CONFIGURATION_REVISION_ID"
test -n "$POLICY_RECORD_ID"
test -n "$CUTOVER_ID"
```

Repeat each command and assert `outcome == "already_exists"` and identical IDs.

Run the valid campaign command:

```bash
pnpm exec tsx scripts/campaign.ts --web --api --country=TN
```

Promote the unchanged candidate explicitly through Dokploy using PL-123, then run PL-124 and Playwright again. Record both-company, both-location, and both-terminal evidence.

**Rollback point:** Push-3 deployment ID. Inactive records remain immutable; do not delete them.

### PL-129 — Push 5: activation, prohibited pending rulings

Do not construct, commit, push, deploy, or set true flags until PL-014 is satisfied. When authorized by the separate T11 supplement:

1. Capture new fleet backups and IDs using PL-122.
2. Capture pre-activation deployment and flag snapshot.
3. Run the supplement’s migrations with PL-124.
4. Enable one flag/cohort at a time.
5. Redeploy explicitly after each change using PL-123.
6. Verify asset hash, API/web fingerprint, Playwright, obligation backlog, reconciliation current-run uniqueness, two-company/location/terminal isolation, and Treasury/GL cardinality.
7. Stop and restore prior false flags on any mismatch.
8. Roll back by redeploying the captured deployment and flags; reverse posted financial documents rather than deleting evidence.

## 12. Dispatch order

**PL-140**

1. T0 — capability and writer census.
2. T1 — exact PostgreSQL schemas, enums, DTO shells, glossary, SQLite v68→v69.
3. Push 1.
4. Push 2.
5. T2 — projection policy and ordered cutovers.
6. T3 — inactive configuration with preserved failure containment.
7. T4 — transfer documents and shared lock order.
8. T5 — policy-neutral v2/v3 evidence.
9. T6 — backend generated repository DTO and shadow removal.
10. T7 — atomic close manifest.
11. T8 — producer-transaction cash-count obligations.
12. T9 — canonical W7 reconciliation.
13. T10 — read-only operator surfaces.
14. T12 — environment, backup IDs, fingerprints, and executable promotion tooling.
15. Push 3.
16. Push 4.
17. Stop. T11 and Push 5 remain prohibited until the owner rulings and prerequisites are complete.

Dependencies:

- T2–T10 require T1.
- T3 and T4 may proceed after T1.
- T5 requires T2 and T3; posting activation also requires T4 and T11.
- T6 requires the backend DTO created in its own lane and T3’s policy-neutral evidence shape.
- T7 requires SQLite v68/v69 and server schema.
- T8 requires its obligation schema and both producer modifications.
- T9 requires T2, T5, T7, T8, W2, W4, and W-LOT evidence availability.
- T10 requires T9 and T6.
- T12 is implemented with T2–T10 and promoted in Push 3.
- T11 is never inferred from another task.

## 13. Final verification checklist

**PL-150 — Authority and policy**

- [ ] Inspected base SHA is recorded as `7e14006182a1d7e281bbdc4660af85a80a2ee9fd`.
- [ ] Q10–Q13 match owner-ruling rows verbatim and remain OPEN.
- [ ] No Q10–Q13 branch exists in schema, enum, state, flag, command, task, worker, UI, seed, or fixture.
- [ ] T11 and Push 5 remain prohibited.
- [ ] Historical backbooking and automatic variance activation remain prohibited.

**PL-151 — Schema**

- [ ] Every referenced HEAD table/column exists or is created earlier in the same migration.
- [ ] Projection code uses `projection_status`; no invented projection `company_id`.
- [ ] Cash-count delivery references `pos_z_reports`, not `cash_counts`.
- [ ] Every new money column is `decimal(20,3)`.
- [ ] Every state/type/code has a PHP enum, model cast, and DB check.
- [ ] Every FK target/delete action and composite ownership constraint matches PL-071–PL-079.
- [ ] Every editable business unique includes `company_id`.
- [ ] PostgreSQL catalog tests inspect columns, nullability, defaults, checks, indexes, predicates, FKs, and delete rules.
- [ ] SQLite v68 runs before v69 and both rerun safely.

**PL-152 — Convention 09 and 11**

- [ ] Real company creation creates company B.
- [ ] Real `pos_enabled` location creation creates location 2.
- [ ] Queries never leak company/location A into B.
- [ ] Every mutator returns `applied`, `already_exists`, `skipped`, `blocked`, or `conflict`.
- [ ] Reruns assert data meaning, balances, IDs, and explicit outcome.
- [ ] Glossary contains every Vocabulary noun.
- [ ] Each concept has one documented writer and operator surface.
- [ ] Backend `PaymentRepositoryData` is generated into shared types.
- [ ] Web/POS cross-boundary shadows are removed.
- [ ] POS SQLite row conversion is validating.

**PL-153 — Financial correctness**

- [ ] Every repository mutation reaches `TreasuryMovementService`.
- [ ] Writer census is regenerated and reviewed.
- [ ] Lock order is tenant numbering → company GL chain → transfer document → sorted repositories.
- [ ] Opposite transfers and unrelated JEs pass PG concurrency tests.
- [ ] One transfer document produces exactly two movements and zero-or-one JE.
- [ ] Reversal is the only posted correction.
- [ ] `InventoryGlPostingBuffer` has zero W-CASH calls.

**PL-154 — Manifest and delivery**

- [ ] Close/Z identities and hashes exist before manifest construction.
- [ ] Z/counts, close/Z events, manifest, outbox, and chain changes share one SQLite transaction.
- [ ] Manifest member fields, namespace, order, canonicalization, version, and hash match PL-077.
- [ ] Both server-generation and device-sync transactions insert all three delivery obligations before commit.
- [ ] After commit only delivery enqueue occurs.
- [ ] Treasury, Compliance, and stored-event outcomes are typed and independently durable.
- [ ] Crash/retry tests cover precommit, postcommit/prequeue, claim, consumer, and outcome boundaries.
- [ ] Redelivery cannot duplicate effects.

**PL-155 — Reconciliation**

- [ ] Canonical head is `pos_session_reconciliations`.
- [ ] Partial unique index permits one current run per company/reconciliation.
- [ ] Head, old-current demotion, new run, supersession, and current pointer change atomically.
- [ ] Dependency writers increment a locked version in their own transactions.
- [ ] A changed dependency version discards a mixed snapshot.
- [ ] Input fingerprint is RFC-8785 SHA-256 over immutable ordered dependencies.
- [ ] Every spec-v4 acceptance method in PL-109 passes on PostgreSQL.
- [ ] Unknown/reclassified tenders fail closed.
- [ ] Chain verification remains complementary.
- [ ] Q-dependent cells report `owner_ruling_required` without selecting a branch.

**PL-156 — Promotion**

- [ ] Shared Compose forwards every API flag and `DB_DIRECT_HOST`.
- [ ] API, worker, scheduler, and websocket invoke the same validator before config caching.
- [ ] Central or tenant migration failure exits nonzero.
- [ ] Fleet backup JSON captures one completed backup ID/SHA per active tenant.
- [ ] `set -euo pipefail` and `set -o pipefail` protect every pipeline.
- [ ] `tenants:migrate-rolling --force` prints and succeeds for every active tenant.
- [ ] Candidate SHA equals `origin/dev`, API fingerprint, and web fingerprint.
- [ ] Dokploy deployment ID and terminal success status are captured for every push.
- [ ] Served entry asset SHA-256 equals `build-meta.json`.
- [ ] Explicit web deployment and Playwright smoke run after every promotion.
- [ ] Push 3 explicitly contains T12.
- [ ] Physical Tauri build uses `pnpm --filter @autoerp/pos tauri build`.
- [ ] Two-company/two-location/two-terminal, offline, crash, reconnect, replay, and no-financial-effect evidence is archived.
- [ ] Every push has a recorded rollback deployment; schema rollback remains forward-compatible and immutable evidence is never deleted.