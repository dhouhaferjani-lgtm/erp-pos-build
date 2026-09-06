<!-- Rev 2, authored by Codex CLI (gpt-5.6-sol, high, read-only) on 2026-09-06 from rev 1 + plan gate r2; filed verbatim by the orchestrator. Status: awaiting plan gate r3. Rev 1 is in git history (67c0805d4). -->
<!-- Authored by Codex CLI on 2026-09-06 from local dev HEAD 6e17a76022c5ccd8864afdf98cd5302e5264176b. Revision 2 replaces docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md verbatim. Read-only planning pass: no repository files changed and no tests run. -->

# W-CASH Execution Plan — Revision 2

## Opening Float, Cash Operations, and Drawer → Safe → Bank Treasury Custody

**Date:** 2026-09-06  
**Repository:** `/Users/houssamr/Projects/syneriva/apps/erp`  
**Verified local `dev` HEAD:** `6e17a76022c5ccd8864afdf98cd5302e5264176b`  
**Authority order:** owner rulings → accepted specification v4 → W-CASH brief → gate-r2 corrections  
**Delivery mode:** reader-first additive migrations; all money-writing capability disabled by default  
**Audit artifact:** `docs/superpowers/audits/2026-09-05-w-cash-census.md`  
**Handback artifact:** `docs/handoff/HANDBACK-W-CASH-2026-09-06.md`

Source files inspected at this HEAD:

- `CLAUDE.md`
- `docs/conventions/09-SECOND-OF-EVERYTHING.md`
- `docs/conventions/10-BENCHMARK-FIRST-SPECS.md`
- `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md`
- `docs/glossary.md`
- `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md`
- `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md`
- revision 1 of this plan
- `docs/superpowers/reviews/2026-09-06-w-cash-plan-codex-gate-r2.md`

Existing unrelated untracked files observed during the read-only inspection are outside W-CASH and must not be modified.

---

## 1. Revision change log and gate closure

No gate finding is rejected.

### 1.1 Gate-r1 PARTIAL/OPEN closure

| Gate-r1 finding | Rev 2 disposition |
|---|---|
| B2 — C1 census incomplete | **CLOSED:** §§6–7 enumerate device/server sources, X/Z payloads, every direct repository-movement caller, writer boundaries, transactions, predicates, locks, scope, and hold time. T0 adds a capability-aware live census that works before future tables exist. |
| B4 — v2/v3 fingerprint and cutover incomplete | **CLOSED:** §8.2 defines the semantic fingerprint independently of raw evidence hashes; §§8.3–8.4 define immutable configuration revisions, event-time policy records, and bounded rail cutovers. T2 and T5 name equivalence/conflict tests. |
| B5 — shared drawer/opening semantics open and contradictory | **CLOSED:** Q11 remains OPEN verbatim. §8.5 supplies the complete retained-balance and sequential-shift algorithm only as a conditional implementation branch. No unconditional opening transfer remains. |
| B6 — W7 implementation incomplete | **CLOSED:** T9 is sequenced after W2 classification, W4 policy completeness, T7 manifests, T8 durable dispatch, and W-LOT status readiness. It includes the complete spec-v4 matrix, append-only supersession, and one reconciliation writer. |
| B7 — migration/deployment unsafe | **CLOSED:** T1 exclusively owns `apps/pos/src/lib/db/migrations.ts` and adds v68 before v69. §12 defines persisted cutovers and the five-push staging manifest. |
| M1 — frozen replay alert incomplete | **CLOSED:** T4 adds `treasury_transfer_alerts`, `TreasuryTransferAlert`, `FrozenTransferAlertData`, company-scoped uniqueness, and retry tests. |
| M3 — owner-controlled semantics incomplete | **CLOSED:** §3 carries Q10–Q13 verbatim as OPEN. Q10 gates W-LOT-dependent promotion; Q11 gates shared-drawer/opening behavior; Q12 gates typed financial dispatch; Q13 gates historical alignment and variance activation. |
| M4 — convention-09 tests not task-specific | **CLOSED:** every task names its second-company, second-location, and rerun-data-meaning cases, first failing assertion, command, and lane. |
| M6 — module and prerequisite gaps | **CLOSED:** T4 gates the reused transfer route with `module:Treasury`; T9 cannot dispatch before its explicit W2/W4/manifest/W-LOT prerequisites. |
| M7 — alignment files generic | **CLOSED:** T11 gives exact model, DTO, service, command, migration, controller, request, test, and route paths. The task remains conditional on Q13. |
| M9 — local type census incomplete | **CLOSED:** §5.2 enumerates every located repository/transfer/movement type alias, including the actual HEAD paths for `RepositoryListPage.tsx`, `PaymentForm.tsx`, and `useTransferCash.ts`. |

### 1.2 Gate-r2 closure

| Gate-r2 finding | Rev 2 disposition |
|---|---|
| BLOCKER — Q10 omitted and Q11/Q12 partly decided | **CLOSED:** §3 reproduces all four owner rows verbatim and marks every row OPEN. No SAFE_DROP dispatch occurs before Q12. |
| BLOCKER — no opening interval-start algorithm | **CLOSED:** §8.5 defines zero, partial top-up, retained-match, retained-excess mismatch, sequential-shift, shared-drawer, missing predecessor, and late predecessor behavior, all conditional on Q11. |
| BLOCKER — recovery uses current activation | **CLOSED:** §8.3 and T2 require immutable event-time policy evidence or an explicit persisted legacy cutover. Recovery may not query today’s registry to invent historical obligations. |
| BLOCKER — no durable cutover/config revision | **CLOSED:** T1/T2/T3 add immutable policy records, cutovers, configuration revisions, authored revision evidence, and bounded source ranges. |
| BLOCKER — tasks lack dispatch contracts | **CLOSED:** every task in §11 contains exact production paths, signatures/schema deltas, red-first tests, command/lane, implementation steps, convention-09 cases, and a reviewer stop. |
| MAJOR — v69 before v68/W7 before prerequisites | **CLOSED:** T1 owns both device migrations and orders v68 then v69; T9 is downstream of T2, T5, T7, T8, W2, W4, and W-LOT. |
| MAJOR — W7 matrix incomplete | **CLOSED:** T9 contains every acceptance case from spec v4 §W7. |
| MAJOR — reconciliation supersession mutates history | **CLOSED:** `pos_session_reconciliation_supersessions` is append-only; the current-result query anti-joins superseded runs. |
| MAJOR — CashCountDispatcher not durable | **CLOSED:** T8 changes `dispatch()` to a typed result, persists one obligation per consumer, and tests partial success and enqueue failure. |
| MAJOR — manifest membership/atomicity undefined | **CLOSED:** §8.6 limits the device manifest to device-authored streams. T7 inserts Z, manifest, and local sync outbox in the existing SQLite write transaction and stores server Z/manifest together. |
| MAJOR — cross-rail fingerprint not canonical | **CLOSED:** §8.2 defines normalized common semantic fields and separate raw-rail hashes. |
| MAJOR — manual movement cannot link to shift/session | **CLOSED:** T4 extends the immutable transfer document/request with authorized optional `shift_id` and `session_id`, validated against company and location. |
| MAJOR — transfer route lacks module gate | **CLOSED:** T4 modifies the verified route at `apps/api/app/Modules/Treasury/Presentation/routes.php:102`. |
| MAJOR — JSONB DTOs unnamed | **CLOSED:** §9 maps every new JSONB column to an exact PHP DTO and requires round-trip tests. |
| MAJOR — local type census incomplete | **CLOSED:** §5.2. |
| MAJOR — provisioning hooks unnamed | **CLOSED:** T3 names `CompanyController`, `LocationController`, both existing provisioners, their interfaces, and their transaction behavior. |
| MAJOR — promotion evidence incomplete | **CLOSED:** T12 and §12 require two branches, two terminals, the onboarding campaign, direct and fleet day-one census, and real Tauri/SQLite/printer/offline/crash smoke. |
| MAJOR — frozen alert not durable | **CLOSED:** T4. |
| MAJOR — tenant-only unique ratchet | **CLOSED:** §9.2 requires `company_id` in every new business unique and names the PostgreSQL ratchet test. |
| MINOR — T0 assumes future tables | **CLOSED:** T0 guards optional probes with `Schema::hasTable()` and tests against the pre-migration schema. |
| MINOR — `training_flag` vague | **CLOSED:** §8.1 fixes the path at `payload.training_flag`, requires a real boolean, and fails closed on missing/string values. |
| MINOR — coverage writer ambiguous | **CLOSED:** `RepositoryCashCoverageService` is a pure calculator; only `PosSessionReconciliationService` persists reconciliation data. |

### 1.3 Findings explicitly preserved

- One repository transfer document/group produces exactly two repository movement legs and zero or one journal entry.
- Existing expected-cash calculation already includes opening cash; it must not add a second opening component.
- A sidecar close manifest is sufficient; sealed fiscal payload bytes do not need a version bump.
- Existing queues remain `default` and `fiscal-projections`.
- Acceptance order is opening → sales → drop → close/reconcile.
- Bank settlement confirmation remains separate from repository transfer creation.
- Historical per-shift backbooking remains prohibited.
- Existing repository and shift/Z detail surfaces remain canonical.

---

## 2. Outcome and non-negotiable boundaries

W-CASH makes physical cash custody explicit without rewriting fiscal history:

1. A typed custody action creates one immutable transfer document, one transfer group, exactly two cross-linked repository movements, and zero or one posted journal entry.
2. Opening cash is not revenue. Whether a declared opening amount causes a safe → drawer transfer depends on the Q11-approved interval-start policy.
3. Drawer → safe and safe → bank use the existing Treasury transfer port.
4. Petty expense is not a transfer. If Q12 approves that classification, it produces an expense document, one cash-repository movement, and one journal entry.
5. v2 and v3 facts converge on one semantic booking obligation while retaining distinct immutable evidence.
6. Training events never move money.
7. No worker or recovery command may infer historical obligations from today’s enabled modules or current repository mappings.
8. No migration, backfill, or activation fabricates historical per-shift movements.
9. New capability defaults disabled at global, company/location configuration, and cutover levels.
10. W7 reports fiscal comparison and repository-coverage comparison independently.
11. Mutable current repository balance is never used as historical close truth.
12. `ShiftExpectedCashService` remains the expected-drawer calculator; W-CASH must not duplicate its opening component.

---

## 3. Owner rulings — all remain OPEN

**Status: OPEN for Q10, Q11, Q12, and Q13.** The rows below are copied verbatim from `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:138-143`. Recommended defaults are recommendations only.

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

Conditionality:

- **Q10:** blocks launch certification of W-LOT status handling and therefore the W7 lot-status promotion row. W-CASH must consume only the eventually approved W-LOT status contract.
- **Q11:** blocks shared-drawer enforcement, interval ownership, retained-balance opening booking, and activation of opening transfers.
- **Q12:** blocks financial dispatch of `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `DEPOSIT`, and `PAYOUT`. No SAFE_DROP authoring may dispatch before this ruling.
- **Q13:** blocks alignment execution and `TREASURY_SHIFT_VARIANCE_GL_ENABLED=true`.
- Generic schemas, disabled readers, policy-recording infrastructure, manifests, and reconciliation plumbing may ship dormant without selecting any recommended default.

---

## 4. Benchmark and compatibility baseline

This table satisfies convention 10: external benchmark, current AutoERP evidence, gap, decision, and verification are explicit.

| Guarantee | Benchmark | AutoERP at HEAD | W-CASH disposition |
|---|---|---|---|
| Opening is a session/custody fact | Odoo POS opening cash control; ERPNext POS Opening Entry | Device writes `SESSION_OPEN` and `OPENING_FLOAT` in one transaction (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:507-566`), but Treasury does not book it. | Match only after Q11 through the interval-start contract; never duplicate opening in expected cash. |
| Cash in/out has typed economic meaning | Odoo cash-move reasons map to accounts; ERPNext selects company-specific payment accounts | Device UI maps free-text deposit/payout to `CASH_IN`/`CASH_OUT` (`apps/pos/src/api/cashDrawerApi.ts:27-76,139-194`). | Q12-gated typed policy; unresolved or `OTHER` remains blocked without money. |
| Internal transfer affects both custody accounts | Odoo internal transfers create outgoing/incoming liquidity effects | `TreasuryMovementService::transfer()` already creates two legs (`apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:225-396`). | Add immutable transfer document, evidence, optional shift/session link, and durable conflict alert. |
| Close compares expected and counted cash | Odoo Closing Control; ERPNext POS Closing Entry | Expected cash exists; W7 result store does not. | Add immutable manifest, independently derived comparison, and repository ordinal coverage. |
| Replays never move money twice | Submitted accounting documents have stable identity | Existing movement service has idempotency support. | Company-scoped semantic obligation plus raw-rail evidence and visible conflict. |
| Cash custody is company/location scoped | Odoo journals/POS configs and ERPNext profiles/accounts are company-specific | Company/location provisioning creates repository rows (`CompanyController.php:194-196`; `LocationController.php:216,240-251`). | Add disabled configuration head/revision during provisioning and convention-09 isolation tests. |
| History is corrected append-only | Posted accounting is corrected by reversal/compensation | Repository movements are protected by immutability migrations. | Original documents/results remain unchanged; append reversal or supersession records. |
| Offline facts recover without bypassing controls | POS may work temporarily offline | v3 and v2 facts can arrive late; frozen repositories currently reject new transfers. | Persist obligations, permit explicitly flagged verified offline transfers, retain no-negative rule, and emit durable once-per-group alerts. |
| Legacy custody starts from one reviewed balance | Accounting systems use opening entries, not invented past transactions | Existing opening service refuses foreign history. | Q13-gated single alignment; no historical shift backbooking. |

---

