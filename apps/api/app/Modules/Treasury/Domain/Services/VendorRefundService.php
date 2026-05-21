<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
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
            // Lock PO first to serialize concurrent refunds. Tenant/company
            // scoping defends against caller-supplied $po pointing at a row
            // that was leaked into the closure from another tenant context.
            /** @var Document $lockedPo */
            $lockedPo = Document::query()
                ->where('tenant_id', $po->tenant_id)
                ->where('company_id', $po->company_id)
                ->lockForUpdate()
                ->findOrFail($po->id);

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

            // Codex round-2 Treasury Finding 12 (2026-05-04): resolve the
            // caller-supplied payment_method_id and repository_id under the
            // locked PO's tenant_id/company_id BEFORE Payment::create.
            // Without these guards, tenant-A's payments.repository_id /
            // payment_method_id columns end up pointing at tenant-B rows
            // even though the balance update + GL reversal correctly skip —
            // a real cross-tenant data binding leak (Payment::repository()
            // and ::paymentMethod() are unscoped belongsTo relations, so a
            // foreign id resolves cross-tenant on read). Cross-tenant ids
            // throw ModelNotFoundException, aborting the transaction.
            /** @var PaymentMethod $resolvedPaymentMethod */
            $resolvedPaymentMethod = PaymentMethod::query()
                ->where('tenant_id', $lockedPo->tenant_id)
                ->where('company_id', $lockedPo->company_id)
                ->findOrFail($paymentMethodId);

            /** @var PaymentRepository $resolvedRepository */
            $resolvedRepository = PaymentRepository::query()
                ->where('tenant_id', $lockedPo->tenant_id)
                ->where('company_id', $lockedPo->company_id)
                ->lockForUpdate()
                ->findOrFail($repositoryId);

            // Create refund payment record
            //
            // Spec §13 writer-inventory row 10 — `VendorRefundService::refundPrepayment()`
            // → `web_admin`. Supplier-advance refunds are authored from the web admin
            // (the procurement / finance side); they are NOT a refund of a customer-
            // facing payment, so the "inherit origin" rule does NOT apply here.
            $payment = Payment::create([
                'tenant_id' => $lockedPo->tenant_id,
                'company_id' => $lockedPo->company_id,
                'partner_id' => $lockedPo->partner_id,
                'payment_method_id' => $resolvedPaymentMethod->id,
                'repository_id' => $resolvedRepository->id,
                'amount' => $amount,
                'currency' => $lockedPo->currency ?? 'EUR',
                'payment_date' => now(),
                'status' => PaymentStatus::Completed,
                'payment_type' => PaymentType::Refund,
                'origin' => PaymentOrigin::WebAdmin,
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

            // Update repository balance (money going out). The repository
            // was already resolved + locked above under the PO's tenant/
            // company scope (Codex round-2 Finding 12), so we can update
            // directly without a second query. Cross-tenant repositories
            // never reach this point — they throw ModelNotFoundException
            // before Payment::create.
            /** @var numeric-string $repoBalance */
            $repoBalance = $resolvedRepository->balance ?? '0.00';
            $resolvedRepository->balance = bcsub($repoBalance, $amount, 2);
            $resolvedRepository->save();

            // Create GL reversal if repository has account_id
            if ($resolvedRepository->account_id) {
                $journalEntry = $this->glService->reverseSupplierAdvanceJournalEntry(
                    companyId: $lockedPo->company_id,
                    partnerId: $lockedPo->partner_id,
                    refundId: $payment->id,
                    amount: $amount,
                    paymentMethodAccountId: $resolvedRepository->account_id,
                    date: now(),
                    description: "Supplier advance refund - {$lockedPo->document_number}".($reason ? " - {$reason}" : ''),
                );

                $payment->journal_entry_id = $journalEntry->id;
                $payment->save();
            }

            return $payment;
        });
    }
}
