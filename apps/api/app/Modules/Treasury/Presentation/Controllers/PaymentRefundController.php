<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Treasury\Domain\Exceptions\RefundLaneRefusedException;
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
     * Find a payment scoped to the current tenant + company, or fail with 404.
     * Treasury is company-scoped — a tenant-only lookup would silently bind a
     * foreign-company payment to the refund (api.treasury.071).
     */
    private function findPaymentOrFail(string $id): Payment
    {
        $company = $this->companyContext->requireCompany();

        /** @var Payment */
        return Payment::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($id);
    }

    /**
     * Find a document scoped to the current tenant + company, or fail with 404.
     * The route param {document} is not covered by RefundPrepaymentRequest
     * (body-only validation), so this is the only gate on the URL id
     * (api.treasury.072).
     */
    private function findDocumentOrFail(string $id): Document
    {
        $company = $this->companyContext->requireCompany();

        /** @var Document */
        return Document::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($id);
    }

    /**
     * Refund a complete payment
     */
    public function refundPayment(Request $request, string $id): JsonResponse
    {
        // Resolve (and company-scope) the payment BEFORE validation so a
        // cross-company id 404s rather than surfacing a 422 (api.treasury.071 /
        // TreasuryCompanyIsolationTest).
        $payment = $this->findPaymentOrFail($id);

        $request->validate([
            'reason' => 'required|string|max:500',
            // Task 18 (HIGH-6): a client-supplied UUID makes the full refund
            // idempotent — a retry with the same id returns the same refund
            // instead of double-refunding.
            'refund_request_id' => 'required|uuid',
        ]);

        try {
            $userId = $request->user()?->id !== null ? (string) $request->user()->id : null;
            $refund = $this->refundService->refundPayment(
                $payment,
                (string) $request->input('reason'),
                $userId,
                (string) $request->input('refund_request_id'),
            );

            return response()->json([
                'data' => $refund->load(['partner', 'paymentMethod', 'allocations']),
                'message' => 'Payment refunded successfully',
            ], 201);
        } catch (RefundLaneRefusedException $e) {
            // F-W2-13 fix round 1 (gate r1 finding #2). MUST precede the generic
            // arm below: that arm flattens the refusal to `{"error": "<string>"}`,
            // a shape `apps/web/src/lib/api.ts` `getErrorMessage()` cannot read
            // (it looks for `data.error.message`), so the operator was shown
            // "Request failed with status code 422" instead of the reason. Same
            // house envelope as `refundPrepayment()` below, and the operator text
            // comes from `lang/<locale>/treasury.php` (rule 11).
            return response()->json([
                'error' => [
                    'code' => 'REFUND_LANE_REFUSED',
                    'message' => __($e->translationKey()),
                    'details' => [
                        'payment_id' => $e->paymentId,
                        'payment_type' => $e->paymentType->value,
                    ],
                ],
            ], 422);
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
        // Resolve + company-scope before validation (see refundPayment note).
        $payment = $this->findPaymentOrFail($id);

        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,3})?$/'],
            'reason' => 'required|string|max:500',
            // Task 18 (HIGH-6): required idempotency key — a retry with the same
            // id must not create a second partial refund / movement.
            'refund_request_id' => ['required', 'uuid'],
        ], [
            'amount.regex' => 'Amount must have at most 3 decimal places.',
        ]);

        try {
            $userId = $request->user()?->id !== null ? (string) $request->user()->id : null;
            $refund = $this->refundService->partialRefund(
                $payment,
                (string) $request->input('amount'),
                (string) $request->input('reason'),
                $userId,
                (string) $request->input('refund_request_id'),
            );

            return response()->json([
                'data' => $refund->load(['partner', 'paymentMethod']),
                'message' => 'Partial refund created successfully',
            ], 201);
        } catch (RefundLaneRefusedException $e) {
            // F-W2-13 fix round 1 (gate r1 finding #2). MUST precede the generic
            // arm below: that arm flattens the refusal to `{"error": "<string>"}`,
            // a shape `apps/web/src/lib/api.ts` `getErrorMessage()` cannot read
            // (it looks for `data.error.message`), so the operator was shown
            // "Request failed with status code 422" instead of the reason. Same
            // house envelope as `refundPrepayment()` below, and the operator text
            // comes from `lang/<locale>/treasury.php` (rule 11).
            return response()->json([
                'error' => [
                    'code' => 'REFUND_LANE_REFUSED',
                    'message' => __($e->translationKey()),
                    'details' => [
                        'payment_id' => $e->paymentId,
                        'payment_type' => $e->paymentType->value,
                    ],
                ],
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reverse a payment (for errors/corrections)
     *
     * DPA V4: the reversal now produces a linked REVERSING DOCUMENT, and returning
     * it is the entire user-visible point of the lane.
     *
     * ⚠️ RESHAPE, not an addition: `data` used to BE the payment; it is now
     * `{payment, reversal}`, so `data.status` is gone (read `data.payment.status`).
     * Verified safe for the app — the sole production consumer
     * (`PaymentDetailPage.tsx`) discards the response body and just invalidates its
     * queries. `data.reversal` is NULLABLE: a payment already unwound by a full
     * refund (or by the POS void lane) has nothing to reverse and returns 200 with
     * `reversal: null`, not a 422.
     *
     * No new permission: `can:payments.reverse` still gates the route. Reversal is
     * the same privileged act — only its record shape changed.
     */
    public function reversePayment(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $payment = $this->findPaymentOrFail($id);

        try {
            $userId = $request->user()?->id !== null ? (string) $request->user()->id : null;
            $reversal = $this->refundService->reversePayment(
                $payment,
                (string) $request->input('reason'),
                $userId
            );

            return response()->json([
                'data' => [
                    'payment' => $payment->fresh(),
                    'reversal' => $reversal?->load(['partner', 'paymentMethod']),
                ],
                'message' => $reversal === null
                    ? 'Payment was already unwound; nothing to reverse'
                    : 'Payment reversed successfully',
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
