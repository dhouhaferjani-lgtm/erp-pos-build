<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Models;

use App\Modules\Channel\Domain\Enums\SyncOperationStatus;
use App\Modules\Channel\Domain\Enums\SyncOperationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $channel_id
 * @property SyncOperationType $operation_type
 * @property string $payload_hash
 * @property string $idempotency_key
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $acknowledged_at
 * @property SyncOperationStatus $status
 * @property int $attempt_count
 * @property Carbon|null $next_retry_at
 */
final class ChannelSyncOperation extends Model
{
    use HasUuids;

    protected $table = 'channel_sync_operations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'operation_type',
        'payload_hash',
        'idempotency_key',
        'dispatched_at',
        'acknowledged_at',
        'status',
        'attempt_count',
        'next_retry_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation_type' => SyncOperationType::class,
            'dispatched_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'status' => SyncOperationStatus::class,
            'attempt_count' => 'integer',
            'next_retry_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
