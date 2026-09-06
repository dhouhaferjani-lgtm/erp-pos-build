<!-- Rev 5, authored by Codex CLI (gpt-5.6-sol, high, read-only) on 2026-09-06 from rev 4 + plan gate r5; filed verbatim. Status: awaiting plan gate r6. Rev 4 = a40449249. -->
# W-CASH float/drop execution plan — revision 5

**Plan date:** 2026-09-06  
**Repository:** `/Users/houssamr/Projects/syneriva/apps/erp`  
**Inspected branch:** `dev`  
**Inspected HEAD:** `4878c3e6add3f2815522e6f1cb553ebcd81b3e66`  
**Inspection mode:** read-only. No files, schema, commits, branches, or remote state were changed.  
**Worktree preserved:** the pre-existing untracked files `apps/api/docs/sessions/2026-08-04-cross-tenant-scheduled-jobs-plan.md` and `docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md` remain outside W-CASH scope.

All repository-relative `path:line` citations refer to inspected HEAD `4878c3e6add3f2815522e6f1cb553ebcd81b3e66`. Production implementation paths are unchanged from rev 4’s inspected implementation snapshot.

Vocabulary: `Fiscal projection policy record` (NEW), `Fiscal projection cutover` (NEW), `Repository transfer document` (NEW), `Shift cash source evidence` (NEW), `Shift cash semantic fact` (NEW), `Z close manifest` (NEW), `Cash-count delivery obligation` (NEW), `Session reconciliation` (existing canonical concept), `Reconciliation run` (NEW), `Reconciliation supersession` (NEW), `Mutation outcome` (NEW). T1 updates `docs/glossary.md`; `Session reconciliation` continues to mean the canonical `pos_session_reconciliations` store written only by `PosSessionReconciliationService`, reusing `ShiftExpectedCashService` (`docs/glossary.md:76`).

## 1. Revision-5 change log and gate-r5 closure

Stable plan markers such as `PL-340` are the “plan line” references used by this change log.

### 1.1 Gate-r5 blockers, majors, and minors

| Gate-r5 finding | Revision-5 disposition |
|---|---|
| Blocker 1 — Q12 branch encoded through `safe_drop`, typed source vocabulary, and drawer→safe configuration | **CLOSED at PL-110, PL-230, PL-250, PL-320, and PL-530.** Pre-ruling schema stores only opaque raw event names, free-text reason, custody evidence, and `blocked/owner_ruling_required`. There is no cash-reason catalogue, drawer/safe destination map, cash-operation→transfer association, cash configuration command, or financial activation push. |
| Blocker 2 — composite FKs cannot be created | **CLOSED at PL-200–PL-280.** Every composite FK has an explicitly ordered supporting unique created before the FK, including policies, cutovers, journal entries, transfer documents, manifests, reconciliation heads, runs, and current-run pointers. The PostgreSQL schema test asserts every referenced and referencing column order. |
| Blocker 3 — circular five-push manifest | **CLOSED at PL-500–PL-550.** Push 1 delivers every later rollout dependency: backup JSON, `AUTO_MIGRATE`, fail-closed entrypoints, build arguments, API/web fingerprints, exact Dokploy correlation/polling, environment update tooling, and Playwright smoke. Push 2 uses those already-deployed tools. Pushes 3–4 consume only earlier artifacts. Push 5 remains prohibited. |
| Blocker 4 — semantic fingerprint cannot deduplicate across rails | **CLOSED at PL-340.** Raw rail/source identity is stored separately. The economic fingerprint excludes rail/source UUID and uses canonical custody boundaries plus the original authored linkage, time, opaque authoring name, amount, and currency. Unlinked cross-rail facts remain blocked. |
| Major 1 — manifest members cannot be derived | **CLOSED at PL-350.** Every current contributing namespace has an exact source query and deterministic ID/time/sequence/hash algorithm. W-LOT-B lot evidence is explicitly excluded from manifest schema v1 until its capability is accepted. |
| Major 2 — W7 diverges from canonical service/schema | **CLOSED at PL-270, PL-360, and T9.** The head carries tenant/company/terminal/shift/session/Z/manifest identity; the writer is `PosSessionReconciliationService`; `ShiftExpectedCashService` is extended for manifest membership and remains the only cash calculator. |
| Major 3 — historical fingerprint A→B→A can leave B current | **CLOSED at PL-360.** Runs are immutable occurrences; fingerprints are indexed but not unique. Same-as-current returns `already_exists`; a historical match appends a new occurrence and atomically replaces the head pointer. |
| Major 4 — incomplete exact task ownership | **CLOSED at PL-400–PL-412.** Every production, DTO, enum, request, provider, route, translation, release-tool, and generated-type file is named. All service and CLI signatures and every red-first case are explicit. |
| Major 5 — convention 09 incomplete for repository-facing work | **CLOSED at PL-120 and T3/T4/T10.** Tests use the real company creation path, a real second `pos_enabled` location, company-B-only list/detail reads, and repeated mutation/retry with unchanged IDs and balances. |
| Independent minors | **REJECTED at PL-010.** Gate r5 reported no independent minor; remaining items were blocker/major requirements (`docs/superpowers/reviews/2026-09-06-w-cash-plan-codex-gate-r5.md:160-162`). |

### 1.2 Five previously open closure-audit rows

| Closure-audit row | Disposition |
|---|---|
| B4 — v2/v3 fingerprint and cutover | **CLOSED at PL-220 and PL-340.** Ordered half-open cutovers remain; semantic identity is now rail-neutral. |
| B6 — W7 reconciliation | **CLOSED at PL-270 and PL-360.** Full custody identity, canonical writer, membership-aware expected-cash derivation, stable snapshots, and A→B→A handling are specified. |
| B7 — migration/deployment safety | **CLOSED at PL-500–PL-550.** The manifest is acyclic, exact, fail-closed, and captures every identifier before reuse. |
| M4 — convention 09 | **CLOSED at PL-120 and the T3/T4/T10 red-first contracts.** |
| M9 — local/generated type ownership | **CLOSED at PL-410/T3.** The configured output is `packages/shared/types/generated.d.ts`, as proven by `apps/api/config/typescript-transformer.php:45-53`; no `generated.ts` path remains. |

### 1.3 Preserved rev-4 closure set

All 48 prior CLOSED rows remain closed in substance. In particular:

- Q10–Q13 remain verbatim and OPEN.
- Q11 join/refusal and opening-float ownership remain withheld.
- Event-time cutovers retain ordered half-open coordinates and serialized overlap rejection.
- Raw evidence remains separate from economic identity.
- The financial lock order remains tenant numbering → company GL chain → transfer document → sorted repositories.
- One immutable transfer document produces exactly two repository movements and zero-or-one journal entry.
- Posted corrections remain reversal-only.
- Company/location provisioning remains failure-contained; W-CASH no longer adds a pre-ruling cash-configuration hook.
- Device schema ordering remains strict. Because accepted W-LOT-B is a W7 prerequisite and reserves SQLite v68/v69, W-CASH uses v70 then v71 while preserving the closed “parent table before outbox table” invariant.
- Close/Z identities exist before manifest construction in the same SQLite transaction.
- Treasury, Compliance, and stored-event cash-count consumers remain independently durable and are seeded in both producer transactions.
- Device manifest membership remains distinct from the server reconciliation dependency snapshot.
- Every rollout flag defaults to literal `false`.
- Historical backbooking and automatic variance activation remain prohibited.
- Two-company, two-location, two-terminal, offline, crash, reconnect, and replay evidence remains mandatory.

### 1.4 Rejected false positives

- **REJECTED:** an opening-interval or shared-drawer algorithm should be added. That would decide Q11.
- **REJECTED:** `tenants:migrate-rolling --force` is invalid. Its signature is present at `apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48-53`; it emits one `→` line per tenant and returns failure when any tenant fails at `:73-103` and `:158-174`.
- **REJECTED:** expected cash needs another opening component. `ShiftExpectedCashService::breakdown()` already includes the persisted opening float at `apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:252-303`.
- **REJECTED:** a transfer needs a third clearing-repository movement. The invariant is two repository movements and zero-or-one GL entry.
- **REJECTED:** `InventoryGlPostingBuffer` should participate. It is inventory-only and remains outside W-CASH.
- **REJECTED:** a second reconciliation service may copy the expected-cash formula. The canonical service is already documented as the sole derivation at `apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:27-59`.

## 2. Authority, scope, and owner stops

**PL-010 — Repository contract.** Strict typing, red-first development, no placeholders, JSONB DTO ownership, module boundaries, immutable events, generated frontend types, enum-backed statuses, and end-to-end verification are mandatory (`CLAUDE.md:15-43`). Money uses decimal strings and scale-3 storage, never floats (`CLAUDE.md:71-79`). POS SQLite timestamps, named queues, and worker context rules remain binding (`CLAUDE.md:81-87`).

**PL-011 — Governing specification.** W-CASH implements W7’s manifest, completeness, run-history, stable-snapshot, and independent fiscal comparison requirements from `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:410-430`.

**PL-012 — Existing variance writer.** `PostShiftCashVarianceAdjustment` stays disabled. No task removes its guard, posts the disabled interval, or turns variance into money.

**PL-013 — Default-off rule.** Every W-CASH rollout variable accepts only literal `true` or `false` and defaults to `false`. Invalid/missing values fail before `config:cache`.

**PL-014 — Prerequisite stop.** Reconciliation activation requires accepted W2 authored tender classification, W4 source completeness, accepted W-LOT-B dependency evidence, T7 manifests, and T8 durable delivery. Missing evidence leaves `W_CASH_RECONCILIATION_ENABLED=false`.

**PL-015 — Push 5 stop.** T11 and Push 5 are non-dispatchable until Q10–Q13 have explicit owner rulings and a separately benchmarked/reviewed supplement exists.

### Owner rulings — verbatim and OPEN

**PL-020 — The following rows are copied verbatim from `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:138-143`; every row remains OPEN.**

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

**PL-021 — Policy-neutrality rule.**

Before T11:

- No schema, enum, state, flag, endpoint, command, seed, fixture, task, worker, or UI may encode recall release, drawer-session ownership, terminal join/refusal, float ownership, a typed cash reason, a cash-operation destination, historical alignment, disabled-window closure, or variance activation.
- `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `DEPOSIT`, and `PAYOUT` may be copied only as opaque immutable source text already authored by existing systems.
- The only new reason field is `reason_text`; no reason-code vocabulary or destination mapping exists.
- Every unresolved cash source remains `blocked` with the technical reason `owner_ruling_required`.
- A raw v3 fact that lacks an authenticated authored linkage remains `blocked/source_linkage_missing`; time/amount similarity alone cannot authorize deduplication or posting.
- No raw cash evidence points to a transfer document, repository destination, account, journal entry, or configuration revision.
- Manual repository transfers remain their existing independent operator action; W-CASH may give those transfers an immutable document and free-text reason, but may not infer one from cash-operation evidence.
- No historical fact is backbooked.

## 3. Industry baseline (benchmark-first — convention 10)

Flow: W-CASH float, raw cash evidence, close manifest, cash-count delivery, manual repository transfer, and session reconciliation. Reference systems: Odoo 19, ERPNext current documentation, Dolibarr TakePOS current documentation. Sources: Odoo POS payments/register close, ERPNext POS workflows/Mode of Payment, and Dolibarr TakePOS.

| ID | Guarantee | Odoo | ERPNext | Dolibarr/NV | AutoERP today path:line | Gap | Decision |
|---|---|---|---|---|---|---|---|
| B1 | A register close preserves counted cash and expected/difference evidence | Register close records counted cash and payment-method differences | POS Closing Entry records closing values | TakePOS close exists; per-tender durability NV | `pos_z_report_counts` persists expected, actual, variance, direction, and count (`apps/api/database/migrations/tenant/2026_04_25_000001_create_pos_z_report_counts_table.php:14-27`) | Delivery to every consumer is not crash-safe | MATCH — T8 |
| B2 | Close evidence has stable explicit membership | Close operates over one POS session | Closing Entry is tied to its opening/session | NV | Device receipt membership uses hash-sequence bounds, while drawer/account/refund streams are separately queried (`apps/pos/src/lib/offline/zReportService.ts:165-225`) | No complete immutable manifest | MATCH — T7 |
| B3 | Reconciliation has immutable run history and one current result | Register history is retained | Closing entries are retained | NV | Canonical store is proposed but absent; glossary records `pos_session_reconciliations` as proposed (`docs/glossary.md:76`) | No head/run/current-pointer store | MATCH — T9 |
| B4 | Duplicate delivery cannot double effects | Accounting effects are document-led | Payment and journal documents are named | Movement documents are auditable | `TreasuryMovementService` owns idempotent recording and sorted repository locking (`apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:225-268`) | Cash-count fan-out can be lost after commit | MATCH — T8 |
| B5 | An internal transfer has an immutable document and balanced legs | Internal-transfer workflows retain journal evidence | Internal Transfer uses paired accounts | NV | `RepositoryTransferService` currently posts GL then calls the movement port (`apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:33-101`) | No canonical transfer document/group | MATCH — T4; no cash-operation mapping |
| B6 | Concurrent corrected inputs cannot expose two current results | One close context per register/session | One named closing entry | NV | No current-run constraint/store exists; stable invalidation is required by spec (`docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:414-422`) | Missing locked head/current pointer | MATCH — T9 |
| B7 | Company B may reuse company A’s business code without leakage | Company accounting contexts are independent | Company accounting contexts are independent | Multi-company behavior varies | Catalogue ratchet inspects live PG uniques (`apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:52-58`) | Repository-facing changes need real company-B proof | MATCH — T3/T4/T10 |
| B8 | A selected second location controls terminal/repository visibility | POS configuration is branch/register-specific | POS Profile carries warehouse/location | Terminal setup is explicit | Terminals persist company/location (`apps/api/database/migrations/tenant/2026_01_08_190429_create_pos_terminals_table.php:20-65`) | Every new read/write must preserve location scope | MATCH — T3/T4/T10 |
| B9 | Repeating a mutation reports an explicit outcome | Named documents prevent silent duplication | Named entries preserve duplicate meaning | NV | Convention 09 requires explicit `skipped/already_exists` and unchanged balances (`docs/conventions/09-SECOND-OF-EVERYTHING.md:37-50`) | Uneven result contracts | MATCH — every mutating task |
| B10 | Shared-drawer ownership is explicit before financial activation | One session per POS configuration | Opening Entry is per user/profile | NV | Shifts are terminal-bound (`apps/api/database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php:20-69`) | Q11 OPEN | DEFER — OWNER-Q11 |
| B11 | Cash-in/out meaning and destination are explicit before posting | Cash reasons map to accounts | Petty cash/transfers use explicit accounts | NV | Device/server retain existing raw operation names and free-text reason (`apps/api/app/Modules/POS/Domain/CashDrawerOperation.php:20-28`; `apps/pos/src/lib/db/migrations.ts:270-284`) | Q12 OPEN | DEFER — OWNER-Q12; only raw evidence ships |
| B12 | Historical alignment is deliberate and non-retroactive | Opening/last-closing balance is explicit | Opening Entry establishes balance | NV | No alignment exists; variance writer remains disabled | Q13 OPEN | DEFER — OWNER-Q13 |
| B13 | Recall/hold disposition cannot silently alter cash state | Quality hold is lifted explicitly | Quarantine/release is disposition-led | NV | No branch hold lifecycle exists; owner row remains open (`docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:140`) | Q10 OPEN | DEFER — OWNER-Q10 |
| B14 | Unknown/reclassified tender evidence fails closed | Payment methods are configured | Mode of Payment is configured | Payment modes are configured | `ShiftExpectedCashService` throws `UnknownTenderClassificationException` (`apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:243-257`) | Must remain visible in W7 | ALREADY — regression-tested by T9 |
| B15 | A close contains all contributing current-device streams | Session close covers session activity | Closing Entry covers session activity | NV | Current Z reads receipts, legacy refunds, cash operations, and account collections separately (`apps/pos/src/lib/offline/zReportService.ts:178-225`) | No common member identity/hash contract | MATCH — T7 |
| B16 | Financial activation is withheld when custody policy is unresolved | Configuration precedes posting | Configuration precedes posting | NV | Existing expected cash can describe drawer arithmetic but Treasury float/drop booking is absent | Owner policy unresolved | DEFER — T11/Push 5 |

Second-of-everything (convention 09): T3, T4, and T10 provision company B through the real company-creation path, create/select a second `pos_enabled` location through the real location path, assert B-only repository/list/detail behavior, and rerun mutations/retries with identical IDs and unchanged balances. T2 verifies company-isolated cutovers. T5, T7, T8, and T9 verify two companies, two selected locations, two terminals, and explicit repeat outcomes. No raw insert substitutes for company or location creation.

## 4. Verified HEAD census

**PL-100 — Existing data reality.**

- `CashDrawerOperation` stores UUID, shift, raw operation name, decimal amount, user, nullable free-text reason, receipt linkage, idempotency key, and immutable creation time (`apps/api/app/Modules/POS/Domain/CashDrawerOperation.php:20-61`).
- Existing helper labels call `DEPOSIT` a safe deposit and `PAYOUT` a payout (`CashDrawerOperation.php:112-140`, `:163-175`); W-CASH does not promote those legacy labels into new financial schema.
- Device cash operations contain `id`, idempotency key, raw type, amount, free-text reason, terminal, shift, and creation time (`apps/pos/src/lib/db/migrations.ts:270-287`).
- Server fiscal events have distinct rail identity/time/hash/sequence (`apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php:25-47`).
- Device fiscal events supply immutable ID, tenant/company/terminal, event type/time, sequence, canonical bytes, and current hash (`apps/pos/src/lib/db/migrations.ts:920-975`).
- Receipt rows have ID, terminal, fiscal hash, hash sequence, payments JSON, and creation time (`apps/pos/src/lib/db/migrations.ts:101-128`, `:317`).
- Payment JSON children have no UUID/time/sequence/hash; they contain method, repository, amount, card/reference, method code, and instrument fields (`apps/pos/src/lib/offline/receiptService.ts:509-526`).
- Device count rows have real UUIDs, Z ID, payment-method ID, amounts, direction, count, and creation time (`apps/pos/src/lib/db/migrations.ts:492-505`).
- `local_shifts.id` is the fiscal shift UUID and `session_id` is separately persisted (`apps/pos/src/lib/db/migrations.ts:1631-1667`).
- Server Z-session evidence persists session and shift identities (`pos_z_session_events` schema and `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:77-114`).
- Server `pos_z_reports` carries terminal and shift but no company/session column (`apps/api/database/migrations/tenant/2026_01_08_190644_create_pos_z_reports_table.php:21-59`).
- Fiscal projection state is `projection_status`, with current values `pending`, `running`, `applied`, and `dead_lettered` (`apps/api/database/migrations/tenant/2026_05_14_100003_create_fiscal_event_projections_table.php:30-63`).
- Server Z/count producers are `ReportGenerationService::generateZReport()` and `ZReportSyncController`; their current event dispatch occurs after the Z/count transaction (`apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:188-205`, `:412-459`; `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:222-278`).
- Correct device Z producer: `apps/pos/src/lib/offline/zReportService.ts`, not the nonexistent `apps/pos/src/services/zReportService.ts`.
- Correct server projector: `apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php`, not a Fiscal-module path.
- Generated TypeScript output is `packages/shared/types/generated.d.ts` (`apps/api/config/typescript-transformer.php:45-53`).

**PL-101 — Provider ownership.**

- Fiscal commands: `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php:47-76`.
- Treasury commands: `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:236-245`.
- POS commands: `apps/api/app/Modules/POS/Providers/POSServiceProvider.php:82-96`.
- No task references `app/Console/Kernel.php`.

## 5. Complete writer and lock census

**PL-150 — Single repository write port.** Every repository balance mutation ends in `TreasuryMovementServiceInterface::record()` or `::transfer()` (`apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php:16-29`, `:57`, `:96`). The physical movement insert and repository balance save remain centralized in `TreasuryMovementService.php:554-610`.

**PL-151 — Current caller census.**

| Owner | Current call at inspected HEAD |
|---|---|
| `apps/api/app/Modules/Income/Application/Services/IncomeService.php` | `record()` at `:175` |
| `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php` | `record()` at `:452`, `:488`, `:816`, `:998` |
| `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php` | `record()` at `:1349`, `:2063` |
| `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php` | `record()` at `:211` |
| `apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php` | `record()` at `:602` |
| `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php` | `record()` at `:729`, `:1087` |
| `apps/api/app/Modules/Treasury/Application/Services/OutboundInstrumentService.php` | `record()` at `:140`, `:298`, `:452` |
| `apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php` | `record()` at `:277`, `:495` |
| `apps/api/app/Modules/Treasury/Application/Services/RepositoryOpeningBalanceService.php` | `record()` at `:152` |
| `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php` | `transfer()` at `:89` |
| `apps/api/app/Modules/Treasury/Application/Services/RepositoryAdjustmentService.php` | `record()` at `:234` |
| `apps/api/app/Modules/Fiscal/Application/Services/RefundCompensationService.php` | `record()` at `:308` |
| `apps/api/app/Modules/Treasury/Application/Services/AcquirerFeeService.php` | `record()` at `:133` |
| `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php` | `record()` at `:1560` |
| `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php` | `record()` at `:249` |
| `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php` | `record()` at `:267` |

T0 reruns:

```bash
rg -n -- '->(record|transfer)\(' apps/api/app/Modules
rg -n "balance\\s*=|increment\\(['\"]balance|decrement\\(['\"]balance" apps/api/app/Modules
```

Any new writer or direct balance mutation blocks T4 until added to this census and reviewed.

**PL-152 — Lock order.**

1. Tenant journal-entry numbering advisory lock.
2. Company GL-chain advisory lock.
3. Repository transfer document row.
4. Payment-repository rows in ascending UUID-byte order.
5. Movement/idempotency rows.

`TreasuryMovementService` takes the company advisory lock before sorted repository row locks (`apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:238-268`). `GeneralLedgerService` owns company and tenant numbering locks (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3696-3772`, `:5659-5730`).

