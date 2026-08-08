<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Treasury;

/**
 * Reversal-scoped instrument-cancellation port (MTP-TRE-23 fix, adversarial
 * review 2026-08-02 finding I1).
 *
 * `PaymentRefundService::reversePayment()` (Treasury `Domain/Services`) needs
 * to cancel a `received` payment instrument ATOMICALLY, inside its own
 * reversal transaction, to resolve the circular precondition deadlock
 * between `InstrumentLifecycleService::cancel()` (requires the payment
 * already `Reversed`) and the reversal precondition (requires the instrument
 * already cancelled/cleared) — neither could go first.
 *
 * Domain-tier code may not import an Application-tier service directly
 * (hexagonal boundary, deptrac-enforced: `ModuleDomain` may only depend on
 * `SharedDomain` / `SharedContracts`). This port is the seam:
 * `PaymentRefundService` depends on this Shared/Contracts interface only,
 * never on the concrete `InstrumentLifecycleService` — and the interface
 * itself takes only primitives, so it introduces zero Domain/Application
 * cross-layer edges of its own (unlike a signature that took the Treasury
 * `PaymentInstrument` model).
 *
 * REVERSE-ONLY: only `PaymentRefundService::reversePayment()` may use this
 * port. `refundPayment()`/`partialRefund()` must fail closed for a
 * non-cleared instrument instead (a cash refund must never auto-cancel an
 * instrument the money never actually cleared through — review C1/C2/C3).
 *
 * The concrete implementation (`InstrumentLifecycleService`) performs the
 * SAME GL entry + `InstrumentEvent` audit row a standalone
 * `InstrumentLifecycleService::cancel()` would emit (see that method's
 * `performCancellation()`), just without requiring the payment already
 * `Reversed` — the caller is IN THE PROCESS of reversing it, in the same
 * transaction.
 *
 * DPA V4 (plan D-5): the method now RETURNS the id of the cancellation journal
 * entry it posted, or `null` when it posted none. That return value is what lets
 * `reversePayment()` build a reversing DOCUMENT that LINKS the existing
 * AR-restoring entry instead of creating a second one — review finding C2's
 * double credit — and it is also the branch discriminator: a non-`null` return
 * means "the instrument lane already handled the GL and no cash moved", so the
 * reversal's own cash branch is skipped entirely.
 */
interface InstrumentReversalCancellerInterface
{
    /**
     * Cancel a `received` payment instrument as part of an atomic payment
     * reversal.
     *
     * MUST be called from inside an open DB transaction — implementations
     * MUST throw `\LogicException` when `DB::transactionLevel() < 1`
     * (review finding I5). The caller
     * (`PaymentRefundService::reversePayment()`) flips the linked payment to
     * `Reversed` itself, in the SAME transaction, immediately after this
     * call returns.
     *
     * N2 hardening (2026-08-02 minor-followups ticket): the tx-level guard
     * above is unverifiable under `RefreshDatabase` (level is always >= 1 in
     * tests) and only proves SOME transaction is open, not that THIS call is
     * part of an in-flight reversal. Implementations MUST assert the
     * instrument's linked payment exists and is `Completed`
     * (`PaymentStatus::canReverse()`) — anything else (already
     * `Reversed`/`Pending`, or no linked payment) throws `\DomainException`.
     * **On its own this check is NOT sufficient** — see H1 below.
     *
     * N3 hardening (same ticket): implementations MUST scope the instrument
     * lookup by `tenant_id`/`company_id` — cheap insurance against a future
     * caller passing a cross-tenant/cross-company instrument id.
     *
     * H1 hardening (2026-08-03 gate finding, fix round 2 — N2/N3 alone did
     * NOT close this): the instrument's linked payment (resolved via the
     * `belongsTo` relation on `payment_instruments.payment_id`) is NOT
     * necessarily the SAME payment the caller is reversing — `payments.
     * instrument_id` is a separate, one-way, unvalidated FK a second payment
     * can point at an instrument it does not own (`PaymentController::store()`
     * accepts `instrument_id` on an immediate/non-maturity payment with no
     * ownership check outside the deferred-customer branch, and never writes
     * the back-link). Without an identity check, reversing that SECOND
     * payment cancels the FIRST payment's instrument while the first payment
     * itself is untouched (stays `Completed`) — a real, publicly-reachable
     * GL-vs-subledger divergence, not merely a theoretical one. Implementations
     * MUST accept the id of the payment actually being reversed and assert
     * `$instrument->payment_id === $paymentId`, throwing `\DomainException`
     * on any mismatch (including a `null` `payment_id`) BEFORE the status
     * check — identity is meaningless to check status against a payment that
     * isn't the one being reversed.
     *
     * @param  string  $instrumentId  Treasury `payment_instruments.id` (UUID).
     * @param  string  $paymentId  `payments.id` of the payment actually being
     *                             reversed — MUST equal the instrument's own
     *                             `payment_id` (H1).
     * @param  string  $tenantId  Tenant scope for the instrument lookup (N3).
     * @param  string  $companyId  Company scope for the instrument lookup (N3).
     * @param  string|null  $userId  Actor recorded on the GL entry / audit event.
     * @param  string  $reason  Free-text audit reason, persisted on the
     *                          `InstrumentEvent` row.
     * @return string|null The id of the cancellation journal entry that was
     *                     posted, or `null` when NONE was posted. Implementations
     *                     post the reversing entry ONLY when the linked payment
     *                     carries a `journal_entry_id` — the instrument lane
     *                     deliberately declines to reverse GL that was never
     *                     posted, and V4's D-17 symmetry rule applies the same
     *                     principle to the cash branch. Callers MUST treat
     *                     `null` as "nothing was posted", never as an error.
     *
     * @throws \DomainException When the instrument is outbound, not currently
     *                          `Received`, is not linked to `$paymentId` (H1),
     *                          its linked payment is not `Completed` (N2), or
     *                          the instrument does not resolve in the given
     *                          `$tenantId`/`$companyId` scope (N3/M2 — the
     *                          scope miss is translated from the ORM's
     *                          not-found exception, never leaked raw).
     * @throws \LogicException When called outside an open DB transaction.
     */
    public function cancelForPaymentReversal(string $instrumentId, string $paymentId, string $tenantId, string $companyId, ?string $userId, string $reason): ?string;
}
