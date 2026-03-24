<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use App\Modules\Treasury\Domain\Services\VendorRefundService;
use App\Modules\Treasury\Presentation\Requests\RefundPrepaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentRefundController extends Controller
{
    public function __construct(
        private readonly PaymentRefundService $refundService,
        private readonly VendorRefundService $vendorRefundService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Find a payment scoped to the current tenant, or fail with 404.
     */
    private function findPaymentOrFail(string $id): Payment
    {
        $company = $this->companyContext->requireCompany();

        /** @var Payment */
        return Payment::query()
            ->where('tenant_id', $company->tenant_id)
            ->findOrFail($id);
    }

    /**
     * Find a document scoped to the current tenant, or fail with 404.
     */
    private function findDocumentOrFail(string $id): Document
    {
        $company = $this->companyContext->requireCompany();

        /** @var Document */
        return Document::query()
            ->where('tenant_id', $company->tenant_id)
            ->findOrFail($id);
    }

    /**
     * Refund a complete payment
     */
    public function refundPayment(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $payment = $this->findPaymentOrFail($id);

        try {
            $userId = $request->user()?->id !== null ? (string) $request->user()->id : null;
            $refund = $this->refundService->refundPayment(
                $payment,
                (string) $request->input('reason'),
                $userId
            );

            return response()->json([
                'data' => $refund->load(['partner', 'paymentMethod', 'allocations']),
                'message' => 'Payment refunded successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Partially refund a payment
     */
    public function partialRefund(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
        ]);

        $payment = $this->findPaymentOrFail($id);

        try {
            $userId = $request->user()?->id !== null ? (string) $request->user()->id : null;
            $refund = $this->refundService->partialRefund(
                $payment,
                (string) $request->input('amount'),
                (string) $request->input('reason'),
                $userId
            );

            return response()->json([
                'data' => $refund->load(['partner', 'paymentMethod']),
                'message' => 'Partial refund created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reverse a payment (for errors/corrections)
     */
    public function reversePayment(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $payment = $this->findPaymentOrFail($id);

        try {
            $userId = $request->user()?->id !== null ? (string) $request->user()->id : null;
            $this->refundService->reversePayment(
                $payment,
                (string) $request->input('reason'),
                $userId
            );

            return response()->json([
                'data' => $payment->fresh(),
                'message' => 'Payment reversed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Check if payment can be refunded
     */
    public function checkRefundable(string $id): JsonResponse
    {
        $payment = $this->findPaymentOrFail($id);

        try {
            $canRefund = $this->refundService->canRefund($payment);

            return response()->json([
                'data' => [
                    'can_refund' => $canRefund,
                    'status' => $payment->status->value,
                    'amount' => $payment->amount,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get refund history for a payment
     */
    public function getRefundHistory(string $id): JsonResponse
    {
        $payment = $this->findPaymentOrFail($id);

        try {
            $history = $this->refundService->getRefundHistory($payment);

            return response()->json([
                'data' => $history,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Refund a prepayment on a Purchase Order.
     */
    public function refundPrepayment(RefundPrepaymentRequest $request, string $documentId): JsonResponse
    {
        $document = $this->findDocumentOrFail($documentId);

        try {
            /** @var numeric-string $amount */
            $amount = (string) $request->validated('amount');

            /** @var string|null $userId */
            $userId = $request->user()?->id !== null ? (string) $request->user()->id : null;

            $refund = $this->vendorRefundService->refundPrepayment(
                po: $document,
                amount: $amount,
                paymentMethodId: (string) $request->validated('payment_method_id'),
                repositoryId: (string) $request->validated('repository_id'),
                reason: $request->validated('reason'),
                userId: $userId,
            );

            return response()->json([
                'data' => $refund->load(['partner', 'paymentMethod', 'allocations']),
                'message' => 'Prepayment refunded successfully',
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'REFUND_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }
}
