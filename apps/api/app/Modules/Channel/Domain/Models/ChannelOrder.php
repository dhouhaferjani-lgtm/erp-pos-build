<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Models;

use App\Modules\Channel\Domain\Enums\ChannelOrderStatus;
use App\Modules\Document\Domain\Document;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $channel_id
 * @property string $external_order_id
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property ChannelOrderStatus $status
 * @property array<string, mixed> $payload
 * @property string|null $document_id
 * @property string|null $error_message
 */
final class ChannelOrder extends Model
{
    use HasUuids;

    protected $table = 'channel_orders';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'external_order_id',
        'received_at',
        'processed_at',
        'status',
        'payload',
        'document_id',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'status' => ChannelOrderStatus::class,
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
