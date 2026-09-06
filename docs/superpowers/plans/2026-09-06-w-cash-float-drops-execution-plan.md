<!-- Rev 3, authored by Codex CLI (gpt-5.6-sol, high, read-only) on 2026-09-06 from rev 2 + plan gate r3; filed verbatim by the orchestrator. Status: awaiting plan gate r4. Rev 1 = 67c0805d4, rev 2 = 2d5268890. -->
# W-CASH execution plan — revision 3

**Date:** 2026-09-06  
**Replacement target:** `docs/superpowers/plans/2026-09-06-w-cash-float-drops-execution-plan.md`  
**Repository:** `/Users/houssamr/Projects/syneriva/apps/erp`  
**Branch read:** `dev`  
**HEAD read:** `11e13eccd746241fb7546d84185310ce490d4464`  
**Mode:** read-only planning. No files changed, no tests run, no commits, migrations, pushes, or deployments performed.

The worktree contained two unrelated untracked files when inspected:

- `apps/api/docs/sessions/2026-08-04-cross-tenant-scheduled-jobs-plan.md`
- `docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md`

They are outside W-CASH and must remain untouched.

Authority order:

1. Owner rulings in `OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md`.
2. Parapharmacy remediation spec v4.
3. W-CASH lane brief and RD4.
4. Repository conventions and current production behavior.
5. Gate-r3 review.

Q10–Q13 remain OPEN. This revision intentionally withholds every schema, command, task, push, or activation branch that would decide one of them.

---

## 1. Revision-3 change log and gate-r3 closure

### 1.1 Carried PARTIAL/OPEN rows

| Gate-r3 row | Rev-3 disposition |
|---|---|
| B4 — v2/v3 fingerprint and cutover incomplete | **CLOSED.** The canonical fingerprint now includes `company_id`, `location_id`, `terminal_id`, normalized `shift_id`, normalized `session_id`, source-fact time, amount/currency, raw fact kind, and policy/configuration revision identity. Cross-shift reuse is a `conflict`, never an idempotent hit. |
| B5 — shared drawer/opening semantics | **CLOSED BY WITHHOLDING.** No custody interval, drawer-session, membership, predecessor, retained-balance, join/refusal, opening-transfer, or count-owner schema or behavior may land while Q11 is OPEN. Evidence may be collected, but processing returns `blocked/owner_ruling_required`. |
| B6 — W7 reconciliation incomplete | **CLOSED.** The policy-neutral obligation/run/supersession schemas, transition fields, CLI signature, immutable run contract, and spec-v4 acceptance matrix are complete. Repository comparison, lot-release interpretation, and historical alignment remain blocked behind Q10–Q13. |
| B7 — migration/deployment unsafe | **CLOSED.** The manifest uses `tenants:migrate-rolling --force`, verifies every tenant, forwards every flag through staging Compose and the API entrypoint, captures revision/cutover IDs from JSON output before use, deploys Dokploy web after each web-touching push, verifies asset hash and feature fingerprint, runs Playwright, and defines a rollback point per push. |
| M4 — task-specific convention-09 tests | **CLOSED.** Every mutation task has a typed result containing `applied`, `already_exists`, `skipped`, `blocked`, or `conflict`; rerun tests assert that outcome and its meaning, not only row counts. |
| M9 — local type census incomplete | **CLOSED.** The POS `PaymentRepository` entity and SQLite row projection are included. The generated DTO becomes the cross-boundary entity; only the SQLite row remains a local persistence projection, behind a validating decoder. |

### 1.2 Gate-r2 findings still PARTIAL/OPEN in gate r3

| Gate-r2 finding | Rev-3 disposition |
|---|---|
| Opening interval algorithm | **CLOSED BY WITHHOLDING.** The algorithm is removed from dispatch. Q11 must be decided before a separate owner supplement specifies drawer custody sessions, membership, count ownership, predecessor order, and intervening transfers. |
| Durable cutover/config revision | **CLOSED.** Complete schemas, constraints, DTOs, immutable identifiers, commands, and output-capture steps appear below. W-CASH cutovers can only record `blocked` while relevant rulings remain OPEN. |
| Tasks lack dispatch contracts | **CLOSED.** Every task names exact HEAD modification targets, exact new-file destinations, signatures, migrations, red-first tests, first assertion, exact command/lane, reviewer gate, and rollback. |
| CashCountDispatcher durability | **CLOSED.** The three durable consumers are Treasury, Compliance, and stored-domain-event history. Flag-false behavior retains the current Laravel event dispatch. |
| Manifest membership/atomicity | **CLOSED.** Close and Z events are appended first inside the existing SQLite transaction; returned identities/hashes are then used to build the manifest and outbox before commit. |
| Cross-rail fingerprint | **CLOSED.** Shift/session identity is part of the semantic fingerprint; raw v2/v3 evidence hashes remain separate. |
| Local type census | **CLOSED.** Web and POS local canonical aliases are both removed or reduced to persistence projections. |
| Provisioning atomicity | **CLOSED.** The plan deliberately changes the current failure-contained contract to fail-closed atomic provisioning and requires repository/configuration fault-injection tests at company and location boundaries. |
| Promotion evidence | **CLOSED.** The five-push manifest includes fleet migrations, web freshness, campaign, two-company/two-location/two-terminal evidence, physical Tauri/SQLite/offline/crash smoke, backup/restore, and rollback points. |
| Tenant-only uniqueness ratchet | **CLOSED.** The actual HEAD scanner is `apps/api/tests/Architecture/Support/TenantOnlyUniqueIndexScanner.php`; every new tenant business unique includes `company_id`. |

### 1.3 New gate-r3 blockers

| Finding | Rev-3 disposition |
|---|---|
| Blocker 1 — convention 10 mechanically unsatisfied | **CLOSED.** Section 4 has exactly the required columns, separate Odoo/ERPNext/Dolibarr comparisons, nine required guarantee rows, and only `MATCH`, `DEFER`, `DIVERGE`, or `ALREADY` in Decision. |
| Blocker 2 — Q11 branch encoded prematurely | **CLOSED.** `treasury_drawer_custody_intervals` and all equivalent session/membership tables are absent. Q11 behavior is withheld, not conditionally shipped. |
| Blocker 3 — incomplete task/schema contract | **CLOSED.** Sections 7–11 specify every migration column, type, nullability, default, FK/delete behavior, checks, indexes, JSONB DTO, transition field, service/CLI signature, test, lane, review, and rollback. |
| Blocker 4 — staging manifest not executable | **CLOSED.** Section 12 fixes command order, output capture, rolling migration verification, web deployment/freshness, and entrypoint failure propagation. |

### 1.4 New gate-r3 majors

| Finding | Rev-3 disposition |
|---|---|
| Major 1 — financial lock inversion | **CLOSED.** The load-bearing order is tenant numbering → company GL chain → transfer document → sorted repositories. A cross-GL draft is minted before Treasury locks. All W-CASH paths share that order; opposite-transfer and unrelated-JE concurrency tests are mandatory. |
| Major 2 — impossible manifest order | **CLOSED.** `appendZSessionCloseAndZReport()` runs before manifest construction, inside the same SQLite transaction. |
| Major 3 — stored-event consumer dropped | **CLOSED.** Stored-event persistence is its own durable obligation. Flag false retains current `Event::dispatch()`. Flag true does not double-dispatch. |
| Major 4 — provisioning behavior contradicted | **CLOSED.** Interfaces and callers are explicitly changed to fail-closed results/exceptions, with one outer transaction for company/location, repositories, and disabled configuration. |
| Major 5 — fingerprint lacks custody boundary | **CLOSED.** `shift_id` and `session_id` are mandatory normalized semantic fields when present at source; absence is represented explicitly, never omitted from hashing. |
| Major 6 — POS absent from type census | **CLOSED.** POS generated DTO consumption and a validated `PaymentRepositoryRow` adapter are mandatory. |
| Major 7 — rerun outcome not explicit | **CLOSED.** Every mutator returns and tests a typed convention-09 outcome. |
| Major 8 — nonexistent paths | **CLOSED.** The correct HEAD paths are used: `tests/Architecture/Support/TenantOnlyUniqueIndexScanner.php`, `app/Modules/POS/routes.php`, and `app/Modules/Compliance/Listeners/OpenFraudAlertForShiftVariance.php`. |

Gate r3 reported no independent new MINOR finding. Previously closed minor findings—optional-table probing, strict `payload.training_flag`, and single reconciliation writer—remain preserved.

---

## 2. Non-negotiable scope and stops

The lane may deliver dormant, policy-neutral infrastructure for:

- Immutable event-time policy evidence.
- Bounded legacy cutovers.
- Disabled custody topology revisions.
- Transfer documents over the existing transfer spine.
- Cross-rail evidence obligations.
- Device-authored close manifests.
- Durable cash-count fan-out.
- Immutable reconciliation obligations/runs/supersessions.
- Generated repository DTO adoption.
- Read-only census and blocked-status operator surfaces.

The lane must not deliver or activate:

- A branch recall/release policy while Q10 is OPEN.
- A drawer custody session, shared-drawer membership, second-terminal join/refusal rule, count owner, retained-balance algorithm, or opening transfer while Q11 is OPEN.
- Typed meanings or destination mappings for `CASH_IN`, `CASH_OUT`, `DEPOSIT`, or `PAYOUT` while Q12 is OPEN.
- Historical alignment documents, adjustments, commands, or variance activation while Q13 is OPEN.
- Automatic historical shift back-booking.
- A combined repository transfer/bank-settlement operation.
- A second expected-cash opening component; `ShiftExpectedCashService` already includes opening cash.
- A new queue. Existing `default` and `fiscal-projections` queues are sufficient.
- Deletion or mutation of sealed fiscal evidence.
- In-place mutation of reconciliation history.

All rollout flags default to `false`. Push 5 is prohibited while any relevant owner ruling remains OPEN.

---

## 3. Owner rulings — verbatim and OPEN

**Status: Q10 OPEN, Q11 OPEN, Q12 OPEN, Q13 OPEN.** The following rows are reproduced verbatim from the owner ruling. Recommendations are not decisions.

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q10** Recall request lifecycle | May the general manager **reject** a branch recall request and **release** the branch hold? Who may release, with what evidence? | Lot hold (`quality_hold` / OCA lock) is a status that QC lifts; no built-in approval chain | GMP norm: quarantined → **released** or **rejected** by the quality authority only, with a recorded disposition and signature; a hold is never lifted by the requester | No hold exists; `is_recalled` is a global boolean | Hold lifecycle `requested → recalled (company-wide)` **or** `requested → released` where **only the general manager** may release, with mandatory reason + append-only evidence; the requesting branch cannot lift its own hold. Recommend this, in scope for W-LOT S1. |
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

A later owner supplement must resolve a row explicitly before any dependent schema or activation is dispatched. Resolving one row does not implicitly resolve the others.

---

## 4. Convention-10 benchmark matrix

