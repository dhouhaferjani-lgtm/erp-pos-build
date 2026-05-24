<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Channel\Domain\Models\ChannelOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class IngestChannelOrderJob implements ShouldQueue
{
    use BindsTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $channelOrderId,
        public readonly string $tenantId,
    ) {}

    public function handle(): void
    {
        $this->withTenantContext(function (): void {
            ChannelOrder::query()->findOrFail($this->channelOrderId);
        });
    }
}