**PL-153 — Transfer cardinality.** One immutable manual transfer document produces exactly two repository movements, one `source` and one `destination`, and zero-or-one journal entry. It never contains or references raw POS cash evidence. Posted documents cannot be edited or deleted; correction is a linked reversal.

**PL-154 — Inventory buffer ownership.** `InventoryGlPostingBuffer` belongs only to inventory posting (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:13-17`, `:34`, `:56-92`). W-CASH never injects, enqueues, or flushes it. T4, T8, and T9 tests assert zero calls.

## 6. Complete migration schema contract

**PL-200 — Universal DDL rules.**

- All server migrations are tenant migrations under `apps/api/database/migrations/tenant`.
- Application code supplies UUIDv7 IDs; no DB-generated UUID defaults.
- Money is `decimal(20,3)` and transported as strings.
- All new timestamps are PostgreSQL `timestamptz`.
- `created_at`/`updated_at` are non-null unless a table is explicitly immutable and carries only `created_at`.
- Foreign keys use `ON DELETE RESTRICT`.
- JSONB columns have the exact DTO named below.
- Technical state/type columns have PHP string-backed enums, Eloquent casts, and named checks.
- Opaque `raw_event_name` is evidence copied from an existing source, not a new semantic type or Q12 vocabulary.
- Every operator-edited business unique includes `company_id`.
- PostgreSQL tests inspect type, length/precision, nullability, default, FK columns/targets/order/delete rule, checks, indexes, uniqueness, and partial predicates.

### PL-201 — Migration 1: supporting ownership keys

`apps/api/database/migrations/tenant/2026_09_06_220000_add_w_cash_supporting_ownership_keys.php` creates these non-primary supporting uniques before any child FK:

| Table | Supporting unique |
|---|---|
| `companies` | `(id, tenant_id)` |
| `locations` | `(id, company_id)` |
| `pos_terminals` | `(id, tenant_id, company_id, location_id)` |
| `pos_shifts` | `(id, terminal_id)` |
| `pos_z_reports` | `(id, shift_id, terminal_id)` |
| `payment_repositories` | `(id, company_id)` |
| `journal_entries` | `(id, company_id)` |
| `fiscal_events` | `(id, tenant_id, company_id, terminal_id)` |

Each key is created with an explicit name. The migration fails before DDL if any table/column is absent. Because each first column is already a primary key, creation cannot encounter duplicate rows.

### PL-210 — Migration 2: observation policy and cutovers

`apps/api/database/migrations/tenant/2026_09_06_220100_create_w_cash_projection_policy_and_cutovers.php` creates `fiscal_projection_policy_records`:

| Column | Type/null/default/FK |
|---|---|
| `id` | uuid, PK, non-null, no default |
| `tenant_id` | uuid, non-null |
| `company_id` | uuid, non-null |
| `projector_name` | varchar(64), non-null |
| `policy_code` | varchar(24), non-null, default `observe_only`; `FiscalProjectionPolicyCode::ObserveOnly` is the only case |
| `effective_at` | timestamptz, non-null |
| `actor_id` | uuid, non-null, FK `users(id)` RESTRICT |
| `reason_text` | text, non-null |
| `evidence` | jsonb, non-null, default `{}`, DTO `FiscalProjectionPolicyEvidenceData` |
| `created_at` | timestamptz, non-null, current timestamp |
| `updated_at` | timestamptz, non-null, current timestamp |

Constraints:

- FK `(company_id, tenant_id) → companies(id, tenant_id)` RESTRICT.
- Checks: nonblank projector/reason; `policy_code='observe_only'`; evidence is an object.
- Unique `(id, tenant_id, company_id)`, created before child FKs.
- Unique `(company_id, projector_name, effective_at, id)`.
- Lookup index `(company_id, projector_name, effective_at DESC, id DESC)`.
- Rows are append-only through service and PostgreSQL update/delete trigger.

Create `fiscal_projection_cutovers`:

| Column | Type/null/default/FK |
|---|---|
| `id` | uuid, PK, non-null |
| `tenant_id` | uuid, non-null |
| `company_id` | uuid, non-null |
| `location_id` | uuid, non-null |
| `terminal_id` | uuid, non-null |
| `projector_name` | varchar(64), non-null |
| `source_rail` | varchar(8), non-null, enum `FiscalSourceRail::{V2,V3}` |
| `lower_occurred_at` | timestamptz, non-null |
| `lower_source_id` | uuid, non-null |
| `upper_occurred_at` | timestamptz, non-null |
| `upper_source_id` | uuid, non-null |
| `policy_record_id` | uuid, non-null |
| `boundary_evidence` | jsonb, non-null, default `{}`, DTO `FiscalCutoverBoundaryEvidenceData` |
| `actor_id` | uuid, non-null, FK `users(id)` RESTRICT |
| `created_at` | timestamptz, non-null, current timestamp |
| `updated_at` | timestamptz, non-null, current timestamp |

Constraints:

- FK `(terminal_id,tenant_id,company_id,location_id) → pos_terminals(id,tenant_id,company_id,location_id)` RESTRICT.
- FK `(location_id,company_id) → locations(id,company_id)` RESTRICT.
- FK `(policy_record_id,tenant_id,company_id) → fiscal_projection_policy_records(id,tenant_id,company_id)` RESTRICT.
- Unique support `(id,tenant_id,company_id)`, created before evidence FKs.
- Check `ROW(lower_occurred_at,lower_source_id) < ROW(upper_occurred_at,upper_source_id)`.
- Unique complete range identity.
- Lookup index `(company_id,terminal_id,projector_name,source_rail,lower_occurred_at,lower_source_id,upper_occurred_at,upper_source_id)`.
- Half-open membership is `lower <= (occurred_at,id) < upper`.
- Creation takes a transaction-scoped advisory lock over tenant/company/location/terminal/projector/rail and rejects overlaps using `existing.lower < requested.upper AND existing.upper > requested.lower`.

Extend `fiscal_event_projections` after the policy table exists:

| Column | Contract |
|---|---|
| `policy_record_id` | uuid nullable; composite FK `(policy_record_id,fiscal_event tenant_id/company_id derived through service)` is not attempted because the table has no company columns; service validates the event-owned composite before storing the single policy UUID |
| `claim_token` | uuid nullable |
| `lease_expires_at` | timestamptz nullable |
| `blocked_reason_code` | varchar(64) nullable, enum `ProjectionBlockReason` |
| `blocked_evidence` | jsonb nullable, DTO `ProjectionBlockedEvidenceData` |

`ProjectionStatus` adds `blocked`. Checks enforce running claim/lease, non-running null claim/lease, blocked reason/evidence consistency, and known status values. Index `(projection_status,lease_expires_at)` is partial over `pending`, `running`, and `blocked`. Existing unique `(fiscal_event_id,projector_name)` remains.

### PL-220 — Migration 3: generic manual transfer documents

`apps/api/database/migrations/tenant/2026_09_06_220200_create_repository_transfer_documents.php` creates `repository_transfer_documents`:

| Column | Type/null/default/FK |
|---|---|
| `id` | uuid PK, non-null |
| `tenant_id` | uuid, non-null |
| `company_id` | uuid, non-null |
| `location_id` | uuid nullable |
| `source_repository_id` | uuid, non-null |
| `destination_repository_id` | uuid, non-null |
| `amount` | decimal(20,3), non-null |
| `currency_code` | char(3), non-null |
| `origin` | varchar(16), non-null, enum `RepositoryTransferOrigin::{Manual,Reversal}` |
| `idempotency_key` | varchar(128), non-null |
| `reason_text` | text, non-null |
| `status` | varchar(16), non-null, default `draft`, enum `RepositoryTransferStatus::{Draft,Posted,Reversed}` |
| `journal_entry_id` | uuid nullable |
| `reverses_document_id` | uuid nullable |
| `posted_at` | timestamptz nullable |
| `reversed_at` | timestamptz nullable |
| `created_by` | uuid, non-null, FK `users(id)` RESTRICT |
| `evidence` | jsonb, non-null, default `{}`, DTO `RepositoryTransferEvidenceData` |
| `created_at` | timestamptz, non-null |
| `updated_at` | timestamptz, non-null |

Constraints:

- FK `(company_id,tenant_id) → companies(id,tenant_id)`.
- FK `(location_id,company_id) → locations(id,company_id)` when location is non-null.
- Both repository FKs use `(repository_id,company_id) → payment_repositories(id,company_id)`.
- FK `(journal_entry_id,company_id) → journal_entries(id,company_id)`.
- Supporting unique `(id,tenant_id,company_id)`.
- Self-FK `(reverses_document_id,tenant_id,company_id) → repository_transfer_documents(id,tenant_id,company_id)`.
- Source and destination differ; amount `> 0`; currency is uppercase ASCII; reason nonblank.
- `draft`: no journal/posted/reversed time or reversal target.
- `posted`: `posted_at` present and no reversal fields.
- `reversed`: `posted_at`, `reversed_at`, and reversal target present.
- `origin=reversal` iff `reverses_document_id` is present.
- Unique `(company_id,idempotency_key)`.
- Index `(company_id,status,created_at,id)`.

Extend `repository_movements`:

| Column | Contract |
|---|---|
| `transfer_document_id` | uuid nullable |
| `transfer_leg` | varchar(16) nullable, enum `RepositoryTransferLeg::{Source,Destination}` |

Constraints:

- FK `(transfer_document_id,tenant_id,company_id) → repository_transfer_documents(id,tenant_id,company_id)`.
- Both fields are null or both non-null.
- Partial unique `(company_id,transfer_document_id,transfer_leg) WHERE transfer_document_id IS NOT NULL`.

No transfer-document column references `shift_cash_*`, `fiscal_events`, cash-operation IDs, raw event names, reason codes, configurations, or inferred destinations.

### PL-230 — Migration 4: policy-neutral shift cash evidence

`apps/api/database/migrations/tenant/2026_09_06_220300_create_shift_cash_source_evidence.php` creates `shift_cash_source_evidence`:

| Column | Type/null/default/FK |
|---|---|
| `id` | uuid PK, non-null |
| `tenant_id` | uuid, non-null |
| `company_id` | uuid, non-null |
| `location_id` | uuid, non-null |
| `terminal_id` | uuid, non-null |
| `shift_id` | uuid, non-null |
| `session_id` | uuid nullable |
| `source_rail` | varchar(8), non-null, enum `FiscalSourceRail` |
| `source_id` | uuid, non-null |
| `source_occurred_at` | timestamptz, non-null |
| `raw_event_name` | varchar(64), non-null |
| `amount` | decimal(20,3), non-null |
| `currency_code` | char(3), non-null |
| `reason_text` | text nullable |
| `authored_link_id` | uuid nullable |
| `source_hash` | char(64), non-null |
| `raw_evidence` | jsonb, non-null, DTO `ShiftCashRawEvidenceData` |
| `state` | varchar(16), non-null, default `pending`, enum `ShiftCashEvidenceState::{Pending,Recorded,Blocked,Conflict}` |
| `block_reason_code` | varchar(64) nullable, enum `ShiftCashEvidenceBlockReason::{OwnerRulingRequired,SourceLinkageMissing,SourceIncomplete,PolicyMissing}` |
| `semantic_fact_id` | uuid nullable |
| `created_at` | timestamptz, non-null |
| `updated_at` | timestamptz, non-null |

Create `shift_cash_semantic_facts` first where required for the nullable FK:

| Column | Type/null/default/FK |
|---|---|
| `id` | uuid PK, non-null |
| `tenant_id` | uuid, non-null |
| `company_id` | uuid, non-null |
| `location_id` | uuid, non-null |
| `terminal_id` | uuid, non-null |
| `shift_id` | uuid, non-null |
| `session_id` | uuid nullable |
| `authored_link_id` | uuid, non-null |
| `economic_occurred_at` | timestamptz, non-null |
| `authored_event_name` | varchar(64), non-null |
| `amount` | decimal(20,3), non-null |
| `currency_code` | char(3), non-null |
| `reason_text` | text nullable |
| `semantic_fingerprint` | char(64), non-null |
| `state` | varchar(16), non-null, default `blocked`, enum `ShiftCashSemanticState::{Observed,Blocked,Conflict}` |
| `block_reason_code` | varchar(64) nullable, same technical block enum |
| `collision_evidence` | jsonb nullable, DTO `ShiftCashCollisionEvidenceData` |
| `created_at` | timestamptz, non-null |
| `updated_at` | timestamptz, non-null |

Ownership and identity:

- Both tables use terminal composite FK `(terminal_id,tenant_id,company_id,location_id)`.
- Both use shift FK `(shift_id,terminal_id)`.
- Raw source rows use unique `(company_id,source_rail,source_id)`.
- Semantic facts use supporting unique `(id,tenant_id,company_id)`.
- Semantic facts use unique `(company_id,semantic_fingerprint)`.
- Raw `semantic_fact_id` FK is `(semantic_fact_id,tenant_id,company_id) → shift_cash_semantic_facts(id,tenant_id,company_id)`.
- Raw index `(company_id,state,created_at,id)`.
- Semantic index `(company_id,state,economic_occurred_at,id)`.
- Hash/currency/nonnegative-amount/nonblank-name checks.
- `recorded` requires semantic fact and no block reason.
- `blocked` requires a block reason.
- `pending` has neither result nor block.
- Semantic `observed` requires no block/collision; `blocked` requires block; `conflict` requires collision evidence.
- No destination, repository, account, journal, transfer, alignment, or business reason-code column exists.

### PL-240 — Migration 5: Z close manifests

`apps/api/database/migrations/tenant/2026_09_06_220400_create_pos_z_close_manifests.php` creates `pos_z_close_manifests`:

| Column | Type/null/default/FK |
|---|---|
| `id` | uuid PK, non-null |
| `tenant_id` | uuid, non-null |
| `company_id` | uuid, non-null |
| `location_id` | uuid, non-null |
| `terminal_id` | uuid, non-null |
| `shift_id` | uuid, non-null |
| `session_id` | uuid, non-null |
| `z_report_id` | uuid, non-null |
| `schema_version` | smallint, non-null, default `1` |
| `close_event_id` | uuid, non-null |
| `close_event_hash` | char(64), non-null |
| `z_event_id` | uuid, non-null |
| `z_event_hash` | char(64), non-null |
| `lower_sequence` | bigint, non-null |
| `upper_sequence` | bigint, non-null |
| `member_count` | integer, non-null |
| `ordered_members` | jsonb, non-null, DTO `ZCloseManifestMemberListData` |
| `canonical_payload` | jsonb, non-null, DTO `ZCloseManifestData` |
| `manifest_hash` | char(64), non-null |
| `created_at` | timestamptz, non-null |

Constraints:

- Terminal composite ownership FK.
- Location/company FK.
- Shift/terminal FK.
- Z/shift/terminal FK `(z_report_id,shift_id,terminal_id)`.
- Close and Z event composite FKs to `fiscal_events(id,tenant_id,company_id,terminal_id)`.
- Supporting unique `(id,tenant_id,company_id,terminal_id,shift_id,session_id,z_report_id)`.
- Unique `(company_id,z_report_id)`.
- Unique `(company_id,manifest_hash)`.
- Checks: schema version `=1`, lower `<=` upper, member count equals JSON array length and is at least two, hashes lowercase hex, JSON shapes valid.
- Index `(company_id,terminal_id,shift_id,session_id,z_report_id)`.

Device SQLite v70 creates `z_close_manifests` with the same identities, INTEGER version/sequence/count fields, TEXT hashes/times/JSON, `sync_state` in `pending|synced|conflict`, unique Z ID/hash, and `(sync_state,created_at)` index.

Device SQLite v71 creates `z_close_manifest_outbox`:

| Column | SQLite contract |
|---|---|
| `id` | TEXT PK, non-null |
| `manifest_id` | TEXT, non-null, FK manifest RESTRICT |
| `state` | TEXT, non-null, default `pending`, check `pending|sending|sent|failed` |
| `attempts` | INTEGER, non-null, default `0`, check `>=0` |
| `claim_token` | TEXT nullable |
| `lease_expires_at` | TEXT nullable |
| `last_error_json` | TEXT nullable |
| `created_at` | TEXT non-null |
| `updated_at` | TEXT non-null |

Unique `manifest_id`; index `(state,lease_expires_at)`. v70 must complete before v71. W-LOT-B owns v68/v69, so W-CASH dispatch cannot start until those migrations are accepted.

### PL-250 — Migration 6: cash-count delivery obligations

`apps/api/database/migrations/tenant/2026_09_06_220500_create_cash_count_delivery_obligations.php` creates:

| Column | Type/null/default/FK |
|---|---|
| `id` | uuid PK, non-null |
| `tenant_id` | uuid, non-null |
| `company_id` | uuid, non-null |
| `location_id` | uuid, non-null |
| `terminal_id` | uuid, non-null |
| `shift_id` | uuid, non-null |
| `z_report_id` | uuid, non-null |
| `consumer` | varchar(24), non-null, enum `CashCountConsumer::{Treasury,Compliance,StoredEvent}` |
| `event_payload` | jsonb, non-null, DTO `CashCountRecordedData` |
| `state` | varchar(16), non-null, default `pending`, enum `CashCountDeliveryState::{Pending,Running,Applied,AlreadyExists,Blocked,Failed}` |
| `attempts` | integer, non-null, default `0` |
| `claim_token` | uuid nullable |
| `lease_expires_at` | timestamptz nullable |
| `last_error` | jsonb nullable, DTO `CashCountDeliveryErrorData` |
| `outcome` | jsonb nullable, DTO `CashCountConsumerOutcomeData` |
| `completed_at` | timestamptz nullable |
| `created_at` | timestamptz, non-null |
| `updated_at` | timestamptz, non-null |

Constraints:

- Terminal composite ownership FK.
- Location/company FK.
- Shift/terminal FK.
- Z/shift/terminal FK.
- Unique `(company_id,z_report_id,consumer)`.
- Index `(company_id,consumer,state,lease_expires_at)`.
- Attempts nonnegative.
- Running requires claim/lease.
- Non-running has no claim/lease.
- Applied/already-exists require outcome/completed time and no error.
- Blocked requires typed outcome/completed time.
- Failed requires last error and no completed time.

### PL-260 — Migration 7: canonical session reconciliation

`apps/api/database/migrations/tenant/2026_09_06_220600_create_pos_session_reconciliations.php` creates `pos_session_reconciliations`:

| Column | Type/null/default/FK |
|---|---|
| `id` | uuid PK, non-null |
| `tenant_id` | uuid, non-null |
| `company_id` | uuid, non-null |
| `location_id` | uuid, non-null |
| `terminal_id` | uuid, non-null |
| `shift_id` | uuid, non-null |
| `session_id` | uuid, non-null |
| `z_report_id` | uuid, non-null |
| `manifest_id` | uuid, non-null |
| `state` | varchar(20), non-null, default `pending_sync`, enum `SessionReconciliationState::{PendingSync,Blocked,Mismatch,Matched}` |
| `dependency_version` | bigint, non-null, default `0` |
| `current_run_id` | uuid nullable |
| `claim_token` | uuid nullable |
| `lease_expires_at` | timestamptz nullable |
| `attempts` | integer, non-null, default `0` |
| `block_reason_code` | varchar(64) nullable, enum `SessionReconciliationBlockReason::{OwnerRulingRequired,ManifestIncomplete,ProjectionIncomplete,DependencyUnavailable}` |
| `last_error` | jsonb nullable, DTO `SessionReconciliationErrorData` |
| `created_at` | timestamptz, non-null |
| `updated_at` | timestamptz, non-null |

Constraints:

- Manifest FK `(manifest_id,tenant_id,company_id,terminal_id,shift_id,session_id,z_report_id)` to the manifest supporting unique.
- Unique business identity `(tenant_id,company_id,terminal_id,shift_id,session_id,z_report_id)`.
- Supporting unique `(id,tenant_id,company_id)`.
- Index `(company_id,state,lease_expires_at)`.
- Nonnegative attempts/dependency version.
- A claim requires `state=pending_sync`, token, and lease.
- `blocked` requires block reason.
- `matched|mismatch` require a current run and no block/claim.
- `pending_sync` may have no current run initially or retain the prior current run while a new dependency version waits.

Create immutable `pos_session_reconciliation_runs`:

| Column | Contract |
|---|---|
| `id` | uuid PK, non-null |
| `tenant_id` | uuid, non-null |
| `company_id` | uuid, non-null |
| `reconciliation_id` | uuid, non-null |
| `run_number` | bigint, non-null |
| `captured_dependency_version` | bigint, non-null |
| `input_fingerprint` | char(64), non-null |
| `input_snapshot` | jsonb, non-null, DTO `SessionReconciliationInputData` |
| `result_code` | varchar(24), non-null, enum `SessionReconciliationResultCode::{Incomplete,Blocked,Matched,Mismatch}` |
| `result_cells` | jsonb, non-null, DTO `SessionReconciliationResultData` |
| `created_at` | timestamptz, non-null |

Constraints:

- FK `(reconciliation_id,tenant_id,company_id) → pos_session_reconciliations(id,tenant_id,company_id)`.
- Supporting unique `(id,reconciliation_id,tenant_id,company_id)`.
- Unique `(company_id,reconciliation_id,run_number)`.
- Nonunique index `(company_id,reconciliation_id,input_fingerprint)`, deliberately not unique.
- Index `(company_id,reconciliation_id,created_at DESC)`.
- Positive run number; nonnegative dependency version; hash/JSON checks.
- Runs cannot be updated/deleted.

After runs exist, add head FK:

`(current_run_id,id,tenant_id,company_id) → pos_session_reconciliation_runs(id,reconciliation_id,tenant_id,company_id)` RESTRICT.

Create immutable `pos_session_reconciliation_supersessions`:

| Column | Contract |
|---|---|
| `id` | uuid PK |
| `tenant_id` | uuid non-null |
| `company_id` | uuid non-null |
| `reconciliation_id` | uuid non-null |
| `superseded_run_id` | uuid non-null |
| `replacement_run_id` | uuid non-null |
| `reason_code` | varchar(32), enum `ReconciliationSupersessionReason::{DependencyChanged,CorrectedInput,LateMember,HistoricalFingerprintRecurred}` |
| `dependency_version` | bigint non-null |
| `created_at` | timestamptz non-null |

Both run FKs use `(run_id,reconciliation_id,tenant_id,company_id)`. Old/replacement differ. Unique `(company_id,reconciliation_id,replacement_run_id)`. Index `(company_id,reconciliation_id,created_at)`.

Create immutable `pos_session_reconciliation_dependency_changes`:

| Column | Contract |
|---|---|
| `id` | uuid PK |
| `tenant_id` | uuid non-null |
| `company_id` | uuid non-null |
| `reconciliation_id` | uuid non-null |
| `dependency_version` | bigint non-null |
| `dependency_kind` | varchar(32), enum `ReconciliationDependencyKind::{Manifest,FiscalProjection,ZReport,ZCount,TenderClassification,LotEvidence,RepositoryEvidence}` |
| `dependency_identity` | varchar(160), non-null |
| `dependency_hash` | char(64), non-null |
| `occurred_at` | timestamptz, non-null |
| `created_at` | timestamptz, non-null |

Composite head FK; unique `(company_id,reconciliation_id,dependency_version)`; index `(company_id,reconciliation_id,occurred_at,id)`.

## 7. State machines and algorithms

**PL-300 — Mutation outcome.**

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

Every mutator returns `MutationResultData` or a typed result containing the same outcome. Read-only audit services return fingerprints directly and do not pretend to produce `already_exists`.

**PL-310 — Projection policy/cutover.**

- Exact policy/range replay with identical evidence returns `already_exists`.
- Same identity with different content returns `conflict`.
- Overlapping range returns `conflict`.
- Policy records and cutovers are immutable.
- Recovery may claim only expired leases.
- The only policy code is `observe_only`.

**PL-320 — Transfer document.**

- `draft → posted → reversed`.
- Posting follows PL-152 and writes exactly two movement legs.
- Same idempotency key/content returns the existing document with `already_exists`.
- Same key/different content returns `conflict`.
- No POS/fiscal cash evidence can call the transfer service before T11.
- Reason is required free text; no reason-code mapping exists.

**PL-330 — Raw evidence.**

- `pending → recorded` only after raw-source ownership, hash, and semantic-link validation.
- `pending → blocked` for owner ruling, missing linkage, missing policy, or incomplete custody fields.
- `pending|blocked → conflict` only when the same source identity or authored linkage has incompatible immutable content.
- No state creates money.
- A retry of identical raw evidence returns `already_exists`; a changed immutable source returns `conflict`.

**PL-340 — Cross-rail semantic identity.**

Raw identity is `(tenant_id,company_id,source_rail,source_id)` and never enters the economic fingerprint.

The semantic fingerprint is SHA-256 over RFC 8785 canonical JSON containing:

```text
schema_version = 1
tenant_id
company_id
location_id
terminal_id
shift_id
session_id | "absent"
authored_link_id
economic_occurred_at
authored_event_name
amount_scale_3
currency_code
```

Custody-boundary rules:

- For v2, `authored_link_id` is the v2 operation UUID; `economic_occurred_at`, name, amount, reason, terminal, and shift come from that operation and its owning shift/terminal.
- For v3 with payload `cash_drawer_operation_id`, the service resolves that exact v2 row and uses the v2-authored values above. Rail/source UUID, v3 ingest time, and v3 event UUID remain only in raw evidence.
- For v3 with no authenticated authored linkage, no time-bucket heuristic is permitted. The row remains `blocked/source_linkage_missing`; it has no semantic fingerprint/fact and cannot post.
- A linked v2/v3 pair creates one semantic fact and two raw-source rows pointing to it.
- Same authored linkage and same semantic content yields `already_exists`.
- Same linkage with changed custody/name/amount/currency yields `conflict`.
- Near-collisions differing by terminal, location, shift, session, authored time, opaque name, amount, or currency remain distinct.
- `authored_event_name` is copied without interpreting `DEPOSIT`, `PAYOUT`, `CASH_IN`, `CASH_OUT`, or `SAFE_DROP`.

**PL-350 — Manifest member derivation.**

Manifest v1 uses the exact current Z contributors queried by `apps/pos/src/lib/offline/zReportService.ts:178-225`. Members sort bytewise by namespace, occurred-at UTC string, sequence sentinel/value, then source UUID bytes. Canonical JSON uses RFC 8785.

| Namespace | Source and membership | `source_id` | `occurred_at` | `sequence` | `event_hash` | `economic_payload_hash` |
|---|---|---|---|---|---|---|
| `fiscal_event` | `fiscal_events` for the terminal/session and `chain_context IN ('operational','z_session')`, through the returned close/Z sequence | actual row UUID | `event_time_device` | actual `sequence_number` | actual `current_hash` | SHA-256 canonical decoded fiscal payload |
| `receipt` | Exact receipt query selected by `shift_receipt_anchors.opening_hash_sequence`, terminal, non-training, and upper receipt sequence captured before close | actual receipt UUID | normalized `created_at` | actual `hash_sequence` | actual `fiscal_hash` | SHA-256 canonical `{receipt_kind,subtotal,tax_amount,discount_amount,total,currency,payments_json}` |
| `receipt_payment` | Every payment-array element from each selected receipt, preserving zero-based array ordinal | UUIDv5 namespace `w-cash-payment-v1`, name `receipt_id + ":" + ordinal` | parent receipt `created_at` | `receipt.hash_sequence*1000+ordinal`; reject ≥1000 children | SHA-256 canonical `{parent_fiscal_hash,ordinal,payment}` | SHA-256 canonical `{method_code,payment_method_id,repository_id,amount,instrument_type,instrument_serial,transaction_reference}` |
| `cash_operation` | `offline_cash_drawer_ops WHERE shift_id=?` | actual row UUID | normalized `created_at` | sentinel `-1` | SHA-256 canonical immutable full row excluding sync lifecycle | SHA-256 canonical `{type,amount,reason,terminal_id,shift_id}` |
| `legacy_refund` | Exact `getRefundRecordsForShift()` result | actual row UUID | stored creation/device time | sentinel `-1` | SHA-256 canonical immutable full row | SHA-256 canonical refund/cash-impact fields |
| `account_payment` | Exact `getAccountPaymentRecordsForShift()` result | actual row UUID | normalized `created_at` | sentinel `-1` | SHA-256 canonical immutable full row | SHA-256 canonical `{method_code,amount,cash_impact,currency,terminal_id,shift_id}` |
| `z_count` | Newly created `z_report_counts` for the Z | actual row UUID | normalized `created_at` | sentinel `-1` | SHA-256 canonical immutable count row | SHA-256 canonical `{payment_method_id,currency_code,expected_amount,actual_amount,variance_amount,variance_direction,transaction_count}` |
| `session_close` | Returned `SESSION_CLOSE` fiscal append result | actual fiscal-event UUID | event device time | actual sequence | actual current hash | canonical payload hash |
| `z_report_event` | Returned Z fiscal append result | actual fiscal-event UUID | event device time | actual sequence | actual current hash | canonical payload hash |

Requirements:

- The shift/session row and anchor are locked/read inside the same SQLite write transaction.
- Real close/Z IDs and hashes are obtained before manifest hashing.
- Any missing required source field aborts the local transaction; it is never silently omitted.
- The manifest records the upper receipt/fiscal sequence actually observed at close.
- W-LOT-B lot-evidence membership is **not** invented in manifest v1. Its separate accepted evidence hash enters the server dependency snapshot only. A later manifest schema version may add lot-evidence members after W-LOT-B’s device persistence contract exists.
- Server ingest independently re-sorts/re-hashes and returns `applied`, `already_exists`, or `conflict`.

**PL-360 — Reconciliation concurrency and historical fingerprint recurrence.**

1. Every dependency writer calls `ReconciliationDependencyVersionService::touch()` in its dependency transaction.
2. `touch()` locks the unique head `FOR UPDATE`, increments `dependency_version`, and inserts one immutable dependency-change row.
3. The worker locks the head, captures version `V`, manifest identity, full custody identity, and current-run ID.
4. It builds immutable inputs in deterministic order.
5. `ShiftExpectedCashService::breakdownForManifest()` performs all expected-cash arithmetic; `PosSessionReconciliationService` only orchestrates completeness, comparisons, and persistence.
6. Before publication, the worker re-locks the head. If its version differs from `V`, the attempt is discarded and retried.
7. It hashes the canonical input DTO.
8. If the fingerprint equals the current run’s fingerprint, it returns `already_exists`.
9. If the fingerprint exists only historically, it appends a new run occurrence with the same fingerprint and next run number; it never reactivates or mutates the historical run.
10. In one transaction it inserts the new run, inserts a supersession from the former current run, updates `current_run_id`, and updates head state.
11. The unique head business identity ensures one head; the single composite-constrained `current_run_id` ensures exactly one current run.
12. Runs and supersessions are append-only; no `is_current` flag is stored on run history.
13. Snapshot inputs include manifest ID/hash/version/members, fiscal member IDs/hashes/projection status, Z/count hashes, authored tender-classification revision or dependency-unavailable, lot-evidence hash/availability, and repository-evidence availability.
14. Q10–Q13-dependent cells contain only `owner_ruling_required`.
15. Repository comparison remains blocked/unavailable until T11; fiscal reconciliation may match independently.

## 8. Full CLI signatures

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
  {--effective-at= : Required ISO-8601 timestamp}
  {--actor= : Required user UUID}
  {--reason= : Required free-text append-only reason}
  {--evidence-json={} : FiscalProjectionPolicyEvidenceData JSON}
  {--format=table : table|json}
```

The command always records `observe_only`; no policy argument can select another behavior.

```text
fiscal:projection-cutover:add
  {company : Company UUID}
  {location : Location UUID}
  {terminal : Terminal UUID}
  {projector : Projector name}
  {rail : v2|v3}
  {--lower-occurred-at= : Required inclusive timestamp}
  {--lower-source-id= : Required inclusive UUID tie-breaker}
  {--upper-occurred-at= : Required exclusive timestamp}
  {--upper-source-id= : Required exclusive UUID tie-breaker}
  {--policy-record= : Required policy-record UUID}
  {--actor= : Required user UUID}
  {--evidence-json={} : FiscalCutoverBoundaryEvidenceData JSON}
  {--format=table : table|json}
```

```text
fiscal:projection-lease:recover
  {--limit=100 : Positive maximum claims}
  {--lease-seconds=120 : Positive lease duration}
  {--format=table : table|json}
```

```text
treasury:repository-transfer:replay
  {document : Transfer document UUID}
  {--actor= : Required user UUID}
  {--reason= : Required free-text replay reason}
  {--format=table : table|json}
```

```text
pos:cash-count-deliver
  {--obligation= : Optional obligation UUID}
  {--limit=100 : Positive maximum claims}
  {--lease-seconds=120 : Positive lease duration}
  {--format=table : table|json}
```

```text
pos:cash-reconcile
  {company : Company UUID}
  {z-report : Z-report UUID}
  {--expected-dependency-version= : Optional nonnegative optimistic version}
  {--format=table : table|json}
```

```text
tenant:backup
  {slug? : Tenant slug; omit with --all}
  {--all : Back up every tenant}
  {--format=table : table|json}
```

Backup JSON contains full `tenant_id`, `tenant_slug`, `backup_id`, `status`, `file_path`, `size_bytes`, and 64-character `sha256`.

No cash-configuration, alignment, reason-code, mapping, or activation CLI exists before T11.

## 9. Exact schema-owned files

**PL-400 — Migrations**

- `apps/api/database/migrations/tenant/2026_09_06_220000_add_w_cash_supporting_ownership_keys.php`
- `apps/api/database/migrations/tenant/2026_09_06_220100_create_w_cash_projection_policy_and_cutovers.php`
- `apps/api/database/migrations/tenant/2026_09_06_220200_create_repository_transfer_documents.php`
- `apps/api/database/migrations/tenant/2026_09_06_220300_create_shift_cash_source_evidence.php`
- `apps/api/database/migrations/tenant/2026_09_06_220400_create_pos_z_close_manifests.php`
- `apps/api/database/migrations/tenant/2026_09_06_220500_create_cash_count_delivery_obligations.php`
- `apps/api/database/migrations/tenant/2026_09_06_220600_create_pos_session_reconciliations.php`

**PL-401 — Models**

- `apps/api/app/Modules/Fiscal/Domain/Models/FiscalProjectionPolicyRecord.php`
- `apps/api/app/Modules/Fiscal/Domain/Models/FiscalProjectionCutover.php`
- `apps/api/app/Modules/Treasury/Domain/RepositoryTransferDocument.php`
- `apps/api/app/Modules/Treasury/Domain/ShiftCashSourceEvidence.php`
- `apps/api/app/Modules/Treasury/Domain/ShiftCashSemanticFact.php`
- `apps/api/app/Modules/POS/Domain/PosZCloseManifest.php`
- `apps/api/app/Modules/POS/Domain/CashCountDeliveryObligation.php`
- `apps/api/app/Modules/POS/Domain/PosSessionReconciliation.php`
- `apps/api/app/Modules/POS/Domain/PosSessionReconciliationRun.php`
- `apps/api/app/Modules/POS/Domain/PosSessionReconciliationSupersession.php`
- `apps/api/app/Modules/POS/Domain/PosSessionReconciliationDependencyChange.php`

**PL-402 — Enums**

- MODIFY `apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionStatus.php`
- CREATE `apps/api/app/Modules/Fiscal/Domain/Enums/FiscalProjectionPolicyCode.php`
- CREATE `apps/api/app/Modules/Fiscal/Domain/Enums/FiscalSourceRail.php`
- CREATE `apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionBlockReason.php`
- CREATE `apps/api/app/Modules/Treasury/Domain/Enums/RepositoryTransferOrigin.php`
- CREATE `apps/api/app/Modules/Treasury/Domain/Enums/RepositoryTransferStatus.php`
- CREATE `apps/api/app/Modules/Treasury/Domain/Enums/RepositoryTransferLeg.php`
- CREATE `apps/api/app/Modules/Treasury/Domain/Enums/ShiftCashEvidenceState.php`
- CREATE `apps/api/app/Modules/Treasury/Domain/Enums/ShiftCashSemanticState.php`
- CREATE `apps/api/app/Modules/Treasury/Domain/Enums/ShiftCashEvidenceBlockReason.php`
- CREATE `apps/api/app/Modules/POS/Domain/Enums/CashCountConsumer.php`
- CREATE `apps/api/app/Modules/POS/Domain/Enums/CashCountDeliveryState.php`
- CREATE `apps/api/app/Modules/POS/Domain/Enums/SessionReconciliationState.php`
- CREATE `apps/api/app/Modules/POS/Domain/Enums/SessionReconciliationResultCode.php`
- CREATE `apps/api/app/Modules/POS/Domain/Enums/SessionReconciliationBlockReason.php`
- CREATE `apps/api/app/Modules/POS/Domain/Enums/ReconciliationDependencyKind.php`
- CREATE `apps/api/app/Modules/POS/Domain/Enums/ReconciliationSupersessionReason.php`
- CREATE `apps/api/app/Shared/Domain/Enums/MutationOutcome.php`

**PL-403 — JSONB DTOs**

- `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalProjectionPolicyEvidenceData.php`
- `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalCutoverBoundaryEvidenceData.php`
- `apps/api/app/Modules/Fiscal/Application/DTOs/ProjectionBlockedEvidenceData.php`
- `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferEvidenceData.php`
- `apps/api/app/Modules/Treasury/Application/DTOs/ShiftCashRawEvidenceData.php`
- `apps/api/app/Modules/Treasury/Application/DTOs/ShiftCashCollisionEvidenceData.php`
- `apps/api/app/Modules/POS/Application/DTOs/ZCloseManifestMemberData.php`
- `apps/api/app/Modules/POS/Application/DTOs/ZCloseManifestMemberListData.php`
- `apps/api/app/Modules/POS/Application/DTOs/ZCloseManifestData.php`
- `apps/api/app/Modules/POS/Application/DTOs/CashCountRecordedData.php`
- `apps/api/app/Modules/POS/Application/DTOs/CashCountDeliveryErrorData.php`
- `apps/api/app/Modules/POS/Application/DTOs/CashCountConsumerOutcomeData.php`
- `apps/api/app/Modules/POS/Application/DTOs/SessionReconciliationErrorData.php`
- `apps/api/app/Modules/POS/Application/DTOs/SessionReconciliationInputData.php`
- `apps/api/app/Modules/POS/Application/DTOs/SessionReconciliationResultData.php`

## 10. Task dispatch contracts

### PL-410 — T0: read-only census

Production files:

- CREATE `apps/api/app/Modules/Treasury/Presentation/Console/WCashAuditCommand.php`
- CREATE `apps/api/app/Modules/Treasury/Application/Services/WCashAuditService.php`
- CREATE `apps/api/app/Modules/Treasury/Application/DTOs/WCashAuditResultData.php`
- MODIFY `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`

Full signatures:

```php
public function audit(?string $companyId): WCashAuditResultData;
public function handle(WCashAuditService $audit): int;
```

Audit output includes tables/columns/indexes, writer and direct-balance-write census, lock sites, provider owners, generated/local type shadows, rollout variables, active tenants, companies, locations, terminals, Z/session identities, and repository topology.

Red first:

- `apps/api/tests/Feature/Treasury/WCashAuditCommandTest.php::test_json_census_names_projection_status_generated_d_ts_and_real_z_count_contract`
- First failing assertion: `projection.state_column === 'projection_status'` and generated path ends in `generated.d.ts`.
- `::test_second_company_and_selected_second_location_are_reported_without_cross_visibility`
- First failing assertion: company B contains only its selected location/terminal/repositories.
- `::test_repeated_read_returns_the_identical_census_fingerprint_without_writing_rows`
- First failing assertion: fingerprints equal and audited table row counts are unchanged.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/WCashAuditCommandTest.php` — **phpunit PG**.

Reviewer gate: Treasury, tenancy, database architecture.  
Rollback: remove command/service/provider registration; no data mutation exists.

### PL-411 — T12: rollout foundation, dispatched before schema

Production files:

- MODIFY `docker-compose.staging.yml`
- MODIFY `apps/api/docker/entrypoint.sh`
- MODIFY `apps/api/docker/entrypoint-worker.sh`
- MODIFY `apps/api/docker/entrypoint-scheduler.sh`
- MODIFY `apps/api/docker/entrypoint-websocket.sh`
- MODIFY `apps/api/docker/entrypoint.sh`
- MODIFY `apps/api/.env.example`
- MODIFY `apps/api/app/Modules/Tenant/Application/Commands/BackupTenantCommand.php`
- MODIFY `apps/api/routes/api.php`
- MODIFY `apps/web/vite.config.ts`
- MODIFY `apps/web/Dockerfile`
- CREATE `apps/api/docker/verify-w-cash-flags.sh`
- CREATE `apps/api/app/Shared/Presentation/Http/Controllers/FeatureFingerprintController.php`
- CREATE `apps/api/app/Shared/Application/DTOs/FeatureFingerprintData.php`
- CREATE `apps/web/tools/wCashBuildFingerprintPlugin.ts`
- CREATE `apps/web/e2e/w-cash-staging-smoke.spec.ts`
- CREATE `scripts/release/update-dokploy-w-cash-env.sh`
- CREATE `scripts/release/poll-dokploy-deployment.sh`

Full signatures:

```php
public function __invoke(): JsonResponse;
public function handle(TenantBackupService $service): int;
```

Shell interfaces:

```text
scripts/release/update-dokploy-w-cash-env.sh
  <dokploy-url> <api-key> <application-id> <candidate-sha> <read-ui:true|false>

scripts/release/poll-dokploy-deployment.sh
  <dokploy-url> <api-key> <application-id> <title> <candidate-sha>
```

The environment script fetches the complete application record, refuses redacted/missing environment/build-argument blocks, replaces only `APP_BUILD_SHA`, `VITE_APP_BUILD_SHA`, and `VITE_W_CASH_READ_UI_ENABLED`, saves the complete environment, re-reads it, and prints the verified application ID and values. It never prints secrets. Dokploy’s documented `application.one`, `application.saveEnvironment`, and `application.redeploy` contracts are used ([Dokploy Application API](https://docs.dokploy.com/docs/api/application)).

Red first:

- `apps/api/tests/Feature/Shared/FeatureFingerprintControllerTest.php::test_fingerprint_is_candidate_specific_and_contains_literal_flags`
- First failing assertion: `build_sha` equals the injected 40-character SHA.
- `apps/api/tests/Feature/Tenant/BackupTenantCommandJsonTest.php::test_all_json_returns_one_completed_backup_id_and_full_hash_per_active_tenant`
- First failing assertion: tenant result count equals active tenant count.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/Shared/FeatureFingerprintControllerTest.php tests/Feature/Tenant/BackupTenantCommandJsonTest.php` — **phpunit PG**.
- `apps/api/tests/Container/WCashEntrypointFlagsTest.sh::invalid_flag_fails_all_four_entrypoints_before_config_cache`
- First failing assertion: each `--check-only` invocation exits nonzero.
- `::migration_failure_exits_api_entrypoint_nonzero`
- First failing assertion: injected central and tenant failures are not swallowed.
- Command/lane: `sh apps/api/tests/Container/WCashEntrypointFlagsTest.sh` — **phpunit sqlite/supporting container lane**.
- `apps/web/e2e/w-cash-staging-smoke.spec.ts::serves_the_exact_candidate_and_policy_neutral_ui`
- First failing assertion: API SHA, web SHA, and expected SHA are equal and no Q10–Q13 action exists.
- Command/lane: `BASE_URL="$WEB_URL" API_BASE_URL="$API_URL" EXPECTED_BUILD_SHA="$CANDIDATE_SHA" pnpm --filter @autoerp/web exec playwright test e2e/w-cash-staging-smoke.spec.ts` — **vitest-adjacent Playwright release lane**.

Reviewer gate: platform/Dokploy, tenancy backup/migration, API, web release.  
Rollback: redeploy captured predecessor API/web deployments and restore captured environment snapshot.

### PL-412 — T1: exact schemas, models, enums, DTOs, glossary, SQLite v70→v71

Production files: every file in PL-400–PL-403, plus:

- MODIFY `apps/pos/src/lib/db/migrations.ts`
- MODIFY `docs/glossary.md`
- MODIFY `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php` only to classify the operator-facing transfer document if required by `CATALOGUE_TABLES`; no baseline ceiling increase is permitted.

Red first:

- `apps/api/tests/Feature/Treasury/WCashSchemaContractTest.php::test_every_column_default_check_index_and_delete_rule_matches_the_rev5_contract`
- First failing assertion: first missing table/column contract is reported.
- `::test_every_composite_fk_has_an_exact_preexisting_unique_target_in_the_same_column_order`
- First failing assertion: PostgreSQL catalog returns no unsupported composite.
- `::test_cross_company_parent_attachment_is_rejected_for_every_composite_fk`
- First failing assertion: first foreign-company attachment raises FK violation.
- `::test_no_q10_q13_vocabulary_or_destination_mapping_exists`
- First failing assertion: schema census finds no forbidden column/check/enum value.
- `::test_company_scoped_business_uniques_pass_the_live_catalogue_ratchet`
- First failing assertion: scanner returns `[]`.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/WCashSchemaContractTest.php tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php` — **phpunit PG**.
- `apps/pos/src/lib/db/__tests__/wCashMigrations.test.ts::v70_precedes_v71_and_both_are_idempotent`
- First failing assertion: v71 on schema version 69 rejects; v70→v71 twice leaves one table/index set.
- Command/lane: `pnpm --filter @autoerp/pos exec vitest run src/lib/db/__tests__/wCashMigrations.test.ts` — **vitest**.

Reviewer gate: database, Fiscal, POS, Treasury, conventions 09/11, owner-policy guard.  
Rollback: keep flags false. Reverse only provably empty/unreferenced server tables; once evidence exists, retain additive schema. Device migrations are never destructively rolled back.

### T2: immutable observe-only policy and cutovers

Production files:

- MODIFY `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventProjectionRow.php`
- MODIFY `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php`
- MODIFY `apps/api/app/Modules/Fiscal/Application/Services/FiscalProjectionDispatcher.php`
- MODIFY `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php`
- MODIFY `apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php`
- CREATE `apps/api/app/Modules/Fiscal/Application/Services/FiscalProjectionPolicyService.php`
- CREATE `apps/api/app/Modules/Fiscal/Application/Services/FiscalCutoverRangeService.php`
- CREATE `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalProjectionPolicyInputData.php`
- CREATE `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalCutoverInputData.php`
- CREATE `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalSourceCoordinateData.php`
- CREATE `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalCutoverResolutionData.php`
- CREATE `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalProjectionRecoveryResultData.php`
- CREATE `apps/api/app/Modules/Fiscal/Presentation/Console/RecordFiscalProjectionPolicyCommand.php`
- CREATE `apps/api/app/Modules/Fiscal/Presentation/Console/AddFiscalProjectionCutoverCommand.php`
- CREATE `apps/api/app/Modules/Fiscal/Presentation/Console/RecoverFiscalProjectionLeasesCommand.php`

Full signatures:

```php
public function record(FiscalProjectionPolicyInputData $input): MutationResultData;
public function add(FiscalCutoverInputData $input): MutationResultData;
public function resolve(FiscalSourceCoordinateData $coordinate): FiscalCutoverResolutionData;
public function recoverExpired(int $limit, int $leaseSeconds): FiscalProjectionRecoveryResultData;
```

Red first:

- `apps/api/tests/Feature/Fiscal/FiscalCutoverRangeTest.php::test_ordered_time_and_uuid_bounds_are_half_open`
- First failing assertion: upper coordinate does not match the preceding range.
- `::test_concurrent_overlap_returns_one_applied_and_one_conflict`
- First failing assertion: exactly one PG transaction commits.
- `::test_company_b_cannot_resolve_company_a_policy_or_range`
- First failing assertion: B resolves only B.
- `::test_exact_rerun_is_already_exists_and_changed_evidence_conflicts`
- First failing assertion: second exact outcome is `already_exists`.
- `::test_only_observe_only_policy_can_be_created`
- First failing assertion: any other policy value is rejected before write.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/Fiscal/FiscalCutoverRangeTest.php` — **phpunit PG**.

Reviewer gate: Fiscal, tenancy, PG concurrency, owner-policy guard.  
Rollback: false flag stops readers/workers; retain immutable policy/cutover evidence.

### T3: canonical repository DTO and convention-09 journey

Production files:

- CREATE `apps/api/app/Modules/Treasury/Application/DTOs/PaymentRepositoryData.php`
- MODIFY `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php`
- MODIFY `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts`
- MODIFY `apps/web/src/features/pos/api/paymentRepositoryApi.ts`
- MODIFY `apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx`
- MODIFY `apps/web/src/features/treasury/components/TransferCashModal.tsx`
- MODIFY `apps/web/src/features/treasury/RepositoryListPage.tsx`
- MODIFY `apps/web/src/features/treasury/RepositoryDetailPage.tsx`
- MODIFY `apps/web/src/features/treasury/PaymentForm.tsx`
- MODIFY `apps/web/src/features/treasury/SplitPaymentForm.tsx`
- MODIFY `apps/pos/src/types/payment.ts`
- MODIFY `apps/pos/src/lib/db/repositories/paymentRepository.ts`
- MODIFY `apps/pos/src/api/paymentApi.ts`
- MODIFY `apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx`
- MODIFY `apps/pos/src/stores/paymentStore.ts`
- MODIFY `apps/pos/src/lib/sync/syncService.ts`
- MODIFY `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx`
- MODIFY `apps/pos/src/components/pos/PaymentSummary.tsx`
- MODIFY `apps/pos/src/pages/ThemePreviewPage.tsx`
- MODIFY `apps/pos/src/test/helpers.ts`
- REGENERATE `packages/shared/types/generated.d.ts`

Signature:

```php
public static function fromModel(PaymentRepository $repository): self;
```

DTO fields: ID, tenant ID, company ID, nullable location ID, code, name, repository type, currency, scale-3 balance string, active state. No drawer owner, shared session, cash configuration, destination mapping, or Q11/Q12 field.

Red first:

- `apps/api/tests/Feature/Treasury/PaymentRepositoryDataTest.php::test_controller_returns_generated_dto_and_decimal_strings`
- First failing assertion: balance is an exact scale-3 string.
- `::test_real_company_b_and_selected_location_two_return_only_b_repositories`
- First failing assertion: response contains no company-A/location-1 ID.
- `::test_repeated_list_and_detail_reads_are_stable_and_do_not_change_ids_or_balances`
- First failing assertion: before/after repository snapshots are identical.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/PaymentRepositoryDataTest.php` — **phpunit PG**.
- `apps/web/src/features/treasury/__tests__/paymentRepositoryGeneratedType.test.ts::company_switch_and_second_location_invalidate_repository_queries`
- First failing assertion: B/location-2 renders only B rows after switching from A.
- Command/lane: `pnpm --filter @autoerp/web exec vitest run src/features/treasury/__tests__/paymentRepositoryGeneratedType.test.ts` — **vitest**.
- `apps/pos/src/lib/db/__tests__/paymentRepositoryDecoder.test.ts::rejects_invalid_rows_and_preserves_company_location`
- First failing assertion: malformed money/location rejects rather than casts.
- Command/lane: `pnpm --filter @autoerp/pos exec vitest run src/lib/db/__tests__/paymentRepositoryDecoder.test.ts` — **vitest**.

Generation:

```bash
cd apps/api
php artisan typescript:transform
git diff --exit-code -- ../../packages/shared/types/generated.d.ts
```

Reviewer gate: backend DTO, web, POS, convention 09/11.  
Rollback: retain generated DTO; temporarily restore compatibility decoding behind a false flag without recreating local cross-boundary interfaces.

### T4: immutable generic manual transfer documents

Production files:

- MODIFY `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php`
- MODIFY `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php`
- MODIFY `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`
- MODIFY `apps/web/src/features/treasury/components/TransferCashModal.tsx`
- MODIFY `apps/web/src/locales/en/treasury.json`
- MODIFY `apps/web/src/locales/fr/treasury.json`
- MODIFY `apps/web/src/locales/ar/treasury.json`
- CREATE `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferDocumentService.php`
- CREATE `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferInputData.php`
- CREATE `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferResultData.php`
- CREATE `apps/api/app/Modules/Treasury/Presentation/Requests/RepositoryTransferRequest.php`
- CREATE `apps/api/app/Modules/Treasury/Presentation/Console/ReplayRepositoryTransferCommand.php`

Full signatures:

```php
public function post(RepositoryTransferInputData $input): RepositoryTransferResultData;
public function reverse(
    string $documentId,
    string $actorId,
    string $reasonText,
): RepositoryTransferResultData;
public function replay(
    string $documentId,
    string $actorId,
    string $reasonText,
): RepositoryTransferResultData;
```

Red first:

- `apps/api/tests/Feature/Treasury/RepositoryTransferDocumentTest.php::test_post_creates_one_document_two_movements_and_zero_or_one_journal_entry`
- First failing assertion: legs equal exactly `source,destination`.
- `::test_document_has_free_text_reason_and_no_cash_operation_mapping`
- First failing assertion: document/schema contains no raw cash-source/destination-mapping field.
- `::test_opposite_transfers_and_unrelated_journal_entry_complete_without_deadlock`
- First failing assertion: all PG workers finish within the barrier timeout.
- `::test_posted_document_is_append_only_and_correction_is_reversal`
- First failing assertion: edit/delete is refused.
- `::test_real_company_b_and_selected_location_two_cannot_use_a_repositories`
- First failing assertion: cross-company repository FK rejects.
- `::test_exact_retry_returns_already_exists_with_same_id_and_balances`
- First failing assertion: IDs and both repository balances are unchanged.
- `::test_inventory_gl_posting_buffer_is_never_called`
- First failing assertion: buffer mock has zero interactions.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/RepositoryTransferDocumentTest.php` — **phpunit PG**.

Reviewer gate: Treasury, Accounting/GL, PG concurrency, convention 09, owner-policy guard.  
Rollback: disable document-producing UI/callers. Retain posted documents; correct only by reversal.

### T5: policy-neutral v2/v3 raw evidence bridge

Production files:

- CREATE `apps/api/app/Shared/Contracts/Treasury/ShiftCashEvidencePortInterface.php`
- CREATE `apps/api/app/Shared/Contracts/Treasury/DTOs/ShiftCashSourceFactData.php`
- CREATE `apps/api/app/Shared/Contracts/Treasury/DTOs/ShiftCashObservationResultData.php`
- MODIFY `apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php`
- MODIFY `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php`
- MODIFY `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`
- CREATE `apps/api/app/Modules/Treasury/Application/Services/ShiftCashEvidenceService.php`
- CREATE `apps/api/app/Modules/Treasury/Application/Services/ShiftCashSemanticFingerprint.php`
- CREATE `apps/api/app/Modules/Treasury/Application/Jobs/ProcessShiftCashSourceEvidence.php`

No Treasury service accepts `CashDrawerOperation` or `FiscalEvent` models.

Full signatures:

```php
interface ShiftCashEvidencePortInterface
{
    public function observe(ShiftCashSourceFactData $fact): ShiftCashObservationResultData;

    public function process(
        string $sourceEvidenceId,
        string $claimToken,
    ): ShiftCashObservationResultData;
}

public function fingerprint(ShiftCashSourceFactData $canonicalFact): string;
```

Red first:

- `apps/api/tests/Feature/Treasury/ShiftCashEvidenceBridgeTest.php::test_linked_v2_and_v3_sources_create_one_semantic_fact_and_two_raw_sources`
- First failing assertion: semantic fact count is one and raw-source count is two.
- `::test_semantic_fingerprint_excludes_rail_and_source_uuid_but_includes_every_custody_boundary`
- First failing assertion: linked cross-rail fingerprints match; each changed custody field differs.
- `::test_unlinked_v3_source_is_blocked_without_time_bucket_deduplication`
- First failing assertion: state is blocked/source-linkage-missing and no semantic fact exists.
- `::test_q11_q12_q13_dependent_evidence_is_owner_ruling_required_without_money_effect`
- First failing assertion: transfer documents, movements, and journal entries remain zero.
- `::test_near_collision_and_conflicting_link_are_not_silently_collapsed`
- First failing assertion: near collision is distinct; conflicting linkage returns conflict.
- `::test_crash_at_each_claim_boundary_recovers_without_money_effect`
- First failing assertion: final typed outcome exists and balances are unchanged.
- `::test_company_b_selected_location_two_and_terminal_two_are_isolated`
- First failing assertion: B worker cannot claim A evidence.
- `::test_exact_retry_returns_already_exists`
- First failing assertion: semantic/raw IDs remain unchanged.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/ShiftCashEvidenceBridgeTest.php` — **phpunit PG**.

Reviewer gate: Treasury, Fiscal/POS, module boundaries, owner-policy guard.  
Rollback: disable observers/workers; retain immutable raw evidence and blocked facts.

### T7: atomic, derivable close manifest

Production files:

- MODIFY `apps/pos/src/lib/offline/zReportService.ts`
- MODIFY `apps/pos/src/lib/fiscal/zSessionAuthoring.ts`
- MODIFY `apps/pos/src/lib/sync/syncService.ts`
- CREATE `apps/pos/src/lib/fiscal/ZCloseManifestBuilder.ts`
- CREATE `apps/pos/src/lib/db/repositories/zCloseManifestRepository.ts`
- CREATE `apps/pos/src/lib/db/repositories/zCloseManifestOutboxRepository.ts`
- CREATE `apps/api/app/Modules/POS/Application/Services/ZCloseManifestIngestor.php`
- MODIFY `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php`

Full signatures:

```ts
export class ZCloseManifestBuilder {
  static build(input: ZCloseManifestBuildInput): Promise<ZCloseManifestData>;
  static hash(manifest: ZCloseManifestData): Promise<string>;
}

export class ZCloseManifestRepository {
  static saveWithinTransaction(
    tx: SqlSurface,
    manifest: ZCloseManifestData,
  ): Promise<MutationResult>;
}
```

```php
public function ingest(ZCloseManifestData $manifest): MutationResultData;
```

Red first:

- `apps/pos/src/lib/fiscal/__tests__/zCloseManifest.atomicity.test.ts::rolls_back_z_counts_close_events_manifest_outbox_and_chain_at_each_fault_point`
- First failing assertion: all involved tables have zero new rows.
- `::derives_every_current_namespace_member_from_the_existing_device_rows`
- First failing assertion: expected ordered namespace/source-ID vector matches exactly.
- `::derives_deterministic_payment_ids_hashes_and_sequence_values`
- First failing assertion: shared UUIDv5/hash vector matches.
- `::uses_returned_close_and_z_event_ids_before_manifest_hashing`
- First failing assertion: payload contains the actual returned hashes.
- `::exact_retry_is_already_exists_and_changed_payload_conflicts`
- First failing assertion: second exact result is `already_exists`.
- `::does_not_add_w_lot_b_members_before_that_capability_exists`
- First failing assertion: schema-v1 namespace set contains no lot-evidence member.
- Command/lane: `pnpm --filter @autoerp/pos exec vitest run src/lib/fiscal/__tests__/zCloseManifest.atomicity.test.ts` — **vitest**.
- `apps/api/tests/Feature/POS/ZCloseManifestIngestorTest.php::test_company_location_terminal_shift_session_and_z_ownership_are_enforced`
- First failing assertion: first cross-boundary composite FK rejects.
- `::test_member_order_hash_schema_version_and_derived_payment_identity_are_verified`
- First failing assertion: reordered/rehydrated member returns conflict.
- `::test_second_company_and_selected_location_are_isolated_and_retry_is_already_exists`
- First failing assertion: B sees no A manifest and retry keeps one row.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/POS/ZCloseManifestIngestorTest.php` — **phpunit PG**.

Reviewer gate: POS SQLite/fiscal chain, backend POS, W-LOT-B dependency owner.  
Rollback: false flag stops new manifests; retain and continue syncing already-authored manifests.

### T8: transactionally seeded cash-count fan-out

Production files:

- MODIFY `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php`
- MODIFY `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php`
- MODIFY `apps/api/app/Modules/POS/Application/Services/CashCountDispatcher.php`
- MODIFY `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`
- CREATE `apps/api/app/Modules/POS/Application/DTOs/CashCountDispatchSeedResultData.php`
- CREATE `apps/api/app/Modules/POS/Application/DTOs/CashCountDispatchResultData.php`
- CREATE `apps/api/app/Modules/POS/Application/Jobs/DeliverCashCountObligation.php`
- CREATE `apps/api/app/Modules/POS/Infrastructure/Commands/DeliverCashCountObligationsCommand.php`

Full signatures:

```php
public function persistObligations(
    CashCountRecorded $event,
): CashCountDispatchSeedResultData;

public function afterCommit(
    CashCountRecorded $event,
): CashCountDispatchResultData;

public function deliver(
    string $obligationId,
    string $claimToken,
): CashCountConsumerOutcomeData;

public function recoverExpired(
    int $limit,
    int $leaseSeconds,
): CashCountDispatchResultData;
```

Red first:

- `apps/api/tests/Feature/POS/CashCountFanOutDurabilityTest.php::test_server_generated_z_and_three_obligations_commit_atomically`
- First failing assertion: forced precommit failure leaves zero Z/count/obligation rows.
- `::test_device_synced_z_and_three_obligations_commit_atomically`
- Same assertion for sync.
- `::test_crash_after_commit_before_queue_leaves_three_pending_obligations`
- First failing assertion: consumers are exactly Treasury, Compliance, stored event.
- `::test_each_consumer_records_a_typed_result_and_redelivery_is_already_exists`
- First failing assertion: second delivery has no repeated effect.
- `::test_stored_event_and_obligation_result_commit_atomically`
- First failing assertion: injected fault leaves neither stored event nor applied result.
- `::test_company_b_location_two_and_terminal_two_cannot_claim_a_obligations`
- First failing assertion: B claim count is zero.
- `::test_flag_false_preserves_legacy_event_dispatch`
- First failing assertion: existing dispatch occurs and no obligation is created.
- `::test_inventory_gl_posting_buffer_is_never_called`
- First failing assertion: zero interactions.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/POS/CashCountFanOutDurabilityTest.php` — **phpunit PG**.

Reviewer gate: both POS producer owners, Treasury, Compliance, event sourcing.  
Rollback: false flag returns producers to legacy dispatch; already-created obligations remain deliverable until drained.

### T9: canonical W7 reconciliation

Production files:

- MODIFY `apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php`
- MODIFY `apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php`
- MODIFY `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php`
- MODIFY `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php`
- MODIFY `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php`
- MODIFY `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php`
- MODIFY `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`
- CREATE `apps/api/app/Modules/POS/Application/DTOs/ShiftExpectedCashMembershipData.php`
- CREATE `apps/api/app/Modules/POS/Application/DTOs/ReconciliationDependencyVersionData.php`
- CREATE `apps/api/app/Modules/POS/Application/DTOs/SessionReconciliationMutationResultData.php`
- CREATE `apps/api/app/Modules/POS/Application/Services/ReconciliationDependencyVersionService.php`
- CREATE `apps/api/app/Modules/POS/Application/Services/PosSessionReconciliationService.php`
- CREATE `apps/api/app/Modules/POS/Application/Services/SessionReconciliationFingerprint.php`
- CREATE `apps/api/app/Modules/POS/Application/Jobs/ReconcilePosSession.php`
- CREATE `apps/api/app/Modules/POS/Infrastructure/Commands/ReconcilePosSessionCommand.php`

Full signatures:

```php
public function breakdownForManifest(
    Shift $shift,
    Terminal $terminal,
    string $currencyCode,
    ShiftExpectedCashMembershipData $membership,
): ShiftExpectedCashBreakdown;

public function touch(
    string $tenantId,
    string $companyId,
    string $reconciliationId,
    ReconciliationDependencyKind $kind,
    string $identity,
    string $hash,
    CarbonImmutable $occurredAt,
): ReconciliationDependencyVersionData;

public function reconcile(
    string $tenantId,
    string $companyId,
    string $zReportId,
    ?int $expectedDependencyVersion = null,
): SessionReconciliationMutationResultData;
```

`breakdownForManifest()` reuses the existing internal arithmetic but constrains receipt, account-collection, and movement reads to manifest identities. `PosSessionReconciliationService` may not contain `bcadd`, `bcsub`, cash direction maps, or a second expected-cash formula.

All tests live in `apps/api/tests/Feature/POS/PosSessionReconciliationAcceptanceTest.php`; command/lane:

```bash
cd apps/api
php artisan test -c phpunit-pgsql.xml tests/Feature/POS/PosSessionReconciliationAcceptanceTest.php
```

— **phpunit PG**.

| Exact method | First failing assertion |
|---|---|
| `test_taxed_sale_and_partial_refund_match_independently_derived_totals` | matched result with independently calculated net/tax/refund strings |
| `test_multiple_tax_rates_are_reconciled_per_rate` | each rate cell equals expected scale-3 values |
| `test_rounded_cash_and_card_split_matches` | rounding contributes exactly once |
| `test_account_collection_is_included_from_manifest_membership` | account collection equals source-derived value |
| `test_opening_float_and_raw_cash_evidence_are_owner_ruling_required_cells` | result is blocked; no repository/GL write exists |
| `test_sequence_gap_is_incomplete` | result is incomplete/blocked, never matched |
| `test_missing_projection_is_incomplete` | missing member identity is named |
| `test_late_member_appends_a_superseding_run` | old run retained; replacement current |
| `test_out_of_manifest_receipt_does_not_enter_the_run` | excluded receipt named |
| `test_refund_only_session_preserves_negative_vat` | VAT is negative scale-3 |
| `test_duplicate_z_report_is_conflict` | current pointer unchanged |
| `test_valid_hashes_with_wrong_economic_totals_are_mismatch` | state equals mismatch |
| `test_lot_dependency_is_explicit_without_deciding_q10` | evidence hash or owner-ruling-required |
| `test_repository_dependency_is_blocked_before_q11_q13` | state equals blocked |
| `test_unknown_tender_throws_unknown_tender_classification_exception` | exact exception class |
| `test_reclassified_tender_invalidates_and_supersedes` | version increments; former run remains immutable |
| `test_concurrent_corrected_inputs_leave_one_current_pointer` | one head and one non-null valid current pointer |
| `test_dependency_change_during_snapshot_discards_mixed_version` | stale captured version is never published |
| `test_same_as_current_fingerprint_returns_already_exists` | run count/current ID unchanged |
| `test_historical_fingerprint_a_b_a_appends_a_new_a_occurrence` | third run has A fingerprint and is current |
| `test_full_tenant_company_terminal_shift_session_z_ownership_is_enforced` | first cross-boundary attachment rejects |
| `test_real_company_b_selected_location_two_and_terminal_two_do_not_cross_reconcile` | B snapshot contains no A identity |
| `test_chain_verification_remains_complementary` | chain failure remains separately visible |
| `test_pos_session_reconciliation_service_contains_no_cash_arithmetic` | architecture scanner finds no duplicate arithmetic |
| `test_inventory_gl_posting_buffer_is_never_called` | zero interactions |

Concurrency cases use two PostgreSQL connections and barriers.

Reviewer gate: POS/Fiscal, Treasury, Accounting, W2, W4, W-LOT-B, PostgreSQL concurrency.  
Rollback: false flag stops new runs; retain immutable histories/dependency changes.

### T10: canonical shift/Z operator surface

Production files:

- MODIFY `apps/api/app/Modules/POS/routes.php`
- CREATE `apps/api/app/Modules/POS/Presentation/Requests/RetryPosSessionReconciliationRequest.php`
- CREATE `apps/api/app/Modules/POS/Presentation/Controllers/PosSessionReconciliationController.php`
- CREATE `apps/api/app/Modules/POS/Application/DTOs/PosSessionReconciliationData.php`
- MODIFY `apps/web/src/features/pos/pages/ZReportDetailPage/ZReportDetailPage.tsx`
- CREATE `apps/web/src/features/pos/api/sessionReconciliationApi.ts`
- CREATE `apps/web/src/features/pos/components/SessionReconciliationPanel.tsx`
- CREATE `apps/web/src/features/pos/components/CashDeliveryObligationsPanel.tsx`
- MODIFY `apps/web/src/locales/en/pos.json`
- MODIFY `apps/web/src/locales/fr/pos.json`
- MODIFY `apps/web/src/locales/ar/pos.json`
- REGENERATE `packages/shared/types/generated.d.ts`

Routes:

```text
GET  /api/v1/pos/reports/z/{zNumber}/reconciliation
POST /api/v1/pos/reports/z/{zNumber}/reconciliation/retry
```

Both use the existing module route group plus `can:pos.manage_shifts`. No permission is added or reseeded: `pos.manage_shifts` already exists in the role catalogue (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:368`, `:620`, `:687`) and gates the existing Shift History web route (`apps/web/src/routes/index.tsx:2897-2901`).

