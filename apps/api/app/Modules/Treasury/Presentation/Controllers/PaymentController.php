<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DocumentFullyPaid;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Application\Services\WithholdingCertificateService;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Events\PaymentRecorded;
use App\Modules\Treasury\Domain\Events\RepositoryBalanceChanged;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
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
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly PartnerBalanceService $partnerBalanceService,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        // Tenant+company scope — Treasury is company-scoped (api.treasury.075).
        // Without the company_id predicate a user bound to one company could
        // read every company's payments in the tenant (go-live audit #4).
        $query = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
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

        // Tenant+company scope — Treasury is company-scoped (api.treasury.075).
        $payment = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
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
            'partner_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'payment_method_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId),
            ],
            'instrument_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('payment_instruments', $tenantId, $companyId),
            ],
            'repository_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.document_id' => [
                'required_with:allocations',
                'uuid',
                ScopedExists::tenantAndCompany('documents', $tenantId, $companyId),
            ],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
            'withholding_enabled' => ['nullable', 'boolean'],
            'withholding_rate' => ['nullable', 'numeric', 'min:0', 'max:1', 'regex:/^\d+(\.\d{1,4})?$/'],
            'withholding_override_reason' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.regex' => 'Amount must have at most 3 decimal places.',
            'allocations.*.amount.regex' => 'Allocation amount must have at most 3 decimal places.',
            'withholding_rate.regex' => 'Withholding rate must have at most 4 decimal places.',
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
            $totalAllocated = bcadd($totalAllocated, $allocationAmt, $this->scale());
        }

        if (bccomp($totalAllocated, $paymentAmount, $this->scale()) > 0) {
            return response()->json([
                'error' => [
                    'code' => 'ALLOCATION_EXCEEDS_PAYMENT',
                    'message' => 'Total allocation amount exceeds payment amount',
                ],
            ], 422);
        }

        // Validate each allocation - cap it at document balance (no overpayment per invoice)
        // Excess will be handled as customer advance.
        //
        // Branch key (C4): when the allocated document is an AP document
        // (DocumentType::SupplierInvoice) the payment is supplier-side — it clears
        // SupplierPayable (401) and moves cash OUT. Customer/AR allocations keep the
        // existing cap-and-advance behavior untouched.
        $isSupplierPayment = false;
        $supplierDocCount = 0;
        $nonSupplierDocCount = 0;
        $adjustedAllocations = [];
        foreach ($allocations as $allocation) {
            /** @var Document $document */
            $document = Document::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->findOrFail($allocation['document_id']);

            // Cross-partner guard: every allocated document must belong to the
            // payment's partner. Otherwise a single supplier_payment JE could
            // debit 401 for another partner's (or a customer's) balance.
            if ($document->partner_id !== $validated['partner_id']) {
                return response()->json([
                    'error' => [
                        'code' => 'ALLOCATION_PARTNER_MISMATCH',
                        'message' => 'Allocated document belongs to a different partner than the payment',
                        'details' => [
                            'document_id' => $document->id,
                            'document_partner_id' => $document->partner_id,
                            'payment_partner_id' => $validated['partner_id'],
                        ],
                    ],
                ], 422);
            }

            /** @var numeric-string $requestedAmount */
            $requestedAmount = (string) $allocation['amount'];

            /** @var numeric-string $balanceDue */
            $balanceDue = $document->balance_due ?? $document->total;

            if ($document->type === DocumentType::SupplierInvoice) {
                $isSupplierPayment = true;
                $supplierDocCount++;

                // B1 guard: the invoice must be Posted AND have a real Cr-401 JE.
                // Without this check a Draft invoice could be paid — Dr 401 with no
                // matching Cr 401 from posting → negative payable_balance.
                if ($document->status !== DocumentStatus::Posted) {
                    return response()->json([
                        'error' => [
                            'code' => 'SUPPLIER_INVOICE_NOT_POSTED',
                            'message' => 'Supplier invoice must be in Posted status before payment. Post the invoice first.',
                            'details' => [
                                'document_id' => $document->id,
                                'current_status' => $document->status->value,
                            ],
                        ],
                    ], 422);
                }

                // Resolve the company's supplier-payable (401) account for the
                // Cr-401 line check. Without it there is no valid payable credit
                // to verify and the guard must reject.
                $payableAccount = Account::findByPurpose(
                    $document->company_id,
                    SystemAccountPurpose::SupplierPayable,
                );

                // The invoice must have a POSTED supplier_invoice JE that carries
                // at least one credit line on the supplier-payable account tagged
                // to this partner. A Draft JE header, an orphan JE without a
                // payable credit, or a JE for a different partner all fail this
                // predicate and are indistinguishable from "not posted" for the
                // purpose of AP accounting — paying such an invoice would create
                // a Dr-401 payment leg with no matching Cr-401 to cancel.
                $hasPostedCr401Je = $payableAccount !== null && JournalEntry::query()
                    ->where('source_type', 'supplier_invoice')
                    ->where('source_id', $document->id)
                    ->where('company_id', $document->company_id)
                    ->where('status', JournalEntryStatus::Posted)
                    ->whereHas('lines', static function (Builder $q) use ($payableAccount, $document): void {
                        /** @var Builder<JournalLine> $q */
                        $q->where('account_id', $payableAccount->id)
                            ->where('partner_id', $document->partner_id)
                            ->where('credit', '>', '0');
                    })
                    ->exists();

                if (! $hasPostedCr401Je) {
                    return response()->json([
                        'error' => [
                            'code' => 'SUPPLIER_INVOICE_NOT_POSTED',
                            'message' => 'Supplier invoice has no posted journal entry with a supplier-payable credit (Cr 401). Post the invoice first.',
                            'details' => [
                                'document_id' => $document->id,
                            ],
                        ],
                    ], 422);
                }

                // Over-allocation guard (M-5): paying a supplier beyond the invoice's
                // outstanding balance would over-debit 401 and drive payable_balance
                // below the non-negative CHECK. There is no supplier-advance path here,
                // so reject rather than silently cap/route an excess.
                if (bccomp($requestedAmount, $balanceDue, $this->scale()) > 0) {
                    return response()->json([
                        'error' => [
                            'code' => 'SUPPLIER_PAYMENT_EXCEEDS_PAYABLE',
                            'message' => 'Payment amount exceeds the supplier invoice outstanding balance',
                            'details' => [
                                'document_id' => $document->id,
                                'requested_amount' => $requestedAmount,
                                'outstanding_balance' => $balanceDue,
                            ],
                        ],
                    ], 422);
                }
            } else {
                $nonSupplierDocCount++;
            }

            // Cap allocation at document balance (can't overpay a single invoice)
            /** @var numeric-string $allocationAmount */
            $allocationAmount = bccomp($requestedAmount, $balanceDue, $this->scale()) > 0
                ? $balanceDue
                : $requestedAmount;

            if (bccomp($allocationAmount, '0', $this->scale()) > 0) {
                $adjustedAllocations[] = [
                    'document_id' => $document->id,
                    'amount' => $allocationAmount,
                ];
            }
        }

        // Mixed-type guard: a single payment cannot allocate to both a
        // supplier_invoice (AP) and a non-supplier (AR/other) document — the two
        // post opposite GL directions and cannot share one journal entry.
        if ($supplierDocCount > 0 && $nonSupplierDocCount > 0) {
            return response()->json([
                'error' => [
                    'code' => 'MIXED_ALLOCATION_TYPES',
                    'message' => 'A payment cannot mix supplier-invoice and non-supplier allocations',
                ],
            ], 422);
        }

        // Supplier payments require a ledgered repository (a gl_account_id to post
        // the Cr Bank leg). Without it the cash would leave the repository with no
        // 401 entry. Reject up front rather than moving cash with no ledger record.
        if ($isSupplierPayment) {
            $supplierRepositoryId = $validated['repository_id'] ?? null;
            /** @var PaymentRepository|null $supplierRepository */
            $supplierRepository = $supplierRepositoryId !== null
                ? PaymentRepository::query()
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->find($supplierRepositoryId)
                : null;

            if (! $supplierRepository instanceof PaymentRepository || $supplierRepository->gl_account_id === null) {
                return response()->json([
                    'error' => [
                        'code' => 'SUPPLIER_PAYMENT_REQUIRES_LEDGERED_REPOSITORY',
                        'message' => 'Supplier payments require a repository linked to a ledger account',
                    ],
                ], 422);
            }
        }

        // Mirror the supplier guard for customer/AR payments: a payment that names a
        // repository must point at a ledgered repository (gl_account_id set). The GL
        // posting below is gated on gl_account_id; without this guard a misconfigured
        // repository would silently skip the journal entry and AR balances would
        // never refresh (the "account_id vs gl_account_id" trap).
        if (! $isSupplierPayment) {
            $customerRepositoryId = $validated['repository_id'] ?? null;
            if ($customerRepositoryId !== null) {
                /** @var PaymentRepository|null $customerRepository */
                $customerRepository = PaymentRepository::query()
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->find($customerRepositoryId);

                if (! $customerRepository instanceof PaymentRepository || $customerRepository->gl_account_id === null) {
                    return response()->json([
                        'error' => [
                            'code' => 'PAYMENT_REQUIRES_LEDGERED_REPOSITORY',
                            'message' => 'Payments require a repository linked to a ledger account',
                        ],
                    ], 422);
                }
            }
        }

        // Supplier payments never create an advance: the cash leaving the
        // repository must equal the payable it clears. Reject any excess beyond
        // the allocated supplier-invoice balance (keeps cash ↔ GL ↔ 401 balanced).
        if ($isSupplierPayment && bccomp($paymentAmount, $totalAllocated, $this->scale()) > 0) {
            return response()->json([
                'error' => [
                    'code' => 'SUPPLIER_PAYMENT_EXCEEDS_PAYABLE',
                    'message' => 'Supplier payment amount exceeds the allocated outstanding balance',
                    'details' => [
                        'payment_amount' => $paymentAmount,
                        'allocated_amount' => $totalAllocated,
                    ],
                ],
            ], 422);
        }

        // Create payment and allocations in a transaction
        $payment = DB::transaction(function () use ($validated, $user, $paymentAmount, $adjustedAllocations, $tenantId, $companyId, $isSupplierPayment) {
            // Determine payment type: advance if no allocations, otherwise document payment
            $paymentType = empty($adjustedAllocations)
                ? PaymentType::Advance
                : PaymentType::DocumentPayment;

            // Spec §13 writer-inventory row 2 — `PaymentController::store()` →
            // `web_admin`. `fiscal_event_id` stays NULL (no fiscal event for
            // an admin-side web payment).
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
                'origin' => PaymentOrigin::WebAdmin,
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            // Dispatch PaymentRecorded event for audit trail
            DB::afterCommit(function () use ($payment, $tenantId, $companyId, $validated, $paymentAmount): void {
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
            });

            // Create withholding certificate if enabled and document allocated
            if (
                ($validated['withholding_enabled'] ?? false)
                && ! empty($adjustedAllocations)
            ) {
                // Get the first document for withholding certificate
                $firstAllocation = $adjustedAllocations[0];
                /** @var Document $document */
                $document = Document::query()
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->findOrFail($firstAllocation['document_id']);

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
                $document = Document::query()
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->findOrFail($allocationData['document_id']);

                /** @var numeric-string $allocationAmount */
                $allocationAmount = (string) $allocationData['amount'];

                // Update document balance
                /** @var numeric-string $currentBalance */
                $currentBalance = $document->balance_due ?? $document->total;

                // Authoritative over-allocation guard on the LOCKED row (FIX A —
                // concurrency). The pre-transaction check read balance_due without a
                // lock, so two concurrent supplier payments could both pass it. Here the
                // row is locked FOR UPDATE; re-check the outstanding balance and reject
                // if this allocation would over-debit 401 / drive payable_balance < 0.
                if (
                    $document->type === DocumentType::SupplierInvoice
                    && bccomp($allocationAmount, $currentBalance, $this->scale()) > 0
                ) {
                    throw new HttpResponseException(response()->json([
                        'error' => [
                            'code' => 'SUPPLIER_PAYMENT_EXCEEDS_PAYABLE',
                            'message' => 'Payment amount exceeds the supplier invoice outstanding balance',
                            'details' => [
                                'document_id' => $document->id,
                                'requested_amount' => $allocationAmount,
                                'outstanding_balance' => $currentBalance,
                            ],
                        ],
                    ], 422));
                }

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'document_id' => $document->id,
                    'amount' => $allocationAmount,
                ]);

                $totalAllocatedForGL = bcadd($totalAllocatedForGL, $allocationAmount, $this->scale());
                $newBalance = bcsub($currentBalance, $allocationAmount, $this->scale());
                $document->balance_due = $newBalance;

                // Mark as paid if fully paid (only for document types that support paid status)
                if (bccomp($newBalance, '0.00', $this->scale()) === 0 && $document->type->canTransitionToPaid()) {
                    $document->status = DocumentStatus::Paid;
                }

                $document->save();

                // Dispatch DocumentFullyPaid event when document is fully paid
                if ($document->status === DocumentStatus::Paid) {
                    $paidDocumentId = $document->id;
                    $paidDocumentNumber = $document->document_number;
                    $paidDocumentType = $document->type->value;
                    $paidPartnerId = $document->partner_id;
                    $paidTotal = $document->total ?? '0.00';
                    DB::afterCommit(function () use ($paidDocumentId, $tenantId, $companyId, $paidDocumentNumber, $paidDocumentType, $paidPartnerId, $paidTotal): void {
                        event(new DocumentFullyPaid(
                            documentId: $paidDocumentId,
                            tenantId: $tenantId,
                            companyId: $companyId,
                            documentNumber: $paidDocumentNumber,
                            documentType: $paidDocumentType,
                            partnerId: $paidPartnerId,
                            totalPaid: $paidTotal,
                            paidAt: now()->toIso8601String(),
                        ));
                    });
                }
            }

            // Update repository balance and create GL journal entry
            $repositoryId = $validated['repository_id'] ?? null;
            /** @var PaymentRepository|null $repository */
            $repository = null;

            if ($repositoryId) {
                /** @var PaymentRepository|null $repoResult */
                $repoResult = PaymentRepository::query()
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->find($repositoryId);
                $repository = $repoResult;

                if ($repository instanceof PaymentRepository) {
                    // Cash direction: customer payments come IN (increment); supplier
                    // payments go OUT (decrement) — money leaves the repository to pay
                    // the supplier. bcmath at scale 3, never float.
                    /** @var numeric-string $currentBalance */
                    $currentBalance = $repository->balance ?? '0.00';
                    $previousBalance = $currentBalance;
                    $repository->balance = $isSupplierPayment
                        ? bcsub($currentBalance, $paymentAmount, $this->scale())
                        : bcadd($currentBalance, $paymentAmount, $this->scale());
                    $repository->save();

                    $newBalance = $repository->balance;
                    DB::afterCommit(function () use ($repository, $tenantId, $companyId, $previousBalance, $newBalance, $paymentAmount, $validated): void {
                        event(new RepositoryBalanceChanged(
                            repositoryId: $repository->id,
                            tenantId: $tenantId,
                            companyId: $companyId,
                            previousBalance: $previousBalance,
                            newBalance: $newBalance,
                            changeAmount: $paymentAmount,
                            currency: $validated['currency'] ?? 'TND',
                            changedAt: now()->toIso8601String(),
                        ));
                    });
                }
            }

            if ($repositoryId && bccomp($totalAllocatedForGL, '0', $this->scale()) > 0) {
                // Ensure repository is loaded if not already
                if (! $repository instanceof PaymentRepository) {
                    /** @var PaymentRepository|null $repoResult */
                    $repoResult = PaymentRepository::query()
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->find($repositoryId);
                    $repository = $repoResult;
                }

                if ($repository instanceof PaymentRepository && $repository->gl_account_id) {
                    if ($isSupplierPayment) {
                        // Supplier-side: Dr SupplierPayable (401, partner-tagged) / Cr Bank.
                        // Reuse the existing canonical, hash-chained GL method.
                        $journalEntry = $this->glService->createSupplierPaymentJournalEntry(
                            companyId: $companyId,
                            partnerId: $validated['partner_id'],
                            paymentId: $payment->id,
                            amount: $totalAllocatedForGL,
                            paymentMethodAccountId: $repository->gl_account_id,
                            date: new \DateTimeImmutable($validated['payment_date']),
                            user: $user,
                            description: "Supplier payment - {$payment->reference}",
                            currencyCode: $payment->currency
                        );

                        // payable_balance is DERIVED from the 401 subledger; recompute it
                        // after the supplier_payment entry is POSTED. The GL method posts
                        // via afterCommit, so defer the refresh to afterCommit too (and
                        // register it AFTER the post so it runs once the Dr 401 is posted).
                        $supplierPartnerId = $validated['partner_id'];
                        DB::afterCommit(function () use ($companyId, $supplierPartnerId): void {
                            $this->partnerBalanceService->refreshPartnerBalance($companyId, $supplierPartnerId);
                        });
                    } else {
                        $journalEntry = $this->glService->createPaymentReceivedJournalEntry(
                            companyId: $companyId,
                            partnerId: $validated['partner_id'],
                            paymentId: $payment->id,
                            amount: $totalAllocatedForGL,
                            paymentMethodAccountId: $repository->gl_account_id,
                            date: new \DateTimeImmutable($validated['payment_date']),
                            description: "Customer payment - {$payment->reference}",
                            user: $user,
                            currencyCode: $payment->currency
                        );
                    }

                    // Link journal entry to payment
                    $payment->journal_entry_id = $journalEntry->id;
                    $payment->save();
                }
            }

            // Handle excess amount as customer advance.
            // Supplier payments are excluded — there is no supplier-advance path here
            // and over-allocation was already rejected before the transaction.
            /** @var numeric-string $excessAmount */
            $excessAmount = bcsub($paymentAmount, $totalAllocatedForGL, $this->scale());

            if (! $isSupplierPayment && bccomp($excessAmount, '0', $this->scale()) > 0 && $repositoryId) {
                if (! $repository instanceof PaymentRepository) {
                    /** @var PaymentRepository|null $foundRepository */
                    $foundRepository = PaymentRepository::query()
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->find($repositoryId);
                    $repository = $foundRepository;
                }

                if ($repository instanceof PaymentRepository && $repository->gl_account_id) {
                    // Create customer advance GL entry for excess (Dr. Bank, Cr. Customer Advance)
                    $this->glService->createCustomerAdvanceJournalEntry(
                        companyId: $companyId,
                        partnerId: $validated['partner_id'],
                        advanceId: $payment->id,
                        amount: $excessAmount,
                        paymentMethodAccountId: $repository->gl_account_id,
                        date: new \DateTimeImmutable($validated['payment_date']),
                        user: $user,
                        description: "Customer advance from payment {$payment->reference}",
                        currencyCode: $payment->currency
                    );

                    // Update payment type to indicate partial advance
                    if (bccomp($totalAllocatedForGL, '0', $this->scale()) > 0) {
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
     * Reject paying a supplier_invoice through the multi-line payment path, which
     * is not supplier-aware (it posts the customer GL direction and moves cash IN).
     * Supplier invoices must use the single-payment supplier-aware store() flow.
     * Throws a 422 HttpResponseException (rolls back if inside the transaction).
     */
    private function rejectSupplierInvoiceInMultiline(Document $document): void
    {
        if ($document->type === DocumentType::SupplierInvoice) {
            throw new HttpResponseException(response()->json([
                'error' => [
                    'code' => 'SUPPLIER_INVOICE_NOT_PAYABLE_VIA_MULTILINE',
                    'message' => 'Supplier invoices must be paid through the single-payment supplier flow, not multi-line payments',
                    'details' => [
                        'document_id' => $document->id,
                    ],
                ],
            ], 422));
        }
    }

    /**
     * Store multiple payments for a document with excess allocation options.
     */
    private function storeMultiple(Request $request, User $user, string $tenantId, string $companyId): JsonResponse
    {
        $validated = $request->validate([
            'partner_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'document_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('documents', $tenantId, $companyId),
            ],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_date' => ['required', 'date'],

            // Multiple payment lines
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => [
                'required',
                'uuid',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId),
            ],
            'payments.*.repository_id' => [
                'nullable',
                'uuid',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],

            // Excess allocation options
            'excess_allocation_method' => ['nullable', 'string', 'in:fifo,due_date,manual,advance'],
            'excess_allocations' => ['nullable', 'array'],
            'excess_allocations.*.document_id' => [
                'required_with:excess_allocations',
                'uuid',
                ScopedExists::tenantAndCompany('documents', $tenantId, $companyId),
            ],
            'excess_allocations.*.amount' => ['required_with:excess_allocations', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
        ], [
            'payments.*.amount.regex' => 'Payment amount must have at most 3 decimal places.',
            'excess_allocations.*.amount.regex' => 'Excess allocation amount must have at most 3 decimal places.',
        ]);

        // Get the primary document
        /** @var Document $primaryDocument */
        $primaryDocument = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($validated['document_id']);

        // The multi-line path is NOT supplier-aware: it builds AR-style allocations,
        // INCREMENTS the repository, and posts createPaymentReceivedJournalEntry
        // (customer GL / cash IN). Reject supplier invoices up front — they must be
        // paid via the single-payment supplier-aware store() path. (Phase 1 does not
        // support multi-line supplier-invoice payments.)
        $this->rejectSupplierInvoiceInMultiline($primaryDocument);

        /** @var numeric-string $documentBalance */
        $documentBalance = $primaryDocument->balance_due ?? $primaryDocument->total;

        // Calculate total payment amount
        /** @var numeric-string $totalPaymentAmount */
        $totalPaymentAmount = '0.00';
        foreach ($validated['payments'] as $paymentLine) {
            /** @var numeric-string $lineAmt */
            $lineAmt = (string) $paymentLine['amount'];
            $totalPaymentAmount = bcadd($totalPaymentAmount, $lineAmt, $this->scale());
        }

        // Calculate excess amount
        /** @var numeric-string $excessAmount */
        $excessAmount = bcsub($totalPaymentAmount, $documentBalance, $this->scale());
        if (bccomp($excessAmount, '0', $this->scale()) < 0) {
            $excessAmount = '0.00';
        }

        // Validate excess allocations if manual method is selected
        $excessAllocationMethod = $validated['excess_allocation_method'] ?? 'advance';
        $excessAllocations = $validated['excess_allocations'] ?? [];

        if ($excessAllocationMethod === 'manual' && bccomp($excessAmount, '0', $this->scale()) > 0) {
            /** @var numeric-string $totalManualAllocation */
            $totalManualAllocation = '0.00';
            foreach ($excessAllocations as $allocation) {
                /** @var numeric-string $allocAmt */
                $allocAmt = (string) $allocation['amount'];
                $totalManualAllocation = bcadd($totalManualAllocation, $allocAmt, $this->scale());
            }

            // Manual allocations + advance can be less than or equal to excess
            if (bccomp($totalManualAllocation, $excessAmount, $this->scale()) > 0) {
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
            $primaryAllocationAmount = bccomp($totalPaymentAmount, $documentBalance, $this->scale()) >= 0
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
                $paymentType = bccomp($remainingPrimaryAllocation, '0', $this->scale()) > 0
                    ? PaymentType::DocumentPayment
                    : PaymentType::Advance;

                // Spec §13 writer-inventory row 3 — `PaymentController::storeMultiple()`
                // → `web_admin`. `fiscal_event_id` stays NULL.
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
                    'origin' => PaymentOrigin::WebAdmin,
                    'reference' => $paymentLine['reference'] ?? 'Payment '.($index + 1)." for {$primaryDocument->document_number}",
                    'notes' => 'Multi-payment (part '.($index + 1).' of '.count($validated['payments']).')',
                    'created_by' => $user->id,
                ]);

                // Dispatch PaymentRecorded event
                $paymentId = $payment->id;
                $paymentMethodId = $paymentLine['payment_method_id'];
                $currency = $validated['currency'] ?? 'TND';
                $partnerId = $validated['partner_id'];
                DB::afterCommit(function () use ($paymentId, $tenantId, $companyId, $partnerId, $lineAmount, $currency, $paymentMethodId): void {
                    event(new PaymentRecorded(
                        paymentId: $paymentId,
                        tenantId: $tenantId,
                        companyId: $companyId,
                        partnerId: $partnerId,
                        amount: $lineAmount,
                        currency: $currency,
                        paymentMethodId: $paymentMethodId,
                        recordedAt: now()->toIso8601String(),
                    ));
                });

                // Allocate to primary document
                /** @var numeric-string $allocationForThisPayment */
                $allocationForThisPayment = bccomp($lineAmount, $remainingPrimaryAllocation, $this->scale()) >= 0
                    ? $remainingPrimaryAllocation
                    : $lineAmount;

                if (bccomp($allocationForThisPayment, '0', $this->scale()) > 0) {
                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'document_id' => $primaryDocument->id,
                        'amount' => $allocationForThisPayment,
                    ]);

                    $remainingPrimaryAllocation = bcsub($remainingPrimaryAllocation, $allocationForThisPayment, $this->scale());
                }

                // Update repository balance
                $repositoryId = $paymentLine['repository_id'] ?? null;
                if ($repositoryId) {
                    /** @var PaymentRepository|null $repository */
                    $repository = PaymentRepository::query()
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->lockForUpdate()
                        ->find($repositoryId);
                    if ($repository) {
                        /** @var numeric-string $currentBalance */
                        $currentBalance = $repository->balance ?? '0.00';
                        $repository->balance = bcadd($currentBalance, $lineAmount, $this->scale());
                        $repository->save();

                        // Create GL entry for allocated portion
                        if (bccomp($allocationForThisPayment, '0', $this->scale()) > 0 && $repository->gl_account_id) {
                            $journalEntry = $this->glService->createPaymentReceivedJournalEntry(
                                companyId: $companyId,
                                partnerId: $validated['partner_id'],
                                paymentId: $payment->id,
                                amount: $allocationForThisPayment,
                                paymentMethodAccountId: $repository->gl_account_id,
                                date: new \DateTimeImmutable($validated['payment_date']),
                                description: "Customer payment - {$payment->reference}",
                                user: $user,
                                currencyCode: $payment->currency
                            );
                            $payment->journal_entry_id = $journalEntry->id;
                            $payment->save();
                        }
                    }
                }

                $createdPayments[] = $payment;
            }

            // Update primary document balance
            $newBalance = bcsub($documentBalance, $primaryAllocationAmount, $this->scale());
            $primaryDocument->balance_due = $newBalance;
            if (bccomp($newBalance, '0.00', $this->scale()) === 0 && $primaryDocument->type->canTransitionToPaid()) {
                $primaryDocument->status = DocumentStatus::Paid;

                // Dispatch DocumentFullyPaid event
                $primaryDocId = $primaryDocument->id;
                $primaryDocNumber = $primaryDocument->document_number;
                $primaryDocType = $primaryDocument->type->value;
                $primaryDocPartnerId = $primaryDocument->partner_id;
                $primaryDocTotal = $primaryDocument->total ?? '0.00';
                DB::afterCommit(function () use ($primaryDocId, $tenantId, $companyId, $primaryDocNumber, $primaryDocType, $primaryDocPartnerId, $primaryDocTotal): void {
                    event(new DocumentFullyPaid(
                        documentId: $primaryDocId,
                        tenantId: $tenantId,
                        companyId: $companyId,
                        documentNumber: $primaryDocNumber,
                        documentType: $primaryDocType,
                        partnerId: $primaryDocPartnerId,
                        totalPaid: $primaryDocTotal,
                        paidAt: now()->toIso8601String(),
                    ));
                });
            }
            $primaryDocument->save();

            // Handle excess amount
            /** @var array<string, mixed> $excessHandlingResult */
            $excessHandlingResult = [
                'excess_amount' => $excessAmount,
                'allocation_method' => $excessAllocationMethod,
                'allocations' => [],
            ];

            if (bccomp($excessAmount, '0', $this->scale()) > 0 && count($createdPayments) > 0) {
                // Find the last payment to use for excess allocation
                /** @var Payment $lastPayment */
                $lastPayment = $createdPayments[count($createdPayments) - 1];

                if ($excessAllocationMethod === 'advance') {
                    // Keep as customer advance - create GL entry
                    $repositoryId = $validated['payments'][count($validated['payments']) - 1]['repository_id'] ?? null;
                    if ($repositoryId) {
                        /** @var PaymentRepository|null $repository */
                        $repository = PaymentRepository::query()
                            ->where('tenant_id', $tenantId)
                            ->where('company_id', $companyId)
                            ->find($repositoryId);
                        if ($repository && $repository->gl_account_id) {
                            $this->glService->createCustomerAdvanceJournalEntry(
                                companyId: $companyId,
                                partnerId: $validated['partner_id'],
                                advanceId: $lastPayment->id,
                                amount: $excessAmount,
                                paymentMethodAccountId: $repository->gl_account_id,
                                date: new \DateTimeImmutable($validated['payment_date']),
                                user: $user,
                                description: "Customer advance from payment {$lastPayment->reference}",
                                currencyCode: $lastPayment->currency
                            );
                        }
                    }
                } elseif ($excessAllocationMethod === 'manual' && ! empty($excessAllocations)) {
                    // Manual allocation to specified documents
                    foreach ($excessAllocations as $allocation) {
                        /** @var Document $targetDoc */
                        $targetDoc = Document::query()
                            ->where('tenant_id', $tenantId)
                            ->where('company_id', $companyId)
                            ->lockForUpdate()
                            ->findOrFail($allocation['document_id']);

                        // Same gap as the primary document: manual excess allocations
                        // also post the customer GL direction — reject supplier invoices.
                        $this->rejectSupplierInvoiceInMultiline($targetDoc);

                        /** @var numeric-string $allocAmount */
                        $allocAmount = (string) $allocation['amount'];

                        PaymentAllocation::create([
                            'payment_id' => $lastPayment->id,
                            'document_id' => $targetDoc->id,
                            'amount' => $allocAmount,
                        ]);

                        $this->createPostedExcessAllocationJournalEntry($lastPayment, $allocAmount, $user);

                        // Update target document balance
                        /** @var numeric-string $targetBalance */
                        $targetBalance = $targetDoc->balance_due ?? $targetDoc->total;
                        $newTargetBalance = bcsub($targetBalance, $allocAmount, $this->scale());
                        $targetDoc->balance_due = $newTargetBalance;
                        if (bccomp($newTargetBalance, '0.00', $this->scale()) === 0 && $targetDoc->type->canTransitionToPaid()) {
                            $targetDoc->status = DocumentStatus::Paid;

                            $paidDocId = $targetDoc->id;
                            $paidDocNumber = $targetDoc->document_number;
                            $paidDocType = $targetDoc->type->value;
                            $paidDocPartnerId = $targetDoc->partner_id;
                            $paidDocTotal = $targetDoc->total ?? '0.00';
                            DB::afterCommit(function () use ($paidDocId, $tenantId, $companyId, $paidDocNumber, $paidDocType, $paidDocPartnerId, $paidDocTotal): void {
                                event(new DocumentFullyPaid(
                                    documentId: $paidDocId,
                                    tenantId: $tenantId,
                                    companyId: $companyId,
                                    documentNumber: $paidDocNumber,
                                    documentType: $paidDocType,
                                    partnerId: $paidDocPartnerId,
                                    totalPaid: $paidDocTotal,
                                    paidAt: now()->toIso8601String(),
                                ));
                            });
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
                        $targetDoc = Document::query()
                            ->where('tenant_id', $tenantId)
                            ->where('company_id', $companyId)
                            ->lockForUpdate()
                            ->findOrFail($allocation['document_id']);
                        /** @var numeric-string $allocAmount */
                        $allocAmount = (string) $allocation['amount'];

                        PaymentAllocation::create([
                            'payment_id' => $lastPayment->id,
                            'document_id' => $targetDoc->id,
                            'amount' => $allocAmount,
                        ]);

                        $this->createPostedExcessAllocationJournalEntry($lastPayment, $allocAmount, $user);

                        // Update target document balance
                        /** @var numeric-string $targetBalance */
                        $targetBalance = $targetDoc->balance_due ?? $targetDoc->total;
                        $newTargetBalance = bcsub($targetBalance, $allocAmount, $this->scale());
                        $targetDoc->balance_due = $newTargetBalance;
                        if (bccomp($newTargetBalance, '0.00', $this->scale()) === 0 && $targetDoc->type->canTransitionToPaid()) {
                            $targetDoc->status = DocumentStatus::Paid;

                            $paidDocId = $targetDoc->id;
                            $paidDocNumber = $targetDoc->document_number;
                            $paidDocType = $targetDoc->type->value;
                            $paidDocPartnerId = $targetDoc->partner_id;
                            $paidDocTotal = $targetDoc->total ?? '0.00';
                            DB::afterCommit(function () use ($paidDocId, $tenantId, $companyId, $paidDocNumber, $paidDocType, $paidDocPartnerId, $paidDocTotal): void {
                                event(new DocumentFullyPaid(
                                    documentId: $paidDocId,
                                    tenantId: $tenantId,
                                    companyId: $companyId,
                                    documentNumber: $paidDocNumber,
                                    documentType: $paidDocType,
                                    partnerId: $paidDocPartnerId,
                                    totalPaid: $paidDocTotal,
                                    paidAt: now()->toIso8601String(),
                                ));
                            });
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
                    if (bccomp($remainingExcess, '0', $this->scale()) > 0) {
                        $repositoryId = $validated['payments'][count($validated['payments']) - 1]['repository_id'] ?? null;
                        if ($repositoryId) {
                            /** @var PaymentRepository|null $repository */
                            $repository = PaymentRepository::query()
                                ->where('tenant_id', $tenantId)
                                ->where('company_id', $companyId)
                                ->find($repositoryId);
                            if ($repository && $repository->gl_account_id) {
                                $this->glService->createCustomerAdvanceJournalEntry(
                                    companyId: $companyId,
                                    partnerId: $validated['partner_id'],
                                    advanceId: $lastPayment->id,
                                    amount: $remainingExcess,
                                    paymentMethodAccountId: $repository->gl_account_id,
                                    date: new \DateTimeImmutable($validated['payment_date']),
                                    user: $user,
                                    description: "Customer advance from payment {$lastPayment->reference}",
                                    currencyCode: $lastPayment->currency
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

    private function createPostedExcessAllocationJournalEntry(Payment $payment, string $amount, User $user): void
    {
        if ($payment->repository_id === null) {
            return;
        }

        /** @var PaymentRepository|null $repository */
        $repository = PaymentRepository::query()
            ->where('tenant_id', $payment->tenant_id)
            ->where('company_id', $payment->company_id)
            ->find($payment->repository_id);

        if ($repository === null || $repository->gl_account_id === null) {
            return;
        }

        $journalEntry = $this->glService->createPaymentReceivedJournalEntry(
            companyId: $payment->company_id,
            partnerId: $payment->partner_id,
            paymentId: $payment->id,
            amount: $amount,
            paymentMethodAccountId: $repository->gl_account_id,
            date: $payment->payment_date,
            description: "Customer payment - {$payment->reference}",
            user: $user,
            currencyCode: $payment->currency
        );

        if ($payment->journal_entry_id === null) {
            $payment->journal_entry_id = $journalEntry->id;
            $payment->save();
        }
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
            'payment_type' => $payment->payment_type->value,
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
