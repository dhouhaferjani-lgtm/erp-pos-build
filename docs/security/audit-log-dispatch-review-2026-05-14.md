# Audit-Log Dispatch Review — Privileged Actions

**Date:** 2026-05-14
**Author:** Claude Opus 4.7 (in-session)
**Scope:** T1 of the dev deferred-backlog session — audit-log integrity in production.
**Branch:** `chore/dev-deferred-backlog`

## Why this review exists

The pre-existing `tests/Feature/Security/PrivilegedAuditLogTest.php` only checks — by
reflection — that the audit event *classes* declare the right constructor fields. It never
proves the event is **dispatched** at the callsite, nor that a **listener persists** it. A
privileged action can therefore have a perfectly-shaped event class and still leave **zero
audit trail** in production if the event is never wired to a handler.

This review enumerates every privileged action, traces dispatch → listener → persisted
record, and records the result.

## How the audit log works

- Audit events are plain Laravel events extending `App\Shared\Domain\Events\DomainEvent`
  (which extends Spatie's `ShouldBeStored`, but Spatie's `stored_events` projector path is
  **not** the audit log here).
- The audit log is the **`audit_events`** table.
- `App\Modules\Compliance\Listeners\DomainEventSubscriber` is the single wiring point. Its
  `subscribe()` array maps event class → handler method. Each handler calls
  `AuditService::record()`, which writes one `audit_events` row.
- Persisted columns: `tenant_id`, `company_id`, `user_id` (actor, from `Auth::id()`),
  `event_type` (the action), `aggregate_type` + `aggregate_id` (the target), `payload`,
  `metadata`, `event_hash` (SHA-256 tamper seal), `occurred_at` (timestamp).
- `persistEvent()` wraps `AuditService::record()` in a `try/catch (\Throwable)` that logs
  and continues — audit logging must never break a business operation. The trade-off: a
  mis-wired handler is **silently invisible** in production. The dispatch tests added in
  this session are the guard against that.

## Finding F0 — `DB::afterCommit` *does* fire under `RefreshDatabase` (Laravel 12)

The session brief flagged that `ReceiptVoidService` dispatches inside `DB::afterCommit(...)`
"which does not fire under RefreshDatabase test transactions." That assumption is **outdated
for Laravel 12**. `Illuminate\Foundation\Testing\DatabaseTransactionsManager` overrides
`afterCommitCallbacksShouldBeExecuted()` to return `$level === 1` (instead of `=== 0`), so a
callback registered inside a service's own nested `DB::transaction()` fires when that nested
transaction commits back down to the RefreshDatabase wrapper level.

Consequence: **no special test harness is needed.** The end-to-end tests in this session
call the real services with plain `RefreshDatabase` and assert the `audit_events` row lands.
The `ReceiptVoidService` e2e test is included as a control — it was already wired, and it
goes green, proving the `afterCommit → subscriber → persist` chain works for the receipt-void
path the brief named explicitly.

## Per-action audit table

Legend: ✅ wired + persisted · 🟢 fixed this session · ❌ no audit trail · ⚠️ partial trail

