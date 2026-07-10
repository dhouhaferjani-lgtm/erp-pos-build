<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\Services\MultiPaymentService;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MultiPaymentController extends Controller
{
    public function __construct(
        private readonly MultiPaymentService $multiPaymentService,
        private readonly CompanyContext $companyContext
    ) {}

    /**
     * Task 19 (spine Wave D): resolve the client-supplied request-level idempotency
     * key — mirrors PaymentController. Prefers the `Idempotency-Key` header; falls back
     * to an `idempotency_key` body field. Returns null when no key was supplied at all
     * (dedup disabled — fully backward compatible). Now that this controller's creation
     * flows move cash through the write port, a lost-response retry with the same key
     * must NOT double-pay.
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
     * Task 19: look up a previously created deposit (single payment) by its idempotency
     * key, scoped to tenant+company (the unique index is per-company).
     */
    private function findPaymentByIdempotencyKey(string $tenantId, string $companyId, string $idempotencyKey): ?Payment
    {
        return Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->with(['partner', 'paymentMethod'])
            ->first();
    }

    /**
     * Task 19: look up a previously created split-payment batch by its idempotency key.
     * createSplitPayment() writes several Payment rows per request (one per split line);
     * each is keyed "{idempotencyKey}:multi:{zero-padded index}" so every row is unique
     * (satisfying the partial unique index) while sharing a discoverable prefix. Mirrors
     * PaymentController::findMultiPaymentBatchByIdempotencyKey. Returns null on a miss.
     *
     * @return array<int, Payment>|null
     */
    private function findSplitPaymentBatchByIdempotencyKey(string $tenantId, string $companyId, string $idempotencyKey): ?array
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
     * Create split payment for a document
     */
    public function createSplitPayment(Request $request, string $documentId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        // Task 19 idempotency short-circuit — checked BEFORE validation and BEFORE the
        // write transaction. A retry (lost response) that resends the same key must
        // return the ORIGINAL batch untouched: no re-validation (the document balance is
        // now 0, which would otherwise fail the split-total check), no new payments, no
        // second set of treasury movements.
        $idempotencyKey = $this->resolveIdempotencyKey($request);
        if ($idempotencyKey !== null) {
            $existingBatch = $this->findSplitPaymentBatchByIdempotencyKey($tenantId, $companyId, $idempotencyKey);
            if ($existingBatch !== null) {
                return $this->formatSplitPaymentBatch($existingBatch, $tenantId, $companyId, $documentId, 200);
            }
        }

        $request->validate([
            'splits' => ['required', 'array', 'min:2'],
            'splits.*.payment_method_id' => [
                'required',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId),
            ],
            'splits.*.amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
            'splits.*.repository_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'splits.*.instrument_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('payment_instruments', $tenantId, $companyId),
            ],
            'splits.*.reference' => ['nullable', 'string', 'max:255'],
        ], [
            'splits.*.amount.regex' => 'Split amount must have at most 3 decimal places.',
        ]);

        /** @var Document $document */
        $document = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($documentId);

        // Supplier invoices (AP) are payable only through the supplier-aware
        // PaymentController::store() path, which posts the 401-clearing entry and
        // reduces payable_balance. This flow is not supplier-aware.
        if ($document->type === DocumentType::SupplierInvoice) {
            return $this->rejectSupplierInvoice();
        }

        try {
            /** @var string|null $userId */
            $userId = $request->user()?->id;
            $payments = $this->multiPaymentService->createSplitPayment(
                $document,
                $request->input('splits'),
                $userId !== null ? (string) $userId : null,
                $idempotencyKey
            );

            return response()->json([
                'data' => [
                    'payments' => $payments,
                    'document' => $document->fresh(['partner']),
                ],
                'message' => 'Split payment created successfully',
            ], 201);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent retry with the same Idempotency-Key committed first. The
            // service's DB::transaction has fully rolled back; read back the batch it
            // created and return it rather than surfacing the DB constraint error.
            if ($idempotencyKey !== null) {
                $existingBatch = $this->findSplitPaymentBatchByIdempotencyKey($tenantId, $companyId, $idempotencyKey);
                if ($existingBatch !== null) {
                    return $this->formatSplitPaymentBatch($existingBatch, $tenantId, $companyId, $documentId, 200);
                }
            }

            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Task 19: build the split-payment success response from an already-persisted batch
     * (an idempotency replay hit). Same shape as the original 201 response.
     *
     * @param  array<int, Payment>  $payments
     */
    private function formatSplitPaymentBatch(array $payments, string $tenantId, string $companyId, string $documentId, int $status): JsonResponse
    {
        $document = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->find($documentId);

        return response()->json([
            'data' => [
                'payments' => $payments,
                'document' => $document?->fresh(['partner']),
            ],
            'message' => 'Split payment created successfully',
        ], $status);
    }

    private function rejectSupplierInvoice(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'SUPPLIER_INVOICE_NOT_PAYABLE_HERE',
                'message' => 'Supplier invoices must be paid through the supplier payment flow, not this allocation path',
            ],
        ], 422);
    }

    /**
     * Record deposit/advance payment
     */
    public function recordDeposit(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        // Task 19 idempotency short-circuit — a ledgered deposit now moves cash through
        // the write port, so a lost-response retry must return the ORIGINAL deposit, not
        // record a second payment + movement. Checked before validation and the write.
        $idempotencyKey = $this->resolveIdempotencyKey($request);
        if ($idempotencyKey !== null) {
            $existing = $this->findPaymentByIdempotencyKey($tenantId, $companyId, $idempotencyKey);
            if ($existing instanceof Payment) {
                return response()->json([
                    'data' => $existing->load(['partner', 'paymentMethod']),
                    'message' => 'Deposit recorded successfully',
                ], 200);
            }
        }

        $request->validate([
            'partner_id' => [
                'required',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'payment_method_id' => [
                'required',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
            'currency' => ['required', 'string', 'size:3'],
            'repository_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'instrument_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('payment_instruments', $tenantId, $companyId),
            ],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.regex' => 'Amount must have at most 3 decimal places.',
        ]);

        try {
            /** @var User $user */
            $user = $request->user();
            $deposit = $this->multiPaymentService->recordDeposit(
                $tenantId,
                $companyId,
                $request->input('partner_id'),
                $request->input('payment_method_id'),
                (string) $request->input('amount'),
                $request->input('currency'),
                $request->input('repository_id'),
                $request->input('instrument_id'),
                $request->input('reference'),
                $request->input('notes'),
                (string) $user->id,
                $idempotencyKey
            );

            return response()->json([
                'data' => $deposit->load(['partner', 'paymentMethod']),
                'message' => 'Deposit recorded successfully',
            ], 201);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent retry with the same Idempotency-Key committed first — read it
            // back rather than surfacing the DB constraint error.
            if ($idempotencyKey !== null) {
                $existing = $this->findPaymentByIdempotencyKey($tenantId, $companyId, $idempotencyKey);
                if ($existing instanceof Payment) {
                    return response()->json([
                        'data' => $existing->load(['partner', 'paymentMethod']),
                        'message' => 'Deposit recorded successfully',
                    ], 200);
                }
            }

            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Apply deposit to document
     */
    public function applyDeposit(Request $request, string $paymentId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $request->validate([
            'document_id' => [
                'required',
                ScopedExists::tenantAndCompany('documents', $tenantId, $companyId),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
        ], [
            'amount.regex' => 'Amount must have at most 3 decimal places.',
        ]);

        /** @var Payment $payment */
        $payment = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($paymentId);
        /** @var Document $document */
        $document = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($request->input('document_id'));

        // Supplier invoices (AP) are payable only through the supplier-aware
        // PaymentController::store() path. This deposit flow is not supplier-aware.
        if ($document->type === DocumentType::SupplierInvoice) {
            return $this->rejectSupplierInvoice();
        }

        try {
            $allocation = $this->multiPaymentService->applyDepositToDocument(
                $payment,
                $document,
                (string) $request->input('amount')
            );

            return response()->json([
                'data' => [
                    'allocation' => $allocation,
                    'document' => $document->fresh(),
                    'payment' => $payment->fresh('allocations'),
                ],
                'message' => 'Deposit applied to document successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get unallocated deposit balance for partner
     */
    public function getUnallocatedBalance(string $partnerId, string $currency): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        // Codex round-3 Finding 14 — resolve the partner under the current
        // tenant + company BEFORE handing the id to the service. The service
        // will additionally re-scope its Payment query (defense in depth)
        // but the controller is the primary guard against cross-tenant
        // partner-id probing.
        $partner = Partner::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->find($partnerId);

        if ($partner === null) {
            return response()->json([
                'error' => [
                    'code' => 'PARTNER_NOT_FOUND',
                    'message' => 'Partner not found',
                ],
            ], 404);
        }

        try {
            $balance = $this->multiPaymentService->getUnallocatedDepositBalance(
                $tenantId,
                $companyId,
                $partner->id,
                $currency
            );

            return response()->json([
                'data' => [
                    'partner_id' => $partner->id,
                    'currency' => $currency,
                    'unallocated_balance' => $balance,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Record payment on account
     */
    public function recordPaymentOnAccount(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $request->validate([
            'partner_id' => [
                'required',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
            'currency' => ['required', 'string', 'size:3'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.regex' => 'Amount must have at most 3 decimal places.',
        ]);

        try {
            /** @var User $user */
            $user = $request->user();
            $result = $this->multiPaymentService->recordPaymentOnAccount(
                $tenantId,
                $companyId,
                $request->input('partner_id'),
                (string) $request->input('amount'),
                $request->input('currency'),
                $request->input('reference'),
                $request->input('notes'),
                (string) $user->id
            );

            return response()->json([
                'data' => [
                    'payment' => $result['payment']->load('partner'),
                    'account_balance' => $result['account_balance'],
                ],
                'message' => 'Payment on account recorded successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get partner account balance
     */
    public function getPartnerAccountBalance(string $partnerId, string $currency): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        // Codex round-3 Finding 14 — resolve the partner under the current
        // tenant + company BEFORE handing the id to the service. The service
        // will additionally re-scope its Payment query (defense in depth)
        // but the controller is the primary guard against cross-tenant
        // partner-id probing.
        $partner = Partner::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->find($partnerId);

        if ($partner === null) {
            return response()->json([
                'error' => [
                    'code' => 'PARTNER_NOT_FOUND',
                    'message' => 'Partner not found',
                ],
            ], 404);
        }

        try {
            $balance = $this->multiPaymentService->getPartnerAccountBalance(
                $tenantId,
                $companyId,
                $partner->id,
                $currency
            );

            return response()->json([
                'data' => $balance,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Validate split payment amounts
     */
    public function validateSplit(Request $request): JsonResponse
    {
        $request->validate([
            'splits' => 'required|array|min:2',
            'splits.*.amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
            'total_required' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
        ], [
            'splits.*.amount.regex' => 'Split amount must have at most 3 decimal places.',
            'total_required.regex' => 'Total required must have at most 3 decimal places.',
        ]);

        try {
            $isValid = $this->multiPaymentService->validateSplitAmounts(
                $request->input('splits'),
                (string) $request->input('total_required')
            );

            return response()->json([
                'data' => [
                    'is_valid' => $isValid,
                    'splits' => $request->input('splits'),
                    'total_required' => $request->input('total_required'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
