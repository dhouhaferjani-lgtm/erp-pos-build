<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Events\DocumentFullyPaid;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Application\Services\WithholdingCertificateService;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Events\PaymentRecorded;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly GeneralLedgerService $glService,
        private readonly PaymentAllocationService $allocationService,
        private readonly WithholdingCertificateService $withholdingService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $query = Payment::query()
            ->where('tenant_id', $tenantId)
            ->with(['partner', 'paymentMethod', 'allocations.document']);

        // Filter by partner
        if ($request->has('partner_id')) {
            $query->where('partner_id', $request->input('partner_id'));
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $payments = $query->orderByDesc('payment_date')->get();

        return response()->json([
            'data' => $payments->map(fn (Payment $payment) => $this->formatPayment($payment)),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $payment = Payment::query()
            ->where('tenant_id', $tenantId)
            ->with(['partner', 'paymentMethod', 'allocations.document'])
            ->findOrFail($id);

        return response()->json([
            'data' => $this->formatPayment($payment),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // Check if this is a multi-payment request
        if ($request->has('payments')) {
            return $this->storeMultiple($request, $user, $tenantId, $companyId);
        }

        $validated = $request->validate([
            'partner_id' => ['required', 'uuid', 'exists:partners,id'],
            'payment_method_id' => ['required', 'uuid', 'exists:payment_methods,id'],
            'instrument_id' => ['nullable', 'uuid', 'exists:payment_instruments,id'],
            'repository_id' => ['nullable', 'uuid', 'exists:payment_repositories,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.document_id' => ['required_with:allocations', 'uuid', 'exists:documents,id'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'min:0.01'],
            'withholding_enabled' => ['nullable', 'boolean'],
            'withholding_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'withholding_override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var numeric-string $paymentAmount */
        $paymentAmount = (string) $validated['amount'];

        // Validate allocations don't exceed payment amount
        $allocations = $validated['allocations'] ?? [];
        /** @var numeric-string $totalAllocated */
        $totalAllocated = '0.00';
        foreach ($allocations as $allocation) {
            /** @var numeric-string $allocationAmt */
            $allocationAmt = (string) $allocation['amount'];
            $totalAllocated = bcadd($totalAllocated, $allocationAmt, 2);
        }

        if (bccomp($totalAllocated, $paymentAmount, 2) > 0) {
            return response()->json([
                'error' => [
                    'code' => 'ALLOCATION_EXCEEDS_PAYMENT',
                    'message' => 'Total allocation amount exceeds payment amount',
                ],
            ], 422);
        }

        // Validate each allocation - cap it at document balance (no overpayment per invoice)
        // Excess will be handled as customer advance
        $adjustedAllocations = [];
        foreach ($allocations as $allocation) {
            /** @var Document $document */
            $document = Document::findOrFail($allocation['document_id']);

            /** @var numeric-string $requestedAmount */
            $requestedAmount = (string) $allocation['amount'];

            /** @var numeric-string $balanceDue */
            $balanceDue = $document->balance_due ?? $document->total;

            // Cap allocation at document balance (can't overpay a single invoice)
            /** @var numeric-string $allocationAmount */
            $allocationAmount = bccomp($requestedAmount, $balanceDue, 2) > 0
                ? $balanceDue
                : $requestedAmount;

            if (bccomp($allocationAmount, '0', 2) > 0) {
                $adjustedAllocations[] = [
                    'document_id' => $document->id,
                    'amount' => $allocationAmount,
                ];
            }
        }

        // Create payment and allocations in a transaction
        $payment = DB::transaction(function () use ($validated, $user, $paymentAmount, $adjustedAllocations, $tenantId, $companyId) {
            // Determine payment type: advance if no allocations, otherwise document payment
            $paymentType = empty($adjustedAllocations)
                ? PaymentType::Advance
                : PaymentType::DocumentPayment;

            $payment = Payment::create([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'partner_id' => $validated['partner_id'],
                'payment_method_id' => $validated['payment_method_id'],
                'instrument_id' => $validated['instrument_id'] ?? null,
                'repository_id' => $validated['repository_id'] ?? null,
                'amount' => $paymentAmount,
                'currency' => $validated['currency'] ?? 'TND',
                'payment_date' => $validated['payment_date'],
                'status' => PaymentStatus::Completed,
                'payment_type' => $paymentType,
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            // Dispatch PaymentRecorded event for audit trail
            event(new PaymentRecorded(
                paymentId: $payment->id,
                tenantId: $tenantId,
                companyId: $companyId,
                partnerId: $validated['partner_id'],
                amount: $paymentAmount,
                currency: $validated['currency'] ?? 'TND',
                paymentMethodId: $validated['payment_method_id'],
                recordedAt: now()->toIso8601String(),
            ));

            // Create withholding certificate if enabled and document allocated
            if (
                ($validated['withholding_enabled'] ?? false)
                && ! empty($adjustedAllocations)
            ) {
                // Get the first document for withholding certificate
                $firstAllocation = $adjustedAllocations[0];
                /** @var Document $document */
                $document = Document::findOrFail($firstAllocation['document_id']);

                try {
                    $certificateData = $this->withholdingService->createFromPayment(
                        $payment,
                        $document,
                        $validated['withholding_rate'] ?? null,
                        $validated['withholding_override_reason'] ?? null
                    );

                    // Link certificate to payment
                    $payment->withholding_certificate_id = $certificateData->id;
                    $payment->save();
                } catch (\DomainException $e) {
                    // Log error but don't fail the payment
                    // Withholding certificate can be created manually later
                    logger()->warning('Failed to create withholding certificate for payment', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Calculate total allocated for GL entry
            /** @var numeric-string $totalAllocatedForGL */
            $totalAllocatedForGL = '0.00';

            // Create allocations and update document balances
            foreach ($adjustedAllocations as $allocationData) {
                /** @var Document $document */
                $document = Document::lockForUpdate()->findOrFail($allocationData['document_id']);

                /** @var numeric-string $allocationAmount */
                $allocationAmount = (string) $allocationData['amount'];

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'document_id' => $document->id,
                    'amount' => $allocationAmount,
                ]);

                $totalAllocatedForGL = bcadd($totalAllocatedForGL, $allocationAmount, 2);

                // Update document balance
                /** @var numeric-string $currentBalance */
                $currentBalance = $document->balance_due ?? $document->total;
                $newBalance = bcsub($currentBalance, $allocationAmount, 2);
                $document->balance_due = $newBalance;

                // Mark as paid if fully paid (only for document types that support paid status)
                if (bccomp($newBalance, '0.00', 2) === 0 && $document->type->canTransitionToPaid()) {
                    $document->status = DocumentStatus::Paid;
                }

                $document->save();

                // Dispatch DocumentFullyPaid event when document is fully paid
                if ($document->status === DocumentStatus::Paid) {
                    event(new DocumentFullyPaid(
                        documentId: $document->id,
                        tenantId: $tenantId,
                        companyId: $companyId,
                        documentNumber: $document->document_number,
                        documentType: $document->type->value,
                        partnerId: $document->partner_id,
                        totalPaid: $document->total ?? '0.00',
                        paidAt: now()->toIso8601String(),
                    ));
                }
            }

            // Update repository balance and create GL journal entry
            $repositoryId = $validated['repository_id'] ?? null;
            /** @var PaymentRepository|null $repository */
            $repository = null;

            if ($repositoryId) {
                /** @var PaymentRepository|null $repository */
                $repository = PaymentRepository::lockForUpdate()->find($repositoryId);

                if ($repository) {
                    // Increment repository balance by payment amount
                    /** @var numeric-string $currentBalance */
                    $currentBalance = $repository->balance ?? '0.00';
                    $repository->balance = bcadd($currentBalance, $paymentAmount, 2);
                    $repository->save();
                }
            }

            if ($repositoryId && bccomp($totalAllocatedForGL, '0', 2) > 0) {
                // Ensure repository is loaded if not already
                $repository = $repository ?? PaymentRepository::find($repositoryId);

                if ($repository && $repository->account_id) {
                    $journalEntry = $this->glService->createPaymentReceivedJournalEntry(
                        companyId: $companyId,
                        partnerId: $validated['partner_id'],
                        paymentId: $payment->id,
                        amount: $totalAllocatedForGL,
                        paymentMethodAccountId: $repository->account_id,
                        date: new \DateTimeImmutable($validated['payment_date']),
                        description: "Customer payment - {$payment->reference}"
                    );

                    // Link journal entry to payment
                    $payment->journal_entry_id = $journalEntry->id;
                    $payment->save();
                }
            }

            // Handle excess amount as customer advance
            /** @var numeric-string $excessAmount */
            $excessAmount = bcsub($paymentAmount, $totalAllocatedForGL, 2);

            if (bccomp($excessAmount, '0', 2) > 0 && $repositoryId) {
                /** @var PaymentRepository|null $foundRepository */
                $foundRepository = PaymentRepository::find($repositoryId);
                $repository = $repository ?? $foundRepository;

                if ($repository && $repository->account_id) {
                    // Create customer advance GL entry for excess (Dr. Bank, Cr. Customer Advance)
                    $this->glService->createCustomerAdvanceJournalEntry(
                        companyId: $companyId,
                        partnerId: $validated['partner_id'],
                        advanceId: $payment->id,
                        amount: $excessAmount,
                        paymentMethodAccountId: $repository->account_id,
                        date: new \DateTimeImmutable($validated['payment_date']),
                        user: $user,
                        description: "Customer advance from payment {$payment->reference}"
                    );

                    // Update payment type to indicate partial advance
                    if (bccomp($totalAllocatedForGL, '0', 2) > 0) {
                        // Has both allocated and excess - keep as DocumentPayment
                        // The advance portion is tracked via GL
                    } else {
                        // Pure advance payment (no allocations)
                        $payment->payment_type = PaymentType::Advance;
                        $payment->save();
                    }
                }
            }

            return $payment;
        });

        $payment->load(['partner', 'paymentMethod', 'allocations.document']);

        return response()->json([
            'data' => $this->formatPayment($payment),
        ], 201);
    }

    /**
     * Store multiple payments for a document with excess allocation options
     *
     * @param  array<string, mixed>  $validated
     */
    private function storeMultiple(Request $request, User $user, string $tenantId, string $companyId): JsonResponse
    {
        $validated = $request->validate([
            'partner_id' => ['required', 'uuid', 'exists:partners,id'],
            'document_id' => ['required', 'uuid', 'exists:documents,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_date' => ['required', 'date'],

            // Multiple payment lines
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'uuid', 'exists:payment_methods,id'],
            'payments.*.repository_id' => ['nullable', 'uuid', 'exists:payment_repositories,id'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],

            // Excess allocation options
            'excess_allocation_method' => ['nullable', 'string', 'in:fifo,due_date,manual,advance'],
            'excess_allocations' => ['nullable', 'array'],
            'excess_allocations.*.document_id' => ['required_with:excess_allocations', 'uuid', 'exists:documents,id'],
            'excess_allocations.*.amount' => ['required_with:excess_allocations', 'numeric', 'min:0.01'],
        ]);

        // Get the primary document
        /** @var Document $primaryDocument */
        $primaryDocument = Document::findOrFail($validated['document_id']);

        /** @var numeric-string $documentBalance */
        $documentBalance = $primaryDocument->balance_due ?? $primaryDocument->total;

        // Calculate total payment amount
        /** @var numeric-string $totalPaymentAmount */
        $totalPaymentAmount = '0.00';
        foreach ($validated['payments'] as $paymentLine) {
            $totalPaymentAmount = bcadd($totalPaymentAmount, (string) $paymentLine['amount'], 2);
        }

        // Calculate excess amount
        /** @var numeric-string $excessAmount */
        $excessAmount = bcsub($totalPaymentAmount, $documentBalance, 2);
        if (bccomp($excessAmount, '0', 2) < 0) {
            $excessAmount = '0.00';
        }

        // Validate excess allocations if manual method is selected
        $excessAllocationMethod = $validated['excess_allocation_method'] ?? 'advance';
        $excessAllocations = $validated['excess_allocations'] ?? [];

        if ($excessAllocationMethod === 'manual' && bccomp($excessAmount, '0', 2) > 0) {
            /** @var numeric-string $totalManualAllocation */
            $totalManualAllocation = '0.00';
            foreach ($excessAllocations as $allocation) {
                $totalManualAllocation = bcadd($totalManualAllocation, (string) $allocation['amount'], 2);
            }

            // Manual allocations + advance can be less than or equal to excess
            if (bccomp($totalManualAllocation, $excessAmount, 2) > 0) {
                return response()->json([
                    'error' => [
                        'code' => 'EXCESS_ALLOCATION_EXCEEDS_AMOUNT',
                        'message' => 'Manual allocation total exceeds excess amount',
                        'details' => [
                            'excess_amount' => $excessAmount,
                            'manual_allocation_total' => $totalManualAllocation,
                        ],
                    ],
                ], 422);
            }
        }

        // Process in transaction
        $result = DB::transaction(function () use (
            $validated,
            $user,
            $tenantId,
            $companyId,
            $primaryDocument,
            $documentBalance,
            $totalPaymentAmount,
            $excessAmount,
            $excessAllocationMethod,
            $excessAllocations
        ) {
            $createdPayments = [];

            // Calculate amount to allocate to primary document
            /** @var numeric-string $primaryAllocationAmount */
            $primaryAllocationAmount = bccomp($totalPaymentAmount, $documentBalance, 2) >= 0
                ? $documentBalance
                : $totalPaymentAmount;

            // Track remaining primary allocation across payment lines
            /** @var numeric-string $remainingPrimaryAllocation */
            $remainingPrimaryAllocation = $primaryAllocationAmount;

            // Create each payment
            foreach ($validated['payments'] as $index => $paymentLine) {
                /** @var numeric-string $lineAmount */
                $lineAmount = (string) $paymentLine['amount'];

                // Determine payment type
                $paymentType = bccomp($remainingPrimaryAllocation, '0', 2) > 0
                    ? PaymentType::DocumentPayment
                    : PaymentType::Advance;

                $payment = Payment::create([
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'partner_id' => $validated['partner_id'],
                    'payment_method_id' => $paymentLine['payment_method_id'],
                    'repository_id' => $paymentLine['repository_id'] ?? null,
                    'amount' => $lineAmount,
                    'currency' => $validated['currency'] ?? 'TND',
                    'payment_date' => $validated['payment_date'],
                    'status' => PaymentStatus::Completed,
                    'payment_type' => $paymentType,
                    'reference' => $paymentLine['reference'] ?? 'Payment '.($index + 1)." for {$primaryDocument->document_number}",
                    'notes' => 'Multi-payment (part '.($index + 1).' of '.count($validated['payments']).')',
                    'created_by' => $user->id,
                ]);

                // Dispatch PaymentRecorded event
                event(new PaymentRecorded(
                    paymentId: $payment->id,
                    tenantId: $tenantId,
                    companyId: $companyId,
                    partnerId: $validated['partner_id'],
                    amount: $lineAmount,
                    currency: $validated['currency'] ?? 'TND',
                    paymentMethodId: $paymentLine['payment_method_id'],
                    recordedAt: now()->toIso8601String(),
                ));

                // Allocate to primary document
                /** @var numeric-string $allocationForThisPayment */
                $allocationForThisPayment = bccomp($lineAmount, $remainingPrimaryAllocation, 2) >= 0
                    ? $remainingPrimaryAllocation
                    : $lineAmount;

                if (bccomp($allocationForThisPayment, '0', 2) > 0) {
                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'document_id' => $primaryDocument->id,
                        'amount' => $allocationForThisPayment,
                    ]);

                    $remainingPrimaryAllocation = bcsub($remainingPrimaryAllocation, $allocationForThisPayment, 2);
                }

                // Update repository balance
                $repositoryId = $paymentLine['repository_id'] ?? null;
                if ($repositoryId) {
                    /** @var PaymentRepository|null $repository */
                    $repository = PaymentRepository::lockForUpdate()->find($repositoryId);
                    if ($repository) {
                        /** @var numeric-string $currentBalance */
                        $currentBalance = $repository->balance ?? '0.00';
                        $repository->balance = bcadd($currentBalance, $lineAmount, 2);
                        $repository->save();

                        // Create GL entry for allocated portion
                        if (bccomp($allocationForThisPayment, '0', 2) > 0 && $repository->account_id) {
                            $journalEntry = $this->glService->createPaymentReceivedJournalEntry(
                                companyId: $companyId,
                                partnerId: $validated['partner_id'],
                                paymentId: $payment->id,
                                amount: $allocationForThisPayment,
                                paymentMethodAccountId: $repository->account_id,
                                date: new \DateTimeImmutable($validated['payment_date']),
                                description: "Customer payment - {$payment->reference}"
                            );
                            $payment->journal_entry_id = $journalEntry->id;
                            $payment->save();
                        }
                    }
                }

                $createdPayments[] = $payment;
            }

            // Update primary document balance
            $newBalance = bcsub($documentBalance, $primaryAllocationAmount, 2);
            $primaryDocument->balance_due = $newBalance;
            if (bccomp($newBalance, '0.00', 2) === 0 && $primaryDocument->type->canTransitionToPaid()) {
                $primaryDocument->status = DocumentStatus::Paid;

                // Dispatch DocumentFullyPaid event
                event(new DocumentFullyPaid(
                    documentId: $primaryDocument->id,
                    tenantId: $tenantId,
                    companyId: $companyId,
                    documentNumber: $primaryDocument->document_number,
                    documentType: $primaryDocument->type->value,
                    partnerId: $primaryDocument->partner_id,
                    totalPaid: $primaryDocument->total ?? '0.00',
                    paidAt: now()->toIso8601String(),
                ));
            }
            $primaryDocument->save();

            // Handle excess amount
            /** @var array<string, mixed> $excessHandlingResult */
            $excessHandlingResult = [
                'excess_amount' => $excessAmount,
                'allocation_method' => $excessAllocationMethod,
                'allocations' => [],
            ];

            if (bccomp($excessAmount, '0', 2) > 0) {
                // Find the last payment to use for excess allocation
                $lastPayment = end($createdPayments);

                if ($excessAllocationMethod === 'advance') {
                    // Keep as customer advance - create GL entry
                    $repositoryId = $validated['payments'][count($validated['payments']) - 1]['repository_id'] ?? null;
                    if ($repositoryId) {
                        /** @var PaymentRepository|null $repository */
                        $repository = PaymentRepository::find($repositoryId);
                        if ($repository && $repository->account_id) {
                            $this->glService->createCustomerAdvanceJournalEntry(
                                companyId: $companyId,
                                partnerId: $validated['partner_id'],
                                advanceId: $lastPayment->id,
                                amount: $excessAmount,
                                paymentMethodAccountId: $repository->account_id,
                                date: new \DateTimeImmutable($validated['payment_date']),
                                user: $user,
                                description: "Customer advance from payment {$lastPayment->reference}"
                            );
                        }
                    }
                } elseif ($excessAllocationMethod === 'manual' && ! empty($excessAllocations)) {
                    // Manual allocation to specified documents
                    foreach ($excessAllocations as $allocation) {
                        /** @var Document $targetDoc */
                        $targetDoc = Document::lockForUpdate()->findOrFail($allocation['document_id']);
                        /** @var numeric-string $allocAmount */
                        $allocAmount = (string) $allocation['amount'];

                        PaymentAllocation::create([
                            'payment_id' => $lastPayment->id,
                            'document_id' => $targetDoc->id,
                            'amount' => $allocAmount,
                        ]);

                        // Update target document balance
                        /** @var numeric-string $targetBalance */
                        $targetBalance = $targetDoc->balance_due ?? $targetDoc->total;
                        $newTargetBalance = bcsub($targetBalance, $allocAmount, 2);
                        $targetDoc->balance_due = $newTargetBalance;
                        if (bccomp($newTargetBalance, '0.00', 2) === 0 && $targetDoc->type->canTransitionToPaid()) {
                            $targetDoc->status = DocumentStatus::Paid;

                            event(new DocumentFullyPaid(
                                documentId: $targetDoc->id,
                                tenantId: $tenantId,
                                companyId: $companyId,
                                documentNumber: $targetDoc->document_number,
                                documentType: $targetDoc->type->value,
                                partnerId: $targetDoc->partner_id,
                                totalPaid: $targetDoc->total ?? '0.00',
                                paidAt: now()->toIso8601String(),
                            ));
                        }
                        $targetDoc->save();

                        $excessHandlingResult['allocations'][] = [
                            'document_id' => $targetDoc->id,
                            'document_number' => $targetDoc->document_number,
                            'amount' => $allocAmount,
                        ];
                    }
                } elseif (in_array($excessAllocationMethod, ['fifo', 'due_date'], true)) {
                    // Use PaymentAllocationService for automatic allocation
                    $allocationMethod = $excessAllocationMethod === 'fifo'
                        ? AllocationMethod::FIFO
                        : AllocationMethod::DUE_DATE_PRIORITY;

                    $preview = $this->allocationService->previewAllocation(
                        companyId: $companyId,
                        partnerId: $validated['partner_id'],
                        paymentAmount: $excessAmount,
                        allocationMethod: $allocationMethod
                    );

                    // Apply allocations
                    foreach ($preview['allocations'] as $allocation) {
                        /** @var Document $targetDoc */
                        $targetDoc = Document::lockForUpdate()->findOrFail($allocation['document_id']);
                        /** @var numeric-string $allocAmount */
                        $allocAmount = (string) $allocation['amount'];

                        PaymentAllocation::create([
                            'payment_id' => $lastPayment->id,
                            'document_id' => $targetDoc->id,
                            'amount' => $allocAmount,
                        ]);

                        // Update target document balance
                        /** @var numeric-string $targetBalance */
                        $targetBalance = $targetDoc->balance_due ?? $targetDoc->total;
                        $newTargetBalance = bcsub($targetBalance, $allocAmount, 2);
                        $targetDoc->balance_due = $newTargetBalance;
                        if (bccomp($newTargetBalance, '0.00', 2) === 0 && $targetDoc->type->canTransitionToPaid()) {
                            $targetDoc->status = DocumentStatus::Paid;

                            event(new DocumentFullyPaid(
                                documentId: $targetDoc->id,
                                tenantId: $tenantId,
                                companyId: $companyId,
                                documentNumber: $targetDoc->document_number,
                                documentType: $targetDoc->type->value,
                                partnerId: $targetDoc->partner_id,
                                totalPaid: $targetDoc->total ?? '0.00',
                                paidAt: now()->toIso8601String(),
                            ));
                        }
                        $targetDoc->save();

                        $excessHandlingResult['allocations'][] = [
                            'document_id' => $targetDoc->id,
                            'document_number' => $allocation['document_number'],
                            'amount' => $allocAmount,
                        ];
                    }

                    // Any remaining excess becomes customer advance
                    /** @var numeric-string $remainingExcess */
                    $remainingExcess = $preview['excess_amount'];
                    if (bccomp($remainingExcess, '0', 2) > 0) {
                        $repositoryId = $validated['payments'][count($validated['payments']) - 1]['repository_id'] ?? null;
                        if ($repositoryId) {
                            /** @var PaymentRepository|null $repository */
                            $repository = PaymentRepository::find($repositoryId);
                            if ($repository && $repository->account_id) {
                                $this->glService->createCustomerAdvanceJournalEntry(
                                    companyId: $companyId,
                                    partnerId: $validated['partner_id'],
                                    advanceId: $lastPayment->id,
                                    amount: $remainingExcess,
                                    paymentMethodAccountId: $repository->account_id,
                                    date: new \DateTimeImmutable($validated['payment_date']),
                                    user: $user,
                                    description: "Customer advance from payment {$lastPayment->reference}"
                                );
                            }
                        }
                        $excessHandlingResult['remaining_advance'] = $remainingExcess;
                    }
                }
            }

            return [
                'payments' => $createdPayments,
                'primary_document' => $primaryDocument,
                'excess_handling' => $excessHandlingResult,
            ];
        });

        // Load relationships for all payments
        foreach ($result['payments'] as $payment) {
            $payment->load(['partner', 'paymentMethod', 'allocations.document']);
        }

        return response()->json([
            'data' => [
                'payments' => array_map(fn (Payment $p) => $this->formatPayment($p), $result['payments']),
                'document' => [
                    'id' => $result['primary_document']->id,
                    'document_number' => $result['primary_document']->document_number,
                    'balance_due' => $result['primary_document']->balance_due,
                    'status' => $result['primary_document']->status->value,
                ],
                'excess_handling' => $result['excess_handling'],
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPayment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'payment_number' => $payment->reference ?? 'PMT-'.substr($payment->id, 0, 8),
            'partner_id' => $payment->partner_id,
            'partner_name' => $payment->partner?->name,
            'partner_type' => $payment->partner?->type,
            'partner' => $payment->partner ? [
                'id' => $payment->partner->id,
                'name' => $payment->partner->name,
                'type' => $payment->partner->type,
            ] : null,
            'payment_method_id' => $payment->payment_method_id,
            'payment_method_name' => $payment->paymentMethod?->name,
            'payment_method' => $payment->paymentMethod ? [
                'id' => $payment->paymentMethod->id,
                'code' => $payment->paymentMethod->code,
                'name' => $payment->paymentMethod->name,
            ] : null,
            'instrument_id' => $payment->instrument_id,
            'repository_id' => $payment->repository_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'payment_date' => $payment->payment_date->toDateString(),
            'status' => $payment->status->value,
            'payment_type' => $payment->payment_type?->value,
            'allocated_amount' => $payment->getAllocatedAmount(),
            'unallocated_amount' => $payment->getUnallocatedAmount(),
            'reference' => $payment->reference,
            'notes' => $payment->notes,
            'allocations' => $payment->allocations->map(fn (PaymentAllocation $allocation) => [
                'id' => $allocation->id,
                'document_id' => $allocation->document_id,
                'document_number' => $allocation->document->document_number,
                'amount' => $allocation->amount,
            ])->toArray(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }
}
