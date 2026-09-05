<!-- Authored by Codex CLI (gpt-5.6-sol, high effort, read-only) on 2026-09-06 from the W-CASH brief + plan gate r1; filed verbatim by the orchestrator. Status: rev 1, awaiting plan gate r2. -->
# W-CASH Execution Plan — Opening Float, Cash Operations, and Drawer → Safe → Bank Treasury Custody

**Date:** 2026-09-06  
**Repository:** `/Users/houssamr/Projects/syneriva/apps/erp`  
**Plan base:** local `dev` at `c36cc97ca4e98f2aaeba2d3d0acf09ffd01e2226`  
**Authority order:** owner rulings → accepted specification v4 → W-CASH brief → gate r1 corrections  
**Delivery mode:** additive migrations and compatible readers first; all new money-writing capability disabled by default  
**Exact audit artifact:** `docs/superpowers/audits/2026-09-05-w-cash-census.md`  
**Exact handback artifact:** `docs/handoff/HANDBACK-W-CASH-2026-09-06.md`

## 1. Outcome and boundaries

W-CASH will make physical cash custody explicit in Treasury:

- Opening float is a transfer from a configured safe to a drawer, never new company money.
- A custody transfer creates one immutable transfer document, one transfer group, and exactly two cross-linked repository movement legs.
- Drawer → safe and safe → bank use the same transfer-document and movement service.
- External payouts such as petty expenses do not masquerade as transfers; they use one accounting document, one repository leg, and one posted journal entry.
- A v3 fiscal adapter and a durable v2 drawer-operation adapter converge on one `ShiftCashBookingService`.
- W7 receives an append-only session-reconciliation store, a versioned unsealed close manifest, and a durable v3 `CashCountRecorded` producer before variance posting can be enabled.
- W-CASH capability is independent of Treasury module entitlement and defaults to disabled.
- Existing fiscal bytes and Z calculations are not rewritten. `ShiftExpectedCashService` remains the single expected-cash derivation.
- Historical facts are not financially back-booked unless an owner-approved alignment policy explicitly authorizes an evidenced compensating document.

Three user-visible accounting policies remain open. This plan recommends benchmark-derived defaults but does not choose them. Tasks depending on those decisions remain conditional.

## 2. Current-code evidence at HEAD