Reference sources: [Odoo POS workflow](https://www.odoo.com/documentation/19.0/applications/sales/point_of_sale/use.html), [Odoo POS employee permissions](https://www.odoo.com/documentation/19.0/applications/sales/point_of_sale/employee_login.html), [Odoo payment methods](https://www.odoo.com/documentation/19.0/applications/sales/point_of_sale/payment_methods.html), [ERPNext POS workflows](https://docs.frappe.io/erpnext/pos-workflows), [ERPNext Journal Entry Template](https://docs.frappe.io/erpnext/journal-entry-template), and [Dolibarr TakePOS](https://wiki.dolibarr.org/index.php/Module_Point_of_sale_(TakePOS)).

| ID | Guarantee | Odoo | ERPNext | Dolibarr (or NV with reason) | AutoERP today path:line | Gap | Decision |
|---|---|---|---|---|---|---|---|
| G01 | Create one auditable transfer document with balanced custody legs | Cash transfers are journal-backed operational facts | Payment/Journal Entry records the balanced transfer | NV — official TakePOS documentation does not establish an equivalent repository-transfer document contract | `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:33`; `TreasuryMovementService.php:225` | Transfer has a group and two legs but no first-class immutable document/source evidence | MATCH |
| G02 | Duplicate create/replay returns the existing result without a second effect | Posted POS/accounting facts are not duplicated by reopening the UI | Named accounting documents are replayed/referenced rather than recreated | NV — no official idempotency contract found | `TreasuryMovementService.php:60`; idempotency handling before mutable policy | A W-CASH semantic identity/result contract is absent | MATCH |
| G03 | Edit after financial use is prohibited | Posted accounting records are corrected through controlled reversal | Submitted accounting documents are amended/cancelled rather than silently edited | NV — TakePOS page does not specify immutable transfer editing | `repository_movements` immutability trigger at `apps/api/database/migrations/tenant/2026_07_23_100001_enforce_treasury_immutability.php:35` | Transfer metadata lacks the same explicit immutable-document boundary | ALREADY |
| G04 | Cancel uses a compensating reversal, preserving the original | Accounting cancellation preserves reversal/audit history | Cancel/amend flows retain document lineage | NV — official TakePOS page does not specify transfer cancellation lineage | `TreasuryMovementService.php:225`; repository movements are immutable | No transfer-document `reversal_of_document_id` exists | MATCH |
| G05 | Rerun returns explicit `already_exists` or `skipped` with unchanged meaning | Reopening does not reproduce posted movement | Submitted document identity gives visible existing state | NV — no official rerun result contract found | Current `RepositoryTransferResult::$idempotentReplay` in `RepositoryTransferService.php:100` | Several other W-CASH mutations lack typed replay outcomes | MATCH |
| G06 | Second company is isolated in lookup, unique keys, locks, and audit | Companies have separate journals/configuration | Company is a standard document/accounting boundary | NV — multi-company guarantee not established by the cited TakePOS page | `TreasuryMovementService.php:238`; company-scoped repository resolution in `RepositoryTransferService.php:118` | All new business uniques and claims must repeat `company_id` | MATCH |
| G07 | Second location cannot borrow another branch’s drawer/configuration | POS configuration/register is location-specific | POS Profile/opening entry scopes custody | NV — cited page does not prove cross-location repository isolation | `LocationCashRegisterProvisionerInterface.php:34`; location provisioning at `LocationController.php:195` | Disabled custody topology and read surfaces are missing | MATCH |
| G08 | Permission and module gates protect every human mutation | Employee/POS permission controls restrict operations | Role permissions restrict submitted documents | TakePOS exposes user/terminal controls, but no equivalent AutoERP permission name | Treasury routes at `apps/api/app/Modules/Treasury/Presentation/routes.php:35`; transfer route at `:102` | Existing transfer route lacks the Treasury module gate required by this lane | MATCH |
| G09 | Audit preserves actor, source identity, reason/evidence, time, and reversal lineage | POS/accounting journals retain operator and transaction evidence | Journal documents retain owner, posting date, reference, and remarks | NV — official TakePOS page is insufficient for full evidence semantics | Movement source/idempotency handling in `TreasuryMovementService.php:554`; fiscal source rows in `OutboxIngestor.php:980` | First-class transfer document, policy evidence, and immutable reconciliation run are absent | MATCH |
| G10 | Shared drawer/second-terminal custody has one explicit ownership policy | One POS configuration/register per cash custody context | POS Opening Entry is per user/profile | NV — no authoritative shared-drawer policy found | `apps/api/database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php:25`; device shifts are terminal-scoped | Owner Q11 is OPEN; no drawer custody session exists | DEFER |
| G11 | Cash-in/out meaning maps to an explicit counterparty/account policy | Cash move reason can determine accounting destination | Journal Entry models expense or transfer counterparty | NV — cited TakePOS page does not specify typed mapping semantics | `CashDrawerOperation.php:21`; current device operation kinds are not a complete accounting classification | Owner Q12 is OPEN | DEFER |
| G12 | Historical repository alignment has one approved cutover rule | Opening/closing cash differences are handled prospectively | Opening Entry/Journal Entry establishes opening balance | NV — no authoritative alignment workflow found | Variance writer remains disabled; no alignment mechanism exists | Owner Q13 is OPEN | DEFER |
| G13 | Lot recall/release status is interpreted under an approved authority rule | Hold/release exists as a quality status | Quality disposition follows quality authority | NV — unrelated to TakePOS | W7 lot integration in spec v4; current `is_recalled` boolean is insufficient | Owner Q10 is OPEN | DEFER |

---

## 5. Verified HEAD census

### 5.1 Device and server source facts

- v3 opening authoring is in [zSessionAuthoring.ts](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/fiscal/zSessionAuthoring.ts:507). The write transaction starts at line 523.
- v3 closing appends `SESSION_CLOSE` and `Z_REPORT` and returns their IDs/hashes at [zSessionAuthoring.ts](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/fiscal/zSessionAuthoring.ts:675).
- Device cash movement authoring begins at [zSessionAuthoring.ts](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/fiscal/zSessionAuthoring.ts:735).
- Z close currently owns its SQLite write transaction at [zReportService.ts](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/offline/zReportService.ts:541).
- Server fiscal projection registration occurs in [OutboxIngestor.php](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:980).
- Dispatch and claim behavior is in [FiscalEventProjectionDispatcher.php](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php:79) and [ApplyFiscalEventProjectionJob.php](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:226).
- v2 source identity remains `pos_cash_drawer_operations.id`; v3 source identity remains `fiscal_events.id`.
- `payload.training_flag` is valid only when present as a JSON boolean. Missing, `null`, string `"true"`, numeric values, or malformed payloads fail closed to `blocked/invalid_training_flag`. Only literal `true` produces `training_no_money`.

### 5.2 Canonical semantic fingerprint

`ShiftCashSemanticFingerprint::fromIntent()` hashes canonical JSON with these keys in this exact order:

```text
tenant_id
company_id
location_id
terminal_id
shift_id
session_id
source_fact_occurred_at_utc
source_fact_kind
amount_decimal
currency
policy_revision_key
configuration_revision_id
```

Rules:

- UUIDs are lowercase canonical text.
- Missing `shift_id`, `session_id`, `policy_revision_key`, or `configuration_revision_id` is encoded as JSON `null`, not omitted.
- Amount is normalized through the company currency scale.
- Time is UTC RFC3339 with microseconds.
- `source_fact_kind` remains the raw authored fact kind; it is not mapped to a Q12 accounting meaning.
- The semantic fingerprint is SHA-256 of canonical JSON.
- Raw evidence hashes are separate:
  - v2: SHA-256 of canonical persisted drawer-operation evidence.
  - v3: authenticated fiscal event hash already carried by the event.
- Same source identity and same semantic fingerprint returns `already_exists`.
- Same source identity and a different semantic fingerprint returns `conflict`.
- Same raw amount/time under another shift or session is a different obligation.
- A legacy event without immutable policy/cutover evidence remains `blocked`; current configuration must never reinterpret it.

### 5.3 Web and POS type census

Canonical boundary type: generated `PaymentRepositoryData`.

Web production consumers to migrate:

- `apps/web/src/features/treasury/pages/RepositoryListPage.tsx`
- `apps/web/src/features/payments/components/PaymentForm.tsx`
- `apps/web/src/features/treasury/hooks/useTransferCash.ts`
- `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts`
- `apps/web/src/features/treasury/pages/RepositoryDetailPage.tsx`
- `apps/web/src/features/payments/components/RecordPaymentModal.tsx`
- `apps/web/src/features/treasury/components/AddRepositoryModal.tsx`
- `apps/web/src/features/payments/components/SplitPaymentForm.tsx`
- `apps/web/src/features/pos/api/paymentRepositoryApi.ts`
- `apps/web/src/features/treasury/hooks/useRepositoryMovements.ts`

Projection/read-model shapes that may remain local because they are not repository entities:

- `apps/web/src/features/treasury/hooks/useCashPosition.ts`
- `apps/web/src/features/treasury/hooks/useRemittances.ts`
- `apps/web/src/features/treasury/statements/api.ts`
- `apps/web/src/features/treasury/statements/StatementUploadWizard.tsx`

POS:

- Remove the separate canonical entity from [payment.ts](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/types/payment.ts:27).
- Retain only `PaymentRepositoryRow` from [paymentRepository.ts](/Users/houssamr/Projects/syneriva/apps/erp/apps/pos/src/lib/db/repositories/paymentRepository.ts:24) as a SQLite persistence projection.
- Replace the unchecked row cast at line 58 with `decodePaymentRepositoryRow(row): PaymentRepositoryData`, validating UUIDs, repository type, currency, nullability, and configuration revision fields.
- Test fixtures must instantiate the generated DTO shape rather than recreating a third local interface.

---

## 6. Financial writer and lock census

### 6.1 Existing single write port

[TreasuryMovementService.php](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:34) is the sole low-level writer of repository movements and cached balances. Its movement insert and balance update occur at lines 582 and 607.

Existing direct callers at HEAD:

| Caller | HEAD line(s) | Transaction/lock obligation |
|---|---:|---|
| `IncomeService.php` | 175 | Caller owns root transaction and GL-before-repository order |
| `ExpenseService.php` | 452, 488, 816, 998 | Same |
| `RefundCompensationService.php` | 308 | Same |
| `TreasuryAccountPaymentBridge.php` | 267 | Same |
| `TreasuryDepositBridge.php` | 249 | Same |
| `TreasuryReceiptBridge.php` | 1560 | Same |
| `AcquirerFeeService.php` | 133 | Same |
| `InstrumentLifecycleService.php` | 277, 495 | Same |
| `OutboundInstrumentService.php` | 140, 298, 452 | Same |
| `RepositoryAdjustmentService.php` | 234 | Same |
| `RepositoryOpeningBalanceService.php` | 152 | Same |
| `MultiPaymentService.php` | 602 | Same |
| `PaymentRefundService.php` | 729, 1087 | Same |
| `VendorRefundService.php` | 211 | Same |
| `PaymentController.php` | 1349, 2063 | Same |
| `RepositoryTransferService.php` | 89 | Uses `transfer()` and must preserve draft-before-repository ordering |

No W-CASH component may insert `repository_movements` or update repository balances directly.

### 6.2 Load-bearing lock order

[GeneralLedgerService.php](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3741) acquires tenant numbering before the company GL chain. Repository transfers lock the company and sorted repository IDs in [TreasuryMovementService.php](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:238).

Required order for a cross-GL W-CASH transfer:

1. A short, committed preclaim transaction inserts or reads the immutable source/document identity. It holds no lock when the financial transaction begins.
2. Begin one root financial transaction.
3. Mint the draft journal entry first. This obtains tenant numbering, then company GL-chain locks, and retains them until root commit.
4. Lock the transfer-document row.
5. Lock repository rows by lexicographically sorted repository UUID.
6. Post exactly two repository movement legs.
7. Seal/post the already-minted journal entry while the earlier tenant/company locks remain owned.
8. Mark the transfer document posted and commit.

The later seal may re-enter locks already held by the same transaction, but it must never introduce tenant numbering after a transaction first acquired company/repository locks.

For a same-GL transfer, no JE is created; the document is locked before sorted repositories, and no tenant-numbering lock is subsequently requested.

### 6.3 `InventoryGlPostingBuffer` ownership

[InventoryGlPostingBuffer.php](/Users/houssamr/Projects/syneriva/apps/erp/apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:17) remains the sole inventory GL buffer. Its `enqueue()` and `flushIfOutermost()` methods are at lines 34 and 56.

W-CASH must:

- Never enqueue through `InventoryGlPostingBuffer`.
- Never flush it.
- Never make it depend on a Treasury document/repository lock.
- Preserve its route into `GeneralLedgerService`, and therefore the same tenant-numbering → company-chain order.
- Add a PostgreSQL test running an unrelated buffered inventory JE concurrently with an opposite-direction W-CASH transfer. Neither transaction may emit SQLSTATE `40P01`, time out, or leave a partial movement/JE.

---

## 7. Complete migration and DTO contract

All migrations are additive and self-guarding. Tenant business unique constraints include `company_id`; primary-key uniqueness is exempt. `tenant_id` has no database FK because tenant databases do not contain the central `tenants` table; it remains non-null and is validated against active tenant context. All UUID FKs use `ON DELETE RESTRICT`.

### 7.1 Migration `2026_09_06_090000_add_w_cash_event_time_policy.php`

#### `fiscal_projection_policy_records`

| Column | Contract |
|---|---|
| `id` | UUID, PK, not null, no default |
| `tenant_id` | UUID, not null, no FK |
| `company_id` | UUID, not null, FK `companies.id`, RESTRICT |
| `fiscal_event_id` | UUID, not null, FK `fiscal_events.id`, RESTRICT |
| `projector_name` | varchar(128), not null |
| `decision` | varchar(32), not null |
| `policy_revision_key` | char(64), not null |
| `policy_evidence` | jsonb, not null, default `'{}'::jsonb` |
| `decided_at` | timestamptz, not null |
| `created_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |

Checks:

- `decision IN ('project','block','training_no_money')`.
- `policy_revision_key ~ '^[0-9a-f]{64}$'`.

Indexes/uniques:

- Unique `(company_id, fiscal_event_id, projector_name)`.
- Index `(company_id, decision, decided_at)`.
- Index `(fiscal_event_id)`.

JSONB DTO: `FiscalProjectionPolicyEvidenceData`.

#### `fiscal_projection_cutovers`

| Column | Contract |
|---|---|
| `id` | UUID, PK, not null |
| `tenant_id` | UUID, not null, no FK |
| `company_id` | UUID, not null, FK `companies.id`, RESTRICT |
| `location_id` | UUID, not null, FK `locations.id`, RESTRICT |
| `terminal_id` | UUID, not null, FK `pos_terminals.id`, RESTRICT |
| `projector_name` | varchar(128), not null |
| `source_rail` | varchar(16), not null |
| `lower_bound` | varchar(191), not null |
| `upper_bound` | varchar(191), not null |
| `decision` | varchar(32), not null |
| `policy_revision_key` | char(64), not null |
| `boundary_evidence` | jsonb, not null |
| `created_by` | UUID, not null, FK `users.id`, RESTRICT |
| `created_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |

Checks:

- `source_rail IN ('v2','v3')`.
- `decision IN ('project','block','training_no_money')`.
- SHA-256 format check on `policy_revision_key`.
- Bounds non-empty and different. Command/service validates rail-specific ordering.

Indexes/uniques:

- Unique `(company_id, location_id, terminal_id, projector_name, source_rail, lower_bound)`.
- Index `(company_id, projector_name, source_rail, lower_bound, upper_bound)`.

JSONB DTO: `FiscalProjectionCutoverBoundaryData`.

#### Additions to `fiscal_event_projections`

| Column | Contract |
|---|---|
| `policy_record_id` | UUID, nullable, FK `fiscal_projection_policy_records.id`, RESTRICT |
| `cutover_id` | UUID, nullable, FK `fiscal_projection_cutovers.id`, RESTRICT |
| `blocked_reason_code` | varchar(64), nullable |
| `blocked_detail` | jsonb, nullable |
| `blocked_at` | timestamptz, nullable |
| `claim_token` | UUID, nullable |
| `lease_expires_at` | timestamptz, nullable |
| `recovery_attempt_count` | integer, not null, default `0` |
| `last_recovery_at` | timestamptz, nullable |

Checks:

- Not both `policy_record_id` and `cutover_id`.
- `recovery_attempt_count >= 0`.
- `claim_token` and `lease_expires_at` are both null or both non-null.
- State `running` requires both claim fields.
- State `blocked` requires `blocked_reason_code` and `blocked_at`.

Indexes:

- `(company_id, status, lease_expires_at)`.
- `(policy_record_id)`.
- `(cutover_id)`.

JSONB DTO: `FiscalProjectionBlockedDetailData`.

### 7.2 Migration `2026_09_06_090100_create_cash_custody_configuration.php`

This is topology/evidence only. It contains no operation mapping, shared-drawer session, opening policy, alignment policy, or Q10 data.

#### `cash_custody_configurations`

| Column | Contract |
|---|---|
| `id` | UUID, PK, not null |
| `tenant_id` | UUID, not null, no FK |
| `company_id` | UUID, not null, FK `companies.id`, RESTRICT |
| `location_id` | UUID, not null, FK `locations.id`, RESTRICT |
| `state` | varchar(16), not null, default `'disabled'` |
| `current_revision_id` | UUID, nullable; FK added after revision table to `cash_custody_configuration_revisions.id`, RESTRICT |
| `lock_version` | bigint, not null, default `0` |
| `created_by` | UUID, nullable, FK `users.id`, RESTRICT |
| `updated_by` | UUID, nullable, FK `users.id`, RESTRICT |
| `created_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |
| `updated_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |

Checks:

- `state IN ('disabled','ready','active','suspended')`.
- `lock_version >= 0`.
- `ready` or `active` requires non-null `current_revision_id`.

Indexes/uniques:

- Unique `(company_id, location_id)`.
- Index `(company_id, state)`.

#### `cash_custody_configuration_revisions`

| Column | Contract |
|---|---|
| `id` | UUID, PK, not null |
| `tenant_id` | UUID, not null, no FK |
| `company_id` | UUID, not null, FK `companies.id`, RESTRICT |
| `configuration_id` | UUID, not null, FK `cash_custody_configurations.id`, RESTRICT |
| `revision_number` | bigint, not null |
| `drawer_repository_id` | UUID, nullable, FK `payment_repositories.id`, RESTRICT |
| `safe_repository_id` | UUID, nullable, FK `payment_repositories.id`, RESTRICT |
| `bank_repository_id` | UUID, nullable, FK `payment_repositories.id`, RESTRICT |
| `currency` | char(3), not null |
| `topology_evidence` | jsonb, not null, default `'{}'::jsonb` |
| `created_by` | UUID, nullable, FK `users.id`, RESTRICT |
| `created_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |

Checks:

- `revision_number > 0`.
- `currency ~ '^[A-Z]{3}$'`.
- Repository IDs, when supplied, must be pairwise different.
- Service validates every repository belongs to the same tenant/company/location and currency.

Indexes/uniques:

- Unique `(company_id, configuration_id, revision_number)`.
- Unique `(company_id, configuration_id, id)`, supporting the head FK scope.
- Indexes on each repository ID.

JSONB DTO: `CashCustodyTopologyEvidenceData`.

While Q10–Q13 are OPEN, the command may publish only `state=disabled`.

### 7.3 Migration `2026_09_06_090200_create_repository_transfer_documents.php`

#### `repository_transfer_documents`

| Column | Contract |
|---|---|
| `id` | UUID, PK, not null |
| `tenant_id` | UUID, not null, no FK |
| `company_id` | UUID, not null, FK `companies.id`, RESTRICT |
| `location_id` | UUID, nullable, FK `locations.id`, RESTRICT |
| `transfer_group_id` | UUID, not null |
| `source_repository_id` | UUID, not null, FK `payment_repositories.id`, RESTRICT |
| `destination_repository_id` | UUID, not null, FK `payment_repositories.id`, RESTRICT |
| `amount` | numeric(20,6), not null |
| `currency` | char(3), not null |
| `source_kind` | varchar(64), not null |
| `source_key` | varchar(191), not null |
| `source_evidence` | jsonb, not null |
| `semantic_fingerprint` | char(64), not null |
| `shift_id` | UUID, nullable, FK `pos_shifts.id`, RESTRICT |
| `session_id` | UUID, nullable |
| `journal_entry_id` | UUID, nullable, FK `journal_entries.id`, RESTRICT |
| `reversal_of_document_id` | UUID, nullable, FK same table `id`, RESTRICT |
| `status` | varchar(16), not null, default `'draft'` |
| `authorized_by` | UUID, not null, FK `users.id`, RESTRICT |
| `occurred_at` | timestamptz, not null |
| `posted_at` | timestamptz, nullable |
| `voided_at` | timestamptz, nullable |
| `notes` | text, nullable |
| `created_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |
| `updated_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |

Checks:

- `amount > 0`.
- Currency and hash formats.
- Source and destination repositories differ.
- `status IN ('draft','posted','voided')`.
- Posted requires `posted_at`; voided requires `voided_at`.
- A reversal cannot reference itself.
- `session_id` is validated against v3 session evidence by the service because no policy-neutral server session table owns that identity.

Indexes/uniques:

- Unique `(company_id, transfer_group_id)`.
- Unique `(company_id, source_kind, source_key)`.
- Index `(company_id, status, occurred_at)`.
- Index `(company_id, shift_id, session_id)`.
- Index `(reversal_of_document_id)`.

JSONB DTO: `RepositoryTransferSourceEvidenceData`.

#### `repository_transfer_alerts`

| Column | Contract |
|---|---|
| `id` | UUID, PK, not null |
| `tenant_id` | UUID, not null, no FK |
| `company_id` | UUID, not null, FK `companies.id`, RESTRICT |
| `transfer_document_id` | UUID, not null, FK `repository_transfer_documents.id`, RESTRICT |
| `repository_id` | UUID, not null, FK `payment_repositories.id`, RESTRICT |
| `alert_kind` | varchar(32), not null |
| `evidence` | jsonb, not null |
| `created_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |
| `acknowledged_by` | UUID, nullable, FK `users.id`, RESTRICT |
| `acknowledged_at` | timestamptz, nullable |

Checks:

- `alert_kind IN ('frozen_override','checkpoint_override')`.
- Acknowledgement fields are both null or both non-null.

Indexes/uniques:

- Unique `(company_id, transfer_document_id, repository_id, alert_kind)`.
- Index `(company_id, acknowledged_at, created_at)`.

JSONB DTO: `RepositoryTransferAlertEvidenceData`.

#### Additions to `repository_movements`

| Column | Contract |
|---|---|
| `transfer_document_id` | UUID, nullable, FK `repository_transfer_documents.id`, RESTRICT |
| `shift_id` | UUID, nullable, FK `pos_shifts.id`, RESTRICT |
| `session_id` | UUID, nullable |

Indexes:

- `(transfer_document_id)`.
- `(company_id, shift_id, session_id)`.

### 7.4 Migration `2026_09_06_090300_create_shift_cash_booking_obligations.php`

#### `shift_cash_booking_obligations`

| Column | Contract |
|---|---|
| `id` | UUID, PK, not null |
| `tenant_id` | UUID, not null, no FK |
| `company_id` | UUID, not null, FK `companies.id`, RESTRICT |
| `location_id` | UUID, not null, FK `locations.id`, RESTRICT |
| `terminal_id` | UUID, not null, FK `pos_terminals.id`, RESTRICT |
| `shift_id` | UUID, nullable, FK `pos_shifts.id`, RESTRICT |
| `session_id` | UUID, nullable |
| `source_rail` | varchar(16), not null |
| `source_kind` | varchar(64), not null |
| `source_key` | varchar(191), not null |
| `source_occurred_at` | timestamptz, not null |
| `amount` | numeric(20,6), nullable |
| `currency` | char(3), nullable |
| `semantic_fingerprint` | char(64), not null |
| `raw_evidence_hash` | char(64), not null |
| `raw_evidence` | jsonb, not null |
| `policy_record_id` | UUID, nullable, FK `fiscal_projection_policy_records.id`, RESTRICT |
| `cutover_id` | UUID, nullable, FK `fiscal_projection_cutovers.id`, RESTRICT |
| `configuration_revision_id` | UUID, nullable, FK `cash_custody_configuration_revisions.id`, RESTRICT |
| `state` | varchar(24), not null, default `'pending'` |
| `blocked_reason_code` | varchar(64), nullable |
| `blocked_detail` | jsonb, nullable |
| `claim_token` | UUID, nullable |
| `lease_expires_at` | timestamptz, nullable |
| `attempt_count` | integer, not null, default `0` |
| `last_attempt_at` | timestamptz, nullable |
| `transfer_document_id` | UUID, nullable, FK `repository_transfer_documents.id`, RESTRICT |
| `applied_at` | timestamptz, nullable |
| `created_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |
| `updated_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |

Checks:

- Rail in `('v2','v3')`.
- Hash and currency formats.
- `attempt_count >= 0`.
- Not both policy and cutover.
- Claimed state requires claim token and lease; other terminal states have no live lease.
- Applied requires `transfer_document_id` and `applied_at`.
- Blocked/conflict requires `blocked_reason_code`.
- `training_no_money` forbids `transfer_document_id`.

Indexes/uniques:

- Unique `(company_id, source_rail, source_key)`.
- Unique `(company_id, semantic_fingerprint)`.
- Index `(company_id, state, lease_expires_at)`.
- Index `(company_id, shift_id, session_id)`.
- Index `(policy_record_id)`, `(cutover_id)`, `(configuration_revision_id)`.

JSONB DTOs:

- `ShiftCashRawEvidenceData`.
- `ShiftCashBlockedDetailData`.

While Q11/Q12 are OPEN, non-training financial facts terminate as `blocked/owner_ruling_required`; no transfer document is created.

### 7.5 Migration `2026_09_06_090400_create_pos_z_close_manifests.php`

#### Server `pos_z_close_manifests`

| Column | Contract |
|---|---|
| `id` | UUID, PK, not null |
| `tenant_id` | UUID, not null, no FK |
| `company_id` | UUID, not null, FK `companies.id`, RESTRICT |
| `location_id` | UUID, not null, FK `locations.id`, RESTRICT |
| `terminal_id` | UUID, not null, FK `pos_terminals.id`, RESTRICT |
| `shift_id` | UUID, not null, FK `pos_shifts.id`, RESTRICT |
| `session_id` | UUID, not null |
| `z_report_id` | UUID, not null, FK `pos_z_reports.id`, RESTRICT |
| `session_close_event_id` | UUID, not null, FK `fiscal_events.id`, RESTRICT |
| `z_report_event_id` | UUID, not null, FK `fiscal_events.id`, RESTRICT |
| `manifest_version` | smallint, not null, default `1` |
| `member_count` | integer, not null |
| `members` | jsonb, not null |
| `manifest_hash` | char(64), not null |
| `server_dependencies` | jsonb, not null, default `'{}'::jsonb` |
| `received_at` | timestamptz, not null |
| `created_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |

Checks:

- `manifest_version = 1`.
- `member_count >= 2`.
- SHA-256 format.
- Service verifies `member_count = jsonb_array_length(members)`.

Indexes/uniques:

- Unique `(company_id, z_report_id)`.
- Unique `(company_id, terminal_id, session_id)`.
- Unique `(company_id, manifest_hash)`.
- Index `(company_id, shift_id)`.

JSONB DTOs:

- `PosZCloseManifestMembersData`.
- `PosZServerDependencySnapshotData`.

Manifest membership is device-authored streams only. Server projections/dependencies go in `server_dependencies`, never in the signed device member set.

#### POS SQLite migration v68 in `apps/pos/src/lib/db/migrations.ts`

Create `z_close_manifests`:

| Column | Contract |
|---|---|
| `id` | TEXT PK, not null |
| `company_id` | TEXT, not null |
| `location_id` | TEXT, not null |
| `terminal_id` | TEXT, not null |
| `shift_id` | TEXT, not null |
| `session_id` | TEXT, not null |
| `z_report_id` | TEXT, not null |
| `session_close_event_id` | TEXT, not null |
| `z_report_event_id` | TEXT, not null |
| `manifest_version` | INTEGER, not null, default `1` |
| `member_count` | INTEGER, not null |
| `members_json` | TEXT, not null |
| `manifest_hash` | TEXT, not null |
| `sync_status` | TEXT, not null, default `'pending'` |
| `created_at` | TEXT, not null |

Checks:

- Version equals 1.
- `member_count >= 2`.
- `sync_status IN ('pending','syncing','synced','failed')`.

Uniques/indexes:

- Unique `(company_id, z_report_id)`.
- Unique `(company_id, terminal_id, session_id)`.
- Index `(sync_status, created_at)`.

#### POS SQLite migration v69

Add to the existing repository cache table:

| Column | Contract |
|---|---|
| `configuration_revision_id` | TEXT, nullable |
| `configuration_state` | TEXT, not null, default `'disabled'` |
| `topology_evidence_json` | TEXT, not null, default `'{}'` |

Check in typed decoder: state is `disabled`, `ready`, `active`, or `suspended`; this revision only accepts server-authored `disabled`.

### 7.6 Migration `2026_09_06_090500_create_cash_reconciliation_obligations.php`

#### `cash_count_delivery_obligations`

| Column | Contract |
|---|---|
| `id` | UUID, PK, not null |
| `tenant_id` | UUID, not null, no FK |
| `company_id` | UUID, not null, FK `companies.id`, RESTRICT |
| `cash_count_id` | UUID, not null, FK `cash_counts.id`, RESTRICT |
| `consumer` | varchar(32), not null |
| `state` | varchar(16), not null, default `'pending'` |
| `claim_token` | UUID, nullable |
| `lease_expires_at` | timestamptz, nullable |
| `attempt_count` | integer, not null, default `0` |
| `last_error` | jsonb, nullable |
| `completed_at` | timestamptz, nullable |
| `created_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |
| `updated_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |

Checks:

- Consumer in `('treasury','compliance','stored_event')`.
- State in `('pending','claimed','applied','blocked','dead')`.
- Attempt count non-negative.
- Claimed requires token/lease.
- Applied requires `completed_at`.

Indexes/uniques:

- Unique `(company_id, cash_count_id, consumer)`.
- Index `(company_id, consumer, state, lease_expires_at)`.

JSONB DTO: `CashCountDeliveryErrorData`.

#### `pos_session_reconciliation_obligations`

| Column | Contract |
|---|---|
| `id` | UUID, PK, not null |
| `tenant_id` | UUID, not null, no FK |
| `company_id` | UUID, not null, FK `companies.id`, RESTRICT |
| `location_id` | UUID, not null, FK `locations.id`, RESTRICT |
| `terminal_id` | UUID, not null, FK `pos_terminals.id`, RESTRICT |
| `shift_id` | UUID, not null, FK `pos_shifts.id`, RESTRICT |
| `session_id` | UUID, not null |
| `z_report_id` | UUID, not null, FK `pos_z_reports.id`, RESTRICT |
| `manifest_id` | UUID, not null, FK `pos_z_close_manifests.id`, RESTRICT |
| `cutover_id` | UUID, nullable, FK `fiscal_projection_cutovers.id`, RESTRICT |
| `state` | varchar(16), not null, default `'pending'` |
| `claim_token` | UUID, nullable |
| `lease_expires_at` | timestamptz, nullable |
| `attempt_count` | integer, not null, default `0` |
| `blocked_reason_code` | varchar(64), nullable |
| `blocked_detail` | jsonb, nullable |
| `current_run_id` | UUID, nullable; FK added after run table, RESTRICT |
| `created_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |
| `updated_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |

Checks:

- State in `('pending','claimed','completed','blocked','dead')`.
- Claimed requires token/lease.
- Completed requires `current_run_id`.
- Blocked requires reason.
- Attempt count non-negative.

Indexes/uniques:

- Unique `(company_id, z_report_id)`.
- Unique `(company_id, terminal_id, session_id)`.
- Index `(company_id, state, lease_expires_at)`.

JSONB DTO: `PosSessionReconciliationBlockedDetailData`.

#### `pos_session_reconciliation_runs`

| Column | Contract |
|---|---|
| `id` | UUID, PK, not null |
| `tenant_id` | UUID, not null, no FK |
| `company_id` | UUID, not null, FK `companies.id`, RESTRICT |
| `obligation_id` | UUID, not null, FK `pos_session_reconciliation_obligations.id`, RESTRICT |
| `run_number` | bigint, not null |
| `status` | varchar(24), not null |
| `fiscal_result` | jsonb, not null |
| `repository_result` | jsonb, not null |
| `lot_result` | jsonb, not null |
| `coverage_result` | jsonb, not null |
| `input_fingerprint` | char(64), not null |
| `created_by` | UUID, nullable, FK `users.id`, RESTRICT |
| `created_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |

Checks:

- `run_number > 0`.
- Status in `('matched','mismatch','incomplete','owner_ruling_required')`.
- Hash format.

Indexes/uniques:

- Unique `(company_id, obligation_id, run_number)`.
- Unique `(company_id, obligation_id, input_fingerprint)`.
- Index `(company_id, status, created_at)`.

JSONB DTOs:

- `FiscalReconciliationResultData`.
- `RepositoryReconciliationResultData`.
- `LotReconciliationResultData`.
- `ReconciliationCoverageResultData`.

#### `pos_session_reconciliation_supersessions`

| Column | Contract |
|---|---|
| `id` | UUID, PK, not null |
| `tenant_id` | UUID, not null, no FK |
| `company_id` | UUID, not null, FK `companies.id`, RESTRICT |
| `obligation_id` | UUID, not null, FK `pos_session_reconciliation_obligations.id`, RESTRICT |
| `superseded_run_id` | UUID, not null, FK `pos_session_reconciliation_runs.id`, RESTRICT |
| `replacement_run_id` | UUID, not null, FK `pos_session_reconciliation_runs.id`, RESTRICT |
| `reason` | text, not null |
| `created_by` | UUID, not null, FK `users.id`, RESTRICT |
| `created_at` | timestamptz, not null, default `CURRENT_TIMESTAMP` |

Checks:

- Superseded and replacement IDs differ.
- Service validates both runs belong to the same company and obligation.

Indexes/uniques:

- Unique `(company_id, superseded_run_id)`.
- Unique `(company_id, obligation_id, replacement_run_id)`.
- Index `(replacement_run_id)`.

The current reader selects a run only when no supersession row names it as `superseded_run_id`. Runs and supersessions are append-only.

### 7.7 Explicitly withheld migrations

These files must not be created while owner rulings remain OPEN:

- No custody interval/session/membership migration.
- No typed cash-operation mapping migration.
- No historical repository-alignment migration.
- No recall-release workflow migration.

---

## 8. State machines and public contracts

### 8.1 Common mutation result

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

Every mutator result carries:

```php
public function __construct(
    public readonly MutationOutcome $outcome,
    public readonly ?string $resourceId,
    public readonly ?string $reasonCode,
    public readonly array $evidence,
) {}
```

`already_exists` means the previous immutable effect has identical semantic meaning. `skipped` means no mutation was required for a declared reason. Neither may conceal compensating work.

### 8.2 Projection policy

Transitions:

- `pending → running`: atomic claim sets `claim_token`, `lease_expires_at`, increments `attempt_count`.
- `running → applied`: token match required; clears lease; sets immutable effect reference.
- `running → blocked`: token match required; sets reason/detail/time; clears lease.
- `running → pending`: recovery only when lease expired and claim token still matches.
- `blocked → pending`: only an explicit resolution or persisted cutover; current registry state is forbidden.
- Applied rows never return to pending.

Signatures:

```php
FiscalProjectionPolicyResolver::resolve(
    FiscalEvent $event,
    string $projectorName,
): FiscalProjectionPolicyDecision;

FiscalProjectionClaimService::claim(
    string $projectionId,
    string $workerId,
    CarbonImmutable $leaseExpiresAt,
): FiscalProjectionClaimResult;

FiscalProjectionClaimService::recoverExpired(
    string $tenantId,
    string $companyId,
    int $limit,
    bool $dryRun,
): FiscalProjectionRecoveryResult;
```

### 8.3 Custody configuration

Transitions:

- `disabled → disabled`: publish a new immutable topology revision; advance head by optimistic `lock_version`.
- `disabled → ready/active`: prohibited in this revision.
- `ready → active`, `active → suspended`, and `suspended → active`: defined structurally but prohibited until relevant owner supplement and activation review.
- Revisions are never updated or deleted.

Signatures:

```php
CashCustodyConfigurationService::publishDisabledRevision(
    PublishCashCustodyConfigurationData $data,
): CashCustodyConfigurationResult;

CashCustodyConfigurationService::findForLocation(
    string $tenantId,
    string $companyId,
    string $locationId,
): ?CashCustodyConfigurationData;
```

### 8.4 Transfer document

Transitions:

- `draft → posted`: exactly two repository legs and zero-or-one JE must exist in the same root transaction.
- `draft → voided`: allowed only before either leg exists.
- Posted documents are immutable.
- Cancellation of a posted document creates a new posted reversal referencing the original.
- Replay returns `already_exists`; mismatched semantic input returns `conflict`.

Signature:

```php
RepositoryTransferDocumentService::transfer(
    RepositoryTransferData $data,
): RepositoryTransferDocumentResult;

RepositoryTransferDocumentService::reverse(
    ReverseRepositoryTransferData $data,
): RepositoryTransferDocumentResult;
```

The existing scalar `RepositoryTransferService::transfer()` remains as a compatibility façade and delegates to the document service.

### 8.5 Shift cash booking obligation

Transitions:

- `pending → claimed`.
- `claimed → applied`, `training_no_money`, `blocked`, or `conflict`.
- `claimed → pending` only after lease expiry and token match.
- `blocked → pending` only after explicit persisted policy resolution.
- Applied and training terminal states are immutable.

While Q11/Q12 are OPEN:

- Opening and ambiguous operation facts become `blocked/owner_ruling_required`.
- Literal training facts become `training_no_money`.
- No transfer document, movement, balance update, or JE is created.

Signatures:

```php
ShiftCashBookingService::recordEvidence(
    ShiftCashBookingIntent $intent,
): ShiftCashBookingResult;

ShiftCashBookingService::claim(
    string $obligationId,
    string $workerId,
    CarbonImmutable $leaseExpiresAt,
): ShiftCashBookingClaimResult;

ShiftCashBookingService::process(
    ShiftCashBookingClaim $claim,
): ShiftCashBookingResult;
```

### 8.6 Cash-count delivery

Transitions:

- `pending → claimed → applied`.
- `claimed → pending` on expired lease and token match.
- `claimed → blocked/dead` with structured error evidence.
- One obligation exists per `(company, cash count, consumer)`.

Signature:

```php
CashCountDispatcher::dispatch(
    CashCountRecorded $event,
): CashCountDispatchResult;
```

Flag false: retain current `Event::dispatch($event)` behavior, including Treasury, Compliance, and Spatie storage.

Flag true:

1. Insert all three obligations in the cash-count transaction.
2. After commit, enqueue delivery jobs.
3. Treasury consumer invokes the existing Treasury handler.
4. Compliance consumer invokes `OpenFraudAlertForShiftVariance`.
5. Stored-event consumer persists `CashCountRecorded` through the Spatie stored-event repository exactly once.
6. Do not additionally dispatch the Laravel event.

### 8.7 Reconciliation

Transitions:

- Obligation `pending → claimed → completed/blocked`.
- Expired claim returns to pending by matching token.
- Every execution appends a run.
- A different input fingerprint appends a replacement run plus supersession; it never updates the previous run.
- An identical fingerprint returns `already_exists`.
- `RepositoryCashCoverageService` is pure.
- `PosSessionReconciliationService` is the sole reconciliation writer.

Signature:

```php
PosSessionReconciliationService::reconcile(
    ReconcilePosSessionData $data,
): PosSessionReconciliationResult;

RepositoryCashCoverageService::calculate(
    RepositoryCashCoverageInput $input,
): RepositoryCashCoverageResult;
```

---

## 9. Complete Artisan command signatures

```text
treasury:w-cash-audit
    {--tenant=* : Tenant UUID or slug; repeatable}
    {--company=* : Company UUID; repeatable}
    {--format=json : json or table}
    {--include-optional : Probe future tables only when present}
    {--require-schema= : Required terminal migration name}
    {--fail-on-drift : Exit non-zero on missing/ambiguous configuration or schema}

fiscal:projection-cutover:create
    {--tenant= : Required tenant UUID or slug}
    {--company= : Required company UUID}
    {--location= : Required location UUID}
    {--terminal= : Required terminal UUID}
    {--projector= : Required projector name}
    {--rail= : Required v2 or v3}
    {--lower-bound= : Required inclusive lower source identity}
    {--upper-bound= : Required inclusive upper source identity}
    {--decision=block : project, block, or training_no_money}
    {--policy-revision-key= : Required SHA-256}
    {--evidence-file= : Required JSON file}
    {--created-by= : Required user UUID}
    {--format=json : json or table}
    {--dry-run : Validate without writing}

treasury:w-cash-configure
    {--tenant= : Required tenant UUID or slug}
    {--company= : Required company UUID}
    {--location= : Required location UUID}
    {--drawer= : Nullable drawer repository UUID}
    {--safe= : Nullable safe repository UUID}
    {--bank= : Nullable bank repository UUID}
    {--expected-head-revision= : Required current revision number, or 0 for create}
    {--state=disabled : disabled only until owner supplement}
    {--evidence-file= : Required JSON evidence file}
    {--created-by= : Required user UUID}
    {--format=json : json or table}
    {--dry-run : Validate without writing}

treasury:w-cash-catch-up-v2
    {--tenant= : Required tenant UUID or slug}
    {--company= : Required company UUID}
    {--cutover= : Required cutover UUID}
    {--from-id= : Required inclusive source ID}
    {--to-id= : Required inclusive source ID}
    {--limit=500 : Maximum rows in this invocation}
    {--dry-run : Report without enqueueing}
    {--enqueue : Create obligations and enqueue}
```

Exactly one of `--dry-run` or `--enqueue` is required.

```text
pos:cash-count-recover
    {--tenant= : Required tenant UUID or slug}
    {--company= : Required company UUID}
    {--consumer=* : treasury, compliance, or stored_event; repeatable}
    {--state=pending : pending, claimed, blocked, or dead}
    {--older-than= : Required ISO-8601 timestamp}
    {--limit=500 : Maximum obligations}
    {--dry-run : Report without mutation}
    {--enqueue : Claim and enqueue}
```

Exactly one of `--dry-run` or `--enqueue` is required.

```text
pos:reconcile-sessions
    {--tenant= : Required tenant UUID or slug}
    {--company= : Required company UUID}
    {--z-report=* : Z report UUID; repeatable}
    {--from= : Inclusive ISO-8601 close time}
    {--to= : Inclusive ISO-8601 close time}
    {--cutover= : Optional bounded cutover UUID}
    {--limit=100 : Maximum sessions}
    {--dry-run : Report without appending runs}
    {--enqueue : Claim and enqueue}
```

Require either at least one `--z-report` or both `--from` and `--to`; exactly one of `--dry-run` or `--enqueue`.

No historical-alignment command may be added before Q13 is resolved.

---

## 10. Task dispatch contracts

Each task begins red-first, stops for the named reviewer, and may not absorb later tasks.

### T0 — capability-aware census

**HEAD files to modify/read**

- `apps/api/app/Console/Kernel.php`
- `apps/api/app/Modules/Treasury/Domain/PaymentRepository.php`
- `apps/api/app/Modules/POS/Domain/CashDrawerOperation.php`
- `apps/api/app/Modules/Fiscal/Domain/FiscalEvent.php`
- `apps/api/tests/Architecture/Support/TenantOnlyUniqueIndexScanner.php`

**New files to create**

- `apps/api/app/Modules/Treasury/Presentation/Console/WCashAuditCommand.php`
- `apps/api/tests/Feature/Treasury/WCashAuditCommandTest.php`

**Contract**

Implement `treasury:w-cash-audit` exactly as §9. Every optional table is guarded with `Schema::hasTable()`. JSON output includes tenant/company/location/terminal counts, v2/v3 fact counts by kind, repository topology, frozen/checkpoint state, unprojected ranges, missing schema names, and `dispatchable: false|true`.

**Red-first**

- Test: `WCashAuditCommandTest::test_pre_migration_schema_returns_capability_report_without_querying_future_tables`
- First failing assertion: `assertSame(0, $this->artisan('treasury:w-cash-audit', ['--format' => 'json'])->run())`
- Command/lane:

```bash
cd apps/api
DB_CONNECTION=pgsql DB_DATABASE=autoerp_test_wcash php artisan test --filter=WCashAuditCommandTest
```

Lane: PostgreSQL `treasury-spine-pgsql`.

Rerun test asserts the second execution returns `skipped` for absent optional probes and byte-equivalent facts.

**Reviewer gate:** treasury + tenancy-authz accept census completeness before any migration.

**Rollback:** command is read-only; remove only before promotion. No data rollback.

### T1 — additive schemas and device v68→v69

**HEAD files to modify**

- `apps/pos/src/lib/db/migrations.ts`
- `apps/pos/src/lib/db/__tests__/helpers/migrationTestHelpers.ts`
- `apps/api/tests/Architecture/Support/TenantOnlyUniqueIndexScanner.php`

**New files to create**

- Six tenant migrations named exactly as §7.
- `apps/api/tests/Feature/Treasury/WCashSchemaContractTest.php`
- `apps/pos/src/lib/db/__tests__/migrations.v68.test.ts`
- `apps/pos/src/lib/db/__tests__/migrations.v69.test.ts`

**Contract**

Implement §7 exactly, with v68 before v69. Add every new business unique to the actual tenant-only unique scanner fixture.

**Red-first**

- `WCashSchemaContractTest::test_every_w_cash_table_has_company_scoped_business_uniques`
- First assertion: scanner result contains zero violations.
- `migrations.v68.test.ts::creates manifest table before v69 repository columns`
- First assertion: `expect(tableNames).toContain('z_close_manifests')`.
- Commands:

```bash
cd apps/api
DB_CONNECTION=pgsql DB_DATABASE=autoerp_test_wcash php artisan test --filter=WCashSchemaContractTest
pnpm --filter @autoerp/pos test -- src/lib/db/__tests__/migrations.v68.test.ts src/lib/db/__tests__/migrations.v69.test.ts
```

Lanes: PostgreSQL `treasury-spine-pgsql`; POS Vitest.

Rerun assertion: migration second pass reports `already_exists`/no pending migration and schema hashes remain unchanged.

**Reviewer gate:** treasury + tenancy-authz + fiscal-pos approve the exact DDL before T2.

**Rollback:** before any tenant migration, migration files may be removed. After staging, schema is forward-only and retained with flags false; do not run destructive `down()` against tenant data.

### T2 — immutable event-time policy and bounded cutovers

**HEAD files to modify**

- `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php`
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php`
- `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php`
- `apps/api/app/Console/Kernel.php`

**New files to create**

- Policy/cutover DTOs and services under `apps/api/app/Modules/Fiscal/Application/`.
- `apps/api/app/Modules/Fiscal/Presentation/Console/CreateProjectionCutoverCommand.php`
- `apps/api/tests/Feature/Fiscal/WCashEventTimePolicyTest.php`

**Contract**

Implement §8.2 and the cutover command. Recovery must resolve from the immutable policy record or bounded cutover only. For W-CASH, the command rejects `decision=project` while Q11/Q12 remain OPEN and returns `blocked/owner_ruling_required`.

**Red-first**

- `WCashEventTimePolicyTest::test_recovery_does_not_reinterpret_legacy_event_from_current_registry`
- First assertion: `assertSame('blocked', $projection->refresh()->status)`.
- Rerun test asserts `MutationOutcome::AlreadyExists` and unchanged policy/cutover IDs.
- Command:

```bash
cd apps/api
DB_CONNECTION=pgsql DB_DATABASE=autoerp_test_wcash php artisan test --filter=WCashEventTimePolicyTest
```

Lane: PostgreSQL `fiscal-pos`.

**Reviewer gate:** fiscal-pos + treasury verify current-state recovery is impossible.

**Rollback:** disable recovery/catch-up invocation and return readers to the previous dispatcher; retain policy/cutover evidence.

### T3 — disabled topology configuration and atomic provisioning

**HEAD files to modify**

- `apps/api/app/Shared/Contracts/Treasury/CompanyPaymentRepositoryProvisionerInterface.php`
- `apps/api/app/Shared/Contracts/Treasury/LocationCashRegisterProvisionerInterface.php`
- `apps/api/app/Modules/Treasury/Application/Services/PaymentRepositoryProvisioningService.php`
- `apps/api/app/Modules/Treasury/Application/Services/LocationCashRegisterProvisioner.php`
- `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php`
- `apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php`
- `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php`
- `apps/api/app/Console/Kernel.php`

**Changed shared signatures**

```php
CompanyPaymentRepositoryProvisionerInterface::provisionForCompany(
    string $tenantId,
    string $companyId,
    ?string $defaultLocationId = null,
): CashCustodyProvisioningResult;

LocationCashRegisterProvisionerInterface::provision(
    string $tenantId,
    string $companyId,
    string $locationId,
    ?string $locationCode,
): CashCustodyProvisioningResult;
```

Both may throw `CashCustodyProvisioningException`. The current catch-and-swallow contract is removed.

**Transaction contract**

- Company creation: the existing CompanyController outer transaction owns company, default location, repositories, and disabled configuration.
- Location create/update: add one outer transaction owning the location write, drawer provisioning, and disabled configuration.
- Any repository or configuration failure rolls back the whole company/location change.
- Registration uses the same fail-closed contract.
- Retry after a committed result returns `already_exists`.
- Retry after a rolled-back failure applies once.

**Red-first**

- `WCashProvisioningAtomicityTest::test_company_rolls_back_when_disabled_configuration_write_fails`
- First assertion: `assertDatabaseMissing('companies', ['id' => $companyId])`.
- `WCashProvisioningAtomicityTest::test_location_rolls_back_when_drawer_write_fails`
- First assertion: `assertDatabaseMissing('locations', ['id' => $locationId])`.
- `WCashProvisioningAtomicityTest::test_retry_returns_already_exists_without_duplicate_repositories`
- First assertion: `assertSame(MutationOutcome::AlreadyExists, $second->outcome)`.
- Command:

```bash
cd apps/api
DB_CONNECTION=pgsql DB_DATABASE=autoerp_test_wcash php artisan test --filter=WCashProvisioningAtomicityTest
```

Lane: PostgreSQL `treasury-spine-pgsql`.

**Reviewer gate:** company/tenancy + treasury explicitly approve the deliberate fail-closed behavior change.

**Rollback:** before activation, revert callers/interfaces together. Retain any committed disabled configuration revisions; never delete operator evidence.

### T4 — immutable transfer documents and lock order

**HEAD files to modify**

- `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php`
- `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php`
- `apps/api/app/Modules/Treasury/Presentation/routes.php`
- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`
- `apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php`

**New files to create**

- Transfer document/model/DTO/service/result/alert classes under existing Treasury tiers.
- `apps/api/tests/Feature/Treasury/WCashTransferDocumentTest.php`
- `apps/api/tests/Feature/Treasury/WCashLockOrderTest.php`
- `apps/api/tests/Feature/Treasury/TreasuryModuleGateTest.php`

**Contract**

- Gate the existing transfer route with Treasury module middleware.
- One transfer document/group.
- Exactly two movement legs.
- Zero JE for same-GL; exactly one JE for cross-GL.
- Currency explicit on intent and document.
- Optional shift/session evidence is company/location validated.
- Frozen/checkpoint overrides create one durable alert, including replay.
- Cancellation is reversal.
- Lock order is §6.2.

**Red-first**

- `WCashTransferDocumentTest::test_replay_returns_already_exists_and_keeps_two_legs`
- First assertion: `assertSame('already_exists', $second->outcome->value)`.
- `WCashLockOrderTest::test_opposite_transfers_and_unrelated_journal_entry_do_not_deadlock`
- First assertion: `assertNull($capturedSqlState40P01)`.
- `WCashLockOrderTest::test_inventory_gl_buffer_and_w_cash_transfer_preserve_global_order`
- First assertion: both worker results equal `applied`.
- `TreasuryModuleGateTest::test_module_off_refuses_existing_transfer_and_configuration_routes`
- First assertion: `assertSame(403, $response->status())`.
- Command:

```bash
cd apps/api
DB_CONNECTION=pgsql DB_DATABASE=autoerp_test_wcash php artisan test --filter='WCashTransferDocumentTest|WCashLockOrderTest|TreasuryModuleGateTest'
```

Lane: PostgreSQL `treasury-spine-pgsql`, with stock-gl-interaction review.

**Reviewer gate:** treasury + accounting + stock-gl-interaction approve cardinality and concurrency.

**Rollback:** set W-CASH flags false and route new callers back through the compatibility façade. Posted documents/movements/JEs stay immutable; correct financial effects only through reversal.

### T5 — policy-neutral v2/v3 evidence bridge

**HEAD files to modify**

- `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php`
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php`
- `apps/api/app/Modules/POS/Domain/CashDrawerOperation.php`
- `apps/api/config/treasury.php`

**New files to create**

- `ShiftCashBookingService`, rail adapters, fingerprint class, DTOs, job, and catch-up command under existing POS/Fiscal/Treasury tiers.
- `apps/api/tests/Feature/Treasury/ShiftCashBookingBridgeTest.php`

**Contract**

- Persist evidence and obligations only.
- No money while Q11/Q12 are OPEN.
- v2 and v3 equivalent facts produce equivalent normalized payloads, not necessarily the same source identity.
- Same source replay returns `already_exists`.
- Cross-shift or altered content under the same source key returns `conflict`.
- Training true returns `skipped/training_no_money`.
- Module-off returns `skipped/module_disabled`.
- Worker crash after any committed stage converges through immutable IDs.

**Red-first**

- `ShiftCashBookingBridgeTest::test_cross_shift_source_reuse_is_conflict`
- First assertion: `assertSame('conflict', $second->outcome->value)`.
- `ShiftCashBookingBridgeTest::test_v2_and_v3_evidence_are_normalized_without_financial_effect`
- First assertion: `assertSame('owner_ruling_required', $result->reasonCode)`.
- `ShiftCashBookingBridgeTest::test_training_boolean_true_returns_skipped_no_money`
- First assertion: `assertSame('skipped', $result->outcome->value)`.
- Command:

```bash
cd apps/api
DB_CONNECTION=pgsql DB_DATABASE=autoerp_test_wcash php artisan test --filter=ShiftCashBookingBridgeTest
```

Lane: PostgreSQL `fiscal-pos` + `treasury-spine-pgsql`.

**Reviewer gate:** no movements exist for opening/ambiguous operations while Q11/Q12 remain OPEN.

**Rollback:** set `TREASURY_W_CASH_BOOKING_ENABLED=false`; retain obligations and raw evidence.

### T6 — POS repository DTO and disabled topology evidence

**HEAD files to modify**

- `apps/pos/src/types/payment.ts`
- `apps/pos/src/lib/db/repositories/paymentRepository.ts`
- `apps/pos/src/lib/db/migrations.ts`
- `apps/pos/tsconfig.json`
- Existing repository sync/terminal configuration consumers discovered by T0.

**New files to create**

- `apps/pos/src/lib/db/repositories/paymentRepositoryDecoder.ts`
- `apps/pos/src/lib/db/repositories/__tests__/paymentRepositoryDecoder.test.ts`
- Generated-type compatibility test under `apps/pos/src/types/__tests__/`.

**Contract**

- Use generated `PaymentRepositoryData` as the boundary entity.
- Preserve `PaymentRepositoryRow` only for SQLite.
- Cache server-authored disabled topology revision evidence.
- Do not add typed cash operation choices or mappings.
- Existing raw device operations remain unchanged.
- A non-disabled configuration received before owner authorization is rejected and recorded as sync error.

**Red-first**

- `paymentRepositoryDecoder.test.ts::rejects row whose repository type diverges from generated DTO`
- First assertion: `expect(() => decodePaymentRepositoryRow(row)).toThrow(RepositoryRowDecodeError)`.
- `paymentRepositoryDecoder.test.ts::returns generated repository data`
- First assertion: `expect(decoded).toSatisfyTypeOf<PaymentRepositoryData>()`.
- Command:

```bash
pnpm --filter @autoerp/pos test -- src/lib/db/repositories/__tests__/paymentRepositoryDecoder.test.ts
pnpm --filter @autoerp/pos typecheck
```

Lane: POS Vitest/typecheck.

**Reviewer gate:** fiscal-pos + frontend conventions confirm there is one cross-boundary repository type.

**Rollback:** ignore v69 columns in the old decoder; SQLite additive columns remain.

### T7 — atomic close manifest

**HEAD files to modify**

- `apps/pos/src/lib/offline/zReportService.ts`
- `apps/pos/src/lib/fiscal/zSessionAuthoring.ts`
- `apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php`
- `apps/api/app/Modules/POS/routes.php`

**New files to create**

- Device manifest builder/repository and tests.
- Server manifest DTO/model/ingestor.
- `apps/api/tests/Feature/POS/ZCloseManifestIngestTest.php`.

**Required order inside the existing SQLite transaction**

1. Persist local Z/count material and advance relevant local sequence totals.
2. Call `appendZSessionCloseAndZReport()`.
3. Receive close and Z event IDs/hashes.
4. Build canonical device-only manifest including those returned identities.
5. Insert manifest.
6. Insert/update local sync outbox for Z and manifest.
7. Commit once.

Any throw at steps 1–6 rolls back all local writes.

**Red-first**

- POS: `zReportService.manifest.test.ts::rolls_back close z manifest and outbox together`
- First assertion: `expect(await manifestRepository.count()).toBe(0)`.
- API: `ZCloseManifestIngestTest::test_duplicate_manifest_returns_already_exists`
- First assertion: `assertSame('already_exists', $second->outcome->value)`.
- Commands:

```bash
pnpm --filter @autoerp/pos test -- src/lib/offline/zReportService.manifest.test.ts
cd apps/api
DB_CONNECTION=pgsql DB_DATABASE=autoerp_test_wcash php artisan test --filter=ZCloseManifestIngestTest
```

Lanes: POS Vitest; PostgreSQL `fiscal-pos`.

**Reviewer gate:** fiscal-pos approves membership, construction order, raw hashes, and server dependency separation.

**Rollback:** old clients continue syncing Z without a manifest; server readers tolerate missing manifests and report `incomplete`. Never forge historical manifests.

### T8 — durable cash-count fan-out

**HEAD files to modify**

- `apps/api/app/Modules/POS/Application/Services/CashCountDispatcher.php`
- `apps/api/app/Modules/POS/Domain/Events/CashCountRecorded.php`
- `apps/api/app/Modules/Compliance/Listeners/OpenFraudAlertForShiftVariance.php`
- Existing POS event/provider registration.
- `apps/api/config/treasury.php`

**New files to create**

- Delivery consumer interface and three consumers.
- Delivery job/recovery command/result DTOs.
- `apps/api/tests/Feature/POS/CashCountDurableDispatchTest.php`.

**Contract**

- Flag false: current `Event::dispatch()` and all three effects remain.
- Flag true: transactionally create Treasury, Compliance, and `stored_event` obligations; jobs claim independently.
- Stored-event consumer calls the Spatie stored-event repository once.
- Partial success leaves only failed consumers retryable.
- Re-enqueue returns `already_exists` for applied consumers.
- Enqueue failure cannot lose the obligation.

**Red-first**

- `CashCountDurableDispatchTest::test_flag_true_persists_three_consumer_obligations`
- First assertion: `assertDatabaseCount('cash_count_delivery_obligations', 3)`.
- `CashCountDurableDispatchTest::test_stored_event_consumer_persists_pos_cash_count_recorded_once`
- First assertion: stored-event count equals one.
- `CashCountDurableDispatchTest::test_flag_false_preserves_current_treasury_compliance_and_storage_effects`
- First assertion: all three current effects exist.
- Command:

```bash
cd apps/api
DB_CONNECTION=pgsql DB_DATABASE=autoerp_test_wcash php artisan test --filter=CashCountDurableDispatchTest
```

Lane: PostgreSQL `fiscal-pos` + `treasury-spine-pgsql`.

**Reviewer gate:** POS + Treasury + Compliance approve all three consumers and false-flag parity.

**Rollback:** set `TREASURY_CASH_COUNT_DISPATCH_ENABLED=false`; existing event path resumes. Retain pending obligations for later audited recovery; do not double-dispatch them.

### T9 — policy-neutral W7 reconciliation

**HEAD files to modify**

- `apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php` only for integration, not expected-value arithmetic.
- `apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php`
- `apps/api/app/Console/Kernel.php`

**New files to create**

- Reconciliation DTOs/services/readers/job/command under POS.
- `apps/api/tests/Feature/POS/W7SessionReconciliationTest.php`.

**Prerequisite stop**

Do not begin until T2, T5, T7, T8, spec W2 classification, spec W4 source completeness, and the W-LOT status interface are present. Q10–Q13 may remain OPEN only because dependent result cells are explicitly `owner_ruling_required`; no branch is selected.

**Acceptance matrix**

| Case | Required result |
|---|---|
| Tax totals independently derived | `matched` or exact mismatch evidence |
| Refund inclusion/exclusion | Matches sealed manifest and current fiscal rules |
| Tender totals | Derived from immutable authored classification only |
| Account/customer-credit legs | Reported separately from money tenders |
| Manifest missing/incomplete | `incomplete`, never inferred from time/count alone |
| Legacy fact without policy/cutover | `owner_ruling_required` |
| Lot-enabled result while Q10 OPEN | Lot cell `owner_ruling_required` |
| Repository comparison while Q11/Q12 OPEN | Repository cell `owner_ruling_required` |
| Historical alignment while Q13 OPEN | Coverage cell `owner_ruling_required` |
| Second company | No result or run crosses company scope |
| Second location | No repository/configuration is borrowed |
| Rerun identical input | `already_exists`, same current run |
| Corrected input | New immutable run plus supersession |
| Variance GL flag | Remains false; no adjustment produced |

**Red-first**

- `W7SessionReconciliationTest::test_repository_comparison_is_owner_ruling_required_without_encoding_open_policy`
- First assertion: `assertSame('owner_ruling_required', $run->repositoryResult->status)`.
- `W7SessionReconciliationTest::test_identical_rerun_returns_already_exists`
- First assertion: `assertSame('already_exists', $second->outcome->value)`.
- `W7SessionReconciliationTest::test_changed_input_appends_run_and_supersession`
- First assertion: `assertDatabaseCount('pos_session_reconciliation_runs', 2)`.
- Command:

```bash
cd apps/api
DB_CONNECTION=pgsql DB_DATABASE=autoerp_test_wcash php artisan test --filter=W7SessionReconciliationTest
```

Lane: PostgreSQL `fiscal-pos` + `treasury-spine-pgsql`.

**Reviewer gate:** fiscal-pos + treasury + W-LOT reviewer accept every matrix row. No repository comparison or variance posting is accepted while its ruling is OPEN.

**Rollback:** stop the reconciliation job/command and retain immutable obligations, runs, and supersessions. Existing Z projection remains authoritative.

### T10 — operator surfaces and generated web DTOs

**HEAD files to modify**

- All web repository consumers listed in §5.3.
- Existing Treasury repository list/detail/transfer UI and shift/Z detail surfaces.
- Existing i18n namespaces used by those surfaces.

**New tests**

- `apps/web/src/features/treasury/pages/RepositoryDetailPage.wCash.test.tsx`
- `apps/web/src/features/pos/components/WCashCoverageStatus.test.tsx`
- `apps/web/e2e/w-cash-coverage.spec.ts`

**Contract**

Surfaces show:

- Disabled topology revision ID and evidence status.
- Transfer document/group, source evidence, shift/session attribution, two legs, JE link, reversal lineage.
- Frozen/checkpoint alert.
- Manifest status.
- Reconciliation status with explicit `Owner ruling required`.
- No UI to activate configuration, classify cash operations, manage shared drawers, align balances, or release recalls.
- Bundle feature fingerprint string: `w-cash-r3-owner-ruling-required`.

**Red-first**

- `RepositoryDetailPage.wCash.test.tsx::renders transfer document and reversal lineage`
- First assertion: `expect(screen.getByText(/transfer document/i)).toBeInTheDocument()`.
- `WCashCoverageStatus.test.tsx::renders owner ruling required without an enable action`
- First assertion: `expect(screen.queryByRole('button', { name: /enable/i })).not.toBeInTheDocument()`.
- Playwright: `w-cash-coverage.spec.ts::manager sees blocked coverage and immutable evidence`
- First assertion: blocked status is visible after navigation.
- Commands:

```bash
pnpm --filter @autoerp/web test -- src/features/treasury/pages/RepositoryDetailPage.wCash.test.tsx src/features/pos/components/WCashCoverageStatus.test.tsx
pnpm --filter @autoerp/web typecheck
pnpm --filter @autoerp/web lint
pnpm --filter @autoerp/web test:e2e -- e2e/w-cash-coverage.spec.ts
```

Lanes: web Vitest/typecheck/lint and Playwright.

**Reviewer gate:** frontend conventions + treasury + design/product approve explicit blocked language and absence of premature controls. Run the repository’s React diagnostic workflow before acceptance.

**Rollback:** deploy the prior web SHA. API readers remain backward compatible and flags remain false.

### T11 — owner supplement, withheld

T11 is not dispatchable work. After explicit rulings, a separate reviewed supplement must define only the selected branches:

- Q10 recall/release.
- Q11 drawer custody session, membership, join/refusal, count owner, predecessor/intervening-transfer treatment, and opening transfer.
- Q12 operation classification and destination accounting.
- Q13 alignment and variance activation.

That supplement must add its own benchmark delta, schema, state machine, tests, migration rollout, and rollback. It must not be inferred from the recommendations in §3.

### T12 — campaign and promotion evidence

**HEAD files to modify only if contract changes are required**

- `scripts/campaign-onboarding.sh`
- `apps/api/app/Modules/Tenant/Application/Commands/DayOneCensusCommand.php`
- `docs/factory/WORKFLOW.md`
- `.env.example`
- `docker-compose.staging.yml`
- `apps/api/docker/entrypoint.sh`

**New tests**

- `apps/api/tests/Feature/Tenant/WCashDayOneCensusTest.php`
- `apps/web/e2e/w-cash-campaign.spec.ts`

**Red-first**

- `WCashDayOneCensusTest::test_two_companies_two_locations_two_terminals_are_reported_independently`
- First assertion: JSON has four distinct company/location pairs and no borrowed repository.
- `w-cash-campaign.spec.ts::fresh_bundle_exposes_w_cash_feature_fingerprint`
- First assertion: served bundle contains `w-cash-r3-owner-ruling-required`.
- Commands:

```bash
cd apps/api
DB_CONNECTION=pgsql DB_DATABASE=autoerp_test_wcash php artisan test --filter=WCashDayOneCensusTest
pnpm --filter @autoerp/web test:e2e -- e2e/w-cash-campaign.spec.ts
```

Lane: PostgreSQL tenant campaign + Playwright.

Manual device evidence is a promotion check, not a red-first unit test:

- Build the actual Tauri artifact.
- Use real SQLite.
- Open/close offline, crash before close commit, restart, reconnect, sync.
- Verify one close, one Z, one manifest, no partial outbox, and blocked/no-money W-CASH obligations.
- Verify printer output remains unchanged.
- Record artifact hash and SQLite evidence.

**Reviewer gate:** release + treasury + fiscal-pos sign the campaign, census, backup/restore, and physical-device report.

**Rollback:** flags false, previous web/API SHA redeployed, disabled configuration revision appended if necessary, immutable evidence retained.

---

## 11. Environment and entrypoint contract

Rollout variables:

```text
TREASURY_W_CASH_BOOKING_ENABLED=false
TREASURY_CASH_COUNT_DISPATCH_ENABLED=false
TREASURY_SHIFT_VARIANCE_GL_ENABLED=false
```

Required changes:

- Add all three to the shared `x-api-env` block in `docker-compose.staging.yml`.
- Because API, worker, scheduler, and websocket inherit that block, verify each rendered service receives identical values.
- Add the variables to `.env.example`.
- Add validation near the start of `apps/api/docker/entrypoint.sh`, before configuration caching:
  - Missing value defaults to `false`.
  - Only literal `true` or `false` is accepted.
  - Invalid value exits non-zero.
  - Print names and normalized values, never secrets.
- Change central and rolling migration failures in the entrypoint to exit non-zero. It may not continue serving after a failed tenant migration.
- Worker/scheduler containers must fail the same validation before starting.

Configuration readers use `config('treasury.w_cash_booking_enabled')`, `config('treasury.cash_count_dispatch_enabled')`, and the existing variance key. Runtime code must not call `env()`.

---

## 12. Five-push staging manifest

No push is authorized by this plan; this is the execution contract once implementation and reviews are accepted. Every promotion is a clean fast-forward to `origin/dev`; never force-push.

### Shared preflight before every push

```bash
git fetch origin dev
git merge-base --is-ancestor origin/dev dev
git status --short
pnpm build
pnpm lint
pnpm test
pnpm typecheck
cd apps/api
composer test
./vendor/bin/phpstan
```

Record candidate SHA, test logs, reviewer acceptance, current API deployment ID, current Dokploy web deployment ID, served asset hash, and tenant backup IDs.

### Push 1 — T0 census only

Content: read-only audit command and tests.

After promotion:

```bash
cd apps/api
php artisan treasury:w-cash-audit --format=json --fail-on-drift
```

Expected: command succeeds against pre-migration schema and reports optional tables absent.

**Rollback point:** prior API SHA. No database change.

### Push 2 — schemas only

Content: T1 migrations and compatibility readers; all flags false.

Before migration, capture backups:

```bash
php artisan tenant:backup "$WCASH_TENANT_SLUG"
```

Run central migration, then the supported rolling tenant command:

```bash
DB_HOST="$DIRECT_DB_HOST" php artisan migrate --force
DB_HOST="$DIRECT_DB_HOST" php artisan tenants:migrate-rolling --force | tee /tmp/wcash-rolling-migrations.log
```

Verification is mandatory:

- Exit code is zero.
- Output contains one terminal success/no-op result for every active tenant.
- Output contains no `FAILED`, `ERROR`, or incomplete tenant.
- Final migrated + already-current count equals the active-tenant count from T0.
- Run:

```bash
php artisan treasury:w-cash-audit \
  --format=json \
  --require-schema=2026_09_06_090500_create_cash_reconciliation_obligations \
  --fail-on-drift
```

The staging entrypoint’s log alone is not proof.

**Rollback point:** Push-1 code SHA with additive schema retained. Do not migrate down on staging.

### Push 3 — dormant backend/device/web implementation

Content: T2–T10 code, DTOs, routes, flags, UI, device v68/v69; flags remain false.

Because this push touches web, explicitly deploy Dokploy application `mY6P_PHb4pw-2LdG1Y7Ml`. Record the returned deployment ID and wait for success.

Web freshness:

1. Fetch served HTML and resolve its current JS asset names.
2. Record SHA-256 for each served entry asset.
3. Assert at least one entry hash changed from Push 2.
4. Fetch the served bundle and assert it contains `w-cash-r3-owner-ruling-required`.
5. Run:

```bash
pnpm --filter @autoerp/web test:e2e -- e2e/w-cash-coverage.spec.ts
```

Also verify API/worker/scheduler/websocket see all three flags as false.

**Rollback point:** disable flags, redeploy Push-2 API and web SHAs. Retain schema and evidence rows.

### Push 4 — disabled revisions, bounded cutovers, campaign evidence

Create only disabled configuration revisions. Capture output before using IDs:

```bash
WCASH_CONFIG_JSON="$(
  php artisan treasury:w-cash-configure \
    --tenant="$WCASH_TENANT_SLUG" \
    --company="$WCASH_COMPANY_ID" \
    --location="$WCASH_LOCATION_ID" \
    --drawer="$WCASH_DRAWER_ID" \
    --safe="$WCASH_SAFE_ID" \
    --bank="$WCASH_BANK_ID" \
    --expected-head-revision=0 \
    --state=disabled \
    --evidence-file="$WCASH_TOPOLOGY_EVIDENCE_FILE" \
    --created-by="$WCASH_OPERATOR_ID" \
    --format=json
)"
WCASH_CONFIGURATION_REVISION_ID="$(
  jq -er '.configuration_revision_id' <<<"$WCASH_CONFIG_JSON"
)"
test -n "$WCASH_CONFIGURATION_REVISION_ID"
```

Create only a blocking bounded cutover:

```bash
WCASH_CUTOVER_JSON="$(
  php artisan fiscal:projection-cutover:create \
    --tenant="$WCASH_TENANT_SLUG" \
    --company="$WCASH_COMPANY_ID" \
    --location="$WCASH_LOCATION_ID" \
    --terminal="$WCASH_TERMINAL_ID" \
    --projector=shift_cash_booking \
    --rail=v2 \
    --lower-bound="$WCASH_LOWER_SOURCE_ID" \
    --upper-bound="$WCASH_UPPER_SOURCE_ID" \
    --decision=block \
    --policy-revision-key="$WCASH_POLICY_REVISION_KEY" \
    --evidence-file="$WCASH_CUTOVER_EVIDENCE_FILE" \
    --created-by="$WCASH_OPERATOR_ID" \
    --format=json
)"
WCASH_CUTOVER_ID="$(jq -er '.cutover_id' <<<"$WCASH_CUTOVER_JSON")"
test -n "$WCASH_CUTOVER_ID"
```

Only after capture:

```bash
php artisan treasury:w-cash-catch-up-v2 \
  --tenant="$WCASH_TENANT_SLUG" \
  --company="$WCASH_COMPANY_ID" \
  --cutover="$WCASH_CUTOVER_ID" \
  --from-id="$WCASH_LOWER_SOURCE_ID" \
  --to-id="$WCASH_UPPER_SOURCE_ID" \
  --limit=500 \
  --dry-run
