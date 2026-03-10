<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Entities;

use App\Modules\Loyalty\Domain\Enums\EarningRuleType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $program_id
 * @property string $name
 * @property EarningRuleType $rule_type
 * @property int $priority
 * @property bool $is_active
 * @property array<string, mixed> $conditions
 * @property numeric-string $reward_value
 * @property string $reward_type
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property numeric-string|null $max_earn_per_transaction
 * @property numeric-string|null $max_earn_per_day
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read LoyaltyProgram $program
 */
class EarningRule extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'program_id',
        'name',
        'rule_type',
        'priority',
        'is_active',
        'conditions',
        'reward_value',
        'reward_type',
        'start_date',
        'end_date',
        'max_earn_per_transaction',
        'max_earn_per_day',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule_type' => EarningRuleType::class,
            'is_active' => 'boolean',
            'conditions' => 'array',
            'reward_value' => 'decimal:4',
            'max_earn_per_transaction' => 'decimal:3',
            'max_earn_per_day' => 'decimal:3',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
        ];
    }

    /**
     * Get the program for this rule
     *
     * @return BelongsTo<LoyaltyProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'program_id');
    }

    /**
     * Check if rule is currently valid
     */
    public function isValid(): bool
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

        return true;
    }
}
