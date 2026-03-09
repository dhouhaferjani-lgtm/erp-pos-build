<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Entities;

use App\Modules\Loyalty\Domain\Enums\LoyaltyTargetType;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property string|null $terms_and_conditions
 * @property LoyaltyTargetType $target_type
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Enrollment> $enrollments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EarningRule> $earningRules
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Reward> $rewards
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Tier> $tiers
 */
class LoyaltyProgram extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): \Database\Factories\Loyalty\LoyaltyProgramFactory
    {
        return \Database\Factories\Loyalty\LoyaltyProgramFactory::new();
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
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'program_id');
    }

    /**
     * Get earning rules for this program
     */
    public function earningRules(): HasMany
    {
        return $this->hasMany(EarningRule::class, 'program_id');
    }

    /**
     * Get rewards for this program
     */
    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class, 'program_id');
    }

    /**
     * Get tiers for this program
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