Full signatures:

```php
public function show(Request $request, int $zNumber): JsonResponse;

public function retry(
    RetryPosSessionReconciliationRequest $request,
    int $zNumber,
): JsonResponse;
```

Retry enqueues existing durable work only; it cannot change policy, classify reasons, map destinations, align history, or activate money.

Red first:

- `apps/api/tests/Feature/POS/PosSessionReconciliationControllerTest.php::test_authorized_company_and_location_scoped_read`
- First failing assertion: company-B user gets 404 for company-A Z.
- `::test_real_company_b_selected_location_two_lists_only_its_reconciliation`
- First failing assertion: B response contains only B/location-2 IDs.
- `::test_retry_of_same_current_fingerprint_returns_already_exists_with_unchanged_ids_and_balances`
- First failing assertion: response outcome, current run ID, and repository balances are unchanged.
- `::test_permission_and_direct_object_reference_are_both_enforced`
- First failing assertion: user without `pos.manage_shifts` receives 403 and foreign Z receives 404.
- Command/lane: `cd apps/api && php artisan test -c phpunit-pgsql.xml tests/Feature/POS/PosSessionReconciliationControllerTest.php` — **phpunit PG**.
- `apps/web/src/features/pos/components/SessionReconciliationPanel.test.tsx::renders_current_history_and_owner_ruling_required_without_actions`
- First failing assertion: no Q10–Q13 activation/classification/alignment control exists.
- `::company_switch_and_selected_location_change_clear_prior_z_data`
- First failing assertion: company-B/location-2 panel contains no A identity.
- `::repeated_retry_renders_already_exists_without_duplicate_history`
- First failing assertion: one current run remains.
- Command/lane: `pnpm --filter @autoerp/web exec vitest run src/features/pos/components/SessionReconciliationPanel.test.tsx` — **vitest**.

