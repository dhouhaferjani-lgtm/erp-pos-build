<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Channel\Application\DTOs\StockUpdateDTO;
use App\Modules\Channel\Application\Services\AdapterRegistry;
use App\Modules\Channel\Domain\Enums\SyncOperationStatus;
use App\Modules\Channel\Domain\Enums\SyncOperationType;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Domain\Models\ChannelSyncOperation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class DispatchStockChangeToChannelJob implements ShouldQueue
{
    use BindsTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $channelId,
        public readonly string $productId,
        public readonly ?string $variantId,
        public readonly string $locationId,
        public readonly string $newStockLevel,
        public readonly string $idempotencyKey,
        public readonly string $tenantId,
    ) {}

    public function handle(AdapterRegistry $adapterRegistry): void
    {
        $this->withTenantContext(function () use ($adapterRegistry): void {
            $channel = Channel::query()->findOrFail($this->channelId);
            $stockLevel = (string) Cache::get($this->idempotencyKey.':latest_stock_level', $this->newStockLevel);
            $operation = ChannelSyncOperation::query()->firstOrCreate(
                ['idempotency_key' => $this->idempotencyKey],
                [
                    'channel_id' => $channel->id,
                    'operation_type' => SyncOperationType::StockPush,
                    'payload_hash' => hash('sha256', $stockLevel),
                    'status' => SyncOperationStatus::Pending,
                    'attempt_count' => 0,
                ],
            );

            $operation->forceFill([
                'status' => SyncOperationStatus::Pending,
                'dispatched_at' => now(),
                'payload_hash' => hash('sha256', $stockLevel),
            ])->save();

            try {
                $result = $adapterRegistry->resolve($channel->adapter_type)->pushStock(new StockUpdateDTO(
                    channelId: $channel->id,
                    productId: $this->productId,
                    variantId: $this->variantId,
                    locationId: $this->locationId,
                    quantity: $stockLevel,
                    idempotencyKey: $this->idempotencyKey,
                ));

                if (! $result->successful) {
                    throw new RuntimeException($result->message ?? 'Channel stock push failed.');
                }

                $operation->forceFill([
                    'status' => SyncOperationStatus::Acknowledged,
                    'acknowledged_at' => now(),
                    'attempt_count' => $operation->attempt_count + 1,
                    'next_retry_at' => null,
                ])->save();
            } catch (Throwable $exception) {
                $operation->forceFill([
                    'status' => SyncOperationStatus::Failed,
                    'attempt_count' => $operation->attempt_count + 1,
                    'next_retry_at' => now()->addMinutes(5),
                ])->save();

                throw $exception;
            }
        });
    }
}
