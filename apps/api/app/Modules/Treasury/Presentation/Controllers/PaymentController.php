<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\PostingMode;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DocumentFullyPaid;
use App\Modules\Document\Domain\Services\DocumentStatusService;
use App\Modules\Identity\Domain\User;
use App\Modules\Taxation\Application\Services\WithholdingCertificateService;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\ReceiveInstrumentData;
use App\Modules\Treasury\Application\Services\DocumentAllocationStateGuard;
use App\Modules\Treasury\Application\Services\InstrumentAccountResolver;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Application\Services\OutboundInstrumentIssuer;
use App\Modules\Treasury\Application\Services\OutboundRepositoryValidator;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Application\Services\SupplierPaymentAuthorizer;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\AllocationTreatment;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Events\PaymentRecorded;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\DocumentAllocationClassifier;
use App\Modules\Treasury\Presentation\Requests\ListPaymentsRequest;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly GeneralLedgerService $glService,
        private readonly PaymentAllocationService $allocationService,
        private readonly WithholdingCertificateService $withholdingService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly TreasuryMovementServiceInterface $movementService,
        private readonly InstrumentLifecycleService $instrumentLifecycle,
        private readonly InstrumentAccountResolver $instrumentAccountResolver,
        private readonly OutboundRepositoryValidator $outboundRepositoryValidator,
        private readonly OutboundInstrumentIssuer $outboundInstrumentIssuer,
        private readonly DocumentAllocationStateGuard $allocationStateGuard,
        private readonly DocumentAllocationClassifier $allocationClassifier,
        private readonly DocumentStatusService $documentStatus,
        private readonly SupplierPaymentAuthorizer $supplierPaymentAuthorizer,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * The documents a PERSISTED payment (or multi-payment batch) is allocated
     * to — the replay-path input for {@see SupplierPaymentAuthorizer}
     * (gate r2 finding 4). `findPaymentByIdempotencyKey()` and
     * `findMultiPaymentBatchByIdempotencyKey()` both eager-load `allocations`.
     *
     * @param  array<int, Payment>  $payments
     * @return list<string>
     */
    private function allocatedDocumentIdsOf(array $payments): array
    {
        $ids = [];
        foreach ($payments as $payment) {
            foreach ($payment->allocations as $allocation) {
                $ids[] = $allocation->document_id;
            }
        }

        return $ids;
    }

    /**
     * Pull the document ids out of a validated allocation array.
     *
     * F-W2-14 residual (a) helper for {@see SupplierPaymentAuthorizer}. The
     * shape is already validated (`allocations.*.document_id` /
     * `excess_allocations.*.document_id` are `uuid` + ScopedExists), so this
     * only narrows the static type; anything unexpected is skipped rather than
     * cast, because a silently coerced id would weaken the gate.
     *
     * @return list<string>
     */
    private function allocationDocumentIds(mixed $allocations): array
    {
        if (! is_array($allocations)) {
            return [];
        }

        $ids = [];
        foreach ($allocations as $allocation) {
            if (is_array($allocation) && isset($allocation['document_id']) && is_string($allocation['document_id'])) {
                $ids[] = $allocation['document_id'];
            }
        }

        return $ids;
    }

    /**
     * MTP-TRE-15 fix (review finding C4): normalise `withholding_rate` to a
     * canonical numeric-string ONCE, here at the HTTP boundary, before it
     * ever reaches `WithholdingCertificateService::createFromPayment()`.
     *
     * The FormRequest-equivalent inline rule is `numeric` (not `string`),
     * so a JSON **number** payload (`"withholding_rate": 0.015`) validates
     * just as cleanly as a JSON string (`"withholding_rate": "0.015"`) —
     * Laravel's `numeric`/`regex` rules both accept either shape (`regex`
     * coerces via `preg_match`'s implicit string cast). `$validated`
     * therefore carries WHATEVER type the client sent: `string` or `float`/
     * `int`. Passing that straight through used to throw a TypeError deep
     * inside the withholding service for the number shape (the exact
     * defect this fix removes) — the precision contract (rule 19) forbids
     * "fixing" that by float-casting; instead every caller of
     * `createFromPayment()` must receive the SAME bcmath-domain string
     * regardless of how the client encoded the number.
     *
     * Does NOT float-cast: `is_string` short-circuits for the already-safe
     * shape; for the numeric (int/float) shape, PHP's own `(string)` cast
     * on JSON-decoded numbers is exact for the validated domain here
     * (`numeric`, `min:0`, `max:1`, regex-capped at 4dp) — PHP's default
     * `serialize_precision=-1` uses the shortest round-tripping
     * representation, so `(string) 0.015 === '0.015'`.
     *
     * @return numeric-string|null
     */
    private function normalizeWithholdingRate(mixed $rawRate): ?string
    {
        if ($rawRate === null) {
            return null;
        }

        $normalized = is_string($rawRate) ? $rawRate : (string) $rawRate;
        if (! is_numeric($normalized)) {
            // The `numeric` FormRequest rule should already have rejected
            // this with a 422 before we ever get here — this is a
            // defense-in-depth guard, not the primary validation layer.
            throw new \InvalidArgumentException('withholding_rate must be numeric');
        }

        return $normalized;
    }

    /**
     * Task 16b (spine Wave D, HIGH-7): resolve the client-supplied request-level
     * idempotency key. Prefers the `Idempotency-Key` header (standard practice);
     * falls back to an `idempotency_key` body field for callers that cannot set
     * custom headers. Returns null when no key was supplied at all — callers must
     * treat null as "dedup disabled for this request" (fully backward compatible).
     */
    private function resolveIdempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            $bodyKey = $request->input('idempotency_key');
            $key = is_string($bodyKey) ? $bodyKey : null;
        }

        if ($key === null) {
            return null;
        }

        $key = trim($key);

        return $key !== '' ? $key : null;
    }

    /**
     * Task 16b: look up a previously created payment for this idempotency key,
     * scoped to tenant+company (the unique index is per-company). Loaded with
     * the same relations formatPayment() needs, so a replay response is
     * identical in shape to the original success response.
     */
    private function findPaymentByIdempotencyKey(string $tenantId, string $companyId, string $idempotencyKey): ?Payment
    {
        return Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->with(['partner', 'paymentMethod', 'allocations.document'])
            ->first();
    }

    /**
     * Task 16b: look up a previously created multi-payment batch by its
     * idempotency key. storeMultiple() creates several Payment rows per
     * request (one per split line); each is keyed
     * "{idempotencyKey}:multi:{zero-padded index}" so every row is unique
     * (satisfying the partial unique index) while sharing a discoverable
     * prefix. Returns null on a cache miss (first request for this key).
     *
     * @return array<int, Payment>|null
     */
    private function findMultiPaymentBatchByIdempotencyKey(string $tenantId, string $companyId, string $idempotencyKey): ?array
    {
        $prefix = $idempotencyKey.':multi:';

        $payments = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('idempotency_key', 'like', $prefix.'%')
            ->with(['partner', 'paymentMethod', 'allocations.document'])
            ->orderBy('idempotency_key')
            ->get();

        return $payments->isEmpty() ? null : $payments->values()->all();
    }

    /**
     * Task 16b: rebuild the storeMultiple() success response from an already
     * persisted batch (a replay hit). Reconstructs `excess_handling` from the
     * durable PaymentAllocation rows rather than re-deriving it — any
     * allocation NOT against the primary document is, by construction, an
     * excess allocation the original request created via the manual/fifo/
     * due_date branches. `remaining_advance` (an excess portion with no
     * allocation row at all) cannot be recovered this way and is omitted on
     * replay; it never drove further server-side state, so this is a display-
     * only gap.
     *
     * @param  array<int, Payment>  $payments
     */
    private function formatMultiPaymentReplay(array $payments, Request $request): JsonResponse
    {
        /** @var Payment $anyPayment */
        $anyPayment = $payments[0];

        $documentId = $request->input('document_id');
        $primaryDocument = null;
        if (is_string($documentId) && Str::isUuid($documentId)) {
            $primaryDocument = Document::query()
                ->where('tenant_id', $anyPayment->tenant_id)
                ->where('company_id', $anyPayment->company_id)
                ->find($documentId);
        }

        /** @var numeric-string $excessAmount */
        $excessAmount = '0.000';
        $excessAllocations = [];
        foreach ($payments as $payment) {
            foreach ($payment->allocations as $allocation) {
                if ($primaryDocument instanceof Document && $allocation->document_id === $primaryDocument->id) {
                    continue;
                }

                $excessAllocations[] = [
                    'document_id' => $allocation->document_id,
                    'document_number' => $allocation->document->document_number,
                    'amount' => $allocation->amount,
                ];
                $excessAmount = bcadd($excessAmount, (string) $allocation->amount, $this->scale());
            }
        }

        $requestedMethod = $request->input('excess_allocation_method');

        return response()->json([
            'data' => [
                'payments' => array_map(fn (Payment $p) => $this->formatPayment($p), $payments),
                'document' => $primaryDocument instanceof Document ? [
                    'id' => $primaryDocument->id,
                    'document_number' => $primaryDocument->document_number,
                    'balance_due' => $primaryDocument->balance_due,
                    'status' => $primaryDocument->status->value,
                ] : null,
                'excess_handling' => [
                    'excess_amount' => $excessAmount,
                    'allocation_method' => is_string($requestedMethod) ? $requestedMethod : 'advance',
                    'allocations' => $excessAllocations,
                ],
            ],
        ], 200);
    }

    public function index(ListPaymentsRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;
        $validated = $request->validated();

        // Tenant+company scope — Treasury is company-scoped (api.treasury.075).
        // Without the company_id predicate a user bound to one company could
        // read every company's payments in the tenant (go-live audit #4).
        $query = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with(['partner', 'paymentMethod', 'allocations.document']);

        if (is_string($validated['partner_id'] ?? null)) {
            $query->where('partner_id', $validated['partner_id']);
        }
        if (is_string($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }

        // Free-text search by reference (the displayed payment number derives
        // from reference) or partner name. Grouped so the clauses OR together.
        $search = $validated['search'] ?? null;
        if (is_string($search) && $search !== '') {
            $pattern = '%'.$search.'%';
            $query->where(static function (Builder $searchQuery) use ($pattern): void {
                $searchQuery->where('reference', 'like', $pattern)
                    ->orWhereHas('partner', static function (Builder $partnerQuery) use ($pattern): void {
                        $partnerQuery->where('partners.name', 'like', $pattern);
                    });
            });
        }

        // Always paginated: an unbounded list read is a request-hygiene defect
        // (S-2). `payment_date DESC, id DESC` makes page traversal
        // deterministic even when many payments share one payment date.
        $payments = $query
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate(
                (int) ($validated['per_page'] ?? 25),
                ['*'],
                'page',
                (int) ($validated['page'] ?? 1),
            );

        return response()->json([
            'data' => $payments->getCollection()
                ->map(fn (Payment $payment): array => $this->formatPayment($payment))
                ->values(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'from' => $payments->firstItem(),
                'to' => $payments->lastItem(),
            ],
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

        // Task 16b (spine Wave D, HIGH-7): idempotency short-circuit, checked
        // BEFORE validation and BEFORE the write transaction. A retry (lost
        // response, client timeout) that resends the same Idempotency-Key must
        // return the ORIGINAL payment untouched — no re-validation, no new
        // Payment row, no second treasury movement (Task 16 keys the movement
        // sourceId on $payment->id, so a second payment would mean a second
        // movement and the balance moving twice).
        $idempotencyKey = $this->resolveIdempotencyKey($request);
        if ($idempotencyKey !== null) {
            $existingPayment = $this->findPaymentByIdempotencyKey($tenantId, $companyId, $idempotencyKey);
            if ($existingPayment instanceof Payment) {
                // Gate r2 finding 4: the replay is a READ, but it hands back a
                // full supplier-payment payload, so it must not become the one
                // path around the gate the AP branch just gained. Re-taken
                // against the PERSISTED payment (its partner and its allocated
                // documents) rather than the request body, because a replay
                // carries nothing but the key.
                $this->supplierPaymentAuthorizer->assertMayPay(
                    $user,
                    $tenantId,
                    $companyId,
                    $existingPayment->partner_id,
                    $this->allocatedDocumentIdsOf([$existingPayment]),
                );

                return response()->json([
                    'data' => $this->formatPayment($existingPayment),
                ], 200);
            }
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
            'instrument' => ['nullable', 'array'],
            'instrument.reference' => ['required_with:instrument', 'string', 'max:100'],
            'instrument.maturity_date' => ['nullable', 'date'],
            'instrument.drawer_name' => ['nullable', 'string', 'max:150'],
            'instrument.bank_id' => [
                'nullable', 'uuid',
                ScopedExists::tenant('banks', $tenantId),
            ],
            'instrument.bank_name' => ['nullable', 'string', 'max:100'],
            'instrument.bank_branch' => ['nullable', 'string', 'max:100'],
            'instrument.bank_account' => ['nullable', 'string', 'max:50'],
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

        // F-W2-14 residual (a): `payments.create` (which a cashier holds so a
        // till can take a customer payment) is NOT authority to send money to a
        // supplier. Taken here — after validation proved the ids are real and
        // tenant/company-scoped, before the first write — so a refusal leaves no
        // payment row and no repository movement. Customer-side payments are
        // untouched. See SupplierPaymentAuthorizer.
        $this->supplierPaymentAuthorizer->assertMayPay(
            $user,
            $tenantId,
            $companyId,
            isset($validated['partner_id']) ? (string) $validated['partner_id'] : null,
            $this->allocationDocumentIds($validated['allocations'] ?? null),
        );

        $paymentMethodId = (string) $validated['payment_method_id'];
        $paymentMethod = PaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereKey($paymentMethodId)
            ->firstOrFail();
        $deferredKind = $paymentMethod->has_maturity
            && in_array($paymentMethod->instrument_kind, [InstrumentKind::Cheque, InstrumentKind::Effet], true)
                ? $paymentMethod->instrument_kind
                : null;
        $paymentCurrency = strtoupper((string) ($validated['currency'] ?? $company->currency));

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

            // W4-3: the document's SIDE must agree with its partner's ROLE.
            //
            // Gate r1 I-1 moved this into DocumentAllocationStateGuard, beside
            // assertAllocatable(), because it was on ONE of four settlement routes
            // and the legacy mis-typed row stayed reachable through the other
            // three. Placed BEFORE the supplier arm so a mis-typed document is
            // refused for what is actually wrong with it, rather than for the
            // downstream symptom (a missing Cr-401 journal entry it could never
            // have had).
            $this->allocationStateGuard->assertDirectionMatchesPartner($document);

            // W-7 F-6: a WITHDRAWN document must be un-allocatable. This is the
            // shared per-allocation guard for both the AR and the AP branch below,
            // deliberately placed here rather than on the five `canTransitionToPaid()`
            // status writes it protects — those are a pure TYPE match and cannot be
            // made to express state.
            $this->allocationStateGuard->assertAllocatable($document);

            /** @var numeric-string $requestedAmount */
            $requestedAmount = (string) $allocation['amount'];

            // W-6 D2: this used to be `$document->balance_due ?? $document->total`.
            // `balance_due` is a PostgreSQL trigger cache fired by allocation DML
            // only, so it is NULL on any document that has never been allocated
            // against — and the `?? total` fallback then offered the document's
            // FULL total as payable regardless of what had already been settled by
            // any path the trigger did not observe. Reading the outstanding
            // computed from the allocations themselves removes the cache from this
            // guard entirely; W-7 F-6 escalation (a) is the same fallback seen from
            // the other side, where a cancelled invoice presented as fully payable.
            /** @var numeric-string $balanceDue */
            $balanceDue = $document->outstandingBalance(
                $this->scaleResolver->getScaleSafe((string) $document->currency, 3),
            );

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

                // C-0a0 — fail fast, BEFORE the balance math and before any row is
                // written. The `allocations.*.document_id` rule is a bare
                // `ScopedExists`: it proves the id belongs to this tenant/company
                // and nothing about whether the document may take money. The
                // authoritative verdict is re-taken on the LOCKED row inside the
                // transaction below (a status can change between the two reads);
                // this call is what stops a refused document from ever reaching
                // the cap-and-advance arithmetic. Receivable-side only — the AP
                // branch above owns supplier invoices.
                $this->allocationClassifier->classifyReceivableSide($document);
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

        // Header attribution follows the first allocated document. Pure
        // advances intentionally remain company-level (NULL location).
        $attributionLocationId = null;
        $firstAllocatedDocumentId = $adjustedAllocations[0]['document_id'] ?? null;
        if (is_string($firstAllocatedDocumentId)) {
            $attributionLocationId = Document::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereKey($firstAllocatedDocumentId)
                ->value('location_id');
            $attributionLocationId = is_string($attributionLocationId) ? $attributionLocationId : null;
        }

        $isDeferredCustomer = ! $isSupplierPayment && $deferredKind !== null;
        $isDeferredSupplier = $isSupplierPayment && $deferredKind !== null;
        $portfolioDebitAccountId = null;
        if ($isDeferredCustomer) {
            $hasInlineInstrument = isset($validated['instrument']) && is_array($validated['instrument']);
            $hasInstrumentId = isset($validated['instrument_id']);
            if ($hasInlineInstrument === $hasInstrumentId) {
                return response()->json([
                    'error' => ['code' => 'INVALID_INSTRUMENT_INPUT', 'message' => 'Provide exactly one of instrument or instrument_id.'],
                ], 422);
            }
            if (! isset($validated['repository_id'])) {
                return response()->json([
                    'error' => ['code' => 'REPOSITORY_REQUIRED', 'message' => 'Deferred tenders require a custody repository.'],
                ], 422);
            }
            if (($validated['withholding_enabled'] ?? false) === true) {
                return response()->json([
                    'error' => ['code' => 'WITHHOLDING_NOT_SUPPORTED', 'message' => 'Withholding is not supported on deferred tenders.'],
                ], 422);
            }
            if ($deferredKind === InstrumentKind::Effet
                && $hasInlineInstrument
                && empty($validated['instrument']['maturity_date'])) {
                return response()->json([
                    'error' => ['code' => 'MATURITY_REQUIRED', 'message' => 'Effet instruments require a maturity date.'],
                ], 422);
            }
            try {
                $portfolioDebitAccountId = $this->instrumentAccountResolver->resolveOrFail(
                    $deferredKind === InstrumentKind::Cheque
                        ? InstrumentAccountPurpose::ChecksToCollect
                        : InstrumentAccountPurpose::EffectsReceivable,
                    $companyId,
                );
            } catch (\DomainException $exception) {
                return response()->json([
                    'error' => ['code' => 'MISSING_PORTFOLIO_ACCOUNT', 'message' => $exception->getMessage()],
                ], 422);
            }

            if ($hasInstrumentId) {
                $candidate = PaymentInstrument::query()
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->find($validated['instrument_id']);
                if (! $candidate instanceof PaymentInstrument
                    || $candidate->status !== InstrumentStatus::Received
                    || $candidate->payment_id !== null
                    || $candidate->partner_id !== $validated['partner_id']
                    || $candidate->kind !== $deferredKind
                    || bccomp($candidate->amount, $paymentAmount, $this->scale()) !== 0
                    || $candidate->currency !== $paymentCurrency) {
                    return response()->json([
                        'error' => ['code' => 'INVALID_INSTRUMENT', 'message' => 'Supplied instrument is not eligible for this payment.'],
                    ], 422);
                }
            }
        }
        if ($isDeferredSupplier) {
            $instrumentData = $validated['instrument'] ?? null;
            if (! is_array($instrumentData) || ! isset($instrumentData['reference'])) {
                return response()->json([
                    'error' => ['code' => 'INSTRUMENT_REQUIRED', 'message' => 'Supplier deferred tenders require instrument details.'],
                ], 422);
            }
            if ($deferredKind === InstrumentKind::Effet && empty($instrumentData['maturity_date'])) {
                return response()->json([
                    'error' => ['code' => 'MATURITY_REQUIRED', 'message' => 'Effet instruments require a maturity date.'],
                ], 422);
            }
            try {
                $repositoryId = $validated['repository_id'] ?? null;
                if (! is_string($repositoryId)) {
                    throw new \DomainException('Outbound instrument settlement repository is required.');
                }
                $this->outboundRepositoryValidator->validate(
                    repositoryId: $repositoryId,
                    tenantId: $tenantId,
                    companyId: $companyId,
                    currency: $paymentCurrency,
                    instrumentBankId: isset($instrumentData['bank_id'])
                        ? (string) $instrumentData['bank_id']
                        : null,
                );
            } catch (\DomainException $exception) {
                return response()->json([
                    'error' => [
                        'code' => 'INVALID_OUTBOUND_REPOSITORY',
                        'message' => $exception->getMessage(),
                    ],
                ], 422);
            }
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

                if (! $customerRepository instanceof PaymentRepository
                    || (! $isDeferredCustomer && $customerRepository->gl_account_id === null)) {
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

        // Create payment and allocations in a transaction. Wrapped in try/catch
        // (Task 16b) so a concurrent retry that races this SELECT-then-INSERT — two
        // requests with the same Idempotency-Key both missing the pre-transaction
        // lookup above — is caught by the DB-level partial unique index rather than
        // creating a second payment. The catch is OUTSIDE the transaction closure
        // (not an inner try/catch) so Laravel's transaction manager fully rolls back
        // before the recovery SELECT runs — on Postgres, querying inside an already-
        // aborted transaction throws a second error, so the rollback must complete
        // first.
        try {
            $payment = DB::transaction(function () use (
                $validated,
                $user,
                $paymentAmount,
                $adjustedAllocations,
                $attributionLocationId,
                $tenantId,
                $companyId,
                $isSupplierPayment,
                $idempotencyKey,
                $isDeferredCustomer,
                $deferredKind,
                $portfolioDebitAccountId,
                $paymentCurrency,
                $paymentMethod,
                $isDeferredSupplier,
            ) {
                $instrument = null;
                if ($isDeferredCustomer) {
                    if (isset($validated['instrument_id'])) {
                        $instrumentId = (string) $validated['instrument_id'];
                        $instrument = PaymentInstrument::query()
                            ->where('tenant_id', $tenantId)
                            ->where('company_id', $companyId)
                            ->lockForUpdate()
                            ->whereKey($instrumentId)
                            ->firstOrFail();
                        if ($instrument->status !== InstrumentStatus::Received
                            || $instrument->payment_id !== null
                            || $instrument->partner_id !== $validated['partner_id']
                            || $instrument->kind !== $deferredKind
                            || bccomp($instrument->amount, $paymentAmount, $this->scale()) !== 0
                            || $instrument->currency !== $paymentCurrency) {
                            throw new HttpResponseException(response()->json([
                                'error' => ['code' => 'INVALID_INSTRUMENT', 'message' => 'Supplied instrument is no longer eligible for this payment.'],
                            ], 422));
                        }
                    } else {
                        $instrumentData = $validated['instrument'];
                        $instrument = $this->instrumentLifecycle->receive(new ReceiveInstrumentData(
                            tenantId: $tenantId,
                            companyId: $companyId,
                            paymentMethodId: $paymentMethod->id,
                            kind: $deferredKind,
                            direction: InstrumentDirection::Inbound,
                            origin: InstrumentOrigin::Web,
                            reference: (string) $instrumentData['reference'],
                            amount: $paymentAmount,
                            currency: $paymentCurrency,
                            repositoryId: (string) $validated['repository_id'],
                            partnerId: (string) $validated['partner_id'],
                            drawerName: isset($instrumentData['drawer_name']) ? (string) $instrumentData['drawer_name'] : null,
                            maturityDate: isset($instrumentData['maturity_date']) ? (string) $instrumentData['maturity_date'] : null,
                            receivedDate: $validated['payment_date'],
                            bankId: isset($instrumentData['bank_id']) ? (string) $instrumentData['bank_id'] : null,
                            bankName: isset($instrumentData['bank_name']) ? (string) $instrumentData['bank_name'] : null,
                            bankBranch: isset($instrumentData['bank_branch']) ? (string) $instrumentData['bank_branch'] : null,
                            bankAccount: isset($instrumentData['bank_account']) ? (string) $instrumentData['bank_account'] : null,
                            createdBy: $user->id,
                            locationId: $attributionLocationId,
                        ));
                    }
                }
                if ($isDeferredSupplier) {
                    $instrumentData = $validated['instrument'];
                    $instrument = $this->instrumentLifecycle->receive(new ReceiveInstrumentData(
                        tenantId: $tenantId,
                        companyId: $companyId,
                        paymentMethodId: $paymentMethod->id,
                        kind: $deferredKind,
                        direction: InstrumentDirection::Outbound,
                        origin: InstrumentOrigin::Web,
                        reference: (string) $instrumentData['reference'],
                        amount: $paymentAmount,
                        currency: $paymentCurrency,
                        repositoryId: (string) $validated['repository_id'],
                        partnerId: (string) $validated['partner_id'],
                        drawerName: isset($instrumentData['drawer_name']) ? (string) $instrumentData['drawer_name'] : null,
                        maturityDate: isset($instrumentData['maturity_date']) ? (string) $instrumentData['maturity_date'] : null,
                        receivedDate: $validated['payment_date'],
                        bankId: isset($instrumentData['bank_id']) ? (string) $instrumentData['bank_id'] : null,
                        bankName: isset($instrumentData['bank_name']) ? (string) $instrumentData['bank_name'] : null,
                        bankBranch: isset($instrumentData['bank_branch']) ? (string) $instrumentData['bank_branch'] : null,
                        bankAccount: isset($instrumentData['bank_account']) ? (string) $instrumentData['bank_account'] : null,
                        createdBy: $user->id,
                        locationId: $attributionLocationId,
                    ));
                }

                // Determine payment type.
                //
                // W4R2-2 — the supplier arm comes FIRST. Before this fix the
                // ternary below was the whole rule, so a supplier payment (which
                // always carries allocations: `:803` refuses one that exceeds
                // `$totalAllocated`) was written as `DocumentPayment`, whose
                // `isIncoming()` is `true`. Every web-admin supplier payment was
                // therefore counted by the dashboard's "Payments Received" tile as
                // money that had come IN, while the GL correctly showed it going
                // out (Dr 401 / Cr bank).
                //
                // `$isSupplierPayment` is decided at `:526` from the allocated
                // documents' `DocumentType::SupplierInvoice`, and `:634` refuses a
                // batch that mixes supplier and non-supplier documents — so the
                // flag is unambiguous for the whole payment by the time we get
                // here. `storeMultiple()` cannot reach this branch at all
                // (`rejectSupplierInvoiceInMultiline()`), which is why `store()`
                // is the only writer that needed the arm.
                $paymentType = match (true) {
                    $isSupplierPayment => PaymentType::SupplierPayment,
                    empty($adjustedAllocations) => PaymentType::Advance,
                    default => PaymentType::DocumentPayment,
                };

                // Spec §13 writer-inventory row 2 — `PaymentController::store()` →
                // `web_admin`. `fiscal_event_id` stays NULL (no fiscal event for
                // an admin-side web payment).
                $payment = Payment::create([
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'partner_id' => $validated['partner_id'],
                    'payment_method_id' => $validated['payment_method_id'],
                    'instrument_id' => $instrument instanceof PaymentInstrument
                        ? $instrument->id
                        : ($validated['instrument_id'] ?? null),
                    'repository_id' => $validated['repository_id'] ?? null,
                    'location_id' => $attributionLocationId,
                    'amount' => $paymentAmount,
                    'currency' => $paymentCurrency,
                    'payment_date' => $validated['payment_date'],
                    'status' => PaymentStatus::Completed,
                    'payment_type' => $paymentType,
                    'origin' => PaymentOrigin::WebAdmin,
                    'reference' => $validated['reference'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'created_by' => $user->id,
                    'idempotency_key' => $idempotencyKey,
                ]);

                if ($instrument instanceof PaymentInstrument) {
                    $instrument->update(['payment_id' => $payment->id]);
                }

                // Dispatch PaymentRecorded event for audit trail
                DB::afterCommit(function () use ($payment, $tenantId, $companyId, $validated, $paymentAmount, $paymentCurrency): void {
                    event(new PaymentRecorded(
                        paymentId: $payment->id,
                        tenantId: $tenantId,
                        companyId: $companyId,
                        partnerId: $validated['partner_id'],
                        amount: $paymentAmount,
                        currency: $paymentCurrency,
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
                            $this->normalizeWithholdingRate($validated['withholding_rate'] ?? null),
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
                // N-6 — the customer-side split by POSTED-NESS. A confirmed
                // (unposted) invoice has no 411 debit to settle, so money
                // allocated to it is a customer advance (Cr 419), cleared to
                // 411 when the invoice is posted.
                /** @var numeric-string $allocatedToAdvanceGL */
                $allocatedToAdvanceGL = '0.00';
                /** @var list<string> $advanceAllocationIds */
                $advanceAllocationIds = [];

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

                    // C-0a0 — classify the LOCKED row, UNCONDITIONALLY. N-6 wrote
                    // `type === SupplierInvoice ? null : classify(...)`, so the one
                    // path capable of paying a supplier was the one path the policy
                    // object never saw. `AllocationTreatment::PayableSettlement` is
                    // now a first-class verdict (spec rule 8), so the bypass is gone
                    // and the AP branch below is reached only for a document the
                    // classifier itself ruled payable. Behaviour is unchanged: the
                    // supplier posted-ness + Cr-401-evidence guard above still runs,
                    // and `PayableSettlement` is not a prepayment.
                    $treatment = $this->allocationClassifier->classify($document);
                    $isPrepayment = $treatment === AllocationTreatment::Prepayment;

                    $allocationRow = PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'document_id' => $document->id,
                        'amount' => $allocationAmount,
                        'booked_as_advance' => $isPrepayment,
                    ]);

                    $totalAllocatedForGL = bcadd($totalAllocatedForGL, $allocationAmount, $this->scale());
                    if ($isPrepayment) {
                        $allocatedToAdvanceGL = bcadd($allocatedToAdvanceGL, $allocationAmount, $this->scale());
                        $advanceAllocationIds[] = $allocationRow->id;
                    }
                    $newBalance = bcsub($currentBalance, $allocationAmount, $this->scale());
                    $document->balance_due = $newBalance;
                    $document->save();

                    // Mark as paid if fully paid (only for document types that support paid status).
                    // N-6 — a PREPAYMENT never moves the lifecycle: the document
                    // is paid in advance and still owes its posting, and
                    // `confirmed -> paid` is refused by the status machine.
                    if (
                        bccomp($newBalance, '0.00', $this->scale()) === 0
                        && ! $isPrepayment
                        && $document->type->canTransitionToPaid()
                    ) {
                        $this->documentStatus->markPaid($document);
                    }

                    // Dispatch DocumentFullyPaid event when document is fully paid
                    if ($document->status === DocumentStatus::Paid) {
                        $paidDocumentId = $document->id;
                        // R-2 / LEDGER D-T9-1: `markPaid()` accepts only a POSTED document,
                        // and posting is only reachable past `Draft` — where the number is
                        // allocated. The value is emitted in an immutable event.
                        $paidDocumentNumber = $document->requireDocumentNumber();
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

                // Post the GL journal entry SYNCHRONOUSLY (in-transaction), then record the
                // treasury cash movement through the single write port. This REPLACES the
                // old inline `$repository->balance = bcadd/bcsub(...)` write and the
                // hand-fired RepositoryBalanceChanged event: the port is now the single
                // writer of the repository balance + append-only movement row, atomically
                // with the GL post.
                //
                // Global lock order (BLOCKER-1): the synchronous GL post takes the company
                // advisory lock FIRST; the port then takes the repository row lock inside
                // record(). The repository is therefore NOT locked here — record() locks it.
                $repositoryId = $validated['repository_id'] ?? null;
                /** @var PaymentRepository|null $repository */
                $repository = null;

                if ($repositoryId) {
                    /** @var PaymentRepository|null $repoResult */
                    $repoResult = PaymentRepository::query()
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->find($repositoryId);
                    $repository = $repoResult;
                }

                /** @var string|null $primaryJournalEntryId */
                $primaryJournalEntryId = null;
                $journalEntry = null;

                if (
                    $repository instanceof PaymentRepository
                    && bccomp($totalAllocatedForGL, '0', $this->scale()) > 0
                ) {
                    $postingDebitAccountId = $isDeferredCustomer
                        ? $portfolioDebitAccountId
                        : $repository->gl_account_id;

                    if ($postingDebitAccountId !== null && $isDeferredSupplier) {
                        if (! $instrument instanceof PaymentInstrument) {
                            throw new \LogicException('Deferred supplier payment is missing its outbound instrument.');
                        }
                        $issued = $this->outboundInstrumentIssuer->issueExisting(
                            instrument: $instrument,
                            partnerId: (string) $validated['partner_id'],
                            user: $user,
                            issueDate: (string) $validated['payment_date'],
                            expectedAmount: $totalAllocatedForGL,
                        );
                        $journalEntry = JournalEntry::query()->findOrFail($issued->journalEntryId);
                    } elseif ($postingDebitAccountId !== null && $isSupplierPayment) {
                        // Supplier-side: Dr SupplierPayable (401, partner-tagged) / Cr Bank.
                        // Posted synchronously in-transaction so it returns the POSTED entry;
                        // its JournalEntryPosted event fires afterCommit and the
                        // RefreshPartnerBalanceOnJournalEntryPosted listener recomputes
                        // payable_balance (no manual partner-refresh needed anymore, LOW-13).
                        $journalEntry = $this->glService->createSupplierPaymentJournalEntry(
                            companyId: $companyId,
                            partnerId: $validated['partner_id'],
                            paymentId: $payment->id,
                            amount: $totalAllocatedForGL,
                            paymentMethodAccountId: $postingDebitAccountId,
                            date: new \DateTimeImmutable($validated['payment_date']),
                            user: $user,
                            description: "Supplier payment - {$payment->reference}",
                            currencyCode: $payment->currency,
                            mode: PostingMode::SynchronousInTransaction,
                        );
                    } elseif ($postingDebitAccountId !== null) {
                        // N-6 — only the receivable-clearing portion may credit
                        // 411. The rest is a customer advance and is posted as
                        // its own Cr-419 entry below.
                        /** @var numeric-string $allocatedToReceivableGL */
                        $allocatedToReceivableGL = bcsub($totalAllocatedForGL, $allocatedToAdvanceGL, $this->scale());

                        if (bccomp($allocatedToReceivableGL, '0', $this->scale()) > 0) {
                            $journalEntry = $this->glService->createPaymentReceivedJournalEntry(
                                companyId: $companyId,
                                partnerId: $validated['partner_id'],
                                paymentId: $payment->id,
                                amount: $allocatedToReceivableGL,
                                paymentMethodAccountId: $postingDebitAccountId,
                                date: new \DateTimeImmutable($validated['payment_date']),
                                description: "Customer payment - {$payment->reference}",
                                user: $user,
                                currencyCode: $payment->currency,
                                mode: PostingMode::SynchronousInTransaction,
                            );
                        }
                    }

                    if ($journalEntry !== null) {
                        $primaryJournalEntryId = $journalEntry->id;

                        // Link journal entry to payment
                        $payment->journal_entry_id = $journalEntry->id;
                        $payment->save();
                    }

                    // N-6 — the prepayment portion: Dr Bank / Cr 419. Posted
                    // inside the same transaction and BEFORE the cash movement
                    // below, so a pure-prepayment payment still has a JE to
                    // carry on its movement (reconciliation-readiness §9.2).
                    if (
                        ! $isSupplierPayment
                        && ! $isDeferredSupplier
                        && $postingDebitAccountId !== null
                        && bccomp($allocatedToAdvanceGL, '0', $this->scale()) > 0
                    ) {
                        $prepaymentEntry = $this->glService->createCustomerAdvanceJournalEntry(
                            companyId: $companyId,
                            partnerId: $validated['partner_id'],
                            advanceId: $payment->id,
                            amount: $allocatedToAdvanceGL,
                            paymentMethodAccountId: $postingDebitAccountId,
                            date: new \DateTimeImmutable($validated['payment_date']),
                            user: $user,
                            description: "Prepayment (customer advance) - {$payment->reference}",
                            currencyCode: $payment->currency,
                            mode: PostingMode::SynchronousInTransaction,
                        );

                        PaymentAllocation::query()
                            ->whereIn('id', $advanceAllocationIds)
                            ->update(['advance_journal_entry_id' => $prepaymentEntry->id]);

                        if ($primaryJournalEntryId === null) {
                            $primaryJournalEntryId = $prepaymentEntry->id;
                            $payment->journal_entry_id = $prepaymentEntry->id;
                            $payment->save();
                        }
                    }
                }

                // Handle excess amount as customer advance — posted BEFORE the cash
                // movement so its JE can back the movement (reconciliation-readiness,
                // spec §9.2). Supplier payments are excluded — there is no supplier-
                // advance path here and over-allocation was already rejected before the
                // transaction.
                /** @var numeric-string $excessAmount */
                $excessAmount = bcsub($paymentAmount, $totalAllocatedForGL, $this->scale());

                /** @var string|null $advanceJournalEntryId */
                $advanceJournalEntryId = null;
                $advanceDebitAccountId = null;

                if (! $isSupplierPayment && bccomp($excessAmount, '0', $this->scale()) > 0 && $repositoryId) {
                    if (! $repository instanceof PaymentRepository) {
                        /** @var PaymentRepository|null $foundRepository */
                        $foundRepository = PaymentRepository::query()
                            ->where('tenant_id', $tenantId)
                            ->where('company_id', $companyId)
                            ->find($repositoryId);
                        $repository = $foundRepository;
                    }

                    if ($repository instanceof PaymentRepository) {
                        $advanceDebitAccountId = $isDeferredCustomer
                            ? $portfolioDebitAccountId
                            : $repository->gl_account_id;
                    }

                    if ($advanceDebitAccountId !== null) {
                        // Create customer advance GL entry for excess (Dr. Bank, Cr. Customer Advance)
                        $advanceEntry = $this->glService->createCustomerAdvanceJournalEntry(
                            companyId: $companyId,
                            partnerId: $validated['partner_id'],
                            advanceId: $payment->id,
                            amount: $excessAmount,
                            paymentMethodAccountId: $advanceDebitAccountId,
                            date: new \DateTimeImmutable($validated['payment_date']),
                            user: $user,
                            description: "Customer advance from payment {$payment->reference}",
                            currencyCode: $payment->currency,
                            mode: PostingMode::SynchronousInTransaction,
                        );

                        $advanceJournalEntryId = $advanceEntry->id;

                        // Update payment type to indicate partial advance
                        if (bccomp($totalAllocatedForGL, '0', $this->scale()) > 0) {
                            // Has both allocated and excess - keep as DocumentPayment
                            // The advance portion is tracked via GL
                        } else {
                            // Pure advance payment (no allocations) — link the advance JE
                            // so the cash movement below carries it (no allocation JE exists).
                            $payment->payment_type = PaymentType::Advance;
                            if ($payment->journal_entry_id === null) {
                                $payment->journal_entry_id = $advanceJournalEntryId;
                            }
                            $payment->save();
                        }
                    }
                }

                // Move the treasury cash through the write port — the SINGLE writer of the
                // repository balance + movement row. Direction: customer payments come IN,
                // supplier payments go OUT. The FULL payment amount moves (matching the old
                // inline write); any excess-advance portion was booked above, but the
                // physical cash-in/out is this one movement, keyed on the stable payment id.
                // Amount/currency pass as strings so the port owns all bcmath/scale (Rule 19).
                //
                // Reconciliation-readiness (spec §9.2): the movement MUST carry a JE — the
                // invoice/order-allocation JE if any, else the advance/excess JE. A named
                // repository is already guaranteed ledgered by the pre-transaction guard, so
                // for any cash-moving payment one of these is non-null. If somehow neither
                // posted (a misconfigured null-gl_account_id repository), fail loud with a
                // 422 rather than record a null-JE cash movement that would freeze the repo
                // at reconcile.
                if ($repository instanceof PaymentRepository && ! $isDeferredCustomer && ! $isDeferredSupplier) {
                    /** @var string|null $movementJournalEntryId */
                    $movementJournalEntryId = $primaryJournalEntryId ?? $advanceJournalEntryId;

                    if ($movementJournalEntryId === null) {
                        throw new \DomainException(
                            "a cash movement requires a GL-linked repository; repository {$repository->id} has no gl_account_id"
                        );
                    }

                    $this->movementService->record(new MovementIntent(
                        repositoryId: $repository->id,
                        tenantId: $tenantId,
                        companyId: $companyId,
                        direction: $isSupplierPayment ? MovementDirection::Out : MovementDirection::In,
                        amount: $paymentAmount,
                        // The movement is a fact about THIS repository — record it in the
                        // repository's own currency (the port's currency invariant), not the
                        // request currency. AutoERP is single-currency today, so these agree.
                        currency: $repository->currency,
                        sourceType: MovementSourceType::Payment,
                        sourceId: $payment->id,
                        idempotencyLeg: 'main',
                        journalEntryId: $movementJournalEntryId,
                        occurredAt: null,
                        reasonCode: null,
                        reversesMovementId: null,
                        createdBy: $user->id,
                        notes: null,
                        allowWhileFrozen: false,
                    ));
                }

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Another concurrent request with the same Idempotency-Key won the race
            // and committed first. Read back the row it created and return it —
            // never surface the DB constraint error to the client.
            if ($idempotencyKey !== null) {
                $existingPayment = $this->findPaymentByIdempotencyKey($tenantId, $companyId, $idempotencyKey);
                if ($existingPayment instanceof Payment) {
                    return response()->json([
                        'data' => $this->formatPayment($existingPayment),
                    ], 200);
                }
            }

            throw $e;
        }

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
        $companyCurrency = $this->companyContext->requireCompany()->currency;
        // Task 16b (spine Wave D, HIGH-7): idempotency short-circuit, checked
        // BEFORE validation and BEFORE the write transaction — mirrors store().
        // A retry with the same Idempotency-Key must return the ORIGINAL batch
        // untouched: no re-validation, no new Payment rows, no second set of
        // treasury movements.
        $idempotencyKey = $this->resolveIdempotencyKey($request);
        if ($idempotencyKey !== null) {
            $existingBatch = $this->findMultiPaymentBatchByIdempotencyKey($tenantId, $companyId, $idempotencyKey);
            if ($existingBatch !== null) {
                // Gate r2 finding 4 — same gate as store()'s replay arm, over
                // every row of the batch (they share a partner; the union of the
                // allocated documents is what decides supplier-side).
                $this->supplierPaymentAuthorizer->assertMayPay(
                    $user,
                    $tenantId,
                    $companyId,
                    isset($existingBatch[0]) ? $existingBatch[0]->partner_id : null,
                    $this->allocatedDocumentIdsOf($existingBatch),
                );

                return $this->formatMultiPaymentReplay($existingBatch, $request);
            }
        }

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

        // F-W2-14 residual (a) — same gate as store(), on the multi-tender arm.
        // A multi-payment names ONE settled `document_id` plus optional excess
        // allocations; any of them being a supplier settlement makes the whole
        // batch supplier-side.
        $this->supplierPaymentAuthorizer->assertMayPay(
            $user,
            $tenantId,
            $companyId,
            isset($validated['partner_id']) ? (string) $validated['partner_id'] : null,
            array_merge(
                isset($validated['document_id']) ? [(string) $validated['document_id']] : [],
                $this->allocationDocumentIds($validated['excess_allocations'] ?? null),
            ),
        );

        $methodIds = [];
        foreach ($validated['payments'] as $paymentLine) {
            if (is_array($paymentLine) && isset($paymentLine['payment_method_id']) && is_string($paymentLine['payment_method_id'])) {
                $methodIds[] = $paymentLine['payment_method_id'];
            }
        }
        if (PaymentMethod::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('id', $methodIds)
            ->where('has_maturity', true)
            ->whereIn('instrument_kind', [InstrumentKind::Cheque, InstrumentKind::Effet])
            ->exists()) {
            return response()->json([
                'error' => [
                    'code' => 'DEFERRED_METHOD_NOT_SUPPORTED',
                    'message' => __('treasury.deferred_method_not_supported_on_this_path'),
                ],
            ], 422);
        }

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
        // W-7 F-6: the multi-line path writes the same `Paid` status transitions.
        $this->allocationStateGuard->assertAllocatable($primaryDocument);
        // W4-3 / gate r1 I-1 — storeMultiple's only type guard was
        // rejectSupplierInvoiceInMultiline(), a pure `=== SupplierInvoice` test that
        // a mis-typed customer document passes.
        $this->allocationStateGuard->assertDirectionMatchesPartner($primaryDocument);
        // N-6 — classify the multi-line target once: every line settles the
        // SAME document, so posted-ness cannot differ between them. Refuses a
        // draft / cancelled / credit-note target with 422
        // DOCUMENT_NOT_ALLOCATABLE before any payment row is written.
        $primaryTreatment = $this->allocationClassifier->classifyReceivableSide($primaryDocument);
        $primaryIsPrepayment = $primaryTreatment === AllocationTreatment::Prepayment;

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

        // Process in transaction. Wrapped in try/catch (Task 16b) for the same
        // reason as store(): a concurrent retry that races the pre-transaction
        // lookup above is caught by the partial unique index rather than creating
        // a second batch. Caught OUTSIDE the closure so the transaction fully
        // rolls back before the recovery read runs.
        try {
            $result = DB::transaction(function () use (
                $validated,
                $user,
                $tenantId,
                $companyId,
                $primaryDocument,
                $primaryIsPrepayment,
                $documentBalance,
                $totalPaymentAmount,
                $excessAmount,
                $excessAllocationMethod,
                $excessAllocations,
                $idempotencyKey,
                $companyCurrency,
            ) {
                $createdPayments = [];

                // Reconciliation-readiness (spec §9.2): per-line cash movements are
                // DEFERRED into this list and recorded only AFTER excess handling, once
                // every backing JE (each line's allocation JE and/or the excess/advance
                // JE) is posted and linked on its payment. Recording in the loop (before
                // the excess JE exists) would stamp a null journal_entry_id on a
                // pure-excess line's movement and freeze the repo at Wave-F reconcile.
                //
                // @var array<int, array{payment: Payment, repository: PaymentRepository, amount: numeric-string}> $pendingMovements
                $pendingMovements = [];

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
                        'location_id' => $primaryDocument->location_id,
                        'amount' => $lineAmount,
                        'currency' => $validated['currency'] ?? $companyCurrency,
                        'payment_date' => $validated['payment_date'],
                        'status' => PaymentStatus::Completed,
                        'payment_type' => $paymentType,
                        'origin' => PaymentOrigin::WebAdmin,
                        'reference' => $paymentLine['reference'] ?? 'Payment '.($index + 1)." for {$primaryDocument->document_number}",
                        'notes' => 'Multi-payment (part '.($index + 1).' of '.count($validated['payments']).')',
                        'created_by' => $user->id,
                        // Task 16b: each line gets its own composed key (zero-padded index)
                        // so the partial unique index (company_id, idempotency_key) allows
                        // every line of one batch while still rejecting a duplicate batch.
                        'idempotency_key' => $idempotencyKey !== null
                            ? sprintf('%s:multi:%04d', $idempotencyKey, $index)
                            : null,
                    ]);

                    // Dispatch PaymentRecorded event
                    $paymentId = $payment->id;
                    $paymentMethodId = $paymentLine['payment_method_id'];
                    $currency = $validated['currency'] ?? $companyCurrency;
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
                            'booked_as_advance' => $primaryIsPrepayment,
                        ]);

                        $remainingPrimaryAllocation = bcsub($remainingPrimaryAllocation, $allocationForThisPayment, $this->scale());
                    }

                    // Post the GL entry SYNCHRONOUSLY for the allocated portion, then QUEUE
                    // the treasury cash movement for this payment line (Task 16). The FULL
                    // line amount moves IN (multi-line is customer-only — supplier invoices
                    // are rejected up front). The port is the single writer of the repository
                    // balance + movement row. Global lock order (BLOCKER-1): the synchronous
                    // GL post takes the company advisory lock FIRST, then the port takes the
                    // repository row lock in record() — so the repository is NOT locked here.
                    //
                    // The movement itself is DEFERRED (see $pendingMovements) so the
                    // excess/advance JE posted after this loop can back a pure-excess line.
                    $repositoryId = $paymentLine['repository_id'] ?? null;
                    if ($repositoryId) {
                        /** @var PaymentRepository|null $repository */
                        $repository = PaymentRepository::query()
                            ->where('tenant_id', $tenantId)
                            ->where('company_id', $companyId)
                            ->find($repositoryId);
                        if ($repository) {
                            // Create GL entry for allocated portion
                            if (bccomp($allocationForThisPayment, '0', $this->scale()) > 0 && $repository->gl_account_id) {
                                // N-6 — Cr 411 only when the target carries a
                                // posted receivable; otherwise Cr 419.
                                $journalEntry = $primaryIsPrepayment
                                    ? $this->glService->createCustomerAdvanceJournalEntry(
                                        companyId: $companyId,
                                        partnerId: $validated['partner_id'],
                                        advanceId: $payment->id,
                                        amount: $allocationForThisPayment,
                                        paymentMethodAccountId: $repository->gl_account_id,
                                        date: new \DateTimeImmutable($validated['payment_date']),
                                        user: $user,
                                        description: "Prepayment (customer advance) - {$payment->reference}",
                                        currencyCode: $payment->currency,
                                        mode: PostingMode::SynchronousInTransaction,
                                    )
                                    : $this->glService->createPaymentReceivedJournalEntry(
                                        companyId: $companyId,
                                        partnerId: $validated['partner_id'],
                                        paymentId: $payment->id,
                                        amount: $allocationForThisPayment,
                                        paymentMethodAccountId: $repository->gl_account_id,
                                        date: new \DateTimeImmutable($validated['payment_date']),
                                        description: "Customer payment - {$payment->reference}",
                                        user: $user,
                                        currencyCode: $payment->currency,
                                        mode: PostingMode::SynchronousInTransaction,
                                    );
                                $payment->journal_entry_id = $journalEntry->id;
                                $payment->save();

                                if ($primaryIsPrepayment) {
                                    PaymentAllocation::query()
                                        ->where('payment_id', $payment->id)
                                        ->where('document_id', $primaryDocument->id)
                                        ->update(['advance_journal_entry_id' => $journalEntry->id]);
                                }
                            }

                            // Defer the full line cash-in until after excess handling (below).
                            $pendingMovements[] = [
                                'payment' => $payment,
                                'repository' => $repository,
                                'amount' => $lineAmount,
                            ];
                        }
                    }

                    $createdPayments[] = $payment;
                }

                // Update primary document balance
                $newBalance = bcsub($documentBalance, $primaryAllocationAmount, $this->scale());
                $primaryDocument->balance_due = $newBalance;
                $primaryDocument->save();
                // N-6 — a prepayment clears the BALANCE but never the lifecycle.
                if (
                    bccomp($newBalance, '0.00', $this->scale()) === 0
                    && ! $primaryIsPrepayment
                    && $primaryDocument->type->canTransitionToPaid()
                ) {
                    $this->documentStatus->markPaid($primaryDocument);

                    // Dispatch DocumentFullyPaid event
                    $primaryDocId = $primaryDocument->id;
                    // R-2: `markPaid()` above accepts only a POSTED document, so it is numbered.
                    $primaryDocNumber = $primaryDocument->requireDocumentNumber();
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
                                $advanceEntry = $this->glService->createCustomerAdvanceJournalEntry(
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

                                // Link the advance JE so a pure-excess last line carries a
                                // JE on its deferred cash movement (reconciliation §9.2).
                                if ($lastPayment->journal_entry_id === null) {
                                    $lastPayment->journal_entry_id = $advanceEntry->id;
                                    $lastPayment->save();
                                }
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
                            // W-7 F-6: manual excess targets are client-supplied
                            // document ids and reach the same status write.
                            $this->allocationStateGuard->assertAllocatable($targetDoc);
                            // W4-3 / gate r1 I-1.
                            $this->allocationStateGuard->assertDirectionMatchesPartner($targetDoc);
                            // N-6 — manual excess targets are client-supplied
                            // ids; classify each one on its own locked row.
                            $targetTreatment = $this->allocationClassifier->classifyReceivableSide($targetDoc);
                            $targetIsPrepayment = $targetTreatment === AllocationTreatment::Prepayment;

                            /** @var numeric-string $allocAmount */
                            $allocAmount = (string) $allocation['amount'];

                            $targetAllocation = PaymentAllocation::create([
                                'payment_id' => $lastPayment->id,
                                'document_id' => $targetDoc->id,
                                'amount' => $allocAmount,
                                'booked_as_advance' => $targetIsPrepayment,
                            ]);

                            $this->createPostedExcessAllocationJournalEntry(
                                $lastPayment,
                                $allocAmount,
                                $user,
                                $targetTreatment,
                                $targetAllocation,
                            );

                            // Update target document balance
                            /** @var numeric-string $targetBalance */
                            $targetBalance = $targetDoc->balance_due ?? $targetDoc->total;
                            $newTargetBalance = bcsub($targetBalance, $allocAmount, $this->scale());
                            $targetDoc->balance_due = $newTargetBalance;
                            $targetDoc->save();
                            if (
                                bccomp($newTargetBalance, '0.00', $this->scale()) === 0
                                && ! $targetIsPrepayment
                                && $targetDoc->type->canTransitionToPaid()
                            ) {
                                $this->documentStatus->markPaid($targetDoc);

                                $paidDocId = $targetDoc->id;
                                // R-2: `markPaid()` above accepts only a POSTED document.
                                $paidDocNumber = $targetDoc->requireDocumentNumber();
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
                            // N-6 — the auto preview already restricts the set
                            // (`getOpenInvoices()`), but the row is only LOCKED
                            // here, so the verdict is re-taken on the locked row.
                            $targetTreatment = $this->allocationClassifier->classifyReceivableSide($targetDoc);
                            $targetIsPrepayment = $targetTreatment === AllocationTreatment::Prepayment;
                            /** @var numeric-string $allocAmount */
                            $allocAmount = (string) $allocation['amount'];

                            $targetAllocation = PaymentAllocation::create([
                                'payment_id' => $lastPayment->id,
                                'document_id' => $targetDoc->id,
                                'amount' => $allocAmount,
                                'booked_as_advance' => $targetIsPrepayment,
                            ]);

                            $this->createPostedExcessAllocationJournalEntry(
                                $lastPayment,
                                $allocAmount,
                                $user,
                                $targetTreatment,
                                $targetAllocation,
                            );

                            // Update target document balance
                            /** @var numeric-string $targetBalance */
                            $targetBalance = $targetDoc->balance_due ?? $targetDoc->total;
                            $newTargetBalance = bcsub($targetBalance, $allocAmount, $this->scale());
                            $targetDoc->balance_due = $newTargetBalance;
                            $targetDoc->save();
                            if (
                                bccomp($newTargetBalance, '0.00', $this->scale()) === 0
                                && ! $targetIsPrepayment
                                && $targetDoc->type->canTransitionToPaid()
                            ) {
                                $this->documentStatus->markPaid($targetDoc);

                                $paidDocId = $targetDoc->id;
                                // R-2: `markPaid()` above accepts only a POSTED document.
                                $paidDocNumber = $targetDoc->requireDocumentNumber();
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
                                    $advanceEntry = $this->glService->createCustomerAdvanceJournalEntry(
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

                                    // Link the advance JE so a pure-excess last line carries
                                    // a JE on its deferred cash movement (reconciliation §9.2).
                                    if ($lastPayment->journal_entry_id === null) {
                                        $lastPayment->journal_entry_id = $advanceEntry->id;
                                        $lastPayment->save();
                                    }
                                }
                            }
                            $excessHandlingResult['remaining_advance'] = $remainingExcess;
                        }
                    }
                }

                // Record the deferred per-line cash movements now that every backing JE
                // (each line's allocation JE and/or the excess/advance JE posted above) is
                // linked on its payment. Recorded in line order so per-repository
                // balance_after stays identical to the in-loop ordering (excess handling
                // never moves repository balances). sourceId is each line's own payment id,
                // so the port idempotency key `payment:{id}:main` is unique per line.
                //
                // Reconciliation-readiness (spec §9.2): the movement MUST carry a JE. A
                // cash-moving line whose repository has no gl_account_id posted NO JE —
                // fail loud with a 422 rather than record a null-JE movement that would
                // freeze the repo at reconcile.
                foreach ($pendingMovements as $pending) {
                    /** @var Payment $movementPayment */
                    $movementPayment = $pending['payment'];
                    /** @var PaymentRepository $movementRepository */
                    $movementRepository = $pending['repository'];
                    /** @var numeric-string $movementAmount */
                    $movementAmount = $pending['amount'];

                    /** @var string|null $movementJournalEntryId */
                    $movementJournalEntryId = $movementPayment->fresh()?->journal_entry_id;

                    if ($movementJournalEntryId === null) {
                        throw new \DomainException(
                            "a cash movement requires a GL-linked repository; repository {$movementRepository->id} has no gl_account_id"
                        );
                    }

                    $this->movementService->record(new MovementIntent(
                        repositoryId: $movementRepository->id,
                        tenantId: $tenantId,
                        companyId: $companyId,
                        direction: MovementDirection::In,
                        amount: $movementAmount,
                        // Record in the repository's own currency (port invariant),
                        // not the request currency — single-currency, so they agree.
                        currency: $movementRepository->currency,
                        sourceType: MovementSourceType::Payment,
                        sourceId: $movementPayment->id,
                        idempotencyLeg: 'main',
                        journalEntryId: $movementJournalEntryId,
                        occurredAt: null,
                        reasonCode: null,
                        reversesMovementId: null,
                        createdBy: $user->id,
                        notes: null,
                        allowWhileFrozen: false,
                    ));
                }

                return [
                    'payments' => $createdPayments,
                    'primary_document' => $primaryDocument,
                    'excess_handling' => $excessHandlingResult,
                ];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Another concurrent request with the same Idempotency-Key won the race
            // and committed its batch first. Read it back and return it rather than
            // surfacing the DB constraint error.
            if ($idempotencyKey !== null) {
                $existingBatch = $this->findMultiPaymentBatchByIdempotencyKey($tenantId, $companyId, $idempotencyKey);
                if ($existingBatch !== null) {
                    return $this->formatMultiPaymentReplay($existingBatch, $request);
                }
            }

            throw $e;
        }

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

    private function createPostedExcessAllocationJournalEntry(
        Payment $payment,
        string $amount,
        User $user,
        AllocationTreatment $treatment,
        PaymentAllocation $allocation,
    ): void {
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

        // N-6 — an excess allocation lands on a document like any other, so
        // it obeys the same rule: Cr 411 only against a posted receivable,
        // Cr 419 otherwise.
        if ($treatment === AllocationTreatment::Prepayment) {
            $journalEntry = $this->glService->createCustomerAdvanceJournalEntry(
                companyId: $payment->company_id,
                partnerId: $payment->partner_id,
                advanceId: $payment->id,
                amount: $amount,
                paymentMethodAccountId: $repository->gl_account_id,
                date: $payment->payment_date,
                user: $user,
                description: "Prepayment (customer advance) - {$payment->reference}",
                currencyCode: $payment->currency,
            );

            $allocation->update(['advance_journal_entry_id' => $journalEntry->id]);
        } else {
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
        }

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
            'dishonored_at' => $payment->dishonored_at?->toIso8601String(),
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