## 5. Convention-11 vocabulary and one-surface contract

### 5.1 Glossary reconciliation

Existing canonical terms at HEAD:

- **Repository:** `docs/glossary.md:60`
- **Terminal:** `docs/glossary.md:74`
- **Shift:** `docs/glossary.md:75`
- **Session reconciliation:** `docs/glossary.md:76`

T3/T4/T9/T11 add these exact glossary rows:

| Canonical term | Definition | Sole writer | Canonical surface |
|---|---|---|---|
| Cash custody configuration | Company/location mapping and policy governing terminal drawer, safe, optional bank, and operation classifications. | `CashCustodyConfigurationService` | Treasury repository detail/editor |
| Cash custody configuration revision | Immutable snapshot used to interpret authored cash facts. | `CashCustodyConfigurationService::appendRevision()` | Configuration history on repository detail |
| Fiscal projection policy record | Event-time decision that a named projector was required, skipped, or blocked. | `FiscalProjectionPolicyRecorder` | Fiscal projection diagnostics |
| Fiscal projection cutover | Persisted bounded rule for admitting legacy source facts. | `FiscalProjectionCutoverService` | Fiscal projection diagnostics |
| Shift cash booking | Idempotent conversion of a typed POS cash fact into a Treasury document/effect. | `ShiftCashBookingService` | Shift/Z detail for status; repository detail for money |
| Repository transfer document | Immutable justification owning one transfer group, two movement legs, and zero/one JE. | `RepositoryTransferDocumentService` | Existing repository transfer/detail surface |
| Coverage interval | Immutable manifest/dependency membership and repository ordinal bounds used for a close comparison. | `PosSessionReconciliationService` | Shift/Z detail |
| Opening-balance alignment | Reviewed cutover adjustment between observed physical cash and Treasury without fabricated past shifts. | `RepositoryBalanceAlignmentService` | Repository detail; Q13-conditional |

No second repository catalogue, transfer modal, bank-deposit catalogue, or shift-close screen is created.

### 5.2 Complete local TypeScript census

Canonical backend DTOs will generate `PaymentRepositoryData`, `RepositoryMovementData`, `RepositoryTransferDocumentData`, `CashCustodyConfigurationData`, and `PosSessionReconciliationData` into `packages/shared/types/generated.d.ts`.

Local entity aliases to remove or replace:

| HEAD path | Local type | Disposition |
|---|---|---|
| `apps/web/src/features/treasury/RepositoryListPage.tsx:23` | `Repository` | Replace with generated `PaymentRepositoryData`. |
| `apps/web/src/features/treasury/PaymentForm.tsx:60` | `Repository` | Replace with generated DTO/pick. |
| `apps/web/src/features/treasury/hooks/useTransferCash.ts:5-19` | `TransferPayload`, `TransferResponse` | Replace with generated request/result types. |
| `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts:7-20` | `PaymentRepository` | Remove. |
| `apps/web/src/features/treasury/RepositoryDetailPage.tsx:27-45` | `Repository`, response wrapper | Replace with generated DTO. |
| `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:28` | repository shape | Replace with generated DTO/pick. |
| `apps/web/src/components/organisms/AddRepositoryModal/AddRepositoryModal.tsx:41` | repository shape | Replace with generated DTO. |
| `apps/web/src/features/treasury/SplitPaymentForm.tsx:27` | repository shape | Replace with generated DTO/pick. |
| `apps/web/src/features/pos/api/paymentRepositoryApi.ts:9` | repository shape | Replace with generated DTO. |
| `apps/web/src/features/treasury/hooks/useRepositoryMovements.ts:38` | movement shape | Replace with generated `RepositoryMovementData`. |

Permitted projection-only view models, explicitly not canonical entities:

| HEAD path | View model | Rule |
|---|---|---|
| `apps/web/src/features/treasury/hooks/useCashPosition.ts:14-16` | `CashPositionRepository` | Keep as server cash-position projection only; add comment naming source DTO. |
| `apps/web/src/features/treasury/hooks/useRemittances.ts:9` | `RemittanceRepository` | Keep as remittance selector projection. |
| `apps/web/src/features/treasury/statements/api.ts:79` | `RepositoryMovementCandidate` | Keep as statement-matching candidate. |
| `apps/web/src/features/treasury/statements/StatementUploadWizard.tsx:26` | `WizardRepository` | Replace with a pick from the generated candidate DTO or document why UI-only. |

---

## 6. HEAD source, payload, and producer census

### 6.1 Device fiscal and offline sources

| Source | Verified writer | Current behavior | W-CASH use |
|---|---|---|---|
| v3 `SESSION_OPEN` + `OPENING_FLOAT` | `authorZSessionOpenWithOpeningFloatOnDb()` at `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:507`; one transaction at `:523`; event appends at `:539-566` | Opening appears twice semantically: session declaration plus distinct movement fact. | `OPENING_FLOAT` is the source identity; `SESSION_OPEN` supplies boundary context. Financial effect is Q11-conditional. |
| v3 cash movement | `authorZCashDrawerMovement()` in `zSessionAuthoring.ts`; caller `apps/pos/src/api/cashDrawerApi.ts:139-194` | UI authors only `CASH_IN` or `CASH_OUT`; `SAFE_DROP` exists in registries but is not the observed UI choice. | Record raw fact and block until a Q12-approved typed policy resolves it. |
| v3 close/Z | `appendZSessionCloseAndZReport()` at `zSessionAuthoring.ts:664-722` | Close and Z are consecutive fiscal events. | Boundary plus immutable manifest identity. |
| Offline Z generation | `generateZReport()` at `apps/pos/src/lib/offline/zReportService.ts:134`; source collection at `:198-225`; write transaction at `:541-576` | Inserts local Z, counts, chains, grand totals, then close/Z. | Add manifest and sync-outbox writes to this same transaction. |
| v2 offline cash rows | `apps/pos/src/lib/db/repositories/cashDrawerRepository.ts`; `apps/pos/src/api/cashDrawerApi.ts:79-133` | Stores deposit/payout, amount, free-text reason, approval, shift, terminal, idempotency key. | Retain immutable row; append typed policy evidence in v68; never infer from reason text. |
| Device sync | `apps/pos/src/lib/sync/syncService.ts:541-562,591,633,2294` | Z reports and drawer operations sync separately. | Z request carries its sidecar manifest; reordered fiscal-event upload remains pending, not falsely complete. |
| SQLite schema | `apps/pos/src/lib/db/migrations.ts:2163` | Highest version is 67. | T1 exclusively adds v68 then v69. |

### 6.2 Server fiscal and cash-count sources

| Source | Verified path | Current behavior | Required change |
|---|---|---|---|
| Fiscal event ingestion | `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:980-1046` | Selects today’s active projectors and inserts pending rows. | Record immutable event-time policy decisions in the ingest transaction. |
| Projection seeding | `FiscalEventProjectionDispatcher.php:79,110-159` | Uses current active registry. | Seed only from persisted policy records or explicit bounded cutover. |
| Projection worker | `ApplyFiscalEventProjectionJob.php:226-319` | Short `lockForUpdate()` claim transaction; releases before projector work. | Add `blocked` CAS state and typed detail without holding the lock during work. |
| v3 Z projection | `apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:54` | Persists Z/cash count but emits no durable count obligation. | Create the producer obligation transactionally and dispatch after commit. |
| Other cash-count producers | `ReportGenerationService.php:433`; `ZReportSyncController.php:508,555` | Call a void dispatcher. | Route through one idempotent producer service. |
| Dispatcher | `CashCountDispatcher.php:89-112` | One event dispatch; catches failure; first listener failure can suppress later listeners. | Typed per-consumer outcomes and persisted obligations. |
| Treasury consumer | `PostShiftCashVarianceAdjustment.php:294`; registered at `TreasuryServiceProvider.php:232-233` | Disabled by `treasury.shift_variance_gl_enabled`. | Consumer key `treasury.shift_variance`; activation only after matched coverage and Q13. |
| Compliance consumer | `OpenFraudAlertForShiftVariance.php:14,22`; registered at `ComplianceServiceProvider.php:82-85` | Event listener. | Consumer key `compliance.shift_variance`; independent outcome/retry. |

### 6.3 X/Z and related payload census

The current fiscal payload keys are:

- `XReportPayload.php:9-29`: `business_date`, `cash_drawer_totals`, `generated_at_device`, `operational_event_range`, `operator_id`, `operator_name`, `payment_method_totals`, `period_start`, `period_end`, `receipt_count`, refund/sale totals, `session_id`, `shift_id`, `terminal_id`, `training_flag`, `vat_breakdown`, void totals, `x_report_uuid`.
- `ZReportPayload.php:9-42`: `business_date`, `cash_count`, `cash_drawer_totals`, `closed_at_device`, `company_snapshot`, `currency_code`, `currency_scale`, formatted and numeric Z identifiers, grand totals before/after, `legacy_report_reference`, `operational_event_range`, operator, payment totals, period, receipt/refund/sale totals, seller, `session_event_range`, session/shift/terminal identity, terminal label, tolerance summary, `training_flag`, VAT, voids, `z_report_uuid`.
- `SessionClosePayload.php:9-38`: cash-count lines, expected/count/variance data, session/shift/terminal identity, operator and `training_flag`.
- `ZCashDrawerMovementPayload.php:9-26`: amount, approval, business date, operation ID, currency/scale, device event time, movement ID/type, operator, reason code/text, session/shift and `training_flag`.
- `zReportService.ts:748-753`: current `operationalEventRange` covers receipt sequence/hash membership only; it is not a complete close manifest.

The sidecar manifest therefore must not be misrepresented as an existing X/Z field.

---

## 7. Complete writer and lock census

### 7.1 Current repository-movement callers

`TreasuryMovementService::insertMovementLeg()` at `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:554-608` is the sole low-level `repository_movements` insert and `payment_repositories.balance` writer. `record()` requires an already-active caller transaction at `:49-56`; `transfer()` owns its transaction at `:225-237`.

Every direct call located at HEAD:

| Path/method or call line | Transaction owner | Row predicates and scope | Lock order and hold time |
|---|---|---|---|
| `IncomeService::post()` call at `apps/api/app/Modules/Income/Application/Services/IncomeService.php:175` | Enclosing `post()` transaction | Source document/idempotency key; tenant/company repository | Existing accounting/JE serialization, then repository row through `record()`; held to outer commit |
| `ExpenseService::post()` calls at `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:452,488` | Enclosing `post()` transaction | Expense/source identity and company repository | GL/JE work, then repository; outer commit |
| `ExpenseService::settle()` call at `:816` | Enclosing settlement transaction | Settlement/source identity | GL then repository; outer commit |
| `ExpenseService::reverse()` call at `:998` | Enclosing reversal transaction | Reversal source identity | GL then repository; outer commit |
| `RefundCompensationService::compensate()` call at `apps/api/app/Modules/Fiscal/Application/Services/RefundCompensationService.php:308` | Enclosing compensation transaction | Fiscal source identity, company repository | GL then repository; outer commit |
| `VendorRefundService::refundPrepayment()` call at `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:211` | Enclosing refund transaction | Vendor prepayment/refund identity | GL then repository; outer commit |
| `MultiPaymentService::recordInMovement()` at `apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php:594-602` | Public payment orchestration transaction | Payment line/source key | GL then repository; outer commit |
| `PaymentController::store()` call at `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:1349` | `store()` transaction, method begins `:340` | Payment/idempotency and company | GL then repository; request transaction |
| `PaymentController::storeMultiple()` call at `:2063`, method at `:1421` | `storeMultiple()` transaction | Payment group/line identity and company | GL then repository; request transaction |
| `PaymentRefundService::postRefundGlAndMovement()` call at `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:729` | Enclosing refund transaction | Refund/source identity | GL then repository; outer commit |
| `PaymentRefundService::postReversalGlAndMovement()` call at `:1087` | Enclosing reversal transaction | Reversal/source identity | GL then repository; outer commit |
| `OutboundInstrumentService::clear()` call at `apps/api/app/Modules/Treasury/Application/Services/OutboundInstrumentService.php:140` | `clear()` transaction, method at `:50` | Instrument lifecycle source | GL then repository; outer commit |
| `OutboundInstrumentService::bounce()` call at `:298` | `bounce()` transaction, method at `:203` | Instrument lifecycle source | GL then repository; outer commit |
| `OutboundInstrumentService::represent()` call at `:452` | `represent()` transaction, method at `:360` | Instrument lifecycle source | GL then repository; outer commit |
| `InstrumentLifecycleService::clear()` call at `apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:277` | `clear()` transaction, method at `:205` | Instrument/source identity | GL then repository; outer commit |
| `InstrumentLifecycleService::bounce()` call at `:495` | `bounce()` transaction, method at `:332` | Instrument/source identity | GL then repository; outer commit |
| `RepositoryOpeningBalanceService::seed()` call at `apps/api/app/Modules/Treasury/Application/Services/RepositoryOpeningBalanceService.php:152` | `seed()` transaction, method at `:95` | Company/repository/source; refuses foreign movement history | GL then repository; outer commit |
| `RepositoryTransferService::transfer()` call at `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:89` | Transaction at `:64-115` | Company-scoped source/destination IDs at `:118-123` | Company GL advisory lock, repositories sorted by UUID; held to commit |
| `TreasuryReceiptBridge::projectPaymentLineFromCanonical()` call at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:1560` | Fiscal projection’s financial transaction | Fiscal event/payment-line source | GL then repository; projection transaction |
| `RepositoryAdjustmentService::post()` call at `apps/api/app/Modules/Treasury/Application/Services/RepositoryAdjustmentService.php:234` | `post()` transaction, method at `:90` | Adjustment document/source identity | GL then repository; outer commit |
| `AcquirerFeeService::record()` call at `apps/api/app/Modules/Treasury/Application/Services/AcquirerFeeService.php:133` | `record()` transaction, method at `:35` | Acquirer fee/source identity | GL then repository; outer commit |
| `TreasuryDepositBridge::apply()` call at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:249` | Projection financial transaction | Fiscal deposit-event identity | GL then repository; projection commit |
| `TreasuryAccountPaymentBridge::apply()` call at `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:267` | Projection financial transaction | Fiscal account-payment identity | GL then repository; projection commit |