| Privileged action | Event class | Listener (DomainEventSubscriber) | Persisted target / key payload | Status |
|---|---|---|---|---|
| Void receipt | `POS\...\ReceiptVoided` | `handleReceiptVoided` | `Receipt` / receipt_number, void_reason, voided_by, voided_at | ✅ |
| Z-report close | `POS\...\ZReportGenerated` | `handleZReportGenerated` | `ZReport` / terminal_id, z_number, fiscal_hash, generated_at | ✅ |
| Terminal activation | `POS\...\TerminalActivatedAudit` | `handleTerminalActivatedAudit` | `Terminal` / terminal_code, activated_by | ✅ (see NIT-1) |
| Terminal deactivation | `POS\...\TerminalDeactivated` | `handleTerminalDeactivated` | `Terminal` / terminal_code, reason, deactivated_by | ✅ |
| Terminal software update | `POS\...\TerminalSoftwareUpdated` | `handleTerminalSoftwareUpdated` | `Terminal` / previous_version, new_version | ✅ |
| Terminal training-mode toggle | `POS\...\TerminalTrainingModeChanged` | `handleTerminalTrainingModeChanged` | `Terminal` / enabled, changed_by | ✅ |
| Shift open | `POS\...\ShiftOpened` | `handleShiftOpened` | `Shift` / cashier_id, opening_balance, opened_at | ✅ |
| Shift close (carries variance) | `POS\...\ShiftClosed` | `handleShiftClosed` | `Shift` / expected_cash, actual_cash, variance, closed_at | ✅ |
| Cash drawer operation (deposit/withdrawal/refund) | `POS\...\CashDrawerOperationRecorded` | `handleCashDrawerOperationRecorded` | `CashDrawerOperation` / operation_type, amount, user_id | ✅ |
| Receipt created / drafted / printed | `POS\...\ReceiptCreated` / `ReceiptDrafted` / `ReceiptPrinted` | `handleReceiptCreated` / `…Drafted` / `…Printed` | `Receipt` / fiscal_hash, chain_sequence, … | ✅ |
| Invoice posted / cancelled / paid | `Document\...\InvoicePosted` / `InvoiceCancelled` / `InvoicePaid` | `handleInvoicePosted` / `…Cancelled` / `…Paid` | `Document` / document_number, fiscal_hash, … | ✅ |
| Document fully paid | `Document\...\DocumentFullyPaid` | `handleDocumentFullyPaid` | `Document` / total_paid, paid_at | ✅ |
| Payment recorded | `Treasury\...\PaymentRecorded` | `handlePaymentRecorded` | `Payment` / amount, currency, payment_method_id | ✅ |
| Invoice closed with tolerance write-off | `Treasury\...\InvoiceClosedWithTolerance` | `handleInvoiceClosedWithTolerance` | `Document` / amount_written_off, gl_entry_id, closed_by | ✅ |
| Company updated (incl. tax-status change) | `Company\...\CompanyUpdated` | `handleCompanyUpdated` | `Company` / changes, updated_by | ✅ |
| **Refund payment (full)** | `Treasury\...\PaymentRefunded` | **`handlePaymentRefunded`** | `Payment` / original_payment_id, amount, currency, reason, refunded_at | 🟢 fixed |
| **Refund payment (partial)** | `Treasury\...\PaymentRefunded` | **`handlePaymentRefunded`** | `Payment` / original_payment_id, amount, currency, reason, refunded_at | 🟢 fixed |
| **Reverse payment (error/correction)** | `Treasury\...\PaymentReversed` | **`handlePaymentReversed`** | `Payment` / amount, currency, reversed_at | 🟢 fixed |
| **Role assignment** | `Identity\...\RoleAssigned` | **`handleRoleAssigned`** | `User` / target_user_id, role_name, actor_user_id, assigned_at | 🟢 fixed |
| **Role removal** | `Identity\...\RoleRemoved` | **`handleRoleRemoved`** | `User` / target_user_id, role_name, actor_user_id, removed_at | 🟢 fixed |
| Manager-PIN verification (discount / variance-close override gate) | — none — | — none — | nothing persisted; only `receipt.authorized_by_user_id` / `shift.manager_override_by` columns, untimestamped, no reason | ❌ gap (G2) |
| POS return/refund proration (`PaymentRefundService::refundReceiptPayments` via `ReceiptReturnService`) | — none dispatched — | n/a | refund `Payment` rows carry `original_payment_id`, `refund_request_id`, `authorized_by_user_id`, `policy_trigger` — but no `audit_events` row | ⚠️ partial (G3) |

## Fixed this session

`PaymentRefunded` and `PaymentReversed` were complete, immutable event classes — each with a
`getAuditPayload()` method — dispatched correctly from `PaymentRefundService::refundPayment()`,
`::partialRefund()`, and `::reversePayment()` (all inside `DB::afterCommit`). They were simply
**absent from `DomainEventSubscriber::subscribe()`**, so every production refund and reversal
fell into the void with no `audit_events` row.

