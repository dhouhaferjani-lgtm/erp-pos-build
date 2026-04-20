<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptCreationService;
use App\Modules\POS\Application\Services\ReceiptPaymentService;
use App\Modules\POS\Application\Services\ReceiptPdfService;
use App\Modules\POS\Application\Services\ReceiptPrintAuditService;
use App\Modules\POS\Application\Services\ReceiptReturnService;
use App\Modules\POS\Application\Services\ReceiptVoidService;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Modules\POS\Domain\Enums\PrintMethod;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Exceptions\DiscountExceedsLimitException;
use App\Modules\POS\Domain\Exceptions\DiscountNotAllowedException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Presentation\Requests\StoreReceiptPaymentsRequest;
use App\Modules\POS\Presentation\Requests\StoreReceiptRequest;
use App\Modules\POS\Presentation\Requests\StoreReturnRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * POS Receipt Controller
 *
 * Handles receipt creation, viewing, and printing operations.
 */
final class ReceiptController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ReceiptCreationService $receiptCreationService,
        private readonly ReceiptPdfService $receiptPdfService,
        private readonly ReceiptPrintAuditService $receiptPrintAuditService,
        private readonly ReceiptPaymentService $receiptPaymentService,
        private readonly ReceiptVoidService $receiptVoidService,
        private readonly ReceiptReturnService $receiptReturnService,
    ) {}

    /**
     * List receipts with filters and pagination.
     *
     * GET /api/v1/pos/receipts
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('pos.view_receipts');

        $companyId = $this->companyContext->getCompanyId();

        $query = Receipt::where('company_id', $companyId)
            ->with(['terminal']);

        // Filters
        if ($request->filled('terminal_id')) {
            $query->where('terminal_id', $request->input('terminal_id'));
        }

        if ($request->filled('cashier_id')) {
            $query->where('cashier_id', $request->input('cashier_id'));
        }

        if ($request->filled('receipt_number')) {
            $query->where('receipt_number', 'like', '%'.$request->input('receipt_number').'%');
        }

        if ($request->filled('customer_id')) {
            $query->where('partner_id', $request->input('customer_id'));
        }

        if ($request->filled('contact_id')) {
            $query->where('contact_id', $request->input('contact_id'));
        }

        if ($request->has('is_voided')) {
            $query->where('is_voided', filter_var($request->input('is_voided'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('receipt_type')) {
            $query->where('receipt_type', $request->input('receipt_type'));
        }

        if ($request->filled('from_date')) {
            $query->where('posted_at', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->where('posted_at', '<=', $request->input('to_date').' 23:59:59');
        }

        $receipts = $query->orderByDesc('posted_at')
            ->paginate((int) $request->input('per_page', 20));

        // Transform receipt data to include terminal_code
        $items = collect($receipts->items())->map(function (Receipt $receipt) {
            return [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'receipt_type' => $receipt->receipt_type->value ?? 'sale',
                'original_receipt_id' => $receipt->original_receipt_id,
                'return_reason' => $receipt->return_reason?->value,
                'terminal_id' => $receipt->terminal_id,
                'terminal_code' => $receipt->terminal->code ?? '',
                'cashier_name' => $receipt->cashier_name,
                'subtotal' => $receipt->subtotal,
                'tax_amount' => $receipt->tax_amount,
                'total' => $receipt->total,
                'currency' => $receipt->currency,
                'customer_name' => $receipt->customer_name,
                'partner_id' => $receipt->partner_id,
                'contact_id' => $receipt->contact_id,
                'posted_at' => $receipt->posted_at->toISOString(),
                'is_voided' => $receipt->is_voided,
                'void_reason' => $receipt->void_reason,
            ];
        });

        return response()->json([
            'data' => [
                'data' => $items,
                'meta' => [
                    'current_page' => $receipts->currentPage(),
                    'last_page' => $receipts->lastPage(),
                    'per_page' => $receipts->perPage(),
                    'total' => $receipts->total(),
                ],
            ],
        ]);
    }

    /**
     * Void a receipt.
     *
     * Marks the receipt as voided, reverses stock movements,
     * and creates reversal GL entries.
     *
     * POST /api/v1/pos/receipts/{id}/void
     */
    public function void(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pos.void_receipts');

        $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $companyId = $this->companyContext->getCompanyId();

        $receipt = Receipt::where('company_id', $companyId)
            ->with(['lines', 'payments'])
            ->findOrFail($id);

        if ($receipt->is_voided) {
            return response()->json([
                'error' => [
                    'code' => 'ALREADY_VOIDED',
                    'message' => 'This receipt has already been voided.',
                ],
            ], 422);
        }

        /** @var User $user */
        $user = Auth::user();

        $voidedReceipt = $this->receiptVoidService->voidReceipt(
            $receipt,
            $user,
            $request->input('reason'),
        );

        return response()->json([
            'data' => [
                'id' => $voidedReceipt->id,
                'receipt_number' => $voidedReceipt->receipt_number,
                'is_voided' => true,
                'voided_at' => $voidedReceipt->voided_at?->toISOString(),
                'void_reason' => $voidedReceipt->void_reason,
            ],
        ]);
    }

    /**
     * Process a partial or full return on a receipt.
     *
     * Creates a new negative receipt (return receipt) that references the original.
     * Restores stock for returned items and records cash drawer refund.
     *
     * POST /api/v1/pos/receipts/{id}/return
     */
    public function processReturn(StoreReturnRequest $request, string $id): JsonResponse
    {
        Gate::authorize('pos.process_returns');

        try {
            $validated = $request->validated();

            /** @var User $user */
            $user = Auth::user();

            $returnReceipt = $this->receiptReturnService->processReturn(
                originalReceiptId: $id,
                returnLines: $validated['lines'],
                returnReason: ReturnReason::from($validated['return_reason']),
                cashier: $user,
                terminalId: $validated['terminal_id'],
                notes: $validated['notes'] ?? null,
            );

            return response()->json([
                'data' => [
                    'id' => $returnReceipt->id,
                    'receipt_number' => $returnReceipt->receipt_number,
                    'receipt_type' => $returnReceipt->receipt_type->value,
                    'original_receipt_id' => $returnReceipt->original_receipt_id,
                    'return_reason' => $returnReceipt->return_reason?->value,
                    'subtotal' => $returnReceipt->subtotal,
                    'tax_amount' => $returnReceipt->tax_amount,
                    'total' => $returnReceipt->total,
                    'currency' => $returnReceipt->currency,
                    'posted_at' => $returnReceipt->posted_at->toISOString(),
                    'lines' => $returnReceipt->lines->map(fn ($line) => [
                        'product_name' => $line->product_name,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unit_price,
                        'line_total' => $line->line_total,
                    ])->values()->toArray(),
                ],
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'RETURN_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_RETURN_DATA',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        }
    }

    /**
     * Create a new POS receipt.
     *
     * Creates a receipt with line items, calculates VAT, decrements stock,
     * and computes the fiscal hash chain.
     */
    public function store(StoreReceiptRequest $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        try {
            $validated = $request->validated();
            $transactionDiscountAmount = isset($validated['transaction_discount_amount'])
                ? (string) $validated['transaction_discount_amount']
                : null;

            $consumptionMode = isset($validated['consumption_mode'])
                ? ConsumptionMode::from($validated['consumption_mode'])
                : null;

            $receipt = $this->receiptCreationService->createReceipt(
                terminalId: $validated['terminal_id'],
                lines: $validated['lines'],
                customerId: $validated['customer_id'] ?? null,
                contactId: $validated['contact_id'] ?? null,
                notes: $validated['notes'] ?? null,
                transactionDiscountAmount: $transactionDiscountAmount,
                transactionDiscountReason: $validated['transaction_discount_reason'] ?? null,
                couponCode: $validated['coupon_code'] ?? null,
                loyaltyDiscountAmount: isset($validated['loyalty_discount_amount'])
                    ? (string) $validated['loyalty_discount_amount']
                    : null,
                loyaltyRewardId: $validated['loyalty_reward_id'] ?? null,
                consumptionMode: $consumptionMode,
            );

            return response()->json([
                'data' => $receipt,
            ], 201);
        } catch (DiscountNotAllowedException|DiscountExceedsLimitException $e) {
            return response()->json([
                'error' => [
                    'code' => 'DISCOUNT_VALIDATION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'RECEIPT_CREATION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_RECEIPT_DATA',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        }
    }

    /**
     * Display the specified receipt.
     */
    public function show(string $id): JsonResponse
    {
        Gate::authorize('pos.view_receipts');

        $companyId = $this->companyContext->getCompanyId();

        $receipt = Receipt::with([
            'company',
            'location',
            'terminal',
            'cashier',
            'lines.product',
            'vatDetails',
            'payments.paymentMethod',
            'returnReceipts' => function ($query): void {
                $query->where('is_voided', false)->with('lines');
            },
        ])
            ->where('company_id', $companyId)
            ->findOrFail($id);

        // Calculate already-returned quantities per line
        $returnedQuantities = $this->calculateReturnedQuantities($receipt);

        // Build response with returned_quantity on each line
        $receiptData = $receipt->toArray();
        $receiptData['lines'] = $receipt->lines->map(function ($line) use ($returnedQuantities) {
            $lineData = $line->toArray();
            $lineData['returned_quantity'] = $returnedQuantities[$line->id] ?? '0.000';

            return $lineData;
        })->values()->toArray();

        // Remove the returnReceipts from the response (internal use only)
        unset($receiptData['return_receipts']);

        return response()->json([
            'data' => $receiptData,
        ]);
    }

    /**
     * Calculate already-returned quantities per original line ID.
     *
     * Sums absolute quantities from non-voided return receipts.
     * Uses original_line_id for precise matching when available, falls back to
     * product attribute matching for legacy return lines.
     *
     * @return array<string, string> Map of original line ID to returned quantity
     */
    private function calculateReturnedQuantities(Receipt $receipt): array
    {
        $returned = [];

        foreach ($receipt->returnReceipts as $returnReceipt) {
            foreach ($returnReceipt->lines as $returnLine) {
                $absQuantity = bcmul((string) $returnLine->quantity, '-1', 3);

                // Prefer direct FK match when available (new return lines)
                if ($returnLine->original_line_id !== null) {
                    $key = $returnLine->original_line_id;
                    $returned[$key] = bcadd($returned[$key] ?? '0.000', $absQuantity, 3);

                    continue;
                }

                // Legacy fallback: match by product attributes
                foreach ($receipt->lines as $originalLine) {
                    $sameProduct = (
                        $originalLine->product_id === $returnLine->product_id
                        && $originalLine->composite_item_id === $returnLine->composite_item_id
                        && $originalLine->product_code === $returnLine->product_code
                    );

                    if ($sameProduct) {
                        $key = $originalLine->id;
                        $returned[$key] = bcadd($returned[$key] ?? '0.000', $absQuantity, 3);
                        break;
                    }
                }
            }
        }

        return $returned;
    }

    /**
     * Download receipt as PDF.
     *
     * Returns a PDF file that can be printed or saved.
     */
    public function downloadPdf(string $id): Response
    {
        Gate::authorize('pos.view_receipts');

        $companyId = $this->companyContext->getCompanyId();

        $receipt = Receipt::where('company_id', $companyId)->findOrFail($id);

        /** @var User $user */
        $user = Auth::user();

        $printRecord = $this->receiptPrintAuditService->recordPrint(
            receiptId: $receipt->id,
            terminalId: $receipt->terminal_id,
            userId: $user->id,
            printMethod: PrintMethod::Pdf,
        );

        $pdf = $this->receiptPdfService->generate($receipt, copyNumber: $printRecord->copy_number);
        $filename = $this->receiptPdfService->getFilename($receipt);

        return $pdf->download($filename);
    }

    /**
     * Stream receipt PDF for inline viewing/printing.
     *
     * Opens in browser print dialog instead of downloading.
     */
    public function streamPdf(string $id): Response
    {
        Gate::authorize('pos.view_receipts');

        $companyId = $this->companyContext->getCompanyId();

        $receipt = Receipt::where('company_id', $companyId)->findOrFail($id);

        /** @var User $user */
        $user = Auth::user();

        $printRecord = $this->receiptPrintAuditService->recordPrint(
            receiptId: $receipt->id,
            terminalId: $receipt->terminal_id,
            userId: $user->id,
            printMethod: PrintMethod::Pdf,
        );

        $pdf = $this->receiptPdfService->generate($receipt, copyNumber: $printRecord->copy_number);
        $filename = $this->receiptPdfService->getFilename($receipt);

        return $pdf->stream($filename);
    }

    /**
     * Process payments for a receipt.
     *
     * Supports split payments across multiple payment methods.
     * Creates Treasury Payment records and General Ledger entries.
     */
    public function storePayments(StoreReceiptPaymentsRequest $request, string $id): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $result = $this->receiptPaymentService->processReceiptPayments(
            receiptId: $id,
            payments: $request->validated('payments'),
            customerId: $request->validated('customer_id'),
        );

        return response()->json([
            'data' => $result,
        ], 201);
    }
}
