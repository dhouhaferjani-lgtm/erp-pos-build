<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\ReceiptCreationService;
use App\Modules\POS\Application\Services\ReceiptPaymentService;
use App\Modules\POS\Application\Services\ReceiptPdfService;
use App\Modules\POS\Application\Services\ReceiptPrintAuditService;
use App\Modules\POS\Application\Services\ReceiptQrTokenIssuanceService;
use App\Modules\POS\Application\Services\ReceiptReturnService;
use App\Modules\POS\Application\Services\ReceiptVoidService;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Modules\POS\Domain\Enums\PrintMethod;
use App\Modules\POS\Domain\Enums\RefundDestination;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Exceptions\DiscountExceedsLimitException;
use App\Modules\POS\Domain\Exceptions\DiscountNotAllowedException;
use App\Modules\POS\Domain\Exceptions\LegacyCorrectionRetiredException;
use App\Modules\POS\Domain\Exceptions\ShiftNotOpenException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Presentation\Requests\StoreReceiptPaymentsRequest;
use App\Modules\POS\Presentation\Requests\StoreReceiptRequest;
use App\Modules\POS\Presentation\Requests\StoreReturnRequest;
use App\Modules\Voucher\Application\Services\VoucherLookupService;
use App\Shared\Domain\QuantityScale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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
        private readonly ReceiptQrTokenIssuanceService $qrTokenIssuanceService,
        private readonly VoucherLookupService $voucherLookupService,
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
            'approval_id' => 'required|uuid',
            'approval_fiscal_event_id' => 'required|uuid',
            'approval_scope' => 'required|in:void_or_return_override',
            'approval_supervisor_user_id' => 'required|uuid',
            'approval_override_event_id' => 'required|uuid',
            'authorized_by_user_id' => 'required|uuid|same:approval_supervisor_user_id',
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

        // Phase 6 guard — refund is the ONLY post-seal correction surface.
        // A RETURN receipt cannot be voided: the void path never reverses
        // the batch restitution the return performed (F4), so a later
        // re-return of the original sale would over-restore
        // inventory_batch_stock. A wrong return is corrected by a
        // compensating sale, not by voiding the return.
        if ($receipt->isReturn()) {
            return response()->json([
                'error' => [
                    'code' => 'CANNOT_VOID_RETURN_RECEIPT',
                    'message' => 'Return receipts cannot be voided.',
                ],
            ], 422);
        }

        /** @var User $user */
        $user = Auth::user();

        $this->assertVoidReturnApproval(
            receipt: $receipt,
            user: $user,
            approvalId: (string) $request->input('approval_id'),
            approvalFiscalEventId: (string) $request->input('approval_fiscal_event_id'),
            approvalSupervisorUserId: (string) $request->input('approval_supervisor_user_id'),
            approvalOverrideEventId: (string) $request->input('approval_override_event_id'),
            targetEventType: 'POS_RECEIPT_VOID',
            targetReferenceId: $receipt->id,
            expectedTarget: [
                'receipt_id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'reason' => (string) $request->input('reason'),
            ],
        );

        $voidedReceipt = $this->receiptVoidService->voidReceipt(
            $receipt,
            $user,
            $request->input('reason'),
            $request->input('approval_supervisor_user_id'),
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

            /** @var Receipt $originalReceipt */
            $originalReceipt = Receipt::where('company_id', $this->companyContext->getCompanyId())
                ->with(['lines'])
                ->findOrFail($id);

            $lineIds = [];
            foreach ($validated['lines'] as $line) {
                if (is_array($line) && array_key_exists('line_id', $line)) {
                    $lineIds[] = (string) $line['line_id'];
                }
            }
            sort($lineIds);

            $this->assertVoidReturnApproval(
                receipt: $originalReceipt,
                user: $user,
                approvalId: (string) $validated['approval_id'],
                approvalFiscalEventId: (string) $validated['approval_fiscal_event_id'],
                approvalSupervisorUserId: (string) $validated['approval_supervisor_user_id'],
                approvalOverrideEventId: (string) $validated['approval_override_event_id'],
                targetEventType: 'POS_RECEIPT_RETURN',
                targetReferenceId: $originalReceipt->id,
                expectedTarget: [
                    'line_ids' => $lineIds,
                    'reason' => (string) ($validated['notes'] ?? ''),
                    'receipt_id' => $originalReceipt->id,
                    'receipt_number' => $originalReceipt->receipt_number,
                ],
            );

            $requestedDestination = isset($validated['refund_destination'])
                ? RefundDestination::from((string) $validated['refund_destination'])
                : null;

            $returnReceipt = $this->receiptReturnService->processReturn(
                originalReceiptId: $id,
                returnLines: $validated['lines'],
                returnReason: ReturnReason::from($validated['return_reason']),
                cashier: $user,
                terminalId: $validated['terminal_id'],
                notes: $validated['notes'] ?? null,
                destination: $requestedDestination,
                refundRequestId: (string) $validated['refund_request_id'],
                authorizedByUserId: $validated['approval_supervisor_user_id'],
                overrideReason: $validated['override_reason'] ?? $validated['notes'] ?? null,
            );

            // Surface the issued voucher (if any) and the new receipt's QR token
            // so the POS printer can render the dedicated voucher ticket and the
            // refund-receipt QR footer without an extra round trip. The voucher
            // is keyed by source_receipt_id (set by VoucherIssuanceService when
            // refund destination is StoreVoucher).
            $issuedVoucher = $this->voucherLookupService->findBySourceReceiptId($returnReceipt->id);

            $qrToken = $this->qrTokenIssuanceService->issueTokenFor($returnReceipt);

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
                    'qr_token' => $qrToken,
                    'issued_voucher' => $issuedVoucher !== null ? [
                        'id' => $issuedVoucher->id,
                        'code' => $issuedVoucher->code,
                        'initial_balance' => (string) $issuedVoucher->initial_balance,
                        'currency' => $issuedVoucher->currency,
                        'expires_at' => $issuedVoucher->expires_at?->toISOString(),
                        'redemption_mode' => $issuedVoucher->redemption_mode->value,
                        'partner_id' => $issuedVoucher->issued_to_partner_id,
                    ] : null,
                    'lines' => $returnReceipt->lines->map(fn ($line) => [
                        'product_name' => $line->product_name,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unit_price,
                        'line_total' => $line->line_total,
                    ])->values()->toArray(),
                ],
            ], 201);
        } catch (LegacyCorrectionRetiredException $e) {
            // v3-refund-chain-integration spec §9.4/§17 — must escape to
            // Laravel's exception pipeline UNCAUGHT so the global
            // `bootstrap/app.php` render() handler (the single source of
            // truth for the 409 `LEGACY_CORRECTION_RETIRED` body/copy)
            // handles it. The broad `catch (\RuntimeException $e)` below
            // would otherwise intercept it first (this exception IS-A
            // RuntimeException) and misreport it as a 422 `RETURN_FAILED` —
            // exactly the bug this acceptance-test companion
            // (ReceiptReturnRefactorV3Test) was written to catch. PHP
            // dispatches to the first matching catch clause, so this
            // narrower clause must be declared before the broad one.
            throw $e;
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
     * @param  array<string, mixed>  $expectedTarget
     *
     * @throws ValidationException
     */
    private function assertVoidReturnApproval(
        Receipt $receipt,
        User $user,
        string $approvalId,
        string $approvalFiscalEventId,
        string $approvalSupervisorUserId,
        string $approvalOverrideEventId,
        string $targetEventType,
        string $targetReferenceId,
        array $expectedTarget,
    ): void {
        $approvalEvent = FiscalEvent::query()
            ->where('id', $approvalFiscalEventId)
            ->where('tenant_id', $receipt->tenant_id)
            ->where('company_id', $receipt->company_id)
            ->where('terminal_id', $receipt->terminal_id)
            ->where('event_type', FiscalEventType::OPERATOR_APPROVAL_GRANTED->value)
            ->first();

        $overrideEvent = FiscalEvent::query()
            ->where('id', $approvalOverrideEventId)
            ->where('tenant_id', $receipt->tenant_id)
            ->where('company_id', $receipt->company_id)
            ->where('terminal_id', $receipt->terminal_id)
            ->where('event_type', FiscalEventType::OVERRIDE_VOID_OR_RETURN->value)
            ->where('reference_event_id', $approvalFiscalEventId)
            ->first();

        if ($approvalEvent === null || $overrideEvent === null) {
            throw ValidationException::withMessages([
                'approval_fiscal_event_id' => ['Void/return approval fiscal event chain was not found.'],
            ]);
        }

        $approvalPayload = $this->fiscalPayload($approvalEvent);
        $overridePayload = $this->fiscalPayload($overrideEvent);
        $overrideContext = $overridePayload['override_context'] ?? null;

        if (! is_array($overrideContext)) {
            throw ValidationException::withMessages([
                'approval_override_event_id' => ['Void/return override context is missing.'],
            ]);
        }

        if (
            ($approvalPayload['approval_id'] ?? null) !== $approvalId
            || ($approvalPayload['approval_scope'] ?? null) !== 'void_or_return_override'
            || ($approvalPayload['cashier_user_id'] ?? null) !== $user->id
            || ($approvalPayload['supervisor_user_id'] ?? null) !== $approvalSupervisorUserId
            || ($approvalPayload['tenant_id'] ?? null) !== $receipt->tenant_id
            || ($approvalPayload['company_id'] ?? null) !== $receipt->company_id
            || ($approvalPayload['terminal_id'] ?? null) !== $receipt->terminal_id
            || ! $this->targetsEqual($approvalPayload['target'] ?? null, $expectedTarget)
            || ($overridePayload['approval_event_id'] ?? null) !== $approvalFiscalEventId
            || ($overridePayload['approval_id'] ?? null) !== $approvalId
            || ($overridePayload['approval_scope'] ?? null) !== 'void_or_return_override'
            || ($overridePayload['supervisor_user_id'] ?? null) !== $approvalSupervisorUserId
            || ($overridePayload['tenant_id'] ?? null) !== $receipt->tenant_id
            || ($overridePayload['company_id'] ?? null) !== $receipt->company_id
            || ($overridePayload['terminal_id'] ?? null) !== $receipt->terminal_id
            || ! $this->targetsEqual($overridePayload['target'] ?? null, $expectedTarget)
            || ($overrideContext['target_event_type'] ?? null) !== $targetEventType
            || ($overrideContext['target_reference_id'] ?? null) !== $targetReferenceId
        ) {
            throw ValidationException::withMessages([
                'approval_override_event_id' => ['Void/return approval evidence does not match this operation.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function targetsEqual(mixed $actual, array $expected): bool
    {
        return is_array($actual) && $actual == $expected;
    }

    /**
     * @return array<string, mixed>
     */
    private function fiscalPayload(FiscalEvent $event): array
    {
        if (is_array($event->payload)) {
            return $event->payload;
        }

        $decoded = json_decode($event->canonical_bytes, true);
        if (! is_array($decoded)) {
            return [];
        }

        $payload = $decoded['payload'] ?? null;

        return is_array($payload) ? $payload : [];
    }

    /**
     * [RETIRED §14.2] Created a new POS receipt.
     *
     * Created a receipt with line items, calculated VAT, decremented stock,
     * and computed the fiscal hash chain.
     *
     * **§14.2 — NEW-SALE AUTHORING RETIRED.** The `POST /api/v1/pos/receipts`
     * route is dispositioned to return HTTP 410 Gone with
     * `NEW_SALE_AUTHORING_RETIRED` at the route-level closure (see
     * `routes.php`). This controller method is preserved for the §14.3
     * chokepoint manifest's reference to `ReceiptCreationService::createReceipt`
     * (the `(c)` carve-outs `void` / `return` reach the service through
     * sibling controllers — `ReceiptVoidService` / `ReceiptReturnService`
     * — not through this method). Do not re-wire this method to a route
     * for new-sale authoring without coordinating with Task 30's CI gate.
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
        $zeroQuantity = QuantityScale::formatForUnit('0', null);

        // Build response with returned_quantity on each line
        $receiptData = $receipt->toArray();
        $receiptData['lines'] = $receipt->lines->map(function ($line) use ($returnedQuantities, $zeroQuantity) {
            $lineData = $line->toArray();
            $lineData['returned_quantity'] = $returnedQuantities[$line->id] ?? $zeroQuantity;

            return $lineData;
        })->values()->toArray();

        // Remove the returnReceipts from the response (internal use only)
        unset($receiptData['return_receipts']);

        return response()->json([
            'data' => $receiptData,
        ]);
    }

    /**
     * MAGNITUDE of a stored return-line quantity, at the canonical quantity
     * storage scale.
     *
     * Mirrors `ReceiptReturnService::quantityMagnitude()` — see that docblock
     * for the full rationale. Short version (whole-branch review C-5/N-1):
     * legacy returns store a NEGATIVE quantity, v4 refunds store a POSITIVE
     * magnitude, so flipping the sign under-reports (indeed NEGATES)
     * `returned_quantity` for a v4-refunded line. This surface is what the
     * cashier-facing receipt view uses to show how much of a line is still
     * returnable, so a negative tally here advertises headroom that does not
     * exist.
     *
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private function quantityMagnitude(string $value): string
    {
        return bccomp($value, '0', 4) < 0 // precision-ok: 4 = canonical quantity storage scale
            ? bcsub('0', $value, 4) // precision-ok: 4 = canonical quantity storage scale
            : bcadd($value, '0', 4); // precision-ok: 4 = canonical quantity storage scale
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
        $zeroQuantity = QuantityScale::formatForUnit('0', null);

        foreach ($receipt->returnReceipts as $returnReceipt) {
            foreach ($returnReceipt->lines as $returnLine) {
                // MAGNITUDE, never a sign flip — see quantityMagnitude().
                $absQuantity = $this->quantityMagnitude((string) $returnLine->quantity);

                // Prefer direct FK match when available (new return lines)
                if ($returnLine->original_line_id !== null) {
                    $key = $returnLine->original_line_id;
                    $returned[$key] = bcadd($returned[$key] ?? $zeroQuantity, $absQuantity, 4);

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
                        $returned[$key] = bcadd($returned[$key] ?? $zeroQuantity, $absQuantity, 4);
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

        // F.2 — defense-in-depth: scope by tenant_id alongside company_id.
        // Companies are tenant-scoped today (a company UUID belongs to
        // exactly one tenant), but the explicit tenant predicate hardens
        // the scope against future schema changes.
        $company = $this->companyContext->requireCompany();

        $receipt = Receipt::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($id);

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

        // F.2 — defense-in-depth tenant_id scope (mirrors downloadPdf).
        $company = $this->companyContext->requireCompany();

        $receipt = Receipt::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($id);

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
     * [RETIRED §14.2] Processed payments for a receipt.
     *
     * Supported split payments across multiple payment methods. Created
     * Treasury Payment records and General Ledger entries.
     *
     * **§14.2 — NEW-SALE AUTHORING RETIRED.** The
     * `POST /api/v1/pos/receipts/{id}/payments` route is dispositioned to
     * return HTTP 410 Gone with `NEW_SALE_AUTHORING_RETIRED` at the
     * route-level closure (see `routes.php`). The device now authors
     * `payment_lines[]` inside the SALE_RECEIPT envelope and the Treasury
     * bridge (Task 22) projects the Treasury Payment + GL on ingestion.
     * This method is preserved as a structural anchor for the writer
     * inventory + audit; do not re-wire it to a route for new-sale
     * authoring without coordinating with Task 30's CI gate.
     */
    public function storePayments(StoreReceiptPaymentsRequest $request, string $id): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        try {
            $result = $this->receiptPaymentService->processReceiptPayments(
                receiptId: $id,
                payments: $request->validated('payments'),
                customerId: $request->validated('customer_id'),
            );
        } catch (ShiftNotOpenException $e) {
            // Cashier is paying a receipt while no shift is open on the terminal —
            // typically the prior shift just closed. Surface a 409 with a domain
            // code that the POS UI can map to a "open a shift first" toast,
            // matching the convention already established in Shift/ReportController.
            return response()->json([
                'error' => [
                    'code' => 'NO_OPEN_SHIFT',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }

        return response()->json([
            'data' => $result,
        ], 201);
    }
}