No W-CASH class may insert `repository_movements` or update cached repository balance directly.

### 7.2 Current and planned non-movement writers

| Writer | Rows/predicate | Transaction owner | Lock order, scope, hold |
|---|---|---|---|
| `authorZSessionOpenWithOpeningFloatOnDb()` | Device fiscal chain for one company/terminal/session | SQLite fiscal write transaction | Device DB writer lock; append session then opening; transaction only |
| `generateZReport()` | Local Z, counts, fiscal events | SQLite `withWriteTransaction('fiscal')` | Device DB writer lock; Z → manifest → local outbox → close/Z; transaction only |
| `OutboxIngestor::dispatchProjections()` | One event/projector policy and projection row | Fiscal ingest transaction | Unique insert/CAS, no Treasury locks; ingest transaction |
| `ApplyFiscalEventProjectionJob::handle()` | One `fiscal_event_projections` row by ID/status | Short claim transaction | Projection row `FOR UPDATE`; released before projector work |
| `CashCustodyConfigurationService` | Head by `(company_id,location_id)`; next immutable revision | Service transaction | Configuration head `FOR UPDATE`; no GL/repository locks; commit immediately |
| `FiscalProjectionCutoverService` | Cutover by company/location/terminal/rail/bound | Service transaction | Company-scoped unique insert; no source row locks |
| `ShiftCashBookingService` | Obligation by company/semantic fingerprint | Short claim, then separate financial transaction | Claim obligation and release; financial phase uses company GL advisory lock then sorted repositories |
| `RepositoryTransferDocumentService` | Document by company/source identity or booking obligation | Financial transaction | Deterministic document insert/savepoint → company GL advisory lock → sorted repositories → JE/legs/document links |
| `TreasuryTransferAlertService` | Alert by company/group/code | Same transfer transaction | Unique insert after flagged legs, before commit; no additional long lock |
| `CashCountDispatcher` | One obligation per company/count/consumer | Short transaction | Unique insert/CAS; release before queue call; outcome update in a new short transaction |
| `PosSessionReconciliationService` | Immutable run and optional supersession | Reconciliation transaction | Stable source reads; run insert; supersession insert; no Treasury row lock |
| `RepositoryCashCoverageService` | **No writes** | None | Pure snapshot calculator over immutable movements and stored ordinals |
| `RepositoryBalanceAlignmentService` | Alignment document, JE, movement | Q13-conditional financial transaction | Alignment unique insert → company GL advisory lock → repository lock → JE/movement/link |
| Device manifest sync controller | Server Z/manifest row by company/Z UUID | Request transaction | Company-scoped unique insert/update of sync acknowledgement only |

### 7.3 Global lock order

All W-CASH writers must obey:

1. Read immutable fiscal/POS source facts without `FOR UPDATE`.
2. Insert or claim the company-scoped obligation/configuration/document identity in a short transaction.
3. Release claim/configuration locks before financial work or queue I/O.
4. For financial work, acquire the company GL advisory lock.
5. Lock affected repositories in ascending UUID order with predicates on `tenant_id`, `company_id`, and `id`.
6. Create zero/one JE, exactly two transfer legs or the documented single external leg, cached balances, links, and alerts.
7. Commit the financial transaction.
8. Update obligation/projection outcome in a separate short transaction.
9. Never acquire terminal, shift, fiscal-event, or configuration locks after GL/repository locks.

Repository locks are held only for the financial transaction. Projection and obligation row locks are held only for claim/CAS operations and never across external queue calls.

---

## 8. Core contracts and algorithms

### 8.1 Fiscal admission and training

A v3 money candidate is admissible only when:

- `integrity_status=verified`
- `chain_context=z_session`
- `payload.training_flag` exists and is a PHP/JSON boolean
- `payload.training_flag === false`
- an event-time `FiscalProjectionPolicyRecord` says the projector was required
- the authored configuration revision exists
- the source identity falls within an enabled persisted cutover

`ZCashDrawerMovementPayload` must implement `fromArray()` using `FiscalPayloadArrayGuards::requireBool($data, 'training_flag')`. Missing, `null`, `0`, `1`, `"false"`, or `"true"` fail closed as `malformed_training_flag`; they never default to production and never move money. A valid `true` event is recorded as `training_no_money`.

### 8.2 Canonical v2/v3 semantic fingerprint

Raw payload hashes remain evidence and are never used as the cross-rail equality test.

```text
semantic_schema = "w-cash-semantic/v1"
semantic_fingerprint = SHA-256(canonical JSON of):
  company_id
  location_id
  terminal_id
  cash_drawer_operation_id
  normalized_operation_kind
  direction
  amount_minor_units
  currency_code_uppercase
  event_time_utc_seconds
  normalized_reason_code
  source_repository_role
  destination_repository_role
  authored_configuration_revision_id
```

Rules:

- Keys are UTF-8, lexicographically sorted, with no insignificant whitespace.
- Monetary text is validated at the explicit currency scale, then converted to integer minor units. PHP floats and JavaScript `number` arithmetic are forbidden.
- `cash_drawer_operation_id` is the common economic identity when present.
- Rail-only event IDs, fiscal sequence/hash, v2 row timestamp formatting, raw reason text, and envelope bytes are excluded from the semantic fingerprint and stored as evidence.
- Missing common semantic fields produce `blocked_missing_semantic_evidence`; they are not omitted from the fingerprint.
- Equivalent v2/v3 facts converge on one obligation and attach two evidence records.
- A reused operation ID with a different semantic fingerprint freezes the obligation as `conflict`; it does not choose the first or last arrival silently.

### 8.3 Event-time policy and recovery

```php
FiscalProjectionPolicyRecorder::record(
    FiscalEvent $event,
    string $projectorKey,
    ProjectionPolicyDecision $decision,
    ProjectionPolicyEvidenceData $evidence
): FiscalProjectionPolicyRecord;

FiscalProjectionRecoveryService::recover(
    FiscalProjectionRecoveryCriteria $criteria
): FiscalProjectionRecoveryResult;
```

Recovery rules:

- New events receive required/skipped/blocked policy records inside ingestion.
- Recovery first reads the immutable policy record.
- A legacy event without a record may be admitted only by a persisted `FiscalProjectionCutover` whose tenant/company/location/terminal/rail and inclusive source bounds contain it.
- The cutover records the policy/configuration revision and evidence used to define the cohort.
- Current module entitlement, current projector registry, current configuration head, or current feature flags may never be substituted for event-time evidence.
- Recovery criteria must include a cutover ID or explicit policy-record IDs; “all blocked” is invalid.
- Module/capability changes after event ingestion cannot add or remove that event’s obligations.
- `blocked → pending` is a compare-and-set tied to a matching resolution/configuration revision.

### 8.4 Configuration revision and cutover

```php
CashCustodyConfigurationService::appendRevision(
    CashCustodyConfiguration $configuration,
    CashCustodyConfigurationRevisionData $revision,
    string $actorId
): CashCustodyConfigurationRevision;

CashCustodyConfigurationService::activateRevision(
    string $companyId,
    string $configurationId,
    string $revisionId,
    CashCustodyActivationData $activation
): CashCustodyConfiguration;

FiscalProjectionCutoverService::create(
    FiscalProjectionCutoverData $data
): FiscalProjectionCutover;
```

A revision is immutable and contains drawer, safe, optional bank, currency, terminal membership, opaque operation-policy map, effective device/source bounds, and evidence. The head’s `current_revision_id` may advance; previously authored events keep their recorded revision ID. Provisioning creates only a disabled head/revision and no operation mappings.

### 8.5 Q11-conditional interval-start algorithm

This section is an implementation candidate, not an owner ruling. It must not be dispatched until Q11 is resolved in its favor or replaced by the approved alternative.

For a drawer custody interval:

```text
R = prior finalized retained drawer balance
D = newly declared physical opening balance
T = max(D - R, 0), but only when D >= R
```

Boundary source:

- First interval after cutover: `R` is the stored post-alignment repository balance/ordinal approved under Q13, or zero only if the cutover explicitly states a verified empty drawer.
- Later interval: `R` is the predecessor interval’s finalized closing balance at its stored repository ordinal.
- Never use `payment_repositories.balance` as the historical value.
- Predecessor ordering uses the persisted drawer interval chain and device close/open source sequence, not server arrival time.

Cases:

| Case | Result |
|---|---|
| `R=0, D=0` | No transfer; interval starts covered at zero. |
| `R=0, D>0` | Candidate safe → drawer top-up of `D`. |
| `0<R<D` | Candidate top-up of `D-R`; never book the full `D`. |
| `R=D` | No transfer; opening declaration confirms retained cash. |
| `R>D` | Block `opening_count_below_retained_balance`; do not infer a drop or add money. |
| Missing/unfinalized predecessor | `pending_predecessor`; no financial write. |
| Late predecessor before any financial post | Recompute and append a superseding interval result. |
| Late predecessor after financial post | Freeze as conflict; require an explicit corrective/reversal document, never mutate the applied transfer. |
| Shift A closes at 2,700; shift B declares 2,700 | B creates no new float. |
| Shift A closes at 2,700; shift B declares 2,900 | B top-up is 200. |
| Shift A closes at 2,700; shift B declares 200 | Block mismatch; never add 200. |
| Second terminal on the same drawer | Under the recommended Q11 branch, join the same drawer custody interval without a second float or refuse opening; exact join/refusal UX waits for the ruling. |

Expected cash remains the existing shift-level calculation. The interval service reconciles custody continuity; it does not add another opening component.

### 8.6 Close manifest

The device manifest contains device-authored identities only:

- `SESSION_OPEN`
- `OPENING_FLOAT`
- typed cash operations and their policy-evidence IDs
- sale receipt events
- v4 refund events
- legacy refund records consumed by the local Z
- local account payment/collection records
- `SESSION_CLOSE`
- `Z_REPORT`

It excludes server-authored repository movements, transfer documents, journal entries, projections, and alerts. Those appear in a separate server dependency snapshot.

Manifest fields:

- schema/version
- tenant/company/location/terminal/shift/session/Z identities
- each member’s stream, source ID, sequence where applicable, current hash where applicable, and raw evidence hash
- per-stream first/last sequence and member count
- device configuration revision
- created-at device timestamp
- canonical manifest hash

The device writes local Z, manifest, sync outbox, close event, and Z event within `zReportService.ts:541-576`’s existing SQLite write transaction. Server reconciliation remains pending until both fiscal membership and the sidecar exist, regardless of upload order.

### 8.7 Transfer and booking cardinality

```php
RepositoryTransferDocumentService::post(
    RepositoryTransferDocumentIntent $intent
): RepositoryTransferDocumentResult;

RepositoryTransferDocumentService::reverse(
    RepositoryTransferDocument $original,
    RepositoryTransferReversalIntent $intent
): RepositoryTransferDocumentResult;

ShiftCashBookingService::book(
    ShiftCashBookingIntent $intent
): ShiftCashBookingResult;
```

One internal custody action produces:

```text
1 repository_transfer_documents row
1 transfer_group_id
2 repository_movements rows
0 journal entries when both repositories share the same GL account
1 posted journal entry when their GL accounts differ
```

A reversal produces a new document, new group, two opposite legs, and explicit lineage. No original row is updated.

---

## 9. Schema plan

### 9.1 Additive tenant migrations

Exact planned files; their parent directory exists at HEAD:

1. `apps/api/database/migrations/tenant/2026_09_06_090000_add_recorded_policy_to_fiscal_event_projections.php`
2. `apps/api/database/migrations/tenant/2026_09_06_090100_create_treasury_cash_custody_configuration_tables.php`
3. `apps/api/database/migrations/tenant/2026_09_06_090200_create_repository_transfer_document_tables.php`
4. `apps/api/database/migrations/tenant/2026_09_06_090300_create_shift_cash_booking_tables.php`
5. `apps/api/database/migrations/tenant/2026_09_06_090400_create_z_close_manifest_tables.php`
6. `apps/api/database/migrations/tenant/2026_09_06_090500_create_cash_count_dispatch_and_reconciliation_tables.php`
7. `apps/api/database/migrations/tenant/2026_09_06_090600_create_repository_balance_alignments.php` — Q13-conditional and omitted from deployment until Q13 is resolved.

Core deltas:

- `fiscal_event_projections`: add `blocked_reason_code`, `blocked_detail`, `blocked_at`, `resolution_revision_id`, `recovery_attempt_count`, `last_recovery_at`.
- `fiscal_projection_policy_records`: immutable event/projector decision and evidence.
- `fiscal_projection_cutovers`: immutable bounded legacy cohort.
- `treasury_cash_custody_configurations`: disabled/ready/active head by company/location.
- `treasury_cash_custody_configuration_revisions`: immutable mapping/policy revisions.
- `treasury_drawer_custody_intervals`: opening/predecessor/retained/top-up and boundary state.
- `repository_transfer_documents`: immutable transfer justification, optional shift/session link, evidence, reversal lineage.
- `repository_movements`: nullable `transfer_document_id` and `transfer_leg_role`.
- `treasury_transfer_alerts`: durable frozen/checkpoint/conflict alerts.
- `shift_cash_booking_obligations` and `shift_cash_booking_evidence`.
- `pos_z_close_manifests`.
- `cash_count_consumer_obligations`.
- `pos_session_reconciliation_runs`.
- `pos_session_reconciliation_supersessions`.
- `repository_balance_alignments` only after Q13.

