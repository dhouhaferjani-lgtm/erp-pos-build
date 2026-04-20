<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Entities;

use App\Modules\Loyalty\Domain\Enums\LoyaltyTargetType;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use Database\Factories\Loyalty\LoyaltyProgramFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property array<int, string>|null $company_ids
 * @property string $name
 * @property ProgramType $program_type
 * @property ProgramStatus $status
 * @property string|null $description
 * @property string|null $currency
 * @property int|null $points_expiry_months
 * @property string|null $welcome_bonus_points
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string|null $terms_and_conditions
 * @property LoyaltyTargetType $target_type
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Enrollment> $enrollments
 * @property-read Collection<int, EarningRule> $earningRules
 * @property-read Collection<int, Reward> $rewards
 * @property-read Collection<int, Tier> $tiers
 */
class LoyaltyProgram extends Model
{
    /** @use HasFactory<LoyaltyProgramFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): LoyaltyProgramFactory
    {
        return LoyaltyProgramFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'company_ids',
        'name',
        'description',
        'program_type',
        'status',
        'currency',
        'points_expiry_months',
        'welcome_bonus_points',
        'start_date',
        'end_date',
        'target_type',
        'terms_and_conditions',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'program_type' => ProgramType::class,
            'status' => ProgramStatus::class,
            'target_type' => LoyaltyTargetType::class,
            'company_ids' => 'array',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Get enrollments for this program
     *
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'program_id');
    }

    /**
     * Get earning rules for this program
     *
     * @return HasMany<EarningRule, $this>
     */
    public function earningRules(): HasMany
    {
        return $this->hasMany(EarningRule::class, 'program_id');
    }

    /**
     * Get rewards for this program
     *
     * @return HasMany<Reward, $this>
     */
    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class, 'program_id');
    }

    /**
     * Get tiers for this program
     *
     * @return HasMany<Tier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(Tier::class, 'program_id');
    }

    /**
     * Check if program is active
     */
    public function isActive(): bool
    {
        if ($this->status !== ProgramStatus::Active) {
            return false;
        }

        $now = now();

        if ($this->start_date && $now->isBefore($this->start_date)) {
            return false;
        }

        if ($this->end_date && $now->isAfter($this->end_date)) {
            return false;
        }

        return true;
    }
}
