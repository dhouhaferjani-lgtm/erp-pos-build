<?php

declare(strict_types=1);

namespace Tests\Feature\Replenishment;

require_once __DIR__.'/../Inventory/StockTransferReceiveTest.php';
use App\Modules\Replenishment\Application\DTOs\CaptureRequestData;
use App\Modules\Replenishment\Application\Services\ReplenishmentCaptureService;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentChannel;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use Tests\Feature\Inventory\TransferReceiptFeatureTestCase;

final class TransferCloseReplenishmentSettlementTest extends TransferReceiptFeatureTestCase
{
    public function test_settled_request_stays_fulfilled_after_both_close_dispositions(): void
    {
        foreach (['write_off', 'return_to_source'] as $disposition) {
            $request = $this->app->make(ReplenishmentCaptureService::class)->capture(new CaptureRequestData(
                tenantId: $this->tenant->id, companyId: $this->company->id, locationId: $this->destination->id,
                productId: $this->product->id, variantId: null, requestedQty: '12.0000', note: null,
                requestedByUserId: $this->user->id, channel: ReplenishmentChannel::Web,
            ));
            $this->transfer = $this->initiate('12.0000');
            $this->close($disposition, $disposition)->assertCreated();
            self::assertSame(ReplenishmentStatus::Fulfilled, $request->refresh()->status);
            self::assertSame($this->transfer->id, $request->fulfillment_id);
            self::assertSame($this->user->id, $request->processed_by_user_id);
        }
    }
}
