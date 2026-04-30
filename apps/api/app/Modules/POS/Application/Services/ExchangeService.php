<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\DTOs\ExchangeRequestInput;
use App\Modules\POS\Application\DTOs\ExchangeResult;
use App\Modules\POS\Domain\Enums\ExchangeRequestStatus;
use App\Modules\POS\Domain\Enums\RefundDestination;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Exceptions\ExchangeInProgressException;
use App\Modules\POS\Domain\ExchangeRequest;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Voucher\Application\DTOs\VoucherIssuanceRequest;
use App\Modules\Voucher\Application\Services\VoucherIssuanceService;
use App\Modules\Voucher\Domain\Voucher;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates the POS exchange flow: return + new sale in one atomic transaction.
 *
 * Design decisions (from Phase F spec / Codex review 3 finding D):
 *
 * 1. Exchange idempotency is stored in pos_exchange_requests, not pos_receipts.
 *    A client-supplied exchange_request_id (unique per company) maps to a stable triple
 *    (return_receipt_id, sale_receipt_id, voucher_id?).
 *
 * 2. Both fiscal documents share the same exchange_group_id UUID, which is committed
 *    into BOTH halves' v3 canonical hash payloads (spec §3.4 / §5.1).  A tampering
 *    detector can verify the link between the two halves.
 *
 * 3. Partial state is NOT allowed. A single DB transaction creates both pending receipts,
 *    finalises both, and persists the idempotency triple atomically.  If the outer
 *    transaction rolls back, no receipts are persisted. Failure is tracked in a SEPARATE
 *    transaction so the failure record persists even after the outer rollback.
 *
 * 4. Finalisation order: return first, then sale.  The sale's previous_hash is therefore
 *    the return's fiscal_hash, keeping a contiguous chain on the terminal.
 *
 * 5. Net settlement:
 *    - Net > 0 (sale > return, customer pays): charge lands on the sale half.
 *    - Net < 0 (return > sale, customer receives): surplus issued as a voucher or cash refund.
 *    - Net = 0 (even exchange): no payment or refund.
 *
 * 6. Stock: return half writes +1 (stock in), sale half writes -1 (stock out).
 *    Same-SKU return+sale produces TWO movements, not one net, preserving the audit trail.
 */
