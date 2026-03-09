<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Entities;

use App\Modules\Loyalty\Domain\Enums\QualificationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $program_id
 * @property string $name
 * @property int $level
 * @property string|null $icon
 * @property string|null $color
 * @property QualificationType $qualification_type
 * @property numeric-string $qualification_threshold
 * @property int|null $qualification_period_months
 * @property numeric-string $earning_multiplier
 * @property array<string, mixed>|null $benefits
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read LoyaltyProgram $program
 */
class Tier extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'loyalty_tiers';

    protected $fillable = [
        'program_id',
        'name',
        'level',
        'icon',
        'color',
        'qualification_type',
        'qualification_threshold',
        'qualification_period_months',
        'earning_multiplier',
        'benefits',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qualification_type' => QualificationType::class,
            'qualification_threshold' => 'decimal:3',
            'earning_multiplier' => 'decimal:2',
            'benefits' => 'array',
        ];
    }

    /**
     * Get the program for this tier
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'program_id');
    }
}
