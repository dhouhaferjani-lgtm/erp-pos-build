<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Events;

final readonly class ChannelDispatchFailed
{
    public function __construct(
        public string $channelId,
        public string $operationType,
        public string $message,
    ) {}
}
