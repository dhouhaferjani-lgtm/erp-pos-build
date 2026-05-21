<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DocumentFullyPaid;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\ApplyPaymentAllocationCommand;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Events\PaymentAllocated;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\PaymentToleranceCheckerContract;
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
    ) {}

    private function scale(?string $currencyCode = null): int
    {
        return $this->scaleResolver->getScale($currencyCode);
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

                // Create allocation record
                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'document_id' => $allocation['document_id'],
                    'amount' => $allocation['amount'],
                    'tolerance_writeoff' => $allocation['tolerance_writeoff'] ?? null,
                ]);

                $createdAllocations[] = [
                    'document_id' => $allocation['document_id'],
                    'amount' => $allocation['amount'],
                ];

                /** @var numeric-string $allocationAmount */
                $allocationAmount = $allocation['amount'];
                $totalAllocated = bcadd($totalAllocated, $allocationAmount, 4);

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
                        description: "Payment tolerance write-off for payment {$payment->reference}"
                    );
                }

                // Refresh document to get trigger-updated balance_due
                $document->refresh();

                // Update document status to Paid if fully paid (only for types that support it)
                // (balance_due was just updated by trigger)
                /** @var numeric-string $balanceDue */
                $balanceDue = $document->balance_due ?? '0.00';
                if (bccomp($balanceDue, '0.00', $this->scale($payment->currency)) === 0 && $document->type->canTransitionToPaid()) {
                    $document->status = DocumentStatus::Paid;
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

                $document->save();
            }

            // Create GL journal entry for the payment if repository has account_id
            $journalEntryId = null;
            if ($payment->repository && $payment->repository->account_id && bccomp($totalAllocated, '0', 4) > 0) {
                // Check if any allocations are to sales orders (prepayments)
                /** @var numeric-string $allocatedToOrders */
                $allocatedToOrders = '0.00';
                /** @var numeric-string $allocatedToInvoices */
                $allocatedToInvoices = '0.00';

                foreach ($preview['allocations'] as $allocation) {
                    /** @var Document $doc */
                    $doc = Document::query()
                        ->where('tenant_id', $command->tenantId)
                        ->where('company_id', $command->companyId)
                        ->find($allocation['document_id']);
                    /** @var numeric-string $allocAmount */
                    $allocAmount = $allocation['amount'];
                    if ($doc->type === DocumentType::SalesOrder) {
                        $allocatedToOrders = bcadd($allocatedToOrders, $allocAmount, $this->scale($payment->currency));
                    } else {
                        $allocatedToInvoices = bcadd($allocatedToInvoices, $allocAmount, $this->scale($payment->currency));
                    }
                }

                // Create regular payment entry for invoice allocations
                if (bccomp($allocatedToInvoices, '0', $this->scale($payment->currency)) > 0) {
                    $journalEntry = $this->glService->createPaymentReceivedJournalEntry(
                        companyId: $payment->company_id,
                        partnerId: $payment->partner_id,
                        paymentId: $payment->id,
                        amount: $allocatedToInvoices,
                        paymentMethodAccountId: $payment->repository->account_id,
                        date: $payment->payment_date,
                        description: "Customer payment - {$payment->reference}"
                    );

                    $journalEntryId = $journalEntry->id;

                    // Link journal entry to payment
                    $payment->journal_entry_id = $journalEntryId;
                    $payment->save();
                }

                // Create advance entry for sales order allocations (prepayments)
                if (bccomp($allocatedToOrders, '0', $this->scale($payment->currency)) > 0 && $actor instanceof User) {
                    $advanceEntry = $this->glService->createCustomerAdvanceJournalEntry(
                        companyId: $payment->company_id,
                        partnerId: $payment->partner_id,
                        advanceId: $payment->id,
                        amount: $allocatedToOrders,
                        paymentMethodAccountId: $payment->repository->account_id,
                        date: $payment->payment_date,
                        user: $actor,
                        description: "Prepayment on order - {$payment->reference}"
                    );

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

            if (bccomp($excessAmount, '0', 4) > 0 && $payment->repository && $payment->repository->account_id) {
                if ($actor instanceof User) {
                    // Create customer advance GL entry for excess (Dr. Bank, Cr. Customer Advance)
                    $advanceEntry = $this->glService->createCustomerAdvanceJournalEntry(
                        companyId: $payment->company_id,
                        partnerId: $payment->partner_id,
                        advanceId: $payment->id,
                        amount: bcsub($excessAmount, '0', $this->scale($payment->currency)), // Format to currency scale
                        paymentMethodAccountId: $payment->repository->account_id,
                        date: $payment->payment_date,
                        user: $actor,
                        description: "Customer advance from payment {$payment->reference}"
                    );

                    $advanceJournalEntryId = $advanceEntry->id;

                    // Update payment type if this is a pure advance (no allocations)
                    if (bccomp($totalAllocated, '0', 4) === 0) {
                        $payment->payment_type = PaymentType::Advance;
                        $payment->save();
                    }
                }
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

        return User::query()
            ->where('tenant_id', $command->tenantId)
            ->where('id', $command->actorUserId)
            ->first();
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
            // Allow both posted invoices AND confirmed sales orders
            ->where(function ($q) {
                $q->where(function ($inner) {
                    // Posted invoices
                    $inner->where('type', DocumentType::Invoice)
                        ->where('status', 'posted');
                })->orWhere(function ($inner) {
                    // Confirmed sales orders (for prepayments)
                    $inner->where('type', DocumentType::SalesOrder)
                        ->where('status', 'confirmed');
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
                    'amount' => $allocationAmount,
                    'original_balance' => $invoiceBalance,
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
                'amount' => $allocationAmount,
                'original_balance' => $invoiceBalance,
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
            'total_to_invoices' => $totalToInvoices,
            'excess_amount' => $remainingAmount,
            'excess_handling' => $excessHandling,
        ];
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

        foreach ($manualAllocations as $manual) {
            $invoice = Document::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->findOrFail($manual['document_id']);
            $invoiceBalance = $this->getInvoiceBalance($invoice);

            $allocations[] = [
                'document_id' => $invoice->id,
                'document_number' => $invoice->document_number,
                'amount' => $manual['amount'],
                'original_balance' => $invoiceBalance,
                'tolerance_writeoff' => null,
            ];

            /** @phpstan-ignore-next-line argument.type */
            $totalAllocated = bcadd($totalAllocated, $manual['amount'], 4);
        }

        /** @phpstan-ignore-next-line argument.type */
        $excessAmount = bcsub($paymentAmount, $totalAllocated, 4);

        return [
            'allocations' => $allocations,
            'total_to_invoices' => $totalAllocated,
            'excess_amount' => $excessAmount,
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
