<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\Services;

use App\Modules\Channel\Application\Commands\CreateChannelCommand;
use App\Modules\Channel\Application\DTOs\ConnectionTestResult;
use App\Modules\Channel\Application\DTOs\SyncResult;
use App\Modules\Channel\Application\Jobs\ChannelReconciliationJob;
use App\Modules\Channel\Domain\Enums\ChannelConnectionStatus;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Domain\Models\ChannelProductMapping;
use App\Modules\Product\Domain\Product;
use Illuminate\Support\Facades\DB;

final class ChannelService
{
    public function __construct(
        private readonly AdapterRegistry $adapterRegistry,
    ) {}

    public function create(CreateChannelCommand $command): Channel
    {
        return Channel::query()->create([
            'company_id' => $command->companyId,
            'name' => $command->name,
            'adapter_type' => $command->adapterType,
            'is_active' => true,
            'connection_status' => ChannelConnectionStatus::Pending,
            'metadata' => $command->metadata,
        ]);
    }

    public function testConnection(string $channelId, ?string $companyId = null): ConnectionTestResult
    {
        $channel = $this->findChannel($channelId, $companyId);
        $adapter = $this->adapterRegistry->resolve($channel->adapter_type);
        $result = $adapter->testConnection($channel);

        $channel->forceFill([
            'connection_status' => $result->successful
                ? ChannelConnectionStatus::Connected
                : ChannelConnectionStatus::Failed,
            'last_error' => $result->successful ? null : $result->message,
            'last_successful_sync_at' => $result->successful ? now() : $channel->last_successful_sync_at,
        ])->save();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function publishProduct(string $channelId, string $productId, ?string $variantId, array $overrides, ?string $companyId = null): ChannelProductMapping
    {
        $channel = $this->findChannel($channelId, $companyId);
        $product = Product::query()
            ->where('company_id', $channel->company_id)
            ->findOrFail($productId);
        $payloadHash = $this->productPayloadHash($channel, $productId, $variantId, $overrides);

        return DB::transaction(function () use ($channel, $product, $productId, $variantId, $overrides, $payloadHash): ChannelProductMapping {
            $mapping = ChannelProductMapping::query()->firstOrNew([
                'channel_id' => $channel->id,
                'product_id' => $productId,
                'variant_id' => $variantId,
            ]);

            if ($mapping->exists && $mapping->last_sync_hash === $payloadHash) {
                return $mapping;
            }

            $mapping->fill([
                'is_published' => true,
                'published_at' => $mapping->published_at ?? now(),
                'price_override' => isset($overrides['price']) ? (string) $overrides['price'] : $mapping->price_override,
                'quantity_cap' => isset($overrides['quantity_cap']) ? (int) $overrides['quantity_cap'] : $mapping->quantity_cap,
            ]);
            $mapping->save();

            $adapter = $this->adapterRegistry->resolve($channel->adapter_type);
            $result = $adapter->pushProduct($product, null, $mapping);

            $mapping->forceFill([
                'external_id' => $result->externalId !== '' ? $result->externalId : $mapping->external_id,
                'last_sync_hash' => $payloadHash,
                'last_synced_at' => now(),
            ])->save();

            return $mapping->refresh();
        });
    }

    public function unpublishProduct(string $channelId, string $productId, ?string $variantId, ?string $companyId = null): void
    {
        $channel = $this->findChannel($channelId, $companyId);

        ChannelProductMapping::query()
            ->where('channel_id', $channel->id)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->update(['is_published' => false]);
    }

    public function manualResync(string $channelId, ?string $companyId = null, ?string $tenantId = null): SyncResult
    {
        $channel = $this->findChannel($channelId, $companyId);
        $this->adapterRegistry->resolve($channel->adapter_type);
        $resolvedTenantId = $tenantId ?? (string) $channel->company()->value('tenant_id');

        ChannelReconciliationJob::dispatch($channel->id, $resolvedTenantId);

        return SyncResult::success($channelId, 'Manual resync queued.');
    }

    public function findChannel(string $channelId, ?string $companyId = null): Channel
    {
        $query = Channel::query();

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        return $query->findOrFail($channelId);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function productPayloadHash(Channel $channel, string $productId, ?string $variantId, array $overrides): string
    {
        return hash('sha256', json_encode([
            'channel_id' => $channel->id,
            'adapter_type' => $channel->adapter_type,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'overrides' => $overrides,
        ], JSON_THROW_ON_ERROR));
    }
}
