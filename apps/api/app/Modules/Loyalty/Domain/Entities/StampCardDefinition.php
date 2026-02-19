<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $program_id
 * @property string $name
 * @property int $stamps_required
 * @property int $stamps_per_item
 * @property array<string, mixed> $qualifying_items
 * @property string $reward_id
 * @property int|null $max_active_cards
 * @property int|null $expiry_days
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read LoyaltyProgram $program
 * @property-read Reward $reward
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MemberStampCard> $memberCards
 */
class StampCardDefinition extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'program_id',
        'name',
        'stamps_required',
        'stamps_per_item',
        'qualifying_items',
        'reward_id',
        'max_active_cards',
        'expiry_days',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qualifying_items' => 'array',
        ];
    }

    /**
     * Get the program for this card definition
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'program_id');
    }

    /**
     * Get the reward for completing this card
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class, 'reward_id');
    }

    /**
     * Get all member cards for this definition
     */
    public function memberCards(): HasMany
    {
        return $this->hasMany(MemberStampCard::class, 'card_definition_id');
    }
}