Device migrations, exclusively owned by T1:

- v68: `cash_custody_policy_cache`, authored revision/cutover evidence on offline cash rows, and typed-policy fields with no seeded economic defaults.
- v69: `z_close_manifests` and local manifest sync-outbox state.

### 9.2 Company-scoped uniqueness

Every business unique contains `company_id`:

- policy: `(company_id, fiscal_event_id, projector_key)`
- cutover: `(company_id, location_id, terminal_id, source_rail, lower_bound)`
- configuration: `(company_id, location_id)`
- revision: `(company_id, configuration_id, revision_number)`
- interval: `(company_id, drawer_repository_id, shift_id)`
- source evidence: `(company_id, source_rail, source_id)`
- booking obligation: `(company_id, semantic_fingerprint)`
- transfer source: `(company_id, source_kind, source_key)`
- transfer alert: `(company_id, transfer_group_id, alert_code)`
- manifest: `(company_id, z_report_uuid)`
- consumer obligation: `(company_id, cash_count_source_id, consumer_key)`
- reconciliation run: `(company_id, z_report_uuid, run_number)`
- supersession: `(company_id, prior_run_id)`
- alignment: `(company_id, repository_id, cutover_id)`

No new tenant-only business unique is permitted. PostgreSQL inspection by `TenantOnlyUniqueIndexScanner` must return no new entry.

### 9.3 JSONB DTO map

| Column | Exact DTO |
|---|---|
| `fiscal_event_projections.blocked_detail` | `apps/api/app/Modules/Fiscal/Application/DTOs/ProjectionBlockedDetailData.php` |
| `fiscal_projection_policy_records.policy_evidence` | `apps/api/app/Modules/Fiscal/Application/DTOs/ProjectionPolicyEvidenceData.php` |
| `fiscal_projection_cutovers.boundary_evidence` | `apps/api/app/Modules/Fiscal/Application/DTOs/ProjectionCutoverEvidenceData.php` |
| `treasury_cash_custody_configuration_revisions.operation_policy` | `apps/api/app/Modules/Treasury/Application/DTOs/CashOperationPolicyMapData.php` |
| `treasury_cash_custody_configuration_revisions.revision_evidence` | `apps/api/app/Modules/Treasury/Application/DTOs/CashCustodyRevisionEvidenceData.php` |
| `repository_transfer_documents.source_evidence` | `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferEvidenceData.php` |
| `treasury_transfer_alerts.detail` | `apps/api/app/Modules/Treasury/Application/DTOs/FrozenTransferAlertData.php` |
| `shift_cash_booking_obligations.blocked_detail` | `apps/api/app/Modules/Treasury/Application/DTOs/ShiftCashBookingBlockedDetailData.php` |
| `shift_cash_booking_evidence.raw_evidence` | `apps/api/app/Modules/Treasury/Application/DTOs/ShiftCashEvidenceData.php` |
| `pos_z_close_manifests.manifest` | `apps/api/app/Modules/POS/Application/DTOs/ZCloseManifestData.php` |
| `pos_session_reconciliation_runs.dependency_snapshot` | `apps/api/app/Modules/POS/Application/DTOs/SessionReconciliationDependencySnapshotData.php` |
| `pos_session_reconciliation_runs.mismatch_detail` | `apps/api/app/Modules/POS/Application/DTOs/SessionReconciliationMismatchData.php` |
| `repository_balance_alignments.count_evidence` | `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryBalanceAlignmentEvidenceData.php` |

Every DTO requires strict `fromArray()`, deterministic `toArray()`, invalid-shape rejection, and database round-trip tests.

---

## 10. State machines

### Fiscal projection

```text
pending → running → applied
pending → running → pending                 transient failure
pending → running → blocked                 missing policy/config/dependency
blocked → pending                           CAS with matching resolution revision
pending/running → dead_lettered             exhausted/permanent technical failure
```

`blocked` does not consume ordinary retry attempts and is never swept globally.

### Cash booking obligation

```text
pending → claimed → applied
pending → claimed → blocked
pending → claimed → conflict
claimed → pending                           process crash/expired lease
blocked → pending                           explicit matching resolution
applied/conflict                            terminal; correction is a new obligation
```

### Reconciliation

```text
pending_membership
pending_dependencies
matched
mismatched
unavailable_before_coverage
```

Rows are immutable. A later result appends a run and, if replacing an earlier current run, appends a supersession record. Current result means “run not referenced as `prior_run_id` by any valid supersession for the same company/Z.”

---

## 11. Dispatchable implementation tasks

## T0 — Pre-migration static and live W-CASH census

**Production files**

- New: `apps/api/app/Modules/Treasury/Infrastructure/Commands/AuditCashCustodyReadinessCommand.php`
- Existing: `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:194,237`
- Existing: `apps/api/app/Support/Tenancy/TenantOnlyUniqueIndexScanner.php:54`
- Existing: `scripts/preflight.sh`
- Evidence: `docs/superpowers/audits/2026-09-05-w-cash-census.md`

**Contract**

```php
AuditCashCustodyReadinessCommand:
treasury:w-cash-audit
    {--company=*}
    {--format=text}
    {--include-optional}
    {--fail-on-drift}
```

Output must enumerate companies, POS locations, terminals/schema versions, open shifts, repositories/types/currencies/GL links, active modules, current fiscal projection backlog, v2/v3 cash sources, and current tenant-only unique ratchet. Optional future-table probes use `Schema::hasTable()`.

**Schema delta:** none.

**Red-first tests**

| Test | First failing assertion | Command/lane |
|---|---|---|
| `apps/api/tests/Feature/Treasury/WCashAuditCommandTest.php::test_it_succeeds_before_w_cash_tables_exist` | `$this->artisan('treasury:w-cash-audit')->assertExitCode(0)` | `cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/WCashAuditCommandTest.php --filter test_it_succeeds_before_w_cash_tables_exist` — SQLite |
| `...::test_second_company_rows_are_reported_without_first_company_repositories` | `assertNotContains($companyARepositoryId, $companyBRow['repository_ids'])` | `./scripts/run-feature-lane-local.sh feature-lane-tenancy --group Company` — PostgreSQL |
| `...::test_second_location_keeps_its_terminal_and_drawer_binding` | `assertSame($locationBId, $terminalBRow['location_id'])` | same PostgreSQL lane |
| `...::test_rerun_preserves_identical_data_meaning` | `assertSame($firstJson, $secondJson)` after removing observation timestamp | same PostgreSQL lane |

**Implementation**

1. Add capability-aware queries only; no new table is required.
2. Add exact command registration.
3. Add static `rg` census output for direct movement writers and X/Z payload keys to the audit artifact.
4. Run locally, then on staging before Push 2:
   `cd apps/api && php artisan treasury:w-cash-audit --format=json --include-optional --fail-on-drift`.
5. Stop if company/location/repository ambiguity, unknown terminal schema, missing GL link, or unexpected writer is found.

**Reviewer gate:** audit artifact contains local and staging output, actual candidate SHA, reviewer name/date, and an explicit `DISPATCHABLE` verdict.

---

## T1 — Additive schema and device migration order

**Production files**

- The seven exact tenant migration paths in §9.1
- Existing: `apps/pos/src/lib/db/migrations.ts:2163`
- Existing: `apps/api/database/migrations/tenant/2026_07_08_100200_create_repository_movements_immutability.php`
- Existing: `apps/api/database/migrations/tenant/2026_07_08_160000_forbid_direct_payment_repository_balance_writes.php`

**Contract/schema**

Apply §9 exactly. v68 precedes v69 in the same `migrations` array. T1 is the sole owner of `migrations.ts`; later tasks consume the tables without editing migration order. All production defaults are disabled/null and all migrations are additive. Q13’s migration is withheld until Q13 is resolved.

**Red-first tests**

| Test | First failing assertion | Command/lane |
|---|---|---|
| `apps/api/tests/Feature/Treasury/WCashSchemaBoundaryTest.php::test_every_w_cash_business_unique_is_company_scoped` | `assertSame([], $scanner->scan())` | `./scripts/run-feature-lane-local.sh feature-lane-tenancy --group Company` — PostgreSQL |
| `...::test_second_company_can_reuse_source_identity` | `assertDatabaseCount('shift_cash_booking_obligations', 2)` | same PostgreSQL lane |
| `...::test_second_location_can_hold_an_independent_disabled_configuration` | `assertDatabaseCount('treasury_cash_custody_configurations', 2)` | same PostgreSQL lane |
| `...::test_schema_rerun_preserves_existing_rows_and_meaning` | `assertSame($before, $after)` | same PostgreSQL lane |
| `apps/pos/src/lib/db/__tests__/wCashMigrations.test.ts::applies_v68_before_v69_and_reruns_idempotently` | `expect(appliedVersions.slice(-2)).toEqual([68, 69])` | `pnpm --filter @autoerp/pos test -- src/lib/db/__tests__/wCashMigrations.test.ts -t "applies v68 before v69 and reruns idempotently"` — Vitest/SQLite |

**Implementation**

1. Add server tables/columns/checks/indexes without backfill or activation.
2. Add v68, then v69, in one edit.
3. Test migration up on empty and populated PostgreSQL tenant databases.
4. Test backup/restore of a migrated tenant.
5. Do not add destructive `down()` behavior; rollback retains compatible tables/readers.

**Reviewer gate:** schema diff confirms no default-enabled state, no tenant-only unique, v68 before v69, and no other task owns `migrations.ts`.

---

## T2 — Immutable event-time projection policy and bounded recovery

**Production files**

- Existing: `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:980-1046`
- Existing: `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:79,110`
- Existing: `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:226-319`
- Existing: `apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionStatus.php`
- Existing: `apps/api/app/Modules/Fiscal/Infrastructure/Commands/RetryFiscalProjectionsCommand.php`
- Existing: `apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php`
- New: `apps/api/app/Modules/Fiscal/Domain/Models/FiscalProjectionPolicyRecord.php`
- New: `apps/api/app/Modules/Fiscal/Domain/Models/FiscalProjectionCutover.php`
- New: `apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionPolicyDecision.php`
- New: `apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionBlockedReason.php`
- New: the three Fiscal DTO paths in §9.3
- New: `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalProjectionRecoveryCriteria.php`
- New: `apps/api/app/Modules/Fiscal/Application/DTOs/FiscalProjectionRecoveryResult.php`
- New: `apps/api/app/Modules/Fiscal/Application/Services/FiscalProjectionPolicyRecorder.php`
- New: `apps/api/app/Modules/Fiscal/Application/Services/FiscalProjectionCutoverService.php`
- New: `apps/api/app/Modules/Fiscal/Application/Services/FiscalProjectionRecoveryService.php`
- New: `apps/api/app/Modules/Fiscal/Infrastructure/Commands/CreateFiscalProjectionCutoverCommand.php`

**Signatures/schema**

Use §8.3. Command:

```text
fiscal:projection-cutover:create
  {--company=}
  {--location=}
  {--terminal=}
  {--rail=}
  {--lower-bound=}
  {--upper-bound=}
  {--policy-revision=}
  {--evidence-file=}
```

No current registry lookup is allowed during recovery.

**Red-first tests**

| Test | First failing assertion | Command/lane |
|---|---|---|
| `apps/api/tests/Feature/Fiscal/FiscalProjectionPolicyRecoveryTest.php::test_later_module_activation_does_not_create_an_old_event_obligation` | `assertDatabaseMissing('fiscal_event_projections', ['fiscal_event_id' => $oldId, 'projector_key' => $laterProjector])` | `./scripts/run-feature-lane-local.sh feature-lane-fiscal-finance --group Fiscal` — PostgreSQL |
| `...::test_later_module_deactivation_does_not_erase_recorded_obligation` | `assertDatabaseHas('fiscal_event_projections', ['fiscal_event_id' => $id])` | same lane |
| `...::test_recovery_refuses_unbounded_legacy_cohort` | `$this->expectException(UnboundedProjectionRecoveryException::class)` | same lane |
| `...::test_second_company_cutover_cannot_admit_first_company_event` | `assertSame(0, $result->admittedCount)` | same lane |
| `...::test_second_location_requires_matching_cutover_scope` | `assertSame(ProjectionBlockedReason::CutoverScopeMismatch, $result->reason)` | same lane |
| `...::test_rerun_preserves_policy_decision_and_data_meaning` | `assertDatabaseCount('fiscal_projection_policy_records', 1)` | same lane |

**Implementation**

1. Record policy decisions in the event-ingestion transaction.
2. Make dispatcher seed from those records.
3. Add bounded legacy cutovers.
4. Add blocked state/CAS recovery metadata.
5. Change retry commands to require recorded policy/cutover evidence.
6. Leave W-CASH projector unregistered until T5 and globally disabled afterward.

**Reviewer gate:** module/capability-change tests are green and code search finds no recovery path deriving historical obligations from the active registry.

---

## T3 — Versioned cash-custody configuration and provisioning

**Production files**

