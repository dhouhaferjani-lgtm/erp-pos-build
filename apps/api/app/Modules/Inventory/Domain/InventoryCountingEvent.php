<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Identity\Domain\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $counting_id
 * @property string|null $item_id
 * @property string $event_type
 * @property array<string, mixed> $event_data
 * @property string|null $user_id
 * @property string $previous_hash
 * @property string $event_hash
 * @property Carbon $created_at
 * @property-read InventoryCounting $counting
 * @property-read InventoryCountingItem|null $item
 * @property-read User|null $user
 */
class InventoryCountingEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'inventory_counting_events';

    protected $fillable = [
        'counting_id',
        'item_id',
        'event_type',
        'event_data',
        'user_id',
        'previous_hash',
        'event_hash',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->created_at = now();

            // Get previous hash
            $lastEvent = static::where('counting_id', $event->counting_id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            $event->previous_hash = $lastEvent !== null ? $lastEvent->event_hash : 'GENESIS';

            // Calculate event hash
            $event->event_hash = hash('sha256', json_encode([
                'previous_hash' => $event->previous_hash,
                'counting_id' => $event->counting_id,
                'item_id' => $event->item_id,
                'event_type' => $event->event_type,
                'event_data' => $event->event_data,
                'user_id' => $event->user_id,
                'created_at' => $event->created_at->toIso8601String(),
            ]) ?: '');
        });
    }

    /**
     * @return BelongsTo<InventoryCounting, $this>
     */
    public function counting(): BelongsTo
    {
        return $this->belongsTo(InventoryCounting::class, 'counting_id');
    }

    /**
     * @return BelongsTo<InventoryCountingItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryCountingItem::class, 'item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Event type constants
    public const COUNTING_CREATED = 'counting.created';

    public const COUNTING_ACTIVATED = 'counting.activated';

    public const COUNTING_CANCELLED = 'counting.cancelled';

    public const COUNT_SUBMITTED = 'count.submitted';

    public const ITEM_AUTO_RESOLVED = 'item.auto_resolved';

    public const ITEM_MANUALLY_OVERRIDDEN = 'item.manually_overridden';

    public const THIRD_COUNT_TRIGGERED = 'third_count.triggered';

    public const COUNTING_FINALIZED = 'counting.finalized';

    /**
     * Record a count submission event.
     *
     * @param  numeric-string  $quantity  Canonical numeric string (quantity scale 4, e.g. '1.2345')
     */
    public static function recordCountSubmitted(
        InventoryCountingItem $item,
        int $countNumber,
        string $quantity,
        ?string $notes,
        string $userId
    ): self {
        return self::create([
            'counting_id' => $item->counting_id,
            'item_id' => $item->id,
            'event_type' => self::COUNT_SUBMITTED,
            'event_data' => [
                'count_number' => $countNumber,
                'quantity' => $quantity,
                'notes' => $notes,
            ],
            'user_id' => $userId,
        ]);
    }

    /**
     * Record an auto resolution event.
     *
     * @param  numeric-string  $finalQty  Canonical numeric string (quantity scale 4)
     */
    public static function recordAutoResolution(
        InventoryCountingItem $item,
        string $method,
        string $finalQty
    ): self {
        $theoreticalQty = $item->theoretical_qty;
        $variance = bcsub($finalQty, $theoreticalQty, 4);

        return self::create([
            'counting_id' => $item->counting_id,
            'item_id' => $item->id,
            'event_type' => self::ITEM_AUTO_RESOLVED,
            'event_data' => [
                'method' => $method,
                'final_qty' => $finalQty,
                'theoretical_qty' => $theoreticalQty,
                'variance' => $variance,
            ],
            'user_id' => null,
        ]);
    }

    /**
     * Record a manual override event.
     *
     * @param  string  $finalQty  Canonical numeric string (quantity scale 4)
     */
    public static function recordManualOverride(
        InventoryCountingItem $item,
        string $finalQty,
        string $notes,
        string $userId
    ): self {
        return self::create([
            'counting_id' => $item->counting_id,
            'item_id' => $item->id,
            'event_type' => self::ITEM_MANUALLY_OVERRIDDEN,
            'event_data' => [
                'final_qty' => $finalQty,
                'notes' => $notes,
                'count_1_qty' => $item->count_1_qty !== null ? (string) $item->count_1_qty : null,
                'count_2_qty' => $item->count_2_qty !== null ? (string) $item->count_2_qty : null,
                'count_3_qty' => $item->count_3_qty !== null ? (string) $item->count_3_qty : null,
                'theoretical_qty' => (string) $item->theoretical_qty,
            ],
            'user_id' => $userId,
        ]);
    }
}
