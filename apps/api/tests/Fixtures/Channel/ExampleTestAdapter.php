<?php

declare(strict_types=1);

namespace Tests\Fixtures\Channel;

use App\Modules\Channel\Application\Contracts\ChannelAdapter;
use App\Modules\Channel\Application\Contracts\ChannelSignatureStrategy;
use App\Modules\Channel\Application\DTOs\ConnectionTestResult;
use App\Modules\Channel\Application\DTOs\OrderStatusUpdateDTO;
use App\Modules\Channel\Application\DTOs\PriceUpdateDTO;
use App\Modules\Channel\Application\DTOs\StockUpdateDTO;
use App\Modules\Channel\Application\DTOs\SyncResult;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Domain\Models\ChannelProductMapping;
use App\Modules\Product\Domain\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ExampleTestAdapter implements ChannelAdapter
{
    public int $productPushCount = 0;

    public int $stockPushCount = 0;

    public ?string $lastStockQuantity = null;

    public function adapterType(): string
    {
        return 'example_test';
    }

    public function signatureStrategy(): ChannelSignatureStrategy
    {
        return new class implements ChannelSignatureStrategy
        {
            public function verify(Request $request, Channel $channel): bool
            {
                return true;
            }
        };
    }

    public function pushProduct(Product $product, ?object $variant, ChannelProductMapping $mapping): SyncResult
    {
        $this->productPushCount++;

        return SyncResult::success('example-'.$product->id);
    }

    public function pushStock(StockUpdateDTO $update): SyncResult
    {
        $this->stockPushCount++;
        $this->lastStockQuantity = $update->quantity;

        return SyncResult::success($update->productId);
    }

    public function pushPriceUpdate(PriceUpdateDTO $update): SyncResult
    {
        return SyncResult::success($update->productId);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pullOrders(Channel $channel): Collection
    {
        return collect([$this->remoteOrder()]);
    }

    public function updateOrderStatus(string $externalOrderId, OrderStatusUpdateDTO $update): SyncResult
    {
        return SyncResult::success($externalOrderId);
    }

    public function testConnection(Channel $channel): ConnectionTestResult
    {
        return ConnectionTestResult::success('Example test adapter connected.');
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteOrder(): array
    {
        return ['external_order_id' => 'REMOTE-1'];
    }
}