- Existing: `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:49-60,83,194-196`
- Existing: `apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php:24-31,181-224,240-251,294`
- Existing: `apps/api/app/Shared/Contracts/Treasury/CompanyPaymentRepositoryProvisionerInterface.php:49`
- Existing: `apps/api/app/Modules/Treasury/Application/Services/PaymentRepositoryProvisioningService.php:32-70`
- Existing: `apps/api/app/Shared/Contracts/Treasury/LocationCashRegisterProvisionerInterface.php`
- Existing: `apps/api/app/Modules/Treasury/Application/Services/LocationCashRegisterProvisioner.php:94`
- Existing: `apps/api/app/Modules/Treasury/Presentation/routes.php:35`
- New: `apps/api/app/Modules/Treasury/Domain/Models/CashCustodyConfiguration.php`
- New: `apps/api/app/Modules/Treasury/Domain/Models/CashCustodyConfigurationRevision.php`
- New: `apps/api/app/Modules/Treasury/Domain/Enums/CashCustodyCapabilityState.php`
- New: `apps/api/app/Modules/Treasury/Application/Services/CashCustodyConfigurationService.php`
- New: `apps/api/app/Modules/Treasury/Application/DTOs/CashCustodyConfigurationData.php`
- New: `apps/api/app/Modules/Treasury/Application/DTOs/CashCustodyConfigurationRevisionData.php`
- New: `apps/api/app/Modules/Treasury/Application/DTOs/CashCustodyActivationData.php`
- New: operation-policy and revision-evidence DTOs from §9.3
- New: `apps/api/app/Modules/Treasury/Presentation/Controllers/CashCustodyConfigurationController.php`
- New: `apps/api/app/Modules/Treasury/Presentation/Requests/UpdateCashCustodyConfigurationRequest.php`
- New: `apps/api/app/Modules/Treasury/Infrastructure/Commands/ConfigureCashCustodyCommand.php`
- Existing: `docs/glossary.md`
- New generated DTO source: `apps/api/app/Modules/Treasury/Application/DTOs/PaymentRepositoryData.php`
- Generated output: `packages/shared/types/generated.d.ts`

**Signatures/schema**

```php
ensureDisabledForCompany(
    string $tenantId,
    string $companyId,
    ?string $defaultLocationId
): CashCustodyConfiguration;

ensureDisabledForLocation(
    string $tenantId,
    string $companyId,
    string $locationId
): CashCustodyConfiguration;

appendRevision(...): CashCustodyConfigurationRevision;
activateRevision(...): CashCustodyConfiguration;
```

`CompanyController`’s existing outer transaction owns company + repository + disabled-config provisioning. `LocationController` wraps POS-enabled location creation/update and `LocationCashRegisterProvisioner::provision()` in one outer transaction; the Treasury provisioner’s nested transaction is a savepoint. Retries return the same disabled configuration.

Command:

```text
treasury:w-cash-configure
  {--company=}
  {--location=}
  {--drawer=}
  {--safe=}
  {--bank=}
  {--policy-file=}
  {--state=disabled}
```

No default operation policy is seeded while Q11/Q12 are open.

**Red-first tests**

| Test | First failing assertion | Command/lane |
|---|---|---|
| `apps/api/tests/Feature/Company/WCashProvisioningTest.php::test_registration_provisions_disabled_cash_custody_configuration` | `assertDatabaseHas('treasury_cash_custody_configurations', ['capability_state' => 'disabled'])` | `./scripts/run-feature-lane-local.sh feature-lane-tenancy --group Company` — PostgreSQL |
| `...::test_second_company_gets_only_its_own_configuration` | `assertNotSame($companyAConfig->id, $companyBConfig->id)` | same lane |
| `...::test_second_pos_location_gets_its_own_drawer_configuration` | `assertSame($locationB->id, $configB->location_id)` | same lane |
| `...::test_provisioning_rerun_preserves_repository_and_configuration_meaning` | `assertDatabaseCount('treasury_cash_custody_configurations', 1)` | same lane |
| `apps/api/tests/Feature/Treasury/CashCustodyConfigurationJsonTest.php::test_revision_jsonb_round_trips_strict_dtos` | `assertEquals($dto, CashCustodyConfigurationRevisionData::fromModel($row))` | PostgreSQL |

**Implementation**

1. Add immutable revision writer and disabled head.
2. Hook it into both existing Treasury provisioners.
3. Make location provisioning atomic with the location state transition.
4. Add read/update endpoints behind `module:Treasury` and `can:treasury.manage`.
5. Generate DTOs and add glossary rows.
6. Reject cross-company, wrong-location, wrong-type, inactive, unlinked-GL, and currency-mismatch repositories.

**Reviewer gate:** a real registration, second company, and second POS location each produce one correctly scoped disabled configuration without selecting Q11/Q12 defaults.

---

## T4 — Transfer document, manual linkage, module gate, and durable frozen alert

**Production files**

- Existing: `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:49,225`
- Existing: `apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php`
- Existing: `apps/api/app/Modules/Treasury/Application/DTOs/TransferIntent.php`
- Existing: `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:33-123`
- Existing: `apps/api/app/Modules/Treasury/Presentation/Requests/TransferRepositoryRequest.php:19-27`
- Existing: `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php:21-56`
- Existing: `apps/api/app/Modules/Treasury/Presentation/routes.php:35,102-104`
- New: `apps/api/app/Modules/Treasury/Domain/Models/RepositoryTransferDocument.php`
- New: `apps/api/app/Modules/Treasury/Domain/Models/TreasuryTransferAlert.php`
- New: `apps/api/app/Modules/Treasury/Domain/Enums/RepositoryTransferKind.php`
- New: `apps/api/app/Modules/Treasury/Domain/Enums/RepositoryTransferStatus.php`
- New: `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferDocumentIntent.php`
- New: `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferDocumentResult.php`
- New: `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferReversalIntent.php`
- New: transfer-evidence and frozen-alert DTOs from §9.3
- New: `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferDocumentService.php`
- New: `apps/api/app/Modules/Treasury/Application/Services/TreasuryTransferAlertService.php`
- Existing: `docs/glossary.md`

**Signatures/schema**

Use §8.7. Extend `TransferIntent` with:

```php
public bool $allowWhileFrozen = false;
public bool $allowBehindCheckpoint = false;
public bool $allowNegative = false;
```

Extend the request/document with nullable `shift_id` and `session_id`. Linkage is append-only, requires `treasury.transfer`, and must resolve to the same company/location as the drawer repository. Existing manual calls pass all replay exceptions as false.

**Red-first tests**

| Test | First failing assertion | Command/lane |
|---|---|---|
| `apps/api/tests/Feature/Treasury/RepositoryTransferDocumentTest.php::test_transfer_creates_one_document_two_legs_and_zero_or_one_je` | `assertDatabaseCount('repository_transfer_documents', 1)` | `./scripts/run-feature-lane-local.sh treasury-spine-pgsql --group Treasury` — PostgreSQL |
| `...::test_frozen_offline_transfer_persists_one_durable_alert_on_retry` | `assertDatabaseCount('treasury_transfer_alerts', 1)` | same lane |
| `...::test_manual_transfer_can_link_a_same_location_shift_and_session` | `assertSame($shiftId, $document->shift_id)` | same lane |
| `...::test_second_company_cannot_reference_first_company_repository_or_shift` | `assertSame(422, $response->status())` | same lane |
| `...::test_second_location_linkage_is_rejected_for_the_wrong_drawer` | `assertSame('TRANSFER_LOCATION_MISMATCH', $response->json('error.code'))` | same lane |
| `...::test_exact_rerun_returns_original_document_without_new_legs` | `assertDatabaseCount('repository_movements', 2)` | same lane |
| `apps/api/tests/Feature/Treasury/TreasuryModuleGateTest.php::test_module_off_refuses_existing_transfer_and_configuration_routes` | `assertSame(403, $response->status())` | PostgreSQL |

**Implementation**

1. Run T0 route compatibility census.
2. Add `module:Treasury` specifically to the reused transfer and new configuration/alignment routes.
3. Wrap manual transfer through `RepositoryTransferDocumentService`.
4. Preserve exactly two legs and zero/one JE.
5. Add optional shift/session validation.
6. Add durable alert within the financial transaction for flagged frozen/checkpoint writes.
7. Make all business uniques company-scoped.
8. Add reversal lineage and no direct movement writes.

**Reviewer gate:** cardinality, module-off behavior, same-company/location linkage, frozen-alert durability, rollback atomicity, and uniqueness ratchet are green on PostgreSQL.

---

## T5 — Shared server booking, canonical fingerprint, and v2/v3 adapters

**Production files**

- Existing: `apps/api/app/Modules/Fiscal/Domain/DTOs/ZCashDrawerMovementPayload.php:7-33`
- Existing: `apps/api/app/Modules/Fiscal/Domain/DTOs/FiscalPayloadArrayGuards.php:34`
- Existing: `apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php`
- Existing: `apps/api/app/Modules/POS/Domain/Events/CashDrawerOperationRecorded.php`
- Existing: `apps/api/app/Modules/POS/Domain/Services/ShiftManagementService.php`
- Existing: `apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:250-304`
- Existing: `apps/api/config/treasury.php:5-29`
- New: `apps/api/app/Modules/Treasury/Application/DTOs/ShiftCashBookingIntent.php`
- New: `apps/api/app/Modules/Treasury/Application/DTOs/ShiftCashBookingResult.php`
- New: `apps/api/app/Modules/Treasury/Application/DTOs/ShiftCashEvidenceData.php`
- New: `apps/api/app/Modules/Treasury/Application/DTOs/ShiftCashBookingBlockedDetailData.php`
- New: `apps/api/app/Modules/Treasury/Domain/Enums/ShiftCashSourceRail.php`
- New: `apps/api/app/Modules/Treasury/Domain/Enums/ShiftCashOperationKind.php`
- New: `apps/api/app/Modules/Treasury/Domain/Models/ShiftCashBookingObligation.php`
- New: `apps/api/app/Modules/Treasury/Domain/Models/ShiftCashBookingEvidence.php`
- New: `apps/api/app/Modules/Treasury/Domain/Models/TreasuryDrawerCustodyInterval.php`
- New: `apps/api/app/Modules/Treasury/Application/Services/ShiftCashSemanticFingerprint.php`
- New: `apps/api/app/Modules/Treasury/Application/Services/DrawerIntervalStartService.php`
- New: `apps/api/app/Modules/Treasury/Application/Services/ShiftCashBookingService.php`
- New: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryShiftCashFiscalProjection.php`
- New: `apps/api/app/Modules/POS/Domain/Events/CashDrawerOperationAuthoredV2.php`
- New: `apps/api/app/Modules/Treasury/Application/Listeners/QueueV2ShiftCashBooking.php`
- New: `apps/api/app/Modules/Treasury/Infrastructure/Commands/CatchUpV2ShiftCashBookingsCommand.php`
- Existing: `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`

**Signatures/schema**

```php
ShiftCashSemanticFingerprint::fromIntent(
    ShiftCashBookingIntent $intent
): string;

DrawerIntervalStartService::resolve(
    DrawerIntervalStartIntent $intent
): DrawerIntervalStartResult;

ShiftCashBookingService::book(
    ShiftCashBookingIntent $intent
): ShiftCashBookingResult;
```

Config flags added to `config/treasury.php`:

```php
'w_cash_booking_enabled' =>
    (bool) env('TREASURY_W_CASH_BOOKING_ENABLED', false),
'cash_count_dispatch_enabled' =>
    (bool) env('TREASURY_CASH_COUNT_DISPATCH_ENABLED', false),
```

The v2 catch-up command requires `--cutover=` and supports `--dry-run`; no unbounded scan.

**Red-first tests**

| Test | First failing assertion | Command/lane |
|---|---|---|
| `apps/api/tests/Feature/Treasury/ShiftCashBookingTest.php::test_equivalent_v2_and_v3_facts_share_one_semantic_obligation` | `assertDatabaseCount('shift_cash_booking_obligations', 1)` | `./scripts/run-feature-lane-local.sh treasury-spine-pgsql --group Treasury` — PostgreSQL |
| `...::test_same_operation_id_with_different_amount_freezes_as_conflict` | `assertSame('conflict', $obligation->status->value)` | same lane |
| `...::test_missing_or_string_training_flag_is_blocked_without_money` | `assertDatabaseCount('repository_movements', 0)` | same lane |
| `...::test_training_event_is_applied_without_money` | `assertSame('training_no_money', $result->code)` | same lane |
| `...::test_later_configuration_revision_does_not_reinterpret_authored_event` | `assertSame($oldRevisionId, $evidence->configuration_revision_id)` | same lane |
| `...::test_second_company_can_reuse_operation_id_without_deduplication` | `assertDatabaseCount('shift_cash_booking_obligations', 2)` | same lane |
| `...::test_second_location_does_not_resolve_first_location_configuration` | `assertSame('configuration_scope_mismatch', $result->code)` | same lane |
| `...::test_exact_rerun_preserves_document_and_movement_meaning` | `assertDatabaseCount('repository_movements', 2)` | same lane |
| `apps/api/tests/Feature/Treasury/DrawerIntervalStartTest.php::test_sequential_shift_uses_retained_balance_not_full_opening` | `assertSame('200.000', $result->topUpAmount)` | same lane; **Q11-conditional** |
| `...::test_declared_opening_below_retained_balance_blocks` | `assertSame('opening_count_below_retained_balance', $result->code)` | same lane; **Q11-conditional** |
| `...::test_second_terminal_cannot_create_a_second_float_for_shared_drawer` | `assertDatabaseCount('repository_transfer_documents', 1)` | same lane; **Q11-conditional** |

**Implementation**

1. Add strict training parsing.
2. Add semantic fingerprint/evidence split.
3. Append the new v2 event; do not mutate the existing event contract.
4. Add bounded catch-up and dormant v3 projector.
5. Persist obligations before financial work.
6. Implement generic blocked outcomes immediately.
7. Implement/enable interval-start financial behavior only after Q11.
8. Implement/enable cash-operation classification only after Q12.
9. Preserve receipt sales/refund booking as authoritative; W-CASH does not rebook them.
10. Leave both global flags false.

**Reviewer gate:** no money is written while Q11/Q12 are OPEN; equivalence, conflict, training, scope, recovery, and conditional sequential-shift tests are accepted.

---

## T6 — Device policy evidence and typed cash authoring

**Production files**

- Existing: `apps/pos/src/api/cashDrawerApi.ts:1-223`
- Existing: `apps/pos/src/components/pos/CashDrawerModal.tsx:1-180`
- Existing: `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:37-92,507-566,664-722`
- Existing: `apps/pos/src/lib/db/repositories/cashDrawerRepository.ts`
- Existing: `apps/pos/src/lib/sync/syncService.ts:591,2294`
- Existing: `apps/pos/src/lib/operatorApproval/cashDrawerApproval.ts:12-44`
- Existing: `apps/api/app/Modules/POS/Presentation/Controllers/CashDrawerController.php:28-245`
- Existing: `apps/api/app/Modules/POS/Presentation/Requests/RecordDepositRequest.php`
- Existing: `apps/api/app/Modules/POS/Presentation/Requests/RecordPayoutRequest.php`
- Existing: `apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php`
- New: `apps/pos/src/lib/cashCustody/cashCustodyPolicy.ts`
- New: `apps/pos/src/lib/cashCustody/cashOperationAuthoring.ts`
- New: `apps/pos/src/lib/db/repositories/cashCustodyPolicyRepository.ts`
- New: `apps/pos/src/api/cashCustodyConfigurationApi.ts`

**Contract/schema**

```ts
type CashOperationPolicy = {
  configurationRevisionId: string;
  cutoverId: string;
  sourceLowerBound: string;
  sourceUpperBound: string | null;
  operationCode: string;
  economicKind: string;
  sourceRepositoryRole: string | null;
  destinationRepositoryRole: string | null;
};