Reviewer gate: API authorization, POS canonical-surface owner, frontend conventions, accessibility, convention 09/11.  
Rollback: disable `VITE_W_CASH_READ_UI_ENABLED`; API/history remain read-only and durable.

### T11: owner-ruling supplement

T11 is **WITHHELD and non-dispatchable**.

Only after explicit Q10–Q13 rulings may a separate supplement define:

- selected cash-reason vocabulary;
- selected reason→destination/account mappings;
- drawer custody/session ownership and join/refusal;
- opening-float ownership;
- cash-operation→transfer/expense associations;
- historical alignment and disabled-window disposition;
- variance activation;
- any additional schema, commands, UI, tasks, tests, rollout, and rollback.

Nothing in this plan constitutes that supplement.

## 11. Environment and entrypoint contract

**PL-500 — Shared staging environment.**

Add to `docker-compose.staging.yml`’s `x-api-env`, currently defined at `docker-compose.staging.yml:17-57` and inherited by API/worker/scheduler/websocket at `:178-186`, `:206-214`, `:227-235`, and `:242-250`:

```yaml
DB_DIRECT_HOST: "${DB_DIRECT_HOST:-postgres}"
APP_BUILD_SHA: "${APP_BUILD_SHA:?APP_BUILD_SHA is required}"
AUTO_MIGRATE: "${AUTO_MIGRATE:-false}"
W_CASH_POLICY_EVIDENCE_ENABLED: "${W_CASH_POLICY_EVIDENCE_ENABLED:-false}"
W_CASH_TRANSFER_DOCUMENTS_ENABLED: "${W_CASH_TRANSFER_DOCUMENTS_ENABLED:-false}"
W_CASH_RAW_EVIDENCE_ENABLED: "${W_CASH_RAW_EVIDENCE_ENABLED:-false}"
W_CASH_Z_MANIFEST_ENABLED: "${W_CASH_Z_MANIFEST_ENABLED:-false}"
W_CASH_COUNT_OBLIGATIONS_ENABLED: "${W_CASH_COUNT_OBLIGATIONS_ENABLED:-false}"
W_CASH_RECONCILIATION_ENABLED: "${W_CASH_RECONCILIATION_ENABLED:-false}"
```

