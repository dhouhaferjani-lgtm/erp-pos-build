<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Channel\Application\Services\ChannelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DispatchProductToChannelJob implements ShouldQueue
{
    use BindsTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function __construct(
        public readonly string $channelId,
        public readonly string $productId,
        public readonly string $tenantId,
        public readonly ?string $variantId = null,
        public readonly array $overrides = [],
    ) {}

    public function handle(ChannelService $channelService): void
    {
        $this->withTenantContext(function () use ($channelService): void {
            $channelService->publishProduct($this->channelId, $this->productId, $this->variantId, $this->overrides);
        });
    }
}