authorCashOperation(input: CashOperationAuthoringInput): Promise<void>;
```

The device may display codes returned by an active signed configuration revision, but the plan does not seed or select the Q12 recommendation. No free-text inference. Opening authoring records revision/cutover evidence; its financial result remains Q11-conditional on the server.

**Red-first tests**

| Test | First failing assertion | Command/lane |
|---|---|---|
| `apps/pos/src/api/__tests__/cashDrawerApi.wCash.test.ts::blocks_cash_authoring_without_active_policy_revision` | `await expect(author).rejects.toThrow('cash custody policy unavailable')` | `pnpm --filter @autoerp/pos test -- src/api/__tests__/cashDrawerApi.wCash.test.ts -t "blocks cash authoring without active policy revision"` — Vitest/SQLite |
| `...::does_not_dispatch_safe_drop_while_q12_policy_is_absent` | `expect(authorZCashDrawerMovement).not.toHaveBeenCalled()` | same lane |
| `...::persists_exact_policy_revision_with_authored_fact` | `expect(row.configuration_revision_id).toBe(revisionId)` | same lane |
| `...::second_company_reads_only_its_company_database_policy` | `expect(policy.companyId).toBe(companyB)` | same lane |
| `...::second_location_rejects_a_policy_for_another_terminal_location` | `expect(result.code).toBe('policy_scope_mismatch')` | same lane |
| `...::policy_refresh_rerun_preserves_authored_fact_meaning` | `expect(after.rawEvidenceHash).toBe(before.rawEvidenceHash)` | same lane |
| `apps/pos/src/lib/fiscal/__tests__/zSessionAuthoring.test.ts::does_not_author_a_second_opening_for_a_joined_drawer_interval` | `expect(events.filter(isOpeningFloat)).toHaveLength(1)` | Vitest/SQLite; **Q11-conditional** |

**Implementation**

1. Sync disabled/active configuration revisions to the device.
2. Cache them in v68.
3. Require revision/cutover evidence at authoring.
4. Replace free-text-only selection with server-provided typed policy choices after Q12.
5. Keep reason text as evidence, not classification.
6. Preserve approval evidence.
7. Do not expose or dispatch SAFE_DROP until Q12.
8. Apply Q11-approved join/refusal behavior only after Q11.
9. Keep v2 endpoint compatibility until cutover verification is complete.

**Reviewer gate:** offline behavior is fail-closed, policy evidence survives sync/retry, and no owner-open operation can dispatch money.

---

## T7 — Atomic device close manifest and server ingestion

**Production files**

- Existing: `apps/pos/src/lib/offline/zReportService.ts:134,198-225,541-576,748-753`
- Existing: `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:664-722`
- Existing: `apps/pos/src/lib/sync/syncService.ts:541-562,633`
- New: `apps/pos/src/lib/offline/zCloseManifest.ts`
- New: `apps/pos/src/lib/db/repositories/zCloseManifestRepository.ts`
- Existing: `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:508-555`
- Existing: `apps/api/app/Modules/POS/Presentation/routes.php:130-135`
- New: `apps/api/app/Modules/POS/Domain/Models/ZCloseManifest.php`
- New: `apps/api/app/Modules/POS/Application/DTOs/ZCloseManifestData.php`
- New: `apps/api/app/Modules/POS/Application/Services/ZCloseManifestIngestor.php`

**Contract/schema**

```ts
buildZCloseManifest(input: ZCloseManifestInput): ZCloseManifest;
```

```php
ZCloseManifestIngestor::ingest(
    string $companyId,
    ZCloseManifestData $manifest
): ZCloseManifest;
```

Membership follows §8.6. Z, manifest, and local outbox are atomic. Server sync stores the Z/manifest acknowledgement in one request transaction. Fiscal upload and Z sync may arrive in either order.

**Red-first tests**

| Test | First failing assertion | Command/lane |
|---|---|---|
| `apps/pos/src/lib/offline/__tests__/zCloseManifest.test.ts::writes_z_manifest_and_outbox_atomically` | `expect(await countManifests(db)).toBe(1)` | `pnpm --filter @autoerp/pos test -- src/lib/offline/__tests__/zCloseManifest.test.ts -t "writes z manifest and outbox atomically"` — Vitest/SQLite |
| `...::rollback_after_manifest_failure_leaves_no_z_or_manifest` | `expect(await countZReports(db)).toBe(0)` | same lane |
| `...::manifest_contains_device_sources_and_excludes_server_movements` | `expect(memberStreams).not.toContain('repository_movements')` | same lane |
| `...::second_company_manifest_never_reads_first_company_database` | `expect(manifest.companyId).toBe(companyB)` | same lane |
| `...::second_location_manifest_contains_only_selected_terminal_sources` | `expect(new Set(memberLocationIds)).toEqual(new Set([locationB]))` | same lane |
| `...::manifest_rerun_has_identical_hash_and_membership` | `expect(second.manifestHash).toBe(first.manifestHash)` | same lane |
| `apps/api/tests/Feature/POS/ZCloseManifestSyncTest.php::test_reordered_manifest_and_fiscal_upload_becomes_complete_only_after_both` | `assertSame('pending_membership', $firstResult->status->value)` | `./scripts/run-feature-lane-local.sh feature-lane-pos --group POS` — PostgreSQL |

**Implementation**

1. Build manifest exclusively from device-authored data used by Z.
2. Insert it in the existing SQLite fiscal transaction.
3. Send it with Z sync and verify its hash server-side.
4. Keep reconciliation pending until referenced members exist.
5. Reject duplicate Z with different manifest hash as a conflict.
6. Do not alter sealed fiscal payload versions.

**Reviewer gate:** crash, replay, out-of-order upload, company/location isolation, and deterministic hashing are green.

---

## T8 — Durable `CashCountDispatcher` and v3 producer

**Production files**

- Existing: `apps/api/app/Modules/POS/Application/Services/CashCountDispatcher.php:89-112`
- Existing: `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:433`
- Existing: `apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:54`
- Existing: `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:508,555`
- Existing: `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php:294`
- Existing: `apps/api/app/Modules/Compliance/Application/Listeners/OpenFraudAlertForShiftVariance.php:14,22`
- Existing: `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:221-237`
- Existing: `apps/api/app/Modules/Compliance/Providers/ComplianceServiceProvider.php:82-85`
- New: `apps/api/app/Shared/Contracts/POS/CashCountConsumerInterface.php`
- New: `apps/api/app/Modules/POS/Application/DTOs/CashCountConsumerOutcome.php`
- New: `apps/api/app/Modules/POS/Application/DTOs/CashCountDispatchResult.php`
- New: `apps/api/app/Modules/POS/Domain/Models/CashCountConsumerObligation.php`
- New: `apps/api/app/Modules/POS/Application/Services/CashCountObligationService.php`
- New: `apps/api/app/Modules/POS/Application/Jobs/DispatchCashCountConsumerJob.php`
- New: `apps/api/app/Modules/POS/Application/Jobs/DispatchProjectedCashCountJob.php`
- New: `apps/api/app/Modules/POS/Application/Services/CashCountRecordedFactory.php`
- New: `apps/api/app/Modules/POS/Infrastructure/Commands/RecoverCashCountDispatchCommand.php`

**Signatures/schema**

```php
interface CashCountConsumerInterface
{
    public function consumerKey(): string;
    public function consume(CashCountRecorded $event): CashCountConsumerOutcome;
}

CashCountDispatcher::dispatch(
    CashCountRecorded $event
): CashCountDispatchResult;
```

Consumers are tagged and iterated independently. The producer obligation is created with the Z projection. A queue-enqueue failure records `enqueue_failed` and throws `CashCountDispatchIncomplete`; it cannot be logged and forgotten.

**Red-first tests**

| Test | First failing assertion | Command/lane |
|---|---|---|
| `apps/api/tests/Feature/POS/CashCountDispatcherDurabilityTest.php::test_first_consumer_failure_does_not_erase_second_consumer_obligation` | `assertDatabaseCount('cash_count_consumer_obligations', 2)` | `./scripts/run-feature-lane-local.sh feature-lane-pos --group POS` — PostgreSQL |
| `...::test_queue_failure_returns_typed_failure_and_remains_recoverable` | `assertSame('enqueue_failed', $result->outcomeFor('treasury.shift_variance')->code)` | same lane |
| `...::test_retry_after_partial_success_does_not_repeat_completed_consumer` | `assertSame(1, $complianceConsumer->calls)` | same lane |
| `...::test_v3_z_projection_creates_obligation_transactionally` | `assertDatabaseHas('cash_count_consumer_obligations', ['cash_count_source_id' => $zId])` | same lane |
| `...::test_second_company_has_independent_consumer_obligations` | `assertDatabaseCount('cash_count_consumer_obligations', 4)` | same lane |
| `...::test_second_location_count_keeps_its_shift_scope` | `assertSame($locationB, $captured->locationId)` | same lane |
| `...::test_dispatch_rerun_preserves_consumer_outcome_meaning` | `assertDatabaseCount('cash_count_consumer_obligations', 2)` | same lane |

**Implementation**

1. Replace broad event dispatch with tagged consumers behind the shared contract.
2. Persist obligations before enqueuing.
3. Register the two existing consumers with stable keys.
4. Create v3 producer obligation transactionally with Z.
5. Route legacy/server Z producers through the same obligation service.
6. Use only the existing `default` queue.
7. Keep `TREASURY_CASH_COUNT_DISPATCH_ENABLED=false`.
8. Exclude training counts.

**Reviewer gate:** crash/retry and partial-consumer failures cannot lose either obligation, and v2/server/v3 producers deduplicate on company/count identity.

---

## T9 — W7 session reconciliation and repository coverage

**Prerequisite stop**

Do not dispatch T9 until all are evidenced:

- W2 table/classification work is approved and its tender/financial-table classification is available.
- W4 projection completeness and T2 event-time policy recovery are green.
- T7 manifest is green.
- T8 durable cash-count production is green.
- W-LOT exposes an explicit lot-estimate/status contract.
- Q10 is resolved before selecting launch-accepted lot states.
- Q11/Q12 branches remain blocked unless separately resolved.

**Production files**

- Existing: `apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:167-419`
- Existing: `apps/api/app/Modules/POS/Domain/Exceptions/UnknownTenderClassificationException.php:39`
- Existing: `apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:54`
- New: `apps/api/app/Modules/POS/Domain/Models/PosSessionReconciliationRun.php`
- New: `apps/api/app/Modules/POS/Domain/Models/PosSessionReconciliationSupersession.php`
- New: `apps/api/app/Modules/POS/Domain/Enums/SessionReconciliationStatus.php`
- New: `apps/api/app/Modules/POS/Application/DTOs/PosSessionReconciliationIntent.php`
- New: `apps/api/app/Modules/POS/Application/DTOs/PosSessionReconciliationResult.php`
- New: dependency/mismatch DTOs from §9.3
- New: `apps/api/app/Modules/POS/Application/Services/FiscalSessionComparisonService.php`
- New: `apps/api/app/Modules/POS/Application/Services/RepositoryCashCoverageService.php`
- New: `apps/api/app/Modules/POS/Application/Services/PosSessionReconciliationService.php`
- New: `apps/api/app/Modules/POS/Application/Jobs/ReconcileProjectedZReportJob.php`
- New: `apps/api/app/Modules/POS/Infrastructure/Commands/ReconcilePosSessionsCommand.php`
- New: `apps/api/app/Modules/POS/Presentation/Controllers/PosSessionReconciliationController.php`
- Existing: `apps/api/app/Modules/POS/routes.php:42,130-135`
- Existing: `docs/glossary.md`

**Signatures/schema**

```php
FiscalSessionComparisonService::compare(
    PosSessionReconciliationIntent $intent
): FiscalSessionComparison;

RepositoryCashCoverageService::calculate(
    RepositoryCashCoverageInput $input
): RepositoryCashCoverageResult; // pure; no writes

PosSessionReconciliationService::reconcile(
    PosSessionReconciliationIntent $intent
): PosSessionReconciliationResult;