**Treasury fix** — added `handlePaymentRefunded` + `handlePaymentReversed` handlers and their
`subscribe()` entries (commit `dev-backlog/t1`). No business-logic change — listener wiring
only.

**Role-assignment fix (G1)** — the `Identity` module had no `Domain/Events/` directory and
`RoleController::assignRole()` / `::removeRole()` called Spatie's `$user->assignRole()` /
`->removeRole()` directly, leaving only a `model_has_roles` row (which carries `team_id` but
no actor, no timestamp, and no removal record at all). Added immutable `RoleAssigned` /
`RoleRemoved` domain events, dispatched directly after the Spatie mutation succeeds (no
enclosing transaction — atomic on its own), and wired `handleRoleAssigned` /
`handleRoleRemoved` handlers. Test-first.

Coverage added:
- `tests/Feature/Security/PrivilegedAuditLogDispatchTest.php` — Treasury: wiring tests fire
  `PaymentRefunded` / `PaymentReversed` directly; e2e tests call the real
  `PaymentRefundService` methods (no `Event::fake`) and assert the row lands through the
  `afterCommit → subscriber → persist` chain; `ReceiptVoidService` e2e control test (proves F0).
- `tests/Feature/Identity/RoleAssignmentAuditTest.php` — assign / remove leave a correctly
  shaped `audit_events` row; a rejected assignment (422) leaves none.

## Open gaps — NOT fixed this session (scope / design decision)

The two remaining gaps have **no audit event class at all**. Closing them requires authoring
new immutable-forever domain events (CLAUDE.md rule 8) and adding dispatch into the POS
layer — a feature addition with more design ambiguity (G2) or more regression risk (G3) than
the G1 fix. They are flagged here for an explicit owner decision rather than guessed at.

### G2 — Manager-PIN override is not audited

`POS\...\ManagerPinController::verify()` is the gate for discount overrides and
variance-close overrides. A successful verification is itself a privileged action — a
manager authorising a cashier to exceed a limit — and currently dispatches nothing. The
downstream `receipt.authorized_by_user_id` / `shift.manager_override_by` columns capture the
*manager identity* but not *when*, not *what was overridden*, and not the failed attempts.

**Recommended fix:** add a `ManagerOverrideAuthorized` domain event (manager id, caller id,
override type, target receipt/shift, timestamp) dispatched on successful verify; wire a
handler. Failed attempts are already rate-limited but could also be logged.

### G3 — POS return/refund proration dispatches no audit event

`PaymentRefundService::refundReceiptPayments()` (the POS-return proration path, called by
`ReceiptReturnService`) writes negative refund `Payment` rows with audit *columns*
(`original_payment_id`, `refund_request_id`, `authorized_by_user_id`, `policy_trigger`) but
dispatches **no** `PaymentRefunded` event — so unlike the `refundPayment` / `partialRefund`
paths, it leaves no `audit_events` row. The data is reconstructable from the `payments`
table, but the unified audit trail is incomplete.

**Recommended fix:** dispatch `PaymentRefunded` (one per allocation, or one aggregate event)
from `refundReceiptPayments()`, inside the existing `DB::afterCommit` boundary. Higher risk
than G1/G2 because it touches a heavily-tested proration path with idempotency / race
handling — needs careful test coverage of the existing proration behaviour first, in its own
focused change. The data is fully reconstructable from the `payments` table today (the audit
columns are all present), so this is the least urgent of the three.

## NIT findings

- **NIT-1 — `TerminalActivatedAudit` is dispatched via a direct `event()` call**, not
  wrapped in `DB::afterCommit()` like the other privileged actions. If the surrounding
  request rolls back after the event fires, the audit row persists for a terminal activation
  that did not happen. Low impact (activation is near-idempotent and rare) but inconsistent
  with the codebase pattern. Wrap in `DB::afterCommit()` when the controller is next touched.
- **NIT-2 — `persistEvent()` swallows all throwables.** Intentional (audit must not break
  business ops) but it means a mis-wired or throwing handler produces no row and no failed
  request — only a log line. The new dispatch tests are the regression guard; consider also
  a low-volume alert on the "Failed to persist audit event" log line in production.