The current device authors `SESSION_OPEN` and a separate `OPENING_FLOAT` in one write transaction; `SESSION_OPEN` carries `opening_float_amount`, while `OPENING_FLOAT` carries the money-movement identity and amount (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:291-335`, `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:507-566`). The server lifecycle projection accepts `SESSION_OPEN`, `OPENING_FLOAT`, `CASH_IN`, `CASH_OUT`, `SAFE_DROP`, and related lifecycle events, but only `SESSION_OPEN` and `SESSION_CLOSE` mutate the projected shift (`apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:31-42`, `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:117-125`). Consequently, `OPENING_FLOAT`, not `SESSION_OPEN.opening_float_amount`, must be the Treasury money source.

The expected-cash derivation already chooses v3 fiscal movements for schema-v3 terminals and v2 drawer rows otherwise (`apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:252-304`). It deliberately skips the v3 `OPENING_FLOAT` movement because the shift’s opening amount is already included (`apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:484-520`). Its current v3 signs are `CASH_IN = +`, `CASH_OUT = -`, and `SAFE_DROP = -` (`apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:657-714`).

Treasury’s transfer port already promises an atomic out/in pair, sorted repository locks, and zero or one journal entry depending on whether the repositories have the same GL account (`apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php:59-96`). Its implementation writes exactly two legs (`apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:355-396`). The current manual transfer service creates the GL draft and paired legs but no justifying transfer document (`apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:64-115`).

The v3 Z projection stores cash-count data but does not emit `CashCountRecorded` (`apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:54-100`, `apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:139-164`). The architecture ratchet records this missing v3 producer explicitly (`apps/api/tests/Architecture/ProjectorEmissionRatchetTest.php:160-187`). The variance listener exists but is globally disabled and runs on the existing `default` queue (`apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php:258-325`, `apps/api/config/treasury.php:16-28`).

## 3. Industry baseline

Flow: POS cash custody from opening through safe and bank deposit. Reference systems: Odoo 18/19, ERPNext current documentation, and Dolibarr TakePOS/Bank-Cash documentation.

| ID | Guarantee the baseline gives the user | Odoo | ERPNext | Dolibarr or NV | AutoERP at HEAD | Gap | Decision |
|---|---|---|---|---|---|---|---|
| B1 | Opening cash is an explicit session fact tied to a cash account. | Opening Cash Control starts the POS session ([Odoo POS](https://www.odoo.com/documentation/18.0/applications/sales/point_of_sale.html)). | POS Opening Entry captures opening cash before sales ([ERPNext POS workflows](https://docs.frappe.io/erpnext/pos-workflows)). | `TAKEPOS_CONTROL_CASH_OPENING` enables controlled opening ([Dolibarr setup](https://wiki.dolibarr.org/index.php/Setup_Other)); the cash-fence table stores `opening` and terminal/user evidence ([Dolibarr cash fence](https://wiki.dolibarr.org/index.php?title=Table_llx_pos_cash_fence)). | Device and fiscal projection record the opening, but Treasury does not consume `OPENING_FLOAT` (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:507-566`; `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:31-42`). | MISSING | **MATCH — T5:** book the `OPENING_FLOAT` fact as safe → drawer, conditional on the shared-drawer ruling. |
| B2 | Cash in/out records amount and reason; its economic counterparty is explicit before accounting. | POS captures Cash In/Out plus reason; access is permissioned ([Odoo workflow](https://www.odoo.com/documentation/19.0/applications/sales/point_of_sale/use.html)). Cash journals expose configured liquidity, profit, and loss accounts ([Odoo journals](https://www.odoo.com/documentation/19.0/applications/finance/accounting/get_started/journals.html)). | Mode of Payment selects a company-specific default cash or bank ledger ([ERPNext Mode of Payment](https://docs.frappe.io/erpnext/mode-of-payment)). | Bank/Cash distinguishes cash and bank accounts, but a typed TakePOS cash-operation counterparty was not verified: **NV** ([Dolibarr Bank/Cash](https://wiki.dolibarr.org/index.php/Module_Banks_and_cash)). | Device `deposit` maps to `CASH_IN`, while v2 `DEPOSIT` means drawer → safe (`apps/pos/src/api/cashDrawerApi.ts:168-183`; `apps/api/database/migrations/tenant/2026_01_08_190642_create_pos_cash_drawer_operations_table.php:63-74`). | WRONG/AMBIGUOUS | **CONDITIONAL MATCH — D-WC-02, T6/T7:** require typed semantics; never infer custody from free text. |
| B3 | An internal liquidity transfer affects both source and destination. | Internal transfer requires outgoing and incoming transactions and updates both accounts ([Odoo internal transfers](https://www.odoo.com/documentation/19.0/applications/finance/accounting/bank/internal_transfers.html)). | Cash/bank ledgers are company-specific destinations; transfer accounting is performed through submitted payment/journal entries ([ERPNext Mode of Payment](https://docs.frappe.io/erpnext/mode-of-payment)). | Bank/Cash supports multiple bank or cash accounts and exposes a dedicated transfer permission ([Dolibarr Bank/Cash developer](https://wiki.dolibarr.org/index.php/Module_Banks_and_Cash_%28developer%29)). | The port writes two movement legs, but manual transfer has no transfer document (`apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:355-396`; `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:64-115`). | PARTIAL | **MATCH — T4/T8:** one transfer document, one group, exactly two legs, zero/one JE. |
| B4 | Closing compares expected and counted cash and exposes differences. | Closing Control shows expected amounts and counted cash and may require resolution of a difference ([Odoo POS](https://www.odoo.com/documentation/18.0/applications/sales/point_of_sale.html)). | POS Closing Entry closes the period and performs accounting postings ([ERPNext POS workflows](https://docs.frappe.io/erpnext/pos-workflows)). | Cash-fence rows carry opening, cash, status, creator, validator, and validation date ([Dolibarr cash fence](https://wiki.dolibarr.org/index.php?title=Table_llx_pos_cash_fence)). | Expected cash is derived, but no W7 result store exists and v3 Z projection emits no cash-count event (`apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:252-304`; `apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:54-100`). | MISSING | **MATCH — T2/T10:** add durable fiscal and repository checks before variance GL. |
| B5 | A repeated command does not move money twice. | Posted journal records and reconciliation provide stable accounting artifacts; exact POS replay semantics were not verified. | Submitted documents provide stable accounting identity; exact POS replay semantics were not verified. | **NV.** | Transfer group replay returns the original pair after semantic validation (`apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php:81-85`). | PARTIAL | **MATCH — T4/T5:** deterministic document/group/source keys plus visible conflicts. |
| B6 | Cash configuration and balances are isolated by company. | Multiple journals can exist, including distinct journals per bank/cash account ([Odoo journals](https://www.odoo.com/documentation/19.0/applications/finance/accounting/get_started/journals.html)). | Mode of Payment has company-specific account rows ([ERPNext Mode of Payment](https://docs.frappe.io/erpnext/mode-of-payment)). | Dolibarr account rows are entity-scoped and Bank/Cash supports multiple accounts ([Dolibarr Bank/Cash](https://wiki.dolibarr.org/index.php/Module_Banks_and_cash)). | The real additional-company path provisions its own default location, drawer, and safe (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:63-74`, `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:127-145`, `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:172-196`). | PARTIAL | **MATCH — T3 and convention-09 tests:** configuration, source keys, and results are company-scoped. |
| B7 | Branch/location custody lands in the selected register, not the first register found. | Payment methods are attached to a selected POS configuration ([Odoo payment methods](https://www.odoo.com/documentation/18.0/applications/sales/point_of_sale/payment_methods.html)). | POS Opening/Closing Entry is associated with a POS Profile ([ERPNext POS workflows](https://docs.frappe.io/erpnext/pos-workflows)). | TakePOS supports multiple terminals, but selected-location custody binding was not verified: **NV** ([Dolibarr TakePOS](https://wiki.dolibarr.org/index.php/Module_Point_of_sale_%28TakePOS%29)). | Creating a selected `pos_enabled` location provisions its drawer (`apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php:181-216`, `apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php:227-250`). | PARTIAL | **MATCH — T3/T8:** exact persisted terminal location and configured repositories; no first/default fallback. |
| B8 | Cash operations and transfers are permissioned, and configuration changes are auditable. | Employee rights control session and cash-in/out actions ([Odoo employee access](https://www.odoo.com/documentation/18.0/applications/sales/point_of_sale/employee_login.html)). | Company/account access controls selection of company-specific ledgers ([ERPNext Mode of Payment](https://docs.frappe.io/erpnext/mode-of-payment)). | Bank/Cash defines read/modify/configure/transfer permissions; security settings expose audit and group permissions ([Dolibarr Bank/Cash developer](https://wiki.dolibarr.org/index.php/Module_Banks_and_Cash_%28developer%29), [Dolibarr security](https://wiki.dolibarr.org/index.php/Setup_Security)). | Transfer and adjustment routes have action permissions, but the route group has no inline `module:Treasury` gate (`apps/api/app/Modules/Treasury/Presentation/routes.php:35-59`, `apps/api/app/Modules/Treasury/Presentation/routes.php:96-104`). | PARTIAL | **MATCH — T3/T8:** `module:Treasury` on new backend endpoints and `hasModule('Treasury')` plus permissions in the existing frontend surface. |
| B9 | Accounting history is append-only; corrections use linked reversal/compensation. | Posted cash/bank journal history is reconciled; secure posted-entry options restrict alteration where applicable ([Odoo journals](https://www.odoo.com/documentation/19.0/applications/finance/accounting/get_started/journals.html)). | Submitted accounting documents are corrected through accounting documents, not silent balance edits. | Dolibarr exposes audit and bank-operation tables; an exact TakePOS reversal contract was not verified: **NV** ([Dolibarr security](https://wiki.dolibarr.org/index.php/Setup_Security)). | Repository movements reject update/delete and require compensating movements (`apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php:62-81`). | PARTIAL | **MATCH — T4/T9:** immutable original document; reversal creates a new cross-linked document and opposite legs. |
| B10 | A cash register’s variance is posted only against a trustworthy covered interval. | Closing reports compare session activity and counted cash ([Odoo POS](https://www.odoo.com/documentation/18.0/applications/sales/point_of_sale.html)). | Opening and Closing Entries bound the POS period ([ERPNext POS workflows](https://docs.frappe.io/erpnext/pos-workflows)). | Cash-fence records are terminal- and period-related, but a repository-ordinal coverage algorithm was not verified: **NV**. | Variance posting resolves one repository and is disabled globally; no repository-coverage result is required today (`apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php:289-325`, `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php:620-693`). | MISSING | **MATCH — T10:** require a matching immutable coverage fingerprint before posting. |
| B11 | Offline facts remain recoverable without silently bypassing controls. | Odoo POS operates temporarily offline, while cash operations remain session facts ([Odoo POS](https://www.odoo.com/documentation/18.0/applications/sales/point_of_sale.html)). | Offline replay policy was not verified: **NV**. | **NV.** | `record()` supports flagged frozen/checkpoint projection writes, but paired transfer currently hard-rejects frozen repositories and hardcodes false flags (`apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php:98-104`; `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:43-50`; `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:295-318`, `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:355-396`). | WRONG | **MATCH — T4:** explicit offline transfer policy, atomic flagged legs, alert, and no negative-cash bypass. |
| B12 | A legacy cash account starts from one reviewed opening/alignment balance rather than invented transaction history. | Opening bank/cash statement balance is set to the actual pre-cutover balance ([Odoo accounting setup](https://www.odoo.com/documentation/19.0/applications/finance/accounting/get_started.html)). | Opening balances are introduced through opening/accounting entries. | Exact POS historical-alignment flow was not verified: **NV**. | Existing repository opening balance refuses a repository that already has foreign movements (`apps/api/app/Modules/Treasury/Application/Services/RepositoryOpeningBalanceService.php:139-152`). | MISSING | **CONDITIONAL MATCH — D-WC-03, T9:** evidenced dated alignment; no invented per-shift history. |

## 4. Vocabulary and one-surface contract

**Vocabulary:** Concepts: Repository (glossary ✅); Shift (glossary ✅); Session reconciliation (glossary ✅, definition extended); Cash custody configuration (NEW — glossary row added in T3); Shift cash booking (NEW — glossary row added in T3); Repository transfer document (NEW — glossary row added in T4); Coverage interval (NEW — glossary row added in T2); Opening-balance alignment (NEW — glossary row added conditionally in T9).

The existing glossary already defines Repository, Shift, Session reconciliation, and Opening batch (`docs/glossary.md:60`, `docs/glossary.md:63`, `docs/glossary.md:75-76`). The new rows will state:

| Concept | Definition and single writer | Canonical surface |
|---|---|---|
| Cash custody configuration | Versioned company/location mapping from terminal drawer to safe and optional bank, written only by `CashCustodyConfigurationService`. | Existing Treasury repository detail/editor. |
| Shift cash booking | Idempotent conversion of one typed POS cash fact into its Treasury document/effect, written only by `ShiftCashBookingService`. | Existing shift/Z detail for status; repository detail for money movements. |
| Repository transfer document | Immutable justification for one custody action, owning one transfer group and exactly two repository legs, written only by `RepositoryTransferDocumentService`. | Existing repository detail and existing `TransferCashModal`. |
| Coverage interval | Immutable source-membership snapshot and repository ordinal bounds used for one close comparison. | Existing shift/Z detail. |
| Opening-balance alignment | Reviewed cutover document reconciling observed physical custody with Treasury without fabricating past shifts. | Existing repository detail; conditional on D-WC-03. |

No new repository catalogue, “bank deposit” catalogue, transfer modal, or alternate shift-close surface will be created. The existing repository detail already imports `TransferCashModal` (`apps/web/src/features/treasury/RepositoryDetailPage.tsx:23-25`), and both repository list/detail currently expose that modal (`apps/web/src/features/treasury/RepositoryDetailPage.tsx:229-237`, `apps/web/src/features/treasury/RepositoryListPage.tsx:210`).

Create backend Spatie Data DTOs and regenerate `packages/shared/types/generated.d.ts`. Remove the hand-written repository shapes in `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts:7-20` and `apps/web/src/features/treasury/RepositoryDetailPage.tsx:27-45`. Types flow from backend per `CLAUDE.md:33-37`.

## 5. Open owner decisions

| Decision | Status | Benchmark-derived recommended default | Alternative and consequence | Conditional tasks |
|---|---|---|---|---|
| **D-WC-01 — shared drawer model** | **OPEN; do not implement a policy implicitly.** | One cash-bearing drawer session at a time. A terminal must hold the drawer lease to author an opening float; another terminal either joins an explicitly modelled drawer session without a second float or is refused cash-bearing open. This follows the one-POS-session/register custody model in Odoo and the per-profile opening in ERPNext. | Coordinated aggregation permits overlapping terminal shifts, but requires drawer-level membership, overlap-safe close ordering, one physical count owner, late-member invalidation, and an allocation rule for two closing counts. Simply booking both floats is prohibited. | T5 opening float, T7 device authoring, shared-drawer cases in T10, activation. |
| **D-WC-02 — typed meaning of `CASH_IN`, `CASH_OUT`, `DEPOSIT`, `PAYOUT`** | **OPEN.** | Add typed reason codes `SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`. `SAFE_DROP` is drawer → safe; `BANK_DEPOSIT` is drawer/safe → bank; `FLOAT_TOP_UP` is safe → drawer; `PETTY_EXPENSE` is an expense/adjustment document with one out leg; `OTHER` remains blocked until classified. Map v2 `DEPOSIT` to safe drop only if approved. Require per-row evidence for ambiguous historical `PAYOUT`. | Keeping raw kinds/free text makes counter-custody and GL treatment unknowable. Such events must remain blocked and variance cannot be enabled for their intervals. Mapping every payout to the safe would misstate petty expenses. | T3 policy schema, T5 non-opening operations, T6 v2 adapter, T7 POS UI, T10 coverage. |
| **D-WC-03 — historical alignment accounting** | **OPEN.** | One dated, approved alignment per repository at cutover: record physical count, ledger balance, signed difference, evidence, reviewer, and one compensating movement/JE to approved cash-difference gain/loss accounts. Do not recreate past floats/drops or past variance documents. | “Start from current balance without accounting” leaves GL/repository disagreement and makes later reconciliation untrustworthy. Retroactive per-shift reconstruction invents timing/counterparties and can alter closed periods. The only safe alternative is to leave the repository comparison unavailable and variance disabled. | T9, final capability readiness, historical-window disposition in T10/T11. |

The owner’s eventual selections must be recorded in `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md` with decision date, actor, selected branch, and accounting reviewer evidence. Independent schema, recovery, DTO, and read-side tasks may proceed; no dependent write capability may be enabled while its decision is open.

## 6. Completed C1 producer, consumer, and writer census

### 6.1 v3 fiscal and device sources

| Fact | Producer/payload | Server persistence today | W-CASH treatment |
|---|---|---|---|
| `SESSION_OPEN` | Device payload includes `opening_float_amount` (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:291-310`). | `ZSessionLifecycleProjection` stores the event and writes `pos_shifts.opening_cash` (`apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:94-125`, `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:208-221`). | Provenance only. Never book money from this event. |
| `OPENING_FLOAT` | Authored immediately after `SESSION_OPEN`, with `movement_id`, optional `cash_drawer_operation_id`, amount, reason, session, and shift (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:313-335`, `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:553-566`). | Stored in `pos_z_session_events` because the lifecycle projector handles it (`apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:31-42`, `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:99-115`). | Sole v3 opening-float money source; safe → drawer, conditional on D-WC-01. |
| `CASH_IN` | Current device `deposit` action emits `CASH_IN` with a free-text reason and `cash_drawer_operation_id` (`apps/pos/src/api/cashDrawerApi.ts:139-194`). | Stored in `pos_z_session_events`. Expected-cash sign is positive (`apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:684-714`). | Block until D-WC-02 supplies typed meaning; afterwards dispatch by typed code. |
| `CASH_OUT` | Current device `payout` action emits `CASH_OUT` (`apps/pos/src/api/cashDrawerApi.ts:168-194`). | Stored in `pos_z_session_events`. Expected-cash sign is negative (`apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:684-714`). | Block until D-WC-02 distinguishes transfer from external expense. |
| `SAFE_DROP` | Present in the authoring type union, but the current cash-drawer UI only chooses `CASH_IN`/`CASH_OUT` (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:87-92`; `apps/pos/src/api/cashDrawerApi.ts:177-183`). | Stored in `pos_z_session_events`; expected-cash sign is negative (`apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:710-713`). | Unambiguous drawer → configured safe transfer. |
| `CASH_CORRECTION` | Accepted by device/server type sets but current expected-cash derivation deliberately refuses to assign a direction (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:87-92`; `apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php:675-713`). | Stored in `pos_z_session_events`. | Unsupported/blocked; W-CASH does not guess. |
| `SESSION_CLOSE` | Device authors close and then Z (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:664-711`). | Lifecycle projection closes the shift (`apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:123-125`). | No Treasury movement; provides interval boundary. |
| `Z_REPORT` | Device Z payload contains cash count and source ranges (`apps/pos/src/lib/fiscal/zSessionAuthoring.ts:689-711`). | `ZReportProjection` stores cash count and expected/actual/variance (`apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php:139-164`). | W7 close-manifest/result source and assigned v3 `CashCountRecorded` producer in T2. |

Cross-rail source key:

```text
if payload.cash_drawer_operation_id is a non-empty UUID:
    source_key = "pos_cash_drawer_operation:" + cash_drawer_operation_id
else:
    source_key = "fiscal_event:" + fiscal_event_id
```

This causes a v2 row and a v3 fiscal event representing the same device operation to converge on one obligation/document. The fiscal event ID, sequence, hash, and payload fingerprint remain evidence even when the v2 operation ID is the economic deduplication key.

Training facts are never booked. The v3 adapter requires `integrity_status=verified`, `chain_context=z_session`, and `training_flag=false`; `training_z_session` is an explicit applied-without-money containment result, not a blocked error. The current lifecycle projector accepts both live and training chain contexts (`apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:55-63`), so W-CASH must make this narrower check itself.

### 6.2 v2 `pos_cash_drawer_operations`

The v2 table permits `OPENING`, `SALE`, `REFUND`, `DEPOSIT`, `PAYOUT`, and `CLOSING`; its database comments define `DEPOSIT` as cash moved to safe and `PAYOUT` as petty cash out (`apps/api/database/migrations/tenant/2026_01_08_190642_create_pos_cash_drawer_operations_table.php:20-74`).

| v2 kind | Current producer/meaning | W-CASH treatment |
|---|---|---|
| `OPENING` | `CashDrawerService::recordOpening()` creates the row but emits no operation event (`apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php:192-214`). `ShiftManagementService` calls it when opening the server shift (`apps/api/app/Modules/POS/Domain/Services/ShiftManagementService.php:101-125`). | v2 safe → drawer opening candidate, keyed by the row ID and conditional on D-WC-01. |
| `SALE` | Recorded from a cash receipt (`apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php:314-338`). | Ignored by the W-CASH adapter; receipt Treasury booking remains authoritative. |
| `REFUND` | Recorded from a receipt refund and emits the legacy event (`apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php:340-368`). | Ignored by W-CASH to avoid duplicating the receipt/refund Treasury path. |
| `DEPOSIT` | Documented and implemented as drawer → safe (`apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php:240-275`). | Conditional safe drop after D-WC-02 confirms the mapping. |
| `PAYOUT` | Implemented as a non-sale cash payout/refund/petty cash (`apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php:277-312`). | Block unless D-WC-02 and typed evidence classify it as transfer or expense. |
| `CLOSING` | Records counted cash (`apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php:216-238`). | No movement; v2’s existing close producer remains, while W7 stores the result. |

The existing `CashDrawerOperationRecorded` event covers only the old fields and lacks tenant, currency, location, and reason (`apps/api/app/Modules/POS/Domain/Events/CashDrawerOperationRecorded.php:15-33`). It will not be modified because events are immutable (`CLAUDE.md:36-37`). T6 adds `CashDrawerOperationAuthoredV2`, dispatched after commit for new `OPENING`, `DEPOSIT`, and `PAYOUT` rows. Historical rows are found by an idempotent catch-up command.

### 6.3 Treasury money-writer census

`TreasuryMovementService::insertMovementLeg()` is the sole low-level `repository_movements` insert and cached-balance writer (`apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:542-610`). The architecture ratchet permits only that service to update `payment_repositories.balance` (`apps/api/tests/Architecture/TreasuryBalanceWritePortTest.php:14-22`, `apps/api/tests/Architecture/TreasuryBalanceWritePortTest.php:48-81`).

Every current direct movement-port call site at HEAD is:

| Consumer group | Direct calls |
|---|---|
| Treasury HTTP/payment orchestration | `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:1349`, `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:2063`, `apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php:602`, `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:729`, `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:1087`, `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:211` |
| Treasury instruments/fees | `apps/api/app/Modules/Treasury/Application/Services/AcquirerFeeService.php:133`, `apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:277`, `apps/api/app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:495`, `apps/api/app/Modules/Treasury/Application/Services/OutboundInstrumentService.php:140`, `apps/api/app/Modules/Treasury/Application/Services/OutboundInstrumentService.php:298`, `apps/api/app/Modules/Treasury/Application/Services/OutboundInstrumentService.php:452` |
| Treasury fiscal bridges | `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:1560`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryAccountPaymentBridge.php:267`, `apps/api/app/Modules/Treasury/Application/Projections/TreasuryDepositBridge.php:249` |
| Treasury repository documents | `apps/api/app/Modules/Treasury/Application/Services/RepositoryOpeningBalanceService.php:152`, `apps/api/app/Modules/Treasury/Application/Services/RepositoryAdjustmentService.php:234`, `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:89` |
| Cross-module callers through the shared port | `apps/api/app/Modules/Income/Application/Services/IncomeService.php:175`, `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:452`, `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:488`, `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:816`, `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php:998`, `apps/api/app/Modules/Fiscal/Application/Services/RefundCompensationService.php:308` |

W-CASH adds no direct movement insert. `RepositoryTransferDocumentService`, `ShiftCashBookingService`, manual safe → bank, and conditional alignment all terminate in `TreasuryMovementServiceInterface::record()` or `transfer()`.

### 6.4 Lock and writer order

Current transfer locking is company GL advisory lock first, followed by both repositories in ascending UUID order (`apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:237-269`). The current shift-open paths lock terminal then inspect/write shifts (`apps/api/app/Modules/POS/Domain/Services/ShiftManagementService.php:66-112`; `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:140-190`).

W-CASH must preserve this order:

1. Read and fingerprint immutable POS/fiscal source facts without `FOR UPDATE`.
2. Insert or find the deterministic booking obligation/document through a savepoint and company-scoped unique key; do not hold a source-row lock.
3. Take the company GL advisory lock.
4. Lock transfer repositories in ascending UUID order.
5. Post zero/one JE and exactly two legs; update both cached balances.
6. Link document to both legs and the JE inside the same outer transaction.
7. Mark the booking obligation applied only after the financial transaction commits.
8. Update projection lifecycle in its separate short transaction.

W-CASH must not lock a terminal or shift after acquiring GL/repository locks. If the projected shift or repository coverage is missing, the adapter releases its projection/obligation claim and records a dependency outcome rather than taking POS locks in the financial transaction. V2 catch-up claims source-obligation rows with `FOR UPDATE SKIP LOCKED`, commits the claim, and processes it without retaining a lock on `pos_cash_drawer_operations`.

## 7. Contracts

### 7.1 Shared booking contract

Create `apps/api/app/Modules/Treasury/Application/DTOs/ShiftCashBookingIntent.php`:

```php
final readonly class ShiftCashBookingIntent
{
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $locationId,
        public string $terminalId,
        public string $shiftId,
        public ?string $sessionId,
        public ShiftCashSourceRail $sourceRail,
        public string $sourceKey,
        public string $sourceId,
        public string $sourceFingerprint,
        public ShiftCashOperationKind $operationKind,
        public string $amount,
        public string $currency,
        public CarbonImmutable $occurredAt,
        public ?string $reasonCode,
        public ?string $reasonText,
        public ?string $operatorId,
        public ShiftCashEvidenceData $evidence,
    ) {}
}
```

Create `apps/api/app/Modules/Treasury/Application/Services/ShiftCashBookingService.php`:

```php
public function book(ShiftCashBookingIntent $intent): ShiftCashBookingResult;
```

Both adapters call this method:

- `TreasuryShiftCashFiscalProjection` for verified, non-training v3 events.
- `QueueV2ShiftCashBooking` and `treasury:w-cash-catch-up-v2` for v2 operation rows.

The service must:

- Clear any reliance on `CompanyContext`.
- Resolve scale with explicit currency using `getScaleSafe($intent->currency, 3)` at the worker boundary.
- Normalize the amount once with `CurrencyScale::bcformatStrict`.
- Reject zero, negative magnitudes, overprecision, source/repository currency mismatch, and conflicting replay.
- Pass the same normalized string to document, both movement legs, and JE.
- Resolve repositories only from the persisted, revisioned cash-custody configuration.
- Return `Applied`, `IdempotentReplay`, `Blocked`, or `Conflict`; never silently skip a live enabled source.

### 7.2 Transfer contract extension

Modify `apps/api/app/Modules/Treasury/Application/DTOs/TransferIntent.php` to append:

```php
public bool $allowWhileFrozen = false;
public bool $allowBehindCheckpoint = false;
public bool $allowNegative = false;
```

Interactive `RepositoryTransferService` passes all three as `false`. Verified offline W-CASH facts pass:

```php
allowWhileFrozen: true,
allowBehindCheckpoint: true,
allowNegative: false,
```

Modify `TreasuryMovementServiceInterface::transfer(TransferIntent $intent): TransferResult` documentation and implementation so:

- Exact replay is resolved before mutable freeze/checkpoint policy.
- A new flagged offline transfer can pass freeze/checkpoint checks, but both legs set `recorded_while_frozen` and/or `recorded_behind_checkpoint`.
- A durable alert/audit record is emitted once per transfer group.
- Insufficient drawer balance never becomes a negative transfer; the obligation remains dependency-blocked until earlier covered movements arrive or an operator resolves it.
- Any failure before both legs are inserted rolls back document, JE, both legs, and both cached balances.

### 7.3 Transfer-document contract

Create:

```php
public function post(
    RepositoryTransferDocumentIntent $intent
): RepositoryTransferDocumentResult;

public function reverse(
    RepositoryTransferDocument $original,
    RepositoryTransferReversalIntent $intent
): RepositoryTransferDocumentResult;
```

`RepositoryTransferDocumentIntent` includes tenant, company, location, source/destination repositories, kind, source rail/key/id/fingerprint, amount, currency, occurred-at, evidence, actor, and replay policy.

A posted action must satisfy:

```text
1 repository_transfer_documents row
1 transfer_group_id
2 repository_movements rows
0 JE if both repositories share gl_account_id
1 posted JE if the repositories have different gl_account_id
```

A correction never updates the original document or movements. `reverse()` creates a new reversal document, a new transfer group, and two opposite legs linked to the original document and movements.

### 7.4 W7 contracts

Create `PosSessionReconciliationService`:

```php
public function reconcile(
    PosSessionReconciliationIntent $intent
): PosSessionReconciliationResult;

public function invalidateForLateMember(
    string $companyId,
    string $zReportId,
    string $dependencyIdentity
): void;
```

Create `CashCountRecordedFactory`:

```php
public function fromProjectedZ(
    FiscalEvent $event,
    ZReport $zReport
): CashCountRecorded;
```

Create `DispatchProjectedCashCountJob` on the existing `default` queue:

```php
public function __construct(public readonly string $dispatchObligationId);

public function handle(
    CashCountRecordedFactory $factory,
    CashCountDispatcher $dispatcher
): void;
```

The Z projection creates the dispatch obligation transactionally with the Z row. Dispatch is after commit, idempotent on `(company_id, z_report_id)`, and excludes training. Recovery re-enqueues pending/failed obligations; it never reconstructs cash counts from mutable shift fields.

## 8. Schema and state machines

All migrations are additive and self-guarding. Every status/type/code column receives a PHP enum and PostgreSQL CHECK parity coverage.

### 8.1 Migration `2026_09_06_090000_add_blocked_state_to_fiscal_event_projections.php`

Add to `fiscal_event_projections`:

- `blocked_reason_code varchar(64) nullable`
- `blocked_detail jsonb nullable`
- `blocked_at timestamptz nullable`
- `blocked_configuration_revision bigint nullable`
- index `(projector_name, projection_status, blocked_reason_code)`

Extend `ProjectionStatus` from its current four cases (`apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionStatus.php:7-13`) with `Blocked = 'blocked'`.

State machine:

```text
pending → running → applied
pending → running → pending            transient failure
pending → running → blocked            actionable missing policy/configuration
blocked → pending                       only after matching resolution or newer config revision
pending/running → dead_lettered         exhausted or permanent technical failure
applied, dead_lettered                  terminal unless existing authorized retry flow resets dead-letter
```

Blocked does not consume retry attempts and is not automatically requeued. Resolution records the deciding configuration/resolution revision and performs a compare-and-set from `blocked` to `pending`.

### 8.2 Migration `2026_09_06_090100_create_treasury_cash_custody_configs.php`

Create `treasury_cash_custody_configs`:

- `id uuid primary key`
- `tenant_id uuid`
- `company_id uuid`
- `location_id uuid`
- `drawer_repository_id uuid`
- `safe_repository_id uuid nullable`
- `default_bank_repository_id uuid nullable`
- `capability_state varchar(24)` enum `disabled|ready|enabled`, default `disabled`
- `policy_revision bigint`, default `1`
- `configuration_fingerprint char(64)`
- `configured_by uuid nullable`
- `activated_by uuid nullable`
- `activated_at timestamptz nullable`
- `disabled_at timestamptz nullable`
- timestamps
- unique `(company_id, location_id)`
- FKs to the three repository rows
- indexes `(tenant_id, company_id)` and `(company_id, capability_state)`

Readiness validates exact tenant/company/currency ownership; drawer type `cash_register` and exact location; safe type `safe`; optional bank type `bank_account`; active repositories; GL account readiness; owner decisions; alignment disposition; W1/W2 prerequisites. No migration guesses repository IDs or enables any row.

State machine:

```text
disabled → ready       validated configuration and resolved prerequisites
ready → enabled        explicit authorized activation
enabled → disabled     kill switch; existing outcomes remain readable/recoverable
ready → disabled       configuration invalidated
```

Configuration edits increment `policy_revision`, write audit evidence, and return the state to `disabled` or `ready`; they never silently redirect already-authored facts.

### 8.3 Migration `2026_09_06_090200_create_repository_transfer_documents.php`

Create `repository_transfer_documents`:

- `id uuid primary key`
- `tenant_id`, `company_id`, `location_id uuid`
- `terminal_id`, `shift_id`, `session_id uuid nullable`
- `document_kind varchar(32)` enum `opening_float|safe_drop|float_top_up|drawer_to_bank|safe_to_bank|reversal`
- `source_rail varchar(24)` enum `fiscal_v3|drawer_v2|backoffice|alignment`
- `source_key varchar(160)`
- `source_id uuid`
- `source_fingerprint char(64)`
- `from_repository_id`, `to_repository_id uuid`
- `amount decimal(15,3)`
- `currency char(3)`
- `occurred_at timestamptz`
- `reason_code varchar(32) nullable`
- `reason_text text nullable`
- `evidence jsonb`
- `transfer_group_id uuid`
- `out_movement_id`, `in_movement_id uuid nullable`
- `journal_entry_id uuid nullable`
- `reverses_document_id uuid nullable`
- `created_by uuid nullable`
- timestamps
- unique `(company_id, source_key)`
- unique `(company_id, transfer_group_id)`
- unique `out_movement_id`
- unique `in_movement_id`
- unique `reverses_document_id` where non-null
- FKs to repositories, movements, JE, and self-reversal document
- positive amount and distinct-repository CHECKs

State machine is represented by linkage, not a mutable user status:

```text
transaction-local draft → committed posted document with both leg IDs
posted original → remains immutable
reversal request → new posted reversal document
```

No committed row may have only one movement link. Add PostgreSQL deferred constraint-trigger coverage if ordinary CHECK/FK constraints cannot express the committed pair invariant.

### 8.4 Migration `2026_09_06_090300_create_shift_cash_booking_obligations.php`

Create `shift_cash_booking_obligations`:

- identity/scope fields matching `ShiftCashBookingIntent`
- `source_rail`, `source_key`, `source_id`, `source_fingerprint`
- nullable `fiscal_event_id`, `cash_drawer_operation_id`
- raw kind and nullable typed `operation_kind`
- normalized amount/currency/occurred-at/reason/evidence
- `status varchar(24)` enum `pending|processing|blocked|applied|conflict|contained_training`
- `blocked_reason_code`, `blocked_detail`, `blocked_at`
- `configuration_revision`
- `transfer_document_id nullable`
- `repository_adjustment_id nullable`
- attempts/timestamps
- unique `(company_id, source_key)`
- indexes `(company_id, status)`, `(shift_id, status)`

State machine:

```text
pending → processing → applied
pending/processing → blocked
blocked → pending          typed/config/dependency resolution
pending/processing → conflict
pending → contained_training
processing → pending       expired claim recovery
```

An obligation can link either one transfer document or one external adjustment document, never both.

### 8.5 Migration `2026_09_06_090400_create_pos_close_manifest_and_reconciliation_tables.php`

Create `pos_session_close_manifests`:

- `id`, tenant/company/terminal/shift/session/Z identities
- `schema_version`
- `fiscal_event_id`
- device source ranges and identities in validated `manifest jsonb`
- `source_fingerprint char(64)`
- `authored_at_device`, `received_at`
- unique `(company_id, z_report_id, schema_version)`
- append-only guard

Create `pos_session_reconciliations`:

- `id`, tenant/company/terminal/shift/session/Z identities
- `check_type` enum `fiscal_totals|repository_cash`
- `status` enum `unavailable|pending_sync|blocked|mismatch|matched|superseded`
- `input_fingerprint char(64)`
- `coverage_started_at`, `coverage_ended_at`
- nullable `opening_repository_ordinal`, `closing_repository_ordinal`
- `expected_amount`, `actual_amount`, `variance_amount decimal(15,3) nullable`
- `currency char(3)`
- validated `dependency_snapshot jsonb`
- `reason_code`, `reason_detail`
- `supersedes_id nullable`
- timestamps
- unique `(company_id, z_report_id, check_type, input_fingerprint)`
- append-only guard

Create `pos_cash_count_dispatches`:

- scope/Z/fiscal identities
- `status` enum `pending|dispatching|dispatched|failed`
- attempts, last error, claim/dispatched timestamps
- unique `(company_id, z_report_id)`

Reconciliation state machine:

```text
unavailable                      capability or owner policy absent
pending_sync                     declared members/projections not complete
blocked                          ambiguous/configuration/dependency evidence
mismatch                         complete comparable inputs disagree
matched                          complete comparable inputs agree
any prior result → superseded    a late member changes the fingerprint
```

A new run is appended for a changed fingerprint. Existing results are not edited into a different conclusion.

### 8.6 Conditional migration `2026_09_06_090500_create_repository_balance_alignments.php`

Create only after D-WC-03 confirms an accounting treatment:

- `repository_balance_alignments`
- scope/repository/effective date
- observed physical balance, pre-alignment ledger balance, signed difference, currency
- accounting policy and GL purpose/account IDs
- evidence, preparer, reviewer, approval timestamps
- status enum `draft|reviewed|posted|reversed`
- movement/JE/reversal links
- company-scoped operation UUID and source fingerprint
- unique `(company_id, operation_uuid)`

No alignment value is backfilled. A row requires an operator-entered physical count and review evidence.

## 9. Device SQLite versions

The current maximum device migration is v67 (`apps/pos/src/lib/db/migrations.ts:2163-2182`). Reserve, in order:

- **v68 — `add_w_cash_policy_and_typed_drawer_operation_fields`**
  - Add nullable `reason_code`, `semantic_type`, `destination_repository_id`, `currency_code`, and `policy_revision` to `offline_cash_drawer_ops`.
  - Create `w_cash_policy_cache` keyed by terminal, company, location, and revision with capability state and typed options.
  - Existing rows remain null/unclassified; the migration does not infer semantics from `reason`.
- **v69 — `create_pos_session_close_manifests`**
  - Create local append-only manifest storage with shift/session/Z IDs, schema version, source identities/ranges, fingerprint, authored timestamp, and sync state.
  - The manifest is an unsealed sidecar and does not alter fiscal canonical bytes.

Create `apps/pos/src/lib/db/__tests__/migrations.v68.test.ts` and `apps/pos/src/lib/db/__tests__/migrations.v69.test.ts`. If another lane lands device migrations before implementation starts, rebase first and reserve the next two contiguous versions; never reuse or renumber a released migration.

## 10. Convention-09 named tests

All three required journeys run by path on SQLite and PostgreSQL 16. PostgreSQL is authoritative for constraints, concurrency, and lock behavior.

| Obligation | File/class/case | Lane | Required data-meaning assertion |
|---|---|---|---|
| Second company through real creation paths | `apps/api/tests/Feature/Treasury/WCashSecondOfEverythingTest.php` → `WCashSecondOfEverythingTest::test_registration_then_real_second_company_creation_provisions_independent_disabled_cash_custody()` | SQLite + PG | First call real `POST /api/v1/auth/register`, then authenticated `POST /api/v1/companies`. Company B receives its own drawer/safe and disabled configuration; company A cannot list, resolve, or book against B’s rows. Same repository codes can exist per company. |
| Second selected location | Same file → `test_selected_second_pos_location_uses_its_own_drawer_and_never_the_default_location_drawer()` | SQLite + PG | Create location B through real `POST /api/v1/locations` with `pos_enabled=true`; bind the terminal there; its obligation/document/legs reference B’s drawer, not MAIN or the first active repository. |
| Re-run/idempotency | Same file → `test_replaying_the_same_source_returns_one_result_without_duplicate_document_legs_or_balance_change()` | SQLite + PG | Second execution returns `idempotentReplay=true`; one obligation, one document, two legs, unchanged ordinals/balances, and no second JE. |
| Two-company concurrency | `apps/api/tests/Feature/Treasury/WCashBookingConcurrencyTest.php` → `test_same_source_racing_in_two_workers_converges_per_company_without_cross_company_collision()` | PG only | Same source UUID in two companies remains independent; two workers in one company converge to one document/two legs. |
| Shared drawer | `apps/api/tests/Feature/Treasury/WCashSharedDrawerPolicyTest.php` → cases named for the selected D-WC-01 policy | SQLite + PG | No second float and no double physical count. |
| Module off | `apps/api/tests/Feature/Fiscal/TreasuryShiftCashFiscalProjectionTest.php` → `test_treasury_module_off_creates_no_booking_effect_and_no_error()` | SQLite + PG | No obligation/document/movement/JE; fiscal event and core POS projection remain valid. |
| Recovery G6 | `apps/api/tests/Feature/Fiscal/WCashProjectionRecoveryTest.php` → three cases specified in T1/T5 | SQLite + PG, worker-death concurrency on PG | Registry outage, lost enqueue, and post-effect worker death each converge to one semantic result or a visible blocked row. |

Update `apps/api/tests/feature-lane-manifest.json` and the live PostgreSQL CI lane so none of these classes is parked or unexecuted.

## 11. Execution tasks

### T0 — Freeze the code census and live preactivation census

**Create**

- `docs/superpowers/audits/2026-09-05-w-cash-census.md`
- `apps/api/app/Modules/Treasury/Infrastructure/Commands/WCashCensusCommand.php`
- `apps/api/tests/Feature/Treasury/WCashCensusCommandTest.php`

**Contract**

```text
treasury:w-cash-census
  {--company=*}
  {--location=*}
  {--from=}
  {--to=}
  {--format=table|json}
  {--fail-on=unconfigured|ambiguous|uncovered|conflict}
```

**Red first**

`WCashCensusCommandTest::test_census_reports_each_company_location_source_rail_configuration_and_historical_gap_without_writing()` initially fails because the command does not exist. Assert snapshots of every new/effected table are identical before and after the command.

**Implementation**

- Copy the completed source/writer/lock census from this plan into the audit.
- Add live read-only counts by tenant/company/location:
  - v3 events per relevant kind, integrity, chain context, and projection state;
  - v2 rows per kind and typed/untyped disposition;
  - drawer/safe/bank repositories, currencies, GL mappings, balances, ordinals, freeze/checkpoint state;
  - uncovered source windows;
  - shared-drawer terminal candidates;
  - missing W7 manifests/results;
  - ambiguous cash operation rows;
  - variance documents already posted;
  - alignment-needed repositories.
- Record query timestamp, environment, candidate revision, row counts, and a salted output digest.
- Do not include tenant credentials or fiscal payload contents in the committed artifact.

**Reviewer gate:** treasury + fiscal-pos. No production task starts until the repository census section is accepted; capability activation also requires the live staging output.

---

### T1 — Land W4 blocked projection and standing recovery

**Create**

- `apps/api/database/migrations/tenant/2026_09_06_090000_add_blocked_state_to_fiscal_event_projections.php`
- `apps/api/app/Modules/Fiscal/Domain/Exceptions/ProjectionBlockedException.php`
- `apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionBlockedReason.php`
- `apps/api/tests/Feature/Fiscal/FiscalProjectionBlockedRecoveryTest.php`
- `apps/api/tests/Feature/Fiscal/FiscalProjectionStandingRecoveryTest.php`

**Modify**

- `apps/api/app/Modules/Fiscal/Domain/Enums/ProjectionStatus.php`
- `apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventProjectionRow.php`
- `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php`
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionDispatcher.php`
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalEventProjectionRegistry.php`
- `apps/api/app/Modules/Fiscal/Infrastructure/Commands/RetryFiscalProjectionsCommand.php`
- `apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php`
- `apps/api/app/Modules/Fiscal/Presentation/Controllers/DeadLetteredProjectionsController.php`
- corresponding Fiscal routes/resources
- `apps/api/tests/Unit/Config/HorizonQueueCoverageTest.php`

**Red-first cases**

- `test_actionable_configuration_failure_becomes_blocked_without_consuming_attempts()`
- `test_newer_configuration_revision_requeues_one_blocked_projection()`
- `test_registry_exception_at_ingest_is_recovered_as_one_missing_projection_row()`
- `test_pending_attempts_zero_after_lost_enqueue_is_redispatched()`
- `test_stale_running_after_effect_commit_replays_to_one_semantic_effect()`

**Implementation**

- Catch only `ProjectionBlockedException` as blocked; other exceptions retain current retry/dead-letter behavior.
- Expose blocked reason, age, configuration revision, and recovery action in the existing projection operations endpoint.
- Extend standing recovery to create missing rows for currently active projectors, enqueue pending attempts-zero rows, and reclaim stale-running rows.
- Use the existing `fiscal-projections` queue. It is already consumed by Horizon (`apps/api/config/horizon.php:206-209`); do not create a W-CASH queue.
- Preserve terminal `applied` and `dead_lettered` behavior.

**Reviewer gate:** fiscal-pos. T5 cannot register the W-CASH projector before T1 passes.

---

### T2 — Land W7 close manifest, result store, and v3 cash-count producer

**Create**

- `apps/api/database/migrations/tenant/2026_09_06_090400_create_pos_close_manifest_and_reconciliation_tables.php`
- `apps/api/app/Modules/POS/Domain/Enums/ReconciliationCheckType.php`
- `apps/api/app/Modules/POS/Domain/Enums/ReconciliationStatus.php`
- `apps/api/app/Modules/POS/Domain/Enums/CashCountDispatchStatus.php`
- `apps/api/app/Modules/POS/Domain/SessionCloseManifest.php`
- `apps/api/app/Modules/POS/Domain/PosSessionReconciliation.php`
- `apps/api/app/Modules/POS/Domain/CashCountDispatch.php`
- `apps/api/app/Modules/POS/Application/DTOs/SessionCloseManifestData.php`
- `apps/api/app/Modules/POS/Application/DTOs/PosSessionReconciliationData.php`
- `apps/api/app/Modules/POS/Application/Services/PosSessionReconciliationService.php`
- `apps/api/app/Modules/POS/Application/Services/CashCountRecordedFactory.php`
- `apps/api/app/Modules/POS/Application/Jobs/DispatchProjectedCashCountJob.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/SessionCloseManifestController.php`
- `apps/api/app/Modules/POS/Presentation/Requests/StoreSessionCloseManifestRequest.php`
- `apps/api/app/Modules/POS/Infrastructure/Commands/RecoverCashCountDispatchesCommand.php`
- `apps/api/tests/Feature/POS/PosSessionReconciliationServiceTest.php`
- `apps/api/tests/Feature/POS/SessionCloseManifestSyncTest.php`
- `apps/api/tests/Feature/POS/ProjectedCashCountDispatchTest.php`
- `apps/pos/src/lib/db/__tests__/migrations.v69.test.ts`
- device manifest authoring/sync tests beside the new implementation

**Modify**

- `apps/api/app/Modules/POS/Application/Projections/ZReportProjection.php`
- `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php`
- `apps/api/app/Modules/POS/Application/Services/CashCountDispatcher.php`
- `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`
- `apps/api/app/Modules/POS/Presentation/routes.php`
- `apps/api/tests/Architecture/ProjectorEmissionRatchetTest.php`
- `apps/api/config/pos.php`
- `apps/pos/src/lib/db/migrations.ts`
- `apps/pos/src/lib/fiscal/zSessionAuthoring.ts`
- `apps/pos/src/lib/offline/zReportService.ts`
- POS sync service and terminal policy authentication files selected by W2
- `packages/shared/types/generated.d.ts`

**Flags**

```text
POS_V3_CASH_COUNT_DISPATCH_ENABLED=false
```

The Z projection always records the durable obligation, but dispatch remains disabled until W7 and W-CASH readiness pass.

**Red-first cases**

- `test_v3_verified_live_z_creates_one_pending_cash_count_dispatch_obligation()`
- `test_training_z_creates_no_cash_count_dispatch_or_money_reconciliation()`
- `test_dispatch_replay_emits_idempotently_from_immutable_z_payload()`
- `test_late_manifest_member_creates_new_fingerprint_and_supersedes_prior_result()`
- `test_missing_refund_or_cash_operation_keeps_result_pending_sync_not_matched()`
- `test_out_of_manifest_event_naming_the_session_is_blocked_not_deleted()`
- `test_cash_count_dispatch_failure_is_recoverable_after_z_projection_is_already_applied()`

**Implementation**

- Factor all three current producers through `CashCountRecordedFactory`; current server producers are in `ReportGenerationService` and `ZReportSyncController` (`apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:433`; `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:549-580`).
- Create the v3 dispatch obligation inside the Z projection transaction, then enqueue after commit.
- Do not depend on re-running an already-applied Z projector to recover dispatch.
- Build manifest membership from explicit source identities and sequence namespaces for:
  - Z-session lifecycle/movement events;
  - sale receipts;
  - refunds;
  - account payments/collections;
  - relevant v2 drawer rows;
  - repository transfer documents/movements for the optional repository check.
- Never use receipt count as all-event completeness.
- Preserve `ShiftExpectedCashService`; extend membership-aware inputs there rather than copying arithmetic.
- Require W2 terminal authority before enabling the manifest sync endpoint.
- Update the architecture ratchet only when the v3 producer and its durable recovery test are green.

**Reviewer gate:** fiscal-pos + treasury for repository-check schema. This task is a hard prerequisite for T10.

---

### T3 — Add disabled cash-custody configuration and canonical DTOs

**Create**

- `apps/api/database/migrations/tenant/2026_09_06_090100_create_treasury_cash_custody_configs.php`
- `apps/api/app/Modules/Treasury/Domain/CashCustodyConfiguration.php`
- `apps/api/app/Modules/Treasury/Domain/Enums/WCashCapabilityState.php`
- `apps/api/app/Modules/Treasury/Application/DTOs/PaymentRepositoryData.php`
- `apps/api/app/Modules/Treasury/Application/DTOs/CashCustodyConfigurationData.php`
- `apps/api/app/Modules/Treasury/Application/Services/CashCustodyConfigurationService.php`
- `apps/api/app/Modules/Treasury/Presentation/Controllers/CashCustodyConfigurationController.php`
- `apps/api/app/Modules/Treasury/Presentation/Requests/UpdateCashCustodyConfigurationRequest.php`
- `apps/api/tests/Feature/Treasury/CashCustodyConfigurationTest.php`
- `apps/api/tests/Feature/Treasury/WCashSecondOfEverythingTest.php`

**Modify**

- `apps/api/app/Modules/Treasury/Presentation/routes.php`
- `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php`
- `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`
- `apps/api/database/seeders/PermissionSeeder.php`
- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- `apps/web/src/features/treasury/RepositoryDetailPage.tsx`
- `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts`
- relevant repository-detail tests and `en/fr/ar` Treasury locale files
- `packages/shared/types/generated.d.ts`
- `docs/glossary.md`
- `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php`
- `apps/api/tests/Architecture/baselines/tenant-only-unique-baseline.json` only if the live-schema ratchet requires classification

**Endpoint and authorization**

```text
GET   /api/v1/payment-repositories/{repository}/cash-custody
PATCH /api/v1/payment-repositories/{repository}/cash-custody
POST  /api/v1/payment-repositories/{repository}/cash-custody/validate
POST  /api/v1/payment-repositories/{repository}/cash-custody/enable
POST  /api/v1/payment-repositories/{repository}/cash-custody/disable
```

All routes require `module:Treasury`; reads require `repositories.view`, edits/validation require `treasury.manage`, enable/disable require `treasury.manage_all_locations` or the owner-approved custody authority. Frontend fields require `hasModule('Treasury')` and the matching permission.

**Red-first cases**

- Wrong-company repository ID returns scoped 404 and writes nothing.
- Wrong-location drawer, wrong type, inactive repository, and currency mismatch fail validation.
- Missing W1/W2, open owner decisions, or alignment disposition prevents `ready/enabled`.
- Module-off routes return 403/404 per established module behavior and fields are hidden.
- Real second company and second location tests from §10 fail before implementation.

**Implementation**

- Reuse repository detail/editor; no second settings page.
- New registrations may create a disabled config row only when the provisioner has exact deterministic drawer/safe identities. Existing companies receive no guessed mappings.
- A second location may receive a disabled row with its exact drawer and a null safe until configured.
- Configuration revisions are append-audited.
- Run `CACHE_STORE=array php artisan typescript:transform`; frontend imports generated DTOs only.
- C4/manual custody activation waits for W2 terminal authority and W1 location-scope rollout. Current repository endpoints are company-scoped but not staff-location-scoped (`docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:131-153`).

**Reviewer gate:** treasury + tenancy-authz + frontend-conventions.

---

### T4 — Add one transfer document and explicit offline transfer policy

**Create**

- `apps/api/database/migrations/tenant/2026_09_06_090200_create_repository_transfer_documents.php`
- `apps/api/app/Modules/Treasury/Domain/RepositoryTransferDocument.php`
- transfer-document enums and DTOs named in §§7-8
- `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferDocumentService.php`
- `apps/api/tests/Feature/Treasury/RepositoryTransferDocumentServiceTest.php`
- `apps/api/tests/Feature/Treasury/RepositoryTransferDocumentConcurrencyTest.php`
- `apps/api/tests/Feature/Treasury/RepositoryTransferFrozenReplayTest.php`
- `apps/api/tests/Feature/Treasury/RepositoryTransferReversalTest.php`

**Modify**

- `apps/api/app/Modules/Treasury/Application/DTOs/TransferIntent.php`
- `apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php`
- `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php`
- `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php`
- `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferResult.php`
- `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php`
- `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryMovementController.php`
- `apps/api/tests/Architecture/TreasuryBalanceWritePortTest.php`
- existing transfer service/HTTP tests

**Red-first cases**

- `test_one_action_commits_one_document_one_group_two_cross_linked_legs_and_one_cross_gl_entry()`
- `test_same_gl_transfer_has_two_legs_and_no_journal_entry()`
- `test_crash_before_second_leg_rolls_back_document_entry_legs_and_balances()`
- `test_same_source_replay_returns_original_document_and_pair()`
- `test_same_source_with_changed_amount_destination_currency_or_fingerprint_is_conflict()`
- `test_verified_offline_transfer_across_frozen_repository_sets_flags_and_alerts_atomically()`
- `test_checkpoint_replay_is_returned_but_new_interactive_transfer_is_refused()`
- `test_offline_drop_cannot_make_drawer_negative()`
- `test_reversal_creates_new_document_and_opposite_pair_without_mutating_original()`

**Implementation**

- Refactor every new manual transfer through `RepositoryTransferDocumentService`.
- Preserve old historical transfer legs without fabricating documents.
- Change `RepositoryTransferService` to inject the shared movement interface rather than the concrete implementation.
- Use deterministic UUIDv5 document/group IDs derived from company, source key, and document kind.
- Compare every material field and evidence fingerprint on replay.
- Ensure alerts are outside neither transaction nor idempotency: record a durable once-per-group alert obligation in the transaction, then deliver after commit.
- Keep global lock order GL → sorted repositories.
- Do not relax negative drawer rules.

**Reviewer gate:** treasury + accounting/stock-gl-interaction.

---

### T5 — Implement the shared booking service and v3 fiscal adapter

**Create**

- `apps/api/database/migrations/tenant/2026_09_06_090300_create_shift_cash_booking_obligations.php`
- booking domain enums/DTOs from §§7-8
- `apps/api/app/Modules/Treasury/Application/Services/ShiftCashBookingService.php`
- `apps/api/app/Modules/Treasury/Application/Projections/TreasuryShiftCashFiscalProjection.php`
- `apps/api/tests/Feature/Fiscal/TreasuryShiftCashFiscalProjectionTest.php`
- `apps/api/tests/Feature/Fiscal/WCashProjectionRecoveryTest.php`
- `apps/api/tests/Feature/Treasury/ShiftCashBookingServiceTest.php`
- `apps/api/tests/Feature/Treasury/WCashBookingPrecisionTest.php`

**Modify**

- `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php`
- `apps/api/tests/Architecture/ProjectorEmissionRatchetTest.php`
- projector registration-set tests
- `apps/api/tests/feature-lane-manifest.json`
- `.github/workflows/ci.yml` if the new PostgreSQL classes are not already collected by the live Treasury/Fiscal lanes

**Projector contract**

```php
name(): 'treasury_shift_cash_booking'
requiresModule(): 'Treasury'
priority(): greater than pos_core_z_session_lifecycle
handlesEventType(): OPENING_FLOAT | CASH_IN | CASH_OUT | SAFE_DROP
```

**Red-first cases**

- Verified live `OPENING_FLOAT` creates safe → drawer document and pair once.
- `SESSION_OPEN` is not handled and cannot double the float.
- Training and unverified events create no money.
- Disabled/unconfigured capability produces a blocked projection with a stable reason.
- Module-off produces no projection effect/error.
- `SAFE_DROP` before prior receipt coverage is visible dependency-blocked, not negative.
- Registry exception, lost enqueue, and worker death after financial commit satisfy G6.
- TND `200.000` and EUR `200.00` normalize correctly.
- Zero, sub-minor, overprecision, and repository/event currency mismatch fail without writes.
- `CompanyContext::clear()` precedes projector application.

**Implementation**

- Persist/upsert the source obligation before calling the booking service.
- Resolve terminal → location and shift/session provenance from persisted source columns; never from the current HTTP company.
- Use `OPENING_FLOAT` as the only opening money source.
- Implement `SAFE_DROP` immediately.
- Keep `CASH_IN`/`CASH_OUT` blocked until D-WC-02 is confirmed.
- Keep opening booking blocked until D-WC-01 is confirmed.
- For every applied transfer assert document amount = out amount = in amount = JE amount string.
- Add no new queue; projection uses `fiscal-projections`.

**Reviewer gate:** treasury + fiscal-pos + accounting.

---

### T6 — Implement the durable v2 adapter and catch-up

**Create**

- `apps/api/app/Modules/POS/Domain/Events/CashDrawerOperationAuthoredV2.php`
- `apps/api/app/Modules/Treasury/Application/Listeners/QueueV2ShiftCashBooking.php`
- `apps/api/app/Modules/Treasury/Infrastructure/Commands/WCashCatchUpV2Command.php`
- `apps/api/tests/Feature/Treasury/V2ShiftCashSourceAdapterTest.php`
- `apps/api/tests/Feature/Treasury/WCashV2CatchUpCommandTest.php`

**Modify**

- `apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php`
- `apps/api/app/Modules/POS/Domain/Services/ShiftManagementService.php`
- POS and Treasury service providers
- existing drawer-operation event tests

**Event contract**

```php
CashDrawerOperationAuthoredV2(
    operationId,
    tenantId,
    companyId,
    locationId,
    terminalId,
    shiftId,
    operationType,
    amount,
    currency,
    reason,
    userId,
    recordedAt,
)
```

**Command**

```text
treasury:w-cash-catch-up-v2
  {--company=*}
  {--location=*}
  {--from=}
  {--to=}
  {--dry-run}
  {--apply}
  {--limit=500}
```

**Red-first cases**

- New v2 opening emits the versioned event after commit and books through `ShiftCashBookingService`.
- A v2 row and v3 event sharing `cash_drawer_operation_id` converge on one obligation/document.
- V2 `SALE`, `REFUND`, and `CLOSING` are ignored.
- V2 `DEPOSIT` and `PAYOUT` remain blocked until D-WC-02 supplies the mapping.
- Historical catch-up creates obligations only for exact source rows in the approved interval.
- Rerun reports `already_exists`, with no duplicate effects.
- A claimed row abandoned by a worker is reclaimed without holding the source-row lock during Treasury booking.

**Implementation**

- Do not modify `CashDrawerOperationRecorded`.
- Dispatch the new versioned event only after the drawer row transaction commits.
- Derive tenant/company/location/currency by joining the persisted shift and terminal; fail blocked if any provenance is missing.
- `--dry-run` is the default; `--apply` requires an explicit bounded `--from`.
- Catch-up creates obligations, not guessed transfer documents. The common service determines whether a resolved obligation may book.

**Reviewer gate:** treasury + fiscal-pos.

---

### T7 — Add typed device authoring and drawer ownership, conditional on D-WC-01/D-WC-02

**Create**

- `apps/pos/src/lib/db/__tests__/migrations.v68.test.ts`
- typed cash-operation policy models/tests
- drawer-lease or drawer-session implementation/tests selected by D-WC-01

**Modify**

- `apps/pos/src/lib/db/migrations.ts`
- `apps/pos/src/api/cashDrawerApi.ts`
- relevant POS store/components for cash in/out
- terminal policy sync/hydration files owned by W2
- translations and accessibility tests

**Red-first cases**

- Device refuses an enabled cash operation when policy cache is absent, stale, wrong-company, wrong-terminal, or wrong-location.
- `SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, and `FLOAT_TOP_UP` produce the approved typed reason and destination class.
- `OTHER` is blocked from money booking until classified.
- Existing nullable/untyped rows remain readable and syncable but cannot be silently treated as safe transfers.
- Selected D-WC-01 cases prove no second float.
- Hydrated/restarted offline device retains only its own terminal/location policy.
- Capability disabled hides/disables new authoring independently of Treasury module availability.

**Implementation**

- Use v68 exactly.
- Transport the policy through W2’s authenticated terminal policy projection; do not reuse the human repository list.
- Store repository IDs only as authored policy bindings, never cashier choices.
- Preserve existing fiscal event shape; existing `reason_code`, `reason_text`, and `cash_drawer_operation_id` fields carry the typed policy result.
- Do not activate until W2 terminal authority and W1 location-scope transition are complete.

**Reviewer gate:** fiscal-pos + tenancy-authz + frontend accessibility.

---

### T8 — Complete the existing repository surface and safe → bank flow

**Modify**

- `apps/web/src/features/treasury/RepositoryDetailPage.tsx`
- `apps/web/src/features/treasury/RepositoryDetailPage.test.tsx`
- `apps/web/src/features/treasury/RepositoryListPage.tsx`
- `apps/web/src/features/treasury/RepositoryListPage.test.tsx`
- `apps/web/src/features/treasury/components/TransferCashModal.tsx`
- `apps/web/src/features/treasury/components/TransferCashModal.test.tsx`
- `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts`
- `apps/web/src/features/treasury/hooks/useTransferCash.ts`
- `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php`
- `apps/api/app/Modules/Treasury/Presentation/Requests/TransferRepositoryRequest.php`
- generated DTOs/types and `en/fr/ar` locales

**Red-first cases**

- Existing transfer modal supports safe → bank through one document/two-leg service.
- Drawer → safe and safe → bank appear in existing repository movement history with document, opposite repository, source, reason, and JE link.
- Wrong-location source is refused with zero side effects.
- Allowed branch → configured central destination succeeds; central → branch still requires central authority.
- No module or permission hides the action and fields.
- Local repository interfaces are gone; generated types compile.

**Implementation**

- Add document/source metadata to existing movement responses.
- Add configuration controls to repository detail without adding a second modal or catalogue.
- Reuse `treasury.transfer`; add only a distinct `treasury.manage_cash_custody` permission if the owner/security reviewer requires configuration separation.
- Wait for W2 terminal authority and W1 location-scope rollout before activation.

**Reviewer gate:** treasury + tenancy-authz + frontend-conventions. Run React diagnostics for the touched feature before handback.

---

### T9 — Implement opening-balance alignment, conditional on D-WC-03

**Create/modify only after the decision**

- `apps/api/database/migrations/tenant/2026_09_06_090500_create_repository_balance_alignments.php`
- alignment model/enums/DTOs/service/controller/request
- repository-detail alignment panel in the existing surface
- `apps/api/tests/Feature/Treasury/RepositoryBalanceAlignmentTest.php`
- `apps/api/tests/Feature/Treasury/RepositoryBalanceAlignmentConcurrencyTest.php`
- glossary/generated types/locales

**Red-first cases**

- Repository with historical movements cannot use ordinary opening-balance service.
- Draft alignment writes no money.
- Posting requires physical count, evidence, effective date, approved GL purposes/accounts, separate reviewer, and an exact pre-alignment ledger balance.
- Stale balance or concurrent movement refuses posting.
- Post creates one alignment document, one adjustment movement, and one JE; rerun is idempotent.
- Reversal compensates without mutating original.
- No automatic command creates alignment amounts or posts historical variance.
- Module/company/location/permission denials write nothing.

**Implementation**

- Capture the repository balance and ordinal during review; compare them under the GL → repository lock order at post time.
- Normalize once with explicit currency.
- Store the selected owner/accounting policy and review evidence.
- Mark historical W7 repository checks before the effective date `unavailable: pre_cutover_uncovered`, not matched.
- If D-WC-03 chooses no accounting alignment, do not create this table/service; record the unavailable historical disposition and keep affected capability disabled.

**Reviewer gate:** treasury + accounting + tenancy-authz + owner/accountant evidence.

---

### T10 — Repository coverage reconciliation and variance activation

**Create**

- `apps/api/app/Modules/Treasury/Application/Services/RepositoryCashCoverageService.php`
- typed coverage DTOs
- `apps/api/tests/Feature/Treasury/RepositoryCashCoverageTest.php`
- `apps/api/tests/Feature/Treasury/WCashSharedDrawerPolicyTest.php`
- `apps/api/tests/Feature/Treasury/WCashEndToEndPostgresTest.php`

**Modify**

- `apps/api/app/Modules/POS/Application/Services/PosSessionReconciliationService.php`
- `apps/api/app/Modules/POS/Application/Services/ShiftExpectedCashService.php` only for membership-aware source selection, not duplicated arithmetic
- `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php`
- existing variance tests
- shift/Z detail API DTO and existing UI
- `apps/api/config/treasury.php`

**Coverage algorithm**

For each close:

1. Load the immutable close manifest and exact fiscal source set.
2. Require every source projection and W-CASH obligation in that set to be applied or explicitly contained.
3. Resolve the approved drawer-custody unit from D-WC-01.
4. Record the opening repository ordinal/balance at the first covered W-CASH movement and closing ordinal at the last pre-variance covered movement.
5. Include:
   - opening float document;
   - receipt/refund/account-collection movements in the manifest;
   - typed cash-operation documents;
   - existing back-office drawer movements exactly once only when explicitly linked to the interval;
   - no bank-settlement movements in the drawer check.
6. Fingerprint ordered source identities, hashes, document IDs, movement IDs, configuration revisions, repository IDs, and ordinal bounds.
7. Compare fiscal expected cash with the repository balance at the stored closing ordinal, never with the repository’s current cached balance.
8. Append `matched`, `mismatch`, `pending_sync`, `blocked`, or `unavailable`.
9. A late valid member creates a new fingerprint and supersedes the prior result.
10. Permit variance posting only when the latest repository check is `matched` for exactly the `CashCountRecorded` Z/fingerprint.
11. Post the signed physical-count variance once through the existing adjustment service.
12. Store the pre-variance closing ordinal in the result; the variance movement is not retroactively included in its own comparison.

**Red-first cases**

- Correct acceptance order: opening float `200.000`, cash sales `3500.000`, then safe drop `1000.000` gives pre-variance drawer `2700.000`; closing count `2690.000` posts one `10.000` out variance.
- The original unsafe order—drop before sufficient custody—is refused/blocked and does not make the drawer negative.
- Repository balance equals expected before variance and counted cash after variance.
- Current balance changed by a later shift does not affect the prior stored check.
- Manual movement is included exactly once only when linked.
- Missing/untyped movement is blocked, never zero.
- Duplicate cash-count dispatch posts no second adjustment.
- TND/EUR precision, zero, sub-minor, overprecision, and currency conflict.
- Shared-drawer behavior follows D-WC-01 exactly.
- Existing fiscal-total `matched` cannot be displayed as full reconciliation when repository check is unavailable.
- Worker runs with `CompanyContext::clear()`.

**Enablement rule**

`TREASURY_SHIFT_VARIANCE_GL_ENABLED` remains the global worker kill switch. Per-company/location W-CASH capability and a current matched coverage result are additional mandatory gates. Neither replaces the other.

**Reviewer gate:** treasury + fiscal-pos + accounting.

---

### T11 — Cutover, recovery proof, and handback

**Create**

- `docs/handoff/HANDBACK-W-CASH-2026-09-06.md`
- update `docs/superpowers/audits/2026-09-05-w-cash-census.md` with staging evidence
- update deployment notes if the existing variance deployment ticket remains authoritative

**Required handback contents**

- Actual candidate 40-character revision.
- Exact migration identifiers and device migration versions.
- Red and green command/output per task and test path.
- PostgreSQL version/container/database used.
- Before/after live census counts by company/location without secrets.
- Capability/configuration revisions enabled.
- Owner decision references.
- Backup and restore rehearsal evidence.
- Worker/API flag parity evidence.
- Horizon/Redis reachability and queue-depth evidence.
- Catch-up dry-run/apply counts.
- Every blocked/conflict/unavailable row and disposition.
- Screenshot or rendered evidence for repository and shift/Z surfaces.
- Rollback exercise and post-rollback recovery result.

**Reviewer gate:** treasury + fiscal-pos + tenancy-authz + frontend-conventions + final owner/accounting launch gate.

## 12. Deployment and cutover order

Pushes to `dev` auto-deploy to staging, so every push must be safe with no operator configuration.

### Phase A — backup and additive inert deployment

1. Back up the staging tenant databases and rehearse restore to an isolated target.
2. Deploy T1-T4 additive migrations, enums, models, compatible readers, DTOs, and hidden UI.
3. Deploy W-CASH projector with blocked-state support already present.
4. Keep:
   - every `treasury_cash_custody_configs.capability_state = disabled`;
   - `POS_V3_CASH_COUNT_DISPATCH_ENABLED=false`;
   - `TREASURY_SHIFT_VARIANCE_GL_ENABLED=false`.
5. Confirm old POS/device builds, old fiscal events, old repository APIs, and existing Treasury writers still work.
6. Confirm `default` and `fiscal-projections` are consumed by Horizon and Redis is reachable.
7. Do not run financial catch-up.

The inert projector may create blocked obligations for new relevant live events. It must not book money, retry to dead-letter, or affect fiscal Z processing.

### Phase B — policy and configuration readiness

1. Obtain and record D-WC-01, D-WC-02, and D-WC-03.
2. Complete W2 terminal authority and W1 location-scope prerequisites.
3. Configure exact drawer/safe/bank mappings per company/location.
4. Validate currencies, repository types, GL accounts, permissions, terminal location, and configuration fingerprint.
5. Run `treasury:w-cash-census --format=json --fail-on=unconfigured`.
6. Resolve or explicitly exclude every ambiguous v2 operation.
7. Perform owner-approved alignment, or mark the historical interval unavailable and leave capability disabled.
8. Transition configurations only to `ready`, not enabled.

### Phase C — enable booking and bounded catch-up

1. Deploy device v68/v69 and confirm server readers first.
2. Enable W-CASH booking on workers before exposing device authoring.
3. Transition one staging company/location from `ready` to `enabled`.
4. Requeue its blocked projections by configuration revision.
5. Run bounded dry-runs:
   - v3 missing projection recovery from the approved cutover;
   - v2 catch-up from the approved cutover.
6. Compare exact source/obligation/document/leg counts.
7. Apply catch-up.
8. Enable device typed authoring for the same terminal/location.
9. Verify new and replayed float/drop/safe → bank flows before expanding to another location.

### Phase D — enable W7 cash-count production

1. Confirm close-manifest v69 is hydrated and syncing.
2. Confirm fiscal-total and repository checks store durable non-false-positive outcomes.
3. Enable `POS_V3_CASH_COUNT_DISPATCH_ENABLED` in the worker processes.
4. Restart workers and verify configuration cache values.
5. Enable the same flag for API processes that execute legacy/live producers.
6. Confirm API and worker values match and pending dispatch obligations drain once.

### Phase E — enable variance

1. Confirm the target location’s repository check is `matched` for a real close.
2. Enable `TREASURY_SHIFT_VARIANCE_GL_ENABLED=true` in Horizon/worker processes first.
3. Restart Horizon and verify the effective value.
4. Enable the API-side value second so new synchronous producers may enqueue.
5. Run the `200 + 3500 - 1000 = 2700`, counted `2690`, variance `-10` staging journey.
6. Verify one variance document, JE, movement, audit event, and final drawer balance.
7. Expand company/location enablement individually; never enable by Treasury entitlement alone.

## 13. Backfills that invent nothing

Permitted backfills:

- Create missing fiscal projection rows for immutable existing fiscal events.
- Create booking obligations from exact existing v3 event IDs/hashes or v2 operation IDs/values.
- Create cash-count dispatch obligations from exact verified Z payloads.
- Derive manifest/reconciliation states only where explicit source membership is provable.
- Annotate existing sources with capability/configuration revisions.
- Mark historical intervals `unavailable`, `pending_sync`, or `blocked`.

Forbidden backfills:

- Guess drawer/safe/bank mappings from “first” or “default” repositories.
- Infer `PAYOUT` or `CASH_IN/CASH_OUT` meaning from free text.
- Generate missing physical counts, approvals, reasons, terminal assignments, or event hashes.
- Recreate historical transfer documents/movements from expected-cash arithmetic.
- Treat current repository balance as a historical closing balance.
- Post historical variance automatically.
- Rewrite fiscal events, Z reports, repository movements, or posted journal entries.

## 14. Rollback

Operational rollback is flag/capability based:

1. Disable device W-CASH authoring.
2. Set affected per-company/location capability to `disabled`.
3. Set `POS_V3_CASH_COUNT_DISPATCH_ENABLED=false`.
4. Set `TREASURY_SHIFT_VARIANCE_GL_ENABLED=false` in API and worker environments.
5. Restart Horizon and verify effective values.
6. Stop catch-up commands.
7. Preserve new schemas, readers, obligations, documents, movements, manifests, and results.
8. Keep ingest/recovery capable of reading already-authored v68/v69 data.
9. Never delete or mutate committed transfer documents, movements, or JEs.
10. Correct an erroneous posted transfer only through its reversal contract.
11. Use database restore only for a failed deployment before new business writes, not as routine financial rollback.
12. Do not run destructive migration `down()` against financial history.

## 15. Dispatch order

- [ ] T0 — freeze repository census and implement read-only live census command.
- [ ] T1 — land blocked projection state and all three G6 recovery paths.
- [ ] T2 — land W7 manifest/result store and durable v3 cash-count obligation, flag off.
- [ ] T3 — land disabled cash-custody configuration, module/permission gates, glossary, and generated DTOs.
- [ ] T4 — land transfer document, two-leg invariant, reversal, and offline frozen/checkpoint policy.
- [ ] Record D-WC-01, D-WC-02, and D-WC-03.
- [ ] Complete W2 terminal authority and W1 location-scope prerequisites.
- [ ] T5 — land shared booking service and v3 adapter; only resolved operation branches become runnable.
- [ ] T6 — land v2 versioned event adapter and bounded catch-up.
- [ ] T7 — land device v68 typed authoring/shared-drawer policy and v69 close manifest.
- [ ] T8 — extend the existing repository/transfer surfaces; no duplicate UI.
- [ ] T9 — implement alignment only if D-WC-03 authorizes it.
- [ ] T10 — land repository coverage and variance gate, both flags off.
- [ ] Execute staged cutover phases A-E one company/location at a time.
- [ ] T11 — write complete handback with candidate revision and staging proof.

## 16. Verification checklist

### Architecture and schema

- [ ] All six migrations are additive, self-guarding, and tested on SQLite and PostgreSQL where applicable.
- [ ] Device migrations reserve v68 and v69 from current max v67.
- [ ] Every status/type/code column has a PHP enum and PostgreSQL CHECK parity test.
- [ ] Every new company-owned unique includes `company_id`.
- [ ] Catalogue/evidence table classifications pass the convention-09 ratchet.
- [ ] `TreasuryBalanceWritePortTest` confirms no writer outside `TreasuryMovementService`.
- [ ] No new queue was introduced; `HorizonQueueCoverageTest` remains green.
- [ ] `CompanyContext::clear()` is used in projection/worker tests.
- [ ] Module boundaries and constructor injection pass PHPStan/deptrac checks.

### Money and idempotency

- [ ] One transfer action produces one document, one group, two legs, zero/one JE.
- [ ] Document, both legs, and JE share one normalized amount string and currency.
- [ ] Same-source replay returns original IDs and balances.
- [ ] Changed source content produces a visible conflict.
- [ ] Frozen/checkpoint offline transfer records both-leg flags and one alert.
- [ ] Interactive frozen/checkpoint transfer remains refused.
- [ ] No transfer can make a non-negative drawer negative.
- [ ] Reversal creates a new document and opposite pair.
- [ ] External petty expense uses one adjustment/expense document and one out leg, not a transfer pair.
- [ ] TND, EUR, zero, sub-minor, overprecision, and currency-conflict cases pass.

### Rails and recovery

- [ ] `SESSION_OPEN` never books opening money.
- [ ] v3 `OPENING_FLOAT` is the sole v3 float source.
- [ ] v2 and v3 cross-rail deduplication converges on `cash_drawer_operation_id`.
- [ ] `SALE`, `REFUND`, and `CLOSING` v2 rows do not double-book.
- [ ] Unverified/training events produce no Treasury money.
- [ ] Registry failure at ingest is recovered.
- [ ] Pending attempts-zero after lost enqueue is recovered.
- [ ] Worker death in running after effect commit converges to one result.
- [ ] Blocked configuration resolution requeues exactly once.
- [ ] Module-off tenant produces no W-CASH effect or error.

### Second-of-everything and authorization

- [ ] Real registration plus authenticated real second-company creation passes.
- [ ] Company B owns independent drawer/safe/configuration and cannot see A’s.
- [ ] Real second `pos_enabled` location uses its selected drawer.
- [ ] Re-run returns explicit already-existing/idempotent outcome.
- [ ] W1 location restrictions cover list/detail/balance/transactions/movements/configuration/transfer/adjustment.
- [ ] W2 terminal policy contains only the terminal’s company/location destinations.
- [ ] New backend endpoints have `module:Treasury`.
- [ ] Existing frontend surfaces gate fields/actions by module and permission.
- [ ] Denial leaves documents, movements, JEs, configurations, and audit snapshots unchanged.

### W7 and variance

- [ ] Close manifest covers receipts, refunds, collections, and cash operations with explicit identities.
- [ ] Missing/late/out-of-manifest facts cannot yield a false `matched`.
- [ ] A changed source set appends a new fingerprint and supersedes the prior result.
- [ ] Repository comparison uses stored ordinal bounds, never current cached balance.
- [ ] Back-office movements are included exactly once only when explicitly linked.
- [ ] Fiscal-total and repository checks remain separately visible.
- [ ] v3 `CashCountRecorded` is produced durably and excludes training.
- [ ] Duplicate dispatch cannot double-post variance.
- [ ] Acceptance journey uses float → sales → drop → close order.
- [ ] `200.000 + 3500.000 - 1000.000 = 2700.000` before variance.
- [ ] Count `2690.000` posts exactly one `10.000` shortfall.
- [ ] Repository equals expected before variance and counted cash after variance.
- [ ] Shared-drawer behavior matches the recorded D-WC-01 ruling.

### Tooling and handback

- [ ] `CACHE_STORE=array php artisan typescript:transform` produces no uncommitted drift after generated types are committed.
- [ ] `composer test` passes for the named SQLite paths.
- [ ] Named PostgreSQL Treasury/Fiscal tests pass against PostgreSQL 16.
- [ ] `pnpm --filter @autoerp/pos test` and `typecheck` pass.
- [ ] `pnpm --filter @autoerp/web test`, `typecheck`, and relevant E2E coverage pass.
- [ ] React diagnostics pass for touched Treasury components.
- [ ] `pnpm build`, `pnpm lint`, `pnpm test`, and `pnpm typecheck` pass.
- [ ] `./vendor/bin/phpstan` and `./vendor/bin/pint --test` pass.
- [ ] `./scripts/preflight.sh` passes.
- [ ] Live staging census has zero unresolved targets selected for enablement.
- [ ] Backup/restore rehearsal, worker/API flag parity, Redis/Horizon health, cutover, rollback, and actual candidate revision are recorded in `docs/handoff/HANDBACK-W-CASH-2026-09-06.md`.