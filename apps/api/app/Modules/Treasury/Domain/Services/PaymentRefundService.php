<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Services;

use App\Modules\POS\Domain\Receipt;
use App\Modules\Treasury\Application\DTOs\RefundAllocation;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\ProrationStrategy;
use App\Modules\Treasury\Domain\Events\PaymentRefunded;
use App\Modules\Treasury\Domain\Events\PaymentReversed;
use App\Modules\Treasury\Domain\Exceptions\RefundIdempotencyException;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentRefundService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
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
        ?string $userId = null
    ): Payment {
        // Idempotent: if already reversed (refunded), find and return the existing refund
        if ($payment->status === PaymentStatus::Reversed) {
            return $this->findExistingFullRefund($payment);
        }

        if ($payment->status !== PaymentStatus::Completed) {
            throw new \RuntimeException('Only completed payments can be refunded');
        }

        return DB::transaction(function () use ($payment, $reason, $userId): Payment {
            // Double-check inside transaction (another request may have refunded it)
            $payment->refresh();
            if ($payment->status === PaymentStatus::Reversed) {
                return $this->findExistingFullRefund($payment);
            }
            // Create refund payment (negative amount)
            // IMPORTANT: payment_type is set explicitly to Refund to avoid the column
            // default 'document_payment' (Codex review 2 additional finding).
            //
            // Spec §13 writer-inventory row 7 — `PaymentRefundService::refundPayment()`
            // → inherit the original payment's `origin`. A refund of a POS-origin
            // payment is itself POS-origin; a refund of a web_admin payment is
            // web_admin. Legacy rows with `origin = NULL` inherit NULL (the same
            // ambiguity their parent row carries — refusing to coerce). `fiscal_event_id`
            // is NOT inherited — refunds are not authored by the device.
            $refund = Payment::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $payment->tenant_id,
                'company_id' => $payment->company_id,
                'partner_id' => $payment->partner_id,
                'payment_method_id' => $payment->payment_method_id,
                'instrument_id' => $payment->instrument_id,
                'repository_id' => $payment->repository_id,
                'amount' => bcmul($payment->amount, '-1', $this->scale()), // Negative amount
                'currency' => $payment->currency,
                'payment_date' => now(),
                'status' => PaymentStatus::Completed,
                'payment_type' => PaymentType::Refund,
                'origin' => $payment->origin,
                'reference' => "Refund for payment {$payment->reference}",
                'notes' => "Refund: {$reason}",
                'created_by' => $userId,
            ]);

            // Reverse original payment allocations
            $originalAllocations = $payment->allocations;

            foreach ($originalAllocations as $allocation) {
                PaymentAllocation::create([
                    'payment_id' => $refund->id,
                    'document_id' => $allocation->document_id,
                    'amount' => bcmul($allocation->amount, '-1', $this->scale()), // Negative amount
                ]);
            }

            // Mark original payment as reversed
            $payment->update([
                'status' => PaymentStatus::Reversed,
                'notes' => ($payment->notes ?? '')."\n\nRefunded: {$reason}",
            ]);

            DB::afterCommit(function () use ($refund, $payment, $reason): void {
                event(new PaymentRefunded(
                    paymentId: $refund->id,
                    tenantId: $refund->tenant_id,
                    companyId: $refund->company_id,
                    originalPaymentId: $payment->id,
                    amount: $refund->amount,
                    currency: $refund->currency,
                    reason: $reason,
                    refundedAt: ($refund->created_at ?? now())->toIso8601String(),
                ));
            });

            return $refund;
        });
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
        ?string $userId = null
    ): Payment {
        if ($payment->status !== PaymentStatus::Completed) {
            throw new \RuntimeException('Only completed payments can be refunded');
        }

        // Validate refund amount
        /** @var numeric-string $amount */
        /** @var numeric-string $paymentAmount */
        $paymentAmount = $payment->amount;
        if (bccomp($amount, '0', $this->scale()) <= 0) {
            throw new \InvalidArgumentException('Refund amount must be greater than zero');
        }

        if (bccomp($amount, $paymentAmount, $this->scale()) > 0) {
            throw new \InvalidArgumentException('Refund amount cannot exceed original payment amount');
        }

        return DB::transaction(function () use ($payment, $amount, $reason, $userId): Payment {
            // Create partial refund payment (negative amount)
            // IMPORTANT: payment_type is set explicitly to Refund to avoid the column
            // default 'document_payment' (Codex review 2 additional finding).
            //
            // Spec §13 writer-inventory row 8 — `PaymentRefundService::partialRefund()`
            // → inherit the original payment's `origin`. Same disposition as
            // `refundPayment()` above.
            $refund = Payment::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $payment->tenant_id,
                'company_id' => $payment->company_id,
                'partner_id' => $payment->partner_id,
                'payment_method_id' => $payment->payment_method_id,
                'instrument_id' => $payment->instrument_id,
                'repository_id' => $payment->repository_id,
                'amount' => bcmul($amount, '-1', $this->scale()), // Negative amount
                'currency' => $payment->currency,
                'payment_date' => now(),
                'status' => PaymentStatus::Completed,
                'payment_type' => PaymentType::Refund,
                'origin' => $payment->origin,
                'reference' => "Partial refund for payment {$payment->reference}",
                'notes' => "Partial refund ({$amount}): {$reason}",
                'created_by' => $userId,
            ]);

            // Update original payment notes
            $payment->update([
                'notes' => ($payment->notes ?? '')."\n\nPartial refund of {$amount}: {$reason}",
            ]);

            DB::afterCommit(function () use ($refund, $payment, $reason): void {
                event(new PaymentRefunded(
                    paymentId: $refund->id,
                    tenantId: $refund->tenant_id,
                    companyId: $refund->company_id,
                    originalPaymentId: $payment->id,
                    amount: $refund->amount,
                    currency: $refund->currency,
                    reason: $reason,
                    refundedAt: ($refund->created_at ?? now())->toIso8601String(),
                ));
            });

            return $refund;
        });
    }

    /**
     * Check if payment can be refunded
     */
    public function canRefund(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Completed;
    }

    /**
     * Get refund history for a payment.
     *
     * @return array<string, mixed>
     */
    public function getRefundHistory(Payment $payment): array
    {
        // Find all refund payments (negative amounts) for this payment
        $refunds = Payment::where('tenant_id', $payment->tenant_id)
            ->where('partner_id', $payment->partner_id)
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
     * Reverse a payment (for errors/corrections).
     *
     * This method is idempotent: calling it on an already-reversed payment
     * will return without error.
     */
    public function reversePayment(
        Payment $payment,
        string $reason,
        ?string $userId = null
    ): void {
        // Idempotent: if already reversed, just return
        if ($payment->status === PaymentStatus::Reversed) {
            return;
        }

        if (! in_array($payment->status, [PaymentStatus::Completed, PaymentStatus::Failed], true)) {
            throw new \RuntimeException('Only completed or failed payments can be reversed');
        }

        DB::transaction(function () use ($payment, $reason): void {
            // Double-check inside transaction (another request may have reversed it)
            $payment->refresh();
            if ($payment->status === PaymentStatus::Reversed) {
                return;
            }
            // Delete allocations
            PaymentAllocation::where('payment_id', $payment->id)->delete();

            // Update payment status
            $payment->update([
                'status' => PaymentStatus::Reversed,
                'notes' => ($payment->notes ?? '')."\n\nReversed: {$reason}",
            ]);

            DB::afterCommit(function () use ($payment): void {
                event(new PaymentReversed(
                    paymentId: $payment->id,
                    tenantId: $payment->tenant_id,
                    companyId: $payment->company_id,
                    amount: $payment->amount,
                    currency: $payment->currency,
                    reversedAt: now()->toIso8601String(),
                ));
            });
        });
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
        $originalPayments = Payment::where('company_id', $originalReceipt->company_id)
            ->where('payment_type', PaymentType::POS->value)
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
                    $refundRow = Payment::create([
                        'id' => Str::uuid()->toString(),
                        'tenant_id' => $original->tenant_id,
                        'company_id' => $original->company_id,
                        'partner_id' => $original->partner_id,
                        'payment_method_id' => $original->payment_method_id,
                        'instrument_id' => $original->instrument_id,
                        'repository_id' => $original->repository_id,
                        // Negative amount — refunds reduce the cash side
                        'amount' => $negativeAmount,
                        'currency' => $original->currency,
                        'payment_date' => now(),
                        'status' => PaymentStatus::Completed,
                        // IMPORTANT: always explicit — never rely on column default (Codex review 2 §4.3)
                        'payment_type' => PaymentType::Refund,
                        'origin' => $original->origin,
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
     * @throws \RuntimeException If refund cannot be found
     */
    private function findExistingFullRefund(Payment $payment): Payment
    {
        // Find refund by looking for a negative payment that references this payment
        /** @var Payment|null $refund */
        $refund = Payment::where('tenant_id', $payment->tenant_id)
            ->where('partner_id', $payment->partner_id)
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