Add web build arguments under the existing block at `docker-compose.staging.yml:263-270`:

```yaml
VITE_APP_BUILD_SHA: "${VITE_APP_BUILD_SHA:?VITE_APP_BUILD_SHA is required}"
VITE_W_CASH_READ_UI_ENABLED: "${VITE_W_CASH_READ_UI_ENABLED:-false}"
```

`apps/web/Dockerfile` currently declares only the existing build arguments at `:50-55`; T12 declares and exports both new arguments before `pnpm build`.

`verify-w-cash-flags.sh`:

- requires 40-character lowercase `APP_BUILD_SHA`;
- accepts only literal `true|false`;
- enforces reconciliation implies manifests and count obligations;
- contains no Q10–Q13 branch flag.

All four entrypoints source it before config caching. API startup uses `AUTO_MIGRATE=true` to run central and `tenants:migrate-rolling --force`, exiting nonzero on either failure. `AUTO_MIGRATE=false` skips both. This replaces the current log-and-continue behavior at `apps/api/docker/entrypoint.sh:127-154`.

## 12. Executable acyclic five-push staging manifest

### PL-510 — Common pre-push

```bash
set -euo pipefail

test "$(git branch --show-current)" = "dev"
test -z "$(git status --porcelain --untracked-files=no)"

git fetch origin dev
git merge --ff-only origin/dev

pnpm lint
pnpm typecheck
pnpm test
pnpm build

(
  cd apps/api
  ./vendor/bin/pint --test
  ./vendor/bin/phpstan analyse
  composer test
  php artisan typescript:transform
)

git diff --exit-code -- packages/shared/types/generated.d.ts
./scripts/preflight.sh

CANDIDATE_SHA="$(git rev-parse HEAD)"
test "${#CANDIDATE_SHA}" -eq 40
```