```

The dry run must report only `blocked` or `training_no_money`; expected financial effects are zero.

Run the campaign with the verified existing options:

```bash
scripts/campaign-onboarding.sh \
  --web="$WCASH_WEB_URL" \
  --api="$WCASH_API_URL" \
  --country=TN
```

Run direct and fleet day-one census and capture JSON.

If Push 4 changes any `apps/web/**` file, repeat the explicit Dokploy deployment, asset-hash, fingerprint, and Playwright sequence from Push 3 before campaign testing.

**Rollback point:** append a new disabled configuration revision if needed; never delete revision/cutover/evidence rows. Redeploy Push-3 code.

### Push 5 — activation

**CURRENT STATUS: PROHIBITED.**

It may occur only after:

- Relevant Q10–Q13 rows are explicitly resolved.
- T11 owner supplement is separately reviewed and implemented.
- W2, W4, W-LOT, T7, T8, and T9 prerequisites are accepted.
- Two-company/two-location/two-terminal campaign passes.
- Physical Tauri/SQLite/offline/crash/reconnect smoke passes.
- Backup/restore rehearsal passes.
- Every tenant completed rolling migrations.
- No blocked/ambiguous W-CASH evidence remains in the activation range.
- Variance coverage proves comparable intervals under the approved policy.

Activation order after those gates:

1. Enable booking for one canary tenant/company/location only.
2. Keep cash-count durable dispatch and variance independently disabled.
3. Observe obligations, movement cardinality, alerts, queue failures, GL balance, and replay.
4. Enable durable cash-count dispatch.
5. Enable reconciliation repository comparison only when coverage is complete.
6. Enable variance GL last.
7. Broaden tenant by tenant.

If an activation release touches web, explicitly deploy Dokploy and repeat asset hash, feature fingerprint, and Playwright before enabling any flag.

**Rollback point:** set all three flags false; append disabled configuration revision; stop catch-up/reconciliation jobs; reverse financial effects through transfer reversals/approved compensation only; preserve all source, policy, document, alert, run, and supersession evidence.

---

## 13. Dispatch order

1. T0 — census.
2. T1 — schemas and POS v68→v69.
3. T2 — event-time policy/cutover.
4. T3 — disabled configuration and fail-closed provisioning.
5. T4 — transfer document and global lock order.
6. T5 — policy-neutral v2/v3 evidence obligations.
7. T6 — POS generated repository DTO and disabled evidence cache.
8. T7 — atomic close manifest.
9. T8 — durable three-consumer cash-count fan-out.
10. Confirm external prerequisites W2, W4, and W-LOT interface.
11. T9 — policy-neutral W7 reconciliation.
12. T10 — operator surfaces.
13. T12 — campaign and promotion evidence.
14. Stop. Do not dispatch T11 or Push 5 without explicit owner rulings.

T3 depends on T1. T4 depends on T1. T5 depends on T2–T4. T6 depends on T1/T3. T7 depends on T1. T8 depends on T1. T9 depends on T2, T5, T7, T8, W2, W4, and W-LOT. T10 depends on T3, T4, T7, and T9. T12 depends on all dispatchable tasks.

---

## 14. Final verification checklist

### Authority and policy

- [ ] Implementation base is exactly recorded and drift-reviewed.
- [ ] Q10–Q13 are reproduced verbatim and remain OPEN.
- [ ] No schema, task, command, UI control, push, or default encodes a Q10–Q13 branch.
- [ ] No custody interval/session/membership or alignment table exists.
- [ ] No typed v2 cash-operation mapping exists.
- [ ] Push 5 remains prohibited.

### Convention 09

- [ ] Create has a first-effect test.
- [ ] Duplicate/replay returns explicit `already_exists` or `skipped`.
- [ ] Edit-after-use is refused.
- [ ] Cancel creates a reversal.
- [ ] Rerun proves unchanged meaning, not only unchanged count.
- [ ] Second company is isolated.
- [ ] Second location cannot borrow custody.
- [ ] Permissions and module gates are tested.
- [ ] Audit actor/source/evidence/time/reversal lineage is present.

### Schema and tenancy

- [ ] All six migrations match §7 column-for-column.
- [ ] Every FK/delete rule, check, index, and unique exists.
- [ ] Every tenant business unique includes `company_id`.
- [ ] Actual `TenantOnlyUniqueIndexScanner.php` passes.
- [ ] Every JSONB field round-trips through its named DTO.
- [ ] POS v68 runs before v69.
- [ ] Optional-table census passes before and after migrations.

### Money and concurrency

- [ ] All repository writes converge on `TreasuryMovementService`.
- [ ] One document/group produces exactly two legs and zero-or-one JE.
- [ ] Cross-GL ordering is tenant numbering → company chain → document → sorted repositories.
- [ ] Opposite transfers do not deadlock.
- [ ] Concurrent unrelated JE does not deadlock.
- [ ] Concurrent `InventoryGlPostingBuffer` flush does not invert locks.
- [ ] W-CASH never uses or flushes the inventory buffer.
- [ ] Frozen/checkpoint replay produces one durable alert.
- [ ] Financial cancellation is reversal only.

### Fiscal and replay

- [ ] Recovery uses immutable event-time policy or bounded cutover.
- [ ] Current configuration cannot reinterpret old events.
- [ ] Semantic fingerprint includes shift and session.
- [ ] Raw evidence hashes remain separate.
- [ ] Cross-shift identity reuse is a conflict.
- [ ] Training requires literal boolean `true`.
- [ ] No opening or ambiguous cash operation writes money while Q11/Q12 are OPEN.
- [ ] `ShiftExpectedCashService` opening arithmetic is unchanged.

### Manifest and counts

- [ ] Close and Z identities exist before manifest construction.
- [ ] Z, close events, manifest, and outbox commit atomically.
- [ ] Manifest contains only device-authored membership.
- [ ] Server dependencies are a separate snapshot.
- [ ] Cash-count flag false preserves Treasury, Compliance, and stored event.
- [ ] Flag true creates exactly three durable obligations.
- [ ] Partial consumer failure remains retryable without duplicate success effects.

### W7

- [ ] All spec-v4 fiscal cases are covered.
- [ ] Identical rerun returns `already_exists`.
- [ ] Changed input appends a run and supersession.
- [ ] Current run is selected by anti-joining superseded runs.
- [ ] Repository, lot, and alignment cells report `owner_ruling_required` as applicable.
- [ ] Variance GL remains disabled.

### Types and UI

- [ ] Web uses generated `PaymentRepositoryData`.
- [ ] POS uses generated `PaymentRepositoryData`.
- [ ] Only SQLite `PaymentRepositoryRow` remains local.
- [ ] The row decoder replaces the unchecked cast.
- [ ] UI has no activation/classification/alignment/shared-drawer/recall-release control.
- [ ] Feature fingerprint is `w-cash-r3-owner-ruling-required`.
- [ ] React diagnostics, web lint, typecheck, Vitest, and Playwright pass.

### Staging and rollback

- [ ] All three rollout variables are forwarded to API, worker, scheduler, and websocket.
- [ ] Entrypoint rejects invalid flags and failed central/tenant migrations.
- [ ] `tenants:migrate-rolling --force` reports every tenant successful/current.
- [ ] Configuration revision ID is captured before use.
- [ ] Cutover ID is captured before use.
- [ ] Every web-touching push triggers explicit Dokploy application `mY6P_PHb4pw-2LdG1Y7Ml`.
- [ ] Deployment ID, served asset hashes, feature fingerprint, and Playwright result are recorded.
- [ ] Two-company/two-location/two-terminal campaign passes.
- [ ] Direct and fleet day-one census agree.
- [ ] Physical Tauri/SQLite/offline/crash/reconnect/printer smoke passes.
- [ ] Backup/restore rehearsal passes.
- [ ] Every push has its recorded rollback SHA and non-destructive rollback procedure.
- [ ] No migration down, evidence deletion, history rewrite, force push, or unapproved financial activation occurs.