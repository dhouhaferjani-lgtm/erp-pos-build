<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Services;

use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\CreditNoteAllocation;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\RefundAllocation;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\ProrationStrategy;
use App\Modules\Treasury\Domain\Enums\ReversalSupport;
use App\Modules\Treasury\Domain\Events\PaymentRefunded;
use App\Modules\Treasury\Domain\Events\PaymentReversed;
use App\Modules\Treasury\Domain\Exceptions\OverRefundException;
use App\Modules\Treasury\Domain\Exceptions\RefundIdempotencyException;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\InstrumentReversalCancellerInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentRefundService
{
    /**
     * Storage scale of `payment_allocations.amount` — `NUMERIC(15,3)` since
     * `2026_06_22_120000_widen_payment_allocations_amount_to_scale_3`.
     *
     * This is deliberately NOT a currency scale. It is the scale a stored
     * allocation row can carry, which for a 2-decimal currency such as EUR is
     * WIDER than the currency scale — and reachable, because
     * `PaymentController::store()` validates allocation amounts with
     * `regex:/^\d+(\.\d{1,3})?$/` and applies no currency-scale narrowing.
     * `reversePayment()` uses it so an allocation mirror negates the stored rows
     * EXACTLY; money at rest on the reversal document itself still uses the
     * currency scale (rule 19 / D-14).
     */
    private const ALLOCATION_STORAGE_SCALE = 3;

    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly GeneralLedgerService $glService,
        private readonly TreasuryMovementServiceInterface $movementService,
        private readonly InstrumentReversalCancellerInterface $instrumentReversalCanceller,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Spec §13 refund-origin inheritance with NULL-origin fallback.
     *
     * Task 22 round-2 (Codex T22-B2 BLOCKER): refund writers inherit
     * the original payment's `origin`. Pre-Task-12 legacy rows have
     * `origin = NULL`; the spec §13 mandates those map to `unknown_legacy`.
     * Without this normalization, every refund of a legacy payment
     * would itself stamp NULL — defeating the §13 invariant ("every
     * §13 writer stamps origin"). This helper centralizes the fallback
     * so all three refund writers (`refundPayment`, `partialRefund`,
     * `refundReceiptPayments`) share one source of truth.
     *
     * DO NOT change the fallback to silently NULL — `unknown_legacy` is
     * a deliberate sentinel that lets ops reason about pre-Task-12
     * data in the audit log instead of mistaking NULL for "not yet
     * stamped" or "missed §13 writer".
     */
    private function originForRefund(Payment $original): PaymentOrigin
    {
        return $original->origin ?? PaymentOrigin::UnknownLegacy;
    }

    /**
     * Refund a completed payment.
     *
     * This method is idempotent: calling it on an already-refunded payment
     * will return the existing refund without error.
     *
     * Bug fix (Codex review 2 additional finding): payment_type is now
     * explicitly set to PaymentType::Refund instead of relying on the column
     * default ('document_payment').
     */
    public function refundPayment(
        Payment $payment,
        string $reason,
        ?string $userId = null,
        ?string $refundRequestId = null,
    ): Payment {
        // Idempotent: if already reversed (refunded), find and return the existing refund
        if ($payment->status === PaymentStatus::Reversed) {
            return $this->findExistingFullRefund($payment);
        }

        if ($payment->status !== PaymentStatus::Completed) {
            throw new \RuntimeException('Only completed payments can be refunded');
        }

        $this->assertRefundableSubject($payment);

        // Task 18: a stable request id is REQUIRED for DB-level idempotency + the
        // movement key. The admin endpoint supplies a client UUID; direct callers
        // that omit one get a per-call UUID (each call is then its own request).
        $refundRequestId ??= Str::uuid()->toString();

        try {
            return DB::transaction(function () use ($payment, $reason, $userId, $refundRequestId): Payment {
                // Lock the ORIGINAL payment row for the duration of the refund so
                // concurrent refunds of the same payment serialise (F10). The
                // over-refund guard below then reads a committed already-refunded
                // total, not a stale snapshot.
                /** @var Payment $original */
                $original = Payment::query()
                    ->where('tenant_id', $payment->tenant_id)
                    ->where('company_id', $payment->company_id)
                    ->lockForUpdate()
                    ->findOrFail($payment->id);

                // Double-check inside the lock (another request may have refunded it)
                if ($original->status === PaymentStatus::Reversed) {
                    return $this->findExistingFullRefund($original);
                }

                // Idempotent replay: a refund row already exists for this
                // (company, original payment, request id) triplet.
                $existing = $this->findExistingRefundByRequestId($original, $refundRequestId);
                if ($existing instanceof Payment) {
                    return $existing;
                }

                // Review C1/C2 fix (revised orchestrator ruling, 2026-08-02
                // remediation): a FULL cash refund must fail closed for a
                // non-cleared instrument — never auto-cancel it. Cancelling
                // the instrument here would (a) move cash OUT of a
                // repository the money never actually arrived in (a
                // deferred customer payment records no cash-IN movement
                // until the instrument clears — PaymentController.php's
                // `$isDeferredCustomer` guard), and (b) post a SECOND
                // AR-restoring GL entry on top of the instrument
                // cancellation's own one. Only reversePayment() may resolve
                // the instrument atomically — see
                // resolveInstrumentForReversal()'s docblock.
                $this->assertInstrumentSettledForCashUndo($original);

                /** @var numeric-string $originalAmount */
                $originalAmount = (string) $original->amount;
                $this->assertWithinRefundableBalance($original, $originalAmount);

                // Create refund payment (negative amount)
                // IMPORTANT: payment_type is set explicitly to Refund to avoid the column
                // default 'document_payment' (Codex review 2 additional finding).
                //
                // Spec §13 writer-inventory row 7 — `PaymentRefundService::refundPayment()`
                // → inherit the original payment's `origin`. Task 22 round-2
                // (Codex T22-B2 BLOCKER): `originForRefund()` falls back to
                // `PaymentOrigin::UnknownLegacy` for NULL-origin legacy originals.
                $refund = Payment::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $original->tenant_id,
                    'company_id' => $original->company_id,
                    'partner_id' => $original->partner_id,
                    'payment_method_id' => $original->payment_method_id,
                    'instrument_id' => $original->instrument_id,
                    'repository_id' => $original->repository_id,
                    'location_id' => $original->location_id,
                    'amount' => bcmul($originalAmount, '-1', $this->scale()), // Negative amount
                    'currency' => $original->currency,
                    'payment_date' => now(),
                    'status' => PaymentStatus::Completed,
                    'payment_type' => PaymentType::Refund,
                    'origin' => $this->originForRefund($original),
                    'original_payment_id' => $original->id,
                    'refund_request_id' => $refundRequestId,
                    'reference' => "Refund for payment {$original->reference}",
                    'notes' => "Refund: {$reason}",
                    'created_by' => $userId,
                ]);

                // Reverse original payment allocations
                /** @var list<string> $refundedDocumentIds */
                $refundedDocumentIds = [];
                foreach ($original->allocations as $allocation) {
                    PaymentAllocation::create([
                        'payment_id' => $refund->id,
                        'document_id' => $allocation->document_id,
                        'amount' => bcmul($allocation->amount, '-1', $this->scale()), // Negative amount
                    ]);
                    $refundedDocumentIds[] = $allocation->document_id;
                }

                // N1 fix (re-gate finding, 2026-08-02): recompute the
                // refunded documents' balance_due and revert Paid -> Posted
                // — mirrors partialRefund()'s unwindAllocationsProRata() and
                // reversePayment(), both of which already call this. Without
                // it, a FULL refund reopened balance_due only via the
                // Postgres balance_due-cache trigger (a no-op on SQLite) and
                // NEVER reverted the cached `status` column on ANY backend —
                // the exact I2 defect, on refundPayment()'s more common
                // path. Lock order (I9): Document locks here, BEFORE
                // postRefundGlAndMovement()'s company advisory lock below —
                // same order the other two callers already use.
                $this->recomputeDocumentBalances(
                    $refundedDocumentIds,
                    $original->tenant_id,
                    $original->company_id,
                    $this->scaleResolver->getScale($original->currency),
                );

                // GL reversal + cash movement OUT via the write port (atomic with
                // this transaction). Skipped cleanly when the original payment has
                // no repository (legacy admin payments without a till).
                $this->postRefundGlAndMovement($original, $refund->id, $originalAmount, $refundRequestId, $userId);

                // Mark original payment as reversed
                $original->update([
                    'status' => PaymentStatus::Reversed,
                    'notes' => ($original->notes ?? '')."\n\nRefunded: {$reason}",
                ]);

                DB::afterCommit(function () use ($refund, $original, $reason): void {
                    event(new PaymentRefunded(
                        paymentId: $refund->id,
                        tenantId: $refund->tenant_id,
                        companyId: $refund->company_id,
                        originalPaymentId: $original->id,
                        amount: $refund->amount,
                        currency: $refund->currency,
                        reason: $reason,
                        refundedAt: ($refund->created_at ?? now())->toIso8601String(),
                    ));
                });

                return $refund;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent refund with the same refund_request_id won the race and
            // committed first; the partial unique index rejected our insert. Read
            // back the committed row (transaction already rolled back) and return it.
            $existing = $this->findExistingRefundByRequestId($payment, $refundRequestId);
            if ($existing instanceof Payment) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Partially refund a payment.
     *
     * Bug fix (Codex review 2 additional finding): payment_type is now
     * explicitly set to PaymentType::Refund instead of relying on the column
     * default ('document_payment').
     */
    public function partialRefund(
        Payment $payment,
        string $amount,
        string $reason,
        ?string $userId = null,
        ?string $refundRequestId = null,
    ): Payment {
        if ($payment->status !== PaymentStatus::Completed) {
            throw new \RuntimeException('Only completed payments can be refunded');
        }

        // BEFORE the per-request amount checks below: this is a precondition on the
        // SUBJECT of the refund, and it must be what refuses a negative original —
        // not the incidental `amount > originalAmount` comparison (T12).
        $this->assertRefundableSubject($payment);

        // Validate refund amount (per-request bounds; the cumulative over-refund
        // guard runs under the original-payment lock inside the transaction).
        /** @var numeric-string $amount */
        /** @var numeric-string $paymentAmount */
        $paymentAmount = $payment->amount;
        if (bccomp($amount, '0', $this->scale()) <= 0) {
            throw new \InvalidArgumentException('Refund amount must be greater than zero');
        }

        if (bccomp($amount, $paymentAmount, $this->scale()) > 0) {
            throw new \InvalidArgumentException('Refund amount cannot exceed original payment amount');
        }

        $refundRequestId ??= Str::uuid()->toString();

        try {
            return DB::transaction(function () use ($payment, $amount, $reason, $userId, $refundRequestId): Payment {
                // Lock the ORIGINAL payment (F10): two concurrent partial refunds of
                // the same payment now serialise, so the cumulative guard below sees
                // a committed already-refunded total rather than a stale read.
                /** @var Payment $original */
                $original = Payment::query()
                    ->where('tenant_id', $payment->tenant_id)
                    ->where('company_id', $payment->company_id)
                    ->lockForUpdate()
                    ->findOrFail($payment->id);

                // Idempotent replay for this (company, original payment, request id).
                $existing = $this->findExistingRefundByRequestId($original, $refundRequestId);
                if ($existing instanceof Payment) {
                    return $existing;
                }

                // Review C1/C2/C3 fix (revised orchestrator ruling, 2026-08-02
                // remediation): a PARTIAL cash refund must fail closed for a
                // non-cleared instrument too — cancelling the WHOLE
                // instrument to satisfy a PARTIAL refund would destroy the
                // remaining collectible balance (a 40.000 cheque partially
                // refunded 10.000 must NOT cancel the cheque and strand the
                // other 30.000), on top of the same phantom-cash-out /
                // double-AR-debit risk refundPayment() has. Only
                // reversePayment() may resolve the instrument atomically —
                // see resolveInstrumentForReversal()'s docblock.
                $this->assertInstrumentSettledForCashUndo($original);

                // Cumulative over-refund guard under the lock.
                $this->assertWithinRefundableBalance($original, $amount);

                // Create partial refund payment (negative amount).
                // Spec §13 writer-inventory row 8 — inherit the original `origin`
                // (UnknownLegacy fallback for NULL-origin legacy originals).
                $refund = Payment::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $original->tenant_id,
                    'company_id' => $original->company_id,
                    'partner_id' => $original->partner_id,
                    'payment_method_id' => $original->payment_method_id,
                    'instrument_id' => $original->instrument_id,
                    'repository_id' => $original->repository_id,
                    'location_id' => $original->location_id,
                    'amount' => bcmul($amount, '-1', $this->scale()), // Negative amount
                    'currency' => $original->currency,
                    'payment_date' => now(),
                    'status' => PaymentStatus::Completed,
                    'payment_type' => PaymentType::Refund,
                    'origin' => $this->originForRefund($original),
                    'original_payment_id' => $original->id,
                    'refund_request_id' => $refundRequestId,
                    'reference' => "Partial refund for payment {$original->reference}",
                    'notes' => "Partial refund ({$amount}): {$reason}",
                    'created_by' => $userId,
                ]);

                // MTP-TRE-10 fix: unwind PaymentAllocation pro-rata for the refunded
                // amount and reopen the affected document(s)' balance_due — mirrors
                // refundPayment()'s negative-allocation-row semantics (never deletes/
                // mutates the original allocation row), but ALSO explicitly recomputes
                // balance_due (the full-refund path relies solely on the Postgres
                // balance_due-cache trigger, which is a no-op on SQLite/tests — see
                // unwindAllocationsProRata() docblock).
                $this->unwindAllocationsProRata($original, $refund->id, $amount);

                // GL reversal + cash movement OUT via the write port.
                $this->postRefundGlAndMovement($original, $refund->id, $amount, $refundRequestId, $userId);

                // Update original payment notes
                $original->update([
                    'notes' => ($original->notes ?? '')."\n\nPartial refund of {$amount}: {$reason}",
                ]);

                DB::afterCommit(function () use ($refund, $original, $reason): void {
                    event(new PaymentRefunded(
                        paymentId: $refund->id,
                        tenantId: $refund->tenant_id,
                        companyId: $refund->company_id,
                        originalPaymentId: $original->id,
                        amount: $refund->amount,
                        currency: $refund->currency,
                        reason: $reason,
                        refundedAt: ($refund->created_at ?? now())->toIso8601String(),
                    ));
                });

                return $refund;
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->findExistingRefundByRequestId($payment, $refundRequestId);
            if ($existing instanceof Payment) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * DPA V4 / T12 (gate Important-4) — only a POSITIVE payment can be refunded.
     *
     * `PaymentType::Reversal` mints a second class of negative payment row, and
     * nothing forbade refunding one. The hole is real, not theoretical: with a
     * `-600.000` row as the original, `assertWithinRefundableBalance()` computes
     * `projected = bcadd('0', '-600.000') = '-600.000'` and
     * `bccomp('-600.000', '-600.000') === 0`, which is NOT `> 0` — so the
     * over-refund guard PASSES and produces a `+600` "refund of a reversal" plus a
     * `Dr AR / Cr cash` entry in the wrong direction.
     *
     * `partialRefund()` was protected only INCIDENTALLY, by the
     * `amount > originalAmount` comparison rejecting a positive request against a
     * negative original. Incidental protection is not a rule, and it produced a
     * misleading message. Both entry points now fail by rule, before any write.
     *
     * The predicate is AMOUNT-based rather than an enum whitelist so it covers
     * `Refund`, `Reversal`, and any future negative class with nothing to keep in
     * sync. It also closes the PRE-EXISTING hole for `Refund` rows — refunding a
     * refund was reachable before V4 existed.
     *
     * This is the symmetric counterpart of D-6's refuse-to-reverse-a-reversal.
     */
    private function assertRefundableSubject(Payment $payment): void
    {
        $scale = $this->scaleResolver->getScale($payment->currency);
        /** @var numeric-string $amount */
        $amount = CurrencyScale::bcformat((string) $payment->amount, $scale);

        if (bccomp($amount, '0', $scale) <= 0) {
            throw new \DomainException(
                "payment {$payment->id} has a non-positive amount ({$amount}) and cannot be refunded — "
                .'only a positive payment can be. A refund or reversal row is itself the undo of a '
                .'payment; refund or reverse the ORIGINAL payment instead.'
            );
        }
    }

    /**
     * Sum the absolute amount already refunded against an original payment
     * (all `payment_type = refund` rows keyed on `original_payment_id`), and
     * reject when adding `$thisRefund` would exceed the original amount.
     *
     * MUST be called while holding a `lockForUpdate()` on `$original` — the lock
     * is what makes the read-then-check atomic against a concurrent refund (F10).
     *
     * @param  numeric-string  $thisRefund  positive refund amount at currency scale
     *
     * @throws OverRefundException
     */
    private function assertWithinRefundableBalance(Payment $original, string $thisRefund): void
    {
        $scale = $this->scaleResolver->getScale($original->currency);
        $alreadyRefunded = $this->alreadyRefundedForOriginal($original, $scale);

        /** @var numeric-string $originalAmount */
        $originalAmount = CurrencyScale::bcformat((string) $original->amount, $scale);
        /** @var numeric-string $projected */
        $projected = bcadd($alreadyRefunded, $thisRefund, $scale);

        if (bccomp($projected, $originalAmount, $scale) > 0) {
            throw new OverRefundException(
                originalPaymentId: $original->id,
                alreadyRefunded: $alreadyRefunded,
                requestedRefund: $thisRefund,
                originalAmount: $originalAmount,
            );
        }
    }

    /**
     * Absolute total already refunded against an original payment.
     *
     * @return numeric-string
     */
    private function alreadyRefundedForOriginal(Payment $original, int $scale): string
    {
        /** @var numeric-string $total */
        $total = '0';

        Payment::query()
            ->where('company_id', $original->company_id)
            ->where('original_payment_id', $original->id)
            ->where('payment_type', PaymentType::Refund->value)
            ->get()
            ->each(function (Payment $refundRow) use (&$total, $scale): void {
                /** @var numeric-string $abs */
                $abs = ltrim((string) $refundRow->amount, '-');
                /** @var numeric-string $total */
                $total = bcadd($total, $abs, $scale);
            });

        return $total;
    }

    /**
     * Find an existing refund row for a (company, original payment, request id)
     * triplet — the same shape the partial unique index enforces. Used for the
     * pre-insert idempotency short-circuit AND the post-race read-back.
     */
    private function findExistingRefundByRequestId(Payment $original, string $refundRequestId): ?Payment
    {
        return Payment::query()
            ->where('company_id', $original->company_id)
            ->where('original_payment_id', $original->id)
            ->where('refund_request_id', $refundRequestId)
            ->where('payment_type', PaymentType::Refund->value)
            ->first();
    }

    /**
     * Post the GL reversal (Dr AR / Cr Bank) SYNCHRONOUSLY and record the cash
     * movement OUT of the repository through the single write port — atomically
     * with the caller's refund transaction.
     *
     * Global lock order (BLOCKER-1, extended by review finding I9): the
     * caller (refundPayment()/partialRefund()) already holds the ORIGINAL
     * PAYMENT row lock; partialRefund() additionally takes the affected
     * DOCUMENT row lock(s) via unwindAllocationsProRata() ->
     * recomputeDocumentBalances() BEFORE calling this method. THEN this
     * method's GL post takes the company advisory lock, and the movement
     * port takes the repository row lock inside record() last — so the
     * order is Payment -> Document(s) -> company advisory -> Repository.
     * reversePayment() follows the SAME Document-before-advisory-lock
     * order via its own recomputeDocumentBalances() call (even though it
     * never reaches this method — it posts no cash movement), so the two
     * paths can never deadlock against each other over overlapping
     * documents/company. The repository itself is NOT pre-locked here.
     *
     * No-ops cleanly when the original payment has no repository (a legacy admin
     * payment recorded without a till) — the refund row still exists, but there is
     * no cash box to move against.
     *
     * @param  numeric-string  $absAmount  positive refund amount at currency scale
     */
    private function postRefundGlAndMovement(
        Payment $original,
        string $refundPaymentId,
        string $absAmount,
        string $refundRequestId,
        ?string $userId,
    ): void {
        if ($original->repository_id === null) {
            return;
        }

        /** @var PaymentRepository|null $repository */
        $repository = PaymentRepository::query()
            ->where('tenant_id', $original->tenant_id)
            ->where('company_id', $original->company_id)
            ->find($original->repository_id);

        if (! $repository instanceof PaymentRepository) {
            return;
        }

        // A cash movement is about to leave this repository. The spine §9.2
        // reconciliation invariant requires every cash movement to carry a
        // linked journal_entry_id. A repository with no gl_account_id has no GL
        // account to post the reversal to — refuse rather than record a null-JE
        // movement that would freeze the repo at Wave-F reconcile (same principle
        // Fix 2 applied to payments).
        if ($repository->gl_account_id === null) {
            throw new \DomainException(
                "a refund cash movement requires a GL-linked repository; repository {$repository->id} has no gl_account_id"
            );
        }

        // GL reversal ALWAYS posted synchronously (in-transaction, atomic with the
        // refund). Actor-nullable: createPaymentRefundJournalEntry seals the
        // reversal via postEntryNow even when the actor can't be resolved, so the
        // movement below ALWAYS has a JE to link — no null-JE refund movement.
        $entry = $this->glService->createPaymentRefundJournalEntry(
            companyId: $original->company_id,
            partnerId: $original->partner_id,
            refundPaymentId: $refundPaymentId,
            amount: $absAmount,
            paymentMethodAccountId: $repository->gl_account_id,
            date: now(),
            description: "Refund for payment {$original->reference}",
            postedByUserId: $userId,
            currencyCode: $repository->currency,
            mode: PostingMode::SynchronousInTransaction,
        );
        $journalEntryId = $entry->id;

        // Money leaves the repository (refunding a customer payment). sourceType
        // Refund, sourceId = ORIGINAL payment id, idempotencyLeg = refund_request_id
        // → idempotency key "refund:{original_payment_id}:{refund_request_id}".
        $this->movementService->record(new MovementIntent(
            repositoryId: $repository->id,
            tenantId: $original->tenant_id,
            companyId: $original->company_id,
            direction: MovementDirection::Out,
            amount: $absAmount,
            currency: $repository->currency,
            sourceType: MovementSourceType::Refund,
            sourceId: $original->id,
            idempotencyLeg: $refundRequestId,
            journalEntryId: $journalEntryId,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: $userId,
            notes: null,
            allowWhileFrozen: false,
        ));
    }

    /**
     * DPA V4 / T7 — the CASH branch of a payment reversal: one `Dr AR / Cr cash`
     * reversing entry plus one cash movement OUT, atomic with the caller's
     * reversal transaction.
     *
     * Reached ONLY when the instrument branch did not handle the reversal (no
     * instrument at all, per D-5 row 1). Structurally a sibling of
     * `postRefundGlAndMovement()`, with four deliberate differences:
     *
     * 1. **D-17 symmetry rule — post GL if and only if the original posted GL.**
     *    `performCancellation()` already applies exactly this rule on the
     *    instrument side (it posts its cancellation entry only when the payment
     *    carries a `journal_entry_id`). One rule, both branches:
     *      - original posted NOTHING → post nothing, silently. The reversing
     *        document and its allocation mirrors still exist. Debiting AR for a
     *        receivable the original never credited would create a phantom
     *        receivable with no offsetting history.
     *      - original DID post but has NO repository → REFUSE. Reversing it
     *        would otherwise leave real, unreversed GL drift standing. (Not
     *        constructible through the API today — JE ⟹ repository at every
     *        payment writer — which is exactly what makes this a safety net.)
     *
     * 2. **`ReversalSupport::NoCashLeg` suppresses the MOVEMENT only** (gate N3).
     *    It does NOT exempt the type from D-17. Because there is no correct
     *    credit-side reversing shape for a credit application, a `NoCashLeg`
     *    original that CARRIES a journal entry refuses rather than posting the
     *    AR shape or orphaning the entry — the same drift case (1) refuses for a
     *    `DocumentPayment`. One exception-free statement of one rule.
     *
     * 3. **`$original->currency` governs** (gate N9). `postRefundGlAndMovement()`
     *    posts with `$repository->currency` while deriving its scale from the
     *    payment currency — a latent mismatch this method must not inherit. Here
     *    a divergent repository currency REFUSES rather than silently posting a
     *    cross-currency leg at the wrong scale. (The existing refund path is left
     *    alone; ticketed separately.)
     *
     * 4. **`source_id` is the REVERSAL payment id** for the journal entry (D-9,
     *    so multiple reversing entries can never collide on
     *    `(source_type, source_id)`), while the MOVEMENT keeps
     *    `sourceId = original->id` with `idempotencyLeg = "reversal:{id}"` (D-8),
     *    yielding `refund:{orig}:reversal:{rev}` — distinct from every refund key
     *    and stable within the transaction. No new `MovementSourceType` case is
     *    introduced: the enum has no exhaustive match anywhere, but
     *    `StatementSuggestionService` and `CashMovementsReportService` classify by
     *    `in_array`/`===` whitelists, so a new case would be SILENTLY dropped from
     *    bank-statement suggestion.
     *
     * Lock order is the caller's: Payment → Document(s) → GL company advisory
     * (inside the GL post) → Repository (inside the movement port).
     *
     * @param  numeric-string  $netAmount  positive net unreversed amount at scale
     */
    private function postReversalGlAndMovement(
        Payment $original,
        string $reversalPaymentId,
        string $netAmount,
        int $scale,
        ReversalSupport $support,
        ?string $userId,
    ): void {
        // D-4: a zero-net reversal still writes its document, but posts no entry
        // and moves no cash — there is nothing left to unwind.
        if (bccomp($netAmount, '0', $scale) <= 0) {
            return;
        }

        // D-17 case A — uniform across both support shapes.
        if ($original->journal_entry_id === null) {
            return;
        }

        // The original posted GL, so the reversal owes a reversing entry.
        if ($support === ReversalSupport::NoCashLeg) {
            throw new \DomainException(
                "payment {$original->id} is a credit application carrying journal entry "
                ."{$original->journal_entry_id}, and there is no correct credit-side reversing shape for it. "
                .'Refusing rather than posting the accounts-receivable shape or leaving the entry unreversed.'
            );
        }

        if ($original->repository_id === null) {
            throw new \DomainException(
                "payment {$original->id} posted journal entry {$original->journal_entry_id} but has no "
                .'repository to reverse the cash leg against; reversing it would leave unreversed GL drift. '
                .'Refusing.'
            );
        }

        /** @var PaymentRepository|null $repository */
        $repository = PaymentRepository::query()
            ->where('tenant_id', $original->tenant_id)
            ->where('company_id', $original->company_id)
            ->find($original->repository_id);

        if (! $repository instanceof PaymentRepository) {
            throw new \DomainException(
                "payment {$original->id} references repository {$original->repository_id}, which does not "
                .'resolve in this tenant/company scope; refusing to reverse its posted GL blind.'
            );
        }

        // Same principle Fix 2 applied to payments and the refund path applies
        // here: a cash movement with no GL account to post against would freeze
        // the repository at Wave-F reconcile.
        if ($repository->gl_account_id === null) {
            throw new \DomainException(
                "a reversal cash movement requires a GL-linked repository; repository {$repository->id} "
                .'has no gl_account_id'
            );
        }

        // N9: never post a cross-currency leg at the payment's scale.
        if ($repository->currency !== $original->currency) {
            throw new \DomainException(
                "repository {$repository->id} holds {$repository->currency} but payment {$original->id} is "
                ."in {$original->currency}; refusing to post a cross-currency reversal leg."
            );
        }

        $entry = $this->glService->createPaymentRefundJournalEntry(
            companyId: $original->company_id,
            partnerId: $original->partner_id,
            refundPaymentId: $reversalPaymentId,
            amount: $netAmount,
            paymentMethodAccountId: $repository->gl_account_id,
            date: now(),
            description: "Reversal of payment {$original->reference}",
            postedByUserId: $userId,
            currencyCode: $original->currency,
            mode: PostingMode::SynchronousInTransaction,
        );

        $this->movementService->record(new MovementIntent(
            repositoryId: $repository->id,
            tenantId: $original->tenant_id,
            companyId: $original->company_id,
            direction: MovementDirection::Out,
            amount: $netAmount,
            currency: $original->currency,
            sourceType: MovementSourceType::Refund,
            sourceId: $original->id,
            idempotencyLeg: "reversal:{$reversalPaymentId}",
            journalEntryId: $entry->id,
            occurredAt: null,
            reasonCode: null,
            reversesMovementId: null,
            createdBy: $userId,
            notes: null,
            allowWhileFrozen: false,
        ));
    }

    /**
     * Check if payment can be refunded.
     *
     * Re-verified against the 2026-08-02 adversarial-review remediation
     * (review finding I3): under the REVISED, narrower orchestrator ruling
     * — atomic instrument cancellation is reversePayment()-ONLY —
     * refundPayment() and partialRefund() still fail closed for
     * Received/Deposited/Bounced exactly as this method reports, so no
     * behavioural change was needed here. (I3 was written against an
     * earlier, broader ruling where refundPayment()/partialRefund() ALSO
     * auto-cancelled a Received instrument; that ruling was superseded —
     * see C1/C2/C3 — before merge. This method never gated
     * reversePayment(), which has no "canReverse" counterpart endpoint.)
     */
    public function canRefund(Payment $payment): bool
    {
        if ($payment->status !== PaymentStatus::Completed) {
            return false;
        }

        $instrument = $payment->instrument()->first();

        return $instrument === null || ! in_array($instrument->status, [
            InstrumentStatus::Received,
            InstrumentStatus::Deposited,
            InstrumentStatus::Bounced,
        ], true);
    }

    /**
     * Get refund history for a payment.
     *
     * @return array<string, mixed>
     */
    public function getRefundHistory(Payment $payment): array
    {
        // Find all refund payments (negative amounts) for this payment.
        //
        // DPA V4 (D-11): the `payment_type` filter is REQUIRED, not hygiene. This
        // is a reference-string heuristic with no type predicate, and a reversal
        // row is ALSO a negative payment whose `reference` contains the original's
        // ("Reversal for payment {ref}"), so without the filter a
        // reversed-not-refunded payment would report a refund that never happened
        // and `total_refunded` would double-count a payment that was reversed after
        // a partial refund. (Replacing the `reference LIKE` matching with
        // `original_payment_id` is a separate cleanup — ticketed.)
        $refunds = Payment::where('tenant_id', $payment->tenant_id)
            ->where('partner_id', $payment->partner_id)
            ->where('payment_type', PaymentType::Refund->value)
            ->where('amount', '<', '0')
            ->where('reference', 'like', '%'.$payment->reference.'%')
            ->get();

        /** @var numeric-string $totalRefunded */
        $totalRefunded = '0.00';
        foreach ($refunds as $refund) {
            // Remove leading minus sign to get absolute value (stays as string for bcmath)
            /** @var numeric-string $absAmount */
            $absAmount = ltrim((string) $refund->amount, '-');
            $totalRefunded = bcadd($totalRefunded, $absAmount, $this->scale());
        }

        return [
            'original_amount' => $payment->amount,
            'total_refunded' => $totalRefunded,
            'remaining_amount' => bcsub($payment->amount, $totalRefunded, $this->scale()),
            'is_fully_refunded' => bccomp($totalRefunded, $payment->amount, $this->scale()) >= 0,
            'refund_count' => $refunds->count(),
            'refunds' => $refunds,
        ];
    }

    /**
     * Reverse a payment (for errors/corrections) by writing a linked REVERSING
     * DOCUMENT — DPA V4.
     *
     * Before V4 this method DELETED the payment's whole allocation lineage and
     * posted no GL at all: a mutation with no justifying document, violating the
     * document-per-action principle and leaving the audit trail unable to answer
     * "what was unwound, when, by whom, for how much". V4 replaces the deletion
     * with a child `Payment` of type `PaymentType::Reversal`, linked by
     * `original_payment_id`, carrying the NET unreversed amount as a negative
     * value, plus negative `PaymentAllocation` mirrors of the per-document NET
     * lineage. Nothing is ever deleted.
     *
     * The invariant that replaces the wipe: for every touched document,
     * `SUM(payment_allocations.amount) == 0`, therefore `balance_due == total`.
     *
     * Two mutually exclusive money branches, in this order:
     *  1. INSTRUMENT — a `Received` instrument is cancelled through the port and
     *     ITS cancellation entry IS the reversal's GL effect. The reversing
     *     document merely LINKS it (`journal_entry_id`); it creates no entry of
     *     its own and moves no cash (the money never arrived). Posting the AR
     *     shape on top would be review finding C2's double credit.
     *  2. CASH — `postReversalGlAndMovement()` posts one `Dr AR / Cr cash` entry
     *     and one movement OUT, subject to D-17's symmetry rule.
     *
     * Return value (D-13, three-valued):
     *  - the reversing document, for a fresh reversal OR an idempotent replay;
     *  - `null` when the payment is already `Reversed` with NO reversal row —
     *     reachable via `refundPayment()` (which stamps `Reversed` itself), via
     *     `InstrumentLifecycleService::performCancellation()`'s POS-revenue arm,
     *     and via the POS void lane. Already unwound; nothing to reverse. This
     *     preserves today's silent no-op byte for byte rather than turning a
     *     legitimate, currently-successful caller path into a 422.
     *  - `\RuntimeException` for any other status.
     *
     * The status branch deliberately runs BEFORE the `payment_type` gate, so an
     * already-`Reversed` POS payment returns `null` rather than the
     * `\DomainException` an unreversed one would get: idempotent replay must not
     * depend on the shape gate.
     *
     * M1 ruling (2026-08-03 gate finding): only a `Completed` payment can be
     * reversed — a `Failed` payment never completed in the first place, so
     * there is nothing to reverse (`PaymentStatus::canReverse()` is
     * `Completed`-only, and the atomic instrument-cancellation port asserts
     * the same). This method previously advertised `Failed` as acceptable
     * here (the guard below used to also allow `PaymentStatus::Failed`) but
     * nothing ever set that status on a Treasury payment via any writer
     * (repo-wide: the only reference to `PaymentStatus::Failed` was this
     * line) — the advertisement was legacy/aspirational, not load-bearing.
     * Fixed to match the actual, intentional rule.
     */
    public function reversePayment(
        Payment $payment,
        string $reason,
        ?string $userId = null
    ): ?Payment {
        // D-13: idempotent replay, or an already-unwound payment (null).
        if ($payment->status === PaymentStatus::Reversed) {
            return $this->findExistingReversal($payment);
        }

        if ($payment->status !== PaymentStatus::Completed) {
            throw new \RuntimeException('Only completed payments can be reversed');
        }

        try {
            return DB::transaction(function () use ($payment, $reason, $userId): ?Payment {
                // I7 fix: lock the payment row for the duration of the
                // reversal. Before this fix, reversePayment() only called
                // $payment->refresh() (no lock) — unlike refundPayment()/
                // partialRefund(), which both lockForUpdate() their original
                // payment row — so two concurrent reversePayment() calls for
                // the same payment could both pass the status check and both
                // run the (non-idempotent) body below.
                /** @var Payment $original */
                $original = Payment::query()
                    ->where('tenant_id', $payment->tenant_id)
                    ->where('company_id', $payment->company_id)
                    ->lockForUpdate()
                    ->findOrFail($payment->id);

                if ($original->status === PaymentStatus::Reversed) {
                    return $this->findExistingReversal($original);
                }

                // D-6 gate, immediately after the lock and BEFORE any write.
                // ONLY the payment_type gate lives here: the instrument-status
                // gate belongs in resolveInstrumentForReversal() and D-17's
                // no-repository case belongs in postReversalGlAndMovement(), so
                // each rule has exactly one home.
                $support = $original->payment_type->reversalSupport();
                if ($support === ReversalSupport::Unsupported) {
                    throw new \DomainException($this->unsupportedReversalMessage($original->payment_type));
                }

                $scale = $this->scaleResolver->getScale($original->currency);

                // D-3: TWO independent net figures, computed separately. They
                // legitimately differ when the original was partly unallocated
                // (e.g. 1000 paid / 700 allocated / 300 sitting as an advance).
                /** @var numeric-string $originalAmount */
                $originalAmount = CurrencyScale::bcformat((string) $original->amount, $scale);
                $alreadyRefunded = $this->alreadyRefundedForOriginal($original, $scale);
                /** @var numeric-string $netUnreversed */
                $netUnreversed = bcsub($originalAmount, $alreadyRefunded, $scale);
                if (bccomp($netUnreversed, '0', $scale) < 0) {
                    // Defensive floor. assertWithinRefundableBalance() keeps
                    // `alreadyRefunded <= originalAmount` on every refund writer,
                    // so this is unreachable through the API — but a negative net
                    // would flip the sign of the reversal row (bcmul by -1) and
                    // mint a POSITIVE reversal, which must never happen.
                    /** @var numeric-string $netUnreversed */
                    $netUnreversed = '0';
                }

                // D-3/D-3b: per-document NET of the whole lineage. The reversal
                // has no in-flight sibling to exclude, hence null.
                //
                // Computed at the ALLOCATION STORAGE scale, NOT the currency scale
                // (gate I-2). `payment_allocations.amount` is `NUMERIC(15,3)` while
                // EUR resolves to scale 2, and a 3-decimal allocation on a 2-decimal
                // currency is reachable through the public API
                // (`PaymentController.php:406` validates `allocations.*.amount` with
                // `regex:/^\d+(\.\d{1,3})?$/` and applies no currency-scale
                // narrowing). Summing at scale 2 would TRUNCATE the net, so the
                // mirror would under-restore and the lineage would settle at
                // `+0.005` instead of `0` — leaving `balance_due` permanently BELOW
                // the document total after a "full" reversal. This is the one place
                // where the currency scale is the wrong ruler: the mirror's job is
                // to negate STORED ROWS exactly, so it must use the column's scale.
                //
                // This does NOT contradict D-14: `netUnreversed` above — the money
                // at rest on the reversal document — stays at currency scale. D-3
                // already establishes these as two independent figures.
                $allocationScale = max($scale, self::ALLOCATION_STORAGE_SCALE);

                /** @var array<string, numeric-string> $netByDocument */
                $netByDocument = $this->netLiveAllocationsByDocument($original, $allocationScale, null);

                // The reversing document. Modelled on refundPayment()'s negative
                // child row, MINUS D-19's three deliberate exclusions:
                //   instrument_id   — carrying it would recreate the "payment 2
                //                     references payment 1's instrument" shape the
                //                     H1 fix hardened against.
                //   fiscal_event_id — DepositAllocationSummaryService does
                //                     ->first() on that column; a second row
                //                     carrying it would break D-10's ruling.
                //   refund_request_id — reversal idempotency is index-based
                //                     (D-7), and a NULL keeps this row outside
                //                     the refund index's partial predicate.
                $reversal = Payment::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $original->tenant_id,
                    'company_id' => $original->company_id,
                    'partner_id' => $original->partner_id,
                    'payment_method_id' => $original->payment_method_id,
                    // Copied from the original even on the INSTRUMENT branch, where
                    // no cash moves — the reversing document belongs to the same
                    // till as the payment it unwinds, and the cash branch needs it
                    // to resolve the repository. Neutral for the cash-movements
                    // report, which reports a reversal through its GL twin and so
                    // never joins this row to a repository (see
                    // CashMovementsReportService::OUTGOING_PAYMENT_TYPES). Any
                    // FUTURE reader that joins `payments` to `payment_repositories`
                    // must filter on a posted cash leg, or it will attribute an
                    // instrument-branch reversal to a till it never touched.
                    'repository_id' => $original->repository_id,
                    'location_id' => $original->location_id,
                    'amount' => CurrencyScale::bcformatStrict(bcmul($netUnreversed, '-1', $scale), $scale),
                    'currency' => $original->currency,
                    'payment_date' => now(),
                    'status' => PaymentStatus::Completed,
                    'payment_type' => PaymentType::Reversal,
                    'origin' => $this->originForRefund($original),
                    'original_payment_id' => $original->id,
                    'reference' => "Reversal for payment {$original->reference}",
                    'notes' => "Reversal: {$reason}",
                    'created_by' => $userId,
                ]);

                // Negative mirrors. D-3b: EVERY non-zero net is mirrored,
                // negatives included (a negative net yields a POSITIVE row), so
                // each touched document's lineage sums to exactly zero by
                // construction. netLiveAllocationsByDocument() already dropped
                // the exact zeros.
                foreach ($netByDocument as $documentId => $net) {
                    PaymentAllocation::create([
                        'payment_id' => $reversal->id,
                        'document_id' => $documentId,
                        'amount' => CurrencyScale::bcformatStrict(
                            bcmul($net, '-1', $allocationScale),
                            $allocationScale,
                        ),
                    ]);
                }

                // I2/I9 fix: recompute the affected documents' balance_due (and
                // revert Paid -> Posted, mirroring
                // OutboundInstrumentService::cancel()'s pattern exactly) BEFORE
                // resolving the instrument below. Lock order: Document row locks
                // here, THEN the instrument-cancellation / reversal GL post
                // (which takes the company advisory lock) — the SAME order
                // partialRefund()/unwindAllocationsProRata() already use, so the
                // paths cannot deadlock over overlapping documents/company.
                $this->recomputeDocumentBalances(
                    array_keys($netByDocument),
                    $original->tenant_id,
                    $original->company_id,
                    $scale,
                );

                // MTP-TRE-23 fix — reversePayment()-ONLY per the revised
                // orchestrator ruling: resolve the instrument-settlement
                // precondition atomically instead of asserting and throwing.
                //
                // I6 authorization ruling (review finding, ticket-approved):
                // `payments.reverse` implicitly authorizes cancelling the
                // payment's OWN linked instrument when it hasn't cleared — that
                // is the entire point of the atomic reversal (it is what
                // resolves the MTP-TRE-23 deadlock). It does NOT require the
                // actor to separately hold `instruments.cancel`; that
                // permission continues to gate the STANDALONE
                // `POST /payment-instruments/{id}/cancel` endpoint only, which
                // is unaffected and still fails closed for a non-reversed
                // payment (see InstrumentLifecycleService::cancel()).
                $cancellationJournalEntryId = $this->resolveInstrumentForReversal(
                    $original,
                    $userId,
                    $reason,
                    $netUnreversed,
                    $scale,
                );

                if ($cancellationJournalEntryId !== null) {
                    // INSTRUMENT BRANCH. The cancellation entry IS the reversal's
                    // GL effect — exactly ONE AR restoration. Link it; create no
                    // second entry and move no cash (review C2 / C1).
                    $reversal->update(['journal_entry_id' => $cancellationJournalEntryId]);
                } else {
                    $this->postReversalGlAndMovement(
                        $original,
                        $reversal->id,
                        $netUnreversed,
                        $scale,
                        $support,
                        $userId,
                    );
                }

                // Update payment status
                $original->update([
                    'status' => PaymentStatus::Reversed,
                    'notes' => ($original->notes ?? '')."\n\nReversed: {$reason}",
                ]);

                DB::afterCommit(function () use ($original, $reversal): void {
                    // `reversedAmount` is read back off the PERSISTED reversal row
                    // rather than from the in-memory `$netUnreversed`, so it passes
                    // through the SAME `decimal:3` cast as the frozen `amount`
                    // field beside it (gate Minor). Taking it from `$netUnreversed`
                    // emitted it at the CURRENCY scale — `'600.00'` next to
                    // `'1000.000'` in one `array<string, string>` payload, which is
                    // numerically right but a different shape, so a lexical
                    // comparator or a payload diff would flag them as mismatched.
                    // Same source, same cast, same shape by construction.
                    /** @var numeric-string $reversedAmount */
                    $reversedAmount = ltrim((string) $reversal->amount, '-');

                    event(new PaymentReversed(
                        paymentId: $original->id,
                        tenantId: $original->tenant_id,
                        companyId: $original->company_id,
                        amount: $original->amount,
                        currency: $original->currency,
                        reversedAt: now()->toIso8601String(),
                        reversalPaymentId: $reversal->id,
                        reversedAmount: $reversedAmount,
                    ));
                });

                return $reversal;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent reversal won the race and committed first;
            // `payments_reversal_idempotency_uniq` rejected our insert. Read back
            // the committed row (our transaction already rolled back) and return
            // it — the same pattern refundPayment() uses for its request-id index.
            $existing = $this->findExistingReversal($payment);
            if ($existing instanceof Payment) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * The single reversing document for an original payment, if one exists.
     *
     * `payments_reversal_idempotency_uniq` guarantees at most one row matches, so
     * `first()` is exact rather than arbitrary. Returns `null` for D-13 case 2 —
     * a payment stamped `Reversed` by some other lane (a full refund, the POS
     * void lane, `performCancellation()`'s POS-revenue arm) that never had a
     * reversing document.
     */
    private function findExistingReversal(Payment $original): ?Payment
    {
        return Payment::query()
            ->where('company_id', $original->company_id)
            ->where('original_payment_id', $original->id)
            ->where('payment_type', PaymentType::Reversal->value)
            ->first();
    }

    /**
     * D-6 refusal text. Each message names the lane that DOES own the operation —
     * or, for customer advances, honestly states that no lane owns it yet.
     *
     * The `Advance` message must NEVER point at the refund lane: `refundPayment()`
     * posts the same AR-shaped entry (`Dr CustomerReceivable`) against an advance
     * that credited `CustomerAdvance`, so redirecting there would send the caller
     * to an equally wrong path. Ticket `DPA-V4-ADV-1` owns the missing shape.
     */
    private function unsupportedReversalMessage(PaymentType $type): string
    {
        return match ($type) {
            PaymentType::Advance => 'customer-advance reversal is not implemented (ticket DPA-V4-ADV-1): '
                .'the original payment credits the customer-advance account, and no reversing entry '
                .'exists for that shape yet. There is currently no supported path for this correction.',
            PaymentType::SupplierPayment => 'a supplier payment cannot be reversed here — both the direction '
                .'and the accounts differ; use the supplier refund lane (VendorRefundService).',
            PaymentType::POS => 'a POS payment cannot be reversed here — POS posts direct to revenue with no '
                .'accounts-receivable leg; use the POS void/return lane.',
            PaymentType::Refund, PaymentType::Reversal => 'a refund or reversal row cannot itself be reversed; '
                .'reverse or refund the original payment instead.',
            // EXHAUSTIVE — no `default` arm, deliberately (gate Minor). The two
            // SUPPORTED shapes are named so the compiler, not a generic fallback,
            // is what forces a decision when a future PaymentType is added: the
            // same engineering the rest of the lane applies to `reversalSupport()`
            // and to the instrument-status gate. Callers only reach this method
            // for an `Unsupported` shape, so these two arms are unreachable —
            // stating them is the point.
            PaymentType::DocumentPayment,
            PaymentType::CreditApplication => throw new \LogicException(
                "unsupportedReversalMessage() called for {$type->value}, which IS reversible; "
                .'the caller must gate on reversalSupport() first.'
            ),
        };
    }

    private function assertInstrumentSettledForCashUndo(Payment $payment): void
    {
        $instrument = $payment->instrument()->first();
        if ($instrument !== null && in_array($instrument->status, [
            InstrumentStatus::Received,
            InstrumentStatus::Deposited,
            InstrumentStatus::Bounced,
        ], true)) {
            throw new \RuntimeException('Settle the payment instrument first (bounce or cancel) before using the cash refund/reverse path.');
        }
    }

    /**
     * MTP-TRE-23 fix, REVERSE-ONLY (revised orchestrator ruling, 2026-08-02
     * adversarial-review remediation): resolve (rather than merely assert)
     * the instrument-settlement precondition — but ONLY for
     * `reversePayment()`. `refundPayment()`/`partialRefund()` call
     * `assertInstrumentSettledForCashUndo()` (still throws) instead — a
     * cash refund/partial-refund must NEVER auto-cancel an instrument the
     * money never actually cleared through (review C1: doing so on the
     * refund paths recorded a phantom cash movement OUT of a repository
     * that never received the money; C2: it also posted a second
     * AR-restoring GL entry on top of the instrument-cancellation entry;
     * C3: it could cancel a WHOLE instrument to satisfy a PARTIAL refund).
     *
     * `reversePayment()` never posts a cash movement or a bank-leg GL entry
     * (it never calls postRefundGlAndMovement()) — cash never arrived for
     * an uncleared instrument, so there is nothing to reverse on that side.
     * The ONLY GL effect of a reversal-triggered instrument cancel is the
     * instrument's OWN Dr 411 / Cr portfolio entry — exactly one AR
     * restoration.
     *
     * Before this fix, `assertInstrumentSettledForCashUndo()` threw
     * whenever the linked instrument was Received/Deposited/Bounced, while
     * `InstrumentLifecycleService::cancel()` required the payment already
     * `Reversed` before it would cancel a `received` instrument — a
     * circular precondition deadlock with no valid API ordering (proven
     * live in both directions by the MTP-TRE-23 repro).
     *
     * For a `received` instrument specifically, this method breaks the
     * cycle by cancelling the instrument atomically, INSIDE the caller's
     * open transaction, via the `InstrumentReversalCancellerInterface` port
     * (review I1: routed through Shared/Contracts so this Domain-tier
     * service never imports the Application-tier
     * `InstrumentLifecycleService` directly) — same GL entry +
     * `InstrumentEvent` audit row a standalone cancel would emit, just
     * without waiting for the payment to already be reversed (the caller
     * reverses it in the same transaction, right after this call returns).
     *
     * `Deposited`/`Bounced` instruments are deliberately NOT auto-cancelled
     * here: there is no domain-safe "cancel a deposited/bounced instrument"
     * lifecycle transition in `InstrumentLifecycleService` (its `cancel()`
     * only ever accepts `Received`) — those states keep failing closed
     * exactly as before.
     *
     * MUST be called from inside an open DB transaction, on an already
     * `lockForUpdate()`'d Payment. ONLY `reversePayment()` may call this —
     * `refundPayment()`/`partialRefund()` use
     * `assertInstrumentSettledForCashUndo()`.
     *
     * H1 fix (2026-08-03 gate finding): `$payment->instrument()->first()`
     * resolves via `payments.instrument_id` — a separate, unvalidated FK a
     * DIFFERENT payment can also point at (`PaymentController::store()`
     * lets an ordinary payment reference another payment's instrument with
     * no ownership check). `$payment->id` is passed to the port below so it
     * can assert the instrument is actually LINKED BACK to `$payment`
     * (`payment_instruments.payment_id === $payment->id`) before touching
     * anything — reversing a payment that merely REFERENCES someone else's
     * instrument must refuse, not cancel that other payment's instrument.
     *
     * DPA V4 (D-5): returns the id of the cancellation journal entry the
     * instrument lane posted, or `null` when the instrument branch does not govern
     * (no instrument at all) or posted nothing. A non-`null` return means "the
     * reversal's GL effect is already recorded — link it, create nothing, move no
     * cash"; `null` sends the caller to the cash branch, which applies D-17.
     *
     * @param  numeric-string  $netUnreversed  the reversing document's amount, for
     *                                         the D-5b equality assertion
     */
    private function resolveInstrumentForReversal(
        Payment $payment,
        ?string $userId,
        string $reason,
        string $netUnreversed,
        int $scale,
    ): ?string {
        $instrument = $payment->instrument()->lockForUpdate()->first();
        if ($instrument === null) {
            // D-5 row 1: no instrument at all. The CASH branch governs, subject
            // to D-6's shape gate and D-17's symmetry rule. Preserved verbatim
            // from the pre-V4 code — the exhaustive match below deliberately does
            // NOT swallow this case.
            return null;
        }

        // D-5 / gate N1: an EXHAUSTIVE match with NO `default` arm. An allowlist
        // (`in_array([Cleared, Cancelled])`) is explicitly rejected: `Clearing`,
        // `InTransit`, `Expired` and `Collected` would fall through into the cash
        // branch, and `Clearing` is legacy-reachable (`canClear()`/`canBounce()`
        // both accept it) with cash that has NOT arrived — the C1 phantom
        // cash-out, narrower. Every one of the nine `InstrumentStatus` cases is
        // named, so a tenth case is a compile error rather than a silent
        // fall-through into a money-moving branch.
        return match ($instrument->status) {
            InstrumentStatus::Received => $this->cancelReceivedInstrumentForReversal(
                $payment,
                $instrument,
                $userId,
                $reason,
                $netUnreversed,
                $scale,
            ),

            // Unchanged, deliberately: there is no domain-safe "cancel a
            // deposited/bounced instrument" lifecycle transition
            // (`InstrumentLifecycleService::cancel()` only ever accepts
            // `Received`), so these keep failing closed with the same message and
            // the same exception class they always had.
            InstrumentStatus::Deposited,
            InstrumentStatus::Bounced => throw new \RuntimeException(
                'Settle the payment instrument first (bounce or cancel) before using the cash refund/reverse path.'
            ),

            // C1 (gate Critical-1) + N1. Pre-V4 all six of these fell THROUGH the
            // if-chain into the cash branch. For `Cleared` that is wrong three
            // ways at once: clearing records its cash IN against the REMITTANCE's
            // bank repository (not `payments.repository_id`, which for a deferred
            // tender is the CUSTODY repository and is explicitly allowed a NULL
            // `gl_account_id`), for `instrument->amount - fee - feeVat` rather
            // than the payment nominal, and it never touches AR at all (AR was
            // credited at payment time against the PORTFOLIO account). So the cash
            // branch would either hard-fail on the missing `gl_account_id` or move
            // the nominal out of a till that never held the money, crediting the
            // wrong account. The other five are reserved-dormant statuses where
            // the cash has likewise not arrived.
            //
            // Refusing is NOT a regression: today a `Cleared` reversal already
            // restores AR with zero GL, and "silently zero" beats "silently
            // wrong". A correct unwind needs a bank-repository-aware reversal plus
            // a clearing fee/VAT ruling — out of scope, owner question OQ-A.
            InstrumentStatus::Cleared,
            InstrumentStatus::Cancelled,
            InstrumentStatus::Clearing,
            InstrumentStatus::InTransit,
            InstrumentStatus::Expired,
            InstrumentStatus::Collected => throw new \DomainException(
                "this payment's instrument is {$instrument->status->value}: reversing it needs the instrument "
                .'lane (a bank-repository-aware unwind), which this reversal path does not implement. '
                .'Refusing rather than moving cash out of a repository that never held it.'
            ),
        };
    }

    /**
     * D-5 `Received` arm: cancel the instrument atomically through the port and
     * return the cancellation journal-entry id it posted (or `null` when the
     * original payment carried no entry, so none was posted — D-17).
     *
     * D-5b (gate Important-9 + N10): the linked cancellation entry is sized on the
     * INSTRUMENT NOMINAL (`createInstrumentCancellationEntry(amount:
     * $instrument->amount)`), while the reversing document is sized on the NET
     * unreversed amount. They coincide by construction today — instruments are
     * created at the payment amount, and `assertInstrumentSettledForCashUndo()`
     * blocks every partial refund while an instrument is `Received`, so no refund
     * can precede this — but NOTHING enforced it. Assert it and fail closed: a
     * divergence would mean the document and the GL entry it links disagree about
     * how much was unwound.
     *
     * The comparison is `bccomp(..., $scale)`, never `!==` on the raw strings: the
     * two values arrive through different decimal casts and `'40.00'` vs
     * `'40.000'` would fire spuriously.
     *
     * @param  numeric-string  $netUnreversed
     */
    private function cancelReceivedInstrumentForReversal(
        Payment $payment,
        PaymentInstrument $instrument,
        ?string $userId,
        string $reason,
        string $netUnreversed,
        int $scale,
    ): ?string {
        /** @var numeric-string $instrumentAmount */
        $instrumentAmount = CurrencyScale::bcformat((string) $instrument->amount, $scale);

        // H1 ordering precedent (InstrumentLifecycleService: "identity BEFORE
        // status — identity has to hold before status is even meaningful"): the
        // same holds for the amount belt. `payments.instrument_id` is a separate,
        // unvalidated FK that a DIFFERENT payment can point at, and comparing this
        // reversal's net against a FOREIGN instrument's nominal is meaningless —
        // it would also mask the exploit refusal behind an accounting-mismatch
        // message. D-5b is therefore evaluated only for an instrument actually
        // linked back to this payment; the port refuses every other case on
        // identity.
        if ($instrument->payment_id === $payment->id
            && bccomp($instrumentAmount, $netUnreversed, $scale) !== 0) {
            throw new \DomainException(
                "instrument {$instrument->id} is for {$instrumentAmount} but the reversal unwinds "
                ."{$netUnreversed}: the cancellation entry and the reversing document would disagree "
                .'about how much was unwound. Refusing.'
            );
        }

        return $this->instrumentReversalCanceller->cancelForPaymentReversal(
            $instrument->id,
            $payment->id,
            $payment->tenant_id,
            $payment->company_id,
            $userId,
            "Auto-cancelled while reversing payment {$payment->reference}: {$reason}",
        );
    }

    /**
     * DPA V4 (T4, plan D-3): the per-document NET of a payment's whole refund
     * lineage — the original payment's own `payment_allocations` rows PLUS every
     * `payment_type = 'refund'` child's negative rows — summed SIGNED.
     *
     * This is the figure a reversing document must mirror. Mirroring the
     * ORIGINAL's allocations GROSS resurrects review finding C6 in a new shape:
     * invoice 1000, payment +1000 allocated 1000, `partialRefund('400')` writes
     * a -400 row against the refund CHILD; a gross -1000 reversal mirror then
     * gives `SUM(payment_allocations) = 1000 - 400 - 1000 = -400`, and
     * `Document::OUTSTANDING_BALANCE_SQL` (plus the Postgres balance_due-cache
     * trigger) computes `balance_due = 1000 - (-400) = 1400` — ABOVE the invoice
     * total, with 1400 of cash going out against 1000 collected. The net figure
     * (600) drives `SUM` to exactly 0 and `balance_due` to exactly 1000.
     *
     * `$excludePaymentId` is MANDATORY for the partial-refund caller, not
     * polish (plan I8 / gate Important-8): `unwindAllocationsProRata()` has
     * always excluded the IN-FLIGHT refund row from its prior-refund scan. A
     * helper without the parameter would silently CHANGE that predicate — and
     * would probably still pass the four pro-rata tests, because the in-flight
     * refund carries no allocation rows yet at that point, which makes the
     * change undetectable rather than harmless. `reversePayment()` passes `null`
     * (it has no in-flight sibling to exclude).
     *
     * D-3b — every NON-ZERO net is returned, NEGATIVES INCLUDED; only exact
     * zeros are skipped. A negative per-document net is reachable at dust scale
     * (the last pro-rata slice was historically uncapped — fixed separately in
     * T13), and dropping it would leave a residual negative allocation row that
     * the balance formula turns into `total + dust`, i.e. a balance ABOVE the
     * document total — precisely the C6 invariant this lane exists to protect.
     * A negative net is mirrored as a POSITIVE row, so every touched document's
     * lineage sums to exactly zero by construction.
     *
     * @param  int  $scale  currency scale of the ORIGINAL payment (rule 19: the
     *                      caller resolves it from `$original->currency`, never
     *                      from the no-arg `scale()` helper).
     * @return array<string, numeric-string> document id => signed net
     */
    private function netLiveAllocationsByDocument(
        Payment $original,
        int $scale,
        ?string $excludePaymentId = null,
    ): array {
        $lineageQuery = Payment::query()
            ->where('company_id', $original->company_id)
            ->where('original_payment_id', $original->id)
            ->where('payment_type', PaymentType::Refund->value);

        if ($excludePaymentId !== null) {
            $lineageQuery->where('id', '!=', $excludePaymentId);
        }

        /** @var list<string> $lineagePaymentIds */
        $lineagePaymentIds = $lineageQuery->pluck('id')->push($original->id)->all();

        /** @var array<string, numeric-string> $netByDocument */
        $netByDocument = [];
        PaymentAllocation::whereIn('payment_id', $lineagePaymentIds)
            ->get()
            ->each(function (PaymentAllocation $row) use (&$netByDocument, $scale): void {
                /** @var numeric-string $running */
                $running = $netByDocument[$row->document_id] ?? '0';
                $netByDocument[$row->document_id] = bcadd($running, (string) $row->amount, $scale);
            });

        return array_filter(
            $netByDocument,
            static fn (string $net): bool => bccomp($net, '0', $scale) !== 0,
        );
    }

    /**
     * MTP-TRE-10 fix: unwind PaymentAllocation pro-rata for a partial-refund
     * amount, and reopen the affected document(s)' `balance_due` — the
     * partial-refund counterpart to refundPayment()'s full unwind (which
     * mirrors every original allocation 1:1 because it always refunds
     * 100%).
     *
     * Mechanics (mirrors refundPayment()'s audit-trail-preserving shape):
     * for each document the ORIGINAL payment is still live-allocated
     * against, insert a NEGATIVE PaymentAllocation row (never delete/mutate
     * the original positive row) sized as this document's pro-rata share of
     * `$unwindAmount`. "Live" = the original allocation minus whatever prior
     * partial refunds of this SAME original payment already unwound for
     * that document — so repeated partial refunds of a multi-document
     * payment can never unwind more than what's actually still allocated.
     *
     * Rounding: proportional shares are truncated to the document's
     * currency scale; the residual (rounding dust) goes to the LAST
     * document in deterministic (document_id ASC) order, so
     * sum(shares) === $unwindAmount exactly — same rounding contract as
     * buildProportionalMap() above.
     *
     * balance_due: the documents table has a Postgres trigger
     * (`payment_allocation_balance_update`) that recomputes
     * `balance_due = total - SUM(payment_allocations) - SUM(credit_note_allocations)`
     * on every payment_allocations write — but it is a no-op on SQLite
     * (the test driver). This method explicitly recomputes `balance_due`
     * with the SAME formula in PHP (portable across both), mirroring the
     * pattern already used by `OutboundInstrumentService::cancel()`.
     *
     * @param  numeric-string  $unwindAmount  Positive refund amount at currency scale.
     */
    private function unwindAllocationsProRata(Payment $original, string $refundPaymentId, string $unwindAmount): void
    {
        $scale = $this->scaleResolver->getScale($original->currency);

        /** @var Collection<int, PaymentAllocation> $originalAllocations */
        $originalAllocations = PaymentAllocation::where('payment_id', $original->id)->get();
        if ($originalAllocations->isEmpty()) {
            // Advance/unallocated payment — nothing to unwind.
            return;
        }

        // Live remaining allocation per document = the NET of this payment's
        // whole refund lineage, EXCLUDING the in-flight refund row (which has no
        // allocation rows yet at this point, but excluding it is the predicate
        // this method has always used — see netLiveAllocationsByDocument()'s
        // `$excludePaymentId` note).
        //
        // DPA V4 (T4): the per-document net is computed by the shared helper so
        // reversePayment() and this method cannot drift apart. What stays LOCAL
        // to the unwind path is the NON-POSITIVE FILTER below: a partial refund
        // must never target a document that is already over-unwound, whereas a
        // reversal deliberately mirrors negative nets too (D-3b).
        /** @var array<string, numeric-string> $netByDocument */
        $netByDocument = $this->netLiveAllocationsByDocument($original, $scale, $refundPaymentId);

        /** @var array<string, numeric-string> $liveByDocument */
        $liveByDocument = [];
        /** @var numeric-string $liveTotal */
        $liveTotal = '0';
        foreach ($netByDocument as $documentId => $net) {
            if (bccomp($net, '0', $scale) <= 0) {
                continue;
            }
            $liveByDocument[$documentId] = $net;
            $liveTotal = bcadd($liveTotal, $net, $scale);
        }

        if (bccomp($liveTotal, '0', $scale) <= 0) {
            // Everything already unwound by prior partial refunds — nothing live.
            return;
        }

        // Defensive cap: never unwind more than what's actually live (the
        // caller's assertWithinRefundableBalance() already guards the
        // payment-level cumulative total; this guards the allocation side).
        /** @var numeric-string $toUnwind */
        $toUnwind = bccomp($unwindAmount, $liveTotal, $scale) > 0 ? $liveTotal : $unwindAmount;

        $documentIds = array_keys($liveByDocument);
        sort($documentIds);

        // DPA V4 / T13 — CAP EVERY SLICE, THEN REDISTRIBUTE THE RESIDUAL.
        //
        // The pre-V4 loop capped non-last slices against `$remainingToAllocate` but
        // gave the LAST document `$remainingToAllocate` verbatim, with no cap
        // against its own live share. Because each share is TRUNCATED to currency
        // scale, every non-last slice is <= its exact share and all the dust
        // accumulates onto the last one, which could therefore be OVER-unwound —
        // producing a negative lineage net and a `balance_due` ABOVE the document
        // total (the C6 invariant). Worked fixture: three documents live `0.333`
        // each, `liveTotal = toUnwind = 0.999` → doc1/doc2 truncate to `0.332` and
        // doc3 receives `0.335` against a `0.333` share.
        //
        // Capping the last slice and STOPPING THERE is the mirror-image defect: the
        // same fixture then unwinds `0.997`, silently leaving `0.002` of the refund
        // NEVER unwound. The documents keep allocation they should have lost, so
        // `balance_due` lands BELOW the true receivable while the refund journal
        // entry debited AR for the full `0.999` — an AR understatement and a
        // document-vs-GL divergence. Both defects are caught only by the invariant
        // `Σ slices == toUnwind`; "no slice exceeds its live share" and
        // "`balance_due <= total`" are both SATISFIED by the under-unwind.
        //
        // Pass 1 floors each share to scale and records its fractional remainder.
        // Pass 2 is a SINGLE bounded largest-remainder sweep: walk the documents in
        // (remainder DESC, document_id ASC) order and give each one up to its
        // remaining headroom, capped by what is left of the residual. No iterative
        // re-proration.
        //
        // Termination is guaranteed, not hoped for: `Σ headroom = liveTotal −
        // Σ base` and `residual = toUnwind − Σ base`, and `$toUnwind` was already
        // clamped to `<= $liveTotal` above, so `residual <= Σ headroom` always. The
        // ordering makes the outcome deterministic across drivers.
        $highScale = $scale + 10;

        /** @var array<string, numeric-string> $slices */
        $slices = [];
        /** @var array<string, numeric-string> $remainders */
        $remainders = [];
        /** @var numeric-string $allocated */
        $allocated = '0';

        foreach ($documentIds as $documentId) {
            /** @var numeric-string $share */
            $share = $liveByDocument[$documentId];
            /** @var numeric-string $ratio */
            $ratio = bcdiv($share, $liveTotal, $highScale);
            /** @var numeric-string $exact */
            $exact = bcmul($ratio, $toUnwind, $highScale);
            /** @var numeric-string $slice */
            $slice = CurrencyScale::bcformat($exact, $scale);

            // Cap EVERY slice — including what used to be the last one — against the
            // document's own live share.
            if (bccomp($slice, $share, $scale) > 0) {
                /** @var numeric-string $slice */
                $slice = $share;
            }

            $slices[$documentId] = $slice;
            $remainders[$documentId] = bcsub($exact, $slice, $highScale);
            $allocated = bcadd($allocated, $slice, $scale);
        }

        /** @var numeric-string $residual */
        $residual = bcsub($toUnwind, $allocated, $scale);

        if (bccomp($residual, '0', $scale) > 0) {
            $ordered = $documentIds;
            usort($ordered, static function (string $a, string $b) use ($remainders, $highScale): int {
                $byRemainder = bccomp($remainders[$b], $remainders[$a], $highScale);

                return $byRemainder !== 0 ? $byRemainder : strcmp($a, $b);
            });

            foreach ($ordered as $documentId) {
                if (bccomp($residual, '0', $scale) <= 0) {
                    break;
                }

                /** @var numeric-string $headroom */
                $headroom = bcsub($liveByDocument[$documentId], $slices[$documentId], $scale);
                if (bccomp($headroom, '0', $scale) <= 0) {
                    continue;
                }

                /** @var numeric-string $topUp */
                $topUp = bccomp($headroom, $residual, $scale) > 0 ? $residual : $headroom;
                $slices[$documentId] = bcadd($slices[$documentId], $topUp, $scale);
                $allocated = bcadd($allocated, $topUp, $scale);
                $residual = bcsub($residual, $topUp, $scale);
            }
        }

        // Fail closed on the invariant rather than writing a silently wrong split.
        // Unreachable given the clamp above; kept because the alternative to an
        // exception here is unreversed money drift discovered at reconcile time.
        if (bccomp($allocated, $toUnwind, $scale) !== 0) {
            throw new \DomainException(
                "pro-rata unwind could not distribute {$toUnwind} exactly (allocated {$allocated}); "
                .'refusing to write a split that would leave the document set and the GL out of step.'
            );
        }

        /** @var list<string> $touchedDocumentIds */
        $touchedDocumentIds = [];

        foreach ($documentIds as $documentId) {
            /** @var numeric-string $slice */
            $slice = $slices[$documentId];
            if (bccomp($slice, '0', $scale) <= 0) {
                continue;
            }

            PaymentAllocation::create([
                'payment_id' => $refundPaymentId,
                'document_id' => $documentId,
                'amount' => bcmul($slice, '-1', $scale),
            ]);

            $touchedDocumentIds[] = $documentId;
        }

        // I2 fix: recompute balance_due AND revert Paid -> Posted, mirroring
        // OutboundInstrumentService::cancel()'s pattern exactly (the
        // implementer's own cited "mirror" does both; the first MTP-TRE-10
        // fix only did the balance_due half).
        $this->recomputeDocumentBalances($touchedDocumentIds, $original->tenant_id, $original->company_id, $scale);
    }

    /**
     * Recompute `balance_due` for a set of documents from their CURRENT
     * `payment_allocations` + `credit_note_allocations` rows, and revert
     * `DocumentStatus::Paid` -> `Posted` when the recomputed balance is
     * greater than zero — mirrors `OutboundInstrumentService::cancel()`'s
     * pattern exactly (review finding I2: documents flip to `Paid` on full
     * allocation, and every downstream consumer — AgedReceivablesService,
     * PaymentAllocationService's outstanding-document lookup, the
     * `documents_balance_due_index` partial index — filters on
     * `status = Posted`; reopening `balance_due` alone left those consumers
     * still excluding the document).
     *
     * Portable across Postgres (where the `balance_due`-cache trigger would
     * otherwise also fire) and SQLite (the test driver, where the trigger
     * is a no-op) — this PHP recompute is authoritative in both, and safe
     * to run alongside the Postgres trigger (both converge on the same
     * value from the same rows).
     *
     * Locks each document row (`lockForUpdate()`) — see the lock-order note
     * on `postRefundGlAndMovement()` (review finding I9): callers MUST take
     * these Document locks BEFORE any GL company-advisory lock in the same
     * transaction.
     *
     * @param  iterable<string>  $documentIds
     */
    private function recomputeDocumentBalances(
        iterable $documentIds,
        string $tenantId,
        string $companyId,
        int $fallbackScale,
    ): void {
        foreach ($documentIds as $documentId) {
            /** @var Document|null $document */
            $document = Document::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->find($documentId);
            if ($document === null) {
                continue;
            }

            $scale = $this->scaleResolver->getScaleSafe($document->currency, $fallbackScale);
            /** @var numeric-string $documentTotal */
            $documentTotal = CurrencyScale::bcformat((string) ($document->total ?? '0'), $scale);

            /** @var numeric-string $allocatedSum */
            $allocatedSum = PaymentAllocation::where('document_id', $documentId)
                ->get('amount')
                ->reduce(
                    fn (string $sum, PaymentAllocation $row): string => bcadd($sum, (string) $row->amount, $scale),
                    '0'
                );
            /** @var numeric-string $creditedSum */
            $creditedSum = CreditNoteAllocation::where('invoice_id', $documentId)
                ->get('amount')
                ->reduce(
                    fn (string $sum, CreditNoteAllocation $row): string => bcadd($sum, (string) $row->amount, $scale),
                    '0'
                );

            /** @var numeric-string $balanceDue */
            $balanceDue = bcsub(bcsub($documentTotal, $allocatedSum, $scale), $creditedSum, $scale);

            $document->forceFill([
                'balance_due' => $balanceDue,
                'status' => $document->status === DocumentStatus::Paid && bccomp($balanceDue, '0', $scale) > 0
                    ? DocumentStatus::Posted
                    : $document->status,
            ])->save();
        }
    }

    /**
     * Prorate a refund total across all of a receipt's original payments.
     *
     * Returns one RefundAllocation per touched original payment.
     * Each allocation corresponds to one newly-written negative Payment row
     * with payment_type = Refund (explicitly set — Codex review 2 additional finding).
     *
     * DB-level idempotency:
     *   A unique partial index on (company_id, original_payment_id, refund_request_id)
     *   WHERE payment_type = 'refund' serialises concurrent callers. If the index
     *   rejects a duplicate insert, this method reads back the existing rows and
     *   returns them — callers always get the same result for the same refund_request_id.
     *
     * Rounding (spec §5.5 / §4.3):
     *   Amounts are allocated using CurrencyScale::bcformat() (MEMORY.md monetary-
     *   precision pitfall). The residual cent (rounding remainder) is allocated to the
     *   LAST payment in deterministic order (amount DESC, id ASC) so that
     *   sum(allocations) == totalToRefund exactly.
     *
     * @param  Receipt  $originalReceipt  The sale receipt whose payments we prorate over.
     * @param  string  $totalToRefund  Positive bcmath-safe decimal at currency scale
     *                                 (e.g. "50.00" for EUR, "50.000" for TND).
     * @param  ProrationStrategy  $strategy  How to split the total across payments.
     * @param  string  $refundRequestId  UUID idempotency key for this refund operation.
     * @param  string|null  $authorizedByUserId  Manager/admin UUID when an override fired.
     * @param  string|null  $policyTrigger  Policy condition code (e.g. "over_threshold").
     * @param  array<string, string>|null  $cashierAllocations  Required for CashierChoice:
     *                                                          map of [original_payment_id => positive_amount]. Caller must have
     *                                                          verified pos.refund_destination_override permission before passing this.
     * @return list<RefundAllocation>
     *
     * @throws \InvalidArgumentException If totalToRefund <= 0 or exceeds receipt total.
     * @throws \InvalidArgumentException If strategy = CashierChoice but $cashierAllocations is null.
     * @throws RefundIdempotencyException (internal; callers normally receive existing rows).
     */
    public function refundReceiptPayments(
        Receipt $originalReceipt,
        string $totalToRefund,
        ProrationStrategy $strategy,
        string $refundRequestId,
        ?string $authorizedByUserId = null,
        ?string $policyTrigger = null,
        ?array $cashierAllocations = null,
    ): array {
        // Resolve currency scale from the receipt's currency
        $scale = $this->scaleResolver->getScale($originalReceipt->currency);

        /** @var numeric-string $totalToRefund */
        if (bccomp($totalToRefund, '0', $scale) <= 0) {
            throw new \InvalidArgumentException('totalToRefund must be greater than zero');
        }

        // Load original payments in deterministic order: amount DESC, id ASC
        //
        // DPA V4 / T14 (gate Important-6) — the `status = Completed` predicate is
        // LOAD-BEARING, not hygiene. Without it this query returned POS payments
        // that had already been unwound, while `buildLargestFirstMap()` computes
        // `alreadyRefunded` from `payment_type = 'refund'` rows ONLY — so a payment
        // unwound by a path that writes no refund row stayed fully refundable and
        // could be paid out TWICE. Those paths are real and reachable:
        // `InstrumentLifecycleService::performCancellation()`'s
        // `CancellationShape::PosRevenue` arm writes `status => Reversed` directly,
        // and so does the POS void lane. D-6's refusal to REVERSE a POS payment
        // does not close this — only this predicate does.
        //
        // It changes THREE behaviours, all deliberately: the proration set itself,
        // the `$receiptTotal` validation ceiling below (which is summed from this
        // same set, so it correctly shrinks to the still-live legs), and the
        // `isEmpty()` early return (a fully unwound receipt now prorates nothing
        // instead of paying out again).
        $originalPayments = Payment::where('company_id', $originalReceipt->company_id)
            ->where('payment_type', PaymentType::POS->value)
            ->where('status', PaymentStatus::Completed->value)
            ->whereIn('id', function ($query) use ($originalReceipt): void {
                $query->select('treasury_payment_id')
                    ->from('pos_receipt_payments')
                    ->where('receipt_id', $originalReceipt->id)
                    ->whereNotNull('treasury_payment_id');
            })
            ->orderByRaw('CAST(amount AS NUMERIC) DESC')
            ->orderBy('id', 'asc')
            ->get();

        if ($originalPayments->isEmpty()) {
            // Fallback: no treasury payment rows linked. Nothing to prorate.
            return [];
        }

        foreach ($originalPayments as $originalPayment) {
            $this->assertInstrumentSettledForCashUndo($originalPayment);
        }

        // Validate totalToRefund does not exceed receipt total
        /** @var numeric-string $receiptTotal */
        $receiptTotal = '0';
        foreach ($originalPayments as $p) {
            /** @var numeric-string $pAmount */
            $pAmount = (string) $p->amount;
            /** @var numeric-string $receiptTotal */
            $receiptTotal = bcadd($receiptTotal, $pAmount, $scale);
        }

        if (bccomp($totalToRefund, $receiptTotal, $scale) > 0) {
            throw new \InvalidArgumentException(
                "totalToRefund ({$totalToRefund}) exceeds receipt total ({$receiptTotal})"
            );
        }

        // Idempotency check: if rows already exist for this refund_request_id, return them
        $existing = $this->findExistingProrationRows($originalReceipt->company_id, $refundRequestId);
        if (! empty($existing)) {
            return $existing;
        }

        // Build allocation map based on strategy
        $allocationMap = match ($strategy) {
            ProrationStrategy::Proportional => $this->buildProportionalMap(
                $originalPayments,
                $totalToRefund,
                $receiptTotal,
                $scale
            ),
            ProrationStrategy::LargestFirst => $this->buildLargestFirstMap(
                $originalPayments,
                $totalToRefund,
                $scale
            ),
            ProrationStrategy::CashierChoice => $this->buildCashierChoiceMap(
                $originalPayments,
                $cashierAllocations,
                $totalToRefund,
                $scale
            ),
        };

        // Write refund Payment rows inside a transaction; handle idempotency race
        return DB::transaction(function () use (
            $originalReceipt,
            $allocationMap,
            $refundRequestId,
            $authorizedByUserId,
            $policyTrigger,
            $scale,
        ): array {
            // Re-check inside transaction (another request may have won the race)
            $existing = $this->findExistingProrationRows($originalReceipt->company_id, $refundRequestId);
            if (! empty($existing)) {
                return $existing;
            }

            $result = [];
            /** @var list<Payment> $createdRefundRows */
            $createdRefundRows = [];

            foreach ($allocationMap as $originalPaymentId => $positiveAmount) {
                /** @var numeric-string $positiveAmount */
                if (bccomp($positiveAmount, '0', $scale) <= 0) {
                    // Zero allocation — skip (LargestFirst strategy may leave some untouched)
                    continue;
                }

                // Defense-in-depth tenant/company scoping. The id originated
                // from a query already filtered on $originalReceipt->company_id,
                // so this lookup must hit the same tenant. Belt-and-braces.
                /** @var Payment $original */
                $original = Payment::query()
                    ->where('tenant_id', $originalReceipt->tenant_id)
                    ->where('company_id', $originalReceipt->company_id)
                    ->find($originalPaymentId);

                try {
                    /** @var numeric-string $negativeAmount */
                    $negativeAmount = CurrencyScale::bcformat(
                        bcmul($positiveAmount, '-1', $scale),
                        $scale
                    );
                    // Spec §13 writer-inventory row 9 — `PaymentRefundService` receipt-
                    // proration refund rows → inherit the original payment's `origin`.
                    // A proration refund of POS-origin payments stays POS-origin (so the
                    // refund row's audit lineage matches the receipt's authoring surface).
                    // Task 22 round-2 (Codex T22-B2 BLOCKER): `originForRefund()`
                    // falls back to `PaymentOrigin::UnknownLegacy` for NULL-origin
                    // legacy originals — see helper docblock. NOTE: the proration
                    // query above (`:349`) filters `payment_type = POS`, so in
                    // steady state this writer only sees POS-typed originals. But
                    // the §13 contract is unconditional; the helper handles every
                    // input variant uniformly.
                    $refundRow = Payment::create([
                        'id' => Str::uuid()->toString(),
                        'tenant_id' => $original->tenant_id,
                        'company_id' => $original->company_id,
                        'partner_id' => $original->partner_id,
                        'payment_method_id' => $original->payment_method_id,
                        'instrument_id' => $original->instrument_id,
                        'repository_id' => $original->repository_id,
                        'location_id' => $original->location_id,
                        // Negative amount — refunds reduce the cash side
                        'amount' => $negativeAmount,
                        'currency' => $original->currency,
                        'payment_date' => now(),
                        'status' => PaymentStatus::Completed,
                        // IMPORTANT: always explicit — never rely on column default (Codex review 2 §4.3)
                        'payment_type' => PaymentType::Refund,
                        'origin' => $this->originForRefund($original),
                        // Audit columns (spec §3.6 / F27)
                        'original_payment_id' => $originalPaymentId,
                        'refund_request_id' => $refundRequestId,
                        'authorized_by_user_id' => $authorizedByUserId,
                        'policy_trigger' => $policyTrigger,
                        'reference' => "Refund allocation for payment {$original->reference}",
                        'notes' => "Prorated refund: {$positiveAmount} {$original->currency}",
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    // Another concurrent request won the race on this specific row.
                    // Read back the existing rows and return them.
                    return $this->findExistingProrationRows($original->company_id, $refundRequestId);
                }

                /** @var numeric-string $positiveAmountForDto */
                $positiveAmountForDto = $positiveAmount;
                $result[] = new RefundAllocation(
                    originalPaymentId: $originalPaymentId,
                    paymentId: $refundRow->id,
                    amount: $positiveAmountForDto,
                );
                $createdRefundRows[] = $refundRow;
            }

            // Audit trail (G3): one PaymentRefunded per allocation, mirroring
            // refundPayment()/partialRefund(). Registered AFTER the loop so it
            // covers exactly the rows committed by this transaction — the
            // idempotency early-returns and the UniqueConstraintViolation race
            // path all return before reaching here, so no event fires for a
            // replay or a lost race. refundReceiptPayments takes no $reason
            // arg, so the policy trigger stands in (constant fallback when the
            // caller supplied none).
            DB::afterCommit(function () use ($createdRefundRows, $policyTrigger): void {
                $reason = $policyTrigger ?? 'pos_return_proration';

                foreach ($createdRefundRows as $refundRow) {
                    event(new PaymentRefunded(
                        paymentId: $refundRow->id,
                        tenantId: $refundRow->tenant_id,
                        companyId: $refundRow->company_id,
                        originalPaymentId: (string) $refundRow->original_payment_id,
                        amount: $refundRow->amount,
                        currency: $refundRow->currency,
                        reason: $reason,
                        refundedAt: ($refundRow->created_at ?? now())->toIso8601String(),
                    ));
                }
            });

            return $result;
        });
    }

    // -------------------------------------------------------------------------
    // Proration strategy builders
    // -------------------------------------------------------------------------

    /**
     * Proportional: each payment gets floor((paymentAmount / receiptTotal) * totalToRefund).
     * Residual goes to the LAST payment in deterministic order (amount DESC, id ASC).
     *
     * @param  Collection<int, Payment>  $payments
     * @return array<string, string> map of [payment_id => positive_amount]
     */
    /**
     * @param  Collection<int, Payment>  $payments
     * @param  numeric-string  $totalToRefund
     * @param  numeric-string  $receiptTotal
     * @return array<string, numeric-string>
     */
    private function buildProportionalMap(
        Collection $payments,
        string $totalToRefund,
        string $receiptTotal,
        int $scale
    ): array {
        /** @var array<string, numeric-string> $map */
        $map = [];
        /** @var numeric-string $allocated */
        $allocated = '0';

        foreach ($payments as $payment) {
            // share = floor((paymentAmount / receiptTotal) * totalToRefund)
            // Use high-precision intermediate to avoid truncation error
            /** @var numeric-string $paymentAmt */
            $paymentAmt = (string) $payment->amount;
            /** @var numeric-string $share */
            $share = bcdiv($paymentAmt, $receiptTotal, $scale + 10);
            /** @var numeric-string $raw */
            $raw = bcmul($share, $totalToRefund, $scale + 10);
            // Truncate (floor) to currency scale
            /** @var numeric-string $slice */
            $slice = CurrencyScale::bcformat($raw, $scale);
            // Enforce no slice > what's left (guard against floating weirdness at scale edges)
            /** @var numeric-string $remaining */
            $remaining = bcsub($totalToRefund, $allocated, $scale);
            if (bccomp($slice, $remaining, $scale) > 0) {
                $slice = $remaining;
            }
            $map[$payment->id] = $slice;
            /** @var numeric-string $allocated */
            $allocated = bcadd($allocated, $slice, $scale);
        }

        // Residual (rounding dust) to the LAST payment
        /** @var numeric-string $residual */
        $residual = bcsub($totalToRefund, $allocated, $scale);
        if (bccomp($residual, '0', $scale) !== 0 && ! $payments->isEmpty()) {
            /** @var Payment $last */
            $last = $payments->last();
            /** @var numeric-string $existingLastAmount */
            $existingLastAmount = $map[$last->id] ?? '0';
            /** @var numeric-string $newLastAmount */
            $newLastAmount = bcadd($existingLastAmount, $residual, $scale);
            $map[$last->id] = $newLastAmount;
        }

        return $map;
    }

    /**
     * LargestFirst: drain from the largest payment first until totalToRefund consumed.
     * Respects already-refunded amounts for each original payment (cumulative across
     * multiple refund calls for the same receipt).
     * Tiebreaker: id ASC (payments are already sorted amount DESC, id ASC).
     *
     * @param  Collection<int, Payment>  $payments
     * @param  numeric-string  $totalToRefund
     * @return array<string, numeric-string> map of [payment_id => positive_amount]
     */
    private function buildLargestFirstMap(
        Collection $payments,
        string $totalToRefund,
        int $scale
    ): array {
        /** @var array<string, numeric-string> $map */
        $map = [];
        /** @var numeric-string $remaining */
        $remaining = $totalToRefund;

        foreach ($payments as $payment) {
            if (bccomp($remaining, '0', $scale) <= 0) {
                break;
            }

            // Compute how much of this payment has already been refunded (any prior requests)
            /** @var numeric-string $alreadyRefunded */
            $alreadyRefunded = Payment::where('original_payment_id', $payment->id)
                ->where('payment_type', PaymentType::Refund->value)
                ->get()
                ->reduce(function (string $carry, Payment $refundRow) use ($scale): string {
                    /** @var numeric-string $carry */
                    // refund rows have negative amounts; take absolute value
                    /** @var numeric-string $absAmt */
                    $absAmt = ltrim((string) $refundRow->amount, '-');

                    return bcadd($carry, $absAmt, $scale);
                }, '0');

            /** @var numeric-string $paymentAmount */
            $paymentAmount = CurrencyScale::bcformat((string) $payment->amount, $scale);
            /** @var numeric-string $available */
            $available = bcsub($paymentAmount, $alreadyRefunded, $scale);

            if (bccomp($available, '0', $scale) <= 0) {
                // This payment is fully exhausted
                continue;
            }

            // How much can we take from this payment?
            /** @var numeric-string $take */
            $take = bccomp($remaining, $available, $scale) >= 0
                ? $available
                : $remaining;
            $map[$payment->id] = $take;
            /** @var numeric-string $remaining */
            $remaining = bcsub($remaining, $take, $scale);
        }

        return $map;
    }

    /**
     * CashierChoice: use caller-supplied per-payment allocations.
     * Validates that sum(allocations) == totalToRefund.
     *
     * @param  Collection<int, Payment>  $payments
     * @param  array<string, string>|null  $cashierAllocations
     * @param  numeric-string  $totalToRefund
     * @return array<string, numeric-string> map of [payment_id => positive_amount]
     */
    private function buildCashierChoiceMap(
        Collection $payments,
        ?array $cashierAllocations,
        string $totalToRefund,
        int $scale
    ): array {
        if ($cashierAllocations === null) {
            throw new \InvalidArgumentException(
                'cashierAllocations must be provided for CashierChoice strategy'
            );
        }

        // Validate each allocation references a real original payment
        $validIds = $payments->pluck('id')->all();
        foreach ($cashierAllocations as $paymentId => $amount) {
            if (! in_array($paymentId, $validIds, true)) {
                throw new \InvalidArgumentException(
                    "cashierAllocations references unknown payment id: {$paymentId}"
                );
            }
        }

        // Validate sum of allocations equals totalToRefund
        /** @var numeric-string $sum */
        $sum = '0';
        foreach ($cashierAllocations as $amount) {
            /** @var numeric-string $amount */
            /** @var numeric-string $sum */
            $sum = bcadd($sum, $amount, $scale);
        }
        if (bccomp($sum, $totalToRefund, $scale) !== 0) {
            throw new \InvalidArgumentException(
                "Sum of cashier allocations ({$sum}) must equal totalToRefund ({$totalToRefund})"
            );
        }

        /** @var array<string, numeric-string> $cashierAllocations */
        return $cashierAllocations;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Find the existing full refund for a reversed payment.
     *
     * DPA V4 (D-11): the `payment_type` filter is REQUIRED. This is the same
     * untyped reference heuristic `getRefundHistory()` uses, and a reversal row
     * matches it on every predicate — negative amount equal to the original's
     * inverse, `Completed`, and a reference containing the original's. Without the
     * filter, a caller asking for the full refund of a REVERSED-not-refunded
     * payment would silently receive the REVERSAL document instead. With it, the
     * lookup finds nothing and the existing "data integrity issue" exception below
     * fires — which is now an ACCURATE description of the state.
     *
     * @throws \RuntimeException If refund cannot be found
     */
    private function findExistingFullRefund(Payment $payment): Payment
    {
        // Find refund by looking for a negative payment that references this payment
        /** @var Payment|null $refund */
        $refund = Payment::where('tenant_id', $payment->tenant_id)
            ->where('partner_id', $payment->partner_id)
            ->where('payment_type', PaymentType::Refund->value)
            ->where('amount', bcmul($payment->amount, '-1', $this->scale()))
            ->where('reference', 'like', '%'.$payment->reference.'%')
            ->where('status', PaymentStatus::Completed)
            ->first();

        if ($refund === null) {
            throw new \RuntimeException(
                'Payment is reversed but no refund record found. Data integrity issue.'
            );
        }

        return $refund;
    }

    /**
     * Return existing RefundAllocation VOs for a (company_id, refund_request_id) pair.
     *
     * @return list<RefundAllocation>
     */
    private function findExistingProrationRows(string $companyId, string $refundRequestId): array
    {
        $rows = Payment::where('company_id', $companyId)
            ->where('refund_request_id', $refundRequestId)
            ->where('payment_type', PaymentType::Refund->value)
            ->whereNotNull('original_payment_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        /** @var list<RefundAllocation> $result */
        $result = $rows->map(function (Payment $p): RefundAllocation {
            /** @var numeric-string $absAmount */
            $absAmount = CurrencyScale::bcformat(
                ltrim((string) $p->amount, '-'),
                $this->scaleResolver->getScale($p->currency)
            );

            return new RefundAllocation(
                originalPaymentId: (string) $p->original_payment_id,
                paymentId: $p->id,
                // amount is stored as negative; return the positive absolute value
                amount: $absAmount,
            );
        })->values()->all();

        return $result;
    }
}
