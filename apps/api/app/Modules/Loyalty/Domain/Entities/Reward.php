<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Entities;

use App\Modules\Loyalty\Domain\Enums\RewardType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $program_id
 * @property string $name
 * @property string|null $description
 * @property RewardType $reward_type
 * @property numeric-string $points_cost
 * @property numeric-string|null $reward_value
 * @property array<string, mixed>|null $qualifying_items
 * @property numeric-string|null $max_discount
 * @property numeric-string|null $min_order_value
 * @property array<int, string>|null $tier_ids
 * @property bool $is_active
 * @property int|null $quantity_available
 * @property int|null $quantity_per_member
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LoyaltyProgram $program
 */
class Reward extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUuids;

    protected $table = 'loyalty_rewards';

    protected $fillable = [
        'program_id',
        'name',
        'description',
        'reward_type',
        'points_cost',
        'reward_value',
        'qualifying_items',
        'max_discount',
        'min_order_value',
        'tier_ids',
        'is_active',
        'quantity_available',
        'quantity_per_member',
        'start_date',
        'end_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reward_type' => RewardType::class,
            'points_cost' => 'decimal:3',
            'reward_value' => 'decimal:3',
            'qualifying_items' => 'array',
            'max_discount' => 'decimal:3',
            'min_order_value' => 'decimal:3',
            'tier_ids' => 'array',
            'is_active' => 'boolean',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
        ];
    }

    /**
     * Get the program for this reward
     *
     * @return BelongsTo<LoyaltyProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'program_id');
    }

    /**
     * Check if reward is currently available
     */
    public function isAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->start_date && $now->isBefore($this->start_date)) {
            return false;
        }

        if ($this->end_date && $now->isAfter($this->end_date)) {
            return false;
        }

        if ($this->quantity_available !== null && $this->quantity_available <= 0) {
            return false;
        }

        return true;
    }
}
