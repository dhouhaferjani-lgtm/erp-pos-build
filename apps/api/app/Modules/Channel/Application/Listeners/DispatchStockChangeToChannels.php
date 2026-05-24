<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\Listeners;

use App\Modules\Channel\Application\Jobs\DispatchStockChangeToChannelJob;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Domain\Models\ChannelProductMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

final class DispatchStockChangeToChannels
{
    public function handle(object $event): void
    {
        if (! property_exists($event, 'tenantId') || ! property_exists($event, 'companyId') || ! property_exists($event, 'productId') || ! property_exists($event, 'locationId') || ! property_exists($event, 'newStockLevel')) {
            return;
        }

        if (! Schema::hasTable('channels') || ! Schema::hasTable('channel_product_mappings')) {
            return;
        }

        $channelIds = Channel::query()
            ->where('company_id', $event->companyId)
            ->where('is_active', true)
            ->pluck('id');

        ChannelProductMapping::query()
            ->where('product_id', $event->productId)
            ->where('is_published', true)
            ->whereIn('channel_id', $channelIds)
            ->with('channel')
            ->get()
            ->each(function (ChannelProductMapping $mapping) use ($event): void {
                $key = sprintf('channel-stock-dispatch:%s:%s:%s', $mapping->channel_id, $event->productId, $event->locationId);
                Cache::put($key.':latest_stock_level', (string) $event->newStockLevel, 300);

                if (! Cache::add($key, true, 5)) {
                    return;
                }

                DispatchStockChangeToChannelJob::dispatch(
                    $mapping->channel_id,
                    (string) $event->productId,
                    $mapping->variant_id,
                    (string) $event->locationId,
                    (string) $event->newStockLevel,
                    $key,
                    (string) $event->tenantId,
                )->delay(now()->addSeconds(5));
            });
    }
}
