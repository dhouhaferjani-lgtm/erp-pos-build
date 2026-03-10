<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MultiPaymentService
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Create split payment across multiple payment methods.
     *
     * @param  array<int, array{payment_method_id: string, amount: numeric-string, repository_id?: string|null, instrument_id?: string|null, reference?: string|null}>  $paymentSplits
     * @return array<int, Payment>
     */
    public function createSplitPayment(
        Document $document,
        array $paymentSplits,
        ?string $userId = null
    ): array {
        // Validate total matches document balance
        /** @var numeric-string $totalSplit */
        $totalSplit = '0.00';
        foreach ($paymentSplits as $split) {
            /** @var numeric-string $splitAmount */
            $splitAmount = $split['amount'];
            $totalSplit = bcadd($totalSplit, $splitAmount, $this->scale());
        }

        /** @var numeric-string $docBalance */
        $docBalance = $document->balance_due ?? $document->total;
        if (bccomp($totalSplit, $docBalance, $this->scale()) !== 0) {
            throw new \InvalidArgumentException(
                'Split payment total must equal document balance'
            );
        }

        if (count($paymentSplits) < 2) {
            throw new \InvalidArgumentException(
                'Split payment requires at least 2 payment methods'
            );
        }

        return DB::transaction(function () use ($document, $paymentSplits, $userId): array {
            $payments = [];

            foreach ($paymentSplits as $index => $split) {
                $payment = Payment::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $document->tenant_id,
                    'company_id' => $document->company_id,
                    'partner_id' => $document->partner_id,
                    'payment_method_id' => $split['payment_method_id'],
                    'instrument_id' => $split['instrument_id'] ?? null,
                    'repository_id' => $split['repository_id'] ?? null,
                    'amount' => $split['amount'],
                    'currency' => $document->currency,
                    'payment_date' => now(),
                    'status' => PaymentStatus::Completed,
                    'reference' => $split['reference'] ?? 'Split payment '.($index + 1)." for {$document->document_number}",
                    'notes' => "Split payment {$split['amount']} (part ".($index + 1).' of '.count($paymentSplits).')',
                    'created_by' => $userId,
                ]);

                // Create allocation
                PaymentAllocation::create([
                    'id' => Str::uuid()->toString(),
                    'payment_id' => $payment->id,
                    'document_id' => $document->id,
                    'amount' => $split['amount'],
                ]);

                $payments[] = $payment;
            }

            // Update document balance
            $document->update([
                'balance_due' => '0.00',
                'status' => $this->getDocumentStatusAfterPayment($document),
            ]);

            return $payments;
        });
    }

    /**
     * Record deposit/advance payment (not allocated to specific document)
     */
    public function recordDeposit(
        string $tenantId,
        string $companyId,
        string $partnerId,
        string $paymentMethodId,
        string $amount,
        string $currency,
        ?string $repositoryId = null,
        ?string $instrumentId = null,
        ?string $reference = null,
        ?string $notes = null,
        ?string $userId = null
    ): Payment {
        /** @var numeric-string $amount */
        if (bccomp($amount, '0', $this->scale()) <= 0) {
            throw new \InvalidArgumentException('Deposit amount must be greater than zero');
        }

        return DB::transaction(function () use (
            $tenantId,
            $companyId,
            $partnerId,
            $paymentMethodId,
            $amount,
            $currency,
            $repositoryId,
            $instrumentId,
            $reference,
            $notes,
            $userId
        ): Payment {
            return Payment::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'payment_method_id' => $paymentMethodId,
                'instrument_id' => $instrumentId,
                'repository_id' => $repositoryId,
                'amount' => $amount,
                'currency' => $currency,
                'payment_date' => now(),
                'status' => PaymentStatus::Completed,
                'reference' => $reference ?? 'Deposit payment',
                'notes' => ($notes ?? 'Advance payment/deposit').' [UNALLOCATED]',
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Apply deposit to document (allocate previously unallocated payment)
     */
    public function applyDepositToDocument(
        Payment $deposit,
        Document $document,
        string $amount
    ): PaymentAllocation {
        if ($deposit->status !== PaymentStatus::Completed) {
            throw new \RuntimeException('Only completed deposits can be applied');
        }

        // Check unallocated amount
        /** @var numeric-string $depositAmount */
        $depositAmount = $deposit->amount;
        /** @var numeric-string $allocatedTotal */
        $allocatedTotal = (string) $deposit->allocations()->sum('amount');
        $unallocated = bcsub($depositAmount, $allocatedTotal, $this->scale());

        /** @var numeric-string $amount */
        if (bccomp($amount, $unallocated, $this->scale()) > 0) {
            throw new \InvalidArgumentException(
                "Amount exceeds unallocated deposit balance ({$unallocated})"
            );
        }

        return DB::transaction(function () use ($deposit, $document, $amount): PaymentAllocation {
            $allocation = PaymentAllocation::create([
                'id' => Str::uuid()->toString(),
                'payment_id' => $deposit->id,
                'document_id' => $document->id,
                'amount' => $amount,
            ]);

            // Update document balance
            /** @var numeric-string $docBal */
            $docBal = $document->balance_due ?? $document->total;
            $newBalance = bcsub($docBal, $amount, $this->scale());
            $document->update([
                'balance_due' => $newBalance,
                'status' => bccomp($newBalance, '0', $this->scale()) === 0
                    ? $this->getDocumentStatusAfterPayment($document)
                    : $document->status,
            ]);

            return $allocation;
        });
    }

    /**
     * Get unallocated deposit balance for a partner
     */
    public function getUnallocatedDepositBalance(string $partnerId, string $currency): string
    {
        $payments = Payment::where('partner_id', $partnerId)
            ->where('currency', $currency)
            ->where('status', PaymentStatus::Completed)
            ->with('allocations')
            ->get();

        $totalUnallocated = '0.00';

        foreach ($payments as $payment) {
            /** @var numeric-string $paymentAmount */
            $paymentAmount = $payment->amount;
            /** @var numeric-string $allocatedAmount */
            $allocatedAmount = (string) $payment->allocations->sum('amount');
            $unallocated = bcsub($paymentAmount, $allocatedAmount, $this->scale());

            if (bccomp($unallocated, '0', $this->scale()) > 0) {
                $totalUnallocated = bcadd($totalUnallocated, $unallocated, $this->scale());
            }
        }

        return $totalUnallocated;
    }

    /**
     * Record payment on account (credit balance for partner).
     *
     * @return array{payment: Payment, account_balance: string}
     */
    public function recordPaymentOnAccount(
        string $tenantId,
        string $companyId,
        string $partnerId,
        string $amount,
        string $currency,
        ?string $reference = null,
        ?string $notes = null,
        ?string $userId = null
    ): array {
        /** @var numeric-string $amount */
        if (bccomp($amount, '0', $this->scale()) <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero');
        }

        $payment = DB::transaction(function () use (
            $tenantId,
            $companyId,
            $partnerId,
            $amount,
            $currency,
            $reference,
            $notes,
            $userId
        ): Payment {
            return Payment::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'payment_method_id' => null, // On account doesn't require payment method
                'amount' => $amount,
                'currency' => $currency,
                'payment_date' => now(),
                'status' => PaymentStatus::Completed,
                'reference' => $reference ?? 'Payment on account',
                'notes' => ($notes ?? 'Payment on account - credit balance').' [ON_ACCOUNT]',
                'created_by' => $userId,
            ]);
        });

        $accountBalance = $this->getUnallocatedDepositBalance($partnerId, $currency);

        return [
            'payment' => $payment,
            'account_balance' => $accountBalance,
        ];
    }

    /**
     * Get partner account balance (unallocated payments).
     *
     * @return array{partner_id: string, currency: string, unallocated_balance: string, deposit_count: int, deposits: \Illuminate\Database\Eloquent\Collection<int, Payment>}
     */
    public function getPartnerAccountBalance(string $partnerId, string $currency): array
    {
        $unallocatedBalance = $this->getUnallocatedDepositBalance($partnerId, $currency);

        $deposits = Payment::where('partner_id', $partnerId)
            ->where('currency', $currency)
            ->where('status', PaymentStatus::Completed)
            ->whereRaw('amount > (SELECT COALESCE(SUM(amount), 0) FROM payment_allocations WHERE payment_id = payments.id)')
            ->get();

        return [
            'partner_id' => $partnerId,
            'currency' => $currency,
            'unallocated_balance' => $unallocatedBalance,
            'deposit_count' => $deposits->count(),
            'deposits' => $deposits,
        ];
    }

    /**
     * Validate split payment amounts.
     *
     * @param  array<int, array{amount?: numeric-string}>  $splits
     */
    public function validateSplitAmounts(array $splits, string $totalRequired): bool
    {
        /** @var numeric-string $totalSplit */
        $totalSplit = '0.00';

        foreach ($splits as $split) {
            /** @var numeric-string $splitAmt */
            $splitAmt = $split['amount'] ?? '0';
            if (! isset($split['amount']) || bccomp($splitAmt, '0', $this->scale()) <= 0) {
                return false;
            }

            $totalSplit = bcadd($totalSplit, $splitAmt, $this->scale());
        }

        /** @var numeric-string $totalRequired */
        return bccomp($totalSplit, $totalRequired, $this->scale()) === 0;
    }

    /**
     * Get document status after full payment
     */
    private function getDocumentStatusAfterPayment(Document $document): \App\Modules\Document\Domain\Enums\DocumentStatus
    {
        return $document->type->canTransitionToPaid()
            ? \App\Modules\Document\Domain\Enums\DocumentStatus::Paid
            : $document->status;
    }

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }
}