PosSessionReconciliationService::supersede(
    string $priorRunId,
    PosSessionReconciliationIntent $replacement
): PosSessionReconciliationResult;
```

The server dependency snapshot contains transfer documents, movement IDs/ordinals, cash bookings, explicitly linked backoffice documents, configuration revision, and alignment boundary. Only `PosSessionReconciliationService` persists runs/supersessions.

**Complete spec-v4 W7 red-first matrix**

All cases live in `apps/api/tests/Feature/POS/PosSessionReconciliationServiceTest.php` and run with:

```bash
./scripts/run-feature-lane-local.sh feature-lane-pos --group POS
```

Lane: PostgreSQL.

| Exact case | First failing assertion |
|---|---|
| `test_taxed_sale_and_partial_refund_match_independent_totals` | `assertSame('matched', $result->fiscalStatus->value)` |
| `test_multiple_vat_rates_compare_per_rate_not_only_grand_total` | `assertSame([], $result->fiscalMismatch->vatBuckets)` |
| `test_rounded_cash_and_card_tenders_match_by_classification` | `assertSame($expectedTenderBuckets, $result->actualTenderBuckets)` |
| `test_account_collection_is_included_in_expected_cash_once` | `assertSame('matched', $result->fiscalStatus->value)` |
| `test_opening_float_and_fiscal_drop_are_covered_without_double_counting_opening` | `assertSame($countedCash, $result->repositoryCoverage->closingAmount)` |
| `test_manifest_membership_gap_stays_pending` | `assertSame('pending_membership', $result->status->value)` |
| `test_missing_projection_stays_pending_dependencies` | `assertSame('pending_dependencies', $result->status->value)` |
| `test_late_valid_member_appends_superseding_run` | `assertDatabaseHas('pos_session_reconciliation_supersessions', ['prior_run_id' => $firstId])` |
| `test_out_of_manifest_receipt_is_reported_as_mismatch` | `assertContains($receiptId, $result->fiscalMismatch->outOfManifestIds)` |
| `test_refund_only_session_preserves_negative_vat_bucket` | `assertSame('-19.000', $result->actualVatBuckets['19.000'])` |
| `test_duplicate_z_is_idempotent_when_manifest_hash_matches` | `assertDatabaseCount('pos_session_reconciliation_runs', 1)` |
| `test_duplicate_z_with_different_manifest_hash_is_conflict` | `assertSame('manifest_conflict', $result->mismatchCode)` |
| `test_legacy_source_with_insufficient_bounds_is_unavailable_not_matched` | `assertSame('unavailable_before_coverage', $result->repositoryStatus->value)` |
| `test_valid_hashes_with_wrong_economic_totals_mismatch` | `assertSame('mismatched', $result->fiscalStatus->value)` |
| `test_lot_estimate_status_is_explicit` | `assertNotNull($result->dependencySnapshot->lotEstimateStatus)` |
| `test_before_repository_cutover_coverage_is_unavailable` | `assertSame('unavailable_before_coverage', $result->repositoryStatus->value)` |
| `test_after_cutover_coverage_includes_float_drop_and_linked_backoffice_movement` | `assertSame($expectedOrdinals, $result->repositoryCoverage->movementOrdinals)` |
| `test_two_terminals_sharing_custody_do_not_double_count` | `assertSame($physicalDrawerTotal, $result->repositoryCoverage->closingAmount)` — Q11-conditional |
| `test_fiscal_and_repository_values_are_independently_derived` | `assertNotSame($zPayloadTotals, $result->derivedDependencySnapshot)` |
| `test_unknown_tender_raises_unknown_tender_classification_exception` | `$this->expectException(UnknownTenderClassificationException::class)` |
| `test_reclassified_tender_raises_unknown_tender_classification_exception` | `$this->expectException(UnknownTenderClassificationException::class)` |
| `test_current_reader_anti_joins_superseded_run` | `assertSame($secondId, $repository->currentForZ($zId)->id)` |
| `test_second_company_cannot_read_first_company_manifest_or_movements` | `assertSame('pending_dependencies', $resultB->status->value)` |
| `test_second_location_uses_only_its_drawer_ordinals` | `assertNotContains($locationAOrdinal, $resultB->repositoryCoverage->movementOrdinals)` |
| `test_rerun_with_same_dependencies_preserves_data_meaning` | `assertSame($first->fingerprint, $second->fingerprint)` |

**Implementation**

1. Derive fiscal totals from manifest members, not copied Z totals.
2. Derive repository coverage from immutable documents/movement ordinals and stored interval bounds.
3. Keep fiscal and repository results separately labeled.
4. Persist one immutable run; append supersession when dependencies change.
5. Raise `UnknownTenderClassificationException` for unknown or reclassified tender inputs.
6. Keep pre-cutover repository result unavailable.
7. Do not use current balances.
8. Display explicit W-LOT status; defer accepted-state mapping until Q10.
9. Queue on existing `default`.

**Reviewer gate:** every matrix row passes on PostgreSQL and reviewers confirm independent derivation, append-only supersession, and single-writer ownership.

---

## T10 — Canonical web DTOs and existing Treasury/shift surfaces

**Production files**

- Every file in the §5.2 TypeScript census
- Existing: `apps/web/src/routes/index.tsx:1937-1949`
- Existing: `apps/web/src/features/treasury/RepositoryDetailPage.tsx:229-237`
- Existing: `apps/web/src/features/treasury/RepositoryListPage.tsx:210`
- Existing: `apps/web/src/features/treasury/components/TransferCashModal.tsx`
- Generated: `packages/shared/types/generated.d.ts`
- New: `apps/web/src/features/treasury/components/CashCustodyConfigurationPanel.tsx`
- New: `apps/web/src/features/treasury/components/RepositoryTransferEvidencePanel.tsx`
- New: `apps/web/src/features/pos/components/SessionReconciliationPanel.tsx`

**Contract/schema:** no database delta. API types come exclusively from generated backend Data objects. Existing route/module/permission gates remain; no new catalogue or modal.

**Red-first tests**

| Test | First failing assertion | Command/lane |
|---|---|---|
| `apps/web/src/features/treasury/RepositoryDetailPage.test.tsx::renders_transfer_document_and_cash_custody_configuration_on_existing_detail` | `expect(screen.getByRole('heading', {name: /cash custody/i})).toBeVisible()` | `pnpm --filter @autoerp/web test -- src/features/treasury/RepositoryDetailPage.test.tsx -t "renders transfer document"` — Vitest |
| `apps/web/src/features/pos/components/SessionReconciliationPanel.test.tsx::shows_fiscal_and_repository_results_separately` | `expect(screen.getByText('Repository coverage')).toBeVisible()` | `pnpm --filter @autoerp/web test -- src/features/pos/components/SessionReconciliationPanel.test.tsx -t "shows fiscal and repository results separately"` — Vitest |
| `apps/web/src/features/treasury/RepositoryListPage.test.tsx::second_company_response_cannot_render_first_company_repository` | `expect(screen.queryByText(companyARepository)).not.toBeInTheDocument()` | web Vitest |
| `apps/web/src/features/treasury/RepositoryDetailPage.test.tsx::second_location_link_is_displayed_without_default_location_fallback` | `expect(screen.getByText(locationBName)).toBeVisible()` | web Vitest |
| `apps/web/src/features/treasury/hooks/useTransferCash.test.tsx::rerun_returns_same_generated_transfer_document_shape` | `expect(second.data.id).toBe(first.data.id)` | web Vitest |

**Implementation**

1. Generate DTO declarations.
2. Remove canonical-entity aliases listed in §5.2.
3. Document the four projection-only models.
4. Add configuration/history/evidence to repository detail.
5. Add reconciliation to the existing shift/Z detail.
6. Keep transfer action in the existing `TransferCashModal`.
7. Enforce `module:Treasury` plus existing permissions.

**Reviewer gate:** repository entity/type search shows one canonical generated representation; UI has one primary surface per concept and accessibility-based tests pass.

---

## T11 — Q13-conditional opening-balance alignment and variance coverage gate

**Status:** do not dispatch, migrate, or activate until Q13 is resolved.

**Production files**

- Conditional migration: `apps/api/database/migrations/tenant/2026_09_06_090600_create_repository_balance_alignments.php`
- Existing: `apps/api/app/Modules/Treasury/Application/Services/RepositoryAdjustmentService.php:90,234`
- Existing: `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php:289-325`
- Existing: `apps/api/config/treasury.php:16-28`
- Existing: `apps/api/app/Modules/Treasury/Presentation/routes.php:96-104`
- New: `apps/api/app/Modules/Treasury/Domain/Models/RepositoryBalanceAlignment.php`
- New: `apps/api/app/Modules/Treasury/Domain/Enums/RepositoryBalanceAlignmentStatus.php`
- New: `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryBalanceAlignmentIntent.php`
- New: `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryBalanceAlignmentResult.php`
- New: alignment-evidence DTO from §9.3
- New: `apps/api/app/Modules/Treasury/Application/Services/RepositoryBalanceAlignmentService.php`
- New: `apps/api/app/Modules/Treasury/Presentation/Requests/CreateRepositoryBalanceAlignmentRequest.php`
- New: `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryBalanceAlignmentController.php`
- New: `apps/api/app/Modules/Treasury/Infrastructure/Commands/CreateRepositoryBalanceAlignmentCommand.php`
- Existing: `docs/glossary.md`

**Signature/schema**

```php
RepositoryBalanceAlignmentService::align(
    RepositoryBalanceAlignmentIntent $intent
): RepositoryBalanceAlignmentResult;
```

Command:

```text
treasury:w-cash-align
  {--company=}
  {--repository=}
  {--cutover=}
  {--counted-amount=}
  {--currency=}
  {--cash-difference-account=}
  {--evidence-file=}
  {--dry-run}
```

The eventually approved Q13 contract controls execution. This plan does not select the recommended default.

**Red-first tests**

| Test | First failing assertion | Command/lane |
|---|---|---|
| `apps/api/tests/Feature/Treasury/RepositoryBalanceAlignmentTest.php::test_alignment_creates_one_document_one_movement_and_one_balanced_je` | `assertDatabaseCount('repository_balance_alignments', 1)` | `./scripts/run-feature-lane-local.sh treasury-spine-pgsql --group Treasury` — PostgreSQL |
| `...::test_alignment_never_backbooks_prior_shifts` | `assertDatabaseCount('repository_transfer_documents', 0)` for historical shifts | same lane |
| `...::test_second_company_cannot_align_first_company_repository` | `assertSame('REPOSITORY_COMPANY_MISMATCH', $result->code)` | same lane |
| `...::test_second_location_alignment_does_not_change_other_drawer` | `assertSame($beforeA, $afterA)` | same lane |
| `...::test_exact_alignment_rerun_preserves_document_and_balance_meaning` | `assertDatabaseCount('repository_balance_alignments', 1)` | same lane |
| `apps/api/tests/Feature/Treasury/ShiftVarianceCoverageGateTest.php::test_variance_listener_refuses_without_matched_repository_coverage` | `assertDatabaseCount('repository_adjustments', 0)` | same lane |

**Implementation**

1. After Q13, encode only the approved alignment policy.
2. Require counted evidence, explicit cutover, actor, currency, and difference account.
3. Produce no historical shift transfers.
4. Make variance posting require current matched reconciliation/coverage.
5. Keep `TREASURY_SHIFT_VARIANCE_GL_ENABLED=false` until all alignments and W7 checks pass.

**Reviewer gate:** owner ruling cited, alignment sample reviewed by Treasury/accounting, no-backbooking test green, and every activated repository has an evidenced cutover disposition.

---

## T12 — Promotion tooling, two-branch/two-terminal campaign, and real-device smoke

**Production/control files**

- Existing: `scripts/campaign-onboarding.sh`
- Existing: `apps/web/e2e/campaign/journey.ts`
- Existing: `apps/web/e2e/campaign/onboarding.campaign.ts`
- Existing: `apps/web/e2e/campaign/selectors.ts`
- Existing: `docs/qa/ONBOARDING-CAMPAIGN.md`
- Existing: `apps/api/app/Modules/Tenant/Application/Commands/BackupTenantCommand.php`
- Existing: `apps/api/tests/Feature/Tenant/FreshTenantCensusInvariantsTest.php`
- Existing: `apps/api/tests/Feature/Tenant/DayOneCensusCommandTest.php`
- Existing: `docs/handoff/RUNBOOK-day-one-census.md`
- Existing: `apps/pos/package.json:10,14`
- Existing: `apps/api/config/treasury.php`
- Evidence: `docs/handoff/HANDBACK-W-CASH-2026-09-06.md`

**Contract/schema:** no schema delta. Campaign adds a W-CASH leg using two POS-enabled branches and two terminals. It records actual IDs, configuration revisions, source/member hashes, transfer document IDs, movement ordinals, reconciliation runs, flags, and candidate SHA.

**Red-first tests**

| Test | First failing assertion | Command/lane |
|---|---|---|
| `apps/web/e2e/campaign/onboarding.campaign.ts::W-CASH_two_branch_two_terminal_custody_journey` | `expect(ledger.legs.W_CASH.status).toBe('PASS')` | `scripts/campaign-onboarding.sh --web https://erp.otospex.dev --api https://api.erp.otospex.dev --country TN` — Playwright/staging |
| `FreshTenantCensusInvariantsTest::test_second_company_and_second_pos_location_have_disabled_w_cash_configuration` | `assertSame('disabled', $configuration->capability_state->value)` | `./scripts/run-feature-lane-local.sh feature-lane-tenancy --group Company` — PostgreSQL |
| `DayOneCensusCommandTest::test_rerun_preserves_w_cash_invariant_meaning` | `assertSame($firstVerdict, $secondVerdict)` | same lane |
| Real-device smoke | First failure is any missing persisted Z/manifest after forced crash/restart | `pnpm --filter @autoerp/pos tauri build` followed by physical-device execution — real Tauri/SQLite/printer lane |