Push and verify:

```bash
git push origin "${CANDIDATE_SHA}:refs/heads/dev"

REMOTE_SHA="$(git ls-remote origin refs/heads/dev | awk '{print $1}')"
test "$REMOTE_SHA" = "$CANDIDATE_SHA"
```

### PL-511 — Dokploy IDs, environment, deployment, and web proof

Repository-recorded application IDs are verified through `application.one` and captured before use:

```bash
: "${DOKPLOY_URL:?}"
: "${DOKPLOY_API_KEY:?}"
: "${WEB_URL:=https://erp.otospex.dev}"
: "${API_URL:=https://api.erp.otospex.dev}"
: "${STAGING_SSH:?}"
: "${SMOKE_TEST_EMAIL:?}"
: "${SMOKE_TEST_PASSWORD:?}"

API_APP_JSON="$(
  curl --fail-with-body --silent --show-error \
    -H "x-api-key: ${DOKPLOY_API_KEY}" \
    "${DOKPLOY_URL}/api/application.one?applicationId=x5wfthp8-7cVbiUfI6Hq7"
)"
API_APPLICATION_ID="$(printf '%s\n' "$API_APP_JSON" | jq -er '.applicationId')"

WEB_APP_JSON="$(
  curl --fail-with-body --silent --show-error \
    -H "x-api-key: ${DOKPLOY_API_KEY}" \
    "${DOKPLOY_URL}/api/application.one?applicationId=mY6P_PHb4pw-2LdG1Y7Ml"
)"
WEB_APPLICATION_ID="$(printf '%s\n' "$WEB_APP_JSON" | jq -er '.applicationId')"

test "$API_APPLICATION_ID" = "x5wfthp8-7cVbiUfI6Hq7"
test "$WEB_APPLICATION_ID" = "mY6P_PHb4pw-2LdG1Y7Ml"
```

