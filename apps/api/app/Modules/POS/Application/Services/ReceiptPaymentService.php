<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Events\ReceiptCompleted;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service for processing receipt payments with Treasury and GL integration.
 *
 * Orchestrates the creation of:
 * 1. Treasury Payment records
 * 2. Receipt Payment records (linked to Treasury)
 * 3. General Ledger entries
 *
 * CRITICAL: POS payments are DIRECT TO REVENUE (no AR account).
 */
final class ReceiptPaymentService
{
    private const SCALE = 3;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly GeneralLedgerService $generalLedgerService,
    ) {}

    /**
     * Process split payments for a receipt.
     *
     * @param  array<int, array{payment_method_id: string, amount: numeric-string, repository_id: string, card_last_four?: string|null, transaction_reference?: string|null, authorization_code?: string|null}>  $payments
     * @return array{receipt: Receipt, receipt_payments: array<int, ReceiptPayment>, treasury_payments: array<int, Payment>, change_due: numeric-string}
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function processReceiptPayments(
        string $receiptId,
        array $payments,
        ?string $customerId = null
    ): array {
        if (count($payments) === 0) {
            throw new \InvalidArgumentException('At least one payment method is required');
        }

        return DB::transaction(function () use ($receiptId, $payments, $customerId): array {
            $receipt = Receipt::with(['lines', 'vatDetails'])->findOrFail($receiptId);
            $companyId = $this->companyContext->requireCompanyId();

            // Validate receipt not already paid
            if ($receipt->payments()->exists()) {
                throw new \RuntimeException('Receipt has already been paid');
            }

            // Calculate total paid
            $totalPaid = '0.000';
            foreach ($payments as &$payment) {
                $payment['amount'] = CurrencyScale::bcformat($payment['amount'], self::SCALE);
                if (bccomp($payment['amount'], '0', self::SCALE) <= 0) {
                    throw new \InvalidArgumentException('Payment amount must be greater than zero');
                }
                $totalPaid = bcadd($totalPaid, $payment['amount'], self::SCALE);
            }
            unset($payment);

            // Validate minimum payment
            if (bccomp($totalPaid, $receipt->total, self::SCALE) < 0) {
                throw new \InvalidArgumentException(
                    "Total paid ({$totalPaid}) is less than receipt total ({$receipt->total})"
                );
            }

            // Calculate change if overpayment
            $changeDue = bcsub($totalPaid, $receipt->total, self::SCALE);

            // Process each payment method
            $receiptPayments = [];
            $treasuryPayments = [];

            foreach ($payments as $index => $paymentData) {
                // Get payment repository
                $repository = PaymentRepository::findOrFail($paymentData['repository_id']);

                if ($repository->gl_account_id === null) {
                    throw new \RuntimeException(
                        "Cannot process payment: the cash register/bank account '{$repository->name}' ({$repository->code}) "
                        .'is not linked to a General Ledger account. Every payment repository must be linked to a GL account '
                        .'(e.g., Cash account for cash registers, Bank account for bank accounts) to record transactions. '
                        .'Go to Settings → Treasury → Payment Repositories and assign a GL account to this repository.'
                    );
                }

                // Get payment method name for receipt payment record
                $paymentMethod = PaymentMethod::findOrFail($paymentData['payment_method_id']);

                // Create Treasury Payment record
                $treasuryPayment = Payment::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $receipt->tenant_id,
                    'company_id' => $companyId,
                    'partner_id' => $customerId,
                    'payment_method_id' => $paymentData['payment_method_id'],
                    'repository_id' => $paymentData['repository_id'],
                    'amount' => $paymentData['amount'],
                    'currency' => $receipt->currency,
                    'payment_date' => $receipt->posted_at,
                    'status' => PaymentStatus::Completed,
                    'payment_type' => PaymentType::POS,
                    'reference' => "POS Receipt {$receipt->receipt_number} - Payment ".($index + 1),
                    'notes' => 'POS payment ('.($index + 1).' of '.count($payments).')',
                ]);

                // Create GL entry for this payment
                $journalEntry = $this->generalLedgerService->createPOSPaymentEntry(
                    payment: $treasuryPayment,
                    receipt: $receipt,
                    repository: $repository
                );

                // Link journal entry to payment
                $treasuryPayment->update(['journal_entry_id' => $journalEntry->id]);

                // Post the journal entry immediately (no draft state for POS)
                $this->generalLedgerService->postEntry(
                    $journalEntry,
                    $receipt->cashier
                );

                // Create ReceiptPayment record linked to Treasury payment
                $receiptPayment = ReceiptPayment::create([
                    'id' => Str::uuid()->toString(),
                    'receipt_id' => $receipt->id,
                    'payment_method_id' => $paymentData['payment_method_id'],
                    'payment_type' => $paymentMethod->name,
                    'amount' => $paymentData['amount'],
                    'card_last_four' => $paymentData['card_last_four'] ?? null,
                    'transaction_reference' => $paymentData['transaction_reference'] ?? null,
                    'authorization_code' => $paymentData['authorization_code'] ?? null,
                    'treasury_payment_id' => $treasuryPayment->id,
                ]);

                $receiptPayments[] = $receiptPayment;
                $treasuryPayments[] = $treasuryPayment;
            }

            // Mark receipt as paid (if exists such field)
            // Note: Receipt model doesn't have is_paid field currently, but payments relation exists

            /** @var Receipt $freshReceipt */
            $freshReceipt = $receipt->fresh(['lines', 'vatDetails', 'payments']);

            // Dispatch event for cross-module listeners (loyalty, analytics)
            DB::afterCommit(function () use ($receipt, $companyId, $customerId): void {
                event(new ReceiptCompleted(
                    receiptId: $receipt->id,
                    tenantId: $receipt->tenant_id,
                    companyId: $companyId,
                    customerId: $customerId,
                    totalAmount: $receipt->total,
                    currency: $receipt->currency,
                ));
            });

            return [
                'receipt' => $freshReceipt,
                'receipt_payments' => $receiptPayments,
                'treasury_payments' => $treasuryPayments,
                'change_due' => $changeDue,
            ];
        });
    }

    /**
     * Validate payment amounts match receipt total (allowing overpayment).
     *
     * @param  array<int, array{amount: numeric-string}>  $payments
     * @param  numeric-string  $receiptTotal
     */
    public function validatePaymentAmounts(array $payments, string $receiptTotal): bool
    {
        $totalPaid = '0.000';

        foreach ($payments as $payment) {
            if (bccomp($payment['amount'], '0', self::SCALE) <= 0) {
                return false;
            }

            $totalPaid = bcadd($totalPaid, $payment['amount'], self::SCALE);
        }

        // Must be at least equal to receipt total
        return bccomp($totalPaid, $receiptTotal, self::SCALE) >= 0;
    }

    /**
     * Calculate change due for overpayment.
     *
     * @param  numeric-string  $totalPaid
     * @param  numeric-string  $receiptTotal
     * @return numeric-string
     */
    public function calculateChange(string $totalPaid, string $receiptTotal): string
    {
        $change = bcsub($totalPaid, $receiptTotal, self::SCALE);

        return bccomp($change, '0', self::SCALE) > 0 ? $change : '0.000';
    }
}
