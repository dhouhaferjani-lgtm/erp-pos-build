<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $card_definition_id
 * @property string $enrollment_id
 * @property int $current_stamps
 * @property Carbon $started_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $reward_claimed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StampCardDefinition $cardDefinition
 * @property-read Enrollment $enrollment
 */
class MemberStampCard extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'card_definition_id',
        'enrollment_id',
        'current_stamps',
        'started_at',
        'expires_at',
        'completed_at',
        'reward_claimed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'reward_claimed_at' => 'datetime',
        ];
    }

    /**
     * Get the card definition
     *
     * @return BelongsTo<StampCardDefinition, $this>
     */
    public function cardDefinition(): BelongsTo
    {
        return $this->belongsTo(StampCardDefinition::class, 'card_definition_id');
    }

    /**
     * Get the enrollment for this card
     *
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    /**
     * Check if card is completed
     */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Check if card is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->isAfter($this->expires_at);
    }

    /**
     * Check if reward has been claimed
     */
    public function isRewardClaimed(): bool
    {
        return $this->reward_claimed_at !== null;
    }
}
