<?php

declare(strict_types=1);

namespace App\Modules\Channel\Application\Services;

use App\Modules\Channel\Domain\Enums\ChannelOrderStatus;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Domain\Models\ChannelOrder;
use App\Modules\Document\Domain\Document;
use RuntimeException;

final class ChannelOrderIngestService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingest(string $externalOrderId, array $payload, Channel $channel): ChannelOrder
    {
        return ChannelOrder::query()->firstOrCreate(
            [
                'channel_id' => $channel->id,
                'external_order_id' => $externalOrderId,
            ],
            [
                'received_at' => now(),
                'status' => ChannelOrderStatus::Pending,
                'payload' => $payload,
            ],
        );
    }

    public function promote(string $channelOrderId): Document
    {
        throw new RuntimeException('Channel order promotion requires the T4 routing service and is not materialized in T3 shared infrastructure.');
    }
}
