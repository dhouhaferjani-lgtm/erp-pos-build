<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Events;

final readonly class ChannelOrderReceived
{
    public function __construct(
        public string $channelOrderId,
        public string $channelId,
    ) {}
}
