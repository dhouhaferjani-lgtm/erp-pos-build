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
 * @property string $member_id
 * @property numeric-string $current_balance
 * @property numeric-string $lifetime_earned
 * @property numeric-string $lifetime_redeemed
 * @property string|null $current_tier_id
 * @property \Illuminate\Support\Carbon|null $tier_qualified_at
 * @property string $status
 * @property \Illuminate\Support\Carbon $enrolled_at
 * @property \Illuminate\Support\Carbon|null $last_transaction_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read LoyaltyProgram $program
 * @property-read LoyaltyMember $member
 * @property-read Tier|null $currentTier
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Transaction> $transactions
 */
class Enrollment extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'loyalty_enrollments';

    protected $fillable = [
        'program_id',
        'member_id',
        'current_balance',
        'lifetime_earned',
        'lifetime_redeemed',
        'current_tier_id',
        'tier_qualified_at',
        'status',
        'enrolled_at',
        'last_transaction_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:2',
            'lifetime_earned' => 'decimal:2',
            'lifetime_redeemed' => 'decimal:2',
            'tier_qualified_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'last_transaction_at' => 'datetime',
        ];
    }

    /**
     * Get the program for this enrollment
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'program_id');
    }

    /**
     * Get the member for this enrollment
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(LoyaltyMember::class, 'member_id');
    }

    /**
     * Get the current tier
     */
    public function currentTier(): BelongsTo
    {
        return $this->belongsTo(Tier::class, 'current_tier_id');
    }

    /**
     * Get all transactions for this enrollment
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'enrollment_id');
    }
}
