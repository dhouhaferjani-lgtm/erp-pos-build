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
     * @param  string  $instrumentId  Treasury `payment_instruments.id` (UUID).
     * @param  string|null  $userId  Actor recorded on the GL entry / audit event.
     * @param  string  $reason  Free-text audit reason, persisted on the
     *                          `InstrumentEvent` row.
     *
     * @throws \DomainException When the instrument is outbound, or not currently `Received`.
     * @throws \LogicException When called outside an open DB transaction.
     */
    public function cancelForPaymentReversal(string $instrumentId, ?string $userId, string $reason): void;
}
