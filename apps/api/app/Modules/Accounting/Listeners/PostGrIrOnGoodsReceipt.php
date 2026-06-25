<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Inventory\Domain\Events\GoodsReceived;
use Illuminate\Support\Facades\Log;

/**
 * Listener that posts the GR-IR journal entry when goods are received
 * against a purchase order.
 *
 * Journal entry posted:
 *   Debit:  Inventory (asset — stock received)
 *   Credit: GoodsReceivedNotInvoiced / 408 (accrued liability until supplier invoice matched)
 *
 * No VAT leg: TVA is deductible only at invoice receipt (Code de la TVA Art. 9/18).
 * No partner_id: 408 is an accrual account, not a partner sub-ledger.
 *
 * The listener is idempotent: if the GL entry for the given movementId already
 * exists, GeneralLedgerService::createGoodsReceiptGrIrEntry() is a no-op.
 */
final class PostGrIrOnGoodsReceipt
{
    public function __construct(
        private readonly GeneralLedgerService $glService,
    ) {}

    public function handle(GoodsReceived $event): void
    {
        try {
            $this->glService->createGoodsReceiptGrIrEntry(
                companyId: $event->companyId,
                movementId: $event->movementId,
                receivedQty: $event->receivedQty,
                unitCost: $event->unitCost,
                currency: $event->currency,
            );
        } catch (\Throwable $e) {
            // Log the error but do not re-throw — failure to post GL must not
            // block the goods receipt (the stock movement has already committed).
            // Ops can replay the GoodsReceived event or create the GL entry manually.
            Log::error('PostGrIrOnGoodsReceipt: failed to post GR-IR entry', [
                'movement_id' => $event->movementId,
                'company_id' => $event->companyId,
                'qty' => $event->receivedQty,
                'unit_cost' => $event->unitCost,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