**Implementation and evidence**

1. Extend campaign with two branches and two terminals.
2. Execute opening → cash sale → partial refund → account collection → approved typed drop if Q12 is resolved → close/Z → reconciliation.
3. If Q11 is resolved to shared custody, exercise the approved join/refusal and sequential-shift behavior.
4. Run direct day-one census inside the campaign tenant:
   `cd apps/api && CACHE_STORE=array php artisan tenant:census-day-one --fail-on-drift`.
5. Run fleet census:
   `cd apps/api && CACHE_STORE=array php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1'`.
6. Because `tenants:run` discards child exit codes, read every printed `DAY-ONE CENSUS` line and reject any `DRIFT(n)` or `NO-COMPANY`.
7. Capture `apps/web/test-results/campaign-<runId>/ledger.json`, `apps/web/test-results/campaign-report.json`, Playwright report, traces, screenshots, and device logs.
8. Build the POS from the exact candidate SHA with:
   `pnpm --filter @autoerp/pos tauri build`.
9. Install that artifact on a real device and exercise local SQLite, receipt printer, drawer kick, offline authoring, process kill between operations, restart, reconnect, reordered sync, duplicate sync, and final server reconciliation.
10. Perform backup and restore rehearsal:
    `cd apps/api && php artisan tenant:backup "$WCASH_TENANT_SLUG"`.
    Restore only in an isolated rehearsal target using the existing restore command/runbook; never overwrite staging during verification.

**Reviewer gate:** campaign ledger is green, every census line is clean, real-device evidence is attached, and the handback records owner rulings, candidate SHA, migration versions, flags, cutovers, alignments, and rollback point.

---

## 12. Five-push staging auto-deploy manifest

Every push to `dev` is treated as an independent staging deployment. Do not combine the stages.

### Push 1 — Preflight and commands

**Contents:** T0 command, tests, `scripts/preflight.sh` integration, and audit template only. No schema or financial behavior.

Before push:

```bash
git rev-parse HEAD
pnpm lint
pnpm typecheck
cd apps/api && ./vendor/bin/phpunit tests/Feature/Treasury/WCashAuditCommandTest.php
```

After staging auto-deploy, in the API service console:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan horizon:terminate
php artisan treasury:w-cash-audit --format=json --include-optional --fail-on-drift
```

Stop before Push 2 unless the audit artifact is reviewed as `DISPATCHABLE`.

### Push 2 — Schema-only additive migrations

**Contents:** T1 migration production files, migration tests, device v68 then v69. No projector registration, policy default, backfill, or activation.

Before push:

```bash
cd apps/api && php artisan tenant:backup "$WCASH_TENANT_SLUG"
cd ../.. && ./scripts/run-feature-lane-local.sh feature-lane-tenancy --group Company
pnpm --filter @autoerp/pos test -- src/lib/db/__tests__/wCashMigrations.test.ts
```

After staging auto-migration:

```bash
cd apps/api
php artisan migrate:status
php artisan tenants:run migrate --option='force=1'
php artisan treasury:w-cash-audit --format=json --include-optional --fail-on-drift
php artisan optimize:clear
php artisan config:cache
php artisan horizon:terminate
```

Verify all new configuration/cutover tables are empty or disabled and v68 precedes v69.

### Push 3 — Dormant readers and code

**Contents:** T2–T10 production code and tests. T11 only if Q13 has been resolved. Defaults remain:

```text
TREASURY_W_CASH_BOOKING_ENABLED=false
TREASURY_CASH_COUNT_DISPATCH_ENABLED=false
TREASURY_SHIFT_VARIANCE_GL_ENABLED=false
```

After auto-deploy:

```bash
cd apps/api
php artisan optimize:clear
php artisan config:cache
php artisan config:show treasury
php artisan horizon:terminate
php artisan treasury:w-cash-audit --format=json --include-optional --fail-on-drift
```

Required observed values:

```text
treasury.w_cash_booking_enabled = false
treasury.cash_count_dispatch_enabled = false
treasury.shift_variance_gl_enabled = false
```

Run all T2–T10 lanes. Confirm no new transfer document, movement, JE, variance adjustment, or recovery obligation is produced solely by deployment.

### Push 4 — Bounded backfill and verification

**Contents:** operational command changes/evidence only; no activation. Persist disabled configuration revisions, explicit policy records/cutovers, and manifests for bounded eligible cohorts.

Commands use IDs captured in T0’s reviewed JSON artifact:

```bash
test -n "$WCASH_COMPANY_ID"
test -n "$WCASH_LOCATION_ID"
test -n "$WCASH_TERMINAL_ID"
test -n "$WCASH_DRAWER_REPOSITORY_ID"
test -n "$WCASH_SAFE_REPOSITORY_ID"
test -n "$WCASH_CUTOVER_ID"

cd apps/api

php artisan treasury:w-cash-configure \
  --company="$WCASH_COMPANY_ID" \
  --location="$WCASH_LOCATION_ID" \
  --drawer="$WCASH_DRAWER_REPOSITORY_ID" \
  --safe="$WCASH_SAFE_REPOSITORY_ID" \
  --policy-file="$WCASH_POLICY_FILE" \
  --state=disabled

php artisan fiscal:projection-cutover:create \
  --company="$WCASH_COMPANY_ID" \
  --location="$WCASH_LOCATION_ID" \
  --terminal="$WCASH_TERMINAL_ID" \
  --rail="$WCASH_SOURCE_RAIL" \
  --lower-bound="$WCASH_LOWER_BOUND" \
  --upper-bound="$WCASH_UPPER_BOUND" \
  --policy-revision="$WCASH_POLICY_REVISION" \
  --evidence-file="$WCASH_CUTOVER_EVIDENCE_FILE"

php artisan treasury:w-cash-catch-up-v2 \
  --cutover="$WCASH_CUTOVER_ID" \
  --dry-run

php artisan pos:reconcile-sessions \
  --company="$WCASH_COMPANY_ID" \
  --cutover="$WCASH_CUTOVER_ID" \
  --dry-run

php artisan pos:cash-count-recover \
  --company="$WCASH_COMPANY_ID" \
  --dry-run
```

Run the onboarding campaign, both census commands, backup/restore rehearsal, and real-device smoke. Resolve every unexplained conflict, missing member, unknown tender, or unavailable post-cutover interval before Push 5.

**Rollback point:** Push 4 complete, all code/schema deployed, all three flags false, no financial activation. Record this release SHA and backup ID in the handback.

### Push 5 — Conditional activation

Push 5 is prohibited while relevant Q10–Q13 rows remain OPEN.

Required owner/risk conditions:

- Q11 resolved before opening/shared-drawer booking.
- Q12 resolved before any cash-operation financial dispatch.
- Q13 resolved and required alignments complete before variance GL.
- Q10 and W-LOT status contract resolved before W7 lot-status promotion acceptance.
- W2, W4, T7, T8, T9, campaign, census, and real-device gates green.

Activate one reviewed company/location/terminal cohort first. In Dokploy, set:

```text
TREASURY_W_CASH_BOOKING_ENABLED=true
TREASURY_CASH_COUNT_DISPATCH_ENABLED=true
TREASURY_SHIFT_VARIANCE_GL_ENABLED=false
```

Then in the API service console:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan config:show treasury
php artisan horizon:terminate
php artisan treasury:w-cash-audit --format=json --include-optional --fail-on-drift
```

Activate only the reviewed configuration revision/cutover through the authenticated configuration endpoint or exact configuration command. Observe one complete opening → sales → drop → close cycle. Enable variance only after matched repository coverage and completed Q13 alignment:

```text
TREASURY_SHIFT_VARIANCE_GL_ENABLED=true
```

Then:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan config:show treasury
php artisan horizon:terminate
```

### Activation rollback

At the first wrong amount, cross-company/location reference, unbounded recovery, duplicate document/leg, missing manifest, unknown tender, unmatched post-cutover coverage, or queue obligation loss:

1. Restore Dokploy environment to:

```text
TREASURY_W_CASH_BOOKING_ENABLED=false
TREASURY_CASH_COUNT_DISPATCH_ENABLED=false
TREASURY_SHIFT_VARIANCE_GL_ENABLED=false
```

2. Run:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan horizon:terminate
php artisan config:show treasury
```

3. Append a disabled configuration revision; do not overwrite the active revision.
4. Leave schema/readers deployed.
5. Do not delete fiscal events, manifests, obligations, documents, movements, alerts, or reconciliation runs.
6. Correct any financial effect through the documented reversal/compensation path.
7. Recover only explicitly selected obligations after the cause is fixed.

---

## 13. Dispatch order

1. T0 — pre-migration census and reviewer stop.
2. T1 — additive server schema and device v68 → v69.
3. T2 — event-time policy and bounded recovery.
4. T3 — disabled revisioned configuration and provisioning.
5. T4 — transfer document, manual linkage, route gate, durable alerts.
6. Resolve Q11/Q12 before enabling dependent branches.
7. T5 — booking/fingerprint/adapters, shipped dormant.
8. T6 — device policy evidence/typed authoring, shipped fail-closed.
9. T7 — atomic manifest.
10. T8 — durable per-consumer cash-count dispatch.
11. Confirm W2 classification, W4 completeness, W-LOT readiness, and Q10 disposition.
12. T9 — complete W7 reconciliation.
13. T10 — generated types and canonical existing surfaces.
14. Resolve Q13, then dispatch T11 if approved.
15. T12 — campaign, day-one census, real-device smoke, backup/restore, handback.
16. Execute Push 4 and record the rollback point.
17. Execute Push 5 only after every applicable owner and reviewer gate is signed.

---

## 14. Final verification checklist

- [ ] Candidate SHA recorded as the exact deployed commit; plan inspection base is `6e17a76022c5ccd8864afdf98cd5302e5264176b`.
- [ ] T0 works before any future W-CASH table exists.
- [ ] Static and live census name every v2/v3 source, X/Z payload key set, writer, consumer, company, location, terminal, repository, and active module.
- [ ] No unexpected direct `repository_movements` or cached-balance writer exists.
- [ ] Device migration v68 precedes v69 and only T1 owns `migrations.ts`.
- [ ] Every migration is additive, compatible, default-disabled, and tested on PostgreSQL.
- [ ] Every business unique includes `company_id`; tenant-only unique scanner is unchanged/clean.
- [ ] Every JSONB column uses its named strict DTO and round-trips.
- [ ] Q10–Q13 remain explicitly OPEN until separate owner resolution; no recommended default is treated as decided.
- [ ] No Q11-dependent opening/shared-drawer money path activates before Q11.
- [ ] Sequential shifts cover zero, partial top-up, retained match, retained excess, missing predecessor, out-of-order arrival, late predecessor, and shared-drawer behavior.
- [ ] No Q12-dependent `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, `DEPOSIT`, or `PAYOUT` dispatch activates before Q12.
- [ ] `payload.training_flag` is required as a real boolean; missing/string values move no money.
- [ ] Training events produce an explicit no-money outcome.
- [ ] Recovery reads immutable event-time policy or a persisted bounded cutover, never current activation.
- [ ] Configuration revisions are immutable and authored events retain the exact revision/cutover evidence.
- [ ] v2/v3 semantic equivalence creates one obligation; true semantic conflict freezes visibly.
- [ ] One internal transfer produces one document/group, two legs, and zero/one JE.
- [ ] Exact replay produces no additional document, leg, JE, balance change, or alert.
- [ ] Frozen/checkpoint transfer alert is durable and once per company/group/code.
- [ ] Manual transfer linkage validates company, location, shift, and session.
- [ ] Reused transfer and new configuration/alignment routes are protected by `module:Treasury`.
- [ ] Device Z, close manifest, and local outbox are atomic.
- [ ] Manifest includes only device-authored sources; server dependencies are a separate snapshot.
- [ ] Reordered uploads and crash recovery remain pending until complete, then converge.
- [ ] Cash-count dispatch persists one independent obligation/outcome per consumer.
- [ ] Partial consumer success and queue failure cannot lose an obligation.
- [ ] W7 ran only after W2, W4, manifest, durable dispatch, and W-LOT prerequisites.
- [ ] Every spec-v4 W7 matrix case is green on PostgreSQL.
- [ ] Unknown and reclassified tenders raise `UnknownTenderClassificationException`.
- [ ] Fiscal totals and repository coverage are independently derived.
- [ ] Pre-cutover repository coverage is `unavailable_before_coverage`, never matched.
- [ ] Repository coverage uses immutable movement ordinals, never current cached balance.
- [ ] `RepositoryCashCoverageService` has no writes.
- [ ] Only `PosSessionReconciliationService` writes reconciliation runs/supersessions.
- [ ] Supersession is append-only and the current reader anti-joins prior runs.
- [ ] Generated DTOs replace all canonical repository/transfer/movement aliases; projection-only models are documented.
- [ ] Existing repository detail/transfer and shift/Z detail remain the only primary surfaces.
- [ ] Fresh registration, second company, second POS location, and rerun tests satisfy convention 09.
- [ ] Onboarding campaign covers two branches and two terminals.
- [ ] Direct day-one census is clean.
- [ ] Fleet census prints no `DRIFT(n)` or `NO-COMPANY`.
- [ ] Real Tauri build from the candidate SHA passes SQLite, printer, drawer, offline, crash/restart, reconnect, reordered sync, and duplicate sync smoke.
- [ ] Backup and isolated restore rehearsal are evidenced.
- [ ] Push 4 rollback SHA and backup ID are recorded.
- [ ] All three flags are false before activation.
- [ ] Q13-approved alignment exists before enabling variance GL.
- [ ] Activation starts with one reviewed cohort.
- [ ] Rollback disables all three flags, clears/rebuilds config cache, terminates Horizon, preserves append-only evidence, and uses forward corrections only.