<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Events;

final readonly class ChannelOrderPromotedToDocument
{
    public function __construct(
        public string $channelOrderId,
        public string $documentId,
    ) {}
}
