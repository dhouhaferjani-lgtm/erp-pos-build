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

final class RejectingSignatureAdapter implements ChannelAdapter
{
    public function adapterType(): string
    {
        return 'rejecting_test';
    }

    public function signatureStrategy(): ChannelSignatureStrategy
    {
        return new class implements ChannelSignatureStrategy
        {
            public function verify(Request $request, Channel $channel): bool
            {
                return false;
            }
        };
    }

    public function pushProduct(Product $product, ?object $variant, ChannelProductMapping $mapping): SyncResult
    {
        return SyncResult::success('unused');
    }

    public function pushStock(StockUpdateDTO $update): SyncResult
    {
        return SyncResult::success('unused');
    }

    public function pushPriceUpdate(PriceUpdateDTO $update): SyncResult
    {
        return SyncResult::success('unused');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pullOrders(Channel $channel): Collection
    {
        return collect();
    }

    public function updateOrderStatus(string $externalOrderId, OrderStatusUpdateDTO $update): SyncResult
    {
        return SyncResult::success($externalOrderId);
    }

    public function testConnection(Channel $channel): ConnectionTestResult
    {
        return ConnectionTestResult::failure('Rejected.');
    }
}
