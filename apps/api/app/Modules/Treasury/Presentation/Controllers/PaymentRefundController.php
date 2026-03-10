<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Http\Controllers\Controller;
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
    ) {}

    /**
     * Refund a complete payment
     */
    public function refundPayment(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        /** @var Payment $payment */
        $payment = Payment::findOrFail($id);

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

        /** @var Payment $payment */
        $payment = Payment::findOrFail($id);

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

        /** @var Payment $payment */
        $payment = Payment::findOrFail($id);

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
        $payment = Payment::findOrFail($id);

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
        $payment = Payment::findOrFail($id);

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
        /** @var Document $document */
        $document = Document::findOrFail($documentId);

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
