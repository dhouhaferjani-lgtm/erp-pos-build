<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Channel\Application\Services\AdapterRegistry;
use App\Modules\Channel\Domain\Events\ChannelSyncDriftDetected;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Domain\Models\ChannelProductMapping;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ChannelReconciliationJob implements ShouldQueue
{
    use BindsTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $channelId,
        public readonly string $tenantId,
    ) {}

    public function handle(AdapterRegistry $adapterRegistry): void
    {
        $this->withTenantContext(function () use ($adapterRegistry): void {
            $channel = Channel::query()->findOrFail($this->channelId);
            $adapter = $adapterRegistry->resolve($channel->adapter_type);
            $remoteOrders = $adapter->pullOrders($channel);
            $localPublishedCount = ChannelProductMapping::query()
                ->where('channel_id', $channel->id)
                ->where('is_published', true)
                ->count();

            if ($remoteOrders->count() > $localPublishedCount) {
                event(new ChannelSyncDriftDetected(
                    channelId: $channel->id,
                    driftCount: $remoteOrders->count() - $localPublishedCount,
                ));
            }
        });
    }
}
