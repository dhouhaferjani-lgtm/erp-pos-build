<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Events\ReceiptVoided;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptLineBatchAllocation;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Shift;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service for voiding POS receipts.
 *
 * Handles the full void operation:
 * 1. Mark receipt as voided with metadata
 * 2. Reverse stock movements (create inverse StockMovement per line)
 * 3. Reverse batch allocations if applicable
 * 4. Record REFUND cash drawer operation
 */
final class ReceiptVoidService
{
    public function __construct(
        private readonly CashDrawerService $cashDrawerService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Void a receipt with full reversal.
     *
     * @param  Receipt  $receipt  The receipt to void (must not be already voided)
     * @param  User  $voidedBy  The user performing the void
     * @param  string  $reason  Reason for voiding
     *
     * @throws \RuntimeException If receipt is already voided
     */
    public function voidReceipt(
        Receipt $receipt,
        User $voidedBy,
        string $reason,
        ?string $authorizedByUserId = null,
    ): Receipt {
        if ($receipt->is_voided) {
            throw new \RuntimeException('Receipt is already voided');
        }

        return DB::transaction(function () use ($receipt, $voidedBy, $reason, $authorizedByUserId): Receipt {
            // 1. Mark as voided.
            //
            // The fiscal_status must transition to Voided: the PostgreSQL
            // receipt immutability trigger only permits an UPDATE on a
            // fiscalized receipt when it moves fiscalized -> voided. Omitting
            // fiscal_status here leaves a fiscalized receipt unchanged on that
            // column, which the trigger rejects ("Receipt is fiscally sealed
            // and cannot be modified"), failing every void in production.
            $receipt->update([
                'is_voided' => true,
                'fiscal_status' => FiscalStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $voidedBy->id,
                'void_reason' => $reason,
                'authorized_by_user_id' => $authorizedByUserId,
                'override_reason' => $reason,
            ]);

            // 2. Reverse stock movements for each line
            $receipt->loadMissing('lines');
            foreach ($receipt->lines as $line) {
                if ($line->product_id === null) {
                    continue;
                }

                $this->reverseStockMovement(
                    $receipt->tenant_id,
                    $receipt->company_id,
                    $receipt->location_id,
                    $line->product_id,
                    $line->quantity,
                    $receipt->id,
                    $voidedBy->id,
                );
            }

            // 3. Reverse batch allocations
            $this->reverseBatchAllocations($receipt);

            // 4. Record REFUND cash drawer operation
            $this->recordCashDrawerRefund($receipt, $voidedBy);

            $receipt->refresh();

            /** @var Carbon $voidedAtTimestamp */
            $voidedAtTimestamp = $receipt->voided_at;

            DB::afterCommit(function () use ($receipt, $reason, $voidedBy, $voidedAtTimestamp) {
                event(new ReceiptVoided(
                    receiptId: $receipt->id,
                    companyId: $receipt->company_id,
                    receiptNumber: $receipt->receipt_number,
                    voidReason: $reason,
                    voidedBy: $voidedBy->id,
                    voidedAt: $voidedAtTimestamp->toIso8601String(),
                ));
            });

            return $receipt;
        });
    }

    /**
     * Reverse stock movement for a product line.
     *
     * Creates a Receipt (inbound) StockMovement to add stock back.
     */
    /**
     * @param  numeric-string  $quantity
     */
    private function reverseStockMovement(
        string $tenantId,
        string $companyId,
        string $locationId,
        string $productId,
        string $quantity,
        string $receiptId,
        string $userId,
    ): void {
        /** @var StockLevel|null $stockLevel */
        $stockLevel = StockLevel::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();

        if ($stockLevel === null) {
            return;
        }

        $quantityBefore = (string) $stockLevel->quantity;
        $quantityAfter = bcadd((string) $stockLevel->quantity, (string) $quantity, 2);

        $stockLevel->quantity = $quantityAfter;
        $stockLevel->save();

        StockMovement::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'movement_type' => MovementType::Receipt,
            'reason' => MovementReason::CustomerReturn,
            'quantity' => $quantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'reference' => 'POS Sale Void',
            'reference_type' => 'pos_receipt_void',
            'reference_id' => $receiptId,
            'notes' => "Stock returned via POS void (receipt: {$receiptId})",
            'user_id' => $userId,
            'is_historical' => false,
        ]);
    }

    /**
     * Reverse batch allocations for the voided receipt.
     */
    private function reverseBatchAllocations(Receipt $receipt): void
    {
        $allocations = ReceiptLineBatchAllocation::where('receipt_id', $receipt->id)->get();

        foreach ($allocations as $allocation) {
            // Increment batch stock level
            $batchStock = DB::table('inventory_batch_stock')
                ->where('batch_id', $allocation->batch_id)
                ->where('location_id', $receipt->location_id)
                ->lockForUpdate()
                ->first();

            if ($batchStock !== null) {
                /** @var object{id: string, quantity: string} $batchStock */
                DB::table('inventory_batch_stock')
                    ->where('id', $batchStock->id)
                    ->update([
                        'quantity' => DB::raw("quantity + {$allocation->quantity}"),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * Record REFUND cash drawer operation for the voided receipt.
     */
    private function recordCashDrawerRefund(Receipt $receipt, User $user): void
    {
        // Find the open shift for this terminal
        $shift = Shift::where('terminal_id', $receipt->terminal_id)
            ->open()
            ->first();

        if ($shift === null) {
            return;
        }

        // Bug 2 follow-up — refund the net cash that physically entered the
        // drawer at sale time:
        //
        //     refund = max(0, min(Σ(cash.amount), total − Σ(non_cash) − tolerance))
        //
        // The `min(...)` covers every payment.amount shape and every cash
        // edge case:
        //
        //   Post-Bug-2 over-tender: cash=tendered (>cash_owed), change>0.
        //     Σ(cash.amount) > total − non_cash → bound to total − non_cash.
        //   Pre-Bug-2 over-tender: cash=total (Bug 2 stored cart total
        //     instead of tendered), change>0.
        //     Σ(cash.amount) = total − non_cash → either branch returns
        //     the same correct net cash.
        //   Tolerance short-pay: cash=tendered (<cash_owed), change=0,
        //     tolerance_writeoff>0. The tendered cash IS what hit the
        //     drawer; the tolerance was posted to GL 658, not the drawer.
        //     Σ(cash.amount) < total − non_cash → bound to Σ(cash.amount).
        //   Exact tender (either era): Σ(cash.amount) = total − non_cash
        //     − 0 → both branches agree.
        //   Split with mixed tenders: per-row amount semantics already
        //     correct; `total − non_cash` is the cash portion of the sale.
        //
        // Codex r1 (P2) closed the pre-fix over-tender case; Codex r2 (P2)
        // closed the tolerance short-pay case. Together they prove the
        // formula is the right invariant.
        $receipt->loadMissing('payments');
        $hasCashPayment = false;
        $cashSum = '0';
        $nonCashSum = '0';
        foreach ($receipt->payments as $payment) {
            if ($payment->payment_type === 'CASH') {
                $hasCashPayment = true;
                $cashSum = bcadd($cashSum, (string) $payment->amount, $this->scale());
            } else {
                $nonCashSum = bcadd($nonCashSum, (string) $payment->amount, $this->scale());
            }
        }

        $tolerance = $receipt->tolerance_writeoff !== null
            ? (string) $receipt->tolerance_writeoff
            : '0';
        $cashOwed = bcsub((string) $receipt->total, $nonCashSum, $this->scale());
        $cashOwed = bcsub($cashOwed, $tolerance, $this->scale());

        // refund = min(cashSum, cashOwed), clamped at 0.
        $netDrawerCash = (bccomp($cashSum, $cashOwed, $this->scale()) < 0)
            ? $cashSum
            : $cashOwed;
        if (bccomp($netDrawerCash, '0', $this->scale()) < 0) {
            $netDrawerCash = '0';
        }

        if ($hasCashPayment && bccomp($netDrawerCash, '0', $this->scale()) > 0) {
            $this->cashDrawerService->recordRefund(
                $shift,
                $netDrawerCash,
                $user,
                $receipt->id,
            );
        }
    }
}
