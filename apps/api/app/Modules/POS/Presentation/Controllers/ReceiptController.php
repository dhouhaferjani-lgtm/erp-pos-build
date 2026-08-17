<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\DTOs\ReceiptListItemData;
use App\Modules\POS\Application\Services\ReceiptCreationService;
use App\Modules\POS\Application\Services\ReceiptPaymentService;
use App\Modules\POS\Application\Services\ReceiptPdfService;
use App\Modules\POS\Application\Services\ReceiptPrintAuditService;
use App\Modules\POS\Application\Services\ReceiptQrTokenIssuanceService;
use App\Modules\POS\Application\Services\ReceiptReturnService;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Modules\POS\Domain\Enums\PrintMethod;
use App\Modules\POS\Domain\Enums\RefundDestination;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Exceptions\DiscountExceedsLimitException;
use App\Modules\POS\Domain\Exceptions\DiscountNotAllowedException;
use App\Modules\POS\Domain\Exceptions\LegacyCorrectionRetiredException;
use App\Modules\POS\Domain\Exceptions\ShiftNotOpenException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Presentation\Requests\IndexReceiptsRequest;
use App\Modules\POS\Presentation\Requests\StoreReceiptPaymentsRequest;
use App\Modules\POS\Presentation\Requests\StoreReceiptRequest;
use App\Modules\POS\Presentation\Requests\StoreReturnRequest;
use App\Modules\Voucher\Application\Services\VoucherLookupService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\QuantityScale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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
        private readonly LocationContext $locationContext,
        private readonly CurrencyScaleResolverInterface $currencyScaleResolver,
        private readonly ReceiptCreationService $receiptCreationService,
        private readonly ReceiptPdfService $receiptPdfService,
        private readonly ReceiptPrintAuditService $receiptPrintAuditService,
        private readonly ReceiptPaymentService $receiptPaymentService,
        private readonly ReceiptReturnService $receiptReturnService,
        private readonly ReceiptQrTokenIssuanceService $qrTokenIssuanceService,
        private readonly VoucherLookupService $voucherLookupService,
    ) {}

    /**
     * List receipts with filters and pagination.
     *
     * GET /api/v1/pos/receipts
     */
    public function index(IndexReceiptsRequest $request): JsonResponse
    {
        Gate::authorize('pos.view_receipts');

        $company = $this->companyContext->requireCompany();
        $companyId = $company->id;
        $validated = $request->validated();

        $query = Receipt::where('company_id', $companyId)
            ->with(['location:id,name', 'terminal:id,code']);

        $allowedLocationIds = $this->locationContext->getAllowedLocationIds($companyId);
        $requestedLocationIds = $validated['location_ids'] ?? null;
        if ($allowedLocationIds !== null) {
            $effectiveLocationIds = $requestedLocationIds === null
                ? $allowedLocationIds
                : array_values(array_intersect($allowedLocationIds, $requestedLocationIds));
            $query->whereIn('location_id', $effectiveLocationIds);
        } elseif ($requestedLocationIds !== null) {
            $query->whereIn('location_id', $requestedLocationIds);
        }

        // Filters
        if (! empty($validated['terminal_id'])) {
            $query->where('terminal_id', $validated['terminal_id']);
        }

        if (! empty($validated['cashier_id'])) {
            $query->where('cashier_id', $validated['cashier_id']);
        }

        if (! empty($validated['receipt_number'])) {
            $escapedReceiptNumber = addcslashes($validated['receipt_number'], '\\%_');
            $query->whereRaw("receipt_number LIKE ? ESCAPE '\\'", ['%'.$escapedReceiptNumber.'%']);
        }

        if (! empty($validated['customer_id'])) {
            $query->where('partner_id', $validated['customer_id']);
        }

        if (! empty($validated['contact_id'])) {
            $query->where('contact_id', $validated['contact_id']);
        }

        if (array_key_exists('is_voided', $validated)) {
            $query->where('is_voided', $request->boolean('is_voided'));
        }

        $legacyReceiptType = $validated['receipt_type'] ?? null;
        $usesLegacyReceiptType = is_string($legacyReceiptType) && ! isset($validated['invoice_type_codes']);
        if ($usesLegacyReceiptType) {
            $query->where('receipt_type', $legacyReceiptType);
        } else {
            /** @var list<string> $invoiceTypeCodes */
            $invoiceTypeCodes = $validated['invoice_type_codes']
                ?? ($request->boolean('include_training') ? ['SALE', 'TRAINING'] : ['SALE']);
            $includesRefund = in_array('REFUND', $invoiceTypeCodes, true);

            $query->where(function (Builder $typeQuery) use ($invoiceTypeCodes, $includesRefund): void {
                $typeQuery->where(function (Builder $currentTypeQuery) use ($invoiceTypeCodes): void {
                    $currentTypeQuery
                        ->whereIn('invoice_type_code', $invoiceTypeCodes)
                        ->where(function (Builder $notLegacyReturn): void {
                            $notLegacyReturn
                                ->where('receipt_type', '!=', 'return')
                                ->orWhereNotNull('fiscal_event_id')
                                ->orWhere('invoice_type_code', '!=', 'SALE');
                        });
                });

                if ($includesRefund) {
                    $typeQuery->orWhere(function (Builder $legacyReturnQuery): void {
                        $legacyReturnQuery
                            ->where('receipt_type', 'return')
                            ->whereNull('fiscal_event_id')
                            ->where('invoice_type_code', 'SALE');
                    });
                }
            });
        }

        if (! $request->boolean('include_training') || $usesLegacyReceiptType) {
            $query->where('training_flag', false);
        }

        if (isset($validated['fiscal_status'])) {
            $query->where('fiscal_status', $validated['fiscal_status']);
        }

        $from = isset($validated['from_date']) && is_string($validated['from_date'])
            ? $this->dateBoundary($validated['from_date'], $company->timezone)->utc()
            : null;
        $to = isset($validated['to_date']) && is_string($validated['to_date'])
            ? $this->dateBoundary($validated['to_date'], $company->timezone)->addDay()->utc()
            : null;

        if ($from !== null) {
            $query->where('posted_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('posted_at', '<', $to);
        }

        $receipts = $query->orderByDesc('posted_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $items = collect($receipts->items())->map(function (Receipt $receipt): array {
            $moneyScale = $this->currencyScaleResolver->getScale($receipt->currency);
            $isLegacyReturn = $receipt->receipt_type->value === 'return'
                && $receipt->fiscal_event_id === null
                && $receipt->invoice_type_code === 'SALE';

            return (new ReceiptListItemData(
                id: $receipt->id,
                receipt_number: $receipt->receipt_number,
                posted_at: $receipt->posted_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                invoice_type_code: $isLegacyReturn ? 'REFUND' : $receipt->invoice_type_code,
                receipt_type: $receipt->receipt_type->value,
                training_flag: $receipt->training_flag,
                fiscal_status: $receipt->fiscal_status->value,
                location_id: $receipt->location_id,
                location_name: $receipt->location->name,
                terminal_id: $receipt->terminal_id,
                terminal_code: $receipt->terminal->code,
                cashier_id: $receipt->cashier_id,
                cashier_name: $receipt->cashier_name,
                total: CurrencyScale::bcformatStrict((string) $receipt->total, $moneyScale),
                currency: $receipt->currency,
                original_receipt_id: $receipt->original_receipt_id,
            ))->toArray();
        });

        return response()->json([
            'data' => [
                'data' => $items,
                'meta' => [
                    'current_page' => $receipts->currentPage(),
                    'last_page' => $receipts->lastPage(),
                    'per_page' => $receipts->perPage(),
                    'total' => $receipts->total(),
                    'from' => $from?->toISOString(),
                    'to' => $to?->toISOString(),
                ],
            ],
        ]);
    }

    private function dateBoundary(string $date, string $timezone): CarbonImmutable
    {
        $boundary = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if (! $boundary instanceof CarbonImmutable) {
            throw new \LogicException('Validated receipt date could not be parsed.');
        }

        return $boundary;
    }

    // DPA V9 (owner ruling D3 — SUNSET): `void()` and `ReceiptVoidService`
    // were removed here. The legacy void mutated the ORIGINAL sealed
    // receipt in place with no justifying document and no GL reversal.
    // `POST /pos/receipts/{id}/void` is now a 410 `LEGACY_VOID_RETIRED`
    // tombstone in routes.php; corrections go through the v4 refund rail.
    // `assertVoidReturnApproval()` below is RETAINED — `processReturn()`
    // is its surviving caller.

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
     * (the surviving `(c)` carve-out `return` reaches the service through a
     * sibling controller — `ReceiptReturnService` — not through this method;
     * the `void` carve-out was SUNSET by DPA V9, owner ruling D3, and
     * `ReceiptVoidService` deleted). Do not re-wire this method to a route
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