Set and verify candidate-specific values before redeploy:

```bash
scripts/release/update-dokploy-w-cash-env.sh \
  "$DOKPLOY_URL" \
  "$DOKPLOY_API_KEY" \
  "$API_APPLICATION_ID" \
  "$CANDIDATE_SHA" \
  false

scripts/release/update-dokploy-w-cash-env.sh \
  "$DOKPLOY_URL" \
  "$DOKPLOY_API_KEY" \
  "$WEB_APPLICATION_ID" \
  "$CANDIDATE_SHA" \
  "$READ_UI_ENABLED"
```

Explicit web deployment:

```bash
DEPLOY_TITLE="W-CASH-${CANDIDATE_SHA}-$(date +%s)"

curl --fail-with-body --silent --show-error \
  -X POST "${DOKPLOY_URL}/api/application.redeploy" \
  -H "x-api-key: ${DOKPLOY_API_KEY}" \
  -H "content-type: application/json" \
  --data "$(jq -nc \
    --arg applicationId "$WEB_APPLICATION_ID" \
    --arg title "$DEPLOY_TITLE" \
    --arg description "$CANDIDATE_SHA" \
    '{applicationId:$applicationId,title:$title,description:$description}')"

DEPLOYMENT_ID="$(
  scripts/release/poll-dokploy-deployment.sh \
    "$DOKPLOY_URL" \
    "$DOKPLOY_API_KEY" \
    "$WEB_APPLICATION_ID" \
    "$DEPLOY_TITLE" \
    "$CANDIDATE_SHA"
)"
test -n "$DEPLOYMENT_ID"
```

The polling script queries exactly:

```text
GET /api/deployment.all?applicationId=<captured-id>
JSON selection:
.[] | select(.title==$title and .description==$sha) | .deploymentId
.[] | select(.deploymentId==$id) | .status
```

It checks every two seconds for at most 60 seconds for ID capture and every five seconds for at most 450 seconds for `done|success`; `error|failed|cancelled`, missing correlation, or timeout exits nonzero.

Candidate proof:

```bash
API_FINGERPRINT="$(
  curl --fail-with-body --silent --show-error \
    "${API_URL}/api/feature-fingerprint"
)"
test "$(printf '%s\n' "$API_FINGERPRINT" | jq -r '.build_sha')" = "$CANDIDATE_SHA"

WEB_META="$(
  curl --fail-with-body --silent --show-error \
    "${WEB_URL}/build-fingerprint.json"
)"
test "$(printf '%s\n' "$WEB_META" | jq -r '.build_sha')" = "$CANDIDATE_SHA"
test "$(printf '%s\n' "$WEB_META" | jq -r '.feature_fingerprint')" \
  = "w-cash-rev5-policy-neutral"

ASSET_PATH="$(printf '%s\n' "$WEB_META" | jq -er '.entry_asset')"
EXPECTED_ASSET_HASH="$(printf '%s\n' "$WEB_META" | jq -er '.entry_asset_sha256')"
SERVED_ASSET_HASH="$(
  curl --fail-with-body --silent --show-error "${WEB_URL}${ASSET_PATH}" |
    openssl dgst -sha256 |
    awk '{print $NF}'
)"
test "$SERVED_ASSET_HASH" = "$EXPECTED_ASSET_HASH"

BASE_URL="$WEB_URL" \
API_BASE_URL="$API_URL" \
EXPECTED_BUILD_SHA="$CANDIDATE_SHA" \
SMOKE_TEST_EMAIL="$SMOKE_TEST_EMAIL" \
SMOKE_TEST_PASSWORD="$SMOKE_TEST_PASSWORD" \
pnpm --filter @autoerp/web exec playwright test \
  e2e/w-cash-staging-smoke.spec.ts
```

This explicit web deploy, asset hash, feature fingerprint, and Playwright smoke runs after every push containing web code, as required by `docs/factory/WORKFLOW.md:206-230`.

### PL-512 — Fleet backup and per-tenant migration proof

Available only after Push 1:

```bash
BACKUP_JSON="$(
  ssh "$STAGING_SSH" \
    'cd /var/www/html &&
     DB_HOST=${DB_DIRECT_HOST:-postgres} php artisan tenant:backup --all --format=json'
)"

printf '%s\n' "$BACKUP_JSON" |
  jq -e '
    (.tenants | length) > 0 and
    all(.tenants[];
      .backup_id != null and
      .status == "completed" and
      .sha256 != null and
      (.sha256 | length) == 64
    )
  '

ACTIVE_TENANTS="$(printf '%s\n' "$BACKUP_JSON" | jq -r '.tenants | length')"

printf '%s\n' "$BACKUP_JSON" |
  jq -r '.tenants[] | [.tenant_id,.backup_id,.sha256] | @tsv' \
  > "/tmp/w-cash-backups-${CANDIDATE_SHA}.tsv"

test "$(wc -l < "/tmp/w-cash-backups-${CANDIDATE_SHA}.tsv" | tr -d ' ')" \
  -eq "$ACTIVE_TENANTS"
```

Migration:

```bash
set -o pipefail
MIGRATION_LOG="/tmp/w-cash-migrate-${CANDIDATE_SHA}.log"

ssh "$STAGING_SSH" \
  'cd /var/www/html &&
   DB_HOST=${DB_DIRECT_HOST:-postgres} php artisan migrate --force &&
   DB_HOST=${DB_DIRECT_HOST:-postgres} php artisan tenants:migrate-rolling --force' \
  | tee "$MIGRATION_LOG"

grep -F "0 failed" "$MIGRATION_LOG"

DECLARED_TENANTS="$(
  sed -n 's/^Rolling tenant migrations across \([0-9][0-9]*\) tenant(s).$/\1/p' \
    "$MIGRATION_LOG"
)"
MIGRATED_TENANTS="$(grep -c '^→ ' "$MIGRATION_LOG")"

test "$DECLARED_TENANTS" -eq "$ACTIVE_TENANTS"
test "$MIGRATED_TENANTS" -eq "$ACTIVE_TENANTS"
! grep -E 'FAILED:|Done with errors' "$MIGRATION_LOG"

ssh "$STAGING_SSH" \
  'cd /var/www/html &&
   DB_HOST=${DB_DIRECT_HOST:-postgres} php artisan tenants:migrate-rolling --force' \
  > "/tmp/w-cash-migrate-rerun-${CANDIDATE_SHA}.log"

grep -F "0 failed" "/tmp/w-cash-migrate-rerun-${CANDIDATE_SHA}.log"
! grep -E 'FAILED:|Done with errors' \
  "/tmp/w-cash-migrate-rerun-${CANDIDATE_SHA}.log"
```

Audit each tenant after migration:

```bash
AUDIT_JSON="$(
  ssh "$STAGING_SSH" \
    'cd /var/www/html &&
     DB_HOST=${DB_DIRECT_HOST:-postgres} php artisan w-cash:audit --format=json --fail-on=error'
)"

printf '%s\n' "$AUDIT_JSON" |
  jq -e --argjson expected "$ACTIVE_TENANTS" '
    .tenant_count == $expected and
    all(.tenants[];
      .migration_status == "complete" and
      .schema_contract == "matched"
    )
  '
```

### PL-520 — Push 1: rollout foundation and census

Contents: T0 and T12 only. No W-CASH schema.

Exact paths:

```bash
git add -- \
  docker-compose.staging.yml \
  apps/api/.env.example \
  apps/api/docker/entrypoint.sh \
  apps/api/docker/entrypoint-worker.sh \
  apps/api/docker/entrypoint-scheduler.sh \
  apps/api/docker/entrypoint-websocket.sh \
  apps/api/docker/verify-w-cash-flags.sh \
  apps/api/app/Modules/Tenant/Application/Commands/BackupTenantCommand.php \
  apps/api/app/Modules/Treasury/Presentation/Console/WCashAuditCommand.php \
  apps/api/app/Modules/Treasury/Application/Services/WCashAuditService.php \
  apps/api/app/Modules/Treasury/Application/DTOs/WCashAuditResultData.php \
  apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php \
  apps/api/app/Shared/Presentation/Http/Controllers/FeatureFingerprintController.php \
  apps/api/app/Shared/Application/DTOs/FeatureFingerprintData.php \
  apps/api/routes/api.php \
  apps/web/vite.config.ts \
  apps/web/Dockerfile \
  apps/web/tools/wCashBuildFingerprintPlugin.ts \
  apps/web/e2e/w-cash-staging-smoke.spec.ts \
  scripts/release/update-dokploy-w-cash-env.sh \
  scripts/release/poll-dokploy-deployment.sh \
  apps/api/tests/Feature/Shared/FeatureFingerprintControllerTest.php \
  apps/api/tests/Feature/Tenant/BackupTenantCommandJsonTest.php \
  apps/api/tests/Feature/Treasury/WCashAuditCommandTest.php \
  apps/api/tests/Container/WCashEntrypointFlagsTest.sh

git commit -m "Phase 7.1.1: Add W-CASH rollout foundations"
```

Flags: all W-CASH flags false; `AUTO_MIGRATE=false`; read UI false.

Run PL-510, push, update both candidate SHAs, explicitly deploy web through PL-511, verify API automatic deployment fingerprint, asset hash, feature fingerprint, Playwright, and four-service environment inheritance.

Rollback point: captured predecessor API and web deployment IDs, complete environment snapshots, predecessor asset hash. No schema rollback.

### PL-521 — Push 2: additive schema only

Prerequisite: Push 1 deployed and proven. Capture backups through PL-512 before pushing.

Exact paths:

```bash
git add -- \
  docs/glossary.md \
  apps/api/database/migrations/tenant/2026_09_06_220000_add_w_cash_supporting_ownership_keys.php \
  apps/api/database/migrations/tenant/2026_09_06_220100_create_w_cash_projection_policy_and_cutovers.php \
  apps/api/database/migrations/tenant/2026_09_06_220200_create_repository_transfer_documents.php \
  apps/api/database/migrations/tenant/2026_09_06_220300_create_shift_cash_source_evidence.php \
  apps/api/database/migrations/tenant/2026_09_06_220400_create_pos_z_close_manifests.php \
  apps/api/database/migrations/tenant/2026_09_06_220500_create_cash_count_delivery_obligations.php \
  apps/api/database/migrations/tenant/2026_09_06_220600_create_pos_session_reconciliations.php \
  apps/api/app/Modules/Fiscal/Domain/Models/FiscalProjectionPolicyRecord.php \
  apps/api/app/Modules/Fiscal/Domain/Models/FiscalProjectionCutover.php \
  apps/api/app/Modules/Fiscal/Domain/Enums \
  apps/api/app/Modules/Fiscal/Application/DTOs \
  apps/api/app/Modules/Treasury/Domain/RepositoryTransferDocument.php \
  apps/api/app/Modules/Treasury/Domain/ShiftCashSourceEvidence.php \
  apps/api/app/Modules/Treasury/Domain/ShiftCashSemanticFact.php \
  apps/api/app/Modules/Treasury/Domain/Enums \
  apps/api/app/Modules/Treasury/Application/DTOs \
  apps/api/app/Modules/POS/Domain/PosZCloseManifest.php \
  apps/api/app/Modules/POS/Domain/CashCountDeliveryObligation.php \
  apps/api/app/Modules/POS/Domain/PosSessionReconciliation.php \
  apps/api/app/Modules/POS/Domain/PosSessionReconciliationRun.php \
  apps/api/app/Modules/POS/Domain/PosSessionReconciliationSupersession.php \
  apps/api/app/Modules/POS/Domain/PosSessionReconciliationDependencyChange.php \
  apps/api/app/Modules/POS/Domain/Enums \
  apps/api/app/Modules/POS/Application/DTOs \
  apps/api/app/Shared/Domain/Enums/MutationOutcome.php \
  apps/pos/src/lib/db/migrations.ts \
  apps/api/tests/Feature/Treasury/WCashSchemaContractTest.php \
  apps/pos/src/lib/db/__tests__/wCashMigrations.test.ts

git commit -m "Phase 7.1.2: Add dormant W-CASH schemas"
```

All flags and `AUTO_MIGRATE` remain false. Push/API deploy makes migration files available, but no schema-dependent application service is present. Run PL-512 controlled central and `tenants:migrate-rolling --force` migration before Push 3. Recreate API/worker/scheduler/websocket and re-run PL-511.

Rollback point: Push-1 deployment plus every captured backup ID/hash. Prefer forward repair. Leave additive schema/device migrations installed once evidence exists.

### PL-522 — Push 3: dormant backend/device workflows

Contents: T2, T4, T5, T7, T8, and T9. No activation and no operator UI.

Exact paths:

```bash
git add -- \
  apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventProjectionRow.php \
  apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php \
  apps/api/app/Modules/Fiscal/Application/Services/FiscalProjectionDispatcher.php \
  apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php \
  apps/api/app/Modules/Fiscal/Application/Services/FiscalProjectionPolicyService.php \
  apps/api/app/Modules/Fiscal/Application/Services/FiscalCutoverRangeService.php \
  apps/api/app/Modules/Fiscal/Presentation/Console/RecordFiscalProjectionPolicyCommand.php \
  apps/api/app/Modules/Fiscal/Presentation/Console/AddFiscalProjectionCutoverCommand.php \
  apps/api/app/Modules/Fiscal/Presentation/Console/RecoverFiscalProjectionLeasesCommand.php \
  apps/api/app/Modules/Fiscal/Providers/FiscalServiceProvider.php \
  apps/api/app/Shared/Contracts/Treasury/ShiftCashEvidencePortInterface.php \
  apps/api/app/Shared/Contracts/Treasury/DTOs/ShiftCashSourceFactData.php \
  apps/api/app/Shared/Contracts/Treasury/DTOs/ShiftCashObservationResultData.php \
  apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php \
  apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php \
  apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferDocumentService.php \
  apps/api/app/Modules/Treasury/Application/Services/ShiftCashEvidenceService.php \
  apps/api/app/Modules/Treasury/Application/Services/ShiftCashSemanticFingerprint.php \
  apps/api/app/Modules/Treasury/Application/Jobs/ProcessShiftCashSourceEvidence.php \
  apps/api/app/Modules/Treasury/Presentation/Requests/RepositoryTransferRequest.php \
  apps/api/app/Modules/Treasury/Presentation/Console/ReplayRepositoryTransferCommand.php \
  apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php \
  apps/pos/src/lib/offline/zReportService.ts \
  apps/pos/src/lib/fiscal/zSessionAuthoring.ts \
  apps/pos/src/lib/fiscal/ZCloseManifestBuilder.ts \
  apps/pos/src/lib/db/repositories/zCloseManifestRepository.ts \
  apps/pos/src/lib/db/repositories/zCloseManifestOutboxRepository.ts \
  apps/pos/src/lib/sync/syncService.ts \
  apps/api/app/Modules/POS/Application/Services/ZCloseManifestIngestor.php \
  apps/api/app/Modules/POS/Application/Services/CashCountDispatcher.php \
  apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php \
  apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php \
  apps/api/app/Modules/POS/Application/Services/ReconciliationDependencyVersionService.php \
  apps/api/app/Modules/POS/Application/Services/PosSessionReconciliationService.php \
  apps/api/app/Modules/POS/Application/Services/SessionReconciliationFingerprint.php \
  apps/api/app/Modules/POS/Application/Jobs/DeliverCashCountObligation.php \
  apps/api/app/Modules/POS/Application/Jobs/ReconcilePosSession.php \
  apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php \
  apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php \
  apps/api/app/Modules/POS/Infrastructure/Commands/DeliverCashCountObligationsCommand.php \
  apps/api/app/Modules/POS/Infrastructure/Commands/ReconcilePosSessionCommand.php \
  apps/api/app/Modules/POS/Providers/POSServiceProvider.php \
  apps/api/tests/Feature/Fiscal/FiscalCutoverRangeTest.php \
  apps/api/tests/Feature/Treasury/RepositoryTransferDocumentTest.php \
  apps/api/tests/Feature/Treasury/ShiftCashEvidenceBridgeTest.php \
  apps/api/tests/Feature/POS/ZCloseManifestIngestorTest.php \
  apps/api/tests/Feature/POS/CashCountFanOutDurabilityTest.php \
  apps/api/tests/Feature/POS/PosSessionReconciliationAcceptanceTest.php \
  apps/pos/src/lib/fiscal/__tests__/zCloseManifest.atomicity.test.ts

git commit -m "Phase 7.1.3: Implement dormant W-CASH evidence workflows"
```