final class ExchangeService
{
    public function __construct(
        private readonly ReceiptCreationService $createService,
        private readonly ReceiptReturnService $returnService,
        private readonly ReceiptPaymentService $paymentService,
        private readonly VoucherIssuanceService $voucherIssuance,
        private readonly ReceiptFinalizationService $finalizationService,
        private readonly CashDrawerService $cashDrawerService,
        private readonly CompanyContext $companyContext,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * Process an exchange: return selected lines + create a new sale.
     *
     * Idempotency: if exchange_request_id was already processed successfully, returns
     * the cached ExchangeResult immediately. If it is in progress (pending), throws
     * ExchangeInProgressException (HTTP 409). If it previously failed, re-attempts.
     *
     * @throws ExchangeInProgressException If the same exchange_request_id is pending
     * @throws \RuntimeException On business logic failure (rolled back + tracked as failed)
     */
    public function processExchange(ExchangeRequestInput $input): ExchangeResult
    {
        $companyId = $this->companyContext->requireCompanyId();

        // Wrap the idempotency check + pending row creation in a transaction so
        // the SELECT FOR UPDATE and the subsequent INSERT are atomic.
        [$exchangeRow, $exchangeGroupId, $existingResult] = DB::transaction(
            function () use ($input, $companyId): array {
                return $this->resolveOrCreateIdempotencyRow($input, $companyId);
            }
        );

        // If the row was already completed, return the cached triple immediately
        if ($existingResult !== null) {
            return $existingResult;
        }

        // ─────────────────────────────────────────────────────────────────
        // Main exchange transaction (separate from the idempotency transaction
        // so that a rollback here doesn't undo the pending row)
        // ─────────────────────────────────────────────────────────────────
        try {
            $result = DB::transaction(function () use ($input, $companyId, $exchangeGroupId, $exchangeRow): ExchangeResult {
                return $this->executeExchange($input, $companyId, $exchangeGroupId, $exchangeRow);
            });

            return $result;
        } catch (\Throwable $e) {
            // Track failure in a SEPARATE transaction so the record persists
            $this->trackFailure($exchangeRow, $e);

            throw $e;
        }
    }

    /**
     * SELECT FOR UPDATE on pos_exchange_requests and either:
     * - Return the completed triple (idempotency hit)
     * - Throw ExchangeInProgressException (pending race condition)
     * - Return a new or reset pending row + fresh exchange_group_id
     *
     * @return array{0: ExchangeRequest, 1: string, 2: ExchangeResult|null}
     */
    private function resolveOrCreateIdempotencyRow(
        ExchangeRequestInput $input,
        string $companyId,
    ): array {
        $existing = ExchangeRequest::where('company_id', $companyId)
            ->where('exchange_request_id', $input->exchangeRequestId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ($existing->status === ExchangeRequestStatus::Completed) {
                $result = new ExchangeResult(
                    returnReceiptId: (string) $existing->return_receipt_id,
                    saleReceiptId: (string) $existing->sale_receipt_id,
                    voucherId: $existing->voucher_id,
                    netAmount: '0',
                );

                return [$existing, $existing->exchange_group_id, $result];
            }

            if ($existing->status === ExchangeRequestStatus::Pending) {
                throw new ExchangeInProgressException($input->exchangeRequestId);
            }

            // Failed: reuse row with a fresh group_id
            $exchangeGroupId = (string) Str::uuid();
            $existing->exchange_group_id = $exchangeGroupId;
            $existing->status = ExchangeRequestStatus::Pending;
            $existing->return_receipt_id = null;
            $existing->sale_receipt_id = null;
            $existing->voucher_id = null;
            $existing->failure_reason = null;
            $existing->failure_payload = null;
            $existing->completed_at = null;
            $existing->save();

            return [$existing, $exchangeGroupId, null];
        }

        // First time: create the pending row
        $exchangeGroupId = (string) Str::uuid();

        /** @var ExchangeRequest $row */
        $row = ExchangeRequest::create([
            'tenant_id' => $input->terminal->tenant_id,
            'company_id' => $companyId,
            'exchange_request_id' => $input->exchangeRequestId,
            'exchange_group_id' => $exchangeGroupId,
            'status' => ExchangeRequestStatus::Pending,
        ]);

        return [$row, $exchangeGroupId, null];
    }

    // =========================================================================
    // Core exchange execution (inside DB transaction)
    // =========================================================================

    private function executeExchange(
        ExchangeRequestInput $input,
        string $companyId,
        string $exchangeGroupId,
        ExchangeRequest $exchangeRow,
    ): ExchangeResult {
        $terminal = $input->terminal;
        $cashier = $input->cashier;

        // ── Step 1: Load active shift (needed for cash drawer operations) ──
        $shift = Shift::where('terminal_id', $terminal->id)
            ->where('status', ShiftStatus::Open)
            ->lockForUpdate()
            ->first();

        if ($shift === null) {
            throw new \RuntimeException('No active shift on this terminal. Open a shift first.');
        }

        // ── Step 2: Build return receipt (draft → finalized) ─────────────
        // ExchangeDeferred skips the payment side effect on the return half.
        // Net settlement is handled below after both halves are built.
        $returnReceipt = $this->returnService->processReturn(
            originalReceiptId: $input->original->id,
            returnLines: $input->returnLines,
            returnReason: $input->returnReason,
            cashier: $cashier,
            terminalId: $terminal->id,
            notes: $input->notes,
            destination: RefundDestination::ExchangeDeferred,
            refundRequestId: null,
            authorizedByUserId: $input->authorizedByUserId,
            overrideReason: $input->overrideReason,
            exchangeGroupId: $exchangeGroupId,
        );

        // ── Step 3: Build sale receipt draft ─────────────────────────────
        $saleDraft = $this->createService->createReceipt(
            terminalId: $terminal->id,
            lines: $input->newSaleItems,
            customerId: $input->partnerId,
            notes: $input->notes,
            exchangeGroupId: $exchangeGroupId,
        );

        // ── Step 4: Compute net amount ────────────────────────────────────
        $scale = $this->scaleResolver->getScale();
        /** @var numeric-string $returnTotal */
        $returnTotal = (string) $returnReceipt->total;  // negative (return receipt)
        /** @var numeric-string $saleTotal */
        $saleTotal = (string) $saleDraft->total;         // positive (sale receipt)

        // netAmount = saleTotal + returnTotal (returnTotal is negative)
        // Positive net: customer pays. Negative net: customer receives.
        /** @var numeric-string $netAmount */
        $netAmount = bcadd($saleTotal, $returnTotal, $scale);

        // ── Step 5: Settle net payment ────────────────────────────────────
        $voucherId = null;

        if (bccomp($netAmount, '0', $scale) > 0) {
            // Customer pays the net difference on the SALE half
            if (count($input->netPaymentTenders) === 0) {
                throw new \InvalidArgumentException(
                    "Net is positive ({$netAmount}) but no netPaymentTenders were provided."
                );
            }

            $this->paymentService->processReceiptPayments(
                receiptId: $saleDraft->id,
                payments: $input->netPaymentTenders,
                customerId: $input->partnerId,
            );

            // processReceiptPayments calls finalizationService->finalize internally
            // so the sale receipt is already sealed at this point
            $saleDraft->refresh();
        } elseif (bccomp($netAmount, '0', $scale) < 0) {
            // Customer is owed money — settle surplus
            /** @var numeric-string $surplusAmount */
            $surplusAmount = bcmul($netAmount, '-1', $scale);

            if ($input->surplusDestination === RefundDestination::StoreVoucher) {
                $voucher = $this->issueSurplusVoucher(
                    returnReceipt: $returnReceipt,
                    cashier: $cashier,
                    terminal: $terminal,
                    surplusAmount: $surplusAmount,
                    authorizedByUserId: $input->authorizedByUserId,
                    overrideReason: $input->overrideReason,
                );
                $voucherId = $voucher->id;
            } else {
                // Cash refund of net surplus
                $this->cashDrawerService->recordRefund(
                    $shift,
                    $surplusAmount,
                    $cashier,
                    $returnReceipt->id,
                );
            }

            // Finalize the sale half (no payment on sale for surplus exchanges)
            $saleDraft = $this->finalizationService->finalize($saleDraft);
        } else {
            // Even exchange — just finalize the sale
            $saleDraft = $this->finalizationService->finalize($saleDraft);
        }

        // ── Step 6: Mark idempotency row as completed ─────────────────────
        $exchangeRow->status = ExchangeRequestStatus::Completed;
        $exchangeRow->return_receipt_id = $returnReceipt->id;
        $exchangeRow->sale_receipt_id = $saleDraft->id;
        $exchangeRow->voucher_id = $voucherId;
        $exchangeRow->completed_at = Carbon::now();
        $exchangeRow->save();

        return new ExchangeResult(
            returnReceiptId: $returnReceipt->id,
            saleReceiptId: $saleDraft->id,
            voucherId: $voucherId,
            netAmount: $netAmount,
        );
    }

    // =========================================================================
    // Surplus voucher issuance (Task 38)
    // =========================================================================

    /**
     * Issue a surplus voucher for the net negative difference.
     *
     * Linked to the RETURN half's receipt so the VoucherLedger row is committed
     * into the return receipt's v3 hash payload.
     *
     * @param  numeric-string  $surplusAmount  Positive amount (absolute value of net)
     */
    private function issueSurplusVoucher(
        Receipt $returnReceipt,
        User $cashier,
        Terminal $terminal,
        string $surplusAmount,
        ?string $authorizedByUserId,
        ?string $overrideReason,
    ): Voucher {
        $request = new VoucherIssuanceRequest(
            amount: $surplusAmount,
            currency: $returnReceipt->currency,
            tenantId: $terminal->tenant_id,
            companyId: $returnReceipt->company_id,
            issuedByUserId: $cashier->id,
            sourceReceiptId: $returnReceipt->id,
            issuedToPartnerId: $returnReceipt->partner_id,
            issuedAtTerminalId: $terminal->id,
            authorizedByUserId: $authorizedByUserId,
            overrideReason: $overrideReason,
        );

        return $this->voucherIssuance->issueFromExchangeSurplus($request);
    }

    // =========================================================================
    // Failure tracking (separate transaction so the record persists)
    // =========================================================================

    private function trackFailure(ExchangeRequest $exchangeRow, \Throwable $e): void
    {
        try {
            DB::transaction(function () use ($exchangeRow, $e): void {
                $fresh = ExchangeRequest::lockForUpdate()->find($exchangeRow->id);
                if ($fresh === null) {
                    return;
                }

                // Only update to failed if still pending (idempotent guard)
                if ($fresh->status === ExchangeRequestStatus::Pending) {
                    $fresh->status = ExchangeRequestStatus::Failed;
                    $fresh->failure_reason = mb_substr($e->getMessage(), 0, 255);
                    $fresh->failure_payload = [
                        'exception_class' => $e::class,
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => array_slice(
                            array_map(fn (array $f): string => ($f['file'] ?? '?').'@'.($f['line'] ?? '?'), $e->getTrace()),
                            0,
                            10
                        ),
                    ];
                    $fresh->save();
                }
            });
        } catch (\Throwable $trackingException) {
            Log::error('ExchangeService: failed to persist failure record', [
                'exchange_request_id' => $exchangeRow->exchange_request_id,
                'original_error' => $e->getMessage(),
                'tracking_error' => $trackingException->getMessage(),
            ]);
        }
    }
}
