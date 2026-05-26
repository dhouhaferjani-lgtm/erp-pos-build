<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Events;

final readonly class ChannelSyncDriftDetected
{
    public function __construct(
        public string $channelId,
        public int $driftCount,
    ) {}
}
