<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Document\Domain\Services\RefundService;
use App\Modules\Taxation\Domain\Exceptions\DocumentPeriodLockedException;
use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function __construct(
        private readonly RefundService $refundService,
        private readonly DocumentNumberingService $numberingService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Tenant+company scoped Document base query for route-anchored lookups.
     *
     * @return Builder<Document>
     */
    private function scopedQuery(): Builder
    {
        $company = $this->companyContext->requireCompany();

        return Document::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id);
    }

    /**
     * Cancel an invoice
     */
    public function cancelInvoice(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $invoice = $this->scopedQuery()->findOrFail($id);

        try {
            $user = $request->user();

            $cancelled = $this->refundService->cancelInvoice(
                $invoice,
                (string) $request->input('reason'),
                $user === null ? null : (string) $user->getAuthIdentifier(),
            );

            return response()->json([
                'data' => $cancelled->load(['lines', 'partner']),
                'message' => 'Invoice cancelled successfully',
            ]);
        } catch (DocumentPeriodLockedException $e) {
            // R2-F1. The generic catch below flattens every failure into
            // `{error: <message>, code: <message>}` — `code` carries the message,
            // not a code — which would erase the typed refusal code and its
            // period metadata. Re-throwing hands the exception to the dedicated
            // renderer in bootstrap/app.php, which emits the standard
            // `{error: {code, message, …}}` envelope with a stable machine code.
            // The pre-existing envelope of the generic branch is left untouched
            // (out of this lane's scope; other consumers assert on it).
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Cancel a credit note
     */
    public function cancelCreditNote(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $creditNote = $this->scopedQuery()->findOrFail($id);

        try {
            $cancelled = $this->refundService->cancelCreditNote(
                $creditNote,
                (string) $request->input('reason')
            );

            return response()->json([
                'data' => $cancelled->load(['lines', 'partner']),
                'message' => 'Credit note cancelled successfully',
            ]);
        } catch (DocumentPeriodLockedException $e) {
            // R2-F1 — see cancelInvoice() above.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Create a full credit note from an invoice
     */
    public function createFullCreditNote(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $invoice = $this->scopedQuery()->findOrFail($id);

        try {
            $creditNote = $this->refundService->createFullCreditNote(
                $invoice,
                (string) $request->input('reason'),
                $this->numberingService
            );

            return response()->json([
                'data' => $creditNote->load(['lines', 'partner']),
                'message' => 'Full credit note created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Create a partial credit note from an invoice
     */
    public function createPartialCreditNote(Request $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $request->validate([
            'reason' => 'required|string|max:500',
            'line_items' => 'required|array|min:1',
            // api.document.042: tenant+company-scoped product validator.
            'line_items.*.product_id' => ['nullable', ScopedExists::tenantAndCompany('products', $company->tenant_id, $company->id)],
            'line_items.*.description' => 'required|string',
            'line_items.*.quantity' => 'required|numeric|min:0.01',
            'line_items.*.unit_price' => 'required|numeric|min:0',
            'line_items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'line_items.*.discount_amount' => 'nullable|numeric|min:0',
            'line_items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'line_items.*.tax_amount' => 'nullable|numeric|min:0',
            'line_items.*.subtotal' => 'required|numeric|min:0',
            'line_items.*.total' => 'required|numeric|min:0',
        ]);

        $invoice = $this->scopedQuery()->findOrFail($id);

        try {
            $creditNote = $this->refundService->createPartialCreditNote(
                $invoice,
                $request->input('line_items'),
                (string) $request->input('reason'),
                $this->numberingService
            );

            return response()->json([
                'data' => $creditNote->load(['lines', 'partner']),
                'message' => 'Partial credit note created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Check if invoice can be cancelled
     */
    public function checkCancellable(string $id): JsonResponse
    {
        $invoice = $this->scopedQuery()->findOrFail($id);

        try {
            // R2-F1 / GL gate I-3: `reason_code` is additive and carries the SAME
            // codes the cancel endpoint's 422 returns, so the UI can disable the
            // button and explain itself (DOCUMENT_PERIOD_FILED is a permanent dead
            // end — a filed declaration is never reopened) instead of letting the
            // user discover the refusal on submit.
            $reasonCode = $this->refundService->cancellationBlockReason($invoice);

            return response()->json([
                'data' => [
                    'can_cancel' => $reasonCode === null,
                    'reason_code' => $reasonCode,
                    'status' => $invoice->status->value,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Check if invoice can be credited
     */
    public function checkCreditable(string $id): JsonResponse
    {
        $invoice = $this->scopedQuery()->findOrFail($id);

        try {
            $canCredit = $this->refundService->canCreditInvoice($invoice);

            return response()->json([
                'data' => [
                    'can_credit' => $canCredit,
                    'status' => $invoice->status->value,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get credit note summary for an invoice
     */
    public function getCreditNoteSummary(string $id): JsonResponse
    {
        $invoice = $this->scopedQuery()->findOrFail($id);

        try {
            $summary = $this->refundService->getCreditNoteSummary($invoice);

            return response()->json([
                'data' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
