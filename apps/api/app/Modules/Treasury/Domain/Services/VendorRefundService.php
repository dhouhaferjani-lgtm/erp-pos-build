<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Services;

use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Illuminate\Support\Facades\DB;

final class VendorRefundService
{
    public function __construct(
        private readonly GeneralLedgerService $glService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly TreasuryMovementServiceInterface $movementService,
        // C-0a0 — the writer census requires every production writer of
        // `payment_allocations` to pass the ONE applicability policy first.
        private readonly DocumentAllocationClassifier $allocationClassifier,
    ) {}

    /**
     * Refund a prepayment on a Purchase Order.
     *
     * Creates a refund payment with negative allocation, restores the PO's
     * balance_due, and creates the GL reversal entry if the repository
     * has a gl_account_id.
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

            $scale = $this->scaleResolver->getScale($lockedPo->currency ?? 'EUR');

            if (bccomp($amount, $totalAllocated, $scale) > 0) {
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

            // NOT locked here (Task 18): the movement port takes the repository
            // row lock inside record(), AFTER the GL post takes the company
            // advisory lock — the global lock order (advisory -> repo, BLOCKER-1).
            // Pre-locking the repo here would invert that order.
            /** @var PaymentRepository $resolvedRepository */
            $resolvedRepository = PaymentRepository::query()
                ->where('tenant_id', $lockedPo->tenant_id)
                ->where('company_id', $lockedPo->company_id)
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
                'location_id' => $lockedPo->location_id,
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

            // C-0a0 — the classifier seam for a REVERSAL. This is the exact case
            // that makes reversal admission total: C-0a0 REFUSES new money on a
            // purchase order (F-153 / LEDGER OQ-3, wrong-direction GL), and the
            // prepayments already sitting on POs must still be refundable, or the
            // fix would trap them. See
            // `DocumentAllocationClassifier::assertReversalAdmitted()`.
            $this->allocationClassifier->assertReversalAdmitted($lockedPo);

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'document_id' => $lockedPo->id,
                'amount' => $negativeAmount,
            ]);

            // Update PO balance_due (add back the refunded amount) — PO already locked above
            /** @var numeric-string $currentBalance */
            $currentBalance = $lockedPo->balance_due ?? '0.00';
            $lockedPo->balance_due = bcadd($currentBalance, $amount, $scale);
            // PO status stays confirmed — never transition to paid
            $lockedPo->save();

            // A cash movement is about to leave this repository. The spine §9.2
            // reconciliation invariant requires every cash movement to carry a
            // linked journal_entry_id. Refund is NOT exempt in
            // ReconcileTreasuryCommand::isJournalEntryExempt(), so a repository
            // with no gl_account_id has no GL account to post the reversal to —
            // refuse rather than record a null-JE movement that would freeze the
            // repo at the next `treasury:reconcile` (mirrors the guard in
            // PaymentRefundService::postRefundGlAndMovement).
            if ($resolvedRepository->gl_account_id === null) {
                throw new \DomainException(
                    "a refund cash movement requires a GL-linked repository; repository {$resolvedRepository->id} has no gl_account_id"
                );
            }

            // Post the GL reversal SYNCHRONOUSLY (in-transaction) FIRST — so its
            // company advisory lock is taken before the movement port's
            // repository row lock (BLOCKER-1). This REPLACES the old inline
            // `$resolvedRepository->balance = bcsub(...)` write; the movement
            // port below is now the single writer of the repository balance +
            // the append-only movement row. ALWAYS posted now that the guard
            // above has ruled out an unledgered repository — no null-JE refund
            // movement is reachable.
            $journalEntry = $this->glService->reverseSupplierAdvanceJournalEntry(
                companyId: $lockedPo->company_id,
                partnerId: $lockedPo->partner_id,
                refundId: $payment->id,
                amount: $amount,
                paymentMethodAccountId: $resolvedRepository->gl_account_id,
                date: now(),
                description: "Supplier advance refund - {$lockedPo->document_number}".($reason ? " - {$reason}" : ''),
                postedByUserId: $userId,
                currencyCode: $payment->currency,
                mode: PostingMode::SynchronousInTransaction,
            );

            $journalEntryId = $journalEntry->id;
            $payment->journal_entry_id = $journalEntry->id;
            $payment->save();

            // Move the cash OUT of the repository through the single write port
            // (money returned to the company from the supplier — the pre-migration
            // inline write DECREMENTED the balance, so the direction is Out). The
            // port takes the repository row lock, advances its gapless ordinal and
            // updates the cached balance atomically with the GL post above.
            // sourceType Refund; sourceId is the refund payment id (freshly minted
            // per call, so the idempotency key is naturally unique).
            $this->movementService->record(new MovementIntent(
                repositoryId: $resolvedRepository->id,
                tenantId: $lockedPo->tenant_id,
                companyId: $lockedPo->company_id,
                direction: MovementDirection::Out,
                amount: $amount,
                // Task 20 review Fix 2 (MINOR) pattern, aligned here: pass the
                // PAYMENT's own currency (the currency `$amount` is denominated
                // in — stamped on the Payment row above from $lockedPo->currency),
                // NOT the repository's cached currency. Passing the repo currency
                // makes the port's CurrencyMismatchException guard
                // (`$repo->currency !== $intent->currency`) trivially always-equal
                // and silently disarms it (see TreasuryReceiptBridge,
                // TreasuryAccountPaymentBridge). Currencies match today so
                // behavior is unchanged; the guard is restored.
                currency: $payment->currency,
                sourceType: MovementSourceType::Refund,
                sourceId: $payment->id,
                idempotencyLeg: 'main',
                journalEntryId: $journalEntryId,
                occurredAt: null,
                reasonCode: null,
                reversesMovementId: null,
                createdBy: $userId,
                notes: null,
                allowWhileFrozen: false,
            ));

            return $payment;
        });
    }
}