Run:

```bash
pnpm --filter @autoerp/pos tauri build
```

Then PL-510, push, verify API fingerprint, run PL-512 migration/audit rerun, and physical device smoke:

1. Online Z close.
2. Offline Z close.
3. Crash before close/Z append.
4. Crash after close/Z append but before manifest insert.
5. Crash after manifest/outbox commit but before sync.
6. Reconnect and sync one manifest.
7. Replay and receive `already_exists`.
8. Verify all three count obligations exist.
9. Verify all repository balances, movements, transfer documents, and journal-entry counts remain unchanged.
10. Verify company A/B, location 1/2, and terminal 1/2 isolation.

Rollback point: Push-2 deployment. False flags permit redeployment while retaining schema and authored device outbox data.

### PL-523 — Push 4: canonical DTO/UI and observe-only activation gate

Contents: T3 and T10. No Q10–Q13 behavior.

Exact paths:

```bash
cd apps/api
php artisan typescript:transform
cd ../..

TYPE_BASELINE="/tmp/w-cash-generated-${CANDIDATE_SHA}.d.ts"
cp packages/shared/types/generated.d.ts "$TYPE_BASELINE"

git add -- \
  apps/api/app/Modules/Treasury/Application/DTOs/PaymentRepositoryData.php \
  apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php \
  apps/api/app/Modules/POS/routes.php \
  apps/api/app/Modules/POS/Presentation/Requests/RetryPosSessionReconciliationRequest.php \
  apps/api/app/Modules/POS/Presentation/Controllers/PosSessionReconciliationController.php \
  apps/api/app/Modules/POS/Application/DTOs/PosSessionReconciliationData.php \
  apps/web/src/features/treasury/hooks/usePaymentRepositories.ts \
  apps/web/src/features/pos/api/paymentRepositoryApi.ts \
  apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx \
  apps/web/src/features/treasury/components/TransferCashModal.tsx \
  apps/web/src/features/treasury/RepositoryListPage.tsx \
  apps/web/src/features/treasury/RepositoryDetailPage.tsx \
  apps/web/src/features/treasury/PaymentForm.tsx \
  apps/web/src/features/treasury/SplitPaymentForm.tsx \
  apps/web/src/features/pos/api/sessionReconciliationApi.ts \
  apps/web/src/features/pos/components/SessionReconciliationPanel.tsx \
  apps/web/src/features/pos/components/CashDeliveryObligationsPanel.tsx \
  apps/web/src/features/pos/pages/ZReportDetailPage/ZReportDetailPage.tsx \
  apps/web/src/locales/en/treasury.json \
  apps/web/src/locales/fr/treasury.json \
  apps/web/src/locales/ar/treasury.json \
  apps/web/src/locales/en/pos.json \
  apps/web/src/locales/fr/pos.json \
  apps/web/src/locales/ar/pos.json \
  apps/pos/src/types/payment.ts \
  apps/pos/src/lib/db/repositories/paymentRepository.ts \
  apps/pos/src/api/paymentApi.ts \
  apps/pos/src/components/organisms/TransactionCart/TransactionCart.tsx \
  apps/pos/src/stores/paymentStore.ts \
  apps/pos/src/lib/sync/syncService.ts \
  apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx \
  apps/pos/src/components/pos/PaymentSummary.tsx \
  apps/pos/src/pages/ThemePreviewPage.tsx \
  apps/pos/src/test/helpers.ts \
  packages/shared/types/generated.d.ts \
  apps/api/tests/Feature/Treasury/PaymentRepositoryDataTest.php \
  apps/api/tests/Feature/POS/PosSessionReconciliationControllerTest.php \
  apps/web/src/features/treasury/__tests__/paymentRepositoryGeneratedType.test.ts \
  apps/web/src/features/pos/components/SessionReconciliationPanel.test.tsx \
  apps/pos/src/lib/db/__tests__/paymentRepositoryDecoder.test.ts

git commit -m "Phase 7.1.4: Add W-CASH read-only reconciliation surfaces"
```

After commit:

```bash
cd apps/api
php artisan typescript:transform
cd ../..
cmp "$TYPE_BASELINE" packages/shared/types/generated.d.ts
```

Prerequisite assertions before any flag becomes true:

```bash
: "${W2_ACCEPTED_SHA:?}"
: "${W4_ACCEPTED_SHA:?}"
: "${WLOT_B_ACCEPTED_SHA:?}"

git merge-base --is-ancestor "$W2_ACCEPTED_SHA" "$CANDIDATE_SHA"
git merge-base --is-ancestor "$W4_ACCEPTED_SHA" "$CANDIDATE_SHA"
git merge-base --is-ancestor "$WLOT_B_ACCEPTED_SHA" "$CANDIDATE_SHA"
```

If any assertion fails, deploy Push 4 with every W-CASH flag false and stop.

If prerequisites pass, create only observe-only policy/cutover evidence. Capture every ID before use:

```bash
POLICY_JSON="$(
  ssh "$STAGING_SSH" \
    "cd /var/www/html &&
     php artisan fiscal:projection-policy:record \
       '$COMPANY_ID' \
       '$PROJECTOR' \
       --effective-at='$EFFECTIVE_AT' \
       --actor='$ACTOR_ID' \
       --reason='W-CASH observe-only evidence' \
       --format=json"
)"
POLICY_RECORD_ID="$(printf '%s\n' "$POLICY_JSON" | jq -er '.aggregate_id')"
test -n "$POLICY_RECORD_ID"

CUTOVER_JSON="$(
  ssh "$STAGING_SSH" \
    "cd /var/www/html &&
     php artisan fiscal:projection-cutover:add \
       '$COMPANY_ID' \
       '$LOCATION_ID' \
       '$TERMINAL_ID' \
       '$PROJECTOR' \
       '$RAIL' \
       --lower-occurred-at='$LOWER_AT' \
       --lower-source-id='$LOWER_ID' \
       --upper-occurred-at='$UPPER_AT' \
       --upper-source-id='$UPPER_ID' \
       --policy-record='$POLICY_RECORD_ID' \
       --actor='$ACTOR_ID' \
       --format=json"
)"
CUTOVER_ID="$(printf '%s\n' "$CUTOVER_JSON" | jq -er '.aggregate_id')"
test -n "$CUTOVER_ID"
```

Repeat both commands and assert `outcome == "already_exists"` with identical IDs.

Allowed true flags after prerequisites and focused evidence are green:

```text
W_CASH_POLICY_EVIDENCE_ENABLED=true
W_CASH_RAW_EVIDENCE_ENABLED=true
W_CASH_Z_MANIFEST_ENABLED=true
W_CASH_COUNT_OBLIGATIONS_ENABLED=true
W_CASH_RECONCILIATION_ENABLED=true
W_CASH_TRANSFER_DOCUMENTS_ENABLED=false
VITE_W_CASH_READ_UI_ENABLED=true
AUTO_MIGRATE=false
```

Manual transfer documents stay false until their independent T4 business acceptance is approved; they are never activated as a cash-operation consumer.

Run:

```bash
pnpm exec tsx scripts/campaign.ts --web --api --country=TN
```

Then PL-510, push, update candidate env, explicitly deploy web, capture deployment ID/status, verify API/web fingerprint, asset hash, Playwright, per-service environment, audit JSON, two-company/location/terminal evidence, obligation backlog, and one constrained current run.

Rollback point: Push-3 deployment and captured flag/environment snapshot. Restore all flags false, redeploy captured API/web predecessors, and retain immutable evidence/history.

### PL-530 — Push 5: prohibited financial activation

Do not construct, commit, push, deploy, or set a financial flag until T11 is approved.

A later T11 supplement must:

1. Capture new fleet backups and complete IDs/hashes.
2. Add only owner-selected Q10–Q13 schema and behavior.
3. Define new typed reason/destination vocabulary only if selected.
4. Define custody ownership/join/refusal only if selected.
5. Define alignment/disabled-window treatment only if selected.
6. Run controlled migrations before schema-dependent code activation.
7. Activate one cohort at a time.
8. Verify transfer cardinality, GL chain, repository balances, two-company/location/terminal isolation, reconciliation, and rollback.
9. Reverse posted documents rather than delete evidence.

## 13. Dispatch order

1. T0 — read-only capability/writer census.
2. T12 — rollout foundation.
3. Push 1 — T0 + T12.
4. T1 — exact server/device schemas, models, enums, DTOs, glossary.
5. Push 2 — additive schema only; controlled fleet migration.
6. T2 — observe-only policy and ordered cutovers.
7. T4 — generic manual transfer documents.
8. T5 — policy-neutral v2/v3 evidence.
9. T7 — atomic derivable close manifest.
10. T8 — transactionally seeded cash-count obligations.
11. T9 — canonical W7 reconciliation using `ShiftExpectedCashService`.
12. Push 3 — dormant backend/device workflows.
13. T3 — canonical repository DTO and convention-09 journey.
14. T10 — canonical shift/Z read surface.
15. Push 4 — read UI and observe-only activation only after W2/W4/W-LOT-B proof.
16. Stop.
17. T11 and Push 5 remain prohibited pending Q10–Q13 rulings.

Dependencies:

- T12 precedes every push that consumes backup JSON, fingerprints, Docker build arguments, entrypoint validation, or Dokploy polling.
- T1 requires accepted W-LOT-B v68/v69 ownership; W-CASH uses v70/v71.
- T2/T4/T5/T7/T8/T9 require T1’s migrated schema.
- T5 requires T2; it does not require or call T4.
- T7 requires device v70/v71 and server manifest schema.
- T8 requires both server Z/count producers and its obligation schema.
- T9 requires T2, T5, T7, T8, accepted W2, accepted W4, and accepted W-LOT-B.
- T3 is independent of Q10–Q13.
- T10 requires T3 and T9.
- T11 is never inferred from any prior task or deployment state.

## 14. Final verification checklist

### Authority and policy

- [ ] Inspected base is `4878c3e6add3f2815522e6f1cb553ebcd81b3e66`.
- [ ] Q10–Q13 match owner rows verbatim and remain OPEN.
- [ ] No Q10–Q13 branch exists in schema, enum, state, flag, command, task, worker, UI, seed, or fixture.
- [ ] No cash-reason vocabulary or destination mapping exists.
- [ ] Raw evidence has only opaque event names and free-text reason.
- [ ] Unmapped/unlinked evidence is blocked.
- [ ] T11 and Push 5 remain prohibited.
- [ ] Historical backbooking and automatic variance activation remain prohibited.

### Schema and ownership

- [ ] Every composite FK target unique exists earlier in the same migration sequence.
- [ ] Exact target/source column order is asserted through PG catalogs.
- [ ] Cross-company and cross-tenant attachments fail.
- [ ] Projection code uses `projection_status`; no invented projection company column exists.
- [ ] Cash-count obligations reference real Z/shift/terminal identities.
- [ ] Reconciliation carries tenant/company/location/terminal/shift/session/Z/manifest identity.
- [ ] Every money column is `decimal(20,3)`.
- [ ] Every JSONB column has its named DTO.
- [ ] Every technical state/type has enum, cast, and named check.
- [ ] SQLite v70 precedes v71 and both rerun safely.
- [ ] W-LOT-B retains v68/v69 ownership.

### Conventions 09, 10, and 11

- [ ] Benchmark matrix has the exact required columns and a decision in every row.
- [ ] Every AutoERP-today cell contains a real path:line.
- [ ] Real company creation produces company B.
- [ ] Real location creation produces a second `pos_enabled` location.
- [ ] Company/location switching cannot expose A rows in B.
- [ ] Reruns assert unchanged IDs, balances, row counts, and explicit outcomes.
- [ ] Glossary names each concept, table, writer, and surface.
- [ ] `PosSessionReconciliationService` is the only reconciliation writer.
- [ ] Existing shift/Z detail is the only operator surface.
- [ ] `PaymentRepositoryData` is the only cross-boundary repository type.
- [ ] Generated output is `packages/shared/types/generated.d.ts`.
- [ ] POS SQLite conversion validates rather than casts unchecked values.

### Financial correctness and locks

- [ ] Writer/direct-balance census is regenerated at implementation HEAD.
- [ ] Every repository mutation reaches `TreasuryMovementService`.
- [ ] Lock order is numbering → GL chain → transfer document → sorted repositories → movements.
- [ ] Opposite transfers and unrelated GL writes pass barriered PG concurrency tests.
- [ ] One manual transfer document creates exactly two movements and zero-or-one journal entry.
- [ ] No raw cash evidence references or calls a transfer document.
- [ ] Posted transfer correction is reversal-only.
- [ ] `InventoryGlPostingBuffer` has zero W-CASH calls.

### Semantic evidence and manifests

- [ ] Raw source identity is separate from semantic identity.
- [ ] Semantic fingerprint excludes rail/source UUID.
- [ ] Fingerprint includes tenant/company/location/terminal/shift/session/time/name/amount/currency/authored linkage.
- [ ] Linked v2/v3 produces one semantic fact and two raw evidence rows.
- [ ] Unlinked cross-rail evidence remains blocked.
- [ ] Near-collision and conflicting-link tests pass.
- [ ] Every current Z-contributing namespace has an exact derivation.
- [ ] Payment child UUID/hash/sequence derivation matches shared vectors.
- [ ] W-LOT-B membership is explicitly absent from manifest v1.
- [ ] Close/Z IDs and hashes exist before manifest construction.
- [ ] Z/count, close/Z events, manifest, outbox, and chain advancement share one SQLite transaction.
- [ ] Server revalidates member order, hashes, schema version, and ownership.

### Cash-count delivery

- [ ] Both server-generation and device-sync transactions insert all three obligations before commit.
- [ ] After commit only delivery enqueue occurs.
- [ ] Treasury, Compliance, and stored-event results are independently durable.
- [ ] Stored event and applied obligation state commit atomically.
- [ ] Crash/retry coverage includes precommit, postcommit/prequeue, claim, consumer, and outcome boundaries.
- [ ] Redelivery cannot repeat effects.

### Reconciliation

- [ ] `ShiftExpectedCashService` remains the only expected-cash calculator.
- [ ] Manifest membership constrains all expected-cash source reads.
- [ ] Dependency writers increment the locked version in their own transactions.
- [ ] A changed version discards a mixed snapshot.
- [ ] Runs are immutable occurrences; the head contains the sole current pointer.
- [ ] Same-as-current fingerprint returns `already_exists`.
- [ ] A→B→A appends a new A occurrence and makes it current.
- [ ] Unknown/reclassified tenders fail closed.
- [ ] Chain verification remains complementary.
- [ ] Q-dependent cells report only `owner_ruling_required`.
- [ ] Repository comparison remains blocked before T11.

### Promotion

- [ ] Push 1 contains every rollout tool later pushes consume.
- [ ] Push 2 contains no schema-dependent service.
- [ ] All rollout variables are forwarded by shared Compose.
- [ ] API, worker, scheduler, and websocket validate flags before config caching.
- [ ] `AUTO_MIGRATE=false` permits controlled migration.
- [ ] Central or tenant migration failure exits nonzero.
- [ ] Fleet backup JSON passes one `all(...)` assertion.
- [ ] Every backup ID/hash is captured before migration.
- [ ] `tenants:migrate-rolling --force` emits and succeeds for every active tenant.
- [ ] Candidate SHA equals `origin/dev`, API fingerprint, web fingerprint, and Docker build arguments.
- [ ] Dokploy application and deployment IDs are captured from command output before reuse.
- [ ] Deployment status is correlated by unique title plus candidate SHA and reaches success within timeout.
- [ ] Every web-changing push triggers explicit Dokploy web deployment.
- [ ] Served entry-asset SHA-256 matches build metadata.
- [ ] Feature fingerprint equals `w-cash-rev5-policy-neutral`.
- [ ] Playwright smoke passes after every web deployment.
- [ ] Physical Tauri build and online/offline/crash/reconnect/replay smoke pass.
- [ ] Push 4 activation is stopped unless accepted W2, W4, and W-LOT-B SHAs are ancestors.
- [ ] Every push has a captured predecessor deployment/environment rollback point.
