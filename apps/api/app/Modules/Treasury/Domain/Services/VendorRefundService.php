<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Support\Facades\DB;

final class VendorRefundService
{
    public function __construct(
        private readonly GeneralLedgerService $glService,
    ) {}

    /**
     * Refund a prepayment on a Purchase Order.
     *
     * Creates a refund payment with negative allocation, restores the PO's
     * balance_due, and creates the GL reversal entry if the repository
     * has an account_id.
     *
     * @param  numeric-string  $amount
     */
    public function refundPrepayment(
        Document $po,
        string $amount,
        string $paymentMethodId,
        string $repositoryId,
        ?string $reason,
        ?string $userId,
    ): Payment {
        if ($po->type !== DocumentType::PurchaseOrder) {
            throw new \DomainException('Prepayment refund is only available for Purchase Orders');
        }

        return DB::transaction(function () use ($po, $amount, $paymentMethodId, $repositoryId, $reason, $userId): Payment {
            // Lock PO first to serialize concurrent refunds
            /** @var Document $lockedPo */
            $lockedPo = Document::lockForUpdate()->findOrFail($po->id);

            if ($lockedPo->status !== DocumentStatus::Confirmed) {
                throw new \DomainException(
                    "Cannot refund prepayment on a PO with status '{$lockedPo->status->value}'"
                );
            }

            // Calculate total already allocated to this PO (inside transaction, serialized by lock)
            /** @var numeric-string $totalAllocated */
            $totalAllocated = (string) PaymentAllocation::query()
                ->where('document_id', $lockedPo->id)
                ->sum('amount');

            if (bccomp($amount, $totalAllocated, 2) > 0) {
                throw new \DomainException(
                    "Refund amount ({$amount}) exceeds total allocated ({$totalAllocated})"
                );
            }

            // Create refund payment record
            $payment = Payment::create([
                'tenant_id' => $lockedPo->tenant_id,
                'company_id' => $lockedPo->company_id,
                'partner_id' => $lockedPo->partner_id,
                'payment_method_id' => $paymentMethodId,
                'repository_id' => $repositoryId,
                'amount' => $amount,
                'currency' => $lockedPo->currency ?? 'EUR',
                'payment_date' => now(),
                'status' => PaymentStatus::Completed,
                'payment_type' => PaymentType::Refund,
                'reference' => "Refund for {$lockedPo->document_number}",
                'notes' => $reason,
                'created_by' => $userId,
            ]);

            // Create negative allocation to reverse part of the prepayment
            /** @var numeric-string $negativeAmount */
            $negativeAmount = bcmul($amount, '-1', 4);

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'document_id' => $lockedPo->id,
                'amount' => $negativeAmount,
            ]);

            // Update PO balance_due (add back the refunded amount) — PO already locked above
            /** @var numeric-string $currentBalance */
            $currentBalance = $lockedPo->balance_due ?? '0.00';
            $lockedPo->balance_due = bcadd($currentBalance, $amount, 2);
            // PO status stays confirmed — never transition to paid
            $lockedPo->save();

            // Update repository balance (money going out)
            /** @var PaymentRepository|null $repository */
            $repository = PaymentRepository::lockForUpdate()->find($repositoryId);
            if ($repository) {
                /** @var numeric-string $repoBalance */
                $repoBalance = $repository->balance ?? '0.00';
                $repository->balance = bcsub($repoBalance, $amount, 2);
                $repository->save();

                // Create GL reversal if repository has account_id
                if ($repository->account_id) {
                    $journalEntry = $this->glService->reverseSupplierAdvanceJournalEntry(
                        companyId: $lockedPo->company_id,
                        partnerId: $lockedPo->partner_id,
                        refundId: $payment->id,
                        amount: $amount,
                        paymentMethodAccountId: $repository->account_id,
                        date: now(),
                        description: "Supplier advance refund - {$lockedPo->document_number}".($reason ? " - {$reason}" : ''),
                    );

                    $payment->journal_entry_id = $journalEntry->id;
                    $payment->save();
                }
            }

            return $payment;
        });
    }
}
