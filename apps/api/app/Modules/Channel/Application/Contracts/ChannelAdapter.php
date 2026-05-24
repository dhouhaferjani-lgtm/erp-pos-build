<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\Contracts;

use App\Modules\Channel\Application\DTOs\ConnectionTestResult;
use App\Modules\Channel\Application\DTOs\OrderStatusUpdateDTO;
use App\Modules\Channel\Application\DTOs\PriceUpdateDTO;
use App\Modules\Channel\Application\DTOs\StockUpdateDTO;
use App\Modules\Channel\Application\DTOs\SyncResult;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Domain\Models\ChannelProductMapping;
use App\Modules\Product\Domain\Product;
use Illuminate\Support\Collection;

interface ChannelAdapter
{
    public function adapterType(): string;

    public function signatureStrategy(): ChannelSignatureStrategy;

    public function pushProduct(Product $product, ?object $variant, ChannelProductMapping $mapping): SyncResult;

    public function pushStock(StockUpdateDTO $update): SyncResult;

    public function pushPriceUpdate(PriceUpdateDTO $update): SyncResult;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pullOrders(Channel $channel): Collection;

    public function updateOrderStatus(string $externalOrderId, OrderStatusUpdateDTO $update): SyncResult;

    public function testConnection(Channel $channel): ConnectionTestResult;
}
