<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Services;

use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Events\PaymentRefunded;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
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
                    refundedAt: $refund->created_at->toIso8601String(),
                ));
            });

            return $refund;
        });
    }

    /**
     * Partially refund a payment
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
                    refundedAt: $refund->created_at->toIso8601String(),
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
        });
    }

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
}
