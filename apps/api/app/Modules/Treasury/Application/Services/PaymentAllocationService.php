<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DocumentFullyPaid;
use App\Modules\Document\Domain\Services\DocumentStatusService;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\ApplyPaymentAllocationCommand;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\AllocationTreatment;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Events\PaymentAllocated;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\Services\DocumentAllocationClassifier;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\PaymentToleranceCheckerContract;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PaymentAllocationService
{
    public function __construct(
        private readonly PaymentToleranceCheckerContract $toleranceChecker,
        private PaymentToleranceService $toleranceService,
        private GeneralLedgerService $glService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly CompanyContext $companyContext,
        private readonly DocumentAllocationStateGuard $allocationStateGuard,
        private readonly DocumentAllocationClassifier $allocationClassifier,
        private readonly DocumentStatusService $documentStatus,
    ) {}

    private function scale(?string $currencyCode = null): int
    {
        return $this->scaleResolver->getScale($currencyCode);
    }

    private function formatMoney(string $amount, ?string $currencyCode): string
    {
        /** @phpstan-ignore-next-line argument.type */
        return bcadd($amount, '0', $this->scale($currencyCode));
    }

    /**
     * Preview how a payment will be allocated
     *
     * @param  array<int, array{document_id: string, amount: string}>|null  $manualAllocations
     * @return array{
     *     allocations: array<int, array{document_id: string, document_number: string, amount: string, tolerance_writeoff: string|null}>,
     *     total_to_invoices: string,
     *     excess_amount: string,
     *     excess_handling: string|null
     * }
     */
    public function previewAllocation(
        string $companyId,
        string $partnerId,
        string $paymentAmount,
        AllocationMethod $allocationMethod,
        ?array $manualAllocations = null
    ): array {
        $tenantId = $this->companyContext->requireTenantId();

        return $this->previewAllocationForContext(
            tenantId: $tenantId,
            companyId: $companyId,
            partnerId: $partnerId,
            paymentAmount: $paymentAmount,
            allocationMethod: $allocationMethod,
            manualAllocations: $manualAllocations,
        );
    }

    /**
     * Preview how a payment will be allocated using an explicit tenant/company context.
     *
     * @param  array<int, array{document_id: string, amount: string}>|null  $manualAllocations
     * @return array{
     *     allocations: array<int, array{document_id: string, document_number: string, amount: string, tolerance_writeoff: string|null}>,
     *     total_to_invoices: string,
     *     excess_amount: string,
     *     excess_handling: string|null
     * }
     */
    private function previewAllocationForContext(
        string $tenantId,
        string $companyId,
        string $partnerId,
        string $paymentAmount,
        AllocationMethod $allocationMethod,
        ?array $manualAllocations = null,
    ): array {
        // Opus round-4 Finding 15 — keep BOTH tenant_id and company_id
        // predicates on every document read. Fiscal replay cannot rely on
        // request-scoped CompanyContext, so the command path passes the
        // context explicitly through this helper.
        $openInvoices = $this->getOpenInvoices($tenantId, $companyId, $partnerId, $allocationMethod);

        if ($allocationMethod === AllocationMethod::MANUAL && $manualAllocations !== null) {
            return $this->previewManualAllocation($paymentAmount, $manualAllocations, $tenantId, $companyId);
        }

        return $this->previewAutoAllocation($paymentAmount, $openInvoices, $tenantId, $companyId);
    }

    /**
     * Apply allocation for a payment
     *
     * @param  array<int, array{document_id: string, amount: string}>|null  $manualAllocations
     * @return array{success: bool, allocations: array<int, array{document_id: string, amount: string}>, journal_entry_id: string|null, advance_journal_entry_id: string|null, excess_amount: string}
     */
    public function applyAllocation(
        string $paymentId,
        AllocationMethod $allocationMethod,
        ?array $manualAllocations = null
    ): array {
        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        $actorUserId = Auth::id();

        return $this->applyAllocationFromCommand(new ApplyPaymentAllocationCommand(
            tenantId: $tenantId,
            companyId: $companyId,
            paymentId: $paymentId,
            allocationMethod: $allocationMethod,
            actorUserId: is_string($actorUserId) ? $actorUserId : null,
            source: 'web:smart_payment',
            manualAllocations: $manualAllocations,
        ));
    }

    /**
     * Apply allocation for a payment using an explicit replay-safe context.
     *
     * @return array{success: bool, allocations: array<int, array{document_id: string, amount: string}>, journal_entry_id: string|null, advance_journal_entry_id: string|null, excess_amount: string, total_allocated: string, fully_paid_documents: array<int, array{documentId: string, tenantId: string, companyId: string, documentNumber: string, documentType: string, partnerId: string, totalPaid: string, paidAt: string}>}
     */
    public function applyAllocationFromCommand(ApplyPaymentAllocationCommand $command): array
    {
        $payment = Payment::query()
            ->where('tenant_id', $command->tenantId)
            ->where('company_id', $command->companyId)
            ->with(['company', 'partner', 'repository'])
            ->findOrFail($command->paymentId);

        $preview = $this->previewAllocationForContext(
            tenantId: $command->tenantId,
            companyId: $command->companyId,
            partnerId: $payment->partner_id,
            paymentAmount: $payment->amount,
            allocationMethod: $command->allocationMethod,
            manualAllocations: $command->manualAllocations,
        );
        $actor = $this->resolveCommandActor($command);

        // Use DB transaction with pessimistic locking for financial operations
        $result = DB::transaction(function () use ($payment, $preview, $command, $actor) {
            $createdAllocations = [];
            $totalAllocated = '0.0000';
            // N-6 — the GL split is by POSTED-NESS, not by document type. These
            // two buckets replace the old `type === SalesOrder ? advance : Cr 411`
            // test, and they are accumulated HERE (in the same pass that locks
            // and classifies each document) rather than in a second, unlocked
            // read loop that could see a different status.
            /** @var numeric-string $allocatedToReceivable */
            $allocatedToReceivable = '0.00';
            /** @var numeric-string $allocatedToAdvance */
            $allocatedToAdvance = '0.00';
            /** @var list<string> $advanceAllocationIds */
            $advanceAllocationIds = [];
            /** @var array<int, array{documentId: string, tenantId: string, companyId: string, documentNumber: string, documentType: string, partnerId: string, totalPaid: string, paidAt: string}> $fullyPaidDocuments */
            $fullyPaidDocuments = [];

            foreach ($preview['allocations'] as $allocation) {
                // Lock the document for update to prevent concurrent modifications
                /** @var Document $document */
                $document = Document::query()
                    ->where('tenant_id', $command->tenantId)
                    ->where('company_id', $command->companyId)
                    ->lockForUpdate()
                    ->findOrFail($allocation['document_id']);

                // Supplier invoices (AP) are payable only through the supplier-aware
                // PaymentController::store() path (Dr 401 / Cr Bank). This generic
                // allocation path posts the AR direction (Dr Bank / Cr AR), so reject.
                $this->rejectSupplierInvoiceAllocation($document);
                // W-7 F-6: a withdrawn document must be un-allocatable here too —
                // this write path reaches its own `canTransitionToPaid()` status
                // flip a few lines below.
                $this->allocationStateGuard->assertAllocatable($document);

                // N-6 — decide the GL treatment from the LOCKED row's state.
                // A confirmed (unposted) invoice carries no receivable, so the
                // money is an ADVANCE (Cr 419), not a settlement; a draft,
                // cancelled or credit-note target is refused outright with 422
                // DOCUMENT_NOT_ALLOCATABLE.
                // C-0a0 — receivable side only: this path posts Dr bank / Cr
                // 411-or-419 and increments a repository, so a payable
                // settlement reaching it would move cash the wrong way.
                $treatment = $this->allocationClassifier->classifyReceivableSide($document);
                $isPrepayment = $treatment === AllocationTreatment::Prepayment;

                // Create allocation record
                $allocationRow = PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'document_id' => $allocation['document_id'],
                    'amount' => $allocation['amount'],
                    'tolerance_writeoff' => $allocation['tolerance_writeoff'] ?? null,
                    // The marker the posting path reads to know how much 419
                    // belongs to this document. It records what the LEDGER did.
                    'booked_as_advance' => $isPrepayment,
                ]);

                if ($isPrepayment) {
                    $advanceAllocationIds[] = $allocationRow->id;
                }

                $createdAllocations[] = [
                    'document_id' => $allocation['document_id'],
                    'amount' => $allocation['amount'],
                ];

                /** @var numeric-string $allocationAmount */
                $allocationAmount = $allocation['amount'];
                $totalAllocated = bcadd($totalAllocated, $allocationAmount, 4);

                if ($isPrepayment) {
                    $allocatedToAdvance = bcadd($allocatedToAdvance, $allocationAmount, $this->scale($payment->currency));
                } else {
                    $allocatedToReceivable = bcadd($allocatedToReceivable, $allocationAmount, $this->scale($payment->currency));
                }

                // Note: balance_due is automatically updated by PostgreSQL trigger
                // when PaymentAllocation is created. See migration: add_balance_due_cache_trigger.php

                // If there's a tolerance write-off, apply it
                /** @var numeric-string|null $toleranceWriteoff */
                $toleranceWriteoff = $allocation['tolerance_writeoff'] ?? null;
                if ($toleranceWriteoff !== null && bccomp($toleranceWriteoff, '0', 4) > 0) {
                    /** @var numeric-string $toleranceAmount */
                    $toleranceAmount = $toleranceWriteoff;
                    $toleranceType = bccomp($toleranceAmount, $allocationAmount, 4) > 0
                        ? 'overpayment'
                        : 'underpayment';

                    $this->toleranceService->applyTolerance(
                        companyId: $payment->company_id,
                        partnerId: $payment->partner_id,
                        documentId: $document->id,
                        amount: $toleranceAmount,
                        type: $toleranceType,
                        date: $payment->payment_date,
                        description: "Payment tolerance write-off for payment {$payment->reference}",
                        postedByUserId: $actor instanceof User ? $actor->id : null,
                        currencyCode: $payment->currency,
                    );
                }

                // Refresh document to get trigger-updated balance_due
                $document->refresh();

                // Update document status to Paid if fully paid (only for types that support it)
                // (balance_due was just updated by trigger)
                //
                // N-6 — `ReceivableClearing` is the new, load-bearing half of
                // this condition. A prepayment settles the invoice's BALANCE
                // (the cache still reflects the allocation: the invoice is paid
                // in advance) but moves no lifecycle status at all — posting is
                // still owed, and `confirmed → paid` is refused by
                // `DocumentStatusService` anyway. Flipping it here is what left
                // INV-2026-0003 unpostable forever.
                /** @var numeric-string $balanceDue */
                $balanceDue = $document->balance_due ?? '0.00';
                if (
                    bccomp($balanceDue, '0.00', $this->scale($payment->currency)) === 0
                    && $treatment === AllocationTreatment::ReceivableClearing
                    && $document->type->canTransitionToPaid()
                ) {
                    $this->documentStatus->markPaid($document);
                    $fullyPaidDocuments[] = [
                        'documentId' => $document->id,
                        'tenantId' => $payment->tenant_id,
                        'companyId' => $payment->company_id,
                        'documentNumber' => $document->document_number,
                        'documentType' => $document->type->value,
                        'partnerId' => $document->partner_id,
                        'totalPaid' => $document->total ?? '0.00',
                        'paidAt' => now()->toIso8601String(),
                    ];
                }
            }

            // Create GL journal entries when either the cash repository or a
            // maturity-leg portfolio override supplies the debit account.
            $journalEntryId = null;
            $debitAccountId = $command->cashAccountOverrideId ?? $payment->repository?->gl_account_id;
            if ($payment->repository && $debitAccountId !== null && bccomp($totalAllocated, '0', 4) > 0) {
                // N-6 — `$allocatedToReceivable` / `$allocatedToAdvance` were
                // accumulated in the locked loop above from the classifier's
                // verdict. The second, UNLOCKED read loop that used to live here
                // and re-tested `type === SalesOrder` is gone: it asked the wrong
                // question (type, not posted-ness) and asked it of a row nothing
                // held.
                /** @var numeric-string $allocatedToOrders */
                $allocatedToOrders = $allocatedToAdvance;
                /** @var numeric-string $allocatedToInvoices */
                $allocatedToInvoices = $allocatedToReceivable;

                // Create regular payment entry for receivable-clearing allocations
                if (bccomp($allocatedToInvoices, '0', $this->scale($payment->currency)) > 0) {
                    $journalEntry = $this->glService->createPaymentReceivedJournalEntry(
                        companyId: $payment->company_id,
                        partnerId: $payment->partner_id,
                        paymentId: $payment->id,
                        amount: $allocatedToInvoices,
                        paymentMethodAccountId: $debitAccountId,
                        date: $payment->payment_date,
                        description: "Customer payment - {$payment->reference}",
                        user: $actor instanceof User ? $actor : null,
                        currencyCode: $payment->currency,
                        mode: $command->cashAccountOverrideId !== null
                            ? PostingMode::SynchronousInTransaction
                            : PostingMode::AfterCommit,
                    );

                    $journalEntryId = $journalEntry->id;

                    // Link journal entry to payment
                    $payment->journal_entry_id = $journalEntryId;
                    $payment->save();
                }

                // Create advance entry for sales order allocations (prepayments).
                // NO actor gate (Task 24 Fix A): cash moved, so the GL consequence
                // must post even when the actor can't be resolved (an offline
                // ACCOUNT_PAYMENT whose cashier is not a company member) — otherwise
                // the bridge records a cash movement with a null journal_entry_id and
                // the treasury reconcile freezes the drawer. A null actor posts the
                // advance as a SYSTEM-generated entry.
                if (bccomp($allocatedToOrders, '0', $this->scale($payment->currency)) > 0) {
                    $advanceEntry = $this->glService->createCustomerAdvanceJournalEntry(
                        companyId: $payment->company_id,
                        partnerId: $payment->partner_id,
                        advanceId: $payment->id,
                        amount: $allocatedToOrders,
                        paymentMethodAccountId: $debitAccountId,
                        date: $payment->payment_date,
                        user: $actor instanceof User ? $actor : null,
                        description: "Prepayment (customer advance) - {$payment->reference}",
                        currencyCode: $payment->currency,
                        mode: $command->cashAccountOverrideId !== null
                            ? PostingMode::SynchronousInTransaction
                            : PostingMode::AfterCommit,
                    );

                    // N-6 — stamp the 419 entry onto every allocation row it
                    // booked, so the posting path can clear exactly this much
                    // and no more. Without the back-link the clearing amount
                    // would have to be re-derived from the journal, and
                    // `journal_entries(source_type, source_id)` carries no
                    // uniqueness to derive it from.
                    if ($advanceAllocationIds !== []) {
                        PaymentAllocation::query()
                            ->whereIn('id', $advanceAllocationIds)
                            ->update(['advance_journal_entry_id' => $advanceEntry->id]);
                    }

                    // If no invoice allocation, use this as main journal entry
                    if ($journalEntryId === null) {
                        $journalEntryId = $advanceEntry->id;
                        $payment->journal_entry_id = $journalEntryId;
                        $payment->save();
                    }
                }
            }

            // Handle excess amount as customer advance
            /** @var numeric-string $excessAmount */
            $excessAmount = $preview['excess_amount'];
            $advanceJournalEntryId = null;

            if (bccomp($excessAmount, '0', 4) > 0 && $payment->repository && $debitAccountId !== null) {
                // Create customer advance GL entry for excess (Dr. Bank, Cr. Customer
                // Advance). NO actor gate (Task 24 Fix A): cash moved, so the excess
                // must post its 419 customer-advance consequence even when the actor
                // can't be resolved (an offline ACCOUNT_PAYMENT whose cashier is not a
                // company member). A null actor posts it as a SYSTEM-generated entry —
                // otherwise the bridge records a cash movement carrying a null
                // journal_entry_id and the treasury reconcile freezes the drawer.
                $advanceEntry = $this->glService->createCustomerAdvanceJournalEntry(
                    companyId: $payment->company_id,
                    partnerId: $payment->partner_id,
                    advanceId: $payment->id,
                    amount: bcsub($excessAmount, '0', $this->scale($payment->currency)), // Format to currency scale
                    paymentMethodAccountId: $debitAccountId,
                    date: $payment->payment_date,
                    user: $actor instanceof User ? $actor : null,
                    description: "Customer advance from payment {$payment->reference}",
                    currencyCode: $payment->currency,
                    mode: $command->cashAccountOverrideId !== null
                        ? PostingMode::SynchronousInTransaction
                        : PostingMode::AfterCommit,
                );

                $advanceJournalEntryId = $advanceEntry->id;

                // Reconciliation-readiness (spec §9.2): when the advance/excess JE
                // is the payment's ONLY journal entry (a PURE advance — no invoice
                // or order allocation posted an entry above), link + persist it as
                // the payment's journal entry. The deposit/account bridges record
                // the cash movement with `payment->journal_entry_id`; without this
                // a plain customer deposit would record a null-JE cash movement even
                // though the 419 customer-advance JE exists — freezing the repo at
                // Wave-F reconcile.
                if ($journalEntryId === null && $payment->journal_entry_id === null) {
                    $payment->journal_entry_id = $advanceJournalEntryId;
                }

                // Update payment type if this is a pure advance (no allocations)
                if (bccomp($totalAllocated, '0', 4) === 0) {
                    $payment->payment_type = PaymentType::Advance;
                }

                $payment->save();
            }

            return [
                'success' => true,
                'allocations' => $createdAllocations,
                'journal_entry_id' => $journalEntryId,
                'advance_journal_entry_id' => $advanceJournalEntryId,
                'excess_amount' => $excessAmount,
                'total_allocated' => $totalAllocated,
                'fully_paid_documents' => $fullyPaidDocuments,
            ];
        });

        // Dispatch event after transaction completes
        event(new PaymentAllocated(
            paymentId: $payment->id,
            tenantId: $payment->tenant_id,
            companyId: $payment->company_id,
            allocationMethod: $command->allocationMethod->value,
            allocations: $result['allocations'],
            totalAllocated: $result['total_allocated'],
            excessAmount: $result['excess_amount'],
            allocatedAt: now()->toIso8601String(),
        ));

        // Dispatch DocumentFullyPaid for each document that transitioned to Paid
        foreach ($result['fully_paid_documents'] as $paidDoc) {
            event(new DocumentFullyPaid(
                documentId: $paidDoc['documentId'],
                tenantId: $paidDoc['tenantId'],
                companyId: $paidDoc['companyId'],
                documentNumber: $paidDoc['documentNumber'],
                documentType: $paidDoc['documentType'],
                partnerId: $paidDoc['partnerId'],
                totalPaid: $paidDoc['totalPaid'],
                paidAt: $paidDoc['paidAt'],
            ));
        }

        return $result;
    }

    private function resolveCommandActor(ApplyPaymentAllocationCommand $command): ?User
    {
        if ($command->actorUserId === null) {
            return null;
        }

        $user = User::query()
            ->where('tenant_id', $command->tenantId)
            ->where('id', $command->actorUserId)
            ->first();

        if ($user === null) {
            return null;
        }

        $hasActiveCompanyMembership = UserCompanyMembership::query()
            ->where('user_id', $user->id)
            ->where('company_id', $command->companyId)
            ->where('status', MembershipStatus::Active)
            ->exists();

        return $hasActiveCompanyMembership ? $user : null;
    }

    /**
     * Get open documents for a partner (invoices and sales orders)
     *
     * Opus round-4 Finding 15 — signature now requires tenantId and applies
     * BOTH tenant_id AND company_id predicates on the Document read. This
     * is the service-tier defense-in-depth pair to the controller fix in
     * SmartPaymentController::getOpenInvoices(); the cluster invariant
     * Codex established in round-3 Finding 14 demands both predicates on
     * every read whose anchor came from a route param.
     *
     * @return Collection<int, Document>
     */
    private function getOpenInvoices(string $tenantId, string $companyId, string $partnerId, AllocationMethod $method): Collection
    {
        $query = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            // N-6 — the allocatable set is the SQL mirror of
            // `DocumentAllocationClassifier`: posted invoices (receivable
            // clearing) plus confirmed invoices and confirmed sales orders
            // (prepayments, Cr 419). A confirmed invoice used to be invisible
            // here while the MANUAL path admitted it anyway — the two halves of
            // one surface disagreeing about what is payable.
            ->where(function ($q) {
                $q->where(function ($inner) {
                    // Posted invoices — receivable clearing (Cr 411)
                    $inner->where('type', DocumentType::Invoice)
                        ->where('status', DocumentStatus::Posted);
                })->orWhere(function ($inner) {
                    // Confirmed invoices — prepayment (Cr 419), no status flip
                    $inner->where('type', DocumentType::Invoice)
                        ->where('status', DocumentStatus::Confirmed);
                })->orWhere(function ($inner) {
                    // Confirmed sales orders — prepayment (Cr 419)
                    $inner->where('type', DocumentType::SalesOrder)
                        ->where('status', DocumentStatus::Confirmed);
                });
            })
            ->whereRaw('total > COALESCE((SELECT SUM(amount) FROM payment_allocations WHERE document_id = documents.id), 0)');

        // Apply sorting based on allocation method
        if ($method === AllocationMethod::FIFO) {
            $query->orderBy('document_date', 'asc')
                ->orderBy('document_number', 'asc');
        } elseif ($method === AllocationMethod::DUE_DATE_PRIORITY) {
            $query->orderBy('due_date', 'asc')
                ->orderBy('document_date', 'asc');
        }

        return $query->get();
    }

    /**
     * Preview automatic allocation (FIFO or Due Date)
     *
     * @param  Collection<int, Document>  $openInvoices
     * @return array{
     *     allocations: array<int, array{document_id: string, document_number: string, amount: string, tolerance_writeoff: string|null}>,
     *     total_to_invoices: string,
     *     excess_amount: string,
     *     excess_handling: string|null
     * }
     */
    private function previewAutoAllocation(string $paymentAmount, Collection $openInvoices, string $tenantId, string $companyId): array
    {
        $remainingAmount = $paymentAmount;
        $allocations = [];
        $totalToInvoices = '0.0000';

        // PaymentToleranceCheckerContract is country/currency-keyed: resolve country
        // once from the owning company; currency is per-invoice (accurate for
        // multi-currency tenants). strict=false rides the inclusive A1 / SmartPayment
        // semantics — a difference exactly at the threshold qualifies for write-off.
        /** @var Company $company */
        $company = Company::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($companyId);
        $countryCode = (string) $company->country_code;
        $companyCurrency = (string) $company->currency;

        foreach ($openInvoices as $invoice) {
            /** @phpstan-ignore-next-line argument.type */
            if (bccomp($remainingAmount, '0', 4) <= 0) {
                break;
            }

            // Calculate invoice balance
            $invoiceBalance = $this->getInvoiceBalance($invoice);

            // Compute the unsigned shortfall (contract input is unsigned by spec)
            // alongside the signed delta we still need to choose the allocation
            // direction (overpayment caps at balance, underpayment consumes remainder).
            /** @phpstan-ignore-next-line argument.type */
            $signedDelta = bcsub($remainingAmount, $invoiceBalance, 4);
            $absShortfall = bccomp($signedDelta, '0', 4) < 0
                ? bcmul($signedDelta, '-1', 4)
                : $signedDelta;

            // Check if this is the last invoice and we can apply tolerance
            $toleranceCheck = $this->toleranceChecker->check(
                shortfall: $absShortfall,
                invoiceTotal: $invoiceBalance,
                currencyCode: (string) $invoice->currency,
                countryCode: $countryCode,
                strict: false,
            );

            if ($toleranceCheck->qualifies) {
                // For overpayment: allocate invoice balance (can't exceed)
                // For underpayment: allocate all remaining payment
                /** @phpstan-ignore-next-line argument.type */
                $isOverpayment = bccomp($remainingAmount, $invoiceBalance, 4) > 0;
                $allocationAmount = $isOverpayment ? $invoiceBalance : $remainingAmount;

                $allocations[] = [
                    'document_id' => $invoice->id,
                    'document_number' => $invoice->document_number,
                    'amount' => $this->formatMoney($allocationAmount, (string) $invoice->currency),
                    'original_balance' => $this->formatMoney($invoiceBalance, (string) $invoice->currency),
                    'tolerance_writeoff' => $toleranceCheck->difference,
                ];

                // total_to_invoices = amount + tolerance (invoice value cleared)
                /** @phpstan-ignore-next-line argument.type */
                $totalToInvoices = bcadd($totalToInvoices, bcadd($allocationAmount, $toleranceCheck->difference, 4), 4);
                $remainingAmount = '0.0000';
                break;
            }

            // Allocate up to the invoice balance
            /** @phpstan-ignore-next-line argument.type */
            $allocationAmount = bccomp($remainingAmount, $invoiceBalance, 4) >= 0
                ? $invoiceBalance
                : $remainingAmount;

            $allocations[] = [
                'document_id' => $invoice->id,
                'document_number' => $invoice->document_number,
                'amount' => $this->formatMoney($allocationAmount, (string) $invoice->currency),
                'original_balance' => $this->formatMoney($invoiceBalance, (string) $invoice->currency),
                'tolerance_writeoff' => null,
            ];

            /** @phpstan-ignore-next-line argument.type */
            $totalToInvoices = bcadd($totalToInvoices, $allocationAmount, 4);
            /** @phpstan-ignore-next-line argument.type */
            $remainingAmount = bcsub($remainingAmount, $allocationAmount, 4);
        }

        // Determine excess handling
        $excessHandling = null;
        /** @phpstan-ignore-next-line argument.type */
        if (bccomp($remainingAmount, '0', 4) > 0) {
            // Check if any allocation has tolerance
            $hasTolerance = collect($allocations)->contains(fn ($a) => $a['tolerance_writeoff'] !== null);
            $excessHandling = $hasTolerance ? 'tolerance_writeoff' : 'credit_balance';
        } elseif (collect($allocations)->contains(fn ($a) => $a['tolerance_writeoff'] !== null)) {
            $excessHandling = 'tolerance_writeoff';
        }

        return [
            'allocations' => $allocations,
            'total_to_invoices' => $this->formatMoney($totalToInvoices, $companyCurrency),
            'excess_amount' => $this->formatMoney($remainingAmount, $companyCurrency),
            'excess_handling' => $excessHandling,
        ];
    }

    /**
     * Reject allocating a payment to a supplier_invoice via the generic allocation
     * path. Supplier invoices (AP) must be paid through the supplier-aware
     * PaymentController::store() flow, which posts the 401-clearing entry and
     * reduces payable_balance. Throws a 422 HttpResponseException.
     */
    private function rejectSupplierInvoiceAllocation(Document $document): void
    {
        if ($document->type === DocumentType::SupplierInvoice) {
            throw new HttpResponseException(response()->json([
                'error' => [
                    'code' => 'SUPPLIER_INVOICE_NOT_PAYABLE_HERE',
                    'message' => 'Supplier invoices must be paid through the supplier payment flow, not document allocation',
                    'details' => [
                        'document_id' => $document->id,
                    ],
                ],
            ], 422));
        }
    }

    /**
     * Preview manual allocation
     *
     * @param  array<int, array{document_id: string, amount: string}>  $manualAllocations
     * @return array{
     *     allocations: array<int, array{document_id: string, document_number: string, amount: string, tolerance_writeoff: string|null}>,
     *     total_to_invoices: string,
     *     excess_amount: string,
     *     excess_handling: string|null
     * }
     */
    private function previewManualAllocation(string $paymentAmount, array $manualAllocations, string $tenantId, string $companyId): array
    {
        $allocations = [];
        $totalAllocated = '0.0000';
        /** @var Company $company */
        $company = Company::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($companyId);
        $companyCurrency = (string) $company->currency;

        foreach ($manualAllocations as $manual) {
            $invoice = Document::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->findOrFail($manual['document_id']);

            // Supplier invoices (AP) are not allocable here — reject in the preview
            // so the manual smart-payment path fails fast before any write.
            $this->rejectSupplierInvoiceAllocation($invoice);
            // W-7 F-6: fail the READ side too, so the smart-payment preview never
            // offers a withdrawn document as payable in the first place.
            $this->allocationStateGuard->assertAllocatable($invoice);
            // N-6 — the MANUAL path used to admit anything that was not
            // withdrawn: a DRAFT invoice, a quote, a credit note. Classify it
            // here so the preview refuses with 422 DOCUMENT_NOT_ALLOCATABLE
            // before any write, and so preview and execute agree on the same
            // set (the auto path's SQL mirror of this rule is in
            // `getOpenInvoices()`).
            $this->allocationClassifier->classifyReceivableSide($invoice);

            $invoiceBalance = $this->getInvoiceBalance($invoice);

            $allocations[] = [
                'document_id' => $invoice->id,
                'document_number' => $invoice->document_number,
                'amount' => $this->formatMoney($manual['amount'], (string) $invoice->currency),
                'original_balance' => $this->formatMoney($invoiceBalance, (string) $invoice->currency),
                'tolerance_writeoff' => null,
            ];

            /** @phpstan-ignore-next-line argument.type */
            $totalAllocated = bcadd($totalAllocated, $manual['amount'], 4);
        }

        /** @phpstan-ignore-next-line argument.type */
        $excessAmount = bcsub($paymentAmount, $totalAllocated, 4);

        return [
            'allocations' => $allocations,
            'total_to_invoices' => $this->formatMoney($totalAllocated, $companyCurrency),
            'excess_amount' => $this->formatMoney($excessAmount, $companyCurrency),
            'excess_handling' => bccomp($excessAmount, '0', 4) > 0 ? 'credit_balance' : null,
        ];
    }

    /**
     * Get the remaining balance for an invoice
     */
    private function getInvoiceBalance(Document $invoice): string
    {
        $total = $invoice->total;

        /** @var numeric-string $totalAllocated */
        $totalAllocated = $invoice->allocations()->sum('amount');

        /** @phpstan-ignore-next-line argument.type */
        return bcsub($total, (string) $totalAllocated, 4);
    }
}
