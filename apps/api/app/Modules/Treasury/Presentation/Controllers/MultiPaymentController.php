<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\Services\MultiPaymentService;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MultiPaymentController extends Controller
{
    public function __construct(
        private readonly MultiPaymentService $multiPaymentService,
        private readonly CompanyContext $companyContext
    ) {}

    /**
     * Create split payment for a document
     */
    public function createSplitPayment(Request $request, string $documentId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $request->validate([
            'splits' => ['required', 'array', 'min:2'],
            'splits.*.payment_method_id' => [
                'required',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId),
            ],
            'splits.*.amount' => ['required', 'numeric', 'min:0.01'],
            'splits.*.repository_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('payment_repositories', $tenantId, $companyId),
            ],
            'splits.*.instrument_id' => [
                'nullable',
                ScopedExists::tenantAndCompany('payment_instruments', $tenantId, $companyId),
            ],
            'splits.*.reference' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var Document $document */
        $document = Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($documentId);

        try {
            /** @var string|null $userId */
            $userId = $request->user()?->id;
            $payments = $this->multiPaymentService->createSplitPayment(
                $document,
                $request->input('splits'),
                $userId !== null ? (string) $userId : null
            );

            return response()->json([
                'data' => [
                    'payments' => $payments,
                    'document' => $document->fresh(['partner']),
                ],
                'message' => 'Split payment created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Record deposit/advance payment
     */
    public function recordDeposit(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $tenantId = $this->companyContext->requireCompany()->tenant_id;

        $request->validate([
            'partner_id' => [
                'required',
                ScopedExists::tenantAndCompany('partners', $tenantId, $companyId),
            ],
            'payment_method_id' => [
                'required',
                ScopedExists::tenantAndCompany('payment_methods', $tenantId, $companyId),
            ],
            'amount' => ['required', 'numeric', 'min:0.01'],
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
                (string) $user->id
            );

            return response()->json([
                'data' => $deposit->load(['partner', 'paymentMethod']),
                'message' => 'Deposit recorded successfully',
            ], 201);
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
            'amount' => ['required', 'numeric', 'min:0.01'],
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
        try {
            $balance = $this->multiPaymentService->getUnallocatedDepositBalance(
                $partnerId,
                $currency
            );

            return response()->json([
                'data' => [
                    'partner_id' => $partnerId,
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
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
        try {
            $balance = $this->multiPaymentService->getPartnerAccountBalance(
                $partnerId,
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
            'splits.*.amount' => 'required|numeric|min:0.01',
            'total_required' => 'required|numeric|min:0.01',
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
